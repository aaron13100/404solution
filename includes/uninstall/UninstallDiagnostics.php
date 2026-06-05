<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public facade for uninstall diagnostics used by the modal and feedback email.
 *
 * Concrete readers own statistics, WordPress inventory, database metadata,
 * and collation snapshot presentation. This class preserves the historical
 * static API used by uninstall entry points.
 *
 * @since 4.3.0
 */
class ABJ_404_Solution_UninstallDiagnostics {

    /**
     * Get the count of redirects for display in the modal preview.
     *
     * @return int Number of active non-trashed redirects, or 0 if the table is missing.
     */
    public static function getRedirectCount(): int {
        return self::statisticsReader()->getRedirectCount();
    }

    /**
     * Get comprehensive plugin statistics for the diagnostic email.
     *
     * @return array{
     *     redirects: array{all:int,manual:int,auto:int,regex:int,trash:int},
     *     captured: array{all:int,captured:int,ignored:int,later:int,trash:int},
     *     log_count: int,
     *     log_table_size_mb: int|float,
     *     debug_file_size_mb: int|float,
     *     _errors?: list<string>
     * }
     */
    public static function getPluginStatistics(): array {
        return self::statisticsReader()->getPluginStatistics();
    }

    /**
     * Get counts of categories, tags, pages, and posts for diagnostics.
     *
     * @return array{categories:int,tags:int,pages:int,posts:int}
     */
    public static function getContentCounts(): array {
        return self::wordPressInventory()->getContentCounts();
    }

    /**
     * Get a comma-separated list of active plugin names capped at 10.
     *
     * @return string
     */
    public static function getActivePluginsList(): string {
        return self::wordPressInventory()->getActivePluginsList();
    }

    /**
     * Get database version and charset info for diagnostics.
     *
     * @return array{version:string,charset:string,collation:string}
     */
    public static function getDatabaseInfo(): array {
        return self::databaseDefaultsReader()->getDatabaseInfo();
    }

    /**
     * Capture charset/collation details for key plugin tables.
     *
     * @return string Human-readable summary for email diagnostics.
     */
    public static function getDatabaseCollationSnapshot(): string {
        return self::collationSnapshotPresenter()->present();
    }

    /**
     * Get table info with fallback chain for locked-down hosts.
     *
     * @param string $tableName Table name to look up.
     * @return array{charset?:string|null,collation?:string|null,engine?:string,error?:string,source?:string}
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
     * Compatibility wrapper for legacy reflection tests.
     *
     * @param string $tableName Table name to look up.
     * @return array{charset?:string|null,collation?:string|null,engine?:string,error?:string}|null
     */
    private static function tryInformationSchema(string $tableName) {
        return self::databaseMetadataReader()->tryInformationSchema($tableName);
    }

    /**
     * Compatibility wrapper for legacy reflection tests.
     *
     * @param string $tableName Table name to look up.
     * @return array{charset?:string|null,collation?:string|null,engine?:string,error?:string}|null
     */
    private static function tryShowTableStatus(string $tableName) {
        return self::databaseMetadataReader()->tryShowTableStatus($tableName);
    }

    /**
     * Compatibility wrapper for legacy reflection tests.
     *
     * @param string $tableName Table name to look up.
     * @return array{charset?:string|null,collation?:string|null,engine?:string,error?:string}|null
     */
    private static function tryShowCreateTable(string $tableName) {
        return self::databaseMetadataReader()->tryShowCreateTable($tableName);
    }

    /**
     * Compatibility wrapper for legacy reflection tests.
     *
     * @return array{charset:string,collation:string,engine:string,source:string}
     */
    private static function getWpdbDefaults(): array {
        return self::databaseMetadataReader()->getWpdbDefaults();
    }

    private static function statisticsReader(): ABJ_404_Solution_UninstallStatisticsReader {
        return new ABJ_404_Solution_UninstallStatisticsReader();
    }

    private static function wordPressInventory(): ABJ_404_Solution_UninstallWordPressInventory {
        return new ABJ_404_Solution_UninstallWordPressInventory();
    }

    private static function databaseMetadataReader(): ABJ_404_Solution_UninstallDatabaseMetadataReader {
        return new ABJ_404_Solution_UninstallDatabaseMetadataReader();
    }

    private static function databaseDefaultsReader(): ABJ_404_Solution_UninstallDatabaseDefaultsReader {
        return new ABJ_404_Solution_UninstallDatabaseDefaultsReader();
    }

    private static function collationSnapshotPresenter(): ABJ_404_Solution_UninstallCollationSnapshotPresenter {
        return new ABJ_404_Solution_UninstallCollationSnapshotPresenter(self::databaseMetadataReader());
    }
}
