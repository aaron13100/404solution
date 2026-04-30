<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin activation, deactivation, multisite lifecycle, and cron registration.
 *
 * Extracted from PluginLogic.php to keep the main class under the size limit.
 */
trait ABJ_404_Solution_PluginLogicTrait_Lifecycle {

    /** Remove cron jobs. @return void */
    static function doUnregisterCrons(): void {
        $crons = array('abj404_cleanupCronAction', 'abj404_duplicateCronAction', 'removeDuplicatesCron', 'deleteOldRedirectsCron',
            'abj404_gsc_fetch_cron', 'abj404_gsc_background_refresh');
        for ($i = 0; $i < count($crons); $i++) {
            $cron_name = $crons[$i];
            $timestamp1 = wp_next_scheduled($cron_name);
            while ($timestamp1 != False) {
                wp_unschedule_event($timestamp1, $cron_name);
                $timestamp1 = wp_next_scheduled($cron_name);
            }

            $timestamp2 = wp_next_scheduled($cron_name, array(''));
            while ($timestamp2 != False) {
                wp_unschedule_event($timestamp2, $cron_name, array(''));
                $timestamp2 = wp_next_scheduled($cron_name, array(''));
            }

            wp_clear_scheduled_hook($cron_name);
        }
    }

    /** Create database tables. Register crons. etc.
     * Handles both single-site and multisite activations.
     *
     * For network activations, sites are activated asynchronously in the background
     * to prevent timeouts on large networks.
     *
     * @param bool $network_wide Whether this is a network-wide activation
     * @global type $abj404logic
     * @global type $abj404dao
     * @return void
     */
    static function runOnPluginActivation(bool $network_wide = false): void {
        if (is_multisite() && $network_wide) {
            // Network activation: Schedule background activation to prevent timeouts
            $sites = get_sites(array('fields' => 'ids', 'number' => 0));

            // Store list of pending site IDs in network option
            update_site_option('abj404_pending_network_activation', $sites);
            update_site_option('abj404_network_activation_total', count($sites));

            // Schedule first batch immediately
            wp_schedule_single_event(time(), 'abj404_network_activation_hook');

            // Show admin notice that activation is happening in background
            add_action('network_admin_notices', function() {
                $pendingRaw = get_site_option('abj404_pending_network_activation', array());
                $pending = is_array($pendingRaw) ? $pendingRaw : array();
                $totalRaw = get_site_option('abj404_network_activation_total', 0);
                $total = is_scalar($totalRaw) ? (int)$totalRaw : 0;
                $completed = $total - count($pending);

                if (!empty($pending)) {
                    echo '<div class="notice notice-info"><p><strong>404 Solution:</strong> Network activation in progress... ' .
                         esc_html((string)$completed) . ' of ' . esc_html((string)$total) . ' sites activated. ' .
                         'This will complete in the background.</p></div>';
                }
            });
        } else {
            // Single site activation (or individual subsite activation)
            self::activateSingleSite();
        }
    }

    /**
     * Activate plugin for a single site.
     * This contains the actual activation logic that was previously in runOnPluginActivation.
     *
     * @global type $abj404logic
     * @global type $abj404dao
     * @global type $abj404logging
     * @return void
     */
    private static function activateSingleSite(): void {
        $abj404logic = abj_service('plugin_logic');
        add_option('abj404_settings', '', '', false);

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->createDatabaseTables();

        ABJ_404_Solution_PluginLogic::doRegisterCrons();

        $abj404logic->doUpdateDBVersionOption();
    }

    /**
     * Background cron handler for network activation.
     * Processes one site at a time to prevent timeouts.
     * Reschedules itself if more sites remain.
     * @return void
     */
    static function networkActivationCronHandler(): void {
        // Get list of pending sites
        $pendingRaw = get_site_option('abj404_pending_network_activation', array());
        $pending = is_array($pendingRaw) ? $pendingRaw : array();

        if (empty($pending)) {
            // All done! Clean up network options
            delete_site_option('abj404_pending_network_activation');
            delete_site_option('abj404_network_activation_total');
            return;
        }

        // Process one site
        $blog_id = array_shift($pending);
        $blog_id_int = is_scalar($blog_id) ? (int)$blog_id : 0;

        try {
            switch_to_blog($blog_id_int);
            self::activateSingleSite();
            restore_current_blog();
        } catch (Exception $e) {
            // Log error but continue with other sites
            error_log('404 Solution: Network activation failed for site ' . $blog_id_int . ': ' . $e->getMessage());
            restore_current_blog();
        }

        // Update pending list
        update_site_option('abj404_pending_network_activation', $pending);

        // Schedule next site (10 seconds delay to spread load)
        if (!empty($pending)) {
            wp_schedule_single_event(time() + 10, 'abj404_network_activation_hook');
        } else {
            // All done! Clean up network options
            delete_site_option('abj404_pending_network_activation');
            delete_site_option('abj404_network_activation_total');
        }
    }

    /**
     * Handle new blog creation in multisite (WordPress < 5.1).
     * This is triggered by the wpmu_new_blog action.
     *
     * @param int $blog_id Blog ID of the new blog
     * @param int $user_id User ID of the user creating the blog
     * @param string $domain Domain of the new blog
     * @param string $path Path of the new blog
     * @param int $site_id Site ID (network ID)
     * @param array<string, mixed> $meta Additional meta information
     * @return void
     */
    static function activateNewSite($blog_id, $user_id, $domain, $path, $site_id, $meta): void {
        // Only activate if the plugin is network-activated.
        // is_plugin_active_for_network() lives in wp-admin/includes/plugin.php; guard
        // adjacent in case this hook fires before wp-admin includes are loaded.
        if (!function_exists('is_plugin_active_for_network')) {
            return;
        }
        if (is_plugin_active_for_network(plugin_basename(ABJ404_FILE))) {
            switch_to_blog($blog_id);
            self::activateSingleSite();
            restore_current_blog();
        }
    }

