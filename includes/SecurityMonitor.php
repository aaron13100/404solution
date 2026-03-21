<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Security-oriented 404 log analyzer.
 *
 * Detects suspicious patterns in the 404 log that may indicate automated
 * vulnerability scanning or brute-force probing:
 *
 *   - Vulnerability-scanner probes: URLs matching known attack paths
 *     (/wp-login.php, /phpMyAdmin, /.env, /xmlrpc.php, /config.php, …)
 *   - High-volume single-source activity: ≥ 50 genuine 404s from the same
 *     hashed IP within the last 24 hours.
 *   - Sequential path enumeration: ≥ 10 unique 404s sharing the same URL
 *     directory prefix (suggests automated crawling).
 *
 * Results are cached in a WordPress transient (TTL 24 h) and surfaced as a
 * notice on the plugin's Stats page.
 */
class ABJ_404_Solution_SecurityMonitor {

    /** @var object */
    private $dao;

    /** @var object */
    private $logger;

    /** Transient key for cached analysis results */
    const TRANSIENT_KEY = 'abj404_security_monitor_results';

    /** TTL for cached results (24 hours) */
    const TRANSIENT_TTL = 86400;

    /**
     * @param object $dao    ABJ_404_Solution_DataAccess instance
     * @param object $logger ABJ_404_Solution_Logging instance
     */
    public function __construct($dao, $logger) {
        $this->dao    = $dao;
        $this->logger = $logger;
    }

    /**
     * Analyze recent 404 logs for suspicious patterns.
     *
     * @return array<int, array{type: string, severity: string, detail: string, count: int}>
     */
    public function analyzeRecentLogs(): array {
        $threats = array();

        $threats = array_merge($threats, $this->detectVulnerabilityScannerProbes());
        $threats = array_merge($threats, $this->detectHighVolumeSource());
        $threats = array_merge($threats, $this->detectSequentialEnumeration());

        return $threats;
    }

    /**
     * Run analysis and cache the results in a transient for 24 hours.
     * Called nightly by the maintenance cron job.
     *
     * @return void
     */
    public function runNightlyAnalysis(): void {
        $results = $this->analyzeRecentLogs();
        set_transient(self::TRANSIENT_KEY, $results, self::TRANSIENT_TTL);

        $count = count($results);
        if ($count > 0) {
            $this->logger->infoMessage(
                'SecurityMonitor: nightly analysis found ' . $count . ' suspicious pattern(s).'
            );
        } else {
            $this->logger->debugMessage('SecurityMonitor: nightly analysis found no suspicious patterns.');
        }
    }

    /**
     * Return the most recent cached analysis results without re-running.
     *
     * @return array|false Array of threat detections, or false if no cache exists.
     */
    public function getCachedResults() {
        return get_transient(self::TRANSIENT_KEY);
    }

    // -------------------------------------------------------------------------
    // Detection methods
    // -------------------------------------------------------------------------

    /**
     * Detect URLs matching known vulnerability-scanner probe patterns.
     *
     * @return array<int, array{type: string, severity: string, detail: string, count: int}>
     */
    private function detectVulnerabilityScannerProbes(): array {
        $rows = $this->getSuspiciousUrlPatterns();

        if (empty($rows)) {
            return array();
        }

        $count = count($rows);
        return array(
            array(
                'type'     => 'vulnerability_scan',
                'severity' => 'high',
                'detail'   => sprintf(
                    /* translators: %d = number of suspicious 404 hits */
                    _n(
                        '%d suspicious 404 URL matched a known attack probe pattern in the last 24 hours.',
                        '%d suspicious 404 URLs matched known attack probe patterns in the last 24 hours.',
                        $count,
                        '404-solution'
                    ),
                    $count
                ),
                'count'    => $count,
            ),
        );
    }

