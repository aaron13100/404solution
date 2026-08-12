<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable boundaries around WordPress hook-registry inspection and mutation.
 *
 * A lifecycle operation writes its first start before registry access. Further
 * starts reuse the same operation id while advancing the priority/callback
 * position, so the support selector retains the deepest durable position when
 * a traversal never returns. Callback values and arguments never enter these
 * records.
 *
 * The same start/end pair also brackets an atomic registry mutation that is
 * NOT a traversal: the raw add_filter / remove_filter a tracer performs to
 * register or remove its own diagnostic hook entry (see traceBoundary). Those
 * calls traverse and mutate the target registry inside WordPress, so a
 * malformed or plugin-modified registry can stall there just as a traversal
 * can; the boundary phases (PHASE_REGISTRATION / PHASE_REMOVAL) distinguish
 * them from the surrounding install/restore traversal in support evidence.
 *
 * allow-no-test-found: exercised through the real table AJAX entry point in tests/TableRendererPreludeTracerTest.php, tests/OptionPersistenceTracerTest.php, and tests/AjaxHookCallbackAttributionTest.php
 *
 * @phpstan-type LifecycleToken array{
 *   operation_id: string,
 *   phase: string,
 *   component: string,
 *   hook: string,
 *   priority: int|null,
 *   callback_ordinal: int
 * }
 */
final class ABJ_404_Solution_HookInstrumentationLifecycleTracer {

    /** Registry traversal while installing callback instrumentation. */
    const PHASE_INSTALL = 'install';

    /** Registry traversal while restoring callback instrumentation. */
    const PHASE_RESTORE = 'restore';

    /**
     * The atomic add_filter that registers a diagnostic-owned hook entry. Its
     * WordPress path traverses and mutates the target registry, so a malformed
     * or plugin-modified registry can stall inside registration with no other
     * durable start identifying the diagnostic boundary.
     */
    const PHASE_REGISTRATION = 'registration';

    /** The atomic remove_filter that removes that diagnostic-owned entry. */
    const PHASE_REMOVAL = 'removal';

    /** @var array<int, string> The controlled phase vocabulary. */
    private const PHASES = array(
        self::PHASE_INSTALL,
        self::PHASE_RESTORE,
        self::PHASE_REGISTRATION,
        self::PHASE_REMOVAL,
    );

    /** @var string */
    private $requestId;

    /** @var string */
    private $component;

    /** @var int */
    private $sequence = 0;

    /** @var int */
    private $recordSequence = 0;

    /** @var bool */
    private $recording = false;

    /** @var string */
    private $resolvedDirectory;

    public function __construct(
        string $requestId,
        string $component,
        string $resolvedDirectory = ''
    ) {
        $this->requestId = $requestId;
        $this->resolvedDirectory = $resolvedDirectory;
        $normalized = preg_replace('/[^a-z0-9_]/', '_', strtolower($component));
        $componentName = is_string($normalized) ? $normalized : 'unknown';
        $truncated = substr($componentName, 0, 48);
        $this->component = is_string($truncated) ? $truncated : 'unknown';
    }

    /**
     * Persist the initial lifecycle position before the first registry access.
     *
     * @return LifecycleToken
     */
    public function begin(string $phase, string $hook): array {
        $this->sequence++;
        $normalizedPhase = in_array($phase, self::PHASES, true) ? $phase : self::PHASE_INSTALL;
        $token = array(
            'operation_id' => substr(hash(
                'sha256',
                $this->requestId . '|' . $this->component . '|' . $this->sequence
                    . '|' . $normalizedPhase . '|' . $hook
            ), 0, 12),
            'phase' => $normalizedPhase,
            'component' => $this->component,
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($hook),
            'priority' => null,
            'callback_ordinal' => 0,
        );
        $this->write('hook_instrumentation_lifecycle_start', $token);
        return $token;
    }

    /**
     * Bracket an atomic registry registration or removal (a raw add_filter /
     * remove_filter) whose own WordPress path traverses and mutates the hook
     * registry. The start is persisted before the operation runs, so a call
     * that never returns leaves a durable, support-reserved boundary naming the
     * diagnostic that stalled. A throw is recorded as a failed end and
     * rethrown, so the caller's existing recovery is unchanged.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function traceBoundary(string $phase, string $hook, callable $operation) {
        $token = $this->begin($phase, $hook);
        try {
            $result = $operation();
        } catch (Throwable $e) {
            $this->complete($token, 'failed', get_class($e));
            throw $e;
        }
        $this->complete(
            $token,
            'complete',
            '',
            array('result' => self::boundaryResult($result))
        );
        return $result;
    }

    /**
     * Register one diagnostic-owned action inside its durable mutation boundary.
     *
     * @return mixed Whatever WordPress add_action() returns.
     */
    public function registerAction(
        string $hook,
        callable $callback,
        int $priority,
        int $acceptedArgs = 1
    ) {
        return $this->traceBoundary(
            ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
            $hook,
            static function () use ($hook, $callback, $priority, $acceptedArgs) {
                return add_action($hook, $callback, $priority, $acceptedArgs);
            }
        );
    }