    /**
     * Handle new blog creation in multisite (WordPress >= 5.1).
     * This is triggered by the wp_initialize_site action.
     *
     * @param WP_Site $site The site object for the new site
     * @param array<string, mixed> $args Additional arguments passed to the hook
     * @return void
     */
    static function activateNewSiteModern($site, $args): void {
        // Only activate if the plugin is network-activated.
        // is_plugin_active_for_network() lives in wp-admin/includes/plugin.php; guard
        // adjacent in case this hook fires before wp-admin includes are loaded.
        if (!function_exists('is_plugin_active_for_network')) {
            return;
        }
        if (is_plugin_active_for_network(plugin_basename(ABJ404_FILE))) {
            switch_to_blog((int)$site->blog_id);
            self::activateSingleSite();
            restore_current_blog();
        }
    }

    /**
     * Handle plugin deactivation for both single-site and multisite.
     *
     * @param bool $network_wide Whether this is a network-wide deactivation
     * @return void
     */
    static function runOnPluginDeactivation(bool $network_wide = false): void {
        if (is_multisite() && $network_wide) {
            // Network deactivation: deactivate for all sites
            $sites = get_sites(array('fields' => 'ids', 'number' => 0));

            foreach ($sites as $blog_id) {
                switch_to_blog($blog_id);
                self::deactivateSingleSite();
                restore_current_blog();
            }
        } else {
            // Single site deactivation
            self::deactivateSingleSite();
        }
    }

    /**
     * Deactivate plugin for a single site.
     * Unregisters cron jobs.
     * @return void
     */
    private static function deactivateSingleSite(): void {
        self::doUnregisterCrons();
    }

    /**
     * Clean up when a blog is deleted in multisite.
     * This is triggered by the delete_blog action.
     *
     * @global wpdb $wpdb WordPress database object
     * @param int $blog_id Blog ID being deleted
     * @param bool $drop Whether to drop the tables (true) or just deactivate (false)
     * @return void
     */
    static function deleteBlogData($blog_id, $drop = false): void {
        if ($drop) {
            switch_to_blog($blog_id);

            global $wpdb;
            $dao = abj_service('data_access');
            $prefix = $dao->getLowercasePrefix();

            // Remove ALL custom database tables via dynamic discovery.
            // SHOW TABLES is the source of truth — new tables are automatically included.
            // DAO-bypass-approved: deleteBlogData() — multisite blog teardown after switch_to_blog()
            $tables = $wpdb->get_results(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($prefix . 'abj404_') . '%'),
                ARRAY_N
            );
            foreach ($tables as $tableRow) {
                $tblName = is_array($tableRow) && isset($tableRow[0]) ? $tableRow[0] : '';
                if (preg_match('/^[a-zA-Z0-9_]+$/', $tblName) && strpos($tblName, 'abj404') !== false) {
                    // DAO-bypass-approved: deleteBlogData() — DDL drop during blog teardown
                    $wpdb->query("DROP TABLE IF EXISTS `{$tblName}`");
                }
            }

            // Remove ALL plugin options
            $plugin_options = array(
                'abj404_settings',
                'abj404_db_version',
                'abj404_migrated_to_relative_paths',
                'abj404_migration_results',
                'abj404_ngram_cache_initialized',
                'abj404_ngram_rebuild_offset',
                'abj404_ngram_usage_stats',
                'abj404_installed_time',
                'abj404_user_feedback',
                'abj404_uninstall_preferences'
            );

            foreach ($plugin_options as $option) {
                delete_option($option);
            }

            // Delete dynamic sync options (using LIKE pattern)
            // DAO-bypass-approved: deleteBlogData() — wp_options cleanup during blog teardown
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like('abj404_sync_') . '%'
                )
            );

            // Clear ALL scheduled cron jobs for this blog
            $cron_hooks = array(
                'abj404_cleanupCronAction',
                'abj404_updateLogsHitsTableAction',
                'abj404_updatePermalinkCacheAction',
                'abj404_rebuild_ngram_cache_hook',
                'abj404_gsc_fetch_cron',
                'abj404_gsc_background_refresh',
            );

            foreach ($cron_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }

            // Also clear legacy cron hooks
            $legacy_hooks = array(
                'abj404_duplicateCronAction',
                'abj404_updatePermalinkCache',
                'abj404_cleanupCron'
            );

            foreach ($legacy_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }

            restore_current_blog();
        }
    }

    /** @return void */
    static function doRegisterCrons(): void {
        if (!wp_next_scheduled('abj404_cleanupCronAction')) {
            // we randomize this so that when the geo2ip file is downloaded, there aren't a whole
            // lot of users that request the file at the same time.
            $timeForEvent = '0' . random_int(0, 5) . ':' . random_int(10, 59) . ':' . random_int(10, 59);
            $eventTimestamp = strtotime($timeForEvent);
            if ($eventTimestamp !== false) {
                wp_schedule_event($eventTimestamp, 'daily', 'abj404_cleanupCronAction');
            }
        }

        if (!wp_next_scheduled('abj404_gsc_fetch_cron')) {
            $timeForGsc = '0' . random_int(1, 4) . ':' . random_int(10, 59) . ':' . random_int(10, 59);
            $gscTimestamp = strtotime($timeForGsc);
            if ($gscTimestamp !== false) {
                wp_schedule_event($gscTimestamp, 'daily', 'abj404_gsc_fetch_cron');
            }
        }
    }
}
