<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable attribution for the response-control filter dispatches on the
 * instrumented table AJAX response tail (Bruno timeout cause matrix, gap-hunt
 * iterations 8 and 9, Codex response-control-filter gaps).
 *
 * Four production filters run foreign WordPress callbacks at response-critical
 * boundaries that no other tracer covers:
 *
 *   - AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput() dispatches
 *     `abj404_should_manage_output_buffer` before the output buffer is read or
 *     drained.
 *   - AjaxResponseEmitter::sendJsonResponseAndExit() dispatches
 *     `abj404_should_exit` after the echo boundary and before the first flush
 *     checkpoint.
 *   - AjaxRequestLedger::resolveDetachAbMode() dispatches
 *     `abj404_should_run_detach_ab_diagnostic` after the response flush and
 *     before the connection-detach call.
 *   - WordPress status_header() dispatches `status_header` before core header
 *     emission while AjaxResponseEmitter is sending the table response.
 *
 * A callback registered on either named filter, or on WordPress's global `all`
 * hook (which fires on every apply_filters), can conditionally block only for
 * `ajaxUpdatePaginationLinks`, so a successful canary request cannot eliminate
 * it: the canary carries no instrumented request id and never reaches this
 * tracer, while the real table request does. Without per-callback identity a
 * failing session says only "between echo and flush" or "before ob_read", not
 * which callback ran there.
 *
 * The tracer is active only inside an instrumented table AJAX request
 * (AjaxRequestLedger::instrumentedRequestIdFromGlobalContext() !== ''); every
 * other request -- the canary ladder, the hot front-end 404 path, any other
 * AJAX action -- is a pure pass-through with no record, no registry access, and
 * behavior byte-identical to a bare apply_filters(). For an instrumented
 * request each dispatch is durably bracketed BEFORE any registry access, then
 * the named filter's callbacks AND the `all` hook's callbacks are wrapped with
 * the shared reference-safe callback-identity machinery
 * (ABJ_404_Solution_HookCallbackInstrumenter). A callback that does not return
 * leaves its start unmatched, and the dispatch bracket start is itself reserved,
 * so a worker killed inside a foreign callback still names the boundary it died
 * on through bounded support extraction. Callback arguments, filter values, and
 * source paths never enter these records.
 *
 * allow-no-test-found: exercised through the real ajaxUpdatePaginationLinks
 * response entry point in tests/ResponseControlFilterTracerTest.php.
 */
final class ABJ_404_Solution_ResponseControlFilterTracer {

    /** WordPress's global hook plus the named response-control filter. */
    const ALL_HOOK = 'all';

    /**
     * Monotonic across every dispatch in the process, so two dispatches of the
     * same filter within one request (the happy-path exit and the error-path
     * exit both dispatch abj404_should_exit) never collide on an operation id --
     * a collision would let one dispatch's end close the other's reserved start.
     *
     * @var int
     */
    private static $dispatchSequence = 0;

    /** @var string */
    private $requestId;
    /** @var string The raw filter hook name passed to apply_filters(). */
    private $rawFilterHook;
    /** @var string Redacted hook identity stamped onto records. */
    private $filterHook;
    /** @var int This dispatch's unique ordinal, seeding every operation id. */
    private $dispatchOrdinal;
    /** @var int */
    private $operationSequence = 0;
    /** @var bool */
    private $recording = false;
    /** @var ABJ_404_Solution_HookCallbackInstrumenter<array{fields: array<string, mixed>, started_at: float|null}|null> */
    private $hookInstrumenter;
    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;

    /**
     * Bracket one response-control filter dispatch and attribute every foreign
     * callback it runs. Returns the dispatch result unchanged so the caller's
     * gate keeps behaving exactly as it did with a bare apply_filters().
     *
     * @template T
     * @param string $filterHook The named filter dispatched inside $dispatch.
     * @param callable():T $dispatch Invokes apply_filters($filterHook, ...).
     * @return T
     */
    public static function traceDispatch(string $filterHook, callable $dispatch) {
        $requestId = class_exists('ABJ_404_Solution_AjaxRequestLedger')
            ? ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext()
            : '';
        if ($requestId === '') {
            // Not the instrumented endpoint (canary ladder, front-end 404, any
            // other AJAX action): no bracket, no registry access, byte-identical.
            return $dispatch();
        }
        return (new self($requestId, $filterHook))->run($dispatch);
    }

