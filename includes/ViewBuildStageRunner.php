<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-stage runner / telemetry / shutdown-diagnostics infrastructure for the
 * staged getRedirectsForView build pipeline.
 *
 * Extracted from {@see ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait}
 * so the orchestrator (runStagedBuildOnce) stays under the modularity cap
 * without losing the timing/log/inflight wiring each stage relies on.
 *
 * What lives here:
 *   - runTimedViewBuildStage(): the wrapper every stage callback flows
 *     through. Times the stage, catches throwables, dispatches them to the
 *     HostFailurePolicy classifier, and emits the per-stage log line.
 *   - runNonBatchedStageWithKillStreakEscape(): the variant used by the
 *     non-batched stages (S3 / S9 / S10) that bumps a kill-streak counter
 *     and swaps in an extended per-query timeout on retry.
 *   - markViewBuildStageStarted / markViewBuildStageCompleted: persist
 *     per-stage entry/exit markers to the progress option registry so a
 *     resumable build can pick up where the previous request left off.
 *   - registerViewBuildShutdownDiagnostics / logViewBuildShutdownDiagnostics
 *     / clearViewBuildOpenStageForShutdown: register_shutdown_function
 *     diagnostics that surface a WARN line when PHP dies mid-stage so the
 *     post-mortem records which stage was open and the last PHP error.
 *   - markBuildStage(): writes the inflight-stage transient that the AJAX
 *     progress poller reads, including the "batch X/Y" inner-loop marker
 *     captured into $lastBatchProgressDetail so a mid-stage yield doesn't
 *     drop back to a bare "yielded in N ms" between ticks.
 *
 * Composed alongside the orchestrator trait into ABJ_404_Solution_DataAccess
 * so the cross-trait calls ($this->readProgressOption, $this->logger,
 * $this->classifyAndHandleStageFailure, $this->resetStageNoProgressStreak,
 * $this->extendedTimeoutForKilledNonBatchedStage, $this->stagedQueryTimeoutSeconds)
 * resolve through the shared composing class.
 *
 * @property ABJ_404_Solution_DatabaseCore $dbCore
 * @property ABJ_404_Solution_Functions $f
 * @property ABJ_404_Solution_Logging $logger
 * @property ABJ_404_Solution_ViewReadService|null $viewReadService
 * @property ABJ_404_Solution_LogsRepository|null $logsRepo
 * @property int $stagedQueryTimeoutSeconds
 * @property string $lastBatchProgressDetail
 * @property bool $viewBuildStageOpenForShutdown
 * @property int $viewBuildShutdownStageNumber
 * @property string $viewBuildShutdownStageKey
 * @property bool|null $namedLockSupportedThisRequest
 * @property bool $fallbackLockLoggedThisRequest
 * @property bool $usingTransientFallbackLock
 * @property string $lastNamedLockUnsupportedReason
 * @property string $lastNamedLockUnsupportedError
 * @method void abortStagedBuildForMutationWatermarkAdvance(...$arguments)
 * @method bool acquireTransientFallbackLock(...$arguments)
 * @method bool acquireViewBuildLock(...$arguments)
 * @method string activeBuildStartedWatermarkOptionName(...$arguments)
 * @method bool adminMutationGateBlocks(...$arguments)
 * @method array<mixed> advanceViewBuildOnce(...$arguments)
 * @method void assertBuildBufferExistsOrHalt(...$arguments)
 * @method ?bool attemptRelaxSqlModeForBuildConnection(...$arguments)
 * @method bool bufferIntegrityPassesForPromote(...$arguments)
 * @method string buildHaltTransientKey(...$arguments)
 * @method string buildViewDoneCountQuery(...$arguments)
 * @method string builtWatermarkOptionName(...$arguments)
 * @method int bumpMutationWatermark(...$arguments)
 * @method int bumpStageNoProgressStreak(...$arguments)
 * @method string capturedPrefixForLog(...$arguments)
 * @method void capturePrefixAtBuildStart(...$arguments)
 * @method void claimForegroundViewBuildLease(...$arguments)
 * @method string classifyAndHandleStageFailure(...$arguments)
 * @method array<mixed> classifySessionVariableWarnings(...$arguments)
 * @method string classifyStageFailure(...$arguments)
 * @method void clearActiveBuildStartedWatermark(...$arguments)
 * @method void clearAdminMutationGateOptions(...$arguments)
 * @method void clearAllProgressOptions(...$arguments)
 * @method void clearPhpEnvironmentProbeCache(...$arguments)
 * @method void clearPrefixAtStageOne(...$arguments)
 * @method void clearSessionVariablesProbeCache(...$arguments)
 * @method void clearSqlModeProbeCache(...$arguments)
 * @method void clearStagedBuildDegradedState(...$arguments)
 * @method void clearViewBuildOpenStageForShutdown(...$arguments)
 * @method void clearViewDoneHardStaleNotice(...$arguments)
 * @method ABJ_404_Solution_Clock clock(...$arguments)
 * @method int countLiveRedirects(...$arguments)
 * @method int countViewBuildRows(...$arguments)
 * @method string describeBuildProgressForNotice(...$arguments)
 * @method string describeDegradedNotice(...$arguments)
 * @method string describeStagedSqlFailure(...$arguments)
 * @method array<mixed> detectAndAdjustSqlMode(...$arguments)
 * @method float detectHostStagedQueryLimitSeconds(...$arguments)
 * @method string doTableNameReplacements(...$arguments)
 * @method void dropDeletemeTable(...$arguments)
 * @method void dropTransientBuffersIfPresent(...$arguments)
 * @method void dropTransientStagedTables(...$arguments)
 * @method void ensureConnection(...$arguments)
 * @method void ensureFallbackLockNoticeAndLog(...$arguments)
 * @method int extendedTimeoutForKilledNonBatchedStage(...$arguments)
 * @method array<mixed> fetchSessionVariablesRowOrEmpty(...$arguments)
 * @method string filesystemEnvironmentProbeOptionName(...$arguments)
 * @method bool forceRestartViewBuild(...$arguments)
 * @method bool foregroundViewBuildLeaseActive(...$arguments)
 * @method string formatPhpMemoryBytesHuman(...$arguments)
 * @method bool gateAbortIfMutationWatermarkAdvanced(...$arguments)
 * @method string getColumnCollationString(...$arguments)
 * @method int getCronStuckHours(...$arguments)
 * @method string getLowercasePrefix(...$arguments)
 * @method array<string, mixed> getViewBuildProgress(...$arguments)
 * @method array<mixed> getViewBuildProgressFingerprint(...$arguments)
 * @method int getViewDoneBuiltAtTimestamp(...$arguments)
 * @method bool haltIfPrefixChangedSinceStageOne(...$arguments)
 * @method string humanBatchProgress(...$arguments)
 * @method float intelligentStagedQueryTimeoutSeconds(...$arguments)
 * @method void invalidateViewDoneServeableCache(...$arguments)
 * @method bool isBuildHaltedForHostFailure(...$arguments)
 * @method bool isCurrentStageOptionName(...$arguments)
 * @method bool isNamedLockUnsupportedError(...$arguments)
 * @method bool isResumableStagedKill(...$arguments)
 * @method bool isStageMarkedSkipped(...$arguments)
 * @method bool isTransientConnectionError(...$arguments)
 * @method string lastBuildStartedWatermarkOptionName(...$arguments)
 * @method string legacyStartedWatermarkOptionName(...$arguments)
 * @method string localizeOrDefaultViewBuildNotice(...$arguments)
 * @method bool logsHitsTableExists(...$arguments)
 * @method void logTimedViewBuildStage(...$arguments)
 * @method void logViewBuildProgressOptionWrite(...$arguments)
 * @method void logViewBuildShutdownDiagnostics(...$arguments)
 * @method void markBuildHaltedForHostFailure(...$arguments)
 * @method void markBuildStage(...$arguments)
 * @method void markStageSkippedForHostFailure(...$arguments)
 * @method void markViewBuildStageCompleted(...$arguments)
 * @method void markViewBuildStageStarted(...$arguments)
 * @method void markViewDoneBuildCompleted(...$arguments)
 * @method void markViewDoneInvalidatedByAdminMutation(...$arguments)
 * @method int maxBuildBufferId(...$arguments)
 * @method void maybeRaiseViewDoneHardStaleNotice(...$arguments)
 * @method bool mutationWatermarkAdvancedSinceBuildStart(...$arguments)
 * @method int mutationWatermarkObservedByAdminAction(...$arguments)
 * @method int mutationWatermarkObservedByAdminActionAt(...$arguments)
 * @method string mutationWatermarkObservedByAdminActionAtOptionName(...$arguments)
 * @method string mutationWatermarkObservedByAdminActionOptionName(...$arguments)
 * @method string normalizePathPrefix(...$arguments)
 * @method bool optionReadBackMatches(...$arguments)
 * @method int parsePhpMemoryLimitToBytes(...$arguments)
 * @method bool pathFallsWithinAny(...$arguments)
 * @method void performFreshStartCleanup(...$arguments)
 * @method array<mixed> phpDisabledFunctionsList(...$arguments)
 * @method string phpEnvironmentProbeOptionName(...$arguments)
 * @method float phpTimeRemainingSeconds(...$arguments)
 * @method string prefixAtStageOneOptionName(...$arguments)
 * @method array<mixed> probeFilesystemEnvironmentForBuild(...$arguments)
 * @method float probeFloatFromValues(...$arguments)
 * @method int probeIntFromValues(...$arguments)
 * @method int probeMemoryLimitForS9(...$arguments)
 * @method array<mixed> probePhpEnvironmentForBuild(...$arguments)
 * @method array<mixed> probeSessionVariablesAtS1Entry(...$arguments)
 * @method bool probeSetTimeLimitAvailability(...$arguments)
 * @method array<mixed> probeSqlModeForBuild(...$arguments)
 * @method string probeStringFromValues(...$arguments)
 * @method string progressOptionName(...$arguments)
 * @method void publishBuiltWatermarkFromActiveBuildStartedWatermark(...$arguments)
 * @method array<mixed> queryAndGetResults(...$arguments)
 * @method int readActiveBuildStartedWatermark(...$arguments)
 * @method array<int, array<string, mixed>> readFromViewDone(...$arguments)
 * @method int readProgressOption(...$arguments)
 * @method int readWatermarkOption(...$arguments)
 * @method void rebuildViewDoneInBackground(...$arguments)
 * @method bool reconcilePostStageElevenState(...$arguments)
 * @method string reconcileStagedTablesAtRunnerStartup(...$arguments)
 * @method int recordStageBatchKilled(...$arguments)
 * @method void registerViewBuildShutdownDiagnostics(...$arguments)
 * @method bool releaseAndReacquireBetweenStages(...$arguments)
 * @method void releaseViewBuildLock(...$arguments)
 * @method void resetStageNoProgressStreak(...$arguments)
 * @method string resolveColumnCollationForStagedBuild(...$arguments)
 * @method void runForceRestartCleanupInsideLock(...$arguments)
 * @method bool runIdRangeBatchedUpdate(...$arguments)
 * @method int runInsertBatch(...$arguments)
 * @method mixed runNonBatchedStageWithKillStreakEscape(...$arguments)
 * @method array{ran: bool, reason: string, progress: array<string, mixed>} runPageLoadFallbackAdvance(...$arguments)
 * @method int runRedirectsForViewCountStaged(...$arguments)
 * @method array<int, array<string, mixed>> runRedirectsForViewStaged(...$arguments)
 * @method bool runS11SwapWithPreRenameWatermarkRecheck(...$arguments)
 * @method bool runStagedBuildOnce(...$arguments)
 * @method bool runStagedBuildStages6Through11(...$arguments)
 * @method void runStagedSqlFile(...$arguments)
 * @method void runStagedSqlFileTolerantOfDuplicateKey(...$arguments)
 * @method mixed runTimedViewBuildStage(...$arguments)
 * @method int safeCurrentMutationWatermark(...$arguments)
 * @method string sanitizeUrlBeforeInsert(...$arguments)
 * @method void scheduleViewDoneRebuild(...$arguments)
 * @method string sessionVariablesProbeOptionName(...$arguments)
 * @method void setFilesystemEnvAdminNotice(...$arguments)
 * @method void setLowMemoryLimitAdminNotice(...$arguments)
 * @method void setSessionEnvAdminNotice(...$arguments)
 * @method void setStagedBuildDegradedNotice(...$arguments)
 * @method void setStagedBuildHaltNotice(...$arguments)
 * @method void setViewBuildCronStuckNotice(...$arguments)
 * @method void setViewBuildScheduleFailedNotice(...$arguments)
 * @method void setViewDoneHardStaleNotice(...$arguments)
 * @method array<mixed> splitOpenBasedirPaths(...$arguments)
 * @method string sqlModeProbeOptionName(...$arguments)
 * @method void stageAddPreJoinIndexes(...$arguments)
 * @method void stageAddSortIndexes(...$arguments)
 * @method void stageCreateBuildTable(...$arguments)
 * @method array<string, mixed> stagedQueryOptions(...$arguments)
 * @method bool stagedTableExists(...$arguments)
 * @method bool stageInsertRedirectsBatched(...$arguments)
 * @method string stageNoProgressStreakOptionName(...$arguments)
 * @method void stageRenameSwap(...$arguments)
 * @method string stageSkipOptionName(...$arguments)
 * @method void stageUpdateExternal(...$arguments)
 * @method void stageUpdateHits(...$arguments)
 * @method void stageUpdateHome(...$arguments)
 * @method bool stageUpdatePostsBatched(...$arguments)
 * @method void stageUpdateSpecial(...$arguments)
 * @method bool stageUpdateTermsBatched(...$arguments)
 * @method void stampStartedWatermarksAtS1Entry(...$arguments)
 * @method void sweepStaleRebuildTransients(...$arguments)
 * @method string transientFallbackLockOptionName(...$arguments)
 * @method bool verifyBuildLockSerializesWriter(...$arguments)
 * @method bool verifyOptionWriteCoherent(...$arguments)
 * @method bool verifyPrefixUnchangedSinceStageOne(...$arguments)
 * @method int viewBuildBatchSize(...$arguments)
 * @method int viewBuildBatchSizeForStage(...$arguments)
 * @method array<mixed> viewBuildOnlyTranslations(...$arguments)
 * @method float viewBuildPerStageBudgetSeconds(...$arguments)
 * @method string viewBuildTableName(...$arguments)
 * @method string viewDeletemeTableName(...$arguments)
 * @method int viewDoneBuiltAt(...$arguments)
 * @method int viewDoneBuiltWatermark(...$arguments)
 * @method int viewDoneDataBuiltAt(...$arguments)
 * @method string viewDoneDataBuiltAtOptionName(...$arguments)
 * @method string viewDoneFreshnessOptionName(...$arguments)
 * @method bool viewDoneHasRows(...$arguments)
 * @method bool viewDoneIsFresh(...$arguments)
 * @method bool viewDoneIsServeable(...$arguments)
 * @method int viewDoneMutationInvalidatedAt(...$arguments)
 * @method string viewDoneMutationInvalidatedAtOptionName(...$arguments)
 * @method bool viewDoneTableExists(...$arguments)
 * @method string viewDoneTableName(...$arguments)
 * @method void writeProgressOption(...$arguments)
 * @method void writeWatermarkOption(...$arguments)
 */
