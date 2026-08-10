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
 * bounded persistence and nothing else: which fields of a boundary may be
 * persisted at all is ABJ_404_Solution_ActiveOperationBoundaryManifest's,
 * because two consumers need that contract without ever touching this file,
 * and event semantics stay with AjaxCheckpointLogger.
 */
final class ABJ_404_Solution_ActiveOperationBreadcrumbs {

    const FILE = 'abj404_ajax_active_operations.jsonl';
    const LOCK_FILE = 'abj404_ajax_active_operations.lock';
    const MAX_RECORDS = 32;
    const MAX_RECORD_BYTES = 2048;
    const LOCK_WAIT_TIMEOUT_US = 50000;

    /**
     * The record table this process last wrote, by path, with the file identity
     * that makes it trustworthy. See readExistingOrRemembered().
     *
     * Each entry of `records` is an encoded record: the decoded record and the
     * exact line that persists it, bound together by encodeRecord(). See that
     * method for why the two travel as one value.
     *
     * @var array<string, array{ino: mixed, dev: mixed, size: mixed, mtime: mixed,
     *   records: array<int, array{record: array<string, mixed>, line: string}>}>
     */
    private static $rememberedTable = array();

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
            $record = self::sanitizeRecord($record);
            if (!class_exists('ABJ_404_Solution_FileSystemService')
                    || !ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                return self::failure('directory_unavailable');
            }

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
                $records = self::readExistingOrRemembered($directory . self::FILE);
                if ($records === null) {
                    return self::failure('existing_file_unparseable');
                }
                $records = self::replaceMatchingRecord($records, $record);
                if ($records === null) {
                    self::reportFailure('active-operation file contains an over-budget record.');
                    return self::failure('record_too_large');
                }
                return self::atomicWrite($directory, $records);
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
     * Return the bounded active identities for one ledger request.
     *
     * Atomic replacement makes a lock unnecessary for readers: they see the
     * complete old file or the complete new file. Invalid or corrupt input
     * fails closed because a threshold report must never invent attribution.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeForRequest(string $directory, string $requestId): array {
        if (preg_match('/^[A-Za-z0-9]{8,64}$/', $requestId) !== 1) {
            return array();
        }
        $records = self::readExisting(self::path($directory));
        if (!is_array($records)) {
            return array();
        }
        return array_values(array_filter(
            $records,
            static function (array $record) use ($requestId): bool {
                return ($record['request_id'] ?? null) === $requestId
                    && ($record['state'] ?? null) === 'active';
            }
        ));
    }

    /**
     * The record as it is allowed to be persisted: the core identity every
     * boundary carries, plus whatever that boundary's privacy allowlist admits.
     *
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
     * The current record table, re-read only when this process is not already
     * holding the answer.
     *
     * WHY. Every post-cap operation replaces one slot in this file, and a
     * plugin-heavy request makes 2,104 of them: measured on the owner's
     * localhost on 2026-08-10 through the real table AJAX endpoint, where
     * replace() cost 1.055 s of a 2.4 s request, roughly half of everything
     * debug_mode added (deliverables/t_260809_224020_311/request-cost.jsonl,
     * label recorder-attribution). Almost all of it was re-reading and
     * re-decoding a table this process had just written itself: up to 32
     * json_decode calls plus 32 validations, 2,104 times over.
     *
     * The remembered table is only trusted while the file on disk is still the
     * exact file this process last wrote. atomicWrite() renames a fresh
     * temporary over the target, so EVERY write, ours or a sibling request's,
     * produces a new inode; an inode, size and mtime that all still match what
     * we recorded after our own write means nothing has replaced it since. One
     * stat answers that, against a read plus a full decode.
     *
     * Correctness is what the check protects: a sibling request's breadcrumb
     * lives in the same table, so writing from a stale copy would erase the
     * operation IT is inside, which is the one thing this file exists to name.
     *
     * @return array<int, array{record: array<string, mixed>, line: string}>|null
     *   Null when the file on disk is unparseable, exactly as readExisting()
     *   reports it.
     */
    private static function readExistingOrRemembered(string $path): ?array {
        clearstatcache(true, $path);
        $current = @stat($path);
        $remembered = self::$rememberedTable[$path] ?? null;
        if (is_array($remembered) && is_array($current)
                && $remembered['ino'] === ($current['ino'] ?? null)
                && $remembered['dev'] === ($current['dev'] ?? null)
                && $remembered['size'] === ($current['size'] ?? null)
                && $remembered['mtime'] === ($current['mtime'] ?? null)) {
            return $remembered['records'];
        }
        $records = self::readExisting($path);
        if ($records === null) {
            return null;
        }
        $encoded = array();
        foreach ($records as $record) {
            $encodedRecord = self::encodeRecord($record);
            if ($encodedRecord === null) {
                // Unreachable through readExisting(), which validates every
                // decoded record against the same byte budget. Fail closed
                // rather than assume that stays true: a table we cannot encode
                // is a table we cannot rewrite without losing a sibling.
                self::reportFailure('active-operation file contains an over-budget record: ' . $path);
                return null;
            }
            $encoded[] = $encodedRecord;
        }
        return $encoded;
    }

