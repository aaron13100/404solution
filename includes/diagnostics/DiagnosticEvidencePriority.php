<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which diagnostic records survive a bounded support payload, and in what order.
 *
 * The journals are written for one purpose: to explain the requests that
 * FAILED. A byte budget therefore cannot be spent chronologically. Measured
 * against a real session, a blind tail of the checkpoint files delivered 4 of
 * 72 request IDs -- and the four were the canary ladder that fires AFTER the
 * failure, not the failing attempts themselves. The evidence was written
 * correctly to disk and then discarded in transit, which is the same
 * "came back empty" outcome the flight recorder exists to prevent.
 *
 * So selection is by request, not by byte offset, and requests are ranked:
 *
 *   1. Requests that failed -- the browser reported a non-success outcome for
 *      them, they recorded a failure branch, or they simply stop with no
 *      terminal record at all (the signature of a request that hung).
 *   2. Requests joined to a failing one by the retry chain, transitively, so a
 *      retry is always readable next to the attempt it was retrying.
 *   3. Everything else, newest first. Healthy traffic and canary probes are
 *      context; they are spent last and they can never evict tier 1.
 *
 * Within tiers 1 and 2 the order is OLDEST first: the first failure happened
 * without the confounding effect of retries, warmed caches, or an already
 * degraded host, so it is the most diagnostic single request in the file.
 * Each prioritized request also holds a reserved share of the budget, so one
 * pathologically long request cannot starve the other failures.
 *
 * This class decides only WHICH lines matter. It reads no files and formats
 * nothing; ABJ_404_Solution_DiagnosticJournalExcerpt does both.
 */
final class ABJ_404_Solution_DiagnosticEvidencePriority {

    /**
     * Events that prove a request reached an orderly end. A request with
     * records but none of these stopped mid-flight, which is exactly the
     * failure under investigation, so absence is a tier-1 signal.
     */
    const TERMINAL_EVENTS = array(
        'exit_sentinel',
        'request_end',
        'finish_request_result',
        'shutdown_action_max',
        'shutdown_response_time',
        'abandoned_recovered',
    );

    /**
     * Events that are a failure finding in themselves, whatever else the
     * request managed to do afterwards.
     */
    const FAILURE_EVENTS = array(
        'auth_failure_branch',
        'auth_service_unavailable_branch',
        'rate_limit_branch',
        'client_report_error',
        'request_id_header_mismatch',
        'abandoned_recovered',
    );

    /**
     * The only client-reported outcome that means an attempt did not fail.
     *
     * Deliberately a one-element allowlist rather than a deny-list of known
     * failure words. 'pending' is an attempt that never finished, which is the
     * hung request itself; an absent or unrecognised outcome is an unknown,
     * and an unknown is more interesting than a known success. Over-including
     * evidence costs budget; under-including it costs the whole investigation.
     */
    const HEALTHY_CLIENT_OUTCOMES = array('success');

    /** Bucket for records that carry no usable request id (torn writes, foreign lines). */
    const UNJOINABLE_KEY = "\0unjoinable";

    /**
     * Choose the lines to ship, in their original file order.
     *
     * @param array<int, string> $lines JSONL lines, oldest first, newline-free.
     * @param int $budgetBytes Hard ceiling for the returned lines including their newlines.
     * @return array{lines: array<int, string>, summary: array<string, int>}
     */
    public static function select(array $lines, int $budgetBytes): array {
        $groups = self::group($lines);
        $failingIds = self::classifyFailures($groups);
        $ordered = self::orderByPriority($groups, $failingIds);

        $selection = self::fillBudget($lines, $groups, $ordered['ids'], $ordered['prioritized'], $budgetBytes);

        return array(
            'lines' => $selection['lines'],
            'summary' => self::summarize($lines, $groups, $failingIds, $selection, $budgetBytes),
        );
    }

    /**
     * One group per request id, in first-seen order.
     *
     * Decoded records are deliberately not retained: each one is folded into
     * its group's classification and then dropped, so a megabyte of JSONL does
     * not become many megabytes of live PHP arrays inside an admin request.
     *
     * @param array<int, string> $lines
     * @return array<string, ABJ_404_Solution_DiagnosticRequestGroup>
     */
    private static function group(array $lines): array {
        $groups = array();
        foreach ($lines as $index => $line) {
            $record = json_decode($line, true);
            $id = self::UNJOINABLE_KEY;
            if (is_array($record) && isset($record['request_id']) && is_scalar($record['request_id'])
                    && (string)$record['request_id'] !== '') {
                $id = (string)$record['request_id'];
            }
            if (!isset($groups[$id])) {
                $groups[$id] = new ABJ_404_Solution_DiagnosticRequestGroup(
                    $id, $id !== self::UNJOINABLE_KEY);
            }
            $groups[$id]->addLine($index, strlen($line) + 1);
            if (!is_array($record)) {
                continue;
            }
            $groups[$id]->applyRecord($record);
            self::applyClientVerdict($groups, $record);
        }
        return $groups;
    }

