<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page-load fallback that advances the staged view build when WP-Cron is
 * broken.
 *
 * Extracted from ABJ_404_Solution_ViewQueriesStaged (i798) so the
 * cron-recovery surface has its own focused class. The fallback's contract
 * is deliberately different from advanceViewBuildOnce:
 *
 *  - it runs from admin_init (not AJAX), so it must short-circuit fast on
 *    healthy-cron sites;
 *  - it must be bounded by a transient gate so admin navigation bursts do
 *    not stack inline build work;
 *  - it clamps the per-stage budget for THIS advance only so a single
 *    page-load never spends the full default budget inline;
 *  - it must never throw or break admin page rendering (caller in
 *    404-solution.php wraps it in try/catch).
 *
 * The AJAX advance path
 * (ABJ_404_Solution_ViewBuildAdvanceCoordinator::advanceViewBuildOnce)
 * intentionally does NOT share these wrappers. Keeping them as a separate
 * collaborator makes the contract clear and prevents either path from
 * accidentally inheriting the other's gates.
 *
 */
class ABJ_404_Solution_ViewBuildPageLoadFallback extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Synchronous fallback that advances the staged view-build by one tick
     * on plugin admin page-load when WP-Cron is broken. Pairs with the
     * cron-stuck admin notice (c374): the notice tells the admin cron is
     * broken; this fallback unblocks the page in the meantime so they do
     * not stare at "Carregando redirecionamentos..." (the Portuguese
     * localization Bruno reported) forever while they fix cron.
     *
     * Without this, hosts where DISABLE_WP_CRON is set in wp-config.php
     * AND no external system cron replaces it leave the staged build
     * stuck: the AJAX JS poller would advance it, but the poller only
     * fires after the page renders, and the fetch path hard-gates on
     * view_done being serveable. The admin sees the loading message
     * indefinitely on every page-load.
     *
     * Gates (cheap, in order):
     *   1. getCronStuckHours() < 24: cron is healthy enough; no fallback
     *      needed. The 24h floor matches the cron-stuck notice (c374) so
     *      the two signals fire together, not piecewise.
     *   2. viewDoneIsServeable() === true: the build is already done, so
     *      there is nothing to advance. Free option-read check.
     *   3. abj404_page_load_fallback_advance transient set: a sibling
     *      sub-request already ran the fallback inside the 60s window;
     *      avoid burning a second stage of inline work in the same admin
     *      burst.
     *
     * Bounding:
     *   - A short-lived filter is registered on the per-stage budget hook
     *     (abj404_view_build_per_stage_budget_seconds) so the advance call
     *     inside the lock cannot spend the full 10s default per-stage
     *     budget. The filter is removed in finally so the next AJAX
     *     advance / cron tick sees the normal budget.
     *
     * Lock semantics:
     *   - Delegates to advanceViewBuildOnce(false), which acquires the
     *     build lock with a 0s timeout. A sibling cron / AJAX advance
     *     already in flight returns immediately with locked=true and this
     *     method reports reason='locked' without doing further work. The
     *     build progress under the existing lock holder is still being
     *     made; the admin's next page-load (after the gate window) will
     *     try again.
     *
     * Caller contract (admin_init wrapper in 404-solution.php):
     *   - Only call when is_admin() is true.
     *   - Only call when the current user has the plugin-admin capability
     *     so unauthenticated requests cannot trigger build work.
     *   - Wrap in try/catch; a failure here must not break admin page
     *     rendering. Log at warning level so it does not generate dev
     *     email reports per the self-healing philosophy.
     *
     * @return array{ran:bool, reason:string, progress:array<string,mixed>}
     */
    public function runPageLoadFallbackAdvance(): array {
        // Cron is healthy: nothing for the fallback to do. Free check
        // (one wp_get_ready_cron_jobs call) so we can run it first.
        if ($this->host->recoveryServices()->cronScheduler()->getCronStuckHours() < 24) {
            return array(
                'ran' => false,
                'reason' => 'cron_healthy',
                'progress' => $this->host->recoveryServices()->readGateway()->getViewBuildProgress(),
            );
        }

        // Build already serveable: returning before any further work keeps
        // the fallback's steady-state cost at zero on hosts that recover,
        // which is the desirable shape (admin returns to a working page
        // without page-load latency).
        if ($this->host->stageServices()->viewDoneState()->viewDoneIsServeable()) {
            return array(
                'ran' => false,
                'reason' => 'not_needed',
                'progress' => $this->host->recoveryServices()->readGateway()->getViewBuildProgress(),
            );
        }

        // Transient gate: a single admin click can produce many
        // sub-requests (prefetch, refresh, multiple browser tabs). Cap
        // inline advances to one per 60s so the page-load impact cannot
        // compound. 60s is short enough that an attentive admin sees real
        // per-load progress, and long enough that bursts of navigation do
        // not stack inline build work.
        $haveTransientApi = function_exists('get_transient') && function_exists('set_transient');
        $gateKey = ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_GATE_KEY;
        if ($haveTransientApi && get_transient($gateKey) !== false) {
            return array(
                'ran' => false,
                'reason' => 'gate_active',
                'progress' => $this->host->recoveryServices()->readGateway()->getViewBuildProgress(),
            );
        }
        if ($haveTransientApi) {
            // Set the gate BEFORE running the advance so any failure or
            // long-running stage still suppresses the next sub-request.
            // Without this, a slow advance that times out partway would
            // be retried by the very next sub-request, compounding the
            // page-load impact instead of bounding it.
            set_transient(
                $gateKey,
                1,
                (int)ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_GATE_SECONDS
            );
        }

        // Compress the per-stage budget for just this advance. The
        // production filter machinery is the existing hook
        // abj404_view_build_per_stage_budget_seconds; clamping via min()
        // rather than overwriting preserves any operator-set
        // smaller-budget filter for hosts that have already tuned down.
        // Priority 100 runs after most operator filters so the fallback's
        // ceiling dominates.
        $budgetSeconds = (float)ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_BUDGET_SECONDS;
        $budgetFilter = static function ($incoming) use ($budgetSeconds) {
            $value = is_scalar($incoming) ? (float)$incoming : $budgetSeconds;
            return min($value, $budgetSeconds);
        };
        $filterRegistered = false;
        if (function_exists('add_filter')) {
            add_filter('abj404_view_build_per_stage_budget_seconds', $budgetFilter, 100);
            $filterRegistered = true;
        }

        try {
            // forceRebuild=false: this is the self-healing path. The
            // explicit ?abj404_force_view_rebuild=1 admin recovery is a
            // separate gesture that intentionally clears degraded gates
            // and waits 30s for the lock. Page-load fallback should never
            // escalate to those semantics.
            $progress = $this->host->recoveryServices()->advanceCoordinator()->advanceViewBuildOnce(false);
        } finally {
            if ($filterRegistered && function_exists('remove_filter')) {
                remove_filter('abj404_view_build_per_stage_budget_seconds', $budgetFilter, 100);
            }
        }

        $reason = !empty($progress['locked']) ? 'locked' : 'advanced';
        return array(
            'ran' => true,
            'reason' => $reason,
            'progress' => $progress,
        );
    }
}
