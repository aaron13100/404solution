<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable decision for whether the stream probe can reach the SAPI.
 *
 * The reason is the stored state and shouldStream() is derived from it, so a
 * caller cannot construct a plan whose boolean contradicts its explanation.
 */
final class ABJ_404_Solution_AjaxCanaryStreamFlushPlan {

    /** The stack is unbuffered, so echo reaches the SAPI directly. */
    const REASON_UNBUFFERED = 'unbuffered_output';

    /** The plugin's managed buffer is the only buffer in the stack. */
    const REASON_PLUGIN_OWNS_ONLY_BUFFER = 'plugin_owns_the_only_buffer';

    /** A foreign buffer beneath the plugin catches ob_flush() output. */
    const REASON_FOREIGN_BUFFER_BELOW = 'foreign_output_buffer_below';

    /** A buffer above the plugin means the current top buffer is not ours. */
    const REASON_NOT_TOP_BUFFER = 'plugin_does_not_own_top_buffer';

    /** Output-buffer management was filtered off for this request. */
    const REASON_MANAGEMENT_OFF = 'output_buffer_management_off';

    /** @var string */
    private $reason;

    private function __construct(string $reason) {
        $this->reason = $reason;
    }

    /**
     * Derive one plan from an observed output-buffer stack.
     *
     * ob_flush() moves the current buffer into its parent, not necessarily to
     * the SAPI. A foreign buffer below the plugin therefore makes emitting a
     * leading block useless and corrupts the matched response comparison.
     *
     * The observation is keyed because the two adjacent buffer depths are
     * both integers. A transposed positional call stays type-correct while
     * answering the opposite question about ownership.
     *
     * @param array{manage_output_buffer: bool, ob_level_before: int, ob_level_now: int} $observation
     */
    public static function fromObservation(array $observation): self {
        $obLevelBefore = $observation['ob_level_before'];
        $obLevelNow = $observation['ob_level_now'];
        if (!$observation['manage_output_buffer']) {
            return new self(self::REASON_MANAGEMENT_OFF);
        }
        if ($obLevelNow <= 0) {
            return new self(self::REASON_UNBUFFERED);
        }
        if ($obLevelBefore <= 0 && $obLevelNow === 1) {
            return new self(self::REASON_PLUGIN_OWNS_ONLY_BUFFER);
        }
        if ($obLevelBefore > 0) {
            return new self(self::REASON_FOREIGN_BUFFER_BELOW);
        }
        return new self(self::REASON_NOT_TOP_BUFFER);
    }

    /** Whether this plan permits a mid-response flush. */
    public function shouldStream(): bool {
        return in_array($this->reason, array(
            self::REASON_UNBUFFERED,
            self::REASON_PLUGIN_OWNS_ONLY_BUFFER,
        ), true);
    }

    /** The observed reason that determines shouldStream(). */
    public function reason(): string {
        return $this->reason;
    }
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

    const WHITESPACE_BYTES = 2048;

    /**
     * Run the step's leading-whitespace block and mid-response flush under
     * the given plan.
     *
     * @param ABJ_404_Solution_AjaxCanaryStreamFlushPlan $plan
     *   Derived from the request's observed output-buffer stack.
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
        ABJ_404_Solution_AjaxCanaryStreamFlushPlan $plan,
        int $whitespaceBytes,
        callable $emitHead,
        callable $bracket
    ): array {
        $stream = $plan->shouldStream();
        $reason = $plan->reason();
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
