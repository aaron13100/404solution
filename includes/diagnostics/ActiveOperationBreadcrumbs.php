<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded append-only state for operations that run after verbose caps.
 *
 * One flushed JSONL record is appended for every transition. Readers fold the
 * stream by (request_id, boundary), keeping the latest 32 live pairs. The raw
 * journal is capped at 1 MiB; only the writer that would cross that bound pays
 * for an atomic compaction to the folded table.
 *
 * A worker death during append can leave a final unterminated fragment. Readers
 * discard only that final fragment and retain the complete prefix; the next
 * writer compacts it away before appending. A malformed terminated line still
 * fails closed. Compaction itself writes and flushes a complete snapshot before
 * renaming, so a death leaves either the append-only source or the complete
 * compacted file. No deliberately truncated target is exposed.
 *
 * This class owns the bounded persistence contract. Boundary field privacy is
 * owned by ABJ_404_Solution_ActiveOperationBoundaryManifest, and event semantics
 * remain with AjaxCheckpointLogger.
 *
 * @phpstan-type EncodedRecord array{record: array<string, mixed>, line: string}
 * @phpstan-type Snapshot array{ino: mixed, dev: mixed, size: int,
 *   max_sequence: int, torn_tail: bool, records: array<int, EncodedRecord>}
 */
final class ABJ_404_Solution_ActiveOperationBreadcrumbs {

    const FILE = 'abj404_ajax_active_operations.jsonl';
    const LOCK_FILE = 'abj404_ajax_active_operations.lock';
    const MAX_RECORDS = 32;
    const MAX_RECORD_BYTES = 2048;
    const MAX_FILE_BYTES = 1048576;
    const LOCK_WAIT_TIMEOUT_US = 50000;

    /**
     * Last validated fold per path. Same-inode growth is parsed from the old
     * size, so a sibling append joins the fold before this process compacts.
     *
     * @var array<string, Snapshot>
     */
    private static $rememberedTable = array();

