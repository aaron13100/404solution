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
 *      "YYYY-MM-DD HH:MM:SS [TZ] (LEVEL): ..." shape that Logging.php emits,
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
     * lines matching the canonical "YYYY-MM-DD HH:MM:SS [TZ] (LEVEL): ..."
     * shape, keeps only [ERROR]/[WARN] entries within the last 7 days,
     * groups by a coarse signature (first 200 chars after the level),
     * keeps the top 5 by count. Returns an empty array on any read
     * failure.
     *
     * Shape:
     *   [ {signature: string, count: int, last_seen_at: int}, ... ]
     *
     * An absent, unreadable, or error-free log yields an empty array: on a
     * healthy site that is the truthful answer. A MISSING LOGGING SERVICE
     * throws instead, so the caller's recordProbe() wrapper writes a
     * `recent_error_signatures_error` marker. Those two cases used to be
     * indistinguishable in the payload -- the same "silently degrade to empty
     * after the thing I observe is refactored away" shape that left
     * `view_build_state` looking healthy-but-empty for seven weeks
     * (t_260801_071502_922). A probe that cannot look must never report
     * "nothing to see".
     *
     * @return array<int, array<string, mixed>>
     */
    public function probeRecentErrorSignatures(): array {
        $out = array();
        $log = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (!is_object($log) || !method_exists($log, 'getDebugFilePath')) {
            throw new \RuntimeException('logging service unavailable for recent_error_signatures probe');
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
            // Match "YYYY-MM-DD HH:MM:SS [TZ] (LEVEL): tail..." per
            // LogTimestampFormatter's actual 'Y-m-d H:i:s T' output (the
            // trailing timezone abbreviation/offset is optional in the
            // pattern so older or hand-edited log lines without one still
            // match).
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\s+\S+)?\s+\((ERROR|WARN)\):\s*(.*)$/', $line, $m)) {
                continue;
            }
            // The captured datetime is in the WP site's configured timezone
            // (LogTimestampFormatter's contract), not PHP's default timezone,
            // so it must be interpreted the same way here before comparing
            // against the true-UTC $cutoff -- otherwise a non-UTC site's
            // recent errors are silently miscounted as too old (or too new).
            try {
                $ts = (new DateTimeImmutable($m[1], ABJ_404_Solution_SiteTimezone::resolve()))->getTimestamp();
            } catch (Exception $e) {
                // The regex above validates digit *shape* (\d{4}-\d{2}-\d{2}
                // \d{2}:\d{2}:\d{2}) but not calendar validity, so a
                // corrupted or hand-edited log line (e.g. month 13) can still
                // reach here. Rare, but a run of these would indicate log
                // corruption or a LogTimestampFormatter regression, so make
                // it visible in diagnostics instead of dropping it with zero
                // trace. Delegated to a helper (rather than inlined here) to
                // keep this already-complex parsing loop's branch count from
                // growing further.
                $this->logUnparseableTimestamp($log, $m[1], $e);
                continue;
            }
            if ($ts < $cutoff) { continue; }
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
     * Report a log line whose timestamp matched the digit-shape regex but
     * failed to parse as a real calendar date/time (e.g. month 13). Debug
     * mode gated (via Logging::debugMessage()'s own contract) since this can
     * run once per matched line in the tail and a corrupted log could
     * otherwise flood the debug log on every probe call.
     *
     * @param object $log Already validated as an object with getDebugFilePath()
     *                     by the caller; debugMessage() itself is checked here
     *                     since not every logger double implements it.
     * @param string $rawTimestamp The unparseable captured group, for context.
     * @param \Exception $e
     * @return void
     */
    private function logUnparseableTimestamp($log, string $rawTimestamp, \Exception $e): void {
        if (method_exists($log, 'debugMessage')) {
            $log->debugMessage('FeedbackEnvironmentExtras_DebugLogSignatures: unparseable log timestamp "' .
                $rawTimestamp . '": ' . $e->getMessage());
        }
    }

    /**
     * Coarse-grain an error message so different incident timestamps,
     * memory addresses, file paths, and line numbers fold into the same
     * signature. Used by probeRecentErrorSignatures to group recurring
     * errors. Exposed (public) so the unit test
     * tests/F6UrlFragmentLeakIntoErrorSignatureTest can pin the
     * PII-stripping behavior directly.
     *
     * Mirrors ABJ_404_Solution_CrashBeacon::lightRedact() (the cheap
     * capture-time version run inside the fatal handler) and is also run
     * again on an already-lightRedact'd message at report time
     * (CrashBeaconReporter::buildSignature()), so the digit fold here must
     * use the same "bytes" exemption or the capture-time fix has no effect
     * on the final reported text. See lightRedact()'s docblock: a byte
     * count is never PII and is the memory_limit/allocation-size
     * diagnostic the crash-beacon feature exists to report. It is also
     * stable per site (memory_limit does not change between requests), so
     * exempting it does not hurt the grouping this function exists for.
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
        $s = preg_replace('/\b\d{4,}\b(?!\s*bytes\b)/i', 'N', $s) ?? $s;
        // Collapse runs of whitespace.
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}
