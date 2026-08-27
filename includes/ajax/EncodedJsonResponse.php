<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The outcome of encoding one AJAX response body: a JSON string that is always
 * a string, plus how much had to be given up to get one.
 *
 * A value object rather than a string return, because "what did we send" and
 * "did we have to degrade to send it" are two answers and the caller needs
 * both: the emitter echoes the first and journals the second. A bare string
 * return forces the caller to re-derive the degradation from json_last_error(),
 * which by then reflects whichever encode attempt ran last.
 *
 * The invariant this type exists to carry: json() is NEVER false. PHP's
 * json_encode() returns false on a payload it cannot represent, `echo false`
 * writes zero bytes, and an HTTP 200 with Content-type: application/json and an
 * empty body is what a browser reports as `parsererror` -- a table that never
 * renders, with nothing on the server naming why. Making the failure
 * unrepresentable in the type is what stops that shape coming back.
 *
 * @since 4.3.5
 */
final class ABJ_404_Solution_EncodedJsonResponse {

    /** json_encode() succeeded on the first, unmodified attempt. */
    const STRATEGY_DIRECT = 'direct';

    /** Re-encoded with JSON_INVALID_UTF8_SUBSTITUTE; malformed bytes became U+FFFD. */
    const STRATEGY_UTF8_SUBSTITUTED = 'utf8_substituted';

    /** Re-encoded with JSON_PARTIAL_OUTPUT_ON_ERROR; unrepresentable branches became null. */
    const STRATEGY_PARTIAL_OUTPUT = 'partial_output';

    /** Nothing encoded; json() carries a hand-built error envelope instead of the payload. */
    const STRATEGY_ERROR_ENVELOPE = 'error_envelope';

    /** @var string */
    private $json;

    /** @var string */
    private $strategy;

    /** @var int */
    private $errorCode;

    /** @var string */
    private $errorMessage;

    /**
     * @param string $json The bytes to echo. Always a string.
     * @param string $strategy One of the STRATEGY_* constants.
     * @param int $errorCode json_last_error() from the FIRST failing attempt, JSON_ERROR_NONE when none failed.
     * @param string $errorMessage json_last_error_msg() from that same attempt, '' when none failed.
     */
    public function __construct(string $json, string $strategy, int $errorCode = JSON_ERROR_NONE, string $errorMessage = '') {
        $this->json = $json;
        $this->strategy = $strategy;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    /** The response body. Never false, never null. */
    public function json(): string {
        return $this->json;
    }

    /** One of the STRATEGY_* constants. */
    public function strategy(): string {
        return $this->strategy;
    }

    /** Whether anything at all had to be given up to produce json(). */
    public function isDegraded(): bool {
        return $this->strategy !== self::STRATEGY_DIRECT;
    }

    /**
     * Whether the caller's payload reached the browser at all.
     *
     * Distinct from isDegraded(): a substituted or partial encode still carries
     * the table the user asked for, so the admin screen works and the event is
     * a warning. An error envelope does not, so the admin sees a real message
     * naming the real reason.
     */
    public function carriesPayload(): bool {
        return $this->strategy !== self::STRATEGY_ERROR_ENVELOPE;
    }

    /** json_last_error() from the first failing attempt; JSON_ERROR_NONE when nothing failed. */
    public function errorCode(): int {
        return $this->errorCode;
    }

    /** json_last_error_msg() from the first failing attempt; '' when nothing failed. */
    public function errorMessage(): string {
        return $this->errorMessage;
    }

    /**
     * The fields a diagnostic record carries about this encode.
     *
     * @return array<string, mixed>
     */
    public function diagnosticFields(): array {
        return array(
            'bytes' => strlen($this->json),
            'hash' => md5($this->json),
            'encode_strategy' => $this->strategy,
            'json_last_error' => $this->errorCode,
            'json_last_error_msg' => $this->errorMessage,
        );
    }
}
