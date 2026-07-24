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
     *
     * Sized against the measurement, not chosen for tidiness, and raised once
     * per schema that adds per-record volume -- because the failure mode of an
     * undersized cap is not "less detail", it is rotation DELETING the first
     * failures of a session before support extraction can ever rank them.
     * Schema 3 added host pressure and recorder cost and forced the move from
     * 512 KB to 1 MB. Schema 4 adds the intra-stage per-query and per-row
     * channels, which take a table request from 26-27 records (~13-16 KB) to
     * ~59 records (~30 KB); the worst-case session this project calibrates
     * against -- six failing attempts, the seven-step canary ladder, and sixty
     * detect-only polls, 69 requests in all -- measures 2,096,927 bytes, so a
     * 1 MB cap rotated twice and deleted every one of the six failures.
     *
     * Retention worst case is ONE cap's worth (immediately after a rotation
     * the current file is empty), so the cap itself, not cap*2, is what has to
     * exceed a whole session. 4 MB leaves roughly 1.9x headroom over the
     * measured one. SupportEvidenceWorstCaseVolumeTest is that measurement
     * turned into a gate, and it fails if this drifts back under it.
     */
    const MAX_CHECKPOINT_BYTES = 4194304;
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

    /**
     * A monotonic nanosecond counter, or 0 when the host has none.
     *
     * This measures the writer's OWN cost, so the reading has to be monotonic:
     * a wall clock can step backwards under NTP and would report a write that
     * took a full second as instantaneous. hrtime() has been core since PHP
     * 7.3 and this plugin requires 7.4, so the only way it is missing is an
     * explicit `disable_functions`. On such a host the honest answer is that
     * the writer's cost is unmeasurable -- both readings return 0, so
     * elapsedMicroseconds() reports 0 -- rather than a number fabricated from
     * a different, non-monotonic time source. Keeping this class free of the
     * clock service is deliberate: it is the last writer standing when the
     * rest of the diagnostic stack is what is broken.
     */
    private static function monotonicNanoseconds(): int {
        return function_exists('hrtime') ? (int)hrtime(true) : 0;
    }

    private static function elapsedMicroseconds(int $startedNs): int {
        return max(0, (int)round((self::monotonicNanoseconds() - $startedNs) / 1000));
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('ajax-checkpoint', $message);
    }
}
