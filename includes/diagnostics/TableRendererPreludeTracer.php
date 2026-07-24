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
    /**
     * @var array<string, array{hook: string, priority: int, id: string, original: callable, wrapper: callable}>
     */
    private $hookWrappers = array();

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
            $tracer->restoreHookCallbacks();
            self::reportFailure('hook registry scan failed: ' . $e->getMessage());
            $hookStatus = array(
                'status' => 'unavailable',
                'reason' => 'hook_registry_scan_failed',
                'hooks_scanned' => count(self::TRANSLATION_HOOKS),
                'callbacks_wrapped' => 0,
                'callbacks_unavailable' => 0,
            );
        }
        try {
            $tracer->locale = $tracer->traceOperation(
                'locale_resolution',
                static fn(): string => self::normalizeLocale(determine_locale())
            );
        } catch (Throwable $e) {
            $tracer->restoreHookCallbacks();
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
    }

    public function finish(): void {
        $this->restoreHookCallbacks();
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
            $work,
            false
        );
    }

    /**
     * @return array{status: string, reason?: string, hooks_scanned: int, callbacks_wrapped: int, callbacks_unavailable: int}
     */
    private function installHookCallbacks(): array {
        $filters = $GLOBALS['wp_filter'] ?? null;
        if (!is_array($filters)) {
            return array(
                'status' => 'unavailable',
                'reason' => 'hook_registry_unavailable',
                'hooks_scanned' => count(self::TRANSLATION_HOOKS),
                'callbacks_wrapped' => 0,
                'callbacks_unavailable' => 0,
            );
        }
        $wrapped = 0;
        $unavailable = 0;
        foreach (self::TRANSLATION_HOOKS as $hookName) {
            $hookObject = $filters[$hookName] ?? null;
            if ($hookObject === null) {
                continue;
            }
            if (!$hookObject instanceof ArrayAccess || !$hookObject instanceof Traversable) {
                $unavailable++;
                continue;
            }
            foreach ($hookObject as $priority => $entries) {
                if (!is_array($entries)) {
                    $unavailable++;
                    continue;
                }
                foreach ($entries as $id => $entry) {
                    $callback = is_array($entry) ? ($entry['function'] ?? null) : null;
                    if (!is_callable($callback)) {
                        $unavailable++;
                        continue;
                    }
                    $identity = ABJ_404_Solution_HookCallbackIdentity::describe($callback);
                    if ($identity['has_reference']) {
                        $unavailable++;
                        $this->write('table_prelude_hook_callback_unavailable', array(
                            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($hookName),
                            'callback' => $identity['callback'],
                            'source' => $identity['source'],
                            'locale' => $this->locale,
                            'reason' => 'callback_has_reference_parameter',
                        ));
                        continue;
                    }
                    $wrapper = $this->callbackWrapper($hookName, (int)$priority, $callback, $identity);
                    $entry['function'] = $wrapper;
                    $entries[$id] = $entry;
                    $hookObject[$priority] = $entries;
                    $key = $hookName . '|' . (string)$priority . '|' . (string)$id;
                    $this->hookWrappers[$key] = array(
                        'hook' => $hookName, 'priority' => (int)$priority, 'id' => (string)$id,
                        'original' => $callback, 'wrapper' => $wrapper,
                    );
                    $wrapped++;
                }
            }
        }
        return array(
            'status' => $unavailable === 0 ? 'ready' : 'partial',
            'hooks_scanned' => count(self::TRANSLATION_HOOKS),
            'callbacks_wrapped' => $wrapped,
            'callbacks_unavailable' => $unavailable,
        );
    }

    /**
     * @param array{callback: string, source: string, has_reference: bool} $identity
     */
    private function callbackWrapper(
        string $hook,
        int $priority,
        callable $callback,
        array $identity
    ): callable {
        return function (...$args) use ($hook, $priority, $callback, $identity) {
            return $this->trace(
                'table_prelude_hook_callback',
                array(
                    'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($hook),
                    'callback' => $identity['callback'],
                    'source' => $identity['source'],
                    'priority' => $priority,
                ),
                static fn() => call_user_func_array($callback, $args),
                true
            );
        };
    }

    /**
     * @template T
     * @param array<string, mixed> $fields
     * @param callable():T $work
     * @return T
     */
    private function trace(string $eventPrefix, array $fields, callable $work, bool $boundedCallback) {
        if ($this->recording) {
            return $work();
        }
        $fields['locale'] = $this->locale;
        $fields['operation_id'] = $this->operationId($eventPrefix, $fields);
        if ($boundedCallback && $this->callbackRecordCount + 2 > self::MAX_CALLBACK_RECORDS) {
            $this->recordCallbackCapOnce();
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId, $eventPrefix, 'active', $fields);
            $result = $work();
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId, $eventPrefix, 'complete', $fields);
            return $result;
        }
        $this->write($eventPrefix . '_start', $fields);
        if ($boundedCallback) {
            $this->callbackRecordCount++;
        }
        $startedAt = function_exists('abj_clock') ? abj_clock()->nowFloat() : null;
        $result = $work();
        $this->write($eventPrefix . '_end', array_merge($fields, array(
            'status' => 'complete',
            'elapsed_ms' => $startedAt === null ? null
                : max(0, (int)round((abj_clock()->nowFloat() - $startedAt) * 1000)),
        )));
        if ($boundedCallback) {
            $this->callbackRecordCount++;
        }
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

    private function restoreHookCallbacks(): void {
        foreach ($this->hookWrappers as $wrapped) {
            $filters = $GLOBALS['wp_filter'] ?? null;
            $hookObject = is_array($filters) ? ($filters[$wrapped['hook']] ?? null) : null;
            if (!$hookObject instanceof ArrayAccess || !isset($hookObject[$wrapped['priority']])) {
                continue;
            }
            $entries = $hookObject[$wrapped['priority']];
            $entry = is_array($entries) ? ($entries[$wrapped['id']] ?? null) : null;
            if (!is_array($entry) || ($entry['function'] ?? null) !== $wrapped['wrapper']) {
                continue;
            }
            $entry['function'] = $wrapped['original'];
            $entries[$wrapped['id']] = $entry;
            $hookObject[$wrapped['priority']] = $entries;
        }
        $this->hookWrappers = array();
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
