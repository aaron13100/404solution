<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin activation, deactivation, multisite lifecycle, and cron registration.
 * Standalone class extracted from PluginLogicTrait_Lifecycle.
 */
class ABJ_404_Solution_PluginLogicLifecycle {

    /** Remove cron jobs. @return void */
    static function doUnregisterCrons(): void {
        $crons = array(
            'abj404_cleanupCronAction',
            'abj404_gsc_fetch_cron',
            'abj404_gsc_background_refresh',
            'abj404_rebuildViewDone',
            'abj404_updatePermalinkCacheAction',
            'abj404_updateLogsHitsTableAction',
            'abj404_send_digest',
            'abj404_rebuild_ngram_cache_hook',
            'abj404_logsv2_canonical_backfill',
            'abj404_send_queued_report',
            'abj404_duplicateCronAction',
            'removeDuplicatesCron',
            'deleteOldRedirectsCron',
        );
        for ($i = 0; $i < count($crons); $i++) {
            $cron_name = $crons[$i];
            $timestamp1 = wp_next_scheduled($cron_name);
            while ($timestamp1 != false) {
                wp_unschedule_event($timestamp1, $cron_name);
                $timestamp1 = wp_next_scheduled($cron_name);
            }

            $timestamp2 = wp_next_scheduled($cron_name, array(''));
            while ($timestamp2 != false) {
                wp_unschedule_event($timestamp2, $cron_name, array(''));
                $timestamp2 = wp_next_scheduled($cron_name, array(''));
            }

            wp_clear_scheduled_hook($cron_name);
        }
    }

    /**
     * Create database tables. Register crons. etc.
     *
     * @param bool $network_wide Whether this is a network-wide activation
     * @return void
     */
    static function runOnPluginActivation(bool $network_wide = false): void {
        if (is_multisite() && $network_wide) {
            $sites = get_sites(array('fields' => 'ids', 'number' => 0));

            update_site_option('abj404_pending_network_activation', $sites);
            update_site_option('abj404_network_activation_total', count($sites));

            wp_schedule_single_event(time(), 'abj404_network_activation_hook');

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
            self::activateSingleSite();
        }
    }

    /**
     * Activate plugin for a single site.
     * @return void
     */
    private static function activateSingleSite(): void {
        $abj404logic = abj_service('plugin_logic');
        add_option('abj404_settings', '', '', false);

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->createDatabaseTables();

        $upgradesEtc->runSelfHealPrologue();

        self::doRegisterCrons();

        $abj404logic->doUpdateDBVersionOption();
    }

    /**
     * Background cron handler for network activation.
     * @return void
     */
    static function networkActivationCronHandler(): void {
        $pendingRaw = get_site_option('abj404_pending_network_activation', array());
        $pending = is_array($pendingRaw) ? $pendingRaw : array();

        if (empty($pending)) {
            delete_site_option('abj404_pending_network_activation');
            delete_site_option('abj404_network_activation_total');
            return;
        }

        $blog_id = array_shift($pending);
        $blog_id_int = is_scalar($blog_id) ? (int)$blog_id : 0;

        try {
            switch_to_blog($blog_id_int);
            self::activateSingleSite();
            restore_current_blog();
        } catch (Exception $e) {
            $remaining = max(0, count($pending));
            $errorLine = '404 Solution: Network activation failed for site ' . $blog_id_int .
                ': ' . $e->getMessage() . '. Remaining sites=' . $remaining .
                '. Action: skipping this site, continuing with next.';
            error_log($errorLine);
            $logger = abj_service('logging');
            if ($logger !== null) {
                $logger->errorMessage($errorLine, $e);
            }
            restore_current_blog();
        }

        update_site_option('abj404_pending_network_activation', $pending);

        if (!empty($pending)) {
            wp_schedule_single_event(time() + 10, 'abj404_network_activation_hook');
        } else {
            delete_site_option('abj404_pending_network_activation');
            delete_site_option('abj404_network_activation_total');
        }
    }

