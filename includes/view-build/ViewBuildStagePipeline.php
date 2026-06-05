<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Staged view-build stage pipeline.
 *
 * Owns the S1-S11 runner, table names, stage budgets, per-stage lock handoff,
 * and multisite prefix safety checks. Public callers enter through
 * advanceViewBuildOnce() or rebuildViewDoneInBackground(); those collaborators
 * route here through the orchestrator.
 *
 * @property ABJ_404_Solution_Logging $logger
 */
class ABJ_404_Solution_ViewBuildStagePipeline extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var bool Process-local guard so a single request never rebuilds twice. */
    private static $viewBuildAlreadyRanThisRequest = false;

    /** @return void */
    public static function resetViewBuildOncePerRequestGuard(): void {
        self::$viewBuildAlreadyRanThisRequest = false;
    }

    /** @return string */
    public function viewBuildTableName(): string {
        return $this->host->doTableNameReplacements('{wp_abj404_view_build}');
    }

    /** @return string */
    public function viewDoneTableName(): string {
        return $this->host->doTableNameReplacements('{wp_abj404_view_done}');
    }

    /** @return string */
    public function viewDeletemeTableName(): string {
        return $this->host->doTableNameReplacements('{wp_abj404_view_deleteme}');
    }

    /** @return int Always >= 1. */
    public function viewBuildBatchSize(): int {
        $size = ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEFAULT_BATCH_SIZE;
        if (defined('ABJ404_VIEW_BUILD_BATCH_SIZE')) {
            $size = intval(ABJ404_VIEW_BUILD_BATCH_SIZE);
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_view_build_batch_size', $size);
            if (is_scalar($filtered)) {
                $size = intval($filtered);
            }
        }
        return max(1, $size);
    }

    /** @return float Seconds; always > 0. */
    public function viewBuildPerStageBudgetSeconds(): float {
        $explicitOverride = false;
        $budget = (float)ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_PER_STAGE_BUDGET_SECONDS;

        if (defined('ABJ404_VIEW_BUILD_PER_STAGE_BUDGET_SECONDS')) {
            $budget = (float)ABJ404_VIEW_BUILD_PER_STAGE_BUDGET_SECONDS;
            $explicitOverride = true;
        }

        $setTimeLimitAvailable = $this->host->hostEnvironmentProbe()->probeSetTimeLimitAvailability();

        if (!$explicitOverride) {
            $maxExec = (int)ini_get('max_execution_time');
            if ($maxExec >= 5) {
                $cushion = $setTimeLimitAvailable ? 2 : 4;
                $budget = (float)max(1, $maxExec - $cushion);
            }
        }
        if (!$explicitOverride && !$setTimeLimitAvailable) {
            $tightCap = max(
                1.0,
                (float)ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_PER_STAGE_BUDGET_SECONDS - 4.0
            );
            $budget = min($budget, $tightCap);
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_view_build_per_stage_budget_seconds', $budget);
            if (is_scalar($filtered)) {
                $budget = (float)$filtered;
            }
        }
        return $budget > 0.1 ? $budget : 0.1;
    }

    /** @return bool */
    public function releaseAndReacquireBetweenStages(): bool {
        $this->host->lockCoordinator()->releaseViewBuildLock();
        if (!$this->host->lockCoordinator()->acquireViewBuildLock(0)) {
            $this->host->logger()->infoMessage(
                '[staged] runStagedBuildOnce: released build lock between stages; '
                . 'another worker took it during the gap. Yielding this tick; '
                . 'the next cron / AJAX advance will resume from the persisted current_stage.'
            );
            return false;
        }
        return true;
    }

    /** @param int $aboutToRunStage @return bool */
    public function haltIfPrefixChangedSinceStageOne(int $aboutToRunStage): bool {
        if ($this->host->prefixDriftGuard()->verifyPrefixUnchangedSinceStageOne()) {
            return false;
        }
        global $wpdb;
        $current = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : '';
        $captured = $this->host->prefixDriftGuard()->capturedPrefixForLog();
        $msg = sprintf(
            'Multisite blog context changed during view rebuild; rebuild '
            . 'aborted to prevent cross-blog data corruption. '
            . 'captured_prefix=%s current_prefix=%s aborted_at_stage=%d',
            $captured,
            $current,
            $aboutToRunStage
        );
        $this->host->hostFailureNotices()->setStagedBuildHaltNotice('multisite_prefix_changed', $msg);
        $this->host->logger()->warn('[staged] ' . $msg);
        return true;
    }

    /** @param int $stage @return bool */
    public function runStagedBuildStages6Through11(int $stage): bool {
        if ($stage < 6 && !$this->runStageSix()) { return false; }
        if ($stage < 7 && !$this->runStageSeven()) { return false; }
        if ($stage < 8 && !$this->runStageEight()) { return false; }
        if ($stage < 9 && !$this->runStageNine()) { return false; }
        if ($stage < 10 && !$this->runStageTen()) { return false; }
        if ($stage < 11 && !$this->runStageEleven()) { return false; }
        return true;
    }

    /** @return bool */
    public function runStagedBuildOnce(): bool {
        if (self::$viewBuildAlreadyRanThisRequest) {
            return $this->host->stateProbe()->viewDoneIsFresh();
        }
        self::$viewBuildAlreadyRanThisRequest = true;
        $this->host->shutdownDiagnostics()->registerViewBuildShutdownDiagnostics();

        $this->host->hostEnvironmentProbe()->probePhpEnvironmentForBuild();
        $this->host->filesystemEnvironmentProbe()->probeFilesystemEnvironmentForBuild();

        if ($this->host->hostFailureState()->isBuildHaltedForHostFailure()) {
            return $this->host->stateProbe()->viewDoneIsFresh();
        }

        $this->prepareBuildRun();
        $this->host->stagedSqlExecutor()->setStagedQueryTimeoutSeconds((int)round($this->host->adaptive()->intelligentStagedQueryTimeoutSeconds()));
        $stage = $this->host->progressOptions()->readProgressOption('current_stage', 0);

        if ($stage < 1 && !$this->runStageOne()) { return false; }
        if ($stage < 2 && !$this->runStageTwo()) { return false; }
        if ($stage < 3 && !$this->runStageThree()) { return false; }
        if ($stage < 4 && !$this->runStageFour()) { return false; }
        if ($stage < 5 && !$this->runStageFive()) { return false; }

        return $this->runStagedBuildStages6Through11($this->host->progressOptions()->readProgressOption('current_stage', 0));
    }

    /** @return void */
    private function prepareBuildRun(): void {
        $startedAt = $this->host->progressOptions()->readProgressOption('started_at', 0);
        $bufferExists = $this->host->stateProbe()->stagedTableExists($this->viewBuildTableName());
        $isResuming = $startedAt > 0
            && (time() - $startedAt) <= ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_RESUME_TTL_SECONDS
            && $bufferExists;

        $currentStage = $this->host->progressOptions()->readProgressOption('current_stage', 0);
        if (!$isResuming) {
            $this->logFreshStart($startedAt, $bufferExists, $currentStage);
            $this->host->progressOptions()->performFreshStartCleanup();
            return;
        }

        $this->host->logger()->infoMessage(sprintf(
            '[staged] runStagedBuildOnce: resuming (started_at=%d, %ds ago); current_stage=%d',
            $startedAt, time() - $startedAt, $currentStage
        ));
        $this->host->stageCallbacks()->dropDeletemeTable();
    }

    /** @param int $startedAt @param bool $bufferExists @param int $currentStage @return void */
    private function logFreshStart(int $startedAt, bool $bufferExists, int $currentStage): void {
        $reason = ($startedAt <= 0)
            ? 'no prior started_at'
            : (!$bufferExists
                ? 'buffer table missing (prior crash or fresh install)'
                : ('prior build older than resume TTL ('
                    . (time() - $startedAt) . 's elapsed)'));
        $this->host->logger()->infoMessage(sprintf(
            '[staged] runStagedBuildOnce: fresh start (%s); current_stage=%d',
            $reason, $currentStage
        ));
    }

    /** @return bool */
    private function runStageOne(): bool {
        $this->host->prefixDriftGuard()->capturePrefixAtBuildStart();
        $this->host->sqlModeProbe()->probeSqlModeForBuild();
        $this->host->sessionVariablesProbe()->probeSessionVariablesAtS1Entry();
        $this->host->logger()->debugMessage(sprintf(
            '[staged] runStagedBuildOnce: capturing prefix at S1 entry: prefix=%s',
            $this->host->prefixDriftGuard()->capturedPrefixForLog()
        ));
        $this->host->stageLogPresenter()->markBuildStage('staged_build_s1_create');
        $result = $this->host->stageRunner()->runTimedViewBuildStage(1, 'staged_build_s1_create', function () {
            $this->host->stageCallbacks()->stageCreateBuildTable();
        });
        if (!$this->stageResultCompleted($result)) { return false; }
        if ($this->host->progressOptions()->readProgressOption('started_at', 0) === 0) {
            $this->host->progressOptions()->writeProgressOption('started_at', time());
        }
        $this->host->progressOptions()->writeProgressOption('current_stage', 1);
        return true;
    }

    /** @return bool */
    private function runStageTwo(): bool {
        if (!$this->beforeStage(2)) { return false; }
        $result = $this->host->stageRunner()->runTimedViewBuildStage(2, 'staged_build_s2_insert', function () {
            return $this->host->batchExecutor()->stageInsertRedirectsBatched();
        });
        return $this->markStageCompleteIfDone(2, $result);
    }

    /** @return bool */
    private function runStageThree(): bool {
        if (!$this->beforeStage(3)) { return false; }
        if ($this->host->hostFailureState()->isStageMarkedSkipped(3)) {
            $this->host->progressOptions()->writeProgressOption('current_stage', 3);
            return true;
        }
        $this->host->stageLogPresenter()->markBuildStage('staged_build_s3_index_fd');
        $result = $this->host->stageRunner()->runNonBatchedStageWithKillStreakEscape(3, 'staged_build_s3_index_fd', 's3_kill_streak',
            function () { $this->host->stageCallbacks()->stageAddPreJoinIndexes(); }
        );
        return $this->markStageCompleteIfDone(3, $result);
    }

    /** @return bool */
    private function runStageFour(): bool {
        if (!$this->beforeStage(4)) { return false; }
        $result = $this->host->stageRunner()->runTimedViewBuildStage(4, 'staged_build_s4_update_posts', function () {
            return $this->host->batchExecutor()->stageUpdatePostsBatched();
        });
        return $this->markStageCompleteIfDone(4, $result);
    }

    /** @return bool */
    private function runStageFive(): bool {
        if (!$this->beforeStage(5)) { return false; }
        $result = $this->host->stageRunner()->runTimedViewBuildStage(5, 'staged_build_s5_update_terms', function () {
            return $this->host->batchExecutor()->stageUpdateTermsBatched();
        });
        return $this->markStageCompleteIfDone(5, $result);
    }

    /** @return bool */
    private function runStageSix(): bool {
        if (!$this->beforeStage(6)) { return false; }
        $this->host->stageLogPresenter()->markBuildStage('staged_build_s6_update_home');
        $result = $this->host->stageRunner()->runTimedViewBuildStage(6, 'staged_build_s6_update_home', function () {
            $this->host->stageCallbacks()->stageUpdateHome();
        });
        return $this->markStageCompleteIfDone(6, $result);
    }

    /** @return bool */
    private function runStageSeven(): bool {
        if (!$this->beforeStage(7)) { return false; }
        $this->host->stageLogPresenter()->markBuildStage('staged_build_s7_update_external');
        $result = $this->host->stageRunner()->runTimedViewBuildStage(7, 'staged_build_s7_update_external', function () {
            $this->host->stageCallbacks()->stageUpdateExternal();
        });
        return $this->markStageCompleteIfDone(7, $result);
    }

    /** @return bool */
    private function runStageEight(): bool {
        if (!$this->beforeStage(8)) { return false; }
        $this->host->stageLogPresenter()->markBuildStage('staged_build_s8_update_special');
        $result = $this->host->stageRunner()->runTimedViewBuildStage(8, 'staged_build_s8_update_special', function () {
            $this->host->stageCallbacks()->stageUpdateSpecial();
        });
        return $this->markStageCompleteIfDone(8, $result);
    }

    /** @return bool */
    private function runStageNine(): bool {
        if (!$this->beforeStage(9)) { return false; }
        if ($this->host->hostFailureState()->isStageMarkedSkipped(9)) {
            $this->host->progressOptions()->writeProgressOption('current_stage', 9);
            return true;
        }
        $result = $this->host->stageRunner()->runNonBatchedStageWithKillStreakEscape(9, 'staged_build_s9_update_hits', 's9_kill_streak',
            function () {
                if ($this->host->logsHitsTableExists()) {
                    $this->host->stageLogPresenter()->markBuildStage('staged_build_s9_update_hits');
                    $this->host->stageCallbacks()->stageUpdateHits();
                    return null;
                }
                $this->host->stageLogPresenter()->markBuildStage('staged_build_s9_update_hits', 'skipped; logs hits table unavailable');
                return 'skipped';
            }
        );
        return $this->markStageCompleteIfDone(9, $result);
    }

    /** @return bool */
    private function runStageTen(): bool {
        if (!$this->beforeStage(10)) { return false; }
        if ($this->host->hostFailureState()->isStageMarkedSkipped(10)) {
            $this->host->progressOptions()->writeProgressOption('current_stage', 10);
            return true;
        }
        $this->host->stageLogPresenter()->markBuildStage('staged_build_s10_index_sort');
        $result = $this->host->stageRunner()->runNonBatchedStageWithKillStreakEscape(10, 'staged_build_s10_index_sort', 's10_kill_streak',
            function () { $this->host->stageCallbacks()->stageAddSortIndexes(); }
        );
        return $this->markStageCompleteIfDone(10, $result);
    }

    /** @return bool */
    private function runStageEleven(): bool {
        if (!$this->beforeStage(11)) { return false; }
        $this->host->stageLogPresenter()->markBuildStage('staged_build_s11_swap');
        if (!$this->host->stagedSqlExecutor()->runS11Swap()) { return false; }
        $this->host->viewDoneState()->markViewDoneBuildCompleted();
        $this->host->progressOptions()->clearAllProgressOptions();
        return true;
    }

    /** @param int $stage @return bool */
    private function beforeStage(int $stage): bool {
        return $this->releaseAndReacquireBetweenStages()
            && !$this->haltIfPrefixChangedSinceStageOne($stage);
    }

    /** @param mixed $result @return bool */
    private function stageResultCompleted($result): bool {
        return $result !== false && $result !== 'halted';
    }

    /**
     * @param int $stage
     * @param mixed $result
     * @return bool
     */
    private function markStageCompleteIfDone(int $stage, $result): bool {
        if (!$this->stageResultCompleted($result)) { return false; }
        $this->host->progressOptions()->writeProgressOption('current_stage', $stage);
        return true;
    }
}
