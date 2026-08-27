<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX endpoint: ajaxRunCanaryStep (Bruno timeout cause matrix,
 * coverage req. 7 -- the adaptive canary ladder).
 *
 * One request per canary step, dispatched by the `canaryStep` param. Every
 * step re-authenticates (admin + the real table endpoint's own nonce) and
 * runs through the exact same ABJ_404_Solution_AjaxStageDiagnostics
 * begin/run/finish pipeline as ajaxUpdatePaginationLinks itself, so a
 * canary's timing is directly comparable, stage for stage, against the real
 * request's own trace record -- both land in the same flight-recorder
 * journal that already feeds the support-request payload.
 *
 * Step order and what each isolates (client-driven; the client decides
 * whether/when to run each one and supplies its own client-side static-asset
 * fetch as step 1, which never reaches PHP):
 *   concurrent_control - boot + auth + delivery, launched beside the first
 *                        real table attempt under the same host conditions.
 *   baseline_control - repeated fixed-size boot + auth + delivery reference
 *                        interleaved between measured steps on pre-releases.
 *   2. auth_only      - boot + auth + delivery, bypasses the rate limiter
 *                        and the table path entirely.
 *   3. post_limiter   - identical to auth_only but placed after a rate-limit
 *                        check, isolating the limiter's own overhead.
 *   4. summary        - the real table path's own DB work (status counts),
 *                        but a tiny summary-only response.
 *   5. size_target    - reads the completed server json_encode byte count
 *                        for this browser session without contaminating the
 *                        auth-only or summary controls.
 *   6. size_probe     - geometric matched-size compressible/incompressible
 *                        responses at 25%, 50%, and 100% of that target.
 *   7. inert          - a filler response of exactly the real payload's
 *                        observed byte size, no query work at all.
 *   8. compress_on/off - the same sized filler, with a hint to intermediaries
 *                        not to transform (compress) the response, isolating
 *                        compression/output-handler behavior.
 *   9. stream         - a flushed leading-whitespace block before the JSON,
 *                        so the client can observe XHR progress and locate
 *                        downstream buffering. Emitted only where the flush
 *                        can actually reach the client (see
 *                        AjaxCanaryLadder::resolveStreamFlushPlan); elsewhere
 *                        the step reports why it could not stream rather than
 *                        prefixing the body with bytes nobody sees early.
 *   interpret         - journals the client-computed interpretation matrix
 *                        (never re-derives it from server-side timing alone:
 *                        the browser is the only side that saw every step).
 */
class ABJ_404_Solution_Ajax_CanaryLadder {

    /** @return void */
    public function handle() {
        $requestReader = ABJ_404_Solution_AjaxAdminEndpointSupport::getRequestReader();
        $requestId = ABJ_404_Solution_AjaxRequestLedger::normalizeId(
            $requestReader->getPostOrGetSanitize('requestId', ABJ_404_Solution_AjaxRequestLedger::UNKNOWN_ID));
        $step = ABJ_404_Solution_AjaxCanaryLadder::normalizeStep($requestReader->getPostOrGetSanitize('canaryStep', ''));
        $subpage = (string)$requestReader->getPostOrGetSanitize('subpage', 'abj404_redirects');
        $ledger = ABJ_404_Solution_AjaxRequestLedger::readFields($requestReader);

        $isPluginAdmin = false;
        $context = array_merge(array(
            'action' => 'ajaxRunCanaryStep',
            'subpage' => $subpage,
            'part' => $step !== '' ? 'canary_' . $step : 'canary_invalid',
            'request_id' => $requestId,
            'retry_count' => 0,
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
            'handler_class' => __CLASS__,
        ), $ledger);
        $context = ABJ_404_Solution_AjaxAdminEndpointSupport::startAjaxDebugContext($context, 'Ajax_CanaryLadder::handle');

        ABJ_404_Solution_AjaxRequestLedger::recordHeaderMismatchIfAny($requestId, (string)$ledger['header_request_id']);

        try {
            if (!ABJ_404_Solution_AjaxAdminEndpointSupport::requireAdminWithNonceOrRespond(
                ABJ_404_Solution_AjaxCanaryLadder::NONCE_ACTION,
                $context,
                'ajaxRunCanaryStep'
            )) {
                return;
            }
            $isPluginAdmin = true;

            // The browser's receipt confirmation for every step that finished
            // before this one rides this request's params. Journaled HERE --
            // above the unknown-step gate, above the rate limiter, and above
            // this step's own work -- because every one of those is a way for
            // this request to end early, and the whole point of moving the
            // receipts off the final `interpret` POST is that they survive a
            // request that does not complete normally.
            self::journalPriorStepReceipts(
                $requestId,
                (string)$ledger['session_id'],
                $requestReader->getPostOrGetSanitize('canaryStepReceipts', ''));

            if ($step === '') {
                ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailure('AJAX unknown canary step in ajaxRunCanaryStep.', $context);
                ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
                $payload = ABJ_404_Solution_AjaxAdminEndpointSupport::buildAjaxErrorResponse('Unknown canary step.', null, false);
                ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 400);
                return;
            }

            if (in_array($step, ABJ_404_Solution_AjaxCanaryLadder::RATE_LIMITED_STEPS, true)
                    && !self::checkWorkRateLimitOrRespond($context)) {
                return;
            }

            ABJ_404_Solution_AjaxStageDiagnostics::beginRequest($context);

            $data = ABJ_404_Solution_AjaxCanaryStepRunner::run(
                $step, $requestReader, $requestId, $subpage, $context);
            $data['requestId'] = $requestId;
            $data['canaryStep'] = $step;

            ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('complete');
            ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($data, 200);
            return;

        } catch (Throwable $e) {
            ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('error');
            self::handleCanaryException($e, $isPluginAdmin, $context);
            return;
        }
    }

    /**
     * Journal what the browser said about steps that completed before this
     * one (Bruno timeout cause matrix, gap-hunt iteration 2 gap GE).
     *
     * Filed under the REPORTED step's own request id, so the receipt lands in
     * the same journal group as that step's own server-side trace and the two
     * halves of "the server ran it / the browser got it" read as one story.
     * Keying it to the carrying request instead would produce the orphaned
     * evidence gap GA had to fix elsewhere. A receipt for the static-asset
     * probe has no server request of its own and falls back to the carrying
     * id, which is the only id it can honestly be filed under.
     *
     * The browser session rides the record itself rather than being looked up
     * later through the stage-trace journal. That cross-channel join is what
     * failed on the 2026-08-27 Azure capture: all fifteen receipts were in the
     * checkpoint journal, the session id existed only in the stage trace, the
     * stage trace was empty, and the reconstruction reported "no_receipts" over
     * a complete set of them. A record that names its own session cannot be
     * lost by the silence of a channel it does not live in.
     *
     * Carried as `session_key` -- the md5 this journal already files
     * `detach_ab_mode` under (AjaxRequestLedger::detachAbSessionKey) -- rather
     * than as a raw id. The reconstruction needs equality and nothing else, and
     * the checkpoint channel has never carried a browser session in the clear.
     *
     * Never throws: ABJ_404_Solution_AjaxCheckpointLogger::record() is
     * failure-safe by contract, and a malformed report must not affect the
     * canary step that carried it.
     *
     * @param string $sessionId Already bounded by
     *   ABJ_404_Solution_AjaxRequestLedger::readFields(); '' when the client
     *   sent none, which stays '' rather than inheriting another run's.
     * @param mixed $raw
     */
    private static function journalPriorStepReceipts(
        string $carrierRequestId,
        string $sessionId,
        $raw
    ): void {
        foreach (ABJ_404_Solution_AjaxCanaryLadder::parseStepReceipts($raw) as $receipt) {
            $stepRequestId = isset($receipt['step_request_id']) && is_string($receipt['step_request_id'])
                ? $receipt['step_request_id'] : '';
            ABJ_404_Solution_AjaxCheckpointLogger::record(
                $stepRequestId !== '' ? $stepRequestId : $carrierRequestId,
                'canary_step_client_receipt',
                array_merge($receipt, array(
                    'carried_by' => $carrierRequestId,
                    'session_key' =>
                        ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey($sessionId),
                ))
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function checkWorkRateLimitOrRespond(array $context): bool {
        if (!ABJ_404_Solution_Ajax_Php::consumeRateLimit('canary_ladder_work', 120, 60)) {
            return true;
        }
        ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxRunCanaryStep.', $context);
        ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
        $payload = ABJ_404_Solution_AjaxAdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
        ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
        ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 429);
        return false;
    }

    /**
     * @param Throwable $e
     * @param array<string, mixed> $context
     */
    private static function handleCanaryException(Throwable $e, bool $isPluginAdmin, array $context): void {
        $isPluginAdmin = ABJ_404_Solution_AjaxAdminEndpointSupport::resolveIsPluginAdminFallback($isPluginAdmin);

        $details = array(
            'exception' => array(
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ),
            'context' => $context,
        );
        ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailure('AJAX exception in ajaxRunCanaryStep.', $details, $e);
        $capturedOutput = ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
        if ($capturedOutput !== '') {
            $details['buffered_output'] = substr($capturedOutput, 0, 8000);
        }

        ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
        $payload = ABJ_404_Solution_AjaxAdminEndpointSupport::buildAjaxErrorResponse(
            'Server error while running the canary ladder.',
            $details,
            $isPluginAdmin
        );
        $responseRequestId = $context['request_id'] ?? null;
        $payload['requestId'] = is_string($responseRequestId) ? $responseRequestId : 'unknown00';
        ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 500);
    }
}
