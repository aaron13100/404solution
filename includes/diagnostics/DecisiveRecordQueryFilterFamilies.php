<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Decisive records separating WordPress query callbacks from driver entry. */
final class ABJ_404_Solution_DecisiveRecordQueryFilterFamilies {

    /** @return array<string, array{emitter:string,events:array<int,string>,presence:string,reserve:array{start:string,end:string}|null,sentinel:string|null}> */
    public static function records(string $always, string $conditional): array {
        return array(
            'query_preflight' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryPreflightTracer',
                'events' => array('query_preflight_start', 'query_preflight_end'),
                'presence' => $always,
                'reserve' => array(
                    'start' => 'query_preflight_start',
                    'end' => 'query_preflight_end',
                ),
                'sentinel' => null,
            ),
            'query_preflight_operation' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryPreflightTracer',
                'events' => array(
                    'query_preflight_operation_start',
                    'query_preflight_operation_end',
                ),
                'presence' => $always,
                'reserve' => array(
                    'start' => 'query_preflight_operation_start',
                    'end' => 'query_preflight_operation_end',
                ),
                'sentinel' => null,
            ),
            'query_first_driver_return' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryRecoveryTracer',
                'events' => array('query_first_driver_return'),
                'presence' => $always,
                'reserve' => null,
                'sentinel' => null,
            ),
            'query_recovery' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryRecoveryTracer',
                'events' => array('query_recovery_start', 'query_recovery_end'),
                'presence' => $always,
                'reserve' => array(
                    'start' => 'query_recovery_start',
                    'end' => 'query_recovery_end',
                ),
                'sentinel' => null,
            ),
            'query_recovery_branch' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryRecoveryTracer',
                'events' => array(
                    'query_recovery_branch_start',
                    'query_recovery_branch_end',
                ),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'query_recovery_branch_start',
                    'end' => 'query_recovery_branch_end',
                ),
                'sentinel' => 'query_recovery_end.branches_selected',
            ),
            'query_recovery_operation' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryRecoveryTracer',
                'events' => array(
                    'query_recovery_operation_start',
                    'query_recovery_operation_end',
                ),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'query_recovery_operation_start',
                    'end' => 'query_recovery_operation_end',
                ),
                'sentinel' => 'query_recovery_end.operations_traced',
            ),
            'query_recovery_attempt' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryRecoveryTracer',
                'events' => array(
                    'query_recovery_attempt_start',
                    'query_recovery_attempt_end',
                ),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'query_recovery_attempt_start',
                    'end' => 'query_recovery_attempt_end',
                ),
                'sentinel' => 'query_recovery_end.attempts_traced',
            ),
            'query_filter_instrumentation' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryFilterTracer',
                'events' => array('query_filter_instrumentation'),
                'presence' => $always,
                'reserve' => null,
                'sentinel' => null,
            ),
            'query_filter_callback' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryFilterTracer',
                'events' => array(
                    'query_filter_callback_start',
                    'query_filter_callback_end',
                ),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'query_filter_callback_start',
                    'end' => 'query_filter_callback_end',
                ),
                'sentinel' => 'query_filter_instrumentation.callbacks_attributed',
            ),
            'query_driver_entry' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryFilterTracer',
                'events' => array('query_driver_entry', 'query_driver_exit'),
                'presence' => $always,
                'reserve' => null,
                'sentinel' => null,
            ),
            'query_filter_callback_capped' => array(
                'emitter' => 'ABJ_404_Solution_DatabaseQueryFilterTracer',
                'events' => array('query_filter_callback_capped'),
                'presence' => $conditional,
                'reserve' => null,
                'sentinel' => 'query_filter_instrumentation.max_records',
            ),
        );
    }

    /** @return array<string, array<string, mixed>> */
    public static function contracts(): array {
        $preflightFields = array(
            'operation_id', 'preflight_id', 'src', 'stage',
        );
        $preflightStartFields = array_merge($preflightFields, array(
            'wpdb_class', 'wpdb_kind', 'reconnect_policy',
        ));
        $preflightOperationFields = array_merge($preflightFields, array('operation'));
        $callbackFields = array(
            'operation_id', 'q', 'sql_id', 'registered_hook', 'hook',
            'callback', 'source', 'priority', 'callback_ordinal',
        );
        $callbackIdentityFields = array(
            'operation_id', 'q', 'sql_id', 'hook', 'callback', 'source',
        );
        $callbackActivation = array(
            'event' => 'query_filter_instrumentation',
            'field' => 'callbacks_attributed',
            'operator' => 'greater_than',
            'value' => 0,
        );
        $contracts = array(
            'database_query_preflight' => array(
                'profiles' => array('ordinary_table', 'database_query_preflight'),
                'requirements' => array(
                    array(
                        'id' => 'query_preflight_start_identity',
                        'event' => 'query_preflight_start',
                        'required_fields' => $preflightStartFields,
                        'non_empty_fields' => array(
                            'operation_id', 'preflight_id', 'src',
                            'wpdb_class', 'wpdb_kind', 'reconnect_policy',
                        ),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_probe'),
                    ),
                    array(
                        'id' => 'query_preflight_operation_start_identity',
                        'event' => 'query_preflight_operation_start',
                        'required_fields' => $preflightOperationFields,
                        'non_empty_fields' => array(
                            'operation_id', 'preflight_id', 'operation', 'src',
                        ),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_preflight_start'),
                    ),
                    array(
                        'id' => 'query_preflight_operation_end_identity',
                        'event' => 'query_preflight_operation_end',
                        'required_fields' => array_merge(
                            $preflightOperationFields,
                            array('status')
                        ),
                        'non_empty_fields' => array(
                            'operation_id', 'preflight_id', 'operation', 'src', 'status',
                        ),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_preflight_start'),
                    ),
                    array(
                        'id' => 'query_preflight_end_identity',
                        'event' => 'query_preflight_end',
                        'required_fields' => array_merge($preflightFields, array('status')),
                        'non_empty_fields' => array(
                            'operation_id', 'preflight_id', 'src', 'status',
                        ),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_preflight_start'),
                    ),
                ),
            ),
            'unmatched_database_query_preflight_support' => array(
                'profiles' => array('unmatched_database_query_preflight_support'),
                'requirements' => array(
                    array(
                        'id' => 'unmatched_query_preflight_identity',
                        'event' => 'query_preflight_start',
                        'required_fields' => $preflightStartFields,
                        'non_empty_fields' => array(
                            'operation_id', 'preflight_id', 'src',
                            'wpdb_class', 'wpdb_kind', 'reconnect_policy',
                        ),
                        'all_matches' => true,
                        'unmatched_end_event' => 'query_preflight_end',
                        'activation' => array(
                            'fact' => 'unmatched_query_preflight_expected',
                            'equals' => true,
                        ),
                    ),
                    array(
                        'id' => 'unmatched_query_preflight_operation_identity',
                        'event' => 'query_preflight_operation_start',
                        'required_fields' => $preflightOperationFields,
                        'non_empty_fields' => array(
                            'operation_id', 'preflight_id', 'operation', 'src',
                        ),
                        'all_matches' => true,
                        'unmatched_end_event' => 'query_preflight_operation_end',
                        'activation' => array(
                            'fact' => 'unmatched_query_preflight_operation_expected',
                            'equals' => true,
                        ),
                    ),
                ),
            ),
            'database_query_filter_callbacks' => array(
                'profiles' => array('ordinary_table', 'database_query_filter_callbacks'),
                'requirements' => array(
                    array(
                        'id' => 'query_filter_instrumentation_identity',
                        'event' => 'query_filter_instrumentation',
                        'required_fields' => array(
                            'q', 'sql_id', 'callbacks_attributed',
                            'callbacks_unavailable', 'registry_status',
                            'driver_sentinel', 'max_records',
                        ),
                        'non_empty_fields' => array(
                            'q', 'sql_id', 'registry_status', 'driver_sentinel',
                        ),
                        'field_types' => array(
                            'q' => 'positive_integer',
                            'callbacks_attributed' => 'non_negative_integer',
                            'callbacks_unavailable' => 'non_negative_integer',
                            'max_records' => 'positive_integer',
                        ),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_probe'),
                    ),
                    array(
                        'id' => 'query_filter_callback_start_identity',
                        'event' => 'query_filter_callback_start',
                        'required_fields' => $callbackFields,
                        'non_empty_fields' => $callbackIdentityFields,
                        'field_types' => array(
                            'q' => 'positive_integer',
                            'priority' => 'integer',
                            'callback_ordinal' => 'positive_integer',
                        ),
                        'all_matches' => true,
                        'activation' => $callbackActivation,
                    ),
                    array(
                        'id' => 'query_filter_callback_end_identity',
                        'event' => 'query_filter_callback_end',
                        'required_fields' => $callbackFields,
                        'non_empty_fields' => $callbackIdentityFields,
                        'field_types' => array(
                            'q' => 'positive_integer',
                            'priority' => 'integer',
                            'callback_ordinal' => 'positive_integer',
                        ),
                        'all_matches' => true,
                        'activation' => $callbackActivation,
                    ),
                    array(
                        'id' => 'query_driver_entry_identity',
                        'event' => 'query_driver_entry',
                        'required_fields' => array('q', 'sql_id'),
                        'non_empty_fields' => array('q', 'sql_id'),
                        'field_types' => array('q' => 'positive_integer'),
                        'activation' => array(
                            'event' => 'query_filter_instrumentation',
                            'field' => 'driver_sentinel',
                            'value' => 'registered',
                        ),
                    ),
                ),
            ),
            'unmatched_database_query_filter_support' => array(
                'profiles' => array('unmatched_database_query_filter_support'),
                'requirements' => array(array(
                    'id' => 'unmatched_query_filter_callback_identity',
                    'event' => 'query_filter_callback_start',
                    'required_fields' => $callbackFields,
                    'non_empty_fields' => $callbackIdentityFields,
                    'field_types' => array(
                        'q' => 'positive_integer',
                        'priority' => 'integer',
                        'callback_ordinal' => 'positive_integer',
                    ),
                    'all_matches' => true,
                    'unmatched_end_event' => 'query_filter_callback_end',
                    'activation' => array(
                        'fact' => 'unmatched_query_filter_callback_expected',
                        'equals' => true,
                    ),
                )),
            ),
        );
        return array_merge($contracts, self::recoveryContracts());
    }

    /** @return array<string, array<string, mixed>> */
    private static function recoveryContracts(): array {
        $recoveryFields = array('q', 'sql_id', 'recovery_id');
        $recoveryOperationFields = array_merge(
            $recoveryFields,
            array('operation_id', 'branch')
        );
        $attemptFields = array_merge(
            $recoveryOperationFields,
            array('attempt_id', 'reason')
        );
        return array(
            'database_query_recovery' => array(
                'profiles' => array('ordinary_table', 'database_query_recovery'),
                'requirements' => array(
                    array(
                        'id' => 'query_first_driver_return_identity',
                        'event' => 'query_first_driver_return',
                        'required_fields' => array_merge($recoveryFields, array('status')),
                        'non_empty_fields' => array('sql_id', 'recovery_id', 'status'),
                        'field_types' => array('q' => 'positive_integer'),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_probe'),
                    ),
                    array(
                        'id' => 'query_recovery_start_identity',
                        'event' => 'query_recovery_start',
                        'required_fields' => array_merge($recoveryFields, array('operation_id')),
                        'non_empty_fields' => array('sql_id', 'recovery_id', 'operation_id'),
                        'field_types' => array('q' => 'positive_integer'),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_first_driver_return'),
                    ),
                    array(
                        'id' => 'query_recovery_end_identity',
                        'event' => 'query_recovery_end',
                        'required_fields' => array_merge(
                            $recoveryFields,
                            array(
                                'operation_id',
                                'status',
                                'branches_selected',
                                'operations_traced',
                                'attempts_traced',
                            )
                        ),
                        'non_empty_fields' => array(
                            'sql_id', 'recovery_id', 'operation_id', 'status',
                        ),
                        'field_types' => array(
                            'q' => 'positive_integer',
                            'branches_selected' => 'non_negative_integer',
                            'operations_traced' => 'non_negative_integer',
                            'attempts_traced' => 'non_negative_integer',
                        ),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_recovery_start'),
                    ),
                    array(
                        'id' => 'query_driver_exit_identity',
                        'event' => 'query_driver_exit',
                        'required_fields' => array('q', 'sql_id', 'status'),
                        'non_empty_fields' => array('sql_id', 'status'),
                        'field_types' => array('q' => 'positive_integer'),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_driver_entry'),
                    ),
                    array(
                        'id' => 'query_recovery_branch_start_identity',
                        'event' => 'query_recovery_branch_start',
                        'required_fields' => $recoveryOperationFields,
                        'non_empty_fields' => $recoveryOperationFields,
                        'field_types' => array('q' => 'positive_integer'),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_recovery_branch_start'),
                    ),
                    array(
                        'id' => 'query_recovery_branch_end_identity',
                        'event' => 'query_recovery_branch_end',
                        'required_fields' => array_merge(
                            $recoveryOperationFields,
                            array('status')
                        ),
                        'non_empty_fields' => array_merge(
                            $recoveryOperationFields,
                            array('status')
                        ),
                        'field_types' => array('q' => 'positive_integer'),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_recovery_branch_start'),
                    ),
                    array(
                        'id' => 'query_recovery_attempt_start_identity',
                        'event' => 'query_recovery_attempt_start',
                        'required_fields' => $attemptFields,
                        'non_empty_fields' => $attemptFields,
                        'field_types' => array('q' => 'positive_integer'),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_recovery_attempt_start'),
                    ),
                    array(
                        'id' => 'query_recovery_attempt_end_identity',
                        'event' => 'query_recovery_attempt_end',
                        'required_fields' => array_merge(
                            $attemptFields,
                            array(
                                'status',
                                'result_status',
                                'row_count',
                                'rows_affected',
                            )
                        ),
                        'non_empty_fields' => array_merge(
                            $attemptFields,
                            array('status', 'result_status')
                        ),
                        'field_types' => array(
                            'q' => 'positive_integer',
                            'row_count' => 'non_negative_integer',
                            'rows_affected' => 'non_negative_integer',
                        ),
                        'all_matches' => true,
                        'activation' => array('event' => 'query_recovery_attempt_start'),
                    ),
                ),
            ),
            'unmatched_database_query_recovery_support' => array(
                'profiles' => array('unmatched_database_query_recovery_support'),
                'requirements' => array(
                    array(
                        'id' => 'unmatched_query_recovery_identity',
                        'event' => 'query_recovery_start',
                        'required_fields' => array_merge($recoveryFields, array('operation_id')),
                        'non_empty_fields' => array('sql_id', 'recovery_id', 'operation_id'),
                        'field_types' => array('q' => 'positive_integer'),
                        'all_matches' => true,
                        'unmatched_end_event' => 'query_recovery_end',
                        'activation' => array(
                            'fact' => 'unmatched_query_recovery_expected',
                            'equals' => true,
                        ),
                    ),
                    array(
                        'id' => 'unmatched_query_recovery_branch_identity',
                        'event' => 'query_recovery_branch_start',
                        'required_fields' => $recoveryOperationFields,
                        'non_empty_fields' => $recoveryOperationFields,
                        'field_types' => array('q' => 'positive_integer'),
                        'all_matches' => true,
                        'unmatched_end_event' => 'query_recovery_branch_end',
                        'activation' => array(
                            'fact' => 'unmatched_query_recovery_branch_expected',
                            'equals' => true,
                        ),
                    ),
                    array(
                        'id' => 'unmatched_query_recovery_attempt_identity',
                        'event' => 'query_recovery_attempt_start',
                        'required_fields' => $attemptFields,
                        'non_empty_fields' => $attemptFields,
                        'field_types' => array('q' => 'positive_integer'),
                        'all_matches' => true,
                        'unmatched_end_event' => 'query_recovery_attempt_end',
                        'activation' => array(
                            'fact' => 'unmatched_query_recovery_attempt_expected',
                            'equals' => true,
                        ),
                    ),
                ),
            ),
        );
    }
}
