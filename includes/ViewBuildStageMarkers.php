<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable per-stage checkpoint writer for the staged view-build pipeline.
 *
 * The timed runner calls this before and after stage callbacks so resumable
 * builds can tell which stage started, which completed, and when the request
 * last made forward progress.
 *
 * @property ABJ_404_Solution_Logging $logger
 * @method int readProgressOption(...$arguments)
 * @method void writeProgressOption(...$arguments)
 */
class ABJ_404_Solution_ViewBuildStageMarkers extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var ABJ_404_Solution_ViewBuildStageRuntimeState */
    private $runtimeState;

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestrator $host
     * @param ABJ_404_Solution_ViewBuildStageRuntimeState $runtimeState
     */
    public function __construct(
        ABJ_404_Solution_ViewBuildOrchestrator $host,
        ABJ_404_Solution_ViewBuildStageRuntimeState $runtimeState
    ) {
        parent::__construct($host);
        $this->runtimeState = $runtimeState;
    }

    /**
     * Persist stage-start metadata before a stage does work. This survives
     * PHP/request death where the completion marker and catch block never run.
     *
     * @param int $stageNumber
     * @param string $stageKey
     * @return void
     */
    public function markViewBuildStageStarted(int $stageNumber, string $stageKey): void {
        $now = time();
        $this->runtimeState->resetBatchProgressDetail();
        if ($this->readProgressOption('started_at', 0) === 0) {
            $this->writeProgressOption('started_at', $now);
        }
        $this->writeProgressOption('last_started_stage', $stageNumber);
        $this->writeProgressOption('last_started_at', $now);
        $this->runtimeState->openStageForShutdown($stageNumber, $stageKey);
        $this->logger->debugMessage(sprintf(
            '[staged] build stage %d/11 %s starting',
            $stageNumber,
            $stageKey
        ));
    }

    /**
     * @param int $stageNumber
     * @return void
     */
    public function markViewBuildStageCompleted(int $stageNumber): void {
        $this->writeProgressOption('last_completed_stage', $stageNumber);
        $this->writeProgressOption('last_completed_at', time());
    }
}
