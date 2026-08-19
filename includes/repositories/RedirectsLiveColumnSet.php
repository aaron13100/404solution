<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The set of columns `wp_abj404_redirects` actually has on THIS install, right
 * now, and the one operation that depends on knowing it: adding an optional
 * column to a pending write only when the live table can accept it.
 *
 * The redirects table has grown columns across releases (canonical_url, engine,
 * score, the denorm display columns). An install whose upgrade never completed
 * -- interrupted migration, read-only replica, a host that killed the ALTER --
 * keeps serving redirects from a table that is missing some of them. Naming a
 * missing column in an INSERT fails the whole statement, so the row is lost
 * rather than the nullable display value: exactly backwards. Every optional
 * column therefore goes through appendIfPresent().
 *
 * The probe is memoized per instance because a captured 404 is the plugin's
 * highest-frequency write; re-reading the column list per insert would put a
 * metadata query on the frontend 404 path. An unreadable or empty column list
 * answers "yes" for every column: an install we cannot introspect is assumed
 * current, which keeps writes working rather than silently dropping columns on
 * a healthy table.
 */
class ABJ_404_Solution_RedirectsLiveColumnSet {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /**
     * Memoized lowercase column names, empty until the first probe.
     *
     * @var array<string, bool>
     */
    private $columns = array();

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     */
    public function __construct($dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * Add one column to a pending INSERT only when the live table has it, so a
     * schema-drifted install loses the value instead of the row.
     *
     * @param array<string, mixed> $insertData column => value, by reference
     * @param array<int, string> $insertFormats wpdb format specifiers, by reference
     * @param string $columnName
     * @param mixed $value
     * @param string $format the wpdb format specifier for $value ('%s', '%d', '%f')
     * @return void
     */
    public function appendIfPresent(array &$insertData, array &$insertFormats,
            string $columnName, $value, string $format): void {
        if (!$this->has($columnName)) {
            return;
        }
        $insertData[$columnName] = $value;
        $insertFormats[] = $format;
    }

    /**
     * @param string $columnName
     * @return bool True when the live table has the column, or when the column
     *              list could not be read (assume current; see class docblock).
     */
    public function has(string $columnName): bool {
        $key = strtolower($columnName);
        if ($this->columns !== array()) {
            return isset($this->columns[$key]);
        }
        global $wpdb;
        if (!isset($wpdb)) {
            return true;
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
        $columns = $this->dbCore->tableNameResolver()->getTableColumnNames($redirectsTable);
        if ($columns === array()) {
            return true;
        }
        $this->columns = array_fill_keys(array_map('strtolower', $columns), true);
        return isset($this->columns[$key]);
    }
}
