<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Diagnostic data collector for the deactivation feedback email.
 *
 * Owns gathering plugin counters (redirects/captured/logs), content counts,
 * active plugin list, and database charset/collation metadata with a
 * fallback chain for locked-down hosts. Pure data-collection layer; never
 * formats output (the email composer does that).
 *
 * @since 4.3.0
 */
class ABJ_404_Solution_UninstallDiagnostics {

    /**
     * Get the count of redirects for display in the modal preview.
     *
     * @return int Number of active (non-trashed) redirects, or 0 if the
     *             table does not exist (test or corrupt environments).
     */
    public static function getRedirectCount(): int {
        global $wpdb;

        // Guard for test environment where DataAccess class may not be loaded
        if (!class_exists('ABJ_404_Solution_DatabaseCore')) {
            return 0;
        }

        $dbCore = abj_service('db_core');
        $table_name = $dbCore->getPrefixedTableName('abj404_redirects');

        // DAO-bypass-approved: Diagnostic table-existence probe for redirect-count display
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;

        if (!$table_exists) {
            return 0;
        }

        // DAO-bypass-approved: Diagnostic count for uninstall-modal preview
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status != " . ABJ404_STATUS_TRASH);

        return $count ? intval($count) : 0;
    }

    /**
     * Get comprehensive plugin statistics for the diagnostic email.
     * Includes redirect counts by type, captured 404s, log entries, and storage sizes.
     *
     * @return array{redirects: array<string, int>, captured: array<string, int>, log_count: int, log_table_size_mb: float, debug_file_size_mb: float}
     */
    public static function getPluginStatistics(): array {
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
            $viewRead = abj_service('view_read_service');

            $redirectCounts = $viewRead->getRedirectStatusCounts(true);
            if (is_array($redirectCounts)) {
                $stats['redirects'] = $redirectCounts;
            }

            $capturedCounts = $viewRead->getCapturedStatusCounts(true);
            if (is_array($capturedCounts)) {
                $stats['captured'] = $capturedCounts;
            }

            $stats['log_count'] = $viewRead->getLogsCount(0);

            $logTableSizeBytes = $viewRead->getLogDiskUsage();
            if ($logTableSizeBytes > 0) {
                $stats['log_table_size_mb'] = round($logTableSizeBytes / (1024 * 1024), 2);
            }

            if (class_exists('ABJ_404_Solution_Logging')) {
                $logger = abj_service('logging');
                $debugFilePath = $logger->getDebugFilePath();
                if (file_exists($debugFilePath)) {
                    $debugFileSize = filesize($debugFilePath);
                    $stats['debug_file_size_mb'] = round($debugFileSize / (1024 * 1024), 2);
                }
            }
        } catch (\Throwable $e) {
            // Surface which call failed so the support-bundle reader sees the
            // reason values are missing instead of silently returning defaults.
            $stats['_errors'][] = 'getDebugFileSize: ' . $e->getMessage();
        }

