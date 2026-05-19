<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin list view read path, snapshot caching, and status counts.
 *
 * Extracted from DataAccess in Phase 6 of the DataAccess refactor.
 * Absorbs 5 traits: ViewQueries, ViewSnapshotCache, ViewMetadata,
 * ViewQueriesHitsLifecycle, ViewQueriesStagedRead.
 *
 * @see docs/dataaccess-refactor-plan.md Phase 6.
 */
class ABJ_404_Solution_ViewReadService implements ABJ_404_Solution_ViewReadServiceInterface {

    // --- Constants (duplicated from DataAccess for self:: usage) ---

    const CACHE_KEY_REDIRECT_STATUS = 'abj404_redirect_status_counts';
    const CACHE_KEY_CAPTURED_STATUS = 'abj404_captured_status_counts';
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = 'abj404_high_impact_captured';
    const STATUS_CACHE_TTL = 86400;
    const STATUS_CACHE_TIMEOUT_SELFHEAL_TTL = 300;
    const VIEW_SNAPSHOT_CACHE_TTL_SECONDS = 120;
    const VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS = 30;
    const VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS = 28;
    const VIEW_SNAPSHOT_WARMUP_STALE_SECONDS = 35;
    const VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS = 3;
    const VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES = 2097152;
    const HITS_TABLE_LAST_CHECKED_FLAG = 'abj404_logs_hits_last_checked_at';
    const HITS_TABLE_LAST_DECISION_FLAG = 'abj404_logs_hits_last_decision';
    const LOGS_COUNT_CACHE_TTL_SECONDS = 60;

    // --- Static properties (moved from DataAccess) ---

    /**
     * Per-request "bulk mutation in progress" flag. When set, per-row
     * invalidateStatusCountsCache() calls short-circuit to a no-op so a
     * 10K-row CSV import does not fire 60K invalidation queries (each row
     * cascades to bumpMutationWatermark + delete_option +
     * delete_transient x N + DELETE FROM view_cache; for a 10K import
     * this took ~63s before this guard). The bulk caller is responsible
     * for issuing ONE final invalidation (typically via
     * markViewDoneInvalidatedByAdminMutation()) after the bulk write
     * completes, so admin reads see the imported rows immediately.
     *
     * Implemented as a static so the flag survives across multiple
     * setupRedirect() calls within one request without needing every
     * caller to thread a parameter through.
     *
     * @var bool
     */
    public static $bulkMutationInProgress = false;

