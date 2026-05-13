<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/FeedbackTransportTrait_EnvironmentExtras.php';

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

    use ABJ_404_Solution_FeedbackTransport_EnvironmentExtrasTrait;

    const TRANSIENT_PREFIX = 'abj404_pending_report_';
    const TRANSIENT_TTL = 86400; // 24 hours
    const CRON_HOOK = 'abj404_send_queued_report';
    const HTTP_TIMEOUT = 10;

    /**
     * Records whether the most recent sendNow() call fell back to wp_mail()
     * after the HTTP POST failed. Read-only for callers that need to surface
     * "we sent via email instead of HTTP" in their own response (e.g. the
     * support-request AJAX handler returning {fallback_used: true}). Reset
     * at the top of every sendNow() call so concurrent reads don't see a
     * stale value from a previous unrelated send.
     *
     * @var bool
     */
    private static $lastSendUsedFallback = false;

    /**
     * Diagnostic details from the most recent sendNow() call. Populated
     * unconditionally so callers (e.g. the support-request AJAX handler)
     * can surface the actual failure code and reason to the user instead
     * of a generic "could not send" message.
     *
     * Shape:
     *   http_status:      int|null  HTTP status code from the developer
     *                                endpoint when the wp_remote_post()
     *                                call completed, or null when the
     *                                request never reached HTTP.
     *   http_reason:      string    Short slug (json_encode_failed,
     *                                gzencode_failed, wp_error,
     *                                http_<code>) usable for log greps.
     *   http_detail:      string    Free-form context (WP_Error message,
     *                                etc). May be empty.
     *   email_attempted:  bool      true when HTTP failed and the email
     *                                fallback ran.
     *   email_ok:         bool|null Result of the email fallback when it
     *                                ran; null when not attempted.
     *
     * @var array{http_status: int|null, http_reason: string, http_detail: string, email_attempted: bool, email_ok: bool|null}
     */
    private static $lastSendDiagnostics = array(
        'http_status'     => null,
        'http_reason'     => '',
        'http_detail'     => '',
        'email_attempted' => false,
        'email_ok'        => null,
    );

    /**
     * Queue a payload for asynchronous send. Used by interactive paths
     * (deactivate AJAX). Returns immediately; the actual send happens in a
     * single-shot cron event.
     *
     * Schedules the cron event and then kicks WP-Cron via spawn_cron() so the
     * send happens on the next request cycle instead of waiting for a natural
     * cron tick. On low-traffic sites a natural tick can be hours away, which
     * is long enough for the deactivate flow to forget about the report.
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

        // Trigger spawn_cron so the listener runs on the next request rather
        // than waiting for the next page load on a logged-in admin. spawn_cron
        // is a no-op when DISABLE_WP_CRON is true or a cron is already running.
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
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
        self::$lastSendUsedFallback = false;
        $started = microtime(true);
        $result = self::httpSend($payload);
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        $statusStr = isset($result['status']) && is_scalar($result['status']) ? (string)$result['status'] : '';
        $reasonStr = isset($result['reason']) && is_scalar($result['reason']) ? (string)$result['reason'] : '';
        $detailStr = isset($result['detail']) && is_scalar($result['detail']) ? (string)$result['detail'] : '';

        self::$lastSendDiagnostics = array(
            'http_status'     => $statusStr !== '' ? (int)$statusStr : null,
            'http_reason'     => $reasonStr,
            'http_detail'     => $detailStr,
            'email_attempted' => false,
            'email_ok'        => null,
        );

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

        self::$lastSendUsedFallback = true;
        self::$lastSendDiagnostics['email_attempted'] = true;
        $emailOk = self::emailFallback($payload, $type);
        self::$lastSendDiagnostics['email_ok'] = $emailOk;
        return $emailOk;
    }

    /**
     * Diagnostic context from the most recent sendNow() call. Callers
     * that surface a user-facing failure message must include the
     * http_status / http_reason here so the message is actionable.
     * "Could not send" alone is the diagnostic black-hole this method
     * exists to prevent (CLAUDE.md > Error visibility).
     *
     * @return array{http_status: int|null, http_reason: string, http_detail: string, email_attempted: bool, email_ok: bool|null}
     */
    public static function lastSendDiagnostics(): array {
        return self::$lastSendDiagnostics;
    }

    /**
     * Whether the most recent sendNow() call used the wp_mail() fallback
     * after the HTTP POST failed. Callers (e.g. the support-request AJAX
     * handler) read this immediately after sendNow() to surface the
     * transport result to the user.
     *
     * @return bool
     */
    public static function lastSendUsedFallback(): bool {
        return self::$lastSendUsedFallback;
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
            // Transient expired before WP-Cron fired, or the cron event fired
            // twice and the second invocation found the key already cleared.
            // Log so the data loss is visible to admins; 24h TTL means this
            // path is reachable on sites where WP-Cron is broken or paused.
            self::log('warn', sprintf(
                'abj404_transport: queued send missed - transient absent or malformed (key=%s). ' .
                'Most commonly: WP-Cron did not fire within the %d second TTL.',
                $key,
                self::TRANSIENT_TTL
            ));
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
     * @param string $type One of 'error', 'heartbeat', 'uninstall', 'support_request'.
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
        $payload['environment_extras']    = self::environmentExtras();

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
    public static function buildMinimalPayload(string $type, array $extra = array()): array {
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
        // Surface the server's structured error message in `detail`. The dev
        // endpoint's setErrorHandler returns
        // {statusCode, error: 'validation_failed', message: '<human>', field?}
        // on schema rejections; without this extraction the admin only sees
        // "HTTP 400" and has no way to tell which field was wrong.
        $rawBody = function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response) : '';
        $detail = self::extractServerErrorDetail(is_string($rawBody) ? $rawBody : '');
        return array('ok' => false, 'reason' => 'http_' . $code, 'status' => $code, 'detail' => $detail);
    }

    /**
     * Pull the response body off a wp_remote_post() result and, when it's a
     * JSON error envelope, extract a one-line "<message> [field=<path>]"
     * detail. Falls back to a short truncated body when the response isn't
     * structured JSON, so opaque HTML error pages from a misrouted endpoint
     * still leave a fingerprint in the admin notice.
     *
     * @param string $body Raw response body from wp_remote_retrieve_body().
     * @return string Empty string if no useful detail could be extracted.
     */
    private static function extractServerErrorDetail(string $body): string {
        if ($body === '') {
            return '';
        }
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = '';
            if (isset($decoded['message']) && is_scalar($decoded['message'])) {
                $message = trim((string)$decoded['message']);
            }
            $field = '';
            if (isset($decoded['field']) && is_scalar($decoded['field'])) {
                $field = trim((string)$decoded['field']);
            }
            if ($message !== '' && $field !== '') {
                return $message . ' [field=' . $field . ']';
            }
            if ($message !== '') {
                return $message;
            }
        }
        // Non-JSON body (HTML error page, plain text). Trim to a sane size
        // so a 1 MB rendered 502 page can't blow up the admin notice.
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }
        return strlen($trimmed) > 240 ? substr($trimmed, 0, 240) . '...' : $trimmed;
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
            // Reject anything that isn't a non-empty http(s) URL so a buggy
            // filter callback can never coerce wp_remote_post into a SSRF /
            // file:// / data: request, or block on a malformed host.
            if (is_string($filtered) && $filtered !== '' && self::isHttpUrl($filtered)) {
                return $filtered;
            }
            if ($filtered !== $default) {
                self::log('warn', sprintf(
                    'abj404_transport: abj404_report_endpoint filter returned an invalid value; using default. got=%s',
                    is_scalar($filtered) ? (string)$filtered : gettype($filtered)
                ));
            }
        }
        return $default;
    }

    /**
     * Strict-shape URL check: only accept http:// or https:// with a host
     * component. parse_url('http://') yields a parseable structure with no
     * host, so we test for that explicitly.
     *
     * @param string $url
     * @return bool
     */
    private static function isHttpUrl(string $url): bool {
        $scheme = function_exists('wp_parse_url')
            ? wp_parse_url($url, PHP_URL_SCHEME)
            : parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || ($scheme !== 'http' && $scheme !== 'https')) {
            return false;
        }
        $host = function_exists('wp_parse_url')
            ? wp_parse_url($url, PHP_URL_HOST)
            : parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '';
    }

    /**
     * Last-resort wp_mail() fallback. Routes to the type-specific email body
     * builder so HTTP and email transports share a single source of truth:
     *   - 'uninstall' delegates to UninstallModal::sendFeedbackEmail($payload)
     *   - 'error' / 'heartbeat' delegates to Logging::emailLogFileToDeveloper($payload)
     *     (attaches a zip of the current debug log and renders the same HTML
     *     body the email transport used pre-migration).
     *   - 'support_request' is built inline (subject + body include the
     *     user's message and reply email at the top, then the standard
     *     diagnostic block + log excerpt).
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
        if ($type === 'support_request') {
            return self::supportRequestEmailFallback($payload);
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
     * Build the wp_mail() body for type='support_request' fallbacks. The
     * user-facing fields (their message and reply address) lead the body so
     * a reviewer can act on the request without scrolling past the
     * diagnostic block. The standard payload dump and any captured log
     * excerpt follow as appendices.
     *
     * @param array<string, mixed> $payload
     * @return bool
     */
    private static function supportRequestEmailFallback(array $payload): bool {
        $to = defined('ABJ404_AUTHOR_EMAIL') ? ABJ404_AUTHOR_EMAIL : '404solution@ajexperience.com';
        $version = defined('ABJ404_VERSION') ? ABJ404_VERSION : '';
        $subject = sprintf('[404 Solution] Support request v%s', $version);

        $userMessage = isset($payload['user_message']) && is_scalar($payload['user_message'])
            ? (string)$payload['user_message'] : '';
        $replyEmail = isset($payload['reply_email']) && is_scalar($payload['reply_email'])
            ? (string)$payload['reply_email'] : '';
        $triggeredFrom = isset($payload['triggered_from']) && is_scalar($payload['triggered_from'])
            ? (string)$payload['triggered_from'] : '';
        $logExcerpt = isset($payload['debug_log_excerpt']) && is_scalar($payload['debug_log_excerpt'])
            ? (string)$payload['debug_log_excerpt'] : '';

        $bodyLines = array();
        $bodyLines[] = '=== USER SUPPORT REQUEST ===';
        $bodyLines[] = '';
        $bodyLines[] = 'Reply-To: ' . ($replyEmail !== '' ? $replyEmail : '(not provided)');
        $bodyLines[] = 'Triggered from: ' . ($triggeredFrom !== '' ? $triggeredFrom : '(unknown)');
        $bodyLines[] = '';
        $bodyLines[] = '--- User message ---';
        $bodyLines[] = $userMessage !== '' ? $userMessage : '(no message provided)';
        $bodyLines[] = '';
        $bodyLines[] = '=== DIAGNOSTICS ===';
        $bodyLines[] = '';
        $scalar = static function ($v, string $fallback = ''): string {
            return is_scalar($v) ? (string)$v : $fallback;
        };
        $bodyLines[] = 'Plugin version: ' . $scalar($payload['plugin_version'] ?? null);
        $bodyLines[] = 'PHP version: ' . $scalar($payload['php_version'] ?? null, PHP_VERSION);
        $bodyLines[] = 'WP version: ' . $scalar($payload['wp_version'] ?? null);
        $bodyLines[] = 'DB: ' . $scalar($payload['db_type'] ?? null) . ' ' . $scalar($payload['db_version'] ?? null);
        $bodyLines[] = 'Site URL: ' . $scalar($payload['site_url'] ?? null);
        $bodyLines[] = 'Multisite: ' . (!empty($payload['is_multisite']) ? 'yes' : 'no');
        $bodyLines[] = '';
        if ($logExcerpt !== '') {
            $bodyLines[] = '=== DEBUG LOG EXCERPT ===';
            $bodyLines[] = '';
            $bodyLines[] = $logExcerpt;
            $bodyLines[] = '';
        }
        $bodyLines[] = '=== FULL PAYLOAD (JSON) ===';
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_PRETTY_PRINT) : json_encode($payload, JSON_PRETTY_PRINT);
        $bodyLines[] = is_string($json) ? $json : '';

        $body = implode("\n", $bodyLines);
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        if ($replyEmail !== '') {
            $headers[] = 'Reply-To: ' . $replyEmail;
        }
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
     *
     * @param string $value
     * @return int
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
     *
     * @param string $raw
     * @return string
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
     *
     * @return string
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
