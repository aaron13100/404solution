<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chunked, time-budgeted backfill of the four denormalized derived columns on
 * the redirects table: logshits, last_used, dest_for_view, published_status.
 *
 * Denorm Step 3a (i458 / i459). These columns roll the per-redirect values that
 * the staged view_done pipeline used to compute at build time directly onto the
 * redirects row, so admin reads can drop the staged build entirely (Step 3b).
 * This component owns only the one-time INITIAL population of those columns for
 * rows that pre-date the column add. Real-time freshness (Step 3c) and the
 * nightly full reconcile (Step 3d) are separate components.
 *
 * The not-yet-backfilled sentinel is `dest_for_view IS NULL`: the column add
 * leaves every existing row NULL, and a processed row is always set to a
 * non-NULL value (empty string at minimum), so a single `WHERE dest_for_view
 * IS NULL` predicate is both the chunk selector and the completion probe. This
 * mirrors the `canonical_url IS NULL` backlog pattern in
 * {@see ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill}.
 *
 * Each chunk resolves dest_for_view + published_status with the same per-type
 * logic the staged pipeline used (stages S4-S8: posts, terms, home, external,
 * 404-displayed) and rolls up logshits + last_used from logsv2 by canonical
 * URL. The whole run is bounded by row count
 * (REDIRECTS_DENORM_BACKFILL_CHUNK_SIZE) and wall clock
 * (REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC) so a large site converges across
 * successive daily cron ticks without ever blocking a request.
 *
 * Reached by {@see ABJ_404_Solution_DatabaseUpgradeDailyMaintenance} (daily
 * cron). Never run synchronously during activation: reads must never wait on it.
 */
class ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Resolve the four derived columns for any redirect rows still carrying the
     * dest_for_view IS NULL sentinel, one chunk at a time.
     *
     * Idempotent: once every row is resolved the chunk selector matches zero
     * rows and the function returns immediately. Reprocessing a row recomputes
     * the same values from the same sources, so an interrupted run resumes
     * cleanly on the next invocation.
     *
     * Skips silently (returns 0) when:
     *   - $wpdb is unavailable,
     *   - the redirects table is missing (degraded site state),
     *   - the dest_for_view column is missing (column add has not happened yet,
     *     e.g. immediately after upgrade before verifyColumns ran).
     *
     * @return int Number of redirect rows resolved in this invocation.
     */
    public function backfillRedirectsDenormColumns(): int {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');

        // SHOW TABLES existence probe, same shape as the canonical-url backfill.
        // Routing through queryAndGetResults would log a benign "table doesn't
        // exist" error on freshly-installed sites before the create-tables flow
        // has run.
        // DAO-bypass-approved: schema existence probe, see comment above.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($redirectsTable) . "'");
        if ($found !== $redirectsTable) {
            return 0;
        }
        if (!$this->columnExists($redirectsTable, 'dest_for_view')) {
            return 0;
        }

        $chunkSize = (int)$this->getRedirectsDenormBackfillChunkSize();
        if ($chunkSize < 1) {
            $chunkSize = 1;
        }
        $timeBudget = (float)$this->getRedirectsDenormBackfillTimeBudgetSec();
        $start = abj_clock()->nowFloat();
        $totalResolved = 0;

        while ((abj_clock()->nowFloat() - $start) < $timeBudget) {
            $ids = $this->fetchNextBackfillChunkIds($redirectsTable, $chunkSize);
            if ($ids === null) {
                // Read error already warned about; stop so we don't spin.
                return $totalResolved;
            }
            if (empty($ids)) {
                break;
            }
            if (!$this->resolveDenormColumnsForIds($redirectsTable, $ids)) {
                return $totalResolved;
            }
            $totalResolved += count($ids);
            if (count($ids) < $chunkSize) {
                break;
            }
        }

        if ($totalResolved > 0) {
            $this->logger->infoMessage(sprintf(
                "backfillRedirectsDenormColumns: resolved %d redirect rows in %.2fs.",
                $totalResolved,
                abj_clock()->nowFloat() - $start
            ));
        }

        $this->maybeFlipDenormBackfillCompleteFlag($redirectsTable);
        return $totalResolved;
    }

    /**
     * Read the next chunk of redirect ids that still need backfilling.
     *
     * @param string $redirectsTable
     * @param int    $chunkSize
     * @return array<int, int>|null List of ids (possibly empty), or null on a query error.
     */
    private function fetchNextBackfillChunkIds(string $redirectsTable, int $chunkSize): ?array {
        $result = $this->dbCore->queryAndGetResults(
            "SELECT id FROM " . $redirectsTable .
            " WHERE dest_for_view IS NULL ORDER BY id ASC LIMIT " . $chunkSize
        );
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $this->logger->warn("backfillRedirectsDenormColumns: stopping after read error: " . $lastError);
            return null;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids;
    }

    /**
     * Populate the four derived columns for an explicit list of redirect ids.
     *
     * dest_for_view + published_status are resolved per redirect type, mirroring
     * staged-build stages S4-S8. Any row whose type matches none of those stages
     * is caught by a final UPDATE so the chunk always drains (no row keeps the
     * dest_for_view IS NULL sentinel). logshits + last_used are rolled up from
     * logsv2 by canonical URL when the logs table exists.
     *
     * @param string         $redirectsTable
     * @param array<int, int> $ids
     * @return bool True if the chunk resolved cleanly, false if a write errored.
     */
    private function resolveDenormColumnsForIds(string $redirectsTable, array $ids): bool {
        // Delegate the per-chunk write (per-type dest/published statements plus
        // the logsv2 hits rollup) to the shared resolver, the single source of
        // truth the Step 3d nightly reconcile also uses so the two bulk-write
        // paths can never drift. $recompute = false: the catch-all guards on the
        // dest_for_view IS NULL sentinel, which keeps the chunk draining and the
        // backlog probe converging.
        return ABJ_404_Solution_RedirectsDenormChunkResolver::resolveChunk(
            $this->dbCore,
            $this->logger,
            $redirectsTable,
            $ids,
            false
        );
    }

    /**
     * Flip the completion option once no redirect row still carries the
     * dest_for_view IS NULL sentinel, so Step 3b reads can trust the derived
     * columns. Cheap LIMIT 1 probe.
     *
     * @param string $redirectsTable
     * @return void
     */
    private function maybeFlipDenormBackfillCompleteFlag(string $redirectsTable): void {
        if (!function_exists('get_option')
            || get_option($this->getRedirectsDenormBackfillCompleteOption())) {
            return;
        }
        $remainingProbe = $this->dbCore->queryAndGetResults(
            "SELECT 1 FROM " . $redirectsTable . " WHERE dest_for_view IS NULL LIMIT 1"
        );
        $remainingRows = is_array($remainingProbe['rows'] ?? null) ? $remainingProbe['rows'] : array();
        $remainingError = isset($remainingProbe['last_error']) && is_string($remainingProbe['last_error'])
            ? $remainingProbe['last_error'] : '';
        if ($remainingError !== '' || !empty($remainingRows) || !function_exists('update_option')) {
            return;
        }
        update_option($this->getRedirectsDenormBackfillCompleteOption(), '1', false);
        $this->logger->infoMessage(
            "backfillRedirectsDenormColumns: backlog cleared, flipped " .
            $this->getRedirectsDenormBackfillCompleteOption() .
            "; Step 3b reads can now trust the derived columns."
        );
    }

    /**
     * Case-insensitive "does this column exist" probe. Delegates to the
     * canonical-url backfill component's implementation so the SHOW COLUMNS
     * probe has a single source of truth.
     *
     * @param string $tableName  Fully-qualified table name.
     * @param string $columnName Column to look for.
     * @return bool
     */
    public function columnExists(string $tableName, string $columnName): bool {
        return $this->upgrades()->canonicalUrlBackfillUpgrade()->columnExists($tableName, $columnName);
    }
}
