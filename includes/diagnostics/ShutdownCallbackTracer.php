<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Attributes each foreign WordPress shutdown callback after handler work.
 *
 * Instrumentation is armed only when the AJAX response is ready, after normal
 * plugins have registered their callbacks and before WordPress dispatches the
 * shutdown action. Reference signatures retain the shared instrumenter's
 * marker semantics; ordinary callbacks receive exact start/end boundaries.
 */
final class ABJ_404_Solution_ShutdownCallbackTracer
    implements ABJ_404_Solution_DiagnosticInternalHookObserver {

    /** @var string */
    private $requestId;

    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycle;

    /** @var ABJ_404_Solution_HookCallbackInstrumenter<array<string, mixed>> */
    private $instrumenter;

    /** @var int */
    private $sequence = 0;

    /** @var bool */
    private $armed = false;

    /** @var bool */
    private $completed = false;

    public function __construct(string $requestId, string $directory) {
        $this->requestId = $requestId;
        $this->lifecycle = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'shutdown_callback_tracer',
            $directory
        );
        $this->instrumenter = new ABJ_404_Solution_HookCallbackInstrumenter(
            array($this, 'beginCallback'),
            array($this, 'completeCallback'),
            $this->lifecycle
        );
    }

    /** Register the coarse request sentinels through the same traced registry boundary. */
    public function registerSentinels(callable $early, callable $late): void {
        $this->lifecycle->registerAction('shutdown', $early, PHP_INT_MIN);
        $this->lifecycle->registerAction('shutdown', $late, PHP_INT_MAX);
    }

    /** Install callback attribution and a last-priority restoration sentinel. */
    public function arm(): void {
        if ($this->armed || $this->requestId === '') {
            return;
        }
        $this->armed = true;
        try {
            $counts = $this->instrumenter->instrument('shutdown');
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $this->requestId,
                'shutdown_callback_instrumentation',
                $counts
            );
            $this->lifecycle->registerAction(
                'shutdown',
                array($this, 'completeScope'),
                PHP_INT_MAX
            );
        } catch (Throwable $e) {
            $this->reportFailure('instrumentation failed: ' . $e->getMessage());
            $this->restoreAfterArmFailure();
        }
    }

    /**
     * @param array{callback: string, source: string, has_reference: bool} $identity
     * @return array<string, mixed>
     */
    public function beginCallback(
        string $registeredHook,
        string $actualHook,
        int $priority,
        array $identity,
        int $ordinal
    ): array {
        $this->sequence++;
        $fields = array(
            'operation_id' => substr(hash(
                'sha256',
                $this->requestId . '|shutdown|' . $priority . '|' . $ordinal . '|'
                    . $identity['callback'] . '|' . $this->sequence
            ), 0, 12),
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($actualHook),
            'callback' => $identity['callback'],
            'source' => $identity['source'],
            'priority' => $priority,
            'callback_ordinal' => max(0, $ordinal),
            'has_reference' => $identity['has_reference'],
            'started_at' => $this->nowFloat(),
        );
        ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
            $this->requestId,
            'shutdown_callback',
            'active',
            $fields
        );
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $this->requestId,
            'shutdown_callback_start',
            $fields
        );
        return $fields;
    }

    /** @param array<string, mixed> $token */
    public function completeCallback(array $token): void {
        $startedAt = is_float($token['started_at'] ?? null) ? $token['started_at'] : null;
        unset($token['started_at']);
        $token['elapsed_ms'] = $this->elapsedMs($startedAt);
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $this->requestId,
            'shutdown_callback_end',
            $token
        );
        ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
            $this->requestId,
            'shutdown_callback',
            'complete',
            $token
        );
    }

    /** Final WordPress shutdown callback: close marker tokens and restore the registry. */
    public function completeScope(): void {
        if ($this->completed) {
            return;
        }
        $this->completed = true;
        try {
            $this->instrumenter->restore(true);
        } catch (Throwable $e) {
            $this->reportFailure('restoration failed: ' . $e->getMessage());
        }
    }

    private function restoreAfterArmFailure(): void {
        try {
            $this->instrumenter->restore(false);
        } catch (Throwable $restoreError) {
            $this->reportFailure('arm-failure restoration failed: ' . $restoreError->getMessage());
        }
    }

    private function nowFloat(): ?float {
        return function_exists('abj_clock') ? abj_clock()->nowFloat() : null;
    }

    private function elapsedMs(?float $startedAt): ?int {
        $endedAt = $this->nowFloat();
        return $startedAt === null || $endedAt === null
            ? null
            : max(0, (int)round(($endedAt - $startedAt) * 1000));
    }

    private function reportFailure(string $message): void {
        abj404_logPhpFallback('shutdown-callback-tracer', $message);
    }
}
