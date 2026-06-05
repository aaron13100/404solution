<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-stage callback implementations for the staged view-build pipeline.
 *
 * Each `stage*` method here is invoked from the orchestrator's
 * runStagedBuildOnce() loop in
 * {@see ABJ_404_Solution_ViewBuildStagePipeline}. The stage pipeline
 * owns the `current_stage` progression, per-stage timing/logging, kill-streak
 * escape, and request-scope guards; this trait owns just the per-stage
 * SQL/work performed at each step:
 *
 *   - dropTransientStagedTables / dropDeletemeTable: S0 cleanup.
 *   - stageCreateBuildTable: S1 CREATE with engine fallback.
 *   - stageAddPreJoinIndexes: S3 ALTER TABLE for join indexes.
 *   - stageUpdateHome / stageUpdateExternal / stageUpdateSpecial: S6/S7/S8
 *     non-batched UPDATEs.
 *   - stageUpdateHits: S9 hits aggregation.
 *   - stageAddSortIndexes: S10 ALTER TABLE for sort indexes.
 *   - stageRenameSwap: S11 atomic RENAME TABLE swap to publish the buffer.
 *
 * Resumable S2/S4/S5 batch execution lives on
 * {@see ABJ_404_Solution_ViewBuildBatchExecutor}.
 *
 * Sibling to ABJ_404_Solution_ViewBuildStagePipeline. Properties / helper
 * methods declared on other collaborators (markBuildStage, runStagedSqlFile,
 * viewBuildTableName, stagedQueryOptions, etc.) are reached through the
 * orchestrator host.
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
class ABJ_404_Solution_ViewBuildStageCallbacks extends ABJ_404_Solution_ViewBuildCollaborator {

