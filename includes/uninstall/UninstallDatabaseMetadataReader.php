<?php
// allow-no-test-found: covered by tests/UninstallDiagnosticsEntryPointTest.php public uninstall modal/email entry points

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads per-table metadata for uninstall diagnostics. Each table probe uses
 * the production fallback chain: information_schema, SHOW TABLE STATUS,
 * SHOW CREATE TABLE, then wpdb defaults.
 */
class ABJ_404_Solution_UninstallDatabaseMetadataReader {

    /**
     * @param string $tableName Table name to look up.
     * @return array{charset?:string|null,collation?:string|null,engine?:string,error?:string,source?:string}
     */
    public function getTableInfo(string $tableName): array {
        $result = $this->tryInformationSchema($tableName);
        if ($result !== null && !isset($result['error'])) {
            return $result;
        }

        $result = $this->tryShowTableStatus($tableName);
        if ($result !== null && !isset($result['error'])) {
            return $result;
        }

        $result = $this->tryShowCreateTable($tableName);
        if ($result !== null && !isset($result['error'])) {
            return $result;
        }

        return $this->getWpdbDefaults();
    }

    /**
     * @param string $tableName Table name to look up.
     * @return array{charset?:string|null,collation?:string|null,engine?:string,error?:string}|null
     */
    public function tryInformationSchema(string $tableName) {
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

        if (empty($result) || !is_array($result)) {
            return null;
        }

        $collation = $this->rowValue($result, 'TABLE_COLLATION');
        $engine = $this->rowValue($result, 'ENGINE') ?: 'Unknown';
        $charset = $this->rowValue($result, 'TABLE_CHARSET');

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
     * @param string $tableName Table name to look up.
     * @return array{charset?:string|null,collation?:string|null,engine?:string,error?:string}|null
     */
    public function tryShowTableStatus(string $tableName) {
        /** @var \wpdb $wpdb */
        global $wpdb;

        if (!method_exists($wpdb, 'get_row')) {
            return array('error' => 'wpdb methods unavailable');
        }

        // DAO-bypass-approved: Diagnostic fallback metadata probe needs SHOW TABLE STATUS directly.
        $result = $wpdb->get_row(
            // DAO-bypass-approved: prepare() is part of diagnostic SHOW TABLE STATUS metadata probing.
            $wpdb->prepare("SHOW TABLE STATUS LIKE %s", $tableName),
            ARRAY_A
        );

        if (!empty($wpdb->last_error)) {
            return array('error' => 'SHOW TABLE STATUS failed');
        }

        if (empty($result) || !is_array($result)) {
            return null;
        }

        $collation = $this->rowValue($result, 'Collation');
        $engine = $this->rowValue($result, 'Engine') ?: 'Unknown';
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
     * @param string $tableName Table name to look up.
     * @return array{charset?:string|null,collation?:string|null,engine?:string,error?:string}|null
     */
    public function tryShowCreateTable(string $tableName) {
        /** @var \wpdb $wpdb */
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            return null;
        }

        // @utf8-audit: opt-out - $tableName is built from $wpdb->prefix plus
        // 'abj404_*' constants by the uninstall flow; never user input.
        // DAO-bypass-approved: Diagnostic last-resort SHOW CREATE TABLE charset parse
        $result = $wpdb->get_row("SHOW CREATE TABLE `" . esc_sql($tableName) . "`", ARRAY_N);

        if (empty($result[1])) {
            return null;
        }

        $ddl = is_string($result[1]) ? $result[1] : '';

        // Charset, collation and engine are all TABLE OPTIONS, which sit after
        // the closing paren of the body. Column definitions come first in real
        // engine output and a column may carry its own `CHARACTER SET x COLLATE
        // y`, so a pattern run over the whole statement reports the first
        // COLUMN's charset as the table's. This tier is the last one the
        // uninstall diagnostics try, and what it returns is sent to the
        // developer as the site's actual schema, so a confident wrong answer
        // here sends a charset investigation after a fiction.
        $tableDefault = ABJ_404_Solution_CreateTableOptionsParser::tableCharsetAndCollation($ddl);
        if ($tableDefault === null) {
            return null;
        }

        $charset = $tableDefault['charset'];
        $collation = $tableDefault['collation'];
        $engine = ABJ_404_Solution_CreateTableOptionsParser::tableEngine($ddl) ?? 'Unknown';

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
     * @return array{charset:string,collation:string,engine:string,source:string}
     */
    public function getWpdbDefaults(): array {
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

    /**
     * @param array<array-key, mixed> $row
     * @param string $key
     * @return string|null
     */
    private function rowValue(array $row, string $key): ?string {
        foreach ($row as $rowKey => $value) {
            if (is_string($rowKey) && strcasecmp($rowKey, $key) === 0) {
                return is_string($value) ? $value : null;
            }
        }
        return null;
    }
}
