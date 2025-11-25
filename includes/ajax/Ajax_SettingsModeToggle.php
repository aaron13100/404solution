<?php

/**
 * AJAX handler for toggling between Simple and Advanced settings modes.
 */

class ABJ_404_Solution_Ajax_SettingsModeToggle {

    private static $instance = null;

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_Ajax_SettingsModeToggle();
        }
        return self::$instance;
    }

    /**
     * Initialize AJAX handlers.
     */
    static function init() {
        $me = ABJ_404_Solution_Ajax_SettingsModeToggle::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_toggle_settings_mode',
            array($me, 'handleModeToggle'));
    }

    /**
     * Handle the mode toggle AJAX request.
     */
    function handleModeToggle() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        // Get and sanitize input
        $mode = $abj404dao->getPostOrGetSanitize('mode');
        $nonce = $abj404dao->getPostOrGetSanitize('nonce');

        // Verify nonce for CSRF protection
        if (!wp_verify_nonce($nonce, 'abj404_mode_toggle')) {
            wp_send_json_error(array('message' => __('Invalid security token', '404-solution')));
            exit;
        }

        // Verify user has appropriate capabilities
        if (!$abj404logic->userIsPluginAdmin()) {
            wp_send_json_error(array('message' => __('Unauthorized', '404-solution')));
            exit;
        }

        // Validate mode
        if ($mode !== 'simple' && $mode !== 'advanced') {
            wp_send_json_error(array('message' => __('Invalid mode', '404-solution')));
            exit;
        }

        // Set the mode
        $result = $abj404logic->setSettingsMode($mode);

        if ($result !== false) {
            wp_send_json_success(array(
                'mode' => $mode,
                'message' => __('Settings mode updated', '404-solution')
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to update settings mode', '404-solution')));
        }

        exit;
    }
}
