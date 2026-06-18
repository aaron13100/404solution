<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public contract for the wp_abj404_logs_hits rollup lifecycle.
 *
 * Extracted from LogsRepository under M201. Owns:
 *   - existence/readiness checks for the rollup table
 *   - rollup rebuild (direct and chunked pipelines)
 *   - rebuild scheduling (cron + shutdown) with per-request dedupe
 *   - cross-request rebuild locking
 *   - staleness signaling (admin notice when cron is not running)
 *   - logsv2 max/min/stored-max id reads used by drift detection and read paths
 *   - last-scheduled / last-refreshed flags
 *
 * Log writes, log read queries, GDPR anonymization, and lookup-table CRUD
 * stay on LogsRepository. LogsRepository forwards the rollup-lifecycle
 * interface methods to this service.
 */
interface ABJ_404_Solution_LogsHitsRollupServiceInterface {

    /** @return void */
    public function recordLogsHitsRollupStalenessSignal(): void;

    /** @return bool */
    public function hitsTableNeedsRebuild();

    /** @return int|null Unix timestamp of last update, or null if table doesn't exist */
    public function getLogsHitsTableLastUpdated();

    /** @return bool */
    public function createRedirectsForViewHitsTable(): bool;

    /** @return bool */
    public function logsHitsTableExists();

    /** @return void */
    public function scheduleHitsTableRebuild(): void;

    /** @return int */
    public function getMaxLogId();

    /** @return int */
    public function getMinLogId();

    /** @return int */
    public function getStoredMaxLogId();

    /** @return int|null */
    public function getLogsHitsTableLastScheduledAt();
}
