<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects the database family/version used in outbound feedback payloads.
 *
 * FeedbackTransport only needs the normalized wire value, but the detection
 * requires several engine-specific probes. Keeping those probes here prevents
 * the transport from growing a second database-detection responsibility.
 */
class ABJ_404_Solution_FeedbackDatabaseIdentity {

    /**
     * Detect the active database family and printable version without letting
     * one engine-specific probe abort report creation. MySQL-compatible wpdb
     * installs usually answer SELECT VERSION(); SQLite-backed drop-ins may not,
     * so PDO driver/schema signals are checked separately and failures degrade
     * to the base db_version() value.
     *
     * @param mixed $wpdb
     * @return array{type: string, version: string}
     */
    public static function detect($wpdb): array {
        $dbVersion = '';
        if (is_object($wpdb) && method_exists($wpdb, 'db_version')) {
            try {
                $raw = $wpdb->db_version();
                $dbVersion = is_scalar($raw) ? (string)$raw : '';
            } catch (\Throwable $e) {
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackTransport db_version probe failed: ' . $e->getMessage());
            }
        }

        // db_version() typically returns the numeric portion only; for
        // mariadb detection we also probe the full VERSION() string.
        $fullVersion = $dbVersion;
        if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            try {
                // DAO-bypass-approved: SELECT VERSION() is a parameterless server-introspection probe with no plugin tables involved; routing through queryAndGetResults() would force a missing-table-repair detour for a query that cannot fail with that error class
                $probed = $wpdb->get_var('SELECT VERSION()');
                if (is_scalar($probed) && trim((string)$probed) !== '') {
                    $fullVersion = (string)$probed;
                }
            } catch (\Throwable $e) {
                ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackTransport SELECT VERSION() probe failed: ' . $e->getMessage());
            }
        }

        $pdoDriver = self::wpdbPdoDriverName($wpdb);
        $schemaSignal = $pdoDriver === '' ? self::databaseSchemaSignal() : '';

        return array(
            'type'    => self::classifyDbType($pdoDriver, $fullVersion, $schemaSignal, $wpdb),
            'version' => $fullVersion,
        );
    }

    /**
     * @param mixed $wpdb
     * @return string Lowercase PDO driver name, or empty string when unavailable.
     */
    private static function wpdbPdoDriverName($wpdb): string {
        if (!is_object($wpdb) || !isset($wpdb->dbh) || !is_object($wpdb->dbh) ||
            !method_exists($wpdb->dbh, 'getAttribute') || !class_exists('PDO', false)) {
            return '';
        }

        try {
            $driver = $wpdb->dbh->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return is_scalar($driver) ? strtolower(trim((string)$driver)) : '';
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackTransport PDO driver probe failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * @return string Best-effort CREATE TABLE schema text. Empty when the
     *                WordPress schema helper is unavailable or fails.
     */
    private static function databaseSchemaSignal(): string {
        if (!function_exists('wp_get_db_schema')) {
            return '';
        }

        try {
            $schema = wp_get_db_schema();
            return is_string($schema) ? $schema : '';
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackTransport wp_get_db_schema probe failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * @param mixed $wpdb
     */
    private static function classifyDbType(string $pdoDriver, string $fullVersion, string $schemaSignal, $wpdb): string {
        $driver = strtolower(trim($pdoDriver));
        $version = strtolower(trim($fullVersion));
        $serverInfo = strtolower(self::wpdbServerInfo($wpdb));
        $schema = strtolower($schemaSignal);

        if ($driver === 'sqlite' || strpos($version, 'sqlite') !== false || self::schemaLooksSqlite($schema)) {
            return 'sqlite';
        }
        if (strpos($version, 'mariadb') !== false || strpos($serverInfo, 'mariadb') !== false) {
            return 'mariadb';
        }
        if ($driver === 'mysql' || $driver === 'mysqli') {
            return 'mysql';
        }
        if ($driver !== '') {
            return 'other';
        }
        if (is_object($wpdb) && isset($wpdb->is_mysql)) {
            return $wpdb->is_mysql === true ? 'mysql' : 'other';
        }
        if (strpos($version, 'mysql') !== false) {
            return 'mysql';
        }
        if (preg_match('/^\d+(?:\.\d+){1,3}(?:[-+].*)?$/', $version) === 1) {
            return 'mysql';
        }

        return 'other';
    }

    private static function schemaLooksSqlite(string $schema): bool {
        if ($schema === '') {
            return false;
        }
        if (strpos($schema, 'sqlite') !== false) {
            return true;
        }
        return preg_match('/integer\s+primary\s+key\s+autoincrement/i', $schema) === 1;
    }

    /**
     * @param mixed $wpdb
     */
    private static function wpdbServerInfo($wpdb): string {
        if (!is_object($wpdb)) {
            return '';
        }
        if (isset($wpdb->db_server_info) && is_scalar($wpdb->db_server_info)) {
            return (string)$wpdb->db_server_info;
        }
        if (!method_exists($wpdb, 'db_server_info')) {
            return '';
        }

        try {
            $info = $wpdb->db_server_info();
            return is_scalar($info) ? (string)$info : '';
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'FeedbackTransport db_server_info probe failed: ' . $e->getMessage());
            return '';
        }
    }
}
