<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persisted progress-checkpoint state for the staged view-build pipeline.
 *
 * Owns three pieces of cross-request state:
 *
 *   1. The per-stage progress option-name registry, plus get / set /
 *      delete helpers. Stage runners call readProgressOption /
 *      writeProgressOption to checkpoint resume state across PHP
 *      requests (current_stage, sN_high_water, sN_kill_streak,
 *      sN_no_progress_streak).
 *
 *   2. Cache-coherence verification on high-stakes writes (current_stage,
 *      s2/s4/s5_high_water): persistent object caches that serve stale
 *      values can let a parallel worker rewind progress; the helper
 *      retries with cache flushes and surfaces a 24h transient on
 *      persistent mismatch.
 *
 *   3. The $wpdb->prefix snapshot captured at S1 entry, used to detect a
 *      mid-build switch_to_blog() before subsequent stages corrupt a
 *      different blog's tables.
 *
 * Staged SQL execution lives on ABJ_404_Solution_ViewBuildStagedSqlExecutor.
 * Build-side existence / freshness probes live on
 * ABJ_404_Solution_ViewBuildStateProbe. All three classes plus
 * ABJ_404_Solution_ViewBuildLockCoordinator and the host-environment probes are
 * registered as ViewBuildOrchestrator collaborators; properties declared here
 * are visible across the family via reflection-routed __get / __set on the
 * orchestrator.
 *
 * @property ABJ_404_Solution_Logging $logger
 * @method string getLowercasePrefix(...$arguments)
 * @method void clearSqlModeProbeCache(...$arguments)
 * @method void clearPhpEnvironmentProbeCache(...$arguments)
 * @method void dropTransientStagedTables(...$arguments)
 */
class ABJ_404_Solution_ViewBuildProgressOptions extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Captured `$wpdb->prefix` snapshot taken at S1 entry. Compared at every
     * subsequent stage entry to detect mid-build `switch_to_blog()` that
     * would otherwise let S2-S11 run against a different blog's tables and
     * silently corrupt the precomputed view (Codex finding #8).
     *
     * Authoritative for within-request detection: if a `switch_to_blog()`
     * happens mid-request, `$wpdb->prefix` changes but this property does
     * not (it lives on the singleton orchestrator). The companion option
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
     * @param string $shortName  One of self::$viewBuildProgressOptionNames keys.
     * @return string  Site-prefixed option name.
     */
    public function progressOptionName(string $shortName): string {
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
    public function readProgressOption(string $shortName, int $default = 0): int {
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
     * @param string $shortName  Progress key.
     * @param int    $value
     * @return void
     */
    public function writeProgressOption(string $shortName, int $value): void {
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
    public function logViewBuildProgressOptionWrite(
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
    public function isCurrentStageOptionName(string $optionName): bool {
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
    public function optionReadBackMatches($actual, $expected): bool {
        if (($actual === null) !== ($expected === null)) {
            return false;
        }
        if (is_scalar($actual) && is_scalar($expected)) {
            return (string)$actual === (string)$expected;
        }
        return $actual == $expected;
    }

    /** @return void */
    public function clearAllProgressOptions(): void {
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
     * One-call cleanup for the fresh-start branch of runStagedBuildOnce:
     * scrap progress options (registry + Phase-2 active stamp), drop any
     * leftover buffer tables. Pulled out of the orchestrator so the
     * body line count stays within the per-function cap.
     *
     * @return void
     */
    public function performFreshStartCleanup(): void {
        $this->clearAllProgressOptions();
        $this->dropTransientStagedTables();
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
    public function prefixAtStageOneOptionName(): string {
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
    public function capturedPrefixForLog(): string {
        if ($this->prefixAtStageOne !== '') {
            return $this->prefixAtStageOne;
        }
        if (!function_exists('get_option')) {
            return '';
        }
        $captured = get_option($this->prefixAtStageOneOptionName(), '');
        return is_string($captured) ? $captured : '';
    }
}
