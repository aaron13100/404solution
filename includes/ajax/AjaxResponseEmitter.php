<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * How a JSON AJAX response actually leaves the server: header + ledger
 * stamping, the measured json_encode + echo boundary, output-buffer drain,
 * connection-detach (fastcgi_finish_request / litespeed_finish_request,
 * including the Bruno timeout cause matrix gap G9 detach A/B diagnostic),
 * and exit. Split out of ABJ_404_Solution_Ajax_AdminEndpointSupport (which
 * owns the surrounding request lifecycle: auth gate, error envelope,
 * debug-context, failure-logging delegation, service resolution) because
 * response emission is its own cohesive responsibility with its own heavy
 * external caller list: every per-endpoint handler in
 * includes/ajax/Ajax_*.php calls sendJsonResponseAndExit() directly.
 */
final class ABJ_404_Solution_AjaxResponseEmitter {

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
        if (!headers_sent()) {
            if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
                $ctx = $GLOBALS['abj404_ajax_context'];
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
            if (function_exists('status_header')) {
                status_header($httpStatus);
            } else if (function_exists('http_response_code')) {
                http_response_code($httpStatus);
            }
        }
        self::checkpointedEncodeAndEcho($payload, $checkpointRequestId);

        // Test hook: tests register `abj404_should_exit` returning false to skip exit.
        if (!apply_filters('abj404_should_exit', true, array('source' => 'viewUpdater_emitJson'))) {
            return;
        }

        self::checkpointedFlushAndFinish($checkpointRequestId);

        exit;
    }

    /**
     * json_encode + echo as one measured boundary (matrix coverage req. 2):
     * bytes, a content hash, and json_last_error() so a truncated or
     * pathological payload is directly visible instead of inferred from a
     * client-side parse failure. $checkpointRequestId === '' means this
     * response is outside the Bruno table-AJAX endpoint; skip the
     * instrumentation but keep behavior identical.
     *
     * @param mixed $payload
     */
    private static function checkpointedEncodeAndEcho($payload, string $checkpointRequestId): void {
        if ($checkpointRequestId === '') {
            echo json_encode($payload);
            return;
        }
        $json = json_encode($payload);
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
     * The output-buffer flush / connection-detach / exit tail (matrix
     * coverage req. 2): each ob_end_flush() close (handler name + bytes),
     * flush(), which finish-request function exists, which one was selected
     * and what it returned, and a final exit sentinel immediately before the
     * caller calls exit. Kept as
     * its own method (rather than inlined before `exit;`) so it is a real,
     * directly callable unit: the literal `exit;` a few lines below it in
     * sendJsonResponseAndExit() can never run inside a PHPUnit process, but
     * this method's own logic can be exercised and asserted on directly.
     */
    private static function checkpointedFlushAndFinish(string $checkpointRequestId): void {
        if (function_exists('ob_end_flush')) {
            while (ob_get_level() > 0) {
                $status = ob_get_status();
                if ($checkpointRequestId !== '') {
                    ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'ob_close', array(
                        'handler' => is_string($status['name'] ?? null) ? $status['name'] : 'unknown',
                        'bytes' => ob_get_length(),
                    ));
                }
                ob_end_flush();
            }
        }
        if (function_exists('flush')) {
            if ($checkpointRequestId !== '') {
                ABJ_404_Solution_AjaxCheckpointLogger::around($checkpointRequestId, 'flush', static function () {
                    flush();
                });
            } else {
                flush();
            }
        }
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
        // alternate whether the detach below actually runs, so a beta.2
        // SUCCESS can be attributed to the detach fix rather than merely
        // correlated with it. Scoped to $checkpointRequestId !== '' -- the
        // same INSTRUMENTED_ACTION gate every other checkpoint here already
        // uses -- so the canary ladder's own requests never reach this branch
        // and its seven-step interpretation matrix can never be confounded
        // by it. The 'inert'/'default' cases behave exactly like before this
        // feature existed; only 'off' changes anything.
        $abDetachSkipped = self::resolveAndRecordDetachAbSkip($checkpointRequestId);

        // Recorded BEFORE the call: if the detach itself stalls or the worker
        // is killed inside it, the journal still names what was about to run.
        // ab_detach_skipped distinguishes a deliberate skip from the 'none'
        // case (no detach function available at all): both leave 'result'
        // null in the next record, and only this flag tells them apart.
        if ($checkpointRequestId !== '') {
            ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'finish_request', array(
                'fastcgi_finish_request_exists' => $hasFastcgiFinish,
                'litespeed_finish_request_exists' => $hasLitespeedFinish,
                'selected' => $finishFunction,
                'sapi' => PHP_SAPI,
                'ab_detach_skipped' => $abDetachSkipped,
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
        if ($checkpointRequestId !== '') {
            ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'finish_request_result', array(
                'function' => $abDetachSkipped ? 'skipped_by_ab_diagnostic' : $finishFunction,
                'result' => $result,
            ));
        }
        if ($checkpointRequestId !== '') {
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
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $rawSessionId = $GLOBALS['abj404_ajax_context']['session_id'] ?? '';
            $sessionId = is_scalar($rawSessionId) ? (string)$rawSessionId : '';
        }
        $abDetachMode = ABJ_404_Solution_AjaxRequestLedger::resolveDetachAbMode($sessionId);
        ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'detach_ab_mode', $abDetachMode);
        return $abDetachMode['mode'] === 'off';
    }
}
