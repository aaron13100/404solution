<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds bounded, exactly sized response bodies for canary ladder probes.
 *
 * Payload construction is separate from ladder interpretation because its
 * invariants are byte-level: hostile input cannot allocate beyond the cap,
 * paired variants have the same encoded size, and incompressible text must
 * remain deterministic, printable, and JSON-safe.
 */
final class ABJ_404_Solution_AjaxCanaryPayloadFactory {

    /**
     * Build one repeated baseline response without letting its ordinal alter
     * the encoded size.
     *
     * Fractional values fall back rather than truncating, because repetitions
     * sent as 1 and 1.9 must not journal as the same sample. The ordinal is
     * appended and then paid for out of the filler, preserving the established
     * response key order as well as the exact byte contract.
     *
     * @param mixed $rawOrdinal
     * @return array<string, mixed>
     */
    public static function buildBaselineControl(string $requestId, $rawOrdinal): array {
        $ordinal = min(20, ABJ_404_Solution_ExactInteger::readOr($rawOrdinal, 0, 0));
        $payload = self::buildFiller(
            $requestId,
            ABJ_404_Solution_AjaxCanaryLadder::STEP_BASELINE_CONTROL,
            ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES
        );
        $payload['baselineOrdinal'] = $ordinal;
        $excessBytes = max(
            0,
            self::encodedBytes($payload) - ABJ_404_Solution_AjaxCanaryLadder::AUTH_ONLY_BYTES
        );
        if ($excessBytes > 0) {
            $payload['filler'] = substr(
                $payload['filler'],
                0,
                max(0, strlen($payload['filler']) - $excessBytes)
            );
        }
        return $payload;
    }

    /**
     * Normalize all untrusted size-probe inputs before its timing stage opens.
     *
     * @param mixed $rawBytes
     * @param mixed $rawVariant
     * @param mixed $rawRungPercent
     * @param mixed $rawTargetSource
     * @return array{request_id: string, target_bytes: int, variant: string, rung_percent: int, target_source: string}
     */
    public static function normalizeSizeProbeOptions(
        string $requestId,
        $rawBytes,
        $rawVariant,
        $rawRungPercent,
        $rawTargetSource
    ): array {
        return array(
            'request_id' => $requestId,
            'target_bytes' => self::clampTargetBytes($rawBytes),
            'variant' => self::normalizeVariant($rawVariant),
            'rung_percent' => self::normalizeRungPercent($rawRungPercent),
            'target_source' => self::normalizeTargetSource($rawTargetSource),
        );
    }

    /** @param mixed $raw */
    public static function clampTargetBytes(
        $raw,
        int $default = ABJ_404_Solution_AjaxCanaryLadder::DEFAULT_INERT_BYTES
    ): int {
        $value = is_numeric($raw) ? (int)$raw : $default;
        if ($value <= 0) {
            $value = $default;
        }
        return max(
            ABJ_404_Solution_AjaxCanaryLadder::MIN_INERT_BYTES,
            min(ABJ_404_Solution_AjaxCanaryLadder::MAX_INERT_BYTES, $value)
        );
    }

    /** @param mixed $raw */
    public static function normalizeVariant($raw): string {
        $candidate = is_scalar($raw) ? (string)$raw : '';
        return $candidate === ABJ_404_Solution_AjaxCanaryLadder::PAYLOAD_VARIANT_INCOMPRESSIBLE
            ? ABJ_404_Solution_AjaxCanaryLadder::PAYLOAD_VARIANT_INCOMPRESSIBLE
            : ABJ_404_Solution_AjaxCanaryLadder::PAYLOAD_VARIANT_COMPRESSIBLE;
    }

    /** @param mixed $raw */
    public static function normalizeRungPercent($raw): int {
        return is_numeric($raw) ? max(1, min(100, (int)$raw)) : 100;
    }

