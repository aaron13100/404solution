<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chunked, time-budgeted backfill of canonical_url on legacy redirect/logsv2 rows.
 *
 * Legacy rows (pre-4.1.x) lack canonical_url, so the view-build JOIN between
 * redirects.canonical_url and logsv2.canonical_url has to fall back to
 * CONCAT/TRIM and cannot use idx_canonical_url. This component drains the NULL
 * backlog across successive daily cron ticks and browser-triggered admin AJAX
 * drains until every row has been populated, at which point reads can drop the
 * COALESCE fallback entirely.
 *
 * Reached by {@see ABJ_404_Solution_DatabaseUpgradeDailyMaintenance} (daily cron)
 * and the browser-triggered lazy-backfill AJAX endpoint.
 */
class ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill extends ABJ_404_Solution_DatabaseUpgradeComponent {

    // Backfill tuning values live on the coordinator and are exposed through
    // DatabaseUpgradeComponent accessors so the delegate does not duplicate
    // constants owned by ABJ_404_Solution_DatabaseUpgradesEtc.

    /**
     * Populate {wp_abj404_redirects}.canonical_url for any rows still NULL,
     * one chunk at a time. Each chunk runs:
     *
     *   UPDATE redirects SET canonical_url = CONCAT('/', TRIM(BOTH '/' FROM url))
     *   WHERE canonical_url IS NULL LIMIT N
     *
     * Idempotent -- once every row has canonical_url set, the WHERE matches
     * zero rows and the function returns immediately. The chunk loop is
     * bounded by both row count (CANONICAL_URL_BACKFILL_CHUNK_SIZE) and wall
     * clock (CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC) so a 350K-row site
     * converges over successive daily cron ticks without ever blocking a
     * request long enough to hit PHP max_execution_time.
     *
     * Skips silently when:
     *   - the redirects table is missing (degraded site state)
     *   - the canonical_url column is missing (column add hasn't happened
     *     yet, e.g. immediately after upgrade before verifyColumns ran)
     *   - the previous run errored -- repair flow surfaces the error
     *
     * @return int Number of rows updated in this invocation.
     */
    public function backfillRedirectsCanonicalUrl(): int {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');

        // SHOW TABLES existence probe -- same shape as verifyTableMaterialized()
        // in DatabaseUpgradesEtc.php:854. The DAO's tableExists() helper is
        // private so we can't reach it from here, and routing through
        // queryAndGetResults() would log a benign "table doesn't exist" error
        // on freshly-installed sites before runInitialCreateTables() has run.
        // DAO-bypass-approved: schema existence probe -- see comment above.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($redirectsTable) . "'");
        if ($found !== $redirectsTable) {
            return 0;
        }
        if ($this->columnExists($redirectsTable, 'canonical_url') !== true) {
            return 0;
        }

        $chunkSize = (int)$this->getCanonicalUrlBackfillChunkSize();
        $timeBudget = (float)$this->getCanonicalUrlBackfillTimeBudgetSec();
        $start = abj_clock()->nowFloat();
        $totalUpdated = 0;

        while ((abj_clock()->nowFloat() - $start) < $timeBudget) {
            $query = "UPDATE " . $redirectsTable .
                " SET canonical_url = CONCAT('/', TRIM(BOTH '/' FROM url))" .
                " WHERE canonical_url IS NULL" .
                " LIMIT " . $chunkSize;

            $result = $this->dbCore->queryAndGetResults($query);
            $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
            if ($lastError !== '') {
                $this->logger->warn("backfillRedirectsCanonicalUrl: stopping after error: " . $lastError);
                return $totalUpdated;
            }

            $rowsAffected = isset($result['rows_affected']) && is_numeric($result['rows_affected'])
                ? (int)$result['rows_affected'] : 0;
            $totalUpdated += $rowsAffected;
            if ($rowsAffected < $chunkSize) {
                break;
            }
        }

        if ($totalUpdated > 0) {
            $this->logger->infoMessage(sprintf(
                "backfillRedirectsCanonicalUrl: populated canonical_url on %d redirect rows in %.2fs.",
                $totalUpdated,
                abj_clock()->nowFloat() - $start
            ));
        }

        $this->maybeFlipRedirectsCanonicalUrlBackfillCompleteFlag($redirectsTable);
        return $totalUpdated;
    }

