<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logs-table row count and on-disk size reads, with a multisite-aware
 * transient cache around the unfiltered count path.
 *
 * Extracted from ViewReadService in the i805 decomposition. The unfiltered
 * `getLogsCount(0)` path is cached keyed on (blog_id, max_log_id) because the
 * old logsv2 table can be very large and dashboards hit it on every page
 * load; the per-id variant joins by logID and is left uncached (callers ask
 * for a single id, the cache hit ratio is near zero).
 */
class ABJ_404_Solution_LogsMetricsReader {

    /** Cache TTL for the unfiltered getLogsCount(0) total row count. */
    const LOGS_COUNT_CACHE_TTL_SECONDS = 60;

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
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_LogsRepository $logsRepo,
        $f,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->logsRepo = $logsRepo;
        $this->f = $f;
        $this->logger = $logger;
    }

    /**
     * @param int $logID 0 for the unfiltered total (cached); nonzero for a per-id count.
     * @return int
     */
    public function getLogsCount($logID): int {
        $logID = absint($logID);

        $cacheKey = $this->logsCountCacheKey($logID);
        if ($cacheKey !== null) {
            $cached = get_transient($cacheKey);
            if (is_numeric($cached)) {
                return (int)$cached;
            }
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getLogsCount.sql");

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
            $count = is_scalar($value) ? intval($value) : 0;
        }

        if (!$hadError && $cacheKey !== null && function_exists('set_transient')
            && empty($GLOBALS['abj404_feedback_preview_readonly'])) {
            set_transient($cacheKey, $count, self::LOGS_COUNT_CACHE_TTL_SECONDS);
        }

        return function_exists('apply_filters')
            ? (int) apply_filters('abj404_logs_count', $count, $logID)
            : $count;
    }

    /**
     * Returns the on-disk size in bytes of the logsv2 table, or -1 on query
     * failure (so callers can distinguish "table is empty" from "we don't
     * know"). The `abj404_log_disk_usage` filter lets tests stub the value.
     *
     * @return int
     */
    public function getLogDiskUsage(): int {
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
     * Cache key for the unfiltered getLogsCount(0) total. Returns null when
     * caching is not applicable (per-id path, or transient API unavailable).
     *
     * @param int $logID
     * @return string|null
     */
    private function logsCountCacheKey(int $logID): ?string {
        if ($logID !== 0 || !function_exists('get_transient')) {
            return null;
        }
        return 'abj404_logs_count_v1_' . $this->currentBlogIdForCache() . '_' . $this->maxLogIdForCache();
    }

    /** @return int */
    private function currentBlogIdForCache(): int {
        if (!function_exists('get_current_blog_id')) {
            return 1;
        }
        $rawBlogId = function_exists('absint')
            ? absint(get_current_blog_id())
            : abs(intval(get_current_blog_id()));
        return $rawBlogId > 0 ? $rawBlogId : 1;
    }

    /** @return int */
    private function maxLogIdForCache(): int {
        try {
            return max(0, intval($this->logsRepo->getMaxLogId()));
        } catch (Throwable $e) {
            $this->logger->debugMessage(__FUNCTION__ . ' getMaxLogId() failed: '
                . $e->getMessage() . '. Falling back to maxLogId=0.');
            return 0;
        }
    }
}
