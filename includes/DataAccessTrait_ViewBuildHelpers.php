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
 *
 *   2. Staged SQL execution: load a SQL template from
 *      includes/sql/getRedirectsForViewStaged/, perform table-name and
 *      placeholder substitutions, route through queryAndGetResults, and
 *      raise a descriptive exception on failure. Plus a duplicate-key
 *      tolerant variant for re-runnable index DDL.
 *
 *   3. Build-side state probes: table existence (view_done, view_build,
 *      view_deleteme), freshness staleness check, GET_LOCK / RELEASE_LOCK,
 *      and the cron rebuild scheduler.
 *
 * Sibling to ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait; both are
 * mixed into ABJ_404_Solution_DataAccess. Properties declared here are
 * visible to the staged-build trait inside the composing class.
 */
trait ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait {

    /** @var int Per-stage timeout in seconds for staged queries; 0 means use queryAndGetResults default. */
    private $stagedQueryTimeoutSeconds = 0;

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
        $value = get_option($name, $default);
        return is_scalar($value) ? max(0, intval($value)) : $default;
    }

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
        // autoload=false so progress writes (potentially many per request)
        // don't bloat the alloptions cache that loads on every WP page.
        update_option($name, max(0, intval($value)), false);
    }

    /** @return void */
    private function clearAllProgressOptions(): void {
        if (!function_exists('delete_option')) {
            return;
        }
        foreach (self::$viewBuildProgressOptionNames as $optName) {
            delete_option($this->getLowercasePrefix() . $optName);
        }
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

    /** @param string $tableName @return bool */
    private function stagedTableExists(string $tableName): bool {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return false;
        }
        /** @var \wpdb $wpdb */
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
     * Per-request memo of whether session-scoped GET_LOCK is supported on
     * this host. null = not yet probed; true = a prior probe returned
     * got=1; false = a prior probe returned NULL or the function is
     * unrecognized (managed/sharded MySQL: PlanetScale, Vitess, certain
     * ProxySQL routings). Once unsupported, we skip the GET_LOCK round
     * trip and go straight to the option-row fallback for the rest of the
     * request.
     *
     * @var bool|null
     */
    private static $namedLockSupportedThisRequest = null;

    /**
     * Fires the "fallback in use" log line at info level once per request
     * even when many `acquireViewBuildLock` calls take the fallback path.
     * Diagnostics only; no correctness impact.
     *
     * @var bool
     */
    private static $fallbackLockLoggedThisRequest = false;

    /**
     * Tracks whether the most recent successful acquire used the option-row
     * fallback (true) or the native GET_LOCK (false), so the matching
     * `releaseViewBuildLock` releases the correct primitive.
     *
     * @var bool
     */
    private $usingTransientFallbackLock = false;

    /** @var string  Last detected reason for falling back; surfaced in the notice. */
    private $lastNamedLockUnsupportedReason = '';
    /** @var string  Last detected error string from GET_LOCK; surfaced in the notice. */
    private $lastNamedLockUnsupportedError = '';

    /**
     * @param int $timeoutSeconds  GET_LOCK wait-time. 0 (default) is the
     *   non-blocking acquire used by every steady-state path: if cron or a
     *   sibling tab holds the lock, we yield immediately so the caller can
     *   return locked=true. Use a positive value only for the diagnostic
     *   force-rebuild path, where we want to block until the in-flight
     *   build releases so we can own the next one.
     *
     * On managed/sharded MySQL hosts where session-scoped named locks are
     * unavailable (`GET_LOCK` returns NULL or "function does not exist"),
     * falls back to a wp_options-row advisory lock acquired with
     * `add_option` semantics. This serializes concurrent workers on a
     * single-master WordPress site even when the database layer cannot.
     * Documented in `ViewBuildLockUnavailabilityTest`.
     *
     * @return bool
     */
    private function acquireViewBuildLock(int $timeoutSeconds = 0): bool {
        $name = $this->getLowercasePrefix() . ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_BUILD_LOCK_NAME;

        // Once we've classified the host as "named locks unsupported" in
        // this request, don't pay the round-trip on every subsequent
        // acquire. Re-check happens on the next request because the static
        // is request-scoped.
        if (self::$namedLockSupportedThisRequest === false) {
            $this->ensureFallbackLockNoticeAndLog();
            return $this->acquireTransientFallbackLock($name);
        }

        $timeout = max(0, $timeoutSeconds);
        $sql = "SELECT GET_LOCK('" . esc_sql($name) . "', " . $timeout . ") AS got";
        $result = $this->queryAndGetResults($sql, array('log_errors' => false));

        $err = isset($result['last_error']) && is_string($result['last_error'])
            ? trim($result['last_error']) : '';
        if ($err !== '' && $this->isNamedLockUnsupportedError($err)) {
            self::$namedLockSupportedThisRequest = false;
            $this->lastNamedLockUnsupportedReason = 'function_unsupported';
            $this->lastNamedLockUnsupportedError = $err;
            $this->ensureFallbackLockNoticeAndLog();
            return $this->acquireTransientFallbackLock($name);
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (!empty($rows) && is_array($rows[0]) && array_key_exists('got', $rows[0])) {
            $got = $rows[0]['got'];
            if ($got === null) {
                // NULL: per the MySQL manual, GET_LOCK returns NULL on an
                // error. On sharded/managed hosts (PlanetScale, Vitess) it
                // is also returned as a "no-op" indicator. Either way the
                // session-scoped lock did not engage; treat as unsupported.
                self::$namedLockSupportedThisRequest = false;
                $this->lastNamedLockUnsupportedReason = 'returned_null';
                $this->lastNamedLockUnsupportedError = '';
                $this->ensureFallbackLockNoticeAndLog();
                return $this->acquireTransientFallbackLock($name);
            }
            $intGot = is_scalar($got) ? intval($got) : 0;
            if ($intGot === 1) {
                if (self::$namedLockSupportedThisRequest === null) {
                    self::$namedLockSupportedThisRequest = true;
                }
                $this->usingTransientFallbackLock = false;
                return true;
            }
            // got=0 (or any other integer): another connection holds the
            // lock. Normal contention; do NOT fall back, the other worker
            // is already advancing the build.
            return false;
        }

        // No rows and no recognized "unsupported" error string: ambiguous.
        // Treat as lock unavailable rather than guessing fallback is needed.
        return false;
    }

    /** @return void */
    private function releaseViewBuildLock(): void {
        // @utf8-audit: opt-out - $name is built from $wpdb->prefix + a class
        // constant; never user input, cannot contain invalid UTF-8 bytes.
        $name = $this->getLowercasePrefix() . ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_BUILD_LOCK_NAME;
        if ($this->usingTransientFallbackLock) {
            $this->usingTransientFallbackLock = false;
            if (function_exists('delete_option')) {
                delete_option($this->transientFallbackLockOptionName($name));
            }
            return;
        }
        $this->queryAndGetResults("SELECT RELEASE_LOCK('" . esc_sql($name) . "')",
            array('log_errors' => false));
    }

    /**
     * Acquire the option-row advisory lock that stands in for GET_LOCK on
     * hosts where named locks are unavailable. Race-safe: `add_option`
     * fails when the option already exists, so at most one worker wins
     * the contended add. Stale locks from a prior crashed worker are
     * cleared when their stored expiry timestamp has passed.
     *
     * @param string $name  Already-prefixed lock identifier shared with GET_LOCK.
     * @return bool
     */
    private function acquireTransientFallbackLock(string $name): bool {
        if (!function_exists('add_option') || !function_exists('get_option')) {
            return false;
        }
        $optionName = $this->transientFallbackLockOptionName($name);
        $now = time();
        $ttl = ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_TRANSIENT_LOCK_TTL_SECONDS;
        $expiresAt = $now + $ttl;

        // Stale-lock recovery: if the existing option's expiry has passed,
        // the prior holder crashed without releasing. Delete and try again.
        $existing = get_option($optionName, 0);
        $existingExpires = is_scalar($existing) ? intval($existing) : 0;
        if ($existingExpires > 0 && $existingExpires <= $now && function_exists('delete_option')) {
            delete_option($optionName);
        }

        // add_option returns false if the option row already exists. This
        // is the race-safe primitive: even with N parallel PHP workers
        // racing on the same option, at most one wins. set_transient is
        // NOT race-safe in this way (it overwrites), so we deliberately
        // use add_option directly.
        $added = add_option($optionName, (string)$expiresAt, '', 'no');
        if ($added) {
            $this->usingTransientFallbackLock = true;
            return true;
        }
        return false;
    }

    /** @param string $name @return string */
    private function transientFallbackLockOptionName(string $name): string {
        return $name . '_transient_lock';
    }

    /**
     * Match a `last_error` string against the patterns that indicate the
     * MySQL host does not support session-scoped named locks. Conservative:
     * we only return true when the error specifically names GET_LOCK as
     * unrecognized; any other DB error stays in the "lock unavailable,
     * try later" bucket.
     *
     * @param string $err
     * @return bool
     */
    private function isNamedLockUnsupportedError(string $err): bool {
        $errLow = strtolower($err);
        if (strpos($errLow, 'get_lock') === false) {
            return false;
        }
        return strpos($errLow, 'does not exist') !== false
            || strpos($errLow, 'unknown function') !== false
            || strpos($errLow, 'er_sp_does_not_exist') !== false
            || strpos($errLow, 'is not allowed') !== false
            || strpos($errLow, 'not allowed in this context') !== false;
    }

    /**
     * Surface a deduplicated admin notice for the fallback path and emit
     * the info-level "fallback in use" log line once per request.
     *
     * The notice transient is refreshed on every fallback acquire (cheap
     * and idempotent) so admins on hosts where named locks come and go
     * still see an up-to-date "still on fallback" indicator. The log line
     * is gated on a per-request static so a steady-state host that
     * acquires the lock dozens of times per request only produces a
     * single info entry.
     *
     * @return void
     */
    private function ensureFallbackLockNoticeAndLog(): void {
        if (function_exists('set_transient')) {
            set_transient(
                'abj404_view_build_get_lock_unsupported_notice',
                array(
                    'reason'  => $this->lastNamedLockUnsupportedReason !== ''
                        ? $this->lastNamedLockUnsupportedReason : 'unknown',
                    'error'   => substr($this->lastNamedLockUnsupportedError, 0, 500),
                    'when'    => time(),
                    'message' => 'This database does not support session-scoped GET_LOCK named locks. '
                        . 'The plugin is using a WordPress option-row fallback to coordinate the staged view-build. '
                        . 'Common on PlanetScale, Vitess, and split-routing ProxySQL deployments.',
                ),
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        }
        if (!self::$fallbackLockLoggedThisRequest) {
            self::$fallbackLockLoggedThisRequest = true;
            $message = '[staged] view-build lock: GET_LOCK unsupported on this host '
                . '(reason=' . ($this->lastNamedLockUnsupportedReason !== ''
                    ? $this->lastNamedLockUnsupportedReason : 'unknown')
                . '); using option-row fallback.';
            if (is_object($this->logger) && method_exists($this->logger, 'infoMessage')) {
                $this->logger->infoMessage($message);
            } elseif (is_object($this->logger) && method_exists($this->logger, 'debugMessage')) {
                $this->logger->debugMessage($message);
            }
        }
    }

    /**
     * Resets the per-request lock-fallback memos so a fresh request starts
     * by probing GET_LOCK on the host again. Tests use this to drive the
     * per-request lifecycle inside a single PHP process.
     *
     * @return void
     */
    public static function resetViewBuildLockFallbackMemos(): void {
        self::$namedLockSupportedThisRequest = null;
        self::$fallbackLockLoggedThisRequest = false;
    }

    /**
     * Probe whether the build lock primitive on this host actually
     * serializes the writer connection. On split-routing deployments
     * (ProxySQL/Vitess/MaxScale read-write split, PlanetScale branch
     * replicas), `SELECT GET_LOCK` may be routed to a replica session
     * that holds a session-scoped lock without preventing two writer
     * connections from running concurrent DDL. The classic symptom is
     * two staged-build workers both passing `acquireViewBuildLock` and
     * both attempting the S11 RENAME swap.
     *
     * The probe acquires the build lock, writes a unique nonce to
     * wp_options, reads it back through the same code path, and verifies
     * the round trip. A passing probe is consistent with single-master
     * routing; a failing probe is a strong signal the lock did not
     * serialize the writer and the caller should switch to the option-row
     * fallback.
     *
     * Public so {@see ABJ_404_Solution_DataAccess} exposes it for the
     * lock-coverage test suite and any future health-check page.
     *
     * @return bool  true when the probe round-tripped successfully through
     *   the held lock; false on any inability to acquire / write / read /
     *   verify.
     */
    public function verifyBuildLockSerializesWriter(): bool {
        if (!$this->acquireViewBuildLock(0)) {
            return false;
        }
        try {
            if (!function_exists('update_option') || !function_exists('get_option')) {
                return false;
            }
            $optionName = $this->getLowercasePrefix() . 'abj404_view_build_lock_writer_probe';
            try {
                $nonce = bin2hex(random_bytes(8));
            } catch (\Throwable $t) {
                $nonce = (string)mt_rand() . '_' . (string)microtime(true);
            }
            update_option($optionName, $nonce, false);
            $readBack = get_option($optionName, '');
            if (function_exists('delete_option')) {
                delete_option($optionName);
            }
            return is_string($readBack) && $readBack === $nonce;
        } finally {
            $this->releaseViewBuildLock();
        }
    }

    /** @return void */
    private function scheduleViewDoneRebuild(int $delaySeconds = 1): void {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            $hook = 'abj404_rebuildViewDone';
            $next = wp_next_scheduled($hook);
            if ($next === false) {
                wp_schedule_single_event(time() + max(1, intval($delaySeconds)), $hook);
            }
        }
    }
}
