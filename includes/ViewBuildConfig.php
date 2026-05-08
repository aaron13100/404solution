<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tunable constants for the staged getRedirectsForView rebuild pipeline.
 *
 * These would naturally live as `const` declarations on
 * ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait, but PHP 7.4 (the
 * declared minimum, see Requires PHP in 404-solution.php) does not allow
 * constants inside a trait body, that arrived in PHP 8.2. They sat on
 * ABJ_404_Solution_DataAccess for one release (4.1.14) and pushed that
 * file past the 1500-line cap, so they were extracted here.
 *
 * Override at runtime via the corresponding define() (for build-stage
 * timeout tuning, see ABJ404_VIEW_BUILD_PER_STAGE_BUDGET_SECONDS) or via
 * the matching abj404_view_build_* filters where wired.
 */
final class ABJ_404_Solution_ViewBuildConfig {

    const VIEW_DONE_FRESHNESS_TTL_SECONDS = 120;
    const VIEW_DONE_BUILD_LOCK_NAME = 'abj404_view_build';

    /**
     * Default batch size for the resumable bulk INSERT (S2) and per-id-range
     * UPDATEs (S4/S5). Tuned to fit comfortably within a single per-query
     * timeout on a slow shared host (5 to 10s typical). Override via define()
     * or the abj404_view_build_batch_size filter.
     */
    const VIEW_BUILD_DEFAULT_BATCH_SIZE = 2000;

    /**
     * Floor for the adaptive batch shrink. When a stage's batch query is
     * killed by the host (max_statement_time, lock-wait), the runtime halves
     * the per-stage batch size and persists the new value so the next tick
     * resumes at the smaller size. Floor at this value so we never spin on
     * a 1-row batch that adds N database round-trips per row.
     *
     * Lowered from 50 to 10 (2026-05-08, deadline-math-audit-2026-05-08.md
     * concern #1). The previous 50 was high enough that on the tightest
     * shared hosts (max_statement_time = 3, slow disk, big wp_posts JOIN)
     * EVERY batch at floor size would still be killed, locking the build
     * into a runaway shrink loop. The fingerprint never advances because
     * killed batches yield before writing high-water, so the JS poller
     * trips its no-progress deadline (240s) and gives up. 10 is small
     * enough to actually finish on hosts where 50 cannot, slow but
     * progressing.
     */
    const VIEW_BUILD_MIN_BATCH_SIZE = 10;

    /**
     * Max wall-clock time a single request will spend executing batches in
     * any one stage before yielding so the request can finish. Resumable
     * builds pick up the remaining batches on the next request (driven by
     * WP-Cron or by JS poll-triggered re-requests).
     *
     * Set to match VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS (28s) so a
     * single staged query has the full warmup query timeout to complete
     * within one budget tick. The prior 10s value caused Bruno Martinez's
     * 484K-row install (May 2026) to yield mid-stage before any single
     * INSERT batch could finish on a slow shared host, so the build never
     * made forward progress within the JS poller's deadline.
     *
     * Higher values (>28s) start to risk PHP killing the request at
     * max_execution_time on shared hosts that default to 30s; the build
     * still resumes safely on the next request via MAX(id) on the buffer,
     * but a graceful yield is preferable.
     */
    const VIEW_BUILD_PER_STAGE_BUDGET_SECONDS = 28;

    /**
     * After this many seconds with no progress, an abandoned partial build
     * is considered stale: the buffer table and high-water options are
     * dropped on the next entry and the build restarts from scratch.
     *
     * Bumped from 600 to 3600 (2026-05-08, deadline-math-audit-2026-05-08.md
     * concern #3). On Bruno-scale installs (484K redirects, slow shared
     * host) a full build can legitimately take longer than 10 minutes; if
     * the user closes the browser mid-build, the prior 600s TTL would
     * discard the partial buffer on the next visit and force a fresh
     * restart, so the build never converged across sessions. 3600s (1
     * hour) is long enough to survive a normal user session gap while
     * still bounding stale-buffer disk cost.
     */
    const VIEW_BUILD_RESUME_TTL_SECONDS = 3600;

    const VIEW_BUILD_FOREGROUND_LEASE_SECONDS = 120;

    /**
     * Cap on the per-query SET STATEMENT max_statement_time hint applied
     * to a non-batched stage (S3 / S9 / S10) on retry after a kill. The
     * hint is also bounded by the request's remaining PHP execution time
     * minus a 2s safety margin -- this constant is the absolute ceiling
     * regardless of how much PHP time is left.
     *
     * 240s matches the JS poller's no-progress deadline: if a single
     * non-batched query needs longer than that, the user-facing UI gives
     * up anyway, so giving the query more time would only delay the
     * eventual failure.
     */
    const VIEW_BUILD_NON_BATCHED_KILL_RETRY_CAP_SECONDS = 240;

    private function __construct() {}
}
