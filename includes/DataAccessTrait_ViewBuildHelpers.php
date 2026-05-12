<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Leaf-utility helpers for the staged view-build pipeline.
 *
 * Three responsibilities, all called from the orchestrator in the sibling
 * trait ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait:
 *
 *   1. Persisted progress tracking: per-stage option-name conventions plus
 *      get/set/clear helpers. Stage runners call readProgressOption /
 *      writeProgressOption to checkpoint resume state across PHP requests.
 *      Includes prefix-at-S1 capture and the SQL mode / max_allowed_packet
 *      session probe.
 *
 *   2. Staged SQL execution: load a SQL template from
 *      includes/sql/getRedirectsForViewStaged/, perform table-name and
 *      placeholder substitutions, route through queryAndGetResults, and
 *      raise a descriptive exception on failure. Plus a duplicate-key
 *      tolerant variant for re-runnable index DDL.
 *
 *   3. Build-side state probes: table existence (view_done, view_build,
 *      view_deleteme) and the view_done freshness / hard-stale-notice gate.
 *
 * Build-writer serialization (GET_LOCK / RELEASE_LOCK + option-row fallback)
 * and the cron rebuild scheduler live on the sibling trait
 * ABJ_404_Solution_DataAccess_ViewBuildLockAndCronTrait. All three traits
 * are mixed into ABJ_404_Solution_DataAccess; properties declared here are
 * visible to the staged-build trait inside the composing class.
 */
trait ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait {

    /** @var int Per-stage timeout in seconds for staged queries; 0 means use queryAndGetResults default. */
    private $stagedQueryTimeoutSeconds = 0;

    /**
     * Captured `$wpdb->prefix` snapshot taken at S1 entry. Compared at every
     * subsequent stage entry to detect mid-build `switch_to_blog()` that
     * would otherwise let S2-S11 run against a different blog's tables and
     * silently corrupt the precomputed view (Codex finding #8).
     *
     * Authoritative for within-request detection: if a `switch_to_blog()`
     * happens mid-request, `$wpdb->prefix` changes but this property does
     * not (it lives on the singleton DAO). The companion option
     * `abj404_view_build_prefix_at_s1` provides cross-request persistence
     * (multisite options tables are per-blog, so the option naturally
     * isolates per-blog: a resume on the same blog finds its capture; a
     * resume after a between-request switch lands on a different options
     * table where current_stage is also 0 and re-runs S1 cleanly).
     *
     * Empty when no build is active. Cleared on S11 completion.
     *
     * @var string
     */
    private $prefixAtStageOne = '';

    /**
     * Persisted progress tracker between requests.  When a stage exits before
     * completing all its batches (PHP timeout, per-stage budget reached), the
     * next request resumes from the stored high-water id.
     *
     * Names are kept short to avoid WP's 191-char option_name index limit
     * even with long table-prefix sites.
     *
     * @var array<string, string>
     */
    private static $viewBuildProgressOptionNames = array(
        'started_at'    => 'abj404_view_build_started_at',
        'current_stage' => 'abj404_view_build_current_stage',
        'last_started_stage' => 'abj404_view_build_last_started_stage',
        'last_started_at'    => 'abj404_view_build_last_started_at',
        'last_completed_stage' => 'abj404_view_build_last_completed_stage',
        'last_completed_at'    => 'abj404_view_build_last_completed_at',
        's2_high_water' => 'abj404_view_build_s2_high_water',
        's4_high_water' => 'abj404_view_build_s4_high_water',
        's5_high_water' => 'abj404_view_build_s5_high_water',
        // Per-stage adaptive batch sizes. When a host kills a batch query at
        // its full per-query limit (genuine batch-too-big), the runtime
        // halves the corresponding entry and persists it so the next tick
        // resumes at the smaller size. Reset to absent on a fresh build via
        // clearAllProgressOptions; preserved across resumes.
        's2_batch_size' => 'abj404_view_build_s2_batch_size',
        's4_batch_size' => 'abj404_view_build_s4_batch_size',
        's5_batch_size' => 'abj404_view_build_s5_batch_size',
        // Per-stage consecutive kill counter for non-batched stages
        // (S3 / S9 / S10). Incremented when a stage's single SQL
        // statement is killed by the host (max_statement_time, gone-away,
        // lock-wait); reset to 0 when the stage completes. When > 0 the
        // next attempt for that stage uses an extended SET STATEMENT
        // timeout that overrides the host's session limit -- the
        // non-batched analog of adaptive batch shrink.
        's3_kill_streak'  => 'abj404_view_build_s3_kill_streak',
        's9_kill_streak'  => 'abj404_view_build_s9_kill_streak',
        's10_kill_streak' => 'abj404_view_build_s10_kill_streak',
        // Per-stage no-progress resumable-kill streak. Counts consecutive
        // ticks where the stage callback raised a resumable-kill error
        // (host kill, lock wait, gone-away) without making any forward
        // progress. After VIEW_BUILD_FLOOR_KILL_STREAK_HALT_THRESHOLD
        // strikes the build halts: the host cannot complete this stage's
        // smallest unit of work so further retries only loop. Reset to
        // 0 on any successful completion or wall-clock yield with
        // progress. Distinct from s{N}_kill_streak: that one extends the
        // per-query timeout for non-batched stages; this one detects
        // genuine "host can never finish" and halts.
        's1_no_progress_streak'  => 'abj404_view_build_s1_no_progress',
        's2_no_progress_streak'  => 'abj404_view_build_s2_no_progress',
        's3_no_progress_streak'  => 'abj404_view_build_s3_no_progress',
        's4_no_progress_streak'  => 'abj404_view_build_s4_no_progress',
        's5_no_progress_streak'  => 'abj404_view_build_s5_no_progress',
        's6_no_progress_streak'  => 'abj404_view_build_s6_no_progress',
        's7_no_progress_streak'  => 'abj404_view_build_s7_no_progress',
        's8_no_progress_streak'  => 'abj404_view_build_s8_no_progress',
        's9_no_progress_streak'  => 'abj404_view_build_s9_no_progress',
        's10_no_progress_streak' => 'abj404_view_build_s10_no_progress',
        's11_no_progress_streak' => 'abj404_view_build_s11_no_progress',
    );

