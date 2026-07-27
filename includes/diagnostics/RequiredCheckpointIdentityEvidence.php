<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Selects the latest schema-complete checkpoint for each required identity.
 *
 * These records carry discriminators that ordinary request-group ranking can
 * omit even though an older complete sample is more useful than a newer
 * partial one. This policy is deliberately independent of request lifecycle
 * and byte-budget decisions: it answers only whether a record proves a
 * required evidence identity.
 */
final class ABJ_404_Solution_RequiredCheckpointIdentityEvidence {

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
        'render_option_io_cap' => 'isRenderOptionIoCap',
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
     * @param array<int, string> $lines JSONL lines, oldest first.
     * @return array<int, string>
     */
    public static function select(array $lines): array {
        $selected = array_fill_keys(array_keys(self::SELECTORS), '');
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
        return array_values(array_filter(
            $selected,
            static fn(string $line): bool => $line !== ''
        ));
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
    private static function isRenderOptionIoCap(array $record): bool {
        return ($record['event'] ?? '') === 'render_option_io_capped'
            && self::hasKeys($record, array('phase', 'recorded', 'max_records'));
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
