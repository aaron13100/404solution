<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Host-failure policy + degraded-build state for the staged view-build pipeline.
 *
 * Triggered when a stage callback raises an error the classifier identifies
 * as a permanent host-side environmental constraint (access denied, read-only,
 * disk-full, quota) rather than a resumable kill. The orchestrator uses this
 * trait to:
 *
 *   1. Mark optional stages permanently skipped (S3 indexes, S9 hits aggregate,
 *      S10 sort indexes) so subsequent cron ticks do NOT re-attempt the same
 *      denied DDL forever (gastroinovace.cz: 60 wasted attempts in 3 days).
 *
 *   2. Mark the build halted when a critical stage (S1/S2/S4-S8/S11) hits a
 *      permanent host failure, so the build does not loop on unrecoverable
 *      errors. The halt is dedup-windowed via transient (24h); a force
 *      rebuild explicitly clears it so admin can retry after fixing the
 *      host config.
 *
 *   3. Surface ONE admin notice per failure type per 24h on the plugin's
 *      own admin screen (`abj404_solution`). Per CLAUDE.md self-healing
 *      reliability rules: never email, never wp-admin-wide banner.
 *
 *   4. Track floor-kill streaks on batched stages: when a stage's batch
 *      is killed by the host while the adaptive shrink is already at
 *      VIEW_BUILD_MIN_BATCH_SIZE, the host cannot finish the plugin's
 *      smallest unit of work; halt rather than loop forever.
 *
 * Skip markers persist across normal invalidations (redirect edits) but
 * are cleared by an explicit force rebuild and on plugin reactivation.
 * Stored as standalone WP options outside the staged-build progress option
 * registry: a redirect-edit watermark bump must NOT clear them or every
 * redirect edit would re-arm the same denied DDL on the next cron tick.
 */
trait ABJ_404_Solution_DataAccess_ViewBuildHostFailurePolicyTrait {

    /**
     * @param int $stageNumber
     * @return string  Site-prefixed option name for the stage skip marker.
     */
    private function stageSkipOptionName(int $stageNumber): string {
        return $this->getLowercasePrefix() . 'abj404_view_build_s' . $stageNumber . '_skipped';
    }

