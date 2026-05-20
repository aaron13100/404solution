<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/StatsRepositoryInterface.php';

/**
 * Stats aggregation, dashboard snapshots, and digest data.
 *
 * Extracted from the DataAccess monolith (Phase 4 of the DataAccess refactor).
 * Methods originate from DataAccessTrait_Stats after Phases 1 and 2 relocated
 * redirect and permalink methods to their respective repositories.
 *
 * Receives DatabaseCore for query execution and LogsRepository for hits-table
 * lifecycle checks.
 */
class ABJ_404_Solution_StatsRepository implements ABJ_404_Solution_StatsRepositoryInterface {

    /** @var int Max age for cached stats-periodic aggregates. */
    const PERIODIC_STATS_CACHE_TTL_SECONDS = 300;
    /** @var int Minimum interval before recalculating expensive stats aggregates. */
    const PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS = 30;
    /** @var int Retention for dashboard stats snapshot payload. */
    const STATS_DASHBOARD_CACHE_TTL_SECONDS = 86400;
    /** @var int Minimum time between full stats snapshot recomputes. */
    const STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS = 30;
    /** @var int Cooldown for distributed refresh locks. */
    const REFRESH_LOCK_COOLDOWN_SECONDS = 30;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_LogsRepository $logsRepo,
        $functions = null,
        $logging = null
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    // =========================================================================
    // Core stats queries
    // =========================================================================

    /** @inheritDoc */
    function getStatsCount($query, array $valueParams) {
        if ($query == '') {
            return 0;
        }

        $result = $this->dbCore->queryAndGetResults($query, array('query_params' => $valueParams));

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return 0;
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            $this->logger->debugMessage("getStatsCount returned no results for query: " . esc_html($query));
            return 0;
        }

