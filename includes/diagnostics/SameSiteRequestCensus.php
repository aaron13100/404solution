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
 * This class owns the POLICY: which requests are in scope, this request's
 * membership, how long an entry counts as a live request, when to reap the
 * ones a killed worker left behind, how often the database may actually be
 * asked, and what a checkpoint record reports. The rows themselves belong to
 * ABJ_404_Solution_SameSiteRequestRegistry, which is also why a leftover entry
 * is recoverable at all: one row per request, written by that request alone,
 * so a request killed before it could deregister leaves an old row rather than
 * a corrupted counter.
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
     * Minimum gap between real readings, in milliseconds.
     *
     * Every full checkpoint envelope carries this reading, and a table request
     * emits roughly 27 of them, so an unconditional query per record would add
     * ~27 queries to the very request whose worker contention is being
     * measured -- the observer effect gap G2 raised about the recorder itself.
     * Consecutive checkpoints during healthy phases are milliseconds apart and
     * say nothing new; consecutive checkpoints during a STALL are seconds
     * apart, which is longer than this window, so the resolution that matters
     * is unaffected. Every reading reports its own age, so a memoized value is
     * never mistaken for a fresh one.
     */
    const SAMPLE_MEMO_MS = 250;

    /** @var string Option name this request registered under, or '' when it did not join. */
    private static $ownEntry = '';

    /** @var array<string, mixed>|null Last reading, reused inside SAMPLE_MEMO_MS. */
    private static $memoSample = null;

    /** @var int When the memoized reading was taken. */
    private static $memoTakenAtMs = 0;

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
                $startedAt, $channel, self::actionForThisRequest(), self::processId());
            if ($optionName === '') {
                return '';
            }
            self::$ownEntry = $optionName;
            // This process just changed the census, so any reading taken
            // before now is wrong, not merely old.
            self::resetSampleMemo();
            register_shutdown_function(array(__CLASS__, 'leave'));
            return $optionName;
        } catch (Throwable $e) {
            self::reportFailure('same-site census join failed: ' . $e->getMessage());
            return '';
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
            self::resetSampleMemo();
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
        self::resetSampleMemo();
    }

    /**
     * The current reading.
     *
     * Never returns an empty or absent field: an unavailable census reports a
     * named reason, because "no reading" and "no concurrent requests" are
     * opposite findings and a blank would let a blind spot read as quiet.
     *
     * @return array<string, mixed>
     */
    public static function sample(): array {
        try {
            $now = self::nowMs();
            if ($now === null) {
                return self::unavailable('clock_unavailable', array(
                    'abj_clock()->nowFloat()' => 'unavailable',
                ));
            }
            if (self::$memoSample !== null && ($now - self::$memoTakenAtMs) < self::SAMPLE_MEMO_MS
                    && ($now - self::$memoTakenAtMs) >= 0) {
                $memo = self::$memoSample;
                $memo['sample_age_ms'] = $now - self::$memoTakenAtMs;
                return $memo;
            }
            $sample = self::readCensus($now);
            self::$memoSample = $sample;
            self::$memoTakenAtMs = $now;
            return $sample;
        } catch (Throwable $e) {
            self::reportFailure('same-site census sample failed: ' . $e->getMessage());
            return self::unavailable('sample_exception', array(
                'SameSiteRequestCensus::sample()' => 'exception:' . get_class($e),
            ));
        }
    }

    /**
     * This class's contribution to a full checkpoint envelope.
     *
     * `same_site_requests` rides EVERY record, because a stall is diagnosed
     * from where the number was when the record was written; -1 means the
     * census could not be read, never 0, which would be a finding rather than
     * an absence. `same_site_census` -- the identities, the scope, the TTL and
     * the reap counters -- rides only the record whose own write actually took
     * the reading. Repeating that structure on all ~27 records of a request
     * would spend the support-excerpt budget that decides how much of a
     * FAILING session reaches the developer, for bytes that say the same thing
     * 27 times; the same trade the rusage subset and the reduced high-frequency
     * envelope were both made for. The two are joined by request id and
     * timestamp, the keys the rest of the journal is already read by.
     *
     * @return array<string, mixed>
     */
    public static function checkpointFields(): array {
        $takenAtBefore = self::$memoTakenAtMs;
        $sample = self::sample();
        $fields = array(
            'same_site_requests' => isset($sample['count']) && is_int($sample['count'])
                ? $sample['count'] : -1,
        );
        if (self::$memoTakenAtMs !== $takenAtBefore) {
            $fields['same_site_census'] = $sample;
        }
        return $fields;
    }

    /**
     * Forget the memoized reading, so the next sample() re-reads regardless of
     * timing. Called by join() and leave(), which change the very thing the
     * memo describes.
     */
    public static function resetSampleMemo(): void {
        self::$memoSample = null;
        self::$memoTakenAtMs = 0;
    }

    /**
     * Split the registry into live requests and leftovers, reap the
     * leftovers, and report what is left.
     *
     * @param int $now
     * @return array<string, mixed>
     */
    private static function readCensus(int $now): array {
        // What this reading costs the request it is measuring, measured
        // through the same injected clock everything else here uses, so an
        // observer effect is visible in the evidence rather than argued about.
        $startedAt = self::nowFloat();
        $registry = ABJ_404_Solution_SameSiteRequestRegistry::readAll();
        $finishedAt = self::nowFloat();
        if ($registry['status'] !== 'available') {
            return self::unavailable($registry['reason'], array(
                'SameSiteRequestRegistry::readAll()' => (string)$registry['reason'],
            ));
        }

        $others = array();
        $stale = array();
        $live = 0;
        $selfRegistered = false;
        foreach ($registry['entries'] as $entry) {
            $ageMs = max(0, $now - $entry['started_at_ms']);
            if ($ageMs > self::ENTRY_TTL_MS) {
                // The failure mode a plain counter cannot survive: the request
                // under investigation is precisely the one killed before it
                // could deregister. An entry older than any request that could
                // still be running is a leftover, not a competitor.
                $stale[] = $entry['option_name'];
                continue;
            }
            $live++;
            if ($entry['option_name'] === self::$ownEntry) {
                $selfRegistered = true;
                continue;
            }
            $others[] = array(
                'channel' => $entry['channel'],
                'action' => $entry['action'],
                'pid' => $entry['pid'],
                'age_ms' => $ageMs,
            );
        }
        return self::report($live, $others, $stale, $selfRegistered, $registry['truncated'],
            ($startedAt === null || $finishedAt === null)
                ? -1.0 : round(($finishedAt - $startedAt) * 1000, 3));
    }

    /**
     * Four fields unconditionally, the rest only when they carry information.
     *
     * Not terseness for its own sake: this reading rides the checkpoint
     * channel, and the support excerpt's byte budget is the scarce resource
     * that decides how much of a FAILING session reaches the developer at all
     * (see CheckpointJournalReader::MAX_SUPPORT_EXCERPT_BYTES and the rusage trim
     * that preceded it). Everything omitted here is omitted only at its
     * documented default and reappears the moment it is not: the entries and
     * the TTL their ages are read against when there IS other traffic,
     * self_registered when this request did NOT register, the reap counters
     * when something was reaped, truncated when the read hit its ceiling.
     *
     * @param array<int, array<string, mixed>> $others
     * @param array<int, string> $stale
     * @return array<string, mixed>
     */
    private static function report(int $live, array $others, array $stale, bool $selfRegistered,
            bool $truncated, float $readMs): array {
        $sample = array(
            'status' => 'available',
            'count' => $live,
            'others' => count($others),
            'sample_age_ms' => 0,
        );
        if ($others !== array()) {
            $sample['scope'] = self::SCOPE;
            $sample['entries'] = $others;
            $sample['ttl_ms'] = self::ENTRY_TTL_MS;
        }
        if (!$selfRegistered) {
            $sample['self_registered'] = false;
        }
        if ($stale !== array()) {
            $sample['stale_seen'] = count($stale);
            $sample['stale_reaped'] = ABJ_404_Solution_SameSiteRequestRegistry::remove($stale);
        }
        if ($truncated) {
            $sample['truncated'] = true;
        }
        $sample['read_ms'] = $readMs;
        return $sample;
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
     */
    private static function nowFloat(): ?float {
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

    private static function nowMs(): ?int {
        $now = self::nowFloat();
        return $now === null ? null : (int)round($now * 1000);
    }

    /**
     * @param array<string, string> $attemptedPaths
     * @return array<string, mixed>
     */
    private static function unavailable(string $reason, array $attemptedPaths): array {
        return array(
            'status' => 'unavailable',
            'reason' => $reason,
            'attempted_paths' => $attemptedPaths,
            'scope' => self::SCOPE,
            // -1, never 0: an unreadable census and a quiet site are opposite
            // findings, and the per-record number has to stay arithmetically
            // impossible to confuse.
            'count' => -1,
            'others' => -1,
            'self_registered' => self::$ownEntry !== '',
            'sample_age_ms' => 0,
        );
    }

    private static function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('same-site-census', $message);
        }
    }
}
