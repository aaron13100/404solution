<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for Engine Profile CRUD operations.
 *
 * Actions:
 *   abj404_engine_profiles_list   – Return all profiles as JSON.
 *   abj404_engine_profiles_save   – Insert or update a profile.
 *   abj404_engine_profiles_delete – Delete a profile by ID.
 */
class ABJ_404_Solution_Ajax_EngineProfiles {

    /** @return void */
    public static function registerActions(): void {
        ABJ_404_Solution_WPUtils::safeAddAction(
            'wp_ajax_abj404_engine_profiles_list',
            'ABJ_404_Solution_Ajax_EngineProfiles::handleList'
        );
        ABJ_404_Solution_WPUtils::safeAddAction(
            'wp_ajax_abj404_engine_profiles_save',
            'ABJ_404_Solution_Ajax_EngineProfiles::handleSave'
        );
        ABJ_404_Solution_WPUtils::safeAddAction(
            'wp_ajax_abj404_engine_profiles_delete',
            'ABJ_404_Solution_Ajax_EngineProfiles::handleDelete'
        );
    }

    /**
     * Return all profiles as a JSON array.
     *
     * @return void
     */
    public static function handleList(): void {
        check_ajax_referer('abj404_engine_profiles_nonce', 'nonce');
        if (!ABJ_404_Solution_PluginLogic::getInstance()->userIsPluginAdmin()) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $profiles = ABJ_404_Solution_EngineProfileResolver::getInstance()->getAllProfilesForAdmin();
        wp_send_json_success(['profiles' => $profiles]);
    }

    /**
     * Insert or update a profile.
     *
     * Expected POST fields: name, url_pattern, is_regex, enabled_engines (JSON),
     * priority, status, and optionally id (for update).
     *
     * @return void
     */
    public static function handleSave(): void {
        check_ajax_referer('abj404_engine_profiles_nonce', 'nonce');
        if (!ABJ_404_Solution_PluginLogic::getInstance()->userIsPluginAdmin()) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $id           = isset($_POST['id'])              ? absint($_POST['id'])                                      : 0;
        $name         = isset($_POST['name'])            ? sanitize_text_field(wp_unslash((string)$_POST['name']))   : '';
        $urlPattern   = isset($_POST['url_pattern'])     ? wp_unslash((string)$_POST['url_pattern'])                 : '';
        $isRegex      = isset($_POST['is_regex'])        ? (int)(bool)$_POST['is_regex']                             : 0;
        $engines      = isset($_POST['enabled_engines']) ? wp_unslash((string)$_POST['enabled_engines'])             : '[]';
        $priority     = isset($_POST['priority'])        ? (int)$_POST['priority']                                   : 0;
        $status       = isset($_POST['status'])          ? (int)(bool)$_POST['status']                               : 1;

        if (trim($name) === '') {
            wp_send_json_error(['message' => __('Profile name is required.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        if (trim($urlPattern) === '') {
            wp_send_json_error(['message' => __('URL pattern is required.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Validate regex pattern before saving.
        // Patterns are stored WITHOUT PHP delimiters (users write ^/shop/ not #^/shop/#).
        // The resolver wraps with # delimiters at match-time when the first char is not
        // a common delimiter — we must mirror that same logic here so validation matches
        // what will actually be executed.
        if ($isRegex) {
            $testPattern      = $urlPattern;
            $commonDelimiters = ['/', '#', '~', '!', '@', '|', '%'];
            if (!in_array(substr($testPattern, 0, 1), $commonDelimiters, true)) {
                $testPattern = '#' . $testPattern . '#';
            }
            set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool { return false; }, E_WARNING);
            $testResult = @preg_match($testPattern, '');
            restore_error_handler();
            if ($testResult === false) {
                wp_send_json_error(['message' => __('Invalid regular expression pattern.', '404-solution')]);
                return; // @phpstan-ignore deadCode.unreachable
            }
        }

        $data = array(
            'id'              => $id,
            'name'            => $name,
            'url_pattern'     => $urlPattern,
            'is_regex'        => $isRegex,
            'enabled_engines' => $engines,
            'priority'        => $priority,
            'status'          => $status,
        );

        $resultId = ABJ_404_Solution_EngineProfileResolver::getInstance()->saveProfile($data);

        if ($resultId === false) {
            wp_send_json_error(['message' => __('Failed to save engine profile.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        wp_send_json_success(['id' => $resultId]);
    }

    /**
     * Delete a profile by ID.
     *
     * @return void
     */
    public static function handleDelete(): void {
        check_ajax_referer('abj404_engine_profiles_nonce', 'nonce');
        if (!ABJ_404_Solution_PluginLogic::getInstance()->userIsPluginAdmin()) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid profile ID.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $ok = ABJ_404_Solution_EngineProfileResolver::getInstance()->deleteProfile($id);
        if (!$ok) {
            wp_send_json_error(['message' => __('Failed to delete engine profile.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        wp_send_json_success(['id' => $id]);
    }
}
