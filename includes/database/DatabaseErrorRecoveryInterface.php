<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Error classification + self-healing recovery: classify infrastructure
 * errors, detect OOM, REPAIR TABLE for crashed tables, fix duplicate
 * auto_increment IDs, recover from collation mismatches, and the test seam
 * for the injected clock.
 *
 * Legacy view-build staged-failure routing (the 'resumable'/'skip'/'halt'
 * /'rethrow' classifier) intentionally lives outside this database-layer
 * interface: those tokens are not database recovery vocabulary.
 */
interface ABJ_404_Solution_DatabaseErrorRecoveryInterface {

    /**
     * Classify an error as infrastructure (server-side) and handle it.
     *
     * @param string $errorText
     * @return bool True if handled as infrastructure error.
     */
    public function classifyAndHandleInfrastructureError(string $errorText): bool;

    /**
     * @param string $errorText
     * @return bool
     */
    public function isOutOfMemoryError(string $errorText): bool;

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
     * Schedule schema-wide collation recovery outside the foreground request.
     *
     * @return void
     */
    public function scheduleCollationRecovery(): void;

    /**
     * Inject the clock instance for testability.
     *
     * @param ABJ_404_Solution_Clock $clock
     * @return void
     */
    public function setClock(ABJ_404_Solution_Clock $clock): void;
}
