<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Separates WordPress `query` filter time from database-driver time.
 *
 * wpdb dispatches `apply_filters('query', $sql)` before `_do_query()`. The
 * ordinary query probe is intentionally written before wpdb starts, so without
 * this tracer a foreign callback that never returns looks exactly like a
 * MariaDB stall. Existing `query` and global `all` callbacks are wrapped for
 * privacy-safe attribution, then a final PHP_INT_MAX query callback writes the
 * driver-entry sentinel immediately before wpdb proceeds.
 */
final class ABJ_404_Solution_DatabaseQueryFilterTracer {

    const MAX_CALLBACK_RECORDS = 64;

    /** @var string */
    private static $budgetRequestId = '';
    /** @var int */
    private static $budgetRecordCount = 0;
    /** @var bool */
    private static $budgetCapped = false;

    /** @var string */
    private $requestId;
    /** @var int */
    private $queryOrdinal;
    /** @var string */
    private $sqlId;
    /** @var int */
    private $operationSequence = 0;
    /** @var bool */
    private $recording = false;
    /** @var string */
    private $resolvedDirectory;
    /** @var ABJ_404_Solution_HookCallbackInstrumenter<array{mode:string,fields:array<string,mixed>,started_at?:float|null}|null> */
    private $instrumenter;
    /** @var ABJ_404_Solution_HookInstrumentationLifecycleTracer */
    private $lifecycleTracer;
    /** @var string */
    private $attemptId;
    /** @var string */
    private $recoveryId;
    /** @var string */
    private $recoveryBranch;

    /**
     * @template T
     * @param array{
     *   q:int,
     *   sql_id:string,
     *   attempt_id?:string,
     *   recovery_id?:string,
     *   recovery_branch?:string
     * }|null $queryIdentity
     * @param callable():T $queryCall
     * @return T
     */
    public static function trace(?array $queryIdentity, callable $queryCall) {
        $requestId = ABJ_404_Solution_AjaxQueryTimeline::armedRequestId();
        if ($requestId === '' || $queryIdentity === null) {
            return $queryCall();
        }
        self::useRequestBudget($requestId);
        try {
            $tracer = new self(
                $requestId,
                (int)($queryIdentity['q'] ?? 0),
                is_string($queryIdentity['sql_id'] ?? null)
                    ? $queryIdentity['sql_id']
                    : '',
                is_string($queryIdentity['attempt_id'] ?? null)
                    ? $queryIdentity['attempt_id']
                    : '',
                is_string($queryIdentity['recovery_id'] ?? null)
                    ? $queryIdentity['recovery_id']
                    : '',
                is_string($queryIdentity['recovery_branch'] ?? null)
                    ? $queryIdentity['recovery_branch']
                    : ''
            );
        } catch (Throwable $e) {
            self::reportFailure('construction failed: ' . self::throwableSummary($e));
            return $queryCall();
        }
        return $tracer->run($queryCall);
    }

    private function __construct(
        string $requestId,
        int $queryOrdinal,
        string $sqlId,
        string $attemptId = '',
        string $recoveryId = '',
        string $recoveryBranch = ''
    ) {
        $this->requestId = $requestId;
        $this->queryOrdinal = $queryOrdinal;
        $this->sqlId = $sqlId;
        $this->attemptId = $attemptId;
        $this->recoveryId = $recoveryId;
        $this->recoveryBranch = $recoveryBranch;
        $this->resolvedDirectory =
            ABJ_404_Solution_AjaxFrequentCheckpointWriter::resolvedDirectoryForRequest(
                $requestId
            );
        $this->lifecycleTracer = new ABJ_404_Solution_HookInstrumentationLifecycleTracer(
            $requestId,
            'database_query_filter',
            $this->resolvedDirectory
        );
        $this->instrumenter = new ABJ_404_Solution_HookCallbackInstrumenter(
            function (
                string $registeredHook,
                string $actualHook,
                int $priority,
                array $identity,
                int $callbackOrdinal
            ) {
                return $this->beginCallback(
                    $registeredHook,
                    $actualHook,
                    $priority,
                    $callbackOrdinal,
                    $identity
                );
            },
            function ($token): void {
                $this->endCallback($token);
            },
            $this->lifecycleTracer
        );
    }

