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
 *   2. auth_only      - boot + auth + delivery, bypasses the rate limiter
 *                        and the table path entirely.
 *   3. post_limiter   - identical to auth_only but placed after a rate-limit
 *                        check, isolating the limiter's own overhead.
 *   4. summary        - the real table path's own DB work (status counts),
 *                        but a tiny summary-only response.
 *   5. inert          - a filler response of exactly the real payload's
 *                        observed byte size, no query work at all.
 *   6. compress_on/off - the same sized filler, with a hint to intermediaries
 *                        not to transform (compress) the response, isolating
 *                        compression/output-handler behavior.
 *   7. stream         - a flushed leading-whitespace block before the JSON,
 *                        so the client can observe XHR progress and locate
 *                        downstream buffering.
 *   interpret         - journals the client-computed interpretation matrix
 *                        (never re-derives it from server-side timing alone:
 *                        the browser is the only side that saw every step).
 */
class ABJ_404_Solution_Ajax_CanaryLadder {

    /** @return void */
    public function handle() {
        $functions = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
        $requestId = ABJ_404_Solution_AjaxRequestLedger::normalizeId(
            $functions->getPostOrGetSanitize('requestId', ABJ_404_Solution_AjaxRequestLedger::UNKNOWN_ID));
        $step = ABJ_404_Solution_AjaxCanaryLadder::normalizeStep($functions->getPostOrGetSanitize('canaryStep', ''));
        $subpage = (string)$functions->getPostOrGetSanitize('subpage', 'abj404_redirects');
        $ledger = ABJ_404_Solution_AjaxRequestLedger::readFields($functions);

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
        $context = ABJ_404_Solution_Ajax_AdminEndpointSupport::startAjaxDebugContext($context, 'Ajax_CanaryLadder::handle');

        ABJ_404_Solution_AjaxRequestLedger::recordHeaderMismatchIfAny($requestId, (string)$ledger['header_request_id']);

        try {
            if (!ABJ_404_Solution_Ajax_AdminEndpointSupport::requireAdminWithNonceOrRespond(
                ABJ_404_Solution_AjaxCanaryLadder::NONCE_ACTION,
                $context,
                'ajaxRunCanaryStep'
            )) {
                return;
            }
            $isPluginAdmin = true;

            if ($step === '') {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX unknown canary step in ajaxRunCanaryStep.', $context);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Unknown canary step.', null, false);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 400);
                return;
            }

            if (in_array($step, ABJ_404_Solution_AjaxCanaryLadder::RATE_LIMITED_STEPS, true)
                    && !self::checkWorkRateLimitOrRespond($context)) {
                return;
            }

            ABJ_404_Solution_AjaxStageDiagnostics::beginRequest($context);

