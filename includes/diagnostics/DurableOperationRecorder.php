<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates two-sink lifecycle evidence around foreign operations.
 *
 * The complete privacy-safe identity reaches the fixed system-temp sink
 * before WordPress path resolution. The existing journal or active-operation
 * store remains the second sink. A final fixed-sink state acknowledges whether
 * foreign work was armed or completed and records the second sink outcome.
 *
 * This class owns sequencing only. CheckpointIntentStore, CheckpointJournalWriter,
 * CheckpointRecordFactory, and ActiveOperationBreadcrumbs remain the persistence
 * implementations.
 *
 * allow-no-test-found: exercised through the real AJAX table handler in tests/AjaxRowProgressAttributionTest.php and tests/TableRendererPreludeTracerTest.php
 */
final class ABJ_404_Solution_DurableOperationRecorder {

    /** @var bool Prevent directory filters from recursively recording themselves. */
    private static $recordingActiveOperation = false;

    /** @var int */
    private static $checkpointSequence = 0;

    /**
     * Privacy-safe scalar fields allowed in the system-temp operation state.
     */
    private const SAFE_FIELDS = array(
        'operation_id',
        'operation',
        'session_state',
        'part',
        'payload_key',
        'transient_key',
        'cache_backend',
        'cache_backend_class',
        'cache_capabilities',
        'counter_status',
        'mode',
        'diagnostic_enabled',
        'error_class',
        'error_code',
        'error',
        'source',
        'phase',
        'boundary',
        'state',
        'hook',
        'callback',
        'locale',
        'priority',
        'status',
        'elapsed_ms',
        'result',
        'armed',
        'first_sink_status',
        'second_sink_status',
    );

    /**
     * Persist cache-probe identity before uploads work and acknowledge arming.
     *
     * @param array<string, mixed> $fields
     * @return string Stable checkpoint identity carried through completion.
     */
    public static function recordStart(
        string $requestId,
        string $event,
        array $fields
    ): string {
        $checkpointId = self::newCheckpointId();
        $safeFields = self::selectSafeFields($fields);
        $intent = self::appendFixedState(
            $requestId,
            $event,
            $checkpointId,
            'intent',
            $safeFields
        );
        $journal = self::appendJournalRecord(
            $requestId,
            $event,
            $checkpointId,
            array_merge($safeFields, array(
                'operation_checkpoint_id' => $checkpointId,
                'armed' => true,
                'first_sink_status' => self::writeStatus($intent),
            ))
        );
        self::appendFixedState(
            $requestId,
            $event,
            $checkpointId,
            'armed',
            array_merge($safeFields, array(
                'armed' => true,
                'first_sink_status' => self::writeStatus($intent),
                'second_sink_status' => self::writeStatus($journal),
            ))
        );
        return $checkpointId;
    }

    /**
     * Persist that foreign work returned using its original correlation key.
     *
     * @param array<string, mixed> $fields
     */
    public static function recordEnd(
        string $requestId,
        string $event,
        string $checkpointId,
        array $fields
    ): void {
        if ($checkpointId === '') {
            return;
        }
        $completion = self::appendFixedState(
            $requestId,
            $event,
            $checkpointId,
            'complete',
            self::selectSafeFields($fields)
        );
        self::appendJournalRecord(
            $requestId,
            $event,
            $checkpointId,
            array_merge($fields, array(
                'operation_checkpoint_id' => $checkpointId,
                'first_sink_status' => self::writeStatus($completion),
            ))
        );
    }

