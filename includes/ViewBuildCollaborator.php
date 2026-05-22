<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for ViewBuildOrchestrator collaborators.
 *
 * The view-build pipeline still has intentional cross-calls between stages,
 * progress bookkeeping, host probes, and watermark gates. This bridge keeps
 * those calls routed through the orchestrator while each collaborator owns its
 * local state.
 *
 * @property mixed $dbCore
 * @property mixed $f
 * @property mixed $fallbackLockLoggedThisRequest
 * @property mixed $filesystemEnvironmentProbeCache
 * @property mixed $hostStagedQueryLimitSecondsCache
 * @property mixed $lastBatchProgressDetail
 * @property mixed $lastNamedLockUnsupportedError
 * @property mixed $lastNamedLockUnsupportedReason
 * @property mixed $logger
 * @property mixed $logsRepo
 * @property mixed $namedLockSupportedThisRequest
 * @property mixed $phpEnvironmentProbeCache
 * @property mixed $prefixAtStageOne
 * @property ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
 * @property mixed $sessionVariablesProbeCache
 * @property mixed $sqlModeProbeCache
 * @property mixed $stagedQueryTimeoutSeconds
 * @property mixed $usingTransientFallbackLock
 * @property mixed $viewBuildAlreadyRanThisRequest
 * @property mixed $viewBuildProgressHighStakesShortNames
 * @property mixed $viewBuildProgressOptionNames
 * @property mixed $viewBuildShutdownLoggerRegistered
 * @property mixed $viewBuildShutdownStageKey
 * @property mixed $viewBuildShutdownStageNumber
 * @property mixed $viewBuildStageOpenForShutdown
 * @property mixed $viewDoneIsServeableCache
 * @property mixed $viewReadService
 * @method mixed abortStagedBuildForMutationWatermarkAdvance(...$arguments)
 * @method mixed acquireTransientFallbackLock(...$arguments)
 * @method mixed acquireViewBuildLock(...$arguments)
 * @method mixed activeBuildStartedWatermarkOptionName(...$arguments)
 * @method mixed adminMutationGateBlocks(...$arguments)
 * @method mixed advanceViewBuildOnce(...$arguments)
 * @method mixed assertBuildBufferExistsOrHalt(...$arguments)
 * @method mixed attemptRelaxSqlModeForBuildConnection(...$arguments)
 * @method mixed bufferIntegrityPassesForPromote(...$arguments)
 * @method mixed buildHaltTransientKey(...$arguments)
 * @method mixed builtWatermarkOptionName(...$arguments)
 * @method mixed bumpMutationWatermark(...$arguments)
 * @method mixed bumpStageNoProgressStreak(...$arguments)
 * @method mixed capturePrefixAtBuildStart(...$arguments)
 * @method mixed capturedPrefixForLog(...$arguments)
 * @method mixed claimForegroundViewBuildLease(...$arguments)
 * @method mixed classifyAndHandleStageFailure(...$arguments)
 * @method mixed classifySessionVariableWarnings(...$arguments)
 * @method mixed clearActiveBuildStartedWatermark(...$arguments)
 * @method mixed clearAdminMutationGateOptions(...$arguments)
 * @method mixed clearAllProgressOptions(...$arguments)
 * @method mixed clearPhpEnvironmentProbeCache(...$arguments)
 * @method mixed clearPrefixAtStageOne(...$arguments)
 * @method mixed clearSessionVariablesProbeCache(...$arguments)
 * @method mixed clearSqlModeProbeCache(...$arguments)
 * @method mixed clearStagedBuildDegradedState(...$arguments)
 * @method mixed clearViewBuildOpenStageForShutdown(...$arguments)
 * @method mixed clearViewDoneHardStaleNotice(...$arguments)
 * @method mixed countLiveRedirects(...$arguments)
 * @method mixed countViewBuildRows(...$arguments)
 * @method mixed describeBuildProgressForNotice(...$arguments)
 * @method mixed describeDegradedNotice(...$arguments)
 * @method mixed describeStagedSqlFailure(...$arguments)
 * @method mixed detectAndAdjustSqlMode(...$arguments)
 * @method mixed detectHostStagedQueryLimitSeconds(...$arguments)
 * @method mixed dropDeletemeTable(...$arguments)
 * @method mixed dropTransientBuffersIfPresent(...$arguments)
 * @method mixed dropTransientStagedTables(...$arguments)
 * @method mixed ensureFallbackLockNoticeAndLog(...$arguments)
 * @method mixed extendedTimeoutForKilledNonBatchedStage(...$arguments)
 * @method mixed fetchSessionVariablesRowOrEmpty(...$arguments)
 * @method mixed filesystemEnvironmentProbeOptionName(...$arguments)
 * @method mixed forceRestartViewBuild(...$arguments)
 * @method mixed foregroundViewBuildLeaseActive(...$arguments)
 * @method mixed formatPhpMemoryBytesHuman(...$arguments)
 * @method mixed gateAbortIfMutationWatermarkAdvanced(...$arguments)
 * @method mixed getCronStuckHours(...$arguments)
 * @method mixed getViewBuildProgress(...$arguments)
 * @method mixed getViewDoneBuiltAtTimestamp(...$arguments)
 * @method mixed haltIfPrefixChangedSinceStageOne(...$arguments)
 * @method mixed humanBatchProgress(...$arguments)
 * @method mixed intelligentStagedQueryTimeoutSeconds(...$arguments)
 * @method mixed invalidateViewDoneServeableCache(...$arguments)
 * @method mixed isBuildHaltedForHostFailure(...$arguments)
 * @method mixed isCurrentStageOptionName(...$arguments)
 * @method mixed isNamedLockUnsupportedError(...$arguments)
 * @method mixed isStageMarkedSkipped(...$arguments)
 * @method mixed lastBuildStartedWatermarkOptionName(...$arguments)
 * @method mixed legacyStartedWatermarkOptionName(...$arguments)
 * @method mixed localizeOrDefaultViewBuildNotice(...$arguments)
 * @method mixed logTimedViewBuildStage(...$arguments)
 * @method mixed logViewBuildProgressOptionWrite(...$arguments)
 * @method mixed logViewBuildShutdownDiagnostics(...$arguments)
 * @method mixed markBuildHaltedForHostFailure(...$arguments)
 * @method mixed markBuildStage(...$arguments)
 * @method mixed markStageSkippedForHostFailure(...$arguments)
 * @method mixed markViewBuildStageCompleted(...$arguments)
 * @method mixed markViewBuildStageStarted(...$arguments)
 * @method mixed markViewDoneBuildCompleted(...$arguments)
 * @method mixed markViewDoneInvalidatedByAdminMutation(...$arguments)
 * @method mixed maxBuildBufferId(...$arguments)
 * @method mixed maybeRaiseViewDoneHardStaleNotice(...$arguments)
 * @method mixed mutationWatermarkAdvancedSinceBuildStart(...$arguments)
 * @method mixed mutationWatermarkObservedByAdminAction(...$arguments)
 * @method mixed mutationWatermarkObservedByAdminActionAt(...$arguments)
 * @method mixed mutationWatermarkObservedByAdminActionAtOptionName(...$arguments)
 * @method mixed mutationWatermarkObservedByAdminActionOptionName(...$arguments)
 * @method mixed normalizePathPrefix(...$arguments)
 * @method mixed optionReadBackMatches(...$arguments)
 * @method mixed parsePhpMemoryLimitToBytes(...$arguments)
 * @method mixed pathFallsWithinAny(...$arguments)
 * @method mixed performFreshStartCleanup(...$arguments)
 * @method mixed phpDisabledFunctionsList(...$arguments)
 * @method mixed phpEnvironmentProbeOptionName(...$arguments)
 * @method mixed phpTimeRemainingSeconds(...$arguments)
 * @method mixed prefixAtStageOneOptionName(...$arguments)
 * @method mixed probeFilesystemEnvironmentForBuild(...$arguments)
 * @method mixed probeFloatFromValues(...$arguments)
 * @method mixed probeIntFromValues(...$arguments)
 * @method mixed probeMemoryLimitForS9(...$arguments)
 * @method mixed probePhpEnvironmentForBuild(...$arguments)
 * @method mixed probeSessionVariablesAtS1Entry(...$arguments)
 * @method mixed probeSetTimeLimitAvailability(...$arguments)
 * @method mixed probeSqlModeForBuild(...$arguments)
 * @method mixed probeStringFromValues(...$arguments)
 * @method mixed progressOptionName(...$arguments)
 * @method mixed publishBuiltWatermarkFromActiveBuildStartedWatermark(...$arguments)
 * @method mixed readActiveBuildStartedWatermark(...$arguments)
 * @method mixed readProgressOption(...$arguments)
 * @method mixed readWatermarkOption(...$arguments)
 * @method mixed rebuildViewDoneInBackground(...$arguments)
 * @method mixed reconcilePostStageElevenState(...$arguments)
 * @method mixed reconcileStagedTablesAtRunnerStartup(...$arguments)
 * @method mixed recordStageBatchKilled(...$arguments)
 * @method mixed registerViewBuildShutdownDiagnostics(...$arguments)
 * @method mixed releaseAndReacquireBetweenStages(...$arguments)
 * @method mixed releaseViewBuildLock(...$arguments)
 * @method mixed resetStageNoProgressStreak(...$arguments)
 * @method mixed resetViewBuildLockFallbackMemos(...$arguments)
 * @method mixed resetViewBuildOncePerRequestGuard(...$arguments)
 * @method mixed resetViewBuildShutdownLoggerRegistration(...$arguments)
 * @method mixed resolveColumnCollationForStagedBuild(...$arguments)
 * @method mixed runForceRestartCleanupInsideLock(...$arguments)
 * @method mixed runIdRangeBatchedUpdate(...$arguments)
 * @method mixed runInsertBatch(...$arguments)
 * @method mixed runNonBatchedStageWithKillStreakEscape(...$arguments)
 * @method mixed runPageLoadFallbackAdvance(...$arguments)
 * @method mixed runRedirectsForViewCountStaged(...$arguments)
 * @method mixed runRedirectsForViewStaged(...$arguments)
 * @method mixed runS11SwapWithPreRenameWatermarkRecheck(...$arguments)
 * @method mixed runStagedBuildOnce(...$arguments)
 * @method mixed runStagedBuildStages6Through11(...$arguments)
 * @method mixed runStagedSqlFile(...$arguments)
 * @method mixed runStagedSqlFileTolerantOfDuplicateKey(...$arguments)
 * @method mixed runTimedViewBuildStage(...$arguments)
 * @method mixed safeCurrentMutationWatermark(...$arguments)
 * @method mixed sanitizeUrlBeforeInsert(...$arguments)
 * @method mixed scheduleViewDoneRebuild(...$arguments)
 * @method mixed sessionVariablesProbeOptionName(...$arguments)
 * @method mixed setFilesystemEnvAdminNotice(...$arguments)
 * @method mixed setLowMemoryLimitAdminNotice(...$arguments)
 * @method mixed setSessionEnvAdminNotice(...$arguments)
 * @method mixed setStagedBuildDegradedNotice(...$arguments)
 * @method mixed setStagedBuildHaltNotice(...$arguments)
 * @method mixed setViewBuildCronStuckNotice(...$arguments)
 * @method mixed setViewBuildScheduleFailedNotice(...$arguments)
 * @method mixed setViewDoneHardStaleNotice(...$arguments)
 * @method mixed splitOpenBasedirPaths(...$arguments)
 * @method mixed sqlModeProbeOptionName(...$arguments)
 * @method mixed stageAddPreJoinIndexes(...$arguments)
 * @method mixed stageAddSortIndexes(...$arguments)
 * @method mixed stageCreateBuildTable(...$arguments)
 * @method mixed stageInsertRedirectsBatched(...$arguments)
 * @method mixed stageNoProgressStreakOptionName(...$arguments)
 * @method mixed stageRenameSwap(...$arguments)
 * @method mixed stageSkipOptionName(...$arguments)
 * @method mixed stageUpdateExternal(...$arguments)
 * @method mixed stageUpdateHits(...$arguments)
 * @method mixed stageUpdateHome(...$arguments)
 * @method mixed stageUpdatePostsBatched(...$arguments)
 * @method mixed stageUpdateSpecial(...$arguments)
 * @method mixed stageUpdateTermsBatched(...$arguments)
 * @method mixed stagedQueryOptions(...$arguments)
 * @method mixed stagedTableExists(...$arguments)
 * @method mixed stampStartedWatermarksAtS1Entry(...$arguments)
 * @method mixed sweepStaleRebuildTransients(...$arguments)
 * @method mixed transientFallbackLockOptionName(...$arguments)
 * @method mixed verifyBuildLockSerializesWriter(...$arguments)
 * @method mixed verifyOptionWriteCoherent(...$arguments)
 * @method mixed verifyPrefixUnchangedSinceStageOne(...$arguments)
 * @method mixed viewBuildBatchSize(...$arguments)
 * @method mixed viewBuildBatchSizeForStage(...$arguments)
 * @method mixed viewBuildPerStageBudgetSeconds(...$arguments)
 * @method mixed viewBuildTableName(...$arguments)
 * @method mixed viewDeletemeTableName(...$arguments)
 * @method mixed viewDoneBuiltAt(...$arguments)
 * @method mixed viewDoneBuiltWatermark(...$arguments)
 * @method mixed viewDoneDataBuiltAt(...$arguments)
 * @method mixed viewDoneDataBuiltAtOptionName(...$arguments)
 * @method mixed viewDoneFreshnessOptionName(...$arguments)
 * @method mixed viewDoneHasRows(...$arguments)
 * @method mixed viewDoneIsFresh(...$arguments)
 * @method mixed viewDoneIsServeable(...$arguments)
 * @method mixed viewDoneMutationInvalidatedAt(...$arguments)
 * @method mixed viewDoneMutationInvalidatedAtOptionName(...$arguments)
 * @method mixed viewDoneTableExists(...$arguments)
 * @method mixed viewDoneTableName(...$arguments)
 * @method mixed writeProgressOption(...$arguments)
 * @method mixed writeWatermarkOption(...$arguments)
 */
abstract class ABJ_404_Solution_ViewBuildCollaborator {

    /** @var ABJ_404_Solution_ViewBuildOrchestrator */
    protected $host;

    public function __construct(ABJ_404_Solution_ViewBuildOrchestrator $host) {
        $this->host = $host;
    }

    /**
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        return $this->host->__call($name, $arguments);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name) {
        return $this->host->__get($name);
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, $value): void {
        $this->host->__set($name, $value);
    }
}
