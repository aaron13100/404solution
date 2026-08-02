<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DecisiveRecordQueryFilterFamilies.php';
require_once __DIR__ . '/DecisiveRecordFailureFamilies.php';
require_once __DIR__ . '/DecisiveRecordRenderFamilies.php';
require_once __DIR__ . '/DecisiveRecordRenderOptionIoFamilies.php';
require_once __DIR__ . '/DecisiveRecordStatusCountFamilies.php';

/**
 * Canonical discriminator-field and conditional-presence contract catalog.
 *
 * The decisive-record manifest owns event-family enrollment. This catalog
 * owns the executable record-shape rules consumed by PHP and browser-backed
 * principal gates, independently of the evaluator that interprets them.
 */
final class ABJ_404_Solution_DecisiveRecordDiscriminatorContract {
    /** @return array<string, array<string, mixed>> */
    public static function contracts(): array {
        $hookRequirements = self::hookRequirements();
        $cacheRequirements = self::cacheRequirements();
        $resolutionRequirements = self::detachResolutionRequirements();
        $operationFields = self::detachOperationFields();
        $transientRequirements = self::detachTransientRequirements($operationFields);
        $probeFields = self::cacheProbeFields();
        $responseCallbackFields = array(
            'operation_id',
            'filter_hook',
            'registered_hook',
            'hook',
            'callback',
            'source',
            'priority',
            'callback_ordinal',
        );
        $responseCallbackIdentityFields = array(
            'operation_id',
            'filter_hook',
            'registered_hook',
            'hook',
            'callback',
            'source',
        );
        $responseCallbackActivation = array(
            'any' => array(
                array(
                    'event' => 'response_control_filter_dispatch_end',
                    'field' => 'callbacks_attributed',
                    'operator' => 'greater_than',
                    'value' => 0,
                ),
                array(
                    'fact' => 'response_control_filter_callbacks_expected',
                    'equals' => true,
                ),
            ),
        );
        $contracts = array(
            'hook_lifecycle_consumers' => array(
                'profiles' => array('ordinary_table'),
                'requirements' => $hookRequirements,
            ),
            'cache_probe_boundaries' => array(
                'profiles' => array('ordinary_table'),
                'requirements' => $cacheRequirements,
            ),
            'post_cap_translation_callback' => array(
                'profiles' => array('post_cap_translation_callback'),
                'requirements' => array(array(
                    'id' => 'post_cap_translation_callback_identity',
                    'event' => 'active_operation_breadcrumb',
                    'match' => array(
                        'operation_state' => 'armed',
                        'boundary' => 'table_prelude_hook_callback',
                        'state' => 'active',
                    ),
                    'required_fields' => array(
                        'operation_id', 'hook', 'callback', 'source', 'locale',
                    ),
                    'non_empty_fields' => array(
                        'operation_id', 'hook', 'callback', 'source', 'locale',
                    ),
                    'all_matches' => true,
                    'activation' => array(
                        'any' => array(
                            array('fact' => 'post_cap_translation_callback_expected', 'equals' => true),
                            array('event' => 'table_prelude_hook_callback_capped'),
                        ),
                    ),
                )),
            ),
            'cache_probe_unmatched_support' => array(
                'profiles' => array('unmatched_cache_probe_support'),
                'requirements' => array(array(
                    'id' => 'unmatched_cache_probe_identity',
                    'event' => 'cache_metrics_probe_start',
                    'required_fields' => $probeFields,
                    'non_empty_fields' => $probeFields,
                    'all_matches' => true,
                    'unmatched_end_event' => 'cache_metrics_probe_end',
                    'activation' => array(
                        'fact' => 'unmatched_cache_probe_expected',
                        'equals' => true,
                    ),
                )),
            ),
            'response_control_filter_callbacks' => array(
                'profiles' => array('ordinary_table', 'response_control_filter_callbacks'),
                'requirements' => array(
                    array(
                        'id' => 'response_control_filter_callback_start_identity',
                        'event' => 'response_control_filter_callback_start',
                        'required_fields' => $responseCallbackFields,
                        'non_empty_fields' => $responseCallbackIdentityFields,
                        'field_types' => array(
                            'priority' => 'integer',
                            'callback_ordinal' => 'positive_integer',
                        ),
                        'all_matches' => true,
                        'activation' => $responseCallbackActivation,
                    ),
                    array(
                        'id' => 'response_control_filter_callback_end_identity',
                        'event' => 'response_control_filter_callback_end',
                        'required_fields' => $responseCallbackFields,
                        'non_empty_fields' => $responseCallbackIdentityFields,
                        'field_types' => array(
                            'priority' => 'integer',
                            'callback_ordinal' => 'positive_integer',
                        ),
                        'all_matches' => true,
                        'activation' => $responseCallbackActivation,
                    ),
                ),
            ),
            'unmatched_response_control_callback_support' => array(
                'profiles' => array('unmatched_response_control_callback_support'),
                'requirements' => array(array(
                    'id' => 'unmatched_response_control_callback_identity',
                    'event' => 'response_control_filter_callback_start',
                    'required_fields' => $responseCallbackFields,
                    'non_empty_fields' => $responseCallbackIdentityFields,
                    'field_types' => array(
                        'priority' => 'integer',
                        'callback_ordinal' => 'positive_integer',
                    ),
                    'all_matches' => true,
                    'unmatched_end_event' => 'response_control_filter_callback_end',
                    'activation' => array(
                        'fact' => 'unmatched_response_control_callback_expected',
                        'equals' => true,
                    ),
                )),
            ),
            'detach_ab_resolution_boundaries' => array(
                'profiles' => array('ordinary_table'),
                'requirements' => $resolutionRequirements,
            ),
            'detach_ab_transient_operations' => array(
                'profiles' => array('ordinary_table'),
                'requirements' => $transientRequirements,
            ),
            'unmatched_detach_ab_support' => array(
                'profiles' => array('unmatched_detach_ab_support'),
                'requirements' => array(array(
                    'id' => 'unmatched_detach_ab_operation_identity',
                    'event' => 'detach_ab_operation_start',
                    'required_fields' => $operationFields,
                    'non_empty_fields' => $operationFields,
                    'all_matches' => true,
                    'unmatched_end_event' => 'detach_ab_operation_end',
                    'activation' => array(
                        'fact' => 'unmatched_detach_ab_expected',
                        'equals' => true,
                    ),
                )),
            ),
        );
        return array_merge(
            $contracts,
            ABJ_404_Solution_DecisiveRecordQueryFilterFamilies::contracts(),
            ABJ_404_Solution_DecisiveRecordFailureFamilies::contracts(),
            ABJ_404_Solution_DecisiveRecordRenderFamilies::contracts(),
            ABJ_404_Solution_DecisiveRecordRenderOptionIoFamilies::contracts(),
            ABJ_404_Solution_DecisiveRecordStatusCountFamilies::contracts()
        );
    }

