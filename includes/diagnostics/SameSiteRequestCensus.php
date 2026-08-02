<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Counts how many PHP requests THIS SITE has in flight right now.
 *
 * ABJ_404_Solution_HostPressureSampler answers "is the machine busy". On a
 * LiteSpeed/CloudLinux host that is not the same question as "did this site
 * run out of its own worker slots": an LVE entry-process cap is per account,
 * so a site can be throttled to a standstill while `sys_getloadavg()` looks
 * ordinary, and a site can survive a genuinely loaded box because its own
 * traffic is light. Only a per-site count separates them, and separating them
 * is what turns "bucket C, host pressure" into an actionable finding -- the
 * WordPress Heartbeat API polls every open admin screen unconditionally, a
 * wp-cron loopback spawns a second PHP request out of the first, and a second
 * admin tab issues its own table request, so the traffic competing for the
 * slot is very often the site's own.
 *
 * This class owns the POLICY and this request's own place in it: which requests
 * are in scope, whether this one joined, which lifecycle segment it is inside,
 * and how long an entry counts as a live request. The rows themselves belong to
 * ABJ_404_Solution_SameSiteRequestRegistry, which is also why a leftover entry
 * is recoverable at all: one row per request, written by that request alone,
 * so a request killed before it could deregister leaves an old row rather than
 * a corrupted counter. Taking a reading of all those rows, and shaping it into
 * a finding, belongs to ABJ_404_Solution_SameSiteCensusReading.
 *
 * Scope is admin-ajax, wp-cron and admin-screen requests. Ordinary front-end
 * page views are deliberately excluded: they are the hot 404 path, two extra
 * queries there would be paid by every visitor of every install, and the four
 * named contention sources above are all inside the scope that is measured.
 * Every reading with a finding reports that scope, so an under-count is never
 * read as a quiet site.
 */
final class ABJ_404_Solution_SameSiteRequestCensus {

    /**
     * How long an entry counts as a live request. Longer than any request that
     * is going to finish (the stall under investigation is a 25-second client
     * timeout against a request the host eventually kills) and short enough
     * that a killed request's leftover row stops being counted within one
     * admin session.
     */
    const ENTRY_TTL_MS = 300000;

    /**
     * What a reading covers, named on the reading itself so an under-count is
     * never read as a quiet site. Ordinary front-end page views are outside it
     * by design (the hot 404 path pays nothing), as are WP-CLI processes,
     * which hold no web worker slot.
     */
    const SCOPE = 'admin-ajax+cron+admin';

    /**
     * The segments of a request's own lifecycle, in the order they are entered.
     *
     * A stranded row's phase is the whole point of recording one. Report 193
     * showed four pagination workers still alive 121-198 seconds after their
     * handlers had returned `status: complete` in 1.3-4.7s, and the census
     * could say only THAT they were stranded -- naming where cost a hunt
     * through a rotating journal whose decisive records had already been
     * elided. These names are chosen so that the phase alone answers it:
     * each one is a segment with a different fix.
     *
     * PHASE_SHUTDOWN deliberately covers everything after the connection is
     * released, because a worker stranded there is holding a process slot
     * while owing the browser nothing -- a different failure from one
     * stranded before it, which is still owed a response.
     */
    const PHASE_BOOT = 'boot';
    const PHASE_HANDLER = 'handler';
    const PHASE_RESPONSE_ENCODE = 'response_encode';
    const PHASE_OB_DRAIN = 'ob_drain';
    const PHASE_DETACH = 'detach';
    const PHASE_SHUTDOWN = 'shutdown';

    /**
     * Every phase name, so a reader can tell an unrecognised value (a row
     * written by a newer build, or a corrupted one) from a known segment.
     */
    const PHASES = array(
        self::PHASE_BOOT,
        self::PHASE_HANDLER,
        self::PHASE_RESPONSE_ENCODE,
        self::PHASE_OB_DRAIN,
        self::PHASE_DETACH,
        self::PHASE_SHUTDOWN,
    );

    /** @var string Option name this request registered under, or '' when it did not join. */
    private static $ownEntry = '';

    /**
     * @var array{started_at_ms: int, channel: string, action: string, pid: int}|null
     * What this request registered with. Retained so a phase update can rewrite
     * the row from these values instead of reading it back first -- a
     * read-modify-write is the one thing that would cost the registry the
     * single-writer property its whole design rests on.
     */
    private static $ownIdentity = null;

    /** @var string The last phase successfully recorded, so a repeat is not re-written. */
    private static $ownPhase = '';