    /**
     * Mirror logsv2 path: if the NULL backlog is fully drained, flip the
     * completion flag so the hits-rebuild phase2 JOIN can drop the
     * redirects-side COALESCE wrap and probe idx_canonical_url directly.
     * Cheap LIMIT 1 probe -- IS NULL is sargable on a B-tree over a
     * nullable column.
     *
     * @param string $redirectsTable
     * @return void
     */
    private function maybeFlipRedirectsCanonicalUrlBackfillCompleteFlag(string $redirectsTable): void {
        if (!function_exists('get_option')
            || get_option($this->getRedirectsCanonicalUrlBackfillCompleteOption())) {
            return;
        }
        $remainingProbe = $this->dbCore->queryAndGetResults(
            "SELECT 1 FROM " . $redirectsTable . " WHERE canonical_url IS NULL LIMIT 1"
        );
        $remainingRows = is_array($remainingProbe['rows'] ?? null) ? $remainingProbe['rows'] : [];
        $remainingError = isset($remainingProbe['last_error']) && is_string($remainingProbe['last_error']) ? $remainingProbe['last_error'] : '';
        if ($remainingError !== '' || !empty($remainingRows) || !function_exists('update_option')) {
            return;
        }
        update_option($this->getRedirectsCanonicalUrlBackfillCompleteOption(), '1', false);
        $this->logger->infoMessage(
            "backfillRedirectsCanonicalUrl: backlog cleared -- flipped " .
            $this->getRedirectsCanonicalUrlBackfillCompleteOption() .
            "; phase2 JOIN can now drop the redirects COALESCE fallback."
        );
    }

    /**
     * Populate {wp_abj404_logsv2}.canonical_url for any rows still NULL,
     * one chunk at a time. Each chunk runs:
     *
     *   UPDATE logsv2 SET canonical_url = CONCAT('/', TRIM(BOTH '/' FROM requested_url))
     *   WHERE canonical_url IS NULL LIMIT N
     *
     * Mirrors backfillRedirectsCanonicalUrl() with one budget difference --
     * 15-second wall budget (vs 25 for redirects) so browser-triggered AJAX
     * drains stay bounded while the daily cron remains the silent backstop. On
     * a Bruno-class 250K-row backlog this converges in ~3-10 days on daily cron
     * alone, faster if the admin regularly visits the tab.
     *
     * Once the backlog is fully cleared (no rows where canonical_url IS NULL),
     * sets the abj404_logsv2_canonical_url_backfill_complete option so reads
     * can drop the COALESCE fallback in getRedirectsForViewTempTable.sql and
     * use the no-COALESCE form (logsv2.canonical_url = redirects.canonical_url).
     *
     * Skips silently when:
     *   - the logsv2 table is missing (degraded site state)
     *   - the canonical_url column is missing (column add hasn't happened
     *     yet, e.g. immediately after upgrade before verifyColumns ran)
     *   - the previous run errored -- repair flow surfaces the error
     *
     * @param ?float $timeBudgetSec Wall-clock budget for this invocation. When
     *   null (the daily-cron path), uses LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC
     *   (15s) for fast unattended convergence. The browser-driven AJAX poller
     *   passes a small value so each request stays well under its client timeout.
     * @return int Number of rows updated in this invocation.
     */
    public function backfillLogsv2CanonicalUrl(?float $timeBudgetSec = null): int {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        $logsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logsv2}');

        // SHOW TABLES existence probe -- same shape as in
        // backfillRedirectsCanonicalUrl(). Routing through queryAndGetResults
        // would log a benign "table doesn't exist" error on freshly-installed
        // sites before runInitialCreateTables() has run.
        // DAO-bypass-approved: schema existence probe -- see comment above.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($logsTable) . "'");
        if ($found !== $logsTable) {
            return 0;
        }
        if ($this->columnExists($logsTable, 'canonical_url') !== true) {
            return 0;
        }

        $chunkSize = (int)$this->getCanonicalUrlBackfillChunkSize();
        $timeBudget = $timeBudgetSec !== null
            ? max(0.0, $timeBudgetSec)
            : (float)$this->getLogsv2CanonicalUrlBackfillTimeBudgetSec();
        $start = abj_clock()->nowFloat();
        $totalUpdated = 0;

