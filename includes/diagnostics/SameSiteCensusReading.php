<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One reading of how many PHP requests THIS SITE has in flight, shaped into a
 * finding small enough to ride every checkpoint record.
 *
 * ABJ_404_Solution_SameSiteRequestCensus owns the policy a reading is taken
 * under -- who is in scope, how long an entry counts as live, and which row
 * belongs to this request -- and ABJ_404_Solution_SameSiteRequestRegistry owns
 * the rows. This class owns only what happens between them: splitting the
 * registry into live requests and leftovers, promoting the leftovers before
 * they are reaped, and deciding which fields are worth the bytes.
 *
 * The dependency runs one way, reading -> policy, and never back. That is what
 * lets the memo below invalidate itself (see sample()) instead of relying on
 * every place that changes the census to remember to call a reset -- the
 * push-based version of that invalidation was a standing invitation to a stale
 * reading after some future third mutation site forgot.
 */
final class ABJ_404_Solution_SameSiteCensusReading {

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

    /** @var array<string, mixed>|null Last reading, reused inside SAMPLE_MEMO_MS. */
    private static $memoSample = null;

    /** @var int When the memoized reading was taken. */
    private static $memoTakenAtMs = 0;

    /**
     * @var string Which census identity the memoized reading was taken under.
     * A reading describes a population this request is part of, so the moment
     * this request joins or leaves, an earlier reading is wrong rather than
     * merely old.
     */
    private static $memoOwnEntry = '';

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
            $now = ABJ_404_Solution_SameSiteRequestCensus::nowMs();
            if ($now === null) {
                return self::unavailable('clock_unavailable', array(
                    'abj_clock()->nowFloat()' => 'unavailable',
                ));
            }
            $ownEntry = ABJ_404_Solution_SameSiteRequestCensus::ownEntryName();
            if (self::$memoSample !== null && self::$memoOwnEntry === $ownEntry
                    && ($now - self::$memoTakenAtMs) < self::SAMPLE_MEMO_MS
                    && ($now - self::$memoTakenAtMs) >= 0) {
                $memo = self::$memoSample;
                $memo['sample_age_ms'] = $now - self::$memoTakenAtMs;
                return $memo;
            }
            $sample = self::readCensus($now, $ownEntry);
            self::$memoSample = $sample;
            self::$memoTakenAtMs = $now;
            self::$memoOwnEntry = $ownEntry;
            return $sample;
        } catch (Throwable $e) {
            self::reportFailure('same-site census sample failed: ' . $e->getMessage());
            return self::unavailable('sample_exception', array(
                'SameSiteCensusReading::sample()' => 'exception:' . get_class($e),
            ));
        }
    }

    /**
     * This reading's contribution to a full checkpoint envelope.
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
     * timing.
     *
     * Not needed for a census identity change -- sample() detects that itself.
     * This is the seam for a process that serves several requests in sequence
     * and has to return this class to the state a freshly started PHP process
     * is in.
     */
    public static function resetSampleMemo(): void {
        self::$memoSample = null;
        self::$memoTakenAtMs = 0;
        self::$memoOwnEntry = '';
    }

    /**
     * Split the registry into live requests and leftovers, reap the
     * leftovers, and report what is left.
     *
     * @return array<string, mixed>
     */
    private static function readCensus(int $now, string $ownEntry): array {
        // What this reading costs the request it is measuring, measured
        // through the same injected clock everything else here uses, so an
        // observer effect is visible in the evidence rather than argued about.
        $startedAt = ABJ_404_Solution_SameSiteRequestCensus::nowFloat();
        $registry = ABJ_404_Solution_SameSiteRequestRegistry::readAll();
        $finishedAt = ABJ_404_Solution_SameSiteRequestCensus::nowFloat();
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
            if ($ageMs > ABJ_404_Solution_SameSiteRequestCensus::ENTRY_TTL_MS) {
                // The failure mode a plain counter cannot survive: the request
                // under investigation is precisely the one killed before it
                // could deregister. An entry older than any request that could
                // still be running is a leftover, not a competitor.
                $entry['age_ms'] = $ageMs;
                $stale[] = $entry;
                continue;
            }
            $live++;
            if ($entry['option_name'] === $ownEntry) {
                $selfRegistered = true;
                continue;
            }
            $others[] = array(
                'channel' => $entry['channel'],
                'action' => $entry['action'],
                'pid' => $entry['pid'],
                'age_ms' => $ageMs,
                // Which segment that request was inside when it last managed to
                // say so. An age alone reports that a worker is stranded; the
                // phase is what says where, and it is the difference between a
                // finding and a hunt through a rotated journal.
                'phase' => $entry['phase'] !== '' ? $entry['phase'] : 'unrecorded',
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
     * @param array<int, array<string, mixed>> $stale Whole decoded rows, so the
     *   account of a request that never deregistered survives its own deletion.
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
            $sample['scope'] = ABJ_404_Solution_SameSiteRequestCensus::SCOPE;
            $sample['entries'] = $others;
            $sample['ttl_ms'] = ABJ_404_Solution_SameSiteRequestCensus::ENTRY_TTL_MS;
        }
        if (!$selfRegistered) {
            $sample['self_registered'] = false;
        }
        if ($stale !== array()) {
            $sample['stale_seen'] = count($stale);
            // Promote BEFORE deleting. A reaped row is a request that outlived
            // any plausible lifetime without deregistering -- the worst strand
            // on the site, and the one a bare DELETE would erase down to a
            // count. See ABJ_404_Solution_StrandedRequestLedger.
            $sample['stale_recorded'] = ABJ_404_Solution_StrandedRequestLedger::record($stale);
            $optionNames = array();
            foreach ($stale as $entry) {
                if (isset($entry['option_name']) && is_string($entry['option_name'])) {
                    $optionNames[] = $entry['option_name'];
                }
            }
            $sample['stale_reaped'] = ABJ_404_Solution_SameSiteRequestRegistry::remove($optionNames);
        }
        if ($truncated) {
            $sample['truncated'] = true;
        }
        $sample['read_ms'] = $readMs;
        return $sample;
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
            'scope' => ABJ_404_Solution_SameSiteRequestCensus::SCOPE,
            // -1, never 0: an unreadable census and a quiet site are opposite
            // findings, and the per-record number has to stay arithmetically
            // impossible to confuse.
            'count' => -1,
            'others' => -1,
            'self_registered' => ABJ_404_Solution_SameSiteRequestCensus::ownEntryName() !== '',
            'sample_age_ms' => 0,
        );
    }

    private static function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('same-site-census', $message);
        }
    }
}
