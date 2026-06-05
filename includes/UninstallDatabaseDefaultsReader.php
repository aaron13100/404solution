<?php
// allow-no-test-found: covered by tests/UninstallDiagnosticsEntryPointTest.php public uninstall modal/email entry points

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads database-level version, charset, and collation defaults for uninstall
 * feedback diagnostics.
 */
class ABJ_404_Solution_UninstallDatabaseDefaultsReader {

    /**
     * @return array{version:string,charset:string,collation:string}
     */
    public function getDatabaseInfo(): array {
        $info = array(
            'version' => $this->databaseVersion(),
            'charset' => 'Unknown',
            'collation' => 'Unknown',
        );

        if (!defined('DB_NAME')) {
            return $this->applyWpdbDefaults($info, false);
        }

        $schemaDefaults = $this->schemaDefaults();
        if ($schemaDefaults !== null) {
            $info['charset'] = $schemaDefaults['charset'];
            $info['collation'] = $schemaDefaults['collation'];
            return $info;
        }

        $variableDefaults = $this->serverVariableDefaults();
        $info['charset'] = $variableDefaults['charset'];
        $info['collation'] = $variableDefaults['collation'];

        return $this->applyWpdbDefaults($info, true);
    }

    private function databaseVersion(): string {
        global $wpdb;

        // DAO-bypass-approved: Diagnostic MySQL VERSION() for support email
        $version = $wpdb->get_var("SELECT VERSION()");
        return is_scalar($version) && (string)$version !== '' ? (string)$version : 'Unknown';
    }

    /**
     * @return array{charset:string,collation:string}|null
     */
    private function schemaDefaults(): ?array {
        global $wpdb;

        // DAO-bypass-approved: Diagnostic database-default charset/collation probe.
        $charsetQuery = $wpdb->prepare(
            "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME " .
            "FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s",
            DB_NAME
        );
        // DAO-bypass-approved: Diagnostic information_schema.SCHEMATA probe
        $dbResult = $wpdb->get_row($charsetQuery, ARRAY_A);
        if (!is_array($dbResult)) {
            return null;
        }

        $charset = $this->rowValue($dbResult, 'DEFAULT_CHARACTER_SET_NAME');
        if ($charset === null || $charset === '') {
            return null;
        }

        $collation = $this->rowValue($dbResult, 'DEFAULT_COLLATION_NAME');
        return array(
            'charset' => $charset,
            'collation' => $collation !== null && $collation !== '' ? $collation : 'Unknown',
        );
    }

    /**
     * @return array{charset:string,collation:string}
     */
    private function serverVariableDefaults(): array {
        global $wpdb;

        // DAO-bypass-approved: Diagnostic server variable readout for support email
        $charsetResult = $wpdb->get_row("SHOW VARIABLES LIKE 'character_set_database'", ARRAY_A);
        // DAO-bypass-approved: Diagnostic server variable readout for support email
        $collationResult = $wpdb->get_row("SHOW VARIABLES LIKE 'collation_database'", ARRAY_A);

        return array(
            'charset' => is_array($charsetResult) ? ($this->rowValue($charsetResult, 'Value') ?: 'Unknown') : 'Unknown',
            'collation' => is_array($collationResult) ? ($this->rowValue($collationResult, 'Value') ?: 'Unknown') : 'Unknown',
        );
    }

    /**
     * @param array{version:string,charset:string,collation:string} $info
     * @return array{version:string,charset:string,collation:string}
     */
    private function applyWpdbDefaults(array $info, bool $allowDbCharsetConstant): array {
        if ($info['charset'] === 'Unknown') {
            $charset = $this->wpdbStringProperty('charset');
            $info['charset'] = $charset !== '' ? $charset : $this->defaultCharset($allowDbCharsetConstant);
        }
        if ($info['collation'] === 'Unknown') {
            $collation = $this->wpdbStringProperty('collate');
            $info['collation'] = $collation !== '' ? $collation : 'utf8mb4_unicode_ci';
        }
        return $info;
    }

    private function defaultCharset(bool $allowDbCharsetConstant): string {
        return $allowDbCharsetConstant && defined('DB_CHARSET') && DB_CHARSET ? DB_CHARSET : 'utf8mb4';
    }

    private function wpdbStringProperty(string $property): string {
        global $wpdb;

        return isset($wpdb->$property) && is_string($wpdb->$property) ? $wpdb->$property : '';
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
