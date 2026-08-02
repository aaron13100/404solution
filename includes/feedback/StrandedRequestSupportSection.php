<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The support-payload section for stranded requests: workers that are still
 * in flight far past a plausible lifetime, and workers that already were.
 *
 * This section exists because of how report 193 was actually read. Four
 * pagination workers had been stranded 121-198 seconds while their own
 * handlers had returned `status: complete` in 1.3-4.7s -- the single most
 * decisive fact in the payload -- and it reached the developer only as a
 * side effect: the census rides checkpoint records, and those particular
 * records happened to survive a 48 KiB excerpt chosen by a priority pass over
 * a 512 KiB journal that rotates against site traffic. Nothing in the feedback
 * layer read the census at all. On a busier site, or a report sent a few hours
 * later, the same evidence would simply have been gone, and the follow-up was
 * going to be asking the user to fetch journal files off their own server
 * before they rotated -- a race the user loses on a site with real traffic.
 *
 * So the finding is composed here instead, from the registry directly: a
 * bounded, self-describing block that says which requests are stranded, how
 * long they have been, and which lifecycle segment each one was inside when it
 * last managed to say so. It reads no journal, so nothing it reports can be
 * elided or rotated away, and it is small enough to ship whole every time.
 *
 * ABJ_404_Solution_SameSiteRequestCensus owns what counts as stranded and what
 * a reading contains; ABJ_404_Solution_StrandedRequestLedger owns the durable
 * account of already-reaped rows. This class owns only the payload job: asking
 * both, and rendering the answer inside a byte budget.
 */
final class ABJ_404_Solution_StrandedRequestSupportSection {

    /**
     * Hard cap on the rendered block. A bounded number of accounts, each a
     * handful of scalars, so it is small by construction; the cap keeps it so
     * regardless of how many strands a busy site accumulated. Reclaimed from
     * the checkpoint excerpt budget so the section sum stays inside the report
     * contract -- see ABJ_404_Solution_CheckpointJournalReader::MAX_SUPPORT_EXCERPT_BYTES
     * and SupportExcerptBudgetContractTest.
     */
    const MAX_STRANDED_DIAG_BYTES = 3072;

    /**
     * How long a request must have been running before it is reported here.
     *
     * Well past any healthy request on the instrumented path (report 193's own
     * handlers completed in 1.3-4.7 seconds) and well under the census reap
     * threshold, so a strand is reported while its row is still live rather
     * than only after it has been reaped into the ledger.
     */
    const STRANDED_AFTER_MS = 30000;

    /** The one JSON key the block hangs under, so a reader can grep for it. */
    const STRANDED_DIAG_KEY = 'abj404_stranded_requests';

