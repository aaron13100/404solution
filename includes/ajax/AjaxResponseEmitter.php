<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/services/PostResponseWorkerBudget.php';

/**
 * How a JSON AJAX response actually leaves the server: header + ledger
 * stamping, the measured json_encode + echo boundary, connection-detach
 * (fastcgi_finish_request / litespeed_finish_request,
 * including the Bruno timeout cause matrix gap G9 detach A/B diagnostic),
 * and exit. Split out of ABJ_404_Solution_AjaxAdminEndpointSupport (which
 * owns the surrounding request lifecycle: auth gate, error envelope,
 * debug-context, failure-logging delegation, service resolution) because
 * response emission is its own cohesive responsibility with its own heavy
 * external caller list: every per-endpoint handler in
 * includes/ajax/Ajax_*.php calls sendJsonResponseAndExit() directly.
 *
 * Every micro-step in this path is bracketed with a start/end checkpoint
 * pair, not just a post-hoc record: gap-hunt iteration 2 (Codex gaps #4 and
 * #5, 2026-07-22) found that json_encode() ran raw. Header emission and the
 * status_header()/http_response_code() call were not measured at all. Those
 * operations are now around()-bracketed like echo already was.
 */
final class ABJ_404_Solution_AjaxResponseEmitter {

    /**
     * Maximum recursion depth payloadShapeFields() will walk into.
     * Bounded so this diagnostic itself cannot become the next unmeasured
     * hang on a pathological (deeply nested or huge) payload -- exactly the
     * failure mode this instrumentation exists to catch.
     */
    private const PAYLOAD_SHAPE_MAX_DEPTH = 32;

    /** Maximum number of array/object elements payloadShapeFields() will visit. */
    private const PAYLOAD_SHAPE_MAX_ELEMENTS = 5000;

    /**
     * @param mixed $payload
     * @param int $httpStatus
     * @return void
     */
    public static function sendJsonResponseAndExit($payload, $httpStatus = 200) {
        $checkpointRequestId = ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext();
        $ledgerRequestId = ABJ_404_Solution_AjaxRequestLedger::requestIdFromGlobalContext();
        $payload = ABJ_404_Solution_AjaxRequestLedger::stampOnPayload($payload, $ledgerRequestId);
        // Close the per-query attribution timeline here rather than in the
        // stage runner: this is the one choke point every exit passes through,
        // including the rate-limit 429 and auth-failure 403 branches that
        // return before any stage opens. All database work for the request is
        // finished by now; encoding and echoing do none.
        ABJ_404_Solution_AjaxQueryTimeline::flushSummary($checkpointRequestId);
        // The request's own census row now says it reached the response tail.
        // Marked BEFORE encoding, so a worker that strands anywhere from here
        // on leaves a row naming this segment rather than the handler it had
        // already finished. See ABJ_404_Solution_SameSiteRequestCensus::markPhase().
        ABJ_404_Solution_SameSiteRequestCensus::markPhase(
            ABJ_404_Solution_SameSiteRequestCensus::PHASE_RESPONSE_ENCODE);
        if (!headers_sent()) {
            self::checkpointedEmitHeaders($checkpointRequestId, $ledgerRequestId, $httpStatus);
        }
        self::checkpointedEncodeAndEcho($payload, $checkpointRequestId);

        // Test hook: tests register `abj404_should_exit` returning false to skip exit.
        // This filter runs foreign WordPress callbacks (named + `all`) after the
        // echo boundary and before the first flush checkpoint. On the instrumented
        // table endpoint the dispatch is bracketed and every callback attributed;
        // off it, traceDispatch() is a byte-identical pass-through.
        $shouldExit = ABJ_404_Solution_ResponseControlFilterTracer::traceDispatch(
            'abj404_should_exit',
            static function () {
                return apply_filters('abj404_should_exit', true, array('source' => 'viewUpdater_emitJson'));
            }
        );
        if (!$shouldExit) {
            return;
        }

        self::checkpointedFlushAndFinish($checkpointRequestId);

        exit;
    }

