<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for toggling between Simple and Advanced settings modes.
 */

class ABJ_404_Solution_Ajax_SettingsModeToggle {

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /** @return self */
    public static function getInstance(): self {
        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_Ajax_SettingsModeToggle();
        }
        return self::$instance;
    }

    /**
     * Initialize AJAX handlers.
     * @return void
     */
    static function init(): void {
        $me = ABJ_404_Solution_Ajax_SettingsModeToggle::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_toggle_settings_mode',
            array($me, 'handleModeToggle'));
    }

    /**
     * Handle the mode toggle AJAX request.
     * @return void
     */
    function handleModeToggle(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-settings-mode-toggle')) {
            return;
        }

        abj_service('ajax_security_gate')->requireAdminWithNonce('abj404_mode_toggle');

        $container = ABJ_404_Solution_ServiceContainer::getInstance();
        /** @var ABJ_404_Solution_Functions $functions */
        $functions = $container->has('functions') ? $container->get('functions') : abj_service('functions');

        $mode = $functions->getPostOrGetSanitize('mode');

        // Validate mode
        if ($mode !== 'simple' && $mode !== 'advanced') {
            wp_send_json_error(array('message' => __('Invalid mode', '404-solution')), 400);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Set the mode
        $result = abj_service('settings_mode_preference')->setMode($mode);

        if ($result !== false) {
            wp_send_json_success(array(
                'mode' => $mode,
                'message' => __('Settings mode updated', '404-solution')
            ));
        } else {
            $logger = abj_service('logging');
            if ($logger !== null) {
                $logger->warn('Ajax_SettingsModeToggle::handleModeToggle: setSettingsMode("' . $mode .
                    '") returned false (option write failed or value unchanged). Returning HTTP 500 to AJAX caller.');
            }
            // Surface the wpdb-reported failure so the admin can self-diagnose
            // (corrupted user_meta table, read-only DB, etc.). When
            // update_user_meta() returns false because the value is unchanged
            // rather than because of a DB error, last_error is empty and the
            // framing message stands alone.
            global $wpdb;
            $dbError = isset($wpdb->last_error) && is_string($wpdb->last_error) ? $wpdb->last_error : '';
            $framing = __('Failed to update settings mode', '404-solution');
            $message = $dbError !== '' ? $framing . ' (DB error: ' . $dbError . ')' : $framing;
            wp_send_json_error(array('message' => $message), 500);
        }
    }
}
