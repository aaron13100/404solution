<?php

if (!defined('ABSPATH')) {
    exit;
}

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
        $positionFields = array(
            'operation_id', 'component', 'phase', 'hook', 'priority', 'callback_ordinal',
        );
        $hookRequirements = array();
        $consumers = array(
            'option_persistence' => array('option_hook_instrumentation', 'callbacks_attributed'),
            'table_renderer_prelude' => array('table_prelude_instrumentation', 'callbacks_attributed'),
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
        foreach (array('registration', 'removal') as $phase) {
            $hookRequirements[] = array(
                'id' => 'row_render_' . $phase,
                'event' => 'hook_instrumentation_lifecycle_start',
                'match' => array('component' => 'row_render', 'phase' => $phase),
                'required_fields' => $positionFields,
                'all_matches' => true,
                'activation' => array(
                    'event' => 'row_operation_instrumentation',
                    'field' => 'hook_boundary',
                    'value' => 'ready',
                ),
            );
        }

        $cacheRequirements = array();
        $probeFields = array('operation_id', 'source', 'phase');
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

        return array(
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
        );
    }
}