    /**
     * Persist the deepest callback position before inspecting or mutating it.
     *
     * Reusing the operation id means one lifecycle end closes every progress
     * start. If access stalls, the reservation selector keeps the last start
     * written for that request/operation pair.
     *
     * @param LifecycleToken $token
     * @return LifecycleToken
     */
    public function advance(array $token, ?int $priority, int $callbackOrdinal): array {
        $token['priority'] = ABJ_404_Solution_HookCallbackIdentity::jsonSafePriority($priority);
        $token['callback_ordinal'] = max(0, $callbackOrdinal);
        $this->write('hook_instrumentation_lifecycle_start', $token);
        return $token;
    }

    /**
     * Close a registry lifecycle after its last access returned.
     *
     * @param LifecycleToken $token
     * @param array<string, mixed> $resultFields
     */
    public function complete(
        array $token,
        string $status = 'complete',
        string $reason = '',
        array $resultFields = array()
    ): void {
        $fields = array_merge($token, $resultFields, array(
            'status' => substr($status, 0, 32),
        ));
        if ($reason !== '') {
            $fields['reason'] = substr($reason, 0, 64);
        }
        $this->write('hook_instrumentation_lifecycle_end', $fields);
    }

    /** @param mixed $result */
    private static function boundaryResult($result): string {
        if (is_bool($result)) {
            return $result ? 'true' : 'false';
        }
        return $result === null ? 'void' : 'returned';
    }

    /** True only while lifecycle evidence itself is being persisted. */
    public function isRecording(): bool {
        return $this->recording;
    }

    /** @param array<string, mixed> $fields */
    private function write(string $event, array $fields): void {
        if ($this->requestId === '' || $this->recording) {
            return;
        }
        $this->recording = true;
        try {
            $this->recordIndependent($event, $fields);
            if ($this->resolvedDirectory === '') {
                ABJ_404_Solution_AjaxCheckpointBoundaryWriter::record(
                    $this->requestId,
                    $event,
                    $fields
                );
            } else {
                ABJ_404_Solution_AjaxFrequentCheckpointWriter::append(
                    $this->requestId,
                    $event,
                    $fields,
                    $this->resolvedDirectory,
                    true
                );
            }
        } catch (Throwable $e) {
            abj404_logPhpFallback(
                'hook-instrumentation-lifecycle',
                'checkpoint write failed: ' . $e->getMessage()
            );
        } finally {
            $this->recording = false;
        }
    }

    /**
     * Land the same record in the system-temp fallback before WordPress path
     * resolution can dispatch an `all` callback or inspect a hook registry.
     *
     * @param array<string, mixed> $fields
     */
    private function recordIndependent(string $event, array $fields): void {
        if (!class_exists('ABJ_404_Solution_CheckpointIntentStore')
                || !class_exists('ABJ_404_Solution_CheckpointRecordFactory')) {
            return;
        }
        $this->recordSequence++;
        $operationId = is_string($fields['operation_id'] ?? null)
            ? $fields['operation_id']
            : 'unknown';
        $record = array_merge(
            $fields,
            ABJ_404_Solution_CheckpointRecordFactory::frequent(array(
                'ts' => function_exists('abj_clock') ? abj_clock()->nowFloat() : null,
                'hrtime_ns' => function_exists('hrtime') ? (int)hrtime(true) : null,
                'request_id' => $this->requestId,
                'event' => $event,
                'checkpoint_id' => 'hil-' . $operationId . '-'
                    . $this->recordSequence,
                'pid' => getmypid(),
            ))
        );
        $result = ABJ_404_Solution_CheckpointIntentStore::append($record);
        if (($result['status'] ?? '') !== 'complete') {
            $reason = is_string($result['reason'] ?? null)
                ? $result['reason']
                : 'unknown';
            abj404_logPhpFallback(
                'hook-instrumentation-lifecycle',
                'independent checkpoint write failed: ' . $reason
            );
        }
    }
}
