<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable attribution for table-preference option persistence.
 *
 * The tracer is active only during ajaxUpdatePaginationLinks rows-per-page or
 * sort-preference writes. It brackets preference normalization, option reads,
 * storage-contract normalization, WordPress storage/cache work, repository
 * cache refresh, and every callback registered on the relevant option
 * lifecycle hooks. Values and callback arguments are never inspected or
 * persisted.
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
    /** @var ABJ_404_Solution_HookCallbackInstrumenter<array{fields: array<string, mixed>, started_at: float|null}|null> */
    private $hookInstrumenter;
    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;

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
        $this->lifecycleTracer = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'option_persistence'
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
            $result = $this->traceOperation('storage_write_cache_invalidation', $work);
        } catch (Throwable $e) {
            $this->restoreHookCallbacks(false);
            throw $e;
        }
        $this->restoreHookCallbacks(true);
        return $result;
    }

    private function installHookCallbacks(): void {
        $wrappedCount = 0;
        $markedCount = 0;
        $unavailableCount = 0;
        $registryUnavailable = false;
        $reason = '';
        foreach (self::OPTION_HOOKS as $hookName) {
            $counts = $this->hookInstrumenter->instrument($hookName);
            $wrappedCount += $counts['callbacks_wrapped'];
            $markedCount += $counts['callbacks_marked'];
            $unavailableCount += $counts['callbacks_unavailable'];
            if ($counts['registry_status'] === 'unavailable') {
                $registryUnavailable = true;
                $reason = (string)($counts['registry_reason'] ?? 'hook_registry_unavailable');
            }
        }
        $status = array(
            'status' => $registryUnavailable
                ? 'unavailable'
                : ($unavailableCount === 0 ? 'ready' : 'partial'),
            'hooks_scanned' => count(self::OPTION_HOOKS),
            'callbacks_wrapped' => $wrappedCount,
            'callbacks_marked' => $markedCount,
            'callbacks_attributed' => $wrappedCount + $markedCount,
            'callbacks_unavailable' => $unavailableCount,
        );
        if ($reason !== '') {
            $status['reason'] = $reason;
        }
        $this->write('option_hook_instrumentation', $status);
    }

    /**
     * @param array{callback: string, source: string, has_reference: bool} $identity
     * @return array{fields: array<string, mixed>, started_at: float|null}|null
     */
    private function beginHookCallback(
        string $actualHook,
        int $priority,
        array $identity
    ): ?array {
        if ($this->recording || $this->lifecycleTracer->isRecording()) {
            return null;
        }
        $fields = array(
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($actualHook),
            'callback' => $identity['callback'],
            'source' => $identity['source'],
            'priority' => ABJ_404_Solution_HookCallbackIdentity::jsonSafePriority($priority),
        );
        $fields['operation_id'] = $this->operationId('option_hook_callback', $fields);
        $this->write('option_hook_callback_start', $fields);
        return array('fields' => $fields, 'started_at' => self::nowFloat());
    }

    /** @param array{fields: array<string, mixed>, started_at: float|null}|null $token */
    private function finishHookCallback($token): void {
        if (!is_array($token)) {
            return;
        }
        $this->write('option_hook_callback_end', array_merge($token['fields'], array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($token['started_at']),
        )));
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

    private function restoreHookCallbacks(bool $scopeCompleted = true): void {
        $this->hookInstrumenter->restore($scopeCompleted);
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
