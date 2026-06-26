<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads aggregate statistics used by admin dashboards and periodic summaries.
 */
class ABJ_404_Solution_StatsReadRepository {

    /** @var int Max age for cached stats-periodic aggregates. */
    const PERIODIC_STATS_CACHE_TTL_SECONDS = 300;
    /** @var int Minimum interval before recalculating expensive stats aggregates. */
    const PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS = 30;

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;
    /** @var ABJ_404_Solution_LogsRepositoryInterface */
    private $logsRepo;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_StatsRefreshLock */
    private $refreshLock;

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param ABJ_404_Solution_LogsRepositoryInterface $logsRepo
     * @param ABJ_404_Solution_Logging $logging
     * @param ABJ_404_Solution_StatsRefreshLock $refreshLock
     */
    public function __construct(
        ABJ_404_Solution_DatabaseQueryInterface $dbCore,
        ABJ_404_Solution_LogsRepositoryInterface $logsRepo,
        $logging,
        ABJ_404_Solution_StatsRefreshLock $refreshLock
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->logger = $logging;
        $this->refreshLock = $refreshLock;
    }

    /**
     * @param string $query
     * @param array<int|string, mixed> $valueParams
     * @return int
     */
    public function getStatsCount($query, array $valueParams) {
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
        return $this->toInt($value, 0);
    }

    /**
     * @param int $sinceTimestamp
     * @param string $notFoundDest
     * @return array{disp404:int,distinct404:int,visitors404:int,refer404:int,redirected:int,distinctredirected:int,distinctvisitors:int,distinctrefer:int}
     */
    public function getPeriodicStatsSummary($sinceTimestamp, $notFoundDest = '404') {
        $sinceTimestamp = absint($sinceTimestamp);
        $notFoundDest = sanitize_text_field((string)$notFoundDest);
        if ($notFoundDest === '') {
            $notFoundDest = '404';
        }

        $zero = $this->zeroPeriodicStats();

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
            $zero[$key] = array_key_exists($key, $row) ? $this->toInt($row[$key], 0) : 0;
        }

