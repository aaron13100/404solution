<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Host-environment probe for the staged view-build pipeline.
 *
 * Detects host-side constraints that silently destabilize the build on
 * hardened shared hosts:
 *
 *   1. `set_time_limit()` in `disable_functions`. The build cannot extend
 *      its time budget mid-stage when the host has revoked it, so the
 *      orchestrator's per-stage budget switches to a tighter cron-tick
 *      mode (yield earlier, rely on the next tick) instead of gambling
 *      on max_execution_time.
 *
 *   2. `memory_limit` below the 128M recommended floor. The S9 hits
 *      aggregate (CREATE TEMPORARY + INSERT ... GROUP BY across logsv2)
 *      can OOM on busy sites with a small PHP-side fetch buffer. Since
 *      `ini_set('memory_limit', ...)` is often blocked, the probe
 *      surfaces a deduplicated admin notice instead of silently failing.
 *
 *   3. Filesystem temp-path constraints and MySQL session variables that
 *      make S1/S9/S10 fragile on shared hosts.
 *
 * Admin notice persistence is delegated to
 * ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy so probe
 * classification stays separate from user-visible side effects.
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
 * @method bool forceRestartViewBuild(...$arguments)
 * @method bool foregroundViewBuildLeaseActive(...$arguments)
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
 * @method bool optionReadBackMatches(...$arguments)
 * @method void performFreshStartCleanup(...$arguments)
 * @method float phpTimeRemainingSeconds(...$arguments)
 * @method string prefixAtStageOneOptionName(...$arguments)
 * @method array<mixed> probeSqlModeForBuild(...$arguments)
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
 * @method void setStagedBuildDegradedNotice(...$arguments)
 * @method void setStagedBuildHaltNotice(...$arguments)
 * @method void setViewBuildCronStuckNotice(...$arguments)
 * @method void setViewBuildScheduleFailedNotice(...$arguments)
 * @method void setViewDoneHardStaleNotice(...$arguments)
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
class ABJ_404_Solution_ViewBuildHostEnvironmentProbe extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy */
    private $noticePolicy;

    /**
     * Cached PHP-environment probe result for the current request:
     * function_exists('set_time_limit') AND not in disable_functions, plus
     * memory_limit parsed to bytes. Populated on first call to
     * probePhpEnvironmentForBuild() and consumed by
     * viewBuildPerStageBudgetSeconds() to switch into a tighter cron-tick
     * budget when set_time_limit cannot extend the request mid-stage.
     *
     * @var array<string,mixed>|null
     */
    private $phpEnvironmentProbeCache = null;

    /**
     * Cached filesystem probe result for the current request.
     *
     * @var array<string,mixed>|null
     */
    private $filesystemEnvironmentProbeCache = null;

    /**
     * Cached session-variables probe result for the current request.
     *
     * @var array<string,mixed>|null
     */
    private $sessionVariablesProbeCache = null;

    public function __construct(ABJ_404_Solution_ViewBuildOrchestrator $host) {
        parent::__construct($host);
        $this->noticePolicy = new ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy($host);
    }

    /** @return string  Option name for the persisted PHP environment probe. */
    public function phpEnvironmentProbeOptionName(): string {
        return 'abj404_view_build_php_env_probe';
    }

    /**
     * Probe the PHP runtime for environmental constraints that affect the
     * staged view build:
     *
     *   - `set_time_limit()` in `disable_functions`: the build cannot extend
     *     its time budget mid-stage on hardened shared hosts. The orchestrator
     *     consumes this flag in viewBuildPerStageBudgetSeconds() to yield
     *     earlier and rely on the next cron tick.
     *
     *   - `memory_limit` below the 128M recommended floor: the S9 hits
     *     aggregate (CREATE TEMPORARY + INSERT ... GROUP BY across logsv2)
     *     can OOM on busy sites. We cannot bump memory_limit at runtime on
     *     hardened hosts, so surface a deduplicated admin notice instead.
     *
     * Side effects: persists the probe result to an option for post-mortem
     * dashboards, and surfaces a low-memory admin notice (one per 24h via
     * transient dedup) when the floor check fails. Idempotent within a
     * request -- repeat calls return the cached array without re-probing.
     *
     * Filterable via `apply_filters('abj404_php_env_probe', $defaults)` so
     * tests and operators can simulate disable_functions / low memory_limit
     * without mutating the running PHP process. Filter callers may add or
     * widen keys, so the return type is the loose `array<string,mixed>`.
     * Internally guaranteed keys: set_time_limit_available (bool),
     * memory_limit_raw (string), memory_limit_bytes (int), memory_limit_low
     * (bool).
     *
     * @return array<string,mixed>
     */
    public function probePhpEnvironmentForBuild(): array {
        if (is_array($this->phpEnvironmentProbeCache)) {
            return $this->phpEnvironmentProbeCache;
        }

        $rawMemory = (string)ini_get('memory_limit');
        $memoryBytes = $this->parsePhpMemoryLimitToBytes($rawMemory);

        $disabled = $this->phpDisabledFunctionsList();
        $setTimeLimitAvailable = function_exists('set_time_limit')
            && !in_array('set_time_limit', $disabled, true);

        $result = array(
            'set_time_limit_available' => $setTimeLimitAvailable,
            'memory_limit_raw'         => $rawMemory,
            'memory_limit_bytes'       => $memoryBytes,
            // memory_limit_bytes == 0 means unlimited (-1 in php.ini), which
            // is fine and is NOT "low".
            'memory_limit_low'         => ($memoryBytes > 0
                && $memoryBytes < ABJ_404_Solution_ViewBuildConfig::PHP_MEMORY_LIMIT_RECOMMENDED_BYTES),
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_php_env_probe', $result);
            if (is_array($filtered)) {
                $result = array_merge($result, $filtered);
            }
        }

        if (empty($result['set_time_limit_available'])) {
            $this->logger->infoMessage(
                '[staged] set_time_limit() unavailable (disable_functions); '
                . 'switching to tighter cron-tick budget mode.'
            );
        }
        if (!empty($result['memory_limit_low'])) {
            $resultMemoryBytes = isset($result['memory_limit_bytes']) && is_numeric($result['memory_limit_bytes'])
                ? (int)$result['memory_limit_bytes'] : 0;
            $this->noticePolicy->setLowMemoryLimitAdminNotice($resultMemoryBytes);
        }

        if (function_exists('update_option')) {
            update_option($this->phpEnvironmentProbeOptionName(), $result, false);
        }

        $this->phpEnvironmentProbeCache = $result;
        return $result;
    }

    /**
     * Contract alias: returns just the boolean used by the env-failure tests.
     * Keeps the public surface compact for callers that only need the flag.
     *
     * @return bool
     */
    public function probeSetTimeLimitAvailability(): bool {
        $probe = $this->probePhpEnvironmentForBuild();
        return !empty($probe['set_time_limit_available']);
    }

    /**
     * Contract alias: returns memory_limit in bytes (0 == unlimited) so
     * callers can choose chunking vs. skip without re-parsing the ini value.
     *
     * @return int
     */
    public function probeMemoryLimitForS9(): int {
        $probe = $this->probePhpEnvironmentForBuild();
        $bytes = $probe['memory_limit_bytes'] ?? 0;
        return is_numeric($bytes) ? (int)$bytes : 0;
    }

    /**
     * Parse a php.ini-style memory size (`128M`, `1G`, `262144`, `-1`) into
     * raw bytes. Returns 0 for "unlimited" (-1) or unparseable input.
     *
     * @param string $raw
     * @return int
     */
    public function parsePhpMemoryLimitToBytes(string $raw): int {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1' || $raw === '0') {
            return 0;
        }
        $unit = strtoupper(substr($raw, -1));
        $num = (int)$raw;
        if ($num <= 0) {
            return 0;
        }
        switch ($unit) {
            case 'G': return $num * 1073741824;
            case 'M': return $num * 1048576;
            case 'K': return $num * 1024;
            default:
                return is_numeric($raw) ? (int)$raw : 0;
        }
    }

    /**
     * @return array<int,string>  Trimmed list of names from ini disable_functions.
     */
    public function phpDisabledFunctionsList(): array {
        $raw = (string)ini_get('disable_functions');
        if ($raw === '') {
            return array();
        }
        $names = array_map('trim', explode(',', $raw));
        return array_values(array_filter($names, function ($n) { return $n !== ''; }));
    }

    /**
     * Format a byte count as a php.ini-style suffix string for admin notices.
     *
     * @param int $bytes
     * @return string
     */
    public function formatPhpMemoryBytesHuman(int $bytes): string {
        if ($bytes <= 0) {
            return 'unlimited';
        }
        if ($bytes >= 1073741824) {
            $g = $bytes / 1073741824;
            return ($g == (int)$g ? (string)(int)$g : number_format($g, 1)) . 'G';
        }
        if ($bytes >= 1048576) {
            return (string)(int)round($bytes / 1048576) . 'M';
        }
        if ($bytes >= 1024) {
            return (string)(int)round($bytes / 1024) . 'K';
        }
        return (string)$bytes;
    }

    /** @return void */
    public function clearPhpEnvironmentProbeCache(): void {
        $this->phpEnvironmentProbeCache = null;
        $this->filesystemEnvironmentProbeCache = null;
        if (function_exists('delete_option')) {
            delete_option($this->phpEnvironmentProbeOptionName());
            delete_option($this->filesystemEnvironmentProbeOptionName());
        }
        $this->noticePolicy->clearPhpAndFilesystemEnvironmentNotices();
    }

    /** @return string */
    public function filesystemEnvironmentProbeOptionName(): string {
        return 'abj404_view_build_fs_env_probe';
    }

    /**
     * Probe filesystem-side host constraints that can silently degrade or
     * abort the staged view-build pipeline:
     *
     *   - `open_basedir` set and our tmp/upload paths fall outside it: any
     *     `disk_free_space()` / fopen() against those paths returns false
     *     and the build cannot diagnose why.
     *   - `upload_tmp_dir` outside open_basedir: same constraint.
     *   - `@@tmpdir` (MySQL temp dir) on a near-full volume: S9 hits aggregate
     *     can fail with "table is full" or "No space left on device" when the
     *     temp file the optimizer materializes for the GROUP BY exceeds free
     *     bytes.
     *
     * Read-and-warn-only: never throws, never blocks the build. Logs at
     * warning level (per defensive philosophy §8 -- infrastructure issues the
     * plugin can degrade past) and surfaces a deduplicated admin notice so
     * the operator can ask the host to widen open_basedir or clear disk space
     * before the next build attempt.
     *
     * Filterable via `apply_filters('abj404_filesystem_env_probe', $defaults)`
     * so tests and operators can simulate hardened-host scenarios without
     * mutating the running PHP / MySQL process.
     *
     * @return array<string,mixed>
     */
    public function probeFilesystemEnvironmentForBuild(): array {
        if (is_array($this->filesystemEnvironmentProbeCache)) {
            return $this->filesystemEnvironmentProbeCache;
        }

        $rawOpenBasedir = (string)ini_get('open_basedir');
        $rawUploadTmpDir = (string)ini_get('upload_tmp_dir');
        $sysTmpDir = function_exists('sys_get_temp_dir') ? (string)sys_get_temp_dir() : '';

        $pluginTmpCandidates = array_filter(array(
            $sysTmpDir,
            $rawUploadTmpDir,
        ), function ($p) { return $p !== ''; });

        $openBasedirPaths = $this->splitOpenBasedirPaths($rawOpenBasedir);

        $tmpOutsideOpenBasedir = false;
        $uploadTmpOutsideOpenBasedir = false;
        if (!empty($openBasedirPaths)) {
            foreach ($pluginTmpCandidates as $candidate) {
                if (!$this->pathFallsWithinAny($candidate, $openBasedirPaths)) {
                    $tmpOutsideOpenBasedir = true;
                    break;
                }
            }
            if ($rawUploadTmpDir !== ''
                && !$this->pathFallsWithinAny($rawUploadTmpDir, $openBasedirPaths)) {
                $uploadTmpOutsideOpenBasedir = true;
            }
        }

        $tmpDirForCheck = $rawUploadTmpDir !== '' ? $rawUploadTmpDir : $sysTmpDir;
        $tmpFreeBytes = $this->probeTmpFreeBytes($tmpDirForCheck, $openBasedirPaths);
        $tmpDiskLow = ($tmpFreeBytes >= 0 && $tmpFreeBytes < ABJ_404_Solution_ViewBuildConfig::PHP_TMPDIR_FREE_FLOOR_BYTES);
        $tmpWriteProbe = $this->probeTmpWritable($tmpDirForCheck, $openBasedirPaths);

        $result = array(
            'open_basedir_raw'                 => $rawOpenBasedir,
            'open_basedir_paths'               => $openBasedirPaths,
            'upload_tmp_dir_raw'               => $rawUploadTmpDir,
            'sys_tmp_dir'                      => $sysTmpDir,
            'tmp_outside_open_basedir'         => $tmpOutsideOpenBasedir,
            'upload_tmp_outside_open_basedir'  => $uploadTmpOutsideOpenBasedir,
            'tmp_free_bytes'                   => $tmpFreeBytes,
            'tmp_disk_low'                     => $tmpDiskLow,
            'tmp_disk_floor_bytes'             => ABJ_404_Solution_ViewBuildConfig::PHP_TMPDIR_FREE_FLOOR_BYTES,
            'tmp_writable'                     => $tmpWriteProbe['writable'],
            'tmp_write_error'                  => $tmpWriteProbe['error'],
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_filesystem_env_probe', $result);
            if (is_array($filtered)) {
                $result = array_merge($result, $filtered);
            }
        }

        $warnings = $this->classifyFilesystemWarnings($result, $tmpDirForCheck);
        $result['warnings'] = $warnings;

        foreach ($warnings as $w) {
            $this->logger->warn('[staged] ' . $w);
        }
        if (!empty($warnings)) {
            $this->noticePolicy->setFilesystemEnvAdminNotice($result);
        }

        if (function_exists('update_option')) {
            update_option($this->filesystemEnvironmentProbeOptionName(), $result, false);
        }

        $this->filesystemEnvironmentProbeCache = $result;
        return $result;
    }

    /**
     * @param array<int,string> $openBasedirPaths
     * @return bool
     */
    private function tmpPathIsProbeable(string $path, array $openBasedirPaths): bool {
        return $path !== ''
            && (empty($openBasedirPaths) || $this->pathFallsWithinAny($path, $openBasedirPaths));
    }

    /**
     * @param array<int,string> $openBasedirPaths
     * @return int
     */
    private function probeTmpFreeBytes(string $tmpDirForCheck, array $openBasedirPaths): int {
        if (!function_exists('disk_free_space')
            || !$this->tmpPathIsProbeable($tmpDirForCheck, $openBasedirPaths)) {
            return -1;
        }
        $prev = function_exists('error_reporting') ? error_reporting(0) : 0;
        try {
            $bytes = @disk_free_space($tmpDirForCheck);
            return ($bytes === false) ? -1 : (int)$bytes;
        } catch (\Throwable $e) {
            if (isset($this->logger) && is_object($this->logger) && method_exists($this->logger, 'warn')) {
                $this->logger->warn('[staged] temp free-space probe failed: '
                    . get_class($e) . ': ' . $e->getMessage());
            }
            return -1;
        } finally {
            if (function_exists('error_reporting')) {
                error_reporting($prev);
            }
        }
    }

    /**
     * @param array<int,string> $openBasedirPaths
     * @return array{writable:bool,error:string}
     */
    private function probeTmpWritable(string $tmpDirForCheck, array $openBasedirPaths): array {
        if ($tmpDirForCheck === '') {
            return array('writable' => false, 'error' => 'No temp directory configured');
        }
        if (!$this->tmpPathIsProbeable($tmpDirForCheck, $openBasedirPaths)) {
            return array('writable' => true, 'error' => '');
        }

        $prev = function_exists('error_reporting') ? error_reporting(0) : 0;
        $probeFile = false;
        try {
            $probeFile = function_exists('tempnam') ? @tempnam($tmpDirForCheck, 'abj404_probe_') : false;
            if (!is_string($probeFile)) {
                return array('writable' => false, 'error' => 'tempnam returned false');
            }
            $written = function_exists('file_put_contents') ? @file_put_contents($probeFile, '1') : false;
            if ($written === false) {
                return array('writable' => false, 'error' => 'file_put_contents returned false');
            }
            return array('writable' => true, 'error' => '');
        } catch (\Throwable $e) {
            return array('writable' => false, 'error' => get_class($e) . ': ' . $e->getMessage());
        } finally {
            if (is_string($probeFile) && function_exists('unlink')) {
                @unlink($probeFile);
            }
            if (function_exists('error_reporting')) {
                error_reporting($prev);
            }
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,string>
     */
    private function classifyFilesystemWarnings(array $result, string $tmpDirForCheck): array {
        $warnings = array();
        $resultOpenBasedirRaw = isset($result['open_basedir_raw']) && is_scalar($result['open_basedir_raw'])
            ? (string)$result['open_basedir_raw'] : '';
        if (!empty($result['tmp_outside_open_basedir'])) {
            $resultSysTmpDir = isset($result['sys_tmp_dir']) && is_scalar($result['sys_tmp_dir'])
                ? (string)$result['sys_tmp_dir'] : '';
            $warnings[] = sprintf(
                'open_basedir (%s) does not include the system temp directory (%s); '
                . 'PHP-side temp file work may fail.',
                $resultOpenBasedirRaw, $resultSysTmpDir
            );
        }
        if (!empty($result['upload_tmp_outside_open_basedir'])) {
            $resultUploadTmpDirRaw = isset($result['upload_tmp_dir_raw']) && is_scalar($result['upload_tmp_dir_raw'])
                ? (string)$result['upload_tmp_dir_raw'] : '';
            $warnings[] = sprintf(
                'upload_tmp_dir (%s) is outside open_basedir (%s); ini upload paths cannot be probed.',
                $resultUploadTmpDirRaw, $resultOpenBasedirRaw
            );
        }
        if (!empty($result['tmp_disk_low'])) {
            $resultTmpFreeBytes = isset($result['tmp_free_bytes']) && is_numeric($result['tmp_free_bytes'])
                ? (int)$result['tmp_free_bytes'] : -1;
            $warnings[] = sprintf(
                'temp directory (%s) has %d bytes free (< %d MB floor); the S9 hits '
                . 'aggregate or any MySQL temp materialization may fail with "No space left on device".',
                $tmpDirForCheck,
                $resultTmpFreeBytes,
                (int)(ABJ_404_Solution_ViewBuildConfig::PHP_TMPDIR_FREE_FLOOR_BYTES / 1048576)
            );
        }
        if (isset($result['tmp_writable']) && empty($result['tmp_writable'])) {
            $resultTmpWriteError = isset($result['tmp_write_error']) && is_scalar($result['tmp_write_error'])
                ? (string)$result['tmp_write_error'] : 'unknown write failure';
            $warnings[] = sprintf(
                'temp directory (%s) is not writable (%s); staged view-build temp-file work may fail.',
                $tmpDirForCheck,
                $resultTmpWriteError
            );
        }
        return $warnings;
    }

    /** @return string  Option name for the persisted session-variables probe. */
    public function sessionVariablesProbeOptionName(): string {
        return 'abj404_view_build_session_env_probe';
    }

    /**
     * Read an int from a probe values map, returning 0 when the value is non-numeric.
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
     * @param array<string,mixed> $thresholds
     * @return int
     */
    private static function thresholdInt(array $thresholds, string $key): int {
        $v = $thresholds[$key] ?? 0;
        return is_numeric($v) ? (int)$v : 0;
    }

    /**
     * @param array<string,mixed> $thresholds
     * @return float
     */
    private static function thresholdFloat(array $thresholds, string $key): float {
        $v = $thresholds[$key] ?? 0.0;
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /**
     * Probe the live MySQL session for operational and DDL-safety variables
     * that can degrade or break the staged view-build pipeline.
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
            $this->noticePolicy->setSessionEnvAdminNotice($warnings);
        }

        if (function_exists('update_option')) {
            update_option($this->sessionVariablesProbeOptionName(), $values, false);
        }

        $this->sessionVariablesProbeCache = $values;
        return $values;
    }

    /**
     * Single-query SHOW VARIABLES read for every name we care about.
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
        } catch (\Throwable $e) {
            $rows = null;
            if (isset($this->logger) && is_object($this->logger) && method_exists($this->logger, 'warn')) {
                $this->logger->warn('[staged] session-variable probe failed: '
                    . get_class($e) . ': ' . $e->getMessage());
            }
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
                if ($klow === 'value' && is_scalar($v)) { $value = (string)$v; }
            }
            if ($name !== '') { $out[$name] = $value; }
        }
        return $out;
    }

    /**
     * Apply SESSION_PROBE_THRESHOLDS and return human-readable warning strings.
     *
     * @param array<string,mixed> $values
     * @return array<int,string>
     */
    public function classifySessionVariableWarnings(array $values): array {
        $warnings = array();
        $t = ABJ_404_Solution_ViewBuildConfig::SESSION_PROBE_THRESHOLDS;

        $this->appendSessionLockAndTempWarnings($warnings, $values, $t);
        $this->appendSessionSlowLogAndBufferWarnings($warnings, $values, $t);
        $this->appendSessionTimeoutWarnings($warnings, $values, $t);
        $this->appendSessionCharsetAndDdlWarnings($warnings, $values);
        $this->appendSessionResourceLimitWarnings($warnings, $values, $t);

        return $warnings;
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @param array<string,mixed> $t
     * @return void
     */
    private function appendSessionLockAndTempWarnings(array &$warnings, array $values, array $t): void {
        $iLockWait = self::probeIntFromValues($values, 'innodb_lock_wait_timeout');
        $lockWaitMin = self::thresholdInt($t, 'innodb_lock_wait_timeout_min');
        if ($iLockWait > 0 && $iLockWait < $lockWaitMin) {
            $warnings[] = sprintf(
                'innodb_lock_wait_timeout=%ds (< %ds); the staged build may abort '
                . 'with "Lock wait timeout exceeded" on busy hosts.',
                $iLockWait, $lockWaitMin
            );
        }

        $tmpTable = self::probeIntFromValues($values, 'tmp_table_size');
        $maxHeap = self::probeIntFromValues($values, 'max_heap_table_size');
        $tmpTableMin = self::thresholdInt($t, 'tmp_table_size_min');
        $maxHeapMin = self::thresholdInt($t, 'max_heap_table_size_min');
        if ($tmpTable > 0 && $tmpTable < $tmpTableMin) {
            $warnings[] = sprintf(
                'tmp_table_size=%d (< %d MB); MySQL will spill GROUP BY work to '
                . 'disk earlier and the S9 hits aggregate may slow significantly.',
                $tmpTable, (int)($tmpTableMin / 1048576)
            );
        }
        if ($maxHeap > 0 && $maxHeap < $maxHeapMin) {
            $warnings[] = sprintf(
                'max_heap_table_size=%d (< %d MB); MEMORY-engine temp tables will '
                . 'truncate or spill earlier than expected.',
                $maxHeap, (int)($maxHeapMin / 1048576)
            );
        }
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @param array<string,mixed> $t
     * @return void
     */
    private function appendSessionSlowLogAndBufferWarnings(array &$warnings, array $values, array $t): void {
        $rawSlow = $values['slow_query_log'] ?? '';
        $rawSlowStr = is_scalar($rawSlow) ? (string)$rawSlow : '';
        $slowOn = (is_numeric($rawSlow) && (int)$rawSlow > 0)
            || strtoupper($rawSlowStr) === 'ON';
        $longTime = self::probeFloatFromValues($values, 'long_query_time');
        $longTimeMin = self::thresholdFloat($t, 'long_query_time_min');
        if ($slowOn && $longTime > 0 && $longTime < $longTimeMin) {
            $warnings[] = sprintf(
                'slow_query_log=ON with long_query_time=%.3fs (< %.1fs); the staged '
                . 'build will flood the slow log with stage-batch queries.',
                $longTime, $longTimeMin
            );
        }

        $bufferPool = self::probeIntFromValues($values, 'innodb_buffer_pool_size');
        $bufferPoolMin = self::thresholdInt($t, 'innodb_buffer_pool_size_min');
        if ($bufferPool > 0 && $bufferPool < $bufferPoolMin) {
            $warnings[] = sprintf(
                'innodb_buffer_pool_size=%d (< %d MB); large logsv2 reads at S2/S9 '
                . 'will thrash the buffer pool.',
                $bufferPool, (int)($bufferPoolMin / 1048576)
            );
        }
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @param array<string,mixed> $t
     * @return void
     */
    private function appendSessionTimeoutWarnings(array &$warnings, array $values, array $t): void {
        $waitTimeout = self::probeIntFromValues($values, 'wait_timeout');
        $interactiveTimeout = self::probeIntFromValues($values, 'interactive_timeout');
        $waitTimeoutMin = self::thresholdInt($t, 'wait_timeout_min');
        $interactiveTimeoutMin = self::thresholdInt($t, 'interactive_timeout_min');
        if ($waitTimeout > 0 && $waitTimeout < $waitTimeoutMin) {
            $warnings[] = sprintf(
                'wait_timeout=%ds (< %ds); the build connection can drop mid-stage '
                . 'leaving the buffer table orphaned (cf. orphan-table cleanup at runner startup).',
                $waitTimeout, $waitTimeoutMin
            );
        }
        if ($interactiveTimeout > 0 && $interactiveTimeout < $interactiveTimeoutMin) {
            $warnings[] = sprintf(
                'interactive_timeout=%ds (< %ds); same orphan-table risk as wait_timeout.',
                $interactiveTimeout, $interactiveTimeoutMin
            );
        }
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @return void
     */
    private function appendSessionCharsetAndDdlWarnings(array &$warnings, array $values): void {
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
    }

    /**
     * @param array<int,string> $warnings
     * @param array<string,mixed> $values
     * @param array<string,mixed> $t
     * @return void
     */
    private function appendSessionResourceLimitWarnings(array &$warnings, array $values, array $t): void {
        $threadStack = self::probeIntFromValues($values, 'thread_stack');
        $threadStackMin = self::thresholdInt($t, 'thread_stack_min');
        if ($threadStack > 0 && $threadStack < $threadStackMin) {
            $warnings[] = sprintf(
                'thread_stack=%d bytes (< %dK); deeply nested SQL may exhaust '
                . 'the connection thread stack.',
                $threadStack, (int)($threadStackMin / 1024)
            );
        }
        $openFiles = self::probeIntFromValues($values, 'open_files_limit');
        $openFilesMin = self::thresholdInt($t, 'open_files_limit_min');
        if ($openFiles > 0 && $openFiles < $openFilesMin) {
            $warnings[] = sprintf(
                'open_files_limit=%d (< %d); high-concurrency table opens may '
                . 'fail with "Too many open files".',
                $openFiles, $openFilesMin
            );
        }

        $alterLog = self::probeIntFromValues($values, 'innodb_online_alter_log_max_size');
        $alterLogMin = self::thresholdInt($t, 'innodb_online_alter_log_max_size_min');
        if ($alterLog > 0 && $alterLog < $alterLogMin) {
            $warnings[] = sprintf(
                'innodb_online_alter_log_max_size=%d (< %d MB); the S3 / S10 '
                . 'ALTER TABLE ADD INDEX may fail with "Online DDL log overflow" '
                . 'on busy hosts.',
                $alterLog, (int)($alterLogMin / 1048576)
            );
        }
    }

    /** @return void */
    public function clearSessionVariablesProbeCache(): void {
        $this->sessionVariablesProbeCache = null;
        if (function_exists('delete_option')) {
            delete_option($this->sessionVariablesProbeOptionName());
        }
        $this->noticePolicy->clearSessionEnvironmentNotices();
    }

    /**
     * Split a raw open_basedir value (`PATH_SEPARATOR`-delimited) into a
     * trimmed list of absolute path prefixes. Empty input returns array().
     *
     * @param string $raw
     * @return array<int,string>
     */
    public function splitOpenBasedirPaths(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') { return array(); }
        $sep = defined('PATH_SEPARATOR') ? PATH_SEPARATOR : ':';
        $parts = array_map('trim', explode($sep, $raw));
        $paths = array();
        foreach ($parts as $part) {
            if ($part !== '') {
                $paths[] = $part;
            }
        }
        return $paths;
    }

    /**
     * True when $candidate falls within at least one of $allowed (string
     * prefix match after normalizing trailing separators). Normalizes both
     * sides via realpath() when available so symlinks resolve consistently.
     *
     * @param string             $candidate
     * @param array<int,string>  $allowed
     * @return bool
     */
    public function pathFallsWithinAny(string $candidate, array $allowed): bool {
        if ($candidate === '' || empty($allowed)) { return true; }
        $normCandidate = $this->normalizePathPrefix($candidate);
        foreach ($allowed as $a) {
            $normA = $this->normalizePathPrefix($a);
            if ($normA === '') { continue; }
            if (strncmp($normCandidate, $normA, strlen($normA)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a path for prefix comparison: realpath() if it exists, else
     * trim trailing separators. Returns '' on bad input.
     *
     * @param string $path
     * @return string
     */
    public function normalizePathPrefix(string $path): string {
        $path = trim($path);
        if ($path === '') { return ''; }
        if (function_exists('realpath')) {
            $real = @realpath($path);
            if (is_string($real)) {
                return rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        }
        return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

}
