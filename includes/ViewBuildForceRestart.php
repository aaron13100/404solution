<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runner-owned `forceRestartViewBuild()` primitive.
 *
 * Purpose. Replaces every external "discard whatever the runner has on disk
 * and restart the build from scratch" caller (the diagnostic AJAX
 * `?abj404_force_view_rebuild=1` path, the admin "rebuild now" button, the
 * WP-CLI rebuild commands). External callers now go through either the
 * source-mutation paths (which call rebuild invalidation directly) or
 * this primitive (explicit restart-from-scratch).
 *
 * The contract is exactly five bullets:
 *
 *   1. Acquire the runner lock.
 *   2. Drop the runner-owned buffer table (`view_build`).
 *   3. Clear runner progress options (registry + prefix-at-S1 capture +
 *      probe caches -- the same set `clearAllProgressOptions()` owns).
 *   4. PRESERVE the prior successful build's published view_done snapshot.
 *      The rebuild is in flight; the old snapshot stays serveable until
 *      the new S11 RENAME completes.
 *   5. Schedule S0/S1 immediately (via the existing cron primitive
 *      `scheduleViewDoneRebuild()`). The lock is released before the
 *      schedule call so the cron tick can acquire cleanly.
 *
 * The primitive does NOT:
 *
 *   - clear `clearStagedBuildDegradedState()`. A host-failed-degraded
 *     site stays degraded across the force-restart unless the caller
 *     explicitly clears the gate; that decision is a caller policy, not
 *     a runner primitive.
 *   - run S0/S1 inline. Callers in request contexts that want immediate
 *     progress (the AJAX force-rebuild path) call `advanceViewBuildOnce()`
 *     after this primitive returns; callers without a request context
 *     (CLI, admin "rebuild now") rely on the scheduled cron tick.
 *
 * Failure modes.
 *
 *   - Lock contention. If `acquireViewBuildLock(N)` returns false (a
 *     sibling cron tick or admin worker is mid-stage), the primitive
 *     returns false without touching any runner state. Callers retry
 *     on the next request; the in-flight build either completes
 *     normally (best case) or aborts at its next stage boundary and
 *     the next force-restart attempt will find the lock free.
 *
 *   - No `scheduleViewDoneRebuild()` on lock contention. Without the
 *     cleanup, scheduling a tick would just race the already-running
 *     tick. The existing holder is already advancing the build.
 *
 * Allowlist note. The host file
 * (`includes/DataAccessTrait_ViewBuildForceRestart.php`) matches the
 * runner-owned `DataAccessTrait_ViewBuild*` glob convention that the
 * Phase 4 semantic forbidden-operation lint (Codex #6 resolution; see
 * StagedBuildOwnershipLintTest) will use to define its allowlist of
 * runner-owned files. The primitive itself issues no DROP TABLE
 * (delegated to `dropTransientBuffersIfPresent()` in the
 * StageCallbacks trait, which IS the allowed owner today) and no direct
 * progress-option writes (delegated to `clearAllProgressOptions()` in
 * the Helpers trait, which IS the allowed owner today), so no allowlist
 * updates are required for the lints that are active on current HEAD.
 *
 * Sibling traits. `ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait`,
 * `ABJ_404_Solution_DataAccess_ViewBuildLockAndCronTrait`,
 * `ABJ_404_Solution_DataAccess_ViewBuildStageCallbacksTrait`; all three
 * provide the helpers this primitive composes (lock acquire/release,
 * buffer drop, progress clear, rebuild scheduling). All four traits are mixed into
 * `ABJ_404_Solution_DataAccess`.
 *
 * @property ABJ_404_Solution_DatabaseCore $dbCore
 * @property ABJ_404_Solution_Functions $f
 * @property ABJ_404_Solution_Logging $logger
 * @property ABJ_404_Solution_ViewReadService|null $viewReadService
 * @property ABJ_404_Solution_LogsRepository|null $logsRepo
 * @property int $stagedQueryTimeoutSeconds
 * @property string $lastBatchProgressDetail
 * @property bool $viewBuildStageOpenForShutdown
 * @property int $viewBuildShutdownStageNumber
 * @property string $viewBuildShutdownStageKey
 * @property bool|null $namedLockSupportedThisRequest
 * @property bool $fallbackLockLoggedThisRequest
 * @property bool $usingTransientFallbackLock
 * @property string $lastNamedLockUnsupportedReason
 * @property string $lastNamedLockUnsupportedError
 */