            $data = self::runStep($step, $functions, $requestId, $subpage, $context);
            $data['requestId'] = $requestId;
            $data['canaryStep'] = $step;

            ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('complete');
            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($data, 200);
            return;

        } catch (Throwable $e) {
            ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('error');
            self::handleCanaryException($e, $isPluginAdmin, $context);
            return;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function checkWorkRateLimitOrRespond(array $context): bool {
        if (!ABJ_404_Solution_Ajax_Php::consumeRateLimit('canary_ladder_work', 120, 60)) {
            return true;
        }
        ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxRunCanaryStep.', $context);
        ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
        $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
        ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
        ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 429);
        return false;
    }

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runStep(string $step, $functions, string $requestId, string $subpage, array &$context): array {
        switch ($step) {
            case ABJ_404_Solution_AjaxCanaryLadder::STEP_CONCURRENT_CONTROL:
            case ABJ_404_Solution_AjaxCanaryLadder::STEP_AUTH_ONLY:
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_' . $step,
                    static function () use ($requestId, $step) {
                        return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                            $requestId, $step,
                            ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES);
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_POST_LIMITER:
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_post_limiter',
                    static function () use ($requestId) {
                        // Same limiter call the real table endpoint makes, on
                        // its own bucket with a ceiling high enough to never
                        // actually trip: this step measures the limiter's own
                        // overhead, not its enforcement.
                        ABJ_404_Solution_Ajax_Php::consumeRateLimit('canary_ladder_probe', 6000, 60);
                        return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                            $requestId, ABJ_404_Solution_AjaxCanaryLadder::STEP_POST_LIMITER,
                            ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES);
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_SUMMARY:
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_summary',
                    static function () use ($subpage) {
                        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
                        $viewReadService = abj_service('view_read_service');
                        $counts = $subpage === 'abj404_captured'
                            ? $viewReadService->getCapturedStatusCounts()
                            : $viewReadService->getRedirectStatusCounts();
                        return array('summaryTotal' => (int)($counts['all'] ?? 0));
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_INERT:
                $bytes = ABJ_404_Solution_AjaxCanaryLadder::clampTargetBytes($functions->getPostOrGetSanitize('payloadBytes', ''));
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_inert',
                    static function () use ($requestId, $bytes) {
                        return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                            $requestId, ABJ_404_Solution_AjaxCanaryLadder::STEP_INERT, $bytes);
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_COMPRESS_ON:
            case ABJ_404_Solution_AjaxCanaryLadder::STEP_COMPRESS_OFF:
                $bytes = ABJ_404_Solution_AjaxCanaryLadder::clampTargetBytes($functions->getPostOrGetSanitize('payloadBytes', ''));
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_' . $step,
                    static function () use ($requestId, $step, $bytes) {
                        if ($step === ABJ_404_Solution_AjaxCanaryLadder::STEP_COMPRESS_OFF) {
                            // Ask any compressing intermediary (LiteSpeed,
                            // Cloudflare) not to transform this response, and
                            // disable PHP's own output compression if it was
                            // on, so the on/off canaries actually differ.
                            if (!headers_sent()) {
                                header('Cache-Control: no-transform');
                            }
                            @ini_set('zlib.output_compression', '0');
                        }
                        $payload = ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload($requestId, $step, $bytes);
                        $payload['compressionMode'] = $step;
                        return $payload;
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_STREAM:
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_stream',
                    static function () use ($requestId) {
                        echo str_repeat(' ', ABJ_404_Solution_AjaxCanaryLadder::STREAM_WHITESPACE_BYTES);
                        ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, 'canary_stream_first_flush', array(
                            'bytes' => ABJ_404_Solution_AjaxCanaryLadder::STREAM_WHITESPACE_BYTES,
                        ));
                        // Routed through the same output-buffer-management
                        // filter every other flush in this codebase respects
                        // (Ajax_AdminEndpointSupport::checkpointedFlushAndFinish),
                        // so tests that disable OB management (and so must
                        // read the whitespace back via ob_get_clean()) are
                        // unaffected: this is a real mid-response flush only
                        // in production, never a premature one in test output
                        // buffering.
                        if (apply_filters('abj404_should_manage_output_buffer', true, array('source' => 'canaryLadder_stream'))) {
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            @flush();
                        }
                        return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                            $requestId, ABJ_404_Solution_AjaxCanaryLadder::STEP_STREAM,
                            ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES);
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_INTERPRET:
                $raw = (string)$functions->getPostOrGetSanitize('observations', '');
                $decoded = $raw !== '' ? json_decode(substr($raw, 0, 8192), true) : null;
                $observations = is_array($decoded) ? $decoded : array();
                $realFailed = (string)$functions->getPostOrGetSanitize('realRequestFailed', '1') !== '0';
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_interpret',
                    static function () use ($observations, $realFailed) {
                        $interpretation = ABJ_404_Solution_AjaxCanaryLadder::interpretResults($observations, $realFailed);
                        ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata($interpretation);
                        return array('interpretation' => $interpretation, 'received' => true);
                    });

            default:
                return array();
        }
    }

    /**
     * @param Throwable $e
     * @param array<string, mixed> $context
     */
    private static function handleCanaryException(Throwable $e, bool $isPluginAdmin, array $context): void {
        $isPluginAdmin = ABJ_404_Solution_Ajax_AdminEndpointSupport::resolveIsPluginAdminFallback($isPluginAdmin);

        $details = array(
            'exception' => array(
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ),
            'context' => $context,
        );
        ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX exception in ajaxRunCanaryStep.', $details, $e);
        $capturedOutput = ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
        if ($capturedOutput !== '') {
            $details['buffered_output'] = substr($capturedOutput, 0, 8000);
        }

        ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
        $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse(
            'Server error while running the canary ladder.',
            $details,
            $isPluginAdmin
        );
        $responseRequestId = $context['request_id'] ?? null;
        $payload['requestId'] = is_string($responseRequestId) ? $responseRequestId : 'unknown00';
        ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 500);
    }
}
