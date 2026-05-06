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

    /** @var int Per-stage timeout in seconds for staged queries; 0 means use queryAndGetResults default. */
    private $stagedQueryTimeoutSeconds = 0;

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

    /**
     * Persisted progress tracker between requests.  When a stage exits before
     * completing all its batches (PHP timeout, per-stage budget reached), the
     * next request resumes from the stored high-water id.
     *
     * Names are kept short to avoid WP's 191-char option_name index limit
     * even with long table-prefix sites.
     *
     * @var array<string, string>
     */
    private static $viewBuildProgressOptionNames = array(
        'started_at'    => 'abj404_view_build_started_at',
        'current_stage' => 'abj404_view_build_current_stage',
        's2_high_water' => 'abj404_view_build_s2_high_water',
        's4_high_water' => 'abj404_view_build_s4_high_water',
        's5_high_water' => 'abj404_view_build_s5_high_water',
    );

    /** @return void */
    public static function resetViewBuildOncePerRequestGuard(): void {
        self::$viewBuildAlreadyRanThisRequest = false;
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
        $haveDone = $this->viewDoneTableExists();
        $builtAt = $this->viewDoneBuiltAt();
        $isFresh = $haveDone && $builtAt > 0 && (time() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS;
        $isInvalidated = $haveDone && $builtAt === 0;

        if ($isFresh) {
            return $this->readFromViewDone($sub, $tableOptions);
        }

        if ($haveDone && !$isInvalidated) {
            // Stale but not invalidated: serve stale (TTL expired but data
            // is presumed correct), kick off background rebuild for next
            // request. This is the steady-state warm path.
            $this->scheduleViewDoneRebuild();
            return $this->readFromViewDone($sub, $tableOptions);
        }

        // view_done is missing or invalidated. Schedule a background rebuild
        // (cron + ajaxAdvanceViewBuild advance the build) and signal pending
        // back up. Inline build inside a fetch request is intentionally
        // removed: on slow hosts it fatals at max_execution_time and the
        // HTTP 500 / "critical error" payload defeats client-side recovery.
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
     * from cache vs. return pending".  True when view_done exists and has
     * been at least once successfully built (not invalidated). Stale-but-
     * present is serveable: the steady-state warm path serves stale and
     * schedules a background rebuild without blocking.
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
        // Invalidated (built_at == 0) is NOT serveable: the freshness option
        // was just cleared by a redirect create/update/delete, so any read
        // would return wildly out-of-date data.  The build must run before
        // the next fetch.
        $this->viewDoneIsServeableCache = ($this->viewDoneBuiltAt() > 0);
        return $this->viewDoneIsServeableCache;
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
     * @return array<string, mixed>
     */
    public function advanceViewBuildOnce(): array {
        if ($this->viewDoneIsServeable()) {
            return $this->getViewBuildProgress();
        }
        if (!$this->acquireViewBuildLock()) {
            $progress = $this->getViewBuildProgress();
            $progress['locked'] = true;
            return $progress;
        }
        try {
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
        $haveDone = $this->viewDoneTableExists();
        $builtAt = $this->viewDoneBuiltAt();
        $isFresh = $haveDone && $builtAt > 0 && (time() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS;
        $isInvalidated = $haveDone && $builtAt === 0;

        if (!$haveDone || $isInvalidated) {
            // No serveable view_done. Schedule a background rebuild and
            // signal pending; never run the staged build inline inside a
            // request. The fetch AJAX gate prevents this from being reached
            // under normal traffic; non-AJAX callers retry on next request.
            $this->scheduleViewDoneRebuild();
            $progress = $this->describeBuildProgressForNotice();
            throw new ABJ_404_Solution_ViewBuildPendingException(
                'Staged view-count build pending; background rebuild scheduled. Progress: ' . $progress,
                $progress
            );
        }

        if (!$isFresh) {
            // Stale but not invalidated: serve the stale count and kick off
            // a background rebuild for the next request.
            $this->scheduleViewDoneRebuild();
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
            $this->scheduleViewDoneRebuild(ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_FOREGROUND_LEASE_SECONDS);
            return;
        }
        if (!$this->acquireViewBuildLock()) { return; }
        try {
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
     * Mark the served view_done table stale so the next request triggers a
     * rebuild. Hooked from invalidateViewSnapshotCache() so redirect
     * create/update/delete invalidates the precomputed view too.
     *
     * Also clears any in-flight resumable-build progress: a redirect change
     * during a partial build would leave the partial buffer with stale data
     * for ids the change touched, so the safest move is to restart the build
     * on the next request rather than stitch onto a half-built buffer.
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
        $this->invalidateViewDoneServeableCache();
    }

    /**
     * Update the inflight stage transient + AJAX-context global so the
     * client-side progress poller can render which sub-stage of the staged
     * build is currently running.
     *
     * Best-effort: when there's no AJAX context (background cron, CLI), this
     * is a no-op.  Never let a transient-write failure mask the real query
     * error we're about to raise.
     *
     * @param string $stageKey  Sub-stage key, e.g. 'staged_build_s2_insert'.
     * @param string $detail    Optional mid-stage progress detail, e.g. 'batch 4/12'.
     * @return void
     */
    private function markBuildStage(string $stageKey, string $detail = ''): void {
        if (!class_exists('ABJ_404_Solution_ViewUpdater')) {
            return;
        }
        $label = $detail !== '' ? ($stageKey . ':' . $detail) : $stageKey;
        // The class is autoloaded by Loader.php; markInflightStage is a
        // best-effort no-op when no AJAX context exists.
        \ABJ_404_Solution_ViewUpdater::markInflightStage($label);
    }

    /**
     * Run one staged view-build step and write a clear per-stage timing line.
     *
     * The build can span several HTTP requests. For resumable stages that yield
     * mid-stage (S2/S4/S5), this records the time spent in the current tick and
     * marks the status as yielded; the final tick for that stage is logged as
     * completed.
     *
     * @param int $stageNumber  1-based staged build number.
     * @param string $stageKey  Stable stage key used by AJAX progress.
     * @param callable $callback Stage work to execute.
     * @return mixed
     */
    private function runTimedViewBuildStage(int $stageNumber, string $stageKey, callable $callback) {
        $started = microtime(true);
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->logTimedViewBuildStage($stageNumber, $stageKey, 'error', $started);
            throw $e;
        }

        $status = 'completed';
        if ($result === false) {
            $status = 'yielded';
        } else if ($result === 'skipped') {
            $status = 'skipped';
        }
        $this->logTimedViewBuildStage($stageNumber, $stageKey, $status, $started);
        return $result;
    }

    /**
     * @param int $stageNumber
     * @param string $stageKey
     * @param string $status
     * @param float $started
     * @return void
     */
    private function logTimedViewBuildStage(int $stageNumber, string $stageKey, string $status, float $started): void {
        $elapsedMs = (int)round((microtime(true) - $started) * 1000);
        $this->markBuildStage($stageKey, $status . ' in ' . $elapsedMs . ' ms');
        $this->logger->debugMessage(sprintf(
            '[staged] build stage %d/11 %s %s in %d ms',
            $stageNumber,
            $stageKey,
            $status,
            $elapsedMs
        ));
    }

    /**
     * @param string $shortName  One of self::$viewBuildProgressOptionNames keys.
     * @return string  Site-prefixed option name.
     */
    private function progressOptionName(string $shortName): string {
        if (!isset(self::$viewBuildProgressOptionNames[$shortName])) {
            return '';
        }
        return $this->getLowercasePrefix() . self::$viewBuildProgressOptionNames[$shortName];
    }

    /**
     * @param string $shortName  Progress key.
     * @param int    $default
     * @return int
     */
    private function readProgressOption(string $shortName, int $default = 0): int {
        if (!function_exists('get_option')) {
            return $default;
        }
        $name = $this->progressOptionName($shortName);
        if ($name === '') {
            return $default;
        }
        $value = get_option($name, $default);
        return is_scalar($value) ? max(0, intval($value)) : $default;
    }

    /**
     * @param string $shortName  Progress key.
     * @param int    $value
     * @return void
     */
    private function writeProgressOption(string $shortName, int $value): void {
        if (!function_exists('update_option')) {
            return;
        }
        $name = $this->progressOptionName($shortName);
        if ($name === '') {
            return;
        }
        // autoload=false so progress writes (potentially many per request)
        // don't bloat the alloptions cache that loads on every WP page.
        update_option($name, max(0, intval($value)), false);
    }

    /** @return void */
    private function clearAllProgressOptions(): void {
        if (!function_exists('delete_option')) {
            return;
        }
        foreach (self::$viewBuildProgressOptionNames as $optName) {
            delete_option($this->getLowercasePrefix() . $optName);
        }
    }

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
     * Read the per-stage wall-clock budget after which the request yields
     * mid-stage (resumed on the next request).
     *
     * @return float  Seconds; always > 0.
     */
    private function viewBuildPerStageBudgetSeconds(): float {
        $budget = (float)ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_PER_STAGE_BUDGET_SECONDS;
        if (defined('ABJ404_VIEW_BUILD_PER_STAGE_BUDGET_SECONDS')) {
            $budget = (float)ABJ404_VIEW_BUILD_PER_STAGE_BUDGET_SECONDS;
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
            // request — don't re-enter.  Caller should not block on this.
            return $this->viewDoneIsFresh();
        }
        self::$viewBuildAlreadyRanThisRequest = true;

        // Decide: resume or restart from scratch?
        $startedAt = $this->readProgressOption('started_at', 0);
        $bufferExists = $this->stagedTableExists($this->viewBuildTableName());
        $isResuming = $startedAt > 0
            && (time() - $startedAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_RESUME_TTL_SECONDS
            && $bufferExists;

        if (!$isResuming) {
            // Fresh start: scrap any partial state.  An abandoned partial
            // build older than the resume TTL is not safe to continue —
            // wp_posts/wp_terms/wp_options state may have drifted.
            $this->clearAllProgressOptions();
            $this->dropTransientStagedTables();
        } else {
            // Resuming: drop only the leftover deleteme from a prior crashed
            // RENAME swap.  Keep the buffer + progress options intact.
            $this->dropDeletemeTable();
        }

        $stage = $this->readProgressOption('current_stage', 0);

        if ($stage < 1) {
            $this->markBuildStage('staged_build_s1_create');
            $this->runTimedViewBuildStage(1, 'staged_build_s1_create', function () {
                $this->stageCreateBuildTable();
            });
            // Stamp started_at on the very first stage so the resume-TTL
            // clock starts from buffer creation.
            if ($this->readProgressOption('started_at', 0) === 0) {
                $this->writeProgressOption('started_at', time());
            }
            $this->writeProgressOption('current_stage', 1);
            $stage = 1;
        }

        if ($stage < 2) {
            if (!$this->runTimedViewBuildStage(2, 'staged_build_s2_insert', function () {
                return $this->stageInsertRedirectsBatched();
            })) {
                return false; // budget exhausted; resume on next request
            }
            $this->writeProgressOption('current_stage', 2);
            $stage = 2;
        }

        if ($stage < 3) {
            $this->markBuildStage('staged_build_s3_index_fd');
            $this->runTimedViewBuildStage(3, 'staged_build_s3_index_fd', function () {
                $this->stageAddPreJoinIndexes();
            });
            $this->writeProgressOption('current_stage', 3);
            $stage = 3;
        }

        if ($stage < 4) {
            if (!$this->runTimedViewBuildStage(4, 'staged_build_s4_update_posts', function () {
                return $this->stageUpdatePostsBatched();
            })) {
                return false;
            }
            $this->writeProgressOption('current_stage', 4);
            $stage = 4;
        }

        if ($stage < 5) {
            if (!$this->runTimedViewBuildStage(5, 'staged_build_s5_update_terms', function () {
                return $this->stageUpdateTermsBatched();
            })) {
                return false;
            }
            $this->writeProgressOption('current_stage', 5);
            $stage = 5;
        }

        if ($stage < 6) {
            $this->markBuildStage('staged_build_s6_update_home');
            $this->runTimedViewBuildStage(6, 'staged_build_s6_update_home', function () {
                $this->stageUpdateHome();
            });
            $this->writeProgressOption('current_stage', 6);
            $stage = 6;
        }

        if ($stage < 7) {
            $this->markBuildStage('staged_build_s7_update_external');
            $this->runTimedViewBuildStage(7, 'staged_build_s7_update_external', function () {
                $this->stageUpdateExternal();
            });
            $this->writeProgressOption('current_stage', 7);
            $stage = 7;
        }

        if ($stage < 8) {
            $this->markBuildStage('staged_build_s8_update_special');
            $this->runTimedViewBuildStage(8, 'staged_build_s8_update_special', function () {
                $this->stageUpdateSpecial();
            });
            $this->writeProgressOption('current_stage', 8);
            $stage = 8;
        }

        if ($stage < 9) {
            $this->runTimedViewBuildStage(9, 'staged_build_s9_update_hits', function () {
                if ($this->logsHitsTableExists()) {
                    $this->markBuildStage('staged_build_s9_update_hits');
                    $this->stageUpdateHits();
                    return null;
                }
                $this->markBuildStage('staged_build_s9_update_hits', 'skipped; logs hits table unavailable');
                return 'skipped';
            });
            // Skipped or not, we've moved past S9.
            $this->writeProgressOption('current_stage', 9);
            $stage = 9;
        }

        if ($stage < 10) {
            $this->markBuildStage('staged_build_s10_index_sort');
            $this->runTimedViewBuildStage(10, 'staged_build_s10_index_sort', function () {
                $this->stageAddSortIndexes();
            });
            $this->writeProgressOption('current_stage', 10);
            $stage = 10;
        }

        if ($stage < 11) {
            $this->markBuildStage('staged_build_s11_swap');
            $this->runTimedViewBuildStage(11, 'staged_build_s11_swap', function () {
                $this->stageRenameSwap();
            });
            if (function_exists('update_option')) {
                update_option($this->viewDoneFreshnessOptionName(), time(), false);
            }
            // Build fully done. Wipe progress so the next rebuild starts clean.
            $this->clearAllProgressOptions();
            $this->invalidateViewDoneServeableCache();
        }

        return true;
    }

    /** Drop both the build buffer and the leftover deleteme.  Used on fresh-start only. */
    private function dropTransientStagedTables(): void {
        $buildTempTable = $this->viewBuildTableName();
        $deletemeTempTable = $this->viewDeletemeTableName();
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $buildTempTable . '`',
            array('log_errors' => false));
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));
    }

    /** Drop only the deleteme leftover from a prior crashed RENAME swap. */
    private function dropDeletemeTable(): void {
        $deletemeTempTable = $this->viewDeletemeTableName();
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));
    }

    /**
     * S1: create the build buffer. Tries the system default storage
     * engine, then falls back to MyISAM, then to InnoDB so it works on
     * hosts that disable one or the other.
     *
     * @return void
     */
    private function stageCreateBuildTable(): void {
        $template = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/sql/createViewBuildTable.sql');
        $base = $this->doTableNameReplacements(is_string($template) ? $template : '');
        if (trim($base) === '') {
            throw new \Exception('createViewBuildTable.sql is empty or unreadable.');
        }

        $attempts = array(
            $base,
            $base . ' ENGINE=MyISAM',
            $base . ' ENGINE=InnoDB',
        );
        $lastError = '';
        $opts = $this->stagedQueryOptions();
        $opts['log_errors'] = false;
        foreach ($attempts as $sql) {
            $result = $this->queryAndGetResults($sql, $opts);
            $err = isset($result['last_error']) && is_string($result['last_error'])
                ? trim($result['last_error']) : '';
            if ($err === '' && empty($result['timed_out'])) {
                return;
            }
            $lastError = $err !== '' ? $err : 'unknown';
        }
        throw new \Exception('Could not create view build table on any storage engine: ' . $lastError);
    }

    /**
     * S2: bulk-load redirects into the build buffer, in resumable batches.
     *
     * Source of truth for the high-water is `MAX(id)` of the build buffer
     * itself — option `s2_high_water` is written for diagnostics / visibility
     * but is never consulted.  Using buffer MAX(id) directly makes resumption
     * crash-safe: if PHP dies between an INSERT and the option write, the
     * next request still picks up from exactly where the INSERT left off.
     *
     * Each batch INSERTs the next BATCH_SIZE rows from wp_abj404_redirects
     * with `id > <buffer MAX(id)>`.  Per-stage budget caps wall-clock time
     * so the request can finish even when the dataset is too big to copy in
     * one shot.
     *
     * @return bool  true when the entire redirects table has been copied;
     *               false when the per-stage budget was exhausted mid-stage.
     */
    private function stageInsertRedirectsBatched(): bool {
        $this->markBuildStage('staged_build_s2_insert');
        $batchSize = $this->viewBuildBatchSize();
        $deadline = microtime(true) + $this->viewBuildPerStageBudgetSeconds();

        $totalCount = $this->countLiveRedirects();
        if ($totalCount <= 0) {
            // Empty redirects table — nothing to copy.
            $this->writeProgressOption('s2_high_water', 0);
            return true;
        }

        while (true) {
            $copiedSoFar = $this->countViewBuildRows();
            if ($copiedSoFar >= $totalCount) {
                break; // covered the table
            }
            if (microtime(true) >= $deadline) {
                $this->markBuildStage('staged_build_s2_insert',
                    'batch ' . $this->humanBatchProgress($copiedSoFar, $totalCount) . ' (yielded)');
                return false;
            }

            $loBound = $this->maxBuildBufferId();
            $beforeMax = $loBound;
            $afterMax = $this->runInsertBatch($loBound, $batchSize);
            if ($afterMax === $beforeMax) {
                // No rows above $loBound to copy.  Either the redirects table
                // shrank during the build, or all remaining ids are <= loBound
                // (impossible given strict id-range semantics, but defensive).
                // Treat as done; the read query will reflect whatever was
                // captured.
                break;
            }
            // Mirror MAX(id) into the option for diagnostics.  This is
            // best-effort; correctness does NOT depend on this write.
            $this->writeProgressOption('s2_high_water', $afterMax);

            $this->markBuildStage('staged_build_s2_insert',
                'batch ' . $this->humanBatchProgress($this->countViewBuildRows(), $totalCount));
        }

        $this->writeProgressOption('s2_high_water', 0);
        return true;
    }

    /** @return void */
    private function stageAddPreJoinIndexes(): void {
        // S3 indexes are added with IF NOT EXISTS semantics emulated by
        // catching "Duplicate key name" on retry — see runStagedSqlFile
        // tolerance below.  ALTER TABLE itself is fast on the buffer.
        $this->runStagedSqlFileTolerantOfDuplicateKey('03_index_fd.sql', array());
    }

    /**
     * S4: resolve POST-typed redirects against wp_posts in resumable batches
     * keyed by view_build.id range.
     *
     * @return bool  true when stage completed; false when budget exhausted.
     */
    private function stageUpdatePostsBatched(): bool {
        return $this->runIdRangeBatchedUpdate(
            'staged_build_s4_update_posts',
            's4_high_water',
            '04_update_posts.sql'
        );
    }

    /**
     * S5: resolve CAT/TAG-typed redirects against wp_terms in resumable
     * batches keyed by view_build.id range.
     *
     * @return bool  true when stage completed; false when budget exhausted.
     */
    private function stageUpdateTermsBatched(): bool {
        return $this->runIdRangeBatchedUpdate(
            'staged_build_s5_update_terms',
            's5_high_water',
            '05_update_terms.sql'
        );
    }

    /** @return void */
    private function stageUpdateHome(): void {
        $this->runStagedSqlFile('06_update_home.sql', array());
    }

    /** @return void */
    private function stageUpdateExternal(): void {
        $this->runStagedSqlFile('07_update_external.sql', array());
    }

    /** @return void */
    private function stageUpdateSpecial(): void {
        $this->runStagedSqlFile('08_update_special.sql', $this->viewBuildOnlyTranslations());
    }

    /** @return void */
    private function stageUpdateHits(): void {
        $this->runStagedSqlFile('09a_drop_hits_temp.sql', array());
        $this->runStagedSqlFile('09b_create_hits_temp.sql', array());
        $this->runStagedSqlFile('09c_insert_hits_temp.sql', array());
        $this->runStagedSqlFile('09_update_hits.sql', array());
        $this->runStagedSqlFile('09a_drop_hits_temp.sql', array());
    }

    /** @return void */
    private function stageAddSortIndexes(): void {
        $this->runStagedSqlFileTolerantOfDuplicateKey('10_index_sort.sql', array());
    }

    /**
     * Run one INSERT batch for S2.  Inserts the next BATCH_SIZE rows from
     * wp_abj404_redirects with `id > $loBound` (ORDER BY id ASC LIMIT
     * BATCH_SIZE) into the build buffer.  Returns the new MAX(id) of the
     * buffer so the caller can detect "no more rows" (buffer max didn't
     * change after the insert).
     *
     * @param int $loBound    MAX(id) of the buffer at batch start.
     * @param int $batchSize
     * @return int  New MAX(id) of the buffer after this batch (== $loBound
     *              when no rows were inserted).
     */
    private function runInsertBatch(int $loBound, int $batchSize): int {
        $loBound = max(0, intval($loBound));
        $batchSize = max(1, intval($batchSize));

        $extra = $this->viewBuildOnlyTranslations();
        $extra['{LO_BOUND}']   = (string)$loBound;
        $extra['{BATCH_SIZE}'] = (string)$batchSize;
        $this->runStagedSqlFile('02_insert.sql', $extra);

        return $this->maxBuildBufferId();
    }

    /**
     * Run an UPDATE-JOIN stage in id-range batches against the build buffer.
     *
     * The SQL fragment must use `WHERE t.id > {LO_BOUND} AND t.id <= {HI_BOUND}`
     * (the staged 04/05 SQL files do this once converted) so we can stride
     * forward by id without a per-batch COUNT.
     *
     * @param string $stageKey         Sub-stage label, e.g. 'staged_build_s4_update_posts'.
     * @param string $highWaterKey     Progress option key, e.g. 's4_high_water'.
     * @param string $sqlFile          Filename under sql/getRedirectsForViewStaged/.
     * @return bool  true when stage completed; false when budget exhausted.
     */
    private function runIdRangeBatchedUpdate(string $stageKey, string $highWaterKey, string $sqlFile): bool {
        $this->markBuildStage($stageKey);
        $batchSize = $this->viewBuildBatchSize();
        $deadline = microtime(true) + $this->viewBuildPerStageBudgetSeconds();

        $highWater = $this->readProgressOption($highWaterKey, 0);
        $totalMaxId = $this->maxBuildBufferId();
        if ($totalMaxId <= 0) {
            // Buffer is empty (no redirects).  Nothing to update.
            $this->writeProgressOption($highWaterKey, 0);
            return true;
        }

        while ($highWater < $totalMaxId) {
            if (microtime(true) >= $deadline) {
                $this->markBuildStage($stageKey,
                    'batch ' . $this->humanBatchProgress($highWater, $totalMaxId) . ' (yielded)');
                return false;
            }

            $hiBound = min($totalMaxId, $highWater + $batchSize);
            $extra = array(
                '{LO_BOUND}' => (string)$highWater,
                '{HI_BOUND}' => (string)$hiBound,
            );
            $this->runStagedSqlFile($sqlFile, $extra);
            $highWater = $hiBound;
            $this->writeProgressOption($highWaterKey, $highWater);

            $this->markBuildStage($stageKey,
                'batch ' . $this->humanBatchProgress($highWater, $totalMaxId));
        }

        // Stage done; reset high-water for the next rebuild.
        $this->writeProgressOption($highWaterKey, 0);
        return true;
    }

    /** @return int  Total active+inactive rows in wp_abj404_redirects. */
    private function countLiveRedirects(): int {
        $sql = 'SELECT COUNT(*) AS cnt FROM '
            . $this->doTableNameReplacements('{wp_abj404_redirects}');
        $result = $this->queryAndGetResults($sql, $this->stagedQueryOptions());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $cnt = $rows[0]['cnt'] ?? 0;
        return is_scalar($cnt) ? max(0, intval($cnt)) : 0;
    }

    /** @return int  Rows currently in the build buffer. */
    private function countViewBuildRows(): int {
        $sql = 'SELECT COUNT(*) AS cnt FROM '
            . $this->doTableNameReplacements('{wp_abj404_view_build}');
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $cnt = $rows[0]['cnt'] ?? 0;
        return is_scalar($cnt) ? max(0, intval($cnt)) : 0;
    }

    /** @return int  Max(id) in the build buffer, 0 when empty. */
    private function maxBuildBufferId(): int {
        $sql = 'SELECT MAX(id) AS max_id FROM '
            . $this->doTableNameReplacements('{wp_abj404_view_build}');
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $rawMax = $rows[0]['max_id'] ?? null;
        if ($rawMax === null || $rawMax === '') {
            return 0;
        }
        return max(0, intval($rawMax));
    }

    /**
     * @param int $done
     * @param int $total
     * @return string  e.g. "12/45" or "complete" when done==total.
     */
    private function humanBatchProgress(int $done, int $total): string {
        if ($total <= 0) {
            return 'complete';
        }
        return min($done, $total) . '/' . $total;
    }

    /**
     * S11: atomic RENAME TABLE swap. Build buffer becomes the new served
     * table; the previous served table (if any) becomes deleteme and is
     * dropped.
     *
     * @return void
     */
    private function stageRenameSwap(): void {
        $buildTempTable = $this->viewBuildTableName();
        $done = $this->viewDoneTableName();
        $deletemeTempTable = $this->viewDeletemeTableName();

        // Defensive: ensure deleteme is gone before the swap (S0 already did
        // this, but a poorly-timed parallel rebuild could have created it).
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));

        if ($this->viewDoneTableExists()) {
            $sql = 'RENAME TABLE `' . $done . '` TO `' . $deletemeTempTable . '`,'
                 . ' `' . $buildTempTable . '` TO `' . $done . '`';
        } else {
            $sql = 'RENAME TABLE `' . $buildTempTable . '` TO `' . $done . '`';
        }

        $result = $this->queryAndGetResults($sql, array('log_errors' => true));
        $err = isset($result['last_error']) && is_string($result['last_error'])
            ? trim($result['last_error']) : '';
        if ($err !== '') {
            throw new \Exception('RENAME TABLE swap failed: ' . $err);
        }

        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));
    }

    /**
     * Execute a staged SQL file with placeholder substitution and the
     * standard error-handling pipeline.
     *
     * On failure, the error message is prefixed with the file name and any
     * batch bounds present in $extraTranslations so the GUI's "stage N
     * failed" notice carries actionable context.  The current sub-stage
     * label set by markBuildStage() remains in place so the AJAX shutdown
     * handler renders the correct stageNumber/queryLabel.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    private function runStagedSqlFile(string $relativePath, array $extraTranslations): void {
        $path = __DIR__ . '/sql/getRedirectsForViewStaged/' . $relativePath;
        $template = ABJ_404_Solution_Functions::readFileContents($path);
        if (!is_string($template) || trim($template) === '') {
            throw new \Exception("Staged SQL template missing or empty: $relativePath");
        }
        $sql = $this->doTableNameReplacements($template);
        // extraTranslations (status_for_view / type_for_view labels, batch
        // bounds) must run BEFORE doNormalReplacements: doNormalReplacements
        // falls back to __() for any {key} it does not know, which strips
        // the braces and prevents the str_replace below from matching.
        if (!empty($extraTranslations)) {
            $sql = $this->f->str_replace(array_keys($extraTranslations), array_values($extraTranslations), $sql);
        }
        $sql = $this->f->doNormalReplacements($sql);
        $result = $this->queryAndGetResults($sql, $this->stagedQueryOptions());
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '') {
            $context = $this->describeStagedSqlFailure($relativePath, $extraTranslations);
            throw new \Exception('Staged SQL ' . $context . ' failed: ' . $err);
        }
    }

    /**
     * Same as runStagedSqlFile but silently tolerates "Duplicate key name"
     * errors so an interrupted ALTER TABLE ADD INDEX can be safely re-run
     * on a request that resumes a prior partially-completed build.  All
     * other errors are raised as usual.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    private function runStagedSqlFileTolerantOfDuplicateKey(string $relativePath, array $extraTranslations): void {
        try {
            $this->runStagedSqlFile($relativePath, $extraTranslations);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate key name') !== false
                || stripos($msg, 'errno: 1061') !== false) {
                // The index already exists from a prior partial run — that is
                // the expected resume-time state, not a failure.
                return;
            }
            throw $e;
        }
    }

    /**
     * Render a short human-readable description of which file + which batch
     * bounds were running when an error fired.  Used to enrich error
     * messages so the GUI notice lists the exact failing slice.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return string
     */
    private function describeStagedSqlFailure(string $relativePath, array $extraTranslations): string {
        $parts = array($relativePath);
        if (isset($extraTranslations['{LO_BOUND}'])) {
            $parts[] = 'lo=' . $extraTranslations['{LO_BOUND}'];
        }
        if (isset($extraTranslations['{HI_BOUND}'])) {
            $parts[] = 'hi=' . $extraTranslations['{HI_BOUND}'];
        }
        if (isset($extraTranslations['{BATCH_SIZE}'])) {
            $parts[] = 'limit=' . $extraTranslations['{BATCH_SIZE}'];
        }
        return implode(' ', $parts);
    }

    /**
     * Render a short human-readable summary of how far a resumable build has
     * progressed.  Used in the admin notice and the throw message when a
     * request can't yet serve view_done because the build is still running
     * across requests.
     *
     * @return string  e.g. "stage 2/11, 3000/12000 rows" or "not yet started".
     */
    private function describeBuildProgressForNotice(): string {
        $stage = $this->readProgressOption('current_stage', 0);
        if ($stage <= 0) {
            return 'not yet started';
        }
        $parts = array('stage ' . $stage . '/11');
        if ($stage < 2) {
            // S2 is the heaviest; surface buffer/redirect counts.
            $copied = $this->countViewBuildRows();
            $total = $this->countLiveRedirects();
            if ($total > 0) {
                $parts[] = $copied . '/' . $total . ' rows';
            }
        }
        return implode(', ', $parts);
    }

    /**
     * @return array<string, mixed> Options for queryAndGetResults that
     * inherit the warmup pipeline's per-stage timeout when set.
     */
    private function stagedQueryOptions(): array {
        if ($this->stagedQueryTimeoutSeconds > 0) {
            return array('timeout' => $this->stagedQueryTimeoutSeconds);
        }
        return array();
    }

    /** @return bool */
    private function viewDoneTableExists(): bool {
        return $this->stagedTableExists($this->viewDoneTableName());
    }

    /** @param string $tableName @return bool */
    private function stagedTableExists(string $tableName): bool {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return false;
        }
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $tableName);
        if (!is_string($sql) || $sql === '') {
            return false;
        }
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return false;
        }
        $first = $rows[0];
        $first = is_array($first) ? $first : array();
        $value = reset($first);
        $valueStr = is_scalar($value) ? (string)$value : '';
        return ($valueStr === $tableName);
    }

    /** @return bool */
    private function viewDoneIsFresh(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $built = get_option($this->viewDoneFreshnessOptionName(), 0);
        $builtAt = is_scalar($built) ? intval($built) : 0;
        if ($builtAt <= 0) {
            return false;
        }
        return (time() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS;
    }

    /** @return bool */
    private function acquireViewBuildLock(): bool {
        $name = $this->getLowercasePrefix() . ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_BUILD_LOCK_NAME;
        $sql = "SELECT GET_LOCK('" . esc_sql($name) . "', 0) AS got";
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return false;
        }
        $got = $rows[0]['got'] ?? 0;
        return is_scalar($got) && intval($got) === 1;
    }

    /** @return void */
    private function releaseViewBuildLock(): void {
        // @utf8-audit: opt-out - $name is built from $wpdb->prefix + a class
        // constant; never user input, cannot contain invalid UTF-8 bytes.
        $name = $this->getLowercasePrefix() . ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_BUILD_LOCK_NAME;
        $this->queryAndGetResults("SELECT RELEASE_LOCK('" . esc_sql($name) . "')",
            array('log_errors' => false));
    }

    /** @return void */
    private function scheduleViewDoneRebuild(int $delaySeconds = 1): void {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            $hook = 'abj404_rebuildViewDone';
            $next = wp_next_scheduled($hook);
            if ($next === false) {
                wp_schedule_single_event(time() + max(1, intval($delaySeconds)), $hook);
            }
        }
    }
}