    /**
     * Register this request in the census and arrange for it to leave at
     * shutdown. Safe to call more than once; only the first call registers.
     * Never throws.
     *
     * @return string the option name this request registered under, or '' when
     *   it did not join (out of scope, no clock, no DAO, or the write failed).
     */
    public static function join(): string {
        try {
            if (self::$ownEntry !== '') {
                return self::$ownEntry;
            }
            $channel = self::channelForThisRequest();
            $startedAt = self::nowMs();
            if ($channel === '' || $startedAt === null) {
                // An entry with no start time could never be aged out, so it
                // would become a permanent phantom request. Not registering is
                // the safe failure.
                return '';
            }
            $optionName = ABJ_404_Solution_SameSiteRequestRegistry::add(
                $startedAt, $channel, self::actionForThisRequest(), self::processId(),
                self::PHASE_BOOT);
            if ($optionName === '') {
                return '';
            }
            self::$ownEntry = $optionName;
            self::$ownIdentity = array(
                'started_at_ms' => $startedAt,
                'channel' => $channel,
                'action' => self::actionForThisRequest(),
                'pid' => self::processId(),
            );
            self::$ownPhase = self::PHASE_BOOT;
            register_shutdown_function(array(__CLASS__, 'leave'));
            return $optionName;
        } catch (Throwable $e) {
            self::reportFailure('same-site census join failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Record that this request is ENTERING the named segment of its lifecycle.
     *
     * Call it immediately before the segment runs, never after it returns: the
     * rows worth reading belong to requests that never came back, so a phase
     * written on the way out is the one phase a stranded row can never carry.
     *
     * A healthy request deletes its row at shutdown and leaves nothing behind,
     * so this costs one UPDATE per transition and stores nothing long-term.
     * An abandoned row keeps the last phase its request survived long enough
     * to write, which is the segment it was inside when it stopped.
     *
     * Never throws: a request must not fail because the census could not
     * describe it.
     *
     * @param string $phase one of self::PHASES.
     * @return bool whether the phase was recorded.
     */
    public static function markPhase(string $phase): bool {
        try {
            if (self::$ownEntry === '' || self::$ownIdentity === null
                    || !in_array($phase, self::PHASES, true)) {
                return false;
            }
            if ($phase === self::$ownPhase) {
                // Re-entering the same segment says nothing new and the write
                // is paid on the path being measured.
                return true;
            }
            $identity = self::$ownIdentity;
            $recorded = ABJ_404_Solution_SameSiteRequestRegistry::advance(
                self::$ownEntry,
                $identity['started_at_ms'],
                $identity['channel'],
                $identity['action'],
                $identity['pid'],
                $phase
            );
            if ($recorded) {
                self::$ownPhase = $phase;
            }
            return $recorded;
        } catch (Throwable $e) {
            self::reportFailure('same-site census phase update failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove this request's entry. Idempotent, and safe to call when the
     * request never joined. Never throws.
     */
    public static function leave(): void {
        try {
            if (self::$ownEntry === '') {
                return;
            }
            $optionName = self::$ownEntry;
            self::$ownEntry = '';
            self::$ownIdentity = null;
            self::$ownPhase = '';
            ABJ_404_Solution_SameSiteRequestRegistry::remove(array($optionName));
        } catch (Throwable $e) {
            self::reportFailure('same-site census leave failed: ' . $e->getMessage());
        }
    }

    /**
     * Return this class to the state a freshly started PHP process is in:
     * no census identity, no memoized reading.
     *
     * One web request is one process, so a normal request never needs this.
     * A process that serves several requests in sequence does -- a persistent
     * SAPI, or any harness that drives more than one request through one
     * interpreter -- because the second request would otherwise inherit the
     * first request's census identity and report another request's row as its
     * own. This is the seam for returning that state, not a back door into it.
     */
    public static function resetRequestState(): void {
        self::$ownEntry = '';
        self::$ownIdentity = null;
        self::$ownPhase = '';
    }

    /**
     * The option name this request registered under, or '' when it did not
     * join.
     *
     * Public because a reading has to tell this request's own row apart from a
     * competitor's, and that is the ONLY thing it needs from this class's
     * private state. Exposing the name rather than letting the reading reach
     * into the identity is what keeps the dependency one-directional.
     */
    public static function ownEntryName(): string {
        return self::$ownEntry;
    }

    /**
     * Which census channel this request belongs to, or '' when it is out of
     * scope. WP-CLI is excluded on purpose: a CLI process does not consume a
     * web worker slot, so counting it would overstate the contention.
     */
    public static function channelForThisRequest(): string {
        if (defined('WP_CLI') && WP_CLI) {
            return '';
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return 'ajax';
        }
        // wp_doing_cron() rather than the DOING_CRON constant: it is
        // filterable the way WordPress itself lets other code correct the
        // signal, and it can be substituted in a test instead of leaking a
        // process-wide constant the moment one test defines it. Same reasoning
        // as AjaxRequestLedger::bootWaypointRequestId()'s wp_doing_ajax() call.
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 'cron';
        }
        if (function_exists('is_admin') && is_admin()) {
            return 'admin';
        }
        return '';
    }

    /** The WordPress action this request names, bounded and character-restricted. */
    private static function actionForThisRequest(): string {
        $raw = isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])
            ? (string)$_REQUEST['action'] : '';
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $raw) === 1 ? $raw : '';
    }

    private static function processId(): int {
        $pid = getmypid();
        return is_int($pid) ? $pid : 0;
    }

    /**
     * Seconds as a float from the injected clock, or null when there is no
     * clock yet.
     *
     * The census can be reached during the boot window (a boot-lifecycle
     * checkpoint fires before the service locator file is even required), and
     * reading the raw system clock there would both defeat the deterministic
     * test seam and silently mix two time sources inside one reading. Null
     * instead: a census with no clock cannot age its entries, and an
     * unavailable reading is the honest answer. The same window has no DAO
     * either, so nothing is lost that was otherwise obtainable.
     *
     * Public because deciding what time means for a census -- one injected
     * clock, null rather than a raw fallback during boot -- is this class's
     * policy, and ABJ_404_Solution_SameSiteCensusReading has to age entries
     * against the same one. A second time source inside one reading is exactly
     * what this null is here to prevent.
     */
    public static function nowFloat(): ?float {
        if (!function_exists('abj_clock')) {
            return null;
        }
        try {
            return abj_clock()->nowFloat();
        } catch (Throwable $e) {
            self::reportFailure('same-site census clock unavailable: ' . $e->getMessage());
            return null;
        }
    }

    /** Milliseconds from the census clock, or null when there is none yet. */
    public static function nowMs(): ?int {
        $now = self::nowFloat();
        return $now === null ? null : (int)round($now * 1000);
    }

    private static function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('same-site-census', $message);
        }
    }
}
