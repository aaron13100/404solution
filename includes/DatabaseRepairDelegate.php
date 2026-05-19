<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bridge interface for Maintenance-trait repair methods called from DatabaseCore's
 * queryAndGetResults() error-recovery paths.
 *
 * DataAccess implements this (it hosts the Maintenance trait) and injects itself
 * into DatabaseCore. In Phase 5 of the DataAccess refactor, when the Maintenance
 * trait is dissolved and these methods move to DatabaseCore directly, this
 * interface is removed.
 */
interface ABJ_404_Solution_DatabaseRepairDelegate {

    /**
     * Attempt REPAIR TABLE for crashed or corrupted-key-file tables.
     *
     * @param string $errorMessage The MySQL error string.
     * @return void
     */
    public function repairTable(string $errorMessage): void;

    /**
     * Attempt to fix duplicate auto_increment IDs caused by ALTER TABLE resequencing.
     *
     * @param string $errorMessage The MySQL error string.
     * @param string $sqlThatWasRun The SQL that triggered the error.
     * @return void
     */
    public function repairDuplicateIDs(string $errorMessage, string $sqlThatWasRun): void;

    /**
     * Auto-recover from collation mismatches by running correctCollations()
     * then retrying the original query.
     *
     * @param string $query The SQL query to retry.
     * @param array<string, mixed> $result Passed by reference; updated on successful retry.
     * @param bool $producesRows Whether the query returns result rows.
     * @param string $resultType wpdb output type (ARRAY_A or OBJECT).
     * @return void
     */
    public function recoverFromCollationMismatchAndRetry(
        string $query, array &$result, bool $producesRows, string $resultType
    ): void;
}
