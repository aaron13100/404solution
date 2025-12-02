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

        // Guard for test environment where DataAccess class may not be loaded
        if (!class_exists('ABJ_404_Solution_DataAccess')) {
            return 0;
        }

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
     * Get comprehensive plugin statistics for diagnostics.
     * Includes redirect counts by type, captured 404s, log entries, and storage sizes.
     *
     * @return array Array with detailed plugin statistics
     */
    private static function getPluginStatistics() {
        $stats = array(
            'redirects' => array('all' => 0, 'manual' => 0, 'auto' => 0, 'regex' => 0, 'trash' => 0),
            'captured' => array('all' => 0, 'captured' => 0, 'ignored' => 0, 'later' => 0, 'trash' => 0),
            'log_count' => 0,
            'log_table_size_mb' => 0,
            'debug_file_size_mb' => 0,
        );

        // Guard for test environment where DataAccess class may not be loaded
        if (!class_exists('ABJ_404_Solution_DataAccess')) {
            return $stats;
        }

        // Additional guard: check if wpdb has the required methods (test environments may use mocks)
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'get_results')) {
            return $stats;
        }

        try {
            $dao = ABJ_404_Solution_DataAccess::getInstance();

            // Get redirect counts by status
            $redirectCounts = $dao->getRedirectStatusCounts(true);
            if (is_array($redirectCounts)) {
                $stats['redirects'] = $redirectCounts;
            }

            // Get captured 404s counts by status
            $capturedCounts = $dao->getCapturedStatusCounts(true);
            if (is_array($capturedCounts)) {
                $stats['captured'] = $capturedCounts;
            }

            // Get log entry count
            $stats['log_count'] = $dao->getLogsCount(0);

            // Get log table size
            $logTableSizeBytes = $dao->getLogDiskUsage();
            if ($logTableSizeBytes > 0) {
                $stats['log_table_size_mb'] = round($logTableSizeBytes / (1024 * 1024), 2);
            }

            // Get debug file size
            if (class_exists('ABJ_404_Solution_Logging')) {
                $logger = ABJ_404_Solution_Logging::getInstance();
                $debugFilePath = $logger->getDebugFilePath();
                if (file_exists($debugFilePath)) {
                    $debugFileSize = filesize($debugFilePath);
                    $stats['debug_file_size_mb'] = round($debugFileSize / (1024 * 1024), 2);
                }
            }
        } catch (Exception $e) {
            // Return defaults if there's any error
        } catch (Error $e) {
            // Also catch PHP Error for method not found, etc.
        }

        return $stats;
    }

    /**
     * Get counts of categories, tags, pages, and posts for diagnostics.
     * These counts help identify if memory issues are caused by large content volume.
     *
     * @return array Array with 'categories', 'tags', 'pages', 'posts' keys
     */
    private static function getContentCounts() {
        $counts = array(
            'categories' => 0,
            'tags' => 0,
            'pages' => 0,
            'posts' => 0,
        );

        // Guard for test environment where WordPress functions may not be available
        if (!function_exists('wp_count_terms') || !function_exists('wp_count_posts')) {
            return $counts;
        }

        // Count categories (includes product_cat for WooCommerce)
        $category_count = wp_count_terms(array('taxonomy' => 'category', 'hide_empty' => false));
        if (!is_wp_error($category_count)) {
            $counts['categories'] = intval($category_count);
        }

        // Also count WooCommerce product categories if they exist
        if (function_exists('taxonomy_exists') && taxonomy_exists('product_cat')) {
            $product_cat_count = wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            if (!is_wp_error($product_cat_count)) {
                $counts['categories'] += intval($product_cat_count);
            }
        }

        // Count tags (includes product_tag for WooCommerce)
        $tag_count = wp_count_terms(array('taxonomy' => 'post_tag', 'hide_empty' => false));
        if (!is_wp_error($tag_count)) {
            $counts['tags'] = intval($tag_count);
        }

        // Also count WooCommerce product tags if they exist
        if (function_exists('taxonomy_exists') && taxonomy_exists('product_tag')) {
            $product_tag_count = wp_count_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
            if (!is_wp_error($product_tag_count)) {
                $counts['tags'] += intval($product_tag_count);
            }
        }

        // Count pages
        $page_counts = wp_count_posts('page');
        if ($page_counts && isset($page_counts->publish)) {
            $counts['pages'] = intval($page_counts->publish);
        }

        // Count posts
        $post_counts = wp_count_posts('post');
        if ($post_counts && isset($post_counts->publish)) {
            $counts['posts'] = intval($post_counts->publish);
        }

        // Also count WooCommerce products if they exist
        if (function_exists('post_type_exists') && post_type_exists('product')) {
            $product_counts = wp_count_posts('product');
            if ($product_counts && isset($product_counts->publish)) {
                $counts['posts'] += intval($product_counts->publish);
            }
        }

        return $counts;
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
        $plugin_stats = self::getPluginStatistics();

        // Gather system information (excluding site URL for privacy)
        $db_info = self::getDatabaseInfo();
        $content_counts = self::getContentCounts();
        $system_info = array(
            'WordPress Version' => $wp_version,
            'PHP Version' => phpversion(),
            'Plugin Version' => defined('ABJ404_VERSION') ? ABJ404_VERSION : 'Unknown',
            'MySQL Version' => $db_info['version'],
            'DB Charset' => $db_info['charset'],
            'DB Collation' => $db_info['collation'],
            'Multisite' => is_multisite() ? 'Yes' : 'No',
            'Active Plugins' => self::getActivePluginsList(),
            'Category Count' => $content_counts['categories'],
            'Tag Count' => $content_counts['tags'],
            'Total Pages' => $content_counts['pages'],
            'Total Posts' => $content_counts['posts'],
            // Redirect counts
            'Redirects (active)' => $plugin_stats['redirects']['all'],
            '  - Manual' => $plugin_stats['redirects']['manual'],
            '  - Automatic' => $plugin_stats['redirects']['auto'],
            '  - Regex' => $plugin_stats['redirects']['regex'],
            '  - Trashed' => $plugin_stats['redirects']['trash'],
            // Captured 404s
            'Captured 404s (active)' => $plugin_stats['captured']['all'],
            '  - New' => $plugin_stats['captured']['captured'],
            '  - Ignored' => $plugin_stats['captured']['ignored'],
            '  - Later' => $plugin_stats['captured']['later'],
            '  - Trash' => $plugin_stats['captured']['trash'],
            // Database stats
            'Log Entries in DB' => $plugin_stats['log_count'],
            'Log Table Size' => $plugin_stats['log_table_size_mb'] . ' MB',
            'Debug File Size' => $plugin_stats['debug_file_size_mb'] . ' MB',
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

            if (class_exists('ABJ_404_Solution_Logging')) {
                try {
                    $logger = ABJ_404_Solution_Logging::getInstance();
                    $logExcerpt = $logger->getSanitizedLogExcerptForSupport();
                    $body .= $logExcerpt . "\n\n";
                } catch (Exception $e) {
                    $body .= "Unable to retrieve log excerpt\n\n";
                }
            } else {
                $body .= "Log excerpt unavailable (logging class not loaded)\n\n";
            }

            // Database collation snapshot to diagnose charset-related issues
            $body .= "═══════════════════════════════════════\n";
            $body .= "DATABASE COLLATIONS\n";
            $body .= "═══════════════════════════════════════\n\n";
            $body .= self::getDatabaseCollationSnapshot() . "\n\n";

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

    /**
     * Get database version and charset info for diagnostics.
     * Uses fallback chain for locked-down hosts.
     *
     * @return array Array with 'version', 'charset', and 'collation' keys
     */
    private static function getDatabaseInfo() {
        global $wpdb;

        $info = array(
            'version' => 'Unknown',
            'charset' => 'Unknown',
            'collation' => 'Unknown',
        );

        // Get MySQL/MariaDB version
        $version = $wpdb->get_var("SELECT VERSION()");
        if ($version) {
            $info['version'] = $version;
        }

        // Get database default charset and collation
        if (!defined('DB_NAME')) {
            // Test environment - use wpdb defaults
            $info['charset'] = $wpdb->charset ?: 'utf8mb4';
            $info['collation'] = $wpdb->collate ?: 'utf8mb4_unicode_ci';
            return $info;
        }

        // Try information_schema.SCHEMATA first
        $db_name = DB_NAME;
        $charset_query = $wpdb->prepare(
            "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME " .
            "FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s",
            $db_name
        );
        $db_result = $wpdb->get_row($charset_query, ARRAY_A);

        if ($db_result && !empty($db_result['DEFAULT_CHARACTER_SET_NAME'])) {
            $info['charset'] = $db_result['DEFAULT_CHARACTER_SET_NAME'];
            $info['collation'] = $db_result['DEFAULT_COLLATION_NAME'] ?? 'Unknown';
            return $info;
        }

        // Fallback: SHOW VARIABLES for character_set_database and collation_database
        $charset_result = $wpdb->get_row("SHOW VARIABLES LIKE 'character_set_database'", ARRAY_A);
        $collation_result = $wpdb->get_row("SHOW VARIABLES LIKE 'collation_database'", ARRAY_A);

        if ($charset_result && isset($charset_result['Value'])) {
            $info['charset'] = $charset_result['Value'];
        }
        if ($collation_result && isset($collation_result['Value'])) {
            $info['collation'] = $collation_result['Value'];
        }

        // Final fallback: WordPress connection settings
        if ($info['charset'] === 'Unknown') {
            $info['charset'] = $wpdb->charset ?: (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
        }
        if ($info['collation'] === 'Unknown') {
            $info['collation'] = $wpdb->collate ?: 'utf8mb4_unicode_ci';
        }

        return $info;
    }

    /**
     * Capture charset/collation details for key plugin tables.
     *
     * @return string Human-readable summary for email diagnostics
     */
    private static function getDatabaseCollationSnapshot() {
        global $wpdb;

        $summaryLines = array();

        // Show the table prefix to help diagnose prefix mismatch issues
        $summaryLines[] = "Table prefix: " . $wpdb->prefix;
        $summaryLines[] = "";

        // Safely get class instances - may not exist in test environment
        if (!class_exists('ABJ_404_Solution_DatabaseUpgradesEtc') ||
            !class_exists('ABJ_404_Solution_DataAccess')) {
            $summaryLines[] = "Collation details unavailable (required classes not loaded).";
            return implode("\n", $summaryLines);
        }

        $dbUtils = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $dao = ABJ_404_Solution_DataAccess::getInstance();

        // Get baseline from wp_posts
        $targetTable = $wpdb->prefix . 'posts';
        $targetInfo = self::getTableInfo($targetTable);

        if ($targetInfo === null || isset($targetInfo['error'])) {
            $errorMsg = isset($targetInfo['error']) ? $targetInfo['error'] : 'table not found';
            $summaryLines[] = "Could not read collation for {$targetTable} (baseline): {$errorMsg}";
            return implode("\n", $summaryLines);
        }

        $targetCollation = $targetInfo['collation'];
        $targetCharset = $targetInfo['charset'];
        $targetEngine = $targetInfo['engine'];

        $summaryLines[] = sprintf(
            "%s -> %s / %s / %s (baseline)",
            $targetTable,
            $targetCharset,
            $targetCollation,
            $targetEngine
        );

        $pluginTables = array(
            'Logs' => $dao->doTableNameReplacements("{wp_abj404_logsv2}"),
            'Lookup' => $dao->doTableNameReplacements("{wp_abj404_lookup}"),
            'Permalink Cache' => $dao->doTableNameReplacements("{wp_abj404_permalink_cache}"),
            'Spelling Cache' => $dao->doTableNameReplacements("{wp_abj404_spelling_cache}"),
            'Redirects' => $dao->doTableNameReplacements("{wp_abj404_redirects}"),
        );

        foreach ($pluginTables as $label => $tableName) {
            $tableInfo = self::getTableInfo($tableName);

            if ($tableInfo === null) {
                $summaryLines[] = sprintf(
                    "%s (%s) -> unavailable (table not found)",
                    $label,
                    $tableName
                );
                continue;
            }

            if (isset($tableInfo['error'])) {
                $summaryLines[] = sprintf(
                    "%s (%s) -> unavailable (%s)",
                    $label,
                    $tableName,
                    $tableInfo['error']
                );
                continue;
            }

            $collation = $tableInfo['collation'];
            $charset = $tableInfo['charset'];
            $engine = $tableInfo['engine'];

            $matchesBaseline = ($collation === $targetCollation && $charset === $targetCharset);
            $utf8mb4Note = (stripos($charset, 'utf8mb4') === false) ? ' [non-utf8mb4]' : '';
            $matchNote = $matchesBaseline ? 'matches' : 'DIFFERS';

            $summaryLines[] = sprintf(
                "%s (%s) -> %s / %s / %s (%s)%s",
                $label,
                $tableName,
                $charset,
                $collation,
                $engine,
                $matchNote,
                $utf8mb4Note
            );
        }

        return implode("\n", $summaryLines);
    }

    /**
     * Get table info with fallback chain for locked-down hosts.
     *
     * Tries multiple methods in order:
     * 1. information_schema (most complete)
     * 2. SHOW TABLE STATUS (widely permitted)
     * 3. SHOW CREATE TABLE (parse DDL)
     * 4. WordPress globals (connection-level defaults)
     *
     * @param string $tableName Table name to look up
     * @return array Array with 'charset', 'collation', 'engine' keys
     */
    private static function getTableInfo($tableName) {
        // Try information_schema first (most complete data)
        $result = self::tryInformationSchema($tableName);
        if ($result !== null && !isset($result['error'])) {
            return $result;
        }

        // Fallback: SHOW TABLE STATUS
        $result = self::tryShowTableStatus($tableName);
        if ($result !== null && !isset($result['error'])) {
            return $result;
        }

        // Fallback: SHOW CREATE TABLE
        $result = self::tryShowCreateTable($tableName);
        if ($result !== null && !isset($result['error'])) {
            return $result;
        }

        // Final fallback: WordPress connection defaults
        return self::getWpdbDefaults();
    }

    /**
     * Try to get table info from information_schema.
     *
     * @param string $tableName Table name to look up
     * @return array|null Array with 'charset', 'collation', 'engine' keys, or null/error array on failure
     */
    private static function tryInformationSchema($tableName) {
        global $wpdb;

        // Guard for test environment where wpdb may be a minimal mock
        if (!method_exists($wpdb, 'get_row')) {
            return array('error' => 'wpdb methods unavailable');
        }

        $query = $wpdb->prepare(
            "SELECT TABLE_COLLATION, ENGINE, " .
            "SUBSTRING_INDEX(TABLE_COLLATION, '_', 1) as TABLE_CHARSET " .
            "FROM information_schema.tables " .
            "WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()",
            $tableName
        );

        $result = $wpdb->get_row($query, ARRAY_A);

        // Check for query error
        if (!empty($wpdb->last_error)) {
            // Check for permission-related errors
            if (stripos($wpdb->last_error, 'denied') !== false ||
                stripos($wpdb->last_error, 'permission') !== false) {
                return array('error' => 'permission denied');
            }
            return array('error' => 'query error');
        }

        // Table not found
        if (empty($result)) {
            return null;
        }

        // Handle case variations in column names
        $result = array_change_key_case($result, CASE_UPPER);

        $collation = $result['TABLE_COLLATION'] ?? null;
        $engine = $result['ENGINE'] ?? 'Unknown';
        $charset = $result['TABLE_CHARSET'] ?? null;

        // Fallback charset extraction from collation
        if (empty($charset) && !empty($collation)) {
            $charset = explode('_', $collation)[0];
        }

        if (empty($collation)) {
            return array('error' => 'no collation data');
        }

        return array(
            'charset' => $charset,
            'collation' => $collation,
            'engine' => $engine
        );
    }

    /**
     * Try to get table info using SHOW TABLE STATUS.
     *
     * @param string $tableName Table name to look up
     * @return array|null Array with 'charset', 'collation', 'engine' keys, or null/error on failure
     */
    private static function tryShowTableStatus($tableName) {
        global $wpdb;

        if (!method_exists($wpdb, 'get_row')) {
            return array('error' => 'wpdb methods unavailable');
        }

        // SHOW TABLE STATUS LIKE requires the table name without database prefix matching
        $result = $wpdb->get_row(
            $wpdb->prepare("SHOW TABLE STATUS LIKE %s", $tableName),
            ARRAY_A
        );

        if (!empty($wpdb->last_error)) {
            return array('error' => 'SHOW TABLE STATUS failed');
        }

        if (empty($result)) {
            return null;
        }

        $collation = $result['Collation'] ?? null;
        $engine = $result['Engine'] ?? 'Unknown';
        $charset = $collation ? explode('_', $collation)[0] : null;

        if (empty($collation)) {
            return null;
        }

        return array(
            'charset' => $charset,
            'collation' => $collation,
            'engine' => $engine
        );
    }

    /**
     * Try to get table info by parsing SHOW CREATE TABLE output.
     *
     * @param string $tableName Table name to look up
     * @return array|null Array with 'charset', 'collation', 'engine' keys, or null on failure
     */
    private static function tryShowCreateTable($tableName) {
        global $wpdb;

        if (!method_exists($wpdb, 'get_row')) {
            return null;
        }

        // Use backticks to safely quote table name
        $result = $wpdb->get_row("SHOW CREATE TABLE `" . esc_sql($tableName) . "`", ARRAY_N);

        if (empty($result[1])) {
            return null;
        }

        $ddl = $result[1];

        // Match charset: CHARSET=utf8mb4, DEFAULT CHARSET=utf8mb4, CHARACTER SET utf8mb4
        preg_match('/(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)(?:\s*=\s*|\s+)([\w\d]+)/i', $ddl, $charsetMatch);

        // Match collation: COLLATE=utf8mb4_unicode_ci, COLLATE utf8mb4_unicode_ci
        preg_match('/(?:DEFAULT\s+)?COLLATE(?:\s*=\s*|\s+)([\w\d_]+)/i', $ddl, $collationMatch);

        // Match engine: ENGINE=InnoDB
        preg_match('/ENGINE\s*=\s*([\w]+)/i', $ddl, $engineMatch);

        $charset = $charsetMatch[1] ?? null;
        $collation = $collationMatch[1] ?? null;
        $engine = $engineMatch[1] ?? 'Unknown';

        // Derive collation from charset if not explicit
        if ($charset && !$collation) {
            $collation = $charset . '_general_ci';
        }

        // Need at least charset or collation to return valid data
        if (empty($charset) && empty($collation)) {
            return null;
        }

        return array(
            'charset' => $charset ?: explode('_', $collation)[0],
            'collation' => $collation,
            'engine' => $engine
        );
    }

    /**
     * Get WordPress connection-level charset/collation as final fallback.
     *
     * @return array Array with 'charset', 'collation', 'engine', 'source' keys
     */
    private static function getWpdbDefaults() {
        global $wpdb;

        $charset = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

        // Try to get from wpdb properties
        if (isset($wpdb->charset) && !empty($wpdb->charset)) {
            $charset = $wpdb->charset;
        } elseif (defined('DB_CHARSET') && DB_CHARSET) {
            $charset = DB_CHARSET;
        }

        if (isset($wpdb->collate) && !empty($wpdb->collate)) {
            $collation = $wpdb->collate;
        }

        return array(
            'charset' => $charset,
            'collation' => $collation,
            'engine' => 'Unknown',
            'source' => 'wpdb defaults'
        );
    }
}
