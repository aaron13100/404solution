<?php
// allow-no-test-found: covered by tests/UninstallDiagnosticsEntryPointTest.php public uninstall modal/email entry points

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats database table charset/collation details for uninstall feedback.
 */
class ABJ_404_Solution_UninstallCollationSnapshotPresenter {

    /** @var ABJ_404_Solution_UninstallDatabaseMetadataReader */
    private $metadataReader;

    /**
     * @param ABJ_404_Solution_UninstallDatabaseMetadataReader $metadataReader
     */
    public function __construct(ABJ_404_Solution_UninstallDatabaseMetadataReader $metadataReader) {
        $this->metadataReader = $metadataReader;
    }

    /**
     * @return string Human-readable summary for email diagnostics.
     */
    public function present(): string {
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
        $targetInfo = $this->metadataReader->getTableInfo($targetTable);

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

        $prefix = $dbCore->tableNameResolver()->getLowercasePrefix();
        if (is_object($wpdb) && method_exists($wpdb, 'esc_like')) {
            $escapedPrefix = $wpdb->esc_like($prefix . 'abj404_');
        } else {
            $escapedPrefix = addcslashes($prefix . 'abj404_', '_%\\');
        }

        // DAO-bypass-approved: Diagnostic table enumeration needs SHOW TABLES metadata directly.
        $rawTables = $wpdb->get_results(
            // DAO-bypass-approved: prepare() is part of diagnostic SHOW TABLES metadata enumeration.
            $wpdb->prepare("SHOW TABLES LIKE %s", $escapedPrefix . '%'),
            ARRAY_N
        );
        $pluginTables = array();
        foreach ($rawTables as $row) {
            $fullName = $row[0];
            $pluginTables[$fullName] = $fullName;
        }

        foreach ($pluginTables as $label => $tableName) {
            $tableInfo = $this->metadataReader->getTableInfo($tableName);

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
}
