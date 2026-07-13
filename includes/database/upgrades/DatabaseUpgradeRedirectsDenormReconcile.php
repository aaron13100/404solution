<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormReconcileIntegrationTest

/**
 * Nightly full reconcile of the four denormalized derived columns on the
 * redirects table: logshits, last_used, dest_for_view, published_status.
 *
 * Denorm Step 3d (i458 / i462). The MANDATED FLOOR of the freshness model and
 * the Tier-3 backstop: a brute-force, cursor-walked full recompute of ALL
 * redirect rows. Step 3c keeps the columns fresh in real time when a redirect,
 * post, or term changes through a WordPress hook, but a raw-SQL writer (a
 * migration tool, a sync plugin, a manual UPDATE) fires no hook and bumps no
 * timestamp, so the stored columns can silently drift. This component is the
 * thing that catches that drift: because it recomputes every row regardless of
 * its current value, it does not care whether a hook ever fired.
 *
 * Cadence (owner decision 2026-06-17): hooks (Step 3c) + this nightly reconcile
 * ONLY. There is deliberately NO more-frequent scheduled drift probe and NO
 * recurring wp_posts MAX(post_modified) "watermark" scan (the rejected shape:
 * recurring cost for invisible benefit, and the cause of prior blank-table
 * bugs). Revisit only on real incident evidence that hook-bypassers are common.
 *
 * Background job ONLY. Reached exclusively by
 * {@see ABJ_404_Solution_DatabaseUpgradeDailyMaintenance} (the daily cron). It
 * MUST NEVER run on the read path and never gates or blocks the table view; a
 * wrong stored value can only delay a refresh by at most one nightly pass, never
 * blank the table (Step 3b live-resolves the visible page off the same sources).
 * The cron-only reachability is asserted structurally by
 * {@see RedirectsDenormReconcileCronOnlyTest}.
 *
 * Chunked + wall-clock-bounded so even a large redirects table never runs long
 * in one pass. Progress is a resumable id cursor persisted in a wp_options key:
 * each invocation walks `WHERE id > cursor ORDER BY id ASC LIMIT chunkSize`,
 * advancing the cursor per chunk, until the time budget is spent (cursor
 * persists, the next nightly tick resumes mid-table) or the backlog drains
 * (cursor resets to 0 so the next tick starts a fresh full pass). Recompute is
 * idempotent, so an interrupted run resumes cleanly and a re-run is a no-op on
 * values that were already correct.
 *
 * Degrades gracefully (defensive philosophy #2/#7/#8): missing redirects table,
 * missing derived columns (column-add ALTER never completed), or an active write
 * block (read-only replica / disk full) -> skip and return 0, never error or
 * email. Per-statement write failures are logged as warnings by
 * queryAndGetResults (the centralized DAO error handler) inside the shared
 * resolver and stop the run without surfacing to the admin.
 */
