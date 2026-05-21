<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-mutation visibility gate (Phase 4 of the staged view-build watermark
 * refactor; see docs/refactor-staged-view-build-watermark.md and queue task
 * t_260516_140130_872).
 *
 * The single observable contract: after an admin clicks Save on the redirects
 * UI, the next AJAX fetch must NOT return a snapshot built before the
 * mutation, OR it must fall back to stale-serving once the sanity timeout
 * elapses (so a stuck cron does not block the admin redirects screen
 * forever). Before Phase 4 the gate was driven by a unix timestamp
 * comparison (`viewDoneMutationInvalidatedAt > viewDoneDataBuiltAt`). Phase
 * 4 swaps the inputs to watermark comparison: `built_watermark` (published
 * at S11 by the runner) vs `mutation_watermark_observed_by_admin_action`
 * (the post-increment value the admin's bump returned at click-Save time).
 *
 * Three options:
 *
 *   - `wp_abj404_view_done_mutation_watermark_observed_by_admin_action`:
 *     the post-increment watermark value the admin's bump returned. Set by
 *     {@see markViewDoneInvalidatedByAdminMutation()}; cleared by
 *     {@see markViewDoneBuildCompleted()} on success.
 *   - `wp_abj404_view_done_mutation_watermark_observed_at`: wall-clock
 *     timestamp paired with the observed value for the sanity-window
 *     fallback. Same lifetime as the observed-watermark option.
 *   - `wp_abj404_view_done_mutation_invalidated_at`: pre-Phase-4 timestamp
 *     option. Read as a cold-bootstrap fallback when the watermark class is
 *     not yet loaded (defensive guard); written by the same fallback path
 *     so an upgraded install that hits a cold path still gates reads.
 *     Cleaned up by {@see markViewDoneBuildCompleted()} for convergence.
 *
 * Comparison semantics (pinned by ViewDoneServeabilityWatermarkGateTest):
 *
 *   - `built_watermark >= observed` releases the gate (the snapshot covers
 *     the admin mutation). `>=`, not `>`, so a build that exactly covers
 *     the observed watermark unblocks reads.
 *   - The comparison uses the OBSERVED value, NOT the live counter
 *     ({@see ABJ_404_Solution_MutationWatermark::current()}). Unrelated
 *     later mutations on a busy site advance the live counter past the
 *     admin's observed value; gating against the live counter would block
 *     reads forever even after a covering build completed.
 *   - The gate respects the same `VIEW_DONE_MUTATION_INVALIDATED_SANITY_
 *     SECONDS` upper bound the legacy timestamp gate did. After the window
 *     elapses the gate falls back to fbc270d8 stale-serving + the
 *     hard-stale admin notice; a stuck cron / broken build cannot block
 *     the admin redirects screen indefinitely.
 *
 * Composition. Mixed into `ABJ_404_Solution_DataAccess` alongside
 * `ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait` (which holds
 * `viewDoneIsServeable()` and consults this trait's reader helpers),
 * `ABJ_404_Solution_DataAccess_MutationWatermarkSeamTrait` (the
 * `bumpMutationWatermark()` source-mutation seam), and
 * `ABJ_404_Solution_DataAccess_ViewBuildStartedWatermarkTrait` (which
 * supplies `builtWatermarkOptionName()` + `readWatermarkOption()`).
 *
 * @see ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait
 * @see ABJ_404_Solution_DataAccess_MutationWatermarkSeamTrait
 * @see ABJ_404_Solution_DataAccess_ViewBuildStartedWatermarkTrait
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
class ABJ_404_Solution_AdminMutationGate extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Phase 4 replacement for the timestamp-based admin-mutation gate.
     * Records the {@see ABJ_404_Solution_MutationWatermark::current()}
     * value observed at the moment {@see markViewDoneInvalidatedByAdmin
     * Mutation()} fires, so {@see viewDoneIsServeable()} can compare it
     * against `built_watermark` (the watermark covered by the last
     * successful build, published at S11 by {@see publishBuiltWatermark
     * FromActiveBuildStartedWatermark()}). The gate blocks reads while
     * `built_watermark < observed`, i.e. while the snapshot on disk
     * does not yet cover the admin's mutation.
     */
    public function mutationWatermarkObservedByAdminActionOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_done_mutation_watermark_observed_by_admin_action';
    }

    /**
     * Sanity-window timestamp for the observed-watermark gate. Set
     * alongside the observed-watermark value at click-Save time so {@see
     * viewDoneIsServeable()} can apply the same `VIEW_DONE_MUTATION_
     * INVALIDATED_SANITY_SECONDS` bound as the legacy timestamp-based
     * gate: a stuck cron / broken build cannot keep view_done unserveable
     * forever; after the sanity window the gate falls back to fbc270d8
     * stale-serving.
     */
    public function mutationWatermarkObservedByAdminActionAtOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_done_mutation_watermark_observed_at';
    }

    /**
     * Pre-Phase-4 timestamp option. Retained for two reasons: (1) cold-
     * bootstrap fallback when the watermark class is not yet loaded;
     * (2) cleanup target on `markViewDoneBuildCompleted()` so upgraded
     * installs converge to "no admin gate" after their first successful
     * build.
     */
    public function viewDoneMutationInvalidatedAtOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_done_mutation_invalidated_at';
    }

    /**
     * Read the watermark value the admin observed at click-Save time.
     * Returns 0 when no admin mutation has been recorded since the last
     * build completion (option absent or cleared by
     * {@see markViewDoneBuildCompleted()}).
     */
    public function mutationWatermarkObservedByAdminAction(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $val = get_option($this->mutationWatermarkObservedByAdminActionOptionName(), 0);
        return is_scalar($val) ? max(0, intval($val)) : 0;
    }

    /** @return int Unix timestamp the admin-mutation watermark was observed, or 0. */
    public function mutationWatermarkObservedByAdminActionAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $val = get_option($this->mutationWatermarkObservedByAdminActionAtOptionName(), 0);
        return is_scalar($val) ? max(0, intval($val)) : 0;
    }

    /** @return int Unix timestamp of the last admin-initiated mutation, or 0. */
    public function viewDoneMutationInvalidatedAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $val = get_option($this->viewDoneMutationInvalidatedAtOptionName(), 0);
        return is_scalar($val) ? max(0, intval($val)) : 0;
    }

    /**
     * Read the `built_watermark` published at the last successful S11
     * completion. Returns 0 when no successful build has run on this
     * install yet, so the gate naturally treats a fresh install as "no
     * admin mutation is covered yet" -- harmless because on a fresh
     * install the observed-admin-mutation-watermark option is also
     * absent.
     */
    public function viewDoneBuiltWatermark(): int {
        $value = $this->readWatermarkOption($this->builtWatermarkOptionName());
        return $value < 0 ? 0 : $value;
    }

    /**
     * True when the admin-mutation gate is currently blocking reads. The
     * gate fires when an observed-watermark is recorded, the observation
     * is within the sanity window, AND `built_watermark` has not yet
     * caught up to the observed value. Otherwise false (no gate state,
     * gate expired, or build already covers the mutation).
     *
     * Called from {@see viewDoneIsServeable()} as the sole admin-gate
     * check; the staged-queries trait does not consult any of the
     * underlying options directly.
     */
    public function adminMutationGateBlocks(): bool {
        $observedWatermark = $this->mutationWatermarkObservedByAdminAction();
        if ($observedWatermark <= 0) {
            return false;
        }
        $observedAt = $this->mutationWatermarkObservedByAdminActionAt();
        if ($observedAt <= 0) {
            return false;
        }
        $sanity = ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_MUTATION_INVALIDATED_SANITY_SECONDS;
        if ($observedAt <= time() - $sanity) {
            return false;
        }
        $builtWatermark = $this->viewDoneBuiltWatermark();
        return $builtWatermark < $observedWatermark;
    }

    /**
     * Mark view_done as needing a fresh build because the admin just
     * mutated a redirect through the UI (add/edit/trash/delete). Phase 4
     * mechanism: bump the mutation watermark and record the
     * post-increment value in
     * `mutation_watermark_observed_by_admin_action`;
     * {@see viewDoneIsServeable()} then blocks reads until
     * `built_watermark >= the recorded value` (or the sanity timeout
     * elapses). The runner observes the bump at the next stage boundary
     * and aborts/restarts the in-flight build so the next snapshot
     * covers the admin's change.
     *
     * Differs from a plain `bumpMutationWatermark()` call (Cluster A-D
     * callers): admin actions need IMMEDIATE feedback, so the recorded
     * observed value drives the stricter gate that pends the AJAX fetch
     * until a covering build completes. Non-admin mutations only need
     * the runner to abort/restart at the next stage boundary; they don't
     * need to block reads in the meantime (fbc270d8 stale-serving).
     */
    public function markViewDoneInvalidatedByAdminMutation(): void {
        // Bump the watermark so the build runner sees the admin mutation at
        // the next stage boundary. This is the sole watermark bump for admin
        // actions; invalidateViewSnapshotCache() no longer bumps (that
        // caused an infinite abort cycle on high-traffic sites where every
        // captured 404 was resetting the build).
        $observed = $this->bumpMutationWatermark();
        if (!function_exists('update_option')) {
            return;
        }
        if ($observed <= 0) {
            // Watermark primitive still unavailable after the fallback
            // bump attempt (cold-bootstrap path before the autoloader
            // resolves MutationWatermark.php). Stamp the legacy timestamp
            // option so a legacy installation of viewDoneIsServeable() can
            // still gate reads if it ever sees this state.
            update_option($this->viewDoneMutationInvalidatedAtOptionName(), time(), false);
            return;
        }
        // Record the watermark and a wall-clock timestamp so
        // viewDoneIsServeable() can apply the sanity timeout to the gate
        // the same way the legacy timestamp gate did.
        update_option($this->mutationWatermarkObservedByAdminActionOptionName(), $observed, false);
        update_option($this->mutationWatermarkObservedByAdminActionAtOptionName(), time(), false);
        $this->invalidateViewDoneServeableCache();
    }

    /**
     * Read the current per-blog mutation watermark, returning 0 when the
     * primitive is unavailable for any reason (class not autoloaded,
     * degraded wpdb that lacks get_var / prepare, transient DB error).
     * Same fallback contract as readMutationWatermarkForCacheKey in
     * DataAccessTrait_ViewSnapshotCache: 0 means "treat as unversioned"
     * and the caller falls through to its degraded path.
     */
    public function safeCurrentMutationWatermark(): int {
        if (!class_exists('ABJ_404_Solution_MutationWatermark')) {
            return 0;
        }
        try {
            return ABJ_404_Solution_MutationWatermark::current();
            // allow-silent-catch: degraded wpdb (test mocks lacking get_var, transient connection errors) collapses to fallback bump in markView; we never want the gate setter to throw and abort the admin response
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Clear the admin-mutation gate options after a build covers the
     * recorded watermark. Called from {@see markViewDoneBuildCompleted()}
     * on the S11-success and reconcile-promote paths. Cleans up the
     * legacy timestamp option as well, so installs upgrading from a
     * pre-Phase-4 build converge to "no admin gate".
     */
    public function clearAdminMutationGateOptions(): void {
        if (!function_exists('delete_option')) {
            return;
        }
        delete_option($this->mutationWatermarkObservedByAdminActionOptionName());
        delete_option($this->mutationWatermarkObservedByAdminActionAtOptionName());
        delete_option($this->viewDoneMutationInvalidatedAtOptionName());
    }
}
