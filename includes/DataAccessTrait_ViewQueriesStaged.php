<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Staged getRedirectsForView pipeline.
 *
 * Replaces the legacy single-shot SQL that JOINed wp_posts/wp_terms/wp_options
 * onto every active redirect and forced an ORDER BY published_status filesort
 * across the full result before LIMIT applied. That shape times out at 45s+
 * on cold-cache shared hosts (Bruno/Showmetech, multiple 4.1.13 reports).
 *
 * The pipeline writes a precomputed view of every redirect into a shared
 * persistent table (`{wp_abj404_view_done}`). Reads serve directly from
 * that table with WHERE/ORDER/LIMIT applied. Per-tab status filtering and
 * filterText LIKE both apply at read time, so one shared `_done` serves
 * every admin's tab.
 *
 * Concurrency model: one builder at a time per site, gated by a session
 * lock (`GET_LOCK`). Atomic `RENAME TABLE` swap publishes a freshly built
 * buffer to readers. Stale-while-revalidate on every request: if the
 * served snapshot is older than VIEW_DONE_FRESHNESS_TTL_SECONDS, kick off
 * a rebuild for the next request and serve the stale data now.
 */
trait ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait {

    // Tunable constants live on ABJ_404_Solution_ViewBuildConfig (see
    // includes/ViewBuildConfig.php) instead of as `const` declarations on
    // this trait, because PHP traits cannot have constants until 8.2 and
    // the plugin declares Requires PHP: 7.4. References below are written
    // as the FQN class constant rather than `self::` so they resolve the
    // same way regardless of which class consumes the trait.

    /** @var bool Process-local guard so a single request never rebuilds twice. */
    private static $viewBuildAlreadyRanThisRequest = false;

    // Stage-runner / shutdown-diagnostics state ($viewBuildShutdownLoggerRegistered,
    // $viewBuildStageOpenForShutdown, $viewBuildShutdownStageNumber,
    // $viewBuildShutdownStageKey, $lastBatchProgressDetail) is declared on the
    // sibling ABJ_404_Solution_DataAccess_ViewBuildStageRunnerTrait so the
    // per-stage timing, shutdown logger and inflight marker share one
    // composing-class field set with the runner methods that read/write them.

    // $stagedQueryTimeoutSeconds is declared on the sibling
    // ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait so the same field
    // backs both stagedQueryOptions() (helpers trait) and the per-stage
    // writes performed by the orchestrator below.

    /**
     * Request-lifetime cache of viewDoneIsServeable().  The AJAX gate, the
     * progress reader, and the pending-build response share the same answer
     * within a single request; without this cache each call reissues a SHOW
     * TABLES probe through the centralized DAO, which on a slow host pays the
     * full diagnostic latency on every probe and pushes the gate response
     * over criterion 6's <2s budget.
     *
     * Reset to null on every fetch entry / write that mutates view_done so a
     * fresh request never sees a stale answer.
     *
     * @var bool|null
     */
    private $viewDoneIsServeableCache = null;

    // $viewBuildProgressOptionNames (the option-name registry) is declared
    // on the sibling ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait so
    // the progress get/set/clear helpers and this orchestrator share one
    // registry. self::$viewBuildProgressOptionNames resolves to the same
    // composing-class property regardless of which trait references it.

    /** @return void */
    public static function resetViewBuildOncePerRequestGuard(): void {
        self::$viewBuildAlreadyRanThisRequest = false;
        self::$viewBuildShutdownLoggerRegistered = false;
    }

    /** @return string */
    private function viewBuildTableName(): string {
        return $this->doTableNameReplacements('{wp_abj404_view_build}');
    }

    /** @return string */
    private function viewDoneTableName(): string {
        return $this->doTableNameReplacements('{wp_abj404_view_done}');
    }

    /** @return string */
    private function viewDeletemeTableName(): string {
        return $this->doTableNameReplacements('{wp_abj404_view_deleteme}');
    }

