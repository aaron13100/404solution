<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stage-failure orchestration for the staged view-build pipeline.
 *
 * This class owns only the decision flow that translates one stage error into
 * the runner outcome. Persistent degraded state, notices, no-progress streaks,
 * and post-S11 reconciliation are separate collaborators reached through the
 * orchestrator's __call bridge.
 *
 * @method int bumpStageNoProgressStreak(int $stageNumber)
 * @method string classifyStageFailure(int $stageNumber, string $errorText)
 * @method void logTimedViewBuildStage(int $stageNumber, string $stageKey, string $status, float $started)
 * @method void markBuildHaltedForHostFailure(int $stageNumber, string $errorText)
 * @method void markStageSkippedForHostFailure(int $stageNumber, string $errorText)
 * @method bool reconcilePostStageElevenState()
 * @method void setStagedBuildHaltNotice(string $scenarioKey, string $errorText)
 */
class ABJ_404_Solution_ViewBuildHostFailurePolicy extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Classify a stage exception and apply side effects (skip / halt /
     * streak). Called from the catch block inside runTimedViewBuildStage()
     * so the orchestrator stays focused on stage sequencing.
     *
     * Returns one of:
     *   - 'resumable_yield': caller should yield the stage (return false).
     *   - 'skipped'        : caller should record stage as skipped.
     *   - 'halted'         : caller should bail out of the build.
     *   - 'completed'      : post-S11 reconciliation succeeded; treat
     *                         as completion (return null).
     *   - 'rethrow'        : programmer-class or unknown error; caller
     *                         should rethrow so the dev mailbox carries
     *                         actionable context.
     *
     * @param int    $stageNumber
     * @param string $stageKey
     * @param string $errMsg
     * @param float  $started
     * @return string
     */
    public function classifyAndHandleStageFailure(int $stageNumber, string $stageKey, string $errMsg, float $started): string {
        $classification = $this->classifyStageFailure($stageNumber, $errMsg);
        if ($classification === 'resumable') {
            $streak = $this->bumpStageNoProgressStreak($stageNumber);
            if ($streak >= ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_FLOOR_KILL_STREAK_HALT_THRESHOLD) {
                $this->setStagedBuildHaltNotice('floor_kill_streak', sprintf(
                    'stage %d: %d consecutive resumable kills with no progress (host_unfit). %s',
                    $stageNumber, $streak, substr($errMsg, 0, 200)
                ));
                $this->markBuildHaltedForHostFailure($stageNumber,
                    'floor_kill_streak (host_unfit): stage ' . $stageNumber
                    . ' killed ' . $streak . ' consecutive ticks: ' . substr($errMsg, 0, 200)
                );
                $this->logTimedViewBuildStage($stageNumber, $stageKey, 'halted_floor_kill_streak', $started);
                return 'halted';
            }
            $this->logTimedViewBuildStage($stageNumber, $stageKey, 'killed_resumable', $started);
            return 'resumable_yield';
        }
        if ($classification === 'skip') {
            $this->markStageSkippedForHostFailure($stageNumber, $errMsg);
            $this->logTimedViewBuildStage($stageNumber, $stageKey, 'skipped_host_failure', $started);
            return 'skipped';
        }
        if ($classification === 'halt') {
            if ($stageNumber === 11 && $this->reconcilePostStageElevenState()) {
                // RENAME committed server-side, error was a connection
                // artifact. Treat as success.
                $this->logTimedViewBuildStage($stageNumber, $stageKey, 'completed_after_reconcile', $started);
                return 'completed';
            }
            $this->markBuildHaltedForHostFailure($stageNumber, $errMsg);
            $this->logTimedViewBuildStage($stageNumber, $stageKey, 'halted_host_failure', $started);
            return 'halted';
        }
        return 'rethrow';
    }
}