class ABJ_404_Solution_DatabaseUpgradeRedirectsDenormReconcile extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /**
     * Recompute all four derived columns for every redirect row, one cursor-walked
     * chunk at a time, bounded by the per-invocation time budget.
     *
     * Skips silently (returns 0) when:
     *   - $wpdb is unavailable,
     *   - the redirects table is missing (degraded site state),
     *   - the dest_for_view column is missing (column add has not happened yet),
     *   - a DB write block is active (read-only replica / disk full).
     *
     * @return int Number of redirect rows recomputed in this invocation.
     */
    public function reconcileRedirectsDenormColumns(): int {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');

        // SHOW TABLES existence probe, same shape as the Step 3a backfill.
        // Routing through queryAndGetResults would log a benign "table doesn't
        // exist" error on freshly-installed sites before create-tables has run.
        // DAO-bypass-approved: schema existence probe, see comment above.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($redirectsTable) . "'");
        if ($found !== $redirectsTable) {
            return 0;
        }
        if (!$this->columnExists($redirectsTable, 'dest_for_view')) {
            return 0;
        }
        if ($this->dbCore->noticeState()->isWriteBlockActive()) {
            // Read-only replica / disk full: skip the whole write-heavy pass and
            // leave the cursor untouched so the next healthy tick resumes.
            $this->logger->debugMessage(
                "reconcileRedirectsDenormColumns skipped: DB write block active (read-only / disk full)."
            );
            return 0;
        }

        $chunkSize = (int)$this->getRedirectsDenormReconcileChunkSize();
        if ($chunkSize < 1) {
            $chunkSize = 1;
        }
        $timeBudget = (float)$this->getRedirectsDenormReconcileTimeBudgetSec();
        $cursor = $this->readReconcileCursor();
        $start = abj_clock()->nowFloat();
        $totalReconciled = 0;

        while ((abj_clock()->nowFloat() - $start) < $timeBudget) {
            $ids = $this->fetchNextReconcileChunkIds($redirectsTable, $cursor, $chunkSize);
            if ($ids === null) {
                // Read error already warned about; leave the cursor where it is
                // so the next tick re-reads this range, and stop so we don't spin.
                return $totalReconciled;
            }
            if (empty($ids)) {
                // No rows past the cursor: a full pass completed. Reset so the
                // next nightly tick starts a fresh sweep from the top.
                $this->resetReconcileCursor();
                break;
            }
            if (!ABJ_404_Solution_RedirectsDenormChunkResolver::resolveChunk(
                    $this->dbCore, $this->logger, $redirectsTable, $ids, true)) {
                // Write error: leave the cursor at the last fully-resolved chunk
                // so the next tick retries this one. Never reset on failure.
                return $totalReconciled;
            }
            $cursor = (int)max($ids);
            $this->writeReconcileCursor($cursor);
            $totalReconciled += count($ids);
            if (count($ids) < $chunkSize) {
                // Last (partial) chunk drained the table: pass complete, reset.
                $this->resetReconcileCursor();
                break;
            }
        }

        if ($totalReconciled > 0) {
            $this->logger->infoMessage(sprintf(
                "reconcileRedirectsDenormColumns: recomputed %d redirect rows in %.2fs.",
                $totalReconciled,
                abj_clock()->nowFloat() - $start
            ));
        }
        return $totalReconciled;
    }

    /**
     * Read the next chunk of redirect ids strictly past the cursor, in id order.
     *
     * Unlike the Step 3a backfill (which selects on the dest_for_view IS NULL
     * sentinel, a self-clearing predicate), the reconcile must revisit rows whose
     * columns are already non-NULL, so progress can only be tracked by an
     * explicit ascending-id cursor rather than by the data itself.
     *
     * @param string $redirectsTable
     * @param int    $cursor    Highest id reconciled so far (exclusive lower bound).
     * @param int    $chunkSize
     * @return array<int, int>|null List of ids (possibly empty), or null on a query error.
     */
    private function fetchNextReconcileChunkIds(string $redirectsTable, int $cursor, int $chunkSize): ?array {
        $result = $this->dbCore->queryAndGetResults(
            "SELECT id FROM " . $redirectsTable .
            " WHERE id > " . (int)$cursor . " ORDER BY id ASC LIMIT " . $chunkSize
        );
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $this->logger->warn("reconcileRedirectsDenormColumns: stopping after read error: " . $lastError);
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
     * Read the persisted reconcile cursor (highest id reconciled in the current
     * pass), 0 when no pass is in flight.
     *
     * @return int
     */
    private function readReconcileCursor(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $raw = get_option($this->getRedirectsDenormReconcileCursorOption(), 0);
        $cursor = is_scalar($raw) ? (int)$raw : 0;
        return max(0, $cursor);
    }

    /**
     * Persist the reconcile cursor so a budget-exhausted pass resumes mid-table
     * on the next nightly tick. Non-autoloaded (cron-only read).
     *
     * @param int $cursor
     * @return void
     */
    private function writeReconcileCursor(int $cursor): void {
        if (!function_exists('update_option')) {
            return;
        }
        update_option($this->getRedirectsDenormReconcileCursorOption(), (string)$cursor, false);
    }

    /**
     * Reset the cursor to 0 once a full pass completes, so the next nightly tick
     * starts a fresh sweep from the top of the table.
     *
     * @return void
     */
    private function resetReconcileCursor(): void {
        $this->writeReconcileCursor(0);
    }
}
