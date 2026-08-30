<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reconstructs the adaptive-canary interpretation from durable browser receipts.
 *
 * Beta.3 posted the complete nested observation graph to a bounded server field.
 * That final request could be truncated into invalid JSON, but every measured
 * step had already relayed a normalized receipt through the checkpoint journal.
 * This class recovers the matrix from those receipts without trusting missing
 * evidence as a failed probe.
 *
 * A receipt names its own browser session -- as `session_key`, the md5 this
 * journal already files `detach_ab_mode` under -- so the checkpoint journal
 * answers the scoping question on its own. It did not always: the session used to live
 * only in the stage-trace journal, and this class resolved it across the two
 * channels. That join fails whenever the trace channel is silent for ANY
 * reason, and on the 2026-08-27 Azure App Service capture it was silent because
 * its writer had never been armed -- so a complete fifteen-receipt run in the
 * checkpoint journal reconstructed as `no_receipts`, with no verdict in the
 * payload and nothing in the report to say why.
 *
 * The trace journal is still read, for two things it alone owns: the plugin
 * version that produced the run, and the carrier-request join that scopes
 * receipts written by a build older than the session stamp. A record that
 * carries a session is scoped by it and never by the carrier, so a stamped
 * foreign run cannot be adopted by a carrier that happens to be in range.
 *
 * The last static-asset receipt starts the latest ladder run, preventing two
 * runs in one tab from being folded into one matrix.
 */
final class ABJ_404_Solution_CanaryReceiptEvidence {

    const STATUS_RECONSTRUCTED = 'reconstructed';
    const STATUS_INCOMPLETE = 'incomplete';
    const STATUS_NO_SESSION = 'no_session';
    const STATUS_NO_RECEIPTS = 'no_receipts';
    const STATUS_ERROR = 'error';

    /** Beta.3 interleaves one fixed-size control after each measured step. */
    const REQUIRED_BASELINE_RECEIPTS = 8;

    /** Evidence required before absence can never be mistaken for failure. */
    const REQUIRED_STEPS = array(
        ABJ_404_Solution_AjaxCanaryLadder::STEP_STATIC_ASSET,
        ABJ_404_Solution_AjaxCanaryLadder::STEP_AUTH_ONLY,
        ABJ_404_Solution_AjaxCanaryLadder::STEP_POST_LIMITER,
        ABJ_404_Solution_AjaxCanaryLadder::STEP_SUMMARY,
        ABJ_404_Solution_AjaxCanaryLadder::STEP_INERT,
        ABJ_404_Solution_AjaxCanaryLadder::STEP_COMPRESS_ON,
        ABJ_404_Solution_AjaxCanaryLadder::STEP_COMPRESS_OFF,
        ABJ_404_Solution_AjaxCanaryLadder::STEP_STREAM,
    );

    /**
     * Reconstruct the latest complete canary run for one browser session.
     *
     * @return array<string, mixed>
     */
    public static function forSession(string $sessionId): array {
        $sessionId = substr($sessionId, 0, 64);
        $sessionKey = ABJ_404_Solution_DetachAbExperiment::sessionKey($sessionId);
        $record = self::emptyRecord($sessionId);
        if ($sessionId === '') {
            $record['status'] = self::STATUS_NO_SESSION;
            return $record;
        }
        try {
            $traceSource = ABJ_404_Solution_AjaxTraceJournal::supportCollectionSource();
            $checkpointSource = ABJ_404_Solution_CheckpointJournalReader::supportCollectionSource();
            $traceLines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines(
                $traceSource['paths']
            );
            $checkpointLines = ABJ_404_Solution_DiagnosticJournalExcerpt::readAllLines(
                $checkpointSource['paths']
            );
            $session = self::sessionRequests($traceLines, $sessionId);
            $record['plugin_version'] = $session['plugin_version'];
            $record['journal_lines_scanned'] = count($traceLines) + count($checkpointLines);
            return self::reconstruct(
                $record, $checkpointLines, $session['request_ids'], $sessionKey, $sessionId);
        } catch (Throwable $e) {
            $record['status'] = self::STATUS_ERROR;
            $record['error'] = substr($e->getMessage(), 0, 200);
            return $record;
        }
    }

    /** @return array<string, mixed> */
    private static function emptyRecord(string $sessionId): array {
        return array(
            'status' => self::STATUS_NO_RECEIPTS,
            'source' => 'checkpoint_receipts',
            'session_key' => ABJ_404_Solution_DetachAbExperiment::sessionKey($sessionId),
            'plugin_version' => '',
            'receipt_records' => 0,
            'baseline_receipts' => 0,
            'malformed_receipts' => 0,
            'missing_required_evidence' => array(),
            'journal_lines_scanned' => 0,
            'body_delivery' => null,
            'interpretation' => null,
        );
    }

