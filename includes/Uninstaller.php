<?php

/**
 * Handles plugin uninstallation
 * Completely separate from existing plugin code
 *
 * @since 2.36.11
 */
class ABJ_404_Solution_Uninstaller {

    /**
     * Main uninstall method
     * Processes deletion based on user preferences
     *
     * @param array $preferences User's uninstall preferences from modal
     */
    public static function uninstall($preferences) {
        global $wpdb;

        // 1. Delete database tables based on user preferences
        self::deleteTables($wpdb, $preferences);

        // 2. Delete all WordPress options
        self::deleteAllOptions();

        // 3. Clean up scheduled cron jobs
        self::cleanupCronJobs();

        // 4. Send feedback via email if requested
        if ($preferences['send_feedback'] && !empty($preferences['uninstall_reason'])) {
            self::sendFeedbackEmail($preferences);
        }
    }

    /**
     * Handle multisite uninstallation
     * Runs uninstall process for each site in the network
     * IMPORTANT: This should only be called when the plugin is network-activated
     *
     * @param array $preferences User's uninstall preferences
     */
    public static function multisite_uninstall($preferences) {
        global $wpdb;

        // Safety check: Verify this is actually a network-wide uninstall
        // This prevents accidental data loss if called incorrectly
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = '404-solution/404-solution.php';
        if (!is_plugin_active_for_network($plugin_file)) {
            // Log error and bail out - this method should not have been called
            error_log('404 Solution: multisite_uninstall() called but plugin is not network-activated. Aborting to prevent data loss.');
            return;
        }

        // Get all blog IDs in the network
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");

        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            self::uninstall($preferences);
            restore_current_blog();
        }
    }

    /**
     * Delete database tables based on user preferences
     *
     * @param wpdb  $wpdb        WordPress database object
     * @param array $preferences User preferences
     */
    private static function deleteTables($wpdb, $preferences) {
        // Use wpdb prefix directly - Uninstaller must be standalone (no autoloader)
        $prefix = strtolower($wpdb->prefix);

        // Delete redirect table if user chose to
        if ($preferences['delete_redirects']) {
            self::deleteTable($prefix . 'abj404_redirects');
        }

        // Delete log tables if user chose to
        if ($preferences['delete_logs']) {
            self::deleteTable($prefix . 'abj404_logsv2');
            self::deleteTable($prefix . 'abj404_lookup');
        }

        // Always delete cache tables (can be rebuilt)
        if ($preferences['delete_cache']) {
            self::deleteTable($prefix . 'abj404_permalink_cache');
            self::deleteTable($prefix . 'abj404_ngram_cache');
            self::deleteTable($prefix . 'abj404_spelling_cache');
        }

        // Always delete temporary tables
        self::deleteTable($prefix . 'abj404_logs_hits_temp');
    }

    /**
     * Safely delete a database table
     *
     * @param string $table_name Full table name with prefix
     */
    private static function deleteTable($table_name) {
        global $wpdb;

        // Security: Use wpdb methods and prepare statement
        $table_name = esc_sql($table_name);
        $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
    }

    /**
     * Delete all plugin options from wp_options table
     */
    public static function deleteAllOptions() {
        global $wpdb;

        // List of all plugin options
        $options = array(
            'abj404_settings',
            'abj404_db_version',
            'abj404_migrated_to_relative_paths',
            'abj404_migration_results',
            'abj404_ngram_cache_initialized',
            'abj404_ngram_rebuild_offset',
            'abj404_uninstall_preferences' // Clean up the preferences option
        );

        // Delete each option
        foreach ($options as $option) {
            delete_option($option);
            delete_site_option($option); // For multisite (network options)
        }

        // Delete dynamic sync options (using LIKE pattern)
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
                $wpdb->esc_like('abj404_sync_') . '%'
            )
        );

        // For multisite, delete from site options too
        if (is_multisite()) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE %s",
                    $wpdb->esc_like('abj404_sync_') . '%'
                )
            );
        }
    }

    /**
     * Clean up all scheduled cron jobs
     */
    public static function cleanupCronJobs() {
        $cron_hooks = array(
            'abj404_cleanupCronAction',
            'abj404_updateLogsHitsTableAction',
            'abj404_updatePermalinkCacheAction',
            'abj404_rebuild_ngram_cache_hook'
        );

        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // Also clean up any old/legacy cron hooks
        $legacy_hooks = array(
            'abj404_updatePermalinkCache',
            'abj404_cleanupCron'
        );

        foreach ($legacy_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * Send feedback email to plugin author
     *
     * @param array $preferences User preferences including feedback data
     */
    private static function sendFeedbackEmail($preferences) {
        // Plugin author email
        $to = '404solution@ajexperience.com';

        $subject = '404 Solution - Uninstall Feedback';

        // Build email message
        $message = "Uninstall feedback received from 404 Solution plugin\n\n";

        // Uninstall reason
        if (!empty($preferences['uninstall_reason'])) {
            $reason_labels = array(
                'temporary' => 'Temporary deactivation for debugging',
                'not-working' => 'The plugin is not working as expected',
                'found-better' => 'Found a better plugin',
                'no-longer-needed' => 'No longer needed this functionality',
                'too-complicated' => 'Too complicated to configure',
                'performance' => 'Performance issues',
                'other' => 'Other reason'
            );

            $reason = isset($reason_labels[$preferences['uninstall_reason']])
                ? $reason_labels[$preferences['uninstall_reason']]
                : $preferences['uninstall_reason'];

            $message .= "Reason: " . $reason . "\n\n";
        }

        // Additional feedback details
        if (!empty($preferences['feedback_details'])) {
            $message .= "Additional Details:\n" . $preferences['feedback_details'] . "\n\n";
        }

        // User contact email (if provided)
        if (!empty($preferences['feedback_email'])) {
            $message .= "User Email: " . $preferences['feedback_email'] . "\n\n";
        }

        // System information
        $message .= "--- System Information ---\n";
        $message .= "WordPress Version: " . get_bloginfo('version') . "\n";
        $message .= "PHP Version: " . PHP_VERSION . "\n";
        $message .= "Plugin Version: " . ABJ404_VERSION . "\n";
        $message .= "Site URL: " . get_site_url() . "\n";
        $message .= "Site Language: " . get_locale() . "\n";

        // Get installed plugins information
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());

        // Add installed plugins list
        $message .= "\n--- Installed Plugins ---\n";
        if (!empty($all_plugins)) {
            foreach ($all_plugins as $plugin_path => $plugin_data) {
                $is_active = in_array($plugin_path, $active_plugins) ? ' (Active)' : ' (Inactive)';
                $message .= sprintf(
                    "%s %s%s\n",
                    $plugin_data['Name'],
                    $plugin_data['Version'],
                    $is_active
                );
            }
        } else {
            $message .= "No plugins found\n";
        }

        // Data deletion choices
        $message .= "\n--- User's Data Choices ---\n";
        $message .= "Deleted Redirects: " . ($preferences['delete_redirects'] ? 'Yes' : 'No') . "\n";
        $message .= "Deleted Logs: " . ($preferences['delete_logs'] ? 'Yes' : 'No') . "\n";

        // Email headers
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        // Add reply-to if user provided their email
        if (!empty($preferences['feedback_email']) && is_email($preferences['feedback_email'])) {
            $headers[] = 'Reply-To: ' . $preferences['feedback_email'];
        }

        // Send email (non-blocking, failures won't stop uninstall)
        @wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Get list of all tables created by this plugin
     *
     * @return array Array of table names (without prefix)
     */
    public static function getTableNames() {
        return array(
            'abj404_redirects',
            'abj404_logsv2',
            'abj404_lookup',
            'abj404_logs_hits_temp',
            'abj404_permalink_cache',
            'abj404_ngram_cache',
            'abj404_spelling_cache'
        );
    }
}
