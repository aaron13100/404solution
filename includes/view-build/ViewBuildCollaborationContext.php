<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Composition root for staged view-build collaborators.
 *
 * ViewBuildOrchestrator owns the public facade. This context now owns only
 * graph construction and exposes cohesive collaborator bundles so peer calls
 * are routed through the real responsibility boundary instead of a broad
 * service-locator surface.
 */
class ABJ_404_Solution_ViewBuildCollaborationContext {

    /** @var ABJ_404_Solution_ViewBuildDataBoundary */
    private $dataBoundary;
    /** @var ABJ_404_Solution_ViewBuildStageServices */
    private $stageServices;
    /** @var ABJ_404_Solution_ViewBuildRecoveryServices */
    private $recoveryServices;

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
        $f,
        $logger,
        $rebuildHealth,
        ABJ_404_Solution_DatabaseConnectionManager $connectionManager,
        ABJ_404_Solution_DatabaseErrorClassifier $errorClassifier
    ) {
        $this->dataBoundary = new ABJ_404_Solution_ViewBuildDataBoundary(
            $dbCore,
            $f,
            $logger,
            $rebuildHealth,
            $connectionManager,
            $errorClassifier
        );

        $viewDoneState = new ABJ_404_Solution_ViewDoneState($this);
        $pageLoadFallback = new ABJ_404_Solution_ViewBuildPageLoadFallback($this);
        $foregroundLease = new ABJ_404_Solution_ViewBuildForegroundLease($this);
        $readGateway = new ABJ_404_Solution_ViewBuildReadGateway($this);
        $advanceCoordinator = new ABJ_404_Solution_ViewBuildAdvanceCoordinator($this);
        $stagePipeline = new ABJ_404_Solution_ViewBuildStagePipeline($this);
        $stageRuntimeState = new ABJ_404_Solution_ViewBuildStageRuntimeState();
        $stageMarkers = new ABJ_404_Solution_ViewBuildStageMarkers($this, $stageRuntimeState);
        $shutdownDiagnostics = new ABJ_404_Solution_ViewBuildShutdownDiagnostics($this, $stageRuntimeState);
        $stageLogPresenter = new ABJ_404_Solution_ViewBuildStageLogPresenter($this, $stageRuntimeState);
        $stageRunner = new ABJ_404_Solution_ViewBuildStageRunner($this);
        $batchExecutor = new ABJ_404_Solution_ViewBuildBatchExecutor($this);
        $stageCallbacks = new ABJ_404_Solution_ViewBuildStageCallbacks($this);
        $adaptive = new ABJ_404_Solution_ViewBuildAdaptive($this);
        $optionWriteVerifier = new ABJ_404_Solution_ViewBuildOptionWriteVerifier($this);
        $prefixDriftGuard = new ABJ_404_Solution_ViewBuildPrefixDriftGuard($this);
        $progressOptions = new ABJ_404_Solution_ViewBuildProgressOptions($this);
        $stagedSqlExecutor = new ABJ_404_Solution_ViewBuildStagedSqlExecutor($this);
        $stateProbe = new ABJ_404_Solution_ViewBuildStateProbe($this);
        $sqlModeProbe = new ABJ_404_Solution_ViewBuildSqlModeProbe($this);
        $rebuildReconcile = new ABJ_404_Solution_ViewBuildRebuildReconcile($this);
        $lockCoordinator = new ABJ_404_Solution_ViewBuildLockCoordinator($this);
        $cronScheduler = new ABJ_404_Solution_ViewBuildCronScheduler($this);
        $hostEnvironmentProbe = new ABJ_404_Solution_ViewBuildHostEnvironmentProbe($this);
        $filesystemEnvironmentProbe = new ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe($this);
        $sessionVariablesProbe = new ABJ_404_Solution_ViewBuildSessionVariablesProbe($this);
        $hostFailureNotices = new ABJ_404_Solution_ViewBuildHostFailureNotices($this);
        $hostFailureState = new ABJ_404_Solution_ViewBuildHostFailureState($this);
        $hostFailurePolicy = new ABJ_404_Solution_ViewBuildHostFailurePolicy($this);
        $forceRestart = new ABJ_404_Solution_ViewBuildForceRestart($this);

        $this->stageServices = new ABJ_404_Solution_ViewBuildStageServices(
            $viewDoneState,
            $stagePipeline,
            $stageRunner,
            $stageMarkers,
            $shutdownDiagnostics,
            $stageLogPresenter,
            $batchExecutor,
            $stageCallbacks,
            $adaptive,
            $optionWriteVerifier,
            $prefixDriftGuard,
            $progressOptions,
            $stagedSqlExecutor,
            $stateProbe,
            $sqlModeProbe
        );
        $this->recoveryServices = new ABJ_404_Solution_ViewBuildRecoveryServices(
            $pageLoadFallback,
            $foregroundLease,
            $readGateway,
            $advanceCoordinator,
            $rebuildReconcile,
            $lockCoordinator,
            $cronScheduler,
            $hostEnvironmentProbe,
            $filesystemEnvironmentProbe,
            $sessionVariablesProbe,
            $hostFailureNotices,
            $hostFailureState,
            $hostFailurePolicy,
            $forceRestart
        );
    }

    /** @param ABJ_404_Solution_ViewReadService $viewReadService @return void */
    public function setViewReadService(ABJ_404_Solution_ViewReadService $viewReadService): void {
        $this->dataBoundary->setViewReadService($viewReadService);
    }

    /** @param ABJ_404_Solution_LogsRepository $logsRepo @return void */
    public function setLogsRepository(ABJ_404_Solution_LogsRepository $logsRepo): void {
        $this->dataBoundary->setLogsRepository($logsRepo);
    }

    /** @return ABJ_404_Solution_ViewBuildDataBoundary */
    public function dataBoundary(): ABJ_404_Solution_ViewBuildDataBoundary { return $this->dataBoundary; }

    /** @return ABJ_404_Solution_ViewBuildStageServices */
    public function stageServices(): ABJ_404_Solution_ViewBuildStageServices { return $this->stageServices; }

    /** @return ABJ_404_Solution_ViewBuildRecoveryServices */
    public function recoveryServices(): ABJ_404_Solution_ViewBuildRecoveryServices { return $this->recoveryServices; }

    /** @return array<int, object> */
    public function collaboratorsForTest(): array {
        return array_merge(
            $this->stageServices->collaboratorsForTest(),
            $this->recoveryServices->collaboratorsForTest()
        );
    }

    /** @return void */
    public function resetViewBuildLockFallbackMemos(): void { ABJ_404_Solution_ViewBuildLockCoordinator::resetViewBuildLockFallbackMemos(); }

    /** @return void */
    public function resetViewBuildOncePerRequestGuard(): void { ABJ_404_Solution_ViewBuildStagePipeline::resetViewBuildOncePerRequestGuard(); }

    /** @return void */
    public function resetViewBuildShutdownLoggerRegistration(): void { ABJ_404_Solution_ViewBuildStageRunner::resetViewBuildShutdownLoggerRegistration(); }
}
