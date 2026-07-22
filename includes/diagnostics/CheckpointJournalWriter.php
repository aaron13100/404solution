<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists one checkpoint record with bounded lock acquisition.
 *
 * A held filesystem lock must never manufacture the timeout this diagnostic
 * is measuring. Normal writes remain serialized for rotation safety; when
 * the advisory lock exceeds its deadline, one O_APPEND emergency record is
 * written without taking that same lock and the caller resumes immediately.
 */
final class ABJ_404_Solution_CheckpointJournalWriter {

    const CHECKPOINT_FILE = 'abj404_ajax_checkpoints.jsonl';
    const ROTATED_FILE = 'abj404_ajax_checkpoints.old.jsonl';
    const LOCK_FILE = 'abj404_ajax_checkpoints.lock';
    /**
     * One current plus one rotated file retain a measured worst-case session.
     * Schema 3 adds host pressure and recorder cost to every checkpoint; the
     * former 512 KB cap rotated twice during that session and deleted the
     * first six failures before support extraction could prioritize them.
     */
    const MAX_CHECKPOINT_BYTES = 1048576;
    const LOCK_WAIT_TIMEOUT_US = 50000;

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed> Measured write result for the next record.
     */
    public static function append(string $directory, array $record): array {
        $startedNs = self::monotonicNanoseconds();
        $event = is_string($record['event'] ?? null) ? $record['event'] : 'unknown';
        $requestId = is_string($record['request_id'] ?? null) ? $record['request_id'] : 'unknown00';
        $path = $directory . self::CHECKPOINT_FILE;
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            self::reportFailure('AJAX checkpoint JSON encoding failed.');
            return self::result(array('status' => 'failed', 'reason' => 'json_encode_failed',
                'request_id' => $requestId, 'event' => $event, 'started_ns' => $startedNs));
        }

