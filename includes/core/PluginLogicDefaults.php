<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Default values for the abj404_settings option array.
 *
 * Standalone class extracted from PluginLogic so the 80-line defaults
 * literal does not bloat the controller. ABJ_404_Solution_PluginLogic::getDefaultOptions()
 * forwards to {@see ABJ_404_Solution_PluginLogicDefaults::defaults()}.
 *
 * Defaults are merged into the persisted option on every read (see
 * PluginLogic::getOptions()), so adding a new key here gives every existing
 * install that key automatically without a migration.
 *
 * Referenced by:
 *   - PluginLogic::getOptions() (defaults merge)
 *   - PluginLogic::updateToNewVersionAction() (defaults merge prior to upgrade)
 *   - PluginLogicSettingsUpdate (per-row default fallback)
 *   - Ajax_RestoreDefaults (full reset to defaults)
 *   - ViewTrait_Shared (best-effort fallback when PluginLogic is unavailable)
 */
class ABJ_404_Solution_PluginLogicDefaults {

    /**
     * Default abj404_settings array.
     *
     * Uses ABJ404_TYPE_404_DISPLAYED, which is defined by the bootstrap (see
     * 404-solution.php); callers must require that constant before invoking.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return array(
            'default_redirect' => '301',
            'send_error_logs' => '0',
            'capture_404' => '1',
            'capture_deletion' => 1095,
            'manual_deletion' => '0',
            'log_deletion' => '365',
            'admin_notification' => '0',
            'remove_matches' => '1',
            'suggest_max' => '5',
            'suggest_title' => '<h3>{suggest_title_text}</h3>',
            'suggest_before' => '<ol>',
            'suggest_after' => '</ol>',
            'suggest_entrybefore' => '<li>',
            'suggest_entryafter' => '</li>',
            'suggest_noresults' => '<p>{suggest_noresults_text}</p>',
            'suggest_cats' => '1',
            'suggest_tags' => '1',
            'suggest_minscore' => '25',
            'suggest_minscore_enabled' => '0',
            'update_suggest_url' => '0',
            'auto_redirects' => '1',
            'auto_slugs' => '1',
            'auto_trash_redirect' => '0',
            'auto_score' => '90',
            'auto_score_title' => '',
            'auto_score_category_tag' => '',
            'auto_score_content' => '',
            'template_redirect_priority' => '9',
            'auto_deletion' => '1095',
            'auto_302_expiration_days' => '0',
            'auto_cats' => '1',
            'auto_tags' => '1',
            'dest404page' => '0|' . ABJ404_TYPE_404_DISPLAYED,
            'maximum_log_disk_usage' => '10',
            'ignore_dontprocess' => 'zemanta aggregator',
            'ignore_doprocess' => "Googlebot\nMediapartners-Google\nAdsBot-Google\ndevelopers.google.com\n"
            . "Bingbot\nYahoo! Slurp\nDuckDuckBot\nBaiduspider\nYandexBot\nwww.sogou.com\nSogou-Test-Spider\n"
            . "Exabot\nfacebot\nfacebookexternalhit\nia_archiver\nSeznamBot\nPinterestbot\nUptimeRobot\nMJ12bot",
            'recognized_post_types' => "page\npost\nproduct",
            'recognized_categories' => "",
            'folders_files_ignore' => implode("\n", array("wp-content/plugins/*", "wp-content/themes/*",
                ".well-known/acme-challenge/*")),
            'folders_files_ignore_usable' => "",
            'suggest_regex_exclusions' => "",
            'suggest_regex_exclusions_usable' => "",
            'plugin_admin_users' => "",
            'debug_mode' => 0,
            'days_wait_before_major_update' => 30,
            'DB_VERSION' => '0.0.0',
            'menuLocation' => 'underSettings',
            'admin_theme' => 'default',
            'plugin_language_override' => '',
            'disable_auto_dark_mode' => '0',
            'admin_notification_email' => '',
            'admin_notification_frequency' => 'instant',
            'admin_notification_digest_limit' => '10',
            'admin_notification_last_sent' => '0',
            'page_redirects_order_by' => 'url',
            'page_redirects_order' => 'ASC',
            'captured_order_by' => 'logshits',
            'captured_order' => 'DESC',
            'excludePages[]' => '',
            'dest404_behavior' => 'theme_default',
            'auto_trash_junk_urls' => '1',
            'auto_trash_junk_patterns' => implode("\n", array(
                '.env', '.git/', '.aws/', '.svn/', '.hg/',
                'xmlrpc.php', 'wlwmanifest.xml',
                'wp-config', 'config.php', 'config.json', 'config.bak',
                'phpinfo', 'phpmyadmin', 'phpMyAdmin', 'adminer',
                'sqladmin', 'dbadmin', 'mysqladmin',
                'id_rsa', '.bash_history', '.bashrc', '.DS_Store',
                'nginx.conf', 'httpd.conf', 'Dockerfile', 'docker-compose',
                '.sql', '.tar.gz', 'db_backup', 'database_backup',
                'setup-config.php',
                '/vendor/', '/node_modules/', '/tmp/',
                '/_profiler/', '/_debugbar/', '/debug/', '/debugbar/',
                '/META-INF/', '/WEB-INF/',
                'magento_version', 'alfa-rex.php', 'bypass.php',
            )),
        );
    }
}
