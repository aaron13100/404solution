<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable attribution for post-driver database recovery and retry work.
 *
 * One instance follows the original query from its first driver return through
 * every selected recovery branch. Retry attempts reuse the original query
 * identity and add a stable attempt ID, allowing DatabaseQueryFilterTracer to
 * attribute WordPress callbacks and driver entry/exit to the exact retry.
 */
final class ABJ_404_Solution_DatabaseQueryRecoveryTracer {

    /** @var string */
    private $requestId;
    /** @var int */
    private $queryOrdinal;
    /** @var string */
    private $sqlId;
    /** @var string */
    private $recoveryId;
    /** @var int */
    private $sequence = 0;
    /** @var bool */
    private $recoveryStarted = false;
    /** @var bool */
    private $recoveryCompleted = false;
    /** @var int */
    private $branchesSelected = 0;
    /** @var int */
    private $operationsTraced = 0;
    /** @var int */
    private $attemptsTraced = 0;

    /**
     * @param array{q:int,sql_id:string}|null $queryIdentity
     */
    public static function begin(?array $queryIdentity): self {
        $requestId = ABJ_404_Solution_AjaxQueryTimeline::armedRequestId();
        $queryOrdinal = (int)($queryIdentity['q'] ?? 0);
        $sqlId = is_string($queryIdentity['sql_id'] ?? null)
            ? $queryIdentity['sql_id']
            : '';
        $recoveryId = $requestId !== '' && $queryOrdinal > 0 && $sqlId !== ''
            ? substr(hash('sha256', $requestId . '|' . $queryOrdinal . '|' . $sqlId . '|recovery'), 0, 12)
            : '';
        return new self($requestId, $queryOrdinal, $sqlId, $recoveryId);
    }

    private function __construct(
        string $requestId,
        int $queryOrdinal,
        string $sqlId,
        string $recoveryId
    ) {
        $this->requestId = $requestId;
        $this->queryOrdinal = $queryOrdinal;
        $this->sqlId = $sqlId;
        $this->recoveryId = $recoveryId;
    }

    /** Record the first wpdb attempt returning or throwing. */
    public function recordFirstDriverReturn(
        string $status = 'complete',
        ?Throwable $failure = null
    ): void {
        $fields = array_merge($this->baseFields(), array(
            'status' => self::status($status),
        ));
        if ($failure !== null) {
            $fields['failure_class'] = self::className($failure);
        }
        $this->write('query_first_driver_return', $fields);
    }

    /** Open the recovery cycle after the first wpdb attempt returned. */
    public function startRecovery(): void {
        if ($this->recoveryStarted) {
            return;
        }
        $this->recoveryStarted = true;
        $this->write('query_recovery_start', array_merge(
            $this->baseFields(),
            array('operation_id' => $this->recoveryId)
        ));
    }

