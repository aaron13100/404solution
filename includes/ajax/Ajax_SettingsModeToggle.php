<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for toggling between Simple and Advanced settings modes.
 */

class ABJ_404_Solution_Ajax_SettingsModeToggle {
    use ABJ_404_Solution_AjaxSecurityTrait;

    /** @var self|null */
    private static $instance = null;

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
        self::requireAdminWithNonce('abj404_mode_toggle');

        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        $mode = $abj404dao->getPostOrGetSanitize('mode');

        // Validate mode
        if ($mode !== 'simple' && $mode !== 'advanced') {
            wp_send_json_error(array('message' => __('Invalid mode', '404-solution')), 400);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Set the mode
        $result = $abj404logic->setSettingsMode($mode);

        if ($result !== false) {
            wp_send_json_success(array(
                'mode' => $mode,
                'message' => __('Settings mode updated', '404-solution')
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to update settings mode', '404-solution')), 500);
        }
    }
}
