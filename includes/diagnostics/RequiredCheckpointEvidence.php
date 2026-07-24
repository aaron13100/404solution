<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Selects checkpoint records that must survive bounded support ranking.
 *
 * Ordinary request-group ranking may omit an early census, a row-progress
 * sample, or a completed browser report even though each is the only record
 * carrying its discriminator fields. This policy reserves one complete record
 * for every such evidence class before the remaining byte budget is ranked.
 */
final class ABJ_404_Solution_RequiredCheckpointEvidence {

    /**
     * Evidence identities mapped to their schema predicate.
     *
     * @var array<string, string>
     */
    private const SELECTORS = array(
        'client_receipt' => 'isClientReceipt',
        'size_receipt' => 'isSizeReceipt',
        'concurrent_control' => 'isConcurrentControlReceipt',
        'same_site_census' => 'isSameSiteCensus',
        'row_loop_activity' => 'isRowLoopActivity',
        'query_identity' => 'isQueryIdentity',
        'query_cap' => 'isQueryCap',
        'row_operation_normal' => 'isRowOperationNormal',
        'row_operation_cap' => 'isRowOperationCap',
        'row_operation_unavailable' => 'isRowOperationUnavailable',
        'browser_attempt' => 'isBrowserAttempt',
        'browser_storage_unavailable' => 'isBrowserStorageUnavailable',
        'browser_table_storage_unavailable' => 'isBrowserTableStorageUnavailable',
        'boot_generation' => 'isBootGeneration',
        'detach_counterbalance' => 'isDetachCounterbalance',
        'recorder_phases' => 'isRecorderPhases',
    );

    /** @var array<int, string> */
    private const CANARY_TRANSPORT_FIELDS = array(
        'payload_variant',
        'content_encoding',
        'transfer_bytes',
        'encoded_body_bytes',
        'decoded_body_bytes',
        'resource_timing_state',
    );

    /** @var array<int, string> */
    private const ROW_ACTIVITY_FIELDS = array(
        'hook_active',
        'hook_calls',
        'hook_top',
        'cache_calls',
        'cache_ms',
        'cache_src',
    );

    /** @var array<int, string> */
    private const RECORDER_PHASE_FIELDS = array(
        'intent_append',
        'directory_resolve',
        'directory_create',
        'host_pressure_probe',
        'envelope_build',
        'append',
    );

