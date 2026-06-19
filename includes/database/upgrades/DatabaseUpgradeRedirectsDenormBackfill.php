<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormBackfillIntegrationTest

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
 * The two narrow LEFT(<source>, 191) sort-key columns (dest_sort_key,
 * url_sort_key) that derive from these are drained by the sibling
 * {@see ABJ_404_Solution_DatabaseUpgradeRedirectsSortKeyBackfill}; the full
 * deferred pass below runs the main backfill then delegates both sort-key drains
 * there under one shared time budget.
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
     * @param ?float $deadlineFloat Absolute wall-clock deadline (abj_clock
     *   nowFloat seconds) to stop by. When null, the method uses its own
     *   REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC budget. A shared deadline lets
     *   {@see runDeferredDenormBackfillPass()} bound the whole three-drain pass
     *   by a single budget instead of one budget per drain.
     * @return int Number of redirect rows resolved in this invocation.
     */
    public function backfillRedirectsDenormColumns(?float $deadlineFloat = null): int {
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
        $start = abj_clock()->nowFloat();
        $deadline = $deadlineFloat ?? ($start + (float)$this->getRedirectsDenormBackfillTimeBudgetSec());
        $totalResolved = 0;

        while (abj_clock()->nowFloat() < $deadline) {
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
            $this->writeCursorOption(
                ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_CURSOR_OPTION,
                (int)max($ids)
            );
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
     * Run the full deferred denorm backfill pass (main derived columns + both
     * narrow sort keys) under ONE shared time budget.
     *
     * Why this exists: browser-triggered admin AJAX runs the three drains
     * back-to-back after the table has rendered. With a per-call budget the
     * combined pass could consume up to 3x REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC.
     * A single shared deadline caps the post-load request at one budget;
     * whatever backlog remains drains on the next browser poll or daily cron.
     * The daily-maintenance path deliberately keeps the per-call budgets (it is
     * true cron, never request-blocking, so faster nightly convergence is
     * preferred there).
     *
     * @param ?float $timeBudgetSec Wall-clock budget for the shared pass. When
     *   null, uses REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC.
     * @return void
     */
    public function runDeferredDenormBackfillPass(?float $timeBudgetSec = null): void {
        $budget = $timeBudgetSec ?? (float)$this->getRedirectsDenormBackfillTimeBudgetSec();
        $deadline = abj_clock()->nowFloat() + $budget;
        $this->backfillRedirectsDenormColumns($deadline);
        $sortKey = $this->upgrades()->redirectsSortKeyBackfillUpgrade();
        $sortKey->backfillRedirectsDestSortKey($deadline);
        $sortKey->backfillRedirectsUrlSortKey($deadline);
    }

    /**
     * Read the next chunk of redirect ids that still need backfilling.
     *
     * @param string $redirectsTable
     * @param int    $chunkSize
     * @return array<int, int>|null List of ids (possibly empty), or null on a query error.
     */
    private function fetchNextBackfillChunkIds(string $redirectsTable, int $chunkSize): ?array {
        $cursorOption = ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_CURSOR_OPTION;
        $cursor = $this->readCursorOption($cursorOption);
        $ids = $this->queryBackfillChunkIds($redirectsTable, $chunkSize, $cursor);
        if ($ids === null) {
            return null;
        }
        if (empty($ids) && $cursor > 0) {
            $this->writeCursorOption($cursorOption, 0);
            $ids = $this->queryBackfillChunkIds($redirectsTable, $chunkSize, 0);
            if ($ids === null) {
                return null;
            }
        }
        return $ids;
    }

    /**
     * @param string $redirectsTable
     * @param int $chunkSize
     * @param int $afterId
     * @return array<int, int>|null
     */
    private function queryBackfillChunkIds(string $redirectsTable, int $chunkSize, int $afterId): ?array {
        $result = $this->dbCore->queryAndGetResults(
            "SELECT id FROM " . $redirectsTable .
            " WHERE id > " . (int)$afterId . " AND dest_for_view IS NULL ORDER BY id ASC LIMIT " . $chunkSize
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
}
