<?php

/**
 * Handles the uninstall modal popup display and AJAX functionality
 * Completely separate from existing plugin code
 *
 * @since 2.36.11
 */
class ABJ_404_Solution_UninstallModal {

    /**
     * Initialize the uninstall modal functionality
     */
    public static function init() {
        // Enqueue assets only on plugins.php page
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueueAssets'));

        // Register AJAX handler for saving preferences
        add_action('wp_ajax_abj404_save_uninstall_prefs', array(__CLASS__, 'handleAjaxSavePreferences'));
    }

    /**
     * Enqueue modal assets (JavaScript, CSS) on plugins.php page
     *
     * @param string $hook Current admin page hook
     */
    public static function enqueueAssets($hook) {
        // Only load on plugins.php page
        if ($hook !== 'plugins.php') {
            return;
        }

        // Only for administrators who can manage plugins
        if (!current_user_can('activate_plugins')) {
            return;
        }

        // Enqueue jQuery UI Dialog (WordPress core)
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');

        // Enqueue custom JavaScript
        wp_enqueue_script(
            'abj404-uninstall-modal',
            plugin_dir_url(ABJ404_FILE) . 'includes/js/uninstall-modal.js',
            array('jquery', 'jquery-ui-dialog'),
            '1.0.0',
            true
        );

        // Get redirect count for display in modal
        $redirectCount = self::getRedirectCount();

        // Pass data to JavaScript
        wp_localize_script('abj404-uninstall-modal', 'abj404UninstallModal', array(
            'nonce' => wp_create_nonce('abj404_uninstall_nonce'),
            'pluginSlug' => self::getPluginSlug(),
            'redirectCount' => $redirectCount
        ));

        // Custom CSS for modal styling
        wp_add_inline_style('wp-jquery-ui-dialog', '
            .abj404-uninstall-dialog .ui-dialog-titlebar {
                background: #d63638;
                color: white;
            }
            .abj404-uninstall-dialog .ui-dialog-titlebar-close {
                color: white;
            }
            .abj404-uninstall-dialog .ui-dialog-titlebar-close:hover {
                background: #b32d2e;
            }
            .abj404-uninstall-dialog .button-danger {
                background: #d63638;
                border-color: #d63638;
                color: white;
            }
            .abj404-uninstall-dialog .button-danger:hover {
                background: #b32d2e;
                border-color: #b32d2e;
            }
            .abj404-uninstall-content label {
                display: block;
                margin: 15px 0;
                cursor: pointer;
            }
            .abj404-uninstall-content label input[type="checkbox"],
            .abj404-uninstall-content label input[type="radio"] {
                margin-right: 8px;
            }
            .abj404-uninstall-content .description {
                margin: 5px 0 0 25px;
                color: #646970;
                font-size: 13px;
            }
            .abj404-uninstall-content h3 {
                margin-top: 20px;
                margin-bottom: 10px;
                border-bottom: 1px solid #dcdcde;
                padding-bottom: 8px;
            }
            .abj404-uninstall-content h3:first-child {
                margin-top: 0;
            }
            .abj404-uninstall-reasons {
                margin-left: 25px;
            }
            .abj404-uninstall-reasons label {
                margin: 8px 0;
            }
            #abj404-feedback-fields {
                margin-top: 10px;
                padding: 15px;
                background: #f6f7f7;
                border-radius: 4px;
            }
            #abj404-feedback-fields input,
            #abj404-feedback-fields textarea {
                margin-top: 8px;
            }
        ');

