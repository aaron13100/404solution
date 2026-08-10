<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks which phase an AJAX request is in for timeout diagnostics.
 *
 * The current stage/query-label/what-happening trio is threaded through
 * $context and mirrored onto $GLOBALS['abj404_ajax_context'] so that if the
 * request ends in an error response, ajaxUpdatePaginationLinks can attach
 * it under data.details.context for the admin-facing failure notice
 * (see view_updater_pagination_error_notice.js).
 */
class ABJ_404_Solution_AjaxStageDiagnostics {

    /**
     * Start the durable request trace after authorization/rate limiting.
     *
     * Runs the per-request trace self-test sentinel first (matrix coverage
     * req. 5), independent of whether trace construction itself succeeds --
     * it journals through ABJ_404_Solution_AjaxCheckpointLogger, not through
     * the trace class it is proving. Trace construction itself is wrapped in
     * a checkpoint pair (matrix coverage req. 2) so a stall inside
     * ABJ_404_Solution_AjaxRequestTrace::start() is directly measurable even
     * though the trace it would have produced does not exist yet.
     *
     * @param array<string, mixed> $context
     * @return void
     */
    public static function beginRequest(array $context): void {
        $requestId = ABJ_404_Solution_AjaxRequestLedger::diagnosticRequestId($context);
        if ($requestId === '') {
            unset($GLOBALS['abj404_ajax_request_trace']);
            return;
        }
        self::recordRequestPhase($requestId, 'request_handler');

        ABJ_404_Solution_DiagnosticDirectoryProbe::run($requestId);

        // The browser's account of the PREVIOUS attempt rides this request's
        // params (matrix coverage req. 6). Journaled here, at the same point
        // the server's own trace opens, so the client and server views of a
        // failure land in one file joined by request id even when the admin
        // never sends a support request.
        ABJ_404_Solution_ClientTransportReport::journal($requestId);

        $traceContext = array(
            'request_id' => $requestId,
            'action' => $context['action'] ?? '',
            'subpage' => $context['subpage'] ?? '',
            'part' => $context['part'] ?? 'all',
            'retry_count' => $context['retry_count'] ?? 0,
            'client_sent_at' => $context['client_sent_at'] ?? '',
            'handler_class' => $context['handler_class'] ?? '',
            'session_id' => $context['session_id'] ?? '',
            'retry_parent_id' => $context['retry_parent_id'] ?? '',
            'header_request_id' => $context['header_request_id'] ?? '',
            'cf_ray' => $context['cf_ray'] ?? '',
        );
        $GLOBALS['abj404_ajax_request_trace'] = ABJ_404_Solution_AjaxCheckpointLogger::around(
            $requestId,
            'trace_construct',
            static function () use ($traceContext) {
                return ABJ_404_Solution_AjaxRequestTrace::start($traceContext);
            }
        );
    }

    /**
     * Run one endpoint stage with a flushed start record and a matching end.
     *
     * @template T
     * @param array<string, mixed> $context
     * @param string $stage
     * @param callable():T $work
     * @return T
     */
    public static function runStage(array &$context, string $stage, callable $work) {
        self::setStage($context, $stage);
        $trace = self::activeTrace();
        if ($trace !== null) {
            $trace->beginStage($stage);
        }
        $completed = false;
        try {
            $result = $work();
            $completed = true;
            return $result;
        } finally {
            if ($trace !== null) {
                $trace->endStage($completed ? 'complete' : 'error');
            }
        }
    }

    /** @param array<string, scalar> $metadata @return void */
    public static function addStageMetadata(array $metadata): void {
        $trace = self::activeTrace();
        if ($trace !== null) {
            $trace->addStageMetadata($metadata);
        }
    }

    /** Finish and detach the current request trace, wrapped in a trace_finish checkpoint pair. */
    public static function finishRequest(string $status = 'complete'): void {
        $requestId = self::currentRequestIdForCheckpoints();
        self::recordRequestPhase($requestId, 'trace_finish');
        $trace = self::activeTrace();
        if ($trace !== null) {
            ABJ_404_Solution_AjaxCheckpointLogger::around(
                $requestId,
                'trace_finish',
                static function () use ($trace, $status) {
                    $trace->finish($status);
                }
            );
        }
        self::recordRequestPhase($requestId, 'response_emission');
        unset($GLOBALS['abj404_ajax_request_trace']);
    }

