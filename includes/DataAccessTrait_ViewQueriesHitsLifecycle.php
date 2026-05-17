<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hits-table lifecycle helpers extracted from
 * ABJ_404_Solution_DataAccess_ViewQueriesTrait to keep the parent trait under
 * the ModularityTest CLASS_LIMIT. Owns the *scheduling, locking, and
 * existence-probe* concerns for the `{wp_abj404_logs_hits}` rollup table,
 * plus the small log-id watermark helpers (getMaxLogId / getMinLogId /
 * getStoredMaxLogId) the scheduling path consults. The *rebuild* path
 * itself lives in ABJ_404_Solution_DataAccess_LogsHitsRebuildTrait
 * (DataAccessTrait_LogsHitsRebuild.php).
 *
 * Composed into ABJ_404_Solution_DataAccess alongside the other DAO traits.
 * Depends on host-class state and methods provided by the rest of DAO:
 * `queryAndGetResults`, `doTableNameReplacements`, `getRuntimeFlag`,
 * `setRuntimeFlag`, `getLowercasePrefix`, `shouldSkipNonEssentialDbWrites`,
 * `recordLogsHitsRollupStalenessSignal`, `hitsTableNeedsRebuild`,
 * `createRedirectsForViewHitsTable`, `getLogsHitsTableStatusRow`, the
 * `$logger` property, the `$hitsTableRebuildScheduled` static, and the
 * HITS_TABLE_* constants on the host class.
 */
trait ABJ_404_Solution_DataAccess_ViewQueriesHitsLifecycleTrait {

    /** @return void */
    function maybeUpdateRedirectsForViewHitsTable(): void {
        // Record that we checked during this request (used for admin tooltip UX).
        $this->setRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG, time(), 86400);

        // Piggyback on the captured-404s tab render: also schedule a
        // 15-second logsv2.canonical_url backfill at shutdown if there's
        // legacy NULL-row backlog. The shutdown handler holds a worker
        // for the budget but the admin response is already flushed by
        // fastcgi_finish_request, so the user doesn't perceive the wait.
        // The function is internally deduped + gated on column existence,
        // probe results, and the backfill-complete option, so calling it
        // unconditionally is cheap.
        if (function_exists('abj_service')) {
            $upgradesEtc = abj_service('database_upgrades');
            if (is_object($upgradesEtc) && method_exists($upgradesEtc, 'scheduleLogsv2CanonicalUrlBackfill')) {
                $upgradesEtc->scheduleLogsv2CanonicalUrlBackfill();
            }
        }

        if ($this->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return;
        }

        // Check if the table exists
        if (!$this->logsHitsTableExists()) {
            // Defer creation to shutdown hook so the admin page loads immediately.
            // The view query gracefully falls back to null hits columns when the
            // table doesn't exist (getRedirectsForViewQuery checks logsHitsTableExists).
            // On sites with large logsv2 tables the INSERT...SELECT that populates
            // the hits table can take minutes, which exceeds proxy timeouts (e.g.
            // Cloudflare's 100-second limit → HTTP 524).
            $this->logger->debugMessage(__FUNCTION__ . " table doesn't exist, deferring creation to shutdown hook.");
            $this->scheduleHitsTableRebuild();
            return;
        }

        // Diagnostic: track the max_log_id age signal so a stalled rollup
        // surfaces a broken-cron admin notice instead of silently showing
        // stale hit-count columns. Self-heals when the gap closes.
        $this->recordLogsHitsRollupStalenessSignal();

