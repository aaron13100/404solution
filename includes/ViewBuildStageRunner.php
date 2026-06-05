<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Timed per-stage callback runner for the staged getRedirectsForView build
 * pipeline.
 *
 * Extracted from the former staged-build trait so runStagedBuildOnce stays under the modularity cap
 * without losing the timing/log/inflight wiring each stage relies on.
 *
 * What lives here:
 *   - runTimedViewBuildStage(): the wrapper every stage callback flows
 *     through. Times the stage, catches throwables, dispatches them to the
 *     HostFailurePolicy classifier, and delegates timing log emission.
 *   - runNonBatchedStageWithKillStreakEscape(): the variant used by the
 *     non-batched stages (S3 / S9 / S10) that bumps a kill-streak counter
 *     and swaps in an extended per-query timeout on retry.
 *
 * Durable marker persistence lives in ViewBuildStageMarkers. Shutdown
 * post-mortems live in ViewBuildShutdownDiagnostics. AJAX marker/log
 * formatting lives in ViewBuildStageLogPresenter. Shared process-local state
 * lives in ViewBuildStageRuntimeState.
 *
 * Composed alongside the stage pipeline through ABJ_404_Solution_ViewBuildOrchestrator
 * so the cross-collaborator calls ($this->readProgressOption, $this->logger,
 * $this->classifyAndHandleStageFailure, $this->resetStageNoProgressStreak,
 * $this->extendedTimeoutForKilledNonBatchedStage, staged query timeout state)
 * resolve through the shared orchestrator host.
 *
 * @property ABJ_404_Solution_DatabaseCore $dbCore
 * @property ABJ_404_Solution_Functions $f
 * @property ABJ_404_Solution_Logging $logger
 * @property ABJ_404_Solution_ViewReadService|null $viewReadService
 * @property ABJ_404_Solution_LogsRepository|null $logsRepo
 * @property bool|null $namedLockSupportedThisRequest
 * @property bool $fallbackLockLoggedThisRequest
 * @property bool $usingTransientFallbackLock
 * @property string $lastNamedLockUnsupportedReason
 * @property string $lastNamedLockUnsupportedError
 * @method bool acquireTransientFallbackLock(...$arguments)
 * @method bool acquireViewBuildLock(...$arguments)
 * @method array<mixed> advanceViewBuildOnce(...$arguments)
 * @method void assertBuildBufferExistsOrHalt(...$arguments)
 * @method ?bool attemptRelaxSqlModeForBuildConnection(...$arguments)
 * @method bool bufferIntegrityPassesForPromote(...$arguments)
 * @method string buildHaltTransientKey(...$arguments)
 * @method string buildViewDoneCountQuery(...$arguments)
 * @method int bumpStageNoProgressStreak(...$arguments)
 * @method string capturedPrefixForLog(...$arguments)
 * @method void capturePrefixAtBuildStart(...$arguments)
 * @method void claimForegroundViewBuildLease(...$arguments)
 * @method string classifyAndHandleStageFailure(...$arguments)
 * @method array<mixed> classifySessionVariableWarnings(...$arguments)
 * @method string classifyStageFailure(...$arguments)
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
 * @method int maxBuildBufferId(...$arguments)
 * @method void maybeRaiseViewDoneHardStaleNotice(...$arguments)
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
 * @method array<mixed> queryAndGetResults(...$arguments)
 * @method array<int, array<string, mixed>> readFromViewDone(...$arguments)
 * @method int readProgressOption(...$arguments)
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
 * @method bool runS11Swap(...$arguments)
 * @method bool runStagedBuildOnce(...$arguments)
 * @method bool runStagedBuildStages6Through11(...$arguments)
 * @method void runStagedSqlFile(...$arguments)
 * @method void runStagedSqlFileTolerantOfDuplicateKey(...$arguments)
 * @method mixed runTimedViewBuildStage(...$arguments)
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
 * @method int viewDoneDataBuiltAt(...$arguments)
 * @method string viewDoneDataBuiltAtOptionName(...$arguments)
 * @method string viewDoneFreshnessOptionName(...$arguments)
 * @method bool viewDoneHasRows(...$arguments)
 * @method bool viewDoneIsFresh(...$arguments)
 * @method bool viewDoneIsServeable(...$arguments)
 * @method bool viewDoneTableExists(...$arguments)
 * @method string viewDoneTableName(...$arguments)
 * @method void writeProgressOption(...$arguments)
 */
class ABJ_404_Solution_ViewBuildStageRunner extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @return void */
    public static function resetViewBuildShutdownLoggerRegistration(): void {
        if (class_exists('ABJ_404_Solution_ViewBuildShutdownDiagnostics')) {
            ABJ_404_Solution_ViewBuildShutdownDiagnostics::resetViewBuildShutdownLoggerRegistration();
        }
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
        $savedTimeout = $this->stagedQueryTimeoutSeconds();
        $this->setStagedQueryTimeoutSeconds($this->extendedTimeoutForKilledNonBatchedStage($streakOptKey));
        try {
            $result = $this->runTimedViewBuildStage($stageNumber, $stageKey, $callback);
        } finally {
            $this->setStagedQueryTimeoutSeconds($savedTimeout);
        }
        if ($result === false) {
            $this->writeProgressOption($streakOptKey,
                $this->readProgressOption($streakOptKey, 0) + 1
            );
        } else {
            $this->writeProgressOption($streakOptKey, 0);
        }
        return $result;
    }

}
