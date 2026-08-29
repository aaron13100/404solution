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
 *
 * Declared once and referenced by every step method below, rather than spelled
 * out at each one: a shape repeated eleven times is a shape that eventually
 * disagrees with itself.
 *
 * @phpstan-type CanaryStepRequest array{step: string, request_reader: ABJ_404_Solution_RequestInputNormalizer, request_id: string, subpage: string}
 */
final class ABJ_404_Solution_AjaxCanaryStepRunner {

    /** Maximum browser-observation bytes accepted by the interpretation step. */
    const MAX_INTERPRETATION_BYTES = 8192;

    /**
     * Route one canary step to the probe that performs it, and return that
     * probe's response payload.
     *
     * A routing table and nothing else: every arm is a single call to one named
     * step method, so a reader can see the ordered sequence of probes in one
     * place without any probe's own logic in the way. That ordered view is the
     * ladder's whole value -- each step differs from its neighbour by exactly
     * one variable, and the comparison between adjacent steps is the diagnosis.
     *
     * The arms used to carry their logic inline. Three of them had grown past a
     * single call (payload resizing, header and ini mutation, four parameter
     * normalizations) while the docblock still claimed all of them were one
     * call, which is precisely how a routing table stops being scannable.
     *
     * Deliberately NOT a strategy registry of one class per step: the steps
     * have exactly one caller and are meaningful only as an ordered ladder, so
     * scattering them across ten files would hide the very comparison they
     * exist to make (modularity-extraction: over-decomposed family).
     *
     * @param CanaryStepRequest $stepRequest
     *        Keyed rather than positional: `step`, `request_id` and `subpage`
     *        are all strings, so positionally any two could be transposed to
     *        produce a type-correct call that probes one thing and labels it
     *        another. PHP 7.4 is the floor here, so named arguments do not
     *        exist and a positional value-object constructor would relocate
     *        the hazard rather than remove it.
     * @param array<string, mixed> $context Mutated in place by runStage().
     * @return array<string, mixed>
     */
    public static function dispatchStep(array $stepRequest, array &$context): array {
        switch ($stepRequest['step']) {
            case ABJ_404_Solution_AjaxCanaryLadder::STEP_CONCURRENT_CONTROL:
            case ABJ_404_Solution_AjaxCanaryLadder::STEP_AUTH_ONLY:
                return self::runFillerStep($stepRequest, $context);

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_SIZE_TARGET:
                return self::resolveSizeTarget($stepRequest, $context);

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_BASELINE_CONTROL:
                return self::runBaselineControlStep($stepRequest, $context);

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_POST_LIMITER:
                return self::runPostLimiterStep($stepRequest, $context);

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_SUMMARY:
                return self::runSummaryStep($stepRequest, $context);

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_SIZE_PROBE:
                return self::runSizeProbeStep($stepRequest, $context);

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_INERT:
                return self::runInertStep($stepRequest, $context);

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_COMPRESS_ON:
            case ABJ_404_Solution_AjaxCanaryLadder::STEP_COMPRESS_OFF:
                return self::runCompressionStep($stepRequest, $context);

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_STREAM:
                return self::runStreamStep($stepRequest, $context);

            case ABJ_404_Solution_AjaxCanaryLadder::STEP_INTERPRET:
                return self::runInterpretStep($stepRequest, $context);

            default:
                return array();
        }
    }

    /**
     * The browser session this request belongs to, or ''.
     *
     * One place rather than the three call sites that each re-derived it: the
     * context is a loose array, so every re-derivation is another chance to
     * disagree about what a non-scalar session id means.
     *
     * @param array<string, mixed> $context
     */
    private static function sessionIdFrom(array $context): string {
        $raw = $context['session_id'] ?? '';
        return is_scalar($raw) ? (string)$raw : '';
    }

