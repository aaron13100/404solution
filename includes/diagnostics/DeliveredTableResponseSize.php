<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How many bytes of an admin-table response actually REACHED the browser.
 *
 * The exact mirror of ABJ_404_Solution_EncodedTableResponseSize, which answers
 * the same question about the other end of the same response: that class says
 * how many bytes the server encoded, this one says how many the browser
 * measured arriving. Two modules rather than one because the two numbers come
 * from different subsystems, different record kinds and different journals'
 * worth of assumptions, and because either can be present without the other --
 * which is the whole finding when they disagree. Joining them is
 * ABJ_404_Solution_ResponseBodyDeliveryEvidence's job, not this class's.
 *
 * Read ONLY from Resource Timing's `decodedBodySize`, never from the client
 * report's own `bytes` field. That field is `responseText.length`, a UTF-16
 * CHARACTER count, and the table payload carries URLs that are not guaranteed
 * ASCII -- comparing a character count against the server's octet count would
 * manufacture a rewritten-in-transit verdict out of an accented redirect.
 * `rt` is also the first field the client trims out of an oversized report
 * (view_updater_transport_telemetry_delivery.js TRIM_FIELD_ORDER), so its
 * absence is ordinary and stays a named absence rather than a number.
 */
final class ABJ_404_Solution_DeliveredTableResponseSize {

    /**
     * What the browser said one table request weighed, or a named absence.
     *
     * Pure: it takes journal lines rather than reading them, so a report with
     * no timing entry, a trimmed report, and a report about a different
     * attempt are all directly assertable.
     *
     * The attempt a report is ABOUT is resolved through
     * ABJ_404_Solution_DiagnosticClientVerdict, the one extractor that already
     * maps a browser report to the journal key its attempt was recorded under.
     * Deriving a second keying here is exactly the defect that made the
     * browser's verdicts land on orphan placeholder groups for a whole
     * release.
     *
     * @param array<int, string> $lines Checkpoint JSONL lines, oldest first.
     * @param string $requestId Normalized ledger id of the table attempt.
     * @return array{bytes: int|null, source: string, resource_timing_state: string}
     */
    public static function forRequest(array $lines, string $requestId): array {
        $result = array(
            'bytes' => null,
            'source' => ABJ_404_Solution_MeasuredBodyBytes::SOURCE_UNAVAILABLE,
            'resource_timing_state' => ABJ_404_Solution_MeasuredBodyBytes::SOURCE_UNAVAILABLE,
        );
        if ($requestId === '') {
            return $result;
        }
        foreach ($lines as $line) {
            // Lines that cannot possibly match are rejected before the JSON
            // decoder sees them: this pass runs over whole journals.
            if (strpos($line, ABJ_404_Solution_DiagnosticClientVerdict::PRIOR_ATTEMPT_EVENT) === false) {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record)
                    || ABJ_404_Solution_DiagnosticClientVerdict::reportedOutcome($record)['id'] !== $requestId) {
                continue;
            }
            // Latest report wins: a retried attempt is reported more than once
            // and the later report describes the later delivery.
            $report = is_array($record['report'] ?? null) ? $record['report'] : array();
            $timing = is_array($report['rt'] ?? null) ? $report['rt'] : array();
            $bytes = ABJ_404_Solution_MeasuredBodyBytes::disclosed($timing['decodedBodySize'] ?? null);
            $state = $report['rtState'] ?? null;
            $result = array(
                'bytes' => $bytes,
                'source' => $bytes === null ? ABJ_404_Solution_MeasuredBodyBytes::SOURCE_UNAVAILABLE : ABJ_404_Solution_MeasuredBodyBytes::SOURCE_RESOURCE_TIMING,
                'resource_timing_state' => is_scalar($state)
                    ? (string)$state : ABJ_404_Solution_MeasuredBodyBytes::SOURCE_UNAVAILABLE,
            );
        }
        return $result;
    }

}
