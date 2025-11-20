<?php

/**
 * Handles the deactivation modal popup display and AJAX functionality
 * Shows options before plugin deactivation to preserve user data
 *
 * @since 2.36.11
 */
class ABJ_404_Solution_UninstallModal {

    /**
     * Initialize the deactivation modal functionality
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
                        <strong>⚠️ <?php _e('Before deactivating:', '404-solution'); ?></strong>
                        <?php _e('Choose what should happen to your data. These preferences will be saved for later if you decide to delete the plugin.', '404-solution'); ?>
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

                <!-- Deactivation Reason -->
                <h3><?php _e('Why are you deactivating? (Optional)', '404-solution'); ?></h3>

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
        // Use filter_var to properly handle boolean values sent from JavaScript
        $preferences = array(
            'delete_redirects' => isset($_POST['delete_redirects']) && filter_var($_POST['delete_redirects'], FILTER_VALIDATE_BOOLEAN),
            'delete_logs' => isset($_POST['delete_logs']) && filter_var($_POST['delete_logs'], FILTER_VALIDATE_BOOLEAN),
            'delete_cache' => true, // Always delete cache tables
            'send_feedback' => isset($_POST['send_feedback']) && filter_var($_POST['send_feedback'], FILTER_VALIDATE_BOOLEAN),
            'uninstall_reason' => isset($_POST['uninstall_reason']) ? sanitize_text_field($_POST['uninstall_reason']) : '',
            'feedback_email' => isset($_POST['feedback_email']) ? sanitize_email($_POST['feedback_email']) : '',
            'feedback_details' => isset($_POST['feedback_details']) ? sanitize_textarea_field($_POST['feedback_details']) : ''
        );

        // Debug logging
        error_log('404 Solution: AJAX handler received deactivation preferences');
        error_log('404 Solution: Raw POST send_feedback = ' . (isset($_POST['send_feedback']) ? $_POST['send_feedback'] : 'NOT SET'));
        error_log('404 Solution: Parsed send_feedback = ' . ($preferences['send_feedback'] ? 'true' : 'false'));
        error_log('404 Solution: Parsed preferences: ' . print_r($preferences, true));

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

        // Send feedback email if user provided any feedback
        // Send if: (radio button selected) OR (send feedback checkbox checked AND has details text)
        $has_reason = !empty($preferences['uninstall_reason']);
        $has_feedback_text = $preferences['send_feedback'] && !empty($preferences['feedback_details']);
        $should_send_email = $has_reason || $has_feedback_text;

        error_log('404 Solution: has_reason=' . ($has_reason ? 'true' : 'false') . ', has_feedback_text=' . ($has_feedback_text ? 'true' : 'false') . ', should_send_email=' . ($should_send_email ? 'true' : 'false'));

        $email_sent = false;
        if ($saved !== false && $should_send_email) {
            $email_sent = self::sendFeedbackEmail($preferences);
        }

        if ($saved !== false) {
            $message = __('Preferences saved successfully', '404-solution');
            if ($should_send_email) {
                $message .= $email_sent
                    ? ' ' . __('Feedback sent successfully.', '404-solution')
                    : ' ' . __('Note: Feedback email could not be sent.', '404-solution');
            }
            wp_send_json_success(array('message' => $message));
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

    /**
     * Send feedback email to plugin author
     *
     * @param array $preferences User preferences including feedback
     * @return bool True if email sent successfully, false otherwise
     */
    private static function sendFeedbackEmail($preferences) {
        // Get site information
        global $wp_version;

        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');

        // Get plugin information
        $redirect_count = self::getRedirectCount();

        // Gather system information (excluding site URL for privacy)
        $system_info = array(
            'WordPress Version' => $wp_version,
            'PHP Version' => phpversion(),
            'Plugin Version' => defined('ABJ404_VERSION') ? ABJ404_VERSION : 'Unknown',
            'Multisite' => is_multisite() ? 'Yes' : 'No',
            'Active Plugins' => self::getActivePluginsList(),
            'Redirect Count' => $redirect_count
        );

        // Build email subject
        $subject = sprintf('[404 Solution] Deactivation Feedback from %s', $site_name);

        // Build email body
        $body = "Deactivation feedback received:\n\n";
        $body .= "═══════════════════════════════════════\n";
        $body .= "USER FEEDBACK\n";
        $body .= "═══════════════════════════════════════\n\n";

        if (!empty($preferences['uninstall_reason'])) {
            $body .= "Reason: " . ucfirst(str_replace('_', ' ', $preferences['uninstall_reason'])) . "\n\n";
        }

        if (!empty($preferences['feedback_details'])) {
            $body .= "Details:\n" . $preferences['feedback_details'] . "\n\n";
        }

        if (!empty($preferences['feedback_email'])) {
            $body .= "User Email: " . $preferences['feedback_email'] . "\n\n";
        }

        $body .= "═══════════════════════════════════════\n";
        $body .= "SYSTEM INFORMATION\n";
        $body .= "═══════════════════════════════════════\n\n";

        foreach ($system_info as $label => $value) {
            $body .= sprintf("%-20s: %s\n", $label, $value);
        }

        $body .= "\n═══════════════════════════════════════\n";
        $body .= "This feedback was sent automatically when the user deactivated the plugin.\n";

        // Set email headers
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        );

        // Add reply-to if user provided email
        if (!empty($preferences['feedback_email'])) {
            $headers[] = 'Reply-To: ' . $preferences['feedback_email'];
        }

        // Send email to plugin author
        $to = defined('ABJ404_AUTHOR_EMAIL') ? ABJ404_AUTHOR_EMAIL : '404solution@ajexperience.com';

        // Log email attempt
        error_log('404 Solution: Attempting to send feedback email to ' . $to);
        error_log('404 Solution: Email subject: ' . $subject);
        error_log('404 Solution: Feedback checkbox was checked: send_feedback=' . ($preferences['send_feedback'] ? 'true' : 'false'));

        // Hook to log wp_mail failures
        add_action('wp_mail_failed', function($error) {
            error_log('404 Solution: wp_mail() FAILED - ' . $error->get_error_message());
        });

        $result = wp_mail($to, $subject, $body, $headers);

        // Log result
        if ($result) {
            error_log('404 Solution: wp_mail() returned TRUE - email sent successfully');
        } else {
            error_log('404 Solution: wp_mail() returned FALSE - email send failed');
        }

        return $result;
    }

    /**
     * Get list of active plugins
     *
     * @return string Comma-separated list of active plugin names
     */
    private static function getActivePluginsList() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());

        $active_plugin_names = array();
        foreach ($active_plugins as $plugin_path) {
            if (isset($all_plugins[$plugin_path])) {
                $active_plugin_names[] = $all_plugins[$plugin_path]['Name'];
            }
        }

        return !empty($active_plugin_names)
            ? implode(', ', array_slice($active_plugin_names, 0, 10)) . (count($active_plugin_names) > 10 ? '...' : '')
            : 'None';
    }
}
