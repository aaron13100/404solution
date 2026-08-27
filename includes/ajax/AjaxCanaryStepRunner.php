<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What each canary step actually DOES.
 *
 * Split from ABJ_404_Solution_Ajax_CanaryLadder, which owns the endpoint:
 * authentication, the rate limiter, journaling the browser's prior-step
 * receipts, emitting the response and handling a thrown step. This class owns
 * the other half -- the ordered probe sequence itself -- and knows nothing
 * about HTTP.
 *
 * The split matters beyond line count. The endpoint's concerns change when
 * WordPress auth or the response emitter changes; the probes change when a new
 * cause needs isolating, which is roughly every support investigation. Reading
 * the ladder now means reading the sequence without the request plumbing
 * around it, and the class docblock on ABJ_404_Solution_Ajax_CanaryLadder still
 * documents what each step isolates and why it sits where it does.
 *
 * Every step runs inside ABJ_404_Solution_AjaxStageDiagnostics::runStage(), so
 * a canary's timing is directly comparable, stage for stage, against the real
 * table request's own trace record in the same journal.
 */
final class ABJ_404_Solution_AjaxCanaryStepRunner {

    /** Maximum browser-observation bytes accepted by the interpretation step. */
    const MAX_INTERPRETATION_BYTES = 8192;

    /**
     * Execute one canary step and return its response payload.
     *
     * A registry-shaped switch rather than a strategy map on purpose: every arm
     * is one call wrapped in the same runStage() bracket, the step ids are a
     * closed set declared by ABJ_404_Solution_AjaxCanaryLadder, and the ladder's
     * whole value is that a reader can see the ordered sequence of probes in one
     * place. Splitting it per step would scatter the very comparison the ladder
     * exists to make.
     *
     * @param ABJ_404_Solution_RequestInputNormalizer $requestReader
     * @param array<string, mixed> $context Mutated in place by runStage().
     * @return array<string, mixed>
     */
    public static function run(string $step, $requestReader, string $requestId, string $subpage, array &$context): array {
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
                        $target = ABJ_404_Solution_EncodedTableResponseSize::forSession($sessionId);
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
                $obLevelBefore = isset($context['ob_level_before']) && is_numeric($context['ob_level_before'])
                    ? (int)$context['ob_level_before'] : 0;
                return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_stream',
                    static function () use ($requestId, $obLevelBefore) {
                        // Routed through the same output-buffer-management
                        // filter every other flush in this codebase respects,
                        // so a host or test that turns that management off
                        // never gets a mid-response flush it did not ask for.
                        //
                        // The plan decides whether a whitespace block is
                        // emitted at all. ob_flush() only moves the CURRENT
                        // buffer into its PARENT, so behind any foreign
                        // buffer this step used to prefix the body with 2048
                        // non-JSON bytes that reached no client, escaped the
                        // plugin's own containment floor, and confounded the
                        // comparison against `inert`. See
                        // ABJ_404_Solution_AjaxCanaryLadder::resolveStreamFlushPlan().
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
                        $plan = ABJ_404_Solution_AjaxCanaryLadder::resolveStreamFlushPlan(
                            $manageOutputBuffer, $obLevelBefore, ob_get_level());
                        $flushOutcome = ABJ_404_Solution_AjaxCanaryStreamFlush::emitAndFlush(
                            $plan,
                            ABJ_404_Solution_AjaxCanaryLadder::STREAM_WHITESPACE_BYTES,
                            static function (): void {
                                ABJ_404_Solution_AjaxResponseEmitter::emitJsonResponseHeadersEarly(200);
                            },
                            static function (callable $work, array $startFields) use ($requestId): void {
                                ABJ_404_Solution_AjaxCheckpointLogger::around(
                                    $requestId, 'canary_stream_first_flush', $work, $startFields);
                            }
                        );
                        // The findings ride their own record rather than the
                        // bracket's end record: a support payload is read
                        // long after the run, and "did this step stream, and
                        // if not why not" is the one thing that decides
                        // whether its outcome is evidence about streaming at
                        // all.
                        ABJ_404_Solution_AjaxCheckpointLogger::record(
                            $requestId, 'canary_stream_flush_outcome', $flushOutcome);
                        return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                            $requestId, ABJ_404_Solution_AjaxCanaryLadder::STEP_STREAM,
                            ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES, $flushOutcome);
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
     * ABJ_404_Solution_DetachAbVerdict::fromAttempts() was a
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
}