    /**
     * @template T
     * @param callable():T $queryCall
     * @return T
     */
    private function run(callable $queryCall) {
        $counts = array(
            'callbacks_wrapped' => 0,
            'callbacks_marked' => 0,
            'callbacks_unavailable' => 0,
            'registry_status' => 'unavailable',
        );
        $sentinelRegistered = false;
        try {
            foreach (array('all', 'query') as $hook) {
                $current = $this->instrumenter->instrument($hook);
                $counts['callbacks_wrapped'] += $current['callbacks_wrapped'];
                $counts['callbacks_marked'] += $current['callbacks_marked'];
                $counts['callbacks_unavailable'] += $current['callbacks_unavailable'];
                if ($current['registry_status'] !== 'unavailable') {
                    $counts['registry_status'] = 'ready';
                }
            }

            if (function_exists('add_filter')
                    && isset($GLOBALS['wp_filter'])
                    && is_array($GLOBALS['wp_filter'])) {
                $sentinelRegistered = (bool)$this->lifecycleTracer->traceBoundary(
                    ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REGISTRATION,
                    'query',
                    function (): bool {
                        return (bool)add_filter(
                            'query',
                            array($this, 'recordDriverEntry'),
                            PHP_INT_MAX,
                            1
                        );
                    }
                );
            }
        } catch (Throwable $e) {
            self::reportFailure('instrumentation install failed: ' . self::throwableSummary($e));
            $this->restore(false, $sentinelRegistered);
            return $queryCall();
        }
        $this->write('query_filter_instrumentation', array_merge($this->queryFields(), array(
            'callbacks_attributed' => $counts['callbacks_wrapped'] + $counts['callbacks_marked'],
            'callbacks_unavailable' => $counts['callbacks_unavailable'],
            'registry_status' => $counts['registry_status'],
            'driver_sentinel' => $sentinelRegistered ? 'registered' : 'unavailable',
            'max_records' => self::MAX_CALLBACK_RECORDS,
        )));

        try {
            $result = $queryCall();
        } catch (Throwable $e) {
            $this->write('query_driver_exit', array_merge($this->queryFields(), array(
                'status' => 'failed',
                'failure_class' => self::safeClassName(get_class($e)),
            )));
            $this->restore(false, $sentinelRegistered);
            throw $e;
        }
        $this->write('query_driver_exit', array_merge($this->queryFields(), array(
            'status' => 'complete',
        )));
        $this->restore(true, $sentinelRegistered);
        return $result;
    }

    /**
     * Final query filter callback: no SQL is stored and the value is returned
     * byte-for-byte so query behavior cannot change.
     *
     * @param mixed $query
     * @return mixed
     */
    public function recordDriverEntry($query) {
        $this->write('query_driver_entry', $this->queryFields());
        return $query;
    }

