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
 *                        downstream buffering.
 *   interpret         - journals the client-computed interpretation matrix
 *                        (never re-derives it from server-side timing alone:
 *                        the browser is the only side that saw every step).
 */
class ABJ_404_Solution_Ajax_CanaryLadder {

    /** Maximum browser-observation bytes accepted by the interpretation step. */
    const MAX_INTERPRETATION_BYTES = 8192;

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
                $requestId, $requestReader->getPostOrGetSanitize('canaryStepReceipts', ''));

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

            $data = self::runStep($step, $requestReader, $requestId, $subpage, $context);
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
     * Never throws: ABJ_404_Solution_AjaxCheckpointLogger::record() is
     * failure-safe by contract, and a malformed report must not affect the
     * canary step that carried it.
     *
     * @param mixed $raw
     */
    private static function journalPriorStepReceipts(string $carrierRequestId, $raw): void {
        foreach (ABJ_404_Solution_AjaxCanaryLadder::parseStepReceipts($raw) as $receipt) {
            $stepRequestId = isset($receipt['step_request_id']) && is_string($receipt['step_request_id'])
                ? $receipt['step_request_id'] : '';
            ABJ_404_Solution_AjaxCheckpointLogger::record(
                $stepRequestId !== '' ? $stepRequestId : $carrierRequestId,
                'canary_step_client_receipt',
                array_merge($receipt, array('carried_by' => $carrierRequestId))
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
     * @param ABJ_404_Solution_RequestInputNormalizer $requestReader
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runStep(string $step, $requestReader, string $requestId, string $subpage, array &$context): array {
        switch ($step) {
            case ABJ_404_Solution_AjaxCanaryLadder::STEP_CONCURRENT_CONTROL:
            case ABJ_404_Solution_AjaxCanaryLadder::STEP_AUTH_ONLY:
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_' . $step,
                    static function () use ($requestId, $step) {
                        return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                            $requestId, $step,
                            ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES);
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_SIZE_TARGET:
                $rawSessionId = $context['session_id'] ?? '';
                $sessionId = is_scalar($rawSessionId) ? (string)$rawSessionId : '';
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_size_target',
                    static function () use ($sessionId) {
                        $target = ABJ_404_Solution_CheckpointJournalReader::latestEncodedTableResponseForSession(
                            $sessionId);
                        return array(
                            'realResponseBytes' => $target['bytes'],
                            'realResponseBytesSource' => $target['source'],
                            'realResponseRequestId' => $target['request_id'],
                        );
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_BASELINE_CONTROL:
                $rawOrdinal = $requestReader->getPostOrGetSanitize('baselineOrdinal', '0');
                $ordinal = is_numeric($rawOrdinal) ? max(0, min(20, (int)$rawOrdinal)) : 0;
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage(
                    $context,
                    'canary_baseline_control',
                    static function () use ($requestId, $ordinal) {
                        $payload = ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                            $requestId,
                            ABJ_404_Solution_AjaxCanaryLadder::STEP_BASELINE_CONTROL,
                            ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES
                        );
                        $payload['baselineOrdinal'] = $ordinal;
                        $encodedBytes = strlen((string)json_encode($payload));
                        $excessBytes = max(
                            0,
                            $encodedBytes - ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES
                        );
                        if ($excessBytes > 0) {
                            $filler = $payload['filler'];
                            $payload['filler'] = substr(
                                $filler,
                                0,
                                max(0, strlen($filler) - $excessBytes)
                            );
                        }
                        return $payload;
                    }
                );

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

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_SIZE_PROBE:
                $bytes = ABJ_404_Solution_AjaxCanaryLadder::clampTargetBytes(
                    $requestReader->getPostOrGetSanitize('payloadBytes', ''));
                $variant = ABJ_404_Solution_AjaxCanaryLadder::normalizePayloadVariant(
                    $requestReader->getPostOrGetSanitize('payloadVariant', ''));
                $rungPercent = ABJ_404_Solution_AjaxCanaryLadder::normalizePayloadRungPercent(
                    $requestReader->getPostOrGetSanitize('payloadRungPercent', ''));
                $targetSource = ABJ_404_Solution_AjaxCanaryLadder::normalizeTargetBytesSource(
                    $requestReader->getPostOrGetSanitize('targetBytesSource', ''));
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_size_probe',
                    static function () use ($requestId, $bytes, $variant, $rungPercent, $targetSource) {
                        return ABJ_404_Solution_AjaxCanaryLadder::buildPayloadVariant(array(
                            'request_id' => $requestId,
                            'target_bytes' => $bytes,
                            'variant' => $variant,
                            'rung_percent' => $rungPercent,
                            'target_source' => $targetSource,
                        ));
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_INERT:
                $bytes = ABJ_404_Solution_AjaxCanaryLadder::clampTargetBytes($requestReader->getPostOrGetSanitize('payloadBytes', ''));
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_inert',
                    static function () use ($requestId, $bytes) {
                        return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                            $requestId, ABJ_404_Solution_AjaxCanaryLadder::STEP_INERT, $bytes);
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_COMPRESS_ON:
            case ABJ_404_Solution_AjaxCanaryLadder::STEP_COMPRESS_OFF:
                $bytes = ABJ_404_Solution_AjaxCanaryLadder::clampTargetBytes($requestReader->getPostOrGetSanitize('payloadBytes', ''));
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
                        // Routed through the same output-buffer-management
                        // filter every other flush in this codebase respects
                        // (AjaxAdminEndpointSupport::checkpointedFlushAndFinish),
                        // so tests that disable OB management (and so must
                        // read the whitespace back via ob_get_clean()) are
                        // unaffected: this is a real mid-response flush only
                        // in production, never a premature one in test output
                        // buffering.
                        //
                        // around()-bracketed rather than announced by a bare
                        // pre-call record (gap-hunt iteration 2, the same
                        // Codex gap #5 shape fixed in AjaxResponseEmitter's
                        // ob_close): a stall inside ob_flush()/flush() behind
                        // a buffering intermediary is exactly what this canary
                        // step exists to detect, and a record with no matching
                        // end could only ever prove a flush was ATTEMPTED.
                        // 'flushed' keeps the skip branch positive evidence
                        // instead of an absence -- without it an elapsed of 0
                        // reads as an instant flush rather than no flush.
                        $manageOutputBuffer = (bool)apply_filters(
                            'abj404_should_manage_output_buffer', true, array('source' => 'canaryLadder_stream'));
                        ABJ_404_Solution_AjaxCheckpointLogger::around(
                            $requestId,
                            'canary_stream_first_flush',
                            static function () use ($manageOutputBuffer) {
                                if (!$manageOutputBuffer) {
                                    return;
                                }
                                if (ob_get_level() > 0) {
                                    @ob_flush();
                                }
                                @flush();
                            },
                            array(
                                'bytes' => ABJ_404_Solution_AjaxCanaryLadder::STREAM_WHITESPACE_BYTES,
                                'flushed' => $manageOutputBuffer,
                                'ob_level' => ob_get_level(),
                            )
                        );
                        return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                            $requestId, ABJ_404_Solution_AjaxCanaryLadder::STEP_STREAM,
                            ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES);
                    });

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_INTERPRET:
                return self::runInterpretStep($requestReader, $requestId, $context);

            default:
                return array();
        }
    }

    /**
     * The ladder's closing step: two independent verdicts, both journaled.
     *
     * The ladder interpretation matrix is computed by the BROWSER (it is
     * the only side that saw every step) and journaled here. The detach A/B
     * verdict is computed HERE, from the durable journal, because its two
     * halves never meet on the client: the server chose each real table
     * request's detach mode, the browser reported whether that request
     * completed, and until this call site existed nothing joined them --
     * ABJ_404_Solution_AjaxCanaryLadder::interpretDetachAbResults() was a
     * decision rule with no production caller, so the verdict a beta session
     * exists to produce depended on a human joining two record kinds by hand.
     *
     * The A/B verdict is written through the checkpoint channel rather than
     * only into the stage trace: its source evidence lives in that same
     * journal, so verdict and evidence travel together into the support
     * payload and the developer log archive, and a defect in the trace class
     * cannot erase the conclusion drawn about it.
     *
     * The two verdicts stay separate records computed from disjoint inputs.
     * Merging them would let an ambiguous quadrant in one leak into the
     * other's conclusion, which is the same reason the pure rules are
     * separate functions.
     *
     * @param ABJ_404_Solution_RequestInputNormalizer $requestReader
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runInterpretStep($requestReader, string $requestId, array &$context): array {
        $raw = (string)$requestReader->getPostOrGetSanitize('observations', '');
        $parsed = ABJ_404_Solution_RequestInputNormalizer::decodeBoundedJsonArray(array(
            'raw' => $raw,
            'max_bytes' => self::MAX_INTERPRETATION_BYTES,
            'unavailable_label' => 'Interpretation unavailable: observations',
        ));
        $realFailed = (string)$requestReader->getPostOrGetSanitize('realRequestFailed', '1') !== '0';
        $rawSessionId = $context['session_id'] ?? '';
        $sessionId = is_scalar($rawSessionId) ? (string)$rawSessionId : '';

        return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_interpret',
            static function () use ($parsed, $realFailed, $requestId, $sessionId) {
                $interpretation = null;
                $stageMetadata = array();
                if ($parsed['status'] === 'available') {
                    $interpretation = ABJ_404_Solution_AjaxCanaryLadder::interpretResults(
                        $parsed['observations'], $realFailed);
                    foreach ($interpretation as $key => $value) {
                        if (is_scalar($value)) {
                            $stageMetadata[$key] = $value;
                        }
                    }
                } else {
                    $stageMetadata = $parsed['unavailable'];
                }
                ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata($stageMetadata);

                $detachAb = ABJ_404_Solution_DetachAbEvidence::verdictForSession($sessionId);
                ABJ_404_Solution_AjaxCheckpointLogger::record(
                    $requestId, ABJ_404_Solution_DetachAbEvidence::VERDICT_EVENT, $detachAb);

                return array(
                    'interpretation' => $interpretation,
                    'interpretationUnavailable' => $parsed['status'] === 'unavailable'
                        ? $parsed['unavailable'] : null,
                    'detachAb' => $detachAb,
                    'received' => $parsed['status'] === 'available',
                );
            });
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
