<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-user UI preference: tracks whether the current WP user wants
 * the plugin's settings/admin surface rendered in "simple" or
 * "advanced" mode. Backed by `user_meta` (key `abj404_settings_mode`).
 *
 * Owns getSettingsMode / setSettingsMode previously hosted on
 * PluginLogic. Composed through abj_service('settings_mode_preference').
 *
 * Unknown / missing / unauthenticated callers fall back to 'simple'
 * (the safer default for a brand-new install).
 */
class ABJ_404_Solution_SettingsModePreference {

    const META_KEY = 'abj404_settings_mode';
    const MODE_SIMPLE = 'simple';
    const MODE_ADVANCED = 'advanced';

    /** @var self|null */
    private static $instance = null;

    /** @var bool */
    private static $loggedUserLookupFailure = false;

    /** @return self */
    public static function getInstance(): self {
        if (self::$instance !== null) {
            return self::$instance;
        }
        self::$instance = new self();
        return self::$instance;
    }

    /** Test-seam reset. @return void */
    public static function reset(): void {
        self::$instance = null;
        self::$loggedUserLookupFailure = false;
    }

    /**
     * Get the current user's settings mode preference.
     *
     * @return string 'simple' or 'advanced'
     */
    public function getMode(): string {
        if (!function_exists('get_current_user_id')) {
            return self::MODE_SIMPLE;
        }
        try {
            $userId = get_current_user_id();
        } catch (\Throwable $e) {
            // Delegated to logUserLookupFailure(), which preserves the error
            // once per process to avoid parallel-test stderr floods. No
            // allow-silent-catch marker: the delegate call is what makes this
            // catch non-silent, and a marker here would keep the audit green
            // if that call were ever removed.
            $this->logUserLookupFailure($e);
            return self::MODE_SIMPLE;
        }
        if (!$userId) {
            return self::MODE_SIMPLE;
        }
        if (!function_exists('get_user_meta')) {
            return self::MODE_SIMPLE;
        }
        try {
            $mode = get_user_meta($userId, self::META_KEY, true);
        } catch (\Throwable $e) {
            $this->logWarning('settings mode read failed (code ' .
                $e->getCode() . '): ' . $e->getMessage());
            return self::MODE_SIMPLE;
        }
        return ($mode === self::MODE_ADVANCED) ? self::MODE_ADVANCED : self::MODE_SIMPLE;
    }

    /**
     * Set the current user's settings mode preference. Unknown values
     * are clamped to 'simple'.
     *
     * @param string $mode 'simple' or 'advanced'
     * @return bool|int Meta ID on insert, true on update, false on failure / no user
     */
    public function setMode($mode) {
        if (!function_exists('get_current_user_id')) {
            return false;
        }
        try {
            $userId = get_current_user_id();
        } catch (\Throwable $e) {
            // Delegated to logUserLookupFailure(), which preserves the error
            // once per process to avoid parallel-test stderr floods. No
            // allow-silent-catch marker: the delegate call is what makes this
            // catch non-silent, and a marker here would keep the audit green
            // if that call were ever removed.
            $this->logUserLookupFailure($e);
            return false;
        }
        if (!$userId) {
            return false;
        }
        if (!function_exists('update_user_meta')) {
            return false;
        }
        $validMode = ($mode === self::MODE_ADVANCED) ? self::MODE_ADVANCED : self::MODE_SIMPLE;
        try {
            return update_user_meta($userId, self::META_KEY, $validMode);
        } catch (\Throwable $e) {
            $this->logWarning('settings mode write failed (code ' .
                $e->getCode() . '): ' . $e->getMessage());
            return false;
        }
    }

    private function logUserLookupFailure(\Throwable $e): void {
        if (strpos($e->getMessage(), 'not defined nor mocked') !== false) {
            return;
        }
        if (self::$loggedUserLookupFailure) {
            return;
        }
        self::$loggedUserLookupFailure = true;
        $this->logWarning('settings mode user lookup failed (code ' .
            $e->getCode() . '): ' . $e->getMessage());
    }

    private function logWarning(string $message): void {
        $logger = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
