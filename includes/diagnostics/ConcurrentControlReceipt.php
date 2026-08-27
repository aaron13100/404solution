<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The concurrent-control browser receipt: how it is journaled, and what makes
 * one complete enough to be believed.
 *
 * The concurrent control is a canary step launched BESIDE the first real table
 * attempt, under the same host conditions, so a table failure can be separated
 * from a host that was struggling for everyone at that instant. That only works
 * if the browser's account of the control comes back, and the browser can only
 * send it on a LATER request -- so the record arrives carried by one request,
 * describes a second, and is filed under a third.
 *
 * Those three joins, plus the browser's own observation, are what
 * `isCompleteJournalRecord()` checks. It is a contract with two consumers
 * outside this file (ABJ_404_Solution_DiagnosticCollectionManifest decides from
 * it whether required evidence is available;
 * ABJ_404_Solution_CanaryReceiptEvidence decides from it whether a ladder run
 * can be interpreted), which is why the record owns its own class rather than
 * living inside ABJ_404_Solution_ClientTransportReport -- that class reads and
 * bounds whatever the browser sent, and this one knows what this particular
 * record means.
 */
final class ABJ_404_Solution_ConcurrentControlReceipt {

    /** The browser's own name for this report kind, on the wire. */
    const KIND = 'concurrent_control_browser_receipt';

    /** The event name it is journaled under. */
    const JOURNAL_EVENT = 'concurrent_control_client_receipt';

    /**
     * File the receipt beside that control's own server trace while retaining
     * which later request delivered it.
     *
     * The browser session is stamped on the record for the same reason the
     * canary step receipts carry it (see
     * Ajax_CanaryLadder::journalPriorStepReceipts): the reconstruction that
     * consumes this record must not have to resolve the session through a
     * second, separately-armed journal that can be empty. Hashed into the
     * `session_key` this journal already uses for `detach_ab_mode`, because
     * equality is the whole requirement.
     *
     * @param string $sessionId Already bounded by AjaxRequestLedger::readFields().
     * @param array<string, mixed> $report
     */
    public static function journal(
        string $carrierRequestId,
        string $sessionId,
        array $report
    ): void {
        $controlRequestId = self::ledgerIdOrEmpty($report['controlRequestId'] ?? '');
        $controlForRequestId = self::ledgerIdOrEmpty($report['controlForRequestId'] ?? '');
        ABJ_404_Solution_AjaxCheckpointLogger::record(
            $controlRequestId !== '' ? $controlRequestId : $carrierRequestId,
            self::JOURNAL_EVENT,
            array(
                'carried_by' => $carrierRequestId,
                'control_for_request_id' => $controlForRequestId,
                'control_request_id' => $controlRequestId,
                'session_key' => ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey($sessionId),
                'report' => $report,
            )
        );
    }

    /**
     * Is this raw browser report a concurrent-control receipt at all?
     *
     * @param array<mixed, mixed> $report
     */
    public static function isBrowserReceipt(array $report): bool {
        return ($report['kind'] ?? '') === self::KIND;
    }

    /**
     * Whether a journal record is complete enough to serve as the required
     * concurrent-control evidence in a support payload.
     *
     * Both halves must hold: the JOURNAL joins (this record can be tied to the
     * control request, its carrier, and the request it was a control FOR) and
     * the BROWSER evidence (the browser actually observed an outcome and an
     * overlap). A record with joins and no observation describes a probe that
     * ran and told us nothing, and counting it as evidence would let the
     * support payload claim a control it does not have.
     *
     * @param array<mixed, mixed> $record
     */
    public static function isCompleteJournalRecord(array $record): bool {
        $report = is_array($record['report'] ?? null) ? $record['report'] : array();
        return self::hasJournalJoins($record) && self::hasBrowserEvidence($report);
    }

    /** @param array<mixed, mixed> $record */
    private static function hasJournalJoins(array $record): bool {
        return ($record['envelope'] ?? '') === ABJ_404_Solution_CheckpointRecordFactory::ENVELOPE_FULL
            && ($record['event'] ?? '') === self::JOURNAL_EVENT
            && is_string($record['carried_by'] ?? null)
            && $record['carried_by'] !== ''
            && is_string($record['control_for_request_id'] ?? null)
            && $record['control_for_request_id'] !== ''
            && is_string($record['control_request_id'] ?? null)
            && $record['control_request_id'] !== ''
            && ($record['request_id'] ?? '') === $record['control_request_id'];
    }

    /** @param array<mixed, mixed> $report */
    private static function hasBrowserEvidence(array $report): bool {
        $receipt = is_array($report['receipt'] ?? null) ? $report['receipt'] : array();
        $overlap = is_array($report['overlap'] ?? null) ? $report['overlap'] : array();
        $overlapState = $overlap['state'] ?? '';
        $validOverlap = $overlapState === 'unavailable'
            || ($overlapState === 'computed'
                && is_numeric($overlap['durationMs'] ?? null)
                && (int)$overlap['durationMs'] >= 0);
        return self::isBrowserReceipt($report)
            && $validOverlap
            && is_string($receipt['resourceTimingState'] ?? null)
            && $receipt['resourceTimingState'] !== '';
    }

    /**
     * A client-supplied request id, or '' when it is not a ledger id. The
     * journal is joined on these, so a value that cannot be one is dropped
     * rather than reflected into a record other readers will try to match.
     *
     * @param mixed $value
     */
    private static function ledgerIdOrEmpty($value): string {
        $id = is_scalar($value) ? (string)$value : '';
        return preg_match('/^[a-zA-Z0-9]{8,64}$/', $id) === 1 ? $id : '';
    }
}
