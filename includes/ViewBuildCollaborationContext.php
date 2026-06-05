<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Explicit dependency graph for staged view-build collaborators.
 *
 * ViewBuildOrchestrator owns the public facade. This context owns the private
 * collaboration boundary between the focused staged-build services so they do
 * not need PHP magic methods or string-keyed operation registries.
 */
class ABJ_404_Solution_ViewBuildCollaborationContext {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;
    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;
    /** @var ABJ_404_Solution_ViewReadService|null */
    private $viewReadService;
    /** @var ABJ_404_Solution_LogsRepository|null */
    private $logsRepo;
    /** @var ABJ_404_Solution_DatabaseConnectionManager */
    private $connectionManager;
    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;
    /** @var ABJ_404_Solution_ViewDoneState */
    private $viewDoneState;
    /** @var ABJ_404_Solution_ViewBuildPageLoadFallback */
    private $pageLoadFallback;
    /** @var ABJ_404_Solution_ViewBuildForegroundLease */
    private $foregroundLease;
    /** @var ABJ_404_Solution_ViewBuildReadGateway */
    private $readGateway;
    /** @var ABJ_404_Solution_ViewBuildAdvanceCoordinator */
    private $advanceCoordinator;
    /** @var ABJ_404_Solution_ViewBuildStagePipeline */
    private $stagePipeline;
    /** @var ABJ_404_Solution_ViewBuildStageRunner */
    private $stageRunner;
    /** @var ABJ_404_Solution_ViewBuildStageMarkers */
    private $stageMarkers;
    /** @var ABJ_404_Solution_ViewBuildShutdownDiagnostics */
    private $shutdownDiagnostics;
    /** @var ABJ_404_Solution_ViewBuildStageLogPresenter */
    private $stageLogPresenter;
    /** @var ABJ_404_Solution_ViewBuildBatchExecutor */
    private $batchExecutor;
    /** @var ABJ_404_Solution_ViewBuildStageCallbacks */
    private $stageCallbacks;
    /** @var ABJ_404_Solution_ViewBuildAdaptive */
    private $adaptive;
    /** @var ABJ_404_Solution_ViewBuildOptionWriteVerifier */
    private $optionWriteVerifier;
    /** @var ABJ_404_Solution_ViewBuildPrefixDriftGuard */
    private $prefixDriftGuard;
    /** @var ABJ_404_Solution_ViewBuildProgressOptions */
    private $progressOptions;
    /** @var ABJ_404_Solution_ViewBuildStagedSqlExecutor */
    private $stagedSqlExecutor;
    /** @var ABJ_404_Solution_ViewBuildStateProbe */
    private $stateProbe;
    /** @var ABJ_404_Solution_ViewBuildSqlModeProbe */
    private $sqlModeProbe;
    /** @var ABJ_404_Solution_ViewBuildRebuildReconcile */
    private $rebuildReconcile;
    /** @var ABJ_404_Solution_ViewBuildLockCoordinator */
    private $lockCoordinator;
    /** @var ABJ_404_Solution_ViewBuildCronScheduler */
    private $cronScheduler;
    /** @var ABJ_404_Solution_ViewBuildHostEnvironmentProbe */
    private $hostEnvironmentProbe;
    /** @var ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe */
    private $filesystemEnvironmentProbe;
    /** @var ABJ_404_Solution_ViewBuildSessionVariablesProbe */
    private $sessionVariablesProbe;
    /** @var ABJ_404_Solution_ViewBuildHostFailureNotices */
    private $hostFailureNotices;
    /** @var ABJ_404_Solution_ViewBuildHostFailureState */
    private $hostFailureState;
    /** @var ABJ_404_Solution_ViewBuildHostFailurePolicy */
    private $hostFailurePolicy;
    /** @var ABJ_404_Solution_ViewBuildForceRestart */
    private $forceRestart;
    /** @var ABJ_404_Solution_ViewBuildStageRuntimeState */
    private $stageRuntimeState;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
     * @param ABJ_404_Solution_DatabaseConnectionManager $connectionManager
     * @param ABJ_404_Solution_DatabaseErrorClassifier $errorClassifier
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_Logging $logger,
        $rebuildHealth,
        ABJ_404_Solution_DatabaseConnectionManager $connectionManager,
        ABJ_404_Solution_DatabaseErrorClassifier $errorClassifier
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f;
        $this->logger = $logger;
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState ? $rebuildHealth : null;
        $this->connectionManager = $connectionManager;
        $this->errorClassifier = $errorClassifier;

