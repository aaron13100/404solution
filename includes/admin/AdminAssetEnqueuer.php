<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueues admin scripts, styles, and support-request bootstrap assets.
 */
class ABJ_404_Solution_AdminAssetEnqueuer {

    /**
     * Include things necessary for ajax.
     *
     * @param string $hook
     * @param callable(string, Throwable): void $errorReporter
     * @return void
     */
    public static function addScripts($hook, callable $errorReporter): void {
        // only load this stuff for this plugin.
        // thanks to https://pippinsplugins.com/loading-scripts-correctly-in-the-wordpress-admin/
        if (!array_key_exists('abj404_settingsPageName', $GLOBALS) ||
                $hook != $GLOBALS['abj404_settingsPageName']) {
            return;
        }

        try {
            $includesUrl = ABJ404_URL . 'includes/';
            $subpage = '';
            if (array_key_exists('subpage', $_GET)) {
                $subpage = sanitize_text_field(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($_GET['subpage']));
            }
            // Default plugin landing is redirects when subpage is not specified.
            if ($subpage === '') {
                $subpage = 'abj404_redirects';
            }

            $isOptionsPage = ($subpage === 'abj404_options');
            $isStatsPage = ($subpage === 'abj404_stats');
            $isToolsPage = ($subpage === 'abj404_tools');
            $isCardAccordionPage = in_array($subpage, array('abj404_options', 'abj404_tools', 'abj404_stats'), true);
            $isLogsPage = ($subpage === 'abj404_logs');
            $isListPage = in_array($subpage, array('abj404_redirects', 'abj404_captured', 'abj404_logs'), true);
            $isEditPage = ($subpage === 'abj404_edit');
            $needsDestinationAutocomplete = in_array($subpage, array('abj404_redirects', 'abj404_captured', 'abj404_options', 'abj404_edit'), true);

            // remove the "thank you for creating with wordpress" message
            add_filter('admin_footer_text',
                'ABJ_404_Solution_WordPress_Connector::remove_admin_footer_text');
            // remove the version number message
            add_filter('update_footer',
                'ABJ_404_Solution_WordPress_Connector::remove_admin_footer_text', 11);

            // jquery is used for the searchable dropdown list of pages for adding a redirect and other things.
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery');
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery-ui-autocomplete');
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery-effects-core');
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery-effects-highlight');
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery-color');

            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-admin-ajax',
                ABJ404_URL . 'includes/js/abj404-admin-ajax.js', array('jquery'));

            wp_register_script('abj404-redirect_to_ajax', $includesUrl . 'ajax/redirect_to_ajax.js',
                    array('jquery', 'jquery-ui-autocomplete'));
            wp_register_script('abj404-exclude_pages_ajax', $includesUrl . 'ajax/exclude_pages_ajax.js',
                array('jquery', 'jquery-ui-autocomplete', 'abj404-redirect_to_ajax'));
            $translation_array = array(
                'type_a_page_name' => __('(Type a page name or an external URL)', '404-solution'),
                'a_page_has_been_selected' => __('(A page has been selected.)', '404-solution'),
                'an_external_url_will_be_used' => __('(An external URL will be used.)', '404-solution')
            );
            wp_localize_script('abj404-redirect_to_ajax', 'abj404localization', $translation_array );
            if ($needsDestinationAutocomplete) {
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-redirect_to_ajax');
                wp_localize_script('abj404-exclude_pages_ajax', 'abj404localization', $translation_array );
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-exclude_pages_ajax');
            }

            wp_register_script('abj404-enable_disable_apply_button_js',
                    ABJ404_URL . 'includes/js/enableDisableApplyButton.js');
            $translation_array = array('{altText}' => __('Choose at least one URL', '404-solution'));
            wp_localize_script('abj404-enable_disable_apply_button_js', 'abj404localization', $translation_array);
            if ($isListPage) {
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-enable_disable_apply_button_js');
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-trash_link_ajax', $includesUrl . 'ajax/trash_link_ajax.js',
                        array('jquery'));
            }
            if ($isListPage || $isEditPage) {
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-table-interactions', $includesUrl . 'js/tableInteractions.js',
                        array('jquery'));