class ABJ_404_Solution_ViewBuildForceRestart extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Runner-owned force-restart primitive.
     *
     * Returns true when the restart completed cleanly: lock acquired,
     * buffer dropped, progress cleared, prior view_done snapshot
     * preserved, rebuild scheduled. Returns false when the lock could
     * not be acquired within `$lockTimeoutSeconds`; the caller may retry
     * on the next request.
     *
     * Default lock wait of 10s matches the existing
     * `?abj404_force_view_rebuild=1` AJAX handler in
     * `advanceViewBuildOnce()` -- comfortable headroom inside a 30s PHP
     * request budget so a typical cron build mid-flight has time to
     * release before we return false to the caller. Inlined as the
     * parameter default rather than a trait const because const-in-trait
     * is PHP 8.2 and the plugin targets PHP 7.4.
     *
     * @param int $lockTimeoutSeconds  GET_LOCK wait-time, default 10s.
     *   Pass 0 for a non-blocking acquire (callers that prefer to
     *   retry than to wait).
     * @return bool  true on success, false if lock contended.
     */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool {
        // (1) Acquire runner lock. Returns false if a sibling worker (cron
        //     tick, admin form save, REST PUT) holds it -- caller retries.
        if (!$this->host->lockCoordinator()->acquireViewBuildLock(max(0, $lockTimeoutSeconds))) {
            return false;
        }

        try {
            $this->runForceRestartCleanupInsideLock();
            if ($this->host->rebuildHealth() instanceof ABJ_404_Solution_RebuildHealthState) {
                $this->host->rebuildHealth()->reset();
                $this->host->rebuildHealth()->acquireTrialToken();
            }
        } finally {
            // Release the lock BEFORE scheduling the next tick so the
            // cron callback can acquire cleanly. A leaked lock would
            // stall every subsequent build attempt until the
            // session-scoped GET_LOCK times out.
            $this->host->lockCoordinator()->releaseViewBuildLock();
        }

        // (7) Schedule S0/S1 immediately. scheduleViewDoneRebuild() is
        //     idempotent (wp_next_scheduled short-circuit) so callers
        //     can chain or replay safely. Cron tick will drive S0 fresh
        //     cleanup -> S1 prefix capture -> S2..S11.
        $this->host->cronScheduler()->scheduleViewDoneRebuild();

        return true;
    }

    /**
     * Inside-lock cleanup phase of force-restart, callable by code paths
     * that already hold the view-build lock and intend to drive the
     * subsequent S0/S1 run inline (e.g. advanceViewBuildOnce() with
     * forceRebuild=true). Public callers should prefer
     * {@see forceRestartViewBuild()} -- this helper does NOT acquire the
     * lock and does NOT schedule the next cron tick.
     *
     * Performs steps 2-4 of the force-restart contract documented on the
     * trait docblock above:
     *
     *   - drop the runner-owned buffer table (and the deleteme leftover)
     *   - clear runner progress options + prefix-at-S1 capture
     *   - preserve the prior view_done snapshot (no write, no delete)
     *
     * Also resets the per-request serveability cache so a subsequent
     * viewDoneIsServeable() inside the same request observes the new
     * state, not the cached pre-cleanup value.
     */
    public function runForceRestartCleanupInsideLock(): void {
        // (2) Drop the runner-owned buffer table (and the deleteme
        //     leftover from any prior crashed S11 RENAME swap).
        //     Gated by SHOW TABLES so a steady-state force-rebuild
        //     after a clean S11 (no buffer present) does not pile
        //     unconditional DDL on the hot path.
        $this->host->stageCallbacks()->dropTransientBuffersIfPresent();

        // (3) Clear runner progress options. The helper owns the
        //     registry + prefix-at-S1 capture + sql_mode + php-env
        //     probe-cache clears as one atomic fresh-start step.
        $this->host->progressOptions()->clearAllProgressOptions();

        // Reset per-request serveability cache so a subsequent
        // viewDoneIsServeable() inside this request reflects the
        // post-cleanup state rather than a stale-cached value.
        $this->host->viewDoneState()->invalidateViewDoneServeableCache();
    }
}
