<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The browser's half of the request ledger (Bruno timeout cause matrix,
 * coverage req. 6).
 *
 * The server flight recorder can prove what PHP did with a request. It cannot
 * prove that the request ever left the browser, how long it sat in the browser
 * connection queue, whether response headers arrived and the body then stalled,
 * or whether the completion callback lost a race to jQuery's timeout timer.
 * Only the browser can answer those, and only if what it observed gets back
 * here. This class is where it lands.
 *
 * Two arrival routes, both authenticated exactly like a normal table request:
 *
 *   1. Riding the next table request's params (the primary route: the client
 *      attaches its previous attempt's record to the following request, so the
 *      evidence arrives even if the admin never sends a support request).
 *   2. A clientReportOnly beacon fired after the last attempt of a failed
 *      request, which does no table work and returns immediately.
 *
 * Reports are journaled through ABJ_404_Solution_AjaxCheckpointLogger rather
 * than the trace class, for the same reason the server checkpoints are: a
 * defect in the component under investigation must not be able to erase the
 * evidence about it. The payload is treated as untrusted text throughout: it
 * is length-bounded, parsed defensively, and never echoed back to any client.
 */
final class ABJ_404_Solution_ClientTransportReport {

    /**
     * Hard bound on a single client report. The client trims its own record to
     * 4000 characters before sending; this is the server refusing to journal
     * more than that regardless of what actually arrives.
     */
    const MAX_REPORT_BYTES = 4096;

    /**
     * Read, bound, and journal whatever the browser said about a previous
     * attempt, plus the build identity of the JavaScript that said it. Never
     * throws: a malformed or absent report must not affect the request that
     * carried it.
     */
    public static function journal(string $requestId): void {
        try {
            $reader = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
            $build = (string)$reader->getPostOrGetSanitize('clientBuild', '');
            $inflight = (string)$reader->getPostOrGetSanitize('clientInflight', '');
            if ($build !== '' || $inflight !== '') {
                // What the client said about ITSELF at send time: which
                // JavaScript is executing, and how many other plugin requests
                // that tab already had open. The previous attempt's story is a
                // separate record below.
                ABJ_404_Solution_AjaxCheckpointLogger::record(
                    $requestId,
                    'client_send_state',
                    array_merge(
                        ABJ_404_Solution_ClientBuildFingerprint::compare($build),
                        array(
                            'inflight' => ctype_digit($inflight) ? (int)$inflight : null,
                            'inflight_ids' => substr(
                                (string)$reader->getPostOrGetSanitize('clientInflightIds', ''), 0, 256),
                        )
                    )
                );
            }
            $report = self::readReport($reader);
            if ($report === null) {
                return;
            }
            // Nested under one key, never spread across the record: the
            // envelope's own fields (request_id, event, ts, pid) are the join
            // keys the whole journal is read by, and a client that sent a
            // field with one of those names would otherwise overwrite them and
            // forge the identity of its own evidence.
            ABJ_404_Solution_AjaxCheckpointLogger::record(
                $requestId, 'client_prior_attempt', array('report' => $report));
        } catch (Throwable $e) {
            ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, 'client_report_error', array(
                'message' => substr($e->getMessage(), 0, 200),
            ));
        }
    }

    /**
     * The decoded client report, or null when none was sent. Returns a
     * diagnostic stand-in (never null) when a report was sent but could not be
     * decoded: "the client sent something unparseable" is itself a finding
     * about the transport and must not be silently dropped.
     *
     * @param ABJ_404_Solution_Functions $reader Docblock-typed only: tests
     *   substitute request-reader doubles that are not literally that class.
     * @return array<string, mixed>|null
     */
    private static function readReport($reader): ?array {
        $raw = $reader->getPostOrGetSanitize('clientReport', '');
        if (!is_scalar($raw) || (string)$raw === '') {
            return null;
        }
        $raw = (string)$raw;
        $truncated = strlen($raw) > self::MAX_REPORT_BYTES;
        $decoded = json_decode(substr($raw, 0, self::MAX_REPORT_BYTES), true);
        if (!is_array($decoded)) {
            return array(
                'decoded' => false,
                'json_error' => json_last_error_msg(),
                'raw_length' => strlen($raw),
                'raw_head' => substr($raw, 0, 200),
            );
        }
        // Rebuilt key by key rather than passed through: the decoded value is
        // whatever the browser sent, so its keys are only assumed to be
        // strings until they are made so here.
        $report = array();
        foreach ($decoded as $key => $value) {
            $report[(string)$key] = $value;
        }
        $report['decoded'] = true;
        $report['truncated_on_arrival'] = $truncated;
        return $report;
    }

    /**
     * A telemetry beacon carries no table request: journal it and answer at
     * once. Returns true when this request was report-only and a response has
     * already been sent, so the caller must stop.
     *
     * Called only after the same nonce and capability gate every other table
     * request passes, so this is not a new unauthenticated write surface.
     */
    public static function respondIfReportOnly(string $requestId): bool {
        $reader = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
        if ((string)$reader->getPostOrGetSanitize('clientReportOnly', '0') !== '1') {
            return false;
        }
        ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, 'client_report_only_branch');
        ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('complete');
        ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
        ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
        ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(
            array('clientReportReceived' => true, 'requestId' => $requestId), 200);
        return true;
    }
}
