<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Decisive records naming foreign WordPress shutdown callbacks. */
final class ABJ_404_Solution_DecisiveRecordShutdownFamilies {

    /**
     * @return array<string, array{
     *     emitter:string,
     *     events:array<int,string>,
     *     presence:string,
     *     reserve:array{start:string,end:string}|null,
     *     sentinel:string|null
     * }>
     */
    public static function records(string $always, string $conditional): array {
        $emitter = 'ABJ_404_Solution_ShutdownCallbackTracer';
        return array(
            'shutdown_callback_instrumentation' => array(
                'emitter' => $emitter,
                'events' => array('shutdown_callback_instrumentation'),
                'presence' => $always,
                'reserve' => null,
                'sentinel' => null,
            ),
            'shutdown_callback' => array(
                'emitter' => $emitter,
                'events' => array('shutdown_callback_start', 'shutdown_callback_end'),
                'presence' => $conditional,
                'reserve' => array(
                    'start' => 'shutdown_callback_start',
                    'end' => 'shutdown_callback_end',
                ),
                'sentinel' => 'shutdown_callback_instrumentation.callbacks_wrapped|callbacks_marked',
            ),
        );
    }
}
