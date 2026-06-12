<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the WP-Cron driven asynchronous N-gram cache rebuild loop.
 *
 * Two distinct responsibilities, both belonging to this collaborator:
 *
 * 1. SCHEDULE: enqueue a wp_schedule_single_event for the rebuild
 *    cron hook the first time, with diagnostics + infra-error
 *    classification on cron-scheduling failure.
 *
 * 2. RUN: the cron callback that processes one bounded chunk of
 *    batches per invocation, reschedules itself until done, and
 *    tracks per-site progress through the network option store on
 *    multisite installs.
 *
 * Lock acquisition is owned by the orchestrator
 * (DatabaseUpgradeNGram). This collaborator assumes the appropriate
 * SyncUtils lock is already held when its methods are called.
 *
 * Cross-component caller note: `countTotalPagesForRebuild()` is
 * reachable from outside (a multisite race-condition test invokes it
 * through the upgrade dispatcher) so it must remain a stable public
 * surface on this collaborator.
 */
class ABJ_404_Solution_NGramCacheRebuildScheduler {

    /** WP-Cron hook this scheduler enqueues and consumes. */
    const REBUILD_CRON_HOOK = 'abj404_rebuild_ngram_cache_hook';

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var mixed */
    private $rebuilder;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_NGramNetworkOptionStore */
    private $optionStore;

    /** @var ABJ_404_Solution_CronScheduler */
    private $cronScheduler;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param mixed $rebuilder Object exposing rebuildCache().
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_NGramNetworkOptionStore $optionStore
     * @param ABJ_404_Solution_CronScheduler|null $cronScheduler
     */
    public function __construct($dbCore, $rebuilder, $logger, $optionStore, ?ABJ_404_Solution_CronScheduler $cronScheduler = null) {
        $this->dbCore = $dbCore;
        $this->rebuilder = $rebuilder;
        $this->logger = $logger;
        $this->optionStore = $optionStore;
        $this->cronScheduler = $cronScheduler instanceof ABJ_404_Solution_CronScheduler
            ? $cronScheduler
            : abj_cron_scheduler();
    }

    /**
     * Enqueue a single cron-driven rebuild if one is not already
     * pending or in progress.
     *
     * @return bool true when scheduling succeeded or was a no-op
     *              because a rebuild is already pending/in progress;
     *              false when WP-Cron rejected the schedule call.
     */
    public function scheduleRebuild() {
        $rawCurrentOffset = $this->optionStore->getOption('abj404_ngram_rebuild_offset', 0);
        $currentOffset = is_scalar($rawCurrentOffset) ? (int)$rawCurrentOffset : 0;

        $totalPages = $this->countTotalPagesForRebuild();

        // A positive offset means a prior batch started. If total-page counting
        // is unavailable, skip scheduling rather than resetting in-flight state.
        if ($currentOffset > 0 && ($totalPages <= 0 || $currentOffset < $totalPages)) {
            $this->logger->debugMessage("N-gram cache rebuild already in progress at offset {$currentOffset} of {$totalPages}");
            return true;
        }

        $hookName = self::REBUILD_CRON_HOOK;
        $nextScheduled = $this->cronScheduler->nextScheduled($hookName);
        if ($nextScheduled) {
            $this->logger->debugMessage("N-gram cache rebuild already scheduled for " . date('Y-m-d H:i:s', $nextScheduled));
            return true;
        }

        $this->optionStore->updateOption('abj404_ngram_rebuild_offset', 0);

        $scheduleTime = $this->cronScheduler->now() + 30;
        $scheduled = $this->cronScheduler->scheduleSingle($hookName, 30);

        if ($scheduled === false) {
            $this->reportScheduleFailure($hookName, $scheduleTime);
            return false;
        }

        $context = is_multisite() ? ' (network-wide)' : '';
        $this->logger->infoMessage("N-gram cache rebuild scheduled to start in 30 seconds{$context}.");
        return true;
    }

    /**
     * WP-Cron callback: process one chunk of rebuild batches and
     * reschedule for the next chunk until the entire content set is
     * covered. Multisite-aware: drains one site at a time before
     * moving on.
     *
     * @return void
     */
    public function runAsyncBatch() {
        $batchSize = 50;
        $maxBatchesPerRun = 20;

        if ($this->optionStore->isNetworkActivated()) {
            $this->runMultisiteBatch($batchSize, $maxBatchesPerRun);
        } else {
            $this->runSingleSiteBatch($batchSize, $maxBatchesPerRun);
        }
    }