    /**
     * @param array{callback:string,source:string,has_reference:bool} $identity
     * @return array{mode:string,fields:array<string,mixed>,started_at?:float|null}|null
     */
    private function beginCallback(
        string $registeredHook,
        string $actualHook,
        int $priority,
        int $callbackOrdinal,
        array $identity
    ): ?array {
        if ($actualHook !== 'query' || $this->recording || $this->lifecycleTracer->isRecording()) {
            return null;
        }
        $fields = array_merge($this->queryFields(), array(
            'operation_id' => $this->operationId($registeredHook, $callbackOrdinal),
            'registered_hook' => ABJ_404_Solution_HookCallbackIdentity::hookName($registeredHook),
            'hook' => 'query',
            'callback' => $identity['callback'],
            'source' => $identity['source'],
            'priority' => ABJ_404_Solution_HookCallbackIdentity::jsonSafePriority($priority),
            'callback_ordinal' => $callbackOrdinal,
        ));
        if (self::$budgetRecordCount + 2 > self::MAX_CALLBACK_RECORDS) {
            $this->recordCapOnce();
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'query_filter_callback',
                'active',
                $fields
            );
            return array('mode' => 'active', 'fields' => $fields);
        }
        $this->write('query_filter_callback_start', $fields);
        self::$budgetRecordCount++;
        return array('mode' => 'journal', 'fields' => $fields, 'started_at' => self::nowFloat());
    }

    /** @param array{mode:string,fields:array<string,mixed>,started_at?:float|null}|null $token */
    private function endCallback($token): void {
        if (!is_array($token)) {
            return;
        }
        if ($token['mode'] === 'active') {
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'query_filter_callback',
                'complete',
                $token['fields']
            );
            return;
        }
        $this->write('query_filter_callback_end', array_merge($token['fields'], array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($token['started_at'] ?? null),
        )));
        self::$budgetRecordCount++;
    }

    private function recordCapOnce(): void {
        if (self::$budgetCapped) {
            return;
        }
        self::$budgetCapped = true;
        $this->write('query_filter_callback_capped', array_merge($this->queryFields(), array(
            'recorded' => self::$budgetRecordCount,
            'max_records' => self::MAX_CALLBACK_RECORDS,
        )));
    }

    private function restore(bool $completed, bool $sentinelRegistered): void {
        if ($sentinelRegistered && function_exists('remove_filter')) {
            try {
                $this->lifecycleTracer->traceBoundary(
                    ABJ_404_Solution_HookInstrumentationLifecycleTracer::PHASE_REMOVAL,
                    'query',
                    function (): void {
                        remove_filter(
                            'query',
                            array($this, 'recordDriverEntry'),
                            PHP_INT_MAX
                        );
                    }
                );
            } catch (Throwable $e) {
                self::reportFailure(
                    'driver sentinel removal failed: ' . self::throwableSummary($e)
                );
            }
        }
        try {
            $this->instrumenter->restore($completed);
        } catch (Throwable $e) {
            self::reportFailure('callback restoration failed: ' . self::throwableSummary($e));
        }
    }

    /** @return array<string, int|string> */
    private function queryFields(): array {
        $fields = array('q' => $this->queryOrdinal, 'sql_id' => $this->sqlId);
        if ($this->attemptId !== '') {
            $fields['attempt_id'] = $this->attemptId;
            $fields['recovery_id'] = $this->recoveryId;
            $fields['recovery_branch'] = $this->recoveryBranch;
        }
        return $fields;
    }

    private function operationId(string $registeredHook, int $ordinal): string {
        $this->operationSequence++;
        return substr(hash(
            'sha256',
            $this->requestId . '|' . $this->queryOrdinal . '|' . $this->sqlId
                . '|' . $registeredHook . '|' . $ordinal . '|' . $this->operationSequence
        ), 0, 12);
    }

    /** @param array<string,mixed> $fields */
    private function write(string $event, array $fields): void {
        if ($this->recording) {
            return;
        }
        $this->recording = true;
        try {
            ABJ_404_Solution_AjaxFrequentCheckpointWriter::append(
                $this->requestId,
                $event,
                $fields,
                $this->resolvedDirectory,
                true
            );
        } catch (Throwable $e) {
            self::reportFailure($event . ' write failed: ' . $e->getMessage());
        } finally {
            $this->recording = false;
        }
    }

    private static function nowFloat(): ?float {
        return function_exists('abj_clock') ? abj_clock()->nowFloat() : null;
    }

    private static function elapsedMilliseconds(?float $startedAt): ?int {
        $now = self::nowFloat();
        return $startedAt === null || $now === null
            ? null
            : max(0, (int)round(($now - $startedAt) * 1000));
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('database-query-filter-tracer', $message);
    }

    private static function useRequestBudget(string $requestId): void {
        if (self::$budgetRequestId === $requestId) {
            return;
        }
        self::$budgetRequestId = $requestId;
        self::$budgetRecordCount = 0;
        self::$budgetCapped = false;
    }

    /** Reset request-local evidence budgets between process-isolated test requests. */
    public static function resetForTests(): void {
        self::$budgetRequestId = '';
        self::$budgetRecordCount = 0;
        self::$budgetCapped = false;
    }

    private static function throwableSummary(Throwable $e): string {
        return get_class($e) . ' code=' . $e->getCode() . ' message=' . $e->getMessage();
    }

    private static function safeClassName(string $className): string {
        $safe = preg_replace('/[^A-Za-z0-9_\\\\-]/', '_', $className);
        return substr(is_string($safe) ? $safe : 'Throwable', 0, 96);
    }
}
