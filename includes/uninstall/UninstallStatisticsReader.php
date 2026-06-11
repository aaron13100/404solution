<?php
// allow-no-test-found: covered by tests/UninstallDiagnosticsEntryPointTest.php public uninstall modal/email entry points

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads uninstall feedback statistics for redirects, captured hits, logs,
 * and debug-file size.
 */
class ABJ_404_Solution_UninstallStatisticsReader {

    /**
     * @return int Number of active non-trashed redirects, or 0 if unavailable.
     */
    public function getRedirectCount(): int {
        global $wpdb;

        $tableName = $this->redirectsTableName();

        // DAO-bypass-approved: Diagnostic table-existence probe for redirect-count display
        $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tableName)) === $tableName;
        if (!$tableExists) {
            return 0;
        }

        // DAO-bypass-approved: Diagnostic count for uninstall-modal preview
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $tableName WHERE status != " . ABJ404_STATUS_TRASH);
        return $count ? intval($count) : 0;
    }

    /**
     * @return array{
     *     redirects: array{all:int,manual:int,auto:int,regex:int,trash:int},
     *     captured: array{all:int,captured:int,ignored:int,later:int,trash:int},
     *     log_count: int,
     *     log_table_size_mb: int|float,
     *     debug_file_size_mb: int|float,
     *     _errors?: list<string>
     * }
     */
    public function getPluginStatistics(): array {
        $stats = array(
            'redirects' => array('all' => 0, 'manual' => 0, 'auto' => 0, 'regex' => 0, 'trash' => 0),
            'captured' => array('all' => 0, 'captured' => 0, 'ignored' => 0, 'later' => 0, 'trash' => 0),
            'log_count' => 0,
            'log_table_size_mb' => 0,
            'debug_file_size_mb' => 0,
        );

        if (!class_exists('ABJ_404_Solution_DataAccess')) {
            return $stats;
        }

        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'get_results')) {
            return $stats;
        }

        try {
            $viewRead = abj_service('view_read_service');

            $redirectCounts = $viewRead->getRedirectStatusCounts(true);
            if (is_array($redirectCounts)) {
                $stats['redirects'] = array(
                    'all' => self::intValue($redirectCounts, 'all'),
                    'manual' => self::intValue($redirectCounts, 'manual'),
                    'auto' => self::intValue($redirectCounts, 'auto'),
                    'regex' => self::intValue($redirectCounts, 'regex'),
                    'trash' => self::intValue($redirectCounts, 'trash'),
                );
            }

            $capturedCounts = $viewRead->getCapturedStatusCounts(true);
            if (is_array($capturedCounts)) {
                $stats['captured'] = array(
                    'all' => self::intValue($capturedCounts, 'all'),
                    'captured' => self::intValue($capturedCounts, 'captured'),
                    'ignored' => self::intValue($capturedCounts, 'ignored'),
                    'later' => self::intValue($capturedCounts, 'later'),
                    'trash' => self::intValue($capturedCounts, 'trash'),
                );
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
            $stats['_errors'][] = 'getDebugFileSize: ' . $e->getMessage();
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $values
     * @return int
     */
    private static function intValue(array $values, string $key): int {
        return isset($values[$key]) && is_numeric($values[$key]) ? (int)$values[$key] : 0;
    }

    /**
     * @return string
     */
    private function redirectsTableName(): string {
        global $wpdb;

        if (class_exists('ABJ_404_Solution_ServiceContainer', false)) {
            $container = ABJ_404_Solution_ServiceContainer::getInstance();
            if ($container->has('db_core')) {
                $dbCore = $container->get('db_core');
                if ($dbCore instanceof ABJ_404_Solution_DatabaseCoreInterface) {
                    return $dbCore->tableNameResolver()->getPrefixedTableName('abj404_redirects');
                }
            }
        }

        // Plugin tables are created lowercased; normalize $wpdb->prefix to
        // lowercase so MySQL hosts with a mixed-case prefix still find the
        // physical table (CentralizedTableNameTest invariant).
        $prefix = isset($wpdb->prefix) && is_string($wpdb->prefix) ? strtolower($wpdb->prefix) : '';
        return $prefix . 'abj404_redirects';
    }
}