        while ((abj_clock()->nowFloat() - $start) < $timeBudget) {
            $query = "UPDATE " . $logsTable .
                " SET canonical_url = CONCAT('/', TRIM(BOTH '/' FROM requested_url))" .
                " WHERE canonical_url IS NULL" .
                " LIMIT " . $chunkSize;

            $result = $this->dbCore->queryAndGetResults($query);
            $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
            if ($lastError !== '') {
                $this->logger->warn("backfillLogsv2CanonicalUrl: stopping after error: " . $lastError);
                return $totalUpdated;
            }

            $rowsAffected = isset($result['rows_affected']) && is_numeric($result['rows_affected'])
                ? (int)$result['rows_affected'] : 0;
            $totalUpdated += $rowsAffected;
            if ($rowsAffected < $chunkSize) {
                break;
            }
        }

        if ($totalUpdated > 0) {
            $this->logger->infoMessage(sprintf(
                "backfillLogsv2CanonicalUrl: populated canonical_url on %d logsv2 rows in %.2fs.",
                $totalUpdated,
                abj_clock()->nowFloat() - $start
            ));
        }

        // If the backlog is now drained, flip the completion flag so reads
        // can drop the COALESCE fallback. Cheap LIMIT 1 probe -- at most reads
        // one row's worth of data via the canonical_url IS NULL filter (uses
        // idx_canonical_url because IS NULL is sargable on a B-tree on a
        // nullable column).
        if (!get_option($this->getLogsv2CanonicalUrlBackfillCompleteOption())) {
            $remainingProbe = $this->dbCore->queryAndGetResults(
                "SELECT 1 FROM " . $logsTable . " WHERE canonical_url IS NULL LIMIT 1"
            );
            $remainingRows = is_array($remainingProbe['rows'] ?? null) ? $remainingProbe['rows'] : [];
            $remainingError = isset($remainingProbe['last_error']) && is_string($remainingProbe['last_error']) ? $remainingProbe['last_error'] : '';
            if ($remainingError === '' && empty($remainingRows)) {
                update_option($this->getLogsv2CanonicalUrlBackfillCompleteOption(), '1', false);
                $this->logger->infoMessage(
                    "backfillLogsv2CanonicalUrl: backlog cleared -- flipped " .
                    $this->getLogsv2CanonicalUrlBackfillCompleteOption() .
                    "; reads can now drop the COALESCE fallback."
                );
            }
        }

