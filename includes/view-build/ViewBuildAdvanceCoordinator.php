<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public bounded build-advance entry point used by ajaxAdvanceViewBuild.
 *
 * @property ABJ_404_Solution_Logging $logger
 * @property ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
 */
class ABJ_404_Solution_ViewBuildAdvanceCoordinator extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * @param bool $forceRebuild
     * @return array<string, mixed>
     */
    public function advanceViewBuildOnce(bool $forceRebuild = false): array {
        if ($forceRebuild) {
            ABJ_404_Solution_ViewBuildStagePipeline::resetViewBuildOncePerRequestGuard();
            if ($this->host->dataBoundary()->rebuildHealth() instanceof ABJ_404_Solution_RebuildHealthState) {
                $this->host->dataBoundary()->rebuildHealth()->reset();
                $this->host->dataBoundary()->rebuildHealth()->acquireTrialToken();
            }
        } elseif ($this->host->dataBoundary()->rebuildHealth() instanceof ABJ_404_Solution_RebuildHealthState
                && !$this->host->dataBoundary()->rebuildHealth()->beginExpensiveRebuildAttempt()) {
            $this->host->dataBoundary()->logger()->debugMessage(
                '[staged] advanceViewBuildOnce: skipped because rebuild health gate is closed.'
            );
            return array(
                'status' => 'paused',
                'locked' => false,
                'healthGateClosed' => true,
            );
        }
        if (!$forceRebuild && $this->host->stageServices()->viewDoneState()->viewDoneIsServeable()) {
            return $this->host->recoveryServices()->readGateway()->getViewBuildProgress();
        }
        $lockTimeoutSeconds = $forceRebuild ? 10 : 0;
        if (!$this->host->recoveryServices()->lockCoordinator()->acquireViewBuildLock($lockTimeoutSeconds)) {
            $this->host->dataBoundary()->logger()->debugMessage(sprintf(
                '[staged] advanceViewBuildOnce: lock not acquired '
                . '(forceRebuild=%s, waited up to %ds)',
                $forceRebuild ? 'true' : 'false', $lockTimeoutSeconds
            ));
            $progress = $this->host->recoveryServices()->readGateway()->getViewBuildProgress();
            $stage = isset($progress['stage']) && is_scalar($progress['stage']) ? (int)$progress['stage'] : 0;
            if ($stage === 0) {
                $progress['stage'] = max(
                    0,
                    $this->host->stageServices()->progressOptions()->readProgressOption('last_completed_stage', 0)
                );
                $progress['progress_text'] = $progress['stage'] > 0
                    ? ('stage ' . $progress['stage'] . '/11')
                    : (isset($progress['progress_text']) && is_scalar($progress['progress_text']) ? (string)$progress['progress_text'] : 'not yet started');
            }
            $progress['locked'] = true;
            return $progress;
        }
        try {
            if ($forceRebuild) {
                $this->host->recoveryServices()->forceRestart()->runForceRestartCleanupInsideLock();
                $this->host->recoveryServices()->hostFailureState()->clearStagedBuildDegradedState();
            } else {
                $this->host->stageServices()->viewDoneState()->invalidateViewDoneServeableCache();
                if ($this->host->stageServices()->viewDoneState()->viewDoneIsServeable()) {
                    return $this->host->recoveryServices()->readGateway()->getViewBuildProgress();
                }
                $reconcileResult = $this->host->recoveryServices()->rebuildReconcile()->reconcileStagedTablesAtRunnerStartup();
                if ($reconcileResult === 'promoted') {
                    return $this->host->recoveryServices()->readGateway()->getViewBuildProgress();
                }
            }
            $isComplete = $this->host->stageServices()->stagePipeline()->runStagedBuildOnce();
        } finally {
            $this->host->recoveryServices()->lockCoordinator()->releaseViewBuildLock();
        }
        if ($isComplete) {
            return $this->host->recoveryServices()->readGateway()->getViewBuildProgress();
        }
        $this->host->recoveryServices()->cronScheduler()->scheduleViewDoneRebuild();
        return $this->host->recoveryServices()->readGateway()->getViewBuildProgress();
    }
}