    /** @param mixed $raw */
    public static function normalizeTargetSource($raw): string {
        $candidate = is_scalar($raw) ? (string)$raw : '';
        return in_array($candidate, array(
            ABJ_404_Solution_AjaxCanaryLadder::TARGET_SOURCE_SESSION_JSON,
            ABJ_404_Solution_AjaxCanaryLadder::TARGET_SOURCE_BROWSER,
            ABJ_404_Solution_AjaxCanaryLadder::TARGET_SOURCE_DEFAULT,
        ), true) ? $candidate : ABJ_404_Solution_AjaxCanaryLadder::TARGET_SOURCE_DEFAULT;
    }

    /**
     * @param array<string, mixed> $extraFields Step-specific fields carried by
     *   this response. Part of the envelope BEFORE the filler is sized, so a
     *   step that reports extra findings still lands on the same encoded byte
     *   count as its size-matched siblings -- the whole size ladder compares
     *   steps by response size, and a step that quietly ran larger than the
     *   one it is compared against would read as a size effect that is really
     *   just its own metadata.
     * @return array{requestId: string, canaryStep: string, filler: string, ...}
     */
    public static function buildFiller(
        string $requestId,
        string $step,
        int $targetBytes,
        array $extraFields = array()
    ): array {
        // Extras first, so the envelope's own three keys are always the
        // envelope's: a step's findings extend the payload, they never
        // redefine the identity every canary response is read by.
        $envelope = array_merge(
            $extraFields,
            array('requestId' => $requestId, 'canaryStep' => $step, 'filler' => '')
        );
        $overhead = self::envelopeOverheadBytes($envelope);
        $envelope['filler'] = str_repeat('a', max(0, $targetBytes - $overhead));
        return $envelope;
    }

    /**
     * @param array{request_id: string, target_bytes: int, variant: string,
     *   rung_percent: int, target_source: string} $options
     * @return array<string, mixed>
     */
    public static function buildVariant(array $options): array {
        $requestId = $options['request_id'];
        $targetBytes = self::clampTargetBytes($options['target_bytes']);
        $variant = self::normalizeVariant($options['variant']);
        $envelope = array(
            'requestId' => $requestId,
            'canaryStep' => ABJ_404_Solution_AjaxCanaryLadder::STEP_SIZE_PROBE,
            'filler' => '',
            'payloadVariant' => $variant,
            'payloadRungPercent' => self::normalizeRungPercent($options['rung_percent']),
            'targetBytes' => $targetBytes,
            'targetBytesSource' => self::normalizeTargetSource($options['target_source']),
        );
        $overhead = self::envelopeOverheadBytes($envelope);
        $fillerLength = max(0, $targetBytes - $overhead);
        $envelope['filler'] = $variant
            === ABJ_404_Solution_AjaxCanaryLadder::PAYLOAD_VARIANT_INCOMPRESSIBLE
            ? self::incompressibleText($requestId, $fillerLength)
            : str_repeat('a', $fillerLength);
        return $envelope;
    }

    /**
     * How many bytes the envelope costs before any filler is added.
     *
     * `strlen((string)json_encode($envelope))` used to compute this. On a
     * payload json_encode() cannot represent that cast turns false into '',
     * the overhead reads as 0, and the size probe silently ships a body larger
     * than the target it is supposed to be measuring -- so the one ladder step
     * whose entire job is calibrating response size reports a number that is
     * wrong in the direction that hides the problem. The encoder cannot return
     * false, so the count is always the real one.
     *
     * @param array<string, mixed> $envelope
     */
    private static function envelopeOverheadBytes(array $envelope): int {
        return self::encodedBytes($envelope);
    }

    /** @param array<string, mixed> $payload */
    private static function encodedBytes(array $payload): int {
        return strlen(ABJ_404_Solution_JsonResponseEncoder::encode($payload)->json());
    }

    private static function incompressibleText(string $seed, int $length): string {
        $text = '';
        for ($counter = 0; strlen($text) < $length; $counter++) {
            $text .= hash('sha256', $seed . '|' . $counter);
        }
        $result = substr($text, 0, $length);
        return is_string($result) ? $result : '';
    }
}