    /**
     * The plain reference probe: a fixed-size filler payload and nothing else.
     *
     * Serves both `auth_only` (the ladder's floor: what an authenticated
     * round trip costs with no work in it) and `concurrent_control` (the same
     * payload, issued alongside a real request, to separate contention from
     * size).
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runFillerStep(array $stepRequest, array &$context): array {
        $requestId = $stepRequest['request_id'];
        $step = $stepRequest['step'];
        return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_' . $step,
            static function () use ($requestId, $step) {
                return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                    $requestId, $step,
                    ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES);
            });
    }

    /**
     * Repeated identical round trips, so run-to-run variance is measurable.
     *
     * The ordinal rides in the payload and is then paid for out of the filler,
     * so every repetition encodes to the same number of bytes as `auth_only`.
     * Without that trim the ordinal's own digits would make later repetitions
     * marginally larger than earlier ones, and the ladder would be reading its
     * own instrument as if it were the site.
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runBaselineControlStep(array $stepRequest, array &$context): array {
        $requestId = $stepRequest['request_id'];
        $rawOrdinal = $stepRequest['request_reader']->getPostOrGetSanitize('baselineOrdinal', '0');
        // Exact, not truncated: two repetitions sent as 1 and '1.9' would both
        // journal as ordinal 1 and the ladder would read one baseline sample
        // where the browser took two.
        $ordinal = min(20, ABJ_404_Solution_ExactInteger::readOr($rawOrdinal, 0, 0));
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
    }

    /**
     * The rate limiter's own cost, isolated from its enforcement.
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runPostLimiterStep(array $stepRequest, array &$context): array {
        $requestId = $stepRequest['request_id'];
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
    }

    /**
     * One real count query, to price the database independently of payload size.
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runSummaryStep(array $stepRequest, array &$context): array {
        $subpage = $stepRequest['subpage'];
        return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_summary',
            static function () use ($subpage) {
                /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
                $viewReadService = abj_service('view_read_service');
                $counts = $subpage === 'abj404_captured'
                    ? $viewReadService->getCapturedStatusCounts()
                    : $viewReadService->getRedirectStatusCounts();
                return array('summaryTotal' => (int)($counts['all'] ?? 0));
            });
    }

    /**
     * The size axis: a payload of a requested size, shape and rung.
     *
     * All four inputs are normalized before the stage opens, so a hostile or
     * malformed query string can only ever produce a payload inside the
     * ladder's declared bounds.
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runSizeProbeStep(array $stepRequest, array &$context): array {
        $requestId = $stepRequest['request_id'];
        $requestReader = $stepRequest['request_reader'];
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
    }

    /**
     * A payload of the same size as the size probe, built with no work at all.
     *
     * The control that separates "this size is slow to send" from "this size is
     * slow to BUILD".
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runInertStep(array $stepRequest, array &$context): array {
        $requestId = $stepRequest['request_id'];
        $bytes = ABJ_404_Solution_AjaxCanaryLadder::clampTargetBytes(
            $stepRequest['request_reader']->getPostOrGetSanitize('payloadBytes', ''));
        return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_inert',
            static function () use ($requestId, $bytes) {
                return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                    $requestId, ABJ_404_Solution_AjaxCanaryLadder::STEP_INERT, $bytes);
            });
    }

    /**
     * The same payload with compression asked for and then asked against.
     *
     * Serves both `compress_on` and `compress_off`; the `off` half actively
     * suppresses transformation so the two are genuinely different requests
     * rather than two names for whatever the host decided to do.
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runCompressionStep(array $stepRequest, array &$context): array {
        $requestId = $stepRequest['request_id'];
        $step = $stepRequest['step'];
        $bytes = ABJ_404_Solution_AjaxCanaryLadder::clampTargetBytes(
            $stepRequest['request_reader']->getPostOrGetSanitize('payloadBytes', ''));
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
                    ABJ_404_Solution_PhpRuntimeCapabilityAdapter::setIni(
                        array('directive' => 'zlib.output_compression', 'value' => '0'));
                }
                $payload = ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload($requestId, $step, $bytes);
                $payload['compressionMode'] = $step;
                return $payload;
            });
    }

    /**
     * The mid-response flush probe: the only step that puts bytes on the wire
     * before the JSON body exists.
     *
     * It decides whether a flush can reach the client at all on this host,
     * performs it, and journals findings about it under two different record
     * kinds -- substantially more than the single call each arm of
     * dispatchStep() makes, which is why it has its own method like every
     * other step.
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context Mutated in place by runStage().
     * @return array<string, mixed>
     */
    private static function runStreamStep(array $stepRequest, array &$context): array {
        $requestId = $stepRequest['request_id'];
        $obLevelBefore = isset($context['ob_level_before']) && is_numeric($context['ob_level_before'])
            ? (int)$context['ob_level_before'] : 0;
        $streamSessionKey = ABJ_404_Solution_AjaxRequestLedger::detachAbSessionKey(
            self::sessionIdFrom($context));
        return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_stream',
            static function () use ($requestId, $obLevelBefore, $streamSessionKey) {
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
                        ABJ_404_Solution_JsonResponseHead::emitEarly(200);
                    },
                    static function (callable $work, array $startFields) use ($requestId): void {
                        ABJ_404_Solution_AjaxCheckpointLogger::around(
                            $requestId, 'canary_stream_first_flush', $work, $startFields);
                    }
                );
                // The response declares the exact prefix it intentionally put
                // on the wire. The delivery analyzer then proves those bytes
                // arrived unchanged; a swallowed/coalesced flush becomes a
                // failed control, while every undeclared prefix stays corrupt.
                $flushOutcome['deliveryPrefix'] = array(
                    'bytes' => $flushOutcome['streamWhitespaceBytes'],
                    'byte' => ' ',
                );
                // The findings ride their own record rather than the
                // bracket's end record: a support payload is read
                // long after the run, and "did this step stream, and
                // if not why not" is the one thing that decides
                // whether its outcome is evidence about streaming at
                // all.
                //
                // The record names its OWN browser session, so
                // ABJ_404_Solution_ResponseBodyDeliveryEvidence can
                // scope it without waiting for the browser's receipt
                // for this step to arrive. Requiring the receipt would
                // make "did this step stream" depend on the very
                // delivery channel under diagnosis -- the same
                // cross-channel join that reported "no_receipts" over
                // a complete set of them on the 2026-08-27 Azure
                // capture. It rides the JOURNAL record only, not the
                // response payload: the browser already knows its own
                // session and the step stays byte-matched with its
                // siblings.
                ABJ_404_Solution_AjaxCheckpointLogger::record(
                    $requestId,
                    // The constant, not the literal it used to repeat: the
                    // reader below matches on this exact string, so a rename
                    // that missed one copy would silently split producer from
                    // consumer and the outcome would simply stop being found.
                    ABJ_404_Solution_BodyDeliveryObservations::STREAM_FLUSH_EVENT,
                    array_merge($flushOutcome, array('session_key' => $streamSessionKey)));
                return ABJ_404_Solution_AjaxCanaryLadder::buildFillerPayload(
                    $requestId, ABJ_404_Solution_AjaxCanaryLadder::STEP_STREAM,
                    ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES, $flushOutcome);
            });
    }

    /**
     * The ladder's closing step: parse the browser's observations, then hand
     * off to the verdict join.
     *
     * The verdicts themselves live in ABJ_404_Solution_CanaryLadderVerdicts,
     * which reads the durable journals and decides what both sides' records
     * mean together. This method's share is the request boundary -- bounding
     * and decoding the observations payload, and opening the trace stage the
     * join reports into.
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runInterpretStep(array $stepRequest, array &$context): array {
        $requestId = $stepRequest['request_id'];
        $requestReader = $stepRequest['request_reader'];
        $parsed = ABJ_404_Solution_RequestInputNormalizer::decodeBoundedJsonArray(array(
            'raw' => (string)$requestReader->getPostOrGetSanitize('observations', ''),
            'max_bytes' => self::MAX_INTERPRETATION_BYTES,
            'unavailable_label' => 'Interpretation unavailable: observations',
        ));
        $realFailed = (string)$requestReader->getPostOrGetSanitize('realRequestFailed', '1') !== '0';
        $sessionId = self::sessionIdFrom($context);

        return ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_interpret',
            static function () use ($parsed, $realFailed, $requestId, $sessionId) {
                return ABJ_404_Solution_CanaryLadderVerdicts::assemble(array(
                    'request_id' => $requestId,
                    'session_id' => $sessionId,
                    'parsed' => $parsed,
                    'real_request_failed' => $realFailed,
                ));
            });
    }

    /**
     * The size axis's calibration point, resolved by
     * ABJ_404_Solution_CanarySizeTarget.
     *
     * The only arm of the ladder that emits no payload and times no round
     * trip, so the decision it makes lives beside the two size sources it
     * arbitrates rather than among the probes that consume its answer.
     *
     * @param CanaryStepRequest $stepRequest
     * @param array<string, mixed> $context Mutated in place by the build's stages.
     * @return array<string, mixed>
     */
    private static function resolveSizeTarget(array $stepRequest, array &$context): array {
        return ABJ_404_Solution_CanarySizeTarget::resolve(array(
            'subpage' => $stepRequest['subpage'],
            'session_id' => self::sessionIdFrom($context),
        ), $context);
    }
}
