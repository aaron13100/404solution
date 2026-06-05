<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats and persists fatal-error diagnostics without leaking sensitive SQL values.
 */
class ABJ_404_Solution_ErrorDiagnosticsReporter {

    /**
     * @param mixed $value
     * @return string
     */
    public function safeJsonEncode($value): string {
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
    public function redactSqlShape($sql): string {
        if (!is_string($sql) || $sql === '') {
            return '';
        }

        $out = $sql;
        $out = preg_replace("~'(?:\\\\'|''|[^'])*'~", "?", $out) ?? $out;
        $out = preg_replace('~"(?:\\\\"|""|[^"])*"~', "?", $out) ?? $out;
        $out = preg_replace('~\\b0x[0-9A-Fa-f]+\\b~', '?', $out) ?? $out;
        $out = preg_replace('~\\b\\d+(?:\\.\\d+)?\\b~', '?', $out) ?? $out;
        $out = preg_replace('~\\(\\s*\\?\\s*(?:,\\s*\\?\\s*)+\\)~', '(?)', $out) ?? $out;
        $out = preg_replace('~\\bIN\\s*\\(\\?\\)\\b~i', 'IN (?)', $out) ?? $out;
        $out = preg_replace('~\\s+~', ' ', trim($out)) ?? $out;
        if (strlen($out) > 4000) {
            $out = substr($out, 0, 4000) . '...';
        }
        return $out;
    }

    /**
     * @param string $line
     * @return bool
     */
    public function writeLine(string $line): bool {
        $logger = abj_service('logging');
        if (is_object($logger) && method_exists($logger, 'writeLineToDebugFile')) {
            $logger->writeLineToDebugFile($line);
            return true;
        }
        if (is_object($logger) && method_exists($logger, 'sanitizeLogLine')) {
            $line = $logger->sanitizeLogLine($line);
        }
        @file_put_contents(ABJ404_PATH . 'abj404_debug_fallback.txt', $line . "\n", FILE_APPEND);
        return false;
    }
}
