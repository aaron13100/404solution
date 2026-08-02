<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable, privacy-safe operation protocol for foreground status-count work.
 *
 * Owns reserved start/end records, parent correlation, result classification,
 * error redaction, and the post-cap active-operation fallback. Runtime hook
 * and cache installation belongs to StatusCountsForegroundTracer.
 */
final class ABJ_404_Solution_StatusCountOperationJournal
    implements ABJ_404_Solution_CacheOperationTraceSink {

    const MAX_OPERATION_RECORDS = 96;

    /** @var string */
    private $requestId;
    /** @var int */
    private $operationSequence = 0;
    /** @var int */
    private $recordCount = 0;
    /** @var bool */
    private $cappedRecorded = false;
    /** @var bool */
    private $active = false;
    /** @var bool */
    private $suspended = false;
    /** @var bool */
    private $recording = false;
    /** @var array<int,string> */
    private $operationStack = array();

    public function __construct(string $requestId) {
        $this->requestId = $requestId;
    }

    public function activate(): void {
        $this->active = true;
    }

    public function deactivate(): void {
        $this->active = false;
    }

    public function suspend(): void {
        $this->suspended = true;
    }

    public function isRecording(): bool {
        return $this->recording;
    }

    /** @param array<string,mixed> $fields */
    public function recordInstrumentation(array $fields): void {
        $fields['max_records'] = self::MAX_OPERATION_RECORDS;
        $this->write('status_count_instrumentation', $fields, false);
    }

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    public function trace(string $operation, array $fields, callable $work) {
        $token = $this->beginOperation($operation, $fields);
        if ($token === null) {
            return $work();
        }
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $error) {
            array_pop($this->operationStack);
            $this->finishToken($token, 'error', $startedAt, $error);
            throw $error;
        }
        array_pop($this->operationStack);
        $this->finishToken(
            $token,
            $this->resultFor($operation, $fields, $result),
            $startedAt
        );
        return $result;
    }

    /**
     * @param mixed $key
     * @param mixed $group
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function traceCache(string $operation, $key, $group, callable $work) {
        return $this->trace('cache_' . strtolower($operation), array(
            'kind' => 'cache',
            'cache_key' => self::hashIdentity($key, 'key'),
            'cache_group' => self::hashIdentity($group, 'group'),
        ), $work);
    }

    /**
     * @param array{callback:string,source:string,has_reference:bool} $identity
     * @return array{
     *     mode:string,
     *     identity:array<string,mixed>,
     *     started_at?:float
     * }|null
     */
    public function beginHookCallback(
        string $hook,
        int $priority,
        array $identity
    ): ?array {
        $token = $this->beginOperation('hook_callback', array(
            'kind' => 'hook',
            'hook' => self::safeHookName($hook),
            'callback' => $identity['callback'],
            'source' => $identity['source'],
            'priority' => ABJ_404_Solution_HookCallbackIdentity::jsonSafePriority($priority),
        ));
        if (is_array($token)) {
            $token['started_at'] = self::nowFloat();
        }
        return $token;
    }

    /**
     * @param array{
     *     mode:string,
     *     identity:array<string,mixed>,
     *     started_at?:float
     * }|null $token
     */
    public function finishHookCallback($token): void {
        if (is_array($token)) {
            array_pop($this->operationStack);
        }
        $startedAt = is_array($token) && is_float($token['started_at'] ?? null)
            ? $token['started_at'] : null;
        $this->finishToken($token, 'callback_returned', $startedAt);
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{
     *     mode:string,
     *     identity:array<string,mixed>,
     *     started_at?:float
     * }|null
     */
    private function beginOperation(string $operation, array $fields): ?array {
        if (!$this->active || $this->suspended || $this->recording
                || (class_exists('ABJ_404_Solution_AjaxCheckpointLogger')
                    && ABJ_404_Solution_AjaxCheckpointLogger::isRecording())) {
            return null;
        }
        $identity = $this->identity($operation, $fields);
        $parent = end($this->operationStack);
        if (is_string($parent) && $parent !== '') {
            $identity['parent_operation_id'] = $parent;
        }
        $this->operationStack[] = $identity['operation_id'];
        if ($this->recordCount + 2 > self::MAX_OPERATION_RECORDS) {
            $this->recordCappedOnce();
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'status_count_operation',
                'active',
                $identity
            );
            return array('mode' => 'active', 'identity' => $identity);
        }
        $this->write('status_count_operation_start', $identity, true);
        return array('mode' => 'journal', 'identity' => $identity);
    }

    /**
     * @param array{
     *     mode:string,
     *     identity:array<string,mixed>,
     *     started_at?:float
     * }|null $token
     * @param Throwable|null $error
     */
    private function finishToken(
        $token,
        string $result,
        ?float $startedAt = null,
        $error = null
    ): void {
        if (!is_array($token) || !isset($token['identity'])
                || !is_array($token['identity'])) {
            return;
        }
        if (($token['mode'] ?? '') === 'active') {
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $this->requestId,
                'status_count_operation',
                'complete',
                $token['identity']
            );
            return;
        }
        $fields = array_merge($token['identity'], array(
            'status' => $error instanceof Throwable ? 'error' : 'complete',
            'elapsed_ms' => is_float($startedAt)
                ? self::elapsedMilliseconds($startedAt) : null,
            'result' => $result,
        ));
        if ($error instanceof Throwable) {
            $fields['error'] = self::errorSummary($error);
        }
        $this->write('status_count_operation_end', $fields, true);
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{
     *     operation_id:string,
     *     operation:string,
     *     parent_operation_id?:string,
     *     scope?:string,
     *     family?:string,
     *     kind?:string,
     *     hook?:mixed,
     *     callback?:mixed,
     *     source?:mixed,
     *     priority?:mixed,
     *     cache_key?:mixed,
     *     cache_group?:mixed
     * }
     */
    private function identity(string $operation, array $fields): array {
        $identity = array(
            'operation_id' => substr(hash(
                'sha256',
                $this->requestId . '|' . (++$this->operationSequence) . '|' . $operation
            ), 0, 12),
            'operation' => self::safeToken($operation, 'operation'),
        );
        foreach (array('scope', 'family', 'kind') as $field) {
            if (isset($fields[$field]) && is_string($fields[$field])) {
                $identity[$field] = self::safeToken(
                    str_replace('-', '_', $fields[$field]),
                    $field
                );
            }
        }
        foreach (array(
            'hook', 'callback', 'source', 'priority', 'cache_key', 'cache_group',
        ) as $field) {
            if (array_key_exists($field, $fields)) {
                $identity[$field] = $fields[$field];
            }
        }
        return $identity;
    }

    /**
     * @param array<string,mixed> $fields
     * @param mixed $result
     */
    private function resultFor(string $operation, array $fields, $result): string {
        $classifiers = self::resultClassifiers();
        if (isset($classifiers[$operation])) {
            return $classifiers[$operation]($fields, $result);
        }
        if (strpos($operation, 'cache_') === 0) {
            return $result === false ? 'miss' : 'hit';
        }
        if ($operation === 'status_count_scope' && is_array($result)) {
            // The redirect/captured scopes resolve to {counts, state}. Keep
            // that state visible instead of collapsing every result to array.
            if (isset($result['state']) && is_string($result['state'])
                    && $result['state'] !== '') {
                return $result['state'];
            }
            if (isset($result['_incomplete'])) {
                return 'missing';
            }
        }
        return is_bool($result) ? ($result ? 'true' : 'false') : gettype($result);
    }

    /**
     * @return array<string,callable(array<string,mixed>,mixed):string>
     */
    private static function resultClassifiers(): array {
        return array(
            'status_cache_read' => static function (array $unusedFields, $value): string {
                if (!is_array($value) || !empty($value['incomplete'])
                        || (array_key_exists('count', $value) && $value['count'] === null)) {
                    return 'missing';
                }
                return !empty($value['needs_refresh']) ? 'stale' : 'hit';
            },
            'transient_read' => static function (array $operationFields, $value): string {
                $expected = $operationFields['expected'] ?? '';
                if (($expected === 'array' && is_array($value))
                        || ($expected === 'numeric' && is_numeric($value))) {
                    return 'hit';
                }
                return $value === false ? 'miss' : 'invalid';
            },
            'next_scheduled_check' => static fn(array $unusedFields, $value): string =>
                $value === false ? 'not_scheduled' : 'already_scheduled',
            'scheduling_write' => static fn(array $unusedFields, $value): string =>
                $value === true ? 'newly_scheduled' : 'schedule_failed',
            'scheduler_resolution' => static fn(array $unusedFields, $value): string =>
                is_object($value) ? 'resolved' : 'unavailable',
            'schedule_if_missing' => static fn(array $unusedFields, $value): string =>
                $value === true ? 'complete' : 'schedule_failed',
        );
    }

    private function recordCappedOnce(): void {
        if ($this->cappedRecorded) {
            return;
        }
        $this->cappedRecorded = true;
        $this->write('status_count_operation_capped', array(
            'recorded' => $this->recordCount,
            'max_records' => self::MAX_OPERATION_RECORDS,
        ), false);
    }

    /** @param array<string,mixed> $fields */
    private function write(string $event, array $fields, bool $countsTowardBudget): void {
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
            if ($countsTowardBudget) {
                $this->recordCount++;
            }
        } catch (Throwable $error) {
            self::reportFailure('checkpoint failed: ' . $error->getMessage());
        } finally {
            $this->recording = false;
        }
    }

    private static function safeToken(string $value, string $fallback): string {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1
            ? $value : $fallback . '#' . substr(hash('sha256', $value), 0, 12);
    }

    /** @param mixed $value */
    private static function hashIdentity($value, string $prefix): string {
        $serialized = is_scalar($value) || $value === null
            ? (string)$value : serialize($value);
        return $prefix . '#' . substr(hash('sha256', $serialized), 0, 12);
    }

    private static function safeHookName(string $hook): string {
        $families = array(
            ABJ_404_Solution_StatusCountsRepository::CACHE_KEY_REDIRECT_STATUS =>
                'redirect_current',
            ABJ_404_Solution_StatusCountsRepository::CACHE_KEY_REDIRECT_STATUS_LAST_KNOWN =>
                'redirect_last_known',
            ABJ_404_Solution_StatusCountsRepository::CACHE_KEY_CAPTURED_STATUS =>
                'captured_current',
            ABJ_404_Solution_StatusCountsRepository::CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN =>
                'captured_last_known',
            ABJ_404_Solution_StatusCountsRepository::CACHE_KEY_HIGH_IMPACT_CAPTURED =>
                'high_impact_current',
            ABJ_404_Solution_StatusCountsRepository::CACHE_KEY_HIGH_IMPACT_CAPTURED_LAST_KNOWN =>
                'high_impact_last_known',
        );
        foreach ($families as $key => $family) {
            if (strpos($hook, $key) !== false) {
                $hook = str_replace($key, 'status_count_' . $family, $hook);
            }
        }
        return ABJ_404_Solution_HookCallbackIdentity::hookName($hook);
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

    /** @return array{class:string,code:int,message:string} */
    private static function errorSummary(Throwable $error): array {
        return array(
            'class' => get_class($error),
            'code' => (int)$error->getCode(),
            'message' => 'message#' . substr(hash('sha256', $error->getMessage()), 0, 12),
        );
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('status-count-operation-journal', $message);
    }
}
