<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runner-owned `forceRestartViewBuild()` primitive (Phase 3a step 2 of the
 * staged view-build watermark refactor; see
 * docs/refactor-staged-view-build-watermark.md and queue task c554).
 *
 * Purpose. Replaces every external "discard whatever the runner has on disk
 * and restart the build from scratch" caller (the diagnostic AJAX
 * `?abj404_force_view_rebuild=1` path, the admin "rebuild now" button if
 * any, the WP-CLI rebuild commands -- migrated by Phase 3a step 4 and the
 * Cluster A-D tasks). Before Phase 4, those callers reached
 * `invalidateViewDone()` directly, which violated the runner-ownership
 * invariant the refactor exists to restore; Phase 4 (commit 2994e21c)
 * deleted that symbol and routed every external caller through either
 * `bumpMutationWatermark()` (source-mutation signal) or this primitive
 * (explicit restart-from-scratch).
 *
 * The contract is exactly seven bullets (and the absence of an eighth):
 *
 *   1. Acquire the runner lock.
 *   2. Drop the runner-owned buffer table (`view_build`).
 *   3. Clear runner progress options (registry + prefix-at-S1 capture +
 *      probe caches -- the same set `clearAllProgressOptions()` owns).
 *   4. Clear `active_build_started_watermark` (the in-flight build's
 *      S1-entry stamp). The sibling `last_build_started_watermark`
 *      stays put -- it is diagnostic-only and survives both abort and
 *      force-rebuild so an operator can see the most recent stamp.
 *   5. PRESERVE `built_watermark` (the prior successful build's published
 *      coverage). The rebuild is in flight, the old view_done snapshot is
 *      still serveable until the new S11 RENAME completes; deleting
 *      built_watermark would orphan the freshness signal until then.
 *   6. Do NOT bump the mutation watermark (force-rebuild is a runner
 *      command, not a data-change signal). Bumping would propagate as a
 *      phantom mutation to every concurrent reader that bracketed this
 *      moment -- their stage-boundary checks would abort their own builds.
 *   7. Schedule S0/S1 immediately (via the existing cron primitive
 *      `scheduleViewDoneRebuild()`). The lock is released before the
 *      schedule call so the cron tick can acquire cleanly.
 *
 * The primitive does NOT:
 *
 *   - clear `clearStagedBuildDegradedState()`. A host-failed-degraded
 *     site stays degraded across the force-restart unless the caller
 *     explicitly clears the gate; that decision is a caller policy, not
 *     a runner primitive.
 *   - run S0/S1 inline. Callers in request contexts that want immediate
 *     progress (the AJAX force-rebuild path) call `advanceViewBuildOnce()`
 *     after this primitive returns; callers without a request context
 *     (CLI, admin "rebuild now") rely on the scheduled cron tick.
 *   - write to `built_watermark` for any reason.
 *
 * Failure modes.
 *
 *   - Lock contention. If `acquireViewBuildLock(N)` returns false (a
 *     sibling cron tick or admin worker is mid-stage), the primitive
 *     returns false without touching any runner state. Callers retry
 *     on the next request; the in-flight build either completes
 *     normally (best case) or aborts at its next stage boundary and
 *     the next force-restart attempt will find the lock free.
 *
 *   - No `scheduleViewDoneRebuild()` on lock contention. Without the
 *     cleanup, scheduling a tick would just race the already-running
 *     tick. The existing holder is already advancing the build.
 *
 * Allowlist note. The host file
 * (`includes/DataAccessTrait_ViewBuildForceRestart.php`) matches the
 * runner-owned `DataAccessTrait_ViewBuild*` glob convention that the
 * Phase 4 semantic forbidden-operation lint (Codex #6 resolution; see
 * StagedBuildOwnershipLintTest) will use to define its allowlist of
 * runner-owned files. The primitive itself issues no DROP TABLE
 * (delegated to `dropTransientBuffersIfPresent()` in the
 * StageCallbacks trait, which IS the allowed owner today) and no direct
 * progress-option writes (delegated to `clearAllProgressOptions()` in
 * the Helpers trait, which IS the allowed owner today), so no allowlist
 * updates are required for the lints that are active on current HEAD.
 *
 * Sibling traits. `ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait`,
 * `ABJ_404_Solution_DataAccess_ViewBuildLockAndCronTrait`,
 * `ABJ_404_Solution_DataAccess_ViewBuildStageCallbacksTrait`; all three
 * provide the helpers this primitive composes (lock acquire/release,
 * buffer drop, progress clear, watermark stamp clear, rebuild
 * scheduling). All four traits are mixed into
 * `ABJ_404_Solution_DataAccess`.
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
class ABJ_404_Solution_ViewBuildForceRestart extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Runner-owned force-restart primitive. Per Phase 3a step 2 (c554).
     *
     * Returns true when the restart completed cleanly: lock acquired,
     * buffer dropped, progress cleared, active_build_started_watermark
     * cleared, last_build_started_watermark preserved (diagnostic),
     * built_watermark preserved (cross-build pre-image), watermark
     * counter unchanged, rebuild scheduled. Returns false when the lock
     * could not be acquired within `$lockTimeoutSeconds`; the caller
     * may retry on the next request.
     *
     * Default lock wait of 10s matches the existing
     * `?abj404_force_view_rebuild=1` AJAX handler in
     * `advanceViewBuildOnce()` -- comfortable headroom inside a 30s PHP
     * request budget so a typical cron build mid-flight has time to
     * release before we return false to the caller. Inlined as the
     * parameter default rather than a trait const because const-in-trait
     * is PHP 8.2 and the plugin targets PHP 7.4.
     *
     * @param int $lockTimeoutSeconds  GET_LOCK wait-time, default 10s.
     *   Pass 0 for a non-blocking acquire (callers that prefer to
     *   retry than to wait).
     * @return bool  true on success, false if lock contended.
     */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool {
        // (1) Acquire runner lock. Returns false if a sibling worker (cron
        //     tick, admin form save, REST PUT) holds it -- caller retries.
        if (!$this->acquireViewBuildLock(max(0, $lockTimeoutSeconds))) {
            return false;
        }

        try {
            $this->runForceRestartCleanupInsideLock();
            if ($this->rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState) {
                $this->rebuildHealth->reset();
                $this->rebuildHealth->acquireTrialToken();
            }
        } finally {
            // Release the lock BEFORE scheduling the next tick so the
            // cron callback can acquire cleanly. A leaked lock would
            // stall every subsequent build attempt until the
            // session-scoped GET_LOCK times out.
            $this->releaseViewBuildLock();
        }

        // (7) Schedule S0/S1 immediately. scheduleViewDoneRebuild() is
        //     idempotent (wp_next_scheduled short-circuit) so callers
        //     can chain or replay safely. Cron tick will drive S0 fresh
        //     cleanup -> S1 prefix capture + started-watermark re-stamp
        //     -> S2..S11.
        $this->scheduleViewDoneRebuild();

        return true;
    }

    /**
     * Inside-lock cleanup phase of force-restart, callable by code paths
     * that already hold the view-build lock and intend to drive the
     * subsequent S0/S1 run inline (e.g. advanceViewBuildOnce() with
     * forceRebuild=true). Public callers should prefer
     * {@see forceRestartViewBuild()} -- this helper does NOT acquire the
     * lock and does NOT schedule the next cron tick.
     *
     * Performs steps 2-6 of the seven-bullet force-restart contract
     * documented on the trait docblock above:
     *
     *   - drop the runner-owned buffer table (and the deleteme leftover)
     *   - clear runner progress options + prefix-at-S1 capture
     *   - clear active_build_started_watermark
     *   - preserve built_watermark (no write, no delete)
     *   - DO NOT bump the mutation watermark
     *
     * Also resets the per-request serveability cache so a subsequent
     * viewDoneIsServeable() inside the same request observes the new
     * state, not the cached pre-cleanup value.
     */
    public function runForceRestartCleanupInsideLock(): void {
        // (2) Drop the runner-owned buffer table (and the deleteme
        //     leftover from any prior crashed S11 RENAME swap).
        //     Gated by SHOW TABLES so a steady-state force-rebuild
        //     after a clean S11 (no buffer present) does not pile
        //     unconditional DDL on the hot path.
        $this->dropTransientBuffersIfPresent();

        // (3) Clear runner progress options. The helper owns the
        //     registry + prefix-at-S1 capture + sql_mode + php-env
        //     probe-cache clears as one atomic fresh-start step.
        $this->clearAllProgressOptions();

        // (4) Clear active_build_started_watermark. Lives outside
        //     the progress registry (so the S11 happy-path
        //     observability contract holds across the boundary), so
        //     it needs its own delete call. The sibling
        //     last_build_started_watermark is left alone --
        //     diagnostic-only, survives abort and force-restart.
        $this->clearActiveBuildStartedWatermark();

        // (5) PRESERVE built_watermark. No write, no delete. The
        //     prior successful build's published coverage stays as
        //     the cross-build pre-image for freshness checks until
        //     the new build's S11 swap publishes a fresh value.

        // (6) DO NOT bump the mutation watermark. No call to
        //     ABJ_404_Solution_MutationWatermark::bump() exists in
        //     this method. Force-rebuild is a runner command, not a
        //     data-change signal; a bump would propagate to every
        //     concurrent reader as a phantom mutation.

        // Reset per-request serveability cache so a subsequent
        // viewDoneIsServeable() inside this request reflects the
        // post-cleanup state rather than a stale-cached value.
        $this->invalidateViewDoneServeableCache();
    }
}
