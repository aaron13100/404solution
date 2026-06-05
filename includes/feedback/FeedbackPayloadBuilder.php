<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackDatabaseIdentity.php';
require_once __DIR__ . '/FeedbackDiagnosticsCollector.php';
require_once __DIR__ . '/FeedbackEnvironmentExtras.php';
require_once __DIR__ . '/FeedbackPayloadSchemaGuard.php';
require_once __DIR__ . '/FeedbackReportUuid.php';
require_once __DIR__ . '/FeedbackRuntimeEnvironment.php';
require_once __DIR__ . '/FeedbackWordPressInventory.php';

/**
 * Assembles feedback report payloads and enforces the outbound schema.
 *
 * Diagnostic collection is delegated to purpose-specific collaborators:
 * runtime environment probes, WordPress inventory, and feedback diagnostics.
 * This class owns the final payload shape, type-specific overlays, and
 * schema validation.
 */
class ABJ_404_Solution_FeedbackPayloadBuilder {

    /**
     * Build a payload from current site state. $extra carries type-specific
     * fields (uninstall_reason, debug_log, error_signature, etc.).
     *
     * @param string $type One of 'error', 'heartbeat', 'uninstall', 'support_request'.
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function build(string $type, array $extra = array()): array {
        global $wpdb;

        $databaseIdentity = ABJ_404_Solution_FeedbackDatabaseIdentity::detect(isset($wpdb) ? $wpdb : null);
        $environment = new ABJ_404_Solution_FeedbackRuntimeEnvironment();
        $inventory = new ABJ_404_Solution_FeedbackWordPressInventory();

        $payload = array(
            'plugin_version' => defined('ABJ404_VERSION') ? ABJ404_VERSION : '',
            'db_type' => $databaseIdentity['type'],
            'db_version' => $databaseIdentity['version'],
            'wp_version' => function_exists('get_bloginfo') ? (string)get_bloginfo('version') : '',
            'php_version' => PHP_VERSION,
            'is_multisite' => function_exists('is_multisite') ? (bool)is_multisite() : false,
            'is_uninstall' => ($type === 'uninstall'),
            'report_type' => $type,
            'site_url' => function_exists('home_url') ? (string)home_url() : '',
            'locale' => function_exists('get_locale') ? (string)get_locale() : '',
            'resource_limits' => $environment->resourceLimits(),
            'wp_memory_limit_bytes' => $environment->wpMemoryLimitBytes(),
            'extensions' => $environment->loadedExtensionsMap(),
            'active_plugins' => $inventory->activePlugins(),
            'active_theme' => $inventory->activeTheme(),
            'object_cache' => $inventory->objectCacheStatus(),
            'table_prefix' => $inventory->tablePrefix(isset($wpdb) ? $wpdb : null),
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'server_software' => $environment->sanitizeServerSoftware(
                isset($_SERVER['SERVER_SOFTWARE']) && is_scalar($_SERVER['SERVER_SOFTWARE']) ? (string)$_SERVER['SERVER_SOFTWARE'] : ''
            ),
        );

        $payload += (new ABJ_404_Solution_FeedbackDiagnosticsCollector())->collect($type);
        $payload['environment_extras'] = (new ABJ_404_Solution_FeedbackEnvironmentExtras())->collect();

        if ($environment->isDevelopmentEnvironment()) {
            $payload['environment_type'] = 'development';
        }

        foreach ($extra as $k => $v) {
            $payload[(string)$k] = $v;
        }

        $payload = ABJ_404_Solution_FeedbackPayloadSchemaGuard::normalize($payload);
        ABJ_404_Solution_FeedbackPayloadSchemaGuard::assert($payload, $type, 'buildPayload');

        return $payload;
    }

    /**
     * Build a schema-conforming payload with diagnostic and site-identifying
     * fields stripped. Used by the uninstall flow when the user unchecks the
     * "Include technical details" opt-in.
     *
     * @param string $type One of 'error', 'heartbeat', 'uninstall', 'support_request'.
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function buildMinimal(string $type, array $extra = array()): array {
        $payload = array(
            'plugin_version' => defined('ABJ404_VERSION') ? ABJ404_VERSION : '',
            'report_type'    => $type,
            'is_uninstall'   => ($type === 'uninstall'),

            'site_url'        => '',
            'locale'          => '',
            'db_type'         => 'mysql',
            'db_version'      => '',
            'table_prefix'    => '',
            'wp_version'      => '',
            'is_multisite'    => false,
            'wp_debug'        => false,
            'php_version'     => '',
            'server_software' => '',

            'resource_limits'       => array(),
            'wp_memory_limit_bytes' => null,
            'extensions'            => array(),
            'active_plugins'        => array(),
            'active_theme'          => '',
            'object_cache'          => 'default',

            'published_posts_count' => null,
            'published_pages_count' => null,
            'categories_count'      => null,
            'tags_count'            => null,

            'redirects_active_total'    => null,
            'redirects_manual_count'    => null,
            'redirects_automatic_count' => null,
            'redirects_regex_count'     => null,
            'redirects_trashed_count'   => null,

            'captured_404s_active_total'  => null,
            'captured_404s_new_count'     => null,
            'captured_404s_ignored_count' => null,
            'captured_404s_later_count'   => null,
            'captured_404s_trashed_count' => null,

            'log_entries_count'     => null,
            'log_table_size_bytes'  => null,
            'error_count_in_log'    => null,
            'debug_file_size_bytes' => null,

            'environment_extras' => array(),
        );

        foreach ($extra as $k => $v) {
            $payload[(string)$k] = $v;
        }

        $payload = ABJ_404_Solution_FeedbackPayloadSchemaGuard::normalize($payload);
        ABJ_404_Solution_FeedbackPayloadSchemaGuard::assert($payload, $type, 'buildMinimalPayload');

        return $payload;
    }

    /**
     * Generate a random UUID v4 string for queueing.
     */
    public static function generateUuid(): string {
        return ABJ_404_Solution_FeedbackReportUuid::generate();
    }
}