        return $totalUpdated;
    }

    /**
     * Cheap "does this column exist on this table" probe via SHOW COLUMNS.
     * Case-insensitive on the column name to match MySQL/MariaDB driver
     * variations in returned column-name casing.
     *
     * Three answers, not two: true, false, and null for "the server did not
     * tell me". Collapsing the third into false said "the column is absent"
     * whenever the read was refused, and the ensure*Column() helpers act on
     * absence by issuing ALTER TABLE ... ADD COLUMN -- so a denied read, a
     * connection lost mid-upgrade or a table caught mid-rename produced a blind
     * schema change against a table nothing had managed to introspect. Callers
     * therefore test against true or false explicitly; null means "leave it
     * alone and look again next tick".
     *
     * @param string $tableName  Fully-qualified table name.
     * @param string $columnName Column to look for.
     * @return bool|null
     */
    public function columnExists(string $tableName, string $columnName): ?bool {
        $result = $this->dbCore->queryAndGetResults("SHOW COLUMNS FROM " . $tableName,
            array('log_errors' => false));
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
        if ($lastError !== '' || !is_array($result['rows'] ?? null) || empty($result['rows'])) {
            // Unreadable, or a live table reporting no columns at all, which is
            // not a state a real table can be in and so is not an answer either.
            return null;
        }
        $rows = $result['rows'];
        $needle = strtolower($columnName);
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) !== 'field' || !is_scalar($value)) { continue; }
                if (strtolower((string)$value) === $needle) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Add the canonical_url column to logsv2 with online DDL when supported.
     *
     * A small idempotent helper that runs ahead of the generic
     * verifyColumns() flow so the column add can use
     * ALGORITHM=INPLACE, LOCK=NONE on InnoDB 5.6 or newer (no table lock during the
     * rewrite). On engines that don't support online DDL for ADD COLUMN the
     * explicit clause causes the statement to fail with
     * ER_ALTER_OPERATION_NOT_SUPPORTED, so we fall back to a bare ALTER, which
     * is what verifyColumns() also runs as the safety net.
     *
     * The matching idx_canonical_url is added by the standard
     * ABJ_404_Solution_DatabaseUpgradeIndexes::verifyIndexes() flow. Index adds
     * use online DDL by default on InnoDB 5.6 or newer, so a separate ensure
     * helper isn't required for the index.
     *
     * @param string $logsTable
     * @return void
     */
    public function ensureLogsv2CanonicalUrlColumn(string $logsTable): void {
        if ($this->columnExists($logsTable, 'canonical_url') !== false) {
            // Present, or unknown. Only a definite absence justifies an ALTER.
            return;
        }
        $inplaceQuery = "ALTER TABLE " . $logsTable .
            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL," .
            " ALGORITHM=INPLACE, LOCK=NONE";
        $result = $this->dbCore->queryAndGetResults($inplaceQuery,
            array('log_too_slow' => false, 'log_errors' => false));
        if (empty($result['last_error'])) {
            $this->logger->infoMessage("Added canonical_url to {$logsTable} (ALGORITHM=INPLACE, LOCK=NONE).");
            return;
        }
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
        if ($this->schemaChangeWasAlreadyApplied($lastError)) {
            // Another request added it between the columnExists() probe above
            // and this ALTER. The bare fallback would meet the same column and
            // the same answer, so the column is there and there is nothing to
            // fall back to.
            $this->logger->infoMessage("canonical_url on {$logsTable} was added by another process " .
                "while this one was adding it.");
            return;
        }
        // Engine didn't support online DDL for ADD COLUMN, so the bare ALTER
        // falls back to whatever algorithm the engine picks (COPY on MyISAM / very
        // old InnoDB). On modern InnoDB the bare ALTER is itself implicitly
        // INPLACE for ADD COLUMN ... DEFAULT NULL, so this branch only runs
        // on legacy engines where some lock is unavoidable.
        $bareQuery = "ALTER TABLE " . $logsTable .
            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL";
        $bare = $this->dbCore->queryAndGetResults($bareQuery,
            array('log_too_slow' => false));
        if (empty($bare['last_error'])) {
            $this->logger->infoMessage("Added canonical_url to {$logsTable} (bare ALTER fallback).");
        }
    }

    /**
     * Add the canonical_url column to the redirects table with online DDL
     * when supported.
     *
     * Sibling of {@see ensureLogsv2CanonicalUrlColumn()} applied to the
     * redirects side. The column shipped in 4.1.11 and is normally added by dbDelta
     * on plugin update. On hosts where dbDelta silently fails to ALTER ADD
     * it, every captured-404 INSERT errors out with "Unknown column
     * 'canonical_url' in 'field list'" until verifyColumns eventually
     * retries the column add. One site in the May 10 debug zip emitted
     * 1671 such errors over 10 days on 4.1.12. Calling this helper eagerly
     * from runInitialCreateTables() shortens that window: every cron tick
     * that runs the bootstrap loop retries the ALTER on its own,
     * independent of the verifyColumns DDL diff path.
     *
     * @param string $redirectsTable
     * @return void
     */
    public function ensureRedirectsCanonicalUrlColumn(string $redirectsTable): void {
        if ($this->columnExists($redirectsTable, 'canonical_url') !== false) {
            // Present, or unknown. Only a definite absence justifies an ALTER.
            return;
        }
        $inplaceQuery = "ALTER TABLE " . $redirectsTable .
            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL," .
            " ALGORITHM=INPLACE, LOCK=NONE";
        $result = $this->dbCore->queryAndGetResults($inplaceQuery,
            array('log_too_slow' => false, 'log_errors' => false));
        if (empty($result['last_error'])) {
            $this->logger->infoMessage("Added canonical_url to {$redirectsTable} (ALGORITHM=INPLACE, LOCK=NONE).");
            return;
        }
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
        if ($this->schemaChangeWasAlreadyApplied($lastError)) {
            // Another request added it between the columnExists() probe above
            // and this ALTER. The bare fallback would meet the same column and
            // the same answer, so the column is there and there is nothing to
            // fall back to.
            $this->logger->infoMessage("canonical_url on {$redirectsTable} was added by another process " .
                "while this one was adding it.");
            return;
        }
        $bareQuery = "ALTER TABLE " . $redirectsTable .
            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL";
        $bare = $this->dbCore->queryAndGetResults($bareQuery,
            array('log_too_slow' => false));
        if (empty($bare['last_error'])) {
            $this->logger->infoMessage("Added canonical_url to {$redirectsTable} (bare ALTER fallback).");
        }
    }
}
