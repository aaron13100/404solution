<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewReadServiceTrait_QueryBuilding.php';
require_once __DIR__ . '/ViewReadServiceTrait_CacheManagement.php';
require_once __DIR__ . '/ViewReadServiceTrait_Diagnostics.php';
require_once __DIR__ . '/ViewReadServiceTrait_Invalidation.php';

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

    use ABJ_404_Solution_ViewReadServiceTrait_QueryBuilding,
        ABJ_404_Solution_ViewReadServiceTrait_CacheManagement,
        ABJ_404_Solution_ViewReadServiceTrait_Diagnostics,
        ABJ_404_Solution_ViewReadServiceTrait_Invalidation;

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
            throw new \RuntimeException('ViewReadService requires ViewBuildOrchestrator (call setViewBuildOrchestrator first)'); // allow-raw-error: assertion, should never reach user
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

    // =========================================================================
    // Interface-satisfying read methods and lightweight accessors
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

        if (!$hadError && $cacheKey !== null && function_exists('set_transient')) {
            set_transient($cacheKey, $count, self::LOGS_COUNT_CACHE_TTL_SECONDS);
        }

        return function_exists('apply_filters')
            ? (int) apply_filters('abj404_logs_count', $count, $logID)
            : $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsAll() {
        $query = "select id, url from {wp_abj404_redirects} order by url";

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
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsWithLogs() {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsWithLogs.sql");

        $result = $this->dbCore->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array();
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return $rows;
    }

    /**
     * Get all regex redirects for pattern matching.
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
     * Find MANUAL redirects whose `url` column contains an unambiguous
     * regex metacharacter.
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
            $query = $this->getOptimizedRedirectsForViewCountQuery($sub, $tableOptions);
            $this->setSqlBigSelects();
            $queryOptions = $queryTimeout > 0 ? array('timeout' => $queryTimeout) : array();
            $results = $this->dbCore->queryAndGetResults($query, $queryOptions);
            $lastErrorRaw = $results['last_error'] ?? '';
            $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        } else {
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
     * @param array<int, string> $postIDs
     * @return array<int, mixed>
     */
    function getExtraDataToPermalinkSuggestions(array $postIDs): array {
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
     * @param string $query
     * @param array<string, mixed> $data
     * @return string
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
     * @param string $query
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<int, mixed>}
     */
    function prepare_query($query, $data) {
        $ordered_values = [];
        $prepared_query = preg_replace_callback('/\{(\w+)\}/', function($matches) use ($data, &$ordered_values) {
            $key = $matches[1];
            if (!isset($data[$key])) {
                return $matches[0];
            }
            $value = $data[$key];

            $ordered_values[] = $value;

            $placeholder_type = is_int($value) ? '%d' : '%s';

            return $placeholder_type;
        }, $query);

        return [$prepared_query !== null ? $prepared_query : $query, $ordered_values];
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
       $bytes = intval($size);
       return function_exists('apply_filters')
           ? (int) apply_filters('abj404_log_disk_usage', $bytes)
           : $bytes;
   }

    /**
     * @param array<int, int> $types
     * @param int $trashed
     * @return int
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

    // =========================================================================
    // FROM: DataAccessTrait_ViewQueriesHitsLifecycle.php
    // =========================================================================

    /** @return void */
    function maybeUpdateRedirectsForViewHitsTable(): void {
        // Record that we checked during this request (used for admin tooltip UX).
        $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG, time(), 86400);

        // Piggyback on the captured-404s tab render: also schedule a
        // 15-second logsv2.canonical_url backfill at shutdown if there's
        // legacy NULL-row backlog.
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
            $this->logger->debugMessage(__FUNCTION__ . " table doesn't exist, deferring creation to shutdown hook.");
            $this->logsRepo->scheduleHitsTableRebuild();
            return;
        }

        $this->logsRepo->recordLogsHitsRollupStalenessSignal();

        // Check if rebuild is needed (logs have changed since last build)
        if (!$this->logsRepo->hitsTableNeedsRebuild()) {
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'not_needed', 86400);
            return;
        }

        // Table exists and logs have changed - defer to shutdown hook
        $this->logsRepo->scheduleHitsTableRebuild();
    }
}
