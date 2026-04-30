<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logs hits rollup rebuild path. Owns the lifecycle of the
 * `{wp_abj404_logs_hits}` table — staleness checks, rebuild orchestration,
 * and the two INSERT strategies (single-statement direct path for small
 * sites, chunked pre-aggregation for large sites). Split out of
 * DataAccessTrait_Logs in 4.1.10+ to keep the parent trait under the
 * file-size limit; the rebuild subsystem is a clear seam.
 *
 * Composed into ABJ_404_Solution_DataAccess alongside the other DAO traits.
 * Depends on host-class state and methods provided by the rest of DAO:
 * `queryAndGetResults`, `doTableNameReplacements`, `getMaxLogId`,
 * `getMinLogId`, `getStoredMaxLogId`, `getRuntimeFlag`, `setRuntimeFlag`,
 * `executeAsTransaction`, `shouldSkipNonEssentialDbWrites`,
 * `acquireHitsTableRebuildLock`, `releaseHitsTableRebuildLock`,
 * the `$logger` property, and HITS_TABLE_* constants on the host class.
 */
trait ABJ_404_Solution_DataAccess_LogsHitsRebuildTrait {

    /** @return bool */
    function hitsTableNeedsRebuild() {
        $storedMaxId = $this->getStoredMaxLogId();
        $currentMaxId = $this->getMaxLogId();

        // Check if log entries have changed
        if ($currentMaxId != $storedMaxId) {
            $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (max_id changed: stored=$storedMaxId, current=$currentMaxId)");
            return true;
        }

        // Check if table is too old (staleness check)
        $lastUpdated = $this->getLogsHitsTableLastUpdated();
        if ($lastUpdated !== null) {
            $age = time() - $lastUpdated;
            if ($age > self::HITS_TABLE_MAX_AGE_SECONDS) {
                $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (stale: age={$age}s > " . self::HITS_TABLE_MAX_AGE_SECONDS . "s)");
                return true;
            }
        }

        $this->logger->debugMessage(__FUNCTION__ . " rebuild=no (max_id=$currentMaxId unchanged, not stale)");
        return false;
    }

    /**
     * Get the last update time of the logs_hits table.
     *
     * Uses the MySQL table creation time from information_schema since
     * the table is dropped and recreated on each rebuild.
     *
     * @return int|null Unix timestamp of last update, or null if table doesn't exist
     */
    function getLogsHitsTableLastUpdated() {
        $rawRefreshedFlag = $this->getRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG);
        $runtimeRefreshedAt = is_scalar($rawRefreshedFlag) ? (int)$rawRefreshedFlag : 0;
        $runtimeRefreshedAt = $runtimeRefreshedAt > 0 ? $runtimeRefreshedAt : null;