        return $zero;
    }

    /**
     * @param string $notFoundDest
     * @return array{today:array<string,int>,month:array<string,int>,year:array<string,int>,all:array<string,int>}
     */
    public function getPeriodicStatsSummariesCached($notFoundDest = '404') {
        $now = abj_clock()->now();
        $today = mktime(0, 0, 0, abs(intval(date('m', $now))), abs(intval(date('d', $now))), abs(intval(date('Y', $now))));
        $firstm = mktime(0, 0, 0, abs(intval(date('m', $now))), 1, abs(intval(date('Y', $now))));
        $firsty = mktime(0, 0, 0, 1, 1, abs(intval(date('Y', $now))));

        $thresholds = array(
            'today' => intval($today),
            'month' => intval($firstm),
            'year' => intval($firsty),
            'all' => 0,
        );

        $emptyPayload = $this->emptyPeriodicPayload();
        $cacheKey = 'abj404_stats_periodic_v1_' . $this->currentBlogId() . '_' . md5(
            $notFoundDest . '|' . $thresholds['today'] . '|' . $thresholds['month'] . '|' . $thresholds['year']
        );
        $cached = function_exists('get_transient') ? get_transient($cacheKey) : null;

        $isCachedValid = (is_array($cached) && isset($cached['periods']) && is_array($cached['periods']));
        $cachedPeriods = $isCachedValid ? $this->normalizePeriodicPayload($cached['periods']) : $emptyPayload;
        $currentMaxLogId = -1;
        try {
            $currentMaxLogId = $this->toInt($this->logsRepo->getMaxLogId(), -1);
        } catch (Throwable $unused) { // allow-silent-catch: cache-key derivation; -1 means "no cached entry, recompute" which is the correct degraded behavior
            $currentMaxLogId = -1;
        }

        if ($isCachedValid) {
            $refreshedAt = $this->toInt($cached['refreshed_at'] ?? 0, 0);
            $ageSeconds = max(0, abj_clock()->now() - $refreshedAt);
            $cachedMaxLogId = $this->toInt($cached['max_log_id'] ?? -1, -1);
            if ($currentMaxLogId >= 0 && $cachedMaxLogId === $currentMaxLogId) {
                return $cachedPeriods;
            }
            if ($ageSeconds < self::PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS) {
                return $cachedPeriods;
            }
        }

        $lockKey = 'stats-periodic:' . $cacheKey;
        $lockAcquired = $this->refreshLock->acquire($lockKey);
        if (!$lockAcquired && $isCachedValid) {
            return $cachedPeriods;
        }

        try {
            $result = array(
                'today' => $this->getPeriodicStatsSummary($thresholds['today'], $notFoundDest),
                'month' => $this->getPeriodicStatsSummary($thresholds['month'], $notFoundDest),
                'year' => $this->getPeriodicStatsSummary($thresholds['year'], $notFoundDest),
                'all' => $this->getPeriodicStatsSummary($thresholds['all'], $notFoundDest),
            );

            if (function_exists('set_transient')) {
                set_transient(
                    $cacheKey,
                    array(
                        'refreshed_at' => abj_clock()->now(),
                        'max_log_id' => $currentMaxLogId,
                        'periods' => $result,
                    ),
                    self::PERIODIC_STATS_CACHE_TTL_SECONDS
                );
            }

            return $result;
        } finally {
            if ($lockAcquired) {
                $this->refreshLock->release($lockKey);
            }
        }
    }

    /** @return int */
    public function getEarliestLogTimestamp() {
        // allow-unbounded-select: MIN(timestamp) aggregate; returns a single row
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
        return $this->toInt($value, -1);
    }

    /**
     * Count redirect rows grouped into match-confidence bands for the stats
     * page Match Confidence card.
     *
     * A NULL score is the "manual" band (no automated scoring took place);
     * scored rows fall into high/medium/low per
     * {@see ABJ_404_Solution_ScoreThresholds}. Disabled rows and rows with
     * status 0 are excluded. Routed through queryAndGetResults() so the
     * 5x SUM(CASE...) aggregate inherits the centralized 60s SELECT timeout
     * (the redirects table can be very large on busy sites).
     *
     * This is the single owner of the confidence-band SQL and thresholds:
     * the view layer asks for the counts and only formats them.
     *
     * @return array{high:int,medium:int,low:int,manual:int,avg:float|null,total:int}|null
     *   Band counts plus the rounded average score (avg is null when no scored
     *   rows exist), or null when the query timed out, errored, or returned no
     *   aggregate row so the caller can skip rendering the card.
     */
    public function getConfidenceBandCounts() {
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');

        $high = ABJ_404_Solution_ScoreThresholds::HIGH;
        $medium = ABJ_404_Solution_ScoreThresholds::MEDIUM;
        $sql = "SELECT
               SUM(CASE WHEN score IS NULL THEN 1 ELSE 0 END) AS manual_count,
               SUM(CASE WHEN score >= {$high} THEN 1 ELSE 0 END) AS high_count,
               SUM(CASE WHEN score >= {$medium} AND score < {$high} THEN 1 ELSE 0 END) AS medium_count,
               SUM(CASE WHEN score IS NOT NULL AND score < {$medium} THEN 1 ELSE 0 END) AS low_count,
               AVG(score) AS avg_score
             FROM `{$redirectsTable}`
             WHERE disabled = %d AND status != %d";

        $result = $this->dbCore->queryAndGetResults($sql, array('query_params' => array(0, 0)));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return null;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0] ?? null)) {
            return null;
        }
        $row = $rows[0];

        $highCount   = $this->toInt($row['high_count']   ?? 0, 0);
        $mediumCount = $this->toInt($row['medium_count'] ?? 0, 0);
        $lowCount    = $this->toInt($row['low_count']    ?? 0, 0);
        $manualCount = $this->toInt($row['manual_count'] ?? 0, 0);
        $avgRaw = $row['avg_score'] ?? null;
        $avgScore = is_numeric($avgRaw) ? round((float)$avgRaw, 1) : null;

        return array(
            'high'   => $highCount,
            'medium' => $mediumCount,
            'low'    => $lowCount,
            'manual' => $manualCount,
            'avg'    => $avgScore,
            'total'  => $highCount + $mediumCount + $lowCount + $manualCount,
        );
    }

    /** @return array<string, mixed> */
    public function buildStatsDashboardSnapshotData() {
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

        $now = abj_clock()->now();
        $thresholds = array(
            'today' => (int)mktime(0, 0, 0, abs(intval(date('m', $now))), abs(intval(date('d', $now))), abs(intval(date('Y', $now)))),
            'month' => (int)mktime(0, 0, 0, abs(intval(date('m', $now))), 1, abs(intval(date('Y', $now)))),
            'year' => (int)mktime(0, 0, 0, 1, 1, abs(intval(date('Y', $now)))),
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

    /** @return array{disp404:int,distinct404:int,visitors404:int,refer404:int,redirected:int,distinctredirected:int,distinctvisitors:int,distinctrefer:int} */
    private function zeroPeriodicStats(): array {
        return array(
            'disp404' => 0,
            'distinct404' => 0,
            'visitors404' => 0,
            'refer404' => 0,
            'redirected' => 0,
            'distinctredirected' => 0,
            'distinctvisitors' => 0,
            'distinctrefer' => 0,
        );
    }

    /** @return array{today:array<string,int>,month:array<string,int>,year:array<string,int>,all:array<string,int>} */
    private function emptyPeriodicPayload(): array {
        $zero = $this->zeroPeriodicStats();
        return array(
            'today' => $zero,
            'month' => $zero,
            'year' => $zero,
            'all' => $zero,
        );
    }

    /**
     * @param mixed $periods
     * @return array{today:array<string,int>,month:array<string,int>,year:array<string,int>,all:array<string,int>}
     */
    private function normalizePeriodicPayload($periods): array {
        $payload = $this->emptyPeriodicPayload();
        if (!is_array($periods)) {
            return $payload;
        }
        foreach (array('today', 'month', 'year', 'all') as $periodKey) {
            if (isset($periods[$periodKey]) && is_array($periods[$periodKey])) {
                $payload[$periodKey] = $this->normalizePeriodicStats($periods[$periodKey]);
            }
        }
        return $payload;
    }

    /**
     * @param array<int|string, mixed> $stats
     * @return array{disp404:int,distinct404:int,visitors404:int,refer404:int,redirected:int,distinctredirected:int,distinctvisitors:int,distinctrefer:int}
     */
    private function normalizePeriodicStats(array $stats): array {
        $zero = $this->zeroPeriodicStats();
        foreach ($zero as $key => $unused) {
            $zero[$key] = array_key_exists($key, $stats) ? $this->toInt($stats[$key], 0) : 0;
        }
        return $zero;
    }

    /** @return int */
    private function currentBlogId(): int {
        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = absint(get_current_blog_id());
            if ($blogId <= 0) {
                $blogId = 1;
            }
        }
        return $blogId;
    }

    /** @param mixed $value @param int $default @return int */
    private function toInt($value, int $default): int {
        if ($value === null) {
            return $default;
        }
        if (is_scalar($value)) {
            return intval($value);
        }
        return $default;
    }
}
