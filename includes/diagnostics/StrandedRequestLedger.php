<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The durable account of requests that were never deregistered by their own
 * process -- the workers that died or stalled past any plausible lifetime.
 *
 * ABJ_404_Solution_SameSiteRequestCensus reaps those leftover rows so a dead
 * request stops being counted as a live competitor. Reaping is correct and has
 * to keep happening, but it used to be a silent DELETE that kept only a count,
 * which threw away precisely the evidence the census exists to produce: the
 * longest-stranded worker is both the most diagnostic one and the first one
 * erased. Report 193 survived only because its strands (121-198s) happened to
 * sit UNDER the reap threshold and were caught mid-flight; a longer stall --
 * the worse bug -- would have left nothing but `stale_reaped: 4`.
 *
 * So a row is promoted into this ledger before it is deleted. What is kept is
 * an ACCOUNT, not a record stream: which lifecycle segment the request was
 * inside when it last managed to write one, how old it had got, and which
 * process held it. That is small enough to ship whole in a support payload,
 * which is the property that matters -- the journals it replaces for this
 * question are byte-capped, rotate against site traffic, and get sampled by a
 * priority pass, so on a busy site the answer can be gone before the admin
 * clicks "send". This is written once per reaped row, bounded, and read back
 * verbatim.
 *
 * Storage is a single non-autoloaded option rather than a file: the payload is
 * assembled from the database anyway, and the uploads directory is the one
 * place a shared host is most likely to have made unwritable.
 */
final class ABJ_404_Solution_StrandedRequestLedger {

    /** The option holding the whole ledger. Non-autoloaded; read only on demand. */
    const OPTION_NAME = 'abj404_stranded_requests';

    /**
     * How many accounts are kept.
     *
     * Small on purpose. This has to fit whole inside a support payload without
     * competing with the journal excerpts for their byte budget, and twenty
     * strands is already far past the point where the reader has the pattern.
     */
    const MAX_ENTRIES = 20;

    /**
     * How many of the EARLIEST accounts are never evicted.
     *
     * A plain ring keeps the newest and loses the first, which is backwards for
     * this evidence: the first strands on an install happened before retries,
     * warmed caches and an already-degraded host could confound them, so they
     * are the cleanest single account of the failure. The newest matter too --
     * they are contemporaneous with the click that sent the report -- so the
     * ledger keeps both ends and drops the middle, which is the part that only
     * repeats what the two ends already say.
     */
    const RETAINED_EARLIEST = 6;

    /**
     * Promote reaped registry rows into the ledger, newest last. Never throws:
     * a census reading must not fail because its own bookkeeping could not be
     * written.
     *
     * Takes loosely-typed decoded registry rows on purpose: the caller is
     * handing over whatever came back out of an options row, and account()
     * below is what decides which of it is usable. A stricter parameter type
     * here would only move that validation to a caller that has no better
     * information than this one does.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return int how many accounts were added.
     */
    public static function record(array $entries): int {
        if ($entries === array()) {
            return 0;
        }
        try {
            $existing = self::read();
            $added = array();
            foreach ($entries as $entry) {
                $account = self::account($entry);
                if ($account !== null) {
                    $added[] = $account;
                }
            }
            if ($added === array()) {
                return 0;
            }
            self::write(self::trim(array_merge($existing, $added)));
            return count($added);
        } catch (Throwable $e) {
            abj404_logPhpFallback('stranded-request-ledger',
                'stranded request record failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Every retained account, oldest first. Never throws; an unreadable or
     * malformed ledger reports as empty rather than propagating into whatever
     * asked for it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function read(): array {
        try {
            if (!function_exists('get_option')) {
                return array();
            }
            $raw = get_option(self::OPTION_NAME, '');
            if (!is_string($raw) || $raw === '') {
                return array();
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return array();
            }
            $entries = array();
            foreach ($decoded as $entry) {
                if (is_array($entry)) {
                    $entries[] = $entry;
                }
            }
            return $entries;
        } catch (Throwable $e) {
            abj404_logPhpFallback('stranded-request-ledger',
                'stranded request read failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return array();
        }
    }

    /** Forget every account. For uninstall and for tests that need a clean slate. */
    public static function clear(): void {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_NAME);
        }
    }

    /**
     * One reaped row as a bounded account, or null when the row carries nothing
     * worth keeping.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    private static function account(array $entry): ?array {
        $pid = isset($entry['pid']) && is_numeric($entry['pid']) ? (int)$entry['pid'] : 0;
        $ageMs = isset($entry['age_ms']) && is_numeric($entry['age_ms']) ? (int)$entry['age_ms'] : 0;
        if ($pid === 0 && $ageMs === 0) {
            return null;
        }
        $phase = isset($entry['phase']) && is_string($entry['phase']) && $entry['phase'] !== ''
            ? substr($entry['phase'], 0, 32)
            // A row written before phases existed, or by a request that died
            // before its first transition. Named rather than blank, so it is
            // never read as "reached no phase".
            : 'unrecorded';
        return array(
            'channel' => isset($entry['channel']) && is_string($entry['channel'])
                ? substr($entry['channel'], 0, 16) : '',
            'action' => isset($entry['action']) && is_string($entry['action'])
                ? substr($entry['action'], 0, 64) : '',
            'pid' => $pid,
            'phase' => $phase,
            'started_at_ms' => isset($entry['started_at_ms']) && is_numeric($entry['started_at_ms'])
                ? (int)$entry['started_at_ms'] : 0,
            'age_ms_at_reap' => $ageMs,
        );
    }

    /**
     * Keep both ends and drop the middle. See RETAINED_EARLIEST.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private static function trim(array $entries): array {
        // The gap marker is NOT an account and must never occupy a slot or be
        // re-counted. Folding prior markers back into one running total first
        // is what keeps the ledger at MAX_ENTRIES accounts with exactly one
        // marker, instead of growing by one marker per trim.
        $dropped = 0;
        $accounts = array();
        foreach ($entries as $entry) {
            if (isset($entry['dropped_middle_accounts'])) {
                // A hand-edited or truncated option can put anything here. A
                // non-numeric marker still means "accounts were dropped", so it
                // is kept as a marker and counted as at least one rather than
                // silently becoming zero.
                $dropped += is_numeric($entry['dropped_middle_accounts'])
                    ? (int)$entry['dropped_middle_accounts'] : 1;
                continue;
            }
            $accounts[] = $entry;
        }

        if (count($accounts) > self::MAX_ENTRIES) {
            $earliest = array_slice($accounts, 0, self::RETAINED_EARLIEST);
            $newest = array_slice($accounts, -(self::MAX_ENTRIES - self::RETAINED_EARLIEST));
            $dropped += count($accounts) - count($earliest) - count($newest);
        } else {
            $earliest = array_slice($accounts, 0, self::RETAINED_EARLIEST);
            $newest = array_slice($accounts, self::RETAINED_EARLIEST);
        }

        if ($dropped === 0) {
            return array_merge($earliest, $newest);
        }
        // The gap is stated in the ledger itself. A reader who cannot see that
        // accounts were dropped would read the two ends as one continuous
        // history, which is the same "looks complete, is 3% of it" failure the
        // journal excerpt summary exists to prevent.
        return array_merge($earliest, array(array('dropped_middle_accounts' => $dropped)), $newest);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private static function write(array $entries): void {
        if (!function_exists('update_option')) {
            return;
        }
        $encoded = json_encode(array_values($entries));
        if (!is_string($encoded)) {
            return;
        }
        update_option(self::OPTION_NAME, $encoded, false);
    }
}
