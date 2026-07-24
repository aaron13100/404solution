<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The support-payload section for the per-failing-session diagnostics (Bruno
 * timeout gap-hunt iteration 5, Opus gap 4).
 *
 * ABJ_404_Solution_FailingSessionEvidence owns the domain question -- which
 * sessions the condemned requests belong to, and each one's detach A/B verdict
 * and encoded-size basis. This class owns the SUPPORT-PAYLOAD job around it:
 * gathering the inputs that question needs (the condemned-request index built
 * from both journals plus the clicking tab's own drained-buffer failures), and
 * rendering the resulting record into a byte-bounded, self-describing block
 * that fits the report contract. Keeping the two apart is the same layer split
 * the rest of this subsystem follows: the evidence class reads nothing about
 * the payload's byte budget, and this section makes no decision about session
 * attribution.
 *
 * The failing-request index is rebuilt here rather than shared with the two
 * journal excerpts' own index (ABJ_404_Solution_SupportEvidenceExcerpt::
 * collectChannels): both are derived deterministically from the same
 * supportCollectionSource() paths and the same failureIndex() pass plus the
 * same client outcomes, so they agree by construction, and a support request
 * is a one-shot admin action where a second bounded journal pass is cheap
 * next to the certainty of a single source of the attribution rule.
 */
final class ABJ_404_Solution_FailingSessionSupportSection {

    /**
     * Hard cap on the rendered block. A bounded number of failing sessions,
     * each a compact verdict plus an encoded-size basis, so it is small by
     * construction; the cap keeps it so regardless of how many sessions a busy
     * site put failures into, and render() sheds the per-session id lists to
     * fit rather than being cut mid-record. Reclaimed from the checkpoint
     * excerpt budget so the section sum stays inside the report contract -- see
     * ABJ_404_Solution_CheckpointJournalReader::MAX_SUPPORT_EXCERPT_BYTES and
     * SupportExcerptBudgetContractTest.
     */
    const MAX_FAILING_SESSION_DIAG_BYTES = 4096;

    /** The one JSON key the block hangs under, so a reader can grep for it. */
    const FAILING_SESSION_DIAG_KEY = 'abj404_failing_session_diag';

