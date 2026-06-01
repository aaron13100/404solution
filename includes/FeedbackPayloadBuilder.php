<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/FeedbackDatabaseIdentity.php';
require_once dirname(__FILE__) . '/FeedbackEnvironmentExtras.php';
require_once dirname(__FILE__) . '/FeedbackPayloadSchemaGuard.php';

/**
 * Assembles feedback report payloads from current site state. Owns all the
 * diagnostic data collection: post/page/category/tag counts, redirect and
 * captured-404 status counts, log table size, debug log tail, loaded PHP
 * extensions, active plugins/theme, resource limits.
 *
 *   build()         - full payload for HTTP/email transports
 *   buildMinimal()  - schema-conforming payload with site-identifying fields
 *                     blanked (uninstall flow when user opts out of details)
 *   generateUuid()  - random v4 token for queueing
 */
class ABJ_404_Solution_FeedbackPayloadBuilder {

    const DEBUG_LOG_MAX_BYTES = 262144;

    /**
     * Build a payload from current site state. $extra carries type-specific
     * fields (uninstall_reason, debug_log, error_signature, etc.).
     *
     * @param string $type One of 'error', 'heartbeat', 'uninstall', 'support_request'.
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function build(string $type, array $extra = array()): array {
        global $wpdb;

        $databaseIdentity = ABJ_404_Solution_FeedbackDatabaseIdentity::detect(isset($wpdb) ? $wpdb : null);
        $dbType = $databaseIdentity['type'];
        $fullVersion = $databaseIdentity['version'];

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
            'extensions' => self::loadedExtensionsMap(),
            'active_plugins' => self::activePlugins(),
            'active_theme' => self::activeTheme(),
            // Server schema declares object_cache as string. "external" when
            // a drop-in is installed (W3 Total Cache, Redis Object Cache),
            // "default" when WordPress is using its in-process cache.
            'object_cache' => (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) ? 'external' : 'default',
            'table_prefix' => $tablePrefix,
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'server_software' => self::sanitizeServerSoftware(
                isset($_SERVER['SERVER_SOFTWARE']) && is_scalar($_SERVER['SERVER_SOFTWARE']) ? (string)$_SERVER['SERVER_SOFTWARE'] : ''
            ),
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
        $payload += self::debugLogPayload($type);
        $payload['environment_extras']    = (new ABJ_404_Solution_FeedbackEnvironmentExtras())->collect();

        if (self::isDevelopmentEnvironment()) {
            $payload['environment_type'] = 'development';
        }

        // Type-specific extras override base fields where appropriate
        // (uninstall adds uninstall_reason / contact_email, error adds
        // error_signature / debug_log).
        foreach ($extra as $k => $v) {
            $payload[(string)$k] = $v;
        }

        $payload = ABJ_404_Solution_FeedbackPayloadSchemaGuard::normalize($payload);
        ABJ_404_Solution_FeedbackPayloadSchemaGuard::assert($payload, $type, 'buildPayload');

        return $payload;
    }

    /**
     * Build a schema-conforming payload with diagnostic and site-identifying
     * fields stripped. Used by the uninstall flow when the user unchecks the
     * "Include technical details" opt-in (docs/diagnostic-catalog.md F1):
     * the server still needs a well-formed payload to record the feedback,
     * but the modal text presents that checkbox as the diagnostic opt-in,
     * so unchecking it must actually suppress site_url, environment_extras,
     * counts, server_software, active_plugins, etc.
     *
     * Routing-only fields (plugin_version, report_type, is_uninstall) stay
     * at their real values; everything else gets the schema-allowed empty /
     * null / enum-default. Type-specific extras from `$extra` are merged on
     * top so the user's actual feedback (uninstall_reason, contact_email,
     * followup_details) still rides through.
     *
     * @param string $type One of 'error', 'heartbeat', 'uninstall', 'support_request'.
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function buildMinimal(string $type, array $extra = array()): array {
        $payload = array(
            // Routing fields - kept at real values so the server can route
            // and version-tag the report.
            'plugin_version' => defined('ABJ404_VERSION') ? ABJ404_VERSION : '',
            'report_type'    => $type,
            'is_uninstall'   => ($type === 'uninstall'),

            // Site-identifying fields - blanked.
            'site_url'        => '',
            'locale'          => '',
            'db_type'         => 'mysql',
            'db_version'      => '',
            'table_prefix'    => '',
            'wp_version'      => '',
            'is_multisite'    => false,
            'wp_debug'        => false,
            'php_version'     => '',
            'server_software' => '',

            // Environment fields - empty / null defaults that still satisfy
            // the schema (object/array shapes and int|null nullability).
            'resource_limits'       => array(),
            'wp_memory_limit_bytes' => null,
            'extensions'            => array(),
            'active_plugins'        => array(),
            'active_theme'          => '',
            'object_cache'          => 'default',

            // Content counts - null (the "unknown" sentinel).
            'published_posts_count' => null,
            'published_pages_count' => null,
            'categories_count'      => null,
            'tags_count'            => null,

            // Redirect counts - null.
            'redirects_active_total'    => null,
            'redirects_manual_count'    => null,
            'redirects_automatic_count' => null,
            'redirects_regex_count'     => null,
            'redirects_trashed_count'   => null,

            // Captured-404 counts - null.
            'captured_404s_active_total'  => null,
            'captured_404s_new_count'     => null,
            'captured_404s_ignored_count' => null,
            'captured_404s_later_count'   => null,
            'captured_404s_trashed_count' => null,

            // Log / debug file health - null.
            'log_entries_count'     => null,
            'log_table_size_bytes'  => null,
            'error_count_in_log'    => null,
            'debug_file_size_bytes' => null,

            // JSON passthrough - empty.
            'environment_extras' => array(),
        );

        // Type-specific extras the user explicitly opted in to. These ride
        // through unchanged so the feedback text/email survives the redaction.
        foreach ($extra as $k => $v) {
            $payload[(string)$k] = $v;
        }

        $payload = ABJ_404_Solution_FeedbackPayloadSchemaGuard::normalize($payload);
        ABJ_404_Solution_FeedbackPayloadSchemaGuard::assert($payload, $type, 'buildMinimalPayload');

        return $payload;
    }

    /**
     * Generate a random UUID v4 string. Uses random_bytes() (with a
     * wp_generate_password fallback) so the per-queue token is unguessable.
     *
     * @return string
     */
    public static function generateUuid(): string {
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
     * Detect a development host so reports can be flagged as non-production
     * before they ship.
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
        // Server schema (see /api/v1/reports) requires every resource_limits
        // value to be an integer. ini_get() returns shorthand strings like
        // "256M" or "30s"; converting here keeps the server validator and the
        // database column types aligned (BIGINT for bytes, INT for seconds).
        return array(
            'php_memory' => function_exists('ini_get') ? self::iniSizeToBytes((string)ini_get('memory_limit')) : 0,
            'wp_memory'  => defined('WP_MEMORY_LIMIT') ? self::iniSizeToBytes((string)WP_MEMORY_LIMIT) : 0,
            'php_max_execution_seconds' => function_exists('ini_get') ? (int)ini_get('max_execution_time') : 0,
            'php_post_max_size' => function_exists('ini_get') ? self::iniSizeToBytes((string)ini_get('post_max_size')) : 0,
            'php_upload_max_size' => function_exists('ini_get') ? self::iniSizeToBytes((string)ini_get('upload_max_filesize')) : 0,
        );
    }