    /**
     * Replace one fixed-size post-cap operation state. Never throws.
     *
     * @param array<string, mixed> $fields
     */
    public static function recordActiveOperation(
        string $requestId,
        string $boundary,
        string $state,
        array $fields
    ): void {
        // Both collaborators are guarded: on a partially corrupt install this
        // path must degrade to recording nothing, never fatal on the redaction
        // catalog after clearing the persistence class.
        if ($requestId === '' || self::$recordingActiveOperation
                || !class_exists('ABJ_404_Solution_ActiveOperationBreadcrumbs')
                || !class_exists('ABJ_404_Solution_ActiveOperationBoundaryManifest')) {
            return;
        }
        self::$recordingActiveOperation = true;
        try {
            $safeFields = ABJ_404_Solution_ActiveOperationBoundaryManifest::selectFields(
                $boundary,
                $fields
            );
            $checkpointId = self::activeCheckpointId(
                $requestId,
                $boundary,
                is_string($safeFields['operation_id'] ?? null)
                    ? $safeFields['operation_id']
                    : ''
            );
            $identity = array_merge(
                array('boundary' => $boundary, 'state' => $state),
                $safeFields
            );
            $intent = self::appendFixedState(
                $requestId,
                'active_operation_breadcrumb',
                $checkpointId,
                'intent',
                $identity
            );
            $result = self::replaceActiveState(
                $requestId,
                $checkpointId,
                $identity,
                $state === 'active',
                self::writeStatus($intent)
            );
            self::appendFixedState(
                $requestId,
                'active_operation_breadcrumb',
                $checkpointId,
                $state === 'active' ? 'armed' : 'complete',
                array_merge($identity, array(
                    'armed' => $state === 'active',
                    'first_sink_status' => self::writeStatus($intent),
                    'second_sink_status' => self::writeStatus($result),
                ))
            );
        } catch (Throwable $e) {
            self::reportFailure('active-operation record failed: ' . $e->getMessage());
        } finally {
            self::$recordingActiveOperation = false;
        }
    }

