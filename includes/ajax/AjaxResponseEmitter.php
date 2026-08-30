<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/services/PostResponseWorkerBudget.php';

/**
 * How a JSON AJAX response actually leaves the server: ledger stamping, the
 * measured json_encode + echo boundary, connection-detach
 * (fastcgi_finish_request / litespeed_finish_request,
 * including the Bruno timeout cause matrix gap G9 detach A/B diagnostic),
 * and exit. Split out of ABJ_404_Solution_AjaxAdminEndpointSupport (which
 * owns the surrounding request lifecycle: auth gate, error envelope,
 * debug-context, failure-logging delegation, service resolution) because
 * response emission is its own cohesive responsibility with its own heavy
 * external caller list: every per-endpoint handler in
 * includes/ajax/Ajax_*.php calls sendJsonResponseAndExit() directly.
 *
 * The response HEAD is not here. ABJ_404_Solution_JsonResponseHead owns the
 * headers and the status code, because they are emitted independently of a
 * body and from elsewhere -- the canary ladder commits them before its stream
 * flush, and the endpoint re-arms them per request. This class asks it to emit
 * before the body and is otherwise not involved.
 *
 * Every micro-step in this path is bracketed with a start/end checkpoint
 * pair, not just a post-hoc record: gap-hunt iteration 2 (Codex gaps #4 and
 * #5, 2026-07-22) found that json_encode() ran raw.
 */
final class ABJ_404_Solution_AjaxResponseEmitter {

    /**
     * @param mixed $payload
     * @param int $httpStatus
     * @return void
     */
    public static function sendJsonResponseAndExit($payload, $httpStatus = 200) {
        $scopes = ABJ_404_Solution_AjaxRequestIdScopes::fromGlobalContext();
        $checkpointRequestId = $scopes->checkpoint();
        $payload = ABJ_404_Solution_AjaxRequestLedger::stampOnPayload($payload, $scopes->ledger());
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
        if (!ABJ_404_Solution_JsonResponseHead::isComplete() && !headers_sent()) {
            ABJ_404_Solution_JsonResponseHead::emit($scopes, $httpStatus);
        }
        self::checkpointedEncodeAndEcho($payload, $scopes);

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
     * json_encode + echo as measured boundaries (matrix coverage req. 2,
     * gap-hunt iteration 2 Codex gap #4): the encode call itself is now
     * bracketed with json_encode_start/_end (payload shape on start, elapsed
     * on end) so a hang or fatal INSIDE json_encode() on a pathological
     * payload is attributable instead of vanishing into the preceding
     * uninstrumented gap. The post-hoc 'json_encode' record (bytes, content
     * hash, json_last_error()) is unchanged -- it still needs the encoded
     * result, which only exists after the bracketed call returns.
     * A response with no checkpoint scope is outside the Bruno table-AJAX
     * endpoint; skip the instrumentation but keep behavior identical.
     *
     * The post-hoc size record is scoped SEPARATELY, to
     * $scopes->measured() (AjaxDiagnosticRequestPolicy::diagnosticRequestId()),
     * because the two scopes answer different questions. The brackets are the
     * table endpoint's expensive micro-boundary instrumentation, and widening
     * that gate would also drag the canary ladder into the detach A/B
     * experiment that AjaxResponseEmitter::checkpointedFlushAndFinish()
     * deliberately keys off the same '' check -- confounding the very
     * interpretation matrix the ladder exists to produce. "How many bytes did
     * this response actually encode" is not instrumentation at all: it is a
     * one-line fact about the response, and every endpoint with an armed
     * durable trace needs it. Support report 2026-08-27 (Azure App Service,
     * plugin 4.3.4) turned on exactly this gap: the ladder's `stream` step
     * emitted 3072 bytes and the browser received 6089, and no record on the
     * server named the 3072, so the ladder could only report that the step
     * failed. See ABJ_404_Solution_ResponseBodyDeliveryEvidence.
     *
     * The measured scope equals the checkpoint scope on the table endpoint, so
     * that response still journals exactly one `json_encode` size record.
     *
     * @param mixed $payload
     */
    private static function checkpointedEncodeAndEcho(
        $payload,
        ABJ_404_Solution_AjaxRequestIdScopes $scopes
    ): void {
        try {
            if (!$scopes->hasCheckpoints()) {
                $encoded = ABJ_404_Solution_JsonResponseEncoder::encode($payload);
            } else {
                // around() RETURNS its closure's result, so the encoded
                // response comes back down the return path rather than through
                // a by-reference capture. That is not a style preference: the
                // by-ref form left a variable that was null until a closure
                // happened to run, which is what made an unreachable
                // "did the closure run?" branch look necessary here.
                $encoded = ABJ_404_Solution_AjaxCheckpointLogger::around(
                    $scopes->checkpoint(),
                    'json_encode',
                    static function () use ($payload) {
                        return ABJ_404_Solution_JsonResponseEncoder::encode($payload);
                    },
                    ABJ_404_Solution_PayloadShapeFingerprint::measure($payload)
                );
            }
        } catch (Throwable $t) {
            $encoded = self::lastResortEnvelope($t);
        }
        // record() is a no-op for '', so an endpoint with no armed trace
        // behaves exactly as it did before this scope existed.
        ABJ_404_Solution_AjaxCheckpointLogger::record(
            $scopes->measured(), 'json_encode', $encoded->diagnosticFields());
        self::reportDegradedEncode($encoded);
        $json = $encoded->json();
        if (!$scopes->hasCheckpoints()) {
            echo $json;
            return;
        }
        ABJ_404_Solution_AjaxCheckpointLogger::around(
            $scopes->checkpoint(),
            'echo',
            static function () use ($json) {
                echo $json;
            },
            array('bytes' => strlen($json))
        );
    }

    /**
     * The response of last resort, when encoding threw rather than degraded.
     *
     * This used to guard a condition that cannot happen: it re-encoded when
     * around() "returned without the closure having assigned", but around()
     * either returns the closure's result or throws, and
     * AjaxCheckpointLogger::record() swallows its own failures, so there is no
     * path on which it returns having not run the work. The real hazard was one
     * line further out and unguarded -- encode() and
     * PayloadShapeFingerprint::measure() can both throw on a pathological
     * payload, and that killed the whole response. The try now wraps the
     * encode, so this envelope ships for a failure that can actually occur.
     *
     * The cause travels two ways. The debug log always receives the class and
     * message via the returned object's errorMessage(). The BODY names the
     * cause only for a plugin admin, matching the details gate every other
     * error envelope on this endpoint already applies: an admin can act on
     * "JsonException: ...", and everyone else gets a sentence that says what to
     * do without publishing the plugin's internals. Neither audience gets the
     * canned "something went wrong" that says nothing to anyone.
     */
    private static function lastResortEnvelope(Throwable $t): ABJ_404_Solution_EncodedJsonResponse {
        $cause = get_class($t) . ': ' . $t->getMessage();
        $ctx = isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])
            ? $GLOBALS['abj404_ajax_context'] : array();
        $isPluginAdmin = !empty($ctx['is_plugin_admin']);
        $message = 'The plugin could not encode this response'
            . ($isPluginAdmin ? ' (' . $cause . ')' : '')
            . '. Reload the page to try again; if it keeps happening, '
            . 'the 404 Solution debug log records the full cause.';
        return new ABJ_404_Solution_EncodedJsonResponse(
            ABJ_404_Solution_AjaxErrorEnvelope::encodeSafely($message),
            ABJ_404_Solution_EncodedJsonResponse::STRATEGY_ERROR_ENVELOPE,
            JSON_ERROR_NONE,
            $cause
        );
    }

