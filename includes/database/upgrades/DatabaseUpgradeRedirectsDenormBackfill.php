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
 * 404-displayed) and rolls up logshits + last_used from the wp_abj404_logs_hits
 * rollup by canonical URL (NOT raw logsv2: report.md Finding 2). The whole run is
 * bounded by row count
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

        return $totalResolved;
    }

    /**
     * One-time drain that populates dest_sort_key on rows whose denorm columns
     * were backfilled BEFORE the dest_sort_key column existed.
     *
     * The Destination sort orders by the narrow indexable dest_sort_key =
     * LEFT(dest_for_view, 191) (report3.md Finding 3). A fresh row gets its sort
     * key during the main backfill (the type-resolution chunk appends the
     * LEFT(...) UPDATE). But an install upgraded across the column add already
     * has dest_for_view populated, so the main backfill's `dest_for_view IS NULL`
     * sentinel skips it, and the converged-row live write-back never fires; the
     * sort key would stay NULL until the nightly full reconcile happened to walk
     * that row (report5.md Finding 1). On a large captured-heavy table that window
     * could span many nightly passes, leaving the Destination sort ordering a big
     * NULL bucket by id.
     *
     * @return int Number of redirect rows whose dest_sort_key was populated.
     */
    public function backfillRedirectsDestSortKey(): int {
        // dest_sort_key derives from dest_for_view, which is itself NULL until the
        // main backfill resolves it; the source guard skips not-yet-resolved rows.
        return $this->drainNarrowSortKey('dest_sort_key', 'dest_for_view', true);
    }

    /**
     * One-time drain that populates url_sort_key on rows that pre-date the
     * url_sort_key column add.
     *
     * The URL sort orders by the narrow indexable url_sort_key = LEFT(url, 191)
     * (report6.md). A new/edited row gets its sort key in real time (the Step 3c
     * recompute runs the same buildDestPublishedStatements that appends the
     * url_sort_key UPDATE). But an install upgraded across the column add already
     * has its rows, so without this drain the sort key would stay NULL on legacy
     * rows until the nightly full reconcile happened to walk them, leaving the URL
     * sort ordering a NULL bucket by id in the meantime. url is NOT NULL, so the
     * drain converges every legacy row.
     *
     * Shares the chunked, wall-clock-bounded, self-clearing-sentinel mechanics and
     * the daily-cron cadence with the dest_sort_key drain.
     *
     * @return int Number of redirect rows whose url_sort_key was populated.
     */
    public function backfillRedirectsUrlSortKey(): int {
        // url is NOT NULL on the schema, so the source guard is always satisfied;
        // it is passed for a single uniform code path with the dest drain.
        return $this->drainNarrowSortKey('url_sort_key', 'url', true);
    }

    /**
     * Shared chunked drain for a narrow LEFT(<source>, 191) sort-key column.
     *
     * Cheap narrow UPDATE (no per-type joins, unlike the main backfill) keyed on
     * the self-clearing `<target> IS NULL [AND <source> IS NOT NULL]` sentinel: a
     * processed row gets a non-NULL sort key (empty string at minimum) and no
     * longer matches, so the predicate is both the chunk selector and the
     * completion probe. Chunked + wall-clock-bounded, sharing the main backfill's
     * chunk-size / time-budget settings.
     *
     * Skips silently (returns 0) when $wpdb is unavailable, the redirects table is
     * missing, or either column is missing (column add not yet run) so a
     * mid-upgrade install never errors on the column.
     *
     * $targetColumn / $sourceColumn are class-internal literals (never request
     * input), so interpolating them is safe, the same way the table name and id
     * list are interpolated throughout this class.
     *
     * @param string $targetColumn The narrow sort-key column to populate.
     * @param string $sourceColumn The wide source column it is LEFT(...)-copied from.
     * @param bool   $guardSourceNotNull Whether to skip rows whose source is NULL.
     * @return int Number of rows populated.
     */
    private function drainNarrowSortKey(string $targetColumn, string $sourceColumn, bool $guardSourceNotNull): int {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }

        // The latch is a one-way ratchet: once the backlog has drained to zero it
        // is set, and the read path may order by the narrow key. Short-circuit on
        // a set latch so the nightly cron stops re-running the drain SELECT
        // forever after convergence (raw-SQL writers that NULL a key are re-healed
        // by the Step 3d reconcile, which repopulates every row's sort key).
        $latchOption = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillLatchOption($targetColumn);
        if ($latchOption !== '' && get_option($latchOption) === '1') {
            return 0;
        }

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');

        // SHOW TABLES existence probe, same shape as the main backfill: routing
        // through queryAndGetResults would log a benign "table doesn't exist"
        // error on a freshly-installed site before create-tables ran.
        // DAO-bypass-approved: schema existence probe, see comment above.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($redirectsTable) . "'");
        if ($found !== $redirectsTable) {
            return 0;
        }
        // Both columns must exist: source is read, target is written. Either
        // missing means the column-add ALTER has not completed, so there is
        // nothing to drain yet (schema-drift tolerance).
        if (!$this->columnExists($redirectsTable, $sourceColumn)
            || !$this->columnExists($redirectsTable, $targetColumn)) {
            return 0;
        }

        $sourceGuard = $guardSourceNotNull ? (' AND ' . $sourceColumn . ' IS NOT NULL') : '';
        $chunkSize = (int)$this->getRedirectsDenormBackfillChunkSize();
        if ($chunkSize < 1) {
            $chunkSize = 1;
        }
        $timeBudget = (float)$this->getRedirectsDenormBackfillTimeBudgetSec();
        $start = abj_clock()->nowFloat();
        $totalRepaired = 0;

        while ((abj_clock()->nowFloat() - $start) < $timeBudget) {
            $ids = $this->fetchNextSortKeyChunkIds($redirectsTable, $targetColumn, $sourceGuard, $chunkSize);
            if ($ids === null) {
                // Read error already warned about; stop so we don't spin.
                return $totalRepaired;
            }
            if (empty($ids)) {
                break;
            }
            if (!$this->populateSortKeyForIds($redirectsTable, $targetColumn, $sourceColumn, $sourceGuard, $ids)) {
                return $totalRepaired;
            }
            $totalRepaired += count($ids);
            if (count($ids) < $chunkSize) {
                break;
            }
        }

        if ($totalRepaired > 0) {
            $this->logger->infoMessage(sprintf(
                "drainNarrowSortKey(%s): populated %d redirect sort keys in %.2fs.",
                $targetColumn,
                $totalRepaired,
                abj_clock()->nowFloat() - $start
            ));
        }

        // Reaching here means the loop exited cleanly (drained to empty or hit the
        // time budget), not via an early read/write-error return. Set the latch
        // only if no NULL key remains, which opens the read gate.
        $this->markSortKeyLatchIfComplete($redirectsTable, $targetColumn);

        return $totalRepaired;
    }

    /**
     * Set the <target> backfill latch when no row still carries a NULL sort key,
     * so the admin read path may switch from the wide-column fallback to the
     * index-ordered narrow-key sort.
     *
     * No-op when the latch is already set, the column has no latch contract, or
     * the probe errors / still finds a NULL key. The probe is UNGUARDED (no
     * source-NOT-NULL filter): for dest_sort_key that means the latch waits for
     * the MAIN backfill too (a row whose dest_for_view is still NULL has a NULL
     * dest_sort_key the guarded drain deliberately skips), so the gate never opens
     * while any row would order into the wrong bucket. One bounded SELECT, run at
     * most once per drain invocation and never again once the latch is set.
     *
     * @param string $redirectsTable
     * @param string $targetColumn Class-internal literal column name.
     * @return void
     */
    private function markSortKeyLatchIfComplete(string $redirectsTable, string $targetColumn): void {
        $latchOption = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillLatchOption($targetColumn);
        if ($latchOption === '' || get_option($latchOption) === '1') {
            return;
        }
        $result = $this->dbCore->queryAndGetResults(
            "SELECT id FROM " . $redirectsTable . " WHERE " . $targetColumn . " IS NULL LIMIT 1"
        );
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            // Could not confirm convergence; leave the latch for a later run.
            return;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            // autoload=false: this latch is read only on admin redirect-table
            // loads, never on the front end, so it must not bloat the autoload
            // payload of every request.
            update_option($latchOption, '1', false);
        }
    }

    /**
     * Activation-safe, in-band latch refresh for both narrow sort-key columns.
     *
     * Unlike {@see backfillRedirectsDestSortKey()} / {@see backfillRedirectsUrlSortKey()},
     * this NEVER runs the populate loop (which can consume the full time budget on
     * a large fresh-upgrade table and stall activation). It only flips the latch
     * when the column is already fully populated -- so an install that already has
     * every sort key (a small site, or one upgraded from a build that already
     * carried the column) switches to the index-ordered sort immediately on
     * upgrade instead of waiting for the first daily-cron drain. A site with a
     * real backlog is left for the cron drain; until then the read path falls back
     * to the wide source column (correct order, filesort), so there is no
     * correctness gap either way.
     *
     * @return void
     */
    public function refreshSortKeyBackfillLatches(): void {
        global $wpdb;
        if (!isset($wpdb)) {
            return;
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        // DAO-bypass-approved: schema existence probe, same shape as the drains.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($redirectsTable) . "'");
        if ($found !== $redirectsTable) {
            return;
        }
        foreach (array('dest_sort_key', 'url_sort_key') as $targetColumn) {
            if ($this->columnExists($redirectsTable, $targetColumn)) {
                $this->markSortKeyLatchIfComplete($redirectsTable, $targetColumn);
            }
        }
    }

    /**
     * Read the next chunk of redirect ids whose narrow sort key still needs
     * populating (NULL target, source present per the guard).
     *
     * @param string $redirectsTable
     * @param string $targetColumn Class-internal literal column name.
     * @param string $sourceGuard  Pre-built " AND <source> IS NOT NULL" or ''.
     * @param int    $chunkSize
     * @return array<int, int>|null List of ids (possibly empty), or null on a query error.
     */
    private function fetchNextSortKeyChunkIds(string $redirectsTable, string $targetColumn, string $sourceGuard, int $chunkSize): ?array {
        $result = $this->dbCore->queryAndGetResults(
            "SELECT id FROM " . $redirectsTable .
            " WHERE " . $targetColumn . " IS NULL" . $sourceGuard .
            " ORDER BY id ASC LIMIT " . $chunkSize
        );
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $this->logger->warn("drainNarrowSortKey(" . $targetColumn . "): stopping after read error: " . $lastError);
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
     * Populate <target> = LEFT(<source>, 191) for an explicit chunk of ids.
     * Mirrors the sort-key statements {@see
     * ABJ_404_Solution_RedirectsDenormColumnSql::buildDestPublishedStatements}
     * appends, so the one-time drain and the per-chunk write stay in lockstep.
     *
     * @param string          $redirectsTable
     * @param string          $targetColumn Class-internal literal column name.
     * @param string          $sourceColumn Class-internal literal column name.
     * @param string          $sourceGuard  Pre-built " AND <source> IS NOT NULL" or ''.
     * @param array<int, int> $ids
     * @return bool True if the chunk wrote cleanly, false if the write errored.
     */
    private function populateSortKeyForIds(string $redirectsTable, string $targetColumn, string $sourceColumn, string $sourceGuard, array $ids): bool {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return true;
        }
        $result = $this->dbCore->queryAndGetResults(
            "UPDATE " . $redirectsTable .
            " SET " . $targetColumn . " = LEFT(" . $sourceColumn . ", 191)" .
            " WHERE 1 = 1" . $sourceGuard . " AND id IN (" . $idList . ")"
        );
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $this->logger->warn("drainNarrowSortKey(" . $targetColumn . "): stopping after write error: " . $lastError);
            return false;
        }
        return true;
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
     * the wp_abj404_logs_hits rollup by canonical URL (NOT raw logsv2: report.md
     * Finding 2) when that rollup table exists.
     *
     * @param string         $redirectsTable
     * @param array<int, int> $ids
     * @return bool True if the chunk resolved cleanly, false if a write errored.
     */
    private function resolveDenormColumnsForIds(string $redirectsTable, array $ids): bool {
        // Delegate the per-chunk write (per-type dest/published statements plus
        // the logs_hits rollup) to the shared resolver, the single source of
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
