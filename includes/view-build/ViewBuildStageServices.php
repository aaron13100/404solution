<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stage-execution collaborator bundle for the staged view build.
 *
 * The collaboration context owns construction; this bundle owns access to
 * peers involved in stage sequencing, stage SQL, progress, and build-state
 * probes.
 */
class ABJ_404_Solution_ViewBuildStageServices {

    /** @var ABJ_404_Solution_ViewDoneState */
    private $viewDoneState;
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

    /**
     * @param ABJ_404_Solution_ViewDoneState $viewDoneState
     * @param ABJ_404_Solution_ViewBuildStagePipeline $stagePipeline
     * @param ABJ_404_Solution_ViewBuildStageRunner $stageRunner
     * @param ABJ_404_Solution_ViewBuildStageMarkers $stageMarkers
     * @param ABJ_404_Solution_ViewBuildShutdownDiagnostics $shutdownDiagnostics
     * @param ABJ_404_Solution_ViewBuildStageLogPresenter $stageLogPresenter
     * @param ABJ_404_Solution_ViewBuildBatchExecutor $batchExecutor
     * @param ABJ_404_Solution_ViewBuildStageCallbacks $stageCallbacks
     * @param ABJ_404_Solution_ViewBuildAdaptive $adaptive
     * @param ABJ_404_Solution_ViewBuildOptionWriteVerifier $optionWriteVerifier
     * @param ABJ_404_Solution_ViewBuildPrefixDriftGuard $prefixDriftGuard
     * @param ABJ_404_Solution_ViewBuildProgressOptions $progressOptions
     * @param ABJ_404_Solution_ViewBuildStagedSqlExecutor $stagedSqlExecutor
     * @param ABJ_404_Solution_ViewBuildStateProbe $stateProbe
     * @param ABJ_404_Solution_ViewBuildSqlModeProbe $sqlModeProbe
     */
    public function __construct(
        ABJ_404_Solution_ViewDoneState $viewDoneState,
        ABJ_404_Solution_ViewBuildStagePipeline $stagePipeline,
        ABJ_404_Solution_ViewBuildStageRunner $stageRunner,
        ABJ_404_Solution_ViewBuildStageMarkers $stageMarkers,
        ABJ_404_Solution_ViewBuildShutdownDiagnostics $shutdownDiagnostics,
        ABJ_404_Solution_ViewBuildStageLogPresenter $stageLogPresenter,
        ABJ_404_Solution_ViewBuildBatchExecutor $batchExecutor,
        ABJ_404_Solution_ViewBuildStageCallbacks $stageCallbacks,
        ABJ_404_Solution_ViewBuildAdaptive $adaptive,
        ABJ_404_Solution_ViewBuildOptionWriteVerifier $optionWriteVerifier,
        ABJ_404_Solution_ViewBuildPrefixDriftGuard $prefixDriftGuard,
        ABJ_404_Solution_ViewBuildProgressOptions $progressOptions,
        ABJ_404_Solution_ViewBuildStagedSqlExecutor $stagedSqlExecutor,
        ABJ_404_Solution_ViewBuildStateProbe $stateProbe,
        ABJ_404_Solution_ViewBuildSqlModeProbe $sqlModeProbe
    ) {
        $this->viewDoneState = $viewDoneState;
        $this->stagePipeline = $stagePipeline;
        $this->stageRunner = $stageRunner;
        $this->stageMarkers = $stageMarkers;
        $this->shutdownDiagnostics = $shutdownDiagnostics;
        $this->stageLogPresenter = $stageLogPresenter;
        $this->batchExecutor = $batchExecutor;
        $this->stageCallbacks = $stageCallbacks;
        $this->adaptive = $adaptive;
        $this->optionWriteVerifier = $optionWriteVerifier;
        $this->prefixDriftGuard = $prefixDriftGuard;
        $this->progressOptions = $progressOptions;
        $this->stagedSqlExecutor = $stagedSqlExecutor;
        $this->stateProbe = $stateProbe;
        $this->sqlModeProbe = $sqlModeProbe;
    }

    /** @return ABJ_404_Solution_ViewDoneState */
    public function viewDoneState(): ABJ_404_Solution_ViewDoneState { return $this->viewDoneState; }
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

    /** @return array<int, object> */
    public function collaboratorsForTest(): array {
        return array(
            $this->viewDoneState,
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
        );
    }
}
