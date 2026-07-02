<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers WordPress lifecycle, admin, AJAX, and async suggestion hooks.
 */
class ABJ_404_Solution_WordPressHookRegistrar {

    /**
     * @param array<string, callable-string> $callbacks
     * @return void
     */
    public static function registerAll(array $callbacks): void {
        self::registerLifecycleHooks();
        self::registerAdminHooks($callbacks);
        self::registerAsyncSuggestionHooks($callbacks);
        ABJ_404_Solution_PluginLogicLifecycle::doRegisterCrons();
    }

    /** @return void */
    private static function registerLifecycleHooks(): void {
        if (!is_admin()) {
            return;
        }

        register_deactivation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogicLifecycle::runOnPluginDeactivation');
        register_activation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogicLifecycle::runOnPluginActivation');

        if (is_multisite()) {
            add_action('wpmu_new_blog', 'ABJ_404_Solution_PluginLogicLifecycle::activateNewSite', 10, 6);
            add_action('wp_initialize_site', 'ABJ_404_Solution_PluginLogicLifecycle::activateNewSiteModern', 10, 2);
            add_action('delete_blog', 'ABJ_404_Solution_PluginLogicLifecycle::deleteBlogData', 10, 2);
        }
    }

    /**
     * @param array<string, callable-string> $callbacks
     * @return void
     */
    private static function registerAdminHooks(array $callbacks): void {
        if (!is_admin()) {
            return;
        }

        add_filter("plugin_action_links_" . ABJ404_NAME,
            self::callback($callbacks, 'settings_link'));
        add_filter('plugin_row_meta',
            self::callback($callbacks, 'plugin_row_meta'), 10, 2);
        add_action('admin_notices',
            self::callback($callbacks, 'review_notice'));
        add_action('admin_init',
            self::callback($callbacks, 'review_redirects'));
        add_action('admin_menu',
            self::callback($callbacks, 'settings_page'));
        add_action('admin_enqueue_scripts',
            self::callback($callbacks, 'admin_assets'), 11);
        add_action('admin_enqueue_scripts',
            self::callback($callbacks, 'plugins_page_assets'), 11);
        add_action('admin_head',
            self::callback($callbacks, 'admin_theme_css'), 1);

        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_echoViewLogsFor', self::callback($callbacks, 'ajax_view_logs'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_trashLink', self::callback($callbacks, 'ajax_trash_link'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_echoRedirectToPages', self::callback($callbacks, 'ajax_redirect_to_pages'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_updateOptions', self::callback($callbacks, 'ajax_update_options'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_load_gsc_section', self::callback($callbacks, 'ajax_load_gsc_section'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404getTrendData', self::callback($callbacks, 'ajax_trend_data'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_crossPluginPreview', self::callback($callbacks, 'ajax_cross_plugin_preview'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_gsc_oauth_callback', self::callback($callbacks, 'ajax_gsc_oauth_callback'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_gsc_revoke', self::callback($callbacks, 'ajax_gsc_revoke'));

        ABJ_404_Solution_Ajax_EngineProfiles::registerActions();
        ABJ_404_Solution_Ajax_SettingsModeToggle::init();
        ABJ_404_Solution_Ajax_RestoreDefaults::init();
        ABJ_404_Solution_Ajax_PrivacyExport::init();
        ABJ_404_Solution_Ajax_PrivacyDelete::init();
        ABJ_404_Solution_Ajax_SupportRequest::init();
        ABJ_404_Solution_Ajax_SupportRequestPreview::init();
        ABJ_404_Solution_UninstallModal::init();
        ABJ_404_Solution_SetupWizard::init();
        if (class_exists('ABJ_404_Solution_Privacy')) {
            ABJ_404_Solution_Privacy::init();
        }
    }

    /**
     * @param array<string, callable-string> $callbacks
     * @return void
     */
    private static function registerAsyncSuggestionHooks(array $callbacks): void {
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_compute_suggestions', self::callback($callbacks, 'ajax_compute_suggestions'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_nopriv_abj404_compute_suggestions', self::callback($callbacks, 'ajax_compute_suggestions'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_poll_suggestions', self::callback($callbacks, 'ajax_poll_suggestions'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_nopriv_abj404_poll_suggestions', self::callback($callbacks, 'ajax_poll_suggestions'));
    }

    /**
     * @param array<string, callable-string> $callbacks
     * @return callable-string
     */
    private static function callback(array $callbacks, string $key): string {
        return $callbacks[$key];
    }
}
