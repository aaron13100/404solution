<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for the wp_abj404_lookup table (lkup_value to id mapping).
 *
 * The lookup table stores small repeating strings (currently user names) by id
 * so the wide logsv2 table can keep a compact integer reference instead of
 * repeating the string per log row. Three operations: insert-or-get-id by
 * value, reverse-lookup by value, and duplicate cleanup (used during
 * fresh-install repair).
 *
 * Extracted from LogsRepository under M201. Consumed by LogsWriter during log
 * writes (insertLookupValueAndGetID) and by DatabaseUpgradeTableRepair (via
 * the LogsRepository facade) for duplicate cleanup.
 */
class ABJ_404_Solution_LogsLookupRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * Insert a lookup value (or fetch its existing id) and return the id.
     *
     * @param string $valueToInsert
     * @return int
     */
    public function insertLookupValueAndGetID($valueToInsert) {
        global $wpdb;
        $query = "INSERT INTO {wp_abj404_lookup} (lkup_value) VALUES (%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";
        $this->dbCore->queryAndGetResults($query, array('query_params' => array($valueToInsert)));
        return intval($wpdb->insert_id);
    }

    /**
     * Reverse-lookup an id for an existing value. Returns -1 if missing.
     *
     * @param string $userName
     * @return int
     */
    public function getLookupIDForUser($userName) {
        // allow-unbounded-select: single lkup_value equality; only the first row is used
        $query = "select id from {wp_abj404_lookup} where lkup_value = %s";
        $results = $this->dbCore->queryAndGetResults($query, array('query_params' => array($userName)));
        $lookupRows = is_array($results['rows']) ? $results['rows'] : array();
        if (count($lookupRows) > 0) {
            $row1 = is_array($lookupRows[0]) ? $lookupRows[0] : array();
            $id = isset($row1['id']) ? $row1['id'] : 0;
            return is_scalar($id) ? intval($id) : 0;
        }
        return -1;
    }

    /**
     * Repair the lookup table after duplicate lkup_value rows accumulate
     * (pre-4.1.8 installs could insert duplicates before the unique key).
     * Runs with log_errors=false / skip_repair=true so a missing table during
     * fresh-install doesn't spam the debug log or trigger repair recursion.
     *
     * @return void
     */
    public function correctDuplicateLookupValues(): void {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/correctLookupTableIssue.sql");
        $this->dbCore->queryAndGetResults($query, array('log_errors' => false, 'skip_repair' => true));
    }
}
