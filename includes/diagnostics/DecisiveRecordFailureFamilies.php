<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Decisive records for post-authorization AJAX failure logging. */
final class ABJ_404_Solution_DecisiveRecordFailureFamilies {

    /** @return array<string, array{emitter:string,events:array<int,string>,presence:string,reserve:array{start:string,end:string}|null,sentinel:string|null}> */
    public static function records(string $conditional): array {
        return array(
            'ajax_failure_branch' => array(
                'emitter' => 'ABJ_404_Solution_PostAuthorizationFailureTracer',
                'events' => array('ajax_failure_branch'),
                'presence' => $conditional,
                'reserve' => null,
                'sentinel' => 'failure-path activation: rate_limit_branch or caught Throwable',
            ),
            'ajax_failure_log' => array(
                'emitter' => 'ABJ_404_Solution_PostAuthorizationFailureTracer',
                'events' => array('ajax_failure_log_start', 'ajax_failure_log_end'),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'ajax_failure_log_start',
                    'end' => 'ajax_failure_log_end',
                ),
                'sentinel' => 'ajax_failure_branch',
            ),
            'ajax_failure_log_operation' => array(
                'emitter' => 'ABJ_404_Solution_PostAuthorizationFailureTracer',
                'events' => array(
                    'ajax_failure_log_operation_start',
                    'ajax_failure_log_operation_end',
                ),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'ajax_failure_log_operation_start',
                    'end' => 'ajax_failure_log_operation_end',
                ),
                'sentinel' => 'ajax_failure_branch',
            ),
        );
    }

    /** @return array<string, array<string, mixed>> */
    public static function contracts(): array {
        $outerFields = array('operation_id', 'failure_id', 'branch');
        $operationFields = array('operation_id', 'failure_id', 'branch', 'operation');
        $branchActivation = array('event' => 'ajax_failure_branch');
        $requirements = array(
            array(
                'id' => 'ajax_failure_branch_fingerprint',
                'event' => 'ajax_failure_branch',
                'required_fields' => $outerFields,
                'non_empty_fields' => $outerFields,
                'all_matches' => true,
                'activation' => array(
                    'fact' => 'post_authorization_failure_expected',
                    'equals' => true,
                ),
            ),
            array(
                'id' => 'ajax_failure_log_start_identity',
                'event' => 'ajax_failure_log_start',
                'required_fields' => $outerFields,
                'non_empty_fields' => $outerFields,
                'all_matches' => true,
                'activation' => $branchActivation,
            ),
            array(
                'id' => 'ajax_failure_log_end_identity',
                'event' => 'ajax_failure_log_end',
                'required_fields' => array_merge($outerFields, array('status')),
                'non_empty_fields' => array_merge($outerFields, array('status')),
                'all_matches' => true,
                'activation' => $branchActivation,
            ),
        );
        foreach (array(
            'detail_construction',
            'logger_resolution',
            'line_construction',
            'details_encoding',
            'logger_write_return',
        ) as $operation) {
            $requirements = array_merge(
                $requirements,
                self::operationRequirements($operation, $operationFields, $branchActivation)
            );
        }
        foreach (array(
            'fallback_sanitize',
            'fallback_path_resolution',
            'fallback_write_flush_return',
        ) as $operation) {
            $requirements = array_merge(
                $requirements,
                self::operationRequirements($operation, $operationFields, array(
                    'fact' => 'ajax_failure_fallback_expected',
                    'equals' => true,
                ))
            );
        }
        foreach (array('native_path_resolution', 'native_write_flush_return') as $operation) {
            $requirements = array_merge(
                $requirements,
                self::operationRequirements($operation, $operationFields, array(
                    'fact' => 'ajax_failure_native_logger_expected',
                    'equals' => true,
                ))
            );
        }
        return array(
            'post_authorization_failure' => array(
                'profiles' => array('post_authorization_failure'),
                'requirements' => $requirements,
            ),
            'unmatched_ajax_failure_log_support' => array(
                'profiles' => array('unmatched_ajax_failure_log_support'),
                'requirements' => array(array(
                    'id' => 'unmatched_ajax_failure_log_operation_identity',
                    'event' => 'ajax_failure_log_operation_start',
                    'required_fields' => $operationFields,
                    'non_empty_fields' => $operationFields,
                    'all_matches' => true,
                    'unmatched_end_event' => 'ajax_failure_log_operation_end',
                    'activation' => array(
                        'fact' => 'unmatched_ajax_failure_log_expected',
                        'equals' => true,
                    ),
                )),
            ),
        );
    }

    /**
     * @param array<int, string> $fields
     * @param array<string, mixed> $activation
     * @return array<int, array<string, mixed>>
     */
    private static function operationRequirements(
        string $operation,
        array $fields,
        array $activation
    ): array {
        $requirements = array();
        foreach (array('start', 'end') as $edge) {
            $required = $edge === 'end'
                ? array_merge($fields, array('status'))
                : $fields;
            $requirements[] = array(
                'id' => 'ajax_failure_' . $operation . '_' . $edge,
                'event' => 'ajax_failure_log_operation_' . $edge,
                'match' => array('operation' => $operation),
                'required_fields' => $required,
                'non_empty_fields' => $required,
                'all_matches' => true,
                'activation' => $activation,
            );
        }
        return $requirements;
    }
}