    /** @return array<int, array<string, mixed>> */
    private static function hookRequirements(): array {
        $positionFields = array(
            'operation_id', 'component', 'phase', 'hook', 'priority', 'callback_ordinal',
        );
        $hookRequirements = array(array(
            'id' => 'all_hook_lifecycle_positions',
            'event' => 'hook_instrumentation_lifecycle_start',
            'required_fields' => $positionFields,
            'all_matches' => true,
            'activation' => array('event' => 'hook_instrumentation_lifecycle_start'),
        ));
        $consumers = array(
            'option_persistence' => array('option_hook_instrumentation', 'callbacks_attributed'),
            'table_renderer_prelude' => array('table_prelude_instrumentation', 'callbacks_attributed'),
            'table_render_translation' => array(
                'render_translation_scope_end', 'callbacks_attributed',
            ),
            'database_query_filter' => array(
                'query_filter_instrumentation', 'callbacks_attributed',
            ),
            'row_render' => array('row_operation_instrumentation', 'all_callbacks_attributed'),
            'response_control_filter' => array(
                'response_control_filter_dispatch_end', 'callbacks_attributed',
            ),
        );
        foreach ($consumers as $component => $sentinel) {
            $activation = array('event' => $sentinel[0]);
            $base = array(
                'event' => 'hook_instrumentation_lifecycle_start',
                'match' => array('component' => $component, 'phase' => 'install'),
                'required_fields' => $positionFields,
                'all_matches' => true,
                'activation' => $activation,
            );
            $hookRequirements[] = array_merge($base, array('id' => $component . '_install'));
            $positionActivation = array(
                'event' => $sentinel[0],
                'field' => $sentinel[1],
                'operator' => 'greater_than',
                'value' => 0,
            );
            $positionTypes = array('priority' => 'integer', 'callback_ordinal' => 'positive_integer');
            $hookRequirements[] = array_merge($base, array(
                'id' => $component . '_install_position',
                'activation' => $positionActivation,
                'field_types' => $positionTypes,
                'all_matches' => false,
            ));
            $hookRequirements[] = array(
                'id' => $component . '_restore_position',
                'event' => 'hook_instrumentation_lifecycle_start',
                'match' => array('component' => $component, 'phase' => 'restore'),
                'required_fields' => $positionFields,
                'field_types' => $positionTypes,
                'activation' => $positionActivation,
            );
        }
        $dynamicRegistrations = array(
            'render_option_io' => array(
                'event' => 'render_option_io_instrumentation',
                'field' => 'query_boundary',
                'value' => 'ready',
            ),
            'row_render' => array(
                'event' => 'row_operation_instrumentation',
                'field' => 'hook_boundary',
                'value' => 'ready',
            ),
            'table_render_translation' => array('event' => 'render_translation_scope_end'),
            'database_query_filter' => array(
                'event' => 'query_filter_instrumentation',
                'field' => 'driver_sentinel',
                'value' => 'registered',
            ),
        );
        foreach ($dynamicRegistrations as $component => $activation) {
            foreach (array('registration', 'removal') as $phase) {
                $hookRequirements[] = array(
                    'id' => $component . '_' . $phase,
                    'event' => 'hook_instrumentation_lifecycle_start',
                    'match' => array('component' => $component, 'phase' => $phase),
                    'required_fields' => $positionFields,
                    'all_matches' => true,
                    'activation' => $activation,
                );
            }
        }
        return $hookRequirements;
    }

