<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the browser said about which SERVER requests failed.
 *
 * Only the browser can see a request that produced no response at all: PHP can
 * run a table request to completion, write a full lifecycle, exit cleanly, and
 * the response can still never arrive. Nothing on the server side distinguishes
 * that request from healthy traffic, so the browser's account of it is the most
 * authoritative failure signal the diagnostics have.
 *
 * That account always arrives on a DIFFERENT request than the one it is about,
 * by two routes:
 *
 *   1. `client_prior_attempt` -- the browser attaches its record of the
 *      previous attempt to the following table request, so a verdict about
 *      attempt N rides attempt N+1's envelope.
 *   2. `client_report_only_branch` -- a `sendBeacon` delivery, which is a
 *      second HTTP request whose only purpose is to talk about a first one that
 *      failed. It travels under its own carrier id and names the attempt it
 *      reports on in `reported_attempt_id`.
 *
 * Resolving "which request id does this record condemn" is therefore its own
 * decision, and it lives here rather than inside a ranking class because two
 * unrelated callers need it: the per-journal ranking
 * (ABJ_404_Solution_DiagnosticEvidencePriority) folds each verdict into the
 * condemned request's group, while ABJ_404_Solution_DiagnosticJournalExcerpt
 * builds a CROSS-journal index from it and wants no grouping, no ranking and no
 * budget at all. One extractor for both is the point: the browser writes its
 * verdicts to one journal only, and two extractors would be two chances for the
 * two journals to disagree about which requests failed.
 *
 * This class reads no files, holds no budget policy, and formats nothing.
 */
final class ABJ_404_Solution_DiagnosticClientVerdict {

    /**
     * The browser's account of an EARLIER attempt, journaled against whichever
     * request carried it here.
     */
    const PRIOR_ATTEMPT_EVENT = 'client_prior_attempt';

    /**
     * The branch a report-only beacon takes. A beacon is sent only after the
     * FINAL attempt of a request has failed, so the attempt it names on its
     * envelope is condemned by the beacon's mere existence -- which is what
     * keeps the verdict readable when the report it carried arrives truncated
     * or unparseable and the id inside it is gone.
     */
    const BEACON_BRANCH_EVENT = 'client_report_only_branch';

    /**
     * The only client-reported outcome that means an attempt did not fail.
     *
     * Deliberately a one-element allowlist rather than a deny-list of known
     * failure words. 'pending' is an attempt that never finished, which is the
     * hung request itself; an absent or unrecognised outcome is an unknown,
     * and an unknown is more interesting than a known success. Over-including
     * evidence costs budget; under-including it costs the whole investigation.
     */
    const HEALTHY_OUTCOMES = array('success');

    /**
     * The request id one journal record condemns, or '' when it condemns none.
     *
     * @param array<array-key, mixed> $record One decoded JSONL record.
     */
    public static function condemnedRequestId(array $record): string {
        $reported = self::reportedOutcome($record);
        return $reported['ok'] ? '' : $reported['id'];
    }

    /**
     * What one journal record says about the attempt it names, or an empty id
     * when it names none.
     *
     * Split out of condemnedRequestId() because two callers need opposite
     * halves of the same answer: ranking only cares which attempts FAILED,
     * while the detach A/B verdict (ABJ_404_Solution_DetachAbEvidence) needs
     * the successes too -- an experiment that separates "ON completed" from
     * "OFF did not" cannot be decided from failures alone. Resolved once here
     * so the two can never disagree about which attempt a report is about.
     *
     * @param array<array-key, mixed> $record One decoded JSONL record.
     * @return array{id: string, ok: bool} ok is true only for an outcome that
     *   positively means the attempt completed.
     */
    public static function reportedOutcome(array $record): array {
        $event = isset($record['event']) && is_scalar($record['event']) ? (string)$record['event'] : '';
        if ($event === self::PRIOR_ATTEMPT_EVENT) {
            if (!isset($record['report']) || !is_array($record['report'])) {
                return array('id' => '', 'ok' => false);
            }
            $report = $record['report'];
            $outcome = isset($report['outcome']) && is_scalar($report['outcome'])
                ? (string)$report['outcome'] : '';
            return array(
                'id' => self::journalKeyOfReportedAttempt($report),
                'ok' => in_array($outcome, self::HEALTHY_OUTCOMES, true),
            );
        }
        if ($event === self::BEACON_BRANCH_EVENT) {
            // No outcome to weigh: the beacon fires only from the transport's
            // terminal-error path, so it exists only because the attempt it
            // names failed. Older clients name nothing and condemn nothing --
            // their beacon still rides the attempt's own id, and the report it
            // carries is the verdict, exactly as before.
            return array('id' => self::journalKeyOf($record['reported_attempt_id'] ?? null), 'ok' => false);
        }
        return array('id' => '', 'ok' => false);
    }

