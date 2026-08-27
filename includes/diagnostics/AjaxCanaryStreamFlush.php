<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * The canary ladder's mid-response flush: the only place in this plugin that
 * pushes response bytes out before the JSON body is built.
 *
 * Kept as its own unit rather than inlined in the `stream` step's closure
 * because the three things that make it correct -- emit the response head
 * before any body byte, only emit a whitespace block that can actually be
 * flushed, and report from OBSERVATION whether the flush crossed the SAPI
 * boundary -- are all directly assertable here, while the step's closure runs
 * only inside a fully authorized AJAX request.
 *
 * Why the SAPI check is headers_sent(): PHP exposes no "did that flush reach
 * the client" predicate. It does guarantee that the response head is
 * committed the moment any body byte crosses the SAPI boundary, so
 * headers_sent() flipping to true across the flush IS that observation, and
 * it is positive evidence rather than an inference from the buffer depth the
 * plan was computed from. The two are journaled side by side on purpose: a
 * plan that says "this will stream" together with reached_sapi=false is a
 * finding about the host, not a silent no-op.
 */
final class ABJ_404_Solution_AjaxCanaryStreamFlush {

    /**
     * Run the step's leading-whitespace block and mid-response flush under
     * the given plan.
     *
     * @param array{stream: bool, reason: string} $plan
     *   From ABJ_404_Solution_AjaxCanaryLadder::resolveStreamFlushPlan().
     * @param int $whitespaceBytes Size of the leading block when the plan streams.
     * @param callable $emitHead Commits the response head, invoked as
     *   $emitHead(): void and only on the streaming branch. Injected rather
     *   than called directly: emitting a response head is a presentation-layer
     *   concern (ABJ_404_Solution_AjaxResponseEmitter), and this unit is a
     *   diagnostic. What stays here is the INVARIANT -- head strictly before
     *   the first body byte -- which is the part that was wrong.
     * @param callable $bracket Journaling bracket, invoked as
     *   $bracket(callable $work, array $startFields): void. Separated from
     *   this unit so the flush itself has no journal dependency, and so the
     *   step keeps its existing canary_stream_first_flush_start/_end
     *   checkpoint pair. The observations this call produces come back in the
     *   RETURN value rather than through the bracket, so the caller journals
     *   them itself: ABJ_404_Solution_AjaxCheckpointLogger::around()'s
     *   by-reference end-fields parameter has no other production caller and
     *   is not carried through Patchwork's call rerouting under test, so
     *   building on it would put this step's only findings on a path nothing
     *   can assert.
     * @return array{streamFlushed: bool, streamFlushReason: string,
     *   streamFlushReachedSapi: bool, streamWhitespaceBytes: int,
     *   streamObLevelAfterFlush: int, streamHeadersSentBeforeFlush: bool}
     *   Reported back to the browser so a ladder run says on its face
     *   whether its streaming step actually streamed.
     */
    public static function emitAndFlush(
        array $plan,
        int $whitespaceBytes,
        callable $emitHead,
        callable $bracket
    ): array {
        $stream = !empty($plan['stream']);
        $reason = isset($plan['reason']) && is_string($plan['reason']) ? $plan['reason'] : '';
        $outcome = array(
            'streamFlushed' => $stream,
            'streamFlushReason' => $reason,
            'streamFlushReachedSapi' => false,
            'streamWhitespaceBytes' => $stream ? $whitespaceBytes : 0,
            'streamObLevelAfterFlush' => 0,
            'streamHeadersSentBeforeFlush' => false,
        );

        if ($stream) {
            // Before the first body byte, never after: the flush below
            // commits the response head, and once headers_sent() is true the
            // response emitter skips its own emission entirely, so a head not
            // sent here is never sent at all.
            $emitHead();
            echo str_repeat(' ', $whitespaceBytes);
        }

        // Sampled BEFORE the flush, and reported alongside the after-reading:
        // headers_sent() being true afterwards only proves OUR bytes crossed
        // the boundary if it was false beforehand. Another plugin that had
        // already committed the head earlier in the request would otherwise
        // make every run report a successful stream.
        $headersSentBefore = headers_sent();
        $outcome['streamHeadersSentBeforeFlush'] = $headersSentBefore;

        $bracket(
            static function () use ($stream, $headersSentBefore, &$outcome): void {
                if ($stream) {
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    @flush();
                }
                $outcome['streamFlushReachedSapi'] = $stream && !$headersSentBefore && headers_sent();
                $outcome['streamObLevelAfterFlush'] = ob_get_level();
            },
            array(
                'bytes' => $whitespaceBytes,
                'flushed' => $stream,
                'reason' => $reason,
                'ob_level' => ob_get_level(),
            )
        );

        return $outcome;
    }
}
