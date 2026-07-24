<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped durable attribution for external work inside table rows.
 *
 * WordPress has no per-callback middleware API: WP_Hook invokes registered
 * callables directly. Object Cache Pro has a callable tracer, but it is fixed
 * in WP_REDIS_CONFIG before ordinary plugins load. This adapter therefore
 * decorates only the live row-render window and restores every changed global
 * afterward. It never runs outside the instrumented table AJAX endpoint.
 *
 * Start/end pairs share one hard record budget. A thrown callback/cache call
 * deliberately leaves its start unmatched, restores the original runtime
 * objects, and rethrows the original error unchanged.
 *
 * PII: hook names, callback identities, source components, cache keys, and
 * cache groups are emitted only as conventional safe names or SHA-256
 * prefixes. Values and callback arguments are never inspected.
 *
 * allow-no-test-found: exercised through the real AJAX table render entry point in tests/AjaxQueryAttributionTest.php
 */
final class ABJ_404_Solution_RowRenderOperationTracer {

    /** Eight complete operations, with start and end records for each. */
    const MAX_OPERATION_RECORDS = 16;

    /** @var string */
    private $requestId;
    /** @var bool */
    private $rowActive = false;
    /** @var bool */
    private $suspended = false;
    /** @var bool */
    private $recording = false;
    /** @var int */
    private $recordCount = 0;
    /** @var int */
    private $operationSequence = 0;
    /** @var bool */
    private $cappedRecorded = false;
    /** @var bool */
    private $unsafeHookRecorded = false;
    /** @var object|null */
    private $originalCache;
    /** @var ABJ_404_Solution_InstrumentedObjectCache|null */
    private $cacheProxy;
    /**
     * @var array<string, array{hook: string, priority: int, id: string, original: callable, wrapper: callable}>
     */
    private $hookWrappers = array();

    public static function begin(string $requestId): self {
        $tracer = new self($requestId);
        $tracer->install();
        return $tracer;
    }

    private function __construct(string $requestId) {
        $this->requestId = $requestId;
    }

    private function install(): void {
        $hookBoundary = 'unavailable';
        if (function_exists('add_filter') && is_array($GLOBALS['wp_filter'] ?? null)) {
            try {
                add_filter('all', array($this, 'prepareHookCallbacks'), PHP_INT_MIN, 1);
                $hookBoundary = 'ready';
            } catch (Throwable $e) {
                self::reportFailure('hook boundary install failed: ' . $e->getMessage());
            }
        }

        $cacheBoundary = 'unavailable';
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        if (is_object($cache) && !$cache instanceof ABJ_404_Solution_InstrumentedObjectCache) {
            $this->originalCache = $cache;
            $this->cacheProxy = new ABJ_404_Solution_InstrumentedObjectCache($cache, $this);
            $cacheBoundary = 'ready';
        }

        $this->write('row_operation_instrumentation', array(
            'hook_boundary' => $hookBoundary,
            'cache_boundary' => $cacheBoundary,
            'max_records' => self::MAX_OPERATION_RECORDS,
        ), false);
    }

    /** Mark the point after the row checkpoint and before row presentation. */
    public function enterRow(): void {
        if (!$this->suspended) {
            $this->rowActive = true;
            if ($this->cacheProxy !== null
                    && ($GLOBALS['wp_object_cache'] ?? null) === $this->originalCache) {
                $GLOBALS['wp_object_cache'] = $this->cacheProxy;
            }
        }
    }

    /** Restore globals after the final row, before aggregate/end checkpoints. */
    public function finish(): void {
        $this->rowActive = false;
        $this->restore();
    }

