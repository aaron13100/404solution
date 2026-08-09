<?php


if (!defined('ABSPATH')) {
    exit;
}

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
     * @param array<string, mixed> $preferences User's uninstall preferences from modal
     * @return void
     */
    public static function uninstall(array $preferences): void {
        global $wpdb;

        /** @var array<string, mixed> $preferences */

        // 1. Delete database tables based on user preferences
        self::deleteTables($wpdb, $preferences);

        // 2. Delete system page if user chose to delete data
        $deleteAnyData = ($preferences['delete_redirects'] ?? false)
            || ($preferences['delete_logs'] ?? false)
            || ($preferences['delete_cache'] ?? false);
        if ($deleteAnyData) {
            self::deleteSystemPage();
        }

        // 3. Delete all WordPress options
        self::deleteAllOptions();

        // 4. Clean up scheduled cron jobs
        self::cleanupCronJobs();

        // Feedback is sent at deactivate-time by UninstallModal (the modal AJAX
        // handler dispatches before WordPress deletes the plugin). WordPress
        // enforces deactivate-before-delete, so any feedback the user opted into
        // has already been queued/sent by the time uninstall.php runs. Sending
        // again here from the persisted preferences would be a double-send.
    }

    /**
     * Delete the auto-created system page (if it exists).
     * Finds it via post meta _abj404_system_page = 1.
     *
     * @return void
     */
    private static function deleteSystemPage(): void {
        // Guard: get_posts may not exist during standalone uninstall (no autoloader).
        if (!function_exists('get_posts')) {
            return;
        }

        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'any',
            'meta_key'       => '_abj404_system_page',
            'meta_value'     => '1',
            'posts_per_page' => 5,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        if (is_array($pages)) {
            foreach ($pages as $pageId) {
                wp_delete_post((int) $pageId, true);
            }
        }
    }

    /**
     * Handle multisite uninstallation
     * Runs uninstall process for each site in the network
     * IMPORTANT: This should only be called when the plugin is network-activated
     *
     * @param array<string, mixed> $preferences User's uninstall preferences
     * @return void
     */
    public static function multisite_uninstall(array $preferences): void {
        global $wpdb;

        // Safety check: Verify this is actually a network-wide uninstall
        // This prevents accidental data loss if called incorrectly
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = '404-solution/404-solution.php';
        if (!is_plugin_active_for_network($plugin_file)) {
            // Log error and bail out - this method should not have been called
            self::logWarning('multisite_uninstall() called but plugin is not network-activated. Aborting to prevent data loss.');
            return;
        }

        // Get all blog IDs in the network
        // DAO-bypass-approved: Multisite blog enumeration during uninstall — no DAO autoloader
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
     * @param object $wpdb        WordPress database object (wpdb or compatible)
     * @param array<string, mixed> $preferences User preferences
     * @return void
     */
    private static function deleteTables(object $wpdb, array $preferences): void {
        // Use wpdb prefix directly - Uninstaller must be standalone (no autoloader)
        /** @var \wpdb $wpdb */
        $prefix = strtolower($wpdb->prefix);

        $deleteRedirects = $preferences['delete_redirects'] ?? false;
        $deleteLogs      = $preferences['delete_logs']      ?? false;
        $deleteCache     = $preferences['delete_cache']     ?? false;

        // When ALL data-deletion options are selected, use SHOW TABLES for dynamic
        // discovery — the DB is the source of truth for which plugin tables exist.
        // This ensures future tables are cleaned up even if this list isn't updated.
        if ($deleteRedirects && $deleteLogs && $deleteCache) {
            // DAO-bypass-approved: Plugin-table discovery during uninstall — no DAO autoloader
            $allTables = $wpdb->get_results(
                // DAO-bypass-approved: Plugin-table discovery during uninstall — no DAO autoloader
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($prefix . 'abj404_') . '%'),
                ARRAY_N
            );
            if (is_array($allTables)) {
                foreach ($allTables as $row) {
                    if (is_string($row[0])) {
                        self::deleteTable($row[0]);
                    }
                }
            }
            return;
        }

        // Partial deletion: respect each category preference separately.
        if ($deleteRedirects) {
            self::deleteTable($prefix . 'abj404_redirects');
        }

        if ($deleteLogs) {
            self::deleteTable($prefix . 'abj404_logsv2');
            self::deleteTable($prefix . 'abj404_lookup');
        }

        if ($deleteCache) {
            self::deleteTable($prefix . 'abj404_permalink_cache');
            self::deleteTable($prefix . 'abj404_ngram_cache');
            self::deleteTable($prefix . 'abj404_spelling_cache');
            self::deleteTable($prefix . 'abj404_view_cache');
        }

        // Always delete temporary tables (they hold no user data worth preserving).
        self::deleteTable($prefix . 'abj404_logs_hits_temp');
    }

    /**
     * Safely delete a database table.
     *
     * Defense-in-depth: refuses any name that is not shaped like a plugin
     * table ({prefix}abj404_{suffix}). Today the only callers are the
     * partial-deletion paths in deleteTables() using {prefix} + 'abj404_*'
     * constants and a 'SHOW TABLES LIKE ...abj404_%' discovery loop, so an
     * unsafe value cannot reach here. The shape check inside the sink keeps
     * that property robust against future maintenance routing a user-derived
     * value into the same private method, without relying on esc_sql() (which
     * does not escape backticks and so cannot protect an identifier context
     * on its own).
     *
     * @param string $table_name Full table name with prefix
     * @return void
     */
    private static function deleteTable(string $table_name): void {
        global $wpdb;

        // CRON GUARD: Uninstaller.php is loaded only by uninstall.php which
        // WordPress invokes during operator-driven plugin removal, never via
        // cron. Refuse cron context as a structural backstop so
        // CronReachableDestructiveSqlLintTest can prove the DROP TABLE below
        // is unreachable from any cron tick.
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return;
        }

        // q992: refuse anything outside the {prefix}abj404_{suffix} shape.
        if (!preg_match('/^[a-z0-9_]+abj404_[a-z0-9_]+$/i', $table_name)) {
            return;
        }

        // DAO-bypass-approved: DDL drop during uninstall. No DAO autoloader available.
        $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
    }

    /**
     * Delete all plugin options from wp_options table
     * @return void
     */
    public static function deleteAllOptions(): void {
        global $wpdb;

        $optionsTable = $wpdb->options ?? (($wpdb->prefix ?? 'wp_') . 'options');
        $sitemetaTable = $wpdb->sitemeta ?? (($wpdb->prefix ?? 'wp_') . 'sitemeta');

        // List of all plugin options
        $options = array(
            'abj404_settings',
            'abj404_db_version',
            'abj404_migrated_to_relative_paths',
            'abj404_migration_results',
            'abj404_ngram_cache_initialized',
            'abj404_ngram_rebuild_offset',
            // The multisite rebuild walk's progress record. Written as network
            // options on a network-activated install, so an uninstall that
            // dropped only the two above left a half-finished walk behind for
            // the next install to resume into a cache that no longer exists.
            'abj404_ngram_last_site_id',
            'abj404_ngram_sites_completed',
            'abj404_ngram_current_site_offset',
            'abj404_ngram_total_sites',
            'abj404_ngram_pending_sites',
            'abj404_uninstall_preferences' // Clean up the preferences option
        );

        // Delete each option
        foreach ($options as $option) {
            delete_option($option);
            delete_site_option($option); // For multisite (network options)
        }

        // Delete dynamic sync options (using LIKE pattern)
        // DAO-bypass-approved: wp_options cleanup during uninstall — no DAO autoloader
        $wpdb->query(
            // DAO-bypass-approved: wp_options cleanup during uninstall — no DAO autoloader
            $wpdb->prepare(
                "DELETE FROM {$optionsTable} WHERE option_name LIKE %s",
                $wpdb->esc_like('abj404_sync_') . '%'
            )
        );

        // For multisite, delete from site options too
        if (is_multisite()) {
            // DAO-bypass-approved: Multisite sitemeta cleanup during uninstall
            $wpdb->query(
                // DAO-bypass-approved: Multisite sitemeta cleanup during uninstall
                $wpdb->prepare(
                    "DELETE FROM {$sitemetaTable} WHERE meta_key LIKE %s",
                    $wpdb->esc_like('abj404_sync_') . '%'
                )
            );
        }
    }

    /**
     * Clean up all scheduled cron jobs
     * @return void
     */
    public static function cleanupCronJobs(): void {
        // Full per-site cron tear-down: everything the plugin currently
        // schedules plus historical hook names that older versions may have
        // left behind. Must stay aligned with the canonical list pinned by
        // DeactivationCronCleanupTest. When production starts scheduling a
        // new hook, add it here AND to doUnregisterCrons() in
        // PluginLogicTrait_Lifecycle.php.
        $cron_hooks = array(
            'abj404_cleanupCronAction',
            'abj404_updateLogsHitsTableAction',
            'abj404_updatePermalinkCacheAction',
            'abj404_rebuild_ngram_cache_hook',
            'abj404_rebuildViewDone',
            'abj404_gsc_fetch_cron',
            'abj404_gsc_background_refresh',
            'abj404_send_digest',
            'abj404_logsv2_canonical_backfill',
            'abj404_redirects_denorm_backfill',
            'abj404_redirects_sort_key_backfill',
            'abj404_send_queued_report',
            'abj404_repair_collations',
        );

        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // Also clean up any old/legacy cron hooks
        $legacy_hooks = array(
            'abj404_duplicateCronAction',
            'abj404_updatePermalinkCache',
            'abj404_cleanupCron',
            'removeDuplicatesCron',
            'deleteOldRedirectsCron',
        );

        foreach ($legacy_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * Get list of all tables created by this plugin
     *
     * @return array<int, string> Array of table names (without prefix)
     */
    public static function getTableNames(): array {
        return array(
            'abj404_redirects',
            'abj404_logsv2',
            'abj404_lookup',
            'abj404_logs_hits_temp',
            'abj404_permalink_cache',
            'abj404_ngram_cache',
            'abj404_spelling_cache',
            'abj404_view_cache'
        );
    }

    private static function logWarning(string $message): void {
        $logger = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