    /**
     * @param string $shortName  One of self::$viewBuildProgressOptionNames keys.
     * @return string  Site-prefixed option name.
     */
    private function progressOptionName(string $shortName): string {
        if (!isset(self::$viewBuildProgressOptionNames[$shortName])) {
            return '';
        }
        return $this->getLowercasePrefix() . self::$viewBuildProgressOptionNames[$shortName];
    }

    /**
     * @param string $shortName  Progress key.
     * @param int    $default
     * @return int
     */
    private function readProgressOption(string $shortName, int $default = 0): int {
        if (!function_exists('get_option')) {
            return $default;
        }
        $name = $this->progressOptionName($shortName);
        if ($name === '') {
            return $default;
        }
        // Broken-cache bypass for high-stakes reads (current_stage,
        // s2/s4/s5_high_water). A prior verifyOptionWriteCoherent() set the
        // abj404_option_cache_incoherent transient because wp_cache_delete +
        // retry could not get a fresh value. Without this bypass the next
        // read of current_stage returns the stale cached 0 and every
        // advanceViewBuildOnce re-enters S1.
        if (in_array($shortName, self::$viewBuildProgressHighStakesShortNames, true)
                && function_exists('get_transient')
                && get_transient('abj404_option_cache_incoherent') !== false
                && function_exists('wp_cache_delete')) {
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
        }
        $value = get_option($name, $default);
        return is_scalar($value) ? max(0, intval($value)) : $default;
    }

    /**
     * Subset of {@see $viewBuildProgressOptionNames} keys whose writes route
     * through {@see verifyOptionWriteCoherent} instead of bare update_option.
     *
     * The helper costs an extra get_option per write (a wp_cache_get on
     * coherent hosts; one extra DB round-trip on hosts that fail the
     * verification). That cost is justified for state where a stale read
     * could cause a destructive stage to re-run or a batch high-water mark
     * to rewind, but not for kill-streak counters or started_at where a
     * single tick of stale data is harmless.
     *
     * @var array<int,string>
     */
    private static $viewBuildProgressHighStakesShortNames = array(
        'current_stage',
        's2_high_water',
        's4_high_water',
        's5_high_water',
    );

    /**
     * @param string $shortName  Progress key.
     * @param int    $value
     * @return void
     */
    private function writeProgressOption(string $shortName, int $value): void {
        if (!function_exists('update_option')) {
            return;
        }
        $name = $this->progressOptionName($shortName);
        if ($name === '') {
            return;
        }
        $intValue = max(0, intval($value));
        // High-stakes view_build_state writes route through the cache-coherent
        // helper (read-back + wp_cache_delete + retry) so a persistent object
        // cache returning a stale value cannot let a parallel worker rewind
        // current_stage or a batch high-water and re-run a destructive stage.
        // Lower-stakes writes use the bare update_option path -- the read-back
        // cost is non-trivial and a single tick of stale streak data is
        // harmless.
        if (in_array($shortName, self::$viewBuildProgressHighStakesShortNames, true)) {
            $writeOk = $this->verifyOptionWriteCoherent($name, $intValue);
            $readBack = function_exists('get_option') ? get_option($name, null) : null;
            $this->logViewBuildProgressOptionWrite($shortName, $name, $intValue, $writeOk, $readBack, 'coherent');
            return;
        }
        // autoload=false so progress writes (potentially many per request)
        // don't bloat the alloptions cache that loads on every WP page.
        $writeOk = update_option($name, $intValue, false);
        $readBack = function_exists('get_option') ? get_option($name, null) : null;
        $this->logViewBuildProgressOptionWrite($shortName, $name, $intValue, $writeOk, $readBack, 'direct');
    }

    /**
     * Log only the stage-resume metadata writes that are needed to diagnose
     * S1 success-vs-progress-write failures without flooding logs for every
     * batched high-water update.
     *
     * @param string $shortName
     * @param string $optionName
     * @param int    $expected
     * @param mixed  $updateReturn
     * @param mixed  $readBack
     * @param string $path
     * @return void
     */
    private function logViewBuildProgressOptionWrite(
        string $shortName,
        string $optionName,
        int $expected,
        $updateReturn,
        $readBack,
        string $path
    ): void {
        if (!in_array($shortName, array(
            'started_at',
            'current_stage',
            'last_started_stage',
            'last_started_at',
            'last_completed_stage',
            'last_completed_at',
        ), true)) {
            return;
        }
        if (!is_object($this->logger) || !method_exists($this->logger, 'debugMessage')) {
            return;
        }

        $readBackForLog = is_scalar($readBack) ? (string)$readBack : gettype($readBack);
        $this->logger->debugMessage(sprintf(
            '[staged] view build progress option write: key=%s option=%s expected=%d path=%s update_option_return=%s read_back=%s',
            $shortName,
            $optionName,
            $expected,
            $path,
            $updateReturn ? 'true' : 'false',
            substr($readBackForLog, 0, 240)
        ));
    }