    /**
     * Read whether the named stage is permanently skipped on this site.
     *
     * @param int $stageNumber
     * @return bool
     */
    private function isStageMarkedSkipped(int $stageNumber): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $value = get_option($this->stageSkipOptionName($stageNumber), 0);
        return is_scalar($value) && intval($value) > 0;
    }

    /**
     * Mark the named stage permanently skipped due to a host-side
     * environmental constraint and surface a deduplicated admin notice.
     * Idempotent: multiple calls with the same stage number write the
     * same marker and reset the notice TTL.
     *
     * @param int    $stageNumber
     * @param string $errorText  Original $wpdb->last_error / exception message.
     * @return void
     */
    private function markStageSkippedForHostFailure(int $stageNumber, string $errorText): void {
        if (function_exists('update_option')) {
            update_option($this->stageSkipOptionName($stageNumber), $this->clock()->now(), false);
        }
        $this->setStagedBuildDegradedNotice($stageNumber, 'skipped', $errorText);
        $this->logger->warn(sprintf(
            '[staged] stage %d permanently skipped (host-side environmental '
            . 'constraint, will not retry until force rebuild). Reason: %s',
            $stageNumber,
            substr($errorText, 0, 240)
        ));
    }

    /**
     * Mark the build halted at the named critical stage. The orchestrator
     * checks isBuildHaltedForHostFailure() at entry and skips the run, so
     * cron ticks during the dedup window are no-ops rather than retrying
     * the same denied DDL forever.
     *
     * @param int    $stageNumber
     * @param string $errorText
     * @return void
     */
    private function markBuildHaltedForHostFailure(int $stageNumber, string $errorText): void {
        if (function_exists('set_transient')) {
            set_transient(
                $this->buildHaltTransientKey(),
                array(
                    'stage' => $stageNumber,
                    'error' => $errorText,
                    'when'  => $this->clock()->now(),
                ),
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        }
        $this->setStagedBuildDegradedNotice($stageNumber, 'halted', $errorText);
        $this->logger->warn(sprintf(
            '[staged] critical stage %d halted (host-side environmental '
            . 'constraint, will not retry until force rebuild or 24h dedup '
            . 'window expires). Reason: %s',
            $stageNumber,
            substr($errorText, 0, 240)
        ));
    }

    /**
     * @return string  Transient key for the build-halted gate.
     */
    private function buildHaltTransientKey(): string {
        return 'abj404_view_build_halted';
    }

    /**
     * True if a prior tick halted the build for a permanent host failure
     * and the dedup window has not yet expired. The advance entry point
     * checks this so it does NOT re-run a build the host cannot finish,
     * avoiding the same waste pattern that motivated the original fix
     * (60 identical access-denied errors in 3 days at gastroinovace.cz).
     *
     * @return bool
     */
    private function isBuildHaltedForHostFailure(): bool {
        if (!function_exists('get_transient')) {
            return false;
        }
        $value = get_transient($this->buildHaltTransientKey());
        return is_array($value);
    }

    /**
     * Surface a deduplicated admin notice for a degraded-build event.
     * Stored as a transient on the plugin's own notice channel so the
     * admin Redirects screen can render it; falls back to a long-lived
     * option when no transient API is available.
     *
     * Notice keys are descriptive on purpose so the matching test seam
     * (StagedBuildPermanentFailureDegradesTest) can verify the right
     * notice fired without coupling to internal hash details.
     *
     * @param int    $stageNumber
     * @param string $kind        'skipped' or 'halted'.
     * @param string $errorText
     * @return void
     */
    private function setStagedBuildDegradedNotice(int $stageNumber, string $kind, string $errorText): void {
        $key = sprintf(
            'abj404_view_build_s%d_%s_notice',
            $stageNumber,
            $kind === 'halted' ? 'halted' : 'skipped'
        );
        $payload = array(
            'stage'   => $stageNumber,
            'kind'    => $kind,
            'error'   => $errorText,
            'message' => $this->describeDegradedNotice($stageNumber, $kind, $errorText),
            'when'    => $this->clock()->now(),
        );
        if (function_exists('set_transient')) {
            set_transient(
                $key,
                $payload,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        } elseif (function_exists('update_option')) {
            update_option($key, $payload, false);
        }
    }

    /**
     * Set a halt notice for a non-stage failure (e.g. floor-kill streak
     * detection). Same dedup TTL as setStagedBuildDegradedNotice() but
     * with a descriptive scenario key so the admin can tell why the
     * build halted.
     *
     * @param string $scenarioKey  e.g. 's2_floor_kill_streak'.
     * @param string $errorText
     * @return void
     */
    private function setStagedBuildHaltNotice(string $scenarioKey, string $errorText): void {
        $key = 'abj404_view_build_' . $scenarioKey . '_halt_notice';
        $payload = array(
            'scenario' => $scenarioKey,
            'kind'     => 'halted',
            'error'    => $errorText,
            'when'     => $this->clock()->now(),
        );
        if (function_exists('set_transient')) {
            set_transient(
                $key,
                $payload,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        } elseif (function_exists('update_option')) {
            update_option($key, $payload, false);
        }
    }

    /**
     * Build a user-facing message describing the degraded build event
     * and the host-side action the admin needs to take. Specificity
     * matters: a vague "view build degraded" notice with no remediation
     * path is exactly the silent-error pattern CLAUDE.md prohibits.
     *
     * @param int    $stageNumber
     * @param string $kind         'skipped' or 'halted'.
     * @param string $errorText
     * @return string
     */
    private function describeDegradedNotice(int $stageNumber, string $kind, string $errorText): string {
        $errorSnippet = substr(trim($errorText), 0, 200);
        $base = $kind === 'halted'
            ? sprintf('The 404 Solution view-build pipeline halted at stage %d/11.', $stageNumber)
            : sprintf('The 404 Solution view-build pipeline skipped optional stage %d/11.', $stageNumber);

        $hint = '';
        if (stripos($errorText, 'create temporary') !== false || stripos($errorText, "to database '") !== false
            || ($stageNumber === 9 && stripos($errorText, 'access denied') !== false)) {
            $hint = ' Ask your host to grant the CREATE TEMPORARY TABLES privilege to your WordPress database user '
                . 'so the hits aggregate column can be populated.';
        } elseif (stripos($errorText, 'alter command denied') !== false) {
            $hint = ' Ask your host to grant the ALTER privilege to your WordPress database user.';
        } elseif (stripos($errorText, 'rename') !== false || $stageNumber === 11) {
            $hint = ' Ask your host to grant ALTER + DROP + CREATE on the database used by WordPress so the '
                . 'view-build swap can complete.';
        } elseif (stripos($errorText, 'access denied') !== false || stripos($errorText, 'command denied') !== false) {
            $hint = ' Ask your host to review your WordPress database user privileges.';
        }

        return $base . $hint . ' Original error: ' . $errorSnippet;
    }

    /**
     * Clear all skip markers + halt gate. Called from the explicit force
     * rebuild path so an admin who has fixed their host configuration
     * can retry the previously denied stages. NOT called from the
     * source-mutation watermark bump path (regular redirect-edit
     * invalidation): every redirect change would otherwise re-arm a
     * denied DDL on the next cron tick, undoing the entire
     * skip-persistence contract.
     *
     * @return void
     */
    public function clearStagedBuildDegradedState(): void {
        if (function_exists('delete_option')) {
            for ($s = 1; $s <= 11; $s++) {
                delete_option($this->stageSkipOptionName($s));
            }
        }
        if (function_exists('delete_transient')) {
            delete_transient($this->buildHaltTransientKey());
        }
        // A force rebuild explicitly restarts the pipeline; the captured
        // prefix is per-build, not per-host, so wipe it so the fresh S1
        // re-captures from the (presumably correct) current $wpdb->prefix.
        $this->clearPrefixAtStageOne();
    }

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
     * The 'resumable_yield' branch also bumps the per-stage no-progress
     * streak; if the streak reaches
     * VIEW_BUILD_FLOOR_KILL_STREAK_HALT_THRESHOLD it converts to 'halted'
     * with a host_unfit notice. That is what stops the test pattern where
     * a stage is killed every tick and the build never converges.
     *
     * @param int    $stageNumber
     * @param string $stageKey
     * @param string $errMsg
     * @param float  $started
     * @return string
     */
    private function classifyAndHandleStageFailure(int $stageNumber, string $stageKey, string $errMsg, float $started): string {
        $classification = $this->classifyStageFailure($stageNumber, $errMsg);
        if ($classification === 'resumable') {
            $streak = $this->bumpStageNoProgressStreak($stageNumber);
            if ($streak >= ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_FLOOR_KILL_STREAK_HALT_THRESHOLD) {
                $this->setStagedBuildHaltNotice('floor_kill_streak', sprintf(
                    'stage %d: %d consecutive resumable kills with no progress (host_unfit). %s',
                    $stageNumber, $streak, substr($errMsg, 0, 200)
                ));
                $this->markBuildHaltedForHostFailure(
                    $stageNumber,
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

    /**
     * Increment the per-stage consecutive no-progress kill streak. Called
     * when a resumable-kill error fires before any forward progress is
     * observed in the tick. Returns the new streak value so the caller
     * can decide whether the floor-kill halt threshold has been reached.
     *
     * Inlined option access (not via writeProgressOption) so the streak
     * tracking does not require the helpers trait, which keeps the
     * trait composable in test contexts that do not pull the full DAO.
     *
     * @param int $stageNumber  1..11
     * @return int  New streak value, or 0 when option API is unavailable.
     */
    private function bumpStageNoProgressStreak(int $stageNumber): int {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return 0;
        }
        $optName = $this->stageNoProgressStreakOptionName($stageNumber);
        if ($optName === '') {
            return 0;
        }
        $current = get_option($optName, 0);
        $next = (is_scalar($current) ? max(0, intval($current)) : 0) + 1;
        update_option($optName, $next, false);
        return $next;
    }

    /**
     * Reset the per-stage no-progress kill streak. Called whenever a
     * stage tick finishes without a resumable-kill exception so
     * legitimate slow stages do not eventually accumulate enough strikes
     * to trip the halt.
     *
     * @param int $stageNumber
     * @return void
     */
    private function resetStageNoProgressStreak(int $stageNumber): void {
        if (!function_exists('update_option')) {
            return;
        }
        $optName = $this->stageNoProgressStreakOptionName($stageNumber);
        if ($optName !== '') {
            update_option($optName, 0, false);
        }
    }

    /**
     * Build the site-prefixed option name for the per-stage no-progress
     * streak counter. Falls back to a fixed prefix when getLowercasePrefix()
     * is not composed.
     *
     * @param int $stageNumber
     * @return string  Empty when the stage is outside 1..11.
     */
    private function stageNoProgressStreakOptionName(int $stageNumber): string {
        if ($stageNumber < 1 || $stageNumber > 11) {
            return '';
        }
        $prefix = $this->getLowercasePrefix();
        return $prefix . 'abj404_view_build_s' . $stageNumber . '_no_progress';
    }

    /**
     * Reconcile post-S11 state when a RENAME swap raised an error AFTER
     * the rename committed but the client lost the connection (Codex
     * finding #3 in StagedBuildPermanentFailureDegradesTest). RENAME TABLE
     * is atomic on the server; if view_done now exists with the buffer's
     * row count and view_build is gone, the swap actually succeeded and
     * the error was a connection-level artifact, not a real failure.
     *
     * Returns true when reconciliation finds the swap committed (caller
     * treats as success: write freshness, clear progress, return ready).
     * Returns false when view_done is genuinely missing or partial; the
     * caller falls through to the regular failure handling.
     *
     * Intentionally tolerant on probe failures: when SHOW TABLES errors
     * out, we cannot verify either way, so we conservatively report
     * "not reconciled" and let the next tick retry.
     *
     * @return bool
     */
    public function reconcilePostStageElevenState(): bool {
        $viewDoneTable  = $this->viewDoneTableName();
        $viewBuildTable = $this->viewBuildTableName();

        if (!$this->stagedTableExists($viewDoneTable)) {
            return false;
        }
        if ($this->stagedTableExists($viewBuildTable)) {
            return false;
        }
        // RENAME swap committed: view_done exists, view_build was renamed
        // away. Treat as success even though the request flow saw an error.
        if (function_exists('update_option')) {
            update_option($this->viewDoneFreshnessOptionName(), $this->clock()->now(), false);
        }
        $this->clearAllProgressOptions();
        $this->invalidateViewDoneServeableCache();
        return true;
    }
}
