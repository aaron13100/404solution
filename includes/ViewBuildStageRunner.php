<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Timed per-stage callback runner for the staged getRedirectsForView build
 * pipeline.
 *
 * Extracted from the former staged-build trait so runStagedBuildOnce stays under the modularity cap
 * without losing the timing/log/inflight wiring each stage relies on.
 *
 * What lives here:
 *   - runTimedViewBuildStage(): the wrapper every stage callback flows
 *     through. Times the stage, catches throwables, dispatches them to the
 *     HostFailurePolicy classifier, and delegates timing log emission.
 *   - runNonBatchedStageWithKillStreakEscape(): the variant used by the
 *     non-batched stages (S3 / S9 / S10) that bumps a kill-streak counter
 *     and swaps in an extended per-query timeout on retry.
 *
 * Durable marker persistence lives in ViewBuildStageMarkers. Shutdown
 * post-mortems live in ViewBuildShutdownDiagnostics. AJAX marker/log
 * formatting lives in ViewBuildStageLogPresenter. Shared process-local state
 * lives in ViewBuildStageRuntimeState.
 *
 * Composed alongside the stage pipeline through ABJ_404_Solution_ViewBuildCollaborationContext
 * so the cross-collaborator calls ($this->readProgressOption, $this->logger,
 * $this->classifyAndHandleStageFailure, $this->resetStageNoProgressStreak,
 * $this->extendedTimeoutForKilledNonBatchedStage, staged query timeout state)
 * resolve through the shared orchestrator host.
 *
 * @property ABJ_404_Solution_DatabaseCore $dbCore
 * @property ABJ_404_Solution_Functions $f
 * @property ABJ_404_Solution_Logging $logger
 * @property ABJ_404_Solution_ViewReadService|null $viewReadService
 * @property ABJ_404_Solution_LogsRepository|null $logsRepo
 * @property bool|null $namedLockSupportedThisRequest
 * @property bool $fallbackLockLoggedThisRequest
 * @property bool $usingTransientFallbackLock
 * @property string $lastNamedLockUnsupportedReason
 * @property string $lastNamedLockUnsupportedError
 */
