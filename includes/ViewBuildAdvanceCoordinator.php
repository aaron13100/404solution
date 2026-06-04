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
            if ($this->rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState) {
                $this->rebuildHealth->reset();
                $this->rebuildHealth->acquireTrialToken();
            }
        } elseif ($this->rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
                && !$this->rebuildHealth->beginExpensiveRebuildAttempt()) {
            $this->logger->debugMessage(
                '[staged] advanceViewBuildOnce: skipped because rebuild health gate is closed.'
            );
            return array(
                'status' => 'paused',
                'locked' => false,
                'healthGateClosed' => true,
            );
        }
        if (!$forceRebuild && $this->viewDoneIsServeable()) {
            return $this->getViewBuildProgress();
        }
        $lockTimeoutSeconds = $forceRebuild ? 10 : 0;
        if (!$this->acquireViewBuildLock($lockTimeoutSeconds)) {
            $this->logger->debugMessage(sprintf(
                '[staged] advanceViewBuildOnce: lock not acquired '
                . '(forceRebuild=%s, waited up to %ds)',
                $forceRebuild ? 'true' : 'false', $lockTimeoutSeconds
            ));
            $progress = $this->getViewBuildProgress();
            if ((int)($progress['stage'] ?? 0) === 0) {
                $progress['stage'] = max(
                    0,
                    $this->readProgressOption('last_completed_stage', 0)
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
                $this->runForceRestartCleanupInsideLock();
                $this->clearStagedBuildDegradedState();
            } else {
                $this->invalidateViewDoneServeableCache();
                if ($this->viewDoneIsServeable()) {
                    return $this->getViewBuildProgress();
                }
                $reconcileResult = $this->reconcileStagedTablesAtRunnerStartup();
                if ($reconcileResult === 'promoted') {
                    return $this->getViewBuildProgress();
                }
            }
            $isComplete = $this->runStagedBuildOnce();
        } finally {
            $this->releaseViewBuildLock();
        }
        if ($isComplete) {
            return $this->getViewBuildProgress();
        }
        $this->scheduleViewDoneRebuild();
        return $this->getViewBuildProgress();
    }
}
