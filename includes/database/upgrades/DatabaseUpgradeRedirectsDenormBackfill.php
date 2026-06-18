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

    // Outcomes of scheduleRedirectsSortKeyBackfill(), returned so the decision is
    // observable without spying on WP cron/shutdown internals (the callers ignore
    // the value; the integration tests assert on it).
    const SCHEDULE_SKIPPED_ALREADY = 'skipped-already';
    const SCHEDULE_SKIPPED_LATCHED = 'skipped-latched';
    const SCHEDULE_SKIPPED_NO_TABLE = 'skipped-no-table';
    const SCHEDULE_SKIPPED_NO_BACKLOG = 'skipped-no-backlog';
    const SCHEDULE_SKIPPED_THROTTLED = 'skipped-throttled';
    // Arming outcomes mirror the centralized CronScheduler deferral vocabulary so
    // a DISABLE_WP_CRON / refused-cron fallback to shutdown is observable here too.
    const SCHEDULE_VIA_CRON = ABJ_404_Solution_CronScheduler::DEFER_VIA_CRON;
    const SCHEDULE_VIA_SHUTDOWN = ABJ_404_Solution_CronScheduler::DEFER_VIA_SHUTDOWN;
    const SCHEDULE_VIA_SHUTDOWN_CRON_UNAVAILABLE = ABJ_404_Solution_CronScheduler::DEFER_VIA_SHUTDOWN_CRON_UNAVAILABLE;
    const SCHEDULE_VIA_SHUTDOWN_CRON_REFUSED = ABJ_404_Solution_CronScheduler::DEFER_VIA_SHUTDOWN_CRON_REFUSED;

    /** Five-minute guard against re-probing/re-arming the same legacy backlog on every admin read. */
    private const DENORM_ARM_THROTTLE_SECONDS = 300;

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
     * Run the full deferred denorm backfill pass (main derived columns + both
     * narrow sort keys) under ONE shared time budget.
     *
     * Why this exists: the three drains run back-to-back from the admin-armed
     * deferred trigger (shutdown hook on a normal page render, WP-Cron during
     * AJAX). With a per-call budget the combined pass could consume up to 3x
     * REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC. On hosts that do not flush the
     * HTTP response before the shutdown hook fires, that whole span is felt as
     * page latency on the admin view that armed it. A single shared deadline
     * caps the pass at one budget; whatever backlog remains drains on the next
     * armed visit or the daily cron. The daily-maintenance path deliberately
     * keeps the per-call budgets (it is true cron, never request-blocking, so
     * faster nightly convergence is preferred there).
     *
     * @return void
     */
    public function runDeferredDenormBackfillPass(): void {
        $deadline = abj_clock()->nowFloat() + (float)$this->getRedirectsDenormBackfillTimeBudgetSec();
        $this->backfillRedirectsDenormColumns($deadline);
        $this->backfillRedirectsDestSortKey($deadline);
        $this->backfillRedirectsUrlSortKey($deadline);
    }

    /**
     * Run the deferred narrow sort-key backfill pass (dest_sort_key +
     * url_sort_key) under ONE shared time budget. Sibling of
     * {@see runDeferredDenormBackfillPass()} for the sort-key-only armed trigger
     * (used when the main derived columns are already populated).
     *
     * @return void
     */
    public function runDeferredSortKeyBackfillPass(): void {
        $deadline = abj_clock()->nowFloat() + (float)$this->getRedirectsDenormBackfillTimeBudgetSec();
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
            if (!$this->populateSortKeyForIds($redirectsTable, $targetColumn, $sourceColumn, $sourceGuard, $ids)) {
                return $totalRepaired;
            }
            $this->writeCursorOption($this->sortKeyCursorOption($targetColumn), (int)max($ids));
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
        foreach (array_keys(self::SORT_KEY_COLUMNS) as $targetColumn) {
            if ($this->columnExists($redirectsTable, $targetColumn)) {
                $this->markSortKeyLatchIfComplete($redirectsTable, $targetColumn);
            }
        }
    }

    /**
     * On-demand trigger for the main redirects denorm backlog. This closes the
     * Bruno-style post-upgrade window where Destination sort cannot switch to
     * dest_sort_key because legacy rows still have dest_for_view NULL. The job is
     * deferred and time-budgeted; the admin read only arms it.
     *
     * A short persistent throttle prevents repeated admin table polls from
     * re-running the same backlog probe or registering shutdown work on every
     * request while a large shared-host table converges.
     *
     * @return string One of the SCHEDULE_* outcome constants.
     */
    public function scheduleRedirectsDenormBackfill(): string {
        if (ABJ_404_Solution_DatabaseUpgradeRuntimeState::isRedirectsDenormBackfillScheduled()) {
            return self::SCHEDULE_SKIPPED_ALREADY;
        }
        if ($this->redirectsDenormBackfillArmingIsThrottled()) {
            return self::SCHEDULE_SKIPPED_THROTTLED;
        }

        global $wpdb;
        if (!isset($wpdb)) {
            return self::SCHEDULE_SKIPPED_NO_TABLE;
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        // DAO-bypass-approved: schema existence probe, same shape as the drains.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($redirectsTable) . "'");
        if ($found !== $redirectsTable) {
            return self::SCHEDULE_SKIPPED_NO_TABLE;
        }
        if (!$this->columnExists($redirectsTable, 'dest_for_view')) {
            return self::SCHEDULE_SKIPPED_NO_BACKLOG;
        }
        if (!$this->redirectsDenormBackfillNeedsDrain($redirectsTable)) {
            return self::SCHEDULE_SKIPPED_NO_BACKLOG;
        }

        ABJ_404_Solution_DatabaseUpgradeRuntimeState::setRedirectsDenormBackfillScheduled(true);
        $this->markRedirectsDenormBackfillArmed();

        return abj_cron_scheduler()->scheduleSingleOrShutdown(
            ABJ_404_Solution_CronScheduler::HOOK_REDIRECTS_DENORM_BACKFILL,
            function (): void {
                $this->runDeferredDenormBackfillPass();
            },
            $this->shouldScheduleSortKeyBackfillViaCron()
        );
    }

    /**
     * On-demand trigger that arms the narrow sort-key drains within seconds of an
     * admin redirect-table render, instead of leaving them to the daily cron.
     *
     * Why this exists (report6.md follow-up): the read path falls back to the wide
     * source column (correct order, filesort) until the per-column backfill latch
     * is set. {@see refreshSortKeyBackfillLatches()} flips the latch in-band on
     * upgrade ONLY when the column is already fully populated; an install with a
     * real legacy backlog would otherwise wait up to a full day for the first
     * daily-cron drain. That window is harmless for the default loads (Page
     * Redirects url ASC stays bounded to the status-filtered minority; Captured
     * defaults to the timestamp index) but a MANUAL URL/Destination sort on the
     * captured-heavy tab would filesort the majority for the whole window. This
     * trigger shrinks the window to "within seconds of the first admin visit".
     *
     * Mirrors {@see ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill::scheduleLogsv2CanonicalUrlBackfill()}:
     * WP-Cron during AJAX (some hosts hold the response open until shutdown work
     * finishes), shutdown otherwise (always fires, independent of DISABLE_WP_CRON).
     * Never runs the populate loop inline; it only ARMS the deferred drain.
     *
     * Pre-flight gates (cheapest first):
     *   1. Request-scoped dedup flag -- skip if already armed this request.
     *   2. Both latches set -- skip permanently (read gates already open).
     *   3. $wpdb / redirects table existence.
     *   4. Per not-yet-latched column: skip if the column is absent (column-add
     *      ALTER not run). Probe for a DRAINABLE backlog (target NULL AND source
     *      NOT NULL -- the exact predicate the drain acts on). If the column is
     *      fully drained but the latch was never flipped, flip it in place and do
     *      not arm. Only a real drainable backlog arms the deferred drain.
     *
     * @return string One of the SCHEDULE_* outcome constants.
     */
    public function scheduleRedirectsSortKeyBackfill(): string {
        if (ABJ_404_Solution_DatabaseUpgradeRuntimeState::isRedirectsSortKeyBackfillScheduled()) {
            return self::SCHEDULE_SKIPPED_ALREADY;
        }
        if (!function_exists('get_option')) {
            return self::SCHEDULE_SKIPPED_NO_BACKLOG;
        }

        // All read gates already open -> nothing to arm. A stray NULL key behind a
        // set latch is re-healed by the Step 3d nightly reconcile, not here.
        $allLatched = true;
        foreach (array_keys(self::SORT_KEY_COLUMNS) as $targetColumn) {
            $latch = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillLatchOption($targetColumn);
            if ($latch === '' || get_option($latch) !== '1') {
                $allLatched = false;
                break;
            }
        }
        if ($allLatched) {
            return self::SCHEDULE_SKIPPED_LATCHED;
        }

        global $wpdb;
        if (!isset($wpdb)) {
            return self::SCHEDULE_SKIPPED_NO_TABLE;
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        // DAO-bypass-approved: schema existence probe, same shape as the drains.
        $found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($redirectsTable) . "'");
        if ($found !== $redirectsTable) {
            return self::SCHEDULE_SKIPPED_NO_TABLE;
        }

        $backlog = false;
        foreach (self::SORT_KEY_COLUMNS as $targetColumn => $sourceColumn) {
            $latch = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillLatchOption($targetColumn);
            if ($latch === '' || get_option($latch) === '1') {
                continue;
            }
            if ($this->sortKeyColumnNeedsDrain($redirectsTable, $targetColumn, $sourceColumn)) {
                $backlog = true;
            }
        }

        if (!$backlog) {
            return self::SCHEDULE_SKIPPED_NO_BACKLOG;
        }

        ABJ_404_Solution_DatabaseUpgradeRuntimeState::setRedirectsSortKeyBackfillScheduled(true);
        // Centralized cron-or-shutdown arming: degrades to a shutdown drain when
        // WP-Cron is disabled or refuses the event, so the narrow sort keys still
        // converge within seconds of the first visit on weak hosting.
        return abj_cron_scheduler()->scheduleSingleOrShutdown(
            ABJ_404_Solution_CronScheduler::HOOK_REDIRECTS_SORT_KEY_BACKFILL,
            function (): void {
                $this->runDeferredSortKeyBackfillPass();
            },
            $this->shouldScheduleSortKeyBackfillViaCron()
        );
    }

    /**
     * Whether to arm the sort-key drain via WP-Cron (true) or a shutdown hook
     * (false). Mirrors the canonical backfill's decision: during admin-ajax some
     * hosts/proxies hold the HTTP response open until shutdown work finishes, so
     * use WP-Cron there to keep table AJAX from timing out behind the drain; on a
     * normal page render shutdown is preferable because it always fires (even on
     * DISABLE_WP_CRON sites) after the response is already sent.
     *
     * @return bool
     */
    public function shouldScheduleSortKeyBackfillViaCron(): bool {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? $_SERVER['SCRIPT_NAME'] : '';
        if ($scriptName !== '' && basename($scriptName) === 'admin-ajax.php') {
            return true;
        }
        $pagenow = isset($GLOBALS['pagenow']) && is_string($GLOBALS['pagenow'])
            ? $GLOBALS['pagenow'] : '';
        return $pagenow === 'admin-ajax.php';
    }

    /**
     * Whether one narrow sort-key column has a DRAINABLE backlog worth arming a
     * deferred drain for, used by {@see scheduleRedirectsSortKeyBackfill()}.
     *
     * Returns false (no drain needed) when:
     *   - either column is absent (column-add ALTER not run yet; schema drift),
     *   - the backlog probe errors (leave it for the daily cron),
     *   - no row carries the drainable sentinel. In that last case the column is
     *     already drained, so the unguarded latch probe is run to flip the latch
     *     in place (opens the read gate without arming a drain).
     *
     * The probe predicate `target IS NULL AND source IS NOT NULL` is exactly what
     * the drain acts on, so a true result guarantees the deferred drain can make
     * progress (it never arms a no-op drain).
     *
     * @param string $redirectsTable
     * @param string $targetColumn Class-internal literal sort-key column.
     * @param string $sourceColumn Class-internal literal wide source column.
     * @return bool
     */
    private function sortKeyColumnNeedsDrain(string $redirectsTable, string $targetColumn, string $sourceColumn): bool {
        if (!$this->columnExists($redirectsTable, $targetColumn)
            || !$this->columnExists($redirectsTable, $sourceColumn)) {
            return false;
        }
        $probe = $this->dbCore->queryAndGetResults(
            "SELECT 1 FROM " . $redirectsTable .
            " WHERE " . $targetColumn . " IS NULL AND " . $sourceColumn . " IS NOT NULL LIMIT 1",
            array('log_too_slow' => false)
        );
        $err = isset($probe['last_error']) && is_string($probe['last_error']) ? $probe['last_error'] : '';
        if ($err !== '') {
            return false;
        }
        $rows = is_array($probe['rows'] ?? null) ? $probe['rows'] : array();
        if (empty($rows)) {
            $this->markSortKeyLatchIfComplete($redirectsTable, $targetColumn);
            return false;
        }
        return true;
    }

    /**
     * @param string $redirectsTable
     * @return bool
     */
    private function redirectsDenormBackfillNeedsDrain(string $redirectsTable): bool {
        $probe = $this->dbCore->queryAndGetResults(
            "SELECT id FROM " . $redirectsTable . " WHERE dest_for_view IS NULL ORDER BY id ASC LIMIT 1",
            array('log_too_slow' => false)
        );
        $err = isset($probe['last_error']) && is_string($probe['last_error']) ? $probe['last_error'] : '';
        if ($err !== '') {
            return false;
        }
        $rows = is_array($probe['rows'] ?? null) ? $probe['rows'] : array();
        return !empty($rows);
    }

    /** @return bool */
    private function redirectsDenormBackfillArmingIsThrottled(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $rawUntil = get_option(
            ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_ARMED_UNTIL_OPTION,
            0
        );
        $until = is_scalar($rawUntil) ? (int)$rawUntil : 0;
        return $until > abj_clock()->now();
    }

    /** @return void */
    private function markRedirectsDenormBackfillArmed(): void {
        if (!function_exists('update_option')) {
            return;
        }
        update_option(
            ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_ARMED_UNTIL_OPTION,
            (string)(abj_clock()->now() + self::DENORM_ARM_THROTTLE_SECONDS),
            false
        );
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
        $cursorOption = $this->sortKeyCursorOption($targetColumn);
        $cursor = $this->readCursorOption($cursorOption);
        $ids = $this->querySortKeyChunkIds($redirectsTable, $targetColumn, $sourceGuard, $chunkSize, $cursor);
        if ($ids === null) {
            return null;
        }
        if (empty($ids) && $cursor > 0) {
            $this->writeCursorOption($cursorOption, 0);
            $ids = $this->querySortKeyChunkIds($redirectsTable, $targetColumn, $sourceGuard, $chunkSize, 0);
            if ($ids === null) {
                return null;
            }
        }
        return $ids;
    }

    /**
     * @param string $redirectsTable
     * @param string $targetColumn
     * @param string $sourceGuard
     * @param int $chunkSize
     * @param int $afterId
     * @return array<int, int>|null
     */
    private function querySortKeyChunkIds(string $redirectsTable, string $targetColumn, string $sourceGuard, int $chunkSize, int $afterId): ?array {
        $result = $this->dbCore->queryAndGetResults(
            "SELECT id FROM " . $redirectsTable .
            " WHERE id > " . (int)$afterId . " AND " . $targetColumn . " IS NULL" . $sourceGuard .
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
     * @param string $targetColumn
     * @return string
     */
    private function sortKeyCursorOption(string $targetColumn): string {
        // Canonical name lives with the latch option in RedirectsDenormColumnSql so
        // this drain writer and the admin progress-tooltip reader never drift.
        return ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillCursorOption($targetColumn);
    }

    /**
     * @param string $option
     * @return int
     */
    private function readCursorOption(string $option): int {
        if ($option === '' || !function_exists('get_option')) {
            return 0;
        }
        $raw = get_option($option, 0);
        return max(0, is_scalar($raw) ? (int)$raw : 0);
    }

    /**
     * @param string $option
     * @param int $cursor
     * @return void
     */
    private function writeCursorOption(string $option, int $cursor): void {
        if ($option === '' || !function_exists('update_option')) {
            return;
        }
        update_option($option, (string)max(0, $cursor), false);
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
