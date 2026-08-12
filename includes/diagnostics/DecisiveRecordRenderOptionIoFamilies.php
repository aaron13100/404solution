<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manifest and discriminator contracts for option I/O inside render scopes.
 *
 * allow-no-test-found: structural coverage is in tests/DecisiveRecordManifestTest.php and real-entry coverage is in tests/AjaxPaginationOptionAttributionTest.php
 */
final class ABJ_404_Solution_DecisiveRecordRenderOptionIoFamilies {

    /**
     * @return array<string,array{
     *   emitter:string,
     *   events:array<int,string>,
     *   presence:string,
     *   reserve:array{start:string,end:string}|null,
     *   sentinel:string|null
     * }>
     */
    public static function records(string $always, string $conditional): array {
        $emitter = 'ABJ_404_Solution_RenderOptionIoOperationJournal';
        return array(
            'render_option_io_instrumentation' => array(
                'emitter' => $emitter,
                'events' => array('render_option_io_instrumentation'),
                'presence' => $always,
                'reserve' => null,
                'sentinel' => null,
            ),
            'render_option_cache' => array(
                'emitter' => $emitter,
                'events' => array(
                    'render_option_cache_start',
                    'render_option_cache_end',
                ),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'render_option_cache_start',
                    'end' => 'render_option_cache_end',
                ),
                'sentinel' => 'render_option_io_instrumentation.cache_boundary',
            ),
            'render_option_query_driver' => array(
                'emitter' => $emitter,
                'events' => array(
                    'render_option_query_driver_entry',
                    'render_option_query_driver_return',
                ),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'render_option_query_driver_entry',
                    'end' => 'render_option_query_driver_return',
                ),
                'sentinel' => 'render_option_io_instrumentation.query_boundary',
            ),
            'render_option_io_capped' => array(
                'emitter' => $emitter,
                'events' => array('render_option_io_capped'),
                'presence' => $conditional,
                'reserve' => null,
                'sentinel' => 'render_option_io_instrumentation.max_records',
            ),
        );
    }

    /** @return array<string,array<string,mixed>> */
    public static function contracts(): array {
        $cacheIdentity = array(
            'operation_id', 'phase', 'operation', 'cache_key', 'cache_group',
            'key_family', 'group_family', 'backend', 'backend_class',
        );
        $queryIdentity = array('operation_id', 'phase', 'operation', 'query_id');
        $cacheActivation = array(
            'fact' => 'render_option_cache_expected',
            'equals' => true,
        );
        $queryActivation = array(
            'fact' => 'render_option_query_expected',
            'equals' => true,
        );
        return array(
            'render_option_io_boundary' => array(
                'profiles' => array('render_option_io'),
                'requirements' => array(array(
                    'id' => 'render_option_io_instrumentation',
                    'event' => 'render_option_io_instrumentation',
                    'required_fields' => array(
                        'phase', 'backend', 'backend_class', 'max_records',
                        'cache_boundary', 'query_boundary', 'scope',
                    ),
                    'non_empty_fields' => array(
                        'phase', 'backend', 'backend_class', 'max_records',
                        'cache_boundary', 'query_boundary', 'scope',
                    ),
                    'all_matches' => true,
                    'activation' => array('always' => true),
                )),
            ),
            'render_option_cache_operations' => array(
                'profiles' => array('render_option_io'),
                'requirements' => array(
                    self::operationRequirement(
                        'render_option_cache_start',
                        $cacheIdentity,
                        $cacheActivation
                    ),
                    self::operationRequirement(
                        'render_option_cache_end',
                        array_merge($cacheIdentity, array(
                            'result', 'result_size', 'result_size_unit', 'elapsed_ms',
                        )),
                        $cacheActivation
                    ),
                ),
            ),
            'render_option_query_operations' => array(
                'profiles' => array('render_option_io'),
                'requirements' => array(
                    self::operationRequirement(
                        'render_option_query_driver_entry',
                        $queryIdentity,
                        $queryActivation
                    ),
                    self::operationRequirement(
                        'render_option_query_driver_return',
                        array_merge($queryIdentity, array(
                            'status', 'result_size', 'result_size_unit',
                        )),
                        $queryActivation
                    ),
                ),
            ),
            'unmatched_render_option_cache_support' => array(
                'profiles' => array('unmatched_render_option_cache_support'),
                'requirements' => array(array(
                    'id' => 'unmatched_render_option_cache_identity',
                    'event' => 'render_option_cache_start',
                    'required_fields' => $cacheIdentity,
                    'non_empty_fields' => $cacheIdentity,
                    'all_matches' => true,
                    'unmatched_end_event' => 'render_option_cache_end',
                    'activation' => array(
                        'fact' => 'unmatched_render_option_cache_expected',
                        'equals' => true,
                    ),
                )),
            ),
            'unmatched_render_option_query_support' => array(
                'profiles' => array('unmatched_render_option_query_support'),
                'requirements' => array(array(
                    'id' => 'unmatched_render_option_query_identity',
                    'event' => 'render_option_query_driver_entry',
                    'required_fields' => $queryIdentity,
                    'non_empty_fields' => $queryIdentity,
                    'all_matches' => true,
                    'unmatched_end_event' => 'render_option_query_driver_return',
                    'activation' => array(
                        'fact' => 'unmatched_render_option_query_expected',
                        'equals' => true,
                    ),
                )),
            ),
            'post_cap_render_option_io' => array(
                'profiles' => array('post_cap_render_option_io'),
                'requirements' => array(array(
                    'id' => 'post_cap_render_option_io_identity',
                    'event' => 'active_operation_breadcrumb',
                    'match' => array(
                        'operation_state' => 'armed',
                        'boundary' => 'render_option_io',
                        'state' => 'active',
                    ),
                    'required_fields' => array('operation_id', 'phase', 'operation'),
                    'non_empty_fields' => array('operation_id', 'phase', 'operation'),
                    'all_matches' => true,
                    'activation' => array(
                        'any' => array(
                            array(
                                'fact' => 'post_cap_render_option_io_expected',
                                'equals' => true,
                            ),
                            array('event' => 'render_option_io_capped'),
                        ),
                    ),
                )),
            ),
        );
    }

    /**
     * @param array<int,string> $fields
     * @param array<string,mixed> $activation
     * @return array<string,mixed>
     */
    private static function operationRequirement(
        string $event,
        array $fields,
        array $activation
    ): array {
        return array(
            'id' => $event . '_identity',
            'event' => $event,
            'required_fields' => $fields,
            'non_empty_fields' => $fields,
            'all_matches' => true,
            'activation' => $activation,
        );
    }
}