                wp_localize_script('abj404-table-interactions', 'abj404_time_ago', array(
                    'second'  => __('second', '404-solution'),
                    'seconds' => __('seconds', '404-solution'),
                    'minute'  => __('minute', '404-solution'),
                    'minutes' => __('minutes', '404-solution'),
                    'hour'    => __('hour', '404-solution'),
                    'hours'   => __('hours', '404-solution'),
                    'day'     => __('day', '404-solution'),
                    'days'    => __('days', '404-solution'),
                    'ago'     => __('ago', '404-solution'),
                ));
            }

            if ($isListPage || $isStatsPage) {
                self::enqueueViewUpdaterModules($includesUrl . 'ajax/');
            }

            if ($isStatsPage) {
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-stats-confidence-chart',
                    ABJ404_URL . 'includes/js/statsConfidenceChart.js', array());
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-stats-trends',
                    ABJ404_URL . 'includes/js/statsTrends.js', array());
            }

            if ($isToolsPage) {
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-tools-migrate-plugin',
                    ABJ404_URL . 'includes/js/toolsMigratePlugin.js', array());
            }

            if ($isLogsPage) {
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-search_logs_ajax', $includesUrl . 'ajax/search_logs_ajax.js',
                    array('jquery', 'jquery-ui-autocomplete'));
            }

            if ($isOptionsPage) {
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-general-js', $includesUrl . 'js/general.js',
                    array('jquery'));
                wp_localize_script('abj404-general-js', 'abj404General', array(
                    'savingSettings' => __('Saving settings...', '404-solution'),
                ));
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-theme-preview', $includesUrl . 'js/themePreview.js',
                    array('jquery'));
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-settings-mode-toggle', $includesUrl . 'ajax/SettingsModeToggle.js',
                    array('jquery'));
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-restore-defaults', $includesUrl . 'ajax/RestoreDefaults.js',
                    array('jquery'));
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-behavior-tiles', ABJ404_URL . 'includes/js/behaviorTiles.js',
                    array());
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-settings-deferred', ABJ404_URL . 'includes/js/settingsDeferred.js',
                    array('jquery'));
            }

            if ($isCardAccordionPage) {
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-options-accordion', $includesUrl . 'js/optionsAccordion.js',
                    array('jquery'));
                wp_localize_script('abj404-options-accordion', 'abj404Accordion', array(
                    'expandAll' => __('Expand All', '404-solution'),
                    'collapseAll' => __('Collapse All', '404-solution'),
                ));
            }

            if ($isOptionsPage) {
                ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-engine-profiles',
                    $includesUrl . 'ajax/ajax-engine-profiles.js',
                    array('jquery'));
                wp_localize_script('abj404-engine-profiles', 'abj404EngineProfiles', array(
                    'nonce'   => wp_create_nonce('abj404_engine_profiles_nonce'),
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'i18n'    => array(
                        'edit'            => __('Edit', '404-solution'),
                        'delete'          => __('Delete', '404-solution'),
                        'addProfile'      => __('Add Engine Profile', '404-solution'),
                        'editProfile'     => __('Edit Engine Profile', '404-solution'),
                        'nameRequired'    => __('Profile name is required.', '404-solution'),
                        'patternRequired' => __('URL pattern is required.', '404-solution'),
                        'saved'           => __('Profile saved.', '404-solution'),
                        'saveFailed'      => __('Failed to save profile.', '404-solution'),
                        'confirmDelete'   => __('Delete this engine profile?', '404-solution'),
                    ),
                ));
            }

            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt(
                'abj404-review-feedback',
                $includesUrl . 'js/reviewFeedback.js',
                array()
            );

            self::registerSupportRequestAssets();

            ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-styles', ABJ404_URL . 'includes/html/404solutionStyles.css',
                    array());
            ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-themes', ABJ404_URL . 'includes/html/adminThemes.css',
                    array());

            if (is_rtl()) {
                ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-rtl', ABJ404_URL . 'includes/html/404solutionStyles-rtl.css',
                        array('abj404solution-styles'));
            }
        } catch (Throwable $e) {
            call_user_func($errorReporter, 'admin_enqueue_scripts', $e);
        }
    }

    /**
     * Enqueue the support-request button assets specifically for wp-admin/plugins.php.
     *
     * @param string $hook
     * @param callable(string, Throwable): void $errorReporter
     * @return void
     */
    public static function enqueueSupportRequestAssetsOnPluginsPage($hook, callable $errorReporter): void {
        if ($hook !== 'plugins.php') {
            return;
        }
        try {
            self::registerSupportRequestAssets();
        } catch (Throwable $e) {
            call_user_func($errorReporter, 'admin_enqueue_scripts:plugins.php', $e);
        }
    }

    /**
     * @param string $vuBase URL prefix for the ajax/ assets directory.
     * @return void
     */
    private static function enqueueViewUpdaterModules(string $vuBase): void {
        $enq = array('ABJ_404_Solution_WPUtils', 'my_wp_enq_scrpt');
        $enq('abj404-view-updater-nonce-refresh',
            $vuBase . 'view_updater_nonce_refresh.js', array('jquery'));
        $enq('abj404-view-updater-stage-diagnostics',
            $vuBase . 'view_updater_stage_diagnostics.js', array('jquery'));
        $enq('abj404-view-updater-stage-progress',
            $vuBase . 'view_updater_stage_progress.js',
            array('jquery', 'abj404-view-updater-stage-diagnostics',
                'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-compare',
            $vuBase . 'view_updater_compare.js', array('jquery'));
        $enq('abj404-view-updater-toast',
            $vuBase . 'view_updater_toast.js', array('jquery'));
        $enq('abj404-view-updater-stats', $vuBase . 'view_updater_stats.js',
            array('jquery', 'abj404-view-updater-toast', 'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-build-advance', $vuBase . 'view_updater_build_advance.js',
            array('jquery', 'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-shared-build-state',
            $vuBase . 'view_updater_shared_build_state.js', array('jquery'));
        $enq('abj404-view-updater-table-init', $vuBase . 'view_updater_table_init.js',
            array('jquery', 'abj404-view-updater-toast', 'abj404-view-updater-stats',
                'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-table-warmup', $vuBase . 'view_updater_table_warmup.js',
            array('jquery', 'abj404-view-updater-stage-diagnostics',
                'abj404-view-updater-stage-progress',
                'abj404-view-updater-build-advance',
                'abj404-view-updater-shared-build-state',
                'abj404-view-updater-table-init',
                'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-pagination-request',
            $vuBase . 'view_updater_pagination_request.js',
            array('jquery', 'abj404-view-updater-compare'));
        $enq('abj404-view-updater-pagination-response-apply',
            $vuBase . 'view_updater_pagination_response_apply.js',
            array('jquery', 'abj404-view-updater-table-init'));
        $enq('abj404-view-updater-pagination-error-notice',
            $vuBase . 'view_updater_pagination_error_notice.js',
            array('jquery', 'abj404-view-updater-stage-diagnostics',
                'abj404-view-updater-table-init', 'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-pagination', $vuBase . 'view_updater_pagination.js',
            array('jquery', 'abj404-view-updater-compare', 'abj404-view-updater-stage-diagnostics',
                'abj404-view-updater-stage-progress',
                'abj404-view-updater-build-advance', 'abj404-view-updater-table-init',
                'abj404-view-updater-table-warmup', 'abj404-view-updater-toast',
                'abj404-view-updater-nonce-refresh',
                'abj404-view-updater-pagination-request',
                'abj404-view-updater-pagination-response-apply',
                'abj404-view-updater-pagination-error-notice'));
        $enq('abj404-view-updater', $vuBase . 'view_updater.js',
            array('jquery', 'jquery-ui-autocomplete',
                'abj404-view-updater-stage-diagnostics',
                'abj404-view-updater-stage-progress',
                'abj404-view-updater-compare',
                'abj404-view-updater-toast', 'abj404-view-updater-stats',
                'abj404-view-updater-build-advance', 'abj404-view-updater-table-init',
                'abj404-view-updater-table-warmup', 'abj404-view-updater-pagination',
                'abj404-view-updater-nonce-refresh'));
    }

    /** @return void */
    private static function registerSupportRequestAssets(): void {
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-support-request-client',
            ABJ404_URL . 'includes/ajax/SupportRequest.js', array());
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-support-request-transport',
            ABJ404_URL . 'includes/js/support-request-transport.js',
            array('abj404-support-request-client'));
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-support-request-modal-view',
            ABJ404_URL . 'includes/js/support-request-modal-view.js', array());
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-support-request-button',
            ABJ404_URL . 'includes/js/support-request-button.js',
            array('abj404-support-request-client', 'abj404-support-request-transport', 'abj404-support-request-modal-view'));
        if (!function_exists('wp_add_inline_script')) {
            return;
        }
        $supportNonce = wp_create_nonce(ABJ_404_Solution_Ajax_SupportRequest::NONCE_ACTION);
        $previewNonce = wp_create_nonce(ABJ_404_Solution_Ajax_SupportRequestPreview::NONCE_ACTION);
        $ajaxUrl = function_exists('admin_url') ? admin_url('admin-ajax.php') : '/wp-admin/admin-ajax.php';
        $payload = wp_json_encode(array(
            'ajaxurl' => $ajaxUrl,
            'nonces' => array(
                'support_request' => $supportNonce,
                'support_request_preview' => $previewNonce,
            ),
        ));
        $bootstrap = 'window.ABJ404=window.ABJ404||{};Object.assign(window.ABJ404,'
            . (is_string($payload) ? $payload : '{}') . ');';
        wp_add_inline_script('abj404-support-request-client', $bootstrap, 'before');
    }
}