        // Output modal HTML in footer
        add_action('admin_footer', array(__CLASS__, 'outputModalHTML'));
    }

    /**
     * Output the modal HTML structure
     */
    public static function outputModalHTML() {
        $redirectCount = self::getRedirectCount();

        ?>
        <div id="abj404-uninstall-modal" class="hidden" style="max-width:600px">
            <div class="abj404-uninstall-content">
                <!-- Warning Box -->
                <div class="notice notice-warning inline" style="margin: 0 0 15px 0;">
                    <p>
                        <strong>⚠️ <?php _e('Warning:', '404-solution'); ?></strong>
                        <?php _e('This will permanently delete the plugin files. You can choose to keep or remove your data below.', '404-solution'); ?>
                    </p>
                </div>

                <!-- Data Deletion Options -->
                <h3><?php _e('What should happen to your data?', '404-solution'); ?></h3>

                <label>
                    <input type="checkbox" id="abj404-keep-redirects" checked>
                    <strong><?php printf(__('Keep my redirects (%d redirects)', '404-solution'), $redirectCount); ?></strong>
                    <p class="description">
                        <?php _e('Check this if you plan to reinstall the plugin later. Your redirect configurations will be preserved.', '404-solution'); ?>
                    </p>
                </label>

                <label>
                    <input type="checkbox" id="abj404-keep-logs" checked>
                    <strong><?php _e('Keep 404 logs', '404-solution'); ?></strong>
                    <p class="description">
                        <?php _e('Historical 404 data and logs will be preserved if checked.', '404-solution'); ?>
                    </p>
                </label>

                <p class="description" style="margin-left: 0; font-style: italic;">
                    <?php _e('Note: Cache tables will always be deleted as they can be rebuilt.', '404-solution'); ?>
                </p>

                <!-- Uninstall Reason -->
                <h3><?php _e('Why are you uninstalling? (Optional)', '404-solution'); ?></h3>

                <div class="abj404-uninstall-reasons">
                    <label>
                        <input type="radio" name="abj404-reason" value="temporary">
                        <?php _e('Temporary deactivation for debugging', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="radio" name="abj404-reason" value="not-working">
                        <?php _e('The plugin is not working as expected', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="radio" name="abj404-reason" value="found-better">
                        <?php _e('I found a better plugin', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="radio" name="abj404-reason" value="no-longer-needed">
                        <?php _e('I no longer need this functionality', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="radio" name="abj404-reason" value="too-complicated">
                        <?php _e('Too complicated to configure', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="radio" name="abj404-reason" value="performance">
                        <?php _e('Performance issues', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="radio" name="abj404-reason" value="other">
                        <?php _e('Other reason', '404-solution'); ?>
                    </label>
                </div>

                <!-- Feedback Section -->
                <h3><?php _e('Help us improve (Optional)', '404-solution'); ?></h3>

                <label>
                    <input type="checkbox" id="abj404-send-feedback">
                    <strong><?php _e('Send feedback to help improve the plugin', '404-solution'); ?></strong>
                </label>

                <div id="abj404-feedback-fields" style="display:none;">
                    <label for="abj404-feedback-email">
                        <?php _e('Your email (optional):', '404-solution'); ?>
                    </label>
                    <input
                        type="email"
                        id="abj404-feedback-email"
                        placeholder="<?php _e('your@email.com (optional)', '404-solution'); ?>"
                        class="widefat"
                    >

                    <label for="abj404-feedback-details" style="margin-top: 10px;">
                        <?php _e('Additional details:', '404-solution'); ?>
                    </label>
                    <textarea
                        id="abj404-feedback-details"
                        rows="3"
                        class="widefat"
                        placeholder="<?php _e('Please share any additional feedback...', '404-solution'); ?>"
                    ></textarea>

                    <p class="description" style="margin-left: 0; margin-top: 8px;">
                        <?php _e('Your feedback helps us improve. System information (WordPress version, PHP version, installed plugins) will be included. We respect your privacy and will not share your information.', '404-solution'); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle AJAX request to save uninstall preferences
     */
    public static function handleAjaxSavePreferences() {
        // Security: Verify nonce
        check_ajax_referer('abj404_uninstall_nonce', 'nonce');

        // Security: Check user capabilities
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', '404-solution')));
            return;
        }

        // Get preferences from AJAX request
        $preferences = array(
            'delete_redirects' => isset($_POST['delete_redirects']) && $_POST['delete_redirects'] === 'true',
            'delete_logs' => isset($_POST['delete_logs']) && $_POST['delete_logs'] === 'true',
            'delete_cache' => true, // Always delete cache tables
            'send_feedback' => isset($_POST['send_feedback']) && $_POST['send_feedback'] === 'true',
            'uninstall_reason' => isset($_POST['uninstall_reason']) ? sanitize_text_field($_POST['uninstall_reason']) : '',
            'feedback_email' => isset($_POST['feedback_email']) ? sanitize_email($_POST['feedback_email']) : '',
            'feedback_details' => isset($_POST['feedback_details']) ? sanitize_textarea_field($_POST['feedback_details']) : ''
        );

        // Save preferences using site options for multisite compatibility
        // In multisite, use site_option for network-activated plugins, regular option for single-site
        $option_name = 'abj404_uninstall_preferences';

        if (is_multisite() && self::isNetworkActivated()) {
            // Network-activated: Use site option (accessible across all sites)
            $saved = update_site_option($option_name, $preferences);
        } else {
            // Single-site or site-specific activation: Use regular option
            $saved = update_option($option_name, $preferences, false); // autoload=false
        }

        if ($saved !== false) {
            wp_send_json_success(array('message' => __('Preferences saved successfully', '404-solution')));
        } else {
            wp_send_json_error(array('message' => __('Failed to save preferences', '404-solution')));
        }
    }

    /**
     * Check if plugin is network-activated
     *
     * @return bool True if network-activated, false otherwise
     */
    private static function isNetworkActivated() {
        if (!is_multisite()) {
            return false;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename(ABJ404_FILE));
    }

    /**
     * Get the plugin slug for JavaScript
     *
     * @return string Plugin directory slug
     */
    private static function getPluginSlug() {
        // Get plugin directory name from plugin file path
        $pluginPath = plugin_basename(ABJ404_FILE);
        $parts = explode('/', $pluginPath);
        return $parts[0];
    }

    /**
     * Get the count of redirects for display
     *
     * @return int Number of redirects
     */
    private static function getRedirectCount() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'abj404_redirects';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if (!$table_exists) {
            return 0;
        }

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status != " . ABJ404_STATUS_TRASH);

        return $count ? intval($count) : 0;
    }
}