    /**
     * Append the latest transition for one request/boundary pair.
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
            $record = self::sanitizeRecord($record);
            if (!class_exists('ABJ_404_Solution_FileSystemService')
                    || !ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                return self::failure('directory_unavailable');
            }

            $path = $directory . self::FILE;
            $lockPath = $directory . self::LOCK_FILE;
            $acquired = ABJ_404_Solution_DiagnosticAppendStream::acquireExclusive(
                $lockPath,
                self::LOCK_WAIT_TIMEOUT_US
            );
            if ($acquired['status'] === 'failed') {
                self::reportFailure('active-operation lock could not be opened: ' . $lockPath);
                return self::failure('lock_open_failed');
            }
            try {
                if ($acquired['status'] === 'lock_timeout') {
                    self::reportFailure('active-operation lock wait exceeded: ' . $lockPath);
                    return self::failure('lock_wait_exceeded');
                }
                $snapshot = self::readExistingOrRemembered($path);
                if ($snapshot === null) {
                    return self::failure('existing_file_unparseable');
                }

                $record['breadcrumb_format_version'] = 2;
                $record['breadcrumb_seq'] = $snapshot['max_sequence'] >= PHP_INT_MAX
                    ? 1 : $snapshot['max_sequence'] + 1;
                $encoded = self::encodeRecord($record);
                if ($encoded === null) {
                    self::reportFailure('active-operation file contains an over-budget record.');
                    return self::failure('record_too_large');
                }
                $payloadBytes = strlen($encoded['line']) + 1;
                if ($snapshot['torn_tail']
                        || $snapshot['size'] + $payloadBytes > self::MAX_FILE_BYTES) {
                    $snapshot = self::compactFile($path, $snapshot);
                    if ($snapshot === null) {
                        return self::failure('compaction_failed');
                    }
                }

                $append = ABJ_404_Solution_DiagnosticAppendStream::append(
                    $path,
                    $encoded['line'] . "\n"
                );
                if ($append['status'] !== 'complete') {
                    unset(self::$rememberedTable[$path]);
                    self::reportFailure('active-operation append failed: '
                        . ($append['reason'] ?? 'unknown'));
                    return self::failure('append_failed');
                }
                $snapshot['records'] = self::foldEncodedRecord($snapshot['records'], $encoded);
                $snapshot['size'] += $payloadBytes;
                $snapshot['max_sequence'] = $record['breadcrumb_seq'];
                $snapshot['torn_tail'] = false;
                self::rememberSnapshot($path, $snapshot);
                return array('status' => 'complete', 'reason' => '');
            } finally {
                if (ABJ_404_Solution_DiagnosticAppendStream::release($lockPath)['status'] === 'failed') {
                    self::reportFailure('active-operation lock could not be released: ' . $lockPath);
                }
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
     * Return the latest active identities for one ledger request.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeForRequest(string $directory, string $requestId): array {
        if (preg_match('/^[A-Za-z0-9]{8,64}$/', $requestId) !== 1) {
            return array();
        }
        $snapshot = self::readSnapshot(self::path($directory));
        if ($snapshot === null) {
            return array();
        }
        return array_values(array_filter(
            array_column($snapshot['records'], 'record'),
            static function (array $record) use ($requestId): bool {
                return ($record['request_id'] ?? null) === $requestId
                    && ($record['state'] ?? null) === 'active';
            }
        ));
    }

    /**
     * Fold active-operation lines for support consumers while preserving every
     * unrelated or malformed line for its owning policy to inspect.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    public static function compactSupportLines(array $lines): array {
        $latest = array();
        foreach ($lines as $index => $line) {
            $record = json_decode($line, true);
            $key = is_array($record) ? self::recordKey($record) : '';
            if ($key === '') {
                continue;
            }
            unset($latest[$key]);
            $latest[$key] = $index;
            if (count($latest) > self::MAX_RECORDS) {
                array_shift($latest);
            }
        }
        if ($latest === array()) {
            return $lines;
        }
        $keep = array_fill_keys(array_values($latest), true);
        return array_values(array_filter(
            $lines,
            static function (string $line, int $index) use ($keep): bool {
                $record = json_decode($line, true);
                return !is_array($record)
                    || self::recordKey($record) === ''
                    || isset($keep[$index]);
            },
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /** Discard request-local cached state between PHPUnit request fixtures. */
    public static function resetForTests(): void {
        self::$rememberedTable = array();
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function sanitizeRecord(array $record): array {
        $boundary = is_string($record['boundary'] ?? null) ? $record['boundary'] : '';
        $core = array();
        foreach (array('request_id', 'event', 'boundary', 'state', 'checkpoint_id') as $field) {
            if (array_key_exists($field, $record)) {
                $core[$field] = $record[$field];
            }
        }
        return array_merge(
            $core,
            ABJ_404_Solution_ActiveOperationBoundaryManifest::selectFields($boundary, $record)
        );
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
        if (!is_string($boundary)
                || !ABJ_404_Solution_ActiveOperationBoundaryManifest::hasBoundary($boundary)) {
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
     * @param array<string, mixed> $record
     * @return array{record: array<string, mixed>, line: string}|null
     */
    private static function encodeRecord(array $record): ?array {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($line) || strlen($line) > self::MAX_RECORD_BYTES) {
            return null;
        }
        return array('record' => $record, 'line' => $line);
    }

    /** @return Snapshot|null */
    private static function readExistingOrRemembered(string $path): ?array {
        clearstatcache(true, $path);
        $current = @stat($path);
        if (!is_array($current) || !@is_file($path)) {
            return self::emptySnapshot();
        }
        $size = is_int($current['size'] ?? null) ? $current['size'] : -1;
        if ($size < 0 || $size > self::MAX_FILE_BYTES) {
            self::reportFailure('active-operation file exceeds its fixed byte bound: ' . $path);
            return null;
        }
        $remembered = self::$rememberedTable[$path] ?? null;
        if (is_array($remembered)
                && $remembered['ino'] === ($current['ino'] ?? null)
                && $remembered['dev'] === ($current['dev'] ?? null)
                && $size >= $remembered['size']) {
            if ($size === $remembered['size']) {
                return $remembered;
            }
            return self::readSnapshot($path, $remembered['size'], $remembered);
        }
        return self::readSnapshot($path);
    }

    /**
     * @param Snapshot|null $base Previously validated prefix.
     * @return Snapshot|null
     */
    private static function readSnapshot(string $path, int $offset = 0, ?array $base = null): ?array {
        if (!@is_file($path)) {
            return self::emptySnapshot();
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            self::reportFailure('active-operation file could not be read: ' . $path);
            return null;
        }
        try {
            $stat = @fstat($handle);
            $size = is_array($stat) && is_int($stat['size'] ?? null) ? $stat['size'] : -1;
            if ($size < 0 || $size > self::MAX_FILE_BYTES || $offset > $size) {
                self::reportFailure('active-operation file exceeds its fixed byte bound: ' . $path);
                return null;
            }
            if ($offset > 0 && @fseek($handle, $offset) !== 0) {
                self::reportFailure('active-operation file suffix could not be read: ' . $path);
                return null;
            }
            $snapshot = $base ?? self::emptySnapshot();
            $snapshot['ino'] = is_array($stat) ? ($stat['ino'] ?? null) : null;
            $snapshot['dev'] = is_array($stat) ? ($stat['dev'] ?? null) : null;
            $snapshot['size'] = $size;
            $snapshot['torn_tail'] = false;
            while (($line = @fgets($handle, self::MAX_RECORD_BYTES + 3)) !== false) {
                $parsed = self::parseJournalLine($line, @feof($handle), $path);
                if ($parsed['status'] === 'torn') {
                    $snapshot['torn_tail'] = true;
                    break;
                }
                if ($parsed['status'] !== 'complete') {
                    return null;
                }
                $snapshot['records'] = self::foldEncodedRecord(
                    $snapshot['records'],
                    $parsed['encoded']
                );
                $snapshot['max_sequence'] = max(
                    $snapshot['max_sequence'],
                    $parsed['sequence']
                );
            }
            return $snapshot;
        } finally {
            @fclose($handle);
        }
    }

    /** @return Snapshot */
    private static function emptySnapshot(): array {
        return array(
            'ino' => null,
            'dev' => null,
            'size' => 0,
            'max_sequence' => 0,
            'torn_tail' => false,
            'records' => array(),
        );
    }

    /**
     * Parse one bounded journal read without letting a torn final append poison
     * the complete prefix.
     *
     * @return array{status: 'complete', encoded: EncodedRecord, sequence: int}
     *   |array{status: 'torn'|'failed'}
     */
    private static function parseJournalLine(string $line, bool $atEof, string $path): array {
        if (substr($line, -1) !== "\n") {
            if ($atEof) {
                return array('status' => 'torn');
            }
            self::reportFailure('active-operation file contains an over-budget record: ' . $path);
            return array('status' => 'failed');
        }
        $line = rtrim($line, "\r\n");
        if (strlen($line) > self::MAX_RECORD_BYTES) {
            self::reportFailure('active-operation file contains an over-budget record: ' . $path);
            return array('status' => 'failed');
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded) || self::recordKey($decoded) === '') {
            self::reportFailure('active-operation file contains an unparseable record: ' . $path);
            return array('status' => 'failed');
        }
        $record = array();
        foreach ($decoded as $key => $value) {
            $record[(string)$key] = $value;
        }
        $sequence = is_int($record['breadcrumb_seq'] ?? null)
            ? $record['breadcrumb_seq'] : 0;
        return array(
            'status' => 'complete',
            'encoded' => array('record' => $record, 'line' => $line),
            'sequence' => $sequence,
        );
    }

    /**
     * @param array<int, array{record: array<string, mixed>, line: string}> $records
     * @param array{record: array<string, mixed>, line: string} $replacement
     * @return array<int, array{record: array<string, mixed>, line: string}>
     */
    private static function foldEncodedRecord(array $records, array $replacement): array {
        $key = self::recordKey($replacement['record']);
        $kept = array_values(array_filter(
            $records,
            static function (array $encoded) use ($key): bool {
                return self::recordKey($encoded['record']) !== $key;
            }
        ));
        $kept[] = $replacement;
        return count($kept) > self::MAX_RECORDS
            ? array_slice($kept, -self::MAX_RECORDS)
            : $kept;
    }

    /** @param array<mixed, mixed> $record */
    private static function recordKey(array $record): string {
        $requestId = $record['request_id'] ?? null;
        $boundary = $record['boundary'] ?? null;
        $state = $record['state'] ?? null;
        if (($record['event'] ?? '') !== 'active_operation_breadcrumb'
                || !is_string($requestId)
                || preg_match('/^[A-Za-z0-9]{8,64}$/', $requestId) !== 1
                || !is_string($boundary)
                || !ABJ_404_Solution_ActiveOperationBoundaryManifest::hasBoundary($boundary)
                || !in_array($state, array('active', 'complete'), true)) {
            return '';
        }
        return $requestId . '|' . $boundary;
    }

    /**
     * @param Snapshot $snapshot Validated folded state.
     * @return Snapshot|null Compacted snapshot, or null on failure.
     */
    private static function compactFile(string $path, array $snapshot): ?array {
        $lines = array_column($snapshot['records'], 'line');
        $payload = implode("\n", $lines) . ($lines === array() ? '' : "\n");
        $temporary = $path . '.compact.tmp';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            self::reportFailure('active-operation compaction file could not be opened: ' . $temporary);
            return null;
        }
        try {
            $written = @fwrite($handle, $payload);
            $flushed = @fflush($handle);
        } finally {
            @fclose($handle);
        }
        if ($written !== strlen($payload) || !$flushed) {
            self::reportFailure('active-operation compaction file could not be flushed: '
                . $temporary);
            @unlink($temporary);
            return null;
        }
        if (!@rename($temporary, $path)) {
            self::reportFailure('active-operation compacted file could not be atomically replaced: '
                . $path);
            @unlink($temporary);
            return null;
        }
        ABJ_404_Solution_DiagnosticAppendStream::invalidate($path);
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!is_array($stat)) {
            self::reportFailure('active-operation compacted file identity could not be read: ' . $path);
            return null;
        }
        $snapshot['ino'] = $stat['ino'] ?? null;
        $snapshot['dev'] = $stat['dev'] ?? null;
        $snapshot['size'] = strlen($payload);
        $snapshot['torn_tail'] = false;
        return $snapshot;
    }

    /** @param Snapshot $snapshot Validated folded state. */
    private static function rememberSnapshot(string $path, array $snapshot): void {
        if ($snapshot['ino'] === null || $snapshot['dev'] === null) {
            clearstatcache(true, $path);
            $stat = @stat($path);
            if (!is_array($stat)) {
                unset(self::$rememberedTable[$path]);
                return;
            }
            $snapshot['ino'] = $stat['ino'] ?? null;
            $snapshot['dev'] = $stat['dev'] ?? null;
        }
        self::$rememberedTable[$path] = $snapshot;
    }

    /** @return array{status: string, reason: string} */
    private static function failure(string $reason): array {
        return array('status' => 'failed', 'reason' => $reason);
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('active-operation-breadcrumb', $message);
    }
}
