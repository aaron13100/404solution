<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';

/**
 * The sanitized debug-log tail for a report, or a statement of why there is
 * none.
 *
 * Three collectors (the support request, its preview, and the uninstall
 * feedback) each used to run the same fifteen lines: resolve the optional
 * logging service, call getSanitizedLogExcerptForSupport(), bound it, and
 * return '' from every branch where any of that did not work out. That empty
 * string is the same defect the collection manifest exists to end, one layer
 * up: a developer holding the report cannot tell "this site has no log" from
 * "the logging service was not registered" from "reading the log threw".
 * formatSnapshot() answers in words when the FILE is missing or unreadable
 * ("No log file available"); the older repeated collectors lost that fact.
 *
 * So the resolution lives here once, and every branch that yields no excerpt
 * yields a sentence instead. Three copies of a silent path could each be fixed
 * three times; one shared path can only be fixed once.
 */
final class ABJ_404_Solution_SupportLogExcerpt {

    /** Prefix every stated reason carries, so a reader can grep for them. */
    const REASON_PREFIX = '[404 Solution] Sanitized debug log unavailable: ';

    /** Bytes of an underlying exception message carried into the report. */
    const MAX_REASON_DETAIL_LENGTH = 200;

    /** Keeps the latest error header inside the 5 KB support preview. */
    const LATEST_ERROR_MAX_BYTES = 4096;

    /**
     * Present one DebugLogReader snapshot without performing another read.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function formatSnapshot(array $snapshot): string {
        $status = isset($snapshot['status']) && is_string($snapshot['status'])
            ? $snapshot['status'] : 'unreadable';
        if ($status === 'missing') {
            return 'No log file available';
        }
        if ($status !== 'ok') {
            return 'Log file not readable';
        }
        $rawEntries = isset($snapshot['error_entries']) && is_array($snapshot['error_entries'])
            ? $snapshot['error_entries'] : array();
        $entries = array();
        foreach ($rawEntries as $rawEntry) {
            if (is_array($rawEntry)) {
                $lines = array_values(array_filter($rawEntry, 'is_string'));
                if ($lines !== array()) {
                    $entries[] = $lines;
                }
            }
        }
        $rawRecent = isset($snapshot['recent_lines']) && is_array($snapshot['recent_lines'])
            ? $snapshot['recent_lines'] : array();
        $recent = array_values(array_filter($rawRecent, 'is_string'));
        if ($entries === array()) {
            if ($recent === array()) {
                return 'Log file is empty';
            }
            return trim('No ERROR/WARN entries found. Last ' . count($recent)
                . " log lines:\n\n" . implode('', $recent));
        }

        $output = 'Last ' . count($entries) . " ERROR/WARN entries:\n\n";
        foreach ($entries as $entry) {
            $output .= implode("\n", $entry) . "\n\n";
        }
        if ($recent !== array()) {
            $output .= 'Recent context (last ' . count($recent) . " lines):\n\n"
                . implode('', $recent);
        }
        $latest = isset($snapshot['line']) && is_string($snapshot['line']) ? $snapshot['line'] : '';
        if ($latest !== '') {
            $bounded = substr($latest, 0, self::LATEST_ERROR_MAX_BYTES);
            $output .= "\n\nLatest reportable ERROR evidence:\n\n" . $bounded;
            if (strlen($latest) > strlen($bounded)) {
                $output .= "\n[404 Solution] Latest ERROR evidence truncated to "
                    . self::LATEST_ERROR_MAX_BYTES . ' bytes.';
            }
        }
        return trim($output);
    }

    /**
     * The excerpt, tail-bounded, or a one-line reason.
     *
     * @param string $context Which collector is asking, for the warning log.
     * @param int $maxBytes Tail bound for the excerpt itself; 0 for unbounded.
     * @return string Never empty.
     */
    public static function resolve(string $context, int $maxBytes = 0): string {
        if (!function_exists('abj_service_optional')) {
            return self::REASON_PREFIX . 'the plugin service container is not loaded in this request.';
        }
        $logger = abj_service_optional('logging');
        if (!is_object($logger) || !method_exists($logger, 'getSanitizedLogExcerptForSupport')) {
            return self::REASON_PREFIX . 'the logging service is not available on this install.';
        }
        try {
            $excerpt = $logger->getSanitizedLogExcerptForSupport();
            if (!is_string($excerpt)) {
                return self::REASON_PREFIX . 'the logging service returned no readable excerpt.';
            }
            if ($excerpt === '') {
                return self::REASON_PREFIX . 'the log excerpt came back empty.';
            }
            return $maxBytes > 0 && strlen($excerpt) > $maxBytes
                ? substr($excerpt, -$maxBytes) : $excerpt;
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log(
                'warn',
                $context . ' debug-log excerpt unavailable: ' . $e->getMessage()
            );
            // The underlying message is carried, not just logged: the site that
            // hit this is the one site whose log we cannot read, so the debug
            // log is exactly where this explanation would NOT reach anyone.
            return self::REASON_PREFIX . 'reading it failed ('
                . substr($e->getMessage(), 0, self::MAX_REASON_DETAIL_LENGTH) . ').';
        }
    }
}