    /**
     * The whole section, ready to join into the support payload. Never throws:
     * a support request is the last thing that may be blocked by its own
     * diagnostics, so a journal read or a missing class degrades to a stated
     * reason rather than a fatal in the request the admin is waiting on.
     *
     * @param array{status: string, ids: array<int, string>, records: int, outcomes: array<string, bool>} $clientAttempts
     */
    public static function compose(array $clientAttempts, string $clickSessionId): string {
        if (!class_exists('ABJ_404_Solution_FailingSessionEvidence')) {
            return 'Failing-session diagnostics unavailable: ABJ_404_Solution_FailingSessionEvidence'
                . ' could not be loaded on this install, so per-session verdicts were not computed here.';
        }
        try {
            $clientFailingIds = self::clientFailingIds($clientAttempts);
            $failingIds = self::failingRequestIndex(self::diagnosticSources(), $clientFailingIds);
            return self::render(
                ABJ_404_Solution_FailingSessionEvidence::forSupport(
                    $failingIds, $clientFailingIds, $clickSessionId));
        } catch (Throwable $e) {
            return 'Failing-session diagnostics could not be computed: ' . substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * The two durable diagnostic journals' candidate paths, each guarded the
     * way the rest of assembly is (a corrupt install can be missing any plugin
     * file; see the safe-autoloader work for error 18).
     *
     * @return array<int, array{channel: string, directory: string, usable: bool, paths: array<int, string>}>
     */
    private static function diagnosticSources(): array {
        $sources = array();
        if (class_exists('ABJ_404_Solution_AjaxRequestTrace')) {
            $sources[] = ABJ_404_Solution_AjaxTraceJournal::supportCollectionSource();
        }
        if (class_exists('ABJ_404_Solution_CheckpointJournalReader')) {
            $sources[] = ABJ_404_Solution_CheckpointJournalReader::supportCollectionSource();
        }
        return $sources;
    }

    /**
     * The request ids the browser condemned in its OWN drained buffer. These
     * belong to the clicking tab by construction -- the browser holds only its
     * own tab's transport buffer -- so the evidence class can attribute an
     * untraced one to the click session instead of dropping it.
     *
     * @param array{status: string, ids: array<int, string>, records: int, outcomes: array<string, bool>} $clientAttempts
     * @return array<string, bool>
     */
    private static function clientFailingIds(array $clientAttempts): array {
        $outcomes = isset($clientAttempts['outcomes']) && is_array($clientAttempts['outcomes'])
            ? $clientAttempts['outcomes'] : array();
        $failing = array();
        foreach ($outcomes as $requestId => $healthy) {
            if ($healthy === false) {
                $failing[(string)$requestId] = true;
            }
        }
        return $failing;
    }

    /**
     * Every request id condemned anywhere: unioned across both journals'
     * failure indexes and the clicking tab's drained-buffer failures. Matches,
     * by construction, the index the journal excerpts rank on.
     *
     * @param array<int, array{channel: string, directory: string, usable: bool, paths: array<int, string>}> $sources
     * @param array<string, bool> $clientFailingIds
     * @return array<string, bool>
     */
    private static function failingRequestIndex(array $sources, array $clientFailingIds): array {
        $failingIds = array();
        if (class_exists('ABJ_404_Solution_DiagnosticJournalExcerpt')) {
            foreach ($sources as $source) {
                $failingIds += ABJ_404_Solution_DiagnosticJournalExcerpt::failureIndex($source['paths']);
            }
        }
        foreach ($clientFailingIds as $id => $present) {
            $failingIds[$id] = true;
        }
        return $failingIds;
    }

    /**
     * The record as a scannable header line plus one JSON record.
     *
     * Over-budget input sheds the per-session id lists and the unresolved list
     * first -- the reducible detail -- then falls back to the relationship and
     * counts alone, rather than being cut at a byte offset. A record cut
     * mid-JSON is unreadable by machine and misleading to a human.
     *
     * @param array<string, mixed> $record ABJ_404_Solution_FailingSessionEvidence::forSupport().
     */
    private static function render(array $record): string {
        $header = 'Failing-session diagnostics -- ' . self::summary($record) . " (JSON):\n";

        $reduced = $record;
        $reducedSessions = array();
        foreach (is_array($record['sessions'] ?? null) ? $record['sessions'] : array() as $session) {
            if (is_array($session)) {
                unset($session['failing_request_ids']);
                $session['failing_request_ids_reduced'] = 'over_budget';
            }
            $reducedSessions[] = $session;
        }
        $reduced['sessions'] = $reducedSessions;
        $reduced['unresolved_failing_request_ids'] = array();

        $minimal = array(
            'status' => self::textOf($record, 'status'),
            'click_session_key' => self::textOf($record, 'click_session_key'),
            'click_vs_failing' => self::textOf($record, 'click_vs_failing'),
            'failing_request_count' => self::countOf($record, 'failing_request_count'),
            'sessions_resolved' => self::countOf($record, 'sessions_resolved'),
            'reduced' => 'over_budget',
        );

        foreach (array($record, $reduced, $minimal) as $candidate) {
            $line = json_encode(array(self::FAILING_SESSION_DIAG_KEY => $candidate));
            if (is_string($line)
                    && strlen($header) + strlen($line) <= self::MAX_FAILING_SESSION_DIAG_BYTES) {
                return $header . $line;
            }
        }
        return $header . 'The failing-session diagnostics record could not be encoded for this payload.';
    }

    /**
     * The one-line version: the relationship first, then how much failing
     * evidence it was drawn from. 'foreign_sessions_only' over four failing
     * requests and 'no_failing_sessions' over zero are different findings, so
     * the counts are part of the summary rather than decoration.
     *
     * @param array<string, mixed> $record
     */
    private static function summary(array $record): string {
        return self::textOf($record, 'status') . ': ' . self::textOf($record, 'click_vs_failing') . '; '
            . self::countOf($record, 'failing_request_count') . ' failing request(s), '
            . self::countOf($record, 'sessions_resolved') . ' session(s) resolved, '
            . self::countOf($record, 'unresolved_failing_request_count') . ' unattributed';
    }

    /**
     * One record field as a string, or '' when it is absent or not scalar.
     *
     * @param array<string, mixed> $record
     */
    private static function textOf(array $record, string $field): string {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * One record field as an integer, or 0 when it is absent or not scalar.
     *
     * @param array<string, mixed> $record
     */
    private static function countOf(array $record, string $field): int {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (int)$value : 0;
    }
}
