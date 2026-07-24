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
 * allow-no-test-found: exercised through the real table AJAX entry point in tests/TableRendererPreludeTracerTest.php, tests/OptionPersistenceTracerTest.php, and tests/AjaxQueryAttributionTest.php
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

    public function __construct(string $requestId, string $component) {
        $this->requestId = $requestId;
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
        $token = array(
            'operation_id' => substr(hash(
                'sha256',
                $this->requestId . '|' . $this->component . '|' . $this->sequence
                    . '|' . $phase . '|' . $hook
            ), 0, 12),
            'phase' => $phase === 'restore' ? 'restore' : 'install',
            'component' => $this->component,
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($hook),
            'priority' => null,
            'callback_ordinal' => 0,
        );
        $this->write('hook_instrumentation_lifecycle_start', $token);
        return $token;
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
        $token['priority'] = $priority;
        $token['callback_ordinal'] = max(0, $callbackOrdinal);
        $this->write('hook_instrumentation_lifecycle_start', $token);
        return $token;
    }

    /**
     * Close a registry lifecycle after its last access returned.
     *
     * @param LifecycleToken $token
     */
    public function complete(array $token, string $status = 'complete', string $reason = ''): void {
        $fields = array_merge($token, array(
            'status' => substr($status, 0, 32),
        ));
        if ($reason !== '') {
            $fields['reason'] = substr($reason, 0, 64);
        }
        $this->write('hook_instrumentation_lifecycle_end', $fields);
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
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $this->requestId,
                $event,
                $fields
            );
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
            ABJ_404_Solution_CheckpointRecordFactory::frequent(array(
                'ts' => function_exists('abj_clock') ? abj_clock()->nowFloat() : null,
                'hrtime_ns' => function_exists('hrtime') ? (int)hrtime(true) : null,
                'request_id' => $this->requestId,
                'event' => $event,
                'checkpoint_id' => 'hil-' . $operationId . '-'
                    . $this->recordSequence,
                'pid' => getmypid(),
            )),
            $fields
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
