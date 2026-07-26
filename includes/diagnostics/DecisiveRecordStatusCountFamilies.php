<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manifest and discriminator contracts for foreground status-count work.
 */
final class ABJ_404_Solution_DecisiveRecordStatusCountFamilies {

    /**
     * @return array<string, array{
     *     emitter: string,
     *     events: array<int, string>,
     *     presence: string,
     *     reserve: array{start: string, end: string}|null,
     *     sentinel: string|null
     * }>
     */
    public static function records(string $conditional): array {
        return array(
            'status_count_operation' => array(
                'emitter' => 'ABJ_404_Solution_StatusCountOperationJournal',
                'events' => array(
                    'status_count_operation_start',
                    'status_count_operation_end',
                ),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'status_count_operation_start',
                    'end' => 'status_count_operation_end',
                ),
                'sentinel' => 'stage_start.stage=redirect_status_counts|captured_status_counts|canary_summary',
            ),
            'status_count_instrumentation' => array(
                'emitter' => 'ABJ_404_Solution_StatusCountOperationJournal',
                'events' => array('status_count_instrumentation'),
                'presence' => $conditional,
                'reserve' => null,
                'sentinel' => 'status_count_operation_start.operation=status_count_scope',
            ),
            'status_count_operation_capped' => array(
                'emitter' => 'ABJ_404_Solution_StatusCountOperationJournal',
                'events' => array('status_count_operation_capped'),
                'presence' => $conditional,
                'reserve' => null,
                'sentinel' => 'status_count_instrumentation.max_records',
            ),
        );
    }

    /** @return array<string,array<string,mixed>> */
    public static function contracts(): array {
        $identity = array('operation_id', 'operation');
        $end = array('operation_id', 'operation', 'status', 'elapsed_ms', 'result');
        $requirements = array(array(
            'id' => 'status_count_instrumentation',
            'event' => 'status_count_instrumentation',
            'required_fields' => array(
                'hook_boundary', 'cache_boundary', 'all_callbacks_attributed',
                'max_records',
            ),
            'non_empty_fields' => array('hook_boundary', 'cache_boundary', 'max_records'),
            'activation' => array('always' => true),
        ));
        foreach (array(
            'status_count_scope' => array('scope'),
            'status_cache_read' => array('scope'),
            'transient_read' => array('family'),
        ) as $operation => $discriminators) {
            foreach (array('start', 'end') as $edge) {
                $required = array_merge(
                    $edge === 'end' ? $end : $identity,
                    $discriminators
                );
                $requirements[] = array(
                    'id' => 'status_count_' . $operation . '_' . $edge,
                    'event' => 'status_count_operation_' . $edge,
                    'match' => array('operation' => $operation),
                    'required_fields' => $required,
                    'non_empty_fields' => $required,
                    'all_matches' => true,
                    'activation' => array('always' => true),
                );
            }
        }
        foreach (array(
            'scheduler_resolution' => array('scope'),
            'schedule_if_missing' => array('scope'),
            'next_scheduled_check' => array('family'),
        ) as $operation => $discriminators) {
            foreach (array('start', 'end') as $edge) {
                $required = array_merge(
                    $edge === 'end' ? $end : $identity,
                    $discriminators
                );
                $requirements[] = array(
                    'id' => 'status_count_' . $operation . '_' . $edge,
                    'event' => 'status_count_operation_' . $edge,
                    'match' => array('operation' => $operation),
                    'required_fields' => $required,
                    'non_empty_fields' => $required,
                    'all_matches' => true,
                    'activation' => array(
                        'fact' => 'status_count_refresh_expected',
                        'equals' => true,
                    ),
                );
            }
        }
        foreach (array('start', 'end') as $edge) {
            $required = array_merge(
                $edge === 'end' ? $end : $identity,
                array('family')
            );
            $requirements[] = array(
                'id' => 'status_count_scheduling_write_' . $edge,
                'event' => 'status_count_operation_' . $edge,
                'match' => array('operation' => 'scheduling_write'),
                'required_fields' => $required,
                'non_empty_fields' => $required,
                'all_matches' => true,
                'activation' => array(
                    'fact' => 'status_count_schedule_write_expected',
                    'equals' => true,
                ),
            );
        }
        foreach (array('start', 'end') as $edge) {
            $required = array_merge(
                $edge === 'end' ? $end : $identity,
                array('kind', 'hook', 'callback', 'source', 'priority')
            );
            $requirements[] = array(
                'id' => 'status_count_hook_callback_' . $edge,
                'event' => 'status_count_operation_' . $edge,
                'match' => array('operation' => 'hook_callback'),
                'required_fields' => $required,
                'non_empty_fields' => array_diff($required, array('priority')),
                'all_matches' => true,
                'activation' => array(
                    'fact' => 'status_count_hook_callback_expected',
                    'equals' => true,
                ),
            );
        }
        foreach (array('start', 'end') as $edge) {
            $required = array_merge(
                $edge === 'end' ? $end : $identity,
                array('kind', 'cache_key', 'cache_group')
            );
            $requirements[] = array(
                'id' => 'status_count_cache_get_' . $edge,
                'event' => 'status_count_operation_' . $edge,
                'match' => array('operation' => 'cache_get'),
                'required_fields' => $required,
                'non_empty_fields' => $required,
                'all_matches' => true,
                'activation' => array(
                    'fact' => 'status_count_cache_operation_expected',
                    'equals' => true,
                ),
            );
        }

        return array(
            'status_count_foreground' => array(
                'profiles' => array('status_count_foreground'),
                'requirements' => $requirements,
            ),
            'unmatched_status_count_support' => array(
                'profiles' => array('unmatched_status_count_support'),
                'requirements' => array(array(
                    'id' => 'unmatched_status_count_identity',
                    'event' => 'status_count_operation_start',
                    'required_fields' => $identity,
                    'non_empty_fields' => $identity,
                    'all_matches' => true,
                    'unmatched_end_event' => 'status_count_operation_end',
                    'activation' => array(
                        'fact' => 'unmatched_status_count_operation_expected',
                        'equals' => true,
                    ),
                )),
            ),
            'post_cap_status_count_operation' => array(
                'profiles' => array('post_cap_status_count_operation'),
                'requirements' => array(array(
                    'id' => 'post_cap_status_count_operation_identity',
                    'event' => 'active_operation_breadcrumb',
                    'match' => array(
                        'operation_state' => 'armed',
                        'boundary' => 'status_count_operation',
                        'state' => 'active',
                    ),
                    'required_fields' => $identity,
                    'non_empty_fields' => $identity,
                    'all_matches' => true,
                    'activation' => array(
                        'any' => array(
                            array(
                                'fact' => 'post_cap_status_count_operation_expected',
                                'equals' => true,
                            ),
                            array('event' => 'status_count_operation_capped'),
                        ),
                    ),
                )),
            ),
        );
    }
}
