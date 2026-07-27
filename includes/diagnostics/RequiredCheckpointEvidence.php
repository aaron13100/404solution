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
            if (self::isReservedActiveOperation($record)) {
                $required[] = $line;
            }
        }
        foreach (self::reservedDurableOperationLines($lines) as $line) {
            $required[] = $line;
        }
        foreach (self::unmatchedOperationLines($lines) as $line) {
            $required[] = $line;
        }
        foreach (ABJ_404_Solution_CheckpointIntentCorrelation::unmatchedIntentLines($lines)
            as $line) {
            $required[] = $line;
        }
        return array_values(array_unique($required));
    }

    /**
     * Select the latest unresolved fixed-sink state for each operation.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function reservedDurableOperationLines(array $lines): array {
        $latestByOperation = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            $operationKey = is_array($record) ? self::durableOperationKey($record) : '';
            if ($operationKey !== '') {
                $latestByOperation[$operationKey] = array('line' => $line, 'record' => $record);
            }
        }
        $selected = array();
        foreach ($latestByOperation as $latest) {
            $record = $latest['record'] ?? null;
            $line = $latest['line'] ?? null;
            if (is_array($record) && is_string($line)
                    && self::isReservedDurableOperation($record)) {
                $selected[] = $line;
            }
        }
        return $selected;
    }

    /**
     * Starts whose matching completion never reached disk.
     *
     * The start/end pairs to reserve are DERIVED from the decisive-record
     * manifest, not hardcoded here: every operation family the manifest marks
     * `reserve` (row-render, rate-limit backend/cache, option persistence,
     * option-hook callback) is preserved by the same request_id + operation_id
     * matching. Enrolling a new decisive record in the manifest extends this
     * reservation automatically, which is the structural fix for the recurring
     * "emitted but un-reserved" gap. The query timeline keeps its own start /
     * clear semantics (a summary with open_query === null cancels the reserve).
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function unmatchedOperationLines(array $lines): array {
        return array_merge(
            self::unmatchedReservedStartLines($lines),
            self::unmatchedQueryLines($lines)
        );
    }

    /**
     * Reserved operation starts (from the manifest) whose matching end never
     * reached disk. Each family's start/end pair is matched by request_id +
     * operation_id; an end removes its start, so what remains is the hung set.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function unmatchedReservedStartLines(array $lines): array {
        $startEvents = array();
        $endToStart = array();
        foreach (ABJ_404_Solution_DecisiveRecordManifest::reservedOperationPairs() as $pair) {
            $startEvents[$pair['start']] = true;
            $endToStart[$pair['end']] = $pair['start'];
        }

        $openStarts = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $event = is_scalar($record['event'] ?? null) ? (string)$record['event'] : '';
            $isStart = isset($startEvents[$event]);
            if (!$isStart && !isset($endToStart[$event])) {
                continue;
            }
            $key = self::operationKey($record, $isStart ? $event : $endToStart[$event]);
            if ($key === '') {
                continue;
            }
            if ($isStart) {
                $openStarts[$key] = $line;
            } else {
                unset($openStarts[$key]);
            }
        }
        return array_values($openStarts);
    }

    /**
     * The reservation key for a start/end record: its start-event namespace
     * plus request_id and operation_id. Empty when either identifier is absent,
     * so an unattributable record is never reserved.
     *
     * @param array<mixed, mixed> $record
     */
    private static function operationKey(array $record, string $startEvent): string {
        $requestId = is_scalar($record['request_id'] ?? null) ? (string)$record['request_id'] : '';
        $operationId = is_scalar($record['operation_id'] ?? null) ? (string)$record['operation_id'] : '';
        if ($requestId === '' || $operationId === '') {
            return '';
        }
        return $startEvent . '|' . $requestId . '|' . $operationId;
    }

    /**
     * The last query probe per request whose timeline never closed (no summary
     * with open_query === null). A killed worker mid-query leaves exactly this.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function unmatchedQueryLines(array $lines): array {
        $lastQueries = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $requestId = is_scalar($record['request_id'] ?? null)
                ? (string)$record['request_id'] : '';
            $event = is_scalar($record['event'] ?? null) ? (string)$record['event'] : '';
            if ($event === 'query_probe' && $requestId !== '') {
                $lastQueries[$requestId] = $line;
            } elseif ($event === 'query_timeline_summary' && $requestId !== ''
                    && ($record['open_query'] ?? null) === null) {
                unset($lastQueries[$requestId]);
            }
        }
        return array_values($lastQueries);
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
        $state = is_scalar($record['state'] ?? null) ? (string)$record['state'] : '';
        $manifest = self::activeBoundaryManifest();
        if ($requestId === ''
                || !array_key_exists($boundary, $manifest)
                || !in_array($state, array('active', 'complete'), true)) {
            return '';
        }
        return $requestId . '|' . $boundary;
    }

    /** @param array<mixed, mixed> $record */
    private static function durableOperationKey(array $record): string {
        if (($record['event'] ?? '') !== 'durable_operation_state') {
            return '';
        }
        $requestId = is_scalar($record['request_id'] ?? null)
            ? (string)$record['request_id'] : '';
        $checkpointId = is_scalar($record['operation_checkpoint_id'] ?? null)
            ? (string)$record['operation_checkpoint_id'] : '';
        return $requestId === '' || $checkpointId === ''
            ? ''
            : $requestId . '|' . $checkpointId;
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
    private static function isReservedActiveOperation(array $record): bool {
        if (($record['event'] ?? '') !== 'active_operation_breadcrumb'
                || ($record['state'] ?? '') !== 'active') {
            return false;
        }
        $boundary = is_scalar($record['boundary'] ?? null)
            ? (string)$record['boundary'] : '';
        $manifest = self::activeBoundaryManifest();
        $requiredFields = $manifest[$boundary]['required_evidence_fields'] ?? array();
        return $requiredFields !== array()
            && self::hasNonEmptyScalarKeys($record, $requiredFields);
    }

    /** @param array<mixed, mixed> $record */
    private static function isReservedDurableOperation(array $record): bool {
        if (($record['event'] ?? '') !== 'durable_operation_state'
                || !in_array($record['operation_state'] ?? '', array('intent', 'armed'), true)) {
            return false;
        }
        $operationEvent = is_scalar($record['operation_event'] ?? null)
            ? (string)$record['operation_event'] : '';
        if ($operationEvent === 'cache_metrics_probe_start') {
            return self::hasNonEmptyScalarKeys(
                $record,
                array('operation_id', 'source', 'phase', 'operation_checkpoint_id')
            );
        }
        if ($operationEvent !== 'active_operation_breadcrumb'
                || ($record['state'] ?? '') !== 'active') {
            return false;
        }
        $boundary = is_scalar($record['boundary'] ?? null)
            ? (string)$record['boundary'] : '';
        $requiredFields = self::activeBoundaryManifest()[$boundary]['required_evidence_fields']
            ?? array();
        return $requiredFields !== array()
            && self::hasNonEmptyScalarKeys($record, $requiredFields)
            && self::hasNonEmptyScalarKeys($record, array('operation_checkpoint_id'));
    }

    /**
     * Missing diagnostics files must degrade to no reserved active evidence
     * instead of breaking the support-request path on a corrupt install.
     *
     * @return array<string, array{
     *   fields: array<int, string>,
     *   required_evidence_fields: array<int, string>
     * }>
     */
    private static function activeBoundaryManifest(): array {
        return class_exists('ABJ_404_Solution_ActiveOperationBreadcrumbs')
            ? ABJ_404_Solution_ActiveOperationBreadcrumbs::boundaryManifest()
            : array();
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

    /**
     * @param array<mixed, mixed> $record
     * @param array<int, string> $fields
     */
    private static function hasNonEmptyScalarKeys(array $record, array $fields): bool {
        foreach ($fields as $field) {
            $value = $record[$field] ?? null;
            if (!is_scalar($value) || (string)$value === '') {
                return false;
            }
        }
        return true;
    }
}
