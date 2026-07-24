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
     * @return array{requestId: string, canaryStep: string, filler: string}
     */
    public static function buildFiller(string $requestId, string $step, int $targetBytes): array {
        $envelope = array('requestId' => $requestId, 'canaryStep' => $step, 'filler' => '');
        $overhead = strlen((string)json_encode($envelope));
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
        $overhead = strlen((string)json_encode($envelope));
        $fillerLength = max(0, $targetBytes - $overhead);
        $envelope['filler'] = $variant
            === ABJ_404_Solution_AjaxCanaryLadder::PAYLOAD_VARIANT_INCOMPRESSIBLE
            ? self::incompressibleText($requestId, $fillerLength)
            : str_repeat('a', $fillerLength);
        return $envelope;
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
