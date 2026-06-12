<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post-execution recovery policy for DatabaseCore queryAndGetResults().
 *
 * DatabaseQueryExecutor owns normalization, preparation, raw execution, and
 * result harvesting. This policy owns the ordered recovery decisions that run
 * after the first wpdb call has produced an error: timeout-wrapper fallback,
 * transient reconnect retry, missing-table repair, invalid-data retry,
 * deadlock retry and notice, collation recovery, timeout fallback rows, and
 * server-side issue state.
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
     * Run all post-execution recovery branches in their legacy order.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @param int $timeoutSeconds
     * @return bool Updated produces-rows decision after timeout-wrapper fallback.
     */
    public function recoverQueryResult(
        string &$query,
        array &$result,
        array $options,
        string $resultType,
        bool $producesRows,
        int $timeoutSeconds
    ): bool {
        $producesRows = $this->retryWithoutSetStatementIfNeeded($query, $result, $resultType, $producesRows);
        $this->retryTransientConnectionIfNeeded($query, $result, $resultType, $producesRows);
        $this->repairMissingTableIfNeeded($query, $result, $options);
        $this->retryInvalidDataIfNeeded($query, $result);
        $this->retryDeadlockIfNeeded($query, $result, $resultType, $producesRows);
        $this->recoverCollationIfNeeded($query, $result, $resultType, $producesRows);
        $this->handleTimeoutIfNeeded($query, $result, $timeoutSeconds);
        $this->noteDatabaseIssueIfNeeded($result);
        return $producesRows;
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @return bool
     */
    private function retryWithoutSetStatementIfNeeded(string &$query, array &$result, string $resultType, bool $producesRows): bool {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError === ''
            || !$this->core->errorClassifier()->taxonomy()->hostState()->classifySetStatementFailure($lastError)
            || !$this->core->queryTimeoutManager()->queryHasSetStatementWrapper($query)) {
            return $producesRows;
        }

        $this->core->queryTimeoutManager()->retryWithoutSetStatementWrapper($query, $result, $resultType);
        return $this->core->queryTimeoutManager()->queryProducesResultRows($query);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @return void
     */
    private function retryTransientConnectionIfNeeded(string $query, array &$result, string $resultType, bool $producesRows): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError === '' || !$this->core->errorClassifier()->taxonomy()->connectivity()->isTransientConnectionError($lastError)) {
            return;
        }

        global $wpdb;
        $this->core->connectionManager()->ensureConnection();
        $wpdb->flush();
        $result = array_merge($result, $this->executeWpdbQuery($query, $resultType, $producesRows));
        $this->resultHarvester->harvestWpdbResult($result);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @return void
     */
    private function repairMissingTableIfNeeded(string $query, array &$result, array $options): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($options['skip_repair'] || $lastError === '' || !$this->core->errorClassifier()->taxonomy()->schema()->isMissingPluginTableError($lastError)) {
            return;
        }
        $this->core->repairPolicy()->attemptMissingTableRepairAndRetry($query, $result);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    private function retryInvalidDataIfNeeded(string $query, array &$result): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError !== '' && $this->core->errorClassifier()->taxonomy()->schema()->isInvalidDataError($lastError)) {
            $this->core->tableRepairer()->attemptInvalidDataRetry($query, $result);
        }
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @return void
     */
    private function retryDeadlockIfNeeded(string $query, array &$result, string $resultType, bool $producesRows): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError === '' || !$this->core->errorClassifier()->taxonomy()->connectivity()->isDeadlockOrLockTimeoutError($lastError)) {
            return;
        }

        usleep(50000);
        $result = array_merge($result, $this->executeWpdbQuery($query, $resultType, $producesRows));
        $this->resultHarvester->harvestWpdbResult($result);
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError !== '' && $this->core->errorClassifier()->taxonomy()->connectivity()->isDeadlockOrLockTimeoutError($lastError)) {
            $this->core->noticeState()->setPluginDbNotice(
                'lock_timeout',
                function_exists('__') ? __('A database lock wait timeout occurred. If this persists, contact your host - another process may be holding a long-running lock.', '404-solution') : 'A database lock wait timeout occurred. If this persists, contact your host - another process may be holding a long-running lock.',
                function_exists('__') ? __('A database lock wait timeout occurred. This is usually caused by another process holding a table lock on your database. It may resolve itself automatically, or contact your hosting provider if it persists.', '404-solution') : 'A database lock wait timeout occurred. This is usually caused by another process holding a table lock on your database. It may resolve itself automatically, or contact your hosting provider if it persists.',
                $lastError
            );
        }
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @return void
     */
    private function recoverCollationIfNeeded(string $query, array &$result, string $resultType, bool $producesRows): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError !== '' && $this->core->errorClassifier()->taxonomy()->schema()->isCollationError($lastError)) {
            $this->core->collationHelper()->recoverFromCollationMismatchAndRetry($query, $result, $producesRows, $resultType);
        }
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param int $timeoutSeconds
     * @return void
     */
    private function handleTimeoutIfNeeded(string $query, array &$result, int $timeoutSeconds): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError === '' || !$this->core->errorClassifier()->taxonomy()->connectivity()->isQueryTimeoutError($lastError)) {
            return;
        }

        $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->queryDiagnostics->extractSqlFilename($query);
        $this->logger->warn(
            'Query timed out after ' . $timeoutSeconds . 's. ' .
            'Query: ' . substr(preg_replace('/\s+/', ' ', trim($sqlInfo)) ?? $sqlInfo, 0, 500)
        );
        $result['rows'] = array();
        $result['timed_out'] = true;
    }

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    private function noteDatabaseIssueIfNeeded(array $result): void {
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError !== '') {
            $this->core->errorClassifier()->noteDatabaseIssueFromError($lastError);
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
            return array('rows' => $wpdb->get_results($query, $resultType));
        }

        // DAO-bypass-approved: Recovery retry executes the original SQL outside DAO routing to avoid recursion.
        $wpdb->query($query);
        return array('rows' => array());
    }

    /**
     * @param array<string, mixed> $result
     * @return string
     */
    private function lastErrorFromResult(array $result): string {
        return isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
    }
}
