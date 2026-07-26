<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authorization logging families enrolled in the decisive-record catalog.
 *
 * Kept separate because both families describe one diagnostic boundary and
 * must evolve together when the native logging adapter changes.
 */
final class ABJ_404_Solution_DecisiveRecordAuthorizationFamilies {

    /**
     * @return array<string, array{
     *   emitter: string,
     *   events: array<int, string>,
     *   presence: string,
     *   reserve: array{start: string, end: string},
     *   sentinel: null
     * }>
     */
    public static function records(string $presence): array {
        return array(
            // The outer pair distinguishes a logger call that did not return
            // after its authorized-action line became visible.
            'authorization_log' => array(
                'emitter' => 'ABJ_404_Solution_AuthorizationLogTracer',
                'events' => array('auth_log_start', 'auth_log_end'),
                'presence' => $presence,
                'reserve' => array(
                    'start' => 'auth_log_start',
                    'end' => 'auth_log_end',
                ),
                'sentinel' => null,
            ),
            // The native adapter further identifies destination resolution
            // versus the final write/close call. No message or path is stored.
            'authorization_log_operation' => array(
                'emitter' => 'ABJ_404_Solution_AuthorizationLogTracer',
                'events' => array('auth_log_operation_start', 'auth_log_operation_end'),
                'presence' => $presence,
                'reserve' => array(
                    'start' => 'auth_log_operation_start',
                    'end' => 'auth_log_operation_end',
                ),
                'sentinel' => null,
            ),
        );
    }
}
