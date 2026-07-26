<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Attributes foreign WordPress callbacks in post-options render scopes.
 *
 * The historical record names retain "translation" for support-payload
 * compatibility. The scope is deliberately broader: an `all` observer runs
 * before each named WP_Hook and instruments its live callback registry before
 * WordPress begins that hook. This covers URL, nonce, sanitization, number
 * formatting, and any future hook reached by the bounded render scope without
 * another hand-maintained hook list.
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
    /** @var bool */
    private $scopeActive = false;
    /** @var int */
    private $callbacksAttributed = 0;
    /** @var int */
    private $callbacksUnavailable = 0;
    /** @var bool */
    private $registryUnavailable = false;
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
        return ABJ_404_Solution_RenderOptionIoTracer::traceScope(
            $requestId,
            $phase,
            static function () use ($requestId, $phase, $messageSet, $render) {
                return (new self($requestId, $phase, $messageSet))->run($render);
            }
        );
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
            $this->installCallbacks();
        } catch (Throwable $e) {
            $this->restoreCallbacks(false);
            throw $e;
        }
        $this->scopeActive = true;
        $startedAt = self::nowFloat();
        try {
            $result = $render();
        } catch (Throwable $e) {
            $this->scopeActive = false;
            $this->restoreCallbacks(false);
            throw $e;
        }
        $this->scopeActive = false;
        $this->restoreCallbacks(true);
        $this->write('render_translation_scope_end', array_merge($scopeFields, $this->status(), array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($startedAt),
        )));
        return $result;
    }

    /**
     * Install the global observer after wrapping callbacks already registered
     * directly on `all`. WordPress invokes `all` before the named hook, so the
     * observer can instrument each named registry just in time.
     */
    private function installCallbacks(): void {
        foreach (self::HOOKS as $hook) {
            $this->rememberCounts($this->hookInstrumenter->instrument($hook));
        }

        if (!function_exists('add_filter')) {
            $this->registryUnavailable = true;
            return;
        }
        $this->lifecycleTracer->traceBoundary(
            ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
            'all',
            function (): void {
                add_filter('all', array($this, 'prepareHookCallbacks'), PHP_INT_MIN, 1);
            }
        );
    }

    /**
     * Runs from WordPress's `all` hook before the named hook starts.
     *
     * @param mixed $hookName
     * @return mixed
     */
    public function prepareHookCallbacks($hookName) {
        if (!$this->scopeActive || $this->recording
                || $this->lifecycleTracer->isRecording()
                || (class_exists('ABJ_404_Solution_AjaxCheckpointLogger', false)
                    && ABJ_404_Solution_AjaxCheckpointLogger::isRecording())
                || !is_string($hookName) || $hookName === 'all') {
            return $hookName;
        }
        $this->rememberCounts($this->hookInstrumenter->instrument($hookName));
        return $hookName;
    }

    /** @param array<string, int|string> $counts */
    private function rememberCounts(array $counts): void {
        $this->callbacksAttributed += (int)($counts['callbacks_wrapped'] ?? 0)
            + (int)($counts['callbacks_marked'] ?? 0);
        $this->callbacksUnavailable += (int)($counts['callbacks_unavailable'] ?? 0);
        if (($counts['registry_status'] ?? '') === 'unavailable') {
            $this->registryUnavailable = true;
        }
    }

    /** @return array{callbacks_attributed: int, callbacks_unavailable: int, registry_status: string} */
    private function status(): array {
        return array(
            'callbacks_attributed' => $this->callbacksAttributed,
            'callbacks_unavailable' => $this->callbacksUnavailable,
            'registry_status' => $this->registryUnavailable
                ? 'unavailable'
                : ($this->callbacksUnavailable === 0 ? 'ready' : 'partial'),
        );
    }

    private function restoreCallbacks(bool $scopeCompleted): void {
        if (function_exists('remove_filter')) {
            try {
                $this->lifecycleTracer->traceBoundary(
                    ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REMOVAL,
                    'all',
                    function (): void {
                        remove_filter('all', array($this, 'prepareHookCallbacks'), PHP_INT_MIN);
                    }
                );
            } catch (Throwable $e) {
                abj404_logPhpFallback('table-render-translation-tracer', $e->getMessage());
            }
        }
        $this->hookInstrumenter->restore($scopeCompleted);
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
            'priority' => ABJ_404_Solution_HookCallbackIdentity::jsonSafePriority($priority),
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
