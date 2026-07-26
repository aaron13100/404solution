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
        );
    }
}