    /** @return string */
    private function viewDoneFreshnessOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_done_built_at';
    }

    /**
     * Option storing the unix timestamp of the most recent ADMIN-INITIATED
     * mutation (add/edit/trash/delete via the redirects UI). Distinct from
     * the freshness signal cleared by invalidateViewDone() (cron, maintenance,
     * upgrade, correctCollations also call invalidateViewDone but must keep
     * fbc270d8 stale-serving). Sites whose admin clicks Save expect the
     * just-saved row to render immediately, not the pre-mutation snapshot.
     *
     * viewDoneIsServeable() consults this option together with the
     * data_built_at floor: while a mutation timestamp is more recent than
     * the latest fresh build, view_done is treated as unserveable so the
     * AJAX gate returns viewBuildPending and the JS poller waits for a
     * build that covers the mutation.
     *
     * Bounded to 5 minutes via MUTATION_INVALIDATED_SANITY_SECONDS so a
     * broken cron / stuck build cannot keep view_done unserveable forever.
     *
     * @return string
     */
    private function viewDoneMutationInvalidatedAtOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_done_mutation_invalidated_at';
    }

    /** @return int Unix timestamp of the last admin-initiated mutation, or 0. */
    private function viewDoneMutationInvalidatedAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $val = get_option($this->viewDoneMutationInvalidatedAtOptionName(), 0);
        return is_scalar($val) ? max(0, intval($val)) : 0;
    }

    // Foreground admin/AJAX flows hold this lease briefly so staged-build
    // diagnostics reach the browser instead of being hidden inside cron. Cron
    // checks foregroundViewBuildLeaseActive() and reschedules itself instead
    // of taking the build lock while the lease is held.
    /** @return void */
    public function claimForegroundViewBuildLease(): void {
        if (!function_exists('update_option')) { return; }
        update_option($this->getLowercasePrefix() . 'abj404_view_build_foreground_until',
            time() + ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_FOREGROUND_LEASE_SECONDS, false);
    }
    /** @return bool */
    private function foregroundViewBuildLeaseActive(): bool {
        if (!function_exists('get_option')) { return false; }
        $until = get_option($this->getLowercasePrefix() . 'abj404_view_build_foreground_until', 0);
        return is_scalar($until) && intval($until) > time();
    }

    /**
     * Public entry: returns the page of rows the admin Redirects/Captured
     * tab should render. Read-only with respect to view_done; never runs
     * the staged build inline. If view_done is missing or invalidated, a
     * background rebuild is scheduled and ABJ_404_Solution_ViewBuildPendingException
     * is thrown so the caller can translate it into a pending response.
     *
     * The fetch AJAX handler (ViewUpdater::getPaginationLinks) gates on
     * viewDoneIsServeable() before calling this method, so under normal
     * traffic this never throws. Non-AJAX callers (REST API, snapshot
     * warmup pipeline, tests) can hit the pending path; they handle it
     * by retrying once cron / the JS poller advances the build.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     * @throws ABJ_404_Solution_ViewBuildPendingException
     */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array {
        // Honor _abj404_query_timeout from the warmup pipeline so staged
        // queries inherit the same per-stage budget legacy code did. Reset
        // on entry so a previous request's value cannot leak across calls.
        $this->stagedQueryTimeoutSeconds = isset($tableOptions['_abj404_query_timeout'])
            && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(0, intval($tableOptions['_abj404_query_timeout'])) : 0;
        if (!empty($tableOptions['_abj404_force_view_rebuild'])) {
            $this->invalidateViewDone();
        }
        $builtAt = $this->viewDoneBuiltAt();
        $isFresh = $builtAt > 0
            && (time() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS
            && $this->viewDoneIsServeable();

        if ($isFresh) {
            return $this->readFromViewDone($sub, $tableOptions);
        }

        // Stale or invalidated: if view_done is serveable (table exists with
        // rows on disk) we return the snapshot now and kick off a background
        // rebuild for the next request. Invalidated counts as "stale-but-
        // present"; the freshness signal was cleared (so the rebuild gets
        // scheduled) but the data on disk is the most recent successful
        // snapshot and is correct to serve. Hard-stale notice fires when
        // the data on disk exceeds VIEW_DONE_HARD_STALE_NOTICE_AGE_SECONDS.
        if ($this->viewDoneIsServeable()) {
            $this->scheduleViewDoneRebuild();
            $this->maybeRaiseViewDoneHardStaleNotice();
            return $this->readFromViewDone($sub, $tableOptions);
        }

        // view_done is missing on disk or empty (no usable data). Schedule a
        // background rebuild (cron + ajaxAdvanceViewBuild advance the build)
        // and signal pending back up. Inline build inside a fetch request is
        // intentionally removed: on slow hosts it fatals at max_execution_time
        // and the HTTP 500 / "critical error" payload defeats client-side
        // recovery.
        $this->scheduleViewDoneRebuild();
        $progress = $this->describeBuildProgressForNotice();
        throw new ABJ_404_Solution_ViewBuildPendingException(
            'Staged view build pending; background rebuild scheduled. Progress: ' . $progress,
            $progress
        );
    }

    /** @return int Unix timestamp of last successful build, or 0 if missing. */
    private function viewDoneBuiltAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $built = get_option($this->viewDoneFreshnessOptionName(), 0);
        return is_scalar($built) ? max(0, intval($built)) : 0;
    }

    /**
     * Public read-only check used by the AJAX fetch endpoints to gate "serve
     * from cache vs. return pending". True when view_done exists on disk and
     * contains rows. Stale-but-present is serveable: invalidate clears the
     * freshness signal but leaves the table contents intact, so the steady-
     * state warm path serves stale and schedules a background rebuild
     * without blocking.
     *
     * Note: serveability does NOT depend on the freshness/built_at signal.
     * That signal gates whether to schedule a rebuild (stale = schedule), not
     * whether the existing data can be returned. Serving stale-but-present
     * data is correct: the data is at most one freshness window out of date
     * relative to when it was last produced, and the maybeRaiseViewDoneHard
     * StaleNotice() check fires an admin notice when staleness exceeds the
     * upper bound (VIEW_DONE_HARD_STALE_NOTICE_AGE_SECONDS).
     *
     * The ViewUpdater AJAX path uses this to avoid triggering the inline
     * build inside a request: if not serveable, the fetch returns
     * `viewBuildPending: true` and the JS poller hits ajaxAdvanceViewBuild.
     *
     * @return bool
     */
    public function viewDoneIsServeable(): bool {
        if ($this->viewDoneIsServeableCache !== null) {
            return $this->viewDoneIsServeableCache;
        }
        if (!$this->viewDoneTableExists()) {
            $this->viewDoneIsServeableCache = false;
            return false;
        }
        // Admin-mutation gate: when the admin just added/edited/trashed/
        // deleted a redirect, the snapshot on disk does not yet reflect
        // their change. Returning serveable here would let the AJAX fetch
        // hand back the pre-mutation rows; the admin would not see their
        // own action take effect until the next cron rebuild. Treat as
        // unserveable so the gate returns viewBuildPending and the JS
        // poller waits for a build that covers the mutation. Bounded by
        // MUTATION_INVALIDATED_SANITY_SECONDS so a stuck build cannot
        // block the page indefinitely; after that window the gate falls
        // back to fbc270d8 stale-serving plus the hard-stale notice.
        $mutationAt = $this->viewDoneMutationInvalidatedAt();
        if ($mutationAt > 0
                && $mutationAt > time() - ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_MUTATION_INVALIDATED_SANITY_SECONDS) {
            $dataBuiltAt = $this->viewDoneDataBuiltAt();
            if ($dataBuiltAt <= $mutationAt) {
                $this->viewDoneIsServeableCache = false;
                return false;
            }
        }
        // Empty view_done is NOT serveable when there has never been a
        // successful build: rendering an empty admin screen during a cold
        // start is a worse UX than a brief pending/loading state that drives
        // the build forward. The has-rows probe also catches the rare "S11
        // promoted an empty buffer" failure mode where the swap completed
        // but S2 produced no rows (botched build state); without this guard
        // the admin would render blank indefinitely with no rebuild ever
        // scheduled.
        //
        // BUT: when a build has actually completed (data_built_at > 0) and
        // the table is genuinely empty (e.g. a fresh install with no
        // redirects yet, or the admin dropped wp_abj404_redirects via WP-CLI
        // and the recreated table is empty), an empty view_done IS the
        // correct serveable result. Returning false here would loop the JS
        // poller forever on a cold install: every build cycle produces an
        // empty view_done, viewDoneIsServeable() returns false, ViewUpdater
        // returns viewBuildPending, the poller fires another advance, and
        // the cycle repeats with no exit. data_built_at distinguishes
        // "build has never completed" from "build completed and the dataset
        // is genuinely empty".
        if ($this->viewDoneHasRows()) {
            $this->viewDoneIsServeableCache = true;
            return true;
        }
        if ($this->viewDoneDataBuiltAt() > 0) {
            $this->viewDoneIsServeableCache = true;
            return true;
        }
        $this->viewDoneIsServeableCache = false;
        return false;
    }

    /**
     * Mark view_done as needing a fresh build because the admin just
     * mutated a redirect through the UI (add/edit/trash/delete). Sets the
     * mutation-invalidated-at option which gates viewDoneIsServeable()
     * until markViewDoneBuildCompleted() runs (or the sanity timeout
     * VIEW_DONE_MUTATION_INVALIDATED_SANITY_SECONDS elapses).
     *
     * Differs from invalidateViewDone() (which serves stale-but-present
     * per fbc270d8 to avoid Loading-redirects on slow hosts during cron /
     * maintenance / correctCollations). Admin actions need immediate
     * feedback: after clicking Save, the user expects to see the new row,
     * not the snapshot from before. This method is the single seam
     * controllers call to opt into that stricter contract.
     *
     * Composes invalidateViewDone() so the existing freshness-clear /
     * progress-clear / buffer-drop / rebuild-schedule lifecycle still
     * runs; the new mutation flag is purely additive.
     *
     * @return void
     */
    public function markViewDoneInvalidatedByAdminMutation(): void {
        if (function_exists('update_option')) {
            update_option($this->viewDoneMutationInvalidatedAtOptionName(), time(), false);
        }
        $this->invalidateViewDone();
    }

    /**
     * Public accessor for the unix-time the view_done snapshot was last
     * successfully built. Returns 0 when never built or when the freshness
     * option has been cleared by an invalidation. Used by the admin footer
     * (and any diagnostic surface) to render a "Cache view freshness: 5m"
     * indicator without exposing the internal option name.
     *
     * @return int  Unix timestamp, or 0.
     */
    public function getViewDoneBuiltAtTimestamp(): int {
        return $this->viewDoneBuiltAt();
    }

    /**
     * Invalidate the request-lifetime serveability cache.  Called from any
     * code path that mutates view_done (rename/drop/build completion) so a
     * subsequent read in the same request sees fresh state.
     *
     * @return void
     */
    private function invalidateViewDoneServeableCache(): void {
        $this->viewDoneIsServeableCache = null;
    }

    /**
     * Public hook called from the S11 swap completion path and from the
     * reconcile-promote path when a fresh view_done has just been published.
     * Updates both freshness and data-built-at signals to now, clears the
     * hard-stale admin notice (self-heal), and resets the request-lifetime
     * serveability cache so subsequent reads in the same request see the
     * just-published table.
     *
     * The data-built-at signal is the floor used by maybeRaiseViewDoneHard
     * StaleNotice() to decide when to surface the "data may be out of date"
     * admin notice. Updating it here means the notice can self-clear
     * automatically once the build catches up, so an admin who fixed the
     * underlying cron or host issue does not see a 24h-stale warning for
     * the entire dedup TTL after recovery.
     *
     * @return void
     */
    public function markViewDoneBuildCompleted(): void {
        if (function_exists('update_option')) {
            $now = $this->clock()->now();
            update_option($this->viewDoneFreshnessOptionName(), $now, false);
            update_option($this->viewDoneDataBuiltAtOptionName(), $now, false);
        }
        $this->clearViewDoneHardStaleNotice();
        // Clear the admin-mutation gate: the fresh build covers any
        // mutation that triggered it, so viewDoneIsServeable() no longer
        // needs to block reads. Leaving it set would force "Loading
        // redirects" for the full 5-minute sanity window after every
        // admin save even though fresh data is on disk.
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneMutationInvalidatedAtOptionName());
        }
        $this->invalidateViewDoneServeableCache();
    }

    /**
     * Public progress snapshot used by the AJAX fetch endpoints when they
     * return a pending response, and by the build-advance endpoint after each
     * tick.  Always safe to call; never queries beyond cheap option reads
     * plus a SHOW TABLES probe.
     *
     * Shape:
     *   - status:        'ready' (view_done is serveable) or 'pending'
     *   - stage:         current sub-stage (0..11) reached so far
     *   - of:            11 (total number of build sub-stages)
     *   - build_started: unix ts when this resumable build began (0 if none)
     *   - progress_text: short human-readable summary (e.g. "stage 2/11")
     *   - fingerprint:   per-tick mutation counters used by the JS poller
     *                    to detect within-stage progress (S2/S4/S5 advance
     *                    high-water ids across multiple ticks while
     *                    current_stage stays the same). The poller gives
     *                    up only when this fingerprint stops changing.
     *
     * @return array<string, mixed>
     */
    public function getViewBuildProgress(): array {
        $stage = $this->readProgressOption('current_stage', 0);
        $startedAt = $this->readProgressOption('started_at', 0);
        $status = $this->viewDoneIsServeable() ? 'ready' : 'pending';
        return array(
            'status' => $status,
            'stage' => max(0, $stage),
            'of' => 11,
            'build_started' => max(0, $startedAt),
            // Cheap text derived from option reads only.  The row-count rich
            // version (describeBuildProgressForNotice) issues two extra DAO
            // queries (countViewBuildRows + countLiveRedirects) which on a
            // slow host can multiply the gate's response time several-fold.
            // Callers that want the rich text can call describeBuildProgressForNotice
            // directly; AJAX gate / poll responses use the cheap form.
            'progress_text' => $stage > 0 ? ('stage ' . $stage . '/11') : 'not yet started',
            'fingerprint' => $this->getViewBuildProgressFingerprint(),
        );
    }

    /**
     * Public bounded build-advance entry point used by ajaxAdvanceViewBuild.
     * Runs at most one resumable tick of the staged build (10s/stage budget;
     * yields mid-stage on S2/S4/S5).  Idempotent: safe to call concurrently.
     * Competing callers fail to acquire the build lock and just return the
     * current progress.  Returns the same shape as getViewBuildProgress()
     * with an additional `locked` bool that is true when this call did not
     * acquire the lock (another worker is already advancing the build).
     *
     * Errors during a tick propagate as exceptions; the caller (AJAX handler)
     * surfaces them.  This is intentionally NOT silent: a failing build that
     * never advances would otherwise leave the JS poller spinning forever.
     *
     * @param bool $forceRebuild  Diagnostic mode (?abj404_force_view_rebuild=1):
     *   - skip the viewDoneIsServeable() short-circuit so we always run the
     *     staged build under the caller's request context, making every
     *     staged_build_s* sub-stage event visible in the AJAX debug log;
     *   - wait up to 30s for the build lock so a sibling cron / tab build
     *     finishes and we can take ownership of the next build cleanly;
     *   - re-invalidate inside the locked region so we run a fresh build
     *     rather than the data the prior lock holder just produced;
     *   - reset the per-request once-guard so a force-rebuild always proceeds
     *     even if a sibling code path already ran a build in this request.
     *
     * @return array<string, mixed>
     */
    public function advanceViewBuildOnce(bool $forceRebuild = false): array {
        if ($forceRebuild) {
            // Allow the build to run even if a sibling read path on the same
            // request already entered the once-guard; the diagnostic flow
            // explicitly wants to rerun.
            self::$viewBuildAlreadyRanThisRequest = false;
        }
        if (!$forceRebuild && $this->viewDoneIsServeable()) {
            return $this->getViewBuildProgress();
        }
        // 10s wait when forced so a cron build mid-flight can release the
        // lock before we take it. Without this, force-rebuild would return
        // locked=true, the JS poller would back off, the cron build would
        // finish in the background under no AJAX context, and the next
        // poll would see view_done as fresh -- no stage diagnostics ever
        // reach the debug log. 10s leaves comfortable headroom inside a
        // 30s PHP request: the typical cron build completes in seconds,
        // and if it doesn't we still return locked=true and the JS poller
        // can retry on the next page load.
        $lockTimeoutSeconds = $forceRebuild ? 10 : 0;
        if (!$this->acquireViewBuildLock($lockTimeoutSeconds)) {
            // Force-rebuild lock losses are interesting: a 10s wait that
            // still failed means another build held the lock longer than
            // expected (cron stuck, sibling tab mid-S2/S4/S5, dead session
            // holding GET_LOCK). Always-locked is one of the symptoms
            // Bruno/Troy report when their build never finishes, so log
            // every miss with the path so we can tell which caller blocked.
            $this->logger->debugMessage(sprintf(
                '[staged] advanceViewBuildOnce: lock not acquired '
                . '(forceRebuild=%s, waited up to %ds)',
                $forceRebuild ? 'true' : 'false', $lockTimeoutSeconds
            ));
            $progress = $this->getViewBuildProgress();
            $progress['locked'] = true;
            return $progress;
        }
        try {
            if ($forceRebuild) {
                // Whatever the prior lock holder produced (cron, sibling tab,
                // a finished S11 swap) we discard inside the locked region
                // so the rebuild happens fresh under the caller's request
                // context. invalidateViewDone() also flips the per-request
                // serveability cache so getViewBuildProgress() at the end
                // reflects the rebuilt state, not the stale-cached one.
                $this->invalidateViewDone();
                // Force-rebuild also clears any per-stage permanent skip
                // markers and the build-halted gate from a prior host
                // failure. The admin pressed "rebuild" explicitly, so
                // re-attempting denied DDL is the intended action.
                $this->clearStagedBuildDegradedState();
            } else {
                // Same runner-startup reconciliation as the cron entry: an
                // AJAX advance picking up after a previous crash must
                // preserve the buffer S2-S10 already built rather than
                // throwing it away to start over. Force-rebuild skips
                // this because the admin explicitly asked to rebuild
                // from scratch.
                $reconcileResult = $this->reconcileStagedTablesAtRunnerStartup();
                if ($reconcileResult === 'promoted') {
                    return $this->getViewBuildProgress();
                }
            }
            $isComplete = $this->runStagedBuildOnce();
        } finally {
            $this->releaseViewBuildLock();
        }
        if ($isComplete) {
            return $this->getViewBuildProgress();
        }
        // Yielded mid-stage; schedule a background tick so cron pushes forward
        // even if the JS poller stops. Cron respects the foreground lease.
        $this->scheduleViewDoneRebuild();
        return $this->getViewBuildProgress();
    }

    /**
     * Synchronous fallback that advances the staged view-build by one
     * tick on plugin admin page-load when WP-Cron is broken. Pairs with
     * the cron-stuck admin notice (c374): the notice tells the admin
     * cron is broken; this fallback unblocks the page in the meantime
     * so they do not stare at "Carregando redirecionamentos..." (the
     * Portuguese localization Bruno reported) forever while they fix
     * cron.
     *
     * Without this, hosts where DISABLE_WP_CRON is set in wp-config.php
     * AND no external system cron replaces it leave the staged build
     * stuck: the AJAX JS poller would advance it, but the poller only
     * fires after the page renders, and the fetch path hard-gates on
     * view_done being serveable. The admin sees the loading message
     * indefinitely on every page-load.
     *
     * Gates (cheap, in order):
     *   1. getCronStuckHours() < 24: cron is healthy enough; no fallback
     *      needed. The 24h floor matches the cron-stuck notice (c374)
     *      so the two signals fire together, not piecewise.
     *   2. viewDoneIsServeable() === true: the build is already done,
     *      so there is nothing to advance. Free option-read check.
     *   3. abj404_page_load_fallback_advance transient set: a sibling
     *      sub-request already ran the fallback inside the 60s window;
     *      avoid burning a second stage of inline work in the same
     *      admin burst.
     *
     * Bounding:
     *   - A short-lived filter is registered on the per-stage budget
     *     hook (abj404_view_build_per_stage_budget_seconds) so the
     *     advance call inside the lock cannot spend the full 10s
     *     default per-stage budget. The filter is removed in finally
     *     so the next AJAX advance / cron tick sees the normal budget.
     *
     * Lock semantics:
     *   - Delegates to advanceViewBuildOnce(false), which acquires the
     *     build lock with a 0s timeout. A sibling cron / AJAX advance
     *     already in flight returns immediately with locked=true and
     *     this method reports reason='locked' without doing further
     *     work. The build progress under the existing lock holder is
     *     still being made; the admin's next page-load (after the gate
     *     window) will try again.
     *
     * Caller contract (admin_init wrapper in 404-solution.php):
     *   - Only call when is_admin() is true.
     *   - Only call when the current user has the plugin-admin
     *     capability so unauthenticated requests cannot trigger build
     *     work.
     *   - Wrap in try/catch; a failure here must not break admin page
     *     rendering. Log at warning level so it does not generate dev
     *     email reports per the self-healing philosophy.
     *
     * @return array{ran:bool, reason:string, progress:array<string,mixed>}
     */
    public function runPageLoadFallbackAdvance(): array {
        // Cron is healthy: nothing for the fallback to do. Free check
        // (one wp_get_ready_cron_jobs call) so we can run it first.
        if ($this->getCronStuckHours() < 24) {
            return array(
                'ran' => false,
                'reason' => 'cron_healthy',
                'progress' => $this->getViewBuildProgress(),
            );
        }

        // Build already serveable: returning before any further work
        // keeps the fallback's steady-state cost at zero on hosts that
        // recover, which is the desirable shape (admin returns to a
        // working page without page-load latency).
        if ($this->viewDoneIsServeable()) {
            return array(
                'ran' => false,
                'reason' => 'not_needed',
                'progress' => $this->getViewBuildProgress(),
            );
        }

        // Transient gate: a single admin click can produce many
        // sub-requests (prefetch, refresh, multiple browser tabs).
        // Cap inline advances to one per 60s so the page-load impact
        // cannot compound. 60s is short enough that an attentive admin
        // sees real per-load progress, and long enough that bursts of
        // navigation do not stack inline build work.
        $haveTransientApi = function_exists('get_transient') && function_exists('set_transient');
        $gateKey = ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_GATE_KEY;
        if ($haveTransientApi && get_transient($gateKey) !== false) {
            return array(
                'ran' => false,
                'reason' => 'gate_active',
                'progress' => $this->getViewBuildProgress(),
            );
        }
        if ($haveTransientApi) {
            // Set the gate BEFORE running the advance so any failure or
            // long-running stage still suppresses the next sub-request.
            // Without this, a slow advance that times out partway would
            // be retried by the very next sub-request, compounding the
            // page-load impact instead of bounding it.
            set_transient(
                $gateKey,
                1,
                (int)ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_GATE_SECONDS
            );
        }

        // Compress the per-stage budget for just this advance. The
        // production filter machinery is the existing hook
        // abj404_view_build_per_stage_budget_seconds; clamping via
        // min() rather than overwriting preserves any operator-set
        // smaller-budget filter for hosts that have already tuned
        // down. Priority 100 runs after most operator filters so the
        // fallback's ceiling dominates.
        $budgetSeconds = (float)ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_BUDGET_SECONDS;
        $budgetFilter = static function ($incoming) use ($budgetSeconds) {
            $value = is_scalar($incoming) ? (float)$incoming : $budgetSeconds;
            return min($value, $budgetSeconds);
        };
        $filterRegistered = false;
        if (function_exists('add_filter')) {
            add_filter('abj404_view_build_per_stage_budget_seconds', $budgetFilter, 100);
            $filterRegistered = true;
        }

        try {
            // forceRebuild=false: this is the self-healing path. The
            // explicit ?abj404_force_view_rebuild=1 admin recovery is a
            // separate gesture that intentionally clears degraded gates
            // and waits 30s for the lock. Page-load fallback should
            // never escalate to those semantics.
            $progress = $this->advanceViewBuildOnce(false);
        } finally {
            if ($filterRegistered && function_exists('remove_filter')) {
                remove_filter('abj404_view_build_per_stage_budget_seconds', $budgetFilter, 100);
            }
        }

        $reason = !empty($progress['locked']) ? 'locked' : 'advanced';
        return array(
            'ran' => true,
            'reason' => $reason,
            'progress' => $progress,
        );
    }

    /**
     * COUNT(*) sibling to runRedirectsForViewStaged. Used by
     * getRedirectsForViewCount when filterText is non-empty (the
     * filterText-empty path already uses the optimized COUNT against
     * the live redirects table, which stays fast). Same build/serve flow
     * as the row path; the build is shared via the per-request guard.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int {
        $this->stagedQueryTimeoutSeconds = isset($tableOptions['_abj404_query_timeout'])
            && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(0, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $builtAt = $this->viewDoneBuiltAt();
        $isFresh = $builtAt > 0
            && (time() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS
            && $this->viewDoneIsServeable();

        if (!$this->viewDoneIsServeable()) {
            // view_done is missing on disk or empty (no usable data).
            // Schedule a background rebuild and signal pending; never run
            // the staged build inline inside a request. The fetch AJAX gate
            // prevents this from being reached under normal traffic; non-
            // AJAX callers retry on next request.
            $this->scheduleViewDoneRebuild();
            $progress = $this->describeBuildProgressForNotice();
            throw new ABJ_404_Solution_ViewBuildPendingException(
                'Staged view-count build pending; background rebuild scheduled. Progress: ' . $progress,
                $progress
            );
        }

        if (!$isFresh) {
            // Stale or invalidated but data on disk is serveable: return the
            // stale count and kick off a background rebuild. Hard-stale
            // notice fires when the data on disk exceeds the upper bound.
            $this->scheduleViewDoneRebuild();
            $this->maybeRaiseViewDoneHardStaleNotice();
        }

        $sql = $this->buildViewDoneCountQuery($sub, $tableOptions);
        $result = $this->queryAndGetResults($sql, $this->stagedQueryOptions());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return 0;
        }
        $row = is_array($rows[0]) ? $rows[0] : array();
        $raw = $row['cnt'] ?? reset($row);
        return is_scalar($raw) ? intval($raw) : 0;
    }

    /**
     * Hook target for `wp_schedule_single_event('abj404_rebuildViewDone')`.
     * Rebuilds inline (under the build lock) so the next admin request
     * sees fresh data. Called from PluginLogic during cron registration.
     *
     * @return void
     */
    public function rebuildViewDoneInBackground(): void {
        if ($this->foregroundViewBuildLeaseActive()) {
            $this->logger->debugMessage(
                '[staged] rebuildViewDoneInBackground: deferring; '
                . 'foreground build lease active. Rescheduled.'
            );
            $this->scheduleViewDoneRebuild(ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_FOREGROUND_LEASE_SECONDS);
            return;
        }
        if (!$this->acquireViewBuildLock()) {
            $this->logger->debugMessage(
                '[staged] rebuildViewDoneInBackground: lock not acquired '
                . '(another worker is building); skipping this cron tick.'
            );
            return;
        }
        try {
            // Runner-startup reconciliation: clean up inconsistent staged-
            // table state from a previous run that crashed mid-S11 or was
            // OOM-killed before the swap option write. Runs BEFORE
            // runStagedBuildOnce() so its halt-gate / once-guard short-
            // circuits cannot suppress the cleanup, and it can short-
            // circuit the rebuild itself when it manages to recover the
            // previous run's buffer in place.
            $reconcileResult = $this->reconcileStagedTablesAtRunnerStartup();
            if ($reconcileResult === 'promoted') {
                // The previous run's view_build was renamed to view_done
                // in place; freshness is recorded; view_done is now
                // serveable. No need to re-run the staged build this tick.
                return;
            }
            $isComplete = $this->runStagedBuildOnce();
            if (!$isComplete) {
                // Build yielded mid-stage; schedule another tick to continue.
                $this->scheduleViewDoneRebuild();
            }
        } catch (Throwable $e) {
            // Log at warning level, not error: a failed background rebuild
            // leaves the plugin functional (the prior view_done snapshot,
            // if any, is still served).  Per CLAUDE.md self-healing rules,
            // infrastructure failures should not generate dev email reports.
            $this->logger->warn('[staged] background rebuild yielded an error: ' . $e->getMessage());
        } finally {
            $this->releaseViewBuildLock();
        }
    }

    /**
     * Reconcile staged-build table state from a previous run that ended
     * in an inconsistent place, before this run's stages execute. Called
     * from rebuildViewDoneInBackground() AFTER the build lock is
     * acquired (so we cannot race a sibling worker on the same site)
     * and BEFORE runStagedBuildOnce() (so the staged orchestrator sees
     * a clean starting state regardless of which entry path took the
     * lock).
     *
     * Cases handled:
     *
     *   1. Orphan `{wp_abj404_view_deleteme}` from a prior crashed S11
     *      swap (or a critical-stage halt that left the previous run's
     *      deleteme on disk). Drop it. Always safe; deleteme is a
     *      transient by design.
     *
     *   2. `{wp_abj404_view_build}` exists, `{wp_abj404_view_done}` does
     *      NOT, AND no resumable build progress is recorded: the
     *      previous run completed S2-S10 but crashed before the S11
     *      RENAME swap published the buffer. Promote the buffer in
     *      place via `RENAME TABLE view_build TO view_done`, mark
     *      fresh, clear progress. Preserves the work of S2-S10 instead
     *      of throwing it away.
     *
     *   3. Both `{wp_abj404_view_build}` and `{wp_abj404_view_done}`
     *      exist, AND no resumable build progress is recorded: the
     *      previous run halted between stages with both tables on
     *      disk. Treat view_done as the live one and drop view_build
     *      so the next fresh build starts from a known empty buffer.
     *
     * Resumable progress = `started_at` within
     * VIEW_BUILD_RESUME_TTL_SECONDS and either a completed stage
     * (`current_stage` > 0) or a durable started-stage marker. When a
     * resumable build is in flight we leave view_build alone so the
     * next tick can continue from the persisted high-water id (cases
     * 2 and 3 are skipped; case 1 still runs).
     *
     * Reconciliation actions are best-effort: when DROP / RENAME is
     * denied by the host (privilege loss between runs), surface a
     * deduplicated admin notice naming the specific tables and
     * recommending manual cleanup. The build then falls through to
     * runStagedBuildOnce() which will hit its own host-failure
     * classifier.
     *
     * @return string  One of:
     *                 'none'     - no reconciliation needed.
     *                 'cleaned'  - orphan tables dropped; build can proceed.
     *                 'promoted' - view_build was renamed to view_done;
     *                              view_done is fresh; rebuild can be
     *                              skipped this tick.
     *                 'failed'   - reconciliation could not complete
     *                              (privilege denied); admin notice set.
     */
    public function reconcileStagedTablesAtRunnerStartup(): string {
        $tempDeletemeTable = $this->viewDeletemeTableName();
        $tempBuildTable    = $this->viewBuildTableName();
        $doneTable         = $this->viewDoneTableName();

        $action = 'none';
        $haveDeleteme = $this->stagedTableExists($tempDeletemeTable);

        if ($haveDeleteme) {
            $r = $this->queryAndGetResults(
                'DROP TABLE IF EXISTS `' . $tempDeletemeTable . '`',
                array('log_errors' => false)
            );
            $err = isset($r['last_error']) && is_string($r['last_error']) ? trim($r['last_error']) : '';
            if ($err === '' || !$this->stagedTableExists($tempDeletemeTable)) {
                $this->logger->infoMessage(sprintf(
                    '[staged] reconcile: dropped orphan view_deleteme `%s` from a previous failed run',
                    $tempDeletemeTable
                ));
                $action = 'cleaned';
            } else {
                $this->logger->warn(sprintf(
                    '[staged] reconcile: orphan view_deleteme `%s` could not be dropped: %s',
                    $tempDeletemeTable, substr($err, 0, 200)
                ));
                $this->setStagedBuildHaltNotice('orphan_deleteme', sprintf(
                    'An orphan staged-build buffer `%s` from a previous failed run could not be removed (privilege denied?): %s. Manual cleanup: drop the buffer table `%s` from your database (e.g. via phpMyAdmin or your hosting MySQL console).',
                    $tempDeletemeTable, substr($err, 0, 200), $tempDeletemeTable
                ));
                $action = 'failed';
            }
        }

        // Resumable build in flight? Leave $tempBuildTable / view_done alone
        // so the next tick can continue from the persisted high-water
        // id; orphan deleteme cleanup above already ran and is enough.
        $startedAt = $this->readProgressOption('started_at', 0);
        $currentStage = $this->readProgressOption('current_stage', 0);
        $lastStartedStage = $this->readProgressOption('last_started_stage', 0);
        $resumeWindowOk = $startedAt > 0
            && (time() - $startedAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_RESUME_TTL_SECONDS;
        if ($resumeWindowOk && ($currentStage > 0 || $lastStartedStage > 0)) {
            return $action;
        }

        $haveBuild = $this->stagedTableExists($tempBuildTable);
        $haveDone  = $this->stagedTableExists($doneTable);

        // Case 2: view_build exists, view_done missing. Promote the
        // buffer in place rather than re-running S1-S11 from scratch
        // -- but only when an integrity probe says the buffer is
        // plausibly complete. Without the integrity check we could
        // publish a partially-built buffer (S2 stopped halfway, or
        // invalidateViewDone() cleared progress while a redirect edit
        // had also added rows we never picked up).
        if ($haveBuild && !$haveDone) {
            if (!$this->bufferIntegrityPassesForPromote($tempBuildTable)) {
                $this->logger->infoMessage(sprintf(
                    '[staged] reconcile: not promoting view_build `%s` (integrity probe failed); dropping for fresh rebuild',
                    $tempBuildTable
                ));
                $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $tempBuildTable . '`',
                    array('log_errors' => false));
                return 'cleaned';
            }
            $sql = 'RENAME TABLE `' . $tempBuildTable . '` TO `' . $doneTable . '`';
            $r = $this->queryAndGetResults($sql, array('log_errors' => true));
            $err = isset($r['last_error']) && is_string($r['last_error']) ? trim($r['last_error']) : '';
            if ($err === '' && $this->stagedTableExists($doneTable)) {
                $this->logger->infoMessage(sprintf(
                    '[staged] reconcile: promoted view_build to view_done '
                    . '(`%s` -> `%s`); previous run crashed before S11 swap',
                    $tempBuildTable, $doneTable
                ));
                // Same as the S11 swap completion: update both freshness
                // signals, clear hard-stale notice, reset serveability cache.
                $this->markViewDoneBuildCompleted();
                $this->clearAllProgressOptions();
                return 'promoted';
            }
            $this->logger->warn(sprintf(
                '[staged] reconcile: could not promote view_build to view_done: %s',
                substr($err, 0, 200)
            ));
            $this->setStagedBuildHaltNotice('promote_build_failed', sprintf(
                'A staged-build buffer `%s` exists from a previous run but could not be promoted to `%s` (privilege denied?): %s. Manual cleanup: rename the buffer `%s` to `%s`, or remove the buffer `%s` from your database (e.g. via phpMyAdmin or your hosting MySQL console).',
                $tempBuildTable, $doneTable, substr($err, 0, 200),
                $tempBuildTable, $doneTable, $tempBuildTable
            ));
            return 'failed';
        }

        // Case 3: both tables exist. view_done is the live one; the
        // orphan $tempBuildTable is from a halted previous run. Drop it so
        // the next fresh build starts from a known empty buffer.
        if ($haveBuild && $haveDone) {
            $r = $this->queryAndGetResults(
                'DROP TABLE IF EXISTS `' . $tempBuildTable . '`',
                array('log_errors' => false)
            );
            $err = isset($r['last_error']) && is_string($r['last_error']) ? trim($r['last_error']) : '';
            if ($err === '' || !$this->stagedTableExists($tempBuildTable)) {
                // WARN (not INFO) so this signal survives a site with DEBUG
                // disabled. Carries the four progress fields support needs
                // to distinguish "build keeps restarting at S1" from
                // "build invalidated on every redirect edit / cron tick"
                // from "multi-tab/cron lock contention orphaning each
                // partial build" without asking for another debug zip.
                $lastCompletedStage = $this->readProgressOption('last_completed_stage', 0);
                $age = $startedAt > 0 ? max(0, time() - $startedAt) : 0;
                $this->logger->warn(sprintf(
                    '[staged] reconcile: dropped orphan view_build `%s` (view_done is live; previous run halted before swap); '
                    . 'current_stage=%d last_started_stage=%d last_completed_stage=%d started_at=%d age=%ds',
                    $tempBuildTable,
                    $currentStage,
                    $lastStartedStage,
                    $lastCompletedStage,
                    $startedAt,
                    $age
                ));
                return 'cleaned';
            }
            $this->logger->warn(sprintf(
                '[staged] reconcile: orphan view_build `%s` could not be dropped: %s',
                $tempBuildTable, substr($err, 0, 200)
            ));
            $this->setStagedBuildHaltNotice('orphan_build', sprintf(
                'A staged-build buffer `%s` from a previous run still exists alongside the live view_done, but could not be removed: %s. Manual cleanup: drop the buffer `%s` from your database (e.g. via phpMyAdmin or your hosting MySQL console).',
                $tempBuildTable, substr($err, 0, 200), $tempBuildTable
            ));
            return 'failed';
        }

        return $action;
    }

    /**
     * Integrity probe used by the case-2 promote branch of
     * reconcileStagedTablesAtRunnerStartup(). Returns true only when the
     * buffer is plausibly complete: row count matches the live redirects
     * table within a small tolerance (one redirect could have been
     * added during the build window). Returns false on any probe error
     * so a transient DB hiccup never publishes a buffer of unknown
     * shape as the live snapshot.
     *
     * Row-count parity is a coarse check (it cannot detect stale POST
     * resolutions when wp_posts has changed mid-flight). Promote is
     * already an opportunistic recovery; if we're wrong, the next
     * invalidate-driven rebuild will replace view_done.
     *
     * @param string $bufferTable
     * @return bool
     */
    private function bufferIntegrityPassesForPromote(string $bufferTable): bool {
        $bufferRows = $this->countViewBuildRows();
        if ($bufferRows <= 0) {
            return false;
        }
        $liveRows = $this->countLiveRedirects();
        if ($liveRows <= 0) {
            // No redirects in the live table -- treat any buffer as
            // unsafe to publish (a bug pruned all redirects, or the
            // count probe itself errored).
            return false;
        }
        // Allow the buffer to differ from live by up to one row in
        // either direction so an admin who created or deleted a single
        // redirect during the build window does not block promotion.
        $diff = abs($bufferRows - $liveRows);
        return $diff <= 1;
    }

    /**
     * Mark the served view_done table stale so the next request triggers a
     * rebuild. Hooked from invalidateViewSnapshotCache() so redirect
     * create/update/delete invalidates the precomputed view too. Also clears
     * any in-flight progress AND drops view_build / view_deleteme in the
     * same call (gated by SHOW TABLES so steady-state invalidate stays
     * cheap), making the "progress lost but buffer present" state unreachable
     * so the next request never classifies a leftover buffer as an orphan
     * to drop (Troy 2026-05: two "[staged] reconcile: dropped orphan
     * view_build" INFO lines 6 min apart). Buffer-drop helper lives in
     * DataAccessTrait_ViewBuildStageCallbacks.
     *
     * @return void
     */
    public function invalidateViewDone(): void {
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneFreshnessOptionName());
            foreach (self::$viewBuildProgressOptionNames as $optName) {
                delete_option($this->getLowercasePrefix() . $optName);
            }
        }
        // The S1-prefix capture is part of the same fresh-start lifecycle:
        // a redirect-edit invalidation forces the next request to S1 from
        // scratch, so the prior capture is no longer authoritative.
        $this->clearPrefixAtStageOne();
        $this->dropTransientBuffersIfPresent();
        $this->invalidateViewDoneServeableCache();
        // Kick off a background rebuild and surface any cron-stuck /
        // schedule-failure conditions to the admin via deduplicated notice.
        // scheduleViewDoneRebuild() is idempotent (checks wp_next_scheduled).
        $this->scheduleViewDoneRebuild();
    }

    // progressOptionName / readProgressOption / writeProgressOption /
    // clearAllProgressOptions live on the sibling
    // ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait. They read and
    // write the progress option registry declared in that trait.

    /**
     * Read the configured per-batch row count.  Honors:
     *  - define('ABJ404_VIEW_BUILD_BATCH_SIZE', N)        for tests/operators
     *  - apply_filters('abj404_view_build_batch_size', N)  for site overrides
     *
     * @return int  Always >= 1.
     */
    private function viewBuildBatchSize(): int {
        $size = ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEFAULT_BATCH_SIZE;
        if (defined('ABJ404_VIEW_BUILD_BATCH_SIZE')) {
            $size = intval(ABJ404_VIEW_BUILD_BATCH_SIZE);
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_view_build_batch_size', $size);
            if (is_scalar($filtered)) {
                $size = intval($filtered);
            }
        }
        return max(1, $size);
    }


    /**
     * Wall-clock budget after which a batched stage (S2 / S4 / S5) yields
     * to the next request rather than starting another batch. This is NOT a
     * query cancellation: any in-flight INSERT or UPDATE-JOIN runs to its
     * own MySQL timeout. It only stops the loop from issuing more batches
     * once the request is close to the real ceiling.
     *
     * Default: derive from PHP's max_execution_time minus a 2s response
     * cushion. WP-CLI / cron with no PHP time limit (max_execution_time = 0)
     * fall back to ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_PER_STAGE_BUDGET_SECONDS
     * since unbounded loops would still be undesirable in those contexts.
     *
     * Explicit overrides (define / filter) win over auto-detection so
     * operators can tune it for their host. The minimum floor of 0.1s is
     * preserved so tests that set a tiny override still complete a batch.
     *
     * @return float  Seconds; always > 0.
     */
    private function viewBuildPerStageBudgetSeconds(): float {
        $explicitOverride = false;
        $budget = (float)ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_PER_STAGE_BUDGET_SECONDS;

        if (defined('ABJ404_VIEW_BUILD_PER_STAGE_BUDGET_SECONDS')) {
            $budget = (float)ABJ404_VIEW_BUILD_PER_STAGE_BUDGET_SECONDS;
            $explicitOverride = true;
        }

        // When set_time_limit() is in disable_functions, the build cannot
        // extend its time mid-request. Widen the cushion (4s vs. 2s) and
        // cap below the default budget so each tick yields earlier and the
        // next cron tick resumes inside its own fresh request budget.
        $setTimeLimitAvailable = $this->probeSetTimeLimitAvailability();

        if (!$explicitOverride) {
            $maxExec = (int)ini_get('max_execution_time');
            if ($maxExec >= 5) {
                $cushion = $setTimeLimitAvailable ? 2 : 4;
                $budget = (float)max(1, $maxExec - $cushion);
            }
        }
        if (!$explicitOverride && !$setTimeLimitAvailable) {
            $tightCap = max(
                1.0,
                (float)ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_PER_STAGE_BUDGET_SECONDS - 4.0
            );
            $budget = min($budget, $tightCap);
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_view_build_per_stage_budget_seconds', $budget);
            if (is_scalar($filtered)) {
                $budget = (float)$filtered;
            }
        }
        return $budget > 0.1 ? $budget : 0.1;
    }

    /**
     * Verify `$wpdb->prefix` has not changed since S1 captured it. When the
     * snapshot and the live prefix disagree, surface a deduplicated admin
     * notice, log the mismatch with both prefixes for post-mortem, and
     * return true so the orchestrator halts before S2-S11 run any DML
     * against a different blog's tables (Codex finding #8: a mu-plugin
     * calling `switch_to_blog()` between cron ticks would otherwise let
     * the build write across prefixes silently).
     *
     * Idempotent: calling on the matching path is cheap (one option read or
     * one in-memory string compare) and never mutates state.
     *
     * @param int $aboutToRunStage  1..11; included in the notice for context.
     * @return bool  True on mismatch -- caller should `return false` from
     *               runStagedBuildOnce immediately. False when prefix matches
     *               (or no capture exists) -- caller proceeds with the stage.
     */
    private function haltIfPrefixChangedSinceStageOne(int $aboutToRunStage): bool {
        if ($this->verifyPrefixUnchangedSinceStageOne()) {
            return false;
        }
        global $wpdb;
        $current = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : '';
        $captured = $this->capturedPrefixForLog();
        $msg = sprintf(
            'Multisite blog context changed during view rebuild; rebuild '
            . 'aborted to prevent cross-blog data corruption. '
            . 'captured_prefix=%s current_prefix=%s aborted_at_stage=%d',
            $captured,
            $current,
            $aboutToRunStage
        );
        $this->setStagedBuildHaltNotice('multisite_prefix_changed', $msg);
        $this->logger->warn('[staged] ' . $msg);
        // Do NOT clear progress / captured prefix here: the original blog's
        // resume on a future request will see its (untouched) progress and
        // prefix capture, verify cleanly, and continue. Clearing here would
        // be writing through the WRONG blog's options table anyway.
        return true;
    }

    /**
     * Run the staged build from wherever we left off, atomically swap into
     * view_done when all stages have completed.
     *
     * Resumable: each stage records progress in WP options so the next
     * request (driven by WP-Cron or by the JS poll re-issuing the page
     * request) can continue where this request left off.  S2/S4/S5 are
     * additionally batched within a single request and yield mid-stage
     * when the per-stage budget is exhausted; the next request resumes
     * from the persisted high-water id.
     *
     * Process-local guard prevents re-entrance within a single request.
     *
     * @return bool  true when the build fully completed and view_done is
     *               now fresh; false when the request yielded mid-stage and
     *               another request is needed to finish.
     */
    private function runStagedBuildOnce(): bool {
        if (self::$viewBuildAlreadyRanThisRequest) {
            // Already either ran to completion or yielded earlier in this
            // request, do not re-enter. Caller should not block on this.
            return $this->viewDoneIsFresh();
        }
        self::$viewBuildAlreadyRanThisRequest = true;
        $this->registerViewBuildShutdownDiagnostics();

        // Probe the PHP runtime once per request: surfaces a low-memory
        // admin notice and gates the per-stage budget into a tighter
        // cron-tick mode when set_time_limit() is in disable_functions.
        $this->probePhpEnvironmentForBuild();
        // Probe filesystem-side host constraints (open_basedir, upload_tmp_dir,
        // tmpdir disk-free) once per request. Read-and-warn-only: surfaces a
        // deduplicated admin notice if anything is out of range; never blocks.
        $this->probeFilesystemEnvironmentForBuild();

        // Build is in the dedup window after a critical-stage permanent
        // host failure. Re-running would just produce the same denied
        // DDL again. Cron ticks during the window are no-ops; an explicit
        // force rebuild clears the gate via clearStagedBuildDegradedState().
        if ($this->isBuildHaltedForHostFailure()) {
            return $this->viewDoneIsFresh();
        }

        // Decide: resume or restart from scratch?
        $startedAt = $this->readProgressOption('started_at', 0);
        $bufferExists = $this->stagedTableExists($this->viewBuildTableName());
        $isResuming = $startedAt > 0
            && (time() - $startedAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_RESUME_TTL_SECONDS
            && $bufferExists;

        // Single line per advance request that pins down WHICH path was
        // taken and why. On a build that takes hours across many requests,
        // this is the entry point for any "stuck at stage N" investigation:
        // a single grep for [staged] in the debug log shows whether each
        // request was resuming, restarting, or skipping due to the per-request
        // guard.
        $currentStage = $this->readProgressOption('current_stage', 0);
        if (!$isResuming) {
            $reason = ($startedAt <= 0)
                ? 'no prior started_at'
                : (!$bufferExists
                    ? 'buffer table missing (prior crash or fresh install)'
                    : ('prior build older than resume TTL ('
                        . (time() - $startedAt) . 's elapsed)'));
            // INFO (not DEBUG) so this signal survives a site that has
            // disabled DEBUG. Without it, a stuck-at-S1 redirects page on
            // such a site leaves no log evidence of whether each request
            // is restarting fresh or resuming.
            $this->logger->infoMessage(sprintf(
                '[staged] runStagedBuildOnce: fresh start (%s); current_stage=%d',
                $reason, $currentStage
            ));
            // Fresh start: scrap any partial state. An abandoned partial
            // build older than the resume TTL is not safe to continue;
            // wp_posts/wp_terms/wp_options state may have drifted.
            $this->clearAllProgressOptions();
            $this->dropTransientStagedTables();
        } else {
            // INFO (not DEBUG): see fresh-start branch above. The pair
            // (fresh start vs. resuming) is the entry point for any
            // stuck-build investigation and must survive DEBUG-off sites.
            $this->logger->infoMessage(sprintf(
                '[staged] runStagedBuildOnce: resuming (started_at=%d, %ds ago); current_stage=%d',
                $startedAt, time() - $startedAt, $currentStage
            ));
            // Resuming: drop only the leftover deleteme from a prior crashed
            // RENAME swap.  Keep the buffer + progress options intact.
            $this->dropDeletemeTable();
        }

        // Set our own per-query timeout for every staged-build query
        // run during this advance call. Sized below the host's session
        // max_statement_time so our hint fires first, producing a
        // classifiable "max_statement_time exceeded" error we can react
        // to (Path B: shrink the batch). Without this, MariaDB's silent
        // server-level kill produces a less-classifiable connection or
        // generic-query error.
        $this->stagedQueryTimeoutSeconds = (int)round($this->intelligentStagedQueryTimeoutSeconds());

        $stage = $this->readProgressOption('current_stage', 0);

        if ($stage < 1) {
            // Capture $wpdb->prefix BEFORE the S1 callback so subsequent
            // stage entries can detect a mid-build switch_to_blog().
            $this->capturePrefixAtBuildStart();
            // Probe sql_mode + max_allowed_packet for THIS connection. The
            // probe persists in `view_build_state` and (best-effort) clears
            // STRICT_TRANS_TABLES / ONLY_FULL_GROUP_BY for the build session
            // so any future query the build adds inherits non-strict
            // semantics. The S2 INSERT is already strict-safe via REGEXP-
            // guarded CAST so this is belt-and-suspenders for new code.
            $this->probeSqlModeForBuild();
            // Probe operational + DDL-safety MySQL session variables once at
            // S1 entry. Read-and-warn-only: surfaces a single consolidated
            // admin notice when any variable is out of range; never blocks.
            $this->probeSessionVariablesAtS1Entry();
            $this->logger->debugMessage(sprintf(
                '[staged] runStagedBuildOnce: capturing prefix at S1 entry: prefix=%s',
                $this->capturedPrefixForLog()
            ));
            $this->markBuildStage('staged_build_s1_create');
            $r = $this->runTimedViewBuildStage(1, 'staged_build_s1_create', function () {
                $this->stageCreateBuildTable();
            });
            if ($r === false || $r === 'halted') {
                return false; // host killed S1 or halt set; next tick gated on halt window
            }
            // Stamp started_at on the very first stage so the resume-TTL
            // clock starts from buffer creation.
            if ($this->readProgressOption('started_at', 0) === 0) {
                $this->writeProgressOption('started_at', time());
            }
            $this->writeProgressOption('current_stage', 1);
            $stage = 1;
        }

        if ($stage < 2) {
            if ($this->haltIfPrefixChangedSinceStageOne(2)) { return false; }
            $r = $this->runTimedViewBuildStage(2, 'staged_build_s2_insert', function () {
                return $this->stageInsertRedirectsBatched();
            });
            if ($r === false || $r === 'halted') {
                return false; // budget exhausted, kill, or halt; resume / no-op next request
            }
            $this->writeProgressOption('current_stage', 2);
            $stage = 2;
        }

        if ($stage < 3) {
            if ($this->haltIfPrefixChangedSinceStageOne(3)) { return false; }
            if ($this->isStageMarkedSkipped(3)) {
                // Permanent host-side denial recorded on a prior tick.
                // Advance current_stage past S3 without touching the SQL.
                $this->writeProgressOption('current_stage', 3);
                $stage = 3;
            } else {
                $this->markBuildStage('staged_build_s3_index_fd');
                // Non-batched: kill-streak escape valve extends the per-query
                // timeout above the host's session limit on retry. Without
                // this, a CREATE INDEX that exceeds max_statement_time on
                // big buffers loops with the same timeout forever.
                $r = $this->runNonBatchedStageWithKillStreakEscape(
                    3, 'staged_build_s3_index_fd', 's3_kill_streak',
                    function () { $this->stageAddPreJoinIndexes(); }
                );
                if ($r === false || $r === 'halted') {
                    return false;
                }
                $this->writeProgressOption('current_stage', 3);
                $stage = 3;
            }
        }

        if ($stage < 4) {
            if ($this->haltIfPrefixChangedSinceStageOne(4)) { return false; }
            $r = $this->runTimedViewBuildStage(4, 'staged_build_s4_update_posts', function () {
                return $this->stageUpdatePostsBatched();
            });
            if ($r === false || $r === 'halted') {
                return false;
            }
            $this->writeProgressOption('current_stage', 4);
            $stage = 4;
        }

        if ($stage < 5) {
            if ($this->haltIfPrefixChangedSinceStageOne(5)) { return false; }
            $r = $this->runTimedViewBuildStage(5, 'staged_build_s5_update_terms', function () {
                return $this->stageUpdateTermsBatched();
            });
            if ($r === false || $r === 'halted') {
                return false;
            }
            $this->writeProgressOption('current_stage', 5);
            $stage = 5;
        }

        if ($stage < 6) {
            if ($this->haltIfPrefixChangedSinceStageOne(6)) { return false; }
            $this->markBuildStage('staged_build_s6_update_home');
            $r = $this->runTimedViewBuildStage(6, 'staged_build_s6_update_home', function () {
                $this->stageUpdateHome();
            });
            if ($r === false || $r === 'halted') {
                return false;
            }
            $this->writeProgressOption('current_stage', 6);
            $stage = 6;
        }

        if ($stage < 7) {
            if ($this->haltIfPrefixChangedSinceStageOne(7)) { return false; }
            $this->markBuildStage('staged_build_s7_update_external');
            $r = $this->runTimedViewBuildStage(7, 'staged_build_s7_update_external', function () {
                $this->stageUpdateExternal();
            });
            if ($r === false || $r === 'halted') {
                return false;
            }
            $this->writeProgressOption('current_stage', 7);
            $stage = 7;
        }

        if ($stage < 8) {
            if ($this->haltIfPrefixChangedSinceStageOne(8)) { return false; }
            $this->markBuildStage('staged_build_s8_update_special');
            $r = $this->runTimedViewBuildStage(8, 'staged_build_s8_update_special', function () {
                $this->stageUpdateSpecial();
            });
            if ($r === false || $r === 'halted') {
                return false;
            }
            $this->writeProgressOption('current_stage', 8);
            $stage = 8;
        }

        if ($stage < 9) {
            if ($this->haltIfPrefixChangedSinceStageOne(9)) { return false; }
            if ($this->isStageMarkedSkipped(9)) {
                $this->writeProgressOption('current_stage', 9);
                $stage = 9;
            } else {
                // Non-batched: temp-table aggregate over wp_abj404_logs_hits +
                // UPDATE JOIN against the buffer. Kill-streak escape valve
                // extends the per-query timeout on retry so a logs_hits scan
                // that doesn't fit in the host's max_statement_time can
                // eventually finish.
                $s9Result = $this->runNonBatchedStageWithKillStreakEscape(
                    9, 'staged_build_s9_update_hits', 's9_kill_streak',
                    function () {
                        if ($this->logsHitsTableExists()) {
                            $this->markBuildStage('staged_build_s9_update_hits');
                            $this->stageUpdateHits();
                            return null;
                        }
                        $this->markBuildStage('staged_build_s9_update_hits', 'skipped; logs hits table unavailable');
                        return 'skipped';
                    }
                );
                if ($s9Result === false || $s9Result === 'halted') {
                    return false;
                }
                // Skipped or not, advance past S9.
                $this->writeProgressOption('current_stage', 9);
                $stage = 9;
            }
        }

        if ($stage < 10) {
            if ($this->haltIfPrefixChangedSinceStageOne(10)) { return false; }
            if ($this->isStageMarkedSkipped(10)) {
                $this->writeProgressOption('current_stage', 10);
                $stage = 10;
            } else {
                $this->markBuildStage('staged_build_s10_index_sort');
                // Non-batched: same kill-streak escape valve as S3. A
                // CREATE INDEX that exceeds the host's max_statement_time on
                // big buffers needs an extended retry timeout to complete.
                $r = $this->runNonBatchedStageWithKillStreakEscape(
                    10, 'staged_build_s10_index_sort', 's10_kill_streak',
                    function () { $this->stageAddSortIndexes(); }
                );
                if ($r === false || $r === 'halted') {
                    return false;
                }
                $this->writeProgressOption('current_stage', 10);
                $stage = 10;
            }
        }

        if ($stage < 11) {
            if ($this->haltIfPrefixChangedSinceStageOne(11)) { return false; }
            $this->markBuildStage('staged_build_s11_swap');
            $r = $this->runTimedViewBuildStage(11, 'staged_build_s11_swap', function () {
                $this->stageRenameSwap();
            });
            if ($r === false || $r === 'halted') {
                return false;
            }
            // Build fully done. markViewDoneBuildCompleted() updates both
            // freshness and data-built-at signals, clears the hard-stale
            // admin notice, and resets the serveability cache.
            $this->markViewDoneBuildCompleted();
            // Wipe progress so the next rebuild starts clean.
            // clearAllProgressOptions() also clears the prefix_at_s1 capture.
            $this->clearAllProgressOptions();
        }

        return true;
    }


    // The following helpers all live on sibling traits so this file stays
    // focused on the orchestrator. They're listed here as a navigation aid:
    //
    // ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait:
    //   - runStagedSqlFile / runStagedSqlFileTolerantOfDuplicateKey
    //   - describeStagedSqlFailure / describeBuildProgressForNotice
    //   - stagedQueryOptions
    //   - viewDoneTableExists / stagedTableExists
    //   - viewDoneIsFresh
    //   - acquireViewBuildLock / releaseViewBuildLock
    //   - scheduleViewDoneRebuild
    //
    // ABJ_404_Solution_DataAccess_ViewBuildStageCallbacksTrait:
    //   - dropTransientStagedTables / dropDeletemeTable
    //   - stageCreateBuildTable (S1)
    //   - stageInsertRedirectsBatched (S2) / runInsertBatch
    //   - stageAddPreJoinIndexes (S3)
    //   - stageUpdatePostsBatched (S4) / stageUpdateTermsBatched (S5) / runIdRangeBatchedUpdate
    //   - stageUpdateHome (S6) / stageUpdateExternal (S7) / stageUpdateSpecial (S8)
    //   - stageUpdateHits (S9)
    //   - stageAddSortIndexes (S10)
    //   - stageRenameSwap (S11)
    //   - countLiveRedirects / countViewBuildRows / maxBuildBufferId / humanBatchProgress
}
