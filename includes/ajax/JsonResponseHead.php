<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The head of a JSON AJAX response: its headers, its status code, and which of
 * those two this request has actually emitted.
 *
 * Split out of ABJ_404_Solution_AjaxResponseEmitter (which owns the body encode
 * + echo boundary and the connection-detach/exit tail) because the head is
 * emitted on its own, from elsewhere, before any body exists:
 * ABJ_404_Solution_AjaxCanaryStepRunner commits it before the canary ladder's
 * `stream` step flushes, and ABJ_404_Solution_AjaxAdminEndpointSupport re-arms
 * it at the start of every request. Those are calls on the head, not on the
 * emitter, and they were reaching through it.
 *
 * The head is TWO emissions, not one. WordPress dispatches the foreign
 * `status_header` filter before its own header() call, so the status half runs
 * arbitrary third-party code and can fail on its own while the headers half has
 * already succeeded. Both are separately checkpoint-bracketed for that reason
 * (gap-hunt iteration 2, Codex gap #5): before that, a blocking header filter
 * left `trace_finish_end` followed by nothing, indistinguishable from a worker
 * kill.
 */
final class ABJ_404_Solution_JsonResponseHead {

    /** The two independently-completing halves of the response head. */
    const STEP_HEADERS = 'headers';
    const STEP_STATUS = 'status_header';

    /**
     * Which halves this request has actually emitted.
     *
     * headers_sent() alone cannot answer that: while any output buffer holds
     * the body, the head is set but not yet on the wire, so a second emission
     * would silently duplicate Content-type and X-ABJ404-Request-ID and journal
     * a second headers_start/_end pair.
     *
     * A SET rather than one flag because the two halves fail independently. A
     * single flag was raised before either had run, so a `status_header`
     * callback that threw left the request marked as having emitted a head it
     * had not: the handler's catch then sent its 500 envelope, this class
     * skipped the status it believed was already sent, and the browser received
     * an error body under HTTP 200 -- which jQuery reports as success.
     *
     * @var array<string, bool>
     */
    private static $completedSteps = array();

    /**
     * Re-arm the per-request bookkeeping. Called from the endpoint's own arming
     * point so this stays request state rather than a test-only back door: one
     * PHP request serves one AJAX response, but one PHPUnit worker serves many.
     *
     * @return void
     */
    public static function resetForRequest(): void {
        self::$completedSteps = array();
    }

    /** Whether both halves of the response head have been emitted. */
    public static function isComplete(): bool {
        return !empty(self::$completedSteps[self::STEP_HEADERS])
            && !empty(self::$completedSteps[self::STEP_STATUS]);
    }

    /**
     * Emit the head NOW, before any body byte can commit it.
     *
     * A handler that echoes and flushes mid-response (the canary ladder's
     * `stream` step) commits the response head at that flush. By the time the
     * emitter runs, headers_sent() is true and its own emission is skipped --
     * so without this call the streamed response ships with PHP's default
     * text/html and, worse, without the X-ABJ404-Request-ID header the ledger
     * relies on to identify a request whose body never arrives, which is
     * exactly the case the canary exists to diagnose.
     *
     * Committing the head here also pins the status code, so an error raised
     * after this point can no longer change it. That is not a regression: on
     * the only branch that calls this, the flush was already committing the
     * head a few statements later regardless. The difference is whether the
     * committed head is the right one.
     *
     * @param int $httpStatus
     * @return void
     */
    public static function emitEarly($httpStatus = 200): void {
        if (self::isComplete() || headers_sent()) {
            return;
        }
        self::emit(ABJ_404_Solution_AjaxRequestIdScopes::fromGlobalContext(), $httpStatus);
    }

    /**
     * Emit whichever halves have not been emitted yet.
     *
     * Each half is marked complete only AFTER its emission returns, and skipped
     * only if it already did, so a throw leaves the half that did not run still
     * pending and the handler's error response can still set its own status.
     *
     * A response with no checkpoint scope is outside the Bruno table-AJAX
     * endpoint; skip the instrumentation but keep behavior identical.
     *
     * @param int $httpStatus
     * @return void
     */
    public static function emit(
            ABJ_404_Solution_AjaxRequestIdScopes $scopes, $httpStatus): void {
        $ledgerRequestId = $scopes->ledger();
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
        if (empty(self::$completedSteps[self::STEP_HEADERS])) {
            if (!$scopes->hasCheckpoints()) {
                $emitHeaders();
            } else {
                ABJ_404_Solution_AjaxCheckpointLogger::around(
                    $scopes->checkpoint(), self::STEP_HEADERS, $emitHeaders);
            }
            self::$completedSteps[self::STEP_HEADERS] = true;
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
        if (!$scopes->hasCheckpoints()) {
            $emitStatus();
        } else {
            ABJ_404_Solution_AjaxCheckpointLogger::around(
                $scopes->checkpoint(), self::STEP_STATUS, $emitStatus,
                array('http_status' => $httpStatus));
        }
        self::$completedSteps[self::STEP_STATUS] = true;
    }
}
