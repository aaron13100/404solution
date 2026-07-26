<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded attribution for table work that runs before row-loop diagnostics.
 *
 * The tracer is active only inside an instrumented table AJAX request. It
 * forces the plugin textdomain's lazy load into an explicit operation, wraps
 * callbacks on the locale/translation hooks WordPress can dispatch from that
 * load, and brackets each table-option resolution step. Callback arguments,
 * translated strings, and filesystem paths are never recorded.
 */
final class ABJ_404_Solution_TableRendererPreludeTracer {

    const TRANSLATION_HOOKS = array(
        'pre_determine_locale',
        'determine_locale',
        'locale',
        'lang_dir_for_domain',
        'pre_load_textdomain',
        'override_load_textdomain',
        'load_textdomain',
        'load_textdomain_mofile',
        'translation_file_format',
        'load_translation_file',
        'gettext',
        'gettext_404-solution',
    );
    const MAX_CALLBACK_RECORDS = 64;

    /** @var string */
    private $requestId;
    /** @var string */
    private $locale;
    /** @var int */
    private $operationSequence = 0;
    /** @var int */
    private $callbackRecordCount = 0;
    /** @var bool */
    private $recording = false;
    /** @var bool */
    private $callbackCapRecorded = false;
    /** @var bool */
    private $scopeFailed = false;
    /** @var ABJ_404_Solution_HookCallbackInstrumenter<array{mode: string, fields: array<string, mixed>, started_at?: float|null}|null> */
    private $hookInstrumenter;
    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;

    public static function begin(): ?self {
        $requestId = class_exists('ABJ_404_Solution_AjaxRequestLedger')
            ? ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext()
            : '';
        if ($requestId === '') {
            return null;
        }
        $tracer = new self($requestId, self::initialLocaleHint());
        try {
            $hookStatus = $tracer->installHookCallbacks();
        } catch (Throwable $e) {
            $tracer->restoreHookCallbacks(false);
            self::reportFailure('hook registry scan failed: ' . $e->getMessage());
            $hookStatus = array(
                'status' => 'unavailable',
                'reason' => 'hook_registry_scan_failed',
                'hooks_scanned' => count(self::TRANSLATION_HOOKS),
                'callbacks_wrapped' => 0,
                'callbacks_marked' => 0,
                'callbacks_attributed' => 0,
                'callbacks_unavailable' => 0,
            );
        }
        try {
            $tracer->locale = $tracer->traceOperation(
                'locale_resolution',
                static fn(): string => self::normalizeLocale(determine_locale())
            );
        } catch (Throwable $e) {
            $tracer->restoreHookCallbacks(false);
            throw $e;
        }
        $tracer->write('table_prelude_instrumentation', array_merge($hookStatus, array(
            'locale' => $tracer->locale,
            'domain_load' => 'ready',
            'max_callback_records' => self::MAX_CALLBACK_RECORDS,
        )));
        return $tracer;
    }

