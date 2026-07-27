<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped attribution for pre-query sort-readiness evaluation.
 *
 * The readiness authority probes live schema and reads two migration latches
 * before every redirects/captured query. During a latch read this tracer also
 * decorates the relevant WordPress option callbacks and object-cache object,
 * restoring both even when platform code throws. Option and cache values are
 * never recorded.
 */
final class ABJ_404_Solution_SortReadinessTracer
    implements ABJ_404_Solution_CacheOperationTraceSink {

    /** @var int */
    private static $operationSequence = 0;
    /** @var bool */
    private static $recording = false;
    /** @var array<int,string> */
    private static $operationStack = array();

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    public static function trace(string $operation, array $fields, callable $work) {
        $requestId = self::requestId();
        if ($requestId === '' || self::$recording) {
            return $work();
        }
        $tracer = new self($requestId);
        return $operation === 'latch_option_read'
            ? $tracer->traceLatchRead($operation, $fields, $work)
            : $tracer->traceOperation($operation, $fields, $work);
    }

    /** @var string */
    private $requestId;
    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;
    /** @var ABJ_404_Solution_HookCallbackInstrumenter<array<string,mixed>> */
    private $hookInstrumenter;
    /** @var object|null */
    private $originalCache;
    /** @var ABJ_404_Solution_InstrumentedObjectCache|null */
    private $cacheProxy;

    private function __construct(string $requestId) {
        $this->requestId = $requestId;
        $this->lifecycleTracer = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'sort_readiness'
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
                $this->finishToken($token);
            },
            $this->lifecycleTracer
        );
    }

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    private function traceLatchRead(string $operation, array $fields, callable $work) {
        $option = isset($fields['option']) && is_string($fields['option'])
            ? $fields['option'] : '';
        $hooks = array(
            'pre_option_' . $option,
            'pre_option',
            'default_option_' . $option,
            'option_' . $option,
        );
        $counts = array('wrapped' => 0, 'marked' => 0, 'unavailable' => 0);
        foreach ($hooks as $hook) {
            $result = $this->hookInstrumenter->instrument($hook);
            $counts['wrapped'] += $result['callbacks_wrapped'];
            $counts['marked'] += $result['callbacks_marked'];
            $counts['unavailable'] += $result['callbacks_unavailable'];
        }
        $this->installCacheProxy();
        $this->write('sort_readiness_instrumentation', array(
            'hooks_scanned' => count($hooks),
            'callbacks_wrapped' => $counts['wrapped'],
            'callbacks_marked' => $counts['marked'],
            'callbacks_attributed' => $counts['wrapped'] + $counts['marked'],
            'callbacks_unavailable' => $counts['unavailable'],
            'cache_boundary' => $this->cacheProxy === null ? 'unavailable' : 'ready',
        ));
        try {
            $result = $this->traceOperation($operation, $fields, $work);
        } catch (Throwable $error) {
            $this->restore(false);
            throw $error;
        }
        $this->restore(true);
        return $result;
    }

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    private function traceOperation(string $operation, array $fields, callable $work) {
        $identity = $this->identity($operation, $fields);
        $this->write('sort_readiness_operation_start', $identity);
        self::$operationStack[] = $identity['operation_id'];
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $error) {
            array_pop(self::$operationStack);
            $this->write('sort_readiness_operation_end', array_merge($identity, array(
                'status' => 'error',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'result' => 'error',
                'error' => self::errorSummary($error),
            )));
            throw $error;
        }
        array_pop(self::$operationStack);
        $this->write('sort_readiness_operation_end', array_merge($identity, array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($startedAt),
            'result' => is_bool($result) ? ($result ? 'true' : 'false') : gettype($result),
        )));
        return $result;
    }

    /**
     * Called by ABJ_404_Solution_InstrumentedObjectCache.
     *
     * @template T
     * @param mixed $key
     * @param mixed $group
     * @param callable():T $work
     * @return T
     */
    public function traceCache(string $operation, $key, $group, callable $work) {
        return $this->traceOperation('cache_' . $operation, array(
            'kind' => 'cache',
            'cache_key' => self::hashIdentity($key, 'key'),
            'cache_group' => self::hashIdentity($group, 'group'),
        ), $work);
    }

    /**
     * @param array{callback:string,source:string,has_reference:bool} $identity
     * @return array<string,mixed>
     */
    private function beginHookCallback(string $hook, int $priority, array $identity): array {
        $fields = array(
            'kind' => 'hook',
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($hook),
            'callback' => $identity['callback'],
            'source' => $identity['source'],
            'priority' => ABJ_404_Solution_HookCallbackIdentity::jsonSafePriority($priority),
        );
        $token = $this->identity('option_callback', $fields);
        $this->write('sort_readiness_operation_start', $token);
        return array('identity' => $token, 'started_at' => self::nowFloat());
    }

    /** @param array<string,mixed>|null $token */
    private function finishToken($token): void {
        if (!is_array($token) || !isset($token['identity']) || !is_array($token['identity'])) {
            return;
        }
        $startedAt = $token['started_at'] ?? null;
        $this->write('sort_readiness_operation_end', array_merge($token['identity'], array(
            'status' => 'complete',
            'elapsed_ms' => is_float($startedAt)
                ? self::elapsedMilliseconds($startedAt) : null,
            'result' => 'callback_returned',
        )));
    }

    private function installCacheProxy(): void {
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        if (!is_object($cache) || $cache instanceof ABJ_404_Solution_InstrumentedObjectCache) {
            return;
        }
        $this->originalCache = $cache;
        $this->cacheProxy = new ABJ_404_Solution_InstrumentedObjectCache($cache, $this);
        $GLOBALS['wp_object_cache'] = $this->cacheProxy;
    }

    private function restore(bool $scopeCompleted): void {
        $this->hookInstrumenter->restore($scopeCompleted);
        if ($this->cacheProxy !== null && ($GLOBALS['wp_object_cache'] ?? null) === $this->cacheProxy) {
            $GLOBALS['wp_object_cache'] = $this->originalCache;
        }
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{
     *   operation_id:string,
     *   operation:string,
     *   parent_operation_id?:string,
     *   column?:string,
     *   kind?:string,
     *   cache_key?:mixed,
     *   cache_group?:mixed,
     *   hook?:mixed,
     *   callback?:mixed,
     *   source?:mixed,
     *   priority?:mixed,
     *   option_id?:string
     * }
     */
    private function identity(string $operation, array $fields): array {
        $identity = array(
            'operation_id' => substr(hash(
                'sha256',
                $this->requestId . '|' . (++self::$operationSequence) . '|' . $operation
            ), 0, 12),
            'operation' => self::safeToken($operation, 'operation'),
        );
        $parent = end(self::$operationStack);
        if (is_string($parent) && $parent !== '') {
            $identity['parent_operation_id'] = $parent;
        }
        foreach (array('column', 'kind') as $field) {
            if (isset($fields[$field]) && is_string($fields[$field])) {
                $identity[$field] = self::safeToken($fields[$field], $field);
            }
        }
        foreach (array('cache_key', 'cache_group', 'hook', 'callback', 'source', 'priority') as $field) {
            if (array_key_exists($field, $fields)) {
                $identity[$field] = $fields[$field];
            }
        }
        if (isset($fields['option']) && is_string($fields['option'])) {
            $identity['option_id'] = self::hashIdentity($fields['option'], 'option');
        }
        return $identity;
    }

    /** @param array<string,mixed> $fields */
    private function write(string $event, array $fields): void {
        self::$recording = true;
        try {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent($this->requestId, $event, $fields);
        } catch (Throwable $error) {
            abj404_logPhpFallback('sort-readiness-tracer', $error->getMessage());
        } finally {
            self::$recording = false;
        }
    }

    private static function requestId(): string {
        return class_exists('ABJ_404_Solution_AjaxRequestLedger')
            ? ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext()
            : '';
    }

    private static function safeToken(string $value, string $fallback): string {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1
            ? $value : $fallback . '#' . substr(hash('sha256', $value), 0, 12);
    }

    /** @param mixed $value */
    private static function hashIdentity($value, string $prefix): string {
        $serialized = is_scalar($value) || $value === null
            ? (string)$value : serialize($value);
        return $prefix . '#' . substr(hash('sha256', $serialized), 0, 12);
    }

    private static function nowFloat(): float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        return class_exists('ABJ_404_Solution_SystemClock')
            ? (new ABJ_404_Solution_SystemClock())->nowFloat()
            : 0.0;
    }

    private static function elapsedMilliseconds(float $startedAt): int {
        $now = self::nowFloat();
        return max(0, (int)round(($now - $startedAt) * 1000));
    }

    /** @return array{class:string,code:int,message:string} */
    private static function errorSummary(Throwable $error): array {
        return array(
            'class' => get_class($error),
            'code' => (int)$error->getCode(),
            'message' => 'message#' . substr(hash('sha256', $error->getMessage()), 0, 12),
        );
    }
}
