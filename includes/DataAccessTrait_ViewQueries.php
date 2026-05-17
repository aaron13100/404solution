<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_ViewQueriesTrait {

    /**
     * Get counts for each redirect status type for display in tabs.
     * Uses transient caching for performance.
     * @param bool $bypassCache If true, skip cache and query database directly
     * @return array<string, int> An array with keys: all, manual, auto, regex, trash
     */
    function getRedirectStatusCounts($bypassCache = false) {
        // Try to get cached value first
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_REDIRECT_STATUS);
            if ($cached !== false && is_array($cached)) {
                /** @var array<string, int> $cached */
                return $cached;
            }
        }

        // IMPORTANT: The redirects table also stores captured/ignored/later rows.
        // The Redirects page "All/Manual/Auto/Trash" tabs should only count actual redirects
        // (manual/auto/regex), not captured URLs.
        $query = "SELECT
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_MANUAL . " THEN 1 ELSE 0 END) as manual_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_AUTO . " THEN 1 ELSE 0 END) as auto_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_REGEX . " THEN 1 ELSE 0 END) as regex_count,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash_count
            FROM {wp_abj404_redirects}
            WHERE status IN (" . ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_AUTO . ", " . ABJ404_STATUS_REGEX . ")";
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query);
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $counts = array('all' => 0, 'manual' => 0, 'auto' => 0, 'regex' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $counts = array(
                'all' => intval(is_scalar($row['active_count'] ?? 0) ? $row['active_count'] : 0),
                'manual' => intval(is_scalar($row['manual_count'] ?? 0) ? $row['manual_count'] : 0),
                'auto' => intval(is_scalar($row['auto_count'] ?? 0) ? $row['auto_count'] : 0),
                'regex' => intval(is_scalar($row['regex_count'] ?? 0) ? $row['regex_count'] : 0),
                'trash' => intval(is_scalar($row['trash_count'] ?? 0) ? $row['trash_count'] : 0)
            );
        }

        // Skip the cache write when the SUM(...) query returned an error or
        // timed out: $rows is empty in that case so $counts is the all-zero
        // default, and pinning that for STATUS_CACHE_TTL (24h) would make the
        // Redirects admin page show "0 of every status" until the transient
        // expires. Same policy as 6454a7dd / b857be36.
        if (!$hadError) {
            set_transient(self::CACHE_KEY_REDIRECT_STATUS, $counts, self::STATUS_CACHE_TTL);
        }

        return $counts;
    }

    /**
     * Get counts for each captured URL status type.
     * Uses transient caching for performance.
     * @param bool $bypassCache If true, skip cache and query database directly
     * @return array<string, int> Array with keys: all, captured, ignored, later, trash
     */
    function getCapturedStatusCounts($bypassCache = false) {
        // Try to get cached value first
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_CAPTURED_STATUS);
            if ($cached !== false && is_array($cached)) {
                /** @var array<string, int> $cached */
                return $cached;
            }
        }

        $query = "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_CAPTURED . " THEN 1 ELSE 0 END) as captured,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_IGNORED . " THEN 1 ELSE 0 END) as ignored,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_LATER . " THEN 1 ELSE 0 END) as later,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash
            FROM {wp_abj404_redirects}
            WHERE status IN (" . ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER . ")";
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query);
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $counts = array('all' => 0, 'captured' => 0, 'ignored' => 0, 'later' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $counts = array(
                'all' => intval(is_scalar($row['active'] ?? 0) ? $row['active'] : 0),
                'captured' => intval(is_scalar($row['captured'] ?? 0) ? $row['captured'] : 0),
                'ignored' => intval(is_scalar($row['ignored'] ?? 0) ? $row['ignored'] : 0),
                'later' => intval(is_scalar($row['later'] ?? 0) ? $row['later'] : 0),
                'trash' => intval(is_scalar($row['trash'] ?? 0) ? $row['trash'] : 0)
            );
        }

        // Skip the cache write when the SUM(...) query returned an error or
        // timed out: $rows is empty in that case so $counts is the all-zero
        // default, and pinning that for STATUS_CACHE_TTL (24h) would make the
        // Captured-URLs admin page show "0 captured / 0 ignored / 0 later"
        // until the transient expires. Same policy as 6454a7dd / b857be36.
        if (!$hadError) {
            set_transient(self::CACHE_KEY_CAPTURED_STATUS, $counts, self::STATUS_CACHE_TTL);
        }

        return $counts;
    }

    /**
     * Count captured URLs that have been hit 3 or more times (signal of real user impact).
     * Uses transient caching for performance.
     *
     * Implementation: INNER JOIN against the pre-aggregated logs_hits rollup,
     * which already stores logshits per requested_url and is rebuilt by cron via
     * createRedirectsForViewHitsTable(). Same JOIN shape as
     * getRedirectsForViewQuery() — BINARY column equality.
     *
     * The previous implementation aggregated logsv2 with GROUP BY + HAVING per
     * call; on busy sites with millions of log rows that took 30–60s and hit
     * the AJAX timeout. The pre-aggregated table makes the count O(distinct
     * URLs) instead of O(total log rows).
     *
     * Fallback: if logs_hits is missing or empty, return 0 and schedule a
     * shutdown-time rebuild so a subsequent request can serve real data. We
     * deliberately do NOT fall back to the old logsv2 GROUP BY query: the whole
     * point of this function is to never run that scan again.
     *
     * Cache policy: only the result of a successful query against a populated
     * rollup is cached for STATUS_CACHE_TTL (24h). Errors, timeouts, missing
     * rollups, and empty-during-rebuild outcomes all return 0 *without
     * caching* so the next request retries — otherwise a single transient
     * failure would hide repeat-visitor URLs for a full day.
     *
     * @return int Number of captured URLs with 3+ log hits
     */
    function getHighImpactCapturedCount(): int {
        $cached = get_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED);
        if ($cached !== false) {
            return intval(is_scalar($cached) ? $cached : 0);
        }

        // If the rollup is not available, defer rather than scan logsv2.
        // Do not cache: the rebuild is in flight and the next request should retry.
        if (!$this->logsHitsTableExists()) {
            $this->scheduleHitsTableRebuild();
            return 0;
        }

        $query = $this->buildHighImpactCapturedCountQuery();

        $result = $this->queryWithTimeout($query, 60);
        $timedOut = !empty($result['timed_out']);
        $hadError = !empty($result['last_error']) || $timedOut;
        $rows = is_array($result['rows']) ? $result['rows'] : array();
        $count = (!empty($rows) && isset($rows[0]['cnt'])) ? intval($rows[0]['cnt']) : 0;

        // Timeout self-heal (Bruno regression). Without this branch every
        // admin pageview re-pays the 60s timeout cost. We schedule a hits
        // table rebuild so the next post-cache request can return real
        // data, and cache 0 for the short STATUS_CACHE_TIMEOUT_SELFHEAL_TTL
        // window (5 min) so subsequent pageviews are instant. The short
        // TTL is far less than STATUS_CACHE_TTL (24h), so a transient
        // timeout cannot hide repeat-visitor URLs for a full day.
        if ($timedOut) {
            $this->scheduleHitsTableRebuild();
            // allow-cache-empty: timeout self-heal sentinel, 5-minute window. Real value returns once the rebuild completes and the short cache expires.
            set_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED, 0, self::STATUS_CACHE_TIMEOUT_SELFHEAL_TTL);
            return 0;
        }

        // Non-timeout errors (network blip, replication lag, etc.) return
        // 0 without caching so the next request retries promptly.
        if ($hadError) {
            return 0;
        }

        // If the rollup exists but has no rows yet (first run, or rebuild in
        // progress), schedule a rebuild AND skip caching so the next request
        // can serve real data once the rebuild completes (typically seconds).
        if ($count === 0) {
            if ($this->isHitsTableEmpty()) {
                $this->scheduleHitsTableRebuild();
                return 0;
            }
        }

        set_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED, $count, self::STATUS_CACHE_TTL);

        return $count;
    }

    /**
     * Build the SQL for getHighImpactCapturedCount(). Exposed so structural
     * regression tests can assert no logsv2 access and verify the EXPLAIN plan.
     *
     * @return string Fully-replaced SQL (table-name placeholders resolved).
     */
    function buildHighImpactCapturedCountQuery(): string {
        // logs_hits.requested_url is stored in canonical form (leading '/',
        // no trailing '/') by createRedirectsForViewHitsTable(). Match against
        // the persisted r.canonical_url column (added 4.1.10) so the JOIN is
        // an indexed equality lookup instead of CONCAT/TRIM per row. The
        // COALESCE fallback covers rows from upgraded sites where the chunked
        // backfill hasn't reached yet.
        $query = "SELECT COUNT(*) AS cnt
            FROM {wp_abj404_redirects} r
            INNER JOIN {wp_abj404_logs_hits} h
                ON BINARY h.requested_url = BINARY
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . " AND r.disabled = 0
              AND h.logshits >= 3";
        return $this->doTableNameReplacements($query);
    }

    /**
     * Cheap probe: does the logs_hits rollup contain at least one row?
     * SELECT 1 ... LIMIT 1 against a small table.
     *
     * @return bool true when the rollup has zero rows (rebuild in progress,
     *              cold start, or post-truncate). false when at least one
     *              row exists OR when the probe itself errors (treat
     *              ambiguous probes as "not empty" so we don't spam reschedules).
     */
    private function isHitsTableEmpty(): bool {
        $check = "SELECT 1 FROM {wp_abj404_logs_hits} LIMIT 1";
        $check = $this->doTableNameReplacements($check);
        $result = $this->queryAndGetResults($check);
        if (!empty($result['last_error']) || !empty($result['timed_out'])) {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return empty($rows);
    }

    /**
     * Execute a query with a timeout to prevent it from blocking the page indefinitely.
     * On timeout, logs an error with the query shape and returns an empty result.
     *
     * Delegates to queryAndGetResults() with the 'timeout' option, which handles
     * MySQL 5.7+ (MAX_EXECUTION_TIME hint) and MariaDB 10.1+ (max_statement_time).
     *
     * @param string $query The SQL query to execute
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return array<string, mixed> Same format as queryAndGetResults()
     */
    private function queryWithTimeout(string $query, int $timeoutSeconds = 60): array {
        return $this->queryAndGetResults($query, array(
            'timeout' => $timeoutSeconds,
        ));
    }

    /**
     * Per-request "bulk mutation in progress" flag. When set, per-row
     * invalidateStatusCountsCache() calls short-circuit to a no-op so a
     * 10K-row CSV import does not fire 60K invalidation queries (each row
     * cascades to bumpMutationWatermark + delete_option +
     * delete_transient × N + DELETE FROM view_cache; for a 10K import
     * this took ~63s before this guard). The bulk caller is responsible
     * for issuing ONE final invalidation (typically via
     * markViewDoneInvalidatedByAdminMutation()) after the bulk write
     * completes, so admin reads see the imported rows immediately.
     *
     * Implemented as a static on the DAO instance ($this only because
     * trait scoping requires it) so the flag survives across multiple
     * setupRedirect() calls within one request without needing every
     * caller to thread a parameter through.
     *
     * @var bool
     */
    public static $bulkMutationInProgress = false;

    /**
     * Open/close the bulk-mutation window. Bulk importers (CSV import,
     * sitemap regeneration, future bulk admin actions) wrap their per-row
     * loop with this. The callable is invoked while the flag is set;
     * exceptions are rethrown but the flag is always restored.
     *
     * On window close, issues exactly one bumpMutationWatermark() to
     * represent the entire batch as a single mutation tick. Without this
     * the per-row chain bumps are all suppressed and a later
     * markViewDoneInvalidatedByAdminMutation() call would observe the
     * pre-batch counter, leaving the admin-visibility gate un-raised
     * and the next read returning the stale snapshot (the failure mode
     * WpCliMutationEndToEndCharacterizationTest::testCliBulkAddFromCsv
     * pins). The bump fires even when the callable returned early or
     * threw, because the side effect of "we entered a mutation window"
     * is what the watermark documents -- whether downstream rows landed
     * is the caller's concern.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runWithDeferredInvalidation(callable $work) {
        $prior = self::$bulkMutationInProgress;
        self::$bulkMutationInProgress = true;
        try {
            return $work();
        } finally {
            self::$bulkMutationInProgress = $prior;
            $this->bumpMutationWatermark();
        }
    }

    /**
     * Invalidate cached status counts.
     * Call this when redirects are created, updated, or deleted.
     *
     * No-op when {@see self::$bulkMutationInProgress} is set; the bulk
     * caller must issue one final invalidation after the loop completes.
     */
    /** @return void */
    function invalidateStatusCountsCache(): void {
        if (self::$bulkMutationInProgress) {
            return;
        }
        delete_transient(self::CACHE_KEY_REDIRECT_STATUS);
        delete_transient(self::CACHE_KEY_CAPTURED_STATUS);
        delete_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED);
        $this->invalidateViewSnapshotCache();
    }

    /**
     * Clear the view snapshot cache so the admin redirect/captured tables
     * reflect newly created, updated, trashed, or deleted redirects immediately.
     *
     * This clears both the custom wp_abj404_view_cache table and the
     * WordPress transients used as a secondary cache layer.
     *
     * @return void
     */
    function invalidateViewSnapshotCache(): void {
        // Source-mutation signal (Phase 4 of the staged view-build watermark
        // refactor; see docs/refactor-staged-view-build-watermark.md). Every
        // DAO mutator (deleteRedirect, setupRedirect, updateRedirect,
        // updateRedirectTypeStatus, moveRedirectsToTrash, removeDuplicatesCron,
        // purgeRedirectsByStatus) routes through invalidateStatusCountsCache()
        // -> here. Bump the per-blog mutation watermark so the staged-build
        // runner observes it at the next stage boundary and either aborts
        // the in-flight build cleanly (so the next build covers the new row)
        // or, if the build is already running for an earlier watermark, the
        // active_build_started_watermark gate keeps the runner from
        // publishing a snapshot that misses the mutation.
        $this->bumpMutationWatermark();

        // Clear view_done freshness so the read path's TTL check trips and
        // a rebuild gets scheduled on the next request. The runner remains
        // the sole owner of progress markers, the S1 prefix capture, and
        // the transient buffer tables: external code (this seam included)
        // must not touch them. scheduleViewDoneRebuild() is idempotent
        // (wp_next_scheduled short-circuit) so concurrent mutators do not
        // pile up cron events.
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneFreshnessOptionName());
        }
        $this->invalidateViewDoneServeableCache();
        $this->scheduleViewDoneRebuild();

        // Clear all rows from the view cache table. log_errors=false marks
        // this as a best-effort operation (the cache expires naturally via
        // TTL if the DELETE fails). skip_repair=true blocks the missing-
        // table auto-create + retry path: a missing view_cache means
        // "nothing to invalidate"; spinning up the full createDatabaseTables
        // flow to make the DELETE succeed is wasteful in production and in
        // tests it cascades correctCollations -> bumpMutationWatermark, which
        // breaks the "exactly one bump per source-data mutation" contract
        // pinned by MixedSourceConcurrentMutationIntegrationTest.
        $query = "DELETE FROM {wp_abj404_view_cache} WHERE 1=1";
        $this->queryAndGetResults($query, array('log_errors' => false, 'skip_repair' => true));

        // Clear WordPress transients for view row and count snapshots.
        // The transient keys are hashed (e.g. abj404_view_rows_<md5>), so
        // we delete by prefix from wp_options directly.
        global $wpdb;
        if (isset($wpdb->options) && method_exists($wpdb, 'query')) {
            // @utf8-audit: opt-out — $wpdb->options is the WordPress core
            // options table name (system value); never user input.
            /** @var string $optionsTable */
            $optionsTable = esc_sql($wpdb->options);
            // DAO-bypass-approved: View-cache clear targets wp_options — outside the plugin's owned tables; runs during cache invalidation hot path; failure is best-effort
            $wpdb->query(
                "DELETE FROM `{$optionsTable}` WHERE option_name LIKE '_transient_abj404_view_%'"
                . " OR option_name LIKE '_transient_timeout_abj404_view_%'"
            );
        }
    }

    /**
     * Clear the per-request regex redirects cache.
     * Primarily used for testing. In production, the cache resets automatically
     * on each new request since it uses static variables.
     */
    /** @return void */
    function clearRegexRedirectsCache(): void {
        self::$regexRedirectsCache = null;
        self::$regexCacheDisabled = false;
    }

    /**
     * @global type $wpdb
     * @param int $logID only return results that correspond to the URL of this $logID. Use 0 to get all records.
     * @return int the number of records found.
     */
    function getLogsCount($logID) {
        // Sanitize logID to prevent SQL injection
        $logID = absint($logID);

        // Audit F4: cache the unfiltered total. InnoDB has no maintained row
        // counter so `SELECT COUNT(id) FROM logsv2` is a full index scan that
        // dominates the Logs admin tab on multi-million-row logsv2. The cache
        // is keyed on (blog_id, max_log_id) so new inserts move the key
        // (fresh value picked up immediately); deletions are bounded by the
        // LOGS_COUNT_CACHE_TTL_SECONDS staleness window. The filtered path
        // (logID != 0) is per-URL and has unbounded key cardinality, so it
        // stays uncached.
        $cacheKey = null;
        if ($logID === 0 && function_exists('get_transient')) {
            $blogId = 1;
            if (function_exists('get_current_blog_id')) {
                $rawBlogId = function_exists('absint')
                    ? absint(get_current_blog_id())
                    : abs(intval(get_current_blog_id()));
                if ($rawBlogId > 0) {
                    $blogId = $rawBlogId;
                }
            }
            $maxLogId = 0;
            try {
                $maxLogId = intval($this->getMaxLogId());
                if ($maxLogId < 0) {
                    $maxLogId = 0;
                }
            } catch (Throwable $e) {
                // getMaxLogId() failed (table missing, query timeout). Fall back
                // to maxLogId=0 so the cache key still varies; the count will
                // recompute on every request until the underlying query recovers.
                $this->logger->debugMessage(__FUNCTION__ . ' getMaxLogId() failed: '
                    . $e->getMessage() . '. Falling back to maxLogId=0.');
                $maxLogId = 0;
            }
            $cacheKey = 'abj404_logs_count_v1_' . $blogId . '_' . $maxLogId;
            $cached = get_transient($cacheKey);
            if (is_numeric($cached)) {
                return (int)$cached;
            }
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsCount.sql");

        if ($logID != 0) {
            $query = $this->f->str_replace('/* {SPECIFIC_ID}', '', $query);
            $query = $this->f->str_replace('{logID}', (string)$logID, $query);
        }

        // Route through queryAndGetResults() so the count query (potentially
        // a JOIN against logsv2 when SPECIFIC_ID is set) inherits the
        // centralized 60s timeout. Bypassing via $wpdb->get_row() leaves the
        // admin page with no upper bound on slow logsv2 lookups.
        $result = $this->queryAndGetResults($query);
        $hadError = !empty($result['timed_out'])
            || (isset($result['last_error']) && $result['last_error'] != '');

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $count = 0;
        if (!empty($rows)) {
            $first = $rows[0];
            $value = is_array($first) ? reset($first) : $first;
            $count = intval($value);
        }

        // Only cache on success. A DB error / timeout would otherwise pin a
        // zero for LOGS_COUNT_CACHE_TTL_SECONDS, so the Logs admin tab would
        // show "0 entries" until the cache expires. Mirrors the
        // getDailyActivityTrend() write-on-success policy.
        if (!$hadError && $cacheKey !== null && function_exists('set_transient')) {
            set_transient($cacheKey, $count, self::LOGS_COUNT_CACHE_TTL_SECONDS);
        }

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsAll() {
        $query = "select id, url from {wp_abj404_redirects} order by url";

        // Route through queryAndGetResults() so this list query inherits the
        // centralized 60s timeout. The redirects table can be very large on
        // busy sites and an unbounded ORDER BY without timeout protection
        // could exceed reverse-proxy limits.
        $result = $this->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array();
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return $rows;
    }
    
    /** @param string $tempFile @return void */
    function doRedirectsExport(string $tempFile): void {
    	global $wpdb;
    	
    	if (file_exists($tempFile)) {
    		ABJ_404_Solution_Functions::safeUnlink($tempFile);
    	}
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
    		"/sql/getRedirectsExport.sql");
    	$query = $this->doTableNameReplacements($query);
    	
    	// we use mysqli here instead of the normal wordpress get_results in order
    	// to get one row at a time, so we don't run out of memory by trying to store
    	// everything in memory all at once.
    	$result = mysqli_query($wpdb->dbh, $query);
    	if ($result instanceof \mysqli_result) {
    		$fh = fopen($tempFile, 'w');
    		if ($fh === false) {
    			return;
    		}
    		fputcsv($fh, array('from_url', 'status', 'type', 'to_url', 'wp_type', 'engine', 'code'), ',', '"', '\\');

    		while (($row = mysqli_fetch_array($result, MYSQLI_ASSOC))) {
    			fputcsv($fh, array(
    				$row['from_url'],
    				$row['status'],
    				$row['type'],
    				$row['to_url'],
    				$row['type_wp'],
    				isset($row['engine']) ? $row['engine'] : '',
    				isset($row['code']) ? $row['code'] : '301'
    			), ',', '"', '\\');
    		}
    		fclose($fh);
    		mysqli_free_result($result);
    	}
    }
    
    /** Only return redirects that have a log entry.
     * @global type $wpdb
     * @global type $abj404dao
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsWithLogs() {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsWithLogs.sql");

        // Route through queryAndGetResults() so this redirects+logs JOIN
        // inherits the centralized 60s timeout. logsv2 can be huge, and the
        // join shape is identical to the one already protected in the hits
        // table rebuild path (commit 70f3b5fe).
        $result = $this->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array();
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return $rows;
    }

    /**
     * Get all regex redirects for pattern matching.
     * Uses per-request caching when redirect count is <= 50 to avoid repeated queries.
     * Cache is automatically skipped if there are too many regex redirects (memory guard).
     *
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsWithRegEx() {
        // Return cached results if available (and caching wasn't disabled due to count)
        if (self::$regexRedirectsCache !== null && !self::$regexCacheDisabled) {
            return self::$regexRedirectsCache;
        }

        // If caching was disabled due to too many redirects, just query without caching
        if (self::$regexCacheDisabled) {
            return $this->queryRegexRedirects();
        }

        // First query - check count and decide whether to cache
        $results = $this->queryRegexRedirects();

        // Only cache if count is within safe memory limits
        if (count($results) <= self::REGEX_CACHE_MAX_COUNT) {
            self::$regexRedirectsCache = $results;
        } else {
            // Too many regex redirects - disable caching for this request
            self::$regexCacheDisabled = true;
        }

        return $results;
    }

    /**
     * Execute the regex redirects query.
     * Separated from getRedirectsWithRegEx() for cache logic clarity.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryRegexRedirects() {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
                . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
                . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n ";
        $query .= "from {wp_abj404_redirects}\n " .
                "  LEFT OUTER JOIN {wp_posts} \n " .
                "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n ";

        $query .= "where status in (" . ABJ404_STATUS_REGEX . ") \n " .
                "     and disabled = 0";
        $results = $this->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /**
     * Find MANUAL redirects whose `url` column contains an unambiguous
     * regex metacharacter (`* [ ] | ^ \ { }`). These are rows the admin
     * created via a pre-auto-promote path (older plugin version, direct
     * DB write, CSV import before the 4.1.x sniff was widened) that
     * really should be treated as regex. The runtime fallback in
     * SpellCheckerTrait_URLMatching tries them as regex without
     * mutating the stored status; the auto-promote on next save sweeps
     * them into the regular regex query.
     *
     * The LIKE filter is deliberately broad to keep the query simple;
     * the runtime caller re-checks with the precise PHP-side helper
     * (looksLikeUnambiguousRegex) before treating any row as regex.
     *
     * @return array<int, array<string, mixed>>
     */
    function getManualRedirectsWithRegexMetachars() {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
                . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
                . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n ";
        $query .= "from {wp_abj404_redirects}\n " .
                "  LEFT OUTER JOIN {wp_posts} \n " .
                "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n ";

        // SQL-side prefilter using INSTR per metachar. INSTR avoids LIKE's
        // wildcard/escape semantics so we do not have to special-case
        // the backslash byte. The PHP-side caller re-checks each row with
        // looksLikeUnambiguousRegex(), so a few false positives here
        // are harmless; the goal is to never miss a row that should be
        // considered. Set matches the helper class.
        $query .= "where status = " . ABJ404_STATUS_MANUAL . " \n " .
                "     and disabled = 0 \n " .
                "     and (INSTR(`url`, '*') > 0 " .
                "       OR INSTR(`url`, '[') > 0 " .
                "       OR INSTR(`url`, ']') > 0 " .
                "       OR INSTR(`url`, '|') > 0 " .
                "       OR INSTR(`url`, '^') > 0 " .
                "       OR INSTR(`url`, '\\\\') > 0 " .
                "       OR INSTR(`url`, '{') > 0 " .
                "       OR INSTR(`url`, '}') > 0)";
        $results = $this->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /** Returns the redirects that are in place.
     * @global type $wpdb
     * @param string $sub either "redirects" or "captured".
     * @param array<string, mixed> $tableOptions filter, order by, paged, perpage etc.
     * @return array<int|string, mixed> rows from the redirects table.
     */
    function getRedirectsForView($sub, $tableOptions) {
        $canUseSnapshotCache = $this->canUseViewTableSnapshotCache($tableOptions);
        $queryTimeout = isset($tableOptions['_abj404_query_timeout']) && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(1, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $throwOnQueryError = !empty($tableOptions['_abj404_throw_on_view_query_error']);
        $snapshotCacheKey = '';
        if ($canUseSnapshotCache && $queryTimeout <= 0) {
            $snapshotCacheKey = $this->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
            $cachedRowsFromTable = $this->getViewRowsSnapshotFromTable($snapshotCacheKey, false, false);
            if (is_array($cachedRowsFromTable)) {
                return $cachedRowsFromTable;
            }
            if (function_exists('get_transient')) {
                $cachedRows = get_transient($snapshotCacheKey);
                if (is_array($cachedRows)) {
                    return $cachedRows;
                }
            }
        }

        try {
            $rows = $this->runRedirectsForViewStaged((string)$sub, is_array($tableOptions) ? $tableOptions : array());
        } catch (ABJ_404_Solution_ViewBuildPendingException $pending) {
            // Cold-start state, not an error. The fetch AJAX gate normally
            // intercepts this before the read; non-AJAX callers (REST, warmup)
            // see an empty page and retry once cron / the JS poller advances
            // the build. Re-throw when the warmup pipeline asks for it so its
            // attempt counter advances and a stage gets blamed.
            if ($throwOnQueryError) {
                throw $pending;
            }
            $this->logger->debugMessage('[staged] getRedirectsForView pending: ' . $pending->getMessage());
            return array();
        } catch (Throwable $e) {
            if ($throwOnQueryError) {
                $stagedFailureMarker = '/* staged: ' . $e->getMessage() . ' */';
                $diagnostics = $this->captureViewQueryFailureDiagnostics(
                    (string)$sub,
                    $stagedFailureMarker,
                    is_array($tableOptions) ? $tableOptions : array(),
                    array('last_error' => $e->getMessage(), 'timed_out' => false)
                );
                $diagnostics['failed_query_label'] = 'getRedirectsForView';
                $diagnostics['staged_error'] = $e->getMessage();
                $message = 'getRedirectsForView failed; last_error=' . $e->getMessage()
                    . '; timed_out=false; sql_source=' . $stagedFailureMarker;
                throw new ABJ_404_Solution_ViewQueryFailureException($message, $diagnostics);
            }
            $this->logger->errorMessage('[staged] getRedirectsForView failed: ' . $e->getMessage(),
                $e instanceof \Exception ? $e : null);
            return array();
        }

        $this->logger->debugMessage(sprintf(
            '[staged] getRedirectsForView returned %d rows for page %s',
            count($rows),
            (string)$sub
        ));

        if ($canUseSnapshotCache && $snapshotCacheKey === '') {
            $snapshotCacheKey = $this->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
        }
        if ($canUseSnapshotCache && $snapshotCacheKey !== '') {
            $this->setViewRowsSnapshotToTable($snapshotCacheKey, $sub, $rows, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            if (function_exists('set_transient')) {
                // allow-cache-empty: empty $rows is a legitimate result on a fresh install (no redirects yet); error paths early-return above without reaching this line
                set_transient($snapshotCacheKey, $rows, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            }
        }

        return $rows;
    }

    /**
     * Return whether the admin rows view already has a usable snapshot.
     *
     * Used by the AJAX first-paint path to avoid running an expensive cold
     * table query inline. Fresh snapshots are preferred, but a recently
     * refreshed stale snapshot is still usable because it lets the admin see
     * real rows while background refresh detects newer data non-destructively.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    function viewRowsSnapshotAvailable($sub, array $tableOptions): bool {
        $canUseSnapshotCache = $this->canUseViewTableSnapshotCache($tableOptions);
        if (!$canUseSnapshotCache) {
            return false;
        }

        $snapshotCacheKey = $this->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
        $freshRows = $this->getViewRowsSnapshotFromTable($snapshotCacheKey, false, false);
        if (is_array($freshRows)) {
            return true;
        }
        $recentRows = $this->getViewRowsSnapshotFromTable($snapshotCacheKey, true, true);
        if (is_array($recentRows)) {
            return true;
        }
        if (function_exists('get_transient')) {
            $transientRows = get_transient($snapshotCacheKey);
            if (is_array($transientRows)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return whether the full AJAX table response can be rendered from cache.
     *
     * Rows alone are not enough for first paint: pagination rendering also
     * needs getRedirectsForViewCount(). If the count snapshot is cold, the
     * "cached" path can still block on a heavy COUNT query. The initial AJAX
     * cache gate uses this method so cold counts are also pushed to the
     * background hydrate request.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    function viewTableSnapshotAvailable($sub, array $tableOptions): bool {
        if (!$this->viewRowsSnapshotAvailable($sub, $tableOptions)) {
            return false;
        }

        $canUseSnapshotCache = function_exists('get_transient')
            && $this->canUseViewTableSnapshotCache($tableOptions);
        if (!$canUseSnapshotCache) {
            return false;
        }

        $countCacheKey = $this->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
        return get_transient($countCacheKey) !== false;
    }
    
    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    function getRedirectsForViewCount(string $sub, array $tableOptions): int {
        $queryTimeout = isset($tableOptions['_abj404_query_timeout']) && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(1, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $throwOnQueryError = !empty($tableOptions['_abj404_throw_on_view_query_error']);
        $canUseSnapshotCache = function_exists('get_transient')
            && $this->canUseViewTableSnapshotCache($tableOptions);
        $requestCountCacheKey = (string)$sub . '|' . md5(serialize($tableOptions));
        $countCacheKey = '';
        if ($canUseSnapshotCache && $queryTimeout <= 0) {
            $countCacheKey = $this->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
            $cachedCount = get_transient($countCacheKey);
            if ($cachedCount !== false) {
                return intval(is_scalar($cachedCount) ? $cachedCount : 0);
            }
        }
        if (array_key_exists($requestCountCacheKey, $this->redirectsForViewCountRequestCache)) {
            return intval($this->redirectsForViewCountRequestCache[$requestCountCacheKey]);
        }

        $rawFilterText = is_string($tableOptions['filterText'] ?? null) ? $tableOptions['filterText'] : '';
        if ($rawFilterText === '') {
            // No search filter: simple COUNT against the live redirects table
            // is fast enough that there is no value building view_done just
            // for this. Keeps cold-start counts cheap.
            $query = $this->getOptimizedRedirectsForViewCountQuery($sub, $tableOptions);
            $this->setSqlBigSelects();
            $queryOptions = $queryTimeout > 0 ? array('timeout' => $queryTimeout) : array();
            $results = $this->queryAndGetResults($query, $queryOptions);
            $lastErrorRaw = $results['last_error'] ?? '';
            $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        } else {
            // Search-filtered count needs to apply the LIKE composite against
            // the precomputed dest_for_view/status_for_view/type_for_view
            // columns, so route through the staged path.
            try {
                $countValue = $this->runRedirectsForViewCountStaged((string)$sub, $tableOptions);
                $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
                if ($canUseSnapshotCache && $countCacheKey === '') {
                    $countCacheKey = $this->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
                }
                if ($canUseSnapshotCache && $countCacheKey !== '') {
                    // allow-cache-empty: $countValue=0 is a legitimate result when no rows match the search filter; the staged pending/error paths throw above without reaching this line
                    set_transient($countCacheKey, $countValue, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
                }
                return $countValue;
            } catch (ABJ_404_Solution_ViewBuildPendingException $pending) {
                // view_done not yet built. Same treatment as getRedirectsForView:
                // signal pending up the warmup pipeline if requested, otherwise
                // record a sentinel count and let the caller retry next request.
                if ($throwOnQueryError) {
                    throw $pending;
                }
                $this->logger->debugMessage('[staged] getRedirectsForViewCount pending: ' . $pending->getMessage());
                $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
                return -1;
            } catch (Throwable $e) {
                if ($throwOnQueryError) {
                    $stagedFailureMarker = '/* staged-count: ' . $e->getMessage() . ' */';
                    $diagnostics = $this->captureViewQueryFailureDiagnostics(
                        (string)$sub,
                        $stagedFailureMarker,
                        $tableOptions,
                        array('last_error' => $e->getMessage(), 'timed_out' => false)
                    );
                    $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
                    $diagnostics['staged_error'] = $e->getMessage();
                    throw new ABJ_404_Solution_ViewQueryFailureException($e->getMessage(), $diagnostics);
                }
                $this->logger->errorMessage('[staged] getRedirectsForViewCount failed: ' . $e->getMessage(),
                    $e instanceof \Exception ? $e : null);
                $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
                return -1;
            }
        }

        if ($throwOnQueryError && (!empty($results['timed_out']) || $lastError !== '')) {
            $message = $this->formatViewQueryFailureMessage('getRedirectsForViewCount', $query, $results);
            $diagnostics = $this->captureViewQueryFailureDiagnostics($sub, $query, $tableOptions, $results);
            $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
            throw new ABJ_404_Solution_ViewQueryFailureException($message, $diagnostics);
        }

        if ($lastError != '' && trim($lastError) != '') {
            $diagnostics = $this->captureViewQueryFailureDiagnostics($sub, $query, $tableOptions, $results);
            $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
            throw new ABJ_404_Solution_ViewQueryFailureException(
                "Error getting redirect count: " . esc_html($lastError),
                $diagnostics
            );
        }
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($rows)) {
            $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
        	return -1;
        }
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        $rawCount = $row['count'] ?? $row['COUNT(*)'] ?? reset($row);
        $countValue = intval(is_scalar($rawCount) ? $rawCount : 0);
        $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
        if ($canUseSnapshotCache && $countCacheKey === '') {
            $countCacheKey = $this->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
        }
        if ($canUseSnapshotCache && $countCacheKey !== '') {
            set_transient($countCacheKey, $countValue, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
        }
        return $countValue;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function getOptimizedRedirectsForViewCountQuery(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types;

        $statusTypes = '';
        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            if ($sub == 'abj404_redirects') {
                $statusTypes = implode(", ", $abj404_redirect_types);
            } else if ($sub == 'abj404_captured') {
                $statusTypes = implode(", ", $abj404_captured_types);
            }
        } else if ($tableOptions['filter'] == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(", ", array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));
        } else if ($tableOptions['filter'] == ABJ404_HANDLED_FILTER) {
            $statusTypes = implode(", ", array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));
        } else {
            $statusTypes = $tableOptions['filter'];
        }
        $statusTypes = preg_replace('/[^\d, ]/', '', trim(is_string($statusTypes) ? $statusTypes : ''));

        $trashValue = ($tableOptions['filter'] == ABJ404_TRASH_FILTER) ? 1 : 0;

        $scoreRangeClause = '';
        $rawScoreRange = is_string($tableOptions['score_range'] ?? '') ? ($tableOptions['score_range'] ?? 'all') : 'all';
        // Each `wp_abj404_redirects.*` reference below is the SQL alias bound by the
        // `FROM {wp_abj404_redirects} wp_abj404_redirects` clause in the assembled
        // query, not a hardcoded table-name literal. Per-line markers keep the
        // lint window (+/- 1 line) honest.
        switch ($rawScoreRange) {
            case 'high': $scoreRangeClause = 'AND wp_abj404_redirects.score >= 80'; break; // allow-prefix-literal: SQL alias, see comment above
            case 'medium': $scoreRangeClause = 'AND wp_abj404_redirects.score >= 50 AND wp_abj404_redirects.score < 80'; break; // allow-prefix-literal: SQL alias
            case 'low': $scoreRangeClause = 'AND wp_abj404_redirects.score IS NOT NULL AND wp_abj404_redirects.score < 50'; break; // allow-prefix-literal: SQL alias
            case 'manual': $scoreRangeClause = 'AND wp_abj404_redirects.score IS NULL'; break; // allow-prefix-literal: SQL alias
        }

        $query = "SELECT COUNT(*) AS count\n" .
                 "FROM {wp_abj404_redirects} wp_abj404_redirects\n" . // allow-prefix-literal: second token is the SQL alias name, not a table reference
                 "WHERE 1 and status IN (" . $statusTypes . ") AND disabled = " . intval($trashValue) . "\n" .
                 $scoreRangeClause;

        return $this->doTableNameReplacements($query);
    }
    
    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $queryAllRowsAtOnce
     * @param int $limitStart
     * @param int $limitEnd
     * @param bool $selectCountOnly
     * @return string
     */
    function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
    	$limitStart, $limitEnd, $selectCountOnly) {
        global $abj404_redirect_types;
        global $abj404_captured_types;
        global $wpdb;

        $logsTableColumns = '';
        $logsTableColumns = "null as logshits, \n null as logsid, \n null as last_used, \n";
        $logsTableJoin = '';
        $statusTypes = '';
        $trashValue = '';
        $selectCountReplacement = '/* selecting data as usual */';
        
        /* if we only want the count(*) then comment out everything else. */
        if ($selectCountOnly) {
        	$selectCountReplacement = "\n /*+ SET_VAR(max_join_size=18446744073709551615) */\n" . 
        		"count(*) as count\n /* only selecting for count";
        }

        if ($queryAllRowsAtOnce && !$selectCountOnly) {
            // create a temp table and use that instead of a subselect to avoid the sql error
            // "The SELECT would examine more than MAX_JOIN_SIZE rows"
            $this->maybeUpdateRedirectsForViewHitsTable();

            // Verify table was actually created before using it (handles silent creation failures)
            if ($this->logsHitsTableExists()) {
                // if we're showing all rows include all of the log data in the query already. this makes the query very slow. 
                // this should be replaced by the dynamic loading of log data using ajax queries as the page is viewed.
                $logsTableColumns = "logstable.logshits as logshits, \n" .
                    "logstable.logsid, \n" .
                    "logstable.last_used, \n";

                // canonical_url is the persisted CONCAT('/', TRIM(BOTH '/' FROM url))
                // form (added 4.1.10) so this JOIN is a single indexed equality
                // lookup against logs_hits.requested_url instead of evaluating
                // the function on every redirects row. The COALESCE fallback
                // covers rows from upgraded sites where the chunked backfill
                // hasn't reached yet — those rows merge in via the original
                // expression so behavior matches pre-upgrade exactly.
                // wp_abj404_redirects below is the SQL alias from the assembled FROM clause, not a hardcoded table name.
                $logsTableJoin = "  LEFT OUTER JOIN {wp_abj404_logs_hits} logstable \n " .
                        "  on binary logstable.requested_url = " .
                        "binary COALESCE(wp_abj404_redirects.canonical_url, " . // allow-prefix-literal: SQL alias
                        "concat('/', trim(both '/' from wp_abj404_redirects.url))) \n "; // allow-prefix-literal: SQL alias
            } else {
                // Fall back to null columns if table creation failed
                $this->logger->debugMessage("logs_hits table not available, falling back to null columns");
            }
        }
        
        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            if ($sub == 'abj404_redirects') {
                $statusTypes = implode(", ", $abj404_redirect_types);

            } else if ($sub == 'abj404_captured') {
                $statusTypes = implode(", ", $abj404_captured_types);

            } else {
                $this->logger->errorMessage("Unrecognized sub type: " . esc_html($sub));
            }

        } else if ($tableOptions['filter'] == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(", ", array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));

        } else if ($tableOptions['filter'] == ABJ404_HANDLED_FILTER) {
            // Composite filter: Ignored + Later (Simple mode "Handled" tab)
            $statusTypes = implode(", ", array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));

        } else {
            $statusTypes = $tableOptions['filter'];
        }
        $statusTypes = preg_replace('/[^\d, ]/', '', trim(is_string($statusTypes) ? $statusTypes : ''));

        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $trashValue = 1;
        } else if ($tableOptions['filter'] == ABJ404_HANDLED_FILTER) {
            // Show both active (disabled=0) and trashed (disabled=1) in Handled view
            $trashValue = 0;
        } else {
            $trashValue = 0;
        }

        /* only try to order by if we're actually selecting data and not only
         * counting the number of rows. */
        $orderByString = '';
        if (!$selectCountOnly) {
            $rawOrderBy = $tableOptions['orderby'] ?? '';
            $orderBy = $this->f->strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
            if ($orderBy == "final_dest") {
                // TODO change the final dest type to an integer and store external URLs somewhere else.
                $orderBy = "case when post_title is null then 1 else 0 end asc, post_title";
            } else {
                // only allow letters and the underscore in the orderby string.
                $orderBy = preg_replace('/[^a-zA-Z_]/', '', trim($orderBy));
            }
            $rawOrderVal = $tableOptions['order'] ?? '';
            $rawOrderValX = is_string($rawOrderVal) ? $rawOrderVal : '';
            $order = strtoupper((string)preg_replace('/[^a-zA-Z_]/', '', trim($rawOrderValX)));
            if ($order !== 'DESC') {
                $order = 'ASC';
            }
            $orderByString = "order by published_status asc, " . $orderBy . " " . $order .
                ", wp_abj404_redirects.url ASC, wp_abj404_redirects.id " . $order; // allow-prefix-literal: SQL alias bound by `FROM {wp_abj404_redirects} wp_abj404_redirects`
        }

        // Score range filter clause. wp_abj404_redirects below is the SQL alias from the assembled FROM clause, not a hardcoded table name.
        $rawScoreRange = is_string($tableOptions['score_range'] ?? '') ? ($tableOptions['score_range'] ?? 'all') : 'all';
        switch ($rawScoreRange) {
            case 'high':
                $scoreRangeClause = 'AND wp_abj404_redirects.score >= 80'; // allow-prefix-literal: SQL alias
                break;
            case 'medium':
                $scoreRangeClause = 'AND wp_abj404_redirects.score >= 50 AND wp_abj404_redirects.score < 80'; // allow-prefix-literal: SQL alias
                break;
            case 'low':
                $scoreRangeClause = 'AND wp_abj404_redirects.score IS NOT NULL AND wp_abj404_redirects.score < 50'; // allow-prefix-literal: SQL alias
                break;
            case 'manual':
                $scoreRangeClause = 'AND wp_abj404_redirects.score IS NULL'; // allow-prefix-literal: SQL alias
                break;
            default:
                $scoreRangeClause = '';
                break;
        }

        $searchFilterForRedirectsExists = "no redirects fiter text found";
        $searchFilterForCapturedExists = "no captured 404s filter text found";
        $filterText = '';
        $rawFilterText = is_string($tableOptions['filterText'] ?? null) ? $tableOptions['filterText'] : '';
        if ($rawFilterText != '') {
            if ($sub == 'abj404_redirects') {
                // Close the comment without including user input to avoid comment breakout.
                $searchFilterForRedirectsExists = ' filter text enabled */';
                
            } else if ($sub == 'abj404_captured') {
                // Close the comment without including user input to avoid comment breakout.
                $searchFilterForCapturedExists = ' filter text enabled */';
                
            } else {
                throw new Exception("Unrecognized page for filter text request.");
            }
        }

        // Sanitize filter text for use inside LIKE; strip comment markers and escape for SQL LIKE.
        $filterTextRaw = str_replace(array('*', '/', '$'), '', $rawFilterText);
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'esc_like')) {
            /** @var wpdb $wpdb */
            $filterTextRaw = $wpdb->esc_like($filterTextRaw);
        } else {
            $filterTextRaw = addcslashes($filterTextRaw, '_%\\');
        }
        $filterText = esc_sql($filterTextRaw);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsForView.sql");
        // Ensure consistent collation for string operations (e.g., REPLACE/LOWER) to avoid
        // "Illegal mix of collations" errors when plugin tables use *_bin collations.
        $wpdbCollate = 'utf8mb4_unicode_ci';
        $hasForcedCollate = false;
        if (array_key_exists('forceCollate', $tableOptions) && !empty($tableOptions['forceCollate'])) {
            $rawForceCollateVal = $tableOptions['forceCollate'];
            $rawForceCollate = is_string($rawForceCollateVal) ? $rawForceCollateVal : '';
            $forced = preg_replace('/[^A-Za-z0-9_]/', '', $rawForceCollate);
            if ($forced !== '') {
                $wpdbCollate = $forced;
                $hasForcedCollate = true;
            }
        }
        if (!$hasForcedCollate && isset($wpdb) && isset($wpdb->collate) && !empty($wpdb->collate)) {
            $wpdbCollate = preg_replace('/[^A-Za-z0-9_]/', '', $wpdb->collate);
        }
        if ($wpdbCollate === '') {
            $wpdbCollate = 'utf8mb4_unicode_ci';
        }
        $query = $this->f->str_replace('{selecting-for-count-true-false}', $selectCountReplacement, $query);
        $query = $this->f->str_replace('{statusTypes}', $statusTypes, $query);
        $query = $this->f->str_replace('{orderByString}', $orderByString, $query);
        $query = $this->f->str_replace('{limitStart}', (string)$limitStart, $query);
        $query = $this->f->str_replace('{limitEnd}', (string)$limitEnd, $query);
        $query = $this->f->str_replace('{searchFilterForRedirectsExists}', $searchFilterForRedirectsExists, $query);
        $query = $this->f->str_replace('{searchFilterForCapturedExists}', $searchFilterForCapturedExists, $query);
        $query = $this->f->str_replace('{filterText}', $filterText, $query);
        $query = $this->f->str_replace('{wpdb_collate}', $wpdbCollate, $query);
        $query = $this->f->str_replace('{logsTableColumns}', $logsTableColumns, $query);
        $query = $this->f->str_replace('{logsTableJoin}', $logsTableJoin, $query);
        $query = $this->f->str_replace('{trashValue}', (string)$trashValue, $query);
        $query = $this->f->str_replace('{scoreRangeClause}', $scoreRangeClause, $query);
        $query = $this->doTableNameReplacements($query);
        
        if (array_key_exists('translations', $tableOptions) && is_array($tableOptions['translations'])) {
            $keys = array_keys($tableOptions['translations']);
            $values = array_values($tableOptions['translations']);
            /** @var array<int, string> $keys */
            $query = $this->f->str_replace($keys, array_map('strval', $values), $query);
        }
        
        $query = $this->f->doNormalReplacements($query);

        return $query;
    }

    /**
     * Build an actionable query failure message for table warmup errors.
     *
     * @param string $queryLabel
     * @param string $query
     * @param array<string, mixed> $result
     * @return string
     */
    private function formatViewQueryFailureMessage(string $queryLabel, string $query, array $result): string {
        $lastErrorRaw = $result['last_error'] ?? '';
        $lastError = is_string($lastErrorRaw) ? trim($lastErrorRaw) : '';
        $timedOut = !empty($result['timed_out']);
        $sqlSource = $this->extractSqlFilename($query);

        if ($lastError === '' && $timedOut) {
            $lastError = $queryLabel . ' timed out';
        } else if ($lastError === '') {
            $lastError = $queryLabel . ' failed without a database error message';
        }

        return $queryLabel . ' failed'
            . '; last_error=' . $lastError
            . '; timed_out=' . ($timedOut ? 'true' : 'false')
            . '; sql_source=' . $sqlSource;
    }

    /**
     * @param array<int, string> $postIDs
     * @return array<int, mixed>
     */
    function getExtraDataToPermalinkSuggestions(array $postIDs): array {
        // Sanitize all post IDs to prevent SQL injection
        $postIDs = array_map('absint', $postIDs);
        $postIDJoined = implode(", ", $postIDs);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getAdditionalPostData.sql");
        $query = $this->f->str_replace('{IDS_TO_INCLUDE}', $postIDJoined, $query);
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);
        
        $results = $this->queryAndGetResults($query);

        /** @var array<int, mixed> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /**
     * Prepare a WordPress SQL query with placeholders and an associative data array.
     *
     * @param string $query The SQL query string with {placeholder} style placeholders.
     * @param array<string, mixed> $data An associative array with keys matching the placeholders in the query.
     * @return string The fully prepared SQL query.
     */
    function prepare_query_wp($query, $data) {
        global $wpdb;
        list($prepared_query, $ordered_values) = $this->prepare_query($query, $data);
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; callers execute the result through queryAndGetResults
        return $wpdb->prepare($prepared_query, $ordered_values);
    }
    
    /**
     * Prepare a SQL query with placeholders and an associative data array.
     *
     * @param string $query The SQL query string with {placeholder} style placeholders.
     * @param array<string, mixed> $data An associative array with keys matching the placeholders in the query.
     * @return array{0: string, 1: array<int, mixed>} Returns an array containing two elements: the prepared query string with %s or %d placeholders, and an ordered array of values for those placeholders.
     */
    function prepare_query($query, $data) {
        $ordered_values = [];
        $prepared_query = preg_replace_callback('/\{(\w+)\}/', function($matches) use ($data, &$ordered_values) {
            $key = $matches[1];
            if (!isset($data[$key])) {
                // Placeholder key not found in data array, ignore and continue
                return $matches[0];
            }
            $value = $data[$key];
            
            // Append the value to the ordered values array
            $ordered_values[] = $value;
            
            // Determine the placeholder type
            $placeholder_type = is_int($value) ? '%d' : '%s';
            
            return $placeholder_type;
        }, $query);
            
        return [$prepared_query !== null ? $prepared_query : $query, $ordered_values];
    }

    /**
     * Check if the hits table needs to be rebuilt.
     *
     * Rebuild is needed if:
     * 1. MAX(id) from logs differs from stored value (new entries or deletions)
     * 2. Table is older than HITS_TABLE_MAX_AGE_SECONDS (staleness check)
     *
     * @return bool True if rebuild needed
     */
}
