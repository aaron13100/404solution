<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts untrusted browser canary receipts into bounded journal records.
 *
 * The parser owns the receipt wire contract only. It neither journals nor
 * interprets records, which keeps malformed input handling independent from
 * the endpoint and the ladder's diagnostic decision matrix.
 */
final class ABJ_404_Solution_AjaxCanaryReceiptParser {

    /**
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    public static function parse($raw): array {
        $text = is_scalar($raw) ? (string)$raw : '';
        if ($text === '') {
            return array();
        }
        $truncated = strlen($text) > ABJ_404_Solution_AjaxCanaryLadder::MAX_STEP_RECEIPTS_BYTES;
        $decoded = json_decode(substr(
            $text,
            0,
            ABJ_404_Solution_AjaxCanaryLadder::MAX_STEP_RECEIPTS_BYTES
        ), true);
        if (!is_array($decoded)) {
            return array(array(
                'decoded' => false,
                'json_error' => json_last_error_msg(),
                'raw_length' => strlen($text),
                'raw_head' => substr($text, 0, 200),
            ));
        }
        // A single receipt sent unwrapped is accepted as readily as a list:
        // an older or hand-modified client that sends one record is a
        // tolerable input, not a reason to discard the only evidence it had.
        if (array_key_exists('step', $decoded)) {
            $decoded = array($decoded);
        }
        $receipts = array();
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $receipts[] = self::normalizeReceipt($entry, $truncated);
            if (count($receipts) >= ABJ_404_Solution_AjaxCanaryLadder::MAX_STEP_RECEIPTS) {
                break;
            }
        }
        return $receipts;
    }

    /**
     * @param array<mixed, mixed> $entry
     * @return array<string, mixed>
     */
    private static function normalizeReceipt(array $entry, bool $truncated): array {
        $rawStep = isset($entry['step']) && is_scalar($entry['step']) ? (string)$entry['step'] : '';
        $known = $rawStep === ABJ_404_Solution_AjaxCanaryLadder::STEP_STATIC_ASSET
            || in_array($rawStep, ABJ_404_Solution_AjaxCanaryLadder::STEPS, true);
        $requestId = isset($entry['requestId']) && is_scalar($entry['requestId'])
            ? (string)$entry['requestId'] : '';
        if (preg_match('/^[a-zA-Z0-9]{1,64}$/', $requestId) !== 1) {
            $requestId = '';
        }
        $status = isset($entry['textStatus']) && is_scalar($entry['textStatus'])
            ? (string)$entry['textStatus'] : '';
        return array_merge(array(
            'decoded' => true,
            'step' => $known ? $rawStep : '',
            'reported_step' => substr(
                $rawStep,
                0,
                ABJ_404_Solution_AjaxCanaryLadder::MAX_REPORTED_STEP_CHARS
            ),
            'step_request_id' => $requestId,
            'ok' => !empty($entry['ok']),
            'ms' => isset($entry['ms']) && is_numeric($entry['ms']) ? (int)$entry['ms'] : null,
            'bytes' => isset($entry['bytes']) && is_numeric($entry['bytes']) ? (int)$entry['bytes'] : null,
            'text_status' => substr(
                $status,
                0,
                ABJ_404_Solution_AjaxCanaryLadder::MAX_TEXT_STATUS_CHARS
            ),
        ), self::transportEvidence($entry), self::payloadEvidence($entry), array(
            'truncated_on_arrival' => $truncated,
        ));
    }

    /**
     * @param array<mixed, mixed> $entry
     * @return array<string, mixed>
     */
    private static function transportEvidence(array $entry): array {
        $contentEncoding = isset($entry['contentEncoding']) && is_scalar($entry['contentEncoding'])
            ? (string)$entry['contentEncoding'] : '';
        $timingState = isset($entry['resourceTimingState']) && is_scalar($entry['resourceTimingState'])
            ? (string)$entry['resourceTimingState'] : '';
        return array(
            'content_encoding' => substr(
                $contentEncoding,
                0,
                ABJ_404_Solution_AjaxCanaryLadder::MAX_TEXT_STATUS_CHARS
            ),
            'transfer_bytes' => self::nonnegativeOrUnavailable($entry['transferBytes'] ?? null),
            'encoded_body_bytes' => self::nonnegativeOrUnavailable($entry['encodedBodyBytes'] ?? null),
            'decoded_body_bytes' => self::nonnegativeOrUnavailable($entry['decodedBodyBytes'] ?? null),
            'resource_timing_state' => in_array($timingState, array(
                'found', 'missing', 'unsupported', 'error',
            ), true) ? $timingState : 'unavailable',
        );
    }

    /**
     * @param array<mixed, mixed> $entry
     * @return array<string, mixed>
     */
    private static function payloadEvidence(array $entry): array {
        $variant = isset($entry['payloadVariant']) && is_scalar($entry['payloadVariant'])
            ? (string)$entry['payloadVariant'] : '';
        if (!in_array($variant, array(
            ABJ_404_Solution_AjaxCanaryLadder::PAYLOAD_VARIANT_COMPRESSIBLE,
            ABJ_404_Solution_AjaxCanaryLadder::PAYLOAD_VARIANT_INCOMPRESSIBLE,
        ), true)) {
            $variant = '';
        }
        $targetSource = isset($entry['targetBytesSource']) && is_scalar($entry['targetBytesSource'])
            ? (string)$entry['targetBytesSource'] : '';
        if (!in_array($targetSource, array(
            ABJ_404_Solution_AjaxCanaryLadder::TARGET_SOURCE_SESSION_JSON,
            ABJ_404_Solution_AjaxCanaryLadder::TARGET_SOURCE_BROWSER,
            ABJ_404_Solution_AjaxCanaryLadder::TARGET_SOURCE_DEFAULT,
        ), true)) {
            $targetSource = '';
        }
        return array(
            'payload_variant' => $variant,
            'payload_rung_percent' => isset($entry['payloadRungPercent'])
                && is_numeric($entry['payloadRungPercent'])
                ? max(-1, min(100, (int)$entry['payloadRungPercent'])) : -1,
            'target_bytes' => self::nonnegativeOrUnavailable($entry['targetBytes'] ?? null),
            'target_bytes_source' => $targetSource,
        );
    }

    /** @param mixed $value */
    private static function nonnegativeOrUnavailable($value): int {
        return is_numeric($value) ? max(-1, (int)$value) : -1;
    }
}
