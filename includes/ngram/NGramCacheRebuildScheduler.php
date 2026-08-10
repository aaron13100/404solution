<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether the asynchronous N-gram cache rebuild needs to be started
 * or resumed, and arms the WP-Cron chain when it does.
 *
 * Arming is a decision with many callers -- the 404 request path on an empty
 * cache, the daily reconciler when its backlog is beyond incremental repair,
 * the admin rebuild button, the activation/upgrade initializer -- and it is
 * the half of the rebuild lifecycle where the "is a rebuild already running?"
 * question has to be answered correctly. Running the batches once armed is a
 * separate concern with exactly one caller (the cron callback) and lives in
 * {@see ABJ_404_Solution_NGramCacheRebuildBatchRunner}.
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

    /** WP-Cron hook this scheduler enqueues. */
    const REBUILD_CRON_HOOK = 'abj404_rebuild_ngram_cache_hook';

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_NGramNetworkOptionStore */
    private $optionStore;

    /** @var ABJ_404_Solution_CronScheduler */
    private $cronScheduler;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_NGramNetworkOptionStore $optionStore
     * @param ABJ_404_Solution_CronScheduler|null $cronScheduler
     */
    public function __construct($dbCore, $logger, $optionStore, ?ABJ_404_Solution_CronScheduler $cronScheduler = null) {
        $this->dbCore = $dbCore;
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
     * "In progress" is decided by whether the cron chain is still armed, NOT
     * by whether the offset is non-zero. A partially-advanced offset with no
     * queued event is a rebuild whose chain DIED (cron refused, request
     * killed, plugin update mid-walk); reading that as "already in progress"
     * is what left wedged rebuilds unrecoverable for months, because every
     * caller that asked for a rebuild -- the 404 request path, the daily
     * reconciler, the activation initializer -- was told one was already
     * running. A stalled chain is resumed from its own offset rather than
     * restarted from zero, so no completed work is repeated.
     *
     * @return bool true when scheduling succeeded or was a no-op
     *              because a rebuild is already pending/in progress;
     *              false when WP-Cron rejected the schedule call.
     */
    public function scheduleRebuild() {
        $rawCurrentOffset = $this->optionStore->getOption('abj404_ngram_rebuild_offset', 0);
        $currentOffset = is_scalar($rawCurrentOffset) ? (int)$rawCurrentOffset : 0;

        $hookName = self::REBUILD_CRON_HOOK;
        $armedAt = $this->armedRebuildTimestamp($hookName, $currentOffset);
        if ($armedAt !== false) {
            $this->logger->debugMessage("N-gram cache rebuild already scheduled for " . date('Y-m-d H:i:s', $armedAt));
            return true;
        }

        $totalPages = $this->countTotalPagesForRebuild();

        // A positive offset with nothing queued is a stalled walk. Resume it
        // where it stopped. If total-page counting is unavailable we cannot
        // tell "stalled mid-walk" from "finished", so treat in-flight state as
        // resumable rather than discarding it.
        $resuming = $currentOffset > 0 && ($totalPages <= 0 || $currentOffset < $totalPages);

        if ($resuming) {
            $this->logger->infoMessage(
                "N-gram cache rebuild stalled at offset {$currentOffset} of {$totalPages} with no queued event. Resuming.");
        } else {
            $this->optionStore->updateOption('abj404_ngram_rebuild_offset', 0);
        }

        $scheduleTime = $this->cronScheduler->now() + 30;
        // Resumed events carry the offset as their cron argument, exactly like
        // the chain's own reschedules, so armedRebuildTimestamp() recognizes
        // them and a second caller cannot start a parallel chain.
        $scheduled = $this->cronScheduler->scheduleSingle($hookName, 30, $resuming ? [$currentOffset] : []);

        if ($scheduled === false) {
            $this->reportScheduleFailure($hookName, $scheduleTime);
            return false;
        }

        $context = is_multisite() ? ' (network-wide)' : '';
        $this->logger->infoMessage("N-gram cache rebuild scheduled to start in 30 seconds{$context}.");
        return true;
    }

    /**
     * Timestamp of the queued rebuild event, or false when the chain is not
     * armed.
     *
     * Two probes are needed because WP-Cron identifies an event by hook AND
     * arguments: the first tick of a chain (and every multisite reschedule) is
     * enqueued with no arguments, while a single-site chain in flight
     * reschedules itself as scheduleSingle($hook, <retry delay>, [$offset]) --
     * the delay varies with the chain's backoff, the arguments do not. A no-args
     * probe alone cannot see an in-flight chain, so it would report every
     * healthy mid-walk rebuild as unarmed and spawn a second chain beside it.
     *
     * @param string $hookName
     * @param int $currentOffset
     * @return int|false
     */
    private function armedRebuildTimestamp(string $hookName, int $currentOffset) {
        $nextScheduled = $this->cronScheduler->nextScheduled($hookName);
        if ($nextScheduled !== false) {
            return $nextScheduled;
        }
        if ($currentOffset > 0) {
            $nextForOffset = $this->cronScheduler->nextScheduled($hookName, [$currentOffset]);
            if ($nextForOffset !== false) {
                return $nextForOffset;
            }
        }
        return false;
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
        // contributed to wp_schedule_single_event() failing: surface
        // the hosting cause as an admin notice while keeping the cron
        // failure ERROR level so the user must act on it.
        if (!empty($wpdb->last_error)) {
            $this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($wpdb->last_error);
        }

        $this->logger->errorMessage($errorMsg);
    }

}