    /**
     * Convert PHP's shorthand byte notation ("256M", "1G", "1024K", "-1") to
     * an integer count of bytes. Returns 0 for empty input or the special
     * "no limit" value -1, since the server schema requires non-negative
     * integers. Plain-numeric strings ("512") are treated as already-bytes.
     */
    private static function iniSizeToBytes(string $value): int {
        $str = trim($value);
        if ($str === '' || $str === '-1' || $str === '0') {
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

    private static function memoryLimitBytes(): int {
        if (!defined('WP_MEMORY_LIMIT')) {
            return 0;
        }
        return self::iniSizeToBytes((string)WP_MEMORY_LIMIT);
    }

    /**
     * Strip site-identifying noise from the SERVER_SOFTWARE banner before
     * shipping it. Apache's mod_status footer (ServerSignature) writes
     * "Server at <hostname> Port <n>" onto SERVER_SOFTWARE on hosts that
     * never disabled the banner, leaking the site's internal hostname into
     * telemetry. Cut at that literal marker (case-insensitive) and cap the
     * result so a multi-line or otherwise verbose banner cannot smuggle
     * additional context through. Useful prefix (software + version, e.g.
     * "Apache/2.4.41 (Ubuntu)") is preserved.
     */
    private static function sanitizeServerSoftware(string $raw): string {
        if ($raw === '') {
            return '';
        }
        $marker = stripos($raw, ' Server at ');
        $clean = $marker === false ? $raw : substr($raw, 0, $marker);
        $clean = trim($clean);
        if (strlen($clean) > 100) {
            $clean = substr($clean, 0, 100);
        }
        return $clean;
    }

    /**
     * Call an int-returning helper, returning null if it throws. Lets the
     * builder assemble a partial report when one count source fails.
     */
    private static function tryInt(callable $fn): ?int {
        try {
            $v = $fn();
            return is_int($v) ? $v : null;
        } catch (\Throwable $e) {
            @error_log('404 Solution: FeedbackPayloadBuilder count lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function tryString(callable $fn): string {
        try {
            $v = $fn();
            return is_string($v) ? $v : '';
        } catch (\Throwable $e) {
            @error_log('404 Solution: FeedbackPayloadBuilder string lookup failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
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
            @error_log('404 Solution: FeedbackPayloadBuilder array lookup failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Pull a single int from a count map, returning null when the key is
     * absent. Distinguishes "DAO returned an empty array" (failure, null)
     * from "DAO returned 0 for this status" (real zero) in the payload.
     *
     * @param array<string, mixed> $map
     */
    private static function pluckInt(array $map, string $key): ?int {
        if (!array_key_exists($key, $map)) {
            return null;
        }
        $v = $map[$key];
        return is_scalar($v) ? (int)$v : null;
    }

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
     * @return array<string, int>
     */
    private static function redirectCountsRaw(): array {
        $viewReadService = self::viewReadService();
        if ($viewReadService === null || !method_exists($viewReadService, 'getRedirectStatusCounts')) {
            throw new \RuntimeException('ViewReadService::getRedirectStatusCounts unavailable');
        }
        $raw = $viewReadService->getRedirectStatusCounts(true);
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
     * @return array<string, int>
     */
    private static function capturedCountsRaw(): array {
        $viewReadService = self::viewReadService();
        if ($viewReadService === null || !method_exists($viewReadService, 'getCapturedStatusCounts')) {
            throw new \RuntimeException('ViewReadService::getCapturedStatusCounts unavailable');
        }
        $raw = $viewReadService->getCapturedStatusCounts(true);
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

    private static function logEntriesCount(): int {
        $viewReadService = self::viewReadService();
        if ($viewReadService === null || !method_exists($viewReadService, 'getLogsCount')) {
            throw new \RuntimeException('ViewReadService::getLogsCount unavailable');
        }
        $v = $viewReadService->getLogsCount(0);
        if (is_scalar($v)) {
            return (int)$v;
        }
        throw new \RuntimeException('getLogsCount returned unexpected shape');
    }

    private static function logTableSizeBytes(): int {
        $dao = self::viewReadService();
        if ($dao === null || !method_exists($dao, 'getLogDiskUsage')) {
            throw new \RuntimeException('DataAccess::getLogDiskUsage unavailable');
        }
        $v = $dao->getLogDiskUsage();
        if (is_scalar($v)) {
            return (int)$v;
        }
        throw new \RuntimeException('getLogDiskUsage returned unexpected shape');
    }

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
     * @return array{debug_log?: string}
     */
    private static function debugLogPayload(string $type): array {
        if ($type !== 'error' && $type !== 'heartbeat') {
            return array();
        }
        return array('debug_log' => self::tryString(function () { return self::debugLogTail(); }));
    }

    private static function debugLogTail(): string {
        if (!function_exists('abj_service')) {
            throw new \RuntimeException('abj_service unavailable');
        }
        $logger = abj_service('logging');
        if (!is_object($logger) || !method_exists($logger, 'getDebugFilePath')) {
            throw new \RuntimeException('Logging::getDebugFilePath unavailable');
        }
        $path = $logger->getDebugFilePath();
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return '';
        }

        $size = @filesize($path);
        if (!is_int($size) || $size <= 0) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('fopen() failed');
        }

        try {
            $offset = max(0, $size - self::DEBUG_LOG_MAX_BYTES);
            if ($offset > 0 && @fseek($handle, $offset) !== 0) {
                throw new \RuntimeException('fseek() failed');
            }

            $remaining = min($size, self::DEBUG_LOG_MAX_BYTES);
            $contents = '';
            while ($remaining > 0 && !feof($handle)) {
                $chunk = @fread($handle, min(8192, $remaining));
                if ($chunk === false) {
                    throw new \RuntimeException('fread() failed');
                }
                if ($chunk === '') {
                    break;
                }
                $contents .= $chunk;
                $remaining -= strlen($chunk);
            }
            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Service-container lookup for DataAccess, returning null instead of
     * throwing when the container isn't initialized yet (test contexts,
     * early boot before Loader.php runs).
     */
    private static function viewReadService(): ?object {
        if (!function_exists('abj_service')) {
            return null;
        }
        // allow-silent-catch: container may not be initialized in early-boot or test contexts; null return signals callers to use zero defaults, no diagnostic info exists yet to log
        try {
            $svc = abj_service('view_read_service');
            return is_object($svc) ? $svc : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build an object-shaped extension map: {curl: true, mbstring: true, ...}.
     * Server schema declares extensions as `object` so it can mark known
     * extensions as boolean columns (has_curl, has_mbstring, etc.). Sending
     * the raw `get_loaded_extensions()` array of strings would be rejected
     * by the server validator with "must be object".
     *
     * @return array<string, bool>
     */
    private static function loadedExtensionsMap(): array {
        if (!function_exists('get_loaded_extensions')) {
            return array();
        }
        $names = get_loaded_extensions();
        if (!is_array($names)) {
            return array();
        }
        $out = array();
        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                // Lowercase the key so the server's has_<ext> column mapping
                // is case-stable: ini-loaded extensions report as "Core",
                // "OpenSSL", etc. while the server probes "curl", "openssl".
                $out[strtolower($name)] = true;
            }
        }
        return $out;
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
     * Return a short human-readable theme identifier ("Name 1.2.3"). Server
     * schema declares active_theme as `string`, not the {name, version}
     * object the email transport historically sent.
     */
    private static function activeTheme(): string {
        if (!function_exists('wp_get_theme')) {
            return '';
        }
        $theme = wp_get_theme();
        if (!is_object($theme) || !method_exists($theme, 'get')) {
            return '';
        }
        $rawName = $theme->get('Name');
        $rawVer = $theme->get('Version');
        $name = is_string($rawName) ? trim($rawName) : '';
        $version = is_string($rawVer) ? trim($rawVer) : '';
        if ($name === '' && $version === '') {
            return '';
        }
        return $version === '' ? $name : trim($name . ' ' . $version);
    }
}
