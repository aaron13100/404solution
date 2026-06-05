<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats staged-build progress markers and timing log lines.
 *
 * The AJAX inflight marker is user-visible through the progress poller; the
 * debug log line is developer-facing. Keeping both in one presenter prevents
 * the two formats from drifting when stage statuses change.
 *
 * @property ABJ_404_Solution_Logging $logger
 */
class ABJ_404_Solution_ViewBuildStageLogPresenter extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var ABJ_404_Solution_ViewBuildStageRuntimeState */
    private $runtimeState;

    /**
     * @param object $host Explicit context or test double exposing the same methods.
     * @phpstan-param ABJ_404_Solution_ViewBuildCollaborationContext $host
     * @param ABJ_404_Solution_ViewBuildStageRuntimeState $runtimeState
     */
    public function __construct(
        $host,
        ABJ_404_Solution_ViewBuildStageRuntimeState $runtimeState
    ) {
        parent::__construct($host);
        $this->runtimeState = $runtimeState;
    }

    /**
     * Update the inflight stage transient + AJAX-context global so the
     * client-side progress poller can render which sub-stage of the staged
     * build is currently running.
     *
     * @param string $stageKey Sub-stage key, e.g. 'staged_build_s2_insert'.
     * @param string $detail Optional mid-stage progress detail, e.g. 'batch 4/12'.
     * @return void
     */
    public function markBuildStage(string $stageKey, string $detail = ''): void {
        if (!class_exists('ABJ_404_Solution_ViewUpdater')) {
            return;
        }
        if ($detail !== ''
            && strncmp($detail, 'batch ', 6) === 0
            && strpos($detail, ', yielded') === false) {
            $this->runtimeState->captureBatchProgressDetail($detail);
        }
        $label = $detail !== '' ? ($stageKey . ':' . $detail) : $stageKey;
        \ABJ_404_Solution_ViewUpdater::markInflightStage($label);
    }

    /**
     * @param int $stageNumber
     * @param string $stageKey
     * @param string $status
     * @param float $started
     * @return void
     */
    public function logTimedViewBuildStage(int $stageNumber, string $stageKey, string $status, float $started): void {
        $elapsedMs = (int)round((microtime(true) - $started) * 1000);
        $markerDetail = $status . ' in ' . $elapsedMs . ' ms';
        if (($status === 'yielded' || $status === 'killed_resumable')
            && $this->runtimeState->lastBatchProgressDetail() !== '') {
            $markerDetail = $this->runtimeState->lastBatchProgressDetail() . ', ' . $markerDetail;
        }
        $this->markBuildStage($stageKey, $markerDetail);
        $this->host->dataBoundary()->logger()->debugMessage(sprintf(
            '[staged] build stage %d/11 %s %s in %d ms',
            $stageNumber,
            $stageKey,
            $status,
            $elapsedMs
        ));
        $this->runtimeState->clearOpenStageForShutdown();
    }
}