        $this->viewDoneState = new ABJ_404_Solution_ViewDoneState($this);
        $this->pageLoadFallback = new ABJ_404_Solution_ViewBuildPageLoadFallback($this);
        $this->foregroundLease = new ABJ_404_Solution_ViewBuildForegroundLease($this);
        $this->readGateway = new ABJ_404_Solution_ViewBuildReadGateway($this);
        $this->advanceCoordinator = new ABJ_404_Solution_ViewBuildAdvanceCoordinator($this);
        $this->stagePipeline = new ABJ_404_Solution_ViewBuildStagePipeline($this);
        $this->stageRuntimeState = new ABJ_404_Solution_ViewBuildStageRuntimeState();
        $this->stageMarkers = new ABJ_404_Solution_ViewBuildStageMarkers($this, $this->stageRuntimeState);
        $this->shutdownDiagnostics = new ABJ_404_Solution_ViewBuildShutdownDiagnostics($this, $this->stageRuntimeState);
        $this->stageLogPresenter = new ABJ_404_Solution_ViewBuildStageLogPresenter($this, $this->stageRuntimeState);
        $this->stageRunner = new ABJ_404_Solution_ViewBuildStageRunner($this);
        $this->batchExecutor = new ABJ_404_Solution_ViewBuildBatchExecutor($this);
        $this->stageCallbacks = new ABJ_404_Solution_ViewBuildStageCallbacks($this);
        $this->adaptive = new ABJ_404_Solution_ViewBuildAdaptive($this);
        $this->optionWriteVerifier = new ABJ_404_Solution_ViewBuildOptionWriteVerifier($this);
        $this->prefixDriftGuard = new ABJ_404_Solution_ViewBuildPrefixDriftGuard($this);
        $this->progressOptions = new ABJ_404_Solution_ViewBuildProgressOptions($this);
        $this->stagedSqlExecutor = new ABJ_404_Solution_ViewBuildStagedSqlExecutor($this);
        $this->stateProbe = new ABJ_404_Solution_ViewBuildStateProbe($this);
        $this->sqlModeProbe = new ABJ_404_Solution_ViewBuildSqlModeProbe($this);
        $this->rebuildReconcile = new ABJ_404_Solution_ViewBuildRebuildReconcile($this);
        $this->lockCoordinator = new ABJ_404_Solution_ViewBuildLockCoordinator($this);
        $this->cronScheduler = new ABJ_404_Solution_ViewBuildCronScheduler($this);
        $this->hostEnvironmentProbe = new ABJ_404_Solution_ViewBuildHostEnvironmentProbe($this);
        $this->filesystemEnvironmentProbe = new ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe($this);
        $this->sessionVariablesProbe = new ABJ_404_Solution_ViewBuildSessionVariablesProbe($this);
        $this->hostFailureNotices = new ABJ_404_Solution_ViewBuildHostFailureNotices($this);
        $this->hostFailureState = new ABJ_404_Solution_ViewBuildHostFailureState($this);
        $this->hostFailurePolicy = new ABJ_404_Solution_ViewBuildHostFailurePolicy($this);
        $this->forceRestart = new ABJ_404_Solution_ViewBuildForceRestart($this);
    }

    /** @param ABJ_404_Solution_ViewReadService $viewReadService */
    public function setViewReadService(ABJ_404_Solution_ViewReadService $viewReadService): void {
        $this->viewReadService = $viewReadService;
    }

    /** @param ABJ_404_Solution_LogsRepository $logsRepo */
    public function setLogsRepository(ABJ_404_Solution_LogsRepository $logsRepo): void {
        $this->logsRepo = $logsRepo;
    }

    /** @return array<int, object> */
    public function collaboratorsForTest(): array {
        return array(
            $this->viewDoneState,
            $this->pageLoadFallback,
            $this->foregroundLease,
            $this->readGateway,
            $this->advanceCoordinator,
            $this->stagePipeline,
            $this->stageRunner,
            $this->stageMarkers,
            $this->shutdownDiagnostics,
            $this->stageLogPresenter,
            $this->batchExecutor,
            $this->stageCallbacks,
            $this->adaptive,
            $this->optionWriteVerifier,
            $this->prefixDriftGuard,
            $this->progressOptions,
            $this->stagedSqlExecutor,
            $this->stateProbe,
            $this->sqlModeProbe,
            $this->rebuildReconcile,
            $this->lockCoordinator,
            $this->cronScheduler,
            $this->hostEnvironmentProbe,
            $this->filesystemEnvironmentProbe,
            $this->sessionVariablesProbe,
            $this->hostFailureNotices,
            $this->hostFailureState,
            $this->hostFailurePolicy,
            $this->forceRestart,
        );
    }

    /** @return ABJ_404_Solution_Functions */
    public function functions(): ABJ_404_Solution_Functions { return $this->f; }

    /** @return ABJ_404_Solution_Logging */
    public function logger(): ABJ_404_Solution_Logging { return $this->logger; }

    /** @return ABJ_404_Solution_RebuildHealthState|null */
    public function rebuildHealth() { return $this->rebuildHealth; }

    /** @return ABJ_404_Solution_ViewReadService */
    private function requireViewReadService(): ABJ_404_Solution_ViewReadService {
        if ($this->viewReadService === null) {
            throw new \RuntimeException('ViewBuildCollaborationContext requires ViewReadService (call setViewReadService first)');
        }
        return $this->viewReadService;
    }

    /** @return ABJ_404_Solution_LogsRepository */
    private function requireLogsRepo(): ABJ_404_Solution_LogsRepository {
        if ($this->logsRepo === null) {
            throw new \RuntimeException('ViewBuildCollaborationContext requires LogsRepository (call setLogsRepository first)');
        }
        return $this->logsRepo;
    }

    /**
     * @param string $query SQL query (may contain {wp_*} table placeholders).
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function queryAndGetResults($query, $options = array()): array { return $this->dbCore->queryAndGetResults($query, $options); }

    /**
     * @param string $query
     * @return string
     */
    public function doTableNameReplacements($query): string { return $this->dbCore->doTableNameReplacements($query); }

    /** @return string */
    public function getLowercasePrefix(): string { return $this->dbCore->getLowercasePrefix(); }

    /** @return bool True if connection is active, false otherwise */
    public function ensureConnection(): bool { return $this->connectionManager->ensureConnection(); }

    /** @return ABJ_404_Solution_Clock */
    public function clock(): ABJ_404_Solution_Clock { return $this->dbCore->clock(); }

    /**
     * @param int $stageNumber
     * @param string $errorText
     * @return string 'resumable', 'skip', 'halt', or 'rethrow'.
     */
    public function classifyStageFailure(int $stageNumber, string $errorText): string { return $this->dbCore->classifyStageFailure($stageNumber, $errorText); }

    /**
     * @param string $errorText
     * @return bool
     */
    public function isResumableStagedKill(string $errorText): bool { return $this->errorClassifier->isResumableStagedKill($errorText); }

    /**
     * @param string|null $errorText
     * @return bool
     */
    public function isTransientConnectionError(?string $errorText): bool { return $this->errorClassifier->isTransientConnectionError($errorText); }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return string
     */
    public function getColumnCollationString(string $tableName, string $columnName): string { return $this->dbCore->getColumnCollationString($tableName, $columnName); }

    /** @return array<string, string> */
    public function viewBuildOnlyTranslations(): array { return $this->requireViewReadService()->viewBuildOnlyTranslations(); }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array { return $this->requireViewReadService()->readFromViewDone($sub, $tableOptions); }

    /** @return array<string, int> */
    public function getViewBuildProgressFingerprint(): array { return $this->requireViewReadService()->getViewBuildProgressFingerprint(); }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string { return $this->requireViewReadService()->buildViewDoneCountQuery($sub, $tableOptions); }

    /** @return bool */
    public function logsHitsTableExists(): bool { return (bool)$this->requireLogsRepo()->logsHitsTableExists(); }

    /** @return int */
    public function stagedQueryTimeoutSeconds(): int { return $this->stagedSqlExecutor->getStagedQueryTimeoutSeconds(); }

    /**
     * @param int $seconds
     * @return void
     */
    public function setStagedQueryTimeoutSeconds(int $seconds): void { $this->stagedSqlExecutor->setStagedQueryTimeoutSeconds($seconds); }

    /** @return array<string,mixed>|null */
    public function sqlModeProbeCache() { return $this->sqlModeProbe->getSqlModeProbeCache(); }

    /**
     * @param string $name Already-prefixed lock identifier shared with GET_LOCK.
     * @return bool
     */
    public function acquireTransientFallbackLock(string $name): bool { return $this->lockCoordinator->acquireTransientFallbackLock($name); }

    /**
     * @param int $timeoutSeconds GET_LOCK wait-time. 0 is the normal
     * @return bool
     */
    public function acquireViewBuildLock(int $timeoutSeconds = 0): bool { return $this->lockCoordinator->acquireViewBuildLock($timeoutSeconds); }

    /**
     * @param bool $forceRebuild
     * @return array<string, mixed>
     */
    public function advanceViewBuildOnce(bool $forceRebuild = false): array { return $this->advanceCoordinator->advanceViewBuildOnce($forceRebuild); }

    /**
     * @param string $stageLabel  Human-readable stage tag included in the
     * @return void
     */
    public function assertBuildBufferExistsOrHalt(string $stageLabel): void { $this->stageCallbacks->assertBuildBufferExistsOrHalt($stageLabel); }

    /**
     * @param string $currentSqlMode
     * @return bool|null
     */
    public function attemptRelaxSqlModeForBuildConnection(string $currentSqlMode): ?bool { return $this->sqlModeProbe->attemptRelaxSqlModeForBuildConnection($currentSqlMode); }

    /**
     * @param string $bufferTable
     * @return bool
     */
    public function bufferIntegrityPassesForPromote(string $bufferTable): bool { return $this->rebuildReconcile->bufferIntegrityPassesForPromote($bufferTable); }

    /** @return string Transient key for the build-halted gate. */
    public function buildHaltTransientKey(): string { return $this->hostFailureState->buildHaltTransientKey(); }

    /**
     * @param int $stageNumber 1..11.
     * @return int New streak value, or 0 when option API is unavailable.
     */
    public function bumpStageNoProgressStreak(int $stageNumber): int { return $this->progressOptions->bumpStageNoProgressStreak($stageNumber); }

    /** @return void */
    public function capturePrefixAtBuildStart(): void { $this->prefixDriftGuard->capturePrefixAtBuildStart(); }

    /** @return string */
    public function capturedPrefixForLog(): string { return $this->prefixDriftGuard->capturedPrefixForLog(); }

    /** @return void */
    public function claimForegroundViewBuildLease(): void { $this->foregroundLease->claimForegroundViewBuildLease(); }

    /**
     * @param int    $stageNumber
     * @param string $stageKey
     * @param string $errMsg
     * @param float  $started
     * @return string
     */
    public function classifyAndHandleStageFailure(int $stageNumber, string $stageKey, string $errMsg, float $started): string { return $this->hostFailurePolicy->classifyAndHandleStageFailure($stageNumber, $stageKey, $errMsg, $started); }

    /**
     * @param array<string,mixed> $values
     * @return array<int,string>
     */
    public function classifySessionVariableWarnings(array $values): array { return $this->sessionVariablesProbe->classifySessionVariableWarnings($values); }

    /** @return void */
    public function clearAllProgressOptions(): void { $this->progressOptions->clearAllProgressOptions(); }

    /** @return void */
    public function clearFilesystemEnvironmentProbeCache(): void { $this->filesystemEnvironmentProbe->clearFilesystemEnvironmentProbeCache(); }

    /** @return void */
    public function clearPhpEnvironmentProbeCache(): void { $this->hostEnvironmentProbe->clearPhpEnvironmentProbeCache(); }

    /** @return void */
    public function clearPrefixAtStageOne(): void { $this->prefixDriftGuard->clearPrefixAtStageOne(); }

    /** @return void */
    public function clearSessionVariablesProbeCache(): void { $this->sessionVariablesProbe->clearSessionVariablesProbeCache(); }

    /** @return void */
    public function clearSqlModeProbeCache(): void { $this->sqlModeProbe->clearSqlModeProbeCache(); }

    /** @return void */
    public function clearStagedBuildDegradedState(): void { $this->hostFailureState->clearStagedBuildDegradedState(); }

    /** @return void */
    public function clearViewBuildOpenStageForShutdown(): void { $this->shutdownDiagnostics->clearViewBuildOpenStageForShutdown(); }

    /** @return void */
    public function clearViewDoneHardStaleNotice(): void { $this->stateProbe->clearViewDoneHardStaleNotice(); }

    /** @return int Total active+inactive rows in wp_abj404_redirects. */
    public function countLiveRedirects(): int { return $this->batchExecutor->countLiveRedirects(); }

    /** @return int Rows currently in the build buffer. */
    public function countViewBuildRows(): int { return $this->batchExecutor->countViewBuildRows(); }

    /** @return string  e.g. "stage 2/11, 3000/12000 rows" or "not yet started". */
    public function describeBuildProgressForNotice(): string { return $this->stateProbe->describeBuildProgressForNotice(); }

    /**
     * @param int    $stageNumber
     * @param string $kind 'skipped' or 'halted'.
     * @param string $errorText
     * @return string
     */
    public function describeDegradedNotice(int $stageNumber, string $kind, string $errorText): string { return $this->hostFailureNotices->describeDegradedNotice($stageNumber, $kind, $errorText); }

    /**
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return string
     */
    public function describeStagedSqlFailure(string $relativePath, array $extraTranslations): string { return $this->stagedSqlExecutor->describeStagedSqlFailure($relativePath, $extraTranslations); }

    /** @return array<string,mixed> */
    public function detectAndAdjustSqlMode(): array { return $this->sqlModeProbe->detectAndAdjustSqlMode(); }

    /** @return float  Seconds, or 0.0 for "no host limit". */
    public function detectHostStagedQueryLimitSeconds(): float { return $this->adaptive->detectHostStagedQueryLimitSeconds(); }

    /** @return void */
    public function dropDeletemeTable(): void { $this->stageCallbacks->dropDeletemeTable(); }

    /** @return void */
    public function dropTransientBuffersIfPresent(): void { $this->stageCallbacks->dropTransientBuffersIfPresent(); }

    /** @return void */
    public function dropTransientStagedTables(): void { $this->stageCallbacks->dropTransientStagedTables(); }

    /** @return void */
    public function ensureFallbackLockNoticeAndLog(): void { $this->lockCoordinator->ensureFallbackLockNoticeAndLog(); }

    /**
     * @param string $stageKillStreakOptKey  Progress option key name,
     * @return int  Seconds.
     */
    public function extendedTimeoutForKilledNonBatchedStage(string $stageKillStreakOptKey): int { return $this->adaptive->extendedTimeoutForKilledNonBatchedStage($stageKillStreakOptKey); }

    /** @return array<string,string> */
    public function fetchSessionVariablesRowOrEmpty(): array { return $this->sessionVariablesProbe->fetchSessionVariablesRowOrEmpty(); }

    /** @return string */
    public function filesystemEnvironmentProbeOptionName(): string { return $this->filesystemEnvironmentProbe->filesystemEnvironmentProbeOptionName(); }

    /**
     * @param int $lockTimeoutSeconds  GET_LOCK wait-time, default 10s.
     * @return bool  true on success, false if lock contended.
     */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool { return $this->forceRestart->forceRestartViewBuild($lockTimeoutSeconds); }

    /** @return bool */
    public function foregroundViewBuildLeaseActive(): bool { return $this->foregroundLease->foregroundViewBuildLeaseActive(); }

    /**
     * @param int $bytes
     * @return string
     */
    public function formatPhpMemoryBytesHuman(int $bytes): string { return $this->hostEnvironmentProbe->formatPhpMemoryBytesHuman($bytes); }

    /** @return int hours since the earliest overdue WordPress cron event, */
    public function getCronStuckHours(): int { return $this->cronScheduler->getCronStuckHours(); }

    /** @return array<string,mixed>|null */
    public function getSqlModeProbeCache() { return $this->sqlModeProbe->getSqlModeProbeCache(); }

    /** @return int */
    public function getStagedQueryTimeoutSeconds(): int { return $this->stagedSqlExecutor->getStagedQueryTimeoutSeconds(); }

    /** @return array<string, mixed> */
    public function getViewBuildProgress(): array { return $this->readGateway->getViewBuildProgress(); }

    /** @return int  Unix timestamp, or 0. */
    public function getViewDoneBuiltAtTimestamp(): int { return $this->viewDoneState->getViewDoneBuiltAtTimestamp(); }

    /**
     * @param int $aboutToRunStage
     * @return bool
     */
    public function haltIfPrefixChangedSinceStageOne(int $aboutToRunStage): bool { return $this->stagePipeline->haltIfPrefixChangedSinceStageOne($aboutToRunStage); }

    /**
     * @param int $done
     * @param int $total
     * @return string e.g. "12/45" or "complete" when done==total.
     */
    public function humanBatchProgress(int $done, int $total): string { return $this->batchExecutor->humanBatchProgress($done, $total); }

    /** @return float  Seconds. */
    public function intelligentStagedQueryTimeoutSeconds(): float { return $this->adaptive->intelligentStagedQueryTimeoutSeconds(); }

    /** @return void */
    public function invalidateViewDoneServeableCache(): void { $this->viewDoneState->invalidateViewDoneServeableCache(); }

    /** @return bool True when a prior tick halted the build and the dedup */
    public function isBuildHaltedForHostFailure(): bool { return $this->hostFailureState->isBuildHaltedForHostFailure(); }

    /**
     * @param string $optionName
     * @return bool
     */
    public function isCurrentStageOptionName(string $optionName): bool { return $this->optionWriteVerifier->isCurrentStageOptionName($optionName); }

    /**
     * @param string $err
     * @return bool
     */
    public function isNamedLockUnsupportedError(string $err): bool { return $this->lockCoordinator->isNamedLockUnsupportedError($err); }

    /**
     * @param int $stageNumber
     * @return bool
     */
    public function isStageMarkedSkipped(int $stageNumber): bool { return $this->hostFailureState->isStageMarkedSkipped($stageNumber); }

    /**
     * @param string $text
     * @return string
     */
    public function localizeOrDefaultViewBuildNotice(string $text): string { return $this->stateProbe->localizeOrDefaultViewBuildNotice($text); }

    /**
     * @param int $stageNumber
     * @param string $stageKey
     * @param string $status
     * @param float $started
     * @return void
     */
    public function logTimedViewBuildStage(int $stageNumber, string $stageKey, string $status, float $started): void { $this->stageLogPresenter->logTimedViewBuildStage($stageNumber, $stageKey, $status, $started); }

    /**
     * @param string $shortName
     * @param string $optionName
     * @param int    $expected
     * @param mixed  $updateReturn
     * @param mixed  $readBack
     * @param string $path
     * @return void
     */
    public function logViewBuildProgressOptionWrite(
        string $shortName,
        string $optionName,
        int $expected,
        $updateReturn,
        $readBack,
        string $path
    ): void { $this->progressOptions->logViewBuildProgressOptionWrite($shortName, $optionName, $expected, $updateReturn, $readBack, $path); }

    /** @return void */
    public function logViewBuildShutdownDiagnostics(): void { $this->shutdownDiagnostics->logViewBuildShutdownDiagnostics(); }

    /**
     * @param int    $stageNumber
     * @param string $errorText
     * @return void
     */
    public function markBuildHaltedForHostFailure(int $stageNumber, string $errorText): void { $this->hostFailureState->markBuildHaltedForHostFailure($stageNumber, $errorText); }

    /**
     * @param string $stageKey Sub-stage key, e.g. 'staged_build_s2_insert'.
     * @param string $detail Optional mid-stage progress detail, e.g. 'batch 4/12'.
     * @return void
     */
    public function markBuildStage(string $stageKey, string $detail = ''): void { $this->stageLogPresenter->markBuildStage($stageKey, $detail); }

    /**
     * @param int    $stageNumber
     * @param string $errorText Original $wpdb->last_error / exception message.
     * @return void
     */
    public function markStageSkippedForHostFailure(int $stageNumber, string $errorText): void { $this->hostFailureState->markStageSkippedForHostFailure($stageNumber, $errorText); }

    /**
     * @param int $stageNumber
     * @return void
     */
    public function markViewBuildStageCompleted(int $stageNumber): void { $this->stageMarkers->markViewBuildStageCompleted($stageNumber); }

    /**
     * @param int $stageNumber
     * @param string $stageKey
     * @return void
     */
    public function markViewBuildStageStarted(int $stageNumber, string $stageKey): void { $this->stageMarkers->markViewBuildStageStarted($stageNumber, $stageKey); }

    /** @return void */
    public function markViewDoneBuildCompleted(): void { $this->viewDoneState->markViewDoneBuildCompleted(); }

    /** @return int Max(id) in the build buffer, 0 when empty. */
    public function maxBuildBufferId(): int { return $this->batchExecutor->maxBuildBufferId(); }

    /** @return void */
    public function maybeRaiseViewDoneHardStaleNotice(): void { $this->stateProbe->maybeRaiseViewDoneHardStaleNotice(); }

    /**
     * @param string $path
     * @return string
     */
    public function normalizePathPrefix(string $path): string { return $this->filesystemEnvironmentProbe->normalizePathPrefix($path); }

    /**
     * @param mixed $actual
     * @param mixed $expected
     * @return bool
     */
    public function optionReadBackMatches($actual, $expected): bool { return $this->optionWriteVerifier->optionReadBackMatches($actual, $expected); }

    /**
     * @param string $raw
     * @return int
     */
    public function parsePhpMemoryLimitToBytes(string $raw): int { return $this->hostEnvironmentProbe->parsePhpMemoryLimitToBytes($raw); }

    /**
     * @param string             $candidate
     * @param array<int,string>  $allowed
     * @return bool
     */
    public function pathFallsWithinAny(string $candidate, array $allowed): bool { return $this->filesystemEnvironmentProbe->pathFallsWithinAny($candidate, $allowed); }

    /** @return void */
    public function performFreshStartCleanup(): void { $this->progressOptions->performFreshStartCleanup(); }

    /** @return array<int,string> Trimmed list of names from ini disable_functions. */
    public function phpDisabledFunctionsList(): array { return $this->hostEnvironmentProbe->phpDisabledFunctionsList(); }

    /** @return string */
    public function phpEnvironmentProbeOptionName(): string { return $this->hostEnvironmentProbe->phpEnvironmentProbeOptionName(); }

    /** @return float */
    public function phpTimeRemainingSeconds(): float { return $this->adaptive->phpTimeRemainingSeconds(); }

    /** @return string */
    public function prefixAtStageOneOptionName(): string { return $this->prefixDriftGuard->prefixAtStageOneOptionName(); }

    /** @return array<string,mixed> */
    public function probeFilesystemEnvironmentForBuild(): array { return $this->filesystemEnvironmentProbe->probeFilesystemEnvironmentForBuild(); }

    /** @return int */
    public function probeMemoryLimitForS9(): int { return $this->hostEnvironmentProbe->probeMemoryLimitForS9(); }

    /** @return array<string,mixed> */
    public function probePhpEnvironmentForBuild(): array { return $this->hostEnvironmentProbe->probePhpEnvironmentForBuild(); }

    /** @return array<string,mixed> */
    public function probeSessionVariablesAtS1Entry(): array { return $this->sessionVariablesProbe->probeSessionVariablesAtS1Entry(); }

    /** @return bool */
    public function probeSetTimeLimitAvailability(): bool { return $this->hostEnvironmentProbe->probeSetTimeLimitAvailability(); }

    /** @return array<string,mixed>  See sqlModeProbeCache docblock. */
    public function probeSqlModeForBuild(): array { return $this->sqlModeProbe->probeSqlModeForBuild(); }

    /**
     * @param string $shortName  One of self::$viewBuildProgressOptionNames keys.
     * @return string  Site-prefixed option name.
     */
    public function progressOptionName(string $shortName): string { return $this->progressOptions->progressOptionName($shortName); }

    /**
     * @param string $shortName  Progress key.
     * @param int    $default
     * @return int
     */
    public function readProgressOption(string $shortName, int $default = 0): int { return $this->progressOptions->readProgressOption($shortName, $default); }

    /** @return void */
    public function rebuildViewDoneInBackground(): void { $this->rebuildReconcile->rebuildViewDoneInBackground(); }

    /** @return bool True when the committed swap was recovered as success. */
    public function reconcilePostStageElevenState(): bool { return $this->rebuildReconcile->reconcilePostStageElevenState(); }

    /** @return string  One of: */
    public function reconcileStagedTablesAtRunnerStartup(): string { return $this->rebuildReconcile->reconcileStagedTablesAtRunnerStartup(); }

    /**
     * @param string $stageShortKey
     * @return int  the new batch size.
     */
    public function recordStageBatchKilled(string $stageShortKey): int { return $this->adaptive->recordStageBatchKilled($stageShortKey); }

    /** @return void */
    public function registerViewBuildShutdownDiagnostics(): void { $this->shutdownDiagnostics->registerViewBuildShutdownDiagnostics(); }

    /** @return bool */
    public function releaseAndReacquireBetweenStages(): bool { return $this->stagePipeline->releaseAndReacquireBetweenStages(); }

    /** @return void */
    public function releaseViewBuildLock(): void { $this->lockCoordinator->releaseViewBuildLock(); }

    /**
     * @param int $stageNumber
     * @return void
     */
    public function resetStageNoProgressStreak(int $stageNumber): void { $this->progressOptions->resetStageNoProgressStreak($stageNumber); }

    /** @return void */
    public function resetViewBuildLockFallbackMemos(): void { ABJ_404_Solution_ViewBuildLockCoordinator::resetViewBuildLockFallbackMemos(); }

    /** @return void */
    public function resetViewBuildOncePerRequestGuard(): void { ABJ_404_Solution_ViewBuildStagePipeline::resetViewBuildOncePerRequestGuard(); }

    /** @return void */
    public function resetViewBuildShutdownLoggerRegistration(): void { ABJ_404_Solution_ViewBuildStageRunner::resetViewBuildShutdownLoggerRegistration(); }

    /** @return string Sanitized collation identifier (e.g. 'utf8mb4_unicode_520_ci'). */
    public function resolveColumnCollationForStagedBuild(): string { return $this->stagedSqlExecutor->resolveColumnCollationForStagedBuild(); }

    /** @return void */
    public function runForceRestartCleanupInsideLock(): void { $this->forceRestart->runForceRestartCleanupInsideLock(); }

    /**
     * @param string $stageKey Sub-stage label, e.g. 'staged_build_s4_update_posts'.
     * @param string $highWaterKey Progress option key, e.g. 's4_high_water'.
     * @param string $sqlFile Filename under sql/getRedirectsForViewStaged/.
     * @return bool True when stage completed; false when budget exhausted.
     */
    public function runIdRangeBatchedUpdate(string $stageKey, string $highWaterKey, string $sqlFile): bool { return $this->batchExecutor->runIdRangeBatchedUpdate($stageKey, $highWaterKey, $sqlFile); }

    /**
     * @param int $loBound MAX(id) of the buffer at batch start.
     * @param int $batchSize
     * @return int New MAX(id) of the buffer after this batch.
     */
    public function runInsertBatch(int $loBound, int $batchSize): int { return $this->batchExecutor->runInsertBatch($loBound, $batchSize); }

    /**
     * @param int      $stageNumber   1-based staged build number.
     * @param string   $stageKey      Stable stage key for AJAX progress.
     * @param string   $streakOptKey  Progress option key, e.g. 's3_kill_streak'.
     * @param callable $callback
     * @return mixed   Forwards runTimedViewBuildStage's return value:
     */
    public function runNonBatchedStageWithKillStreakEscape(
        int $stageNumber,
        string $stageKey,
        string $streakOptKey,
        callable $callback
    ) { return $this->stageRunner->runNonBatchedStageWithKillStreakEscape($stageNumber, $stageKey, $streakOptKey, $callback); }

    /** @return array{ran:bool, reason:string, progress:array<string,mixed>} */
    public function runPageLoadFallbackAdvance(): array { return $this->pageLoadFallback->runPageLoadFallbackAdvance(); }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int { return $this->readGateway->runRedirectsForViewCountStaged($sub, $tableOptions); }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array { return $this->readGateway->runRedirectsForViewStaged($sub, $tableOptions); }

    /** @return bool  True when the swap completed cleanly; false when the */
    public function runS11Swap(): bool { return $this->stagedSqlExecutor->runS11Swap(); }

    /** @return bool */
    public function runStagedBuildOnce(): bool { return $this->stagePipeline->runStagedBuildOnce(); }

    /**
     * @param int $stage
     * @return bool
     */
    public function runStagedBuildStages6Through11(int $stage): bool { return $this->stagePipeline->runStagedBuildStages6Through11($stage); }

    /**
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    public function runStagedSqlFile(string $relativePath, array $extraTranslations): void { $this->stagedSqlExecutor->runStagedSqlFile($relativePath, $extraTranslations); }

    /**
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    public function runStagedSqlFileTolerantOfDuplicateKey(string $relativePath, array $extraTranslations): void { $this->stagedSqlExecutor->runStagedSqlFileTolerantOfDuplicateKey($relativePath, $extraTranslations); }

    /**
     * @param int $stageNumber  1-based staged build number.
     * @param string $stageKey  Stable stage key used by AJAX progress.
     * @param callable $callback Stage work to execute.
     * @return mixed
     */
    public function runTimedViewBuildStage(int $stageNumber, string $stageKey, callable $callback) { return $this->stageRunner->runTimedViewBuildStage($stageNumber, $stageKey, $callback); }

    /**
     * @param string $url Raw URL captured from $_SERVER['REQUEST_URI'] or wpdb input.
     * @param int    $maxLength Optional override; 0 means "use the probe-derived cap".
     * @return string
     */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string { return $this->stagedSqlExecutor->sanitizeUrlBeforeInsert($url, $maxLength); }

    /**
     * @param int $delaySeconds
     * @return void
     */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void { $this->cronScheduler->scheduleViewDoneRebuild($delaySeconds); }

    /** @return string */
    public function sessionVariablesProbeOptionName(): string { return $this->sessionVariablesProbe->sessionVariablesProbeOptionName(); }

    /**
     * @param int    $stageNumber
     * @param string $kind 'skipped' or 'halted'.
     * @param string $errorText
     * @return void
     */
    public function setStagedBuildDegradedNotice(int $stageNumber, string $kind, string $errorText): void { $this->hostFailureNotices->setStagedBuildDegradedNotice($stageNumber, $kind, $errorText); }

    /**
     * @param string $scenarioKey
     * @param string $errorText
     * @return void
     */
    public function setStagedBuildHaltNotice(string $scenarioKey, string $errorText): void { $this->hostFailureNotices->setStagedBuildHaltNotice($scenarioKey, $errorText); }

    /**
     * @param int $hoursStuck how many hours the earliest overdue event has been waiting
     * @return void
     */
    public function setViewBuildCronStuckNotice(int $hoursStuck): void { $this->cronScheduler->setViewBuildCronStuckNotice($hoursStuck); }

    /**
     * @param string $detail
     * @return void
     */
    public function setViewBuildScheduleFailedNotice(string $detail): void { $this->cronScheduler->setViewBuildScheduleFailedNotice($detail); }

    /**
     * @param int $ageSeconds Current age of data on disk.
     * @return void
     */
    public function setViewDoneHardStaleNotice(int $ageSeconds): void { $this->stateProbe->setViewDoneHardStaleNotice($ageSeconds); }

    /**
     * @param string $raw
     * @return array<int,string>
     */
    public function splitOpenBasedirPaths(string $raw): array { return $this->filesystemEnvironmentProbe->splitOpenBasedirPaths($raw); }

    /** @return string */
    public function sqlModeProbeOptionName(): string { return $this->sqlModeProbe->sqlModeProbeOptionName(); }

    /** @return void */
    public function stageAddPreJoinIndexes(): void { $this->stageCallbacks->stageAddPreJoinIndexes(); }

    /** @return void */
    public function stageAddSortIndexes(): void { $this->stageCallbacks->stageAddSortIndexes(); }

    /** @return void */
    public function stageCreateBuildTable(): void { $this->stageCallbacks->stageCreateBuildTable(); }

    /** @return bool True when the entire redirects table has been copied; */
    public function stageInsertRedirectsBatched(): bool { return $this->batchExecutor->stageInsertRedirectsBatched(); }

    /**
     * @param int $stageNumber
     * @return string Empty when the stage is outside 1..11.
     */
    public function stageNoProgressStreakOptionName(int $stageNumber): string { return $this->progressOptions->stageNoProgressStreakOptionName($stageNumber); }

    /** @return void */
    public function stageRenameSwap(): void { $this->stageCallbacks->stageRenameSwap(); }

    /**
     * @param int $stageNumber
     * @return string Site-prefixed option name for the stage skip marker.
     */
    public function stageSkipOptionName(int $stageNumber): string { return $this->hostFailureState->stageSkipOptionName($stageNumber); }

    /** @return void */
    public function stageUpdateExternal(): void { $this->stageCallbacks->stageUpdateExternal(); }

    /** @return void */
    public function stageUpdateHits(): void { $this->stageCallbacks->stageUpdateHits(); }

    /** @return void */
    public function stageUpdateHome(): void { $this->stageCallbacks->stageUpdateHome(); }

    /** @return bool True when stage completed; false when budget exhausted. */
    public function stageUpdatePostsBatched(): bool { return $this->batchExecutor->stageUpdatePostsBatched(); }

    /** @return void */
    public function stageUpdateSpecial(): void { $this->stageCallbacks->stageUpdateSpecial(); }

    /** @return bool True when stage completed; false when budget exhausted. */
    public function stageUpdateTermsBatched(): bool { return $this->batchExecutor->stageUpdateTermsBatched(); }

    /** @return array<string, mixed> Options for queryAndGetResults that */
    public function stagedQueryOptions(): array { return $this->stagedSqlExecutor->stagedQueryOptions(); }

    /**
     * @param string $tableName
     * @return bool
     */
    public function stagedTableExists(string $tableName): bool { return $this->stateProbe->stagedTableExists($tableName); }

    /** @return void */
    public function sweepStaleRebuildTransients(): void { $this->rebuildReconcile->sweepStaleRebuildTransients(); }

    /**
     * @param string $name
     * @return string
     */
    public function transientFallbackLockOptionName(string $name): string { return $this->lockCoordinator->transientFallbackLockOptionName($name); }

    /** @return bool */
    public function verifyBuildLockSerializesWriter(): bool { return $this->lockCoordinator->verifyBuildLockSerializesWriter(); }

    /**
     * @param string $optionName  WordPress option name (already fully prefixed).
     * @param mixed  $expected    Value just written, compared against the read-back.
     * @return bool  True on coherent write (first try or retry); false when the
     */
    public function verifyOptionWriteCoherent(string $optionName, $expected): bool { return $this->optionWriteVerifier->verifyOptionWriteCoherent($optionName, $expected); }

    /** @return bool  False on mismatch (caller should halt the build). */
    public function verifyPrefixUnchangedSinceStageOne(): bool { return $this->prefixDriftGuard->verifyPrefixUnchangedSinceStageOne(); }

    /** @return int Always >= 1. */
    public function viewBuildBatchSize(): int { return $this->stagePipeline->viewBuildBatchSize(); }

    /**
     * @param string $stageShortKey  One of 's2_batch_size', 's4_batch_size', 's5_batch_size'.
     * @return int  Always >= VIEW_BUILD_MIN_BATCH_SIZE.
     */
    public function viewBuildBatchSizeForStage(string $stageShortKey): int { return $this->adaptive->viewBuildBatchSizeForStage($stageShortKey); }

    /** @return float Seconds; always > 0. */
    public function viewBuildPerStageBudgetSeconds(): float { return $this->stagePipeline->viewBuildPerStageBudgetSeconds(); }

    /** @return string */
    public function viewBuildTableName(): string { return $this->stagePipeline->viewBuildTableName(); }

    /** @return string */
    public function viewDeletemeTableName(): string { return $this->stagePipeline->viewDeletemeTableName(); }

    /** @return int Unix timestamp of last successful build, or 0 if missing. */
    public function viewDoneBuiltAt(): int { return $this->viewDoneState->viewDoneBuiltAt(); }

    /** @return int */
    public function viewDoneDataBuiltAt(): int { return $this->stateProbe->viewDoneDataBuiltAt(); }

    /** @return string */
    public function viewDoneDataBuiltAtOptionName(): string { return $this->stateProbe->viewDoneDataBuiltAtOptionName(); }

    /** @return string */
    public function viewDoneFreshnessOptionName(): string { return $this->viewDoneState->viewDoneFreshnessOptionName(); }

    /** @return bool */
    public function viewDoneHasRows(): bool { return $this->stateProbe->viewDoneHasRows(); }

    /** @return bool */
    public function viewDoneIsFresh(): bool { return $this->stateProbe->viewDoneIsFresh(); }

    /** @return bool */
    public function viewDoneIsServeable(): bool { return $this->viewDoneState->viewDoneIsServeable(); }

    /** @return bool */
    public function viewDoneTableExists(): bool { return $this->stateProbe->viewDoneTableExists(); }

    /** @return string */
    public function viewDoneTableName(): string { return $this->stagePipeline->viewDoneTableName(); }

    /**
     * @param string $shortName  Progress key.
     * @param int    $value
     * @return void
     */
    public function writeProgressOption(string $shortName, int $value): void { $this->progressOptions->writeProgressOption($shortName, $value); }


}