    /**
     * Fold the browser's verdict about a DIFFERENT attempt into that other
     * attempt's group.
     *
     * The client's account of a previous attempt rides a LATER request, so
     * this record condemns an id other than the one whose envelope carries it.
     * That indirection is the most authoritative failure signal there is: only
     * the browser can see a request that produced no response at all.
     *
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     * @param array<array-key, mixed> $record
     */
    private static function applyClientVerdict(array &$groups, array $record): void {
        $event = isset($record['event']) && is_scalar($record['event']) ? (string)$record['event'] : '';
        if ($event !== 'client_prior_attempt' || !isset($record['report']) || !is_array($record['report'])) {
            return;
        }
        $report = $record['report'];
        $outcome = isset($report['outcome']) && is_scalar($report['outcome']) ? (string)$report['outcome'] : '';
        $reportedId = self::journalKeyOfReportedAttempt($report);
        if ($reportedId === '' || in_array($outcome, self::HEALTHY_CLIENT_OUTCOMES, true)) {
            return;
        }
        if (!isset($groups[$reportedId])) {
            // The condemned attempt wrote nothing here at all -- it may never
            // have reached PHP. Recorded as a known-failing id with no records
            // so the summary can say so out loud instead of it being absent.
            $groups[$reportedId] = new ABJ_404_Solution_DiagnosticRequestGroup($reportedId, true);
        }
        $groups[$reportedId]->markFailed();
    }

    /**
     * The journal key the browser's report is talking about, or '' when it
     * named nothing usable.
     *
     * Every group here is keyed by whatever the browser sent as the wire
     * `requestId`, normalized by the ledger on arrival. The browser sends its
     * PER-ATTEMPT composite id there whenever the attempt recorder is running
     * (`record.id`, e.g. `abc123t2`), and falls back to the LOGICAL request id
     * (`record.rid`, `abc123` -- the prefix every retry of one part shares)
     * only when the recorder did not load and no attempt id exists. So the key
     * is resolved in exactly that order, through exactly that normalization.
     *
     * Reading the logical id alone was a join that could never land: while the
     * recorder runs, no group is ever keyed by it, so the verdict minted an
     * empty placeholder and the attempt that actually failed stayed ranked as
     * healthy context. That silently defeated the one case only the browser
     * can report -- PHP completed the request and the response never arrived.
     *
     * @param array<array-key, mixed> $report
     */
    private static function journalKeyOfReportedAttempt(array $report): string {
        foreach (array('id', 'rid') as $field) {
            $raw = $report[$field] ?? null;
            if (!is_scalar($raw) || (string)$raw === '') {
                continue;
            }
            // Normalized, not compared raw: an id the ledger refuses was
            // journaled under its unknown-id sentinel, so that sentinel is the
            // group this verdict belongs to. A raw comparison against an
            // already-normalized key can only ever miss.
            return ABJ_404_Solution_AjaxRequestLedger::normalizeId($raw);
        }
        return '';
    }

    /**
     * Ids of every request that failed.
     *
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     * @return array<string, bool>
     */
    private static function classifyFailures(array $groups): array {
        $failing = array();
        foreach ($groups as $id => $group) {
            if ($group->isFailing()) {
                $failing[$id] = true;
            }
        }
        return $failing;
    }

