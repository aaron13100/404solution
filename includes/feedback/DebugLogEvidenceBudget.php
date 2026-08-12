<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised through public report builders in tests/FeedbackTransportSupportPayloadTest.php.

/**
 * Allocates the fixed debug-log wire budget between an error anchor and the
 * existing recent tail. It performs no file I/O; snapshots come from
 * ABJ_404_Solution_DebugLogReader and crash beacons may supply their own anchor.
 */
final class ABJ_404_Solution_DebugLogEvidenceBudget {

    const SCHEMA_VERSION = 1;

    /**
     * Shape one reader snapshot for FeedbackDiagnosticsCollector.
     *
     * @param array<string, mixed> $snapshot
     * @return array{debug_log: string, debug_log_evidence: array{schema_version: int,
     *     error_excerpt: string, error_excerpt_in_debug_log: bool,
     *     error_line_number: int, total_evidence_bytes: int}}
     */
    public static function fromSnapshot(array $snapshot): array {
        $tail = isset($snapshot['tail']) && is_string($snapshot['tail'])
            ? $snapshot['tail'] : '';
        $lineNumber = isset($snapshot['num']) && is_scalar($snapshot['num'])
            ? (int)$snapshot['num'] : -1;
        $anchor = '';
        $inTail = false;

        if ($lineNumber >= 0) {
            $fileSize = isset($snapshot['file_size']) && is_scalar($snapshot['file_size'])
                ? (int)$snapshot['file_size'] : strlen($tail);
            $tailStart = max(0, $fileSize - strlen($tail));
            $errorOffset = isset($snapshot['latest_error_offset']) && is_scalar($snapshot['latest_error_offset'])
                ? (int)$snapshot['latest_error_offset'] : -1;
            $inTail = $errorOffset >= $tailStart;
            if (!$inTail) {
                $context = isset($snapshot['error_context']) && is_string($snapshot['error_context'])
                    ? $snapshot['error_context'] : '';
                $contextStart = isset($snapshot['error_context_start']) && is_scalar($snapshot['error_context_start'])
                    ? (int)$snapshot['error_context_start'] : $errorOffset;
                $nonOverlappingBytes = max(0, $tailStart - $contextStart);
                $anchor = substr(
                    $context,
                    0,
                    min(ABJ_404_Solution_DebugLogReader::ERROR_EXCERPT_MAX_BYTES, $nonOverlappingBytes)
                );
                if ($anchor === '') {
                    $line = isset($snapshot['line']) && is_string($snapshot['line']) ? $snapshot['line'] : '';
                    $anchor = substr($line, 0, ABJ_404_Solution_DebugLogReader::ERROR_EXCERPT_MAX_BYTES);
                }
            }
        }

        return self::shape($tail, $anchor, $inTail, $lineNumber);
    }

    /**
     * Re-apply the invariant after type-specific extras override collected
     * fields. Crash beacons use this path to anchor their captured fatal error.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $payload): array {
        $tail = isset($payload['debug_log']) && is_string($payload['debug_log'])
            ? $payload['debug_log'] : '';
        $evidence = isset($payload['debug_log_evidence']) && is_array($payload['debug_log_evidence'])
            ? $payload['debug_log_evidence'] : array();
        $anchor = isset($evidence['error_excerpt']) && is_string($evidence['error_excerpt'])
            ? substr($evidence['error_excerpt'], 0, ABJ_404_Solution_DebugLogReader::ERROR_EXCERPT_MAX_BYTES)
            : '';
        $inTail = !empty($evidence['error_excerpt_in_debug_log']);
        if ($anchor !== '' && strpos($tail, $anchor) !== false) {
            $inTail = true;
        }
        $lineNumber = isset($evidence['error_line_number']) && is_scalar($evidence['error_line_number'])
            ? (int)$evidence['error_line_number'] : -1;
        $source = isset($evidence['source']) && is_string($evidence['source'])
            ? $evidence['source'] : 'debug_log';

        $shaped = self::shape($tail, $anchor, $inTail, $lineNumber);
        $payload['debug_log'] = $shaped['debug_log'];
        $payload['debug_log_evidence'] = $shaped['debug_log_evidence'];
        $payload['debug_log_evidence']['source'] = $source;
        return $payload;
    }

    /**
     * @return array{debug_log: string, debug_log_evidence: array{schema_version: int,
     *     error_excerpt: string, error_excerpt_in_debug_log: bool,
     *     error_line_number: int, total_evidence_bytes: int}}
     */
    public static function emptyEvidence(): array {
        return self::shape('', '', false, -1);
    }

    /**
     * @return array{debug_log: string, debug_log_evidence: array{schema_version: int,
     *     error_excerpt: string, error_excerpt_in_debug_log: bool,
     *     error_line_number: int, total_evidence_bytes: int}}
     */
    private static function shape(string $tail, string $anchor, bool $inTail, int $lineNumber): array {
        if ($inTail) {
            $anchor = '';
        }
        $tailBudget = ABJ_404_Solution_DebugLogReader::REPORT_EVIDENCE_MAX_BYTES - strlen($anchor);
        if (strlen($tail) > $tailBudget) {
            $tail = $tailBudget > 0 ? substr($tail, -$tailBudget) : '';
        }
        return array(
            'debug_log' => $tail,
            'debug_log_evidence' => array(
                'schema_version' => self::SCHEMA_VERSION,
                'error_excerpt' => $anchor,
                'error_excerpt_in_debug_log' => $inTail,
                'error_line_number' => $lineNumber,
                'total_evidence_bytes' => strlen($tail) + strlen($anchor),
            ),
        );
    }
}
