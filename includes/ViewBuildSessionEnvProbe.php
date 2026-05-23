<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MySQL-session environment probe for the staged view-build pipeline.
 *
 * Read-and-warn-only check of operational + DDL-safety MySQL session
 * variables that can silently degrade or break a staged view-build but
 * are not severe enough to halt it. Each out-of-range variable produces
 * one warning-level log line and contributes to a single consolidated
 * deduplicated admin notice (per the task spec: "One concise warning per
 * S1 entry -- no per-stage spam").
 *
 * Sibling to ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait. Extracted
 * from that trait to keep both files under the project's 1500-line cap.
 * The public probe method probeSessionVariablesAtS1Entry() is the entry
 * point called from runStagedBuildOnce() right after the existing
 * probeSqlModeForBuild() call.
 *
 * Variables covered (P3 + P4 ride-along, see task brief):
 *   - innodb_lock_wait_timeout (< 30s)
 *   - tmp_table_size + max_heap_table_size (< 16M)
 *   - slow_query_log + long_query_time (log on AND threshold very low)
 *   - innodb_buffer_pool_size (< 256M)
 *   - wait_timeout + interactive_timeout (< 600s; orphan-table cause)
 *   - innodb_flush_method (== O_DSYNC)
 *   - character_set_server / collation_server (not utf8mb4)
 *   - sql_require_primary_key (ON; future CREATE TABLE breaks)
 *   - innodb_file_per_table (OFF; tablespace bloat / un-reclaimable DROP)
 *   - thread_stack + open_files_limit (very low)
 *   - innodb_online_alter_log_max_size (small; S3/S10 ALTER risk)
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
class ABJ_404_Solution_ViewBuildSessionEnvProbe extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Cached session-variables probe result for the current request. Holds
     * the raw values pulled via SHOW VARIABLES at S1 entry plus the per-key
     * out-of-range flags so callers can render a single consolidated warning
     * without re-querying.
     *
     * @var array<string,mixed>|null
     */
    private $sessionVariablesProbeCache = null;

    /** @return string  Option name for the persisted session-variables probe. */
    public function sessionVariablesProbeOptionName(): string {
        return 'abj404_view_build_session_env_probe';
    }

    /**
     * Read an int from a probe values map, returning 0 when the value is
     * non-numeric. Avoids the (int) cast on `mixed` which PHPStan level 9
     * flags as `cast.int`.
     *
     * @param array<string,mixed> $values
     */
    private static function probeIntFromValues(array $values, string $key): int {
        $v = $values[$key] ?? 0;
        return is_numeric($v) ? (int)$v : 0;
    }

    /**
     * Read a float from a probe values map, returning 0.0 when non-numeric.
     *
     * @param array<string,mixed> $values
     */
    private static function probeFloatFromValues(array $values, string $key): float {
        $v = $values[$key] ?? 0;
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /**
     * Read a string from a probe values map, returning '' when not scalar.
     *
     * @param array<string,mixed> $values
     */
    private static function probeStringFromValues(array $values, string $key): string {
        $v = $values[$key] ?? '';
        return is_scalar($v) ? (string)$v : '';
    }

    /**
     * Probe the live MySQL session for operational + DDL-safety variables
     * that can silently degrade or break the staged view-build pipeline.
     * Read-and-warn-only: never throws, never blocks the build. Logs each
     * out-of-range variable at warning level (per defensive philosophy §8 --
     * infrastructure issues the plugin can degrade past) and surfaces ONE
     * consolidated admin notice per 24h on the plugin's own admin screen.
     *
     * Idempotent within a request: repeat calls return the cached result.
     * Cleared by clearSessionVariablesProbeCache() (chained from
     * clearSqlModeProbeCache) on a fresh build.
     *
     * Filterable via `apply_filters('abj404_session_env_probe', $defaults)`
     * so tests and operators can simulate a constrained host without
     * mutating the live MySQL session.
     *
     * @return array<string,mixed>
     */
    public function probeSessionVariablesAtS1Entry(): array {
        if (is_array($this->sessionVariablesProbeCache)) {
            return $this->sessionVariablesProbeCache;
        }

        $defaults = array(
            'innodb_lock_wait_timeout'         => 0,
            'tmp_table_size'                   => 0,
            'max_heap_table_size'              => 0,
            'slow_query_log'                   => 0,
            'long_query_time'                  => 0.0,
            'innodb_buffer_pool_size'          => 0,
            'wait_timeout'                     => 0,
            'interactive_timeout'              => 0,
            'innodb_flush_method'              => '',
            'character_set_server'             => '',
            'collation_server'                 => '',
            'sql_require_primary_key'          => '',
            'innodb_file_per_table'            => '',
            'thread_stack'                     => 0,
            'open_files_limit'                 => 0,
            'innodb_online_alter_log_max_size' => 0,
            'probe_succeeded'                  => false,
        );

        $row = $this->fetchSessionVariablesRowOrEmpty();
        $values = $defaults;
        if (!empty($row)) {
            $values['probe_succeeded'] = true;
            foreach ($row as $k => $v) {
                $klow = strtolower((string)$k);
                if (!array_key_exists($klow, $defaults)) { continue; }
                if ($klow === 'long_query_time') {
                    $values[$klow] = is_scalar($v) ? (float)$v : 0.0;
                } elseif (is_int($defaults[$klow])) {
                    $values[$klow] = is_scalar($v) ? (int)$v : 0;
                } else {
                    $values[$klow] = is_scalar($v) ? (string)$v : '';
                }
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_session_env_probe', $values);
            if (is_array($filtered)) {
                $values = array_merge($values, $filtered);
            }
        }

        $warnings = $this->classifySessionVariableWarnings($values);
        $values['warnings'] = $warnings;

        foreach ($warnings as $w) {
            if (is_scalar($w)) {
                $this->logger->warn('[staged] ' . (string)$w);
            }
        }
        if (!empty($warnings)) {
            $this->setSessionEnvAdminNotice($warnings);
        }

        if (function_exists('update_option')) {
            update_option($this->sessionVariablesProbeOptionName(), $values, false);
        }

        $this->sessionVariablesProbeCache = $values;
        return $values;
    }

    /**
     * Single-query SHOW VARIABLES read for every name we care about. Returns
     * a key=>value map keyed by lowercase Variable_name (case-insensitive
     * driver tolerance per defensive philosophy §5). Empty on probe failure.
     *
     * @return array<string,string>
     */
    public function fetchSessionVariablesRowOrEmpty(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return array();
        }
        /** @var \wpdb $wpdb */
        $names = array(
            'innodb_lock_wait_timeout',
            'tmp_table_size',
            'max_heap_table_size',
            'slow_query_log',
            'long_query_time',
            'innodb_buffer_pool_size',
            'wait_timeout',
            'interactive_timeout',
            'innodb_flush_method',
            'character_set_server',
            'collation_server',
            'sql_require_primary_key',
            'innodb_file_per_table',
            'thread_stack',
            'open_files_limit',
            'innodb_online_alter_log_max_size',
        );
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $sql = "SHOW SESSION VARIABLES WHERE Variable_name IN ($placeholders)";
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            $prepared = method_exists($wpdb, 'prepare') ? $wpdb->prepare($sql, $names) : $sql;
            // DAO-bypass-approved: read-only probe of @@SESSION on this connection.
            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } catch (\Throwable $e) { // allow-silent-catch: probe is best-effort; suppress restored below
            $rows = null;
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }

        $out = array();
        if (!is_array($rows)) { return $out; }
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $name = '';
            $value = '';
            foreach ($row as $k => $v) {
                $klow = strtolower((string)$k);
                if ($klow === 'variable_name' && is_scalar($v)) { $name = strtolower((string)$v); }
                if ($klow === 'value' && is_scalar($v))         { $value = (string)$v; }
            }
            if ($name !== '') { $out[$name] = $value; }
        }
        return $out;
    }

    /**
     * Apply the SESSION_PROBE_THRESHOLDS to a value map and return a flat
     * list of human-readable warning strings (one per out-of-range variable).
     * Pure / side-effect free so tests can assert classification independently
     * from logging and admin-notice surfacing.
     *
     * @param array<string,mixed> $values
     * @return array<int,string>
     */
    public function classifySessionVariableWarnings(array $values): array {
        $warnings = array();
        $t = ABJ_404_Solution_ViewBuildConfig::SESSION_PROBE_THRESHOLDS;

        $iLockWait = self::probeIntFromValues($values, 'innodb_lock_wait_timeout');
        if ($iLockWait > 0 && $iLockWait < $t['innodb_lock_wait_timeout_min']) {
            $warnings[] = sprintf(
                'innodb_lock_wait_timeout=%ds (< %ds); the staged build may abort '
                . 'with "Lock wait timeout exceeded" on busy hosts.',
                $iLockWait, $t['innodb_lock_wait_timeout_min']
            );
        }

        $tmpTable = self::probeIntFromValues($values, 'tmp_table_size');
        $maxHeap = self::probeIntFromValues($values, 'max_heap_table_size');
        if ($tmpTable > 0 && $tmpTable < $t['tmp_table_size_min']) {
            $warnings[] = sprintf(
                'tmp_table_size=%d (< %d MB); MySQL will spill GROUP BY work to '
                . 'disk earlier and the S9 hits aggregate may slow significantly.',
                $tmpTable, (int)($t['tmp_table_size_min'] / 1048576)
            );
        }
        if ($maxHeap > 0 && $maxHeap < $t['max_heap_table_size_min']) {
            $warnings[] = sprintf(
                'max_heap_table_size=%d (< %d MB); MEMORY-engine temp tables will '
                . 'truncate or spill earlier than expected.',
                $maxHeap, (int)($t['max_heap_table_size_min'] / 1048576)
            );
        }

        $rawSlow = $values['slow_query_log'] ?? '';
        $rawSlowStr = is_scalar($rawSlow) ? (string)$rawSlow : '';
        $slowOn = (is_numeric($rawSlow) && (int)$rawSlow > 0)
            || strtoupper($rawSlowStr) === 'ON';
        $longTime = self::probeFloatFromValues($values, 'long_query_time');
        if ($slowOn && $longTime > 0 && $longTime < $t['long_query_time_min']) {
            $warnings[] = sprintf(
                'slow_query_log=ON with long_query_time=%.3fs (< %.1fs); the staged '
                . 'build will flood the slow log with stage-batch queries.',
                $longTime, (float)$t['long_query_time_min']
            );
        }

        $bufferPool = self::probeIntFromValues($values, 'innodb_buffer_pool_size');
        if ($bufferPool > 0 && $bufferPool < $t['innodb_buffer_pool_size_min']) {
            $warnings[] = sprintf(
                'innodb_buffer_pool_size=%d (< %d MB); large logsv2 reads at S2/S9 '
                . 'will thrash the buffer pool.',
                $bufferPool, (int)($t['innodb_buffer_pool_size_min'] / 1048576)
            );
        }

        $waitTimeout = self::probeIntFromValues($values, 'wait_timeout');
        $interactiveTimeout = self::probeIntFromValues($values, 'interactive_timeout');
        if ($waitTimeout > 0 && $waitTimeout < $t['wait_timeout_min']) {
            $warnings[] = sprintf(
                'wait_timeout=%ds (< %ds); the build connection can drop mid-stage '
                . 'leaving the buffer table orphaned (cf. orphan-table cleanup at runner startup).',
                $waitTimeout, $t['wait_timeout_min']
            );
        }
        if ($interactiveTimeout > 0 && $interactiveTimeout < $t['interactive_timeout_min']) {
            $warnings[] = sprintf(
                'interactive_timeout=%ds (< %ds); same orphan-table risk as wait_timeout.',
                $interactiveTimeout, $t['interactive_timeout_min']
            );
        }

        $flush = strtoupper(self::probeStringFromValues($values, 'innodb_flush_method'));
        if ($flush === 'O_DSYNC') {
            $warnings[] = 'innodb_flush_method=O_DSYNC; this is the slowest flush '
                . 'mode and large stage writes will be much slower than O_DIRECT.';
        }

        $charset = strtolower(self::probeStringFromValues($values, 'character_set_server'));
        $collation = strtolower(self::probeStringFromValues($values, 'collation_server'));
        if ($charset !== '' && strpos($charset, 'utf8mb4') !== 0) {
            $warnings[] = sprintf(
                'character_set_server=%s (not utf8mb4); 4-byte characters in URLs '
                . 'will be truncated or rejected by the server default.',
                $charset
            );
        }
        if ($collation !== '' && strpos($collation, 'utf8mb4') !== 0) {
            $warnings[] = sprintf(
                'collation_server=%s (not utf8mb4); 4-byte characters in URLs '
                . 'may sort or compare unexpectedly.',
                $collation
            );
        }

        $requirePk = strtoupper(self::probeStringFromValues($values, 'sql_require_primary_key'));
        if ($requirePk === 'ON' || $requirePk === '1') {
            $warnings[] = 'sql_require_primary_key=ON; future CREATE TABLE without '
                . 'a primary key will be rejected by the server.';
        }

        $filePerTable = strtoupper(self::probeStringFromValues($values, 'innodb_file_per_table'));
        if ($filePerTable === 'OFF' || $filePerTable === '0') {
            $warnings[] = 'innodb_file_per_table=OFF; new InnoDB tables share the '
                . 'system tablespace and cannot be reclaimed by DROP.';
        }

        $threadStack = self::probeIntFromValues($values, 'thread_stack');
        if ($threadStack > 0 && $threadStack < $t['thread_stack_min']) {
            $warnings[] = sprintf(
                'thread_stack=%d bytes (< %dK); deeply nested SQL may exhaust '
                . 'the connection thread stack.',
                $threadStack, (int)($t['thread_stack_min'] / 1024)
            );
        }
        $openFiles = self::probeIntFromValues($values, 'open_files_limit');
        if ($openFiles > 0 && $openFiles < $t['open_files_limit_min']) {
            $warnings[] = sprintf(
                'open_files_limit=%d (< %d); high-concurrency table opens may '
                . 'fail with "Too many open files".',
                $openFiles, $t['open_files_limit_min']
            );
        }

        $alterLog = self::probeIntFromValues($values, 'innodb_online_alter_log_max_size');
        if ($alterLog > 0 && $alterLog < $t['innodb_online_alter_log_max_size_min']) {
            $warnings[] = sprintf(
                'innodb_online_alter_log_max_size=%d (< %d MB); the S3 / S10 '
                . 'ALTER TABLE ADD INDEX may fail with "Online DDL log overflow" '
                . 'on busy hosts.',
                $alterLog, (int)($t['innodb_online_alter_log_max_size_min'] / 1048576)
            );
        }

        return $warnings;
    }

    /**
     * Surface a single deduplicated admin notice consolidating the session
     * variable warnings (per the task spec: one concise warning per S1 entry,
     * no per-stage spam). Dedup window matches the existing self-healing
     * notice TTL (24h).
     *
     * @param array<int,string> $warnings
     * @return void
     */
    public function setSessionEnvAdminNotice(array $warnings): void {
        $key = 'abj404_view_build_session_env_notice';
        $payload = array(
            'kind'     => 'session_env',
            'warnings' => $warnings,
            'message'  => 'The 404 Solution view-build pipeline detected MySQL '
                . 'session-variable settings that may degrade the next rebuild: '
                . implode(' | ', $warnings),
            'when'     => $this->clock()->now(),
        );
        $dedupKey = 'abj404_view_build_session_env_dedup';
        if (function_exists('get_transient') && get_transient($dedupKey) !== false) {
            return;
        }
        if (function_exists('set_transient')) {
            // allow-cache-empty: session-environment warning marker intentionally stores diagnostics, not query data.
            set_transient(
                $key,
                $payload,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
            set_transient(
                $dedupKey,
                1,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        } elseif (function_exists('update_option')) {
            update_option($key, $payload, false);
        }
    }

    /** @return void */
    public function clearSessionVariablesProbeCache(): void {
        $this->sessionVariablesProbeCache = null;
        if (function_exists('delete_option')) {
            delete_option($this->sessionVariablesProbeOptionName());
        }
        if (function_exists('delete_transient')) {
            delete_transient('abj404_view_build_session_env_dedup');
        }
    }
}