    private function __construct(string $requestId, string $filterHook) {
        $this->requestId = $requestId;
        $this->rawFilterHook = $filterHook;
        $this->filterHook = ABJ_404_Solution_HookCallbackIdentity::hookName($filterHook);
        $this->dispatchOrdinal = ++self::$dispatchSequence;
        $this->lifecycleTracer = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'response_control_filter'
        );
        $this->hookInstrumenter = new ABJ_404_Solution_HookCallbackInstrumenter(
            function (
                string $registeredHook,
                string $actualHook,
                int $priority,
                array $identity,
                int $callbackOrdinal
            ) {
                return $this->beginHookCallback(
                    $registeredHook,
                    $actualHook,
                    $priority,
                    $callbackOrdinal,
                    $identity
                );
            },
            function ($token): void {
                $this->finishHookCallback($token);
            },
            $this->lifecycleTracer
        );
    }

    /**
     * @template T
     * @param callable():T $dispatch
     * @return T
     */
    private function run(callable $dispatch) {
        // The durable dispatch start lands BEFORE any registry access or foreign
        // dispatch. A worker killed inside a callback still leaves this reserved
        // start naming the boundary it died on.
        $operationId = $this->operationId('response_control_filter_dispatch');
        $this->write('response_control_filter_dispatch_start', array(
            'operation_id' => $operationId,
            'filter_hook' => $this->filterHook,
        ));
        $status = $this->installHookCallbacks();
        $startedAt = self::nowFloat();
        try {
            $result = $dispatch();
        } catch (Throwable $e) {
            // Leave the dispatch start and any in-flight callback start unmatched
            // (both reserved), restore the registry without ending pending
            // markers, and rethrow so the caller's recovery is unchanged.
            $this->restoreHookCallbacks(false);
            throw $e;
        }
        $this->restoreHookCallbacks(true);
        $this->write('response_control_filter_dispatch_end', array_merge($status, array(
            'operation_id' => $operationId,
            'filter_hook' => $this->filterHook,
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($startedAt),
        )));
        return $result;
    }

    /**
     * Wrap every callback on the named filter and on the `all` hook. The named
     * hook is instrumented last so its callbacks sit closest to the dispatch,
     * mirroring WordPress's `all`-then-named invocation order.
     *
     * @return array{callbacks_attributed: int, callbacks_unavailable: int, registry_status: string}
     */
    private function installHookCallbacks(): array {
        $attributed = 0;
        $unavailable = 0;
        $registryUnavailable = false;
        foreach (array(self::ALL_HOOK, $this->rawFilterHook) as $hookName) {
            $counts = $this->hookInstrumenter->instrument($hookName);
            $attributed += $counts['callbacks_wrapped'] + $counts['callbacks_marked'];
            $unavailable += $counts['callbacks_unavailable'];
            if ($counts['registry_status'] === 'unavailable') {
                $registryUnavailable = true;
            }
        }
        return array(
            'callbacks_attributed' => $attributed,
            'callbacks_unavailable' => $unavailable,
            'registry_status' => $registryUnavailable
                ? 'unavailable'
                : ($unavailable === 0 ? 'ready' : 'partial'),
        );
    }

    /**
     * @param array{callback: string, source: string, has_reference: bool} $identity
     * @return array{fields: array<string, mixed>, started_at: float|null}|null
     */
    private function beginHookCallback(
        string $registeredHook,
        string $actualHook,
        int $priority,
        int $callbackOrdinal,
        array $identity
    ): ?array {
        if ($this->recording || $this->lifecycleTracer->isRecording()) {
            return null;
        }
        $fields = array(
            'filter_hook' => $this->filterHook,
            'registered_hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($registeredHook),
            'hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($actualHook),
            'callback' => $identity['callback'],
            'source' => $identity['source'],
            'priority' => ABJ_404_Solution_HookCallbackIdentity::jsonSafePriority($priority),
            'callback_ordinal' => $callbackOrdinal,
        );
        $fields['operation_id'] = $this->operationId('response_control_filter_callback');
        $this->write('response_control_filter_callback_start', $fields);
        return array('fields' => $fields, 'started_at' => self::nowFloat());
    }

    /** @param array{fields: array<string, mixed>, started_at: float|null}|null $token */
    private function finishHookCallback($token): void {
        if (!is_array($token)) {
            return;
        }
        $this->write('response_control_filter_callback_end', array_merge($token['fields'], array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($token['started_at']),
        )));
    }

    private function restoreHookCallbacks(bool $scopeCompleted): void {
        $this->hookInstrumenter->restore($scopeCompleted);
    }

    private function operationId(string $eventPrefix): string {
        $this->operationSequence++;
        return substr(hash(
            'sha256',
            $this->requestId . '|' . $this->rawFilterHook . '|' . $this->dispatchOrdinal
                . '|' . $this->operationSequence . '|' . $eventPrefix
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
        abj404_logPhpFallback('response-control-filter-tracer', $message);
    }
}