    /**
     * Response headers as two measured boundaries (gap-hunt iteration 2,
     * Codex gap #5): the X-ABJ404 and Content-type header() calls, then
     * separately the status_header()/http_response_code() call. Neither was
     * measured before this fix, so a blocking header filter (e.g. an
     * optimizer plugin hooked on `status_header`) left `trace_finish_end`
     * followed by nothing, indistinguishable from a worker kill.
     * $checkpointRequestId === '' means this response is outside the Bruno
     * table-AJAX endpoint; skip the instrumentation but keep behavior
     * identical.
     *
     * @param int $httpStatus
     */
    private static function checkpointedEmitHeaders(string $checkpointRequestId, string $ledgerRequestId, $httpStatus): void {
        $ctx = isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])
            ? $GLOBALS['abj404_ajax_context'] : array();
        $emitHeaders = static function () use ($ctx, $ledgerRequestId) {
            if ($ctx !== array()) {
                if (array_key_exists('action', $ctx) && is_string($ctx['action'])) {
                    header('X-ABJ404-Ajax: ' . preg_replace('/[\r\n]+/', '', $ctx['action']));
                }
                if (array_key_exists('subpage', $ctx) && is_string($ctx['subpage']) && $ctx['subpage'] !== '') {
                    header('X-ABJ404-Subpage: ' . preg_replace('/[\r\n]+/', '', $ctx['subpage']));
                }
                // Immutable request ledger (matrix coverage req. 1): echo the
                // request ID back as a response header so it is recoverable
                // from the client/proxy side even when the JSON body itself
                // never arrives. Normalized to the ledger format, so no
                // header-splitting scrub is needed and no raw client value
                // is ever reflected.
                if ($ledgerRequestId !== '') {
                    header('X-ABJ404-Request-ID: ' . $ledgerRequestId);
                }
            }
            header('Content-type: application/json; charset=UTF-8');
        };
        if ($checkpointRequestId === '') {
            $emitHeaders();
        } else {
            ABJ_404_Solution_AjaxCheckpointLogger::around($checkpointRequestId, 'headers', $emitHeaders);
        }

        $emitStatus = static function () use ($httpStatus) {
            if (function_exists('status_header')) {
                // WordPress dispatches the foreign `status_header` filter and
                // global `all` hook before its core header() call. Attribute
                // those callbacks inside the existing outer status boundary:
                // completed callbacks followed by a missing status_header_end
                // then isolate the remaining stall to WordPress/core emission.
                ABJ_404_Solution_ResponseControlFilterTracer::traceDispatch(
                    'status_header',
                    static function () use ($httpStatus) {
                        status_header($httpStatus);
                    }
                );
            } else if (function_exists('http_response_code')) {
                http_response_code($httpStatus);
            }
        };
        if ($checkpointRequestId === '') {
            $emitStatus();
        } else {
            ABJ_404_Solution_AjaxCheckpointLogger::around(
                $checkpointRequestId, 'status_header', $emitStatus, array('http_status' => $httpStatus));
        }
    }

    /**
     * json_encode + echo as measured boundaries (matrix coverage req. 2,
     * gap-hunt iteration 2 Codex gap #4): the encode call itself is now
     * bracketed with json_encode_start/_end (payload shape on start, elapsed
     * on end) so a hang or fatal INSIDE json_encode() on a pathological
     * payload is attributable instead of vanishing into the preceding
     * uninstrumented gap. The post-hoc 'json_encode' record (bytes, content
     * hash, json_last_error()) is unchanged -- it still needs the encoded
     * result, which only exists after the bracketed call returns.
     * $checkpointRequestId === '' means this response is outside the Bruno
     * table-AJAX endpoint; skip the instrumentation but keep behavior
     * identical.
     *
     * @param mixed $payload
     */
    private static function checkpointedEncodeAndEcho($payload, string $checkpointRequestId): void {
        if ($checkpointRequestId === '') {
            echo json_encode($payload);
            return;
        }
        $json = null;
        ABJ_404_Solution_AjaxCheckpointLogger::around(
            $checkpointRequestId,
            'json_encode',
            static function () use ($payload, &$json) {
                $json = json_encode($payload);
            },
            self::payloadShapeFields($payload)
        );
        ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'json_encode', array(
            'bytes' => is_string($json) ? strlen($json) : 0,
            'hash' => is_string($json) ? md5($json) : null,
            'json_last_error' => json_last_error(),
            'json_last_error_msg' => json_last_error() === JSON_ERROR_NONE ? '' : json_last_error_msg(),
        ));
        ABJ_404_Solution_AjaxCheckpointLogger::around(
            $checkpointRequestId,
            'echo',
            static function () use ($json) {
                echo $json;
            },
            array('bytes' => is_string($json) ? strlen($json) : 0)
        );
    }

    /**
     * A best-effort structural fingerprint of the payload BEFORE
     * json_encode() runs: max nesting depth, element count, and total string
     * bytes. Recorded on json_encode_start so a stall or fatal inside the
     * encode call is attributable to a payload shape instead of an absence.
     *
     * @param mixed $payload
     * @return array{depth: int, element_count: int, string_byte_total: int, truncated: bool}
     */
    private static function payloadShapeFields($payload): array {
        $stats = array('depth' => 0, 'element_count' => 0, 'string_byte_total' => 0, 'truncated' => false);
        self::walkPayloadShape($payload, 0, $stats);
        return $stats;
    }

    /**
     * @param mixed $value
     * @param array{depth: int, element_count: int, string_byte_total: int, truncated: bool} $stats
     */
    private static function walkPayloadShape($value, int $currentDepth, array &$stats): void {
        if ($stats['truncated']) {
            return;
        }
        $stats['depth'] = max($stats['depth'], $currentDepth);
        if ($currentDepth >= self::PAYLOAD_SHAPE_MAX_DEPTH) {
            $stats['truncated'] = true;
            return;
        }
        if (is_string($value)) {
            $stats['string_byte_total'] += strlen($value);
            return;
        }
        $children = null;
        if (is_array($value)) {
            $children = $value;
        } else if (is_object($value)) {
            $children = get_object_vars($value);
        }
        if ($children === null) {
            return;
        }
        foreach ($children as $child) {
            $stats['element_count']++;
            if ($stats['element_count'] >= self::PAYLOAD_SHAPE_MAX_ELEMENTS) {
                $stats['truncated'] = true;
                return;
            }
            self::walkPayloadShape($child, $currentDepth + 1, $stats);
        }
    }

    /**
     * The connection-detach / exit tail (matrix coverage req. 2): which
     * finish-request function exists, which one was selected and what it
     * returned, and a final exit sentinel immediately before the caller calls
     * exit. Kept as
     * its own method (rather than inlined before `exit;`) so it is a real,
     * directly callable unit: the literal `exit;` a few lines below it in
     * sendJsonResponseAndExit() can never run inside a PHPUnit process, but
     * this method's own logic can be exercised and asserted on directly.
     */
    private static function checkpointedFlushAndFinish(string $checkpointRequestId): void {
        // Detach the response before shutdown work runs. fastcgi_finish_request()
        // is FPM-only: php-src deliberately disabled the alias under the
        // litespeed SAPI (commit ccf051c3), so on a LiteSpeed/LSAPI host the
        // FPM-only guard is a silent no-op and the HTTP connection stays open
        // through WP's 'shutdown' action, this plugin's log-queue flush and
        // lock reclaim, and every other plugin's shutdown callbacks. LSAPI's
        // equivalent is litespeed_finish_request(). Preference order matches
        // Symfony HttpFoundation Response::send() (symfony/symfony#42293):
        // fastcgi, then litespeed, then neither. Which one was selected, and
        // what it returned, is journaled either way -- including the 'none'
        // case, so "did not detach" is positive evidence rather than a gap.
        // Both supported SAPI functions flush every response buffer themselves.
        // Empirical probes against native PHP-FPM and LiteSpeed 6.3.6 confirmed
        // that they deliver the complete body and reduce a positive stack level
        // to zero. Pre-draining here was therefore redundant and violated
        // ownership by tearing down WordPress, PHP, and other-plugin buffers.
        // SAPIs without either function flush normally when the immediate exit
        // after this method terminates the request.
        ABJ_404_Solution_SameSiteRequestCensus::markPhase(
            ABJ_404_Solution_SameSiteRequestCensus::PHASE_DETACH);
        $hasFastcgiFinish = function_exists('fastcgi_finish_request');
        $hasLitespeedFinish = function_exists('litespeed_finish_request');
        $finishFunction = 'none';
        if ($hasFastcgiFinish) {
            $finishFunction = 'fastcgi_finish_request';
        } else if ($hasLitespeedFinish) {
            $finishFunction = 'litespeed_finish_request';
        }

        // Bruno timeout cause matrix, gap G9 (c434): within a bounded,
        // opt-in-twice diagnostic session (AjaxRequestLedger::resolveDetachAbMode()),
        // counterbalance whether the detach below actually runs within
        // matched workload pairs, so a beta.2
        // SUCCESS can be attributed to the detach fix rather than merely
        // correlated with it. Scoped to $checkpointRequestId !== '' -- the
        // same INSTRUMENTED_ACTION gate every other checkpoint here already
        // uses -- so the canary ladder's own requests never reach this branch
        // and its interpretation matrix can never be confounded
        // by it. The 'inert'/'default' cases behave exactly like before this
        // feature existed; only 'off' changes anything.
        $abDetachSkipped = self::resolveAndRecordDetachAbSkip($checkpointRequestId);

        // Recorded IMMEDIATELY before the call: if the detach itself stalls or
        // the worker is killed inside it, the journal still names what was
        // about to run and proves whether output buffers remained open.
        // ab_detach_skipped distinguishes a deliberate skip from the 'none'
        // case (no detach function available at all): both leave 'result'
        // null in the next record, and only this flag tells them apart.
        $obLevelAtCall = ABJ_404_Solution_OutputBufferDrain::currentLevel();
        if ($checkpointRequestId !== '') {
            ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'finish_request', array(
                'fastcgi_finish_request_exists' => $hasFastcgiFinish,
                'litespeed_finish_request_exists' => $hasLitespeedFinish,
                'selected' => $finishFunction,
                'sapi' => PHP_SAPI,
                'ab_detach_skipped' => $abDetachSkipped,
                'ob_level_at_call' => $obLevelAtCall,
            ));
        }
        $result = null;
        if (!$abDetachSkipped) {
            if ($finishFunction === 'fastcgi_finish_request') {
                $result = fastcgi_finish_request();
            } else if ($finishFunction === 'litespeed_finish_request') {
                $result = litespeed_finish_request();
            }
        }
        $obLevelAfterCall = ABJ_404_Solution_OutputBufferDrain::currentLevel();
        if ($checkpointRequestId !== '') {
            // This MUST be the first operation after the SAPI call. Moving the
            // record behind worker-budget setup made a stall in that setup
            // indistinguishable from a finish_request() call that never
            // returned. The record envelope's `ts` is therefore the durable
            // post-call timestamp the support payload can compare with the
            // pre-call finish_request record.
            ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'finish_request_result', array(
                'function' => $abDetachSkipped ? 'skipped_by_ab_diagnostic' : $finishFunction,
                'result' => $result,
                // Repeated because journal rotation can evict the pre-call
                // record while leaving this result in the support excerpt.
                'ob_level_at_call' => $obLevelAtCall,
                'ob_level_after_call' => $obLevelAfterCall,
            ));
        }
        $detached = !$abDetachSkipped && $finishFunction !== 'none' && $result !== false;
        if ($checkpointRequestId !== '' && $detached) {
            // Connection detach does not release the LSAPI/FPM worker. Bound
            // everything after this point, including foreign shutdown code.
            // PostResponseWorkerBudget writes its own armed/unavailable event,
            // so its outcome stays observable without delaying the detach
            // result boundary above.
            ABJ_404_Solution_PostResponseWorkerBudget::arm($checkpointRequestId);
        }
        if ($checkpointRequestId !== '') {
            ABJ_404_Solution_AjaxStageDiagnostics::recordRequestPhase(
                $checkpointRequestId,
                'response_emission',
                'complete'
            );
            ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'exit_sentinel');
        }
    }

    /**
     * The detach A/B decision for one request (Bruno timeout cause matrix,
     * gap G9 / c434), split out of checkpointedFlushAndFinish() purely to
     * keep that method's cyclomatic complexity within the project's ceiling
     * -- the branching here is one self-contained decision (resolve the
     * mode, journal it, report whether to skip), not logic that needs to be
     * inlined at the call site.
     *
     * @return bool True when the caller must skip the detach call entirely.
     */
    private static function resolveAndRecordDetachAbSkip(string $checkpointRequestId): bool {
        if ($checkpointRequestId === '') {
            return false;
        }
        $sessionId = '';
        $part = 'all';
        $payloadKey = '';
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $rawSessionId = $GLOBALS['abj404_ajax_context']['session_id'] ?? '';
            $sessionId = is_scalar($rawSessionId) ? (string)$rawSessionId : '';
            $rawPart = $GLOBALS['abj404_ajax_context']['part'] ?? 'all';
            $part = is_scalar($rawPart) ? (string)$rawPart : 'all';
            $rawPayloadKey = $GLOBALS['abj404_ajax_context']['detach_ab_payload_key'] ?? '';
            $payloadKey = is_scalar($rawPayloadKey) ? (string)$rawPayloadKey : '';
        }
        $abDetachMode = ABJ_404_Solution_AjaxRequestLedger::resolveDetachAbMode(
            $sessionId, $part, $payloadKey);
        ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'detach_ab_mode', $abDetachMode);
        return $abDetachMode['mode'] === 'off';
    }
}
