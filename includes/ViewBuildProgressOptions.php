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
 * Cache-coherence verification for high-stakes writes lives on
 * ABJ_404_Solution_ViewBuildOptionWriteVerifier. Prefix drift detection lives
 * on ABJ_404_Solution_ViewBuildPrefixDriftGuard. Staged SQL execution lives on
 * ABJ_404_Solution_ViewBuildStagedSqlExecutor. Build-side existence /
 * freshness probes live on ABJ_404_Solution_ViewBuildStateProbe. These
 * classes plus ABJ_404_Solution_ViewBuildLockCoordinator and the
 * host-environment probes are registered as ViewBuildOrchestrator
 * collaborators and use the orchestrator's explicit operation map for
 * cross-class calls.
 *
 * @property ABJ_404_Solution_Logging $logger
 * @method string getLowercasePrefix(...$arguments)
 * @method bool verifyOptionWriteCoherent(...$arguments)
 * @method void clearPrefixAtStageOne(...$arguments)
 * @method void clearSqlModeProbeCache(...$arguments)
 * @method void clearPhpEnvironmentProbeCache(...$arguments)
 * @method void dropTransientStagedTables(...$arguments)
 */
class ABJ_404_Solution_ViewBuildProgressOptions extends ABJ_404_Solution_ViewBuildCollaborator {

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
     * Increment a stage's consecutive no-progress kill streak.
     *
     * @param int $stageNumber 1..11.
     * @return int New streak value, or 0 when option API is unavailable.
     */
    public function bumpStageNoProgressStreak(int $stageNumber): int {
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
     * Reset a stage's no-progress kill streak after any successful stage tick.
     *
     * @param int $stageNumber
     * @return void
     */
    public function resetStageNoProgressStreak(int $stageNumber): void {
        if (!function_exists('update_option')) {
            return;
        }
        $optName = $this->stageNoProgressStreakOptionName($stageNumber);
        if ($optName !== '') {
            update_option($optName, 0, false);
        }
    }

    /**
     * @param int $stageNumber
     * @return string Empty when the stage is outside 1..11.
     */
    public function stageNoProgressStreakOptionName(int $stageNumber): string {
        if ($stageNumber < 1 || $stageNumber > 11) {
            return '';
        }
        return $this->progressOptionName('s' . $stageNumber . '_no_progress_streak');
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

}
