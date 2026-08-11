<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseInfrastructureErrorTaxonomy.php';

/**
 * Post-execution recovery policy for DatabaseCore queryAndGetResults().
 *
 * DatabaseQueryExecutor owns normalization, preparation, raw execution, and
 * result harvesting. This policy owns the ordered recovery decisions that run
 * after the first wpdb call has produced an error: timeout-wrapper fallback,
 * immediate transient-infrastructure retry, missing-table repair,
 * invalid-data retry, deadlock retry and notice, collation recovery, timeout
 * fallback rows, and server-side issue state.
 */
class ABJ_404_Solution_DatabaseQueryRecoveryPolicy {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseWpdbResultHarvester */
    private $resultHarvester;

    /** @var ABJ_404_Solution_DatabaseQueryDiagnostics */
    private $queryDiagnostics;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseWpdbResultHarvester $resultHarvester
     * @param ABJ_404_Solution_DatabaseQueryDiagnostics $queryDiagnostics
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $core,
        $logger,
        ABJ_404_Solution_DatabaseWpdbResultHarvester $resultHarvester,
        ABJ_404_Solution_DatabaseQueryDiagnostics $queryDiagnostics
    ) {
        $this->core = $core;
        $this->logger = $logger;
        $this->resultHarvester = $resultHarvester;
        $this->queryDiagnostics = $queryDiagnostics;
    }

    /**
     * Classify retry policy without changing the shared infrastructure-error
     * taxonomy that owns severity decisions.
     *
     * Error 2014 needs a distinct immediate-recovery branch because its retry
     * requires draining mysqli result sets, not reconnecting. Keeping that
     * decision here lets observed-error reporting and recovery dispatch share
     * one policy without coupling the severity taxonomy to recovery mechanics.
     *
     * @param string $lastError
     * @return array{strategy: string, branch: string, reason: string}
     */
    public function classifyRetry(string $lastError): array {
        $taxonomy = $this->core->errorClassifier()->taxonomy();
        if ($taxonomy->connectivity()->isCommandsOutOfSyncError($lastError)) {
            return array(
                'strategy' => ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy::QUERY_RETRY_IMMEDIATE,
                'branch' => 'commands_out_of_sync',
                'reason' => 'pending_results_drained',
            );
        }
        return $taxonomy->classifyQueryRetry($lastError);
    }

    /**
     * Run all post-execution recovery branches in their legacy order.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @param int $timeoutSeconds
     * @param ABJ_404_Solution_DatabaseQueryRecoveryTracer|null $tracer
     * @return bool Updated produces-rows decision after timeout-wrapper fallback.
     */
    public function recoverQueryResult(
        string &$query,
        array &$result,
        array $options,
        string $resultType,
        bool $producesRows,
        int $timeoutSeconds,
        ?ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer = null
    ): bool {
        $tracer = $tracer ?? ABJ_404_Solution_DatabaseQueryRecoveryTracer::begin(null);
        $tracer->startRecovery();
        try {
            $producesRows = $this->retryWithoutSetStatementIfNeeded(
                $query,
                $result,
                $resultType,
                $producesRows,
                $tracer
            );
            $this->retryImmediateInfrastructureIfNeeded(
                $query,
                $result,
                $resultType,
                $producesRows,
                $tracer
            );
            $this->repairMissingTableIfNeeded($query, $result, $options, $tracer);
            $this->retryInvalidDataIfNeeded($query, $result, $tracer);
            $this->retryDeadlockIfNeeded(
                $query,
                $result,
                $resultType,
                $producesRows,
                $tracer
            );
            $this->recoverCollationIfNeeded($result, $tracer);
            $this->handleTimeoutIfNeeded($query, $result, $timeoutSeconds, $tracer);
            $this->noteDatabaseIssueIfNeeded($result, $tracer);
        } catch (Throwable $e) {
            $tracer->completeRecovery('failed', $e);
            throw $e;
        }
        return $producesRows;
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @return bool
     */
    private function retryWithoutSetStatementIfNeeded(
        string &$query,
        array &$result,
        string $resultType,
        bool $producesRows,
        ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer
    ): bool {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError === ''
            || !$this->core->errorClassifier()->taxonomy()->hostState()->classifySetStatementFailure($lastError)
            || !$this->core->queryTimeoutManager()->queryHasSetStatementWrapper($query)) {
            return $producesRows;
        }

        $tracer->traceBranch('timeout_wrapper', function () use (
            &$query,
            &$result,
            $resultType,
            $tracer
        ): void {
            $this->core->queryTimeoutManager()->retryWithoutSetStatementWrapper(
                $query,
                $result,
                $resultType,
                $tracer
            );
        });
        return $this->core->queryTimeoutManager()->queryProducesResultRows($query);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @return void
     */
    private function retryImmediateInfrastructureIfNeeded(
        string $query,
        array &$result,
        string $resultType,
        bool $producesRows,
        ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer
    ): void {
        $lastError = $this->lastErrorFromResult($result);
        $retryDecision = $this->classifyRetry($lastError);
        if ($lastError === ''
            || $retryDecision['strategy'] !== ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy::QUERY_RETRY_IMMEDIATE) {
            return;
        }

        $retryBranch = $retryDecision['branch'];
        $retryReason = $retryDecision['reason'];

        $tracer->traceBranch($retryBranch, function () use (
            $query,
            &$result,
            $resultType,
            $producesRows,
            $tracer,
            $retryBranch,
            $retryReason,
            $lastError
        ): void {
            $tracer->traceOperation(
                $retryBranch,
                'connection_recovery',
                fn(): bool => $this->core->connectionManager()->ensureConnection()
            );
            $reset = $tracer->traceOperation(
                $retryBranch,
                'connection_retry_reset',
                fn(): bool => $this->core->connectionManager()->resetForRetry($lastError)
            );
            if (!$reset) {
                return;
            }
            $retried = $tracer->traceAttempt(
                $retryBranch,
                $retryReason,
                fn(): array => $this->executeWpdbQuery($query, $resultType, $producesRows)
            );
            $result = array_merge($result, $retried);
        });
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @return void
     */
    private function repairMissingTableIfNeeded(
        string $query,
        array &$result,
        array $options,
        ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer
    ): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($options['skip_repair'] || $lastError === '' || !$this->core->errorClassifier()->taxonomy()->schema()->isMissingPluginTableError($lastError)) {
            return;
        }
        $tracer->traceBranch('missing_table', function () use (
            $query,
            &$result,
            $tracer
        ): void {
            $this->core->repairPolicy()->attemptMissingTableRepairAndRetry(
                $query,
                $result,
                $tracer
            );
        });
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    private function retryInvalidDataIfNeeded(
        string $query,
        array &$result,
        ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer
    ): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError !== '' && $this->core->errorClassifier()->taxonomy()->schema()->isInvalidDataError($lastError)) {
            $tracer->traceBranch('invalid_data', function () use (
                $query,
                &$result,
                $tracer
            ): void {
                $this->core->tableRepairer()->attemptInvalidDataRetry(
                    $query,
                    $result,
                    $tracer
                );
            });
        }
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @return void
     */
    private function retryDeadlockIfNeeded(
        string $query,
        array &$result,
        string $resultType,
        bool $producesRows,
        ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer
    ): void {
        $lastError = $this->lastErrorFromResult($result);
        $retryDecision = $this->classifyRetry($lastError);
        if ($lastError === ''
            || $retryDecision['strategy'] !== ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy::QUERY_RETRY_BACKOFF) {
            return;
        }

        $tracer->traceBranch('deadlock', function () use (
            $query,
            &$result,
            $resultType,
            $producesRows,
            $tracer
        ): void {
            $tracer->traceOperation(
                'deadlock',
                'retry_backoff',
                static function (): void {
                    usleep(50000);
                }
            );
            $retried = $tracer->traceAttempt(
                'deadlock',
                'deadlock_or_lock_timeout',
                fn(): array => $this->executeWpdbQuery($query, $resultType, $producesRows)
            );
            $result = array_merge($result, $retried);
            $lastError = $this->lastErrorFromResult($result);
            if ($lastError !== '' && $this->core->errorClassifier()->taxonomy()->connectivity()->isDeadlockOrLockTimeoutError($lastError)) {
                $tracer->traceOperation(
                    'deadlock',
                    'notice_update',
                    function () use ($lastError): void {
                        $this->core->noticeState()->setPluginDbNotice(
                            'lock_timeout',
                            function_exists('__') ? __('A database lock wait timeout occurred. If this persists, contact your host - another process may be holding a long-running lock.', '404-solution') : 'A database lock wait timeout occurred. If this persists, contact your host - another process may be holding a long-running lock.',
                            function_exists('__') ? __('A database lock wait timeout occurred. This is usually caused by another process holding a table lock on your database. It may resolve itself automatically, or contact your hosting provider if it persists.', '404-solution') : 'A database lock wait timeout occurred. This is usually caused by another process holding a table lock on your database. It may resolve itself automatically, or contact your hosting provider if it persists.',
                            $lastError
                        );
                    }
                );
            }
        });
    }

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    private function recoverCollationIfNeeded(
        array $result,
        ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer
    ): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError !== '' && $this->core->errorClassifier()->taxonomy()->schema()->isCollationError($lastError)) {
            $tracer->traceBranch('collation', function () use ($tracer): void {
                $tracer->traceOperation(
                    'collation',
                    'schedule_recovery',
                    function (): void {
                        $this->core->collationHelper()->scheduleCollationRecovery();
                    }
                );
            });
        }
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param int $timeoutSeconds
     * @return void
     */
    private function handleTimeoutIfNeeded(
        string $query,
        array &$result,
        int $timeoutSeconds,
        ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer
    ): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError === '' || !$this->core->errorClassifier()->taxonomy()->connectivity()->isQueryTimeoutError($lastError)) {
            return;
        }

        $tracer->traceBranch('timeout', function () use (
            $query,
            &$result,
            $timeoutSeconds,
            $tracer
        ): void {
            $tracer->traceOperation(
                'timeout',
                'timeout_log',
                function () use ($query, $timeoutSeconds): void {
                    $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->queryDiagnostics->extractSqlFilename($query);
                    $this->logger->warn(
                        'Query timed out after ' . $timeoutSeconds . 's. ' .
                        'Query: ' . substr(preg_replace('/\s+/', ' ', trim($sqlInfo)) ?? $sqlInfo, 0, 500)
                    );
                }
            );
            $result['rows'] = array();
            $result['timed_out'] = true;
        });
    }

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    private function noteDatabaseIssueIfNeeded(
        array $result,
        ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer
    ): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError !== '') {
            $tracer->traceBranch('database_issue', function () use (
                $lastError,
                $tracer
            ): void {
                $tracer->traceOperation(
                    'database_issue',
                    'notice_update',
                    function () use ($lastError): void {
                        $this->core->errorClassifier()->noteDatabaseIssueFromError($lastError);
                    }
                );
            });
        }
    }

    /**
     * @param string $query
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @return array<string, mixed>
     */
    private function executeWpdbQuery(string $query, string $resultType, bool $producesRows): array {
        global $wpdb;
        if ($producesRows) {
            // DAO-bypass-approved: Recovery retry executes the original SQL outside DAO routing to avoid recursion.
            $result = array('rows' => $wpdb->get_results($query, $resultType));
        } else {
            // DAO-bypass-approved: Recovery retry executes the original SQL outside DAO routing to avoid recursion.
            $wpdb->query($query);
            $result = array('rows' => array());
        }
        $this->resultHarvester->harvestWpdbResult($result);
        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @return string
     */
    private function lastErrorFromResult(array $result): string {
        return isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
    }
}
