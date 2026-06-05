<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public bounded build-advance entry point used by ajaxAdvanceViewBuild.
 *
 * @property ABJ_404_Solution_Logging $logger
 * @property ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
 * @method bool acquireViewBuildLock(int $timeoutSeconds = 0)
 * @method void clearStagedBuildDegradedState(...$arguments)
 * @method array<string, mixed> getViewBuildProgress(...$arguments)
 * @method void invalidateViewDoneServeableCache(...$arguments)
 * @method int readProgressOption(string $shortName, int $default = 0)
 * @method string reconcileStagedTablesAtRunnerStartup(...$arguments)
 * @method void releaseViewBuildLock(...$arguments)
 * @method void runForceRestartCleanupInsideLock(...$arguments)
 * @method bool runStagedBuildOnce(...$arguments)
 * @method void scheduleViewDoneRebuild(int $delaySeconds = 1)
 * @method bool viewDoneIsServeable(...$arguments)
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
        if (!$forceRebuild && $this->host->viewDoneIsServeable()) {
            return $this->host->getViewBuildProgress();
        }
        $lockTimeoutSeconds = $forceRebuild ? 10 : 0;
        if (!$this->host->acquireViewBuildLock($lockTimeoutSeconds)) {
            $this->host->logger()->debugMessage(sprintf(
                '[staged] advanceViewBuildOnce: lock not acquired '
                . '(forceRebuild=%s, waited up to %ds)',
                $forceRebuild ? 'true' : 'false', $lockTimeoutSeconds
            ));
            $progress = $this->host->getViewBuildProgress();
            if ((int)($progress['stage'] ?? 0) === 0) {
                $progress['stage'] = max(
                    0,
                    $this->host->readProgressOption('last_completed_stage', 0)
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
                $this->host->runForceRestartCleanupInsideLock();
                $this->host->clearStagedBuildDegradedState();
            } else {
                $this->host->invalidateViewDoneServeableCache();
                if ($this->host->viewDoneIsServeable()) {
                    return $this->host->getViewBuildProgress();
                }
                $reconcileResult = $this->host->reconcileStagedTablesAtRunnerStartup();
                if ($reconcileResult === 'promoted') {
                    return $this->host->getViewBuildProgress();
                }
            }
            $isComplete = $this->host->runStagedBuildOnce();
        } finally {
            $this->host->releaseViewBuildLock();
        }
        if ($isComplete) {
            return $this->host->getViewBuildProgress();
        }
        $this->host->scheduleViewDoneRebuild();
        return $this->host->getViewBuildProgress();
    }
}