    /** Drop both the build buffer and the leftover deleteme.  Used on fresh-start only. */
    public function dropTransientStagedTables(): void {
        $buildTempTable = $this->host->viewBuildTableName();
        $deletemeTempTable = $this->host->viewDeletemeTableName();
        $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $buildTempTable . '`',
            array('log_errors' => false));
        $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));
    }

    /** Drop only the deleteme leftover from a prior crashed RENAME swap. */
    public function dropDeletemeTable(): void {
        $deletemeTempTable = $this->host->viewDeletemeTableName();
        $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));
    }

    /**
     * Drop view_build / view_deleteme only if either exists on disk. Gated by
     * SHOW TABLES so a steady-state invalidate (no buffer present, the common
     * case for redirect-edit invalidations) does not pile DROP IF EXISTS DDL
     * on the hot path. Called from the runner-owned force-rebuild primitive
     * (see DataAccessTrait_ViewBuildForceRestart) so the buffer drop is
     * atomic with the progress-option clear.
     *
     * @return void
     */
    public function dropTransientBuffersIfPresent(): void {
        $buildTempTable = $this->host->viewBuildTableName();
        $deletemeTempTable = $this->host->viewDeletemeTableName();
        if ($this->host->stagedTableExists($buildTempTable)) {
            $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $buildTempTable . '`',
                array('log_errors' => false));
        }
        if ($this->host->stagedTableExists($deletemeTempTable)) {
            $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
                array('log_errors' => false));
        }
    }

    /**
     * S1: create the build buffer. Tries the system default storage
     * engine, then falls back to MyISAM, then to InnoDB so it works on
     * hosts that disable one or the other.
     *
     * @return void
     */
    public function stageCreateBuildTable(): void {
        $template = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/sql/createViewBuildTable.sql');
        $base = $this->host->doTableNameReplacements(is_string($template) ? $template : '');
        if (trim($base) === '') {
            throw new \Exception('createViewBuildTable.sql is empty or unreadable.');
        }

        $attempts = array(
            'default' => $base,
            'MyISAM'  => $base . ' ENGINE=MyISAM',
            'InnoDB'  => $base . ' ENGINE=InnoDB',
        );
        $lastError = '';
        $errorsSoFar = array();
        $opts = $this->host->stagedQueryOptions();
        $opts['log_errors'] = false;
        foreach ($attempts as $engineLabel => $sql) {
            $attemptStarted = microtime(true);
            $this->host->logger()->debugMessage(sprintf(
                '[staged] S1 createViewBuildTable attempt starting: engine=%s',
                $engineLabel
            ));
            $result = $this->host->queryAndGetResults($sql, $opts);
            $err = isset($result['last_error']) && is_string($result['last_error'])
                ? trim($result['last_error']) : '';
            $timedOut = !empty($result['timed_out']);
            $elapsedMs = (int)round((microtime(true) - $attemptStarted) * 1000);
            $this->host->logger()->debugMessage(sprintf(
                '[staged] S1 createViewBuildTable attempt finished: engine=%s elapsed_ms=%d timed_out=%s last_error=%s',
                $engineLabel,
                $elapsedMs,
                $timedOut ? 'true' : 'false',
                $err !== '' ? substr($err, 0, 240) : 'none'
            ));
            if ($err === '' && !$timedOut) {
                if ($engineLabel !== 'default') {
                    // Default engine failed but a fallback won. Worth knowing
                    // because hosts that need a fallback often have other
                    // engine-specific quirks downstream (lock waits, ALTER
                    // semantics, etc.).
                    $this->host->logger()->warn(sprintf(
                        '[staged] S1 createViewBuildTable: default engine '
                        . 'failed (%s); succeeded on fallback %s.',
                        substr(implode('; ', $errorsSoFar), 0, 200),
                        $engineLabel
                    ));
                }
                return;
            }
            $lastError = $err !== '' ? $err : 'unknown';
            $errorsSoFar[] = $engineLabel . ': ' . $lastError;
        }
        throw new \Exception('Could not create view build table on any storage engine: ' . $lastError);
    }

    /** @return void */
    public function stageAddPreJoinIndexes(): void {
        // S3 indexes are added with IF NOT EXISTS semantics emulated by
        // catching "Duplicate key name" on retry. See runStagedSqlFile
        // tolerance below.  ALTER TABLE itself is fast on the buffer.
        $this->host->assertBuildBufferExistsOrHalt('S3 stageAddPreJoinIndexes');
        $this->host->runStagedSqlFileTolerantOfDuplicateKey('03_index_fd.sql', array());
    }

    /** @return void */
    public function stageUpdateHome(): void {
        $this->host->assertBuildBufferExistsOrHalt('S6 stageUpdateHome');
        $this->host->runStagedSqlFile('06_update_home.sql', array());
    }

    /** @return void */
    public function stageUpdateExternal(): void {
        $this->host->assertBuildBufferExistsOrHalt('S7 stageUpdateExternal');
        $this->host->runStagedSqlFile('07_update_external.sql', array());
    }

    /** @return void */
    public function stageUpdateSpecial(): void {
        $this->host->assertBuildBufferExistsOrHalt('S8 stageUpdateSpecial');
        $this->host->runStagedSqlFile('08_update_special.sql', $this->host->viewBuildOnlyTranslations());
    }

    /** @return void */
    public function stageUpdateHits(): void {
        $this->host->assertBuildBufferExistsOrHalt('S9 stageUpdateHits');
        $s9Collation = $this->host->resolveColumnCollationForStagedBuild();
        $collationExtra = array('{S9_COLLATION}' => $s9Collation);
        $this->host->runStagedSqlFile('09a_drop_hits_temp.sql', array());
        $this->host->runStagedSqlFile('09b_create_hits_temp.sql', $collationExtra);
        $this->host->runStagedSqlFile('09c_insert_hits_temp.sql', array());
        $this->host->runStagedSqlFile('09_update_hits.sql', $collationExtra);
        $this->host->runStagedSqlFile('09a_drop_hits_temp.sql', array());
    }

    /** @return void */
    public function stageAddSortIndexes(): void {
        $this->host->assertBuildBufferExistsOrHalt('S10 stageAddSortIndexes');
        $this->host->runStagedSqlFileTolerantOfDuplicateKey('10_index_sort.sql', array());
    }

    /**
     * S11: atomic RENAME TABLE swap. Build buffer becomes the new served
     * table; the previous served table (if any) becomes deleteme and is
     * dropped.
     *
     * @return void
     */
    public function stageRenameSwap(): void {
        $this->host->assertBuildBufferExistsOrHalt('S11 stageRenameSwap');
        $buildTempTable = $this->host->viewBuildTableName();
        $done = $this->host->viewDoneTableName();
        $deletemeTempTable = $this->host->viewDeletemeTableName();

        // Defensive: ensure deleteme is gone before the swap (S0 already did
        // this, but a poorly-timed parallel rebuild could have created it).
        $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));

        if ($this->host->viewDoneTableExists()) {
            $sql = 'RENAME TABLE `' . $done . '` TO `' . $deletemeTempTable . '`,'
                 . ' `' . $buildTempTable . '` TO `' . $done . '`';
        } else {
            $sql = 'RENAME TABLE `' . $buildTempTable . '` TO `' . $done . '`';
        }

        $result = $this->host->queryAndGetResults($sql, array('log_errors' => true));
        $err = isset($result['last_error']) && is_string($result['last_error'])
            ? trim($result['last_error']) : '';
        if ($err !== '') {
            throw new \Exception('RENAME TABLE swap failed: ' . $err);
        }

        $this->host->queryAndGetResults('DROP TABLE IF EXISTS `' . $deletemeTempTable . '`',
            array('log_errors' => false));
    }

    /**
     * Pre-stage probe that halts the running stage cleanly when the
     * view_build buffer is missing on disk. A concurrent invalidateViewDone()
     * (redirect edit, plugin upgrade, correctCollations, daily maintenance)
     * can call dropTransientBuffersIfPresent() and remove view_build between
     * the start of a build tick and the next stage callback. Without this
     * probe, the stage's first DDL/DML against view_build would hit
     * queryAndGetResults, which logs the "Table doesn't exist" error at
     * ERROR severity. The dispatcher then uploads it as a real bug report
     * even though it is an expected concurrent-invalidate race (Pattern 13).
     *
     * Three production reports on 2026-05-16 (ids 9/10/11; plugin 4.1.18;
     * sites greyleafmedia.com, myticas.com, p2p-game.com) traced to this
     * shape at S3 (stageAddPreJoinIndexes) and S11 (stageRenameSwap). S2,
     * S4, and S5 had bespoke inline guards already; this helper centralizes
     * the same shape so every stage that touches view_build can opt in by
     * adding one line at the top of its callback.
     *
     * The exception text MUST begin with "Staged view-build buffer missing"
     * so the orchestrator's catch (runTimedViewBuildStage -> classifyStage-
     * Failure in DataAccessTrait_ErrorClassification.php) recognizes the
     * marker and routes the failure to 'resumable' -> resumable_yield ->
     * orchestrator returns false. The next tick reads progress options and
     * either restarts from S0 (if invalidateViewDone cleared them, the
     * normal case) or fails the same check again until floor_kill_streak
     * trips a clean halt notice.
     *
     * Warn-level severity is the correct choice per CLAUDE.md §8
     * ("Infrastructure errors are warnings, not bugs -- unless the plugin
     * can't function"): the build can recover by restarting on the next
     * tick, so it remains functional. The warn line is still visible in
     * debug.log for diagnosis; it just does not trigger the ERROR-level
     * dispatcher upload path.
     *
     * @param string $stageLabel  Human-readable stage tag included in the
     *                            warn line and exception message so the
     *                            failure can be attributed to the exact
     *                            stage callback that detected the race.
     * @throws \Exception Always when the buffer is missing; never throws
     *                   when the buffer is present (silent no-op happy
     *                   path).
     * @return void
     */
    public function assertBuildBufferExistsOrHalt(string $stageLabel): void {
        if ($this->host->stagedTableExists($this->host->viewBuildTableName())) {
            return;
        }
        $this->host->logger()->warn(sprintf(
            '[staged] %s halting: view_build buffer missing on disk. A '
            . 'concurrent invalidateViewDone() drop is the expected cause '
            . '(Pattern 13). Yielding stage; the next tick will rebuild '
            . 'from S0 after progress options are cleared.',
            $stageLabel
        ));
        throw new \Exception(sprintf(
            'Staged view-build buffer missing at %s entry; pipeline state '
            . 'diverged from disk. Halting stage for resume.',
            $stageLabel
        ));
    }
}