        $query = "SELECT create_time FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        if ($results['rows'] == null || empty($results['rows'])) {
            if (!empty($results['last_error'])) {
                $statusRow = $this->getLogsHitsTableStatusRow();
                $dateValue = '';
                if (is_array($statusRow)) {
                    $dateValue = $statusRow['update_time'] ?? ($statusRow['create_time'] ?? '');
                }
                if ($dateValue !== '') {
                    $fallbackTimestamp = strtotime(is_string($dateValue) ? $dateValue : '');
                    if ($fallbackTimestamp !== false) {
                        if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $fallbackTimestamp) {
                            return $runtimeRefreshedAt;
                        }
                        return $fallbackTimestamp;
                    }
                }
            }
            return $runtimeRefreshedAt;
        }

        $hitsRows = is_array($results['rows']) ? $results['rows'] : array();
        $row = is_array($hitsRows[0] ?? null) ? $hitsRows[0] : array();
        $row = array_change_key_case($row);
        $createTime = $row['create_time'] ?? null;

        if ($createTime === null) {
            return $runtimeRefreshedAt;
        }

        // Convert MySQL datetime to Unix timestamp
        $schemaTimestamp = strtotime(is_string($createTime) ? $createTime : '');
        if ($schemaTimestamp === false) {
            return $runtimeRefreshedAt;
        }
        if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $schemaTimestamp) {
            return $runtimeRefreshedAt;
        }
        return $schemaTimestamp;
    }

    /** @return array<string, mixed> */
    private function getLogsHitsTableStatusRow() {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return array();
        }
        $tableName = $this->doTableNameReplacements('{wp_abj404_logs_hits}');
        /** @var wpdb $wpdb */
        $query = $wpdb->prepare("SHOW TABLE STATUS LIKE %s", $tableName);
        if ($query === null) {
            return array();
        }
        $results = $this->queryAndGetResults($query, array('log_errors' => false));
        if (!is_array($results['rows']) || empty($results['rows']) || !is_array($results['rows'][0])) {
            return array();
        }
        return array_change_key_case($results['rows'][0], CASE_LOWER);
    }

    /**
     * Get a human-readable "time ago" string for the hits table's last update.
     *
     * @return string e.g., "2 minutes ago", "1 hour ago", or empty string if unknown
     */
    function getLogsHitsTableLastUpdatedHuman() {
        $timestamp = $this->getLogsHitsTableLastUpdated();

        if ($timestamp === null) {
            return '';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return __('Just now', '404-solution');
        } elseif ($diff < 3600) {
            $minutes = (int)floor($diff / 60);
            return sprintf(_n('%d minute ago', '%d minutes ago', $minutes, '404-solution'), $minutes);
        } elseif ($diff < 86400) {
            $hours = (int)floor($diff / 3600);
            return sprintf(_n('%d hour ago', '%d hours ago', $hours, '404-solution'), $hours);
        } else {
            $days = (int)floor($diff / 86400);
            return sprintf(_n('%d day ago', '%d days ago', $days, '404-solution'), $days);
        }
    }

    /** @return bool */
    function createRedirectsForViewHitsTable(): bool {
        $wasRefreshed = false;
        if ($this->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return false;
        }
        if (!$this->acquireHitsTableRebuildLock()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild lock is already held.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'running', 86400);
            return false;
        }
        $preAggTable = $this->doTableNameReplacements("{wp_abj404_logs_hits}_preagg");
        try {

        $finalDestTable = $this->doTableNameReplacements("{wp_abj404_logs_hits}");
        $tempDestTable = $this->doTableNameReplacements("{wp_abj404_logs_hits}_temp");

        // create the temp output table
        $this->queryAndGetResults("drop table if exists " . $tempDestTable);
        $createTempTableQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
        	"/sql/createLogsHitsTempTable.sql");
        $createTempTableQuery = $this->doTableNameReplacements($createTempTableQuery);
        $this->queryAndGetResults($createTempTableQuery);
        // @cache-write-audit: opt-out — temp table internal to this rebuild
        // (`{wp_abj404_logs_hits}_temp`); no other code path reads it, so no
        // dependent caches exist to invalidate. The atomic swap to the live
        // `{wp_abj404_logs_hits}` table later in this function is the only
        // observable effect.
        $this->queryAndGetResults("truncate table " . $tempDestTable);

        // Capture a pre-insert snapshot watermark.
        // This keeps rebuild checks consistent with getMaxLogId() while avoiding
        // claiming coverage for rows that may arrive during/after the insert.
        $maxLogIdSnapshot = $this->getMaxLogId();
        $minLogId = $this->getMinLogId();
        $chunkSize = self::HITS_TABLE_PREAGG_CHUNK_SIZE;
        $idRange = $maxLogIdSnapshot - $minLogId;

        // Small-table fast path: if the entire logsv2 table fits in one chunk,
        // run the original single query (no pre-aggregation overhead).
        if ($idRange <= $chunkSize) {
            $results = $this->hitsTableInsertDirect($tempDestTable);
        } else {
            $results = $this->hitsTableInsertChunked(
                $tempDestTable, $preAggTable, $minLogId, $maxLogIdSnapshot, $chunkSize
            );
        }

        // If the query timed out or errored, don't replace the existing table with empty data.
        if ($results === false || !empty($results['timed_out']) || !empty($results['last_error'])) {
            $this->queryAndGetResults("drop table if exists " . $tempDestTable);
            $this->logger->debugMessage(__FUNCTION__ . " INSERT timed out or errored; aborting rebuild.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return false;
        }

        // Store elapsed time and max log ID in comment for invalidation check
        // Format: "elapsed_time|max_log_id" (e.g., "0.35|12345")
        $elapsedTime = $results['elapsed_time'];
        $comment = $elapsedTime . '|' . $maxLogIdSnapshot;
        // @utf8-audit: opt-out — $comment is internally composed from numeric
        // elapsed-time and integer max-log-id values; never user-controlled.
        // Escape comment and truncate to MySQL's 2048 char limit for table comments
        $comment = substr(esc_sql($comment), 0, 2048);
        $addComment = "ALTER TABLE " . $tempDestTable . " COMMENT '" . $comment . "'";
        $this->queryAndGetResults($addComment);

        // drop the old hits table and rename the temp table to the hits table as a transaction
        $statements = array(
            "drop table if exists " . $finalDestTable,
            "rename table " . $tempDestTable . ' to ' . $finalDestTable
        );
        $this->executeAsTransaction($statements);
        $this->setRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG, time(), 86400);
        $wasRefreshed = true;

        $this->logger->debugMessage(__FUNCTION__ . " refreshed " . $finalDestTable . " in " . $elapsedTime .
                " seconds.");
        } catch (Throwable $e) {
            // Never break the admin request because a shutdown refresh fails.
            $this->logger->errorMessage(__FUNCTION__ . " failed: " . $e->getMessage(), $e instanceof \Exception ? $e : null);
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
        } finally {
            $this->queryAndGetResults("drop table if exists " . $preAggTable);
            $this->releaseHitsTableRebuildLock();
        }
        return $wasRefreshed;
    }

    /**
     * Small-table fast path: single INSERT...SELECT with a DB-level timeout.
     *
     * @param string $tempDestTable
     * @return array<string, mixed>
     */
    private function hitsTableInsertDirect(string $tempDestTable): array {
        $ttSelectQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
            "/sql/getRedirectsForViewTempTable.sql");
        $ttSelectQuery = $this->doTableNameReplacements($ttSelectQuery);

        $ttInsertQuery = "insert into " . $tempDestTable . " (requested_url, logsid, " .
            "last_used, logshits, failed_hits) \n " . $ttSelectQuery;
        return $this->queryAndGetResults($ttInsertQuery, array('log_too_slow' => false, 'timeout' => 60));
    }

    /**
     * Large-table path: two-phase chunked pre-aggregation.
     *
     * Phase 1: chunk through logsv2 by ID range, aggregating each chunk into a
     * pre-agg table (no join — uses PRIMARY KEY index, fast).
     *
     * Phase 2: join the small pre-agg table with redirects (same concat/trim
     * normalization) and re-aggregate across chunks into the final temp table.
     *
     * @param string $tempDestTable
     * @param string $preAggTable
     * @param int $minId
     * @param int $maxId
     * @param int $chunkSize
     * @return array<string, mixed>|false False on chunk error
     */
    private function hitsTableInsertChunked(
        string $tempDestTable, string $preAggTable,
        int $minId, int $maxId, int $chunkSize
    ) {
        $logsv2Table = $this->doTableNameReplacements("{wp_abj404_logsv2}");
        $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");
        $startTime = microtime(true);

        // Create the pre-aggregation scratch table.
        $this->queryAndGetResults("drop table if exists " . $preAggTable);
        $createPreAggQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
            "/sql/createLogsHitsPreAggTempTable.sql");
        $createPreAggQuery = $this->doTableNameReplacements($createPreAggQuery);
        $this->queryAndGetResults($createPreAggQuery);

        // Phase 1: chunk through logsv2 by ID range.
        // Each chunk aggregates by canonical requested_url (CONCAT('/', TRIM…))
        // so URL variants like '/foo', 'foo', and '/foo/' collapse into a
        // single pre-agg row. The same canonical key can still appear across
        // chunks — Phase 2 sums them.
        // failed_hits = count of 404-only hits per canonical URL (rows where
        // dest_url is empty/NULL). Lets flagDeadDestinationRedirects() avoid
        // scanning logsv2 in cron — see DataAccessTrait_Maintenance::flagDeadDestinationRedirects().
        for ($start = $minId; $start <= $maxId; $start += $chunkSize) {
            $end = $start + $chunkSize;
            $chunkQuery = "INSERT INTO " . $preAggTable .
                " (requested_url, logsid, last_used, logshits, failed_hits) " .
                "SELECT CONCAT('/', TRIM(BOTH '/' FROM requested_url)), " .
                "       MIN(id), MAX(timestamp), COUNT(*), " .
                "       SUM(CASE WHEN dest_url = '' OR dest_url IS NULL THEN 1 ELSE 0 END) " .
                "FROM " . $logsv2Table . " " .
                "WHERE id >= %d AND id < %d " .
                "GROUP BY CONCAT('/', TRIM(BOTH '/' FROM requested_url))";
            $chunkResult = $this->queryAndGetResults($chunkQuery, array(
                'log_too_slow' => false,
                'timeout' => 10,
                'query_params' => array($start, $end),
            ));
            if (!empty($chunkResult['timed_out']) || !empty($chunkResult['last_error'])) {
                $this->logger->debugMessage(__FUNCTION__ .
                    " Phase 1 chunk failed at id range [{$start}, {$end}); aborting.");
                return false;
            }
        }

        // Phase 2: join the small pre-agg table with redirects and
        // re-aggregate across chunks into the final temp table.
        // a.requested_url is already canonical from Phase 1. Match against
        // the persisted r.canonical_url column (added 4.1.10) so the JOIN
        // is an indexed equality lookup; COALESCE fallback covers rows
        // where the chunked backfill hasn't reached yet. Final GROUP BY
        // collapses any remaining duplicate canonical rows that originated
        // from different ID-range chunks.
        $phase2Query = "INSERT INTO " . $tempDestTable .
            " (requested_url, logsid, last_used, logshits, failed_hits) " .
            "SELECT a.requested_url, MIN(a.logsid), MAX(a.last_used), SUM(a.logshits), SUM(a.failed_hits) " .
            "FROM " . $preAggTable . " a " .
            "INNER JOIN " . $redirectsTable . " r " .
            "ON a.requested_url = COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url))) " .
            "GROUP BY a.requested_url";
        $results = $this->queryAndGetResults($phase2Query, array('log_too_slow' => false, 'timeout' => 60));

        // Attach total elapsed time so the caller can store it in the table comment.
        $results['elapsed_time'] = round(microtime(true) - $startTime, 3);

        // Clean up pre-agg table (also done in the finally block as a safety net).
        $this->queryAndGetResults("drop table if exists " . $preAggTable);

        return $results;
    }
}