    /**
     * Leave a durable trace whenever an encode had to degrade.
     *
     * The checkpoint record above only lands when a durable trace is armed,
     * which on this endpoint means a retry -- so a FIRST-attempt encode failure
     * would otherwise leave nothing behind at all, which is exactly how the
     * 2026-08-27 Azure report arrived with a `parsererror` and no server-side
     * explanation. The debug log always gets the line.
     *
     * Severity follows the project's test: "can the plugin still do its job
     * after this failure?" A substituted or partial encode still renders the
     * admin table, so it is a warning; an envelope means the screen is broken,
     * so it is an error.
     *
     * @return void
     */
    private static function reportDegradedEncode(ABJ_404_Solution_EncodedJsonResponse $encoded): void {
        if (!$encoded->isDegraded()) {
            return;
        }
        $logger = function_exists('abj_service') ? abj_service('logging') : null;
        if (!is_object($logger)) {
            return;
        }
        $ctx = isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])
            ? $GLOBALS['abj404_ajax_context'] : array();
        $action = isset($ctx['action']) && is_string($ctx['action']) ? $ctx['action'] : '(unknown action)';
        $part = isset($ctx['part']) && is_string($ctx['part']) ? $ctx['part'] : '';
        $message = 'AjaxResponseEmitter: json_encode could not represent the response for '
            . $action . ($part === '' ? '' : ' (part ' . $part . ')')
            . '. Recovery strategy: ' . $encoded->strategy()
            . '. JSON error ' . $encoded->errorCode() . ': ' . $encoded->errorMessage()
            . '. Response bytes sent: ' . strlen($encoded->json()) . '.';
        if ($encoded->carriesPayload() && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }
        if (method_exists($logger, 'errorMessage')) {
            $logger->errorMessage($message);
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
        // opt-in-twice diagnostic session (DetachAbExperiment::assignNextAttempt()),
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
        $scope = ABJ_404_Solution_DetachAbScope::fromAjaxContext(
            $GLOBALS['abj404_ajax_context'] ?? null);
        $abDetachMode = ABJ_404_Solution_DetachAbExperiment::assignNextAttempt($scope);
        ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'detach_ab_mode', $abDetachMode);
        return $abDetachMode['mode'] === 'off';
    }
}
