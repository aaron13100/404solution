<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX failure-logging utilities used by the AJAX handler classes.
 */
class ABJ_404_Solution_AjaxFailureLogger {

    /** @var object|null */
    private $logger;

    /** @var callable(string, callable): mixed|null */
    private $operationTracer = null;

    /** @param object|null $logger */
    public function __construct($logger = null) {
        $this->logger = $logger;
    }

    /** @param callable(string, callable): mixed $operationTracer */
    public function setOperationTracer(callable $operationTracer): void {
        $this->operationTracer = $operationTracer;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function safeJsonEncode($value) {
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
    public function redactSqlShape($sql) {
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
    public function safeLogAjaxFailure($summary, $details = null, $throwable = null) {
        $line = $this->traceOperation(
            'line_construction',
            static fn() => date('c', abj_clock()->now()) . ' (ERROR): ' . $summary
        );
        if ($details !== null) {
            $encodedDetails = $this->traceOperation(
                'details_encoding',
                fn() => $this->safeJsonEncode($details)
            );
            $line .= ' Details: ' . $encodedDetails;
        }
        if ($throwable instanceof Throwable) {
            $line = $this->traceOperation(
                'exception_encoding',
                static fn() => $line . ' Exception: ' . $throwable->getMessage()
                    . ' @ ' . $throwable->getFile() . ':' . $throwable->getLine()
                    . ' Trace: ' . $throwable->getTraceAsString()
            );
        }

        // Always attempt to write to the plugin debug file.
        $logger = $this->resolveLogger();
        if (is_object($logger) && method_exists($logger, 'writeLineToDebugFile')) {
            try {
                $result = $this->traceOperation(
                    'logger_write_return',
                    static fn() => $logger->writeLineToDebugFile($line)
                );
                if ($result !== false) {
                    return;
                }
                $this->reportFailure('primary logger returned false');
            } catch (Throwable $error) {
                $this->reportFailure(
                    'primary logger threw ' . get_class($error) . ' code ' . (int)$error->getCode()
                );
            }
        }

        // Last-resort fallback (should be rare): write next to the plugin.
        // This ensures we still capture the error even if options/services are broken.
        if (is_object($logger) && method_exists($logger, 'sanitizeLogLine')) {
            try {
                $line = $this->traceOperation(
                    'fallback_sanitize',
                    static fn() => $logger->sanitizeLogLine($line)
                );
            } catch (Throwable $error) {
                $this->reportFailure(
                    'fallback sanitizer threw ' . get_class($error) . ' code '
                        . (int)$error->getCode()
                );
            }
        }
        $fallbackPath = $this->traceOperation(
            'fallback_path_resolution',
            static fn() => ABJ404_PATH . 'abj404_debug_fallback.txt'
        );
        $this->traceOperation(
            'fallback_write_flush_return',
            fn() => $this->writeFallbackLine($fallbackPath, (string)$line)
        );
    }

    /**
     * If the captured throwable is an ABJ_404_Solution_ViewQueryFailureException
     * (or a wrapped version of one), return its diagnostics payload. Otherwise
     * return null.
     *
     * @param Throwable $throwable
     * @return array<string, mixed>|null
     */
    public function extractViewQueryDiagnostics(Throwable $throwable) {
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

    /** @return object|null */
    private function resolveLogger() {
        if (is_object($this->logger)) {
            return $this->logger;
        }
        if (function_exists('abj_service_optional')) {
            $resolved = abj_service_optional('logging');
            return is_object($resolved) ? $resolved : null;
        }
        return null;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function traceOperation(string $operation, callable $work) {
        if (!is_callable($this->operationTracer)) {
            return $work();
        }
        return call_user_func($this->operationTracer, $operation, $work);
    }

    private function writeFallbackLine(string $path, string $line): bool {
        $this->clearLastError();
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            $this->reportFailure('fallback file could not be opened' . $this->lastErrorSuffix());
            return false;
        }
        try {
            $written = @fwrite($handle, $line . "\n");
            $flushed = @fflush($handle);
            if ($written !== strlen($line) + 1 || !$flushed) {
                $this->reportFailure(
                    'fallback file append/flush failed' . $this->lastErrorSuffix()
                );
                return false;
            }
            return true;
        } finally {
            @fclose($handle);
        }
    }

    private function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('ajax-failure-logger', $message);
        }
    }

    private function clearLastError(): void {
        if (function_exists('error_clear_last')) {
            error_clear_last();
        }
    }

    private function lastErrorSuffix(): string {
        $error = error_get_last();
        if (!is_array($error)) {
            return '';
        }
        return ': ' . (string)$error['message'];
    }
}
