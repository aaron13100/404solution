<?php
/**
 * Table-name parsing and metadata probes for database error handling.
 */

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through DatabaseErrorClassifier facade by tests/MissingTableErrorDoubleReportingTest.php and tests/DataAccessRetrySemanticsTest.php
class ABJ_404_Solution_DatabaseErrorTableInspector {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Extract a table name from a MySQL "table is full" error message.
     *
     * @param string $errorText
     * @return string|null
     */
    public function extractTableNameFromFullError(string $errorText): ?string {
        if (preg_match("/table '([^']+)' is full/i", $errorText, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Extract the table name from a MySQL "doesn't exist" error message.
     *
     * @param string $errorText
     * @return string
     */
    public function extractMissingTableNameFromError(string $errorText): string {
        if ($errorText === '') {
            return '';
        }
        if (!preg_match("/Table '([^']+)' doesn't exist/i", $errorText, $matches)) {
            return '';
        }
        $fullName = $matches[1];
        $dotPos = strrpos($fullName, '.');
        return $dotPos !== false ? substr($fullName, $dotPos + 1) : $fullName;
    }

    /**
     * Check if a given table uses the InnoDB storage engine.
     *
     * @param string $tableName
     * @return bool
     */
    public function isInnoDBTable(string $tableName): bool {
        global $wpdb;
        /** @var wpdb $wpdb */
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return false;
        }
        if (defined('DB_NAME')) {
            $dbName = (string)DB_NAME;
        } else {
            static $warnedNoDbName = false;
            if (!$warnedNoDbName) {
                $warnedNoDbName = true;
                $this->logger->warn(__METHOD__ . ': DB_NAME undefined; using empty schema in InnoDB probe');
            }
            $dbName = '';
        }
        // DAO-bypass-approved: read-only information_schema engine probe for classifying table-full DB errors.
        $engine = $wpdb->get_var(
            // DAO-bypass-approved: prepare call for read-only information_schema engine probe placeholders.
            $wpdb->prepare(
                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                $dbName,
                $tableName
            )
        );
        return is_string($engine) && strtolower($engine) === 'innodb';
    }
}
