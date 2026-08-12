<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable, privacy-safe evidence for option I/O reached during render scopes.
 *
 * The journal owns record budgets and result classification. Runtime object
 * replacement and WordPress query-filter installation belong to
 * RenderOptionIoTracer.
 *
 * allow-no-test-found: real-entry coverage is in tests/AjaxPaginationOptionAttributionTest.php
 */
final class ABJ_404_Solution_RenderOptionIoOperationJournal
    implements ABJ_404_Solution_CacheOperationTraceSink {

    const MAX_RECORDS = 48;

    /** @var string */
    private $requestId;
    /** @var callable():string */
    private $phase;
    /** @var callable():void */
    private $beforeCache;
    /** @var array{backend:string,backend_class:string} */
    private $backend;
    /** @var int */
    private $sequence = 0;
    /** @var int */
    private $recordCount = 0;
    /** @var bool */
    private $recording = false;
    /** @var bool */
    private $capped = false;

    /**
     * @param callable():string $phase
     * @param callable():void $beforeCache
     * @param array{backend:string,backend_class:string} $backend
     */
    public function __construct(
        string $requestId,
        callable $phase,
        callable $beforeCache,
        array $backend
    ) {
        $this->requestId = $requestId;
        $this->phase = $phase;
        $this->beforeCache = $beforeCache;
        $this->backend = $backend;
    }

    /** @param array<string,mixed> $fields */
    public function recordInstrumentation(array $fields): void {
        $this->write('render_option_io_instrumentation', array_merge(
            array(
                'phase' => $this->currentPhase(),
                'backend' => $this->backend['backend'],
                'backend_class' => $this->backend['backend_class'],
                'max_records' => self::MAX_RECORDS,
            ),
            $fields
        ), false);
    }

    /**
     * @param mixed $key
     * @param mixed $group
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function traceCache(string $operation, $key, $group, callable $work) {
        call_user_func($this->beforeCache);
        if ($this->recording) {
            return $work();
        }
        $identity = array_merge(
            $this->newIdentity(strtolower($operation)),
            self::cacheIdentity($key, $group),
            $this->backend
        );
        $token = $this->start('render_option_cache_start', $identity);
        if ($token === null) {
            return $work();
        }
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $error) {
            $this->leaveUnmatched($token);
            throw $error;
        }
        $this->finish(
            'render_option_cache_end',
            $token,
            array_merge(
                self::resultFields($result),
                array('elapsed_ms' => self::elapsedMilliseconds($startedAt))
            )
        );
        return $result;
    }

    /**
     * Announce a direct WordPress option-table query immediately before wpdb
     * enters its driver path. SQL text is represented only by a short hash.
     *
     * @return array{mode:string,identity:array<string,mixed>}|null
     */
    public function beginOptionQuery(string $operation, string $query): ?array {
        $identity = array_merge(
            $this->newIdentity($operation),
            array('query_id' => 'query#' . substr(hash('sha256', $query), 0, 12))
        );
        return $this->start('render_option_query_driver_entry', $identity);
    }

    /**
     * @param array{mode:string,identity:array<string,mixed>}|null $token
     * @param mixed $wpdb
     */
    public function completeOptionQuery($token, $wpdb): void {
        $rows = is_object($wpdb) && isset($wpdb->last_result)
            && is_array($wpdb->last_result) ? count($wpdb->last_result) : 0;
        $status = is_object($wpdb) && isset($wpdb->last_error)
            && is_string($wpdb->last_error) && $wpdb->last_error !== ''
            ? 'error' : 'complete';
        $this->finish('render_option_query_driver_return', $token, array(
            'status' => $status,
            'result_size' => $rows,
            'result_size_unit' => 'rows',
        ));
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{mode:string,identity:array<string,mixed>}|null
     */
    private function start(string $event, array $identity): ?array {
        if ($this->recording) {
            return null;
        }
        if ($this->recordCount + 2 > self::MAX_RECORDS) {
            $this->recordCapOnce();
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'render_option_io',
                'active',
                $identity
            );
            return array('mode' => 'active', 'identity' => $identity);
        }
        $this->write($event, $identity, true);
        return array('mode' => 'journal', 'identity' => $identity);
    }

    /**
     * @param array{mode:string,identity:array<string,mixed>}|null $token
     * @param array<string,mixed> $fields
     */
    private function finish(string $event, $token, array $fields): void {
        if (!is_array($token)) {
            return;
        }
        if (($token['mode'] ?? '') === 'active') {
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'render_option_io',
                'complete',
                $token['identity']
            );
            return;
        }
        $this->write($event, array_merge($token['identity'], $fields), true);
    }

    /** @param array{mode:string,identity:array<string,mixed>} $token */
    private function leaveUnmatched(array $token): void {
        if (($token['mode'] ?? '') !== 'active') {
            return;
        }
        ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
            $this->requestId,
            'render_option_io',
            'active',
            $token['identity']
        );
    }

    /** @return array{operation_id:string,phase:string,operation:string} */
    private function newIdentity(string $operation): array {
        $safeOperation = preg_match('/^[a-z][a-z0-9_]{0,47}$/', $operation) === 1
            ? $operation : 'operation#' . substr(hash('sha256', $operation), 0, 12);
        return array(
            'operation_id' => substr(hash(
                'sha256',
                $this->requestId . '|' . (++$this->sequence) . '|' . $safeOperation
            ), 0, 12),
            'phase' => $this->currentPhase(),
            'operation' => $safeOperation,
        );
    }

    /**
     * @param mixed $key
     * @param mixed $group
     * @return array{cache_key:string,cache_group:string,key_family:string,group_family:string}
     */
    private static function cacheIdentity($key, $group): array {
        $keyText = is_scalar($key) || $key === null ? (string)$key : serialize($key);
        $groupText = is_scalar($group) || $group === null ? (string)$group : serialize($group);
        return array(
            'cache_key' => 'key#' . substr(hash('sha256', $keyText), 0, 12),
            'cache_group' => 'group#' . substr(hash('sha256', $groupText), 0, 12),
            'key_family' => in_array($keyText, array('alloptions', 'notoptions', 'siteurl'), true)
                ? $keyText : 'other',
            'group_family' => $groupText === 'options' ? 'options' : 'other',
        );
    }

    /**
     * @param mixed $result
     * @return array{result:string,result_size:int,result_size_unit:string}
     */
    private static function resultFields($result): array {
        if ($result === false) {
            return array('result' => 'miss', 'result_size' => 0, 'result_size_unit' => 'items');
        }
        if (is_array($result)) {
            return array('result' => 'hit', 'result_size' => count($result), 'result_size_unit' => 'items');
        }
        if (is_string($result)) {
            return array('result' => 'hit', 'result_size' => strlen($result), 'result_size_unit' => 'bytes');
        }
        return array('result' => 'hit', 'result_size' => 1, 'result_size_unit' => 'value');
    }

    private function recordCapOnce(): void {
        if ($this->capped) {
            return;
        }
        $this->capped = true;
        $this->write('render_option_io_capped', array(
            'phase' => $this->currentPhase(),
            'recorded' => $this->recordCount,
            'max_records' => self::MAX_RECORDS,
        ), false);
    }

    /** @param array<string,mixed> $fields */
    private function write(string $event, array $fields, bool $counts): void {
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
            if ($counts) {
                $this->recordCount++;
            }
        } catch (Throwable $error) {
            abj404_logPhpFallback('render-option-io-journal', $error->getMessage());
        } finally {
            $this->recording = false;
        }
    }

    private function currentPhase(): string {
        return (string)call_user_func($this->phase);
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
        return max(0, (int)round((self::nowFloat() - $startedAt) * 1000));
    }
}