    /**
     * Cache-coherent option write. Persistent object caches (Redis,
     * Memcached, mu-cluster split routing) can serve a stale `get_option`
     * value for one tick after `update_option` writes the row. For
     * high-stakes options (staged-build current_stage, batch high-water
     * marks) that single tick is enough to let a parallel worker rewind to
     * a just-completed stage and re-run destructive work.
     *
     * Procedure:
     *   1. update_option($name, $expected, autoload=false).
     *   2. get_option($name) and strict-compare to $expected.
     *   3. On mismatch: wp_cache_delete($name, 'options') and the
     *      'alloptions' bucket (covers both keying strategies WP uses), then
     *      update_option + get_option once more.
     *   4. On persistent mismatch: set a 24h transient
     *      'abj404_option_cache_incoherent' carrying name + observed value
     *      so other code can short-circuit cache-coherence-sensitive logic,
     *      log a warning, return false.
     *   5. On success (first or retry): return true.
     *
     * Idempotent and safe to call repeatedly. Loose-equal comparison is
     * intentional: option values round-trip through serialization and
     * scalar coercion, so an int 4 may come back as the string "4".
     *
     * @param string $optionName  WordPress option name (already fully prefixed).
     * @param mixed  $expected    Value just written -- compared against the read-back.
     * @return bool  True on coherent write (first try or retry); false when the
     *               cache layer fails to invalidate even after wp_cache_delete.
     */
    public function verifyOptionWriteCoherent(string $optionName, $expected): bool {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            return false;
        }
        // Capture the prior persisted value so a first-read-back-fail WARN
        // (below, for current_stage only) can carry prior + new + observed,
        // letting support see whether the cache returned the previous value
        // or some unrelated state from a parallel request.
        $prior = get_option($optionName, null);
        update_option($optionName, $expected, false);
        $actual = get_option($optionName, null);
        if ($this->optionReadBackMatches($actual, $expected)) {
            return true;
        }
        // First read disagrees with the just-written value. Surface a WARN
        // for current_stage specifically (the most diagnostically valuable
        // stage-progress key) so the signal survives a site with DEBUG off.
        // Other high-stakes keys (s2/s4/s5_high_water) stay silent on the
        // first miss to avoid log volume; they still hit the persistent-
        // mismatch WARN further down if the retry also fails.
        if ($this->isCurrentStageOptionName($optionName) && is_object($this->logger)
                && method_exists($this->logger, 'warn')) {
            $this->logger->warn(sprintf(
                '[staged] option write incoherent (first read-back) on %s: prior=%s new=%s observed=%s; flushing cache and retrying',
                $optionName,
                is_scalar($prior)    ? (string)$prior    : '<non-scalar>',
                is_scalar($expected) ? (string)$expected : '<non-scalar>',
                is_scalar($actual)   ? (string)$actual   : '<non-scalar>'
            ));
        }
        // Flush both candidate cache keys and retry. Use a typeof-guarded
        // call because wp_cache_delete is part of WP core but not loaded
        // in unit-test bootstraps that don't pull in cache.php.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($optionName, 'options');
            // alloptions is the bundled bucket WP loads on every page; even
            // for autoload=false writes some object-cache backends miss the
            // per-key invalidation and need the bucket flushed.
            wp_cache_delete('alloptions', 'options');
        }
        update_option($optionName, $expected, false);
        $retry = get_option($optionName, null);
        if ($this->optionReadBackMatches($retry, $expected)) {
            return true;
        }

        // Persistent mismatch: surface to other code via a deduplicated
        // transient and log a warning. Don't email -- this is a host-config
        // problem, not a plugin defect.
        if (function_exists('set_transient')) {
            // allow-cache-empty: payload is diagnostic state; empty observed/error fields are still actionable.
            set_transient(
                'abj404_option_cache_incoherent',
                array(
                    'option'   => $optionName,
                    'expected' => is_scalar($expected) ? (string)$expected : 'non-scalar',
                    'observed' => is_scalar($retry) ? (string)$retry : 'non-scalar',
                    'when'     => time(),
                ),
                86400
            );
        }
        if (is_object($this->logger)) {
            $message = sprintf(
                '[staged] option write incoherent on this host: %s expected=%s observed=%s '
                . '(persistent object cache likely returning stale values; '
                . 'wp_cache_delete + retry did not invalidate).',
                $optionName,
                is_scalar($expected) ? (string)$expected : '<non-scalar>',
                is_scalar($retry)    ? (string)$retry    : '<non-scalar>'
            );
            if (method_exists($this->logger, 'warn')) {
                $this->logger->warn($message);
            } elseif (method_exists($this->logger, 'debugMessage')) {
                $this->logger->debugMessage($message);
            }
        }
        return false;
    }

    /**
     * Whether the fully-prefixed option name refers to the view-build
     * `current_stage` key (the prefix component varies by site). Used by
     * verifyOptionWriteCoherent() to scope its first-read-back-fail WARN to
     * the most diagnostically valuable stage-progress key.
     *
     * @param string $optionName
     * @return bool
     */
    private function isCurrentStageOptionName(string $optionName): bool {
        $suffix = self::$viewBuildProgressOptionNames['current_stage'] ?? '';
        if ($suffix === '') {
            return false;
        }
        $len = strlen($suffix);
        return $len > 0 && substr($optionName, -$len) === $suffix;
    }

    /**
     * Loose-equal read-back comparison. WP option values round-trip through
     * serialize() and may come back as a different scalar type than written
     * (int 4 -> string "4"). The semantic question is "did the persisted
     * value reflect the write," so we compare via string casts when both
     * sides are scalar; otherwise fall back to ==.
     *
     * Null asymmetry is treated as a mismatch. PHP's loose-equal would
     * otherwise have `null == 0`, `null == ''`, `null == false` all return
     * true, so a cache layer that served null ("option not found") for a
     * value-of-zero write (s2/s4/s5_high_water reset on a fresh build) would
     * have spuriously passed verification. An unwritten cache slot is not
     * the same value as a written falsy value.
     *
     * @param mixed $actual
     * @param mixed $expected
     * @return bool
     */
    private function optionReadBackMatches($actual, $expected): bool {
        if (($actual === null) !== ($expected === null)) {
            return false;
        }
        if (is_scalar($actual) && is_scalar($expected)) {
            return (string)$actual === (string)$expected;
        }
        return $actual == $expected;
    }

    /** @return void */
    private function clearAllProgressOptions(): void {
        if (!function_exists('delete_option')) {
            return;
        }
        foreach (self::$viewBuildProgressOptionNames as $optName) {
            delete_option($this->getLowercasePrefix() . $optName);
        }
        // The S1 prefix capture lives outside $viewBuildProgressOptionNames
        // because its option name is intentionally not prefix-bound (so a
        // mid-build switch_to_blog cannot make get_option silently miss it).
        // It belongs to the same fresh-start lifecycle, so clear it alongside.
        $this->clearPrefixAtStageOne();
        // Same lifecycle: a fresh build must re-probe the live session so a
        // hosting move that changed sql_mode (or a schema swap that changed
        // max_allowed_packet) is picked up at the next S1 entry. The PHP
        // environment probe (set_time_limit / memory_limit) is reset for the
        // same reason: an ini change between builds must take effect.
        $this->clearSqlModeProbeCache();
        $this->clearPhpEnvironmentProbeCache();
    }

    /**
     * Option name used to persist the `$wpdb->prefix` captured at S1. Kept
     * deliberately NOT site-prefixed so that within a single request we can
     * still tell when `switch_to_blog()` has flipped `$wpdb->prefix` out
     * from under us: the option-key the get_option call computes does not
     * itself depend on the current prefix. (WP's options table itself is
     * per-blog in multisite, which gives the cross-blog isolation we want
     * for the cross-request resume case for free.)
     *
     * @return string
     */
    private function prefixAtStageOneOptionName(): string {
        return 'abj404_view_build_prefix_at_s1';
    }

    /**
     * Snapshot the current `$wpdb->prefix` so subsequent stage entries can
     * detect a mid-build `switch_to_blog()`. Called from runStagedBuildOnce
     * at S1 entry. Idempotent on repeated S1 runs (fresh start clears via
     * clearPrefixAtStageOne first, then captures the live prefix here).
     *
     * @return void
     */
    public function capturePrefixAtBuildStart(): void {
        global $wpdb;
        $prefix = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : '';
        $this->prefixAtStageOne = $prefix;
        if (function_exists('update_option')) {
            update_option($this->prefixAtStageOneOptionName(), $prefix, false);
        }
    }

    /**
     * Compare the live `$wpdb->prefix` against the snapshot taken at S1.
     * Returns true when they match (or no snapshot exists -- fresh blog or
     * pre-S1). Returns false when a mismatch is detected, which is the
     * orchestrator's signal to halt the rebuild before S2-S11 writes
     * against a different blog's tables.
     *
     * Logic:
     *   - In-memory `$this->prefixAtStageOne` is authoritative when set.
     *     `switch_to_blog()` cannot flip an instance property, so any
     *     change in `$wpdb->prefix` after capture is a real mismatch.
     *   - Falls back to the persisted option for cross-request resumes
     *     (the in-memory capture starts empty on each request).
     *   - Empty captured value means S1 has never run on this blog (in
     *     multisite, options are per-blog: a fresh blog has no record
     *     of any past build) -- treat as "nothing to verify".
     *
     * @return bool  False on mismatch (caller should halt the build).
     */
    public function verifyPrefixUnchangedSinceStageOne(): bool {
        global $wpdb;
        $current = (isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : '';

        if ($this->prefixAtStageOne !== '') {
            return $this->prefixAtStageOne === $current;
        }

        if (!function_exists('get_option')) {
            return true;
        }
        $captured = get_option($this->prefixAtStageOneOptionName(), '');
        if (!is_string($captured) || $captured === '') {
            return true;
        }
        return $captured === $current;
    }

    /**
     * Clear the captured S1 prefix so the next rebuild starts fresh.
     * Called after a successful S11 swap and from the explicit force
     * rebuild path in clearStagedBuildDegradedState().
     *
     * @return void
     */
    public function clearPrefixAtStageOne(): void {
        $this->prefixAtStageOne = '';
        if (function_exists('delete_option')) {
            delete_option($this->prefixAtStageOneOptionName());
        }
    }

    /**
     * Read-only accessor for diagnostic logging. Returns the in-memory
     * capture if present, otherwise the persisted option, otherwise ''.
     *
     * @return string
     */
    private function capturedPrefixForLog(): string {
        if ($this->prefixAtStageOne !== '') {
            return $this->prefixAtStageOne;
        }
        if (!function_exists('get_option')) {
            return '';
        }
        $captured = get_option($this->prefixAtStageOneOptionName(), '');
        return is_string($captured) ? $captured : '';
    }

    /**
     * Cached probe result for the current build run: SESSION sql_mode and
     * max_allowed_packet. Populated on first call to probeSqlModeForBuild()
     * within a request. Returned shape:
     *   array{
     *     sql_mode: string,                    // raw flags, e.g. "STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY"
     *     strict_mode_active: bool,            // true if STRICT_TRANS_TABLES or STRICT_ALL_TABLES present
     *     only_full_group_by_active: bool,     // true if ONLY_FULL_GROUP_BY present
     *     no_zero_date_active: bool,           // true if NO_ZERO_DATE / NO_ZERO_IN_DATE present
     *     max_allowed_packet: int,             // bytes (0 if unknown)
     *     adjusted: bool,                      // true if we successfully relaxed sql_mode for the build connection
     *     adjustment_denied: bool,             // true if the relax attempt was rejected (privilege)
     *     truncate_url_to: int                 // 2048 default, smaller when packet is constrained
     *   }
     *
     * @var array<string,mixed>|null
     */
    private $sqlModeProbeCache = null;

    /**
     * Option name used to persist the most recent probe result so a
     * post-mortem on a stuck build can see the exact session config the
     * runner saw at S1 entry. Lives outside the per-request cache so the
     * dashboard can read it across requests.
     *
     * @return string
     */
    private function sqlModeProbeOptionName(): string {
        return 'abj404_view_build_session_probe';
    }

    /**
     * Probe the live MySQL session for sql_mode and max_allowed_packet at
     * staged-build entry, before S1 runs. Persists the result in
     * `view_build_state` for diagnostic purposes and tries to relax
     * STRICT_TRANS_TABLES / ONLY_FULL_GROUP_BY for THIS connection only via
     * `SET SESSION sql_mode = ''`. The S2 INSERT already uses a strict-safe
     * REGEXP-guarded CAST so it survives strict mode regardless; the relax
     * is belt-and-suspenders for any future query the build may issue.
     *
     * Idempotent within a request -- repeat calls return the cached result
     * without re-querying. Cleared by clearSqlModeProbeCache() on a fresh
     * build (alongside clearAllProgressOptions).
     *
     * Public so the orchestrator and tests can call it. The contract test
     * `StagedBuildHostQuirksTest::testStrictSqlModeIsDetectedAndAdjustedOrSurfaced`
     * asserts the method exists; without this, a strict host fails S2 with
     * an unhelpful CAST error.
     *
     * @return array<string,mixed>  See sqlModeProbeCache docblock.
     */
    public function probeSqlModeForBuild(): array {
        if (is_array($this->sqlModeProbeCache)) {
            return $this->sqlModeProbeCache;
        }

        $result = array(
            'sql_mode'                  => '',
            'strict_mode_active'        => false,
            'only_full_group_by_active' => false,
            'no_zero_date_active'       => false,
            'max_allowed_packet'        => 0,
            'adjusted'                  => false,
            'adjustment_denied'         => false,
            'truncate_url_to'           => 2048,
        );

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            $this->sqlModeProbeCache = $result;
            return $result;
        }
        /** @var \wpdb $wpdb */

        // Round-trip both probes in one query: avoids two protocol hops on
        // slow shared hosts. Suppress wpdb's own error display because some
        // hosts revoke @@SESSION reads (rare but real on certain ProxySQL
        // routings) and we want to fail soft.
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            // DAO-bypass-approved: probe live @@SESSION on this connection.
            $row = $wpdb->get_row(
                "SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.max_allowed_packet AS max_allowed_packet",
                ARRAY_A
            );
        } catch (\Throwable $e) {
            $row = null;
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }

        if (is_array($row)) {
            // Case-insensitive key lookup (some MySQL drivers normalize column case).
            foreach ($row as $k => $v) {
                $klow = strtolower((string)$k);
                if ($klow === 'sql_mode' && is_scalar($v)) {
                    $result['sql_mode'] = (string)$v;
                } elseif ($klow === 'max_allowed_packet' && is_scalar($v)) {
                    $result['max_allowed_packet'] = (int)$v;
                }
            }
        }

        $modeUpper = strtoupper($result['sql_mode']);
        $result['strict_mode_active'] = (
            strpos($modeUpper, 'STRICT_TRANS_TABLES') !== false ||
            strpos($modeUpper, 'STRICT_ALL_TABLES') !== false
        );
        $result['only_full_group_by_active'] = (strpos($modeUpper, 'ONLY_FULL_GROUP_BY') !== false);
        $result['no_zero_date_active'] = (
            strpos($modeUpper, 'NO_ZERO_DATE') !== false ||
            strpos($modeUpper, 'NO_ZERO_IN_DATE') !== false
        );

        // If max_allowed_packet < 1MB, leave headroom for SQL framing
        // (column names, escapes, repeated values) by truncating URL inputs
        // to floor(packet * 0.4). Leaves >50% packet room for the rest of
        // the row payload. Above 1MB we keep the schema's 2048-char ceiling.
        $packet = (int)$result['max_allowed_packet'];
        if ($packet > 0 && $packet < 1048576) {
            $result['truncate_url_to'] = max(255, (int)floor($packet * 0.4));
            $this->logger->warn(sprintf(
                '[staged] max_allowed_packet=%d (<1MB); URL inputs will be truncated to %d chars to leave room for SQL framing.',
                $packet, $result['truncate_url_to']
            ));
        }

        // If strict mode or ONLY_FULL_GROUP_BY is active, attempt to relax
        // it for THIS connection only (no global change, no other clients
        // affected). This is best-effort: managed hosts may deny the SET.
        // The S2 INSERT already uses a strict-safe CAST so the build
        // survives a denied relax; the warn below makes it diagnosable.
        if ($result['strict_mode_active'] || $result['only_full_group_by_active']) {
            $relaxed = $this->attemptRelaxSqlModeForBuildConnection($result['sql_mode']);
            $result['adjusted'] = $relaxed === true;
            $result['adjustment_denied'] = $relaxed === false;
            if ($result['adjustment_denied']) {
                $this->logger->warn(sprintf(
                    '[staged] sql_mode contains STRICT_TRANS_TABLES / ONLY_FULL_GROUP_BY (%s) and the relax attempt was denied. The S2 INSERT is strict-safe via REGEXP-guarded CAST; build will proceed.',
                    $result['sql_mode']
                ));
            } elseif ($result['adjusted']) {
                $this->logger->infoMessage(sprintf(
                    '[staged] Relaxed sql_mode for build connection (was: %s).',
                    $result['sql_mode']
                ));
            }
        }

        // @cache-write-audit: opt-out - $result is a captured snapshot of
        // session-variable state used for diagnostics, not a cached query
        // result. A failed SHOW VARIABLES populates defaults that are still
        // safe to persist (the dashboard reader treats sql_mode=='' as a
        // probe failure and skips its row).
        if (function_exists('update_option')) {
            update_option($this->sqlModeProbeOptionName(), $result, false);
        }

        $this->sqlModeProbeCache = $result;
        return $result;
    }

    /**
     * Alias kept for the alternative contract phrasing in
     * StagedBuildHostQuirksTest. Returns the same probe result.
     *
     * @return array<string,mixed>
     */
    public function detectAndAdjustSqlMode(): array {
        return $this->probeSqlModeForBuild();
    }

    /**
     * Strip STRICT_TRANS_TABLES / STRICT_ALL_TABLES / ONLY_FULL_GROUP_BY /
     * NO_ZERO_DATE / NO_ZERO_IN_DATE from the supplied sql_mode string and
     * issue `SET SESSION sql_mode = '<remaining>'`. Returns true on success,
     * false on denial, null when wpdb is unavailable.
     *
     * @param string $currentSqlMode
     * @return bool|null
     */
    private function attemptRelaxSqlModeForBuildConnection(string $currentSqlMode): ?bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'query')) {
            return null;
        }
        /** @var \wpdb $wpdb */
        $flags = array_filter(array_map('trim', explode(',', $currentSqlMode)));
        $strip = array(
            'STRICT_TRANS_TABLES',
            'STRICT_ALL_TABLES',
            'ONLY_FULL_GROUP_BY',
            'NO_ZERO_DATE',
            'NO_ZERO_IN_DATE',
            'TRADITIONAL', // umbrella that re-enables strict
        );
        $relaxed = array();
        foreach ($flags as $flag) {
            $upper = strtoupper($flag);
            if (in_array($upper, $strip, true)) {
                continue;
            }
            $relaxed[] = $flag;
        }
        $newMode = implode(',', $relaxed);
        // @utf8-audit: opt-out - sql_mode flags are server-controlled
        // uppercase ASCII identifiers (STRICT_TRANS_TABLES, ONLY_FULL_GROUP_BY,
        // etc.); $newMode is built from filtered $flags whose source is
        // SHOW SESSION VARIABLES output, never user input.
        $escaped = function_exists('esc_sql') ? esc_sql($newMode) : str_replace("'", "''", $newMode);
        $escapedStr = is_array($escaped) ? '' : (string)$escaped;
        $prevSuppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
        try {
            // DAO-bypass-approved: SET SESSION must run on the live wpdb connection.
            $ok = $wpdb->query("SET SESSION sql_mode = '" . $escapedStr . "'");
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (method_exists($wpdb, 'suppress_errors')) {
            $wpdb->suppress_errors($prevSuppress);
        }
        $err = $wpdb->last_error;
        if ($ok === false || $err !== '') {
            return false;
        }
        return true;
    }

    /** @return void */
    private function clearSqlModeProbeCache(): void {
        $this->sqlModeProbeCache = null;
        if (function_exists('delete_option')) {
            delete_option($this->sqlModeProbeOptionName());
        }
        // The session-variables probe (operational + DDL-safety MySQL vars)
        // shares the same lifecycle as the sql_mode probe: a fresh build must
        // re-evaluate session config in case the host was tuned between runs.
        // Trait method lives on ABJ_404_Solution_DataAccess_ViewBuildSessionEnvProbeTrait.
        $this->clearSessionVariablesProbeCache();
    }

    // PHP-runtime environment probe (set_time_limit / memory_limit) lives
    // on the sibling ABJ_404_Solution_DataAccess_ViewBuildPhpEnvProbeTrait.
    // The MySQL-session operational + DDL-safety probe lives on the sibling
    // ABJ_404_Solution_DataAccess_ViewBuildSessionEnvProbeTrait.
    // clearAllProgressOptions() above calls clearPhpEnvironmentProbeCache()
    // and (via clearSqlModeProbeCache) clearSessionVariablesProbeCache so a
    // fresh build re-evaluates the host on next entry.

    /**
     * Sanitize a URL string at the build/log boundary so a NULL byte or a
     * pathological length cannot reach the SQL layer. Behavior:
     *   - Strip ASCII NULL (\x00) bytes and other low control bytes (\x01-\x08,
     *     \x0B, \x0C, \x0E-\x1F, \x7F) that wpdb->prepare() would otherwise
     *     reject with "could not execute query, contains invalid data".
     *   - Truncate to the cap returned by the most recent
     *     probeSqlModeForBuild() (default 2048 == varchar(2048) ceiling on
     *     the redirects table; smaller when max_allowed_packet < 1MB).
     *
     * Public so the 404-listener boundary and the staged-build entry both
     * route through one sanitizer (same input rules everywhere). The
     * contract tests `testNullByteInUrlRejectedAtBoundaryNotInSqlLayer` and
     * `testUrlLongerThan2048CharsTruncatedOrRejectedAtBoundary` assert the
     * method exists; the implementation is what makes the gastroinovace.cz
     * 2,800x error-mailbox flood (mid-2024) stay fixed.
     *
     * @param string $url Raw URL captured from $_SERVER['REQUEST_URI'] or wpdb input.
     * @param int    $maxLength Optional override; 0 means "use the probe-derived cap".
     * @return string
     */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string {
        if ($url === '') {
            return '';
        }
        // Strip NULL bytes and control bytes BEFORE truncation so a
        // multi-byte sequence at the cap doesn't get split mid-byte and
        // become a partial NULL.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $url);
        if (!is_string($clean)) {
            $clean = $url;
        }
        if ($maxLength <= 0) {
            $probe = is_array($this->sqlModeProbeCache) ? $this->sqlModeProbeCache : null;
            $maxLength = ($probe !== null && isset($probe['truncate_url_to']))
                ? max(255, (int)$probe['truncate_url_to'])
                : 2048;
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($clean) > $maxLength) {
                $clean = mb_substr($clean, 0, $maxLength);
            }
        } elseif (strlen($clean) > $maxLength) {
            $clean = substr($clean, 0, $maxLength);
        }
        return $clean;
    }

    /**
     * Execute a staged SQL file with placeholder substitution and the
     * standard error-handling pipeline.
     *
     * On failure, the error message is prefixed with the file name and any
     * batch bounds present in $extraTranslations so the GUI's "stage N
     * failed" notice carries actionable context.  The current sub-stage
     * label set by markBuildStage() remains in place so the AJAX shutdown
     * handler renders the correct stageNumber/queryLabel.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    private function runStagedSqlFile(string $relativePath, array $extraTranslations): void {
        $path = __DIR__ . '/sql/getRedirectsForViewStaged/' . $relativePath;
        $template = ABJ_404_Solution_Functions::readFileContents($path);
        if (!is_string($template) || trim($template) === '') {
            throw new \Exception("Staged SQL template missing or empty: $relativePath");
        }
        $sql = $this->doTableNameReplacements($template);
        // extraTranslations (status_for_view / type_for_view labels, batch
        // bounds) must run BEFORE doNormalReplacements: doNormalReplacements
        // falls back to __() for any {key} it does not know, which strips
        // the braces and prevents the str_replace below from matching.
        if (!empty($extraTranslations)) {
            $sql = $this->f->str_replace(array_keys($extraTranslations), array_values($extraTranslations), $sql);
        }
        $sql = $this->f->doNormalReplacements($sql);
        $result = $this->queryAndGetResults($sql, $this->stagedQueryOptions());
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '') {
            $context = $this->describeStagedSqlFailure($relativePath, $extraTranslations);
            throw new \Exception('Staged SQL ' . $context . ' failed: ' . $err);
        }
    }

    /**
     * Same as runStagedSqlFile but silently tolerates "Duplicate key name"
     * errors so an interrupted ALTER TABLE ADD INDEX can be safely re-run
     * on a request that resumes a prior partially-completed build.  All
     * other errors are raised as usual.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    private function runStagedSqlFileTolerantOfDuplicateKey(string $relativePath, array $extraTranslations): void {
        try {
            $this->runStagedSqlFile($relativePath, $extraTranslations);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate key name') !== false
                || stripos($msg, 'errno: 1061') !== false) {
                // The index already exists from a prior partial run; the
                // expected resume-time state, not a failure. Log at debug so
                // a "why did this stage take 0ms" question has an answer.
                $this->logger->debugMessage(sprintf(
                    '[staged] %s: index already exists, tolerated as resume.',
                    $relativePath
                ));
                return;
            }
            throw $e;
        }
    }

    /**
     * Render a short human-readable description of which file + which batch
     * bounds were running when an error fired.  Used to enrich error
     * messages so the GUI notice lists the exact failing slice.
     *
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return string
     */
    private function describeStagedSqlFailure(string $relativePath, array $extraTranslations): string {
        $parts = array($relativePath);
        if (isset($extraTranslations['{LO_BOUND}'])) {
            $parts[] = 'lo=' . $extraTranslations['{LO_BOUND}'];
        }
        if (isset($extraTranslations['{HI_BOUND}'])) {
            $parts[] = 'hi=' . $extraTranslations['{HI_BOUND}'];
        }
        if (isset($extraTranslations['{BATCH_SIZE}'])) {
            $parts[] = 'limit=' . $extraTranslations['{BATCH_SIZE}'];
        }
        return implode(' ', $parts);
    }

    /**
     * Render a short human-readable summary of how far a resumable build has
     * progressed.  Used in the admin notice and the throw message when a
     * request can't yet serve view_done because the build is still running
     * across requests.
     *
     * @return string  e.g. "stage 2/11, 3000/12000 rows" or "not yet started".
     */
    private function describeBuildProgressForNotice(): string {
        $stage = $this->readProgressOption('current_stage', 0);
        if ($stage <= 0) {
            return 'not yet started';
        }
        $parts = array('stage ' . $stage . '/11');
        if ($stage < 2) {
            // S2 is the heaviest; surface buffer/redirect counts.
            $copied = $this->countViewBuildRows();
            $total = $this->countLiveRedirects();
            if ($total > 0) {
                $parts[] = $copied . '/' . $total . ' rows';
            }
        }
        return implode(', ', $parts);
    }

    /**
     * @return array<string, mixed> Options for queryAndGetResults that
     * inherit the warmup pipeline's per-stage timeout when set.
     */
    private function stagedQueryOptions(): array {
        if ($this->stagedQueryTimeoutSeconds > 0) {
            return array('timeout' => $this->stagedQueryTimeoutSeconds);
        }
        return array();
    }

    /** @return bool */
    private function viewDoneTableExists(): bool {
        return $this->stagedTableExists($this->viewDoneTableName());
    }

    /**
     * Cheap "does view_done have at least one row" probe used by
     * viewDoneIsServeable() to make the post-invalidate stale-but-present
     * decision honest. Without this, viewDoneIsServeable() might report a
     * just-promoted-but-empty buffer as serveable; the admin would render
     * an empty redirects screen indefinitely with no rebuild scheduled.
     *
     * SELECT 1 ... LIMIT 1 is the cheapest existence query MySQL can do;
     * within a request the result is memoized inside viewDoneIsServeable()
     * so the probe fires once even on hot AJAX paths.
     *
     * @return bool
     */
    private function viewDoneHasRows(): bool {
        if (!$this->viewDoneTableExists()) {
            return false;
        }
        $sql = 'SELECT 1 FROM `' . $this->viewDoneTableName() . '` LIMIT 1';
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return !empty($rows);
    }

    /**
     * Option name for the floor timestamp on the data currently stored in
     * the view_done table. Distinct from viewDoneFreshnessOptionName():
     *
     *   - viewDoneFreshnessOptionName() (built_at): cleared on invalidate.
     *     "Last build that has not been invalidated." Drives the freshness
     *     TTL gate that decides whether to schedule a background rebuild.
     *
     *   - viewDoneDataBuiltAtOptionName() (data_built_at): preserved across
     *     invalidate. "When was the snapshot currently on disk produced."
     *     Drives the hard-stale notice and lets us answer "how old is the
     *     data the admin is looking at" honestly even after invalidation.
     *
     * @return string
     */
    private function viewDoneDataBuiltAtOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_view_done_data_built_at';
    }

    /**
     * Unix timestamp when the data currently in the view_done table was
     * produced. Survives invalidateViewDone() so the read path can compute
     * an honest "data on disk is N hours old" age regardless of whether the
     * freshness signal has been cleared.
     *
     * @return int
     */
    private function viewDoneDataBuiltAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $built = get_option($this->viewDoneDataBuiltAtOptionName(), 0);
        return is_scalar($built) ? max(0, intval($built)) : 0;
    }

    /**
     * Set a deduplicated admin notice when the data in view_done is older
     * than VIEW_DONE_HARD_STALE_NOTICE_AGE_SECONDS. Surfaced on the plugin's
     * own admin screen by abj404_show_view_build_cron_notices in
     * 404-solution.php; never sent via email or shown wp-admin-wide.
     *
     * Same 24h dedup TTL as the other view-build notices so the three
     * notice families share a consistent lifecycle.
     *
     * @param int $ageSeconds Current age of data on disk.
     * @return void
     */
    private function setViewDoneHardStaleNotice(int $ageSeconds): void {
        if (!function_exists('set_transient')) {
            return;
        }
        $key = 'abj404_view_done_hard_stale';
        if (function_exists('get_transient') && get_transient($key) !== false) {
            return; // dedup window still active
        }
        $hours = max(1, intval(floor($ageSeconds / 3600)));
        $template = $this->localizeOrDefaultViewBuildNotice(
            'The 404 Solution redirects table data is more than %d hours old. '
            . 'A background rebuild is scheduled but has not completed; the '
            . 'redirects screen is showing the most recent successful snapshot. '
            . 'Check WordPress cron health and the staged-build progress.'
        );
        $payload = array(
            'type'         => 'view_done_hard_stale',
            'message'      => sprintf($template, $hours),
            'timestamp'    => time(),
            'error_string' => '',
            'age_hours'    => $hours,
        );
        // allow-cache-empty: notice payload is intentional; error_string is empty by definition for stale-data state.
        set_transient($key, $payload, ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS);
    }

    /**
     * Self-heal: clear the hard-stale notice when a successful build
     * completes and the data on disk is no longer stale. Called from
     * markViewDoneBuildCompleted() so the notice does not linger for the
     * full 24h dedup TTL after the build catches up.
     *
     * @return void
     */
    private function clearViewDoneHardStaleNotice(): void {
        if (function_exists('delete_transient')) {
            delete_transient('abj404_view_done_hard_stale');
        }
    }

    /**
     * Read-path hook: when serving stale data from view_done, surface the
     * hard-stale notice if the data is older than the configured threshold.
     *
     * No-ops when data_built_at is missing (legacy installs that pre-date
     * the data-built-at signal) so a one-time migration does not generate
     * spurious 24h notices on the first read after upgrade. The next
     * successful build sets the signal and from then on the staleness
     * check is honest.
     *
     * @return void
     */
    private function maybeRaiseViewDoneHardStaleNotice(): void {
        $built = $this->viewDoneDataBuiltAt();
        if ($built <= 0) {
            return;
        }
        $age = time() - $built;
        if ($age >= ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_HARD_STALE_NOTICE_AGE_SECONDS) {
            $this->setViewDoneHardStaleNotice($age);
        }
    }

    /** @param string $tableName @return bool */
    private function stagedTableExists(string $tableName): bool {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return false;
        }
        /** @var \wpdb $wpdb */
        // DAO-bypass-approved: prepare only; execution still routes through queryAndGetResults().
        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $tableName);
        if (!is_string($sql) || $sql === '') {
            return false;
        }
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return false;
        }
        $first = $rows[0];
        $first = is_array($first) ? $first : array();
        $value = reset($first);
        $valueStr = is_scalar($value) ? (string)$value : '';
        return ($valueStr === $tableName);
    }

    /** @return bool */
    private function viewDoneIsFresh(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $built = get_option($this->viewDoneFreshnessOptionName(), 0);
        $builtAt = is_scalar($built) ? intval($built) : 0;
        if ($builtAt <= 0) {
            return false;
        }
        return (time() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS;
    }

    /**
     * Tiny helper so the staged-build notices read the same way as the
     * existing setPluginDbNotice() copy: call __() when WordPress is loaded,
     * otherwise return the raw English. Kept local to the helpers trait
     * (rather than DataAccess.php's private localizeOrDefault()) so the
     * sibling lock-and-cron trait can reach it via $this-> on the composing
     * class without exposing the private DataAccess method.
     *
     * @param string $text
     * @return string
     */
    private function localizeOrDefaultViewBuildNotice(string $text): string {
        if (function_exists('__')) {
            return __($text, '404-solution');
        }
        return $text;
    }
}
