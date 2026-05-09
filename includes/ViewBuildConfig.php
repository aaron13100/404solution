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
     * Safety-net TTL for the option-row used as the fallback build lock on
     * managed/sharded MySQL providers (PlanetScale, Vitess, ProxySQL splits)
     * where session-scoped GET_LOCK is unavailable.  Sized at one full
     * advance-tick budget plus headroom: a single request never holds the
     * lock longer than VIEW_BUILD_PER_STAGE_BUDGET_SECONDS, but a crashed
     * request must not strand the lock indefinitely.  Production code
     * always releases the option in `releaseViewBuildLock`; this TTL only
     * applies when the releasing request died (PHP fatal, OOM, lost
     * connection) and the next worker needs to take over.
     */
    const VIEW_BUILD_TRANSIENT_LOCK_TTL_SECONDS = 600;

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

    /**
     * Floor-kill streak threshold. After this many consecutive kills at
     * VIEW_BUILD_MIN_BATCH_SIZE on the same stage, the build halts: the
     * host cannot finish the plugin's smallest unit of work, retrying
     * will only loop forever and trip the JS poller's no-progress
     * deadline. Surfaces as a "host_unfit" admin notice.
     */
    const VIEW_BUILD_FLOOR_KILL_STREAK_HALT_THRESHOLD = 5;

    /**
     * TTL for the deduplicated admin notice transients raised when a
     * stage is permanently skipped or the build halts. One notice per
     * 24h per failure type per the self-healing reliability rules in
     * CLAUDE.md (notices on the plugin's own admin screen, never email,
     * never wp-admin-wide banner).
     */
    const VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS = 86400;

    /**
     * Per-stage failure policy used by the host-failure classifier. When
     * a stage's callback raises an error that the classifier identifies
     * as a permanent host-side constraint (access denied, read-only,
     * disk-full, quota), this map decides whether the build can degrade
     * gracefully past the stage ('optional': skip + advance) or must
     * stop retrying and surface a critical notice ('critical': halt).
     *
     * Optional stages (skippable on permanent failure):
     *   - S3 (ALTER ADD INDEX on view_build): build runs slower without
     *     the index, but completes correctly.
     *   - S9 (CREATE TEMPORARY hits aggregate): hit-count column is
     *     null/0 but the rest of the redirect listing is intact.
     *   - S10 (ALTER ADD sort indexes): sorted reads slower, correct.
     *
     * Critical stages (halt on permanent failure):
     *   - S1 (create build buffer): nothing can run without the buffer.
     *   - S2 (insert redirects): empty buffer means empty published view.
     *   - S4-S8 (UPDATE-JOIN against wp_posts/wp_terms/external/special):
     *     resolved fields are mandatory for the rendered view; partial
     *     resolution would produce a broken admin screen.
     *   - S11 (RENAME swap): without the swap, view_done is never
     *     published and the build is wasted work.
     *
     * @var array<int, string>
     */
    private const STAGE_FAILURE_POLICY = array(
        1  => 'critical',
        2  => 'critical',
        3  => 'optional',
        4  => 'critical',
        5  => 'critical',
        6  => 'critical',
        7  => 'critical',
        8  => 'critical',
        9  => 'optional',
        10 => 'optional',
        11 => 'critical',
    );

    /**
     * Look up the per-stage failure policy. Stages not registered in
     * STAGE_FAILURE_POLICY default to 'critical' (fail safely: any
     * unknown stage that fails permanently halts rather than silently
     * skipping data the user expects).
     *
     * @param int $stageNumber  1-based staged build number.
     * @return string  'optional' or 'critical'.
     */
    public static function stageFailurePolicy(int $stageNumber): string {
        return self::STAGE_FAILURE_POLICY[$stageNumber] ?? 'critical';
    }

    /** Recommended floor for memory_limit (128M) in the PHP env probe. */
    const PHP_MEMORY_LIMIT_RECOMMENDED_BYTES = 134217728;

    /** Floor for free space on @@tmpdir's volume before warning (100MB). */
    const PHP_TMPDIR_FREE_FLOOR_BYTES = 104857600;

    /**
     * Out-of-range thresholds for the operational + DDL-safety MySQL session
     * variables probed at S1 entry.  Centralized here (rather than on the
     * trait) because PHP < 8.2 forbids constants in trait bodies and the
     * plugin supports 7.4+.
     */
    const SESSION_PROBE_THRESHOLDS = array(
        'innodb_lock_wait_timeout_min'         => 30,
        'tmp_table_size_min'                   => 16777216,
        'max_heap_table_size_min'              => 16777216,
        'long_query_time_min'                  => 1.0,
        'innodb_buffer_pool_size_min'          => 268435456,
        'wait_timeout_min'                     => 600,
        'interactive_timeout_min'              => 600,
        'thread_stack_min'                     => 196608,
        'open_files_limit_min'                 => 1024,
        'innodb_online_alter_log_max_size_min' => 134217728,
    );

    private function __construct() {}
}
