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
 * allow-no-test-found: exercised through the real AJAX table render entry point in tests/AjaxRowProgressAttributionTest.php
 */
final class ABJ_404_Solution_RowRenderOperationTracer
    implements ABJ_404_Solution_CacheOperationTraceSink {

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
    private $unavailableHookRecorded = false;
    /** @var object|null */
    private $originalCache;
    /** @var ABJ_404_Solution_InstrumentedObjectCache|null */
    private $cacheProxy;
    /** @var ABJ_404_Solution_HookCallbackInstrumenter<array{mode: string, record: array<string, mixed>}|null> */
    private $hookInstrumenter;
    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;

    public static function begin(string $requestId): self {
        $tracer = new self($requestId);
        $tracer->install();
        return $tracer;
    }

    private function __construct(string $requestId) {
        $this->requestId = $requestId;
        $this->lifecycleTracer = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'row_render'
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
                $this->finishOperation($token);
            },
            $this->lifecycleTracer
        );
    }

    private function install(): void {
        $hookBoundary = 'unavailable';
        $allHookCounts = array(
            'callbacks_wrapped' => 0,
            'callbacks_marked' => 0,
            'callbacks_unavailable' => 0,
        );
        if (function_exists('add_filter')) {
            try {
                $allHookCounts = $this->hookInstrumenter->instrument('all');
                if ($allHookCounts['registry_status'] !== 'unavailable') {
                    // The raw add_filter itself traverses and mutates the `all`
                    // registry inside WordPress, after instrument()'s traversal
                    // lifecycle has already closed. Bracket it so a stall inside
                    // registration leaves a durable, reserved boundary rather
                    // than an unattributable hang.
                    $this->lifecycleTracer->traceBoundary(
                        ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
                        'all',
                        function (): void {
                            add_filter('all', array($this, 'prepareHookCallbacks'), PHP_INT_MIN, 1);
                        }
                    );
                    $hookBoundary = 'ready';
                }
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
            'all_callbacks_wrapped' => $allHookCounts['callbacks_wrapped'],
            'all_callbacks_marked' => $allHookCounts['callbacks_marked'],
            'all_callbacks_attributed' => $allHookCounts['callbacks_wrapped']
                + $allHookCounts['callbacks_marked'],
            'all_callbacks_unavailable' => $allHookCounts['callbacks_unavailable'],
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
        if (!$this->rowActive || $this->suspended || $this->recording || $this->cappedRecorded
                || $this->lifecycleTracer->isRecording()
                || (class_exists('ABJ_404_Solution_AjaxCheckpointLogger')
                    && ABJ_404_Solution_AjaxCheckpointLogger::isRecording())
                || !is_string($hookName) || $hookName === 'all') {
            return $hookName;
        }
        $counts = $this->hookInstrumenter->instrument($hookName);
        if ($counts['callbacks_unavailable'] > 0) {
            $this->recordUnavailableHookOnce($hookName, $counts['callbacks_unavailable']);
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
        $token = $this->beginOperation($fields);
        if ($token === null) {
            return $work();
        }
        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->suspended = true;
            $this->restore(false);
            throw $e;
        }
        $this->finishOperation($token);
        return $result;
    }

    /**
     * @param array{callback: string, source: string, has_reference: bool} $identity
     * @return array{mode: string, record: array<string, mixed>}|null
     */
    private function beginHookCallback(
        string $actualHook,
        int $priority,
        array $identity
    ): ?array {
        return $this->beginOperation(array(
            'kind' => 'hook',
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($actualHook),
            'callback' => $identity['callback'],
            'source' => $identity['source'],
            'priority' => ABJ_404_Solution_HookCallbackIdentity::jsonSafePriority($priority),
        ));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{mode: string, record: array<string, mixed>}|null
     */
    private function beginOperation(array $fields): ?array {
        if (!$this->rowActive || $this->suspended || $this->recording
                || $this->lifecycleTracer->isRecording()
                || (class_exists('ABJ_404_Solution_AjaxCheckpointLogger')
                    && ABJ_404_Solution_AjaxCheckpointLogger::isRecording())) {
            return null;
        }
        $operationId = substr(hash(
            'sha256',
            $this->requestId . '|' . (++$this->operationSequence) . '|' . serialize($fields)
        ), 0, 12);
        $record = array_merge(array('operation_id' => $operationId), $fields);
        if ($this->recordCount + 2 > self::MAX_OPERATION_RECORDS) {
            $this->recordCappedOnce();
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId, 'row_operation', 'active', $record);
            return array('mode' => 'active', 'record' => $record);
        }
        $this->write('row_operation_start', $record, true);
        return array('mode' => 'journal', 'record' => $record);
    }

    /** @param array{mode: string, record: array<string, mixed>}|null $token */
    private function finishOperation($token): void {
        if (!is_array($token)) {
            return;
        }
        if ($token['mode'] === 'active') {
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'row_operation',
                'complete',
                $token['record']
            );
            return;
        }
        $this->write('row_operation_end', $token['record'], true);
    }

    private function recordUnavailableHookOnce(string $hookName, int $count): void {
        if (!$this->rowActive || $this->unavailableHookRecorded) {
            return;
        }
        $this->unavailableHookRecorded = true;
        $this->write('row_operation_unavailable', array(
            'kind' => 'hook',
            'reason' => 'hook_callback_entry_unavailable',
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($hookName),
            'callbacks_unavailable' => $count,
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

    private function restore(bool $scopeCompleted = true): void {
        if (function_exists('remove_filter')) {
            try {
                // Mirror of install(): remove_filter traverses and mutates the
                // `all` registry before the traversal restore lifecycle begins,
                // so bracket the atomic removal on its own boundary phase.
                $this->lifecycleTracer->traceBoundary(
                    ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REMOVAL,
                    'all',
                    function (): void {
                        remove_filter('all', array($this, 'prepareHookCallbacks'), PHP_INT_MIN);
                    }
                );
            } catch (Throwable $e) {
                self::reportFailure('hook boundary removal failed: ' . $e->getMessage());
            }
        }
        $this->hookInstrumenter->restore($scopeCompleted);
        if ($this->cacheProxy !== null && ($GLOBALS['wp_object_cache'] ?? null) === $this->cacheProxy) {
            $GLOBALS['wp_object_cache'] = $this->originalCache;
        }
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
