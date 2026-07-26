<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Decisive records separating WordPress query callbacks from driver entry. */
final class ABJ_404_Solution_DecisiveRecordQueryFilterFamilies {

    /** @return array<string, array{emitter:string,events:array<int,string>,presence:string,reserve:array{start:string,end:string}|null,sentinel:string|null}> */
    public static function records(string $always, string $conditional): array {
        return array(
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
                'events' => array('query_driver_entry'),
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

        return array(
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
    }
}