    /** @var bool */
    private static $viewSnapshotTableEnsured = false;

    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        self::$viewSnapshotTableEnsured = $value;
    }

    // --- Dependencies (constructor injection) ---

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    // --- ViewBuildOrchestrator bridge (setter injection, replaces temporary DataAccess coupling) ---

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface|null */
    private $viewBuildOrchestrator;

    // --- Instance property (moved from DataAccess) ---

    /** @var array<string, int> */
    private $redirectsForViewCountRequestCache = array();

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepo
     * @param ABJ_404_Solution_Functions|null $f Falls back to abj_service('functions')
     * @param ABJ_404_Solution_Logging|null $logger Falls back to abj_service('logging')
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_LogsRepository $logsRepo,
        ABJ_404_Solution_RedirectsRepository $redirectsRepo,
        $f = null,
        $logger = null
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->redirectsRepo = $redirectsRepo;
        $this->f = $f !== null ? $f : abj_service('functions');
        $this->logger = $logger !== null ? $logger : abj_service('logging');
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->viewBuildOrchestrator = $viewBuildOrchestrator;
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestratorInterface */
    private function requireViewBuildOrchestrator(): ABJ_404_Solution_ViewBuildOrchestratorInterface {
        if ($this->viewBuildOrchestrator === null) {
            throw new \RuntimeException('ViewReadService requires ViewBuildOrchestrator (call setViewBuildOrchestrator first)');
        }
        return $this->viewBuildOrchestrator;
    }

    // --- Locally replicated helper methods ---

    /** @return string */
    private function viewDoneTableName(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_done}');
    }

    /** @return string */
    private function viewDoneFreshnessOptionName(): string {
        return $this->dbCore->getLowercasePrefix() . 'abj404_view_done_built_at';
    }

    /** @return int */
    private function bumpMutationWatermark(): int {
        if (!class_exists('ABJ_404_Solution_MutationWatermark')) {
            return 0;
        }
        return ABJ_404_Solution_MutationWatermark::bump();
    }

    /** @return void */
    private function setSqlBigSelects(): void {
        $ignoreErrorsOptions = array('log_errors' => false);
        $this->dbCore->queryAndGetResults("set session max_join_size = 18446744073709551615",
            $ignoreErrorsOptions);
        $this->dbCore->queryAndGetResults("set session sql_big_selects = 1", $ignoreErrorsOptions);
    }

    // =========================================================================
    // FROM: DataAccessTrait_ViewQueries.php
    // =========================================================================

    /**
     * Get counts for each redirect status type for display in tabs.
     * Uses transient caching for performance.
     * @param bool $bypassCache If true, skip cache and query database directly
     * @return array<string, int> An array with keys: all, manual, auto, regex, trash
     */
    function getRedirectStatusCounts($bypassCache = false): array {
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
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
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
    function getCapturedStatusCounts($bypassCache = false): array {
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
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
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
     * getRedirectsForViewQuery() -- BINARY column equality.
     *
     * The previous implementation aggregated logsv2 with GROUP BY + HAVING per
     * call; on busy sites with millions of log rows that took 30-60s and hit
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
     * caching* so the next request retries -- otherwise a single transient
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
        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logsRepo->scheduleHitsTableRebuild();
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
            $this->logsRepo->scheduleHitsTableRebuild();
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
                $this->logsRepo->scheduleHitsTableRebuild();
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
        return $this->dbCore->doTableNameReplacements($query);
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
        $check = $this->dbCore->doTableNameReplacements($check);
        $result = $this->dbCore->queryAndGetResults($check);
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
        return $this->dbCore->queryAndGetResults($query, array(
            'timeout' => $timeoutSeconds,
        ));
    }

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
        $this->requireViewBuildOrchestrator()->invalidateViewDoneServeableCacheBridge();
        $this->requireViewBuildOrchestrator()->scheduleViewDoneRebuild();

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
        $this->dbCore->queryAndGetResults($query, array('log_errors' => false, 'skip_repair' => true));

        // Clear WordPress transients for view row and count snapshots.
        // The transient keys are hashed (e.g. abj404_view_rows_<md5>), so
        // we delete by prefix from wp_options directly.
        global $wpdb;
        if (isset($wpdb->options) && method_exists($wpdb, 'query')) {
            // @utf8-audit: opt-out -- $wpdb->options is the WordPress core
            // options table name (system value); never user input.
            /** @var string $optionsTable */
            $optionsTable = esc_sql($wpdb->options);
            // DAO-bypass-approved: View-cache clear targets wp_options -- outside the plugin's owned tables; runs during cache invalidation hot path; failure is best-effort
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
        $this->redirectsRepo->clearRegexRedirectsCache();
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
                $maxLogId = intval($this->logsRepo->getMaxLogId());
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
        $result = $this->dbCore->queryAndGetResults($query);
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
        $result = $this->dbCore->queryAndGetResults($query);
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
    	$query = $this->dbCore->doTableNameReplacements($query);

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
        $result = $this->dbCore->queryAndGetResults($query);
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
        $cached = ABJ_404_Solution_RedirectsRepository::getRegexRedirectsCache();
        $disabled = ABJ_404_Solution_RedirectsRepository::isRegexCacheDisabled();

        if ($cached !== null && !$disabled) {
            return $cached;
        }

        if ($disabled) {
            return $this->queryRegexRedirects();
        }

        $results = $this->queryRegexRedirects();

        if (count($results) <= ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT) {
            ABJ_404_Solution_RedirectsRepository::setRegexRedirectsCache($results);
        } else {
            ABJ_404_Solution_RedirectsRepository::setRegexCacheDisabled(true);
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
        $results = $this->dbCore->queryAndGetResults($query);

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
        $results = $this->dbCore->queryAndGetResults($query);

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
            $rows = $this->requireViewBuildOrchestrator()->runRedirectsForViewStaged((string)$sub, is_array($tableOptions) ? $tableOptions : array());
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
            $results = $this->dbCore->queryAndGetResults($query, $queryOptions);
            $lastErrorRaw = $results['last_error'] ?? '';
            $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        } else {
            // Search-filtered count needs to apply the LIKE composite against
            // the precomputed dest_for_view/status_for_view/type_for_view
            // columns, so route through the staged path.
            try {
                $countValue = $this->requireViewBuildOrchestrator()->runRedirectsForViewCountStaged((string)$sub, $tableOptions);
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

        return $this->dbCore->doTableNameReplacements($query);
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
            if ($this->logsRepo->logsHitsTableExists()) {
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
                // hasn't reached yet -- those rows merge in via the original
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
        $query = $this->dbCore->doTableNameReplacements($query);

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
        $sqlSource = $this->dbCore->extractSqlFilename($query);

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
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);

        $results = $this->dbCore->queryAndGetResults($query);

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

    // =========================================================================
    // FROM: DataAccessTrait_ViewSnapshotCache.php
    // =========================================================================

    /**
     * Build a stable cache key for admin list data/count snapshots.
     *
     * The key embeds the current per-blog mutation watermark, so any source-
     * data mutation (which already bumps the watermark via the centralized
     * invalidateViewSnapshotCache / bumpMutationWatermark seam) implicitly
     * invalidates every prior cache entry. Readers post-mutation generate a
     * new key, miss the cache, and rebuild from the fresh view_done snapshot.
     * Old entries become orphans and are reaped by the expires_at cleanup.
     *
     * Why this matters. The pre-watermark invalidation path manually issued
     * `DELETE FROM wp_options WHERE option_name LIKE '_transient_abj404_view_%'`
     * to drop the WP-transient mirror written alongside the table-backed
     * cache. That DELETE assumes transients live in wp_options; integration
     * tests that stub `set_transient` to a `$GLOBALS['test_transients']`
     * registry diverge silently, the transient survives the invalidate, and
     * the next read returns the pre-mutation snapshot. Version-keying makes
     * the manual delete optional (it now only matters for disk-space
     * reclamation, not correctness) and closes the test/production gap by
     * construction.
     *
     * @param string $prefix
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function getViewSnapshotCacheKey($prefix, $sub, $tableOptions) {
        $cacheShape = array(
            'sub' => (string)$sub,
            'filter' => is_scalar($tableOptions['filter'] ?? 0) ? (int)($tableOptions['filter'] ?? 0) : 0,
            'orderby' => is_scalar($tableOptions['orderby'] ?? 'url') ? (string)($tableOptions['orderby'] ?? 'url') : 'url',
            'order' => is_scalar($tableOptions['order'] ?? 'ASC') ? (string)($tableOptions['order'] ?? 'ASC') : 'ASC',
            'paged' => is_scalar($tableOptions['paged'] ?? 1) ? (int)($tableOptions['paged'] ?? 1) : 1,
            'perpage' => is_scalar($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) ? (int)($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) : ABJ404_OPTION_DEFAULT_PERPAGE,
            'filterText' => is_scalar($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '',
            'score_range' => (function ($v) { return is_string($v) ? $v : 'all'; })($tableOptions['score_range'] ?? 'all'),
            'blog' => function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1,
            'mw' => $this->readMutationWatermarkForCacheKey(),
        );
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($cacheShape) : json_encode($cacheShape);
        return $prefix . '_' . md5((string)$encoded);
    }

    /**
     * Read the current mutation watermark for the per-blog cache key, with
     * a defensive fallback of 0 when the watermark primitive is unavailable
     * for any reason: the class is not yet loaded (cold-bootstrap path
     * before the autoloader resolved it), the global `$wpdb` does not yet
     * expose the query / prepare / get_var triple the primitive needs
     * (legacy unit-test mocks that only expose `query`), or the underlying
     * MariaDB connection is mid-failure. The fallback collapses to a
     * single shared "version 0" bucket; correctness still holds because:
     *
     *   - In a healthy install, the first real mutation produces a
     *     non-zero watermark for every subsequent read, so cache keys
     *     diverge by version as designed.
     *   - In a degraded environment where the watermark can't be read, no
     *     watermark-bumping mutation can succeed either (the same wpdb is
     *     in use), so the cache is implicitly version-stable.
     *
     * Throwable catch is intentional: this primitive sits on the read hot
     * path and must never propagate a watermark read failure as a hard
     * fault into `getViewSnapshotCacheKey()`. A swallowed read produces
     * a less-precise cache key, not a broken read.
     *
     * @return int
     */
    private function readMutationWatermarkForCacheKey(): int {
        if (!class_exists('ABJ_404_Solution_MutationWatermark')) {
            return 0;
        }
        try {
            return ABJ_404_Solution_MutationWatermark::current();
            // allow-silent-catch: degraded wpdb (e.g. unit-test mocks lacking prepare()) falls back to "version 0" cache bucket; never propagate a watermark-read fault into the read hot path
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return void */
    private function ensureViewSnapshotTableExists(): void {
        if (self::$viewSnapshotTableEnsured) {
            return;
        }
        self::$viewSnapshotTableEnsured = true;
        $sqlFile = __DIR__ . '/sql/createViewCacheTable.sql';
        $create = ABJ_404_Solution_Functions::readFileContents($sqlFile);
        if (is_string($create) && trim($create) !== '') {
            $this->dbCore->queryAndGetResults($create, array('log_errors' => false));
        }
    }

    /** @param string $cacheKey @return string */
    private function getViewSnapshotLockOptionName(string $cacheKey): string {
        return $this->dbCore->getLowercasePrefix() . 'abj404_view_cache_lock_' . md5((string)$cacheKey);
    }

    /** @return string */
    private function getViewSnapshotWarmupGlobalLockKey(): string {
        return 'abj404_view_table_warmup_global';
    }

    /** @return bool */
    protected function acquireViewSnapshotWarmupGlobalLock(): bool {
        return $this->acquireViewSnapshotRefreshLock($this->getViewSnapshotWarmupGlobalLockKey());
    }

    /** @return void */
    protected function releaseViewSnapshotWarmupGlobalLock(): void {
        $this->releaseViewSnapshotRefreshLock($this->getViewSnapshotWarmupGlobalLockKey());
    }

    /** @param string $cacheKey @return string */
    private function getViewWarmupStateOptionName(string $cacheKey): string {
        return $this->dbCore->getLowercasePrefix() . 'abj404_view_warmup_' . md5((string)$cacheKey);
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    private function canUseViewTableSnapshotCache(array $tableOptions): bool {
        if (!empty($tableOptions['_abj404_force_view_rebuild'])) {
            return false;
        }
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $isLogsMaintenanceSort = ($orderBy === 'logshits' || $orderBy === 'last_used');
        $rawPerpage = $tableOptions['perpage'] ?? 0;
        return absint(is_scalar($rawPerpage) ? $rawPerpage : 0) <= 200 && !$isLogsMaintenanceSort;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function getViewTableWarmupShapeKey(string $sub, array $tableOptions): string {
        return $this->getViewSnapshotCacheKey('abj404_view_table', $sub, $tableOptions);
    }

    /**
     * @param mixed $state
     * @return array<string, mixed>
     */
    private function normalizeViewWarmupState($state): array {
        $default = array(
            'status' => 'idle',
            'stage' => 'rows',
            'stage_started_at' => 0,
            'stage_completed_at' => 0,
            'attempts_by_stage' => array('rows' => 0, 'count' => 0),
            'timings_by_stage' => array(
                'rows' => array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => ''),
                'count' => array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => ''),
            ),
            'query_label' => 'getRedirectsForView',
            'last_error' => '',
            'logged_stale_by_stage' => array(),
            'build_progress_at_stage_start' => array(),
        );
        if (!is_array($state)) {
            return $default;
        }
        /** @var array<string, mixed> $out */
        $out = array_merge($default, $state);
        $status = is_string($out['status'] ?? null) ? $out['status'] : 'idle';
        if (!in_array($status, array('idle', 'running', 'ready', 'blocked', 'error'), true)) {
            $out['status'] = 'idle';
        } else {
            $out['status'] = $status;
        }
        $stage = is_string($out['stage'] ?? null) ? $out['stage'] : 'rows';
        if (!in_array($stage, array('rows', 'count'), true)) {
            $out['stage'] = 'rows';
        } else {
            $out['stage'] = $stage;
        }
        $stageStartedAt = $out['stage_started_at'] ?? 0;
        $stageCompletedAt = $out['stage_completed_at'] ?? 0;
        $out['stage_started_at'] = is_scalar($stageStartedAt) ? intval($stageStartedAt) : 0;
        $out['stage_completed_at'] = is_scalar($stageCompletedAt) ? intval($stageCompletedAt) : 0;

        $attempts = is_array($out['attempts_by_stage']) ? $out['attempts_by_stage'] : array();
        $attemptsRows = $attempts['rows'] ?? 0;
        $attemptsCount = $attempts['count'] ?? 0;
        $out['attempts_by_stage'] = array(
            'rows' => is_scalar($attemptsRows) ? intval($attemptsRows) : 0,
            'count' => is_scalar($attemptsCount) ? intval($attemptsCount) : 0,
        );

        $timings = is_array($out['timings_by_stage']) ? $out['timings_by_stage'] : array();
        $out['timings_by_stage'] = array(
            'rows' => $this->normalizeStageTiming($timings['rows'] ?? null),
            'count' => $this->normalizeStageTiming($timings['count'] ?? null),
        );

        $out['query_label'] = is_string($out['query_label'] ?? null) ? $out['query_label'] : $this->getViewWarmupStageQueryLabel((string)$out['stage']);
        $out['last_error'] = is_string($out['last_error'] ?? null) ? $out['last_error'] : '';
        $out['logged_stale_by_stage'] = is_array($out['logged_stale_by_stage']) ? $out['logged_stale_by_stage'] : array();
        $out['build_progress_at_stage_start'] = is_array($out['build_progress_at_stage_start'] ?? null)
            ? $out['build_progress_at_stage_start'] : array();
        return $out;
    }

    /**
     * @param mixed $timing
     * @return array<string, mixed>
     */
    private function normalizeStageTiming($timing): array {
        $default = array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => '');
        if (!is_array($timing)) {
            return $default;
        }
        $lastMs = $timing['last_ms'] ?? 0;
        $maxMs = $timing['max_ms'] ?? 0;
        $lastCompletedAt = $timing['last_completed_at'] ?? 0;
        $lastError = $timing['last_error'] ?? '';
        return array(
            'last_ms' => is_scalar($lastMs) ? intval($lastMs) : 0,
            'max_ms' => is_scalar($maxMs) ? intval($maxMs) : 0,
            'last_completed_at' => is_scalar($lastCompletedAt) ? intval($lastCompletedAt) : 0,
            'last_error' => is_string($lastError) ? $lastError : '',
        );
    }

    /** @param string $stage @return string */
    private function getViewWarmupStageQueryLabel(string $stage): string {
        return $stage === 'count' ? 'getRedirectsForViewCount' : 'getRedirectsForView';
    }

    /** @param string $stage @return int */
    private function getViewWarmupStageNumber(string $stage): int {
        return $stage === 'count' ? 2 : 1;
    }

    /** @return array<string, int> */
    public function getViewBuildProgressFingerprint(): array {
        return array(
            'started_at' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('started_at', 0),
            'current_stage' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('current_stage', 0),
            'last_started_stage' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('last_started_stage', 0),
            'last_completed_stage' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('last_completed_stage', 0),
            's2_high_water' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('s2_high_water', 0),
            's4_high_water' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('s4_high_water', 0),
            's5_high_water' => $this->requireViewBuildOrchestrator()->readBuildProgressOption('s5_high_water', 0),
        );
    }

    /**
     * @param mixed $baseline
     * @param array<string, int>|null $current
     * @return bool
     */
    private function viewBuildProgressAdvancedSince($baseline, ?array $current = null): bool {
        if (!is_array($baseline) || empty($baseline)) {
            return false;
        }
        $current = $current ?? $this->getViewBuildProgressFingerprint();
        foreach (array('current_stage', 's2_high_water', 's4_high_water', 's5_high_water') as $key) {
            $before = is_scalar($baseline[$key] ?? null) ? intval($baseline[$key]) : 0;
            $after = is_scalar($current[$key] ?? null) ? intval($current[$key]) : 0;
            if ($after > $before) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @param string $stage
     * @param array<string, int>|null $currentProgress
     * @return bool
     */
    private function forgiveWarmupAttemptIfBuildProgressed(array &$state, string $stage, ?array $currentProgress = null): bool {
        if (!$this->viewBuildProgressAdvancedSince($state['build_progress_at_stage_start'] ?? array(), $currentProgress)) {
            return false;
        }
        $attempts = is_array($state['attempts_by_stage'] ?? null) ? $state['attempts_by_stage'] : array('rows' => 0, 'count' => 0);
        $rawAttempt = $attempts[$stage] ?? 0;
        $attempts[$stage] = max(0, (is_scalar($rawAttempt) ? intval($rawAttempt) : 0) - 1);
        $state['attempts_by_stage'] = $attempts;
        $state['build_progress_at_stage_start'] = is_array($currentProgress)
            ? $currentProgress : $this->getViewBuildProgressFingerprint();
        return true;
    }

    /**
     * @param string $optionName
     * @return array<string, mixed>
     */
    private function getViewWarmupState(string $optionName): array {
        if (!function_exists('get_option')) {
            return $this->normalizeViewWarmupState(null);
        }
        return $this->normalizeViewWarmupState(get_option($optionName, array()));
    }

    /**
     * @param string $optionName
     * @param array<string, mixed> $state
     * @return void
     */
    private function setViewWarmupState(string $optionName, array $state): void {
        if (function_exists('update_option')) {
            update_option($optionName, $state, false);
        } else if (function_exists('add_option')) {
            add_option($optionName, $state, '', false);
        }
    }

    /**
     * Warm exactly one admin table snapshot stage, then return progress.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    function warmViewTableSnapshotStage(string $sub, array $tableOptions): array {
        if (!$this->canUseViewTableSnapshotCache($tableOptions)) {
            return array(
                'status' => 'ready',
                'ready' => true,
                'uncached' => true,
                'stage' => 'rows',
                'stageNumber' => 1,
                'queryLabel' => 'getRedirectsForView',
                'message' => 'This table shape is not snapshot-cacheable.',
            );
        }

        $shapeKey = $this->getViewTableWarmupShapeKey($sub, $tableOptions);
        $optionName = $this->getViewWarmupStateOptionName($shapeKey);
        $state = $this->getViewWarmupState($optionName);
        $now = time();

        if ($this->viewTableSnapshotAvailable($sub, $tableOptions)) {
            $state['status'] = 'ready';
            $state['stage'] = 'count';
            $state['query_label'] = 'getRedirectsForViewCount';
            $state['stage_completed_at'] = $now;
            $state['last_error'] = '';
            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, true);
        }

        if ($this->viewRowsSnapshotAvailable($sub, $tableOptions)) {
            $state['stage'] = 'count';
            $state['query_label'] = 'getRedirectsForViewCount';
        } else {
            $state['stage'] = 'rows';
            $state['query_label'] = 'getRedirectsForView';
        }

        $stage = (string)$state['stage'];
        $attempts = is_array($state['attempts_by_stage']) ? $state['attempts_by_stage'] : array('rows' => 0, 'count' => 0);
        $attemptCountRaw = $attempts[$stage] ?? 0;
        $attemptCount = is_scalar($attemptCountRaw) ? intval($attemptCountRaw) : 0;

        if ($state['status'] === 'running') {
            $stageStartedAt = $state['stage_started_at'] ?? 0;
            $elapsed = $now - (is_scalar($stageStartedAt) ? intval($stageStartedAt) : 0);
            if ($elapsed <= self::VIEW_SNAPSHOT_WARMUP_STALE_SECONDS) {
                return $this->formatViewWarmupResponse($state, false);
            }
            $currentBuildProgress = $this->getViewBuildProgressFingerprint();
            if ($this->forgiveWarmupAttemptIfBuildProgressed($state, $stage, $currentBuildProgress)) {
                $attempts = is_array($state['attempts_by_stage']) ? $state['attempts_by_stage'] : array('rows' => 0, 'count' => 0);
                $attemptCountRaw = $attempts[$stage] ?? 0;
                $attemptCount = is_scalar($attemptCountRaw) ? intval($attemptCountRaw) : 0;
            }
            if ($attemptCount >= self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
                $state['status'] = 'blocked';
                $previousLastError = $state['last_error'] ?? '';
                $previousError = is_string($previousLastError) ? trim($previousLastError) : '';
                $state['last_error'] = 'Previous warmup stage was killed or stalled too many times.'
                    . ($this->isViewWarmupErrorDiagnostic($previousError) ? ' Previous error: ' . $previousError : '');
                $this->logViewWarmupFailure($sub, $tableOptions, $state);
                $this->setViewWarmupState($optionName, $state);
                return $this->formatViewWarmupResponse($state, false);
            }
            $loggedKey = $stage . ':' . (is_scalar($stageStartedAt) ? intval($stageStartedAt) : 0);
            $loggedStaleByStage = is_array($state['logged_stale_by_stage'] ?? null) ? $state['logged_stale_by_stage'] : array();
            if (empty($loggedStaleByStage[$loggedKey])) {
                $this->logStaleViewWarmupStage($sub, $tableOptions, $state, $elapsed, $attemptCount);
                $loggedStaleByStage[$loggedKey] = 1;
                $state['logged_stale_by_stage'] = $loggedStaleByStage;
            }
        }

        if ($attemptCount >= self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
            $previousLastError = $state['last_error'] ?? '';
            $previousError = is_string($previousLastError) ? trim($previousLastError) : '';
            if (!$this->isViewWarmupErrorDiagnostic($previousError)) {
                $attempts[$stage] = self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS - 1;
                $state['attempts_by_stage'] = $attempts;
                $attemptCount = is_scalar($attempts[$stage] ?? 0) ? intval($attempts[$stage]) : 0;
            }
        }

        if ($attemptCount >= self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) {
            $state['status'] = 'blocked';
            $state['last_error'] = 'Warmup stage reached the retry limit.'
                . ($this->isViewWarmupErrorDiagnostic($previousError) ? ' Previous error: ' . $previousError : '');
            $this->logViewWarmupFailure($sub, $tableOptions, $state);
            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, false);
        }

        if (!$this->acquireViewSnapshotWarmupGlobalLock()) {
            $state['status'] = 'running';
            $state['last_error'] = 'Another table cache warmup is already running for this site.';
            return $this->formatViewWarmupResponse($state, false, array(
                'locked' => true,
                'lockScope' => 'site',
                'retryAfterMs' => 2500,
            ));
        }

        $attempts[$stage] = $attemptCount + 1;
        $state['status'] = 'running';
        $state['stage_started_at'] = $now;
        $state['stage_completed_at'] = 0;
        $state['attempts_by_stage'] = $attempts;
        $state['query_label'] = $this->getViewWarmupStageQueryLabel($stage);
        $state['last_error'] = '';
        $state['build_progress_at_stage_start'] = $this->getViewBuildProgressFingerprint();
        $this->setViewWarmupState($optionName, $state);

        $stageOptions = $tableOptions;
        $stageOptions['_abj404_query_timeout'] = self::VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS;
        $stageOptions['_abj404_throw_on_view_query_error'] = true;

        $startMs = microtime(true);
        try {
            if ($stage === 'rows') {
                $this->getRedirectsForView($sub, $stageOptions);
                if (!$this->viewRowsSnapshotAvailable($sub, $tableOptions)) {
                    throw new \Exception('Warmup rows stage completed but the row snapshot was not available afterward.');
                }
                $state['status'] = 'idle';
                $state['stage'] = 'count';
                $state['query_label'] = 'getRedirectsForViewCount';
            } else {
                $this->getRedirectsForViewCount($sub, $stageOptions);
                if (!$this->viewTableSnapshotAvailable($sub, $tableOptions)) {
                    throw new \Exception('Warmup count stage completed but the full table snapshot was not available afterward.');
                }
                $state['status'] = 'ready';
                $state['stage'] = 'count';
                $state['query_label'] = 'getRedirectsForViewCount';
            }
            $elapsedMs = (int)round((microtime(true) - $startMs) * 1000);
            $state['stage_completed_at'] = time();
            $state['last_error'] = '';

            $timingsByStage = is_array($state['timings_by_stage'] ?? null) ? $state['timings_by_stage'] : array();
            $timings = $this->normalizeStageTiming($timingsByStage[$stage] ?? null);
            $timings['last_ms'] = $elapsedMs;
            $timings['max_ms'] = max($timings['max_ms'], $elapsedMs);
            $timings['last_completed_at'] = $state['stage_completed_at'];
            $timings['last_error'] = '';
            $timingsByStage[$stage] = $timings;
            $state['timings_by_stage'] = $timingsByStage;

            $this->logger->debugMessage(sprintf(
                "[warmup] shape=%s stage=%s ms=%d attempts=%d error=",
                substr($shapeKey, 0, 8),
                $stage,
                $elapsedMs,
                $attemptCount + 1
            ));

            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, $state['status'] === 'ready');
        } catch (Throwable $e) {
            $elapsedMs = (int)round((microtime(true) - $startMs) * 1000);
            $errorMessage = $e->getMessage();
            $state['last_error'] = $errorMessage;
            $state['stage_completed_at'] = time();
            $currentAttempts = $attempts[$stage] ?? 0;
            if ($this->forgiveWarmupAttemptIfBuildProgressed($state, $stage)) {
                $attempts = is_array($state['attempts_by_stage']) ? $state['attempts_by_stage'] : $attempts;
                $rawAttemptCount = $attempts[$stage] ?? 0;
                $currentAttempts = is_scalar($rawAttemptCount) ? intval($rawAttemptCount) : 0;
            }
            $state['status'] = ($currentAttempts >= self::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS) ? 'blocked' : 'idle';

            $timingsByStage = is_array($state['timings_by_stage'] ?? null) ? $state['timings_by_stage'] : array();
            $timings = $this->normalizeStageTiming($timingsByStage[$stage] ?? null);
            $timings['last_error'] = $errorMessage;
            $timingsByStage[$stage] = $timings;
            $state['timings_by_stage'] = $timingsByStage;

            $this->logger->debugMessage(sprintf(
                "[warmup] shape=%s stage=%s ms=%d attempts=%d error=%s",
                substr($shapeKey, 0, 8),
                $stage,
                $elapsedMs,
                $attemptCount + 1,
                $errorMessage
            ));

            $this->logViewWarmupFailure($sub, $tableOptions, $state);
            $this->setViewWarmupState($optionName, $state);
            return $this->formatViewWarmupResponse($state, false);
        } finally {
            $this->releaseViewSnapshotWarmupGlobalLock();
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param bool $ready
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function formatViewWarmupResponse(array $state, bool $ready, array $extra = array()): array {
        $stageValue = $state['stage'] ?? 'rows';
        $stage = is_string($stageValue) ? $stageValue : 'rows';
        $statusValue = $state['status'] ?? 'idle';
        $status = is_string($statusValue) ? $statusValue : 'idle';
        $stageStartedAt = $state['stage_started_at'] ?? 0;
        $stageCompletedAt = $state['stage_completed_at'] ?? 0;
        $lastError = $state['last_error'] ?? '';
        $response = array(
            'status' => $status,
            'ready' => $ready || $status === 'ready',
            'stage' => $stage,
            'stageNumber' => $this->getViewWarmupStageNumber($stage),
            'queryLabel' => $this->getViewWarmupStageQueryLabel($stage),
            'stageStartedAt' => is_scalar($stageStartedAt) ? intval($stageStartedAt) : 0,
            'stageCompletedAt' => is_scalar($stageCompletedAt) ? intval($stageCompletedAt) : 0,
            'attemptsByStage' => is_array($state['attempts_by_stage'] ?? null) ? $state['attempts_by_stage'] : array(),
            'timingsByStage' => is_array($state['timings_by_stage'] ?? null) ? $state['timings_by_stage'] : array(),
            'lastError' => is_string($lastError) ? $lastError : '',
        );
        foreach ($extra as $key => $value) {
            if (is_string($key)) {
                $response[$key] = $value;
            }
        }
        return $response;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $state
     * @param int $elapsed
     * @param int $attemptCount
     * @return void
     */
    private function logStaleViewWarmupStage(string $sub, array $tableOptions, array $state, int $elapsed, int $attemptCount): void {
        $details = array(
            'stage' => is_string($state['stage'] ?? null) ? $state['stage'] : '',
            'query_label' => is_string($state['query_label'] ?? null) ? $state['query_label'] : '',
            'elapsed_seconds' => $elapsed,
            'subpage' => $sub,
            'attempt_count' => $attemptCount,
            'table_shape' => array(
                'filter' => $tableOptions['filter'] ?? null,
                'orderby' => $tableOptions['orderby'] ?? null,
                'order' => $tableOptions['order'] ?? null,
                'paged' => $tableOptions['paged'] ?? null,
                'perpage' => $tableOptions['perpage'] ?? null,
                'filterText_length' => is_string($tableOptions['filterText'] ?? null) ? strlen($tableOptions['filterText']) : 0,
                'score_range' => $tableOptions['score_range'] ?? null,
            ),
        );
        $message = 'Table cache warmup stage appears stalled: ' . json_encode($details);
        $this->logger->warn($message);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $state
     * @return void
     */
    private function logViewWarmupFailure(string $sub, array $tableOptions, array $state): void {
        $details = array(
            'status' => is_string($state['status'] ?? null) ? $state['status'] : '',
            'stage' => is_string($state['stage'] ?? null) ? $state['stage'] : '',
            'stage_number' => $this->getViewWarmupStageNumber(is_string($state['stage'] ?? null) ? $state['stage'] : 'rows'),
            'query_label' => is_string($state['query_label'] ?? null) ? $state['query_label'] : '',
            'last_error' => is_string($state['last_error'] ?? null) ? $state['last_error'] : '',
            'subpage' => $sub,
            'attempts_by_stage' => is_array($state['attempts_by_stage'] ?? null) ? $state['attempts_by_stage'] : array(),
            'table_shape' => array(
                'filter' => $tableOptions['filter'] ?? null,
                'orderby' => $tableOptions['orderby'] ?? null,
                'order' => $tableOptions['order'] ?? null,
                'paged' => $tableOptions['paged'] ?? null,
                'perpage' => $tableOptions['perpage'] ?? null,
                'filterText_length' => is_string($tableOptions['filterText'] ?? null) ? strlen($tableOptions['filterText']) : 0,
                'score_range' => $tableOptions['score_range'] ?? null,
            ),
        );
        $message = 'Table cache warmup failed: ' . json_encode($details);
        $this->logger->errorMessage($message);
    }

    /** @param string $lastError @return bool */
    private function isViewWarmupErrorDiagnostic(string $lastError): bool {
        if ($lastError === '') {
            return false;
        }
        return $lastError !== 'Warmup stage reached the retry limit.'
            && $lastError !== 'Previous warmup stage was killed or stalled too many times.';
    }

    /** @param string $cacheKey @return bool */
    private function isViewSnapshotRefreshLocked(string $cacheKey): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $lockKey = $this->getViewSnapshotLockOptionName($cacheKey);
        $lockValue = get_option($lockKey, false);
        if ($lockValue === false || $lockValue === '' || $lockValue === null) {
            return false;
        }
        $lockTs = is_numeric($lockValue) ? (int)$lockValue : 0;
        if ($lockTs > 0 && (time() - $lockTs) > self::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS) {
            if (function_exists('delete_option')) {
                delete_option($lockKey);
            }
            return false;
        }
        return true;
    }

    /** @param string $cacheKey @return bool */
    private function acquireViewSnapshotRefreshLock(string $cacheKey): bool {
        if (!function_exists('add_option')) {
            return true;
        }
        if ($this->isViewSnapshotRefreshLocked($cacheKey)) {
            return false;
        }
        $lockKey = $this->getViewSnapshotLockOptionName($cacheKey);
        return (bool)add_option($lockKey, time(), '', false);
    }

    /** @param string $cacheKey @return void */
    private function releaseViewSnapshotRefreshLock(string $cacheKey): void {
        if (function_exists('delete_option')) {
            delete_option($this->getViewSnapshotLockOptionName($cacheKey));
        }
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>|null
     */
    private function decodeSnapshotPayload($payload) {
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $cacheKey
     * @param bool $allowExpired
     * @param bool $respectCooldown
     * @return array<string, mixed>|null
     */
    private function getViewRowsSnapshotFromTable(string $cacheKey, bool $allowExpired = false, bool $respectCooldown = false) {
        $this->ensureViewSnapshotTableExists();
        $query = "SELECT payload, refreshed_at, expires_at
            FROM {wp_abj404_view_cache}
            WHERE cache_key = %s LIMIT 1";
        $result = $this->dbCore->queryAndGetResults($query, array('query_params' => array($cacheKey), 'log_errors' => true));
        $resultRows = $result['rows'] ?? array();
        if (!is_array($resultRows) || empty($resultRows) || !is_array($resultRows[0])) {
            return null;
        }
        $row = $resultRows[0];
        $expiresAtRaw = $row['expires_at'] ?? 0;
        $refreshedAtRaw = $row['refreshed_at'] ?? 0;
        $expiresAt = is_scalar($expiresAtRaw) ? intval($expiresAtRaw) : 0;
        $refreshedAt = is_scalar($refreshedAtRaw) ? intval($refreshedAtRaw) : 0;
        $now = time();
        $isFresh = ($expiresAt > $now);
        $recentEnough = ($refreshedAt > 0 && ($now - $refreshedAt) <= self::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS);
        if (!$allowExpired && !$isFresh) {
            return null;
        }
        if ($respectCooldown && !$isFresh && !$recentEnough) {
            return null;
        }
        $payload = $row['payload'] ?? '';
        return $this->decodeSnapshotPayload(is_scalar($payload) ? (string)$payload : '');
    }

    /**
     * @param string $cacheKey
     * @param string $sub
     * @param mixed $rows
     * @param int $ttlSeconds
     * @return void
     */
    private function setViewRowsSnapshotToTable(string $cacheKey, string $sub, $rows, int $ttlSeconds): void {
        if (!is_array($rows)) {
            return;
        }
        $this->ensureViewSnapshotTableExists();
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($rows) : json_encode($rows);
        if (!is_string($encoded)) {
            return;
        }
        $bytes = strlen($encoded);
        if ($bytes > self::VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES) {
            return;
        }
        $now = time();
        $expiresAt = $now + max(1, intval($ttlSeconds));
        $query = "INSERT INTO {wp_abj404_view_cache}
            (cache_key, subpage, payload, payload_bytes, refreshed_at, expires_at, updated_at)
            VALUES (%s, %s, %s, %d, %d, %d, %d)
            ON DUPLICATE KEY UPDATE
                subpage = VALUES(subpage),
                payload = VALUES(payload),
                payload_bytes = VALUES(payload_bytes),
                refreshed_at = VALUES(refreshed_at),
                expires_at = VALUES(expires_at),
                updated_at = VALUES(updated_at)";
        $this->dbCore->queryAndGetResults($query, array(
            'query_params' => array($cacheKey, (string)$sub, $encoded, $bytes, $now, $expiresAt, $now),
            'log_errors' => false,
        ));
        $this->cleanupExpiredViewSnapshotRowsIfNeeded();
    }

    /**
     * @param string $cacheKey
     * @param int $timeoutMs
     * @return array<string, mixed>|null
     */
    private function waitForViewRowsSnapshotFromTable(string $cacheKey, int $timeoutMs = 4000) {
        $deadline = microtime(true) + (max(100, intval($timeoutMs)) / 1000);
        while (microtime(true) < $deadline) {
            $rows = $this->getViewRowsSnapshotFromTable($cacheKey, false, false);
            if (is_array($rows)) {
                return $rows;
            }
            usleep(100000);
        }
        return null;
    }

    /** @return void */
    private function cleanupExpiredViewSnapshotRowsIfNeeded(): void {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return;
        }
        $marker = get_transient('abj404_view_cache_cleanup_marker');
        if ($marker !== false) {
            return;
        }
        set_transient('abj404_view_cache_cleanup_marker', time(), 1800);
        $query = "DELETE FROM {wp_abj404_view_cache} WHERE expires_at < %d";
        $this->dbCore->queryAndGetResults($query, array(
            'query_params' => array(time() - self::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS),
            'log_errors' => false,
        ));
    }

    // =========================================================================
    // FROM: DataAccessTrait_ViewMetadata.php
    // =========================================================================

    /** @return array<string, mixed> */
    function getTableEngines() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/selectTableEngines.sql");
    	$results = $this->dbCore->queryAndGetResults($query);
    	return $results;
    }

    /** @return bool */
    function isMyISAMSupported(): bool {
        $supportResults = $this->dbCore->queryAndGetResults("SELECT ENGINE, SUPPORT " .
            "FROM information_schema.ENGINES WHERE lower(ENGINE) = 'myisam'",
            array('log_errors' => false));

        if (!empty($supportResults) && !empty($supportResults['rows']) && is_array($supportResults['rows'])) {
            $rows = $supportResults['rows'];
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $supportValue = array_key_exists('support', $row) ? (string)($row['support'] ?? '') :
            (array_key_exists('SUPPORT', $row) ? (string)($row['SUPPORT'] ?? '') : "nope");

            return strtolower($supportValue) == 'yes';
        }
        return false;
    }

    /** Insert data into the database.
     * Create my own insert statement because wordpress messes it up when the field
     * length is too long. this also returns the correct value for the last_query.
     * @global type $wpdb
     * @param string $tableName
     * @param array<string, mixed> $dataToInsert
     * @return array<string, mixed>
     */
    function insertAndGetResults($tableName, $dataToInsert) {
        $tableName = $this->dbCore->doTableNameReplacements($tableName);

        $columns = array();
        $placeholders = array();
        $values = array();

        foreach ($dataToInsert as $column => $value) {
            $columns[] = '`' . $column . '`';

            if ($value === null) {
                $placeholders[] = 'NULL';
            } else {
                $currentDataType = gettype($value);
                if ($currentDataType == 'integer' || $currentDataType == 'double') {
                    $placeholders[] = '%d';
                    $values[] = $value;
                } elseif ($currentDataType == 'boolean') {
                    $placeholders[] = '%d';
                    $values[] = $value ? 1 : 0;
                } else {
                    $placeholders[] = '%s';
                    $values[] = is_scalar($value) ? (string)$value : '';
                }
            }
        }

        $sql = 'INSERT INTO `' . $tableName . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        return $this->dbCore->queryAndGetResults($sql, ['query_params' => $values]);
    }

   /**
    * @return int the total number of redirects that have been captured.
    */
   function getCapturedCount() {
       $query = "select count(id) from {wp_abj404_redirects} where status = " . absint(ABJ404_STATUS_CAPTURED);

       $result = $this->dbCore->queryAndGetResults($query);
       if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
           return 0;
       }

       $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
       if (empty($rows)) {
           return 0;
       }
       $first = $rows[0];
       $value = is_array($first) ? reset($first) : $first;
       return intval($value);
   }

   /** Get all of the post types from the wp_posts table.
    * @return array<int, string> An array of post type names. */
   function getAllPostTypes() {
       $query = "SELECT DISTINCT post_type FROM {wp_posts} order by post_type";
       $results = $this->dbCore->queryAndGetResults($query);
       $rows = $results['rows'];

       $postType = array();

       if (is_array($rows)) {
           foreach ($rows as $row) {
               array_push($postType, $row['post_type']);
           }
       }

       return $postType;
   }

   /** Get the approximate number of bytes used by the logs table.
    *
    * @return int Bytes used by the logs table, 0 on missing/empty stats,
    *             or -1 if the lookup itself failed/timed out.
    */
   function getLogDiskUsage() {
       $query = 'SELECT (data_length+index_length) tablesize FROM information_schema.tables '
               . 'WHERE table_name=\'{wp_abj404_logsv2}\'';

       $result = $this->dbCore->queryAndGetResults($query);

       if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
           $err = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
           if ($err !== '') {
               $this->logger->errorMessage("Error: " . esc_html($err));
           }
           return -1;
       }

       $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
       if (empty($rows)) {
           return 0;
       }

       $row = is_array($rows[0] ?? null) ? $rows[0] : array();
       $size = $row['tablesize'] ?? null;
       if ($size === null || !is_scalar($size)) {
           return 0;
       }
       return intval($size);
   }

    /**
     * @global type $wpdb
     * @param array<int, int> $types specified types such as ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED.
     * @param int $trashed 1 to only include disabled redirects. 0 to only include enabled redirects.
     * @return int the number of records matching the specified types.
     */
    function getRecordCount($types = array(), $trashed = 0) {
        $recordCount = 0;

        if (count($types) >= 1) {
            $query = "select count(id) as count from {wp_abj404_redirects} where 1 and (status in (";

            $filteredTypes = array_map('absint', $types);
            $typesForSQL = implode(", ", $filteredTypes);
            $query .= $typesForSQL . "))";
            $query .= " and disabled = " . absint($trashed);

            $result = $this->dbCore->queryAndGetResults($query);
            $rows = is_array($result['rows']) ? $result['rows'] : array();
            if (!empty($rows)) {
	            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
	            $recordCount = isset($row['count']) && is_scalar($row['count']) ? intval($row['count']) : 0;
            }
        }

        return intval($recordCount);
    }

    /**
     * Capture a structured diagnostics snapshot when getRedirectsForView() or
     * getRedirectsForViewCount() fails or times out. The snapshot is intended
     * to give support enough evidence in a single debug zip / AJAX response to
     * identify the root cause of slow view queries (missing index, MyISAM
     * corruption, multi-million-row logsv2, canonical_url not backfilled,
     * collation mismatch, etc.) without a follow-up round trip to the user.
     *
     * Every sub-probe is wrapped in try/catch with a tight per-query timeout:
     * one failed probe never blocks the others, and the original failure is
     * never masked by a diagnostic capture exception.
     *
     * @param string $sub Subpage that triggered the query (abj404_redirects / abj404_captured / abj404_logs).
     * @param string $failedQuery The SQL that failed (used for EXPLAIN + redacted shape).
     * @param array<string, mixed> $tableOptions The original tableOptions (kept for context echo).
     * @param array<string, mixed> $queryResult The wpdb-shaped result of the failed call (last_error / timed_out / elapsed_time).
     * @return array<string, mixed>
     */
    public function captureViewQueryFailureDiagnostics(string $sub, string $failedQuery, array $tableOptions, array $queryResult): array {
        $diag = array(
            'failed_query_label' => '',
            'failed_query_redacted' => '',
            'last_error' => '',
            'timed_out' => false,
            'elapsed_time_seconds' => null,
            'sub' => $sub,
            'redirects_count' => array('active' => null, 'trashed' => null),
            'logsv2_count' => null,
            'wp_posts_count' => null,
            'tables' => array(),
            'expected_indexes' => array(),
            'canonical_url_state' => array(),
            'db_version' => '',
            'explain' => null,
        );

        $diag['failed_query_label'] = $this->resolveViewQueryDiagnosticLabel($failedQuery, $sub);
        $diag['failed_query_redacted'] = $this->redactQueryShapeForDiagnostics($failedQuery);

        $lastError = is_string($queryResult['last_error'] ?? null) ? $queryResult['last_error'] : '';
        $diag['last_error'] = $lastError;
        $diag['timed_out'] = !empty($queryResult['timed_out']);
        if (isset($queryResult['elapsed_time']) && is_numeric($queryResult['elapsed_time'])) {
            $diag['elapsed_time_seconds'] = (float)$queryResult['elapsed_time'];
        }

        global $wpdb;
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $logsv2Table = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $postsTable = $this->resolvePostsTableName();

        $diag['explain'] = $this->safeProbeExplain($failedQuery);
        $diag['db_version'] = $this->safeProbeDbVersion();

        $diag['redirects_count']['active'] = $this->safeProbeCount(
            "SELECT COUNT(*) AS count FROM `" . $redirectsTable . "` WHERE disabled = 0"
        );
        $diag['redirects_count']['trashed'] = $this->safeProbeCount(
            "SELECT COUNT(*) AS count FROM `" . $redirectsTable . "` WHERE disabled = 1"
        );
        $diag['logsv2_count'] = $this->safeProbeCount(
            "SELECT COUNT(*) AS count FROM `" . $logsv2Table . "`"
        );
        if ($postsTable !== '') {
            $diag['wp_posts_count'] = $this->safeProbeCount(
                "SELECT COUNT(*) AS count FROM `" . $postsTable . "`"
            );
        }

        $diag['tables'] = $this->safeProbeTableEnginesAndCollations(array($redirectsTable, $logsv2Table));

        $diag['expected_indexes'] = array(
            $redirectsTable => $this->safeProbeIndexCoverage($redirectsTable, array(
                'PRIMARY', 'status', 'type', 'code', 'timestamp', 'disabled', 'url', 'final_dest',
                'idx_url_disabled_status', 'idx_status_disabled', 'idx_canonical_url',
            )),
            $logsv2Table => $this->safeProbeIndexCoverage($logsv2Table, array(
                'PRIMARY', 'timestamp', 'requested_url', 'username', 'min_log_id',
                'idx_requested_url_timestamp', 'idx_canonical_url',
            )),
        );

        $diag['canonical_url_state'] = array(
            $redirectsTable => $this->safeProbeCanonicalUrlState($redirectsTable),
            $logsv2Table => $this->safeProbeCanonicalUrlState($logsv2Table),
        );

        return $diag;
    }

    /**
     * Resolve a human-readable label for the failing query. Mirrors the
     * sql_source extraction from formatViewQueryFailureMessage() so the
     * diagnostic snapshot stays self-explanatory in the debug log.
     *
     * @param string $failedQuery
     * @param string $sub
     * @return string
     */
    private function resolveViewQueryDiagnosticLabel(string $failedQuery, string $sub): string {
        if (preg_match('/\/\*\s*-+\s*(.+?\.sql)\s+BEGIN\s*-+\s*\*\//i', $failedQuery, $m)) {
            return basename($m[1]);
        }
        if (stripos($failedQuery, 'COUNT(*)') !== false) {
            return 'getRedirectsForViewCount';
        }
        return 'getRedirectsForView';
    }

    /**
     * Redact literals from a SQL string for safe inclusion in error responses
     * and the debug log. Keeps table / column / keyword shape so support can
     * pattern-match against the failing query, but strips quoted strings,
     * numbers, and IN(...) value lists.
     *
     * @param string $sql
     * @return string
     */
    private function redactQueryShapeForDiagnostics(string $sql): string {
        if ($sql === '') {
            return '';
        }
        $out = $sql;
        $out = preg_replace("~'(?:\\\\'|''|[^'])*'~", "?", $out) ?? $out;
        $out = preg_replace('~"(?:\\\\"|""|[^"])*"~', "?", $out) ?? $out;
        $out = preg_replace('~\\b0x[0-9A-Fa-f]+\\b~', '?', $out) ?? $out;
        $out = preg_replace('~\\b\\d+(?:\\.\\d+)?\\b~', '?', $out) ?? $out;
        $out = preg_replace('~\\(\\s*\\?\\s*(?:,\\s*\\?\\s*)+\\)~', '(?)', $out) ?? $out;
        $out = preg_replace('~\\s+~', ' ', trim($out)) ?? $out;
        if (strlen($out) > 4000) {
            $out = substr($out, 0, 4000);
        }
        return $out;
    }

    /** @return string */
    private function resolvePostsTableName(): string {
        global $wpdb;
        if (isset($wpdb->posts) && is_string($wpdb->posts) && $wpdb->posts !== '') {
            return $wpdb->posts;
        }
        if (isset($wpdb->prefix) && is_string($wpdb->prefix) && $wpdb->prefix !== '') {
            return $wpdb->prefix . 'posts';
        }
        return '';
    }

    /**
     * Run a `SELECT COUNT(*)` style query with a tight diagnostic timeout
     * and silent error handling. Returns the integer count, or a string error
     * marker if the probe itself failed.
     *
     * @param string $countQuery
     * @return int|string
     */
    private function safeProbeCount(string $countQuery) {
        try {
            $result = $this->dbCore->queryAndGetResults($countQuery, array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $lastErrorRaw = $result['last_error'] ?? '';
            $err = is_string($lastErrorRaw) ? $lastErrorRaw : '';
            if ($err !== '' || !empty($result['timed_out'])) {
                return 'error: ' . ($err !== '' ? $err : 'timed out');
            }
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            if (empty($rows)) {
                return 0;
            }
            $first = is_array($rows[0]) ? $rows[0] : array();
            $value = $first['count'] ?? $first['COUNT(*)'] ?? reset($first);
            return is_scalar($value) ? (int)$value : 0;
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * Run EXPLAIN against the failing query and return the plan rows. Falls
     * back to a string error marker if EXPLAIN itself errors (e.g., the query
     * was a SET STATEMENT wrapper or a stored procedure call).
     *
     * @param string $failedQuery
     * @return array<int, array<string,mixed>>|string
     */
    private function safeProbeExplain(string $failedQuery) {
        if ($failedQuery === '') {
            return 'error: no query supplied';
        }
        $stripped = $this->stripWrappersForExplain($failedQuery);
        try {
            $result = $this->dbCore->queryAndGetResults('EXPLAIN ' . $stripped, array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $lastErrorRaw = $result['last_error'] ?? '';
            $err = is_string($lastErrorRaw) ? $lastErrorRaw : '';
            if ($err !== '' || !empty($result['timed_out'])) {
                return 'error: ' . ($err !== '' ? $err : 'timed out');
            }
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            $clean = array();
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $clean[] = $row;
                } else if (is_object($row)) {
                    $clean[] = (array)$row;
                }
            }
            return $clean;
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * Strip the `SET STATEMENT max_statement_time=N FOR ` / `/*+ MAX_EXECUTION_TIME(...) *\/`
     * wrappers that applyQueryTimeout() prepends so EXPLAIN sees the original
     * SELECT shape. Best effort: if the input does not match, return as-is.
     *
     * @param string $query
     * @return string
     */
    private function stripWrappersForExplain(string $query): string {
        $q = trim($query);
        $q = preg_replace('/^\\s*\\/\\*\\+[^*]*\\*\\/\\s*/', '', $q) ?? $q;
        $q = preg_replace('/^\\s*SET\\s+STATEMENT\\s+max_statement_time\\s*=\\s*\\d+\\s+FOR\\s+/i', '', $q) ?? $q;
        return $q;
    }

    /** @return string */
    private function safeProbeDbVersion(): string {
        try {
            $result = $this->dbCore->queryAndGetResults('SELECT VERSION() AS version', array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            if (empty($rows)) {
                return '';
            }
            $first = is_array($rows[0]) ? $rows[0] : array();
            $value = $first['version'] ?? $first['VERSION()'] ?? reset($first);
            return is_scalar($value) ? (string)$value : '';
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * Probe engine + collation for the requested plugin tables via
     * information_schema.TABLES. Driver-case insensitive (MySQL drivers vary
     * between TABLE_NAME / table_name).
     *
     * @param array<int, string> $tableNames
     * @return array<string, array{engine:string, collation:string}>
     */
    private function safeProbeTableEnginesAndCollations(array $tableNames): array {
        $out = array();
        foreach ($tableNames as $name) {
            $out[$name] = array('engine' => '', 'collation' => '');
        }
        if (empty($tableNames)) {
            return $out;
        }
        try {
            $list = array();
            foreach ($tableNames as $name) {
                $list[] = "'" . str_replace("'", "''", $name) . "'";
            }
            $query = "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . implode(',', $list) . ")";
            $result = $this->dbCore->queryAndGetResults($query, array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = '';
                $engine = '';
                $collation = '';
                foreach ($row as $key => $value) {
                    $k = strtolower((string)$key);
                    if ($k === 'table_name' && is_scalar($value)) {
                        $name = (string)$value;
                    } else if ($k === 'engine' && is_scalar($value)) {
                        $engine = (string)$value;
                    } else if ($k === 'table_collation' && is_scalar($value)) {
                        $collation = (string)$value;
                    }
                }
                if ($name !== '' && array_key_exists($name, $out)) {
                    $out[$name] = array('engine' => $engine, 'collation' => $collation);
                }
            }
        } catch (Throwable $e) {
            foreach (array_keys($out) as $name) {
                if ($out[$name]['engine'] === '') {
                    $out[$name] = array('engine' => 'error: ' . $e->getMessage(), 'collation' => '');
                }
            }
        }
        return $out;
    }

    /**
     * Compare an expected index list against what SHOW INDEX reports for the
     * named table. Returns three lists (expected, present, missing) so the
     * support workflow can spot dropped indexes at a glance.
     *
     * @param string $tableName
     * @param array<int, string> $expectedKeys
     * @return array{expected: array<int,string>, present: array<int,string>, missing: array<int,string>, error?: string}
     */
    private function safeProbeIndexCoverage(string $tableName, array $expectedKeys): array {
        $out = array(
            'expected' => array_values($expectedKeys),
            'present' => array(),
            'missing' => array(),
        );
        try {
            $result = $this->dbCore->queryAndGetResults('SHOW INDEX FROM `' . $tableName . '`', array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $lastErrorRaw = $result['last_error'] ?? '';
            $err = is_string($lastErrorRaw) ? $lastErrorRaw : '';
            if ($err !== '') {
                $out['error'] = $err;
                $out['missing'] = $out['expected'];
                return $out;
            }
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            $present = array();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $key => $value) {
                    if (strtolower((string)$key) === 'key_name' && is_scalar($value)) {
                        $present[(string)$value] = true;
                        break;
                    }
                }
            }
            $out['present'] = array_keys($present);
            $missing = array();
            foreach ($expectedKeys as $expected) {
                if (!array_key_exists($expected, $present)) {
                    $missing[] = $expected;
                }
            }
            $out['missing'] = $missing;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            $out['missing'] = $out['expected'];
        }
        return $out;
    }

    /**
     * Probe canonical_url backfill state for one of the plugin's tables.
     * Returns column existence, NULL count, total row count, and an error
     * marker when the probe itself failed.
     *
     * @param string $tableName
     * @return array{column_exists: bool, null_count: int|string|null, total_count: int|string|null, error?: string}
     */
    private function safeProbeCanonicalUrlState(string $tableName): array {
        $out = array(
            'column_exists' => false,
            'null_count' => null,
            'total_count' => null,
        );
        try {
            $colResult = $this->dbCore->queryAndGetResults('SHOW COLUMNS FROM `' . $tableName . '`', array(
                'timeout' => 5,
                'log_errors' => false,
                'skip_repair' => true,
            ));
            $colRows = is_array($colResult['rows'] ?? null) ? $colResult['rows'] : array();
            foreach ($colRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $key => $value) {
                    if (strtolower((string)$key) === 'field' && is_scalar($value)
                            && strtolower((string)$value) === 'canonical_url') {
                        $out['column_exists'] = true;
                        break 2;
                    }
                }
            }
            if (!$out['column_exists']) {
                return $out;
            }
            $out['null_count'] = $this->safeProbeCount(
                "SELECT COUNT(*) AS count FROM `" . $tableName . "` WHERE canonical_url IS NULL"
            );
            $out['total_count'] = $this->safeProbeCount(
                "SELECT COUNT(*) AS count FROM `" . $tableName . "`"
            );
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    // =========================================================================
    // FROM: DataAccessTrait_ViewQueriesHitsLifecycle.php
    // =========================================================================

    /** @return void */
    function maybeUpdateRedirectsForViewHitsTable(): void {
        // Record that we checked during this request (used for admin tooltip UX).
        $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG, time(), 86400);

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

        if ($this->dbCore->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return;
        }

        // Check if the table exists
        if (!$this->logsRepo->logsHitsTableExists()) {
            // Defer creation to shutdown hook so the admin page loads immediately.
            // The view query gracefully falls back to null hits columns when the
            // table doesn't exist (getRedirectsForViewQuery checks logsHitsTableExists).
            // On sites with large logsv2 tables the INSERT...SELECT that populates
            // the hits table can take minutes, which exceeds proxy timeouts (e.g.
            // Cloudflare's 100-second limit -> HTTP 524).
            $this->logger->debugMessage(__FUNCTION__ . " table doesn't exist, deferring creation to shutdown hook.");
            $this->logsRepo->scheduleHitsTableRebuild();
            return;
        }

        // Diagnostic: track the max_log_id age signal so a stalled rollup
        // surfaces a broken-cron admin notice instead of silently showing
        // stale hit-count columns. Self-heals when the gap closes.
        $this->logsRepo->recordLogsHitsRollupStalenessSignal();

        // Check if rebuild is needed (logs have changed since last build)
        if (!$this->logsRepo->hitsTableNeedsRebuild()) {
            // No new log entries - skip rebuild to reduce server load
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'not_needed', 86400);
            return;
        }

        // Table exists and logs have changed - defer to shutdown hook
        $this->logsRepo->scheduleHitsTableRebuild();
    }

    // =========================================================================
    // FROM: DataAccessTrait_ViewQueriesStagedRead.php
    // =========================================================================

    /**
     * Read page from the served view_done table.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array {
        $query = $this->buildViewDoneReadQuery($sub, $tableOptions);
        $result = $this->dbCore->queryAndGetResults($query, $this->requireViewBuildOrchestrator()->getStagedQueryOptionsForRead());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Build the WHERE/ORDER/LIMIT SELECT against view_done. Mirrors the
     * legacy filter-text composite LIKE so search semantics are preserved,
     * but reads against precomputed columns (no JOINs, no CASE
     * recomputation).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function buildViewDoneReadQuery(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types, $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
        // Match legacy semantics: every tab including HANDLED filters by
        // disabled = 0 (active rows) except the dedicated TRASH tab.
        $trashClause = 'AND disabled = ' . intval($trashValue);

        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $scoreRange = is_string($rawScoreRange) ? $rawScoreRange : 'all';
        $scoreRangeClause = '';
        switch ($scoreRange) {
            case 'high':   $scoreRangeClause = 'AND score >= 80'; break;
            case 'medium': $scoreRangeClause = 'AND score >= 50 AND score < 80'; break;
            case 'low':    $scoreRangeClause = 'AND score IS NOT NULL AND score < 50'; break;
            case 'manual': $scoreRangeClause = 'AND score IS NULL'; break;
        }

        $rawFilterText = $tableOptions['filterText'] ?? '';
        $rawFilterText = is_string($rawFilterText) ? $rawFilterText : '';
        $filterTextClause = '';
        if ($rawFilterText !== '') {
            $sanitized = str_replace(array('*', '/', '$'), '', $rawFilterText);
            if (isset($wpdb) && method_exists($wpdb, 'esc_like')) {
                /** @var \wpdb $wpdb */
                $sanitized = $wpdb->esc_like($sanitized);
            } else {
                $sanitized = addcslashes($sanitized, '_%\\');
            }
            $filterText = esc_sql($sanitized);
            if ($sub === 'abj404_redirects') {
                $filterTextClause = "AND REPLACE(LOWER(CONCAT(url, '////', status_for_view, '////',"
                    . " type_for_view, '////', dest_for_view, '////', code)), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            } else {
                $filterTextClause = "AND REPLACE(LOWER(url), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            }
        }

        $orderBy = $this->resolveOrderByColumn($tableOptions);
        $rawOrderVal = $tableOptions['order'] ?? '';
        $rawOrderValStr = is_string($rawOrderVal) ? $rawOrderVal : '';
        $order = strtoupper((string)preg_replace('/[^a-zA-Z]/', '', trim($rawOrderValStr)));
        if ($order !== 'DESC') { $order = 'ASC'; }

        $rawPaged = $tableOptions['paged'] ?? 1;
        $paged = max(1, is_scalar($rawPaged) ? intval($rawPaged) : 1);
        $rawPerpage = $tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE;
        $perpage = max(1, is_scalar($rawPerpage) ? intval($rawPerpage) : (int)ABJ404_OPTION_DEFAULT_PERPAGE);
        $limitStart = ($paged - 1) * $perpage;

        $done = $this->viewDoneTableName();
        $query = "SELECT id, url, status, status_for_view, type, type_for_view,\n"
            . "       final_dest, dest_for_view, published_status, code, timestamp,\n"
            . "       engine, score, wp_post_id, wp_post_type,\n"
            . "       logshits, logsid, last_used\n"
            . "FROM `" . $done . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause . "\n"
            . "ORDER BY published_status ASC, " . $orderBy . " " . $order . ", url ASC, id " . $order . "\n"
            . "LIMIT " . $limitStart . ", " . $perpage;
        return $query;
    }

    /**
     * COUNT(*) variant of buildViewDoneReadQuery. Same WHERE clauses, no
     * ORDER BY, no LIMIT.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        global $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
        // Match legacy semantics: HANDLED filter shows active rows only
        // (disabled = 0), same as every non-TRASH tab.
        $trashClause = 'AND disabled = ' . intval($trashValue);

        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $scoreRange = is_string($rawScoreRange) ? $rawScoreRange : 'all';
        $scoreRangeClause = '';
        switch ($scoreRange) {
            case 'high':   $scoreRangeClause = 'AND score >= 80'; break;
            case 'medium': $scoreRangeClause = 'AND score >= 50 AND score < 80'; break;
            case 'low':    $scoreRangeClause = 'AND score IS NOT NULL AND score < 50'; break;
            case 'manual': $scoreRangeClause = 'AND score IS NULL'; break;
        }

        $rawFilterText = $tableOptions['filterText'] ?? '';
        $rawFilterText = is_string($rawFilterText) ? $rawFilterText : '';
        $filterTextClause = '';
        if ($rawFilterText !== '') {
            $sanitized = str_replace(array('*', '/', '$'), '', $rawFilterText);
            if (isset($wpdb) && method_exists($wpdb, 'esc_like')) {
                /** @var \wpdb $wpdb */
                $sanitized = $wpdb->esc_like($sanitized);
            } else {
                $sanitized = addcslashes($sanitized, '_%\\');
            }
            $filterText = esc_sql($sanitized);
            if ($sub === 'abj404_redirects') {
                $filterTextClause = "AND REPLACE(LOWER(CONCAT(url, '////', status_for_view, '////',"
                    . " type_for_view, '////', dest_for_view, '////', code)), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            } else {
                $filterTextClause = "AND REPLACE(LOWER(url), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            }
        }

        $done = $this->viewDoneTableName();
        $query = "SELECT COUNT(*) AS cnt\n"
            . "FROM `" . $done . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause;
        return $query;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function resolveStatusTypeList(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types;
        $filter = $tableOptions['filter'] ?? 0;
        $statusTypes = '';
        if ($filter == 0 || $filter == ABJ404_TRASH_FILTER) {
            if ($sub === 'abj404_redirects') {
                $types = array();
                if (is_array($abj404_redirect_types)) {
                    foreach ($abj404_redirect_types as $t) {
                        $types[] = is_scalar($t) ? intval($t) : 0;
                    }
                }
                $statusTypes = implode(', ', $types);
            } else if ($sub === 'abj404_captured') {
                $types = array();
                if (is_array($abj404_captured_types)) {
                    foreach ($abj404_captured_types as $t) {
                        $types[] = is_scalar($t) ? intval($t) : 0;
                    }
                }
                $statusTypes = implode(', ', $types);
            }
        } else if ($filter == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(', ', array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));
        } else if ($filter == ABJ404_HANDLED_FILTER) {
            $statusTypes = implode(', ', array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));
        } else {
            $statusTypes = is_scalar($filter) ? (string)$filter : '';
        }
        $cleaned = preg_replace('/[^\d, ]/', '', $statusTypes);
        return is_string($cleaned) ? $cleaned : '';
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function resolveOrderByColumn(array $tableOptions): string {
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $allowed = array('url', 'status', 'type', 'code', 'score', 'timestamp',
            'logshits', 'last_used', 'final_dest', 'dest', 'id');
        if ($orderBy === 'dest' || $orderBy === 'final_dest') {
            // Same as legacy: treat empty dest as last.
            return "CASE WHEN dest_for_view IS NULL OR dest_for_view = '' THEN 1 ELSE 0 END ASC, dest_for_view";
        }
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'url';
        }
        return $orderBy;
    }

    /**
     * Translations for status_for_view, type_for_view, and the special
     * 404-displayed label. Everything else (`{wp_*}`, `{ABJ404_TYPE_X}`)
     * is handled by doTableNameReplacements + doNormalReplacements.
     *
     * @return array<string, string>
     */
    public function viewBuildOnlyTranslations(): array {
        return array(
            '{ABJ404_STATUS_MANUAL_text}' => __('Manual', '404-solution'),
            '{ABJ404_STATUS_AUTO_text}'   => __('Automatic', '404-solution'),
            '{ABJ404_STATUS_REGEX_text}'  => __('Regex', '404-solution'),
            '{ABJ404_TYPE_EXTERNAL_text}' => __('External', '404-solution'),
            '{ABJ404_TYPE_CAT_text}'      => __('Category', '404-solution'),
            '{ABJ404_TYPE_TAG_text}'      => __('Tag', '404-solution'),
            '{ABJ404_TYPE_HOME_text}'     => __('Home', '404-solution'),
            '{ABJ404_TYPE_404_DISPLAYED_text}' => __('(404 page)', '404-solution'),
            '{ABJ404_TYPE_SPECIAL_text}'  => __('Special', '404-solution'),
        );
    }
}