    /**
     * Every attempt the browser reported on anywhere in a stream of records,
     * keyed by the journal key that attempt was recorded under.
     *
     * An attempt absent from the returned map is one the browser has not
     * spoken about, which is a different thing from one it reported as failed:
     * callers that tally outcomes must treat the absence as unknown, never as
     * a failure. A failure, once reported, is sticky -- a later duplicate or
     * reordered report cannot un-fail an attempt, and between two reports the
     * failing one is always the more interesting finding.
     *
     * @param array<int, string> $lines JSONL lines, any order.
     * @return array<string, bool> true = the browser saw that attempt complete.
     */
    public static function reportedOutcomesIn(array $lines): array {
        $outcomes = array();
        foreach ($lines as $line) {
            if (strpos($line, self::PRIOR_ATTEMPT_EVENT) === false
                    && strpos($line, self::BEACON_BRANCH_EVENT) === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $reported = self::reportedOutcome($record);
            if ($reported['id'] === '') {
                continue;
            }
            if (!array_key_exists($reported['id'], $outcomes) || !$reported['ok']) {
                $outcomes[$reported['id']] = $reported['ok'];
            }
        }
        return $outcomes;
    }

    /**
     * Every request id condemned anywhere in a stream of journal records.
     *
     * The plugin keeps two independent journals for one request, but the
     * browser's verdicts land in only ONE of them: both events above are
     * written by ABJ_404_Solution_ClientTransportReport through the checkpoint
     * logger. Ranking each journal purely from its own contents therefore left
     * the stage trace unable to see the one failure mode only the browser can
     * report, and an attempt in that state is indistinguishable from healthy
     * traffic in the trace file -- so it was spent on budget like any other
     * completed request. Building this index once and handing it to BOTH
     * selections is what makes the two journals agree.
     *
     * @param array<int, string> $lines JSONL lines, any order.
     * @return array<string, bool> Condemned ids, keyed by id.
     */
    public static function requestIdsIn(array $lines): array {
        $ids = array();
        foreach ($lines as $line) {
            // Only two events can carry a verdict, and this pass runs over
            // whole journals in addition to the ranking pass that follows it,
            // so lines that cannot possibly match are rejected before the JSON
            // decoder sees them. Keyed off the same constants the decoder
            // matches on, so a renamed event cannot make this filter silently
            // start dropping verdicts.
            if (strpos($line, self::PRIOR_ATTEMPT_EVENT) === false
                    && strpos($line, self::BEACON_BRANCH_EVENT) === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $condemned = self::condemnedRequestId($record);
            if ($condemned !== '') {
                $ids[$condemned] = true;
            }
        }
        return $ids;
    }

    /**
     * The journal key the browser's report is talking about, or '' when it
     * named nothing usable.
     *
     * Every group in a journal is keyed by whatever the browser sent as the
     * wire `requestId`, normalized by the ledger on arrival. The browser sends
     * its PER-ATTEMPT composite id there whenever the attempt recorder is
     * running (`record.id`, e.g. `abc123t2`), and falls back to the LOGICAL
     * request id (`record.rid`, `abc123` -- the prefix every retry of one part
     * shares) only when the recorder did not load and no attempt id exists. So
     * the key is resolved in exactly that order, through exactly that
     * normalization.
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
            $key = self::journalKeyOf($report[$field] ?? null);
            if ($key !== '') {
                return $key;
            }
        }
        return '';
    }

    /**
     * One client-sent id as the group key it belongs to, or '' when it named
     * nothing at all.
     *
     * Normalized, not compared raw: an id the ledger refuses was journaled
     * under its unknown-id sentinel, so that sentinel is the group the verdict
     * belongs to. A raw comparison against an already-normalized key can only
     * ever miss. An ABSENT value is different from a refused one and stays '',
     * so a client that named no attempt condemns nothing rather than condemning
     * the unjoinable bucket.
     *
     * @param mixed $raw
     */
    private static function journalKeyOf($raw): string {
        if (!is_scalar($raw) || (string)$raw === '') {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::normalizeId($raw);
    }
}