    /**
     * Keep only the latest durable and active state for each operation identity.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    public static function compactSupportLines(array $lines): array {
        $latestIndexes = array();
        foreach ($lines as $index => $line) {
            $record = json_decode($line, true);
            if (!is_array($record) || ($record['event'] ?? '') !== 'durable_operation_state') {
                continue;
            }
            $requestId = is_scalar($record['request_id'] ?? null)
                ? (string)$record['request_id'] : '';
            $checkpointId = is_scalar($record['operation_checkpoint_id'] ?? null)
                ? (string)$record['operation_checkpoint_id'] : '';
            if ($requestId !== '' && $checkpointId !== '') {
                $latestIndexes[$requestId . '|' . $checkpointId] = $index;
            }
        }
        $compacted = $lines;
        if ($latestIndexes !== array()) {
            $keep = array_fill_keys(array_values($latestIndexes), true);
            $compacted = array_values(array_filter(
                $lines,
                static function (string $line, int $index) use ($keep): bool {
                    $record = json_decode($line, true);
                    return !is_array($record)
                        || ($record['event'] ?? '') !== 'durable_operation_state'
                        || isset($keep[$index]);
                },
                ARRAY_FILTER_USE_BOTH
            ));
        }
        return class_exists('ABJ_404_Solution_ActiveOperationBreadcrumbs')
            ? ABJ_404_Solution_ActiveOperationBreadcrumbs::compactSupportLines($compacted)
            : $compacted;
    }

    /** Active-state path for support collection, or empty when unavailable. */
    public static function activePath(string $directory): string {
        return $directory !== '' && class_exists('ABJ_404_Solution_ActiveOperationBreadcrumbs')
            ? ABJ_404_Solution_ActiveOperationBreadcrumbs::path($directory)
            : '';
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function appendJournalRecord(
        string $requestId,
        string $event,
        string $checkpointId,
        array $fields
    ): array {
        try {
            $directory = ABJ_404_Solution_AjaxCheckpointLogger::resolveDirectoryPath();
            if ($directory === '') {
                return self::failure('directory_unavailable');
            }
            if (!class_exists('ABJ_404_Solution_FileSystemService')
                    || !ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                return self::failure('directory_create_failed');
            }
            return ABJ_404_Solution_CheckpointJournalWriter::append(
                $directory,
                array_merge(
                    $fields,
                    self::frequentRecord($requestId, $event, $checkpointId)
                )
            );
        } catch (Throwable $e) {
            self::reportFailure('journal record failed: ' . $e->getMessage());
            return self::failure('unexpected_failure');
        }
    }

    /**
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private static function replaceActiveState(
        string $requestId,
        string $checkpointId,
        array $identity,
        bool $armed,
        string $firstSinkStatus
    ): array {
        try {
            $directory = ABJ_404_Solution_AjaxCheckpointLogger::resolveDirectoryPath();
            if ($directory === '') {
                return self::failure('directory_unavailable');
            }
            return ABJ_404_Solution_ActiveOperationBreadcrumbs::replace(
                $directory,
                array_merge(
                    self::frequentRecord(
                        $requestId,
                        'active_operation_breadcrumb',
                        $checkpointId
                    ),
                    $identity,
                    array(
                        'operation_checkpoint_id' => $checkpointId,
                        'armed' => $armed,
                        'first_sink_status' => $firstSinkStatus,
                    )
                )
            );
        } catch (Throwable $e) {
            self::reportFailure('active-operation write failed: ' . $e->getMessage());
            return self::failure('unexpected_failure');
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function appendFixedState(
        string $requestId,
        string $operationEvent,
        string $checkpointId,
        string $operationState,
        array $fields
    ): array {
        return ABJ_404_Solution_CheckpointIntentStore::append(array_merge(
            self::frequentRecord($requestId, 'durable_operation_state', $checkpointId),
            array(
                'operation_event' => $operationEvent,
                'operation_state' => $operationState,
                'operation_checkpoint_id' => $checkpointId,
            ),
            self::selectSafeFields($fields)
        ));
    }

    /** @return array<string, mixed> */
    private static function frequentRecord(
        string $requestId,
        string $event,
        string $checkpointId
    ): array {
        return ABJ_404_Solution_CheckpointRecordFactory::frequent(array(
            'ts' => self::nowFloat(),
            'hrtime_ns' => function_exists('hrtime') ? (int)hrtime(true) : null,
            'request_id' => $requestId,
            'event' => $event,
            'checkpoint_id' => $checkpointId,
            'pid' => getmypid(),
        ));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function selectSafeFields(array $fields): array {
        $safe = array();
        foreach (self::SAFE_FIELDS as $field) {
            if (array_key_exists($field, $fields)
                    && (is_scalar($fields[$field]) || $fields[$field] === null)) {
                $safe[$field] = $fields[$field];
            }
        }
        return $safe;
    }

    /** @param array<string, mixed> $write */
    private static function writeStatus(array $write): string {
        return ($write['status'] ?? '') === 'complete' ? 'complete' : 'failed';
    }

    /** @return array{status: string, reason: string} */
    private static function failure(string $reason): array {
        return array('status' => 'failed', 'reason' => $reason);
    }

    private static function activeCheckpointId(
        string $requestId,
        string $boundary,
        string $operationId
    ): string {
        $identity = hash('sha256', $requestId . '|' . $boundary . '|' . $operationId);
        return 'op-' . substr(strtr($identity, '0123456789', 'ghijklmnop'), 0, 20);
    }

    private static function newCheckpointId(): string {
        self::$checkpointSequence++;
        $pid = getmypid();
        $identity = dechex(is_int($pid) ? $pid : 0) . '-'
            . dechex(function_exists('hrtime') ? (int)hrtime(true) : 0) . '-'
            . dechex(self::$checkpointSequence);
        return strtr($identity, '0123456789abcdef', 'ghijklmnopqrstuv');
    }

    private static function nowFloat(): ?float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        if (class_exists('ABJ_404_Solution_SystemClock')) {
            return (new ABJ_404_Solution_SystemClock())->nowFloat();
        }
        return null;
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('durable-operation-recorder', $message);
    }
}