class ABJ_404_Solution_ViewBuildStageRunner extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var bool Process-local guard so shutdown diagnostics register once. */
    private static $viewBuildShutdownLoggerRegistered = false;

    /** @return void */
    public static function resetViewBuildShutdownLoggerRegistration(): void {
        self::$viewBuildShutdownLoggerRegistered = false;
    }

    /** @var bool True while a stage has started but has not reached normal logging. */
    private $viewBuildStageOpenForShutdown = false;

    /** @var int Stage currently open for shutdown diagnostics. */
    private $viewBuildShutdownStageNumber = 0;

    /** @var string Stage key currently open for shutdown diagnostics. */
    private $viewBuildShutdownStageKey = '';

    /**
     * Most recent "batch X/Y" progress detail captured from the inner loops of
     * resumable stages (S2/S4/S5). Preserved across the per-stage yield log so
     * the user-visible status doesn't drop from "batch 1.28M/1.97M (yielded)"
     * back to a bare "yielded in N ms" right before the next tick resumes.
     *
     * Reset to '' at the start of every runTimedViewBuildStage() invocation.
     *
     * @var string
     */
    private $lastBatchProgressDetail = '';

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
    public function markBuildStage(string $stageKey, string $detail = ''): void {
        if (!class_exists('ABJ_404_Solution_ViewUpdater')) {
            return;
        }
        // Capture inner-loop batch markers ("batch 1282000/1971286",
        // "batch ... (yielded)", "batch killed at size N; shrunk ...") so
        // logTimedViewBuildStage() can preserve them in the per-stage yield
        // marker. Skip strings that already contain ", yielded" so the final
        // yield write does not loop back into the captured detail.
        if ($detail !== ''
            && strncmp($detail, 'batch ', 6) === 0
            && strpos($detail, ', yielded') === false) {
            $this->lastBatchProgressDetail = $detail;
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
    public function runTimedViewBuildStage(int $stageNumber, string $stageKey, callable $callback) {
        $started = microtime(true);
        // Reset per-stage so a yield marker for this stage cannot accidentally
        // pick up a prior stage's batch detail. Inner loops (S2/S4/S5)
        // populate this via markBuildStage() as they emit "batch X/Y" lines.
        $this->lastBatchProgressDetail = '';
        try {
            $this->markViewBuildStageStarted($stageNumber, $stageKey);
            // Public extension point. Sites can hook this for telemetry, custom
            // progress dashboards, or chaos-testing the build's resume contract.
            // The do_action call is inside the try so a callback that throws
            // (test injection, host kill simulator) is treated identically to
            // a real SQL error from the stage callback below.
            if (function_exists('do_action')) {
                do_action('abj404_view_build_stage_starting', $stageNumber, $stageKey);
            }
            $result = $callback();
        } catch (\Throwable $e) {
            // B17 (Bruno 2026-05-13): when the host kills our connection
            // mid-stage (wait_timeout < build duration, MySQL errno 2006 /
            // 2013, "MySQL server has gone away" / "Lost connection during
            // query"), explicitly reconnect BEFORE the classifier and its
            // option-write side effects run. queryAndGetResults() already
            // calls ensureConnection() on its own ingress, so this is
            // belt-and-suspenders for the catch-block path: if a future
            // refactor moved any catch-block option write outside the DAO,
            // a still-broken handle would silently lose the progress /
            // streak / notice updates the classifier depends on. The
            // explicit reconnect also pins the "resume from last completed
            // stage, not S1" contract at the stage runner level rather
            // than at the DAO level. ensureConnection() is idempotent
            // (returns true when already connected) so the cost on the
            // non-connection-drop paths is one mysqli_ping per stage exit.
            if ($this->isTransientConnectionError($e->getMessage())) {
                $this->ensureConnection();
            }
            // Catch-block classification + side effects (skip / halt / streak)
            // live on the HostFailurePolicy trait so this orchestrator stays
            // focused on stage sequencing. classifyAndHandleStageFailure()
            // returns one of: 'resumable_yield', 'skipped', 'halted',
            // 'completed' (post-S11 reconcile), or 'rethrow'.
            $outcome = $this->classifyAndHandleStageFailure($stageNumber, $stageKey, $e->getMessage(), $started);
            if ($outcome === 'resumable_yield') {
                return false;
            }
            if ($outcome === 'skipped') {
                return 'skipped';
            }
            if ($outcome === 'halted') {
                return 'halted';
            }
            if ($outcome === 'completed') {
                return null;
            }
            $this->logTimedViewBuildStage($stageNumber, $stageKey, 'error', $started);
            throw $e;
        }

        $status = 'completed';
        if ($result === false) {
            $status = 'yielded';
        } else if ($result === 'skipped') {
            $status = 'skipped';
        }
        // Wall-clock yield (false return) implies the stage's batch loop ran
        // far enough to exhaust the per-stage budget, which is observable
        // forward progress. Reset the no-progress streak so legitimate
        // long-running batched stages do not eventually trip the halt.
        // Completion / skip likewise reset.
        $this->resetStageNoProgressStreak($stageNumber);
        if ($status === 'completed' || $status === 'skipped') {
            $this->markViewBuildStageCompleted($stageNumber);
        }
        $this->logTimedViewBuildStage($stageNumber, $stageKey, $status, $started);
        return $result;
    }

    /**
     * Persist stage-start metadata before a stage does work. This survives
     * PHP/request death where the completion marker and catch block never run.
     *
     * @param int $stageNumber
     * @param string $stageKey
     * @return void
     */
    public function markViewBuildStageStarted(int $stageNumber, string $stageKey): void {
        $now = time();
        if ($this->readProgressOption('started_at', 0) === 0) {
            $this->writeProgressOption('started_at', $now);
        }
        $this->writeProgressOption('last_started_stage', $stageNumber);
        $this->writeProgressOption('last_started_at', $now);
        $this->viewBuildStageOpenForShutdown = true;
        $this->viewBuildShutdownStageNumber = $stageNumber;
        $this->viewBuildShutdownStageKey = $stageKey;
        $this->logger->debugMessage(sprintf(
            '[staged] build stage %d/11 %s starting',
            $stageNumber,
            $stageKey
        ));
    }

    /**
     * @param int $stageNumber
     * @return void
     */
    public function markViewBuildStageCompleted(int $stageNumber): void {
        $this->writeProgressOption('last_completed_stage', $stageNumber);
        $this->writeProgressOption('last_completed_at', time());
    }

    /** @return void */
    public function clearViewBuildOpenStageForShutdown(): void {
        $this->viewBuildStageOpenForShutdown = false;
        $this->viewBuildShutdownStageNumber = 0;
        $this->viewBuildShutdownStageKey = '';
    }

    /** @return void */
    public function registerViewBuildShutdownDiagnostics(): void {
        if (self::$viewBuildShutdownLoggerRegistered || !function_exists('register_shutdown_function')) {
            return;
        }
        self::$viewBuildShutdownLoggerRegistered = true;
        register_shutdown_function(function () {
            $this->logViewBuildShutdownDiagnostics();
        });
    }

    /** @return void */
    public function logViewBuildShutdownDiagnostics(): void {
        if (!$this->viewBuildStageOpenForShutdown) {
            return;
        }
        $stageNumber = $this->viewBuildShutdownStageNumber > 0
            ? $this->viewBuildShutdownStageNumber
            : $this->readProgressOption('last_started_stage', 0);
        if ($stageNumber <= 0) {
            return;
        }
        $lastCompleted = $this->readProgressOption('last_completed_stage', 0);
        if ($lastCompleted >= $stageNumber) {
            return;
        }

        $errorText = 'none';
        if (function_exists('error_get_last')) {
            $lastError = error_get_last();
            if (is_array($lastError)) {
                $message = isset($lastError['message']) && is_scalar($lastError['message'])
                    ? (string)$lastError['message'] : '';
                $file = isset($lastError['file']) && is_scalar($lastError['file'])
                    ? (string)$lastError['file'] : '';
                $line = isset($lastError['line']) && is_scalar($lastError['line'])
                    ? (string)$lastError['line'] : '';
                $errorText = trim($message . ($file !== '' ? ' in ' . $file : '') . ($line !== '' ? ':' . $line : ''));
                if ($errorText === '') {
                    $errorText = 'error_get_last returned an empty error';
                }
            }
        }

        $this->logger->warn(sprintf(
            '[staged] shutdown while build stage %d/11 %s was still open; '
            . 'last_completed_stage=%d; fatal_context=%s',
            $stageNumber,
            $this->viewBuildShutdownStageKey,
            $lastCompleted,
            substr($errorText, 0, 240)
        ));
    }

    /**
     * Run a non-batched stage (S3 / S9 / S10) with the kill-streak
     * escape valve applied. Behaves like runTimedViewBuildStage() except:
     *
     *   - Before invoking the stage, looks up the persisted kill streak
     *     for $streakOptKey. If >= 1, swaps in an extended per-query
     *     timeout (extendedTimeoutForKilledNonBatchedStage) so the
     *     SET STATEMENT max_statement_time hint can exceed the host's
     *     session limit on retry.
     *   - On `false` return (resumable kill), increments the streak so
     *     the next request resumes with the extended timeout already in
     *     effect.
     *   - On any non-`false` return (completed or 'skipped'), resets the
     *     streak to 0 -- the next rebuild starts fresh.
     *
     * The original $stagedQueryTimeoutSeconds is restored before
     * returning so subsequent stages run with their own intelligent
     * timeout, not the extended one (which was only meant for the
     * stuck non-batched stage).
     *
     * @param int      $stageNumber   1-based staged build number.
     * @param string   $stageKey      Stable stage key for AJAX progress.
     * @param string   $streakOptKey  Progress option key, e.g. 's3_kill_streak'.
     * @param callable $callback
     * @return mixed   Forwards runTimedViewBuildStage's return value:
     *                 typically true|null on completion, false on
     *                 resumable kill, 'skipped' when the callback
     *                 self-skips (S9 with no logs_hits table). Callers
     *                 only check `=== false` so the broader type is fine.
     */
    public function runNonBatchedStageWithKillStreakEscape(
        int $stageNumber,
        string $stageKey,
        string $streakOptKey,
        callable $callback
    ) {
        $savedTimeout = $this->stagedQueryTimeoutSeconds;
        $this->stagedQueryTimeoutSeconds = $this->extendedTimeoutForKilledNonBatchedStage($streakOptKey);
        try {
            $result = $this->runTimedViewBuildStage($stageNumber, $stageKey, $callback);
        } finally {
            $this->stagedQueryTimeoutSeconds = $savedTimeout;
        }
        if ($result === false) {
            $this->writeProgressOption(
                $streakOptKey,
                $this->readProgressOption($streakOptKey, 0) + 1
            );
        } else {
            $this->writeProgressOption($streakOptKey, 0);
        }
        return $result;
    }

    /**
     * @param int $stageNumber
     * @param string $stageKey
     * @param string $status
     * @param float $started
     * @return void
     */
    public function logTimedViewBuildStage(int $stageNumber, string $stageKey, string $status, float $started): void {
        $elapsedMs = (int)round((microtime(true) - $started) * 1000);
        $markerDetail = $status . ' in ' . $elapsedMs . ' ms';
        // Preserve mid-stage batch progress in the user-visible yield marker
        // so the polled status does not drop from "batch 1282000/1971286
        // (yielded; tight time)" back to a bare "yielded in N ms" between
        // ticks. Only applied to yield-class statuses; "completed" already
        // reads cleanly without batch context.
        if (($status === 'yielded' || $status === 'killed_resumable')
            && $this->lastBatchProgressDetail !== '') {
            $markerDetail = $this->lastBatchProgressDetail . ', ' . $markerDetail;
        }
        $this->markBuildStage($stageKey, $markerDetail);
        $this->logger->debugMessage(sprintf(
            '[staged] build stage %d/11 %s %s in %d ms',
            $stageNumber,
            $stageKey,
            $status,
            $elapsedMs
        ));
        $this->clearViewBuildOpenStageForShutdown();
    }
}
