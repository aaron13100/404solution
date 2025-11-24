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
            'redirectCount' => $redirectCount,
            'i18n' => array(
                'dialogTitle' => __('404 Solution - Deactivation Options', '404-solution'),
                'btnCancel' => __('Cancel', '404-solution'),
                'btnSkipFeedback' => __('Deactivate without feedback', '404-solution'),
                'btnDeactivate' => __('Email Feedback & Deactivate', '404-solution'),
                'btnSaving' => __('Processing...', '404-solution'),
                'btnDeactivating' => __('Deactivating', '404-solution'),
            )
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
                margin: 8px 0;
                cursor: pointer;
            }
            .abj404-uninstall-content label input[type="checkbox"],
            .abj404-uninstall-content label input[type="radio"] {
                margin-right: 8px;
            }
            .abj404-uninstall-content .description {
                margin: 0;
                color: #646970;
                font-size: 12px;
            }
            .abj404-uninstall-content h3 {
                margin-top: 18px;
                margin-bottom: 8px;
                border-bottom: 1px solid #dcdcde;
                padding-bottom: 6px;
                font-size: 14px;
            }
            .abj404-uninstall-content h3:first-child {
                margin-top: 0;
            }
            .abj404-uninstall-reasons {
                margin-left: 25px;
            }
            .abj404-uninstall-reasons label {
                margin: 6px 0;
                font-size: 13px;
            }
            .abj404-followup-section {
                margin: 12px 0 !important;
                padding: 12px !important;
            }
            .abj404-followup-section p {
                margin: 0 0 8px 0 !important;
                font-size: 13px !important;
            }
            .abj404-followup-section label {
                margin: 4px 0 !important;
                font-size: 13px !important;
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
                <!-- Data Deletion Options -->
                <h3 style="margin-top: 0;">
                    ⚠️ <?php _e('Before deactivating, choose what happens to your data:', '404-solution'); ?>
                </h3>

                <label>
                    <input type="checkbox" id="abj404-keep-redirects" checked>
                    <strong><?php printf(__('Keep my redirects (%d)', '404-solution'), $redirectCount); ?></strong>
                    <span class="description" style="display: inline; margin-left: 5px;">
                        <?php _e('— saves them for later if you reinstall', '404-solution'); ?>
                    </span>
                </label>

                <label>
                    <input type="checkbox" id="abj404-keep-logs" checked>
                    <strong><?php _e('Keep 404 logs', '404-solution'); ?></strong>
                    <span class="description" style="display: inline; margin-left: 5px;">
                        <?php _e('— historical data preserved', '404-solution'); ?>
                    </span>
                </label>

                <p class="description" style="margin: 5px 0 0 25px; font-size: 12px;">
                    <?php _e('Cache tables are always deleted (can be rebuilt)', '404-solution'); ?>
                </p>

                <!-- Deactivation Reason -->
                <h3 style="margin-top: 20px;"><?php _e('Help us improve (Optional)', '404-solution'); ?></h3>

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

                <!-- Conditional follow-up sections (shown based on selected reason) -->
                <div id="abj404-followup-not-working" class="abj404-followup-section" style="display:none; background: #f6f7f7; border-radius: 4px; border-left: 3px solid #d63638;">
                    <p style="font-weight: 600;">
                        <?php _e('What specifically isn\'t working?', '404-solution'); ?>
                    </p>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="redirects-not-triggering">
                        <?php _e('Redirects not triggering/working', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="settings-not-saving">
                        <?php _e('Settings not saving', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="admin-errors">
                        <?php _e('Admin pages showing errors', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="suggestions-not-appearing">
                        <?php _e('Suggestions not appearing', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="plugin-conflicts">
                        <?php _e('Conflicts with other plugins', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="other-issue">
                        <?php _e('Other issue (please specify below)', '404-solution'); ?>
                    </label>
                </div>

                <div id="abj404-followup-performance" class="abj404-followup-section" style="display:none; background: #f6f7f7; border-radius: 4px; border-left: 3px solid #d63638;">
                    <p style="font-weight: 600;">
                        <?php _e('What type of performance issue?', '404-solution'); ?>
                    </p>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="slow-admin">
                        <?php _e('Slow admin dashboard', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="slow-frontend">
                        <?php _e('Slow frontend page loads', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="high-database">
                        <?php _e('High database usage', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="memory-issues">
                        <?php _e('Memory issues', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="other-performance">
                        <?php _e('Other (please specify below)', '404-solution'); ?>
                    </label>
                </div>

                <div id="abj404-followup-complicated" class="abj404-followup-section" style="display:none; background: #f6f7f7; border-radius: 4px; border-left: 3px solid #d63638;">
                    <p style="font-weight: 600;">
                        <?php _e('What was confusing?', '404-solution'); ?>
                    </p>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="settings-confusing">
                        <?php _e('Settings are confusing', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="too-many-options">
                        <?php _e('Too many options', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="unclear-docs">
                        <?php _e('Unclear documentation', '404-solution'); ?>
                    </label>
                    <label>
                        <input type="checkbox" class="abj404-issue-checkbox" name="abj404-issue[]" value="other-confusion">
                        <?php _e('Other (please specify below)', '404-solution'); ?>
                    </label>
                </div>

                <!-- Follow-up for "Found a better plugin" -->
                <div id="abj404-followup-better-plugin" class="abj404-followup-section" style="display:none; padding: 0 !important;">
                    <label for="abj404-better-plugin-name" style="margin-bottom: 5px;">
                        <?php _e('Which plugin are you switching to?', '404-solution'); ?>
                    </label>
                    <input
                        type="text"
                        id="abj404-better-plugin-name"
                        class="widefat"
                        placeholder="<?php _e('Plugin name (optional)', '404-solution'); ?>"
                    >
                </div>

                <!-- Follow-up for "Other reason" -->
                <div id="abj404-followup-other" class="abj404-followup-section" style="display:none; padding: 0 !important;">
                    <label for="abj404-other-reason-text" style="margin-bottom: 5px;">
                        <?php _e('Please tell us more (optional):', '404-solution'); ?>
                    </label>
                    <textarea
                        id="abj404-other-reason-text"
                        rows="3"
                        class="widefat"
                        placeholder="<?php _e('What\'s your reason for deactivating?', '404-solution'); ?>"
                    ></textarea>
                </div>

                <!-- Additional details for conditional sections -->
                <div id="abj404-followup-details" class="abj404-followup-section" style="display:none; padding: 0 !important; margin-top: 10px !important;">
                    <label for="abj404-followup-details-text" style="margin-bottom: 5px;">
                        <?php _e('Additional details (optional):', '404-solution'); ?>
                    </label>
                    <textarea
                        id="abj404-followup-details-text"
                        rows="3"
                        class="widefat"
                        placeholder="<?php _e('Any other information about the issue...', '404-solution'); ?>"
                    ></textarea>
                </div>

                <!-- Optional Feedback Email -->
                <div id="abj404-feedback-email-section" style="margin: 15px 0 10px 0;">
                    <label for="abj404-feedback-email" style="display: block; margin-bottom: 5px;">
                        <strong><?php _e('Your email (optional):', '404-solution'); ?></strong>
                    </label>
                    <input
                        type="email"
                        id="abj404-feedback-email"
                        placeholder="<?php _e('For follow-up if needed', '404-solution'); ?>"
                        class="widefat"
                    >
                </div>

                <!-- Technical Details Opt-in -->
                <label style="margin: 10px 0 15px 0; display: block;">
                    <input type="checkbox" id="abj404-include-diagnostics" checked>
                    <?php _e('Include technical details (system info + sanitized log excerpt) to help diagnose the issue', '404-solution'); ?>
                </label>
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
            'delete_redirects' => isset($_POST['delete_redirects']) ? filter_var($_POST['delete_redirects'], FILTER_VALIDATE_BOOLEAN) : false,
            'delete_logs' => isset($_POST['delete_logs']) ? filter_var($_POST['delete_logs'], FILTER_VALIDATE_BOOLEAN) : false,
            'delete_cache' => true, // Always delete cache tables
            'send_feedback' => isset($_POST['send_feedback']) ? filter_var($_POST['send_feedback'], FILTER_VALIDATE_BOOLEAN) : false,
            'uninstall_reason' => isset($_POST['uninstall_reason']) ? sanitize_text_field($_POST['uninstall_reason']) : '',
            'selected_issues' => isset($_POST['selected_issues']) ? sanitize_text_field($_POST['selected_issues']) : '',
            'followup_details' => isset($_POST['followup_details']) ? sanitize_textarea_field($_POST['followup_details']) : '',
            'better_plugin_name' => isset($_POST['better_plugin_name']) ? sanitize_text_field($_POST['better_plugin_name']) : '',
            'other_reason_text' => isset($_POST['other_reason_text']) ? sanitize_textarea_field($_POST['other_reason_text']) : '',
            'feedback_email' => isset($_POST['feedback_email']) ? sanitize_email($_POST['feedback_email']) : '',
            'include_diagnostics' => isset($_POST['include_diagnostics']) ? filter_var($_POST['include_diagnostics'], FILTER_VALIDATE_BOOLEAN) : false
        );

        // Debug logging (only in debug mode to avoid logging PII like email/feedback in production)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('404 Solution: AJAX handler received deactivation preferences');
            error_log('404 Solution: Raw POST send_feedback = ' . (isset($_POST['send_feedback']) ? $_POST['send_feedback'] : 'NOT SET'));
            error_log('404 Solution: Parsed send_feedback = ' . ($preferences['send_feedback'] ? 'true' : 'false'));
            error_log('404 Solution: Parsed preferences: ' . print_r($preferences, true));
        }

        // Save preferences using site options for multisite compatibility
        // In multisite, use site_option for network-activated plugins, regular option for single-site
        $option_name = 'abj404_uninstall_preferences';

        // Capture return value to verify save success
        $save_result = false;
        if (is_multisite() && self::isNetworkActivated()) {
            // Network-activated: Use site option (accessible across all sites)
            $save_result = update_site_option($option_name, $preferences);
        } else {
            // Single-site or site-specific activation: Use regular option
            $save_result = update_option($option_name, $preferences, false); // autoload=false
        }

        // Verify the save was successful (false could mean unchanged OR failure)
        if ($save_result === false) {
            // Read back the option to verify it was actually saved
            $saved_value = is_multisite() && self::isNetworkActivated()
                ? get_site_option($option_name)
                : get_option($option_name);

            // If the saved value doesn't match what we tried to save, it's a real failure
            if ($saved_value !== $preferences) {
                wp_send_json_error(array(
                    'message' => __('Could not save preferences. Your choices may not be preserved.', '404-solution')
                ));
                return;
            }
            // If values match, the false return was just because value was unchanged (which is OK)
        }

        // Send feedback email only if user explicitly opted in
        $should_send_email = $preferences['send_feedback'];

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('404 Solution: send_feedback=' . ($preferences['send_feedback'] ? 'true' : 'false') . ', should_send_email=' . ($should_send_email ? 'true' : 'false'));
        }

        $email_sent = false;
        if ($should_send_email) {
            $email_sent = self::sendFeedbackEmail($preferences);
        }

        // Build success message
        if ($should_send_email) {
            // User sent feedback - show appropriate message
            $message = $email_sent
                ? __('Feedback sent successfully.', '404-solution')
                : __('Feedback could not be sent.', '404-solution');
        } else {
            // User skipped feedback - minimal message (won't be shown anyway due to instant redirect)
            $message = '';
        }

        // Return success (failures are already handled above)
        wp_send_json_success(array('message' => $message));
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
        $dao = ABJ_404_Solution_DataAccess::getInstance();
        $table_name = $dao->getPrefixedTableName('abj404_redirects');

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;

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
            $body .= "Reason: " . ucfirst(str_replace('-', ' ', $preferences['uninstall_reason'])) . "\n\n";
        }

        // Show selected issues (checkboxes)
        if (!empty($preferences['selected_issues'])) {
            $body .= "Specific Issues:\n";
            $issues = explode(',', $preferences['selected_issues']);
            foreach ($issues as $issue) {
                $body .= "  ☑ " . ucfirst(str_replace('-', ' ', $issue)) . "\n";
            }
            $body .= "\n";
        }

        // Show additional details from follow-up textarea
        if (!empty($preferences['followup_details'])) {
            $body .= "Additional Details:\n" . $preferences['followup_details'] . "\n\n";
        }

        // Show better plugin name if provided
        if (!empty($preferences['better_plugin_name'])) {
            $body .= "Switching to: " . $preferences['better_plugin_name'] . "\n\n";
        }

        // Show other reason details if provided
        if (!empty($preferences['other_reason_text'])) {
            $body .= "Other Reason Details:\n" . $preferences['other_reason_text'] . "\n\n";
        }

        if (!empty($preferences['feedback_email'])) {
            $body .= "User Email: " . $preferences['feedback_email'] . "\n\n";
        }

        // Include diagnostics if user opted in
        if (!empty($preferences['include_diagnostics'])) {
            // Plugin debug log excerpt
            $body .= "═══════════════════════════════════════\n";
            $body .= "PLUGIN DEBUG LOG\n";
            $body .= "═══════════════════════════════════════\n\n";

            try {
                $logger = ABJ_404_Solution_Logging::getInstance();
                $logExcerpt = $logger->getSanitizedLogExcerptForSupport();
                $body .= $logExcerpt . "\n\n";
            } catch (Exception $e) {
                $body .= "Unable to retrieve log excerpt\n\n";
            }

            // System information
            $body .= "═══════════════════════════════════════\n";
            $body .= "SYSTEM INFORMATION\n";
            $body .= "═══════════════════════════════════════\n\n";

            foreach ($system_info as $label => $value) {
                $body .= sprintf("%-20s: %s\n", $label, $value);
            }
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

        // Log email attempt (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('404 Solution: Attempting to send feedback email to ' . $to);
            error_log('404 Solution: Email subject: ' . $subject);
            error_log('404 Solution: Feedback checkbox was checked: send_feedback=' . ($preferences['send_feedback'] ? 'true' : 'false'));
        }

        // Hook to log wp_mail failures (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('wp_mail_failed', function($error) {
                error_log('404 Solution: wp_mail() FAILED - ' . $error->get_error_message());
            });
        }

        $result = wp_mail($to, $subject, $body, $headers);

        // Log result (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($result) {
                error_log('404 Solution: wp_mail() returned TRUE - email sent successfully');
            } else {
                error_log('404 Solution: wp_mail() returned FALSE - email send failed');
            }
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
