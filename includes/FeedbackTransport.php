<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTTP-first transport for plugin feedback reports.
 *
 * Replaces direct wp_mail() sends with a POST to a central reports endpoint,
 * keeping wp_mail() only as a last-resort fallback when HTTP fails. The class
 * has two modes:
 *
 *   queue($payload, $type): interactive paths (deactivate AJAX). Stores
 *     the payload in a transient and schedules a single-shot cron event so
 *     the user's click is never blocked on the network.
 *
 *   sendNow($payload, $type): already-async paths (nightly cron).
 *     Synchronously POSTs the payload, falls back to wp_mail() on non-2xx
 *     or WP_Error.
 *
 *   handleQueuedSend($uuid): cron handler. Loads transient, calls
 *     sendNow(), deletes transient regardless of outcome.
 *
 *   buildPayload($type, $extra): centralised payload assembly. All required
 *     server fields (plugin_version, db_type, site_id, is_uninstall, etc.)
 *     are derived here.
 *
 * Callers are wired in subsequent tasks (c347/c348). This class is dormant
 * until then.
 */
class ABJ_404_Solution_FeedbackTransport {

    const TRANSIENT_PREFIX = 'abj404_pending_report_';
    const TRANSIENT_TTL = 86400; // 24 hours
    const CRON_HOOK = 'abj404_send_queued_report';
    const HTTP_TIMEOUT = 10;

    /**
     * Queue a payload for asynchronous send. Used by interactive paths
     * (deactivate AJAX). Returns immediately; the actual send happens in a
     * single-shot cron event.
     *
     * @param array<string, mixed> $payload
     * @param string $type
     * @return void
     */
    public static function queue(array $payload, string $type): void {
        $uuid = self::generateUuid();
        $envelope = array(
            'payload' => $payload,
            'type' => $type,
        );
        set_transient(self::TRANSIENT_PREFIX . $uuid, $envelope, self::TRANSIENT_TTL);
        wp_schedule_single_event(time(), self::CRON_HOOK, array($uuid));
    }

    /**
     * Synchronously POST and fall back to wp_mail() on failure. Used by paths
     * already in cron context (nightly maintenance).
     *
     * @param array<string, mixed> $payload
     * @param string $type
     * @return bool true if any transport (HTTP or email) succeeded.
     */
    public static function sendNow(array $payload, string $type): bool {
        $started = microtime(true);
        $result = self::httpSend($payload);
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        $statusStr = isset($result['status']) && is_scalar($result['status']) ? (string)$result['status'] : '';
        $reasonStr = isset($result['reason']) && is_scalar($result['reason']) ? (string)$result['reason'] : '';
        $detailStr = isset($result['detail']) && is_scalar($result['detail']) ? (string)$result['detail'] : '';

        if (!empty($result['ok'])) {
            self::log('info', sprintf(
                'abj404_transport: type=%s http_status=%s fallback_used=false ms_elapsed=%d',
                $type,
                $statusStr !== '' ? $statusStr : 'ok',
                $elapsedMs
            ));
            return true;
        }

        $statusLabel = $statusStr !== '' ? $statusStr : ($reasonStr !== '' ? $reasonStr : 'unknown');
        self::log('warn', sprintf(
            'abj404_transport: type=%s http_status=%s fallback_used=true ms_elapsed=%d detail=%s',
            $type,
            $statusLabel,
            $elapsedMs,
            $detailStr
        ));

        return self::emailFallback($payload, $type);
    }

    /**
     * Cron handler for queued sends. Loads payload from transient, calls
     * sendNow(), deletes transient regardless of outcome (24h TTL still
     * cleans up if anything throws before the delete).
     *
     * @param string $uuid
     * @return void
     */
    public static function handleQueuedSend(string $uuid): void {
        $key = self::TRANSIENT_PREFIX . $uuid;
        $envelope = get_transient($key);
        if (!is_array($envelope) || !isset($envelope['payload']) || !is_array($envelope['payload'])) {
            // Transient expired or never written; nothing to do.
            delete_transient($key);
            return;
        }
        /** @var array<string, mixed> $payload */
        $payload = $envelope['payload'];
        $type = isset($envelope['type']) && is_string($envelope['type']) ? $envelope['type'] : 'unknown';

        try {
            self::sendNow($payload, $type);
        } catch (\Throwable $e) {
            // sendNow() must be defensive, but if anything escapes we still
            // log and let the transient be cleared so cron doesn't loop on it.
            self::log('warn', 'abj404_transport: sendNow threw: ' . $e->getMessage());
        }

        delete_transient($key);
    }

