<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central query pipeline for the plugin's DAO layer.
 *
 * Extracted from DatabaseCore as part of the (5/6) DatabaseCore decomposition.
 * Owns the run-a-SQL-query path that every DAO module routes through:
 *
 *   - queryAndGetResults():    the main pipeline (option normalization, table-name
 *                              substitution, prepare(), timeout wrapping, latency
 *                              simulation, get_results/query call, error harvest,
 *                              transient-connection / set-statement / missing-table /
 *                              invalid-data / deadlock / collation / timeout retries,
 *                              and final error-logging / repair dispatch).
 *   - queryScalarInt():        thin wrapper that runs a query and returns the
 *                              first scalar column of the first row as an int.
 *   - handleQueryErrorsAndLogging(): post-query error reporting + repair dispatch.
 *
 * The executor holds a DatabaseCore back-reference and calls back into core's
 * already-extracted helpers (connection manager, query-timeout manager, error
 * classifier, sql-error reporter, table-name resolver, notice-state holder,
 * collation helper, table repairer) by their public method names. This mirrors
 * the back-reference pattern used by DatabaseConnectionManager,
 * DatabaseQueryTimeoutManager, DatabaseErrorClassifier, and
 * DatabaseSqlErrorReporter.
 *
 * The per-request $currentResultType state lives here (not on DatabaseCore) so
 * the executor fully owns its pipeline state. DatabaseCore::getCurrentResultType()
 * delegates here for back-compat with DatabaseErrorClassifier's missing-table
 * repair path, which needs to re-run the original query with the same wpdb
 * output type.
 */
class ABJ_404_Solution_DatabaseQueryExecutor {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseWpdbResultHarvester */
    private $resultHarvester;

    /** @var ABJ_404_Solution_DatabaseQueryDiagnostics */
    private $queryDiagnostics;

