<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table-name resolution and DDL introspection: prefix, plugin-table names,
 * CREATE TABLE DDL, table-level and column-level collations, existence,
 * and column-name listing.
 */
interface ABJ_404_Solution_DatabaseTableMetadataInterface {

    /**
     * Get the normalized (lowercase) WordPress table prefix.
     *
     * @return string
     */
    public function getLowercasePrefix(): string;

    /**
     * Build a fully-qualified plugin table name.
     *
     * @param string $tableSuffix e.g. 'abj404_redirects'
     * @return string
     */
    public function getPrefixedTableName($tableSuffix): string;

    /**
     * Get the CREATE TABLE DDL for an existing table.
     *
     * @param string $tableName
     * @return string
     */
    public function getCreateTableDDL($tableName): string;

    /**
     * Get the table-level default collation for an existing table.
     *
     * @param string $tableName
     * @return string
     */
    public function getTableCollationString(string $tableName): string;

    /**
     * Get the column-level collation for an existing character column.
     *
     * @param string $tableName
     * @param string $columnName
     * @return string
     */
    public function getColumnCollationString(string $tableName, string $columnName): string;

    /**
     * Check whether a database table exists.
     *
     * @param string $tableName
     * @return bool
     */
    public function tableExists($tableName): bool;

    /**
     * Get column names from an actual database table.
     *
     * @param string $tableName
     * @return array<int, string>
     */
    public function getTableColumnNames(string $tableName): array;
}
