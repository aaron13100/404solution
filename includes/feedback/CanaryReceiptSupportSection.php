<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders reconstructed canary-receipt evidence into the support payload.
 *
 * The diagnostic evidence class owns journal correlation and interpretation.
 * This class owns only the presentation boundary: a stable grep key, a compact
 * human header, and whole-record reduction that never cuts JSON mid-document.
 */
final class ABJ_404_Solution_CanaryReceiptSupportSection {

    /** Reallocated from the generic sanitized log tail and pinned by the budget test. */
    const MAX_CANARY_RECEIPT_INTERPRETATION_BYTES = 4096;

    /** Stable payload key for receipt-derived interpretation. */
    const INTERPRETATION_KEY = 'abj404_canary_receipt_interpretation';

    public static function compose(string $sessionId): string {
        if (!class_exists('ABJ_404_Solution_CanaryReceiptEvidence')) {
            return 'Canary receipt interpretation unavailable: ABJ_404_Solution_CanaryReceiptEvidence'
                . ' could not be loaded on this install.';
        }
        try {
            return self::render(ABJ_404_Solution_CanaryReceiptEvidence::forSession($sessionId));
        } catch (Throwable $e) {
            return 'Canary receipt interpretation could not be computed: '
                . substr($e->getMessage(), 0, 200);
        }
    }

    /** @param array<string, mixed> $record */
    private static function render(array $record): string {
        $status = self::textField($record, 'status');
        $header = 'Canary interpretation reconstructed from checkpoint receipts -- '
            . $status . " (JSON):\n";
        $minimal = array(
            'status' => $status,
            'source' => self::textField($record, 'source'),
            'session_key' => self::textField($record, 'session_key'),
            'plugin_version' => self::textField($record, 'plugin_version'),
            'missing_required_evidence' =>
                is_array($record['missing_required_evidence'] ?? null)
                    ? $record['missing_required_evidence'] : array(),
            'interpretation' => is_array($record['interpretation'] ?? null)
                ? $record['interpretation'] : null,
            'reduced' => 'over_budget',
        );
        foreach (array($record, $minimal) as $candidate) {
            $line = json_encode(array(self::INTERPRETATION_KEY => $candidate));
            if (is_string($line)
                    && strlen($header) + strlen($line)
                        <= self::MAX_CANARY_RECEIPT_INTERPRETATION_BYTES) {
                return $header . $line;
            }
        }
        return $header . 'The receipt interpretation record could not be encoded for this payload.';
    }

    /** @param array<array-key, mixed> $record */
    private static function textField(array $record, string $field): string {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (string)$value : '';
    }
}
