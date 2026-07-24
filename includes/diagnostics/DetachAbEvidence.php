<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The detach A/B experiment's evidence, joined and decided server-side (Bruno
 * timeout cause matrix, gap G9 / gap-hunt iteration 2 Codex gap #2).
 *
 * The experiment produces its two halves in two different places and never in
 * the same record. ABJ_404_Solution_AjaxRequestLedger::resolveDetachAbMode()
 * decides whether a given table request detaches the connection, and the
 * response tail journals that decision under the request's own id. Whether
 * that same request ever COMPLETED is something only the browser can say, and
 * it says so later, about an earlier attempt, on a different request
 * (ABJ_404_Solution_ClientTransportReport). So the verdict the whole
 * experiment exists to produce -- detach causal, transient, neither, or
 * honestly inconclusive -- lives in neither record.
 *
 * Joining them was, until this class, a manual step performed by a human
 * reading JSONL at analysis time. That is the same manual step that has
 * already gone wrong once in this investigation, and it is the reason the
 * decision rule (ABJ_404_Solution_AjaxCanaryLadder::interpretDetachAbResults())
 * had no production caller at all.
 *
 * This class owns the join and nothing else: which mode records belong to one
 * browser session, which browser verdict resolves each of them, and what the
 * accounting around that looks like. It owns no decision rule (that stays in
 * AjaxCanaryLadder, where the pure quadrant logic is already tested), no
 * transport, and no formatting.
 *
 * Three properties are load-bearing rather than defensive:
 *
 *   1. Session scoping. The checkpoint journal is site-wide while the A/B
 *      attempt counter is per session and workload scope, so two admin tabs
 *      write independent sequences into one file. Tallying them together
 *      would invent ON/OFF pairs that never existed.
 *   2. Workload matching. A part and payload fingerprint stay attached to
 *      every attempt, so faster counts requests cannot be paired with slower
 *      table requests and mistaken for a treatment effect.
 *   3. Unknown is not failure. An attempt the browser has not reported on is
 *      excluded from the tally and counted separately. Treating silence as
 *      "did not complete" would manufacture a detach-causal verdict out of
 *      evidence that has merely not arrived yet, which is worse than no
 *      verdict at all.
 */
final class ABJ_404_Solution_DetachAbEvidence {

    /** The journal event this class writes its decision under. */
    const VERDICT_EVENT = 'detach_ab_verdict';

    /** The journal event ABJ_404_Solution_AjaxResponseEmitter writes each per-request mode under. */
    const MODE_EVENT = 'detach_ab_mode';

    /** The verdict was computed from this session's own joined evidence. */
    const STATUS_COMPUTED = 'computed';

    /** This build does not run the experiment, so there is nothing to decide. */
    const STATUS_NOT_ARMED = 'not_armed';

    /** The client sent no session id, so no attempt sequence can be scoped. */
    const STATUS_NO_SESSION = 'no_session';

    /** The journal could not be read or joined; the reason travels with the record. */
    const STATUS_ERROR = 'error';

    /**
     * Attempts listed verbatim on the journaled record. One workload scope is
     * ABJ_404_Solution_AjaxRequestLedger::AB_DETACH_MAX_ATTEMPTS attempts.
     * This copy has headroom for a restarted scope, but the TALLY is never
     * bounded by it: only the human-readable copy is, so one long-lived
     * session cannot turn a decision record into a large one.
     */
    const MAX_ATTEMPTS_ON_RECORD = 12;

    /**
     * The full decision for one browser session, ready to journal.
     *
     * Never throws and never returns a partial shape: every field is present
     * on every path, and `status` says why the verdict is what it is. That is
     * the same principle the mode record itself already follows -- 'inert' is
     * recorded rather than skipped, so "the experiment did not run" is
     * positive evidence rather than an absence a reader has to infer.
     *
     * Gated on the same two opt-ins the experiment itself requires (a build
     * that arms it, and a session id to scope it to), so an ordinary released
     * install never pays for the journal read: on those builds every mode
     * record says 'inert' and there is provably nothing to join.
     *
     * @return array<string, mixed>
     */
    public static function verdictForSession(string $sessionId): array {
        $sessionKey = ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey($sessionId);
        $record = self::emptyRecord($sessionKey);
        try {
            if ($sessionKey === '') {
                $record['status'] = self::STATUS_NO_SESSION;
                return $record;
            }
            if (!ABJ_404_Solution_AjaxRequestLedger::isDetachAbDiagnosticEnabled()) {
                $record['status'] = self::STATUS_NOT_ARMED;
                return $record;
            }
            $source = ABJ_404_Solution_CheckpointJournalReader::supportCollectionSource();
            $lines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines($source['paths']);
            return self::decide($record, self::attemptsIn($lines, $sessionKey), count($lines));
        } catch (Throwable $e) {
            $record['status'] = self::STATUS_ERROR;
            $record['error'] = substr($e->getMessage(), 0, 200);
            return $record;
        }
    }

    /**
     * The record every path starts from: a complete shape with a zero-evidence
     * verdict already in it.
     *
     * The verdict is produced by the real rule rather than hand-written as a
     * literal, so a "nothing to decide" record can never drift out of step with
     * the shape a decided one has.
     *
     * @param string $sessionKey ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey().
     * @return array<string, mixed>
     */
    private static function emptyRecord(string $sessionKey): array {
        return array(
            'status' => self::STATUS_COMPUTED,
            'session_key' => $sessionKey,
            'build_channel' => ABJ_404_Solution_PluginReleaseChannel::currentChannel(),
            'attempts' => array(),
            'attempts_with_mode' => 0,
            'attempts_resolved' => 0,
            'attempts_unresolved' => 0,
            'journal_lines_scanned' => 0,
            'verdict' => ABJ_404_Solution_AjaxCanaryLadder::interpretDetachAbResults(array()),
        );
    }

