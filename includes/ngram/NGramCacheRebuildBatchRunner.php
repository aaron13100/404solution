<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs the N-gram cache rebuild: one bounded chunk of batches per WP-Cron
 * tick, advancing the rebuild cursor and rescheduling the chain until the
 * whole content set is covered.
 *
 * Reached only from the cron callback
 * (ABJ_404_Solution_DatabaseUpgradeNGram::rebuildNGramCacheAsync). Deciding
 * WHETHER a rebuild needs to start or resume is a separate concern with
 * different callers -- the 404 request path, the daily reconciler, the admin
 * rebuild button, the activation initializer -- and lives in
 * {@see ABJ_404_Solution_NGramCacheRebuildScheduler}.
 *
 * Multisite-aware: a network-activated install drains one site at a time,
 * tracking pending sites and the per-site cursor through the network option
 * store, so a large network converges across ticks without ever holding more
 * than one site's batch in memory.
 *
 * Lock acquisition is owned by the orchestrator (DatabaseUpgradeNGram). This
 * collaborator assumes the 'ngram_rebuild' SyncUtils lock is already held when
 * its methods are called.
 */
class ABJ_404_Solution_NGramCacheRebuildBatchRunner {

    /**
     * WP-Cron hook this runner reschedules itself on. The canonical
     * definition lives on the cron adapter that owns hook names
     * (ABJ_404_Solution_CronScheduler::HOOK_REBUILD_NGRAM_CACHE); the literal
     * is repeated here so the Pattern 8 coordination-key audit can see the
     * key in the file that enqueues it.
     */
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
            throw new RuntimeException('NGramCacheRebuildBatchRunner requires a rebuilder with rebuildCache().');
        }
        $stats = $rebuilder->rebuildCache($batchSize, $offset);
        return [
            'processed' => is_array($stats) && isset($stats['processed']) && is_numeric($stats['processed']) ? (int)$stats['processed'] : 0,
            'success' => is_array($stats) && isset($stats['success']) && is_numeric($stats['success']) ? (int)$stats['success'] : 0,
            'failed' => is_array($stats) && isset($stats['failed']) && is_numeric($stats['failed']) ? (int)$stats['failed'] : 0,
        ];
    }
}