        $lock = @fopen($directory . self::LOCK_FILE, 'cb');
        if ($lock === false) {
            self::reportFailure('AJAX checkpoint lock file could not be opened: ' . $directory . self::LOCK_FILE);
            return self::result(array('status' => 'failed', 'reason' => 'lock_open_failed',
                'request_id' => $requestId, 'event' => $event, 'started_ns' => $startedNs));
        }
        $status = 'complete';
        $reason = '';
        $locked = false;
        try {
            if (!self::acquireLockWithinTimeout($lock)) {
                $status = 'lock_timeout';
                $reason = 'lock_wait_exceeded';
                $waitUs = self::elapsedMicroseconds($startedNs);
                self::appendLockTimeoutRecord(array(
                    'path' => $path,
                    'record' => $record,
                    'blocked_event' => $event,
                    'wait_us' => $waitUs,
                ));
                self::reportFailure('AJAX checkpoint lock timed out after ' . $waitUs . 'us: ' . $path);
                return self::result(array('status' => $status, 'reason' => $reason,
                    'request_id' => $requestId, 'event' => $event, 'started_ns' => $startedNs));
            }
            $locked = true;
            $outcome = self::appendUnderLock(array(
                'directory' => $directory,
                'path' => $path,
                'line' => $json . "\n",
            ));
            $status = $outcome['status'];
            $reason = $outcome['reason'];
        } finally {
            if ($locked && !@flock($lock, LOCK_UN)) {
                $status = 'failed';
                $reason = 'unlock_failed';
                self::reportFailure('AJAX checkpoint lock could not be released: ' . $path);
            }
            @fclose($lock);
        }
        return self::result(array('status' => $status, 'reason' => $reason,
            'request_id' => $requestId, 'event' => $event, 'started_ns' => $startedNs));
    }

    /**
     * @param array{directory: string, path: string, line: string} $write
     * @return array{status: string, reason: string}
     */
    private static function appendUnderLock(array $write): array {
        $directory = $write['directory'];
        $path = $write['path'];
        $line = $write['line'];
        $status = 'complete';
        $reason = '';
        $size = @filesize($path);
        if (is_int($size) && ($size + strlen($line)) > self::MAX_CHECKPOINT_BYTES) {
            $old = $directory . self::ROTATED_FILE;
            if (@is_file($old) && !@unlink($old)) {
                $status = 'failed';
                $reason = 'rotated_file_delete_failed';
                self::reportFailure('AJAX checkpoint rotated file could not be deleted: ' . $old);
            }
            if (@is_file($path) && !@rename($path, $old)) {
                $status = 'failed';
                $reason = 'rotation_rename_failed';
                self::reportFailure('AJAX checkpoint file could not be rotated: ' . $path);
            }
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            self::reportFailure('AJAX checkpoint file could not be opened: ' . $path);
            return array('status' => 'failed', 'reason' => 'journal_open_failed');
        }
        try {
            $written = @fwrite($handle, $line);
            $flushed = @fflush($handle);
        } finally {
            @fclose($handle);
        }
        if ($written !== strlen($line) || !$flushed) {
            self::reportFailure('AJAX checkpoint append/flush failed: ' . $path);
            return array('status' => 'failed', 'reason' => 'append_flush_failed');
        }
        return array('status' => $status, 'reason' => $reason);
    }

    /** @param resource $lock */
    private static function acquireLockWithinTimeout($lock): bool {
        $startedNs = self::monotonicNanoseconds();
        do {
            if (@flock($lock, LOCK_EX | LOCK_NB)) {
                return true;
            }
            if (self::elapsedMicroseconds($startedNs) >= self::LOCK_WAIT_TIMEOUT_US) {
                return false;
            }
            usleep(1000);
        } while (true);
    }

    /**
     * @param array{path: string, record: array<string, mixed>, blocked_event: string, wait_us: int} $timeout
     */
    private static function appendLockTimeoutRecord(array $timeout): void {
        $path = $timeout['path'];
        $blockedRecord = $timeout['record'];
        $blockedEvent = $timeout['blocked_event'];
        $waitUs = $timeout['wait_us'];
        $timeoutRecord = $blockedRecord;
        $timeoutRecord['event'] = 'lock_timeout';
        $timeoutRecord['blocked_event'] = $blockedEvent;
        $timeoutRecord['lock_wait_us'] = $waitUs;
        $timeoutRecord['lock_timeout_us'] = self::LOCK_WAIT_TIMEOUT_US;
        $json = json_encode($timeoutRecord, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            self::reportFailure('AJAX checkpoint lock-timeout JSON encoding failed.');
            return;
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            self::reportFailure('AJAX checkpoint lock-timeout journal could not be opened: ' . $path);
            return;
        }
        try {
            $line = $json . "\n";
            $written = @fwrite($handle, $line);
            $flushed = @fflush($handle);
            if ($written !== strlen($line) || !$flushed) {
                self::reportFailure('AJAX checkpoint lock-timeout record could not be appended: ' . $path);
            }
        } finally {
            @fclose($handle);
        }
    }

    /**
     * @param array{status: string, reason: string, request_id: string, event: string, started_ns: int} $state
     * @return array<string, mixed>
     */
    private static function result(array $state): array {
        $result = array(
            'status' => $state['status'],
            'request_id' => $state['request_id'],
            'event' => $state['event'],
            'elapsed_us' => self::elapsedMicroseconds($state['started_ns']),
        );
        if ($state['reason'] !== '') {
            $result['reason'] = $state['reason'];
        }
        return $result;
    }

    private static function monotonicNanoseconds(): int {
        return function_exists('hrtime') ? (int)hrtime(true) : (int)round(microtime(true) * 1000000000);
    }

    private static function elapsedMicroseconds(int $startedNs): int {
        return max(0, (int)round((self::monotonicNanoseconds() - $startedNs) / 1000));
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('ajax-checkpoint', $message);
    }
}