class ABJ_404_Solution_ViewBuildStageRunner extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @return void */
    public static function resetViewBuildShutdownLoggerRegistration(): void {
        if (class_exists('ABJ_404_Solution_ViewBuildShutdownDiagnostics')) {
            ABJ_404_Solution_ViewBuildShutdownDiagnostics::resetViewBuildShutdownLoggerRegistration();
        }
    }

    /**
     * Run one staged view-build step and write a clear per-stage timing line.
     *
     * The build can span several HTTP requests. For resumable stages that yield
     * mid-stage (S2/S4/S5), this records the time spent in the current tick and
     * marks the status as yielded; the final tick for that stage is logged as
     * completed.
     *
     * @param int $stageNumber  1-based staged build number.
     * @param string $stageKey  Stable stage key used by AJAX progress.
     * @param callable $callback Stage work to execute.
     * @return mixed
     */
    public function runTimedViewBuildStage(int $stageNumber, string $stageKey, callable $callback) {
        $started = microtime(true);
        try {
            $this->host->stageMarkers()->markViewBuildStageStarted($stageNumber, $stageKey);
            // Public extension point. Sites can hook this for telemetry, custom
            // progress dashboards, or chaos-testing the build's resume contract.
            // The do_action call is inside the try so a callback that throws
            // (test injection, host kill simulator) is treated identically to
            // a real SQL error from the stage callback below.
            if (function_exists('do_action')) {
                do_action('abj404_view_build_stage_starting', $stageNumber, $stageKey);
            }
            $result = $callback();
        } catch (\Throwable $e) {
            // B17 (Bruno 2026-05-13): when the host kills our connection
            // mid-stage (wait_timeout < build duration, MySQL errno 2006 /
            // 2013, "MySQL server has gone away" / "Lost connection during
            // query"), explicitly reconnect BEFORE the classifier and its
            // option-write side effects run. queryAndGetResults() already
            // calls ensureConnection() on its own ingress, so this is
            // belt-and-suspenders for the catch-block path: if a future
            // refactor moved any catch-block option write outside the DAO,
            // a still-broken handle would silently lose the progress /
            // streak / notice updates the classifier depends on. The
            // explicit reconnect also pins the "resume from last completed
            // stage, not S1" contract at the stage runner level rather
            // than at the DAO level. ensureConnection() is idempotent
            // (returns true when already connected) so the cost on the
            // non-connection-drop paths is one mysqli_ping per stage exit.
            if ($this->host->isTransientConnectionError($e->getMessage())) {
                $this->host->ensureConnection();
            }
            // Catch-block classification + side effects (skip / halt / streak)
            // live on the HostFailurePolicy trait so this orchestrator stays
            // focused on stage sequencing. classifyAndHandleStageFailure()
            // returns one of: 'resumable_yield', 'skipped', 'halted',
            // 'completed' (post-S11 reconcile), or 'rethrow'.
            $outcome = $this->host->hostFailurePolicy()->classifyAndHandleStageFailure($stageNumber, $stageKey, $e->getMessage(), $started);
            if ($outcome === 'resumable_yield') {
                return false;
            }
            if ($outcome === 'skipped') {
                return 'skipped';
            }
            if ($outcome === 'halted') {
                return 'halted';
            }
            if ($outcome === 'completed') {
                return null;
            }
            $this->host->stageLogPresenter()->logTimedViewBuildStage($stageNumber, $stageKey, 'error', $started);
            throw $e;
        }

        $status = 'completed';
        if ($result === false) {
            $status = 'yielded';
        } else if ($result === 'skipped') {
            $status = 'skipped';
        }
        // Wall-clock yield (false return) implies the stage's batch loop ran
        // far enough to exhaust the per-stage budget, which is observable
        // forward progress. Reset the no-progress streak so legitimate
        // long-running batched stages do not eventually trip the halt.
        // Completion / skip likewise reset.
        $this->host->progressOptions()->resetStageNoProgressStreak($stageNumber);
        if ($status === 'completed' || $status === 'skipped') {
            $this->host->stageMarkers()->markViewBuildStageCompleted($stageNumber);
        }
        $this->host->stageLogPresenter()->logTimedViewBuildStage($stageNumber, $stageKey, $status, $started);
        return $result;
    }

    /**
     * Run a non-batched stage (S3 / S9 / S10) with the kill-streak
     * escape valve applied. Behaves like runTimedViewBuildStage() except:
     *
     *   - Before invoking the stage, looks up the persisted kill streak
     *     for $streakOptKey. If >= 1, swaps in an extended per-query
     *     timeout (extendedTimeoutForKilledNonBatchedStage) so the
     *     SET STATEMENT max_statement_time hint can exceed the host's
     *     session limit on retry.
     *   - On `false` return (resumable kill), increments the streak so
     *     the next request resumes with the extended timeout already in
     *     effect.
     *   - On any non-`false` return (completed or 'skipped'), resets the
     *     streak to 0 -- the next rebuild starts fresh.
     *
     * The original $stagedQueryTimeoutSeconds is restored before
     * returning so subsequent stages run with their own intelligent
     * timeout, not the extended one (which was only meant for the
     * stuck non-batched stage).
     *
     * @param int      $stageNumber   1-based staged build number.
     * @param string   $stageKey      Stable stage key for AJAX progress.
     * @param string   $streakOptKey  Progress option key, e.g. 's3_kill_streak'.
     * @param callable $callback
     * @return mixed   Forwards runTimedViewBuildStage's return value:
     *                 typically true|null on completion, false on
     *                 resumable kill, 'skipped' when the callback
     *                 self-skips (S9 with no logs_hits table). Callers
     *                 only check `=== false` so the broader type is fine.
     */
    public function runNonBatchedStageWithKillStreakEscape(
        int $stageNumber,
        string $stageKey,
        string $streakOptKey,
        callable $callback
    ) {
        $savedTimeout = $this->host->stagedSqlExecutor()->getStagedQueryTimeoutSeconds();
        $this->host->stagedSqlExecutor()->setStagedQueryTimeoutSeconds($this->host->adaptive()->extendedTimeoutForKilledNonBatchedStage($streakOptKey));
        try {
            $result = $this->runTimedViewBuildStage($stageNumber, $stageKey, $callback);
        } finally {
            $this->host->stagedSqlExecutor()->setStagedQueryTimeoutSeconds($savedTimeout);
        }
        if ($result === false) {
            $this->host->progressOptions()->writeProgressOption($streakOptKey,
                $this->host->progressOptions()->readProgressOption($streakOptKey, 0) + 1
            );
        } else {
            $this->host->progressOptions()->writeProgressOption($streakOptKey, 0);
        }
        return $result;
    }

}
