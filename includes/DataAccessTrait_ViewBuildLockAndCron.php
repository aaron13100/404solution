<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cross-request coordination primitives for the staged view-build pipeline.
 *
 * Two responsibilities, both called from the staged-build orchestrator in
 * ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait:
 *
 *   1. Build-writer serialization: acquireViewBuildLock / releaseViewBuildLock
 *      with a wp_options-row advisory-lock fallback for managed/sharded MySQL
 *      hosts (PlanetScale, Vitess, split-routing ProxySQL) where session-
 *      scoped GET_LOCK is unsupported. Includes the diagnostic
 *      verifyBuildLockSerializesWriter() probe.
 *
 *   2. Background rebuild scheduling: scheduleViewDoneRebuild() plus the
 *      cron-stuck and schedule-failed deduplicated admin notices. Detects a
 *      stuck WordPress cron from wp_get_ready_cron_jobs() age and surfaces a
 *      notice without sending email or flooding wp-admin.
 *
 * Sibling to ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait; both are
 * mixed into ABJ_404_Solution_DataAccess. Calls localizeOrDefaultViewBuildNotice()
 * from the helpers trait (resolved via $this-> on the composing class).
 */
trait ABJ_404_Solution_DataAccess_ViewBuildLockAndCronTrait {

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
        $added = add_option($optionName, (string)$expiresAt, '', false);
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
            // allow-cache-empty: notice must exist even when the host returns no named-lock error text.
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

