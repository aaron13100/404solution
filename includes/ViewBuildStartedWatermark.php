<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-build watermark stamp machinery for the staged view-build pipeline.
 *
 * Three persistent stamps, three lifetimes (Phase 3a step 3 split of the
 * legacy single `started_watermark` -- Codex #7):
 *
 *   - active_build_started_watermark: written at S1 entry on a fresh build,
 *     cleared on S11 success and on every abort (mutation-watermark gate,
 *     prefix-changed gate, force-restart primitive). Read at every stage
 *     boundary by {@see mutationWatermarkAdvancedSinceBuildStart}: a strict
 *     "current() > stamp" comparison drives the abort decision. While this
 *     stamp exists, a build is in flight against the watermark it holds.
 *   - last_build_started_watermark: diagnostic sibling. Written at S1 entry
 *     alongside the active stamp, RETAINED across every settle path. Tells
 *     an operator "what was the most recent stamp we wrote, regardless of
 *     whether that build completed?" -- the question the original single
 *     `started_watermark` could not answer cleanly (a stamp surviving a
 *     completed build was the footgun Codex #7 flagged).
 *   - built_watermark: the cross-build pre-image. Written ONLY at S11
 *     success and ONLY from the active stamp's value, so it records
 *     "what mutation watermark did the LAST SUCCESSFUL view_done snapshot
 *     cover?" Read by future freshness checks (Phase 4) to decide whether
 *     to serve view_done or trigger a rebuild. Survives every fresh-start
 *     and abort cleanup.
 *
 * All three stamps live OUTSIDE `$viewBuildProgressOptionNames` because
 * reads/writes route through {@see readWatermarkOption} / {@see writeWatermarkOption}
 * to preserve the absent-vs-0 distinction: a fresh install whose mutation
 * watermark is 0 produces a legitimate stamp of 0, which `readProgressOption`
 * (clamping to >= 0) cannot distinguish from "never stamped".
 *
 * Migration. A pre-Phase-3a-step-3 install upgrading mid-flight may carry
 * the legacy `started_watermark` option populated by the prior code path.
 * {@see readActiveBuildStartedWatermark} falls back to the legacy key when
 * the active key is absent, promotes the value into the active key, and
 * deletes the legacy key so convergence happens after one tick. The stamp /
 * clear methods also delete the legacy key whenever they run, so the next
 * S1 entry, abort, or S11 success on a post-upgrade install converges to
 * the new naming. Phase 5 cleanup deletes the fallback + the legacy helper
 * once the rename has been in production for one release cycle.
 *
 * Composition. Mixed into `ABJ_404_Solution_DataAccess` alongside
 * `ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait` (the orchestrator's
 * abort/fresh-start/gate methods call into this trait via `$this->`).
 *
 * @see ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait
 * @see ABJ_404_Solution_DataAccess_ViewBuildForceRestartTrait
 * @see ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait
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
class ABJ_404_Solution_ViewBuildStartedWatermark extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Unprefixed option-name suffix for the S11-completion watermark stamp.
     * Lives on its own (NOT in `$viewBuildProgressOptionNames`) so it
     * survives across builds: this is the published "what watermark did the
     * LAST SUCCESSFUL view_done snapshot cover?" pre-image.
     * clearAllProgressOptions (called on abort and on fresh-start) must NOT
     * touch it.
     */
    public function builtWatermarkOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_built_watermark';
    }

    /**
     * Option name for the in-flight build's S1-entry stamp. Cleared on S11
     * success, on at-stage abort, and by `forceRestartViewBuild()`.
     */
    public function activeBuildStartedWatermarkOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_active_started_watermark';
    }

    /**
     * Option name for the diagnostic-only "most recent stamp" sibling.
     * Written at S1 entry alongside the active stamp, RETAINED across S11
     * success, abort, and force-restart so operators can see the value
     * regardless of completion.
     */
    public function lastBuildStartedWatermarkOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_last_started_watermark';
    }

    /**
     * Pre-Phase-3a-step-3 option name. Read via the migration fallback in
     * {@see readActiveBuildStartedWatermark} and deleted by every stamp /
     * clear call so post-upgrade installs converge to the new naming after
     * one build cycle. Phase 5 cleanup deletes this helper.
     */
    public function legacyStartedWatermarkOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_started_watermark';
    }

    /**
     * Raw watermark-option reader. Returns -1 when the option is absent,
     * the stored integer otherwise. The absent-vs-0 distinction matters
     * for the resume-preserves-stamp contract: a fresh install with no
     * mutations has current()=0, so a stamp of 0 is a legitimate stamped
     * value, NOT the "no stamp yet" sentinel.
     *
     * @param string $fullyPrefixedName Option name as built by
     *   activeBuildStartedWatermarkOptionName() / builtWatermarkOptionName().
     * @return int  -1 when absent; the stored integer otherwise.
     */
    public function readWatermarkOption(string $fullyPrefixedName): int {
        if (!function_exists('get_option')) {
            return -1;
        }
        $value = get_option($fullyPrefixedName, null);
        if ($value === null || $value === false) {
            return -1;
        }
        if (!is_scalar($value)) {
            return -1;
        }
        return intval($value);
    }

    /**
     * Raw watermark-option writer. Autoload=false so the per-build stamps
     * do not bloat the alloptions cache loaded on every WP page.
     */
    public function writeWatermarkOption(string $fullyPrefixedName, int $value): void {
        if (!function_exists('update_option')) {
            return;
        }
        update_option($fullyPrefixedName, max(0, $value), false);
    }

    /**
     * True when the live mutation watermark is strictly greater than the
     * pre-image stamped at S1 entry of the in-flight build. Returns false
     * when no stamp exists (no in-flight build) or when current() <= stamp.
     *
     * Called at every stage boundary by the orchestrator. A true result
     * means an external caller bumped the watermark while this build was
     * running, so the buffer it has assembled does not cover that mutation.
     * The orchestrator must abort and let the next tick rebuild.
     */
    public function mutationWatermarkAdvancedSinceBuildStart(): bool {
        $started = $this->readActiveBuildStartedWatermark();
        if ($started < 0) {
            return false;
        }
        if (!class_exists('ABJ_404_Solution_MutationWatermark')) {
            return false;
        }
        $current = ABJ_404_Solution_MutationWatermark::current();
        return $current > $started;
    }

    /**
     * Read the active stamp, falling back to the pre-rename legacy option
     * name when the new key is absent. The fallback covers the upgrade
     * window: an install whose previous PHP request stamped
     * `started_watermark` mid-flight and whose current request is the
     * first to run with the renamed code must still see the stamp at the
     * gate-check site, otherwise the stage-boundary abort would miss a
     * real mutation that landed before the upgrade. On a hit, we promote
     * the legacy value into the active key (so the next read is direct)
     * and delete the legacy key so convergence happens after one tick.
     *
     * @return int  -1 when neither key is set; the stamp value otherwise.
     */
    public function readActiveBuildStartedWatermark(): int {
        $value = $this->readWatermarkOption($this->activeBuildStartedWatermarkOptionName());
        if ($value >= 0) {
            return $value;
        }
        $legacy = $this->readWatermarkOption($this->legacyStartedWatermarkOptionName());
        if ($legacy < 0) {
            return -1;
        }
        $this->writeWatermarkOption($this->activeBuildStartedWatermarkOptionName(), $legacy);
        if (function_exists('delete_option')) {
            delete_option($this->legacyStartedWatermarkOptionName());
        }
        return $legacy;
    }

    /**
     * Stamp BOTH started-watermark fields at S1 entry of a fresh build.
     * Callers route through this from the orchestrator's `if ($stage < 1)`
     * block, which only fires on fresh starts -- a resuming tick has
     * `current_stage >= 1` and never re-enters this branch, so the stamps
     * written here remain the pre-image for every subsequent stage
     * boundary in the build.
     *
     * Always overwrites both. The S11-completion path and the abort
     * cleanup path clear `active_*` only; the diagnostic `last_*` is
     * retained for operator visibility and overwritten on the next S1
     * entry. Also deletes the legacy option name so a post-upgrade
     * install converges to the new naming.
     */
    public function stampStartedWatermarksAtS1Entry(): void {
        if (!class_exists('ABJ_404_Solution_MutationWatermark')) {
            return;
        }
        $watermark = ABJ_404_Solution_MutationWatermark::current();
        $this->writeWatermarkOption($this->activeBuildStartedWatermarkOptionName(), $watermark);
        $this->writeWatermarkOption($this->lastBuildStartedWatermarkOptionName(), $watermark);
        if (function_exists('delete_option')) {
            delete_option($this->legacyStartedWatermarkOptionName());
        }
    }

    /**
     * Delete the active stamp. Called from the abort path so the next
     * tick's fresh-start branch sees no leftover stamp from the aborted
     * run, and from the S11 success path so the just-completed build's
     * pre-image does not become the next build's pre-image. (S11 also
     * publishes built_watermark, which is the cross-build pre-image that
     * survives the clear.)
     *
     * Does NOT clear `last_build_started_watermark`: that stamp's whole
     * purpose is to survive both abort and success so operators can
     * observe "what watermark did the most recent build attempt stamp?"
     * regardless of completion.
     *
     * Also deletes the legacy option name so post-upgrade installs
     * converge to the new naming after the first build settles.
     */
    public function clearActiveBuildStartedWatermark(): void {
        if (!function_exists('delete_option')) {
            return;
        }
        delete_option($this->activeBuildStartedWatermarkOptionName());
        delete_option($this->legacyStartedWatermarkOptionName());
    }

    /**
     * Stamp built_watermark = active stamp at S11 success so future
     * freshness checks can compare it against the live mutation watermark
     * to decide if view_done covers the latest data.
     *
     * Must be called BEFORE clearActiveBuildStartedWatermark() since the
     * read source disappears once the active stamp is cleared.
     * built_watermark itself lives outside the progress registry, so it
     * survives the post-S11 clears.
     */
    public function publishBuiltWatermarkFromActiveBuildStartedWatermark(): void {
        $started = $this->readActiveBuildStartedWatermark();
        if ($started < 0) {
            // No active stamp (and no legacy fallback): pre-Phase-2 install
            // upgrading mid-build, or a code path that reached S11 without
            // entering S1. Either way, do not overwrite a prior good
            // built_watermark with a bogus zero.
            return;
        }
        $this->writeWatermarkOption($this->builtWatermarkOptionName(), $started);
    }
}
