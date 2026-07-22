<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Independent, minimal JSONL append-only logger for AJAX request checkpoints.
 *
 * Deliberately separate from ABJ_404_Solution_AjaxRequestTrace: every record
 * opens, locks, appends, flushes, unlocks, and closes the file immediately.
 * There is no pending/promotion state machine and no in-memory batching, so
 * a bug in the trace class under test (a stuck pending file, a rotation
 * failure, a construction exception) cannot erase this evidence. It is also
 * the channel the trace's own per-request self-test sentinel journals
 * through (see runSelfTest()), so a bug in the trace journal itself cannot
 * hide the sentinel's result.
 *
 * Every public method is failure-safe: it never lets an internal write
 * failure escape as an exception. around() re-throws only the wrapped
 * work's own exception, never a logging failure.
 */
final class ABJ_404_Solution_AjaxCheckpointLogger {

    const CHECKPOINT_FILE = 'abj404_ajax_checkpoints.jsonl';
    const ROTATED_FILE = 'abj404_ajax_checkpoints.old.jsonl';
    const LOCK_FILE = 'abj404_ajax_checkpoints.lock';
    const MAX_CHECKPOINT_BYTES = 524288;
    const MAX_SUPPORT_EXCERPT_BYTES = 32768;
    const SCHEMA_VERSION = 1;

