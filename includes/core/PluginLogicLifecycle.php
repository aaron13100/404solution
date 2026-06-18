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
        abj_cron_scheduler()->clearRegisteredHooks();
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

            abj_cron_scheduler()->scheduleSingle(
                ABJ_404_Solution_CronScheduler::HOOK_NETWORK_ACTIVATION
            );

            add_action('network_admin_notices', function() {
                $pendingRaw = get_site_option('abj404_pending_network_activation', array());
                $pending = is_array($pendingRaw) ? $pendingRaw : array();
                $totalRaw = get_site_option('abj404_network_activation_total', 0);
                $total = is_scalar($totalRaw) ? (int)$totalRaw : 0;
                $completed = $total - count($pending);

                if (!empty($pending)) {
                    $template = ABJ_404_Solution_FileSystemService::readFileContents(
                        dirname(__DIR__) . '/html/networkActivationNotice.html',
                        false
                    );
                    echo str_replace(
                        array('{completed}', '{total}'),
                        array(esc_html((string)$completed), esc_html((string)$total)),
                        $template
                    );
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
        add_option('abj404_settings', '', '', false);

        $upgradesEtc = abj_service('database_upgrades');
        $upgradesEtc->components()->bootstrapUpgrade()->createDatabaseTables();

        $upgradesEtc->components()->selfHealUpgrade()->runSelfHealPrologue();

        self::doRegisterCrons();

        abj_service('version_upgrade')->stampDbVersion();
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
            $logger = abj_service('logging');
            if ($logger !== null) {
                $logger->errorMessage($errorLine, $e);
            }
            restore_current_blog();
        }

        update_site_option('abj404_pending_network_activation', $pending);

        if (!empty($pending)) {
            abj_cron_scheduler()->scheduleSingle(
                ABJ_404_Solution_CronScheduler::HOOK_NETWORK_ACTIVATION,
                10
            );
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
            $prefix = $dbCore->tableNameResolver()->getLowercasePrefix();

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
                'abj404_redirects_denorm_backfill',
                'abj404_redirects_sort_key_backfill',
                'abj404_send_queued_report',
            );

            foreach ($cron_hooks as $hook) {
                abj_cron_scheduler()->clearHook($hook);
            }

            $legacy_hooks = array(
                'abj404_duplicateCronAction',
                'abj404_updatePermalinkCache',
                'abj404_cleanupCron',
                'removeDuplicatesCron',
                'deleteOldRedirectsCron',
            );

            foreach ($legacy_hooks as $hook) {
                abj_cron_scheduler()->clearHook($hook);
            }

            restore_current_blog();
        }
    }

    /** @return void */
    static function doRegisterCrons(): void {
        $scheduler = abj_cron_scheduler();
        $scheduler->scheduleDailyInWindowIfMissing(ABJ_404_Solution_CronScheduler::HOOK_CLEANUP, 0, 5);
        $scheduler->scheduleDailyInWindowIfMissing(ABJ_404_Solution_CronScheduler::HOOK_GSC_FETCH, 1, 4);
    }
}
