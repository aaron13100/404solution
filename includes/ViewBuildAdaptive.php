<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adaptive-runtime helpers for the staged view-build pipeline.
 *
 * Two responsibilities, both keyed off behavior the build only learns at
 * runtime on the actual host:
 *
 *   1. Adaptive batch size: when a host kills a batched stage's per-query
 *      (max_statement_time exceeded, lock-wait, gone-away), halve the
 *      batch size for that stage and persist it. Subsequent ticks of the
 *      same build use the smaller size, so a slow shared host eventually
 *      converges to a batch size it can actually finish.
 *
 *   2. Intelligent per-query timeout: probe the host's session-level
 *      max_statement_time (MariaDB) or max_execution_time (MySQL) once
 *      per request, then size our own per-query SET STATEMENT hint to
 *      fire just before the host's silent kill would. This converts a
 *      generic connection drop into a clean classifiable kill the
 *      pipeline can resume from.
 *
 * Sibling to ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait; both are
 * mixed into ABJ_404_Solution_DataAccess. Private members declared here
 * are visible to the staged-build trait inside the composing class.
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
class ABJ_404_Solution_ViewBuildAdaptive extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Request-lifetime cache of the host's per-statement timeout, in
     * seconds. -1 means "not yet probed", 0 means "no host limit", > 0
     * is the limit the host enforces. Probed lazily by
     * detectHostStagedQueryLimitSeconds().
     *
     * @var float
     */
    private $hostStagedQueryLimitSecondsCache = -1.0;

    /**
     * Per-stage batch size with adaptive-shrink memory. Reads the
     * persisted shrink option for this stage (s2_batch_size /
     * s4_batch_size / s5_batch_size); falls back to the global default
     * when none is set. The persisted value survives across requests so
     * a host that has already shown it cannot handle 2000-row batches
     * keeps using the smaller size for the rest of the build.
     *
     * @param string $stageShortKey  One of 's2_batch_size', 's4_batch_size', 's5_batch_size'.
     * @return int  Always >= VIEW_BUILD_MIN_BATCH_SIZE.
     */
    public function viewBuildBatchSizeForStage(string $stageShortKey): int {
        $defaultSize = $this->viewBuildBatchSize();
        $persisted = $this->readProgressOption($stageShortKey, 0);
        $effective = $persisted > 0 ? $persisted : $defaultSize;
        return max(ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_MIN_BATCH_SIZE, $effective);
    }

    /**
     * Record that a batch in stage $stageShortKey was killed by the host
     * (resumable kill class: max_statement_time exceeded, gone-away,
     * lock-wait). Halves the batch size, floors at
     * VIEW_BUILD_MIN_BATCH_SIZE, persists. Subsequent ticks pick up the
     * smaller size via viewBuildBatchSizeForStage().
     *
     * @param string $stageShortKey
     * @return int  the new batch size.
     */
    public function recordStageBatchKilled(string $stageShortKey): int {
        $current = $this->viewBuildBatchSizeForStage($stageShortKey);
        $shrunk = (int)max(
            ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_MIN_BATCH_SIZE,
            (int)floor($current / 2)
        );
        $this->writeProgressOption($stageShortKey, $shrunk);
        return $shrunk;
    }

    /**
     * Seconds of PHP request time remaining before max_execution_time
     * fires. PHP_INT_MAX when no limit is set (CLI / unbounded cron).
     *
     * Used by the batched stages to decide whether there is room to
     * start another batch at our full per-query limit. If not, the stage
     * yields via the wall-clock path (no batch attempt, no shrink) and
     * the next request resumes with a fresh PHP time budget.
     *
     * @return float
     */
    public function phpTimeRemainingSeconds(): float {
        $limit = (int)ini_get('max_execution_time');
        if ($limit <= 0) {
            return (float)PHP_INT_MAX;
        }
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float)$_SERVER['REQUEST_TIME_FLOAT']
            : (float)microtime(true);
        $elapsed = max(0.0, microtime(true) - $start);
        return max(0.0, (float)$limit - $elapsed);
    }

    /**
     * Probe the host for its session-level per-statement timeout. Reads
     * the MariaDB session variable max_statement_time (seconds, decimal)
     * first, then falls back to MySQL max_execution_time (milliseconds).
     * Returns 0.0 when no host limit is set. Cached on the instance for
     * the request lifetime so the build pays the SHOW VARIABLES cost
     * once, not once per stage.
     *
     * @return float  Seconds, or 0.0 for "no host limit".
     */
    public function detectHostStagedQueryLimitSeconds(): float {
        if ($this->hostStagedQueryLimitSecondsCache >= 0.0) {
            return $this->hostStagedQueryLimitSecondsCache;
        }
        $limitSeconds = 0.0;

        $result = $this->queryAndGetResults(
            "SHOW SESSION VARIABLES LIKE 'max_statement_time'",
            array('log_errors' => false)
        );
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (!empty($rows) && is_array($rows[0])) {
            $value = $rows[0]['Value'] ?? ($rows[0]['value'] ?? null);
            if ($value !== null && is_numeric($value) && (float)$value > 0.0) {
                $limitSeconds = (float)$value;
            }
        }

        if ($limitSeconds <= 0.0) {
            $result = $this->queryAndGetResults(
                "SHOW SESSION VARIABLES LIKE 'max_execution_time'",
                array('log_errors' => false)
            );
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            if (!empty($rows) && is_array($rows[0])) {
                $value = $rows[0]['Value'] ?? ($rows[0]['value'] ?? null);
                if ($value !== null && is_numeric($value) && (int)$value > 0) {
                    $limitSeconds = ((int)$value) / 1000.0;
                }
            }
        }

        $this->hostStagedQueryLimitSecondsCache = max(0.0, $limitSeconds);
        return $this->hostStagedQueryLimitSecondsCache;
    }

    /**
     * Compute the per-query timeout we hint to the database for a
     * single staged-build query. The result is the smallest of:
     *
     *   - our per-stage budget minus a 2s margin (so the hint fires
     *     before PHP max_execution_time can interrupt the request)
     *   - the host max_statement_time minus 1s (so the hint fires
     *     before the host silent kill, giving us a clean classifiable
     *     error rather than a dropped connection)
     *
     * Floored at 1s. The whole point of the function is to fire OUR
     * kill before the host's; with the prior 5s floor, on hosts with
     * `max_statement_time = 3` we would emit a 5s hint that the host
     * pre-empts at 3s, defeating the classifiable-kill design. 1s is
     * the smallest sensible floor (sub-second queries are noise) but
     * still lets the function honor genuinely-tight host limits.
     * (2026-05-08, deadline-math-audit-2026-05-08.md concern #2.)
     *
     * Our-limit floor stays at 5s: that one represents "this query
     * is so small that the per-stage budget overhead dominates" and
     * has nothing to do with the host kill. The host-limit code path
     * uses 1s.
     *
     * When the host has no limit set, only the per-stage budget applies.
     *
     * @return float  Seconds.
     */
    public function intelligentStagedQueryTimeoutSeconds(): float {
        $ourLimit = max(5.0, (float)$this->viewBuildPerStageBudgetSeconds() - 2.0);
        $hostLimit = $this->detectHostStagedQueryLimitSeconds();
        if ($hostLimit > 0.0) {
            return max(1.0, min($ourLimit, $hostLimit - 1.0));
        }
        return $ourLimit;
    }

    /**
     * Per-query timeout for the next attempt of a non-batched stage that
     * has been killed at least once already. When the persisted streak is
     * 0 (no prior kill on this stage in the current build) returns the
     * normal intelligent timeout; otherwise returns an extended timeout
     * that intentionally overrides the host's session max_statement_time.
     *
     * The override works because MariaDB 10.1+ honors
     * `SET STATEMENT max_statement_time=N FOR <query>` even when N is
     * larger than the session limit: SET STATEMENT scopes the override
     * to the wrapped statement only. Without this, S3 / S9 / S10 -- the
     * non-batched stages -- would hit the host limit and retry with the
     * same timeout forever, looping with no escape valve (the batched
     * stages have one in the form of adaptive batch shrink; non-batched
     * stages don't, so we extend in the time dimension instead).
     *
     * Bounded by:
     *   - VIEW_BUILD_NON_BATCHED_KILL_RETRY_CAP_SECONDS (absolute ceiling)
     *   - phpTimeRemainingSeconds() - 2.0 (so the request returns inside
     *     PHP max_execution_time even if the query still gets killed)
     *   - max(1.0, ...) so we never ship a non-positive hint
     *
     * @param string $stageKillStreakOptKey  Progress option key name,
     *   e.g. 's3_kill_streak'. Production callers register the key in
     *   the staged trait's progress option name map.
     * @return int  Seconds.
     */
    public function extendedTimeoutForKilledNonBatchedStage(string $stageKillStreakOptKey): int {
        $streak = $this->readProgressOption($stageKillStreakOptKey, 0);
        if ($streak <= 0) {
            return (int)round($this->intelligentStagedQueryTimeoutSeconds());
        }
        $cap = (float)ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_NON_BATCHED_KILL_RETRY_CAP_SECONDS;
        $phpRemaining = max(1.0, $this->phpTimeRemainingSeconds() - 2.0);
        return (int)round(max(1.0, min($cap, $phpRemaining)));
    }
}