    /**
     * Resolve the same directory ABJ_404_Solution_AjaxRequestTrace uses, via
     * the same filter, so checkpoints and the trace journal live side by
     * side and share one support-payload excerpt. Resolved independently
     * (not delegated to the trace class) so a bug there cannot take this
     * down too.
     *
     * @return string Empty string when unavailable.
     */
    public static function resolveDirectory(): string {
        try {
            $directory = function_exists('abj404_getUploadsDir') ? abj404_getUploadsDir() : '';
            if (function_exists('apply_filters')) {
                $directory = (string)apply_filters('abj404_ajax_trace_directory', $directory, array());
            }
            if ($directory === '') {
                return '';
            }
            $directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
            if (!class_exists('ABJ_404_Solution_FileSystemService')
                    || !ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                return '';
            }
            return $directory;
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint directory resolution failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Append one checkpoint record. Never throws.
     *
     * @param array<string, mixed> $fields
     */
    public static function record(string $requestId, string $event, array $fields = array()): void {
        try {
            $directory = self::resolveDirectory();
            if ($directory === '') {
                return;
            }
            self::writeRecord($directory, array_merge(self::envelope($requestId, $event), $fields));
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint record failed: ' . $e->getMessage());
        }
    }

    /**
     * Record a checkpoint pair (`${label}_start` / `${label}_end`) around a
     * unit of work and return its result. The end record always fires (a
     * finally block), and always carries elapsed_ms and status; the work's
     * own exception (if any) propagates to the caller unchanged.
     *
     * @template T
     * @param callable():T $work
     * @param array<string, mixed> $startFields
     * @return T
     */
    public static function around(string $requestId, string $label, callable $work, array $startFields = array()) {
        $startedAt = microtime(true);
        self::record($requestId, $label . '_start', $startFields);
        $status = 'complete';
        try {
            return $work();
        } catch (Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            self::record($requestId, $label . '_end', array(
                'status' => $status,
                'elapsed_ms' => max(0, (int)round((microtime(true) - $startedAt) * 1000)),
            ));
        }
    }

    /**
     * Per-request trace self-test sentinel: create, append, flush, stat,
     * glob, read, and delete a request-specific file, journaling every
     * step's outcome through this independent channel. Proves the same
     * directory the trace journal depends on is actually writable and
     * readable for THIS request, not just that a directory path resolved.
     * Never throws.
     */
    public static function runSelfTest(string $requestId): void {
        try {
            $directory = self::resolveDirectory();
            if ($directory === '') {
                self::record($requestId, 'selftest', array('ok' => false, 'step' => 'resolve_directory'));
                return;
            }
            $path = $directory . 'abj404_checkpoint_selftest_' . $requestId . '_' . getmypid() . '.tmp';
            $payload = 'abj404-selftest-' . $requestId;

            $handle = @fopen($path, 'wb');
            if ($handle === false) {
                self::record($requestId, 'selftest', array('ok' => false, 'step' => 'create'));
                return;
            }
            $written = @fwrite($handle, $payload);
            $flushed = @fflush($handle);
            @fclose($handle);
            if ($written === false || !$flushed) {
                self::record($requestId, 'selftest', array('ok' => false, 'step' => 'append_flush'));
                return;
            }

            $size = @filesize($path);
            if (!is_int($size) || $size !== strlen($payload)) {
                self::record($requestId, 'selftest', array('ok' => false, 'step' => 'stat', 'size' => $size));
                return;
            }

            $globMatches = @glob($directory . 'abj404_checkpoint_selftest_*');
            $globCount = is_array($globMatches) ? count($globMatches) : 0;
            if ($globCount < 1) {
                self::record($requestId, 'selftest', array('ok' => false, 'step' => 'glob', 'glob_count' => $globCount));
                return;
            }

            $readBack = @file_get_contents($path);
            if ($readBack !== $payload) {
                self::record($requestId, 'selftest', array('ok' => false, 'step' => 'read'));
                return;
            }

            $deleted = @unlink($path);
            if (!$deleted) {
                self::record($requestId, 'selftest', array('ok' => false, 'step' => 'delete'));
                return;
            }

            self::record($requestId, 'selftest', array('ok' => true, 'step' => 'complete', 'glob_count' => $globCount));
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint self-test failed: ' . $e->getMessage());
            self::record($requestId, 'selftest', array('ok' => false, 'step' => 'exception', 'message' => substr($e->getMessage(), 0, 200)));
        }
    }

    /**
     * Bounded recent checkpoint lines for the support-request payload.
     *
     * Without this the checkpoints are written and never read by anyone: the
     * support payload carried only the stage trace, so a request that died
     * BEFORE its first stage -- the exact beta.1 failure -- reached the
     * developer as an empty excerpt. Every pre-stage boundary (auth, rate
     * limit, trace construction, service resolution) and every post-stage
     * boundary (encode, echo, each ob close, flush, finish-request, exit) is
     * recorded only here, so this is the channel that makes "nothing after
     * authorized" a readable fact instead of an absence.
     *
     * The rotated file is included: a session busy enough to rotate is a
     * session whose oldest evidence is still the most interesting.
     */
    public static function readRecentForSupport(): string {
        $directory = self::resolveDirectory();
        if ($directory === '') {
            return '';
        }
        return ABJ_404_Solution_DiagnosticJournalExcerpt::compose(
            array($directory . self::ROTATED_FILE, $directory . self::CHECKPOINT_FILE),
            self::MAX_SUPPORT_EXCERPT_BYTES,
            "Recent AJAX request checkpoints (JSONL):\n"
        );
    }

    /** @return array<string, mixed> */
    private static function envelope(string $requestId, string $event): array {
        $rusage = function_exists('getrusage') ? getrusage() : null;
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'ts' => microtime(true),
            'hrtime_ns' => function_exists('hrtime') ? hrtime(true) : null,
            'rusage' => is_array($rusage) ? $rusage : null,
            'request_id' => $requestId,
            'event' => $event,
            'pid' => getmypid(),
        );
    }

    /** @param array<string, mixed> $record */
    private static function writeRecord(string $directory, array $record): void {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            self::reportFailure('AJAX checkpoint JSON encoding failed.');
            return;
        }
        $line = $json . "\n";
        $path = $directory . self::CHECKPOINT_FILE;

        $lock = @fopen($directory . self::LOCK_FILE, 'cb');
        if ($lock === false) {
            self::reportFailure('AJAX checkpoint lock file could not be opened: ' . $directory . self::LOCK_FILE);
            return;
        }
        try {
            if (!@flock($lock, LOCK_EX)) {
                self::reportFailure('AJAX checkpoint lock failed: ' . $path);
                return;
            }
            $size = @filesize($path);
            if (is_int($size) && ($size + strlen($line)) > self::MAX_CHECKPOINT_BYTES) {
                $old = $directory . self::ROTATED_FILE;
                if (@is_file($old)) {
                    @unlink($old);
                }
                if (@is_file($path)) {
                    @rename($path, $old);
                }
            }
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                self::reportFailure('AJAX checkpoint file could not be opened: ' . $path);
                return;
            }
            $writeOk = false;
            try {
                $written = @fwrite($handle, $line);
                $flushed = @fflush($handle);
                $writeOk = $written !== false && $flushed;
            } finally {
                @fclose($handle);
            }
            if (!$writeOk) {
                self::reportFailure('AJAX checkpoint append/flush failed: ' . $path);
            }
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private static function reportFailure(string $message): void {
        // Unconditional: abj404_logPhpFallback() is defined at plugin entry
        // (404-solution.php), before any class here can be autoloaded, so a raw
        // error_log() second sink was unreachable and made this file an
        // offender in the centralized-error-log audit.
        abj404_logPhpFallback('ajax-checkpoint', $message);
    }
}
