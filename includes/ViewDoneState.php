<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View-done snapshot serveability/freshness state.
 *
 * Extracted from ABJ_404_Solution_ViewQueriesStaged (i798) so the question
 * "can we serve the published view_done snapshot to a reader right now?"
 * lives in one focused class rather than being entangled with the build
 * state machine.
 *
 * Owns:
 *  - the request-lifetime serveability cache (single private bool|null field
 *    consulted by every AJAX gate and reader on every request);
 *  - the freshness signal read (viewDoneBuiltAt, getViewDoneBuiltAtTimestamp);
 *  - the serveability decision (viewDoneIsServeable);
 *  - the boundary write event that publishes a freshly-built snapshot
 *    (markViewDoneBuildCompleted: updates freshness + data-built-at signals,
 *    clears the hard-stale admin notice, invalidates the cache).
 *
 * Does NOT own:
 *  - the staged-build state machine (ViewBuildStagePipeline);
 *  - lock primitives, host probes, stage callbacks (sibling collaborators);
 *  - viewDoneTableExists / viewDoneHasRows / viewDoneDataBuiltAt — these
 *    are lower-level table/column probes that live on ViewBuildHelpers and
 *    are reached through the orchestrator's __call.
 *
 * @method bool viewDoneTableExists(...$arguments)
 * @method bool viewDoneHasRows(...$arguments)
 * @method int viewDoneDataBuiltAt(...$arguments)
 * @method string viewDoneDataBuiltAtOptionName(...$arguments)
 * @method string getLowercasePrefix(...$arguments)
 * @method void clearViewDoneHardStaleNotice(...$arguments)
 * @method ABJ_404_Solution_Clock clock(...$arguments)
 */
class ABJ_404_Solution_ViewDoneState extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Request-lifetime cache of viewDoneIsServeable(). The AJAX gate, the
     * progress reader, and the pending-build response share the same answer
     * within a single request; without this cache each call reissues a SHOW
     * TABLES probe through the centralized DAO, which on a slow host pays
     * the full diagnostic latency on every probe and pushes the gate
     * response over criterion 6's <2s budget.
     *
     * Reset to null on every fetch entry / write that mutates view_done so
     * a fresh request never sees a stale answer.
     *
     * @var bool|null
     */
    private $viewDoneIsServeableCache = null;

    /** @return string */
    public function viewDoneFreshnessOptionName(): string {
        return $this->host->getLowercasePrefix() . 'abj404_view_done_built_at';
    }

    /** @return int Unix timestamp of last successful build, or 0 if missing. */
    public function viewDoneBuiltAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $built = get_option($this->host->viewDoneFreshnessOptionName(), 0);
        return is_scalar($built) ? max(0, intval($built)) : 0;
    }

    /**
     * Public accessor for the unix-time the view_done snapshot was last
     * successfully built. Returns 0 when never built or when the freshness
     * option has been cleared by an invalidation. Used by the admin footer
     * (and any diagnostic surface) to render a "Cache view freshness: 5m"
     * indicator without exposing the internal option name.
     *
     * @return int  Unix timestamp, or 0.
     */
    public function getViewDoneBuiltAtTimestamp(): int {
        return $this->host->viewDoneBuiltAt();
    }

    /**
     * Public read-only check used by the AJAX fetch endpoints to gate "serve
     * from cache vs. return pending". True when view_done exists on disk and
     * contains rows. Stale-but-present is serveable: invalidate clears the
     * freshness signal but leaves the table contents intact, so the steady-
     * state warm path serves stale and schedules a background rebuild
     * without blocking.
     *
     * Note: serveability does NOT depend on the freshness/built_at signal.
     * That signal gates whether to schedule a rebuild (stale = schedule),
     * not whether the existing data can be returned. Serving stale-but-
     * present data is correct: the data is at most one freshness window
     * out of date relative to when it was last produced.
     *
     * The ViewUpdater AJAX path uses this to avoid triggering the inline
     * build inside a request: if not serveable, the fetch returns
     * `viewBuildPending: true` and the JS poller hits ajaxAdvanceViewBuild.
     *
     * @return bool
     */
    public function viewDoneIsServeable(): bool {
        if ($this->viewDoneIsServeableCache !== null) {
            return $this->viewDoneIsServeableCache;
        }
        if (!$this->host->viewDoneTableExists()) {
            $this->viewDoneIsServeableCache = false;
            return false;
        }
        // Empty view_done is NOT serveable when there has never been a
        // successful build: rendering an empty admin screen during a cold
        // start is a worse UX than a brief pending/loading state that
        // drives the build forward. The has-rows probe also catches the
        // rare "S11 promoted an empty buffer" failure mode where the swap
        // completed but S2 produced no rows (botched build state); without
        // this guard the admin would render blank indefinitely with no
        // rebuild ever scheduled.
        //
        // BUT: when a build has actually completed (data_built_at > 0) and
        // the table is genuinely empty (e.g. a fresh install with no
        // redirects yet, or the admin dropped wp_abj404_redirects via
        // WP-CLI and the recreated table is empty), an empty view_done IS
        // the correct serveable result. Returning false here would loop
        // the JS poller forever on a cold install: every build cycle
        // produces an empty view_done, viewDoneIsServeable() returns
        // false, ViewUpdater returns viewBuildPending, the poller fires
        // another advance, and the cycle repeats with no exit.
        // data_built_at distinguishes "build has never completed" from
        // "build completed and the dataset is genuinely empty".
        if ($this->host->viewDoneHasRows()) {
            $this->viewDoneIsServeableCache = true;
            return true;
        }
        if ($this->host->viewDoneDataBuiltAt() > 0) {
            $this->viewDoneIsServeableCache = true;
            return true;
        }
        $this->viewDoneIsServeableCache = false;
        return false;
    }

    /**
     * Invalidate the request-lifetime serveability cache. Called from any
     * code path that mutates view_done (rename/drop/build completion) so a
     * subsequent read in the same request sees fresh state.
     *
     * @return void
     */
    public function invalidateViewDoneServeableCache(): void {
        $this->viewDoneIsServeableCache = null;
    }

    /**
     * Public hook called from the S11 swap completion path and from the
     * reconcile-promote path when a fresh view_done has just been
     * published. Updates both freshness and data-built-at signals to now,
     * clears the hard-stale admin notice (self-heal), and resets the
     * request-lifetime serveability cache so subsequent reads in the same
     * request see the just-published table.
     *
     * The data-built-at signal is the floor used by
     * maybeRaiseViewDoneHardStaleNotice() to decide when to surface the
     * "data may be out of date" admin notice. Updating it here means the
     * notice can self-clear automatically once the build catches up, so an
     * admin who fixed the underlying cron or host issue does not see a
     * 24h-stale warning for the entire dedup TTL after recovery.
     *
     * @return void
     */
    public function markViewDoneBuildCompleted(): void {
        if (function_exists('update_option')) {
            // clock() is an internal orchestrator dependency exposed through
            // the explicit collaborator operation map.
            $now = $this->host->clock()->now();
            update_option($this->host->viewDoneFreshnessOptionName(), $now, false);
            update_option($this->host->viewDoneDataBuiltAtOptionName(), $now, false);
        }
        $this->host->clearViewDoneHardStaleNotice();
        $this->host->invalidateViewDoneServeableCache();
    }
}