    private function __construct(string $requestId, string $locale) {
        $this->requestId = $requestId;
        $this->locale = $locale;
        $this->lifecycleTracer = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'table_renderer_prelude'
        );
        $this->hookInstrumenter = new ABJ_404_Solution_HookCallbackInstrumenter(
            function (
                string $registeredHook,
                string $actualHook,
                int $priority,
                array $identity
            ) {
                return $this->beginHookCallback($actualHook, $priority, $identity);
            },
            function ($token): void {
                $this->finishHookCallback($token);
            },
            $this->lifecycleTracer
        );
    }

    public function finish(): void {
        $this->restoreHookCallbacks(!$this->scopeFailed);
    }

    /** Force WordPress's JIT textdomain load into its own durable boundary. */
    public function prepareTranslationDomain(): void {
        $this->traceOperation(
            'translation_domain_load',
            static fn() => get_translations_for_domain('404-solution')
        );
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function traceOperation(string $operation, callable $work) {
        return $this->trace(
            'table_prelude_operation',
            array('operation' => substr($operation, 0, 64)),
            $work
        );
    }

    /**
     * @return array{status: string, reason?: string, hooks_scanned: int, callbacks_wrapped: int, callbacks_marked?: int, callbacks_attributed?: int, callbacks_unavailable: int}
     */
    private function installHookCallbacks(): array {
        $wrapped = 0;
        $marked = 0;
        $unavailable = 0;
        $registryUnavailable = false;
        $reason = '';
        foreach (self::TRANSLATION_HOOKS as $hookName) {
            $counts = $this->hookInstrumenter->instrument($hookName);
            $wrapped += $counts['callbacks_wrapped'];
            $marked += $counts['callbacks_marked'];
            $unavailable += $counts['callbacks_unavailable'];
            if ($counts['registry_status'] === 'unavailable') {
                $registryUnavailable = true;
                $reason = (string)($counts['registry_reason'] ?? 'hook_registry_unavailable');
            }
        }
        $status = array(
            'status' => $registryUnavailable
                ? 'unavailable'
                : ($unavailable === 0 ? 'ready' : 'partial'),
            'hooks_scanned' => count(self::TRANSLATION_HOOKS),
            'callbacks_wrapped' => $wrapped,
            'callbacks_marked' => $marked,
            'callbacks_attributed' => $wrapped + $marked,
            'callbacks_unavailable' => $unavailable,
        );
        if ($reason !== '') {
            $status['reason'] = $reason;
        }
        return $status;
    }

    /**
     * @param array{callback: string, source: string, has_reference: bool} $identity
     * @return array{mode: string, fields: array<string, mixed>, started_at?: float|null}|null
     */
    private function beginHookCallback(
        string $hook,
        int $priority,
        array $identity
    ): ?array {
        if ($this->recording || $this->lifecycleTracer->isRecording()) {
            return null;
        }
        $fields = array(
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($hook),
            'callback' => $identity['callback'],
            'source' => $identity['source'],
            'priority' => ABJ_404_Solution_HookCallbackIdentity::jsonSafePriority($priority),
            'locale' => $this->locale,
        );
        $fields['operation_id'] = $this->operationId('table_prelude_hook_callback', $fields);
        if ($this->callbackRecordCount + 2 > self::MAX_CALLBACK_RECORDS) {
            $this->recordCallbackCapOnce();
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'table_prelude_hook_callback',
                'active',
                $fields
            );
            return array('mode' => 'active', 'fields' => $fields);
        }
        $this->write('table_prelude_hook_callback_start', $fields);
        $this->callbackRecordCount++;
        return array(
            'mode' => 'journal',
            'fields' => $fields,
            'started_at' => function_exists('abj_clock') ? abj_clock()->nowFloat() : null,
        );
    }

    /**
     * @param array{mode: string, fields: array<string, mixed>, started_at?: float|null}|null $token
     */
    private function finishHookCallback($token): void {
        if (!is_array($token)) {
            return;
        }
        if ($token['mode'] === 'active') {
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'table_prelude_hook_callback',
                'complete',
                $token['fields']
            );
            return;
        }
        $startedAt = $token['started_at'] ?? null;
        $this->write('table_prelude_hook_callback_end', array_merge($token['fields'], array(
            'status' => 'complete',
            'elapsed_ms' => $startedAt === null ? null
                : max(0, (int)round((abj_clock()->nowFloat() - $startedAt) * 1000)),
        )));
        $this->callbackRecordCount++;
    }

    /**
     * @template T
     * @param array<string, mixed> $fields
     * @param callable():T $work
     * @return T
     */
    private function trace(string $eventPrefix, array $fields, callable $work) {
        if ($this->recording) {
            return $work();
        }
        $fields['locale'] = $this->locale;
        $fields['operation_id'] = $this->operationId($eventPrefix, $fields);
        $this->write($eventPrefix . '_start', $fields);
        $startedAt = function_exists('abj_clock') ? abj_clock()->nowFloat() : null;
        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->scopeFailed = true;
            throw $e;
        }
        $this->write($eventPrefix . '_end', array_merge($fields, array(
            'status' => 'complete',
            'elapsed_ms' => $startedAt === null ? null
                : max(0, (int)round((abj_clock()->nowFloat() - $startedAt) * 1000)),
        )));
        return $result;
    }

    /** @param array<string, mixed> $fields */
    private function operationId(string $eventPrefix, array $fields): string {
        $this->operationSequence++;
        return substr(hash('sha256',
            $this->requestId . '|' . $this->operationSequence . '|' . $eventPrefix . '|' . serialize($fields)
        ), 0, 12);
    }

    private function recordCallbackCapOnce(): void {
        if ($this->callbackCapRecorded) {
            return;
        }
        $this->callbackCapRecorded = true;
        $this->write('table_prelude_hook_callback_capped', array(
            'locale' => $this->locale,
            'recorded' => $this->callbackRecordCount,
            'max_records' => self::MAX_CALLBACK_RECORDS,
        ));
    }

    /** @param array<string, mixed> $fields */
    private function write(string $event, array $fields): void {
        if ($this->recording) {
            return;
        }
        $this->recording = true;
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent($this->requestId, $event, $fields);
        } catch (Throwable $e) {
            self::reportFailure('checkpoint write failed: ' . $e->getMessage());
        } finally {
            $this->recording = false;
        }
    }

    private function restoreHookCallbacks(bool $scopeCompleted = true): void {
        $this->hookInstrumenter->restore($scopeCompleted);
    }

    private static function initialLocaleHint(): string {
        foreach (array(
            $GLOBALS['abj404_plugin_language_override'] ?? null,
            $GLOBALS['locale'] ?? null,
        ) as $locale) {
            if (is_scalar($locale) && (string)$locale !== '') {
                return self::normalizeLocale($locale);
            }
        }
        return 'unresolved';
    }

    /** @param mixed $locale */
    private static function normalizeLocale($locale): string {
        $locale = is_scalar($locale) ? (string)$locale : '';
        return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $locale) === 1 ? $locale : 'unavailable';
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('table-renderer-prelude-tracer', $message);
    }
}