    /** Persist the high-level operation that owns the current request window. */
    public static function recordRequestPhase(
        string $requestId,
        string $phase,
        string $state = 'active'
    ): void {
        if ($requestId === '') {
            return;
        }
        ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
            $requestId,
            'request_phase',
            $state,
            array(
                'operation_id' => substr(hash('sha256', $requestId . '|request_phase'), 0, 12),
                'operation' => 'ajax_request',
                'phase' => substr($phase, 0, 64),
                'threshold_ms' => 20000,
            )
        );
    }

    private static function activeTrace(): ?ABJ_404_Solution_AjaxRequestTrace {
        $trace = $GLOBALS['abj404_ajax_request_trace'] ?? null;
        return $trace instanceof ABJ_404_Solution_AjaxRequestTrace ? $trace : null;
    }

    /**
     * Best-effort request ID for checkpoint correlation, read from the
     * shared AJAX debug context global. Used by call sites (finishRequest)
     * that do not otherwise have the raw request context in hand.
     */
    private static function currentRequestIdForCheckpoints(): string {
        return ABJ_404_Solution_AjaxRequestLedger::diagnosticRequestIdFromGlobalContext();
    }

    /**
     * Update the in-flight stage marker for the current AJAX request: sets
     * `$context['stage']` (plus query_label/what_happening) and mirrors it
     * onto $GLOBALS['abj404_ajax_context'] so an error response emitted
     * later in the same request can attach the last-known stage.
     *
     * @param array<string, mixed> $context  Passed by reference; mutated in place.
     * @param string $stage  Stage label (e.g. 'table_captured', 'paginationLinks').
     * @return void
     */
    public static function setStage(&$context, $stage) {
        if (!is_array($context)) {
            $context = array();
        }
        $diagnostics = self::getStageDiagnostics($stage);
        $context['stage'] = $stage;
        $context['query_label'] = $diagnostics['query_label'];
        $context['what_happening'] = $diagnostics['what_happening'];
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $GLOBALS['abj404_ajax_context']['stage'] = $stage;
            $GLOBALS['abj404_ajax_context']['query_label'] = $diagnostics['query_label'];
            $GLOBALS['abj404_ajax_context']['what_happening'] = $diagnostics['what_happening'];
        }
    }

    /**
     * @param string $stage
     * @return array{query_label: string, what_happening: string}
     */
    public static function getStageDiagnostics($stage) {
        $map = array(
            'table_redirects' => array(
                'query_label' => 'getAdminRedirectsPageTable() -> read redirects rows from wp_abj404_redirects (single-table live read)', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Loading Redirects table rows',
            ),
            'redirect_status_counts' => array(
                'query_label' => 'getRedirectStatusCounts()',
                'what_happening' => 'Counting Redirects status tabs',
            ),
            'table_captured' => array(
                'query_label' => 'getCapturedURLSPageTable() -> read captured rows from wp_abj404_redirects (single-table live read)', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Loading Captured 404 URLs table rows',
            ),
            'captured_status_counts' => array(
                'query_label' => 'getCapturedStatusCounts()',
                'what_happening' => 'Counting Captured 404 URLs status tabs',
            ),
            'table_logs' => array(
                'query_label' => 'getAdminLogsPageTable() -> getLogRecords()',
                'what_happening' => 'Loading Logs table rows',
            ),
            // One stage, not two: the strip is rendered once and used for
            // both the top and bottom slots. The retired paginationLinksTop /
            // paginationLinksBottom codes are deliberately absent rather than
            // kept as decoders -- a label naming work the plugin no longer
            // does is what sent the i900 diagnosis after a removed
            // architecture for months.
            'paginationLinks' => array(
                'query_label' => 'getPaginationLinks() -> read pagination count from wp_abj404_redirects (single-table live read)', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Rendering pagination links',
            ),
            'table_cache_rows' => array(
                'query_label' => 'getRedirectsForView',
                'what_happening' => 'Warming table row snapshot',
            ),
            'table_cache_count' => array(
                'query_label' => 'getRedirectsForViewCount',
                'what_happening' => 'Warming table count snapshot',
            ),
            'high_impact_count' => array(
                'query_label' => 'getHighImpactCapturedCount()',
                'what_happening' => 'Counting high-impact captured URLs',
            ),
        );
        if (array_key_exists($stage, $map)) {
            return $map[$stage];
        }
        $colonPos = is_string($stage) ? strpos((string)$stage, ':') : false;
        if ($colonPos !== false) {
            $base = substr((string)$stage, 0, $colonPos);
            $detail = trim(substr((string)$stage, $colonPos + 1));
            if (array_key_exists($base, $map)) {
                $entry = $map[$base];
                if ($detail !== '') {
                    $entry['what_happening'] = $entry['what_happening'] . ' — ' . $detail; // allow-em-dash: stage label separator in user-visible AJAX diagnostics
                }
                return $entry;
            }
        }
        return array(
            'query_label' => (string)$stage,
            'what_happening' => 'Running AJAX stage ' . (string)$stage,
        );
    }
}