    /**
     * @param array<int, string> $lines
     * @return array{request_ids: array<string, bool>, plugin_version: string}
     */
    private static function sessionRequests(array $lines, string $sessionId): array {
        $requestIds = array();
        $pluginVersion = '';
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)
                    || self::scalarField($decoded, 'session_id') !== $sessionId) {
                continue;
            }
            $requestId = self::ledgerId($decoded['request_id'] ?? null);
            if ($requestId !== '') {
                $requestIds[$requestId] = true;
            }
            $candidateVersion = self::scalarField($decoded, 'plugin_version');
            if ($candidateVersion !== '') {
                $pluginVersion = substr($candidateVersion, 0, 64);
            }
        }
        return array('request_ids' => $requestIds, 'plugin_version' => $pluginVersion);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, string> $lines
     * @param array<string, bool> $sessionRequestIds
     * @return array<string, mixed>
     */
    private static function reconstruct(
        array $record,
        array $lines,
        array $sessionRequestIds,
        string $sessionKey,
        string $sessionId
    ): array {
        $receipts = self::sessionReceiptRecords($lines, $sessionRequestIds, $sessionKey);
        if ($receipts === array()) {
            return $record;
        }
        $latestRun = self::latestRun($receipts);
        $steps = array();
        $controls = array();
        foreach ($latestRun as $receipt) {
            if (($receipt['event'] ?? '') === 'canary_step_client_receipt') {
                $steps[] = $receipt;
            } elseif (($receipt['event'] ?? '') === 'concurrent_control_client_receipt') {
                $controls[] = $receipt;
            }
        }
        $projection = self::projectSteps($steps);
        $concurrent = self::latestConcurrentControl($controls);
        $missing = self::missingEvidence($projection, $concurrent);

        $record['receipt_records'] = count($latestRun);
        $record['baseline_receipts'] = count($projection['baselines']);
        $record['malformed_receipts'] = $projection['malformed'];
        $record['missing_required_evidence'] = $missing;
        if ($missing !== array() || $concurrent === null) {
            $record['status'] = self::STATUS_INCOMPLETE;
            return $record;
        }

        $observations = $projection['observations'];
        $observations[ABJ_404_Solution_AjaxCanaryLadder::STEP_CONCURRENT_CONTROL] =
            self::projectConcurrentControl($concurrent);
        // The emitted-against-delivered join is rebuilt from the SAME
        // receipts this reconstruction already holds. Recomputing the matrix
        // without it would drop bodyRewrittenInTransitCausal from exactly the
        // payload a maintainer reads when the live interpret response never
        // arrived -- the case this whole path exists for.
        $bodyDelivery = ABJ_404_Solution_ResponseBodyDeliveryEvidence::fromLines(
            $lines,
            $sessionKey,
            ABJ_404_Solution_EncodedTableResponseSize::forSession($sessionId)
        );
        $record['status'] = self::STATUS_RECONSTRUCTED;
        $record['body_delivery'] = $bodyDelivery;
        $record['interpretation'] =
            ABJ_404_Solution_AjaxCanaryLadder::interpretResults($observations, true, $bodyDelivery);
        return $record;
    }

    /**
     * Every receipt belonging to this session, in journal order.
     *
     * A record that names a session is scoped BY that name and by nothing else:
     * it is kept when the name matches and dropped when it does not, so a
     * foreign run can never be adopted through a carrier that happens to be in
     * the trace-derived set. Only a record with no session of its own -- one
     * written before the stamp existed -- falls back to the carrier join, and
     * on a host whose trace journal is silent that set is empty, which drops it.
     * Unattributable evidence is dropped, never adopted.
     *
     * @param array<int, string> $lines
     * @param array<string, bool> $sessionRequestIds
     * @param string $sessionKey DetachAbExperiment::sessionKey() of the requested session.
     * @return array<int, array<string, mixed>>
     */
    private static function sessionReceiptRecords(
        array $lines,
        array $sessionRequestIds,
        string $sessionKey
    ): array {
        $receipts = array();
        foreach ($lines as $line) {
            if (strpos($line, 'canary_step_client_receipt') === false
                    && strpos($line, 'concurrent_control_client_receipt') === false) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['event'] ?? '') !== 'canary_step_client_receipt'
                    && ($decoded['event'] ?? '') !== 'concurrent_control_client_receipt') {
                continue;
            }
            $recordSessionKey = self::scalarField($decoded, 'session_key');
            $belongs = $recordSessionKey !== ''
                ? $recordSessionKey === $sessionKey
                : isset($sessionRequestIds[self::ledgerId($decoded['carried_by'] ?? null)]);
            if ($belongs) {
                $receipts[] = $decoded;
            }
        }
        return $receipts;
    }

    /**
     * @param array<int, array<string, mixed>> $receipts
     * @return array<int, array<string, mixed>>
     */
    private static function latestRun(array $receipts): array {
        $start = -1;
        foreach ($receipts as $index => $receipt) {
            $step = self::reportedStep($receipt);
            if ($step === ABJ_404_Solution_AjaxCanaryLadder::STEP_STATIC_ASSET) {
                $start = $index;
            }
        }
        return $start < 0 ? $receipts : array_slice($receipts, $start);
    }

    /**
     * @param array<int, array<string, mixed>> $receipts
     * @return array{observations: array<string, mixed>, baselines: array<int, array<string, mixed>>, malformed: int}
     */
    private static function projectSteps(array $receipts): array {
        $byStep = array();
        $baselines = array();
        $malformed = 0;
        $seen = array();
        foreach ($receipts as $receipt) {
            $step = self::reportedStep($receipt);
            $stepRequestId = self::ledgerId($receipt['step_request_id'] ?? null);
            $valid = ($receipt['envelope'] ?? '') === 'full'
                && ($receipt['decoded'] ?? null) === true
                && $step !== ''
                && ($step === ABJ_404_Solution_AjaxCanaryLadder::STEP_STATIC_ASSET
                    || $stepRequestId !== '')
                && empty($receipt['truncated_on_arrival']);
            if (!$valid) {
                $malformed++;
                if ($step !== '') {
                    $byStep[$step] = null;
                }
                continue;
            }
            $identity = $step === ABJ_404_Solution_AjaxCanaryLadder::STEP_STATIC_ASSET
                ? $step : $step . '|' . $stepRequestId;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $projected = array(
                'ok' => ($receipt['ok'] ?? null) === true,
                'ms' => is_numeric($receipt['ms'] ?? null) ? (int)$receipt['ms'] : -1,
            );
            if ($step === ABJ_404_Solution_AjaxCanaryLadder::STEP_BASELINE_CONTROL) {
                $baselines[] = $projected;
            } else {
                $byStep[$step] = $projected;
            }
        }
        $observations = $byStep;
        $observations[ABJ_404_Solution_AjaxCanaryLadder::STEP_BASELINE_CONTROL] = $baselines;
        return array(
            'observations' => $observations,
            'baselines' => $baselines,
            'malformed' => $malformed,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $controls
     * @return array<string, mixed>|null
     */
    private static function latestConcurrentControl(array $controls): ?array {
        $latest = null;
        foreach ($controls as $control) {
            $latest = $control;
        }
        return $latest;
    }

    /**
     * @param array{observations: array<string, mixed>, baselines: array<int, array<string, mixed>>, malformed: int} $projection
     * @param array<string, mixed>|null $concurrent
     * @return array<int, string>
     */
    private static function missingEvidence(array $projection, ?array $concurrent): array {
        $missing = array();
        foreach (self::REQUIRED_STEPS as $step) {
            if (!is_array($projection['observations'][$step] ?? null)) {
                $missing[] = $step;
            }
        }
        if (count($projection['baselines']) < self::REQUIRED_BASELINE_RECEIPTS) {
            $missing[] = ABJ_404_Solution_AjaxCanaryLadder::STEP_BASELINE_CONTROL;
        }
        if ($concurrent === null
                || !ABJ_404_Solution_ConcurrentControlReceipt::isCompleteJournalRecord(
                    $concurrent
                )) {
            $missing[] = ABJ_404_Solution_AjaxCanaryLadder::STEP_CONCURRENT_CONTROL;
        }
        if ($projection['malformed'] > 0 && $missing === array()) {
            $missing[] = 'malformed_receipt';
        }
        return $missing;
    }

    /**
     * @param array<string, mixed> $record
     * @return array{
     *   tableOutcome: string,
     *   receipt: array{ok: bool},
     *   overlap: array{state: string, durationMs: int|null}
     * }
     */
    private static function projectConcurrentControl(array $record): array {
        $report = is_array($record['report'] ?? null) ? $record['report'] : array();
        $receipt = is_array($report['receipt'] ?? null) ? $report['receipt'] : array();
        $overlap = is_array($report['overlap'] ?? null) ? $report['overlap'] : array();
        return array(
            'tableOutcome' => self::scalarField($report, 'tableOutcome'),
            'receipt' => array('ok' => ($receipt['ok'] ?? null) === true),
            'overlap' => array(
                'state' => self::scalarField($overlap, 'state'),
                'durationMs' => is_numeric($overlap['durationMs'] ?? null)
                    ? (int)$overlap['durationMs'] : null,
            ),
        );
    }

    /** @param array<string, mixed> $record */
    private static function reportedStep(array $record): string {
        $step = self::scalarField($record, 'step');
        return $step !== '' ? $step : self::scalarField($record, 'reported_step');
    }

    /** @param mixed $value */
    private static function ledgerId($value): string {
        $candidate = is_scalar($value) ? (string)$value : '';
        return preg_match('/^[A-Za-z0-9]{8,64}$/', $candidate) === 1 ? $candidate : '';
    }

    /** @param array<array-key, mixed> $record */
    private static function scalarField(array $record, string $field): string {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (string)$value : '';
    }
}
