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
            if ($this->host->rebuildHealth() instanceof ABJ_404_Solution_RebuildHealthState) {
                $this->host->rebuildHealth()->reset();
                $this->host->rebuildHealth()->acquireTrialToken();
            }
        } elseif ($this->host->rebuildHealth() instanceof ABJ_404_Solution_RebuildHealthState
                && !$this->host->rebuildHealth()->beginExpensiveRebuildAttempt()) {
            $this->host->logger()->debugMessage(
                '[staged] advanceViewBuildOnce: skipped because rebuild health gate is closed.'
            );
            return array(
                'status' => 'paused',
                'locked' => false,
                'healthGateClosed' => true,
            );
        }
        if (!$forceRebuild && $this->host->viewDoneState()->viewDoneIsServeable()) {
            return $this->host->readGateway()->getViewBuildProgress();
        }
        $lockTimeoutSeconds = $forceRebuild ? 10 : 0;
        if (!$this->host->lockCoordinator()->acquireViewBuildLock($lockTimeoutSeconds)) {
            $this->host->logger()->debugMessage(sprintf(
                '[staged] advanceViewBuildOnce: lock not acquired '
                . '(forceRebuild=%s, waited up to %ds)',
                $forceRebuild ? 'true' : 'false', $lockTimeoutSeconds
            ));
            $progress = $this->host->readGateway()->getViewBuildProgress();
            if ((int)($progress['stage'] ?? 0) === 0) {
                $progress['stage'] = max(
                    0,
                    $this->host->progressOptions()->readProgressOption('last_completed_stage', 0)
                );
                $progress['progress_text'] = $progress['stage'] > 0
                    ? ('stage ' . $progress['stage'] . '/11')
                    : (string)($progress['progress_text'] ?? 'not yet started');
            }
            $progress['locked'] = true;
            return $progress;
        }
        try {
            if ($forceRebuild) {
                $this->host->forceRestart()->runForceRestartCleanupInsideLock();
                $this->host->hostFailureState()->clearStagedBuildDegradedState();
            } else {
                $this->host->viewDoneState()->invalidateViewDoneServeableCache();
                if ($this->host->viewDoneState()->viewDoneIsServeable()) {
                    return $this->host->readGateway()->getViewBuildProgress();
                }
                $reconcileResult = $this->host->rebuildReconcile()->reconcileStagedTablesAtRunnerStartup();
                if ($reconcileResult === 'promoted') {
                    return $this->host->readGateway()->getViewBuildProgress();
                }
            }
            $isComplete = $this->host->stagePipeline()->runStagedBuildOnce();
        } finally {
            $this->host->lockCoordinator()->releaseViewBuildLock();
        }
        if ($isComplete) {
            return $this->host->readGateway()->getViewBuildProgress();
        }
        $this->host->cronScheduler()->scheduleViewDoneRebuild();
        return $this->host->readGateway()->getViewBuildProgress();
    }
}
