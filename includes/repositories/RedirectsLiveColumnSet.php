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
 * column therefore goes through candidateIfPresent().
 *
 * The probe is memoized per instance because a captured 404 is the plugin's
 * highest-frequency write; re-reading the column list per insert would put a
 * metadata query on the frontend 404 path. An unreadable or empty column list
 * omits every optional column and logs the failed probe. That preserves the
 * base redirect row: guessing that a column exists can make the entire INSERT
 * fail on the schema-drifted installs this class protects.
 *
 * // allow-no-test-found: exercised by DataAccessSchemaDriftTest
 */
class ABJ_404_Solution_RedirectsLiveColumnSet {

    /** @var ABJ_404_Solution_DatabaseTableNameResolver */
    private $tableMetadata;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * Memoized lowercase column names, empty until the first probe.
     *
     * @var array<string, bool>
     */
    private $columns = array();

    /** @var bool Whether the live schema probe has already been attempted. */
    private $loaded = false;

    /**
     * @param array{
     *   tableMetadata: ABJ_404_Solution_DatabaseTableNameResolver,
     *   logger: ABJ_404_Solution_Logging
     * } $dependencies
     */
    public function __construct(array $dependencies) {
        $this->tableMetadata = $dependencies['tableMetadata'];
        $this->logger = $dependencies['logger'];
    }

    /**
     * Return one optional INSERT candidate only when the live table can accept
     * it. The named request prevents column/value/format argument swaps.
     *
     * @param array{columnName: string, value: mixed, format: string} $candidate
     * @return array{columnName: string, value: mixed, format: string}|null
     */
    public function candidateIfPresent(array $candidate): ?array {
        return $this->has($candidate['columnName']) ? $candidate : null;
    }

    /**
     * @param string $columnName
     * @return bool True only when the live table positively reports the column.
     */
    public function has(string $columnName): bool {
        $key = strtolower($columnName);
        if ($this->loaded) {
            return isset($this->columns[$key]);
        }
        $this->loaded = true;
        $redirectsTable = $this->tableMetadata->getPrefixedTableName('abj404_redirects');
        $columns = $this->tableMetadata->getTableColumnNames($redirectsTable);
        if ($columns === array()) {
            global $wpdb;
            $lastError = isset($wpdb) && isset($wpdb->last_error) && is_string($wpdb->last_error)
                ? trim($wpdb->last_error) : '';
            $this->logger->warn(
                'Could not read the live redirects-table columns; optional redirect fields are omitted'
                . ' so the base row can still be inserted.'
                . ($lastError === '' ? '' : ' Database error: ' . $lastError)
            );
            return false;
        }
        $this->columns = array_fill_keys(array_map('strtolower', $columns), true);
        return isset($this->columns[$key]);
    }
}