    /**
     * Build a payload from current site state. $extra carries type-specific
     * fields (uninstall_reason, debug_log, error_signature, etc.).
     *
     * @param string $type One of 'error', 'heartbeat', 'uninstall'.
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function buildPayload(string $type, array $extra = array()): array {
        global $wpdb;

        $dbVersion = '';
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'db_version')) {
            $raw = $wpdb->db_version();
            $dbVersion = is_scalar($raw) ? (string)$raw : '';
        }
        // db_version() typically returns the numeric portion only; for
        // mariadb detection we also probe the full VERSION() string.
        $fullVersion = $dbVersion;
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            // DAO-bypass-approved: SELECT VERSION() is a parameterless server-introspection probe with no plugin tables involved; routing through queryAndGetResults() would force a missing-table-repair detour for a query that cannot fail with that error class
            $probed = $wpdb->get_var('SELECT VERSION()');
            if (is_string($probed) && $probed !== '') {
                $fullVersion = $probed;
            }
        }
        $dbType = (stripos($fullVersion, 'mariadb') !== false) ? 'mariadb' : 'mysql';

        $tablePrefix = '';
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix) && is_string($wpdb->prefix)) {
            $tablePrefix = $wpdb->prefix;
        }

        $payload = array(
            'plugin_version' => defined('ABJ404_VERSION') ? ABJ404_VERSION : '',
            'db_type' => $dbType,
            'db_version' => $fullVersion,
            'wp_version' => function_exists('get_bloginfo') ? (string)get_bloginfo('version') : '',
            'php_version' => PHP_VERSION,
            'is_multisite' => function_exists('is_multisite') ? (bool)is_multisite() : false,
            'is_uninstall' => ($type === 'uninstall'),
            'report_type' => $type,
            'site_url' => function_exists('home_url') ? (string)home_url() : '',
            'locale' => function_exists('get_locale') ? (string)get_locale() : '',
            'resource_limits' => self::resourceLimits(),
            'wp_memory_limit_bytes' => self::tryInt(function () { return self::memoryLimitBytes(); }),
            'extensions' => function_exists('get_loaded_extensions') ? get_loaded_extensions() : array(),
            'active_plugins' => self::activePlugins(),
            'active_theme' => self::activeTheme(),
            'object_cache' => function_exists('wp_using_ext_object_cache') ? (bool)wp_using_ext_object_cache() : false,
            'table_prefix' => $tablePrefix,
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) && is_scalar($_SERVER['SERVER_SOFTWARE']) ? (string)$_SERVER['SERVER_SOFTWARE'] : '',
        );

        // Null (not 0) on lookup failure so the server can distinguish
        // "actually zero" from "we don't know" per server-schema contract.
        $payload['published_posts_count'] = self::tryInt(function () { return self::countPublishedPosts(); });
        $payload['published_pages_count'] = self::tryInt(function () { return self::countPublishedPages(); });
        $payload['categories_count']      = self::tryInt(function () { return self::countCategories(); });
        $payload['tags_count']            = self::tryInt(function () { return self::countTags(); });

        // Server schema flattens the DAO's ['all','manual','auto','regex','trash']
        // map. 'redirects_active_total' is the DAO 'all' (manual+auto+regex).
        $redirectCounts = self::tryArray(function () { return self::redirectCountsRaw(); });
        $payload['redirects_active_total']    = self::pluckInt($redirectCounts, 'all');
        $payload['redirects_manual_count']    = self::pluckInt($redirectCounts, 'manual');
        $payload['redirects_automatic_count'] = self::pluckInt($redirectCounts, 'auto');
        $payload['redirects_regex_count']     = self::pluckInt($redirectCounts, 'regex');
        $payload['redirects_trashed_count']   = self::pluckInt($redirectCounts, 'trash');

        // DAO key 'captured' is the "new" status; server renames it.
        $capturedCounts = self::tryArray(function () { return self::capturedCountsRaw(); });
        $payload['captured_404s_active_total']  = self::pluckInt($capturedCounts, 'all');
        $payload['captured_404s_new_count']     = self::pluckInt($capturedCounts, 'captured');
        $payload['captured_404s_ignored_count'] = self::pluckInt($capturedCounts, 'ignored');
        $payload['captured_404s_later_count']   = self::pluckInt($capturedCounts, 'later');
        $payload['captured_404s_trashed_count'] = self::pluckInt($capturedCounts, 'trash');

        $payload['log_entries_count']     = self::tryInt(function () { return self::logEntriesCount(); });
        $payload['log_table_size_bytes']  = self::tryInt(function () { return self::logTableSizeBytes(); });
        $payload['error_count_in_log']    = self::tryInt(function () { return self::errorCountInLog(); });
        $payload['debug_file_size_bytes'] = self::tryInt(function () { return self::debugFileSizeBytes(); });

        if (self::isDevelopmentEnvironment()) {
            $payload['environment_type'] = 'development';
        }

        // Type-specific extras override base fields where appropriate
        // (uninstall adds uninstall_reason / contact_email, error adds
        // error_signature / debug_log).
        foreach ($extra as $k => $v) {
            $payload[(string)$k] = $v;
        }

        return $payload;
    }

    /**
     * HTTP transport. Returns ['ok' => bool, 'status' => int|null,
     * 'reason' => string|null, 'detail' => string|null].
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function httpSend(array $payload): array {
        $endpoint = self::resolveEndpoint();
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        if (!is_string($json) || $json === '') {
            return array('ok' => false, 'reason' => 'json_encode_failed');
        }

        $body = function_exists('gzencode') ? gzencode($json, 6) : false;
        if ($body === false) {
            return array('ok' => false, 'reason' => 'gzencode_failed');
        }

        $response = wp_remote_post($endpoint, array(
            'timeout' => self::HTTP_TIMEOUT,
            'redirection' => 0,
            'blocking' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Content-Encoding' => 'gzip',
            ),
            'body' => $body,
        ));

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            // is_wp_error() narrowing guarantees get_error_message() exists
            // on both real WP_Error and the test stub.
            $raw = $response->get_error_message();
            $msg = is_scalar($raw) ? (string)$raw : '';
            return array('ok' => false, 'reason' => 'wp_error', 'detail' => $msg);
        }

        $code = function_exists('wp_remote_retrieve_response_code') ? (int)wp_remote_retrieve_response_code($response) : 0;
        if ($code >= 200 && $code < 300) {
            return array('ok' => true, 'status' => $code);
        }
        return array('ok' => false, 'reason' => 'http_' . $code, 'status' => $code);
    }

    /**
     * Resolve the endpoint URL, allowing override via the
     * 'abj404_report_endpoint' filter. Falls back to a string default if
     * the constant is not defined yet (e.g. before Loader.php boots).
     *
     * @return string
     */
    private static function resolveEndpoint(): string {
        $default = defined('ABJ404_REPORT_ENDPOINT')
            ? ABJ404_REPORT_ENDPOINT
            : 'https://404solution.ajexperience.com/api/v1/reports';
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_report_endpoint', $default);
            if (is_string($filtered) && $filtered !== '') {
                return $filtered;
            }
        }
        return $default;
    }

    /**
     * Last-resort wp_mail() fallback. Routes to the type-specific email body
     * builder so HTTP and email transports share a single source of truth:
     *   - 'uninstall' delegates to UninstallModal::sendFeedbackEmail($payload)
     *   - 'error' / 'heartbeat' delegates to Logging::emailLogFileToDeveloper($payload)
     *     (attaches a zip of the current debug log and renders the same HTML
     *     body the email transport used pre-migration).
     *
     * If the type-specific delegate is unavailable (class not loaded, service
     * container empty), falls back to a generic JSON dump so the data is at
     * least preserved somewhere.
     *
     * @param array<string, mixed> $payload
     * @param string $type
     * @return bool
     */
    private static function emailFallback(array $payload, string $type): bool {
        if (!function_exists('wp_mail')) {
            return false;
        }
        if ($type === 'uninstall' && class_exists('ABJ_404_Solution_UninstallModal')) {
            return ABJ_404_Solution_UninstallModal::sendFeedbackEmail($payload);
        }
        if (($type === 'error' || $type === 'heartbeat') && function_exists('abj_service')) {
            try {
                $logger = abj_service('logging');
                if (is_object($logger) && method_exists($logger, 'emailLogFileToDeveloper')) {
                    return (bool) $logger->emailLogFileToDeveloper($payload);
                }
            } catch (\Throwable $e) {
                @error_log('404 Solution: FeedbackTransport email-fallback delegate (' . $type . ') failed: ' . $e->getMessage());
            }
        }
        $to = defined('ABJ404_AUTHOR_EMAIL') ? ABJ404_AUTHOR_EMAIL : '404solution@ajexperience.com';
        $version = defined('ABJ404_VERSION') ? ABJ404_VERSION : '';
        $subject = sprintf('[404 Solution] %s report (HTTP fallback) v%s', $type, $version);
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_PRETTY_PRINT) : json_encode($payload, JSON_PRETTY_PRINT);
        $body = is_string($json) ? $json : '';
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $result = wp_mail($to, $subject, $body, $headers);
        return (bool)$result;
    }

    /**
     * Detect a development host so the server can filter local traffic out
     * of production reports.
     *
     * @return bool
     */
    private static function isDevelopmentEnvironment(): bool {
        $home = function_exists('home_url') ? (string)home_url() : '';
        $host = '';
        if (function_exists('wp_parse_url')) {
            $parsed = wp_parse_url($home, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : '';
        } else {
            $parsed = parse_url($home, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : '';
        }
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost') {
            return true;
        }
        if (preg_match('/\.(test|local|dev|localhost)$/i', $host) === 1) {
            return true;
        }
        // home_url() returning host:port: wp_parse_url strips the port, so
        // dev-only ports (8888 MAMP, etc.) are caught by the WP_DEBUG plus
        // localhost check on the raw home_url() string below.
        if (defined('WP_DEBUG') && WP_DEBUG && strpos((string)$home, 'localhost') !== false) {
            return true;
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resourceLimits(): array {
        return array(
            'php_memory' => function_exists('ini_get') ? (string)ini_get('memory_limit') : '',
            'wp_memory' => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : '',
            'max_execution_time' => function_exists('ini_get') ? (string)ini_get('max_execution_time') : '',
            'post_max_size' => function_exists('ini_get') ? (string)ini_get('post_max_size') : '',
            'upload_max_filesize' => function_exists('ini_get') ? (string)ini_get('upload_max_filesize') : '',
        );
    }

    /**
     * Resolve the WordPress memory limit constant to bytes. Mirrors the
     * existing email transport, which exposes this as a string; servers
     * receive a stable integer so they can sort or compare across sites
     * without re-parsing "256M" / "512M".
     *
     * @return int Byte count, or 0 when undefined.
     */
    private static function memoryLimitBytes(): int {
        if (!defined('WP_MEMORY_LIMIT')) {
            return 0;
        }
        $str = trim((string)WP_MEMORY_LIMIT);
        if ($str === '' || $str === '-1') {
            return 0;
        }
        $unit = strtolower(substr($str, -1));
        $num = (int) $str;
        switch ($unit) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default:  return $num;
        }
    }

    /**
     * Call an int-returning helper, returning null if it throws. Lets
     * buildPayload() assemble a partial report when one count source fails.
     *
     * @param callable $fn
     * @return int|null
     */
    private static function tryInt(callable $fn): ?int {
        try {
            $v = $fn();
            return is_int($v) ? $v : null;
        } catch (\Throwable $e) {
            @error_log('404 Solution: FeedbackTransport count lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Call an array-returning helper, returning [] if it throws.
     *
     * @param callable $fn
     * @return array<string, int>
     */
    private static function tryArray(callable $fn): array {
        try {
            $v = $fn();
            if (!is_array($v)) {
                return array();
            }
            /** @var array<string, int> $coerced */
            $coerced = array();
            foreach ($v as $k => $val) {
                if (is_string($k) && is_int($val)) {
                    $coerced[$k] = $val;
                }
            }
            return $coerced;
        } catch (\Throwable $e) {
            @error_log('404 Solution: FeedbackTransport array lookup failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Pull a single int from a count map, returning null when the key is
     * absent. Distinguishes "DAO returned an empty array" (failure, null)
     * from "DAO returned 0 for this status" (real zero) in the payload.
     *
     * @param array<string, mixed> $map
     * @param string $key
     * @return int|null
     */
    private static function pluckInt(array $map, string $key): ?int {
        if (!array_key_exists($key, $map)) {
            return null;
        }
        $v = $map[$key];
        return is_scalar($v) ? (int)$v : null;
    }

    /**
     * @return int wp_count_posts('post')->publish.
     */
    private static function countPublishedPosts(): int {
        if (!function_exists('wp_count_posts')) {
            throw new \RuntimeException('wp_count_posts unavailable');
        }
        $posts = wp_count_posts();
        if (is_object($posts) && isset($posts->publish) && is_scalar($posts->publish)) {
            return (int)$posts->publish;
        }
        throw new \RuntimeException('wp_count_posts returned unexpected shape');
    }

    /**
     * @return int wp_count_posts('page')->publish.
     */
    private static function countPublishedPages(): int {
        if (!function_exists('wp_count_posts')) {
            throw new \RuntimeException('wp_count_posts unavailable');
        }
        $pages = wp_count_posts('page');
        if (is_object($pages) && isset($pages->publish) && is_scalar($pages->publish)) {
            return (int)$pages->publish;
        }
        throw new \RuntimeException('wp_count_posts(page) returned unexpected shape');
    }

    /**
     * @return int wp_count_terms('category').
     */
    private static function countCategories(): int {
        if (!function_exists('wp_count_terms')) {
            throw new \RuntimeException('wp_count_terms unavailable');
        }
        $v = wp_count_terms(array('taxonomy' => 'category'));
        if (function_exists('is_wp_error') && is_wp_error($v)) {
            throw new \RuntimeException('wp_count_terms(category) returned WP_Error');
        }
        if (is_scalar($v)) {
            return (int)$v;
        }
        throw new \RuntimeException('wp_count_terms(category) returned unexpected shape');
    }

    /**
     * @return int wp_count_terms('post_tag').
     */
    private static function countTags(): int {
        if (!function_exists('wp_count_terms')) {
            throw new \RuntimeException('wp_count_terms unavailable');
        }
        $v = wp_count_terms(array('taxonomy' => 'post_tag'));
        if (function_exists('is_wp_error') && is_wp_error($v)) {
            throw new \RuntimeException('wp_count_terms(post_tag) returned WP_Error');
        }
        if (is_scalar($v)) {
            return (int)$v;
        }
        throw new \RuntimeException('wp_count_terms(post_tag) returned unexpected shape');
    }

    /**
     * Raw redirect-status counts straight from DataAccess. Throws when the
     * DAO isn't booted or the method is missing; tryArray() catches.
     *
     * @return array<string, int>
     */
    private static function redirectCountsRaw(): array {
        $dao = self::dao();
        if ($dao === null || !method_exists($dao, 'getRedirectStatusCounts')) {
            throw new \RuntimeException('DataAccess::getRedirectStatusCounts unavailable');
        }
        $raw = $dao->getRedirectStatusCounts(true);
        if (!is_array($raw)) {
            throw new \RuntimeException('getRedirectStatusCounts returned non-array');
        }
        $out = array();
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $out[$k] = (int)$v;
            }
        }
        return $out;
    }

    /**
     * Raw captured-404 status counts straight from DataAccess.
     *
     * @return array<string, int>
     */
    private static function capturedCountsRaw(): array {
        $dao = self::dao();
        if ($dao === null || !method_exists($dao, 'getCapturedStatusCounts')) {
            throw new \RuntimeException('DataAccess::getCapturedStatusCounts unavailable');
        }
        $raw = $dao->getCapturedStatusCounts(true);
        if (!is_array($raw)) {
            throw new \RuntimeException('getCapturedStatusCounts returned non-array');
        }
        $out = array();
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $out[$k] = (int)$v;
            }
        }
        return $out;
    }

    /**
     * @return int Row count of {prefix}abj404_logsv2.
     */
    private static function logEntriesCount(): int {
        $dao = self::dao();
        if ($dao === null || !method_exists($dao, 'getLogsCount')) {
            throw new \RuntimeException('DataAccess::getLogsCount unavailable');
        }
        $v = $dao->getLogsCount(0);
        if (is_scalar($v)) {
            return (int)$v;
        }
        throw new \RuntimeException('getLogsCount returned unexpected shape');
    }

    /**
     * @return int data_length + index_length for the log table.
     */
    private static function logTableSizeBytes(): int {
        $dao = self::dao();
        if ($dao === null || !method_exists($dao, 'getLogDiskUsage')) {
            throw new \RuntimeException('DataAccess::getLogDiskUsage unavailable');
        }
        $v = $dao->getLogDiskUsage();
        if (is_scalar($v)) {
            return (int)$v;
        }
        throw new \RuntimeException('getLogDiskUsage returned unexpected shape');
    }

    /**
     * @return int Total (ERROR) line count in the debug file.
     */
    private static function errorCountInLog(): int {
        if (!function_exists('abj_service')) {
            throw new \RuntimeException('abj_service unavailable');
        }
        $logger = abj_service('logging');
        if (!is_object($logger) || !method_exists($logger, 'getLatestErrorLine')) {
            throw new \RuntimeException('Logging::getLatestErrorLine unavailable');
        }
        $info = $logger->getLatestErrorLine();
        if (is_array($info) && isset($info['total_error_count']) && is_scalar($info['total_error_count'])) {
            return (int)$info['total_error_count'];
        }
        throw new \RuntimeException('getLatestErrorLine returned unexpected shape');
    }

    /**
     * @return int filesize() of the plugin debug log.
     */
    private static function debugFileSizeBytes(): int {
        if (!function_exists('abj_service')) {
            throw new \RuntimeException('abj_service unavailable');
        }
        $logger = abj_service('logging');
        if (!is_object($logger) || !method_exists($logger, 'getDebugFilePath')) {
            throw new \RuntimeException('Logging::getDebugFilePath unavailable');
        }
        $path = $logger->getDebugFilePath();
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            // Missing file is a real zero, not a failure.
            return 0;
        }
        $fs = @filesize($path);
        if (is_int($fs)) {
            return $fs;
        }
        throw new \RuntimeException('filesize() failed');
    }

    /**
     * Service-container lookup for DataAccess, returning null instead of
     * throwing when the container isn't initialized yet (test contexts,
     * early boot before Loader.php runs).
     *
     * @return object|null
     */
    private static function dao(): ?object {
        if (!function_exists('abj_service')) {
            return null;
        }
        // allow-silent-catch: container may not be initialized in early-boot or test contexts; null return signals callers to use zero defaults, no diagnostic info exists yet to log
        try {
            $svc = abj_service('data_access');
            return is_object($svc) ? $svc : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private static function activePlugins(): array {
        if (!function_exists('get_option')) {
            return array();
        }
        $list = get_option('active_plugins', array());
        if (!is_array($list)) {
            return array();
        }
        $out = array();
        foreach ($list as $entry) {
            if (is_string($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * @return array{name: string, version: string}
     */
    private static function activeTheme(): array {
        if (!function_exists('wp_get_theme')) {
            return array('name' => '', 'version' => '');
        }
        $theme = wp_get_theme();
        if (!is_object($theme)) {
            return array('name' => '', 'version' => '');
        }
        $name = '';
        $version = '';
        if (method_exists($theme, 'get')) {
            $rawName = $theme->get('Name');
            $rawVer = $theme->get('Version');
            if (is_string($rawName)) { $name = $rawName; }
            if (is_string($rawVer)) { $version = $rawVer; }
        }
        return array('name' => $name, 'version' => $version);
    }

    /**
     * Generate a random UUID v4 string. Uses random_bytes() (with a
     * wp_generate_password fallback) so the per-queue token is unguessable.
     *
     * @return string
     */
    private static function generateUuid(): string {
        try {
            $data = random_bytes(16);
        // allow-silent-catch: random_bytes only throws when CSPRNG unavailable; fallback to wp_generate_password / mt_rand still produces a valid transient key
        } catch (\Throwable $e) {
            $data = '';
            if (function_exists('wp_generate_password')) {
                $data = (string)wp_generate_password(16, true, true);
                $data = substr($data . str_repeat("\0", 16), 0, 16);
            }
            if ($data === '' || strlen($data) < 16) {
                $data = str_pad((string)mt_rand(), 16, "\0");
                $data = substr($data, 0, 16);
            }
        }
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Internal logging shim. Routes through the plugin's Logging service if
     * available, falls back to error_log() so messages aren't lost in
     * standalone tests / early-boot contexts.
     *
     * @param string $level 'info' | 'warn' | 'error'
     * @param string $message
     * @return void
     */
    private static function log(string $level, string $message): void {
        if (function_exists('abj_service')) {
            try {
                $logger = abj_service('logging');
                if (is_object($logger)) {
                    if ($level === 'info' && method_exists($logger, 'infoMessage')) {
                        $logger->infoMessage($message);
                        return;
                    }
                    if ($level === 'warn' && method_exists($logger, 'warn')) {
                        $logger->warn($message);
                        return;
                    }
                    if ($level === 'error' && method_exists($logger, 'errorMessage')) {
                        $logger->errorMessage($message);
                        return;
                    }
                }
            } catch (\Throwable $e) {
                @error_log('404 Solution: FeedbackTransport logger lookup failed (' . $e->getMessage() . '); falling back to error_log');
            }
        }
        @error_log('404 Solution: ' . $message);
    }
}