    /**
     * Bind a record to the exact line that persists it.
     *
     * WHY THE PAIR. atomicWrite() rewrites the whole fixed-size table on every
     * replacement, but exactly one of its 32 records has changed; re-encoding
     * the other 31 was the largest remaining cost in a path a plugin-heavy
     * request takes 2,120 times (566 ms of one request, measured on the owner's
     * localhost on 2026-08-10, deliverables/t_260809_224020_311). Carrying the
     * line beside its record lets the untouched ones be imploded as they are.
     *
     * The pair is what makes that safe. A cached encoding is only wrong when it
     * outlives the record it describes, so the two are created together, here,
     * and nothing afterwards edits one half: replaceMatchingRecord() drops and
     * appends whole pairs, and PHP array value semantics mean no caller can
     * reach into a pair it was handed. Drift is not guarded against, it is
     * unrepresentable.
     *
     * @param array<string, mixed> $record
     * @return array{record: array<string, mixed>, line: string}|null Null when
     *   the record does not fit its fixed per-record byte budget.
     */
    private static function encodeRecord(array $record): ?array {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($line) || strlen($line) > self::MAX_RECORD_BYTES) {
            return null;
        }
        return array('record' => $record, 'line' => $line);
    }

    /**
     * Remember what we just wrote, so the next replacement in this request can
     * skip the re-read. Forgotten rather than guessed at when the file cannot
     * be stat()ed, because a wrong memory here erases a sibling's evidence.
     *
     * @param array<int, array{record: array<string, mixed>, line: string}> $records
     */
    private static function rememberWrittenTable(string $path, array $records): void {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!is_array($stat)) {
            unset(self::$rememberedTable[$path]);
            return;
        }
        self::$rememberedTable[$path] = array(
            'ino' => $stat['ino'] ?? null,
            'dev' => $stat['dev'] ?? null,
            'size' => $stat['size'] ?? null,
            'mtime' => $stat['mtime'] ?? null,
            'records' => $records,
        );
    }

    /**
     * Discard the remembered table. The request-scoped reset seam, called by
     * name from ABJ404_RequestScopedStateReset: a PHPUnit worker replays many
     * requests in one process and deletes each one's directory, so a table
     * remembered for a path a later test recreates must not be reused.
     */
    public static function resetForTests(): void {
        self::$rememberedTable = array();
    }

    /** @return array<int, array<string, mixed>>|null */
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
     * Swap one boundary's slot for its replacement, keeping every other pair
     * exactly as it was read or last written, line included.
     *
     * @param array<int, array{record: array<string, mixed>, line: string}> $records
     * @param array<string, mixed> $replacement
     * @return array<int, array{record: array<string, mixed>, line: string}>|null
     *   Null when the replacement, once its sequence number is assigned, no
     *   longer fits its fixed per-record byte budget.
     */
    private static function replaceMatchingRecord(array $records, array $replacement): ?array {
        $nextSequence = 1;
        $kept = array();
        foreach ($records as $encodedRecord) {
            $record = $encodedRecord['record'];
            $recordSequence = is_int($record['breadcrumb_seq'] ?? null)
                ? $record['breadcrumb_seq'] : 0;
            $nextSequence = max($nextSequence, $recordSequence + 1);
            if (($record['request_id'] ?? null) === $replacement['request_id']
                    && ($record['boundary'] ?? null) === $replacement['boundary']) {
                continue;
            }
            $kept[] = $encodedRecord;
        }
        $replacement['breadcrumb_seq'] = $nextSequence;
        $encodedReplacement = self::encodeRecord($replacement);
        if ($encodedReplacement === null) {
            return null;
        }
        $kept[] = $encodedReplacement;
        if (count($kept) > self::MAX_RECORDS) {
            $kept = array_slice($kept, -self::MAX_RECORDS);
        }
        return $kept;
    }

    /**
     * @param array<int, array{record: array<string, mixed>, line: string}> $records
     * @return array{status: string, reason: string}
     */
    private static function atomicWrite(string $directory, array $records): array {
        $lines = array_column($records, 'line');
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
        self::rememberWrittenTable($directory . self::FILE, $records);
        return array('status' => 'complete', 'reason' => '');
    }

    /** @return array{status: string, reason: string} */
    private static function failure(string $reason): array {
        return array('status' => 'failed', 'reason' => $reason);
    }

    // The bounded lock wait these two clock helpers served now lives in
    // ABJ_404_Solution_DiagnosticAppendStream::acquireExclusive(), together
    // with the descriptor it locks, so the clock-unavailable rule they encoded
    // (fail the wait CLOSED rather than treat it as zero elapsed, which would
    // make the recorder used to diagnose stalls wait unboundedly) has one home
    // instead of a copy per writer.

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('active-operation-breadcrumb', $message);
    }
}
