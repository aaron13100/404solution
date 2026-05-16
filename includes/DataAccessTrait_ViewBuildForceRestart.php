<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runner-owned `forceRestartViewBuild()` primitive (Phase 3a step 2 of the
 * staged view-build watermark refactor; see
 * docs/refactor-staged-view-build-watermark.md and queue task c554).
 *
 * Purpose. Replaces every external "discard whatever the runner has on disk
 * and restart the build from scratch" caller (the diagnostic AJAX
 * `?abj404_force_view_rebuild=1` path, the admin "rebuild now" button if
 * any, the WP-CLI rebuild commands -- migrated by Phase 3a step 4 and the
 * Cluster A-D tasks). Today those callers reach `invalidateViewDone()`
 * directly, which violates the runner-ownership invariant the refactor
 * exists to restore.
 *
 * The contract is exactly seven bullets (and the absence of an eighth):
 *
 *   1. Acquire the runner lock.
 *   2. Drop the runner-owned buffer table (`view_build`).
 *   3. Clear runner progress options (registry + prefix-at-S1 capture +
 *      probe caches -- the same set `clearAllProgressOptions()` owns).
 *   4. Clear `started_watermark` (the in-flight build's S1-entry stamp;
 *      Phase 3a step 3 renames this to `active_build_started_watermark`
 *      and the call site here is updated alongside that rename).
 *   5. PRESERVE `built_watermark` (the prior successful build's published
 *      coverage). The rebuild is in flight, the old view_done snapshot is
 *      still serveable until the new S11 RENAME completes; deleting
 *      built_watermark would orphan the freshness signal until then.
 *   6. Do NOT bump the mutation watermark (force-rebuild is a runner
 *      command, not a data-change signal). Bumping would propagate as a
 *      phantom mutation to every concurrent reader that bracketed this
 *      moment -- their stage-boundary checks would abort their own builds.
 *   7. Schedule S0/S1 immediately (via the existing cron primitive
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
 *   - write to `built_watermark` for any reason.
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
 * buffer drop, progress clear, watermark stamp clear, rebuild
 * scheduling). All four traits are mixed into
 * `ABJ_404_Solution_DataAccess`.
 */
trait ABJ_404_Solution_DataAccess_ViewBuildForceRestartTrait {

    /**
     * Runner-owned force-restart primitive. Per Phase 3a step 2 (c554).
     *
     * Returns true when the restart completed cleanly: lock acquired,
     * buffer dropped, progress cleared, started_watermark cleared,
     * built_watermark preserved, watermark unchanged, rebuild scheduled.
     * Returns false when the lock could not be acquired within
     * `$lockTimeoutSeconds`; the caller may retry on the next request.
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
        if (!$this->acquireViewBuildLock(max(0, $lockTimeoutSeconds))) {
            return false;
        }

        try {
            // (2) Drop the runner-owned buffer table (and the deleteme
            //     leftover from any prior crashed S11 RENAME swap).
            //     Gated by SHOW TABLES so a steady-state force-rebuild
            //     after a clean S11 (no buffer present) does not pile
            //     unconditional DDL on the hot path.
            $this->dropTransientBuffersIfPresent();

            // (3) Clear runner progress options. The helper owns the
            //     registry + prefix-at-S1 capture + sql_mode + php-env
            //     probe-cache clears as one atomic fresh-start step.
            $this->clearAllProgressOptions();

            // (4) Clear started_watermark. Lives outside the progress
            //     registry (so the S11 happy-path observability contract
            //     holds across the boundary), so it needs its own delete
            //     call. Phase 3a step 3 renames this to
            //     active_build_started_watermark; that task updates the
            //     helper name, this call updates with it automatically.
            $this->clearStartedWatermark();

            // (5) PRESERVE built_watermark. No write, no delete. The
            //     prior successful build's published coverage stays as
            //     the cross-build pre-image for freshness checks until
            //     the new build's S11 swap publishes a fresh value.

            // (6) DO NOT bump the mutation watermark. No call to
            //     ABJ_404_Solution_MutationWatermark::bump() exists in
            //     this method. Force-rebuild is a runner command, not a
            //     data-change signal; a bump would propagate to every
            //     concurrent reader as a phantom mutation.
        } finally {
            // Release the lock BEFORE scheduling the next tick so the
            // cron callback can acquire cleanly. A leaked lock would
            // stall every subsequent build attempt until the
            // session-scoped GET_LOCK times out.
            $this->releaseViewBuildLock();
        }

        // (7) Schedule S0/S1 immediately. scheduleViewDoneRebuild() is
        //     idempotent (wp_next_scheduled short-circuit) so callers
        //     can chain or replay safely. Cron tick will drive S0 fresh
        //     cleanup -> S1 prefix capture + started_watermark re-stamp
        //     -> S2..S11.
        $this->scheduleViewDoneRebuild();

        return true;
    }
}
