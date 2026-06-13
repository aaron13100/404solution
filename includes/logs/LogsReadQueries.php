<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-side queries for the logsv2 table feeding the admin Logs page,
 * autocomplete dropdowns, and the daily-activity trend chart.
 *
 * Responsibilities:
 *  - getLogRecords: paginated/sortable admin table read with field allow-listing.
 *  - getLogsIDandURL / getLogsIDandURLLike: dropdown lookups by URL value.
 *  - getDistinctLoggedUrls: distinct URL list for the GSC sitemap probe.
 *  - getDailyActivityTrend: cached daily counts (404 vs redirect) for charting.
 *
 * Extracted from LogsRepository under M201. Consumed by the LogsRepository
 * facade; the rollup-derived MAX(logsv2.id) used to invalidate the trend
 * cache is passed in by the facade so test rollups swapped via
 * TrackingLogsRepository::setRollupForTests() propagate naturally.
 */
class ABJ_404_Solution_LogsReadQueries {

    /** @var int Max age for cached daily-activity trend data. */
    const TREND_DATA_CACHE_TTL_SECONDS = 900;

    /**
     * Default size of the recency window scanned to find distinct logged URLs.
     * The GSC URL probe only needs recent traffic, so we cap rows read from
     * logsv2 before deduplication.
     */
    const DEFAULT_RECENT_LOG_WINDOW = 5000;

    /** Hard ceiling on the recency window so a misbehaving caller cannot exhaust memory. */
    const MAX_RECENT_LOG_WINDOW = 50000;

    /** Default cap on the number of distinct URLs returned. */
    const DEFAULT_DISTINCT_URL_CAP = 500;