    /**
     * Count the total number of permalink-cache rows the rebuild has
     * to cover. In a network-activated multisite install this sums
     * across every site; in single-site it returns the current site
     * only.
     *
     * @return int
     */
    public function countTotalPagesForRebuild() {
        if (!$this->optionStore->isNetworkActivated()) {
            $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');
            return $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$permalinkCacheTable}");
        }

        $sites = get_sites(array('fields' => 'ids', 'number' => 0));
        $totalPages = 0;

        foreach ($sites as $blog_id) {
            switch_to_blog($blog_id);
            $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');
            $totalPages += $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$permalinkCacheTable}");
            restore_current_blog();
        }

        return $totalPages;
    }

    /**
     * Per-batch worker for multisite: switch to a pending site,
     * process up to $maxBatchesPerRun batches of $batchSize, then
     * either advance to the next site (when current site is drained)
     * or reschedule for the next chunk of the same site.
     */
    private function runMultisiteBatch(int $batchSize, int $maxBatchesPerRun): void {
        $pendingSitesRaw = $this->optionStore->getOption('abj404_ngram_pending_sites', null);
        /** @var array<int, int> $pendingSites */
        $pendingSites = is_array($pendingSitesRaw) ? $pendingSitesRaw : [];

        if ($pendingSitesRaw === null) {
            // First run: Initialize site list and tracking
            $sites = get_sites(array('fields' => 'ids', 'number' => 0));
            $this->optionStore->updateOption('abj404_ngram_pending_sites', $sites);
            $this->optionStore->updateOption('abj404_ngram_total_sites', count($sites));
            $this->optionStore->updateOption('abj404_ngram_current_site_offset', 0);
            $pendingSites = $sites;
        }

        if (empty($pendingSites)) {
            // All sites processed!
            $this->optionStore->updateOption('abj404_ngram_cache_initialized', '1');
            $this->optionStore->updateOption('abj404_ngram_pending_sites', null);
            $this->optionStore->updateOption('abj404_ngram_total_sites', null);
            $this->optionStore->updateOption('abj404_ngram_current_site_offset', null);
            $this->logger->infoMessage("N-gram cache rebuild complete for all sites in network!");
            return;
        }

        $currentSiteId = (int)$pendingSites[0];
        $rawOffset = $this->optionStore->getOption('abj404_ngram_current_site_offset', 0);
        $offset = is_scalar($rawOffset) ? (int)$rawOffset : 0;
        $rawTotalSites = $this->optionStore->getOption('abj404_ngram_total_sites', count($pendingSites));
        $totalSites = is_scalar($rawTotalSites) ? (int)$rawTotalSites : count($pendingSites);
        $completedSites = $totalSites - count($pendingSites);

        switch_to_blog($currentSiteId);

        $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');
        $sitePages = $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$permalinkCacheTable}");

        if ($sitePages == 0) {
            array_shift($pendingSites);
            $this->optionStore->updateOption('abj404_ngram_pending_sites', $pendingSites);
            $this->optionStore->updateOption('abj404_ngram_current_site_offset', 0);
            restore_current_blog();

            $this->logger->infoMessage(sprintf(
                "Site %d has no pages. Moving to next site. Progress: %d/%d sites completed.",
                $currentSiteId,
                $completedSites + 1,
                $totalSites
            ));

            $this->cronScheduler->scheduleSingle(self::REBUILD_CRON_HOOK);
            return;
        }

        $this->logger->infoMessage(sprintf(
            "Processing N-gram cache for site %d (Site %d of %d): Offset %d of %d pages",
            $currentSiteId,
            $completedSites + 1,
            $totalSites,
            $offset,
            $sitePages
        ));

        $batchesProcessed = 0;
        $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];

        while ($batchesProcessed < $maxBatchesPerRun && $offset < $sitePages) {
            try {
                $stats = $this->runRebuildBatch($batchSize, $offset);

                $totalStats['processed'] += $stats['processed'];
                $totalStats['success'] += $stats['success'];
                $totalStats['failed'] += $stats['failed'];

                $offset += $batchSize;
                $batchesProcessed++;

                $this->optionStore->updateOption('abj404_ngram_current_site_offset', $offset);

                if ($stats['processed'] < $batchSize) {
                    break;
                }

            } catch (Exception $e) {
                $this->logger->errorMessage("Error during N-gram rebuild for site {$currentSiteId} at offset {$offset}: " . $e->getMessage());
                $totalStats['failed'] += $batchSize;
                $offset += $batchSize;
                $batchesProcessed++;
                $this->optionStore->updateOption('abj404_ngram_current_site_offset', $offset);
            }
        }

        $progress = $sitePages > 0 ? min(100, round(($offset / $sitePages) * 100, 1)) : 100;

        $this->logger->infoMessage(sprintf(
            "Site %d progress: %d%% complete (%d/%d pages), %d success, %d failed",
            $currentSiteId,
            $progress,
            $offset,
            $sitePages,
            $totalStats['success'],
            $totalStats['failed']
        ));

        if ($offset >= $sitePages) {
            array_shift($pendingSites);
            $this->optionStore->updateOption('abj404_ngram_pending_sites', $pendingSites);
            $this->optionStore->updateOption('abj404_ngram_current_site_offset', 0);

            $this->logger->infoMessage(sprintf(
                "Site %d complete! Progress: %d/%d sites completed.",
                $currentSiteId,
                $completedSites + 1,
                $totalSites
            ));
        }

        restore_current_blog();

        $this->cronScheduler->scheduleSingle(self::REBUILD_CRON_HOOK, 10);
    }

    /**
     * Per-batch worker for single-site: process up to
     * $maxBatchesPerRun batches against the current site, then either
     * complete (mark initialized) or reschedule for the next chunk.
     */
    private function runSingleSiteBatch(int $batchSize, int $maxBatchesPerRun): void {
        $rawSingleOffset = $this->optionStore->getOption('abj404_ngram_rebuild_offset', 0);
        $offset = is_scalar($rawSingleOffset) ? (int)$rawSingleOffset : 0;
        $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');
        $totalPages = $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$permalinkCacheTable}");

        if ($totalPages == 0) {
            $this->logger->debugMessage("No pages to process. Setting initialized flag.");
            $this->optionStore->updateOption('abj404_ngram_cache_initialized', '1');
            $this->optionStore->updateOption('abj404_ngram_rebuild_offset', 0);
            return;
        }

        $this->logger->infoMessage(sprintf(
            "Async N-gram rebuild: Processing batch at offset %d of %d total pages",
            $offset,
            $totalPages
        ));

        $batchesProcessed = 0;
        $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];

        while ($batchesProcessed < $maxBatchesPerRun && $offset < $totalPages) {
            try {
                $stats = $this->runRebuildBatch($batchSize, $offset);

                $totalStats['processed'] += $stats['processed'];
                $totalStats['success'] += $stats['success'];
                $totalStats['failed'] += $stats['failed'];

                $offset += $batchSize;
                $batchesProcessed++;

                $this->optionStore->updateOption('abj404_ngram_rebuild_offset', $offset);

                if ($stats['processed'] < $batchSize) {
                    break;
                }

            } catch (Exception $e) {
                $this->logger->errorMessage("Error during async N-gram cache rebuild at offset {$offset}: " . $e->getMessage());
                $totalStats['failed'] += $batchSize;
                $offset += $batchSize;
                $batchesProcessed++;
                $this->optionStore->updateOption('abj404_ngram_rebuild_offset', $offset);
            }
        }

        $progress = $totalPages > 0 ? min(100, round(($offset / $totalPages) * 100, 1)) : 100;

        $this->logger->infoMessage(sprintf(
            "Async N-gram rebuild progress: %d%% complete (%d/%d pages), %d success, %d failed",
            $progress,
            $offset,
            $totalPages,
            $totalStats['success'],
            $totalStats['failed']
        ));

        if ($offset < $totalPages) {
            $scheduleTime = $this->cronScheduler->now() + 10;
            $hookName = self::REBUILD_CRON_HOOK;
            $scheduled = $this->cronScheduler->scheduleSingle($hookName, 10, [$offset]);

            if ($scheduled === false) {
                $this->reportRescheduleFailure($hookName, $scheduleTime, $offset, $progress);
            }
        } else {
            $this->optionStore->updateOption('abj404_ngram_cache_initialized', '1');
            $this->optionStore->updateOption('abj404_ngram_rebuild_offset', 0);
            $this->logger->infoMessage("N-gram cache rebuild complete! Total: {$totalStats['processed']} processed, {$totalStats['success']} success, {$totalStats['failed']} failed.");
        }
    }

    /**
     * Initial schedule failed: emit a diagnostic error log and, if a
     * concurrent infra DB error is in play, surface it through the
     * plugin-page admin-notice classifier.
     */
    private function reportScheduleFailure(string $hookName, int $scheduleTime): void {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $this->logger->errorMessage(
                "Cannot schedule N-gram cache rebuild: WP-Cron is disabled (DISABLE_WP_CRON=true). " .
                "Consider enabling WP-Cron or using server-side cron with a fallback mechanism."
            );
            return;
        }

        global $wpdb;

        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $alreadyScheduled = $this->cronScheduler->nextScheduled($hookName);
        $dbError = !empty($wpdb->last_error) ? $wpdb->last_error : 'none';
        $rawRebuildOffset = $this->optionStore->getOption('abj404_ngram_rebuild_offset', 'not set');
        $rebuildOffset = is_scalar($rawRebuildOffset) ? (string)$rawRebuildOffset : 'not set';
        $rawCacheInit = $this->optionStore->getOption('abj404_ngram_cache_initialized', 'not set');
        $cacheInitialized = is_scalar($rawCacheInit) ? (string)$rawCacheInit : 'not set';

        $errorMsg = sprintf(
            "Failed to schedule N-gram cache rebuild. Hook: %s, Schedule time: %d (current: %d), " .
            "Already scheduled: %s, WP-Cron disabled: %s, DB error: %s, " .
            "Rebuild offset: %s, Cache initialized: %s, Multisite: %s, Blog ID: %d",
            $hookName,
            $scheduleTime,
            $this->cronScheduler->now(),
            $alreadyScheduled ? date('Y-m-d H:i:s', $alreadyScheduled) : 'no',
            $cronDisabled ? 'yes' : 'no',
            $dbError,
            $rebuildOffset,
            $cacheInitialized,
            is_multisite() ? 'yes' : 'no',
            get_current_blog_id()
        );

        // Pattern 7 (defense-in-depth): a concurrent infra-level DB
        // error (disk full, read-only, crashed table) may have
        // contributed to wp_schedule_single_event() failing — surface
        // the hosting cause as an admin notice while keeping the cron
        // failure ERROR level so the user must act on it.
        if (!empty($wpdb->last_error)) {
            $this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($wpdb->last_error);
        }

        $this->logger->errorMessage($errorMsg);
    }

    /**
     * Re-schedule for the next chunk failed: emit a diagnostic error
     * log (no return value matters; the in-flight batch already
     * committed its work).
     */
    private function reportRescheduleFailure(string $hookName, int $scheduleTime, int $offset, float $progress): void {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $this->logger->errorMessage(
                "Cannot schedule next N-gram rebuild batch at offset {$offset}: WP-Cron is disabled (DISABLE_WP_CRON=true). " .
                "Consider enabling WP-Cron or using server-side cron with a fallback mechanism."
            );
            return;
        }

        global $wpdb;

        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $alreadyScheduled = $this->cronScheduler->nextScheduled($hookName, [$offset]);
        $dbError = !empty($wpdb->last_error) ? $wpdb->last_error : 'none';
        $rawCacheInit2 = $this->optionStore->getOption('abj404_ngram_cache_initialized', 'not set');
        $cacheInitialized = is_scalar($rawCacheInit2) ? (string)$rawCacheInit2 : 'not set';

        $errorMsg = sprintf(
            "Failed to schedule next N-gram rebuild batch at offset %d. Hook: %s, Schedule time: %d (current: %d), " .
            "Already scheduled: %s, WP-Cron disabled: %s, DB error: %s, " .
            "Cache initialized: %s, Progress: %.1f%%, Multisite: %s, Blog ID: %d",
            $offset,
            $hookName,
            $scheduleTime,
            $this->cronScheduler->now(),
            $alreadyScheduled ? date('Y-m-d H:i:s', $alreadyScheduled) : 'no',
            $cronDisabled ? 'yes' : 'no',
            $dbError,
            $cacheInitialized,
            $progress,
            is_multisite() ? 'yes' : 'no',
            get_current_blog_id()
        );

        if (!empty($wpdb->last_error)) {
            $this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($wpdb->last_error);
        }

        $this->logger->errorMessage($errorMsg);
    }

    /**
     * @param int $batchSize
     * @param int $offset
     * @return array{processed: int, success: int, failed: int}
     */
    private function runRebuildBatch(int $batchSize, int $offset): array {
        $rebuilder = $this->rebuilder;
        if (!is_object($rebuilder) || !method_exists($rebuilder, 'rebuildCache')) {
            throw new RuntimeException('NGramCacheRebuildScheduler requires a rebuilder with rebuildCache().');
        }
        $stats = $rebuilder->rebuildCache($batchSize, $offset);
        return [
            'processed' => is_array($stats) && isset($stats['processed']) && is_numeric($stats['processed']) ? (int)$stats['processed'] : 0,
            'success' => is_array($stats) && isset($stats['success']) && is_numeric($stats['success']) ? (int)$stats['success'] : 0,
            'failed' => is_array($stats) && isset($stats['failed']) && is_numeric($stats['failed']) ? (int)$stats['failed'] : 0,
        ];
    }
}