    /**
     * Schedule the staged-build cron rebuild hook. Idempotent: the
     * wp_next_scheduled() check short-circuits when an event is already
     * queued. Promoted from `private` to `public` in Phase 4 of the staged
     * view-build watermark refactor: the deleted invalidateViewDone() god
     * method previously exposed schedule-only semantics through its body;
     * the post-refactor seam for "schedule a rebuild with no other side
     * effects" is this method called directly. Production callers reach it
     * via invalidateViewSnapshotCache() (cron / mutation path) and
     * forceRestartViewBuild() (runner-owned force-restart); the public
     * surface lets test code drive the same primitive without resorting to
     * reflection.
     *
     * @return void
     */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        $hook = 'abj404_rebuildViewDone';
        // Detect a stuck WordPress cron by reading WP's own scheduled-event
        // metadata. When cron is firing normally, wp_reschedule_event()
        // (wp-cron.php:129) advances each recurring event's next_run_time
        // to the future before the handler executes, so wp_get_ready_cron_jobs()
        // returns events whose timestamps are at most a few minutes overdue.
        // When cron stops, those timestamps stay frozen in the past and the
        // earliest one grows older with every passing hour. >= 24h overdue
        // is unambiguously broken; this works whether DISABLE_WP_CRON is set
        // or not, and produces no false positives for sites with working
        // external cron (the great majority of DISABLE_WP_CRON installs).
        $stuckHours = $this->getCronStuckHours();
        if ($stuckHours >= 24) {
            $this->setViewBuildCronStuckNotice($stuckHours);
        } elseif (function_exists('delete_transient')) {
            // Cron is healthy. Self-heal: clear any stale cron-stuck notice
            // so a previous false-positive (or a recovered failure) does
            // not linger up to 24h waiting for the dedup transient to
            // expire on its own.
            delete_transient('abj404_view_build_stuck_wp_cron_disabled');
        }
        $next = wp_next_scheduled($hook);
        if ($next !== false) {
            return;
        }
        // Pass wp_error=true so a failed schedule returns a WP_Error we can
        // route into a notice instead of silently dropping. WP cron schedule
        // can fail when the cron lock is held, the cron option is unwritable,
        // or a custom cron implementation rejects the event.
        $scheduled = wp_schedule_single_event(
            time() + max(1, intval($delaySeconds)),
            $hook,
            array(),
            true
        );
        $isError = (function_exists('is_wp_error') && is_wp_error($scheduled));
        if ($scheduled === false) {
            $this->setViewBuildScheduleFailedNotice('');
        } elseif ($isError) {
            $errMsg = '';
            if (is_object($scheduled) && method_exists($scheduled, 'get_error_message')) {
                $msg = $scheduled->get_error_message();
                $errMsg = is_string($msg) ? $msg : '';
            }
            $this->setViewBuildScheduleFailedNotice($errMsg);
        }
    }

    /**
     * Deduplicated admin notice (24h transient) telling the admin that
     * WordPress cron has stopped advancing. Triggered by isCronStuck()
     * detecting that the earliest overdue cron event is at least 24 hours
     * older than now, which means recurring events are no longer being
     * rescheduled and cron-dependent plugin features are stalled.
     *
     * @param int $hoursStuck how many hours the earliest overdue event has been waiting
     * @return void
     */
    private function setViewBuildCronStuckNotice(int $hoursStuck): void {
        if (!function_exists('set_transient')) {
            return;
        }
        $key = 'abj404_view_build_stuck_wp_cron_disabled';
        if (function_exists('get_transient') && get_transient($key) !== false) {
            return; // dedup window still active
        }
        $template = $this->localizeOrDefaultViewBuildNotice(
            'WordPress cron does not appear to be running. The earliest overdue '
            . 'cron event has been waiting at least %d hours, so cron-dependent '
            . 'plugin features (staged view-build, daily cleanup, log updates, '
            . 'digest emails) are not advancing. To resolve: if DISABLE_WP_CRON '
            . 'is set in wp-config.php either remove it, or configure a system '
            . 'cron job that requests wp-cron.php periodically. To force the '
            . 'redirect view to rebuild right now in your browser (workaround '
            . 'while cron is broken), open the 404 Solution Redirects page '
            . 'with ?abj404_force_view_rebuild=1 appended to the URL.'
        );
        $payload = array(
            'type'         => 'view_build_stuck_cron_disabled',
            'message'      => sprintf($template, $hoursStuck),
            'timestamp'    => time(),
            'error_string' => '',
        );
        // allow-cache-empty: intentional notice payload; error_string is empty by definition for cron-disabled state.
        set_transient($key, $payload, 86400);
    }

    /**
     * @return int hours since the earliest overdue WordPress cron event,
     *             or 0 when cron is healthy / cannot be inspected.
     *
     * Uses WP core bookkeeping rather than a heartbeat option:
     * `wp_reschedule_event()` (wp-cron.php:129) updates each recurring
     * event's next_run_time to a future timestamp before its handler
     * executes. So if cron is running, every recurring event lives in the
     * future and `wp_get_ready_cron_jobs()` returns at most a few-minute
     * window of events that have just become due. If cron stops, those
     * timestamps stay frozen in the past and the earliest one keeps
     * aging.
     */
    private function getCronStuckHours(): int {
        if (!function_exists('wp_get_ready_cron_jobs')) {
            return 0;
        }
        $ready = wp_get_ready_cron_jobs();
        if (!is_array($ready) || empty($ready)) {
            return 0;
        }
        $earliest = 0;
        foreach (array_keys($ready) as $ts) {
            $tsInt = (int) $ts;
            if ($tsInt > 0 && ($earliest === 0 || $tsInt < $earliest)) {
                $earliest = $tsInt;
            }
        }
        if ($earliest <= 0) {
            return 0;
        }
        $delta = time() - $earliest;
        if ($delta <= 0) {
            return 0;
        }
        return (int) floor($delta / 3600);
    }

    /**
     * Deduplicated admin notice (24h transient) when wp_schedule_single_event
     * itself returns false / WP_Error -- the cron lock is held, the cron
     * option is unwritable, or a custom cron implementation rejected the
     * event. Distinct from the DISABLE_WP_CRON case: scheduling itself
     * failed, so the build will not advance even with external cron.
     *
     * @param string $detail
     * @return void
     */
    private function setViewBuildScheduleFailedNotice(string $detail): void {
        if (!function_exists('set_transient')) {
            return;
        }
        $key = 'abj404_view_build_cron_schedule_failed';
        if (function_exists('get_transient') && get_transient($key) !== false) {
            return; // dedup window still active
        }
        $message = 'Scheduling the 404 Solution staged view-build cron event failed. '
            . 'The build will not advance in the background until this clears. '
            . 'This usually indicates the WordPress cron lock is held, the cron '
            . 'option is unwritable, or a custom cron implementation rejected '
            . 'the event. Check your hosting provider and any cron-replacement '
            . 'plugins. To force the redirect view to rebuild right now in your '
            . 'browser (workaround while cron scheduling is failing), open the '
            . '404 Solution Redirects page with ?abj404_force_view_rebuild=1 '
            . 'appended to the URL.';
        if ($detail !== '') {
            $message .= ' (' . $detail . ')';
        }
        $payload = array(
            'type'         => 'view_build_schedule_failed',
            'message'      => $this->localizeOrDefaultViewBuildNotice($message),
            'timestamp'    => time(),
            'error_string' => $detail,
        );
        // allow-cache-empty: schedule-failure notice remains useful even when WP returns no detail string.
        set_transient($key, $payload, 86400);
    }
}
