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
 * Tier 1 is decided from ONE index across every journal, not from each file's
 * own contents: the browser's verdicts are written to the checkpoint journal
 * alone, and a request PHP completed but the browser never received looks
 * exactly like healthy traffic anywhere else. Resolving and collecting those
 * verdicts is ABJ_404_Solution_DiagnosticClientVerdict's; this class only
 * receives the resulting ids and promotes the groups it already holds.
 *
 * Within tiers 1 and 2 the order is OLDEST first: the first failure happened
 * without the confounding effect of retries, warmed caches, or an already
 * degraded host, so it is the most diagnostic single request in the file.
 * Each prioritized request also holds a reserved share of the budget, so one
 * pathologically long request cannot starve the other failures -- except a
 * request with no terminal event, which is granted its full record run
 * before any share is split at all. Its middle is the only account of the
 * stall; a completed request's middle is comparatively spendable, so a
 * completed request's records are trimmed first when the budget is tight
 * (report 193: a 165-second holder with no request_end had 7 of its records
 * dropped by an even split before this ordering existed).
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
        'ajax_failure_branch',
        'client_report_error',
        'request_id_header_mismatch',
        'abandoned_recovered',
    );

    /** Bucket for records that carry no usable request id (torn writes, foreign lines). */
    const UNJOINABLE_KEY = "\0unjoinable";

    /**
     * Choose the lines to ship, in their original file order.
     *
     * @param array<int, string> $lines JSONL lines, oldest first, newline-free.
     * @param int $budgetBytes Hard ceiling for the returned lines including their newlines.
     * @param array<string, bool> $knownFailingIds Request ids condemned in a DIFFERENT
     *   journal, keyed by id, from
     *   ABJ_404_Solution_DiagnosticClientVerdict::requestIdsIn().
     * @return array{lines: array<int, string>, summary: array<string, int>}
     */
    public static function select(array $lines, int $budgetBytes, array $knownFailingIds = array()): array {
        $groups = self::group($lines);
        self::applyKnownFailures($groups, $knownFailingIds);
        $failingIds = self::classifyFailures($groups);
        $ordered = self::orderByPriority($groups, $failingIds);

        $selection = ABJ_404_Solution_DiagnosticEvidenceBudget::allocate(
            $lines, $groups, $ordered['ids'], $ordered['prioritized'], $budgetBytes);

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
     * Fold a record's verdict about another request into that request's group.
     *
     * The verdict always names an id other than the one whose envelope carries
     * it, so a record can condemn a group these lines have not reached yet, or
     * one they never reach at all.
     *
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     * @param array<array-key, mixed> $record
     */
    private static function applyClientVerdict(array &$groups, array $record): void {
        $reportedId = ABJ_404_Solution_DiagnosticClientVerdict::condemnedRequestId($record);
        if ($reportedId === '') {
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
     * Fold verdicts found in OTHER journals into the groups this one holds.
     *
     * Only groups that exist here are promoted; a condemned id this journal
     * never recorded mints nothing. That asymmetry with the in-journal pass is
     * deliberate: a request that died before its first stage legitimately
     * writes nothing to the stage trace, so minting a placeholder for it there
     * would report a failing request the excerpt could never carry and make
     * the accounting claim evidence was lost when none ever existed. The
     * journal that DID record the verdict still mints its own placeholder, so
     * the "condemned and completely absent" case stays stated exactly once.
     *
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     * @param array<string, bool> $knownFailingIds
     */
    private static function applyKnownFailures(array $groups, array $knownFailingIds): void {
        foreach (array_keys($knownFailingIds) as $id) {
            $id = (string)$id;
            if (isset($groups[$id]) && $groups[$id]->hasRecords()) {
                $groups[$id]->markFailed();
            }
        }
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
