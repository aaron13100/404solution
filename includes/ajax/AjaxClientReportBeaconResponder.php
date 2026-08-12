<?php
// allow-no-test-found: covered through Ajax_GetPaginationLinks by tests/ClientTransportBeaconTest.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The table endpoint's report-only branch: recognise a client telemetry
 * beacon, journal which attempt it names, and answer it without doing any
 * table work (Bruno timeout cause matrix, coverage req. 6).
 *
 * A beacon is fired by the browser after the last attempt of a failed table
 * request, so by definition it arrives on a site that is already struggling.
 * Running the real query path for it would make a diagnostic channel into a
 * load source, which is why this branch terminates the request itself.
 *
 * Lives beside the endpoint handlers rather than with
 * ABJ_404_Solution_ClientTransportReport, which owns reading and journaling
 * the report itself. Emitting an HTTP response is presentation work: the
 * recorder that observes a failed request must not be reaching for the
 * response emitter, or a defect in the endpoint surface can take the evidence
 * channel down with it. The split is enforced by the Diagnostics layer in
 * deptrac.yaml, which is forbidden from depending on Presentation.
 */
final class ABJ_404_Solution_AjaxClientReportBeaconResponder {

    /**
     * Journal the beacon and answer it. Returns true when this request was
     * report-only and a response has already been sent, so the caller must
     * stop.
     *
     * Called only after the same nonce and capability gate every other table
     * request passes, so this is not a new unauthenticated write surface.
     */
    public static function respondIfReportOnly(string $requestId): bool {
        $reader = ABJ_404_Solution_AjaxAdminEndpointSupport::getRequestReader();
        if ((string)$reader->getPostOrGetSanitize('clientReportOnly', '0') !== '1') {
            return false;
        }
        // Which attempt this carrier exists to talk about. Recorded on the
        // branch record rather than merged into the report, because it is a
        // fact about the ENVELOPE: the report is the browser's own bounded,
        // trimmable payload, and this has to survive a payload that arrives
        // truncated or unreadable. A beacon fires only after the final attempt
        // of a request failed, so naming an attempt here is itself a verdict --
        // see ABJ_404_Solution_DiagnosticClientVerdict::condemnedRequestId().
        $reportedAttemptId = self::reportedAttemptId($reader);
        $rawThresholdMs = $reader->getPostOrGetSanitize('clientThresholdMs', '0');
        $thresholdMs = is_scalar($rawThresholdMs) && is_numeric($rawThresholdMs)
            ? (int)$rawThresholdMs
            : 0;
        ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, 'client_report_only_branch', array(
            'reported_attempt_id' => $reportedAttemptId,
            'client_threshold_ms' => $thresholdMs === 20000 ? $thresholdMs : 0,
        ));
        if ($thresholdMs === 20000) {
            self::recordThresholdCrossing($reportedAttemptId, $thresholdMs);
        }
        ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('complete');
        ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
        ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
        ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit(
            array(
                'clientReportReceived' => true,
                'clientThresholdRecorded' => $thresholdMs === 20000,
                'requestId' => $requestId,
            ),
            200
        );
        return true;
    }

    private static function recordThresholdCrossing(string $reportedAttemptId, int $thresholdMs): void {
        if ($reportedAttemptId === '') {
            return;
        }
        $active = ABJ_404_Solution_ActiveOperationBreadcrumbs::activeForRequest(
            ABJ_404_Solution_AjaxCheckpointLogger::resolveDirectoryPath(),
            $reportedAttemptId
        );
        $operation = $active === array() ? array() : $active[count($active) - 1];
        $fields = array(
            'threshold_ms' => $thresholdMs,
            'active_operation_status' => $operation === array() ? 'unavailable' : 'available',
        );
        foreach (array(
            'boundary' => 'active_boundary',
            'operation_id' => 'active_operation_id',
            'operation' => 'active_operation',
            'phase' => 'active_phase',
            'hook' => 'active_hook',
            'callback' => 'active_callback',
            'source' => 'active_source',
            'priority' => 'active_priority',
            'callback_ordinal' => 'active_callback_ordinal',
        ) as $source => $destination) {
            if (array_key_exists($source, $operation)) {
                $fields[$destination] = $operation[$source];
            }
        }
        ABJ_404_Solution_AjaxCheckpointLogger::record(
            $reportedAttemptId,
            'client_budget_threshold_crossed',
            $fields
        );
    }

    /**
     * The attempt a beacon is reporting on, or '' when it named none.
     *
     * Normalized through the ledger for the same reason every other id is: an
     * attempt whose own id the ledger refuses was journaled under the ledger's
     * unknown-id sentinel, so that sentinel IS the group the beacon points at,
     * and a raw value compared against an already-normalized journal key could
     * only ever miss. An absent parameter stays '' rather than becoming the
     * sentinel -- "this client named no attempt" (an older client, still
     * riding the attempt's own id) and "this client named one we cannot use"
     * are different findings about the client.
     *
     * @param ABJ_404_Solution_RequestInputNormalizer $reader Docblock-typed only:
     *   tests substitute request-reader doubles that are not literally that class.
     */
    private static function reportedAttemptId($reader): string {
        $raw = $reader->getPostOrGetSanitize('reportedAttemptId', '');
        if (!is_scalar($raw) || (string)$raw === '') {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::normalizeId($raw);
    }
}
