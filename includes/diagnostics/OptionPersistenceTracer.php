<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable attribution for rows-per-page option persistence.
 *
 * The tracer is active only during ajaxUpdatePaginationLinks. It brackets
 * preference normalization, option reads, storage-contract normalization,
 * WordPress storage/cache work, repository cache refresh, and every callback
 * registered on the relevant option lifecycle hooks. Values and callback
 * arguments are never inspected or persisted.
 *
 * A callback or operation that does not return leaves its start record
 * unmatched. Every decorated callback is restored in a finally path without
 * overwriting callbacks another participant changed while the hook ran.
 */
final class ABJ_404_Solution_OptionPersistenceTracer {

    const OPTION_HOOKS = array(
        'all',
        'pre_update_option_abj404_settings',
        'pre_update_option',
        'update_option',
        'update_option_abj404_settings',
        'updated_option',
    );

    /** @var self|null */
    private static $active = null;
    /** @var string */
    private $requestId;
    /** @var int */
    private $operationSequence = 0;
    /** @var int */
    private $scopeDepth = 1;
    /** @var bool */
    private $recording = false;
    /**
     * @var array<string, array{hook: string, priority: int, id: string, original: callable, wrapper: callable}>
     */
    private $hookWrappers = array();

    public static function begin(): ?self {
        $requestId = self::currentRequestId();
        if ($requestId === '') {
            return null;
        }
        if (self::$active !== null && self::$active->requestId === $requestId) {
            self::$active->scopeDepth++;
            return self::$active;
        }
        self::$active = new self($requestId);
        return self::$active;
    }

    private function __construct(string $requestId) {
        $this->requestId = $requestId;
    }

    public function finish(): void {
        $this->scopeDepth--;
        if ($this->scopeDepth > 0) {
            return;
        }
        $this->restoreHookCallbacks();
        if (self::$active === $this) {
            self::$active = null;
        }
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function traceCurrent(string $operation, callable $work) {
        return self::$active === null
            ? $work()
            : self::$active->traceOperation($operation, $work);
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function traceCurrentStorageWrite(callable $work) {
        if (self::$active === null) {
            return $work();
        }
        return self::$active->traceStorageWrite($work);
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function traceOperation(string $operation, callable $work) {
        return $this->trace(
            'option_operation',
            array('operation' => substr($operation, 0, 64)),
            $work
        );
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function traceStorageWrite(callable $work) {
        $this->installHookCallbacks();
        try {
            return $this->traceOperation('storage_write_cache_invalidation', $work);
        } finally {
            $this->restoreHookCallbacks();
        }
    }

    private function installHookCallbacks(): void {
        $filters = $GLOBALS['wp_filter'] ?? null;
        if (!is_array($filters)) {
            $this->write('option_hook_instrumentation', array(
                'status' => 'unavailable',
                'reason' => 'hook_registry_unavailable',
                'hooks_scanned' => count(self::OPTION_HOOKS),
            ));
            return;
        }

        $wrappedCount = 0;
        $unavailableCount = 0;
        foreach (self::OPTION_HOOKS as $hookName) {
            $hookObject = $filters[$hookName] ?? null;
            if ($hookObject === null) {
                continue;
            }
            if (!$hookObject instanceof ArrayAccess || !$hookObject instanceof Traversable) {
                $unavailableCount++;
                continue;
            }
            foreach ($hookObject as $priority => $entries) {
                if (!is_array($entries)) {
                    $unavailableCount++;
                    continue;
                }
                foreach ($entries as $id => $entry) {
                    $callback = is_array($entry) ? ($entry['function'] ?? null) : null;
                    if (!is_callable($callback)) {
                        $unavailableCount++;
                        continue;
                    }
                    $identity = ABJ_404_Solution_HookCallbackIdentity::describe($callback);
                    if ($identity['has_reference']) {
                        $unavailableCount++;
                        $this->write('option_hook_callback_unavailable', array(
                            'reason' => 'callback_has_reference_parameter',
                            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($hookName),
                            'callback' => $identity['callback'],
                            'source' => $identity['source'],
                        ));
                        continue;
                    }
                    $wrapper = $this->callbackWrapper(
                        $hookName,
                        (int)$priority,
                        $callback,
                        $identity
                    );
                    $entry['function'] = $wrapper;
                    $entries[$id] = $entry;
                    $hookObject[$priority] = $entries;
                    $mapKey = $hookName . '|' . (string)$priority . '|' . (string)$id;
                    $this->hookWrappers[$mapKey] = array(
                        'hook' => $hookName,
                        'priority' => (int)$priority,
                        'id' => (string)$id,
                        'original' => $callback,
                        'wrapper' => $wrapper,
                    );
                    $wrappedCount++;
                }
            }
        }
        $this->write('option_hook_instrumentation', array(
            'status' => $unavailableCount === 0 ? 'ready' : 'partial',
            'hooks_scanned' => count(self::OPTION_HOOKS),
            'callbacks_wrapped' => $wrappedCount,
            'callbacks_unavailable' => $unavailableCount,
        ));
    }

    /**
     * @param callable $callback
     * @param array{callback: string, source: string, has_reference: bool} $identity
     * @return callable
     */
    private function callbackWrapper(
        string $registeredHook,
        int $priority,
        callable $callback,
        array $identity
    ): callable {
        return function (...$args) use ($registeredHook, $priority, $callback, $identity) {
            $actualHook = $registeredHook;
            if ($registeredHook === 'all' && is_string($args[0] ?? null)) {
                $actualHook = $args[0];
            }
            return $this->trace(
                'option_hook_callback',
                array(
                    'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($actualHook),
                    'callback' => $identity['callback'],
                    'source' => $identity['source'],
                    'priority' => $priority,
                ),
                static fn() => call_user_func_array($callback, $args)
            );
        };
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
        $fields['operation_id'] = $this->operationId($eventPrefix, $fields);
        $this->write($eventPrefix . '_start', $fields);
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $e) {
            throw $e;
        }
        $this->write($eventPrefix . '_end', array_merge($fields, array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($startedAt),
        )));
        return $result;
    }

    /** @param array<string, mixed> $fields */
    private function operationId(string $eventPrefix, array $fields): string {
        $this->operationSequence++;
        return substr(hash(
            'sha256',
            $this->requestId . '|' . $this->operationSequence . '|' . $eventPrefix . '|' . serialize($fields)
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
            $current = is_array($entry) ? ($entry['function'] ?? null) : null;
            if ($current !== $wrapped['wrapper'] || !is_array($entry)) {
                continue;
            }
            $entry['function'] = $wrapped['original'];
            $entries[$wrapped['id']] = $entry;
            $hookObject[$wrapped['priority']] = $entries;
        }
        $this->hookWrappers = array();
    }

    private static function currentRequestId(): string {
        if (!class_exists('ABJ_404_Solution_AjaxRequestLedger')) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext();
    }

    private static function nowFloat(): ?float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        if (class_exists('ABJ_404_Solution_SystemClock')) {
            return (new ABJ_404_Solution_SystemClock())->nowFloat();
        }
        return null;
    }

    private static function elapsedMilliseconds(?float $startedAt): ?int {
        return $startedAt === null
            ? null
            : max(0, (int)round((self::nowFloat() - $startedAt) * 1000));
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('option-persistence-tracer', $message);
    }
}
