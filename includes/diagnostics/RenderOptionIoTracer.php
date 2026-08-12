<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Installs option-cache and raw option-query attribution for render scopes.
 *
 * One coordinator owns nested scopes so the exact host cache and query hook
 * are installed once, restored once, and never replaced by a nested render.
 *
 * allow-no-test-found: real-entry coverage is in tests/AjaxPaginationOptionAttributionTest.php
 */
final class ABJ_404_Solution_RenderOptionIoTracer
    implements ABJ_404_Solution_DiagnosticInternalHookObserver {

    /** @var self|null */
    private static $active;

    /** @var string */
    private $requestId;
    /** @var array<int,string> */
    private $phases = array();
    /** @var object|null */
    private $originalCache;
    /** @var ABJ_404_Solution_InstrumentedObjectCache|null */
    private $cacheProxy;
    /** @var bool */
    private $queryFilterInstalled = false;
    /** @var bool */
    private $restoring = false;
    /** @var bool */
    private $scopeActive = false;
    /** @var array{mode:string,identity:array<string,mixed>}|null */
    private $pendingQuery;
    /** @var ABJ_404_Solution_RenderOptionIoOperationJournal */
    private $journal;
    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function traceScope(
        string $requestId,
        string $phase,
        callable $work
    ) {
        if ($requestId === '') {
            return $work();
        }
        if (self::$active !== null) {
            if (self::$active->requestId !== $requestId) {
                abj404_logPhpFallback(
                    'render-option-io-tracer',
                    'request changed while a render option I/O scope was active'
                );
                return $work();
            }
            return self::$active->runNested($phase, $work);
        }

        $tracer = new self($requestId);
        self::$active = $tracer;
        try {
            return $tracer->runOutermost($phase, $work);
        } finally {
            self::$active = null;
        }
    }

    private function __construct(string $requestId) {
        $this->requestId = $requestId;
        $backend = self::backendIdentity($GLOBALS['wp_object_cache'] ?? null);
        $resolvedDirectory =
            ABJ_404_Solution_AjaxFrequentCheckpointWriter::resolvedDirectoryForRequest(
                $requestId
            );
        $this->lifecycleTracer = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'render_option_io',
            $resolvedDirectory
        );
        $this->journal = new ABJ_404_Solution_RenderOptionIoOperationJournal(
            $requestId,
            function (): string {
                return $this->currentPhase();
            },
            function (): void {
                $this->completePendingQuery();
            },
            $backend
        );
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function runOutermost(string $phase, callable $work) {
        $this->phases[] = self::safePhase($phase);
        $status = $this->install();
        $this->scopeActive = true;
        $this->journal->recordInstrumentation($status);
        try {
            $result = $work();
        } catch (Throwable $error) {
            $this->restore(false);
            array_pop($this->phases);
            throw $error;
        }
        $this->completePendingQuery();
        $this->restore(true);
        array_pop($this->phases);
        return $result;
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function runNested(string $phase, callable $work) {
        $this->completePendingQuery();
        $this->phases[] = self::safePhase($phase);
        $this->journal->recordInstrumentation(array(
            'cache_boundary' => $this->cacheProxy === null ? 'unavailable' : 'ready',
            'query_boundary' => $this->queryFilterInstalled ? 'ready' : 'unavailable',
            'scope' => 'nested',
        ));
        try {
            $result = $work();
        } catch (Throwable $error) {
            // The nested query/cache operation that threw deliberately keeps
            // its start unmatched. It must not be closed later by outer work.
            $this->pendingQuery = null;
            array_pop($this->phases);
            throw $error;
        }
        $this->completePendingQuery();
        array_pop($this->phases);
        return $result;
    }

    /** @return array{cache_boundary:string,query_boundary:string,scope:string} */
    private function install(): array {
        $cacheBoundary = 'unavailable';
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        if (is_object($cache)
                && !$cache instanceof ABJ_404_Solution_InstrumentedObjectCache) {
            $this->originalCache = $cache;
            $this->cacheProxy = new ABJ_404_Solution_InstrumentedObjectCache(
                $cache,
                $this->journal
            );
            $GLOBALS['wp_object_cache'] = $this->cacheProxy;
            $cacheBoundary = 'ready';
        }

        $queryBoundary = 'unavailable';
        if (function_exists('add_filter')) {
            try {
                $this->queryFilterInstalled = $this->lifecycleTracer->traceBoundary(
                    ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
                    'query',
                    function (): bool {
                        return (bool)add_filter(
                            'query',
                            array($this, 'observeQuery'),
                            PHP_INT_MAX,
                            1
                        );
                    }
                );
                $queryBoundary = $this->queryFilterInstalled ? 'ready' : 'unavailable';
            } catch (Throwable $error) {
                self::reportFailure('query filter install failed: ' . $error->getMessage());
            }
        }
        return array(
            'cache_boundary' => $cacheBoundary,
            'query_boundary' => $queryBoundary,
            'scope' => 'outer',
        );
    }

    /**
     * WordPress query-filter callback immediately before the raw driver.
     *
     * @param mixed $query
     * @return mixed
     */
    public function observeQuery($query) {
        if (!$this->scopeActive || $this->restoring || !is_string($query)) {
            return $query;
        }
        $this->completePendingQuery();
        $operation = $this->optionQueryOperation($query);
        if ($operation !== '') {
            $this->pendingQuery = $this->journal->beginOptionQuery($operation, $query);
        }
        return $query;
    }

    private function completePendingQuery(): void {
        if ($this->pendingQuery === null) {
            return;
        }
        $token = $this->pendingQuery;
        $this->pendingQuery = null;
        $this->journal->completeOptionQuery($token, $GLOBALS['wpdb'] ?? null);
    }

    private function restore(bool $scopeCompleted): void {
        $this->scopeActive = false;
        $this->restoring = true;
        if ($scopeCompleted) {
            $this->completePendingQuery();
        }
        if ($this->queryFilterInstalled && function_exists('remove_filter')) {
            try {
                $this->lifecycleTracer->traceBoundary(
                    ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REMOVAL,
                    'query',
                    function (): bool {
                        return (bool)remove_filter(
                            'query',
                            array($this, 'observeQuery'),
                            PHP_INT_MAX
                        );
                    }
                );
            } catch (Throwable $error) {
                self::reportFailure('query filter removal failed: ' . $error->getMessage());
            }
        }
        $this->queryFilterInstalled = false;
        $this->pendingQuery = null;
        if ($this->cacheProxy !== null
                && ($GLOBALS['wp_object_cache'] ?? null) === $this->cacheProxy) {
            $GLOBALS['wp_object_cache'] = $this->originalCache;
        }
        $this->restoring = false;
    }

    private function optionQueryOperation(string $query): string {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        $table = is_object($wpdb) && isset($wpdb->options)
            && is_string($wpdb->options) ? $wpdb->options : '';
        if ($table === '') {
            return '';
        }
        $tablePattern = '/(?:^|[^A-Za-z0-9_])`?' . preg_quote($table, '/')
            . '`?(?:[^A-Za-z0-9_]|$)/i';
        if (preg_match($tablePattern, $query) !== 1) {
            return '';
        }
        if (preg_match('/^\\s*(select|update|insert|delete|replace)\\b/i', $query, $match) !== 1) {
            return 'option_query';
        }
        return 'option_' . strtolower($match[1]);
    }

    private function currentPhase(): string {
        $phase = end($this->phases);
        return is_string($phase) && $phase !== '' ? $phase : 'unknown';
    }

    private static function safePhase(string $phase): string {
        $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($phase));
        return substr(is_string($safe) && $safe !== '' ? $safe : 'unknown', 0, 48);
    }

    /**
     * @param mixed $cache
     * @return array{backend:string,backend_class:string}
     */
    private static function backendIdentity($cache): array {
        if (!is_object($cache)) {
            return array('backend' => 'unavailable', 'backend_class' => 'none');
        }
        $persistent = function_exists('wp_using_ext_object_cache')
            && wp_using_ext_object_cache();
        return array(
            'backend' => $persistent ? 'persistent_object_cache' : 'wordpress_runtime_cache',
            'backend_class' => 'class#' . substr(hash('sha256', get_class($cache)), 0, 12),
        );
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('render-option-io-tracer', $message);
    }
}