    /**
     * Latest complete record for every required evidence identity.
     *
     * @param array<int, string> $lines JSONL lines, oldest first.
     * @return array<int, string>
     */
    public static function select(array $lines): array {
        $selected = array_fill_keys(array_keys(self::SELECTORS), '');
        $operationState = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            $operationKey = is_array($record) ? self::activeOperationKey($record) : '';
            if ($operationKey !== '') {
                $operationState[$operationKey] = array('line' => $line, 'record' => $record);
            }
        }
        foreach (array_reverse($lines) as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            foreach (self::SELECTORS as $identity => $predicate) {
                if ($selected[$identity] === '' && self::{$predicate}($record)) {
                    $selected[$identity] = $line;
                }
            }
            if (!in_array('', $selected, true)) {
                break;
            }
        }
        $required = array_filter(
            $selected,
            static fn(string $line): bool => $line !== ''
        );
        foreach ($operationState as $latest) {
            $record = $latest['record'] ?? null;
            $line = $latest['line'] ?? null;
            if (!is_array($record) || !is_string($line)) {
                continue;
            }
            if (self::isActiveQuery($record) || self::isActiveRowOperation($record)) {
                $required[] = $line;
            }
        }
        foreach (self::unmatchedOperationLines($lines) as $line) {
            $required[] = $line;
        }
        return array_values(array_unique($required));
    }

    /**
     * Starts whose matching completion never reached disk.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function unmatchedOperationLines(array $lines): array {
        $rowStarts = array();
        $lastQueries = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $requestId = is_scalar($record['request_id'] ?? null)
                ? (string)$record['request_id'] : '';
            $event = $record['event'] ?? '';
            if ($event === 'row_operation_start') {
                $operationId = is_scalar($record['operation_id'] ?? null)
                    ? (string)$record['operation_id'] : '';
                if ($requestId !== '' && $operationId !== '') {
                    $rowStarts[$requestId . '|' . $operationId] = $line;
                }
            } elseif ($event === 'row_operation_end') {
                $operationId = is_scalar($record['operation_id'] ?? null)
                    ? (string)$record['operation_id'] : '';
                unset($rowStarts[$requestId . '|' . $operationId]);
            } elseif ($event === 'query_probe' && $requestId !== '') {
                $lastQueries[$requestId] = $line;
            } elseif ($event === 'query_timeline_summary' && $requestId !== ''
                    && ($record['open_query'] ?? null) === null) {
                unset($lastQueries[$requestId]);
            }
        }
        return array_merge(array_values($rowStarts), array_values($lastQueries));
    }

    /** @param array<mixed, mixed> $record */
    private static function activeOperationKey(array $record): string {
        if (($record['event'] ?? '') !== 'active_operation_breadcrumb') {
            return '';
        }
        $requestId = is_scalar($record['request_id'] ?? null)
            ? (string)$record['request_id'] : '';
        $boundary = is_scalar($record['boundary'] ?? null)
            ? (string)$record['boundary'] : '';
        if ($requestId === '' || !in_array($boundary, array('query', 'row_operation'), true)) {
            return '';
        }
        return $requestId . '|' . $boundary;
    }

    /** @param array<mixed, mixed> $record */
    private static function isClientReceipt(array $record): bool {
        return self::hasReceiptJoins($record, 'canary_step_client_receipt');
    }

    /** @param array<mixed, mixed> $record */
    private static function isSizeReceipt(array $record): bool {
        return self::hasReceiptJoins($record, 'canary_step_client_receipt')
            && in_array($record['payload_variant'] ?? '', array('compressible', 'incompressible'), true)
            && self::hasKeys($record, self::CANARY_TRANSPORT_FIELDS);
    }

    /** @param array<mixed, mixed> $record */
    private static function isConcurrentControlReceipt(array $record): bool {
        return ABJ_404_Solution_ClientTransportReport::isCompleteConcurrentControlJournalRecord($record);
    }

    /** @param array<mixed, mixed> $record */
    private static function isSameSiteCensus(array $record): bool {
        return is_array($record['same_site_census'] ?? null)
            && array_key_exists('same_site_requests', $record);
    }

    /** @param array<mixed, mixed> $record */
    private static function isRowLoopActivity(array $record): bool {
        return ($record['event'] ?? '') === 'row_loop_progress'
            && self::hasKeys($record, self::ROW_ACTIVITY_FIELDS);
    }

    /** @param array<mixed, mixed> $record */
    private static function isQueryIdentity(array $record): bool {
        return ($record['event'] ?? '') === 'query_probe'
            && self::hasKeys($record, array('q', 'src', 'sql_id'));
    }

    /** @param array<mixed, mixed> $record */
    private static function isQueryCap(array $record): bool {
        return ($record['event'] ?? '') === 'query_probe_capped'
            && self::hasKeys($record, array('q', 'limit'));
    }

    /** @param array<mixed, mixed> $record */
    private static function isRowOperationNormal(array $record): bool {
        return ($record['event'] ?? '') === 'row_operation_end'
            && self::hasKeys($record, array('operation_id', 'kind'));
    }

    /** @param array<mixed, mixed> $record */
    private static function isRowOperationCap(array $record): bool {
        return ($record['event'] ?? '') === 'row_operation_capped'
            && self::hasKeys($record, array('recorded', 'max_records'));
    }

    /** @param array<mixed, mixed> $record */
    private static function isRowOperationUnavailable(array $record): bool {
        return ($record['event'] ?? '') === 'row_operation_unavailable'
            && self::hasKeys($record, array('kind', 'reason'));
    }

    /** @param array<mixed, mixed> $record */
    private static function isActiveQuery(array $record): bool {
        return self::isActiveOperation($record, 'query')
            && self::hasKeys($record, array('q', 'src', 'sql_id'));
    }

    /** @param array<mixed, mixed> $record */
    private static function isActiveRowOperation(array $record): bool {
        return self::isActiveOperation($record, 'row_operation')
            && self::hasKeys($record, array('operation_id', 'kind'));
    }

    /** @param array<mixed, mixed> $record */
    private static function isActiveOperation(array $record, string $boundary): bool {
        return ($record['event'] ?? '') === 'active_operation_breadcrumb'
            && ($record['boundary'] ?? '') === $boundary
            && ($record['state'] ?? '') === 'active';
    }

    /** @param array<mixed, mixed> $record */
    private static function isBrowserAttempt(array $record): bool {
        $report = is_array($record['report'] ?? null) ? $record['report'] : array();
        $environment = is_array($report['env'] ?? null) ? $report['env'] : array();
        return ($record['event'] ?? '') === 'client_prior_attempt'
            && is_array($report['storage_health'] ?? null)
            && is_array($environment['drift'] ?? null);
    }

    /** @param array<mixed, mixed> $record */
    private static function isBrowserStorageUnavailable(array $record): bool {
        $report = is_array($record['report'] ?? null) ? $record['report'] : array();
        $storage = is_array($report['storage_health'] ?? null)
            ? $report['storage_health'] : array();
        return self::isBrowserAttempt($record)
            && ($storage['status'] ?? '') === 'unavailable';
    }

    /** @param array<mixed, mixed> $record */
    private static function isBrowserTableStorageUnavailable(array $record): bool {
        $report = is_array($record['report'] ?? null) ? $record['report'] : array();
        return self::isBrowserStorageUnavailable($record)
            && ($report['part'] ?? '') === 'table';
    }

    /** @param array<mixed, mixed> $record */
    private static function isBootGeneration(array $record): bool {
        $opcache = is_array($record['boundary_opcache'] ?? null)
            ? $record['boundary_opcache'] : array();
        return in_array($record['event'] ?? '', array(
            'boot_plugin_entry', 'plugins_loaded', 'init', 'admin_init', 'ajax_dispatch',
        ), true)
            && array_key_exists('build_generation_consistent', $record)
            && array_key_exists('matches_checkpoint_logger', $opcache);
    }

    /** @param array<mixed, mixed> $record */
    private static function isDetachCounterbalance(array $record): bool {
        return ($record['event'] ?? '') === 'detach_ab_mode'
            && self::hasKeys($record, array('part', 'ordinal', 'pair_ordinal'));
    }

    /** @param array<mixed, mixed> $record */
    private static function isRecorderPhases(array $record): bool {
        $previous = is_array($record['previous_checkpoint_write'] ?? null)
            ? $record['previous_checkpoint_write'] : array();
        $phases = is_array($previous['phases_us'] ?? null) ? $previous['phases_us'] : array();
        return self::hasKeys($phases, self::RECORDER_PHASE_FIELDS);
    }

    /** @param array<mixed, mixed> $record */
    private static function hasReceiptJoins(array $record, string $event): bool {
        return ($record['envelope'] ?? '') === ABJ_404_Solution_CheckpointRecordFactory::ENVELOPE_FULL
            && ($record['event'] ?? '') === $event
            && is_string($record['carried_by'] ?? null)
            && $record['carried_by'] !== ''
            && is_string($record['step_request_id'] ?? null)
            && $record['step_request_id'] !== '';
    }

    /**
     * @param array<mixed, mixed> $record
     * @param array<int, string> $fields
     */
    private static function hasKeys(array $record, array $fields): bool {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $record)) {
                return false;
            }
        }
        return true;
    }
}
