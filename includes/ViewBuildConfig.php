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
     * Max wall-clock time a single request will spend executing batches in
     * any one stage before yielding so the request can finish. Resumable
     * builds pick up the remaining batches on the next request (driven by
     * WP-Cron or by JS poll-triggered re-requests).
     */
    const VIEW_BUILD_PER_STAGE_BUDGET_SECONDS = 10;

    /**
     * After this many seconds with no progress, an abandoned partial build
     * is considered stale: the buffer table and high-water options are
     * dropped on the next entry and the build restarts from scratch.
     */
    const VIEW_BUILD_RESUME_TTL_SECONDS = 600;

    const VIEW_BUILD_FOREGROUND_LEASE_SECONDS = 120;

    private function __construct() {}
}
