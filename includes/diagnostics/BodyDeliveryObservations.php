<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the checkpoint journal actually says, as three typed maps.
 *
 * The boundary between a site-wide JSONL file and the emitted-against-
 * delivered comparison built on top of it. Every record here arrived as text
 * written by an older plugin version, a different browser, another admin tab,
 * or a write that was cut off mid-line, so the single question this class
 * exists to answer is which fields can be READ and which cannot -- before any
 * of them reach a causal verdict.
 *
 * That question has teeth. `streamFlushReachedSapi` and
 * `streamWhitespaceBytes` both feed
 * ABJ_404_Solution_ResponseBodyRewriteVerdict, and each has a value that is a
 * real, consequential observation (`false` = this host cannot stream; `0` = the
 * step put no prefix on the wire) sitting immediately next to the value that
 * means the record did not say. Coercion between those two is not a rounding
 * error, it is a fabricated finding about an intermediary that did nothing,
 * which is exactly the misreading that cost the 2026-08-27 Azure report a
 * week. So the rules below accept only what each field is DEFINED to carry and
 * answer null for everything else, rather than taking the nearest plausible
 * interpretation.
 *
 * Pure: it takes lines rather than reading them, so a truncated record, a
 * foreign session's receipts and a field that changed meaning between versions
 * are all directly assertable without a journal on disk that happens to
 * contain one.
 */
final class ABJ_404_Solution_BodyDeliveryObservations {

    /** The browser's per-step receipt, written by ABJ_404_Solution_Ajax_CanaryLadder. */
    const RECEIPT_EVENT = 'canary_step_client_receipt';

    /** The post-encode size record written by ABJ_404_Solution_AjaxResponseEmitter. */
    const ENCODE_EVENT = 'json_encode';

    /** The `stream` step's own flush findings, written by ABJ_404_Solution_AjaxCanaryStepRunner. */
    const STREAM_FLUSH_EVENT = 'canary_stream_flush_outcome';

    /**
     * The latest receipt this session's browser filed for each ladder step.
     *
     * Latest wins: a step the client retried reports twice, and the run the
     * `interpret` step is closing is the later one.
     *
     * @param array<int, string> $lines
     * @return array<string, array{request_id: string, delivered_bytes: int|null,
     *   resource_timing_state: string}>
     */
    public static function receiptsInSession(array $lines, string $sessionKey): array {
        $receipts = array();
        foreach ($lines as $line) {
            if ($sessionKey === '' || strpos($line, self::RECEIPT_EVENT) === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record) || ($record['event'] ?? '') !== self::RECEIPT_EVENT
                    || self::scalarField($record, 'session_key') !== $sessionKey) {
                continue;
            }
            $step = self::scalarField($record, 'step');
            $requestId = ABJ_404_Solution_AjaxRequestLedger::normalizeId(
                $record['step_request_id'] ?? null, '');
            if ($step === '' || $requestId === ''
                    || !in_array($step, ABJ_404_Solution_AjaxCanaryLadder::STEPS, true)) {
                continue;
            }
            $receipts[$step] = array(
                'request_id' => $requestId,
                'delivered_bytes' => ABJ_404_Solution_MeasuredBodyBytes::disclosed($record['decoded_body_bytes'] ?? null),
                'resource_timing_state' => self::scalarField($record, 'resource_timing_state'),
            );
        }
        return $receipts;
    }

    /**
     * Encoded response size by request id, latest write per id.
     *
     * @param array<int, string> $lines
     * @return array<string, int>
     */
    public static function encodedSizesIn(array $lines): array {
        $sizes = array();
        foreach ($lines as $line) {
            if (strpos($line, '"event":"' . self::ENCODE_EVENT . '"') === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record) || ($record['event'] ?? '') !== self::ENCODE_EVENT) {
                continue;
            }
            $requestId = self::scalarField($record, 'request_id');
            $bytes = ABJ_404_Solution_MeasuredBodyBytes::disclosed($record['bytes'] ?? null);
            if ($requestId === '' || $bytes === null) {
                continue;
            }
            $sizes[$requestId] = $bytes;
        }
        return $sizes;
    }

    /**
     * The `stream` step's own flush findings by request id.
     *
     * Both fields matter to a different consumer: `whitespace_bytes` corrects
     * the emitted count, and `reached_sapi` is what lets
     * `streamingBufferCausal` require that the streaming step actually
     * streamed instead of assuming it did.
     *
     * @param array<int, string> $lines
     * @return array<string, array{reached_sapi: bool|null, whitespace_bytes: int|null, session_key: string}>
     */
    public static function streamFlushOutcomesIn(array $lines): array {
        $outcomes = array();
        foreach ($lines as $line) {
            if (strpos($line, self::STREAM_FLUSH_EVENT) === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record) || ($record['event'] ?? '') !== self::STREAM_FLUSH_EVENT) {
                continue;
            }
            $requestId = self::scalarField($record, 'request_id');
            if ($requestId === '') {
                continue;
            }
            // Absent or unreadable fields become NULL, never false and never
            // 0. Both feed causal verdicts, so collapsing "the record does not
            // say" into "the flush did not reach the SAPI", or into "no
            // whitespace was emitted", manufactures an observation out of a
            // record this code could not read. An understated emitted count in
            // particular is the exact signature this class reports as a body
            // rewritten in transit.
            $outcomes[$requestId] = array(
                'reached_sapi' => self::flushReachedSapi(
                    $record['streamFlushReachedSapi'] ?? null),
                'whitespace_bytes' => self::whitespaceByteCount(
                    $record['streamWhitespaceBytes'] ?? null),
                'session_key' => self::scalarField($record, 'session_key'),
            );
        }
        return $outcomes;
    }

    /**
     * Did the flush reach the SAPI, or does the record not readably say?
     *
     * Only the values the field is DEFINED to carry are answers: a real
     * boolean, or 0/1 as some JSON encoders render one. Testing `=== 1` over
     * anything numeric is not a guard, because it answers `false` for every
     * other number -- a 2 from a field that changed meaning, a -1 sentinel, a
     * truncated write -- and `false` here is the positive claim that the host
     * could not stream. That is the same manufactured observation the absence
     * check above exists to prevent, arriving by a different route.
     *
     * @param mixed $value
     */
    private static function flushReachedSapi($value): ?bool {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        return null;
    }

    /**
     * How many whitespace bytes the `stream` step put on the wire ahead of the
     * JSON, or null when the record does not readably say.
     *
     * A negative value is refused rather than clamped to zero. Zero is a real
     * and consequential observation -- the step emitted no prefix, so the
     * encoded size IS the whole body -- and reaching it by clamping a sentinel
     * understates the emitted total by the prefix length. Understated emitted
     * against a real delivered count is precisely what this class reports as a
     * body rewritten in transit, so the clamp could invent an intermediary out
     * of a number the journal never meant as a count. Fractions are refused
     * for the same reason: a byte count that is not an integer did not come
     * from the counter this field is fed by.
     *
     * @param mixed $value
     */
    private static function whitespaceByteCount($value): ?int {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            return (int)$value;
        }
        return null;
    }

    /**
     * One record field as a string, or '' when it is absent or not scalar.
     *
     * @param array<array-key, mixed> $record
     */
    private static function scalarField(array $record, string $field): string {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (string)$value : '';
    }
}