        $first = $rows[0];
        if (is_array($first)) {
            $value = reset($first);
        } else {
            $value = $first;
        }
        return intval($value);
    }

    /** @inheritDoc */
    function getPeriodicStatsSummary($sinceTimestamp, $notFoundDest = '404') {
        $sinceTimestamp = absint($sinceTimestamp);
        $notFoundDest = sanitize_text_field((string)$notFoundDest);
        if ($notFoundDest === '') {
            $notFoundDest = '404';
        }

        $zero = array(
            'disp404' => 0,
            'distinct404' => 0,
            'visitors404' => 0,
            'refer404' => 0,
            'redirected' => 0,
            'distinctredirected' => 0,
            'distinctvisitors' => 0,
            'distinctrefer' => 0,
        );

        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $sql = "SELECT
                COUNT(CASE WHEN dest_url = %s THEN 1 END) AS disp404,
                COUNT(DISTINCT CASE WHEN dest_url = %s THEN requested_url END) AS distinct404,
                COUNT(DISTINCT CASE WHEN dest_url = %s THEN user_ip END) AS visitors404,
                COUNT(DISTINCT CASE WHEN dest_url = %s THEN referrer END) AS refer404,
                COUNT(CASE WHEN dest_url <> %s THEN 1 END) AS redirected,
                COUNT(DISTINCT CASE WHEN dest_url <> %s THEN requested_url END) AS distinctredirected,
                COUNT(DISTINCT CASE WHEN dest_url <> %s THEN user_ip END) AS distinctvisitors,
                COUNT(DISTINCT CASE WHEN dest_url <> %s THEN referrer END) AS distinctrefer
            FROM {$logsTable}
            WHERE timestamp >= %d";

        $result = $this->dbCore->queryAndGetResults($sql, array(
            'query_params' => array(
                $notFoundDest, $notFoundDest, $notFoundDest, $notFoundDest,
                $notFoundDest, $notFoundDest, $notFoundDest, $notFoundDest,
                $sinceTimestamp,
            ),
        ));

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return $zero;
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0] ?? null)) {
            return $zero;
        }
        $row = $rows[0];

        foreach ($zero as $key => $unused) {
            $zero[$key] = isset($row[$key]) ? intval($row[$key]) : 0;
        }

        return $zero;
    }

    /** @inheritDoc */
    function getPeriodicStatsSummariesCached($notFoundDest = '404') {
        $today = mktime(0, 0, 0, abs(intval(date('m'))), abs(intval(date('d'))), abs(intval(date('Y'))));
        $firstm = mktime(0, 0, 0, abs(intval(date('m'))), 1, abs(intval(date('Y'))));
        $firsty = mktime(0, 0, 0, 1, 1, abs(intval(date('Y'))));

        $thresholds = array(
            'today' => intval($today),
            'month' => intval($firstm),
            'year' => intval($firsty),
            'all' => 0,
        );

        $zero = array(
            'disp404' => 0,
            'distinct404' => 0,
            'visitors404' => 0,
            'refer404' => 0,
            'redirected' => 0,
            'distinctredirected' => 0,
            'distinctvisitors' => 0,
            'distinctrefer' => 0,
        );
        $emptyPayload = array(
            'today' => $zero,
            'month' => $zero,
            'year' => $zero,
            'all' => $zero,
        );

        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = absint(get_current_blog_id());
            if ($blogId <= 0) {
                $blogId = 1;
            }
        }

        $cacheKey = 'abj404_stats_periodic_v1_' . $blogId . '_' . md5(
            $notFoundDest . '|' . $thresholds['today'] . '|' . $thresholds['month'] . '|' . $thresholds['year']
        );
        $cached = null;
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
        }

        $isCachedValid = (is_array($cached) && isset($cached['periods']) && is_array($cached['periods']));
        $currentMaxLogId = -1;
        try {
            $currentMaxLogId = intval($this->logsRepo->getMaxLogId());
        } catch (Throwable $unused) { // allow-silent-catch: cache-key derivation; -1 means "no cached entry, recompute" which is the correct degraded behavior
            $currentMaxLogId = -1;
        }

        if ($isCachedValid) {
            $refreshedAt = intval($cached['refreshed_at'] ?? 0);
            $ageSeconds = max(0, time() - $refreshedAt);
            $cachedMaxLogId = intval($cached['max_log_id'] ?? -1);
            if ($currentMaxLogId >= 0 && $cachedMaxLogId === $currentMaxLogId) {
                /** @var array{today: array<string, int>, month: array<string, int>, year: array<string, int>, all: array<string, int>} */
                $merged = array_merge($emptyPayload, $cached['periods']);
                return $merged;
            }
            if ($ageSeconds < self::PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS) {
                /** @var array{today: array<string, int>, month: array<string, int>, year: array<string, int>, all: array<string, int>} */
                $merged = array_merge($emptyPayload, $cached['periods']);
                return $merged;
            }
        }

        $lockKey = 'stats-periodic:' . $cacheKey;
        $lockAcquired = $this->acquireRefreshLock($lockKey);
        if (!$lockAcquired && $isCachedValid) {
            /** @var array{today: array<string, int>, month: array<string, int>, year: array<string, int>, all: array<string, int>} */
            $merged = array_merge($emptyPayload, $cached['periods']);
            return $merged;
        }

        try {
            $periods = array();
            foreach ($thresholds as $key => $ts) {
                $periods[$key] = $this->getPeriodicStatsSummary($ts, $notFoundDest);
            }
            /** @var array{today: array<string, int>, month: array<string, int>, year: array<string, int>, all: array<string, int>} */
            $result = array_merge($emptyPayload, $periods);

            if (function_exists('set_transient')) {
                set_transient(
                    $cacheKey,
                    array(
                        'refreshed_at' => time(),
                        'max_log_id' => $currentMaxLogId,
                        'periods' => $result,
                    ),
                    self::PERIODIC_STATS_CACHE_TTL_SECONDS
                );
            }

            return $result;
        } finally {
            if ($lockAcquired) {
                $this->releaseRefreshLock($lockKey);
            }
        }
    }

    // =========================================================================
    // Dashboard snapshot
    // =========================================================================

    /** @inheritDoc */
    function getStatsDashboardSnapshot($allowStale = true) {
        $cached = $this->getStatsDashboardSnapshotFromCache();
        if (is_array($cached) && !empty($cached['data']) && $allowStale) {
            /** @var array{refreshed_at: int, hash: string, data: array<string, mixed>} $cached */
            return $cached;
        }

        return $this->refreshStatsDashboardSnapshot(false);
    }

    /** @inheritDoc */
    function refreshStatsDashboardSnapshot($force = false) {
        $cached = $this->getStatsDashboardSnapshotFromCache();
        $hasCachedData = (is_array($cached) && !empty($cached['data']));
        $cachedAge = $hasCachedData ? max(0, time() - (is_scalar($cached['refreshed_at'] ?? 0) ? intval($cached['refreshed_at'] ?? 0) : 0)) : PHP_INT_MAX;

        if (!$force && $hasCachedData && $cachedAge < self::STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS) {
            /** @var array{refreshed_at: int, hash: string, data: array<string, mixed>} $cached */
            return $cached;
        }

        $lockKey = 'stats-dashboard:' . $this->getStatsDashboardSnapshotCacheKey();
        $lockAcquired = $this->acquireRefreshLock($lockKey);
        if (!$lockAcquired && $hasCachedData) {
            /** @var array{refreshed_at: int, hash: string, data: array<string, mixed>} $cached */
            return $cached;
        }

        try {
            $data = $this->buildStatsDashboardSnapshotData();
            $payload = array(
                'refreshed_at' => time(),
                'hash' => $this->hashStatsDashboardSnapshot($data),
                'data' => $data,
            );
            if (function_exists('set_transient')) {
                set_transient($this->getStatsDashboardSnapshotCacheKey(), $payload, self::STATS_DASHBOARD_CACHE_TTL_SECONDS);
            }
            return $payload;
        } catch (Throwable $e) {
            if ($hasCachedData) {
                $this->logger->debugMessage(__FUNCTION__ . ' failed to recompute stats snapshot; returning cached snapshot. Error: ' . $e->getMessage());
                /** @var array{refreshed_at: int, hash: string, data: array<string, mixed>} $cached */
                return $cached;
            }
            throw $e;
        } finally {
            if ($lockAcquired) {
                $this->releaseRefreshLock($lockKey);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function getStatsDashboardSnapshotFromCache() {
        if (!function_exists('get_transient')) {
            return null;
        }
        $cached = get_transient($this->getStatsDashboardSnapshotCacheKey());
        if (!is_array($cached)) {
            return null;
        }
        if (!array_key_exists('data', $cached) || !is_array($cached['data'])) {
            return null;
        }
        $cached['refreshed_at'] = intval($cached['refreshed_at'] ?? 0);
        $cached['hash'] = is_string($cached['hash'] ?? null) ? $cached['hash'] : '';
        return $cached;
    }

    /** @return string */
    private function getStatsDashboardSnapshotCacheKey(): string {
        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = absint(get_current_blog_id());
            if ($blogId <= 0) {
                $blogId = 1;
            }
        }
        return 'abj404_stats_dashboard_snapshot_v1_' . $blogId;
    }

    /**
     * @param array<string, mixed> $data
     * @return string
     */
    private function hashStatsDashboardSnapshot($data) {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        if (!is_string($encoded)) {
            $encoded = '';
        }
        return md5($encoded);
    }

    /** @return array<string, mixed> */
    private function buildStatsDashboardSnapshotData() {
        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");

        $auto301 = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and code = 301 and status = %d",
            array(ABJ404_STATUS_AUTO)
        );
        $auto302 = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and code = 302 and status = %d",
            array(ABJ404_STATUS_AUTO)
        );
        $manual301 = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and code = 301 and status = %d",
            array(ABJ404_STATUS_MANUAL)
        );
        $manual302 = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and code = 302 and status = %d",
            array(ABJ404_STATUS_MANUAL)
        );
        $trashedRedirects = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 1 and (status = %d or status = %d)",
            array(ABJ404_STATUS_AUTO, ABJ404_STATUS_MANUAL)
        );

        $captured = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and status = %d",
            array(ABJ404_STATUS_CAPTURED)
        );
        $ignored = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and status in (%d, %d)",
            array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER)
        );
        $trashedCaptured = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 1 and (status in (%d, %d, %d) )",
            array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER)
        );

        $thresholds = array(
            'today' => (int)mktime(0, 0, 0, abs(intval(date('m'))), abs(intval(date('d'))), abs(intval(date('Y')))),
            'month' => (int)mktime(0, 0, 0, abs(intval(date('m'))), 1, abs(intval(date('Y')))),
            'year' => (int)mktime(0, 0, 0, 1, 1, abs(intval(date('Y')))),
            'all' => 0,
        );
        $periods = array();
        foreach ($thresholds as $periodKey => $ts) {
            $periods[$periodKey] = $this->getPeriodicStatsSummary($ts, '404');
        }

        return array(
            'redirects' => array(
                'auto301' => intval($auto301),
                'auto302' => intval($auto302),
                'manual301' => intval($manual301),
                'manual302' => intval($manual302),
                'trashed' => intval($trashedRedirects),
            ),
            'captured' => array(
                'captured' => intval($captured),
                'ignored' => intval($ignored),
                'trashed' => intval($trashedCaptured),
            ),
            'periods' => $periods,
        );
    }

    // =========================================================================
    // Log timestamp
    // =========================================================================

    /** @inheritDoc */
    function getEarliestLogTimestamp() {
        $query = 'SELECT min(timestamp) as timestamp FROM {wp_abj404_logsv2}';

        $result = $this->dbCore->queryAndGetResults($query);

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return -1;
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return -1;
        }

        $first = $rows[0];
        $value = is_array($first) ? reset($first) : $first;
        if ($value === null || $value === false || $value === '') {
            return -1;
        }
        return intval($value);
    }

    // =========================================================================
    // Email digest
    // =========================================================================

    /** @inheritDoc */
    function getTopCapturedForDigest(int $limit): array {
        $limit = max(1, $limit);

        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logger->warn('getTopCapturedForDigest: logs_hits rollup unavailable; '
                . 'digest top-captured table will be empty until rebuild completes. '
                . 'EmailDigest pre-checks via logsHitsTableExists() to render an "unavailable" message instead.');
            $this->logsRepo->scheduleHitsTableRebuild();
            return array();
        }

        $query = $this->buildTopCapturedForDigestQuery($limit);
        $result = $this->dbCore->queryAndGetResults($query, array('timeout' => 60));

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            $errRaw = $result['last_error'] ?? '';
            $errMsg = is_string($errRaw) ? $errRaw : '';
            $timedOut = !empty($result['timed_out']);
            $this->logger->warn('getTopCapturedForDigest: query failed against present rollup; '
                . 'digest top-captured table will be empty. timed_out=' . ($timedOut ? '1' : '0')
                . ', error=' . ($errMsg !== '' ? $errMsg : '(none)'));
            return array();
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return $rows;
    }

    /** @inheritDoc */
    function buildTopCapturedForDigestQuery(int $limit): string {
        $limit = max(1, $limit);
        $query = "SELECT r.url, COALESCE(h.logshits, 0) AS logshits, r.timestamp AS created
            FROM {wp_abj404_redirects} r
            LEFT JOIN {wp_abj404_logs_hits} h
                ON BINARY h.requested_url = BINARY
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . " AND r.disabled = 0
            ORDER BY logshits DESC, r.url ASC
            LIMIT " . $limit;
        return $this->dbCore->doTableNameReplacements($query);
    }

    /** @inheritDoc */
    function getDigestSummaryStats(): array {
        $zero = array(
            'total_captured' => 0,
            'total_manual' => 0,
            'total_auto' => 0,
        );

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');

        try {
            $total_captured = $this->getStatsCount(
                "SELECT COUNT(id) FROM {$redirectsTable} WHERE status = %d AND disabled = 0",
                array(ABJ404_STATUS_CAPTURED)
            );
            $total_manual = $this->getStatsCount(
                "SELECT COUNT(id) FROM {$redirectsTable} WHERE status = %d AND disabled = 0",
                array(ABJ404_STATUS_MANUAL)
            );
            $total_auto = $this->getStatsCount(
                "SELECT COUNT(id) FROM {$redirectsTable} WHERE status = %d AND disabled = 0",
                array(ABJ404_STATUS_AUTO)
            );
        } catch (Throwable $e) {
            $this->logger->warn(
                'getRedirectsBreakdownStats failed; returning zero counts: '
                . $e->getMessage()
            );
            return $zero;
        }

        return array(
            'total_captured' => intval($total_captured),
            'total_manual' => intval($total_manual),
            'total_auto' => intval($total_auto),
        );
    }

    /** @inheritDoc */
    function getCapturedCountForNotification(): int {
        $viewRead = abj_service('view_read_service');
        return $viewRead->getRecordCount(array(ABJ404_STATUS_CAPTURED));
    }

    // =========================================================================
    // Content keywords (permalink cache)
    // =========================================================================

    /** @inheritDoc */
    function getPostsNeedingContentKeywords(int $limit = 500): array {
        $limitResults = " */\n  limit " . absint($limit);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPostsNeedingContentKeywords.sql");
        $query = $this->f->str_replace('{limit-results}', $limitResults, $query);

        $result = $this->dbCore->queryAndGetResults($query, array(
            'result_type' => OBJECT,
            'log_errors' => false,
        ));

        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            if (stripos($lastError, 'unknown column') !== false) {
                $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $lastError);
            } else if (!$this->dbCore->classifyAndHandleInfrastructureError($lastError)) {
                $this->logger->errorMessage("Error fetching posts for content keywords: " . $lastError);
            }
            return array();
        }

        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        return $rows;
    }

    /** @inheritDoc */
    function bulkUpdateContentKeywords(array $idToKeywords): void {
        if (empty($idToKeywords)) {
            return;
        }

        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_permalink_cache}');

        $whenClauses = array();
        $params = array();
        $ids = array();
        foreach ($idToKeywords as $id => $keywords) {
            $intId = (int) $id;
            $whenClauses[] = 'WHEN %d THEN %s';
            $params[] = $intId;
            $params[] = $keywords;
            $ids[] = $intId;
        }

        $idPlaceholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = "UPDATE `{$table}` SET content_keywords = CASE id\n        "
            . implode("\n        ", $whenClauses)
            . "\n        END\n        WHERE id IN ({$idPlaceholders})";

        $allParams = array_merge($params, $ids);

        $result = $this->dbCore->queryAndGetResults($sql, array('query_params' => $allParams));

        $lastErrorRaw = $result['last_error'] ?? '';
        $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        if ($lastError !== '') {
            if (stripos($lastError, 'unknown column') !== false) {
                $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $lastError);
            }
        }
    }

    // =========================================================================
    // Distributed refresh locks (self-contained, same pattern as ViewSnapshotCache)
    // =========================================================================

    /** @param string $cacheKey @return bool */
    private function acquireRefreshLock(string $cacheKey): bool {
        if (!function_exists('add_option')) {
            return true;
        }
        if ($this->isRefreshLocked($cacheKey)) {
            return false;
        }
        $lockKey = $this->getRefreshLockOptionName($cacheKey);
        return (bool)add_option($lockKey, time(), '', false);
    }

    /** @param string $cacheKey @return void */
    private function releaseRefreshLock(string $cacheKey): void {
        if (function_exists('delete_option')) {
            delete_option($this->getRefreshLockOptionName($cacheKey));
        }
    }

    /** @param string $cacheKey @return bool */
    private function isRefreshLocked(string $cacheKey): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $lockKey = $this->getRefreshLockOptionName($cacheKey);
        $lockValue = get_option($lockKey, false);
        if ($lockValue === false || $lockValue === '' || $lockValue === null) {
            return false;
        }
        $lockTs = is_numeric($lockValue) ? (int)$lockValue : 0;
        if ($lockTs > 0 && (time() - $lockTs) > self::REFRESH_LOCK_COOLDOWN_SECONDS) {
            delete_option($lockKey);
            return false;
        }
        return true;
    }

    /** @param string $cacheKey @return string */
    private function getRefreshLockOptionName(string $cacheKey): string {
        return $this->dbCore->getLowercasePrefix() . 'abj404_view_cache_lock_' . md5((string)$cacheKey);
    }
}
