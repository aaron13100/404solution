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

    const VIEW_DONE_FRESHNESS_TTL_SECONDS = 120;
    const VIEW_DONE_BUILD_LOCK_NAME = 'abj404_view_build';
    const VIEW_DONE_FIRST_BUILD_POLL_INTERVAL_MS = 250;
    const VIEW_DONE_FIRST_BUILD_POLL_BUDGET_MS = 25000;

    // Default batch size for the resumable bulk INSERT (S2) and per-id-range
    // UPDATEs (S4/S5).  Tuned to fit comfortably within a single per-query
    // timeout on a slow shared host (5–10s typical).  Override via define()
    // or the abj404_view_build_batch_size filter.
    const VIEW_BUILD_DEFAULT_BATCH_SIZE = 2000;

    // Max wall-clock time a single request will spend executing batches in
    // any one stage before yielding so the request can finish.  Resumable
    // builds pick up the remaining batches on the next request (driven by
    // WP-Cron or by JS poll-triggered re-requests).
    const VIEW_BUILD_PER_STAGE_BUDGET_SECONDS = 10;

    // After this many seconds with no progress, an abandoned partial build
    // is considered stale: the buffer table and high-water options are
    // dropped on the next entry and the build restarts from scratch.
    const VIEW_BUILD_RESUME_TTL_SECONDS = 600;

    /** @var bool Process-local guard so a single request never rebuilds twice. */
    private static $viewBuildAlreadyRanThisRequest = false;

    /** @var int Per-stage timeout in seconds for staged queries; 0 means use queryAndGetResults default. */
    private $stagedQueryTimeoutSeconds = 0;

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

    /**
     * Public entry: returns the page of rows the admin Redirects/Captured
     * tab should render. Always reads from the served view_done table.
     * The build is triggered (inline or background, depending on freshness
     * and presence of view_done) before the read.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array {
        // Honor _abj404_query_timeout from the warmup pipeline so staged
        // queries inherit the same per-stage budget legacy code did. Reset
        // on entry so a previous request's value cannot leak across calls.
        $this->stagedQueryTimeoutSeconds = isset($tableOptions['_abj404_query_timeout'])
            && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(0, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $haveDone = $this->viewDoneTableExists();
        $builtAt = $this->viewDoneBuiltAt();
        $isFresh = $haveDone && $builtAt > 0 && (time() - $builtAt) < self::VIEW_DONE_FRESHNESS_TTL_SECONDS;
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

        // Either view_done is missing entirely, or invalidateViewDone()
        // cleared the freshness option (redirect was just created/edited/
        // deleted). Either way, force an inline rebuild before serving.
        // Stale data after an invalidation is wildly wrong, not just 60s
        // out of date.
        if ($this->acquireViewBuildLock()) {
            $isComplete = false;
            try {
                $isComplete = $this->runStagedBuildOnce();
            } finally {
                $this->releaseViewBuildLock();
            }
            if ($isComplete && $this->viewDoneTableExists()) {
                return $this->readFromViewDone($sub, $tableOptions);
            }
            // Build yielded mid-stage (resumable).  Schedule a background
            // tick to continue, then either serve the prior view_done if it
            // exists, or fall through to the poll-and-notify path.
            $this->scheduleViewDoneRebuild();
            if ($haveDone && $this->viewDoneTableExists()) {
                return $this->readFromViewDone($sub, $tableOptions);
            }
        }

        if ($this->pollForViewDone(self::VIEW_DONE_FIRST_BUILD_POLL_BUDGET_MS)) {
            return $this->readFromViewDone($sub, $tableOptions);
        }

        $progress = $this->describeBuildProgressForNotice();
        $this->surfaceViewBuildAdminNotice(
            'The redirects view table is still being built (' . $progress
            . '). Reload the page in a few seconds.'
        );
        throw new \Exception('Staged view build still pending after poll budget. Progress: ' . $progress);
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
        $isFresh = $haveDone && $builtAt > 0 && (time() - $builtAt) < self::VIEW_DONE_FRESHNESS_TTL_SECONDS;
        $isInvalidated = $haveDone && $builtAt === 0;

        if (!$isFresh) {
            if (!$haveDone || $isInvalidated) {
                // Force inline rebuild: either no data at all, or the
                // freshness option was cleared by invalidateViewDone().
                if ($this->acquireViewBuildLock()) {
                    $isComplete = false;
                    try {
                        $isComplete = $this->runStagedBuildOnce();
                    } finally {
                        $this->releaseViewBuildLock();
                    }
                    if (!$isComplete) {
                        $this->scheduleViewDoneRebuild();
                        if (!$haveDone && !$this->pollForViewDone(self::VIEW_DONE_FIRST_BUILD_POLL_BUDGET_MS)) {
                            throw new \Exception('Staged view build still pending after poll budget. Progress: '
                                . $this->describeBuildProgressForNotice());
                        }
                    }
                } else if (!$this->pollForViewDone(self::VIEW_DONE_FIRST_BUILD_POLL_BUDGET_MS)) {
                    throw new \Exception('Staged view build still pending after poll budget. Progress: '
                        . $this->describeBuildProgressForNotice());
                }
            } else {
                $this->scheduleViewDoneRebuild();
            }
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
        if (!$this->acquireViewBuildLock()) {
            return;
        }
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
        $size = self::VIEW_BUILD_DEFAULT_BATCH_SIZE;
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
        $budget = (float)self::VIEW_BUILD_PER_STAGE_BUDGET_SECONDS;
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
            && (time() - $startedAt) < self::VIEW_BUILD_RESUME_TTL_SECONDS
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
            $this->stageCreateBuildTable();
            // Stamp started_at on the very first stage so the resume-TTL
            // clock starts from buffer creation.
            if ($this->readProgressOption('started_at', 0) === 0) {
                $this->writeProgressOption('started_at', time());
            }
            $this->writeProgressOption('current_stage', 1);
            $stage = 1;
        }

        if ($stage < 2) {
            if (!$this->stageInsertRedirectsBatched()) {
                return false; // budget exhausted; resume on next request
            }
            $this->writeProgressOption('current_stage', 2);
            $stage = 2;
        }

        if ($stage < 3) {
            $this->markBuildStage('staged_build_s3_index_fd');
            $this->stageAddPreJoinIndexes();
            $this->writeProgressOption('current_stage', 3);
            $stage = 3;
        }

        if ($stage < 4) {
            if (!$this->stageUpdatePostsBatched()) {
                return false;
            }
            $this->writeProgressOption('current_stage', 4);
            $stage = 4;
        }

        if ($stage < 5) {
            if (!$this->stageUpdateTermsBatched()) {
                return false;
            }
            $this->writeProgressOption('current_stage', 5);
            $stage = 5;
        }

        if ($stage < 6) {
            $this->markBuildStage('staged_build_s6_update_home');
            $this->stageUpdateHome();
            $this->writeProgressOption('current_stage', 6);
            $stage = 6;
        }

        if ($stage < 7) {
            $this->markBuildStage('staged_build_s7_update_external');
            $this->stageUpdateExternal();
            $this->writeProgressOption('current_stage', 7);
            $stage = 7;
        }

        if ($stage < 8) {
            $this->markBuildStage('staged_build_s8_update_special');
            $this->stageUpdateSpecial();
            $this->writeProgressOption('current_stage', 8);
            $stage = 8;
        }

        if ($stage < 9) {
            if ($this->logsHitsTableExists()) {
                $this->markBuildStage('staged_build_s9_update_hits');
                $this->stageUpdateHits();
            }
            // Skipped or not, we've moved past S9.
            $this->writeProgressOption('current_stage', 9);
            $stage = 9;
        }

        if ($stage < 10) {
            $this->markBuildStage('staged_build_s10_index_sort');
            $this->stageAddSortIndexes();
            $this->writeProgressOption('current_stage', 10);
            $stage = 10;
        }

        if ($stage < 11) {
            $this->markBuildStage('staged_build_s11_swap');
            $this->stageRenameSwap();
            if (function_exists('update_option')) {
                update_option($this->viewDoneFreshnessOptionName(), time(), false);
            }
            // Build fully done — wipe progress so the next rebuild starts clean.
            $this->clearAllProgressOptions();
        }

        return true;
    }

    /** Drop both the build buffer and the leftover deleteme.  Used on fresh-start only. */
    private function dropTransientStagedTables(): void {
        $build = $this->viewBuildTableName();
        $deleteme = $this->viewDeletemeTableName();
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $build . '`',
            array('log_errors' => false));
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deleteme . '`',
            array('log_errors' => false));
    }

    /** Drop only the deleteme leftover from a prior crashed RENAME swap. */
    private function dropDeletemeTable(): void {
        $deleteme = $this->viewDeletemeTableName();
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deleteme . '`',
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
        $this->runStagedSqlFile('09_update_hits.sql', array());
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
        $build = $this->viewBuildTableName();
        $done = $this->viewDoneTableName();
        $deleteme = $this->viewDeletemeTableName();

        // Defensive: ensure deleteme is gone before the swap (S0 already did
        // this, but a poorly-timed parallel rebuild could have created it).
        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deleteme . '`',
            array('log_errors' => false));

        if ($this->viewDoneTableExists()) {
            $sql = 'RENAME TABLE `' . $done . '` TO `' . $deleteme . '`,'
                 . ' `' . $build . '` TO `' . $done . '`';
        } else {
            $sql = 'RENAME TABLE `' . $build . '` TO `' . $done . '`';
        }

        $result = $this->queryAndGetResults($sql, array('log_errors' => true));
        $err = isset($result['last_error']) && is_string($result['last_error'])
            ? trim($result['last_error']) : '';
        if ($err !== '') {
            throw new \Exception('RENAME TABLE swap failed: ' . $err);
        }

        $this->queryAndGetResults('DROP TABLE IF EXISTS `' . $deleteme . '`',
            array('log_errors' => false));
    }

    /**
     * Read page from the served view_done table.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    private function readFromViewDone(string $sub, array $tableOptions): array {
        $query = $this->buildViewDoneReadQuery($sub, $tableOptions);
        $result = $this->queryAndGetResults($query, $this->stagedQueryOptions());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Build the WHERE/ORDER/LIMIT SELECT against view_done. Mirrors the
     * legacy filter-text composite LIKE so search semantics are preserved,
     * but reads against precomputed columns (no JOINs, no CASE
     * recomputation).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function buildViewDoneReadQuery(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types, $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
        // Match legacy semantics: every tab including HANDLED filters by
        // disabled = 0 (active rows) except the dedicated TRASH tab.
        $trashClause = 'AND disabled = ' . intval($trashValue);

        $scoreRange = is_string($tableOptions['score_range'] ?? '')
            ? (string)($tableOptions['score_range'] ?? 'all') : 'all';
        $scoreRangeClause = '';
        switch ($scoreRange) {
            case 'high':   $scoreRangeClause = 'AND score >= 80'; break;
            case 'medium': $scoreRangeClause = 'AND score >= 50 AND score < 80'; break;
            case 'low':    $scoreRangeClause = 'AND score IS NOT NULL AND score < 50'; break;
            case 'manual': $scoreRangeClause = 'AND score IS NULL'; break;
        }

        $rawFilterText = is_string($tableOptions['filterText'] ?? null)
            ? (string)$tableOptions['filterText'] : '';
        $filterTextClause = '';
        if ($rawFilterText !== '') {
            $sanitized = str_replace(array('*', '/', '$'), '', $rawFilterText);
            if (isset($wpdb) && method_exists($wpdb, 'esc_like')) {
                /** @var \wpdb $wpdb */
                $sanitized = $wpdb->esc_like($sanitized);
            } else {
                $sanitized = addcslashes($sanitized, '_%\\');
            }
            $filterText = esc_sql($sanitized);
            if ($sub === 'abj404_redirects') {
                $filterTextClause = "AND REPLACE(LOWER(CONCAT(url, '////', status_for_view, '////',"
                    . " type_for_view, '////', dest_for_view, '////', code)), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            } else {
                $filterTextClause = "AND REPLACE(LOWER(url), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            }
        }

        $orderBy = $this->resolveOrderByColumn($tableOptions);
        $rawOrderVal = $tableOptions['order'] ?? '';
        $rawOrderValStr = is_string($rawOrderVal) ? $rawOrderVal : '';
        $order = strtoupper((string)preg_replace('/[^a-zA-Z]/', '', trim($rawOrderValStr)));
        if ($order !== 'DESC') { $order = 'ASC'; }

        $paged = max(1, intval(is_scalar($tableOptions['paged'] ?? 1) ? (int)$tableOptions['paged'] : 1));
        $rawPerpage = $tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE;
        $perpage = max(1, intval(is_scalar($rawPerpage) ? (int)$rawPerpage : ABJ404_OPTION_DEFAULT_PERPAGE));
        $limitStart = ($paged - 1) * $perpage;

        $done = $this->viewDoneTableName();
        $query = "SELECT id, url, status, status_for_view, type, type_for_view,\n"
            . "       final_dest, dest_for_view, published_status, code, timestamp,\n"
            . "       engine, score, wp_post_id, wp_post_type,\n"
            . "       logshits, logsid, last_used\n"
            . "FROM `" . $done . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause . "\n"
            . "ORDER BY published_status ASC, " . $orderBy . " " . $order . ", url ASC, id " . $order . "\n"
            . "LIMIT " . $limitStart . ", " . $perpage;
        return $query;
    }

    /**
     * COUNT(*) variant of buildViewDoneReadQuery. Same WHERE clauses, no
     * ORDER BY, no LIMIT.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        global $wpdb;

        $statusTypes = $this->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
        // Match legacy semantics: HANDLED filter shows active rows only
        // (disabled = 0), same as every non-TRASH tab.
        $trashClause = 'AND disabled = ' . intval($trashValue);

        $scoreRange = is_string($tableOptions['score_range'] ?? '')
            ? (string)($tableOptions['score_range'] ?? 'all') : 'all';
        $scoreRangeClause = '';
        switch ($scoreRange) {
            case 'high':   $scoreRangeClause = 'AND score >= 80'; break;
            case 'medium': $scoreRangeClause = 'AND score >= 50 AND score < 80'; break;
            case 'low':    $scoreRangeClause = 'AND score IS NOT NULL AND score < 50'; break;
            case 'manual': $scoreRangeClause = 'AND score IS NULL'; break;
        }

        $rawFilterText = is_string($tableOptions['filterText'] ?? null)
            ? (string)$tableOptions['filterText'] : '';
        $filterTextClause = '';
        if ($rawFilterText !== '') {
            $sanitized = str_replace(array('*', '/', '$'), '', $rawFilterText);
            if (isset($wpdb) && method_exists($wpdb, 'esc_like')) {
                /** @var \wpdb $wpdb */
                $sanitized = $wpdb->esc_like($sanitized);
            } else {
                $sanitized = addcslashes($sanitized, '_%\\');
            }
            $filterText = esc_sql($sanitized);
            if ($sub === 'abj404_redirects') {
                $filterTextClause = "AND REPLACE(LOWER(CONCAT(url, '////', status_for_view, '////',"
                    . " type_for_view, '////', dest_for_view, '////', code)), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            } else {
                $filterTextClause = "AND REPLACE(LOWER(url), ' ', '')"
                    . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
            }
        }

        $done = $this->viewDoneTableName();
        $query = "SELECT COUNT(*) AS cnt\n"
            . "FROM `" . $done . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause;
        return $query;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function resolveStatusTypeList(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types;
        $filter = $tableOptions['filter'] ?? 0;
        $statusTypes = '';
        if ($filter == 0 || $filter == ABJ404_TRASH_FILTER) {
            if ($sub === 'abj404_redirects') {
                $statusTypes = implode(', ', is_array($abj404_redirect_types) ? $abj404_redirect_types : array());
            } else if ($sub === 'abj404_captured') {
                $statusTypes = implode(', ', is_array($abj404_captured_types) ? $abj404_captured_types : array());
            }
        } else if ($filter == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(', ', array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));
        } else if ($filter == ABJ404_HANDLED_FILTER) {
            $statusTypes = implode(', ', array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));
        } else {
            $statusTypes = (string)$filter;
        }
        $cleaned = preg_replace('/[^\d, ]/', '', $statusTypes);
        return is_string($cleaned) ? $cleaned : '';
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function resolveOrderByColumn(array $tableOptions): string {
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $allowed = array('url', 'status', 'type', 'code', 'score', 'timestamp',
            'logshits', 'last_used', 'final_dest', 'dest', 'id');
        if ($orderBy === 'dest' || $orderBy === 'final_dest') {
            // Same as legacy: treat empty dest as last.
            return "CASE WHEN dest_for_view IS NULL OR dest_for_view = '' THEN 1 ELSE 0 END ASC, dest_for_view";
        }
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'url';
        }
        return $orderBy;
    }

    /**
     * Translations for status_for_view, type_for_view, and the special
     * 404-displayed label. Everything else (`{wp_*}`, `{ABJ404_TYPE_X}`)
     * is handled by doTableNameReplacements + doNormalReplacements.
     *
     * @return array<string, string>
     */
    private function viewBuildOnlyTranslations(): array {
        return array(
            '{ABJ404_STATUS_MANUAL_text}' => __('Manual', '404-solution'),
            '{ABJ404_STATUS_AUTO_text}'   => __('Automatic', '404-solution'),
            '{ABJ404_STATUS_REGEX_text}'  => __('Regex', '404-solution'),
            '{ABJ404_TYPE_EXTERNAL_text}' => __('External', '404-solution'),
            '{ABJ404_TYPE_CAT_text}'      => __('Category', '404-solution'),
            '{ABJ404_TYPE_TAG_text}'      => __('Tag', '404-solution'),
            '{ABJ404_TYPE_HOME_text}'     => __('Home', '404-solution'),
            '{ABJ404_TYPE_404_DISPLAYED_text}' => __('(404 page)', '404-solution'),
            '{ABJ404_TYPE_SPECIAL_text}'  => __('Special', '404-solution'),
        );
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
        return ((string)$value === $tableName);
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
        return (time() - $builtAt) < self::VIEW_DONE_FRESHNESS_TTL_SECONDS;
    }

    /** @return bool */
    private function acquireViewBuildLock(): bool {
        $name = $this->getLowercasePrefix() . self::VIEW_DONE_BUILD_LOCK_NAME;
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
        $name = $this->getLowercasePrefix() . self::VIEW_DONE_BUILD_LOCK_NAME;
        $this->queryAndGetResults("SELECT RELEASE_LOCK('" . esc_sql($name) . "')",
            array('log_errors' => false));
    }

    /**
     * Wait up to $budgetMs for view_done to materialize. Used when the
     * build lock was unavailable on a first-ever request and we need
     * the other builder to finish before we can serve.
     *
     * @param int $budgetMs
     * @return bool true when view_done becomes available within the budget
     */
    private function pollForViewDone(int $budgetMs): bool {
        $deadline = microtime(true) + ($budgetMs / 1000);
        while (microtime(true) < $deadline) {
            if ($this->viewDoneTableExists()) {
                return true;
            }
            usleep(self::VIEW_DONE_FIRST_BUILD_POLL_INTERVAL_MS * 1000);
        }
        return $this->viewDoneTableExists();
    }

    /** @return void */
    private function scheduleViewDoneRebuild(): void {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            $hook = 'abj404_rebuildViewDone';
            $next = wp_next_scheduled($hook);
            if ($next === false) {
                wp_schedule_single_event(time() + 1, $hook);
            }
        }
    }

    /**
     * Surface a single admin notice on the plugin's own admin pages when
     * the first-ever build is still pending. Per CLAUDE.md: never email,
     * never wp-admin-wide. Existing transient-based dedupe keeps it to one
     * notice per 24h per failure type.
     *
     * @param string $message
     * @return void
     */
    private function surfaceViewBuildAdminNotice(string $message): void {
        if (function_exists('set_transient')) {
            set_transient('abj404_view_build_pending_notice', $message, 60);
        }
    }
}
