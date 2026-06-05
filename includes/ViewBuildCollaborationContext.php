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

    /** @return ABJ_404_Solution_ViewDoneState */
    public function viewDoneState(): ABJ_404_Solution_ViewDoneState { return $this->viewDoneState; }

    /** @return ABJ_404_Solution_ViewBuildPageLoadFallback */
    public function pageLoadFallback(): ABJ_404_Solution_ViewBuildPageLoadFallback { return $this->pageLoadFallback; }

    /** @return ABJ_404_Solution_ViewBuildForegroundLease */
    public function foregroundLease(): ABJ_404_Solution_ViewBuildForegroundLease { return $this->foregroundLease; }

    /** @return ABJ_404_Solution_ViewBuildReadGateway */
    public function readGateway(): ABJ_404_Solution_ViewBuildReadGateway { return $this->readGateway; }

    /** @return ABJ_404_Solution_ViewBuildAdvanceCoordinator */
    public function advanceCoordinator(): ABJ_404_Solution_ViewBuildAdvanceCoordinator { return $this->advanceCoordinator; }

    /** @return ABJ_404_Solution_ViewBuildStagePipeline */
    public function stagePipeline(): ABJ_404_Solution_ViewBuildStagePipeline { return $this->stagePipeline; }

    /** @return ABJ_404_Solution_ViewBuildStageRunner */
    public function stageRunner(): ABJ_404_Solution_ViewBuildStageRunner { return $this->stageRunner; }

    /** @return ABJ_404_Solution_ViewBuildStageMarkers */
    public function stageMarkers(): ABJ_404_Solution_ViewBuildStageMarkers { return $this->stageMarkers; }

    /** @return ABJ_404_Solution_ViewBuildShutdownDiagnostics */
    public function shutdownDiagnostics(): ABJ_404_Solution_ViewBuildShutdownDiagnostics { return $this->shutdownDiagnostics; }

    /** @return ABJ_404_Solution_ViewBuildStageLogPresenter */
    public function stageLogPresenter(): ABJ_404_Solution_ViewBuildStageLogPresenter { return $this->stageLogPresenter; }

    /** @return ABJ_404_Solution_ViewBuildBatchExecutor */
    public function batchExecutor(): ABJ_404_Solution_ViewBuildBatchExecutor { return $this->batchExecutor; }

    /** @return ABJ_404_Solution_ViewBuildStageCallbacks */
    public function stageCallbacks(): ABJ_404_Solution_ViewBuildStageCallbacks { return $this->stageCallbacks; }

    /** @return ABJ_404_Solution_ViewBuildAdaptive */
    public function adaptive(): ABJ_404_Solution_ViewBuildAdaptive { return $this->adaptive; }

    /** @return ABJ_404_Solution_ViewBuildOptionWriteVerifier */
    public function optionWriteVerifier(): ABJ_404_Solution_ViewBuildOptionWriteVerifier { return $this->optionWriteVerifier; }

    /** @return ABJ_404_Solution_ViewBuildPrefixDriftGuard */
    public function prefixDriftGuard(): ABJ_404_Solution_ViewBuildPrefixDriftGuard { return $this->prefixDriftGuard; }

    /** @return ABJ_404_Solution_ViewBuildProgressOptions */
    public function progressOptions(): ABJ_404_Solution_ViewBuildProgressOptions { return $this->progressOptions; }

    /** @return ABJ_404_Solution_ViewBuildStagedSqlExecutor */
    public function stagedSqlExecutor(): ABJ_404_Solution_ViewBuildStagedSqlExecutor { return $this->stagedSqlExecutor; }

    /** @return ABJ_404_Solution_ViewBuildStateProbe */
    public function stateProbe(): ABJ_404_Solution_ViewBuildStateProbe { return $this->stateProbe; }

    /** @return ABJ_404_Solution_ViewBuildSqlModeProbe */
    public function sqlModeProbe(): ABJ_404_Solution_ViewBuildSqlModeProbe { return $this->sqlModeProbe; }

    /** @return ABJ_404_Solution_ViewBuildRebuildReconcile */
    public function rebuildReconcile(): ABJ_404_Solution_ViewBuildRebuildReconcile { return $this->rebuildReconcile; }

    /** @return ABJ_404_Solution_ViewBuildLockCoordinator */
    public function lockCoordinator(): ABJ_404_Solution_ViewBuildLockCoordinator { return $this->lockCoordinator; }

    /** @return ABJ_404_Solution_ViewBuildCronScheduler */
    public function cronScheduler(): ABJ_404_Solution_ViewBuildCronScheduler { return $this->cronScheduler; }

    /** @return ABJ_404_Solution_ViewBuildHostEnvironmentProbe */
    public function hostEnvironmentProbe(): ABJ_404_Solution_ViewBuildHostEnvironmentProbe { return $this->hostEnvironmentProbe; }

    /** @return ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe */
    public function filesystemEnvironmentProbe(): ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe { return $this->filesystemEnvironmentProbe; }

    /** @return ABJ_404_Solution_ViewBuildSessionVariablesProbe */
    public function sessionVariablesProbe(): ABJ_404_Solution_ViewBuildSessionVariablesProbe { return $this->sessionVariablesProbe; }

    /** @return ABJ_404_Solution_ViewBuildHostFailureNotices */
    public function hostFailureNotices(): ABJ_404_Solution_ViewBuildHostFailureNotices { return $this->hostFailureNotices; }

    /** @return ABJ_404_Solution_ViewBuildHostFailureState */
    public function hostFailureState(): ABJ_404_Solution_ViewBuildHostFailureState { return $this->hostFailureState; }

    /** @return ABJ_404_Solution_ViewBuildHostFailurePolicy */
    public function hostFailurePolicy(): ABJ_404_Solution_ViewBuildHostFailurePolicy { return $this->hostFailurePolicy; }

    /** @return ABJ_404_Solution_ViewBuildForceRestart */
    public function forceRestart(): ABJ_404_Solution_ViewBuildForceRestart { return $this->forceRestart; }

    /** @return ABJ_404_Solution_Functions */
    public function functions(): ABJ_404_Solution_Functions { return $this->f; }

    /** @return ABJ_404_Solution_Logging */
    public function logger(): ABJ_404_Solution_Logging { return $this->logger; }

    /** @return ABJ_404_Solution_RebuildHealthState|null */
    public function rebuildHealth() { return $this->rebuildHealth; }

    /** @return ABJ_404_Solution_ViewReadService */
    public function viewReadService(): ABJ_404_Solution_ViewReadService { return $this->requireViewReadService(); }

    /** @return ABJ_404_Solution_ViewReadService */
    private function requireViewReadService(): ABJ_404_Solution_ViewReadService {
        if ($this->viewReadService === null) {
            throw new \RuntimeException('ViewBuildCollaborationContext requires ViewReadService (call setViewReadService first)');
        }
        return $this->viewReadService;
    }

    /** @return ABJ_404_Solution_LogsRepository */
    public function logsRepository(): ABJ_404_Solution_LogsRepository { return $this->requireLogsRepo(); }

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

    /** @return void */
    public function resetViewBuildLockFallbackMemos(): void { ABJ_404_Solution_ViewBuildLockCoordinator::resetViewBuildLockFallbackMemos(); }

    /** @return void */
    public function resetViewBuildOncePerRequestGuard(): void { ABJ_404_Solution_ViewBuildStagePipeline::resetViewBuildOncePerRequestGuard(); }

    /** @return void */
    public function resetViewBuildShutdownLoggerRegistration(): void { ABJ_404_Solution_ViewBuildStageRunner::resetViewBuildShutdownLoggerRegistration(); }


}
