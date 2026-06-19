<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormBackfillIntegrationTest

/**
 * Chunked, time-budgeted drain of the two narrow LEFT(<source>, 191) sort-key
 * columns on the redirects table: dest_sort_key (from dest_for_view) and
 * url_sort_key (from url). These narrow indexable columns let the admin
 * redirect-table Destination and URL sorts order by an index instead of
 * filesorting the wide source column (report3.md Finding 3, report6.md).
 *
 * This component owns the one-time drain of legacy rows that pre-date each
 * column add (the per-row "why" is on {@see backfillRedirectsDestSortKey()} /
 * {@see backfillRedirectsUrlSortKey()} / {@see drainNarrowSortKey()}): the
 * per-column drains, the resumable cursor walk, the one-way completion latch,
 * and the deferred sort-key pass. The whole run is
 * bounded by row count (REDIRECTS_DENORM_BACKFILL_CHUNK_SIZE) and wall clock
 * (REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC) so a large site converges across
 * successive daily cron ticks without ever blocking a request.
 *
 * Sibling of {@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill},
 * which owns the wide dest_for_view denorm backfill these sort keys derive from.
 * Its full deferred pass runs the main backfill then delegates both sort-key
 * drains here under one shared time budget. Reached by {@see
 * ABJ_404_Solution_DatabaseUpgradeDailyMaintenance} (daily cron) and the
 * browser-triggered admin AJAX endpoint. Never run synchronously during activation.
 */
class ABJ_404_Solution_DatabaseUpgradeRedirectsSortKeyBackfill extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Narrow LEFT(<source>, 191) sort-key columns this component drains, mapped
     * to the wide source column each is copied from. Single source of truth for
     * the per-column drains, the in-band latch refresh, and the on-demand
     * scheduler so the three paths can never drift on which columns exist.
     *
     * @var array<string, string>
     */
    private const SORT_KEY_COLUMNS = array(
        'dest_sort_key' => 'dest_for_view',
        'url_sort_key'  => 'url',
    );

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
     * @param ?float $deadlineFloat Shared deadline (see backfillRedirectsDenormColumns).
     * @return int Number of redirect rows whose dest_sort_key was populated.
     */
    public function backfillRedirectsDestSortKey(?float $deadlineFloat = null): int {
        // dest_sort_key derives from dest_for_view, which is itself NULL until the
        // main backfill resolves it; the source guard skips not-yet-resolved rows.
        return $this->drainNarrowSortKey('dest_sort_key', 'dest_for_view', true, $deadlineFloat);
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
     * @param ?float $deadlineFloat Shared deadline (see backfillRedirectsDenormColumns).
     * @return int Number of redirect rows whose url_sort_key was populated.
     */
    public function backfillRedirectsUrlSortKey(?float $deadlineFloat = null): int {
        // url is NOT NULL on the schema, so the source guard is always satisfied;
        // it is passed for a single uniform code path with the dest drain.
        return $this->drainNarrowSortKey('url_sort_key', 'url', true, $deadlineFloat);
    }

    /**
     * Run the deferred narrow sort-key backfill pass (dest_sort_key +
     * url_sort_key) under ONE shared time budget. Sibling of
     * {@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill::runDeferredDenormBackfillPass()}
     * for browser-triggered passes where the main derived columns are already
     * populated.
     *
     * @param ?float $timeBudgetSec Wall-clock budget for the shared pass. When
     *   null, uses REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC.
     * @return void
     */
    public function runDeferredSortKeyBackfillPass(?float $timeBudgetSec = null): void {
        $budget = $timeBudgetSec ?? (float)$this->getRedirectsDenormBackfillTimeBudgetSec();
        $deadline = abj_clock()->nowFloat() + $budget;
        $this->backfillRedirectsDestSortKey($deadline);
        $this->backfillRedirectsUrlSortKey($deadline);
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
     * @param ?float  $deadlineFloat Shared deadline (see backfillRedirectsDenormColumns).
     * @return int Number of rows populated.
     */
    private function drainNarrowSortKey(string $targetColumn, string $sourceColumn, bool $guardSourceNotNull, ?float $deadlineFloat = null): int {
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
        $start = abj_clock()->nowFloat();
        $deadline = $deadlineFloat ?? ($start + (float)$this->getRedirectsDenormBackfillTimeBudgetSec());
        $totalRepaired = 0;

        while (abj_clock()->nowFloat() < $deadline) {
            $ids = $this->fetchNextSortKeyChunkIds($redirectsTable, $targetColumn, $sourceGuard, $chunkSize);
            if ($ids === null) {
                // Read error already warned about; stop so we don't spin.
                return $totalRepaired;
            }
            if (empty($ids)) {
                break;
            }
            if (!ABJ_404_Solution_RedirectsSortKeyChunkStore::populateForIds(
                $this->dbCore, $this->logger, $redirectsTable, $targetColumn, $sourceColumn, $sourceGuard, $ids
            )) {
                return $totalRepaired;
            }
            $this->writeCursorOption(
                ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillCursorOption($targetColumn),
                (int)max($ids)
            );
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
        // @utf8-audit: opt-out - $redirectsTable is doTableNameReplacements() of a fixed internal placeholder (lowercase prefix + literal suffix); system-controlled, cannot contain invalid UTF-8.
        // DAO-bypass-approved: schema existence probe, same shape as the drains.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($redirectsTable) . "'");
        if ($found !== $redirectsTable) {
            return;
        }
        foreach (array_keys(self::SORT_KEY_COLUMNS) as $targetColumn) {
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
        // Canonical cursor option name lives with the latch option in
        // RedirectsDenormColumnSql so this drain writer and the admin
        // progress-tooltip reader never drift.
        $cursorOption = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillCursorOption($targetColumn);
        $cursor = $this->readCursorOption($cursorOption);
        $ids = ABJ_404_Solution_RedirectsSortKeyChunkStore::queryChunkIds(
            $this->dbCore, $this->logger, $redirectsTable, $targetColumn, $sourceGuard, $chunkSize, $cursor
        );
        if ($ids === null) {
            return null;
        }
        if (empty($ids) && $cursor > 0) {
            $this->writeCursorOption($cursorOption, 0);
            $ids = ABJ_404_Solution_RedirectsSortKeyChunkStore::queryChunkIds(
                $this->dbCore, $this->logger, $redirectsTable, $targetColumn, $sourceGuard, $chunkSize, 0
            );
            if ($ids === null) {
                return null;
            }
        }
        return $ids;
    }

}
