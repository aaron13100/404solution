<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fixed-size, crash-safe state for operations that run after verbose caps.
 *
 * The ordinary checkpoint journal is append-only and intentionally stops
 * recording detailed query/row-operation events at a request-local ceiling.
 * This file keeps one latest state per request and boundary instead. Each
 * replacement is written to a sibling temporary file, flushed, and atomically
 * renamed, so a worker death leaves either the previous complete file or the
 * new complete file. It never leaves a deliberately truncated target.
 *
 * The caller supplies a frequent checkpoint record. This class owns the
 * bounded persistence and the privacy allowlist for its boundary payloads;
 * event semantics stay with AjaxCheckpointLogger.
 */
final class ABJ_404_Solution_ActiveOperationBreadcrumbs {

    const FILE = 'abj404_ajax_active_operations.jsonl';
    const LOCK_FILE = 'abj404_ajax_active_operations.lock';
    const MAX_RECORDS = 32;
    const MAX_RECORD_BYTES = 2048;
    const LOCK_WAIT_TIMEOUT_US = 50000;
    /**
     * One boundary catalog shared by persistence and support reservation.
     *
     * `fields` is the default-deny privacy allowlist written to disk.
     * `required_evidence_fields` is the minimum discriminator identity an
     * active record must carry before support collection reserves it.
     *
     * @var array<string, array{
     *   fields: array<int, string>,
     *   required_evidence_fields: array<int, string>
     * }>
     */
    private const BOUNDARY_MANIFEST = array(
        'query' => array(
            'fields' => array('q', 'stage', 'src', 'sql_id', 'sql_len', 'timeout_s'),
            'required_evidence_fields' => array('q', 'src', 'sql_id'),
        ),
        'row_operation' => array(
            'fields' => array(
                'operation_id', 'kind', 'operation', 'key', 'group', 'hook', 'callback', 'source',
            ),
            'required_evidence_fields' => array('operation_id', 'kind'),
        ),
        'table_prelude_hook_callback' => array(
            'fields' => array('operation_id', 'hook', 'callback', 'source', 'locale'),
            'required_evidence_fields' => array(
                'operation_id', 'hook', 'callback', 'source', 'locale',
            ),
        ),
        'render_translation_callback' => array(
            'fields' => array(
                'operation_id', 'phase', 'hook', 'callback', 'source', 'locale',
                'message_set_hash',
            ),
            'required_evidence_fields' => array(
                'operation_id', 'phase', 'hook', 'callback', 'source', 'locale',
                'message_set_hash',
            ),
        ),
        'query_filter_callback' => array(
            'fields' => array(
                'operation_id', 'q', 'sql_id', 'registered_hook', 'hook',
                'callback', 'source', 'priority', 'callback_ordinal',
            ),
            'required_evidence_fields' => array(
                'operation_id', 'q', 'sql_id', 'hook', 'callback', 'source',
            ),
        ),
    );

    /**
     * The canonical allowed/reserved active-operation boundary contract.
     *
     * @return array<string, array{
     *   fields: array<int, string>,
     *   required_evidence_fields: array<int, string>
     * }>
     */
    public static function boundaryManifest(): array {
        return self::BOUNDARY_MANIFEST;
    }

    /**
     * Replace the latest record for one request/boundary pair.
     *
     * @param array<string, mixed> $record
     * @return array{status: string, reason: string}
     */
    public static function replace(string $directory, array $record): array {
        try {
            $validated = self::validateRecord($record);
            if ($validated['status'] !== 'complete') {
                return $validated;
            }
            if (!class_exists('ABJ_404_Solution_FileSystemService')
                    || !ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                return self::failure('directory_unavailable');
            }

            $lock = @fopen($directory . self::LOCK_FILE, 'cb');
            if ($lock === false) {
                self::reportFailure('active-operation lock could not be opened: ' . $directory . self::LOCK_FILE);
                return self::failure('lock_open_failed');
            }
            $locked = false;
            try {
                if (!self::acquireLockWithinTimeout($lock)) {
                    self::reportFailure('active-operation lock wait exceeded: ' . $directory . self::LOCK_FILE);
                    return self::failure('lock_wait_exceeded');
                }
                $locked = true;
                $records = self::readExisting($directory . self::FILE);
                if ($records === null) {
                    return self::failure('existing_file_unparseable');
                }
                $records = self::replaceMatchingRecord($records, $record);
                return self::atomicWrite($directory, $records);
            } finally {
                if ($locked && !@flock($lock, LOCK_UN)) {
                    self::reportFailure('active-operation lock could not be released: '
                        . $directory . self::LOCK_FILE);
                }
                @fclose($lock);
            }
        } catch (Throwable $e) {
            self::reportFailure('active-operation replacement failed: ' . $e->getMessage());
            return self::failure('unexpected_failure');
        }
    }

    /** The active-state file path whether or not the file currently exists. */
    public static function path(string $directory): string {
        return $directory . self::FILE;
    }

