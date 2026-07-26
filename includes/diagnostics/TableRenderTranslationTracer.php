<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Attributes foreign translation callbacks in post-options render scopes.
 */
final class ABJ_404_Solution_TableRenderTranslationTracer {

    const HOOKS = array(
        'all',
        'gettext',
        'gettext_404-solution',
        'ngettext',
        'ngettext_404-solution',
    );
    const MAX_CALLBACK_RECORDS = 64;

    /** @var int */
    private static $scopeSequence = 0;
    /** @var string */
    private $requestId;
    /** @var string */
    private $phase;
    /** @var string */
    private $messageSetHash;
    /** @var string */
    private $locale;
    /** @var string */
    private $scopeOperationId;
    /** @var int */
    private $operationSequence = 0;
    /** @var int */
    private $callbackRecordCount = 0;
    /** @var bool */
    private $recording = false;
    /** @var bool */
    private $capRecorded = false;
    /** @var ABJ_404_Solution_HookCallbackInstrumenter<array{mode: string, fields: array<string, mixed>, started_at?: float|null}|null> */
    private $hookInstrumenter;
    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;

    /**
     * @template T
     * @param callable():T $render
     * @return T
     */
    public static function traceScope(string $phase, string $messageSet, callable $render) {
        $requestId = class_exists('ABJ_404_Solution_AjaxRequestLedger')
            ? ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext()
            : '';
        if ($requestId === '') {
            return $render();
        }
        return (new self($requestId, $phase, $messageSet))->run($render);
    }

    private function __construct(string $requestId, string $phase, string $messageSet) {
        $this->requestId = $requestId;
        $this->phase = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($phase)) ?: 'unknown', 0, 48);
        $this->messageSetHash = substr(hash('sha256', $messageSet), 0, 16);
        $this->locale = self::localeHint();
        $this->scopeOperationId = $this->operationId('scope', ++self::$scopeSequence);
        $this->lifecycleTracer = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'table_render_translation'
        );
        $this->hookInstrumenter = new ABJ_404_Solution_HookCallbackInstrumenter(
            function (
                string $registeredHook,
                string $actualHook,
                int $priority,
                array $identity,
                int $callbackOrdinal
            ) {
                return $this->beginCallback(
                    $registeredHook,
                    $actualHook,
                    $priority,
                    $callbackOrdinal,
                    $identity
                );
            },
            function ($token): void {
                $this->finishCallback($token);
            },
            $this->lifecycleTracer
        );
    }

    /**
     * @template T
     * @param callable():T $render
     * @return T
     */
    private function run(callable $render) {
        $scopeFields = $this->scopeFields();
        $this->write('render_translation_scope_start', $scopeFields);
        try {
            $status = $this->installCallbacks();
        } catch (Throwable $e) {
            $this->hookInstrumenter->restore(false);
            throw $e;
        }
        $startedAt = self::nowFloat();
        try {
            $result = $render();
        } catch (Throwable $e) {
            $this->hookInstrumenter->restore(false);
            throw $e;
        }
        $this->hookInstrumenter->restore(true);
        $this->write('render_translation_scope_end', array_merge($scopeFields, $status, array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($startedAt),
        )));
        return $result;
    }

    /**
     * @return array{callbacks_attributed: int, callbacks_unavailable: int, registry_status: string}
     */
    private function installCallbacks(): array {
        $attributed = 0;
        $unavailable = 0;
        $registryUnavailable = false;
        foreach (self::HOOKS as $hook) {
            $counts = $this->hookInstrumenter->instrument($hook);
            $attributed += $counts['callbacks_wrapped'] + $counts['callbacks_marked'];
            $unavailable += $counts['callbacks_unavailable'];
            if ($counts['registry_status'] === 'unavailable') {
                $registryUnavailable = true;
            }
        }
        return array(
            'callbacks_attributed' => $attributed,
            'callbacks_unavailable' => $unavailable,
            'registry_status' => $registryUnavailable
                ? 'unavailable'
                : ($unavailable === 0 ? 'ready' : 'partial'),
        );
    }

    /**
     * @param array{callback: string, source: string, has_reference: bool} $identity
     * @return array{mode: string, fields: array<string, mixed>, started_at?: float|null}|null
     */
    private function beginCallback(
        string $registeredHook,
        string $actualHook,
        int $priority,
        int $callbackOrdinal,
        array $identity
    ): ?array {
        if ($this->recording || $this->lifecycleTracer->isRecording()) {
            return null;
        }
        $fields = array_merge($this->scopeFields(), array(
            'registered_hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($registeredHook),
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($actualHook),
            'callback' => $identity['callback'],
            'source' => $identity['source'],
            'priority' => $priority,
            'callback_ordinal' => $callbackOrdinal,
            'operation_id' => $this->operationId('callback', $callbackOrdinal),
        ));
        if ($this->callbackRecordCount + 2 > self::MAX_CALLBACK_RECORDS) {
            $this->recordCapOnce();
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'render_translation_callback',
                'active',
                $fields
            );
            return array('mode' => 'active', 'fields' => $fields);
        }
        $this->write('render_translation_callback_start', $fields);
        $this->callbackRecordCount++;
        return array('mode' => 'journal', 'fields' => $fields, 'started_at' => self::nowFloat());
    }

    /** @param array{mode: string, fields: array<string, mixed>, started_at?: float|null}|null $token */
    private function finishCallback($token): void {
        if (!is_array($token)) {
            return;
        }
        if ($token['mode'] === 'active') {
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'render_translation_callback',
                'complete',
                $token['fields']
            );
            return;
        }
        $this->write('render_translation_callback_end', array_merge($token['fields'], array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($token['started_at'] ?? null),
        )));
        $this->callbackRecordCount++;
    }

    private function recordCapOnce(): void {
        if ($this->capRecorded) {
            return;
        }
        $this->capRecorded = true;
        $this->write('render_translation_callback_capped', array_merge($this->scopeFields(), array(
            'recorded' => $this->callbackRecordCount,
            'max_records' => self::MAX_CALLBACK_RECORDS,
        )));
    }

    /** @return array<string, mixed> */
    private function scopeFields(): array {
        return array(
            'operation_id' => $this->scopeOperationId,
            'phase' => $this->phase,
            'locale' => $this->locale,
            'message_set_hash' => $this->messageSetHash,
        );
    }

    private function operationId(string $kind, int $ordinal): string {
        $this->operationSequence++;
        return substr(hash(
            'sha256',
            $this->requestId . '|' . $this->phase . '|' . $kind . '|'
                . $ordinal . '|' . $this->operationSequence
        ), 0, 12);
    }

    /** @param array<string, mixed> $fields */
    private function write(string $event, array $fields): void {
        if ($this->recording) {
            return;
        }
        $this->recording = true;
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $this->requestId,
                $event,
                $fields
            );
        } catch (Throwable $e) {
            abj404_logPhpFallback('table-render-translation-tracer', $e->getMessage());
        } finally {
            $this->recording = false;
        }
    }

    private static function localeHint(): string {
        $locale = $GLOBALS['locale'] ?? (defined('WPLANG') ? WPLANG : '');
        return is_string($locale) ? substr($locale, 0, 24) : '';
    }

    private static function nowFloat(): ?float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        return class_exists('ABJ_404_Solution_SystemClock')
            ? (new ABJ_404_Solution_SystemClock())->nowFloat()
            : null;
    }

    private static function elapsedMilliseconds(?float $startedAt): ?int {
        $now = self::nowFloat();
        return $startedAt === null || $now === null
            ? null
            : max(0, (int)round(($now - $startedAt) * 1000));
    }
}
