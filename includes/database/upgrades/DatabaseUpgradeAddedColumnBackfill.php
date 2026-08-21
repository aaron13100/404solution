<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One-time data backfills and cache invalidations triggered by a column that
 * was just added to a plugin table.
 *
 * Peer of the other column-triggered backfill components
 * ({@see ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill},
 * {@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}): those make
 * sure a column EXISTS, this one seeds the data that a column needs once it
 * does. Reached from
 * {@see ABJ_404_Solution_DatabaseUpgradeSchemaDiff::verifyColumns()}, the only
 * caller that knows a column was actually added rather than already present.
 *
 * Extracted from ABJ_404_Solution_DatabaseTableDdlExecutor, where it shared a
 * file with permanent-DDL discovery and execution but never shared a call
 * graph with them: nothing in that file called it and it called nothing in
 * that file, and it owned that file's only use of the content repository.
 * Seeding table DATA is a different job from executing table DDL; the two only
 * ever met because both happen during an upgrade.
 */
class ABJ_404_Solution_DatabaseUpgradeAddedColumnBackfill extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Post-creation, column-triggered one-time data backfills. Dispatches
     * on (tableName, colName) to exactly two cases:
     *  - abj404_logsv2.min_log_id: runs the seed SQL (backfillLogsMinLogId)
     *    and ensures the composite index that depends on it.
     *  - abj404_permalink_cache.url_length: truncates the permalink cache
     *    so the new column gets populated on next rebuild.
     * Any other (tableName, colName) pair is a no-op.
     *
     * Takes a single associative array (rather than two positional strings)
     * so the table name and column name -- both plain strings -- cannot be
     * silently transposed at the call site.
     *
     * @param array{tableName: string, colName: string} $context
     * @return void
     */
    public function runBackfillsForAddedColumn(array $context) {
        $tableName = isset($context['tableName']) && is_string($context['tableName']) ? $context['tableName'] : '';
        $colName = isset($context['colName']) && is_string($context['colName']) ? $context['colName'] : '';
        if (empty($tableName)) {
            return;
        }

        if (strpos($tableName, 'abj404_logsv2') !== false && $colName == 'min_log_id') {
            $this->backfillLogsMinLogId($tableName);
        }
        if (strpos($tableName, 'abj404_permalink_cache') !== false && $colName == 'url_length') {
            // clear the permalink cache so that the url length column will be populated.
            // this could be more efficient but I'll assume that's not necessary.
            $this->contentRepo->truncatePermalinkCacheTable();
        }
    }

    /**
     * Continue resumable backfills even after the triggering column exists.
     * A failed or partially completed data update must remain reachable on the
     * next schema verification.
     *
     * @param string $tableName
     * @return void
     */
    public function runPendingBackfills(string $tableName): void {
        if (strpos($tableName, 'abj404_logsv2') !== false) {
            $this->backfillLogsMinLogId($tableName);
        }
    }

    /**
     * One-time backfill for the abj404_logsv2.min_log_id column: runs the
     * seed SQL, then ensures the composite index that depends on it exists.
     * Extracted out of runBackfillsForAddedColumn() so that method stays a plain
     * column-name dispatcher; the data-access step (SQL file load + execute)
     * lives in its own method instead of inline in the dispatch logic.
     *
     * @param string $tableName
     * @return void
     */
    private function backfillLogsMinLogId($tableName) {
        try {
            $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../../sql/logsSetMinLogID.sql");
        } catch (Exception $e) {
            $this->logger->errorMessage(
                'Could not read logsSetMinLogID.sql backfill file for ' . $tableName
                . ': ' . $e->getMessage() . '. Skipping min_log_id backfill and composite '
                . 'index creation this run; will retry on the next upgrade check.'
            );
            return;
        }
        $queryResponse = $this->dbCore->queryAndGetResults($query);
        $lastErrorValue = $queryResponse['last_error'] ?? null;
        if (!is_string($lastErrorValue)) {
            $this->logger->errorMessage(
                'min_log_id backfill query returned invalid last_error type ('
                . gettype($lastErrorValue) . ') for ' . $tableName
                . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return;
        }
        $lastError = $lastErrorValue;
        if ($lastError !== '') {
            // Don't create the composite index on the strength of a backfill
            // that didn't actually run: the index exists to make min_log_id
            // lookups fast, and building it now would just lock in whatever
            // stale/default values the column already has. Skipping is safe
            // (idempotent) -- the next upgrade check retries both steps.
            $this->logger->errorMessage(
                'min_log_id backfill query failed for ' . $tableName . ': ' . $lastError
                . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return;
        }
        $rowsAffected = $queryResponse['rows_affected'] ?? null;
        if (!is_int($rowsAffected) && !is_numeric($rowsAffected)) {
            $this->logger->errorMessage(
                'min_log_id backfill query returned invalid rows_affected type ('
                . gettype($rowsAffected) . ') for ' . $tableName
                . '. Skipping composite index creation this run; will retry on the next upgrade check.'
            );
            return;
        }
        if ((int)$rowsAffected > 0) {
            return;
        }
        $this->upgrades()->indexesUpgrade()->ensureLogsCompositeIndex($tableName);
    }
}
