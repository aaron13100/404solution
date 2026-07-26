<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped boundary instrumentation for foreground status-count work.
 *
 * Activated only by AJAX handlers that install the three status-count
 * authority seams. It temporarily instruments WordPress hook callbacks and
 * the live object cache, while StatusCountOperationJournal owns the durable
 * operation protocol and privacy-safe record content.
 */
final class ABJ_404_Solution_StatusCountsForegroundTracer {

    /** @var self|null */
    private static $active = null;

    /** @var bool */
    private $scopeActive = false;
    /** @var bool */
    private $suspended = false;
    /** @var object|null */
    private $originalCache;
    /** @var ABJ_404_Solution_InstrumentedObjectCache|null */
    private $cacheProxy;
    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;
    /** @var ABJ_404_Solution_StatusCountOperationJournal */
    private $operationJournal;
    /**
     * @var ABJ_404_Solution_HookCallbackInstrumenter<array{
     *     mode:string,
     *     identity:array<string,mixed>,
     *     started_at?:float
     * }|null>
     */
    private $hookInstrumenter;

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    public static function trace(string $operation, array $fields, callable $work) {
        if (self::$active !== null) {
            return self::$active->operationJournal->trace($operation, $fields, $work);
        }

        $requestId = self::requestId();
        if ($requestId === '') {
            return $work();
        }
        $tracer = new self($requestId);
        self::$active = $tracer;
        $tracer->install();
        try {
            $result = $tracer->operationJournal->trace($operation, $fields, $work);
        } catch (Throwable $error) {
            $tracer->suspended = true;
            $tracer->operationJournal->suspend();
            $tracer->restore(false);
            self::$active = null;
            throw $error;
        }
        $tracer->restore(true);
        self::$active = null;
        return $result;
    }

    private function __construct(string $requestId) {
        $this->operationJournal = new ABJ_404_Solution_StatusCountOperationJournal(
            $requestId
        );
        $this->lifecycleTracer = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'status_count'
        );
        $this->hookInstrumenter = new ABJ_404_Solution_HookCallbackInstrumenter(
            function (
                string $registeredHook,
                string $actualHook,
                int $priority,
                array $identity
            ) {
                return $this->operationJournal->beginHookCallback(
                    $actualHook,
                    $priority,
                    $identity
                );
            },
            function ($token): void {
                $this->operationJournal->finishHookCallback($token);
            },
            $this->lifecycleTracer
        );
    }

    private function install(): void {
        $hookBoundary = 'unavailable';
        $allCounts = array(
            'callbacks_wrapped' => 0,
            'callbacks_marked' => 0,
            'callbacks_unavailable' => 0,
        );
        if (function_exists('add_filter')) {
            try {
                $allCounts = $this->hookInstrumenter->instrument('all');
                if ($allCounts['registry_status'] !== 'unavailable') {
                    $this->lifecycleTracer->traceBoundary(
                        ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
                        'all',
                        function (): void {
                            add_filter('all', array($this, 'prepareHookCallbacks'), PHP_INT_MIN, 1);
                        }
                    );
                    $hookBoundary = 'ready';
                }
            } catch (Throwable $error) {
                self::reportFailure('hook boundary install failed: ' . $error->getMessage());
            }
        }

        $cacheBoundary = 'unavailable';
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        if (is_object($cache) && !$cache instanceof ABJ_404_Solution_InstrumentedObjectCache) {
            $this->originalCache = $cache;
            $this->cacheProxy = new ABJ_404_Solution_InstrumentedObjectCache(
                $cache,
                $this->operationJournal
            );
            $GLOBALS['wp_object_cache'] = $this->cacheProxy;
            $cacheBoundary = 'ready';
        }
        $this->scopeActive = true;
        $this->operationJournal->activate();
        $this->operationJournal->recordInstrumentation(array(
            'hook_boundary' => $hookBoundary,
            'cache_boundary' => $cacheBoundary,
            'all_callbacks_wrapped' => $allCounts['callbacks_wrapped'],
            'all_callbacks_marked' => $allCounts['callbacks_marked'],
            'all_callbacks_attributed' => $allCounts['callbacks_wrapped']
                + $allCounts['callbacks_marked'],
            'all_callbacks_unavailable' => $allCounts['callbacks_unavailable'],
        ));
    }

    /**
     * Instrument callbacks immediately before WordPress dispatches a named hook.
     *
     * @param mixed $hookName
     * @return mixed
     */
    public function prepareHookCallbacks($hookName) {
        if (!$this->scopeActive || $this->suspended
                || $this->operationJournal->isRecording()
                || $this->lifecycleTracer->isRecording()
                || (class_exists('ABJ_404_Solution_AjaxCheckpointLogger')
                    && ABJ_404_Solution_AjaxCheckpointLogger::isRecording())
                || !is_string($hookName) || $hookName === 'all') {
            return $hookName;
        }
        $this->hookInstrumenter->instrument($hookName);
        return $hookName;
    }

    private function restore(bool $scopeCompleted): void {
        $this->scopeActive = false;
        $this->operationJournal->deactivate();
        if (function_exists('remove_filter')) {
            try {
                $this->lifecycleTracer->traceBoundary(
                    ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REMOVAL,
                    'all',
                    function (): void {
                        remove_filter('all', array($this, 'prepareHookCallbacks'), PHP_INT_MIN);
                    }
                );
            } catch (Throwable $error) {
                self::reportFailure('hook boundary removal failed: ' . $error->getMessage());
            }
        }
        $this->hookInstrumenter->restore($scopeCompleted);
        if ($this->cacheProxy !== null
                && ($GLOBALS['wp_object_cache'] ?? null) === $this->cacheProxy) {
            $GLOBALS['wp_object_cache'] = $this->originalCache;
        }
    }

    private static function requestId(): string {
        if (!class_exists('ABJ_404_Solution_AjaxRequestLedger')) {
            return '';
        }
        $context = $GLOBALS['abj404_ajax_context'] ?? null;
        $action = is_array($context) && is_scalar($context['action'] ?? null)
            ? (string)$context['action'] : '';
        if (!in_array($action, array(
            ABJ_404_Solution_AjaxRequestLedger::INSTRUMENTED_ACTION,
            'ajaxRunCanaryStep',
            'ajaxRefreshHealthBar',
        ), true)) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::requestIdFromGlobalContext();
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('status-count-foreground', $message);
    }
}