        return $stats;
    }

    /**
     * Get counts of categories, tags, pages, and posts for diagnostics.
     * These counts help identify if memory issues are caused by large content volume.
     *
     * @return array{categories: int, tags: int, pages: int, posts: int}
     */
    public static function getContentCounts(): array {
        $counts = array(
            'categories' => 0,
            'tags' => 0,
            'pages' => 0,
            'posts' => 0,
        );

        if (!function_exists('wp_count_terms') || !function_exists('wp_count_posts')) {
            return $counts;
        }

        $category_count = wp_count_terms(array('taxonomy' => 'category', 'hide_empty' => false));
        if (!is_wp_error($category_count)) {
            $counts['categories'] = intval($category_count);
        }

        if (function_exists('taxonomy_exists') && taxonomy_exists('product_cat')) {
            $product_cat_count = wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            if (!is_wp_error($product_cat_count)) {
                $counts['categories'] += intval($product_cat_count);
            }
        }

        $tag_count = wp_count_terms(array('taxonomy' => 'post_tag', 'hide_empty' => false));
        if (!is_wp_error($tag_count)) {
            $counts['tags'] = intval($tag_count);
        }

        if (function_exists('taxonomy_exists') && taxonomy_exists('product_tag')) {
            $product_tag_count = wp_count_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
            if (!is_wp_error($product_tag_count)) {
                $counts['tags'] += intval($product_tag_count);
            }
        }

        $page_counts = wp_count_posts('page');
        if (isset($page_counts->publish)) {
            $counts['pages'] = intval($page_counts->publish);
        }

        $post_counts = wp_count_posts('post');
        if (isset($post_counts->publish)) {
            $counts['posts'] = intval($post_counts->publish);
        }

        if (function_exists('post_type_exists') && post_type_exists('product')) {
            $product_counts = wp_count_posts('product');
            if (isset($product_counts->publish)) {
                $counts['posts'] += intval($product_counts->publish);
            }
        }

        return $counts;
    }

    /**
     * Get a comma-separated list of active plugin names (capped at 10).
     *
     * @return string Comma-separated list of active plugin names
     */
    public static function getActivePluginsList(): string {
        if (!function_exists('get_plugins')) {
            $pluginFile = ABSPATH . 'wp-admin/includes/plugin.php';
            if (!is_readable($pluginFile)) {
                return 'Unavailable: wp-admin/includes/plugin.php not readable';
            }
            require_once $pluginFile;
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());
        if (!is_array($active_plugins)) {
            $active_plugins = array();
        }

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
     * @return array{version: string, charset: string, collation: string}
     */
    public static function getDatabaseInfo(): array {
        global $wpdb;

        $info = array(
            'version' => 'Unknown',
            'charset' => 'Unknown',
            'collation' => 'Unknown',
        );

        // DAO-bypass-approved: Diagnostic MySQL VERSION() for support email
        $version = $wpdb->get_var("SELECT VERSION()");
        if ($version) {
            $info['version'] = $version;
        }

        if (!defined('DB_NAME')) {
            // Test environment. Use wpdb defaults.
            $charset = isset($wpdb->charset) ? $wpdb->charset : '';
            $collate = isset($wpdb->collate) ? $wpdb->collate : '';
            $info['charset'] = $charset ?: 'utf8mb4';
            $info['collation'] = $collate ?: 'utf8mb4_unicode_ci';
            return $info;
        }

        $db_name = DB_NAME;
        // DAO-bypass-approved: Diagnostic database-default charset/collation probe.
        $charset_query = $wpdb->prepare(
            "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME " .
            "FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s",
            $db_name
        );
        // DAO-bypass-approved: Diagnostic information_schema.SCHEMATA probe
        $db_result = $wpdb->get_row($charset_query, ARRAY_A);

        if ($db_result && !empty($db_result['DEFAULT_CHARACTER_SET_NAME'])) {
            $info['charset'] = $db_result['DEFAULT_CHARACTER_SET_NAME'];
            $info['collation'] = $db_result['DEFAULT_COLLATION_NAME'] ?? 'Unknown';
            return $info;
        }

        // DAO-bypass-approved: Diagnostic server variable readout for support email
        $charset_result = $wpdb->get_row("SHOW VARIABLES LIKE 'character_set_database'", ARRAY_A);
        // DAO-bypass-approved: Diagnostic server variable readout for support email
        $collation_result = $wpdb->get_row("SHOW VARIABLES LIKE 'collation_database'", ARRAY_A);

        if ($charset_result && isset($charset_result['Value'])) {
            $info['charset'] = $charset_result['Value'];
        }
        if ($collation_result && isset($collation_result['Value'])) {
            $info['collation'] = $collation_result['Value'];
        }

        if ($info['charset'] === 'Unknown') {
            $charset = isset($wpdb->charset) ? $wpdb->charset : '';
            $info['charset'] = $charset ?: (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
        }
        if ($info['collation'] === 'Unknown') {
            $collate = isset($wpdb->collate) ? $wpdb->collate : '';
            $info['collation'] = $collate ?: 'utf8mb4_unicode_ci';
        }

        return $info;
    }

    /**
     * Capture charset/collation details for key plugin tables.
     *
     * @return string Human-readable summary for email diagnostics
     */
    public static function getDatabaseCollationSnapshot(): string {
        global $wpdb;

        $summaryLines = array();
        $summaryLines[] = "Table prefix: " . $wpdb->prefix;
        $summaryLines[] = "";

        if (!class_exists('ABJ_404_Solution_DatabaseUpgradesEtc') ||
            !class_exists('ABJ_404_Solution_DataAccess')) {
            $summaryLines[] = "Collation details unavailable (required classes not loaded).";
            return implode("\n", $summaryLines);
        }

        $dbCore = abj_service('db_core');

        $targetTable = $wpdb->prefix . 'posts';
        $targetInfo = self::getTableInfo($targetTable);

        if (isset($targetInfo['error'])) {
            $summaryLines[] = "Could not read collation for {$targetTable} (baseline): " . $targetInfo['error'];
            return implode("\n", $summaryLines);
        }

        $targetCollation = isset($targetInfo['collation']) ? $targetInfo['collation'] : '';
        $targetCharset = isset($targetInfo['charset']) ? $targetInfo['charset'] : '';
        $targetEngine = isset($targetInfo['engine']) ? $targetInfo['engine'] : '';

        $summaryLines[] = sprintf(
            "%s -> %s / %s / %s (baseline)",
            $targetTable,
            $targetCharset,
            $targetCollation,
            $targetEngine
        );

        $prefix = $dbCore->getLowercasePrefix();
        if (is_object($wpdb) && method_exists($wpdb, 'esc_like')) {
            $escapedPrefix = $wpdb->esc_like($prefix . 'abj404_');
        } else {
            $escapedPrefix = addcslashes($prefix . 'abj404_', '_%\\');
        }
        // DAO-bypass-approved: Diagnostic table enumeration needs SHOW TABLES metadata directly.
        $rawTables = $wpdb->get_results(
            $wpdb->prepare("SHOW TABLES LIKE %s", $escapedPrefix . '%'),
            ARRAY_N
        );
        $pluginTables = array();
        foreach ($rawTables as $row) {
            $fullName = $row[0];
            $pluginTables[$fullName] = $fullName;
        }

        foreach ($pluginTables as $label => $tableName) {
            $tableInfo = self::getTableInfo($tableName);

            if (isset($tableInfo['error'])) {
                $summaryLines[] = sprintf(
                    "%s (%s) -> unavailable (%s)",
                    $label,
                    $tableName,
                    $tableInfo['error']
                );
                continue;
            }

            $collation = isset($tableInfo['collation']) ? $tableInfo['collation'] : '';
            $charset = isset($tableInfo['charset']) ? $tableInfo['charset'] : '';
            $engine = isset($tableInfo['engine']) ? $tableInfo['engine'] : '';

            $matchesBaseline = ($collation === $targetCollation && $charset === $targetCharset);
            $utf8mb4Note = (is_string($charset) && stripos($charset, 'utf8mb4') === false) ? ' [non-utf8mb4]' : '';
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
     * @return array{charset?: string|null, collation?: string|null, engine?: string, error?: string, source?: string}
     */
    public static function getTableInfo(string $tableName): array {
        $result = self::tryInformationSchema($tableName);
        if ($result !== null && !isset($result['error'])) {
            return $result;
        }

        $result = self::tryShowTableStatus($tableName);
        if ($result !== null && !isset($result['error'])) {
            return $result;
        }

        $result = self::tryShowCreateTable($tableName);
        if ($result !== null && !isset($result['error'])) {
            return $result;
        }

        return self::getWpdbDefaults();
    }

    /**
     * Try to get table info from information_schema.
     *
     * @param string $tableName Table name to look up
     * @return array{charset?: string|null, collation?: string|null, engine?: string, error?: string}|null
     */
    private static function tryInformationSchema(string $tableName) {
        /** @var \wpdb $wpdb */
        global $wpdb;

        if (!method_exists($wpdb, 'get_row')) {
            return array('error' => 'wpdb methods unavailable');
        }

        // DAO-bypass-approved: Diagnostic table charset/collation metadata probe.
        $query = $wpdb->prepare(
            "SELECT TABLE_COLLATION, ENGINE, " .
            "SUBSTRING_INDEX(TABLE_COLLATION, '_', 1) as TABLE_CHARSET " .
            "FROM information_schema.tables " .
            "WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()",
            $tableName
        );

        // DAO-bypass-approved: Diagnostic information_schema.tables probe
        $result = $wpdb->get_row($query, ARRAY_A);

        if (!empty($wpdb->last_error)) {
            if (stripos($wpdb->last_error, 'denied') !== false ||
                stripos($wpdb->last_error, 'permission') !== false) {
                return array('error' => 'permission denied');
            }
            return array('error' => 'query error');
        }

        if (empty($result)) {
            return null;
        }

        $result = array_change_key_case($result, CASE_UPPER);

        $collation = isset($result['TABLE_COLLATION']) && is_string($result['TABLE_COLLATION']) ? $result['TABLE_COLLATION'] : null;
        $engine = isset($result['ENGINE']) && is_string($result['ENGINE']) ? $result['ENGINE'] : 'Unknown';
        $charset = isset($result['TABLE_CHARSET']) && is_string($result['TABLE_CHARSET']) ? $result['TABLE_CHARSET'] : null;

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
     * @return array{charset?: string|null, collation?: string|null, engine?: string, error?: string}|null
     */
    private static function tryShowTableStatus(string $tableName) {
        /** @var \wpdb $wpdb */
        global $wpdb;

        if (!method_exists($wpdb, 'get_row')) {
            return array('error' => 'wpdb methods unavailable');
        }

        // DAO-bypass-approved: Diagnostic fallback metadata probe needs SHOW TABLE STATUS directly.
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

        $collation = isset($result['Collation']) && is_string($result['Collation']) ? $result['Collation'] : null;
        $engine = isset($result['Engine']) && is_string($result['Engine']) ? $result['Engine'] : 'Unknown';
        $charset = (is_string($collation) && $collation !== '') ? explode('_', $collation)[0] : null;

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
     * @return array{charset?: string|null, collation?: string|null, engine?: string, error?: string}|null
     */
    private static function tryShowCreateTable(string $tableName) {
        /** @var \wpdb $wpdb */
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            return null;
        }

        // @utf8-audit: opt-out. $tableName is built from $wpdb->prefix plus
        // 'abj404_*' constants by the uninstall flow; never user input.
        // DAO-bypass-approved: Diagnostic last-resort SHOW CREATE TABLE charset parse
        $result = $wpdb->get_row("SHOW CREATE TABLE `" . esc_sql($tableName) . "`", ARRAY_N);

        if (empty($result[1])) {
            return null;
        }

        $ddl = is_string($result[1]) ? $result[1] : '';

        preg_match('/(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)(?:\s*=\s*|\s+)([\w\d]+)/i', $ddl, $charsetMatch);
        preg_match('/(?:DEFAULT\s+)?COLLATE(?:\s*=\s*|\s+)([\w\d_]+)/i', $ddl, $collationMatch);
        preg_match('/ENGINE\s*=\s*([\w]+)/i', $ddl, $engineMatch);

        $charset = $charsetMatch[1] ?? null;
        $collation = $collationMatch[1] ?? null;
        $engine = $engineMatch[1] ?? 'Unknown';

        if ($charset && !$collation) {
            $collation = $charset . '_general_ci';
        }

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
     * @return array{charset: string, collation: string, engine: string, source: string}
     */
    private static function getWpdbDefaults(): array {
        global $wpdb;

        $charset = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

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