    /**
     * Request ids in the order their budget is granted, plus how many of them
     * are prioritized (tiers 1 and 2) and therefore hold a reserved share.
     *
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     * @param array<string, bool> $failingIds
     * @return array{ids: array<int, string>, prioritized: int}
     */
    private static function orderByPriority(array $groups, array $failingIds): array {
        $chainIds = self::retryChainOf($groups, $failingIds);

        $failing = array();
        $chain = array();
        $rest = array();
        foreach ($groups as $id => $group) {
            if (!$group->hasRecords()) {
                continue;
            }
            if (isset($failingIds[$id])) {
                $failing[] = $id;
            } elseif (isset($chainIds[$id])) {
                $chain[] = $id;
            } else {
                $rest[] = $id;
            }
        }
        // Tier 3 newest first: recent context is what a reader can still
        // correlate with the moment the admin clicked "send". Unjoinable lines
        // lead it, because a torn journal is itself a finding.
        //
        // Partitioned by hand rather than sorted: usort() is only stable from
        // PHP 8.0, and this plugin still runs on 7.4, where an equal-comparing
        // sort would quietly scramble the newest-first order that decides
        // which requests count as recent context.
        $unjoinable = array();
        $joinable = array();
        foreach (array_reverse($rest) as $id) {
            if ($id === self::UNJOINABLE_KEY) {
                $unjoinable[] = $id;
            } else {
                $joinable[] = $id;
            }
        }

        return array(
            'ids' => array_merge($failing, $chain, $unjoinable, $joinable),
            'prioritized' => count($failing) + count($chain),
        );
    }

    /**
     * Transitive retry-chain closure around the failing requests: parents of a
     * failing request, and any request that names a chain member as its parent.
     *
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     * @param array<string, bool> $failingIds
     * @return array<string, bool>
     */
    private static function retryChainOf(array $groups, array $failingIds): array {
        $children = array();
        foreach ($groups as $id => $group) {
            foreach ($group->parentIds() as $parentId) {
                $children[$parentId][(string)$id] = true;
            }
        }
        $chain = array();
        $pending = array_keys($failingIds);
        while ($pending !== array()) {
            $id = (string)array_pop($pending);
            $neighbours = isset($groups[$id]) ? $groups[$id]->parentIds() : array();
            foreach (array_keys($children[$id] ?? array()) as $childId) {
                $neighbours[] = (string)$childId;
            }
            foreach ($neighbours as $neighbourId) {
                $neighbourId = (string)$neighbourId;
                if (isset($failingIds[$neighbourId]) || isset($chain[$neighbourId])
                        || !isset($groups[$neighbourId])) {
                    continue;
                }
                $chain[$neighbourId] = true;
                $pending[] = $neighbourId;
            }
        }
        return $chain;
    }

    /**
     * Grant budget in priority order. Each prioritized request still to come
     * holds a reserved share, so an early one can use everything that is not
     * reserved but can never consume a later failure's guarantee.
     *
     * @param array<int, string> $lines
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     * @param array<int, string> $ordered
     * @return array{lines: array<int, string>, requests: int, records: int, bytes: int,
     *     includedIds: array<string, bool>, elided: int}
     */
    private static function fillBudget(array $lines, array $groups, array $ordered,
            int $prioritizedCount, int $budgetBytes): array {
        $reservePerRequest = $prioritizedCount > 0 ? intdiv(max(0, $budgetBytes), $prioritizedCount) : 0;
        $remaining = max(0, $budgetBytes);
        $keptIndexes = array();
        $notes = array();
        $included = array();
        $elided = 0;

        $prioritizedLeft = $prioritizedCount;
        foreach ($ordered as $position => $id) {
            $isPrioritized = $position < $prioritizedCount;
            if ($isPrioritized) {
                $prioritizedLeft--;
            }
            $heldBack = $isPrioritized ? ($prioritizedLeft * $reservePerRequest) : 0;
            $allowance = max(0, $remaining - $heldBack);
            if ($allowance <= 0) {
                continue;
            }
            $picked = self::pickWithinAllowance($lines, $groups[$id]->indexes(), $allowance, $id);
            if ($picked['indexes'] === array()) {
                continue;
            }
            foreach ($picked['indexes'] as $index) {
                $keptIndexes[$index] = true;
            }
            $remaining -= $picked['bytes'];
            $included[$id] = true;
            $elided += $picked['elided'];
            if ($picked['elided'] > 0) {
                $notes[max($picked['indexes'])] = self::elisionNote($id, $picked['elided']);
            }
        }

        ksort($keptIndexes);
        $out = array();
        $records = 0;
        foreach (array_keys($keptIndexes) as $index) {
            $out[] = $lines[$index];
            $records++;
            if (isset($notes[$index])) {
                $out[] = $notes[$index];
            }
        }

        return array(
            'lines' => $out,
            'requests' => count($included),
            'records' => $records,
            'bytes' => max(0, $budgetBytes) - $remaining,
            'includedIds' => $included,
            'elided' => $elided,
        );
    }

