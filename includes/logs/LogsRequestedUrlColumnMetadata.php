<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves and caches metadata for logsv2.requested_url so log writes can
 * adapt to legacy charsets and engine-specific collation reporting.
 */
class ABJ_404_Solution_LogsRequestedUrlColumnMetadata {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Look up (and cache for a week) the character set and collation of the
     * logsv2.requested_url column.
     *
     * @param string $logTableName
     * @param mixed $wpdb
     * @return array{charset_name: ?string, collation_name: ?string}|null
     */
    public function resolveRequestedUrlColumnMeta(string $logTableName, $wpdb): ?array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cachedMeta = $this->readCachedColumnMeta();
        if ($cachedMeta !== null) {
            $cached = $cachedMeta;
            return $cached;
        }

        $dbName = defined('DB_NAME') ? (string)DB_NAME : '';
        if ($dbName === '' || !is_object($wpdb)
                || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return null;
        }

        $meta = $this->queryRequestedUrlColumnMeta($logTableName, $dbName, $wpdb);
        if ($meta === null) {
            return null;
        }
        $this->cacheColumnMeta($meta);
        $cached = $meta;
        return $meta;
    }

    /**
     * @return array{charset_name: ?string, collation_name: ?string}|null
     */
    private function readCachedColumnMeta(): ?array {
        if (!function_exists('get_transient')) {
            return null;
        }
        $existing = get_transient('abj404_logs_requested_url_column_meta');
        if (is_array($existing)) {
            return array(
                'charset_name' => is_string($existing['charset_name'] ?? null) ? $existing['charset_name'] : null,
                'collation_name' => is_string($existing['collation_name'] ?? null) ? $existing['collation_name'] : null,
            );
        }
        $legacyCharset = get_transient('abj404_logs_requested_url_charset');
        if (is_string($legacyCharset) && $legacyCharset !== '') {
            return array('charset_name' => $legacyCharset, 'collation_name' => null);
        }
        return null;
    }

    /**
     * @return array{charset_name: ?string, collation_name: ?string}|null
     */
    private function queryRequestedUrlColumnMeta(string $logTableName, string $dbName, object $wpdb): ?array {
        if (!method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return null;
        }
        // DAO-bypass-approved: metadata probe needs wpdb prepare/get_results against information_schema and reads no plugin data.
        $getCharsetQuery = $wpdb->prepare("SELECT character_set_name as charset_name, collation_name as collation_name \n FROM information_schema.columns \n WHERE lower(table_schema) = lower(%s) \n AND lower(table_name) = lower(%s) \n AND lower(column_name) = lower(%s) ", $dbName, $logTableName, 'requested_url');
        // DAO-bypass-approved: paired information_schema metadata read for requested_url charset/collation probe.
        $resultArray = $wpdb->get_results($getCharsetQuery, ARRAY_A);
        if (empty($resultArray)) {
            return null;
        }
        $firstRow = is_array($resultArray[0]) ? $resultArray[0] : array();
        $row = array_change_key_case($firstRow, CASE_LOWER);
        $meta = array(
            'charset_name' => is_string($row['charset_name'] ?? null) ? $row['charset_name'] : null,
            'collation_name' => is_string($row['collation_name'] ?? null) ? $row['collation_name'] : null,
        );
        return $meta;
    }

    /**
     * @param array{charset_name: ?string, collation_name: ?string} $meta
     */
    private function cacheColumnMeta(array $meta): void {
        if (!function_exists('set_transient')) {
            return;
        }
        // @cache-write-audit: opt-out - schema metadata probe cache, not user-query result data.
        // allow-cache-empty: $meta is built from a non-empty $resultArray in queryRequestedUrlColumnMeta(), so charset_name / collation_name are populated DB metadata, not a failed-fetch sentinel.
        $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
        set_transient('abj404_logs_requested_url_column_meta', $meta, $ttl);
        if (!empty($meta['charset_name'])) {
            set_transient('abj404_logs_requested_url_charset', $meta['charset_name'], $ttl);
        }
    }

    /** Emit a one-shot warning the first time a non-utf8 logs charset is observed. */
    public function warnLogsCharsetMismatchOnce(string $logTableName, string $charset): void {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return;
        }
        $warnKey = 'abj404_warned_logs_charset_mismatch';
        $warnVal = $logTableName . '|' . strtolower($charset);
        $already = get_transient($warnKey);
        if ($already === $warnVal) {
            return;
        }
        $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
        // @cache-write-audit: opt-out - charset warning dedup marker, not query result data.
        set_transient($warnKey, $warnVal, $ttl);
        $this->logger->warn("Logs table column charset is '{$charset}' for {$logTableName}. URL-encoding stored requested URLs to avoid charset issues.");
    }
}