        // Check if rebuild is needed (logs have changed since last build)
        if (!$this->hitsTableNeedsRebuild()) {
            // No new log entries - skip rebuild to reduce server load
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'not_needed', 86400);
            return;
        }

        // Table exists and logs have changed - defer to shutdown hook
        $this->scheduleHitsTableRebuild();
    }

    /**
     * Schedule the hits table to be rebuilt at shutdown.
     *
     * Uses a static flag to ensure the hook is only registered once per request,
     * even if multiple calls to getRedirectsForView with hits sorting occur.
     *
     * The shutdown hook runs after the response is sent, so the admin sees the page
     * immediately with existing data, and fresh data is available on next load.
     */
    /** @return void */
    function scheduleHitsTableRebuild(): void {
        if ($this->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return;
        }
        if (!self::$hitsTableRebuildScheduled) {
            if ($this->isHitsTableRebuildLocked()) {
                $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling because another rebuild is already running.");
                $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'running', 86400);
                return;
            }

            $rawScheduledFlag = $this->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG);
            $lastScheduled = is_scalar($rawScheduledFlag) ? (int)$rawScheduledFlag : 0;
            if ($lastScheduled > 0 && (time() - $lastScheduled) < self::HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS) {
                $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling due to cooldown.");
                $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'cooldown', 86400);
                return;
            }

            self::$hitsTableRebuildScheduled = true;
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG, time(), 86400);
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'scheduled', 86400);
            if ($this->shouldScheduleHitsTableRebuildViaCron()) {
                $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild via WP-Cron.");
                if (function_exists('wp_schedule_single_event')) {
                    wp_schedule_single_event(time() + 5, 'abj404_updateLogsHitsTableAction');
                }
                return;
            }

            $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild for shutdown hook.");
            add_action('shutdown', function(): void { $this->createRedirectsForViewHitsTable(); });
        }
    }

    /** @return bool */
    private function shouldScheduleHitsTableRebuildViaCron(): bool {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? $_SERVER['SCRIPT_NAME'] : '';
        if ($scriptName !== '' && basename($scriptName) === 'admin-ajax.php') {
            return true;
        }
        $pagenow = isset($GLOBALS['pagenow']) && is_string($GLOBALS['pagenow'])
            ? $GLOBALS['pagenow'] : '';
        return $pagenow === 'admin-ajax.php';
    }

    private function getHitsTableRebuildLockOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_logs_hits_rebuild_lock';
    }

    /** @return bool */
    private function isHitsTableRebuildLocked(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $lockValue = get_option($this->getHitsTableRebuildLockOptionName(), false);
        if ($lockValue === false || $lockValue === null || $lockValue === '') {
            return false;
        }
        // Defensive: if lock is corrupted (non-numeric), clear it so rebuilds can resume.
        if (!is_numeric($lockValue)) {
            if (function_exists('delete_option')) {
                delete_option($this->getHitsTableRebuildLockOptionName());
            }
            return false;
        }
        $lockTimestamp = (int)$lockValue;
        if ($lockTimestamp > 0 && (time() - $lockTimestamp) > self::HITS_TABLE_REBUILD_LOCK_TTL_SECONDS) {
            if (function_exists('delete_option')) {
                delete_option($this->getHitsTableRebuildLockOptionName());
            }
            return false;
        }
        return true;
    }

    /** @return int|null */
    function getLogsHitsTableLastCheckedAt() {
        $rawTsFlag = $this->getRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG);
        $ts = is_scalar($rawTsFlag) ? (int)$rawTsFlag : 0;
        return $ts > 0 ? $ts : null;
    }

    /** @return int|null */
    function getLogsHitsTableLastScheduledAt() {
        $rawTsFlag2 = $this->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG);
        $ts = is_scalar($rawTsFlag2) ? (int)$rawTsFlag2 : 0;
        return $ts > 0 ? $ts : null;
    }

    /** @return string */
    function getLogsHitsTableLastDecision(): string {
        $v = $this->getRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG);
        return is_string($v) ? $v : '';
    }

    /** @return bool */
    private function acquireHitsTableRebuildLock(): bool {
        if (!function_exists('add_option')) {
            return true;
        }
        if ($this->isHitsTableRebuildLocked()) {
            return false;
        }
        return (bool)add_option(
            $this->getHitsTableRebuildLockOptionName(),
            (string)time(),
            '',
            false
        );
    }

    /** @return void */
    private function releaseHitsTableRebuildLock(): void {
        if (function_exists('delete_option')) {
            delete_option($this->getHitsTableRebuildLockOptionName());
        }
    }

    /** @return bool */
    private function logsHitsTableExistsViaShowTables(): bool {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return false;
        }
        $tableName = $this->doTableNameReplacements('{wp_abj404_logs_hits}');
        /** @var wpdb $wpdb */
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; the resulting SQL is executed below via queryAndGetResults
        $showTablesQuery = $wpdb->prepare("SHOW TABLES LIKE %s", $tableName);
        if ($showTablesQuery === null) {
            return false;
        }
        $fallback = $this->queryAndGetResults($showTablesQuery, array('log_errors' => false));
        if (empty($fallback['rows'])) {
            return false;
        }
        $fbRows = is_array($fallback['rows']) ? $fallback['rows'] : array();
        $firstRow = isset($fbRows[0]) ? $fbRows[0] : null;
        if (!is_array($firstRow)) {
            return false;
        }
        $value = reset($firstRow);
        return ((string)$value === (string)$tableName);
    }

    /**
     * Check if the logs_hits table exists.
     * Used to verify table was created before using it in queries.
     * @return bool
     */
    function logsHitsTableExists() {
        $query = "SELECT 1 FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE() LIMIT 1";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        if ($results['rows'] != null && !empty($results['rows'])) {
            return true;
        }
        if (!empty($results['last_error'])) {
            // Some hosts restrict information_schema access; fall back to SHOW TABLES.
            return $this->logsHitsTableExistsViaShowTables();
        }
        return false;
    }

    /**
     * Get the maximum log ID from the logs table.
     *
     * Used to detect if logs have changed since the hits table was last built.
     * O(1) query using primary key index.
     *
     * @return int Maximum log ID, or 0 if table is empty
     */
    function getMaxLogId() {
        $query = "SELECT MAX(id) FROM {wp_abj404_logsv2}";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) {
            return 0;
        }

        $row = $resultRows[0];
        // Handle both object and array results
        $maxId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return (int)($maxId ?? 0);
    }

    /** @return int */
    function getMinLogId() {
        $query = "SELECT MIN(id) FROM {wp_abj404_logsv2}";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) {
            return 0;
        }

        $row = $resultRows[0];
        $minId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return is_numeric($minId) ? (int)$minId : 0;
    }

    /**
     * Get the stored max log ID from the hits table comment.
     *
     * Comment format: "elapsed_time|max_log_id" (e.g., "0.35|12345")
     *
     * @return int Stored max log ID, or 0 if not found
     */
    function getStoredMaxLogId() {
        $query = "SELECT table_comment FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        $storedRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($storedRows)) {
            if (!empty($results['last_error'])) {
                $statusRow = $this->getLogsHitsTableStatusRow();
                $commentFromStatus = $statusRow['comment'] ?? '';
                if ($commentFromStatus !== '') {
                    $parts = explode('|', is_string($commentFromStatus) ? $commentFromStatus : '');
                    if (count($parts) >= 2) {
                        return (int)$parts[1];
                    }
                }
            }
            return 0;
        }

        $row = is_array($storedRows[0] ?? null) ? $storedRows[0] : array();
        $row = array_change_key_case($row);
        $comment = $row['table_comment'] ?? '';

        // Parse comment format: "elapsed_time|max_log_id"
        $parts = explode('|', is_string($comment) ? $comment : '');
        if (count($parts) >= 2) {
            return (int)$parts[1];
        }

        // Old format (just elapsed time) or empty - treat as needing rebuild
        return 0;
    }
}