    /**
     * Detect a single hashed IP generating >= 50 genuine 404s in 24 hours.
     *
     * @return array<int, array{type: string, severity: string, detail: string, count: int}>
     */
    private function detectHighVolumeSource(): array {
        global $wpdb;

        $logsTable = $this->getLogsTableName();
        if ($logsTable === '') {
            return array();
        }

        $cutoff = time() - 86400;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT lookup_id, COUNT(*) AS hit_count
                 FROM `{$logsTable}`
                 WHERE timestamp >= %d
                   AND (dest_url = '' OR dest_url IS NULL)
                   AND lookup_id > 0
                 GROUP BY lookup_id
                 HAVING COUNT(*) >= 50",
                $cutoff
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $threats = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hitCount = isset($row['hit_count']) && is_numeric($row['hit_count']) ? (int)$row['hit_count'] : 0;
            $threats[] = array(
                'type'     => 'high_volume_source',
                'severity' => 'medium',
                'detail'   => sprintf(
                    /* translators: %d = number of 404 hits from a single source */
                    _n(
                        'A single source generated %d genuine 404 in the last 24 hours.',
                        'A single source generated %d genuine 404s in the last 24 hours.',
                        $hitCount,
                        '404-solution'
                    ),
                    $hitCount
                ),
                'count'    => $hitCount,
            );
        }

        return $threats;
    }

    /**
     * Detect sequential path enumeration: >= 10 distinct 404 URLs sharing the
     * same directory prefix within the last 24 hours.
     *
     * @return array<int, array{type: string, severity: string, detail: string, count: int}>
     */
    private function detectSequentialEnumeration(): array {
        global $wpdb;

        $logsTable = $this->getLogsTableName();
        if ($logsTable === '') {
            return array();
        }

        $cutoff = time() - 86400;

        // Extract the directory portion of each 404 URL (everything up to the last slash)
        // and group by prefix. MySQL's SUBSTRING_INDEX gives us the parent path.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT SUBSTRING_INDEX(requested_url, '/', -0) AS path_prefix,
                        CONCAT(SUBSTRING_INDEX(requested_url, '/', 2)) AS dir_prefix,
                        COUNT(DISTINCT requested_url) AS url_count
                 FROM `{$logsTable}`
                 WHERE timestamp >= %d
                   AND (dest_url = '' OR dest_url IS NULL)
                   AND requested_url != ''
                 GROUP BY SUBSTRING_INDEX(requested_url, '/', 2)
                 HAVING COUNT(DISTINCT requested_url) >= 10",
                $cutoff
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $threats = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $urlCount  = isset($row['url_count'])   && is_numeric($row['url_count'])   ? (int)$row['url_count']   : 0;
            $dirPrefix = isset($row['dir_prefix'])  && is_string($row['dir_prefix'])   ? $row['dir_prefix']       : '';
            $threats[] = array(
                'type'     => 'sequential_enumeration',
                'severity' => 'medium',
                'detail'   => sprintf(
                    /* translators: 1: number of URLs, 2: path prefix */
                    __('%1$d distinct 404 URLs under "%2$s" detected — possible path enumeration.', '404-solution'),
                    $urlCount,
                    $dirPrefix
                ),
                'count'    => $urlCount,
            );
        }

        return $threats;
    }

    // -------------------------------------------------------------------------
    // Data-access helpers
    // -------------------------------------------------------------------------

    /**
     * Query the logs table for URLs matching known attack probe patterns in the
     * last 24 hours (genuine 404s only — no dest_url means no redirect occurred).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSuspiciousUrlPatterns(): array {
        global $wpdb;

        $logsTable = $this->getLogsTableName();
        if ($logsTable === '') {
            return array();
        }

        $cutoff = time() - 86400;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT requested_url, COUNT(*) AS hit_count
                 FROM `{$logsTable}`
                 WHERE timestamp >= %d
                   AND (dest_url = '' OR dest_url IS NULL)
                   AND (
                       requested_url LIKE '%wp-login%'
                    OR requested_url LIKE '%phpMyAdmin%'
                    OR requested_url LIKE '%.env%'
                    OR requested_url LIKE '%xmlrpc%'
                    OR requested_url LIKE '%/admin%'
                    OR requested_url LIKE '%config.php%'
                    OR requested_url LIKE '%wp-admin%'
                   )
                 GROUP BY requested_url
                 ORDER BY hit_count DESC",
                $cutoff
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Resolve the fully-prefixed logs table name.
     * Returns an empty string if the table does not exist, so callers can
     * skip the query gracefully rather than generating a SQL error.
     *
     * @return string
     */
    private function getLogsTableName(): string {
        global $wpdb;

        $tableName = $wpdb->prefix . 'abj404_logsv2';
        $exists    = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));

        return ($exists !== null && $exists !== false && $exists !== '') ? $tableName : '';
    }
}
