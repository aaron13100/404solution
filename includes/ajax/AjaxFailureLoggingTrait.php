<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX failure-logging utilities used by the AJAX handler classes.
 *
 * Four pure static helpers, all callable as `self::method()` from any class
 * that composes this trait:
 *
 *   - safeJsonEncode: json_encode wrapper that handles encoding failures so
 *     a malformed payload can never throw inside the failure path itself.
 *   - redactSqlShape: collapse a $wpdb->last_query value into a placeholder
 *     shape (numbers + quoted strings becomes "?") for safe logging.
 *   - safeLogAjaxFailure: write a single error line to the plugin debug log
 *     with summary + details + throwable trace, with a fallback path that
 *     writes next to the plugin file when logging services are unavailable.
 *   - extractViewQueryDiagnostics: walk an exception chain looking for an
 *     ABJ_404_Solution_ViewQueryFailureException and return its diagnostics
 *     payload (table counts, indexes, EXPLAIN, etc.) for the AJAX response.
 *
 * Composed into ABJ_404_Solution_ViewUpdater. No state of its own; methods
 * are static and use only globals (\$GLOBALS['abj404_ajax_context'] is read
 * by callers, not by these helpers directly) plus the plugin logging service.
 */
trait ABJ_404_Solution_AjaxFailureLoggingTrait {

    /**
     * @param mixed $value
     * @return string
     */
    private static function safeJsonEncode($value) {
        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false) {
            return '(json_encode failed) ' . print_r($value, true);
        }
        return $encoded;
    }

    /**
     * @param mixed $sql
     * @return string
     */
    private static function redactSqlShape($sql) {
        if (!is_string($sql) || $sql === '') {
            return '';
        }

        $out = $sql;

        // Replace quoted strings (single and double quotes) with placeholders.
        // Note: $wpdb->last_query is a final SQL string and may contain user input values.
        $out = preg_replace("~'(?:\\\\'|''|[^'])*'~", "?", $out) ?? $out;
        $out = preg_replace('~"(?:\\\\"|""|[^"])*"~', "?", $out) ?? $out;

        // Replace hex literals and numbers.
        $out = preg_replace('~\\b0x[0-9A-Fa-f]+\\b~', '?', $out) ?? $out;
        $out = preg_replace('~\\b\\d+(?:\\.\\d+)?\\b~', '?', $out) ?? $out;

        // Collapse long IN (...) / value lists to a single placeholder.
        $out = preg_replace('~\\(\\s*\\?\\s*(?:,\\s*\\?\\s*)+\\)~', '(?)', $out) ?? $out;
        $out = preg_replace('~\\bIN\\s*\\(\\?\\)\\b~i', 'IN (?)', $out) ?? $out;

        // Normalize whitespace and cap length (shape only).
        $out = preg_replace('~\\s+~', ' ', trim($out)) ?? $out;
        if (strlen($out) > 4000) {
            // allow-em-dash: 1-char ellipsis as truncation marker (existing convention)
            $out = substr($out, 0, 4000) . '…';
        }
        return $out;
    }

    /**
     * @param string $summary
     * @param mixed $details
     * @param \Throwable|null $throwable
     * @return void
     */
    private static function safeLogAjaxFailure($summary, $details = null, $throwable = null) {
        $line = date('c') . ' (ERROR): ' . $summary;
        if ($details !== null) {
            $line .= ' Details: ' . self::safeJsonEncode($details);
        }
        if ($throwable instanceof Throwable) {
            $line .= ' Exception: ' . $throwable->getMessage() . ' @ ' . $throwable->getFile() . ':' . $throwable->getLine() .
                ' Trace: ' . $throwable->getTraceAsString();
        }

        // Always attempt to write to the plugin debug file.
        $logger = abj_service('logging');
        if (is_object($logger) && method_exists($logger, 'writeLineToDebugFile')) {
            $logger->writeLineToDebugFile($line);
            return;
        }

        // Last-resort fallback (should be rare): write next to the plugin.
        // This ensures we still capture the error even if options/services are broken.
        if (is_object($logger) && method_exists($logger, 'sanitizeLogLine')) {
            $line = $logger->sanitizeLogLine($line);
        }
        @file_put_contents(ABJ404_PATH . 'abj404_debug_fallback.txt', $line . "\n", FILE_APPEND);
    }

    /**
     * If the captured throwable is an ABJ_404_Solution_ViewQueryFailureException
     * (or a wrapped version of one), return its diagnostics payload. Otherwise
     * return null. Used by the AJAX error handlers to surface getRedirectsForView /
     * getRedirectsForViewCount diagnostics (table counts, engine, indexes,
     * canonical_url state, EXPLAIN, db_version, etc.) to plugin admins and the
     * debug log without a follow-up debug zip.
     *
     * @param Throwable $throwable
     * @return array<string, mixed>|null
     */
    private static function extractViewQueryDiagnostics(Throwable $throwable) {
        $current = $throwable;
        $depth = 0;
        while ($current !== null && $depth < 5) {
            if ($current instanceof ABJ_404_Solution_ViewQueryFailureException) {
                return $current->getDiagnostics();
            }
            $current = $current->getPrevious();
            $depth++;
        }
        return null;
    }
}
