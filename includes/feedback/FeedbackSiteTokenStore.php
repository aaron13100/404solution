<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * Owns the plugin-side diagnostic identity credential stored in wp_options.
 * The server mints every token; this class only validates, persists, reads,
 * and cache-bypasses that confirmed value.
 */
class ABJ_404_Solution_FeedbackSiteTokenStore {

    const TOKEN_OPTION = 'abj404_site_token';
    const NOTICE_TRANSIENT = 'abj404_plugin_db_notice';
    const NOTICE_TYPE = 'diagnostic_identity_reestablished';
    const NOTICE_TTL = 86400;

    /**
     * @param mixed $value
     */
    public static function isValidToken($value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    public static function storedToken(): string {
        if (!function_exists('get_option')) {
            return '';
        }
        $raw = get_option(self::TOKEN_OPTION, '');
        if ($raw === '' || $raw === false || $raw === null) {
            return '';
        }
        if (!is_string($raw) || !self::isValidToken($raw)) {
            ABJ_404_Solution_FeedbackTransportLog::log(
                'warn',
                'abj404_transport: stored site token is malformed; ignoring it for this send'
            );
            return '';
        }
        return $raw;
    }

    public static function freshStoredToken(): string {
        self::clearOptionCaches();
        return self::storedToken();
    }

    public static function persistToken(string $token): bool {
        if (!self::isValidToken($token)) {
            return false;
        }
        if (!function_exists('update_option')) {
            ABJ_404_Solution_FeedbackTransportLog::log(
                'warn',
                'abj404_transport: update_option unavailable; could not persist site token'
            );
            return false;
        }
        return (bool) update_option(self::TOKEN_OPTION, $token, false);
    }

    public static function recordIdentityRecoveryNotice(): void {
        ABJ_404_Solution_FeedbackTransportLog::log(
            'warn',
            'abj404_transport: site diagnostic identity was re-established after a 401 response'
        );
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return;
        }
        $existing = get_transient(self::NOTICE_TRANSIENT);
        if (is_array($existing) && (($existing['type'] ?? null) === self::NOTICE_TYPE)) {
            return;
        }
        // allow-cache-empty: locally generated admin-notice payload, not a cached fetch result.
        set_transient(self::NOTICE_TRANSIENT, array(
            'type' => self::NOTICE_TYPE,
            'message' => __(
                "This site's diagnostic identity had to be re-established after the reports server rejected its stored credential.",
                '404-solution'
            ),
            'guidance' => __(
                'Reporting has recovered. Older self-service privacy history may require the manual privacy request path.',
                '404-solution'
            ),
        ), self::NOTICE_TTL);
    }

    private static function clearOptionCaches(): void {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete(self::TOKEN_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }
}