    /** @return array<int, array<string, mixed>> */
    private static function cacheRequirements(): array {
        $cacheRequirements = array();
        $probeFields = self::cacheProbeFields();
        foreach (array('initial', 'progress', 'finish') as $phase) {
            $cachePresent = array('fact' => 'cache_object_present', 'equals' => true);
            $phaseSentinel = $phase === 'initial'
                ? array('event' => 'row_loop_start')
                : array(
                    'event' => $phase === 'progress' ? 'row_loop_progress' : 'row_loop_end',
                    'field' => 'cache_src',
                    'operator' => 'not_equals',
                    'value' => 'error',
                );
            $capabilityExpected = array('all' => array($cachePresent, $phaseSentinel));
            foreach (array('start', 'end') as $edge) {
                $cacheRequirements[] = array(
                    'id' => 'cache_capability_' . $phase . '_' . $edge,
                    'event' => 'cache_metrics_probe_' . $edge,
                    'match' => array('source' => 'metrics_capability', 'phase' => $phase),
                    'required_fields' => $probeFields,
                    'all_matches' => true,
                    'activation' => $capabilityExpected,
                );
            }
            $metricsAvailable = array(
                'event' => 'cache_metrics_probe_end',
                'match' => array('source' => 'metrics_capability', 'phase' => $phase),
                'field' => 'result',
                'value' => 'available',
            );
            foreach (array('start', 'end') as $edge) {
                $cacheRequirements[] = array(
                    'id' => 'cache_metrics_' . $phase . '_' . $edge,
                    'event' => 'cache_metrics_probe_' . $edge,
                    'match' => array('source' => 'metrics', 'phase' => $phase),
                    'required_fields' => $probeFields,
                    'all_matches' => true,
                    'activation' => $metricsAvailable,
                );
            }
            $countersRequired = array(
                'any' => array(
                    array(
                        'event' => 'cache_metrics_probe_end',
                        'match' => array('source' => 'metrics_capability', 'phase' => $phase),
                        'field' => 'result',
                        'value' => 'unavailable',
                    ),
                    array(
                        'event' => 'cache_metrics_probe_end',
                        'match' => array('source' => 'metrics', 'phase' => $phase),
                        'field' => 'result',
                        'value' => 'snapshot_unavailable',
                    ),
                ),
            );
            foreach (array('start', 'end') as $edge) {
                $cacheRequirements[] = array(
                    'id' => 'cache_counters_' . $phase . '_' . $edge,
                    'event' => 'cache_metrics_probe_' . $edge,
                    'match' => array('source' => 'counters', 'phase' => $phase),
                    'required_fields' => $probeFields,
                    'all_matches' => true,
                    'activation' => $countersRequired,
                );
            }
        }
        $cacheRequirements[] = array(
            'id' => 'no_cache_object_sentinel',
            'event' => 'row_loop_end',
            'match' => array('cache_src' => 'none'),
            'required_fields' => array('cache_src', 'cache_calls', 'cache_ms'),
            'activation' => array('fact' => 'cache_object_present', 'equals' => false),
        );
        $cacheRequirements[] = array(
            'id' => 'cache_probe_error_sentinel',
            'event' => 'row_loop_end',
            'match' => array('cache_src' => 'error'),
            'required_fields' => array('cache_src', 'cache_calls', 'cache_ms'),
            'activation' => array(
                'event' => 'cache_metrics_probe_end',
                'field' => 'status',
                'value' => 'error',
            ),
        );
        return $cacheRequirements;
    }

