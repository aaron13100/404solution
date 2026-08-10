<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decisive records emitted by post-options render callback tracing.
 *
 * Event names retain "translation" for support-schema compatibility; the
 * tracer dynamically covers every named WordPress hook reached by its scope.
 */
final class ABJ_404_Solution_DecisiveRecordRenderFamilies {

    /** @return array<string, array{emitter: string, events: array<int, string>, presence: string, reserve: array{start: string, end: string}|null, sentinel: string|null}> */
    public static function records(string $always, string $conditional): array {
        return array(
            'render_translation_scope' => array(
                'emitter' => 'ABJ_404_Solution_TableRenderTranslationTracer',
                'events' => array('render_translation_scope_start', 'render_translation_scope_end'),
                'presence' => $always,
                'reserve' => array(
                    'start' => 'render_translation_scope_start',
                    'end' => 'render_translation_scope_end',
                ),
                'sentinel' => null,
            ),
            'render_translation_callback' => array(
                'emitter' => 'ABJ_404_Solution_TableRenderTranslationTracer',
                'events' => array(
                    'render_translation_callback_start',
                    'render_translation_callback_end',
                ),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'render_translation_callback_start',
                    'end' => 'render_translation_callback_end',
                ),
                'sentinel' => 'render_translation_scope_end.callbacks_attributed',
            ),
            'render_translation_callback_capped' => array(
                'emitter' => 'ABJ_404_Solution_TableRenderTranslationTracer',
                'events' => array('render_translation_callback_capped'),
                'presence' => $conditional,
                'reserve' => null,
                'sentinel' => 'render_translation_callback_capped.max_records',
            ),
            'template_file_operation' => array(
                'emitter' => 'ABJ_404_Solution_TemplateFileReadTracer',
                'events' => array(
                    'template_file_operation_start',
                    'template_file_operation_end',
                ),
                'presence' => $always,
                'reserve' => array(
                    'start' => 'template_file_operation_start',
                    'end' => 'template_file_operation_end',
                ),
                'sentinel' => null,
            ),
            // Template I/O is the one family whose volume follows the rendered
            // row count, so it spends a per-request journal budget and
            // announces the rest on the active-operations channel. This record
            // is what tells a support reader that the journalled reads are the
            // first N and not all of them. Conditional: a small table never
            // reaches the budget and never emits it.
            'template_file_operation_capped' => array(
                'emitter' => 'ABJ_404_Solution_TemplateFileReadTracer',
                'events' => array('template_file_operation_capped'),
                'presence' => $conditional,
                'reserve' => null,
                'sentinel' => 'template_file_operation_capped.max_records',
            ),
            'routine_log_operation' => array(
                'emitter' => 'ABJ_404_Solution_RoutineLogTracer',
                'events' => array(
                    'routine_log_operation_start',
                    'routine_log_operation_end',
                ),
                'presence' => $always,
                'reserve' => array(
                    'start' => 'routine_log_operation_start',
                    'end' => 'routine_log_operation_end',
                ),
                'sentinel' => null,
            ),
            'sort_readiness_operation' => array(
                'emitter' => 'ABJ_404_Solution_SortReadinessTracer',
                'events' => array(
                    'sort_readiness_operation_start',
                    'sort_readiness_operation_end',
                ),
                'presence' => $always,
                'reserve' => array(
                    'start' => 'sort_readiness_operation_start',
                    'end' => 'sort_readiness_operation_end',
                ),
                'sentinel' => null,
            ),
            'sort_readiness_instrumentation' => array(
                'emitter' => 'ABJ_404_Solution_SortReadinessTracer',
                'events' => array('sort_readiness_instrumentation'),
                'presence' => $conditional,
                'reserve' => null,
                'sentinel' => 'sort_readiness_operation_end.operation=schema_readiness,result=false',
            ),
        );
    }

    /** @return array<string, array<string, mixed>> */
    public static function contracts(): array {
        $identityFields = array('operation_id', 'operation', 'template_id');
        $endFields = array_merge($identityFields, array('status', 'elapsed_ms', 'result', 'bytes'));
        $requirements = array();
        foreach (array('stat', 'read_attempt') as $operation) {
            $requirements[] = array(
                'id' => 'template_file_' . $operation . '_start',
                'event' => 'template_file_operation_start',
                'match' => array('operation' => $operation),
                'required_fields' => $identityFields,
                'non_empty_fields' => $identityFields,
                'all_matches' => true,
                'activation' => array('always' => true),
            );
            $requirements[] = array(
                'id' => 'template_file_' . $operation . '_end',
                'event' => 'template_file_operation_end',
                'match' => array('operation' => $operation),
                'required_fields' => $endFields,
                'non_empty_fields' => array_merge($identityFields, array('status')),
                'all_matches' => true,
                'activation' => array('always' => true),
            );
        }
        foreach (array('retry_wait', 'warning_log', 'curl_fallback') as $operation) {
            foreach (array('start', 'end') as $edge) {
                $required = $edge === 'end' ? $endFields : $identityFields;
                $requirements[] = array(
                    'id' => 'template_file_' . $operation . '_' . $edge,
                    'event' => 'template_file_operation_' . $edge,
                    'match' => array('operation' => $operation),
                    'required_fields' => $required,
                    'non_empty_fields' => $edge === 'end'
                        ? array_merge($identityFields, array('status'))
                        : $identityFields,
                    'all_matches' => true,
                    'activation' => array(
                        'fact' => 'template_file_' . $operation . '_expected',
                        'equals' => true,
                    ),
                );
            }
        }

        $routineIdentity = array('operation_id', 'operation');
        $routineRequirements = array();
        foreach (array('message', 'timestamp_resolution', 'debug_state_resolution') as $operation) {
            foreach (array('start', 'end') as $edge) {
                $required = $edge === 'end'
                    ? array_merge($routineIdentity, array('status', 'result'))
                    : $routineIdentity;
                $routineRequirements[] = array(
                    'id' => 'routine_log_' . $operation . '_' . $edge,
                    'event' => 'routine_log_operation_' . $edge,
                    'match' => array('operation' => $operation),
                    'required_fields' => $required,
                    'non_empty_fields' => $required,
                    'all_matches' => true,
                    'activation' => array('always' => true),
                );
            }
        }

        $sortIdentity = array('operation_id', 'operation');
        $sortRequirements = array();
        foreach (array('readiness_evaluation', 'schema_readiness') as $operation) {
            foreach (array('start', 'end') as $edge) {
                $required = $edge === 'end'
                    ? array_merge($sortIdentity, array('status', 'result'))
                    : $sortIdentity;
                $sortRequirements[] = array(
                    'id' => 'sort_readiness_' . $operation . '_' . $edge,
                    'event' => 'sort_readiness_operation_' . $edge,
                    'match' => array('operation' => $operation),
                    'required_fields' => $required,
                    'non_empty_fields' => $required,
                    'all_matches' => true,
                    'activation' => array('always' => true),
                );
            }
        }

        return array(
            'template_file_io' => array(
                'profiles' => array('template_file_io'),
                'requirements' => $requirements,
            ),
            'unmatched_template_file_io_support' => array(
                'profiles' => array('unmatched_template_file_io_support'),
                'requirements' => array(array(
                    'id' => 'unmatched_template_file_operation_identity',
                    'event' => 'template_file_operation_start',
                    'required_fields' => $identityFields,
                    'non_empty_fields' => $identityFields,
                    'all_matches' => true,
                    'unmatched_end_event' => 'template_file_operation_end',
                    'activation' => array(
                        'fact' => 'unmatched_template_file_operation_expected',
                        'equals' => true,
                    ),
                )),
            ),
            'routine_log_io' => array(
                'profiles' => array('routine_log_io'),
                'requirements' => $routineRequirements,
            ),
            'sort_readiness_io' => array(
                'profiles' => array('sort_readiness_io'),
                'requirements' => $sortRequirements,
            ),
        );
    }
}