    /** Close the recovery cycle once. */
    public function completeRecovery(
        string $status = 'complete',
        ?Throwable $failure = null
    ): void {
        if (!$this->recoveryStarted || $this->recoveryCompleted) {
            return;
        }
        $this->recoveryCompleted = true;
        $fields = array_merge($this->baseFields(), array(
            'operation_id' => $this->recoveryId,
            'status' => self::status($status),
            'branches_selected' => $this->branchesSelected,
            'operations_traced' => $this->operationsTraced,
            'attempts_traced' => $this->attemptsTraced,
        ));
        if ($failure !== null) {
            $fields['failure_class'] = self::className($failure);
        }
        $this->write('query_recovery_end', $fields);
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function traceBranch(string $branch, callable $work) {
        $this->branchesSelected++;
        return $this->tracePair(
            'query_recovery_branch',
            self::branch($branch),
            '',
            $work
        );
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function traceOperation(string $branch, string $operation, callable $work) {
        $this->operationsTraced++;
        return $this->tracePair(
            'query_recovery_operation',
            self::branch($branch),
            self::operation($operation),
            $work
        );
    }

    /**
     * Run one SQL retry through the existing query-filter and driver tracer.
     *
     * @template T
     * @param callable():T $queryCall
     * @return T
     */
    public function traceAttempt(string $branch, string $reason, callable $queryCall) {
        if (!$this->isArmed()) {
            return $queryCall();
        }
        $branch = self::branch($branch);
        $reason = self::reason($reason);
        $this->attemptsTraced++;
        $attemptId = $this->nextId('attempt|' . $branch . '|' . $reason);
        $fields = array_merge($this->baseFields(), array(
            'operation_id' => $attemptId,
            'attempt_id' => $attemptId,
            'branch' => $branch,
            'reason' => $reason,
        ));
        $this->write('query_recovery_attempt_start', $fields);
        $queryIdentity = array_merge($this->queryFields(), array(
            'attempt_id' => $attemptId,
            'recovery_id' => $this->recoveryId,
            'recovery_branch' => $branch,
        ));
        try {
            $result = ABJ_404_Solution_DatabaseQueryFilterTracer::trace(
                $queryIdentity,
                $queryCall
            );
        } catch (Throwable $e) {
            $this->write('query_recovery_attempt_end', array_merge($fields, array(
                'status' => 'failed',
                'failure_class' => self::className($e),
                'result_status' => 'exception',
                'row_count' => 0,
                'rows_affected' => 0,
            )));
            throw $e;
        }
        $resultFields = is_array($result)
            ? self::safeResultFields($result)
            : array(
                'result_status' => 'unavailable',
                'row_count' => 0,
                'rows_affected' => 0,
            );
        $this->write('query_recovery_attempt_end', array_merge(
            $fields,
            array('status' => 'complete'),
            $resultFields
        ));
        return $result;
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function tracePair(
        string $eventPrefix,
        string $branch,
        string $operation,
        callable $work
    ) {
        if (!$this->isArmed()) {
            return $work();
        }
        $operationId = $this->nextId($eventPrefix . '|' . $branch . '|' . $operation);
        $fields = array_merge($this->baseFields(), array(
            'operation_id' => $operationId,
            'branch' => $branch,
        ));
        if ($operation !== '') {
            $fields['operation'] = $operation;
        }
        $this->writePairStart($eventPrefix, $fields);
        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->writePairEnd($eventPrefix, array_merge($fields, array(
                'status' => 'failed',
                'failure_class' => self::className($e),
            )));
            throw $e;
        }
        $this->writePairEnd($eventPrefix, array_merge($fields, array(
            'status' => 'complete',
        )));
        return $result;
    }

    /** @return array{q:int,sql_id:string} */
    private function queryFields(): array {
        return array('q' => $this->queryOrdinal, 'sql_id' => $this->sqlId);
    }

    /** @return array{q:int,sql_id:string,recovery_id:string} */
    private function baseFields(): array {
        return array_merge($this->queryFields(), array(
            'recovery_id' => $this->recoveryId,
        ));
    }

    private function nextId(string $scope): string {
        $this->sequence++;
        return substr(hash(
            'sha256',
            $this->requestId . '|' . $this->recoveryId . '|' . $this->sequence . '|' . $scope
        ), 0, 12);
    }

    private function isArmed(): bool {
        return $this->requestId !== ''
            && $this->queryOrdinal > 0
            && $this->sqlId !== ''
            && $this->recoveryId !== '';
    }

    /** @param array<string, mixed> $fields */
    private function write(string $event, array $fields): void {
        if (!$this->isArmed()) {
            return;
        }
        try {
            ABJ_404_Solution_AjaxFrequentCheckpointWriter::append(
                $this->requestId,
                $event,
                $fields,
                ABJ_404_Solution_AjaxFrequentCheckpointWriter::resolvedDirectoryForRequest(
                    $this->requestId
                ),
                true
            );
        } catch (Throwable $e) {
            abj404_logPhpFallback(
                'database-query-recovery-tracer',
                $event . ' write failed; exception=' . self::className($e)
                    . '; code=' . (string)$e->getCode()
            );
        }
    }

    private static function status(string $status): string {
        return $status === 'complete' ? 'complete' : 'failed';
    }

    private static function branch(string $branch): string {
        $allowed = array(
            'timeout_wrapper',
            'transient_connection',
            'commands_out_of_sync',
            'missing_table',
            'invalid_data',
            'deadlock',
            'collation',
            'timeout',
            'database_issue',
            'corrupted_table',
            'duplicate_id',
        );
        return in_array($branch, $allowed, true) ? $branch : 'unknown';
    }

    private static function operation(string $operation): string {
        $allowed = array(
            'connection_recovery',
            'connection_retry_reset',
            'repair_create',
            'retry_prepare',
            'retry_suppression',
            'retry_backoff',
            'schedule_recovery',
            'timeout_log',
            'notice_update',
        );
        return in_array($operation, $allowed, true) ? $operation : 'unknown';
    }

    private static function reason(string $reason): string {
        $allowed = array(
            'timeout_wrapper_rejected',
            'connection_lost',
            'pending_results_drained',
            'missing_table',
            'invalid_data',
            'deadlock_or_lock_timeout',
            'corrupted_table',
        );
        return in_array($reason, $allowed, true) ? $reason : 'unknown';
    }

    private static function className(Throwable $failure): string {
        $name = preg_replace('/[^A-Za-z0-9_\\\\-]/', '_', get_class($failure));
        return substr(is_string($name) ? $name : 'Throwable', 0, 96);
    }

    /**
     * @param array<mixed, mixed> $result
     * @return array{result_status:string,row_count:int,rows_affected:int}
     */
    private static function safeResultFields(array $result): array {
        $lastError = is_scalar($result['last_error'] ?? null)
            ? (string)$result['last_error']
            : '';
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return array(
            'result_status' => $lastError === '' ? 'success' : 'error',
            'row_count' => count($rows),
            'rows_affected' => is_numeric($result['rows_affected'] ?? null)
                ? max(0, (int)$result['rows_affected'])
                : 0,
        );
    }

    /** @param array<string, mixed> $fields */
    private function writePairStart(string $prefix, array $fields): void {
        if ($prefix === 'query_recovery_branch') {
            $this->write('query_recovery_branch_start', $fields);
            return;
        }
        $this->write('query_recovery_operation_start', $fields);
    }

    /** @param array<string, mixed> $fields */
    private function writePairEnd(string $prefix, array $fields): void {
        if ($prefix === 'query_recovery_branch') {
            $this->write('query_recovery_branch_end', $fields);
            return;
        }
        $this->write('query_recovery_operation_end', $fields);
    }
}