    /**
     * The callback registered on WordPress's `all` hook. It runs before the
     * named WP_Hook starts, so replacing that hook's callable entries here
     * does not alter an active specific-hook iteration.
     *
     * @param mixed $hookName
     * @return mixed The original all-hook value, which WordPress ignores.
     */
    public function prepareHookCallbacks($hookName) {
        if (!$this->rowActive || $this->suspended || $this->recording
                || !is_string($hookName) || $hookName === 'all') {
            return $hookName;
        }
        $filters = $GLOBALS['wp_filter'] ?? null;
        $hookObject = is_array($filters) ? ($filters[$hookName] ?? null) : null;
        if (!$hookObject instanceof ArrayAccess || !$hookObject instanceof Traversable) {
            return $hookName;
        }

        foreach ($hookObject as $priority => $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $id => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $callback = $entry['function'] ?? null;
                if (!is_callable($callback)) {
                    continue;
                }
                $mapKey = $hookName . '|' . (string)$priority . '|' . (string)$id;
                if (isset($this->hookWrappers[$mapKey])
                        && $callback === $this->hookWrappers[$mapKey]['wrapper']) {
                    continue;
                }
                $identity = self::callbackIdentity($callback);
                if ($identity['has_reference']) {
                    $this->recordUnsafeHookOnce($hookName, $identity);
                    continue;
                }
                $wrapper = function (...$args) use ($hookName, $callback, $identity) {
                    return $this->trace(
                        array(
                            'kind' => 'hook',
                            'hook' => self::redactedName($hookName, 'hook'),
                            'callback' => $identity['callback'],
                            'source' => $identity['source'],
                        ),
                        static fn() => call_user_func_array($callback, $args)
                    );
                };
                $entry['function'] = $wrapper;
                $entries[$id] = $entry;
                $hookObject[$priority] = $entries;
                $this->hookWrappers[$mapKey] = array(
                    'hook' => $hookName,
                    'priority' => (int)$priority,
                    'id' => (string)$id,
                    'original' => $callback,
                    'wrapper' => $wrapper,
                );
            }
        }
        return $hookName;
    }

    /**
     * @param mixed $key
     * @param mixed $group
     * @param callable(): mixed $work
     * @return mixed
     */
    public function traceCache(string $operation, $key, $group, callable $work) {
        return $this->trace(array(
            'kind' => 'cache',
            'operation' => substr(strtolower($operation), 0, 32),
            'key' => self::hashedValue($key, 'key'),
            'group' => self::hashedValue($group, 'group'),
        ), $work);
    }

    /**
     * @param array<string, mixed> $fields
     * @param callable(): mixed $work
     * @return mixed
     */
    private function trace(array $fields, callable $work) {
        if (!$this->rowActive || $this->suspended || $this->recording) {
            return $work();
        }
        if ($this->recordCount + 2 > self::MAX_OPERATION_RECORDS) {
            $this->recordCappedOnce();
            return $work();
        }

        $operationId = substr(hash(
            'sha256',
            $this->requestId . '|' . (++$this->operationSequence) . '|' . serialize($fields)
        ), 0, 12);
        $record = array_merge(array('operation_id' => $operationId), $fields);
        $this->write('row_operation_start', $record, true);
        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->suspended = true;
            $this->restore();
            throw $e;
        }
        $this->write('row_operation_end', $record, true);
        return $result;
    }

    /** @param array{callback: string, source: string, has_reference: bool} $identity */
    private function recordUnsafeHookOnce(string $hookName, array $identity): void {
        if (!$this->rowActive || $this->unsafeHookRecorded) {
            return;
        }
        $this->unsafeHookRecorded = true;
        $this->write('row_operation_unavailable', array(
            'kind' => 'hook',
            'reason' => 'callback_has_reference_parameter',
            'hook' => self::redactedName($hookName, 'hook'),
            'callback' => $identity['callback'],
            'source' => $identity['source'],
        ), false);
    }

    private function recordCappedOnce(): void {
        if ($this->cappedRecorded) {
            return;
        }
        $this->cappedRecorded = true;
        $this->write('row_operation_capped', array(
            'recorded' => $this->recordCount,
            'max_records' => self::MAX_OPERATION_RECORDS,
        ), false);
    }

    /** @param array<string, mixed> $fields */
    private function write(string $event, array $fields, bool $countsTowardBudget): void {
        if ($this->requestId === '' || $this->recording) {
            return;
        }
        $this->recording = true;
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent($this->requestId, $event, $fields);
            if ($countsTowardBudget) {
                $this->recordCount++;
            }
        } catch (Throwable $e) {
            self::reportFailure('operation checkpoint failed: ' . $e->getMessage());
        } finally {
            $this->recording = false;
        }
    }

    private function restore(): void {
        if (function_exists('remove_filter')) {
            try {
                remove_filter('all', array($this, 'prepareHookCallbacks'), PHP_INT_MIN);
            } catch (Throwable $e) {
                self::reportFailure('hook boundary removal failed: ' . $e->getMessage());
            }
        }
        foreach ($this->hookWrappers as $wrapped) {
            $filters = $GLOBALS['wp_filter'] ?? null;
            $hookObject = is_array($filters) ? ($filters[$wrapped['hook']] ?? null) : null;
            if (!$hookObject instanceof ArrayAccess || !isset($hookObject[$wrapped['priority']])) {
                continue;
            }
            $entries = $hookObject[$wrapped['priority']];
            if (!is_array($entries)) {
                continue;
            }
            $entry = $entries[$wrapped['id']] ?? null;
            $current = is_array($entry) ? ($entry['function'] ?? null) : null;
            if ($current !== $wrapped['wrapper'] || !is_array($entry)) {
                continue;
            }
            $entry['function'] = $wrapped['original'];
            $entries[$wrapped['id']] = $entry;
            $hookObject[$wrapped['priority']] = $entries;
        }
        $this->hookWrappers = array();
        if ($this->cacheProxy !== null && ($GLOBALS['wp_object_cache'] ?? null) === $this->cacheProxy) {
            $GLOBALS['wp_object_cache'] = $this->originalCache;
        }
    }

    /**
     * @param callable $callback
     * @return array{callback: string, source: string, has_reference: bool}
     */
    private static function callbackIdentity(callable $callback): array {
        $descriptor = 'callable';
        $source = 'runtime';
        $hasReference = false;
        try {
            if (is_array($callback)) {
                $owner = is_object($callback[0]) ? get_class($callback[0]) : (string)$callback[0];
                $descriptor = $owner . '::' . (string)$callback[1];
                $reflection = new ReflectionMethod($callback[0], (string)$callback[1]);
            } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                $descriptor = $callback;
                $reflection = new ReflectionMethod($callback);
            } elseif (is_string($callback)) {
                $descriptor = $callback;
                $reflection = new ReflectionFunction($callback);
            } elseif ($callback instanceof Closure) {
                $descriptor = 'closure';
                $reflection = new ReflectionFunction($callback);
            } elseif (is_object($callback)) {
                $descriptor = get_class($callback) . '::__invoke';
                $reflection = new ReflectionMethod($callback, '__invoke');
            } else {
                $reflection = new ReflectionFunction(Closure::fromCallable($callback));
            }
            foreach ($reflection->getParameters() as $parameter) {
                $hasReference = $hasReference || $parameter->isPassedByReference();
            }
            $source = self::sourceIdentity((string)$reflection->getFileName());
            $descriptor .= '|' . (string)$reflection->getStartLine() . '|' . $source;
        } catch (Throwable $e) {
            self::reportFailure(
                'callback reflection failed (' . get_class($e) . '): ' . $e->getMessage()
            );
            $descriptor .= '|reflection-unavailable|' . get_class($e);
            $source = 'unavailable';
        }
        return array(
            'callback' => 'cb#' . substr(hash('sha256', $descriptor), 0, 12),
            'source' => $source,
            'has_reference' => $hasReference,
        );
    }

    private static function sourceIdentity(string $file): string {
        $normalized = str_replace('\\', '/', $file);
        foreach (array('plugins' => 'plugin', 'mu-plugins' => 'mu', 'themes' => 'theme') as $part => $label) {
            if (preg_match('#/wp-content/' . $part . '/([^/]+)#i', $normalized, $match) === 1) {
                return $label . '#' . substr(hash('sha256', strtolower($match[1])), 0, 12);
            }
        }
        if (strpos($normalized, '/wp-includes/') !== false || strpos($normalized, '/wp-admin/') !== false) {
            return 'wordpress-core';
        }
        return $normalized === '' ? 'php-runtime'
            : 'source#' . substr(hash('sha256', $normalized), 0, 12);
    }

    private static function redactedName(string $value, string $prefix): string {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]{0,63}$/', $value) === 1) {
            return preg_replace('/[0-9]+/', '#', $value) ?? '';
        }
        return $prefix . '#' . substr(hash('sha256', $value), 0, 12);
    }

    /** @param mixed $value */
    private static function hashedValue($value, string $prefix): string {
        if (is_scalar($value) || $value === null) {
            $serialized = (string)$value;
        } elseif (is_array($value)) {
            $serialized = serialize($value);
        } else {
            $serialized = gettype($value);
        }
        return $prefix . '#' . substr(hash('sha256', $serialized), 0, 12);
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('row-render-operation', $message);
    }
}