    /**
     * The line indexes of one request that fit in $allowance. When the whole
     * request does not fit, records are taken from both ENDS: the opening
     * records carry the environment the request ran in, and the last ones are
     * where it stopped. The middle is what a reader can most afford to lose.
     *
     * @param array<int, string> $lines
     * @param array<int, int> $indexes
     * @return array{indexes: array<int, int>, bytes: int, elided: int}
     */
    private static function pickWithinAllowance(array $lines, array $indexes, int $allowance,
            string $requestId): array {
        $total = 0;
        foreach ($indexes as $index) {
            $total += strlen($lines[$index]) + 1;
        }
        if ($total <= $allowance) {
            return array('indexes' => $indexes, 'bytes' => $total, 'elided' => 0);
        }

        // The note that will declare the elision costs bytes too, and its
        // length depends on the request id (up to 64 characters) and the
        // count. Reserved at its true upper bound rather than at a guessed
        // constant: a reserve that is one byte short makes the returned block
        // exceed a budget the caller was promised was hard.
        $noteReserve = strlen(self::elisionNote($requestId, PHP_INT_MAX)) + 1;
        $allowance -= $noteReserve;
        $head = array();
        $tail = array();
        $used = $noteReserve;
        $low = 0;
        $high = count($indexes) - 1;
        $fromHead = true;
        while ($low <= $high && $allowance > 0) {
            $index = $fromHead ? $indexes[$low] : $indexes[$high];
            $cost = strlen($lines[$index]) + 1;
            if ($cost > $allowance) {
                if (!$fromHead) {
                    break;
                }
                // The head record did not fit; a shorter tail record still might.
                $fromHead = false;
                continue;
            }
            $allowance -= $cost;
            $used += $cost;
            if ($fromHead) {
                $head[] = $index;
                $low++;
            } else {
                array_unshift($tail, $index);
                $high--;
            }
            $fromHead = !$fromHead;
        }

        $kept = array_merge($head, $tail);
        if ($kept === array()) {
            return array('indexes' => array(), 'bytes' => 0, 'elided' => 0);
        }
        return array('indexes' => $kept, 'bytes' => $used, 'elided' => count($indexes) - count($kept));
    }

    /**
     * A JSON line, so the excerpt stays parseable as JSONL end to end, saying
     * exactly what was dropped and why.
     */
    private static function elisionNote(string $requestId, int $elided): string {
        $note = json_encode(array(
            'abj404_excerpt_note' => 'records elided to fit the support budget',
            'request_id' => $requestId === self::UNJOINABLE_KEY ? '' : $requestId,
            'elided_records' => $elided,
        ), JSON_UNESCAPED_SLASHES);
        return is_string($note) ? $note : '{"abj404_excerpt_note":"records elided"}';
    }

    /**
     * The accounting that rides the excerpt. Without it a reader cannot tell
     * "this session only had four requests" from "sixty-eight were dropped in
     * transit", which is the exact ambiguity that made the previous excerpt
     * look like complete evidence when it was 3% of it.
     *
     * @param array<int, string> $lines
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     * @param array<string, bool> $failingIds
     * @param array{requests: int, records: int, bytes: int, includedIds: array<string, bool>, elided: int} $selection
     * @return array<string, int>
     */
    private static function summarize(array $lines, array $groups, array $failingIds,
            array $selection, int $budgetBytes): array {
        $bytesOnDisk = 0;
        $withRecords = 0;
        $unjoinable = 0;
        foreach ($groups as $id => $group) {
            $bytesOnDisk += $group->bytes();
            if ($group->hasRecords()) {
                $withRecords++;
            }
            if ($id === self::UNJOINABLE_KEY) {
                $unjoinable = $group->recordCount();
            }
        }
        $failingIncluded = 0;
        $failingWithoutRecords = 0;
        foreach (array_keys($failingIds) as $id) {
            if (isset($selection['includedIds'][$id])) {
                $failingIncluded++;
            }
            if (!isset($groups[$id]) || !$groups[$id]->hasRecords()) {
                $failingWithoutRecords++;
            }
        }
        return array(
            'requests_on_disk' => $withRecords,
            'requests_included' => $selection['requests'],
            'records_on_disk' => count($lines),
            'records_included' => $selection['records'],
            'records_elided' => $selection['elided'],
            'records_unjoinable' => $unjoinable,
            'failing_requests' => count($failingIds),
            'failing_requests_included' => $failingIncluded,
            'failing_requests_without_records' => $failingWithoutRecords,
            'bytes_on_disk' => $bytesOnDisk,
            'bytes_included' => $selection['bytes'],
            'bytes_budget' => $budgetBytes,
        );
    }
}