    /** @return array<int, string> */
    private static function cacheProbeFields(): array {
        return array('operation_id', 'source', 'phase');
    }

    /** @return array<int, array<string, mixed>> */
    private static function detachResolutionRequirements(): array {
        $resolutionFields = array(
            'operation_id', 'operation', 'session_state', 'part', 'payload_key',
        );
        return array(
            array(
                'id' => 'detach_ab_resolution_start_identity',
                'event' => 'detach_ab_resolution_start',
                'required_fields' => $resolutionFields,
                'non_empty_fields' => $resolutionFields,
                'all_matches' => true,
                'activation' => array('event' => 'finish_request'),
            ),
            array(
                'id' => 'detach_ab_resolution_end_identity',
                'event' => 'detach_ab_resolution_end',
                'required_fields' => array_merge($resolutionFields, array(
                    'status', 'mode', 'diagnostic_enabled', 'counter_status',
                )),
                'non_empty_fields' => array_merge($resolutionFields, array(
                    'status', 'mode', 'counter_status',
                )),
                'all_matches' => true,
                'activation' => array('event' => 'finish_request'),
            ),
        );
    }

    /** @return array<int, string> */
    private static function detachOperationFields(): array {
        return array(
            'operation_id', 'operation', 'transient_key', 'cache_backend',
            'cache_backend_class', 'cache_capabilities',
        );
    }

    /**
     * @param array<int, string> $operationFields
     * @return array<int, array<string, mixed>>
     */
    private static function detachTransientRequirements(array $operationFields): array {
        $operationActivation = array(
            'event' => 'detach_ab_resolution_end',
            'field' => 'counter_status',
            'value' => 'attempt_resolved',
        );
        $transientRequirements = array();
        foreach (array('get_transient', 'set_transient') as $operation) {
            foreach (array('start', 'end') as $edge) {
                $required = $operationFields;
                if ($edge === 'end') {
                    $required = array_merge($required, array('status', 'result'));
                }
                $transientRequirements[] = array(
                    'id' => 'detach_ab_' . $operation . '_' . $edge,
                    'event' => 'detach_ab_operation_' . $edge,
                    'match' => array('operation' => $operation),
                    'required_fields' => $required,
                    'non_empty_fields' => $required,
                    'all_matches' => true,
                    'activation' => $operationActivation,
                );
            }
        }
        return $transientRequirements;
    }
}
