<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for restoring plugin settings to canonical defaults.
 *
 * Entry point: wp_ajax_abj404_restore_defaults.
 * Gates: valid nonce ('abj404_restore_defaults') AND plugin admin capability.
 * Action: writes PluginLogic::getDefaultOptions() through PluginLogic::updateOptions(),
 * preserving the DB_VERSION key so a settings restore does not trigger a schema downgrade.
 */
class ABJ_404_Solution_Ajax_RestoreDefaults {

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
            self::$instance = new ABJ_404_Solution_Ajax_RestoreDefaults();
        }
        return self::$instance;
    }

    /**
     * Initialize AJAX handler.
     * @return void
     */
    static function init(): void {
        $me = ABJ_404_Solution_Ajax_RestoreDefaults::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_restore_defaults',
            array($me, 'handleRestoreDefaults'));
    }

    /**
     * Handle the restore-defaults AJAX request. Verifies nonce + admin
     * capability, then overwrites abj404_settings with getDefaultOptions().
     * DB_VERSION is preserved from the current settings so a settings reset
     * does not look like a schema downgrade to DatabaseUpgradesEtc.
     * @return void
     */
    function handleRestoreDefaults(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-restore-defaults')) {
            return;
        }

        abj_service('ajax_security_gate')->requireAdminWithNonce('abj404_restore_defaults');

        $abj404logic = abj_service('plugin_logic');

        $defaults = ABJ_404_Solution_PluginLogicDefaults::defaults();

        // Preserve DB_VERSION so the restore does not appear as a schema
        // downgrade to the upgrade engine.
        $current = abj_service('options_repository')->getOptions(true);
        if (is_array($current) && array_key_exists('DB_VERSION', $current)) {
            $defaults['DB_VERSION'] = $current['DB_VERSION'];
        }

        abj_service('options_repository')->updateOptions($defaults);

        wp_send_json_success(array(
            'message' => __('Settings restored to defaults.', '404-solution'),
        ));
    }
}