    /** Hard ceiling on the distinct URL cap. */
    const MAX_DISTINCT_URL_CAP = 5000;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Functions $f,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f;
        $this->logger = $logger;
    }

    /**
     * Fetch the set of distinct recently-requested URLs from logsv2.
     *
     * Both bounds are caller-supplied so the cap is visible at the
     * repository boundary instead of being hidden inside the SQL file.
     * Values are clamped to [1, MAX_*] to keep this read safe even when
     * callers (or test fixtures) pass garbage.
     *
     * @param int $recentLogWindow Max rows scanned from logsv2 (clamped to [1, MAX_RECENT_LOG_WINDOW]).
     * @param int $distinctUrlCap  Max distinct URLs returned (clamped to [1, MAX_DISTINCT_URL_CAP]).
     * @return array<int, string>
     */
    public function getDistinctLoggedUrls(
        int $recentLogWindow = self::DEFAULT_RECENT_LOG_WINDOW,
        int $distinctUrlCap = self::DEFAULT_DISTINCT_URL_CAP
    ): array {
        $recentLogWindow = max(1, min(self::MAX_RECENT_LOG_WINDOW, $recentLogWindow));
        $distinctUrlCap = max(1, min(self::MAX_DISTINCT_URL_CAP, $distinctUrlCap));
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getDistinctLoggedUrls.sql");
        $query = $this->f->str_replace('{recent_window}', (string)$recentLogWindow, $query);
        $query = $this->f->str_replace('{distinct_cap}', (string)$distinctUrlCap, $query);
        $results = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        $urls = array();
        foreach ($rows as $row) {
            $url = isset($row['requested_url']) && is_string($row['requested_url']) ? $row['requested_url'] : '';
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * @param string $specificURL
     * @return array<int, array<string, mixed>>
     */
    public function getLogsIDandURL($specificURL = '') {
        $whereClause = '';
        if ($specificURL != '') {
            $specificURL = $this->f->sanitizeInvalidUTF8($specificURL);
            $escapedURL = esc_sql($specificURL);
            $whereClause = "where requested_url = '" . $escapedURL . "'";
        }
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getLogsIDandURL.sql");
        $query = $this->f->str_replace('{where_clause_here}', $whereClause, $query);
        $results = $this->dbCore->queryAndGetResults($query);
        return is_array($results['rows']) ? $results['rows'] : array();
    }

    /**
     * @param string $specificURL
     * @param string|int $limitResults
     * @return array<int, array<string, mixed>>
     */
    public function getLogsIDandURLLike($specificURL, $limitResults) {
        global $wpdb;
        $whereClause = '';
        if ($specificURL != '') {
            $likePattern = '%' . $wpdb->esc_like($specificURL) . '%';
            $escapedURL = esc_sql($likePattern);
            $whereClause = "where lower(requested_url) like lower('" . $escapedURL . "')\n";
            $whereClause .= "and min_log_id = true";
        }
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getLogsIDandURLForAjax.sql");
        $query = $this->f->str_replace('{where_clause_here}', $whereClause, $query);
        $query = $this->f->str_replace('{limit-results}', 'limit ' . absint($limitResults), $query);
        $results = $this->dbCore->queryAndGetResults($query);
        return is_array($results['rows']) ? $results['rows'] : array();
    }

    /**
     * Admin logs page read with allow-listed orderby + pagination.
     *
     * @param array<string, mixed> $tableOptions Boundary-normalized callers pass typed
     *                                            log/page values; this method keeps
     *                                            query-specific allowlists and bounds.
     * @return array<int, array<string, mixed>>
     */
    public function getLogRecords($tableOptions) {
        $logsid_included = '';
        $logsid = '';
        $logsIdValue = $this->positiveIntOption($tableOptions, 'logsid', 0);
        if ($logsIdValue > 0) {
            $logsid_included = 'specific logs id included. */';
            $logsid = (string)$logsIdValue;
        }
        $orderbyExpressionByName = array(
            'timestamp'     => '{wp_abj404_logsv2}.timestamp',
            'requested_url' => '{wp_abj404_logsv2}.requested_url',
            'url'           => 'url',
            'id'            => '{wp_abj404_logsv2}.id',
            'min_log_id'    => '{wp_abj404_logsv2}.min_log_id',
        );
        $orderby = $this->stringOption($tableOptions, 'orderby', '');
        $orderby = array_key_exists($orderby, $orderbyExpressionByName) ? $orderby : 'timestamp';
        $orderbyExpression = $orderbyExpressionByName[$orderby];
        $order = strtoupper($this->stringOption($tableOptions, 'order', ''));
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC';
        }
        $paged = $this->positiveIntOption($tableOptions, 'paged', 1);
        $perpage = $this->positiveIntOption($tableOptions, 'perpage', ABJ404_OPTION_DEFAULT_PERPAGE);
        $start = ($paged - 1) * $perpage;
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getLogRecords.sql");
        $query = $this->f->str_replace('{logsid_included}', $logsid_included, $query);
        $query = $this->f->str_replace('{logsid}', $logsid, $query);
        $query = $this->f->str_replace('{orderby}', $orderbyExpression, $query);
        $query = $this->f->str_replace('{order}', $order, $query);
        $query = $this->f->str_replace('{start}', (string)$start, $query);
        $query = $this->f->str_replace('{perpage}', (string)$perpage, $query);
        $results = $this->dbCore->queryAndGetResults($query);
        $rawRows = $results['rows'];
        return is_array($rawRows) ? $rawRows : array();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function positiveIntOption(array $options, string $key, int $default): int {
        $raw = $options[$key] ?? $default;
        if (!is_scalar($raw)) {
            return $default;
        }
        $raw = trim((string)$raw);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return $default;
        }
        $value = intval($raw);
        return $value > 0 ? $value : $default;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $key, string $default): string {
        $raw = $options[$key] ?? $default;
        return is_string($raw) ? $raw : $default;
    }

    /**
     * Daily activity trend for the dashboard chart. Cached for 15 min keyed
     * on (blog id, day count, current MAX logsv2 id) so the cache invalidates
     * naturally as new rows arrive.
     *
     * @param int $days Number of days (clamped to 1-90)
     * @param ABJ_404_Solution_LogsRepositoryInterface $repo Source of the cache-busting MAX(logsv2.id) read; passing the facade (rather than the raw rollup) ensures subclass overrides propagate.
     * @return array<int, array<string, mixed>>
     */
    public function getDailyActivityTrend(int $days, ABJ_404_Solution_LogsRepositoryInterface $repo): array {
        $days = max(1, min(90, $days));
        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = function_exists('absint') ? absint(get_current_blog_id()) : abs(intval(get_current_blog_id()));
            if ($blogId <= 0) { $blogId = 1; }
        }
        $maxLogId = 0;
        try {
            $maxLogId = intval($repo->getMaxLogId());
            if ($maxLogId < 0) { $maxLogId = 0; }
        } catch (Throwable $e) {
            $this->logger->debugMessage(__FUNCTION__ . ' getMaxLogId() failed: ' . $e->getMessage() . '. Falling back to maxLogId=0 (cache key uses 0).');
            $maxLogId = 0;
        }
        $cacheKey = 'abj404_trend_v1_' . $blogId . '_' . $days . '_' . $maxLogId;
        if (function_exists('get_transient')) { $cached = get_transient($cacheKey); if (is_array($cached)) { return $cached; } }
        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');
        $cutoff = abj_clock()->now() - ($days * 86400);
        $notFoundDest = '404';
        $query = "SELECT DATE(FROM_UNIXTIME(`timestamp`)) AS `date`, SUM(CASE WHEN `dest_url` = %s THEN 1 ELSE 0 END) AS `hits_404`, SUM(CASE WHEN `dest_url` <> %s THEN 1 ELSE 0 END) AS `hits_redirect` FROM " . $logsTable . " WHERE `timestamp` >= " . intval($cutoff) . " GROUP BY DATE(FROM_UNIXTIME(`timestamp`)) ORDER BY `date` ASC";
        $result = $this->dbCore->queryAndGetResults($query, array('query_params' => array($notFoundDest, $notFoundDest)));
        $hadError = !empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] !== '');
        $rows = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : array();
        $byDate = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $d = isset($row['date']) ? (string)$row['date'] : '';
            if ($d === '') { continue; }
            $byDate[$d] = array('date' => $d, 'hits_404' => intval($row['hits_404'] ?? 0), 'hits_redirect' => intval($row['hits_redirect'] ?? 0), 'new_captures' => intval($row['hits_404'] ?? 0));
        }
        $output = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', abj_clock()->now() - ($i * 86400));
            $output[] = isset($byDate[$d]) ? $byDate[$d] : array('date' => $d, 'hits_404' => 0, 'hits_redirect' => 0, 'new_captures' => 0);
        }
        if (!$hadError && function_exists('set_transient')) { set_transient($cacheKey, $output, self::TREND_DATA_CACHE_TTL_SECONDS); }
        return $output;
    }
}