    /** @var string Current wpdb result type for queryAndGetResults (ARRAY_A or OBJECT). */
    private $currentResultType = ARRAY_A;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseWpdbResultHarvester $resultHarvester
     * @param ABJ_404_Solution_DatabaseQueryDiagnostics $queryDiagnostics
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $core,
        $f,
        $logger,
        ABJ_404_Solution_DatabaseWpdbResultHarvester $resultHarvester,
        ABJ_404_Solution_DatabaseQueryDiagnostics $queryDiagnostics
    ) {
        $this->core = $core;
        $this->f = $f;
        $this->logger = $logger;
        $this->resultHarvester = $resultHarvester;
        $this->queryDiagnostics = $queryDiagnostics;
        $this->currentResultType = ARRAY_A;
    }

    /** @return string */
    public function getCurrentResultType(): string {
        return $this->currentResultType;
    }

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return int
     */
    public function queryScalarInt($query, $options = array()): int {
        $result = $this->queryAndGetResults($query, $options);
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $first = reset($rows[0]);
        return is_scalar($first) ? (int)$first : 0;
    }

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function queryAndGetResults($query, $options = array()): array {
        global $wpdb;

        $this->core->connectionManager()->ensureConnection();

        $options = $this->normalizeQueryOptions($options);
        $resultType = $this->normalizeResultType($options['result_type']);
        $this->currentResultType = $resultType;

        $ignoreErrorStrings = is_array($options['ignore_errors']) ? $options['ignore_errors'] : array();
        $queryParameters = is_array($options['query_params']) ? $options['query_params'] : array();

        $query = $this->core->doTableNameReplacements($query);
        $query = $this->prepareQueryParameters($query, $queryParameters);

        $timeoutRaw = isset($options['timeout']) && is_numeric($options['timeout']) ? (int)$options['timeout'] : 0;
        $timeoutSeconds = $timeoutRaw > 0 ? $timeoutRaw : 60;
        $query = $this->core->queryTimeoutManager()->applyQueryTimeout($query, $timeoutSeconds);

        $this->queryDiagnostics->applyDiagnosticLatencyIfConfigured();

        $timer = new ABJ_404_Solution_Timer();

        $suppressWpdbErrors = !$options['log_errors'] && method_exists($wpdb, 'suppress_errors');
        $previousSuppressState = false;
        if ($suppressWpdbErrors) {
            /** @var wpdb $wpdb */
            $previousSuppressState = $wpdb->suppress_errors(true);
        }

        $producesRows = $this->core->queryTimeoutManager()->queryProducesResultRows($query);

        $result = array();
        try {
            $result = $this->executeWpdbQuery($query, $resultType, $producesRows);
        } catch (Throwable $e) {
            $result['elapsed_time'] = $timer->stop();
            $this->core->sqlErrorReporter()->logSqlThrowable($query, $e, $options, $producesRows);
            if ($suppressWpdbErrors) {
                /** @var wpdb $wpdb */
                $wpdb->suppress_errors($previousSuppressState);
            }
            throw $e;
        }

        $result['elapsed_time'] = $timer->stop();
        $elapsedMs = ((float)$result['elapsed_time']) * 1000.0;
        $this->queryDiagnostics->recordQueryBudgetIfEnabled($query, $elapsedMs, $timeoutSeconds);
        $this->resultHarvester->harvestWpdbResult($result);
        $lastErrorForObservedLog = is_string($result['last_error'] ?? null) ? $result['last_error'] : '';
        if ($lastErrorForObservedLog === '' || !$this->core->errorClassifier()->isTransientConnectionError($lastErrorForObservedLog)) {
            $this->core->sqlErrorReporter()->logObservedSqlError($query, $result, $options, $producesRows);
        }

        if ($producesRows && !is_array($result['rows'])) {
            $this->queryDiagnostics->logMalformedRowsIfNeeded($query, $result['rows']);
        }

        $producesRows = $this->recoverQueryResult($query, $result, $options, $resultType, $producesRows, $timeoutSeconds);

        if ($suppressWpdbErrors) {
            /** @var wpdb $wpdb */
            $wpdb->suppress_errors($previousSuppressState);
        }

        $this->handleQueryErrorsAndLogging(
            $query, $result, $options, $ignoreErrorStrings, $timer
        );

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeQueryOptions(array $options): array {
        return array_merge(array(
            'log_errors' => true,
            'log_too_slow' => true,
            'ignore_errors' => array(),
            'query_params' => array(),
            'skip_repair' => false,
            'result_type' => ARRAY_A,
            'timeout' => 0,
        ), $options);
    }

    /**
     * @param mixed $resultType
     * @return 'OBJECT'|'ARRAY_A'
     */
    private function normalizeResultType($resultType): string {
        return $resultType === OBJECT ? OBJECT : ARRAY_A;
    }

    /**
     * @param string $query
     * @param array<int|string, mixed> $queryParameters
     * @return string
     */
    private function prepareQueryParameters(string $query, array $queryParameters): string {
        if (empty($queryParameters)) {
            return $query;
        }

        global $wpdb;
        /** @var literal-string $queryLiteral */
        $queryLiteral = $query;
        $orderedParameters = array_values($queryParameters);
        try {
            /** @var wpdb $wpdb */
            $preparedResult = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($queryLiteral), $orderedParameters));
            return is_string($preparedResult) ? $preparedResult : $queryLiteral;
        } catch (Throwable $t) {
            $this->logger->debugMessage('wpdb prepare variadic call failed; retrying with array parameters.', $t);
            $preparedFallback = $wpdb->prepare($queryLiteral, $orderedParameters);
            return $preparedFallback !== null ? $preparedFallback : $queryLiteral;
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
            return array('rows' => $wpdb->get_results($query, $resultType));
        }

        $wpdb->query($query);
        return array('rows' => array());
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @param bool $producesRows
     * @param int $timeoutSeconds
     * @return bool
     */
    private function recoverQueryResult(
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
            || !$this->core->errorClassifier()->classifySetStatementFailure($lastError)
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
        if ($lastError === '' || !$this->core->errorClassifier()->isTransientConnectionError($lastError)) {
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
        if ($options['skip_repair'] || $lastError === '' || !$this->core->errorClassifier()->isMissingPluginTableError($lastError)) {
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
        if ($lastError !== '' && $this->core->errorClassifier()->isInvalidDataError($lastError)) {
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
        if ($lastError === '' || !$this->core->errorClassifier()->isDeadlockOrLockTimeoutError($lastError)) {
            return;
        }

        usleep(50000);
        $result = array_merge($result, $this->executeWpdbQuery($query, $resultType, $producesRows));
        $this->resultHarvester->harvestWpdbResult($result);
        $lastError = $this->lastErrorFromResult($result);
        if ($lastError !== '' && $this->core->errorClassifier()->isDeadlockOrLockTimeoutError($lastError)) {
            // allow-em-dash: copied verbatim from existing user-facing localized string in DataAccess.php
            $this->core->setPluginDbNotice('lock_timeout', $this->core->noticeState()->localizeOrDefault('A database lock wait timeout occurred. If this persists, contact your host — another process may be holding a long-running lock.'), $lastError);
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
        if ($lastError !== '' && $this->core->errorClassifier()->isCollationError($lastError)) {
            $this->core->recoverFromCollationMismatchAndRetry($query, $result, $producesRows, $resultType);
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
        if ($lastError === '' || !$this->core->errorClassifier()->isQueryTimeoutError($lastError)) {
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
     * @param array<string, mixed> $result
     * @return string
     */
    private function lastErrorFromResult(array $result): string {
        return isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
    }

    /**
     * Handle error logging, repair attempts, and slow-query logging after query execution.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @param array<int|string, string> $ignoreErrorStrings
     * @param ABJ_404_Solution_Timer $timer
     * @return void
     */
    private function handleQueryErrorsAndLogging(
        string $query, array &$result, array $options,
        array $ignoreErrorStrings, ABJ_404_Solution_Timer $timer
    ): void {
        global $wpdb;

        if ($options['log_errors'] && $result['last_error'] != '') {
            if ($this->f->strpos($result['last_error'],
                    " is marked as crashed ") !== false) {
                $this->core->repairTable($result['last_error']);
            }
            if ($this->f->strpos($result['last_error'],
                    "ALTER TABLE causes auto_increment resequencing") !== false &&
                    $this->f->strpos($result['last_error'], "resulting in duplicate entry") !== false) {
                $this->core->repairDuplicateIDs($result['last_error'], $query);
            }
            if ($this->core->errorClassifier()->isIncorrectKeyFileError(is_string($result['last_error']) ? $result['last_error'] : '')) {
                $this->core->tableRepairer()->repairCorruptedTableAndRetry($query, $result);
            }

            if ($result['last_error'] === '') { return; }

            $reportError = true;
            foreach ($ignoreErrorStrings as $ignoreThis) {
                if (is_string($ignoreThis) && strpos($result['last_error'], $ignoreThis) !== false) {
                    $reportError = false;
                    break;
                }
            }

            $lastErrorForClassification = is_string($result['last_error']) ? $result['last_error'] : '';
            if ($reportError && (
                $this->core->errorClassifier()->isDiskFullError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isReadOnlyError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isQuotaLimitError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isInvalidDataError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isCollationError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isMissingPluginTableError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isIncorrectKeyFileError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isCrashedTableError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isDeadlockOrLockTimeoutError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isGaleraConflictError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isTransientConnectionError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isQueryTimeoutError($lastErrorForClassification) ||
                $this->core->errorClassifier()->isAccessDeniedError($lastErrorForClassification)
            )) {
                $this->logger->warn("Server-side DB issue (handled): " . $lastErrorForClassification);
                $reportError = false;
            }

            if ($reportError) {
                $stripped_query = 'n/a';
                if ($this->core->errorClassifier()->isInvalidDataError($result['last_error'])) {
                    $strippedResult = $this->core->tableRepairer()->get_stripped_query_result($query);
                    $stripped_query = is_string($strippedResult) ? $strippedResult : 'n/a';
                }

                $extraDataQuery = "select @@max_join_size as max_join_size, " .
                    "@@sql_big_selects as sql_big_selects, " .
                    "@@character_set_database as character_set_database";
                $someMySQLVariables = $wpdb->get_results($extraDataQuery, ARRAY_A);
                $variables = print_r($someMySQLVariables, true);

                $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->queryDiagnostics->extractSqlFilename($query);

                $dbVer = $wpdb->db_version();
                $this->logger->errorMessage("Ugh. SQL query error: " . (is_string($result['last_error']) ? $result['last_error'] : '') .
                        ", SQL: " . $sqlInfo .
                        ", Execution time: " . round($timer->getElapsedTime(), 2) .
                        ", DB ver: " . (is_string($dbVer) ? $dbVer : 'unknown') .
                        ", Variables: " . $variables .
                        ", stripped_query: " . $stripped_query);
            }

        } else {
            if ($options['log_too_slow'] && $timer->getElapsedTime() > 5) {
                $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->queryDiagnostics->extractSqlFilename($query);
                $this->logger->debugMessage("Slow query (" . round($timer->getElapsedTime(), 2) . " seconds): " .
                        $sqlInfo);
            }

            if ($result['last_error'] === '') {
                if (!$this->core->noticeState()->isServerSideIssueNoted() && !$this->core->noticeState()->isServerSideIssueChecked()) {
                    $this->core->noticeState()->markServerSideIssueChecked();
                    $existing = $this->core->getRuntimeFlag('abj404_plugin_db_notice');
                    $excludedTypes = array('stale_permalink_cache', 'missing_table');
                    if (is_array($existing) && !empty($existing['type'])
                        && !in_array($existing['type'], $excludedTypes, true)) {
                        $this->core->noticeState()->markServerSideIssueNoted();
                    }
                }
                if ($this->core->noticeState()->isServerSideIssueNoted() && !$this->core->isWriteBlockActive() && !$this->core->errorClassifier()->isQuotaCooldownActive()) {
                    $this->core->noticeState()->clearServerSideDbNotice();
                }
            }
        }
    }

}