    /**
     * Keep only scalar, non-sensitive identity fields for one boundary.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function selectFields(string $boundary, array $fields): array {
        $boundaryContract = self::BOUNDARY_MANIFEST[$boundary] ?? array();
        $allowed = $boundaryContract['fields'] ?? array();
        $safe = array();
        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)
                    && (is_scalar($fields[$field]) || $fields[$field] === null)) {
                $safe[$field] = $fields[$field];
            }
        }
        return $safe;
    }

    /**
     * @param array<mixed, mixed> $record
     * @return array{status: string, reason: string}
     */
    private static function validateRecord(array $record): array {
        $requestId = $record['request_id'] ?? null;
        $boundary = $record['boundary'] ?? null;
        $state = $record['state'] ?? null;
        if (!is_string($requestId) || preg_match('/^[A-Za-z0-9]{8,64}$/', $requestId) !== 1) {
            self::reportFailure('active-operation record has an invalid request id.');
            return self::failure('invalid_request_id');
        }
        if (!is_string($boundary) || !array_key_exists($boundary, self::BOUNDARY_MANIFEST)) {
            self::reportFailure('active-operation record has an invalid boundary.');
            return self::failure('invalid_boundary');
        }
        if (!in_array($state, array('active', 'complete'), true)) {
            self::reportFailure('active-operation record has an invalid state.');
            return self::failure('invalid_state');
        }
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_RECORD_BYTES) {
            self::reportFailure('active-operation record exceeds its fixed record budget.');
            return self::failure('record_too_large');
        }
        return array('status' => 'complete', 'reason' => '');
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private static function readExisting(string $path): ?array {
        if (!is_file($path)) {
            return array();
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            self::reportFailure('active-operation file could not be read: ' . $path);
            return null;
        }
        $records = array();
        try {
            while (($line = @fgets($handle, self::MAX_RECORD_BYTES + 2)) !== false) {
                if (strlen($line) > self::MAX_RECORD_BYTES + 1
                        || count($records) >= self::MAX_RECORDS) {
                    self::reportFailure('active-operation file exceeds its fixed bounds: ' . $path);
                    return null;
                }
                $decoded = json_decode(trim($line), true);
                if (!is_array($decoded) || self::validateRecord($decoded)['status'] !== 'complete') {
                    self::reportFailure('active-operation file contains an unparseable record: ' . $path);
                    return null;
                }
                $records[] = $decoded;
            }
        } finally {
            @fclose($handle);
        }
        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $replacement
     * @return array<int, array<string, mixed>>
     */
    private static function replaceMatchingRecord(array $records, array $replacement): array {
        $nextSequence = 1;
        $kept = array();
        foreach ($records as $record) {
            $recordSequence = is_int($record['breadcrumb_seq'] ?? null)
                ? $record['breadcrumb_seq'] : 0;
            $nextSequence = max($nextSequence, $recordSequence + 1);
            if (($record['request_id'] ?? null) === $replacement['request_id']
                    && ($record['boundary'] ?? null) === $replacement['boundary']) {
                continue;
            }
            $kept[] = $record;
        }
        $replacement['breadcrumb_seq'] = $nextSequence;
        $kept[] = $replacement;
        if (count($kept) > self::MAX_RECORDS) {
            $kept = array_slice($kept, -self::MAX_RECORDS);
        }
        return $kept;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{status: string, reason: string}
     */
    private static function atomicWrite(string $directory, array $records): array {
        $lines = array();
        foreach ($records as $record) {
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || strlen($encoded) > self::MAX_RECORD_BYTES) {
                self::reportFailure('active-operation file contains an over-budget record.');
                return self::failure('record_too_large');
            }
            $lines[] = $encoded;
        }
        $payload = implode("\n", $lines) . ($lines === array() ? '' : "\n");
        $temporary = $directory . self::FILE . '.tmp';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            self::reportFailure('active-operation temporary file could not be opened: ' . $temporary);
            return self::failure('temporary_open_failed');
        }
        try {
            $written = @fwrite($handle, $payload);
            $flushed = @fflush($handle);
        } finally {
            @fclose($handle);
        }
        if ($written !== strlen($payload) || !$flushed) {
            self::reportFailure('active-operation temporary file could not be flushed: ' . $temporary);
            @unlink($temporary);
            return self::failure('temporary_write_failed');
        }
        if (!@rename($temporary, $directory . self::FILE)) {
            self::reportFailure('active-operation file could not be atomically replaced: '
                . $directory . self::FILE);
            @unlink($temporary);
            return self::failure('atomic_replace_failed');
        }
        return array('status' => 'complete', 'reason' => '');
    }

    /** @param resource $lock */
    private static function acquireLockWithinTimeout($lock): bool {
        $started = self::monotonicNanoseconds();
        do {
            if (@flock($lock, LOCK_EX | LOCK_NB)) {
                return true;
            }
            if (self::elapsedMicroseconds($started) >= self::LOCK_WAIT_TIMEOUT_US) {
                return false;
            }
            usleep(1000);
        } while (true);
    }

    /** @return array{status: string, reason: string} */
    private static function failure(string $reason): array {
        return array('status' => 'failed', 'reason' => $reason);
    }

    private static function monotonicNanoseconds(): ?int {
        if (function_exists('hrtime')) {
            return (int)hrtime(true);
        }
        if (function_exists('abj_clock')) {
            return (int)round(abj_clock()->nowFloat() * 1000000000);
        }
        if (class_exists('ABJ_404_Solution_SystemClock')) {
            return (int)round((new ABJ_404_Solution_SystemClock())->nowFloat() * 1000000000);
        }
        return null;
    }

    private static function elapsedMicroseconds(?int $started): int {
        $finished = self::monotonicNanoseconds();
        if ($started === null || $finished === null) {
            // No reachable clock must fail the bounded non-blocking lock wait
            // closed; treating it as zero would turn the loop into an
            // unbounded wait inside the recorder being used to diagnose stalls.
            return self::LOCK_WAIT_TIMEOUT_US;
        }
        return max(0, (int)round(($finished - $started) / 1000));
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('active-operation-breadcrumb', $message);
    }
}
