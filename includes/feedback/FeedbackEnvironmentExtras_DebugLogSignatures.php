<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug-log tail scanner + PII-stripping signature normalizer for the
 * feedback payload's `recent_error_signatures` field.
 *
 * Two responsibilities both anchored to the debug log:
 *   1. probeRecentErrorSignatures(): tail-read the plugin debug log
 *      (capped at 256 KB), parse lines matching the canonical
 *      "YYYY-MM-DD HH:MM:SS (LEVEL): ..." shape that Logging.php emits,
 *      keep only [ERROR]/[WARN] entries within the last 7 days, group
 *      by coarse signature, return the top 5 by count.
 *   2. normalizeErrorSignature(): the PII-stripping transform that
 *      makes the grouping correct. Strips absolute paths to basenames,
 *      collapses memory addresses, hex literals, multi-digit numbers,
 *      and whitespace runs so different incident timestamps and
 *      addresses fold into the same signature key. This is the unit
 *      pinned by tests/F6UrlFragmentLeakIntoErrorSignatureTest.
 *
 * Owned by ABJ_404_Solution_FeedbackEnvironmentExtras via composition;
 * see that class's collect() method for the recordProbe() wrapper that
 * converts a thrown scan failure into a recent_error_signatures_error
 * marker slug.
 */
class ABJ_404_Solution_FeedbackEnvironmentExtras_DebugLogSignatures {

    /**
     * Top distinct recurring error signatures from the plugin's debug
     * log file over the last 7 days, capped at 5 entries. The triggering
     * error is captured by the report itself ('error_signature' on the
     * payload); this probe captures the recurring error which is often
     * different and would never reach the email-on-first-error path.
     *
     * Bounded cost: reads the tail 256 KB of the debug file, parses
     * lines matching the canonical "YYYY-MM-DD HH:MM:SS (LEVEL): ..."
     * shape, keeps only [ERROR]/[WARN] entries within the last 7 days,
     * groups by a coarse signature (first 200 chars after the level),
     * keeps the top 5 by count. Returns an empty array on any read
     * failure.
     *
     * Shape:
     *   [ {signature: string, count: int, last_seen_at: int}, ... ]
     *
     * @return array<int, array<string, mixed>>
     */
    public function probeRecentErrorSignatures(): array {
        $out = array();
        $log = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (!is_object($log) || !method_exists($log, 'getDebugFilePath')) {
            return $out;
        }
        $path = (string)$log->getDebugFilePath();
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $out;
        }
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return $out;
        }
        $readBytes = 262144; // 256 KB
        $offset = $size > $readBytes ? $size - $readBytes : 0;
        $fh = @fopen($path, 'rb');
        if (!is_resource($fh)) {
            return $out;
        }
        $tail = '';
        try {
            if ($offset > 0) {
                @fseek($fh, $offset);
                // Discard the partial first line so we only group on whole records.
                @fgets($fh);
            }
            $chunk = @fread($fh, $readBytes);
            if (is_string($chunk)) {
                $tail = $chunk;
            }
        } finally {
            @fclose($fh);
        }
        if ($tail === '') {
            return $out;
        }
        $cutoff = abj_clock()->now() - 7 * 86400;
        $byKey = array();
        $lines = preg_split('/\r?\n/', $tail);
        if (!is_array($lines)) {
            return $out;
        }
        foreach ($lines as $line) {
            if (!is_string($line) || $line === '') { continue; }
            // Match "YYYY-MM-DD HH:MM:SS (LEVEL): tail..." per Logging.php format.
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \((ERROR|WARN)\):\s*(.*)$/', $line, $m)) {
                continue;
            }
            $ts = strtotime($m[1]);
            if ($ts === false || $ts < $cutoff) { continue; }
            $level = $m[2];
            $msg = trim($m[3]);
            if ($msg === '') { continue; }
            $sig = $level . ':' . substr($this->normalizeErrorSignature($msg), 0, 200);
            if (!isset($byKey[$sig])) {
                $byKey[$sig] = array('signature' => $sig, 'count' => 0, 'last_seen_at' => 0);
            }
            $byKey[$sig]['count']++;
            if ($ts > $byKey[$sig]['last_seen_at']) {
                $byKey[$sig]['last_seen_at'] = $ts;
            }
        }
        if (empty($byKey)) {
            return $out;
        }
        $list = array_values($byKey);
        usort($list, function ($a, $b) {
            $cmp = $b['count'] - $a['count'];
            if ($cmp !== 0) { return $cmp; }
            return $b['last_seen_at'] - $a['last_seen_at'];
        });
        return array_slice($list, 0, 5);
    }

    /**
     * Coarse-grain an error message so different incident timestamps,
     * memory addresses, file paths, and line numbers fold into the same
     * signature. Used by probeRecentErrorSignatures to group recurring
     * errors. Exposed (public) so the unit test
     * tests/F6UrlFragmentLeakIntoErrorSignatureTest can pin the
     * PII-stripping behavior directly.
     *
     * @param string $msg
     * @return string
     */
    public function normalizeErrorSignature(string $msg): string {
        $s = $msg;
        // Strip absolute paths to just the basename.
        $s = preg_replace('#/[A-Za-z0-9_\-\./]+/([A-Za-z0-9_\-]+\.php)#', '$1', $s) ?? $s;
        // Collapse memory addresses, hex, and digit sequences.
        $s = preg_replace('/\b0x[0-9a-fA-F]+\b/', '0xN', $s) ?? $s;
        $s = preg_replace('/\b\d{4,}\b/', 'N', $s) ?? $s;
        // Collapse runs of whitespace.
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}
