<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A link that opens one specific Advanced Mode setting, from anywhere.
 *
 * Every new install starts in Simple Mode, and the mode is a per-user
 * preference held in user_meta (see SettingsModePreference), not a URL
 * parameter. So a plain `...&subpage=abj404_options#auto_score` link lands a
 * simple-mode admin on a page where the field they were promised does not
 * exist. This class closes that gap from both ends:
 *
 *   - {@see urlForAdvancedSetting()} builds a nonce-protected link that
 *     carries a request to switch the current user to Advanced Mode, plus the
 *     `#fragment` of the field to land on.
 *   - {@see applyIfRequested()} runs at admin_init, verifies the nonce and
 *     that the caller really is a plugin admin, and flips that user's own
 *     stored preference to Advanced before the page renders.
 *
 * Only the requesting user's own display preference is changed, only ever
 * towards Advanced, and the mode toggle sitting at the top of the page they
 * land on shows the new state and puts it back in one click. Nothing about
 * the plugin's behaviour changes; Advanced Mode only reveals more fields.
 */
class ABJ_404_Solution_SettingsModeDeepLink {

    /** Query arg carrying the request. Also referenced literally by AdminInitHandlers. */
    const QUERY_ARG = 'abj404_show_advanced';

    /** Nonce action guarding the mode switch. */
    const NONCE_ACTION = 'abj404_show_advanced_setting';

    /**
     * Absolute, unescaped URL to the options page that puts the current user
     * into Advanced Mode and lands on the given field.
     *
     * @param string $anchorId The `id` attribute of the field to land on,
     *   e.g. 'auto_score'.
     * @param array<string, mixed> $options The plugin options.
     * @return string
     */
    public static function urlForAdvancedSetting(string $anchorId, array $options): string {
        $args = array(self::QUERY_ARG => '1');
        if (function_exists('wp_create_nonce')) {
            $args['_wpnonce'] = (string)wp_create_nonce(self::NONCE_ACTION);
        }
        $url = ABJ_404_Solution_AdminPageUrlBuilder::subpageUrl('abj404_options', $args, $options);

        if ($anchorId !== '') {
            $url .= '#' . rawurlencode($anchorId);
        }
        return $url;
    }

    /**
     * Honour an inbound advanced-setting link: switch the current user to
     * Advanced Mode so the field the link points at is actually rendered.
     *
     * No-op unless the request carries the query arg, a valid nonce for this
     * user's session, and the caller is a plugin admin. Never switches a user
     * back to Simple Mode; that stays a deliberate act on the mode toggle.
     *
     * @return bool True when the preference was switched.
     */
    public static function applyIfRequested(): bool {
        if (!isset($_GET[self::QUERY_ARG])) {
            return false;
        }
        if (!function_exists('wp_verify_nonce')) {
            return false;
        }

        $policy = abj_service('admin_access_policy');
        if (!is_object($policy) || !method_exists($policy, 'isPluginAdmin') || !$policy->isPluginAdmin()) {
            return false;
        }

        $nonce = ABJ_404_Solution_RequestInputNormalizer::readText(
            $_GET, array('name' => '_wpnonce'));
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return false;
        }

        $preference = abj_service('settings_mode_preference');
        if (!is_object($preference) || !method_exists($preference, 'setMode')) {
            return false;
        }
        $writeResult = $preference->setMode(
            ABJ_404_Solution_SettingsModePreference::MODE_ADVANCED
        );
        if ($writeResult === false) {
            self::reportModeWriteFailure();
            return false;
        }
        return true;
    }

    private static function reportModeWriteFailure(): void {
        $message = 'Advanced-settings deep link could not persist the current user mode.';
        $logger = function_exists('abj_service_optional')
            ? abj_service_optional('logging')
            : null;
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }
        abj404_logPhpFallback('settings-mode-deep-link', $message);
    }
}