    /**
     * Apply the decision rule to a completed join and fill in the accounting.
     *
     * Only attempts the browser actually resolved reach the rule; the rest are
     * counted, listed with a null outcome, and otherwise ignored.
     *
     * @param array<string, mixed> $record
     * @param array{attempts: array<int, array<string, mixed>>, unresolved: int, with_mode: int} $joined
     * @return array<string, mixed>
     */
    private static function decide(array $record, array $joined, int $linesScanned): array {
        $resolved = array();
        foreach ($joined['attempts'] as $attempt) {
            if ($attempt['ok'] !== null) {
                $resolved[] = $attempt;
            }
        }
        $record['attempts'] = array_slice($joined['attempts'], 0, self::MAX_ATTEMPTS_ON_RECORD);
        $record['attempts_with_mode'] = $joined['with_mode'];
        $record['attempts_resolved'] = count($resolved);
        $record['attempts_unresolved'] = $joined['unresolved'];
        $record['journal_lines_scanned'] = $linesScanned;
        $record['verdict'] = ABJ_404_Solution_AjaxCanaryLadder::interpretDetachAbResults($resolved);
        return $record;
    }

    /**
     * One session's A/B attempts, each paired with the browser's verdict on it.
     *
     * Pure and side-effect free: it takes journal lines rather than reading
     * them, so every join case (a foreign session, an unrecognised mode, a
     * silent attempt, a duplicate report) is directly assertable without a
     * journal on disk that happens to contain it.
     *
     * Attempt outcomes come from ABJ_404_Solution_DiagnosticClientVerdict, the
     * one extractor that already resolves a browser report to the journal key
     * its attempt was recorded under. Deriving a second keying here is exactly
     * the defect that made the browser's verdicts land on orphan placeholder
     * groups for an entire release.
     *
     * @param array<int, string> $lines JSONL lines, oldest first.
     * @param string $sessionKey ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey().
     * @return array{attempts: array<int, array<string, mixed>>, unresolved: int, with_mode: int}
     */
    public static function attemptsIn(array $lines, string $sessionKey): array {
        $modes = self::modesInSession($lines, $sessionKey);
        $outcomes = ABJ_404_Solution_DiagnosticClientVerdict::reportedOutcomesIn($lines);

        $attempts = array();
        $unresolved = 0;
        foreach ($modes as $requestId => $modeRecord) {
            $requestId = (string)$requestId;
            $resolved = array_key_exists($requestId, $outcomes);
            if (!$resolved) {
                $unresolved++;
            }
            $attempts[] = array(
                'request_id' => $requestId,
                'mode' => $modeRecord['mode'],
                'part' => $modeRecord['part'],
                'payload_key' => $modeRecord['payload_key'],
                'ordinal' => $modeRecord['ordinal'],
                'pair_ordinal' => $modeRecord['pair_ordinal'],
                'pair_position' => $modeRecord['pair_position'],
                // null, never false: "the browser has not said" and "the
                // browser said it failed" are opposite findings, and only one
                // of them belongs in the tally.
                'ok' => $resolved ? $outcomes[$requestId] : null,
            );
        }
        return array('attempts' => $attempts, 'unresolved' => $unresolved, 'with_mode' => count($modes));
    }

    /**
     * Request id to A/B slot for one session, in journal order.
     *
     * Only 'on' and 'off' are slots. 'inert' (the experiment did not run) and
     * 'default' (the session's bounded run is over) are deliberately recorded
     * by the ledger as positive evidence, and neither is a measurement: folding
     * them in would compare the experiment against itself.
     *
     * @param array<int, string> $lines
     * @return array<string, array<string, mixed>>
     */
    private static function modesInSession(array $lines, string $sessionKey): array {
        $modes = array();
        foreach ($lines as $line) {
            // Lines that cannot possibly match are rejected before the JSON
            // decoder sees them: this pass runs over whole journals, and the
            // mode records are a few dozen lines out of tens of thousands.
            if (strpos($line, self::MODE_EVENT) === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record) || ($record['event'] ?? '') !== self::MODE_EVENT) {
                continue;
            }
            if (self::scalarField($record, 'session_key') !== $sessionKey) {
                continue;
            }
            $mode = self::scalarField($record, 'mode');
            $requestId = self::scalarField($record, 'request_id');
            if (($mode !== 'on' && $mode !== 'off') || $requestId === '') {
                continue;
            }
            $part = self::scalarField($record, 'part');
            $payloadKey = self::scalarField($record, 'payload_key');
            $ordinal = isset($record['ordinal']) && is_numeric($record['ordinal'])
                ? (int)$record['ordinal'] : -1;
            $pairOrdinal = isset($record['pair_ordinal']) && is_numeric($record['pair_ordinal'])
                ? (int)$record['pair_ordinal'] : -1;
            $pairPosition = isset($record['pair_position']) && is_numeric($record['pair_position'])
                ? (int)$record['pair_position'] : -1;
            $modes[$requestId] = array(
                'mode' => $mode,
                'part' => $part,
                'payload_key' => $payloadKey,
                'ordinal' => $ordinal,
                'pair_ordinal' => $pairOrdinal,
                'pair_position' => $pairPosition,
            );
        }
        return $modes;
    }

    /**
     * One record field as a string, or '' when it is absent or not scalar.
     *
     * @param array<array-key, mixed> $record
     */
    private static function scalarField(array $record, string $field): string {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (string)$value : '';
    }
}