    /**
     * The whole section, ready to join into the support payload. Never throws:
     * a support request is the last thing that may be blocked by its own
     * diagnostics, so a missing class or an unreadable option degrades to a
     * stated reason rather than a fatal in the request the admin is waiting on.
     */
    public static function compose(): string {
        if (!class_exists('ABJ_404_Solution_SameSiteCensusReading')
                || !class_exists('ABJ_404_Solution_StrandedRequestLedger')) {
            return 'Stranded-request diagnostics unavailable: the census classes could not be'
                . ' loaded on this install, so in-flight worker state was not read here.';
        }
        try {
            return self::render(self::record());
        } catch (Throwable $e) {
            return 'Stranded-request diagnostics could not be computed: '
                . substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * The finding: currently-stranded requests from the live census, plus the
     * durable accounts of ones already reaped.
     *
     * `census_status` is carried even when it is fine, because "no strands"
     * and "could not read the census" are opposite findings and a blank would
     * let a blind spot read as a healthy site.
     *
     * @return array<string, mixed>
     */
    private static function record(): array {
        $sample = ABJ_404_Solution_SameSiteCensusReading::sample();
        $status = isset($sample['status']) && is_string($sample['status'])
            ? $sample['status'] : 'unknown';
        $entries = isset($sample['entries']) && is_array($sample['entries'])
            ? $sample['entries'] : array();

        $stranded = array();
        foreach ($entries as $entry) {
            $account = is_array($entry) ? self::strandedAccount($entry) : null;
            if ($account !== null) {
                $stranded[] = $account;
            }
        }

        $reaped = ABJ_404_Solution_StrandedRequestLedger::read();
        return array(
            'census_status' => $status,
            'census_reason' => isset($sample['reason']) && is_string($sample['reason'])
                ? $sample['reason'] : '',
            'in_flight_total' => isset($sample['count']) && is_int($sample['count'])
                ? $sample['count'] : -1,
            'stranded_after_ms' => self::STRANDED_AFTER_MS,
            'stranded_now' => $stranded,
            'stranded_previously' => $reaped,
            'phase_meaning' => 'the lifecycle segment the request had ENTERED when it last'
                . ' recorded one; a request that died inside a segment never records the next',
        );
    }

    /**
     * One census entry as a stranded-request account, or null when the request
     * is simply still running.
     *
     * A request that is merely in flight is not a finding: reporting every one
     * would bury the strand in ordinary traffic, which is the same
     * signal-to-noise failure that made the raw journal excerpt unusable.
     *
     * Keyed loosely because that is what a census reading actually is: values
     * decoded out of options rows, whose keys this class must not assume are
     * present or well-typed. Every field it reads is validated below.
     *
     * @param array<array-key, mixed> $entry
     * @return array<string, mixed>|null
     */
    private static function strandedAccount(array $entry): ?array {
        $ageMs = isset($entry['age_ms']) && is_numeric($entry['age_ms'])
            ? (int)$entry['age_ms'] : 0;
        if ($ageMs < self::STRANDED_AFTER_MS) {
            return null;
        }
        return array(
            'action' => isset($entry['action']) && is_string($entry['action'])
                ? $entry['action'] : '',
            'channel' => isset($entry['channel']) && is_string($entry['channel'])
                ? $entry['channel'] : '',
            'pid' => isset($entry['pid']) && is_numeric($entry['pid']) ? (int)$entry['pid'] : 0,
            'age_ms' => $ageMs,
            'phase' => isset($entry['phase']) && is_string($entry['phase']) && $entry['phase'] !== ''
                ? $entry['phase'] : 'unrecorded',
        );
    }

    /**
     * The record as a scannable header line plus one JSON record.
     *
     * Over-budget input sheds the historical accounts first -- the reducible
     * detail, since a currently-stranded worker is contemporaneous with the
     * click that sent the report -- then falls back to the counts and phases
     * alone, rather than being cut at a byte offset. A record cut mid-JSON is
     * unreadable by machine and misleading to a human.
     *
     * @param array<string, mixed> $record
     */
    private static function render(array $record): string {
        $header = 'Stranded-request diagnostics -- ' . self::summary($record) . " (JSON):\n";

        $withoutHistory = $record;
        $withoutHistory['stranded_previously'] = 'over_budget';

        $minimal = array(
            'census_status' => $record['census_status'],
            'in_flight_total' => $record['in_flight_total'],
            'stranded_now_count' => count(self::listOf($record, 'stranded_now')),
            'stranded_now_phases' => self::phaseTally(self::listOf($record, 'stranded_now')),
            'reduced' => 'over_budget',
        );

        foreach (array($record, $withoutHistory, $minimal) as $candidate) {
            $line = json_encode(array(self::STRANDED_DIAG_KEY => $candidate));
            if (is_string($line)
                    && strlen($header) + strlen($line) <= self::MAX_STRANDED_DIAG_BYTES) {
                return $header . $line;
            }
        }
        return $header . 'The stranded-request record could not be encoded for this payload.';
    }

    /**
     * The one-line version: how many are stranded right now and where they are
     * stuck, because the phase tally is the finding and the count alone is not.
     *
     * @param array<string, mixed> $record
     */
    private static function summary(array $record): string {
        $now = self::listOf($record, 'stranded_now');
        $previously = self::listOf($record, 'stranded_previously');
        if ($now === array() && $previously === array()) {
            return 'census ' . (is_string($record['census_status'] ?? null)
                ? $record['census_status'] : 'unknown') . ': no stranded requests';
        }
        $tally = self::phaseTally($now);
        $phases = array();
        foreach ($tally as $phase => $count) {
            $phases[] = $phase . ' x' . $count;
        }
        return count($now) . ' stranded now'
            . ($phases !== array() ? ' (' . implode(', ', $phases) . ')' : '')
            . ', ' . count($previously) . ' recorded previously';
    }

    /**
     * How many stranded requests sit in each lifecycle segment.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, int>
     */
    private static function phaseTally(array $entries): array {
        $tally = array();
        foreach ($entries as $entry) {
            $phase = is_array($entry) && isset($entry['phase']) && is_string($entry['phase'])
                ? $entry['phase'] : 'unrecorded';
            $tally[$phase] = isset($tally[$phase]) ? $tally[$phase] + 1 : 1;
        }
        return $tally;
    }

    /**
     * One record field as a list, or an empty list when it is absent or was
     * already shed to fit the budget.
     *
     * @param array<string, mixed> $record
     * @return array<int, array<string, mixed>>
     */
    private static function listOf(array $record, string $field): array {
        $value = $record[$field] ?? null;
        if (!is_array($value)) {
            return array();
        }
        $entries = array();
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }
}