    /**
     * Handle new blog creation in multisite (WordPress < 5.1).
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
        if (!function_exists('is_plugin_active_for_network')) {
            return;
        }
        if (is_plugin_active_for_network(plugin_basename(ABJ404_FILE))) {
            switch_to_blog($blog_id);
            try {
                self::activateSingleSite();
            } catch (\Throwable $e) {
                $logger = abj_service('logging');
                if ($logger !== null && method_exists($logger, 'warn')) {
                    $logger->warn(sprintf(
                        '404 Solution: subsite activation failed for blog_id=%d: %s',
                        (int)$blog_id,
                        $e->getMessage()
                    ));
                }
            } finally {
                restore_current_blog();
            }
        }
    }

    /**
     * Handle new blog creation in multisite (WordPress >= 5.1).
     *
     * @param mixed $site The WP_Site object for the new site.
     * @param array<string, mixed> $args Additional arguments passed to the hook
     * @return void
     */
    static function activateNewSiteModern($site, $args): void {
        if (!function_exists('is_plugin_active_for_network')) {
            return;
        }
        if (is_plugin_active_for_network(plugin_basename(ABJ404_FILE))) {
            $siteRef = ABJ_404_Solution_SiteRef::fromWpSite($site);
            if ($siteRef === null) {
                return;
            }
            $blogId = $siteRef->getBlogId();
            switch_to_blog($blogId);
            try {
                self::activateSingleSite();
            } catch (\Throwable $e) {
                $logger = abj_service('logging');
                if ($logger !== null && method_exists($logger, 'warn')) {
                    $logger->warn(sprintf(
                        '404 Solution: subsite activation failed for blog_id=%d: %s',
                        $blogId,
                        $e->getMessage()
                    ));
                }
            } finally {
                restore_current_blog();
            }
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
            $sites = get_sites(array('fields' => 'ids', 'number' => 0));

            foreach ($sites as $blog_id) {
                switch_to_blog($blog_id);
                self::deactivateSingleSite();
                restore_current_blog();
            }
        } else {
            self::deactivateSingleSite();
        }
    }

    /**
     * Deactivate plugin for a single site.
     * @return void
     */
    private static function deactivateSingleSite(): void {
        self::doUnregisterCrons();
    }

    /**
     * Clean up when a blog is deleted in multisite.
     *
     * @global wpdb $wpdb WordPress database object
     * @param int $blog_id Blog ID being deleted
     * @param bool $drop Whether to drop the tables
     * @return void
     */
    static function deleteBlogData($blog_id, $drop = false): void {
        // CRON GUARD: refuse cron context as a structural backstop so
        // CronReachableDestructiveSqlLintTest can prove the DROP TABLE below
        // is never reachable from a daily cron tick.
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return;
        }

        if ($drop) {
            switch_to_blog($blog_id);

            global $wpdb;
            $dbCore = abj_service('db_core');
            $prefix = $dbCore->getLowercasePrefix();

            // DAO-bypass-approved: deleteBlogData() runs during multisite blog teardown after switch_to_blog()
            $tables = $wpdb->get_results(
                // DAO-bypass-approved: prepare() argument to the get_results above
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($prefix . 'abj404_') . '%'),
                ARRAY_N
            );
            foreach ($tables as $tableRow) {
                $tblName = is_array($tableRow) && isset($tableRow[0]) ? $tableRow[0] : '';
                if (preg_match('/^[a-zA-Z0-9_]+$/', $tblName) && strpos($tblName, 'abj404') !== false) {
                    // DAO-bypass-approved: deleteBlogData(), DDL drop during blog teardown
                    $wpdb->query("DROP TABLE IF EXISTS `{$tblName}`");
                }
            }

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

            // DAO-bypass-approved: deleteBlogData() runs wp_options cleanup during blog teardown
            $wpdb->query(
                // DAO-bypass-approved: prepare() argument to the query above
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like('abj404_sync_') . '%'
                )
            );

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
                'abj404_send_queued_report',
            );

            foreach ($cron_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }

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

            restore_current_blog();
        }
    }

    /** @return void */
    static function doRegisterCrons(): void {
        if (!wp_next_scheduled('abj404_cleanupCronAction')) {
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

        self::scheduleViewDoneWarmup();
    }

    /** @return void */
    private static function scheduleViewDoneWarmup(): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        if (class_exists('ABJ_404_Solution_ServiceContainer')
                && ABJ_404_Solution_ServiceContainer::safeHas('rebuild_health')) {
            $rebuildHealth = ABJ_404_Solution_ServiceContainer::safeGet('rebuild_health');
            if ($rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
                    && !$rebuildHealth->mayStartExpensiveRebuild()) {
                return;
            }
        }
        if (wp_next_scheduled('abj404_rebuildViewDone') !== false) {
            return;
        }
        wp_schedule_single_event(time() + 5, 'abj404_rebuildViewDone');
    }
}
