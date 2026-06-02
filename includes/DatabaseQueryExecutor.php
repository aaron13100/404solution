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
 *   - executeAsTransaction():  multi-statement transactional execution with
 *                              deadlock-aware retry, BEGIN/COMMIT/ROLLBACK
 *                              bookkeeping, and infrastructure-error classification.
 *   - handleQueryErrorsAndLogging(): post-query error reporting + repair dispatch.
 *   - harvestWpdbResult():     copy wpdb->last_error / last_result /
 *                              rows_affected / insert_id into the result array.
 *   - applyDiagnosticLatencyIfConfigured(): test/diagnostic hook for simulating
 *                              latency before each query.
 *   - extractSqlFilename() / resolveCallerFromBacktrace(): resolve a stable
 *                              source identifier for safe logging.
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

    /** @var string Current wpdb result type for queryAndGetResults (ARRAY_A or OBJECT). */
    private $currentResultType = ARRAY_A;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $core, $f, $logger) {
        $this->core = $core;
        $this->f = $f;
        $this->logger = $logger;
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

        $ignoreErrorStrings = array();

        $options = array_merge(array('log_errors' => true,
            'log_too_slow' => true, 'ignore_errors' => array(),
            'query_params' => array(), 'skip_repair' => false,
            'result_type' => ARRAY_A, 'timeout' => 0),
            $options);
        $resultType = $options['result_type'] === OBJECT ? OBJECT : ARRAY_A;
        $this->currentResultType = $resultType;

        $ignoreErrorStrings = is_array($options['ignore_errors']) ? $options['ignore_errors'] : array();
        $queryParameters = is_array($options['query_params']) ? $options['query_params'] : array();

        $query = $this->core->doTableNameReplacements($query);

        if (!empty($queryParameters)) {
            /** @var literal-string $queryLiteral */
            $queryLiteral = $query;
            try {
                /** @var wpdb $wpdb */
                $preparedResult = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($queryLiteral), $queryParameters));
                $query = is_string($preparedResult) ? $preparedResult : $queryLiteral;
            } catch (Throwable $t) {
                $this->logger->debugMessage('wpdb prepare variadic call failed; retrying with array parameters.', $t);
                $preparedFallback = $wpdb->prepare($queryLiteral, $queryParameters);
                $query = $preparedFallback !== null ? $preparedFallback : $queryLiteral;
            }
        }

        $timeoutRaw = isset($options['timeout']) && is_numeric($options['timeout']) ? (int)$options['timeout'] : 0;
        $timeoutSeconds = $timeoutRaw > 0 ? $timeoutRaw : 60;
        $query = $this->core->queryTimeoutManager()->applyQueryTimeout($query, $timeoutSeconds);

        $this->applyDiagnosticLatencyIfConfigured();

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
            if ($producesRows) {
                $result['rows'] = $wpdb->get_results($query, $resultType);
            } else {
                $wpdb->query($query);
                $result['rows'] = array();
            }
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
        if (function_exists('abj404_benchmark_record_db_query')) {
            abj404_benchmark_record_db_query($elapsedMs);
        }
        if (function_exists('abj404_query_budget_record')
            && class_exists('ABJ_404_Solution_QueryBudgetInstrumentation', false)
            && ABJ_404_Solution_QueryBudgetInstrumentation::isEnabled()) {
            abj404_query_budget_record($this->extractSqlFilename($query), $elapsedMs, $timeoutSeconds);
        }
        $this->harvestWpdbResult($result);
        $lastErrorForObservedLog = is_string($result['last_error'] ?? null) ? $result['last_error'] : '';
        if ($lastErrorForObservedLog === '' || !$this->core->isTransientConnectionError($lastErrorForObservedLog)) {
            $this->core->sqlErrorReporter()->logObservedSqlError($query, $result, $options, $producesRows);
        }

        if ($producesRows && !is_array($result['rows'])) {
            $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
            $this->logger->errorMessage("Query result is not an array. Query: " . $sqlInfo,
                        new Exception("Query result is not an array.")); // allow-raw-error: behavior preserved from pre-extraction DatabaseCore; passed to logger as diagnostic context, not thrown
        }

        $lastErrorForSetStatement = is_string($result['last_error'] ?? null) ? $result['last_error'] : '';
        if ($lastErrorForSetStatement !== ''
            && $this->core->errorClassifier()->classifySetStatementFailure($lastErrorForSetStatement)
            && $this->core->queryTimeoutManager()->queryHasSetStatementWrapper($query)) {
            $this->core->retryWithoutSetStatementWrapper($query, $result, $resultType);
            $producesRows = $this->core->queryTimeoutManager()->queryProducesResultRows($query);
        }

        if ($result['last_error'] !== '' && $this->core->isTransientConnectionError($result['last_error'])) {
            $this->core->connectionManager()->ensureConnection();
            $wpdb->flush();
            if ($producesRows) {
                $result['rows'] = $wpdb->get_results($query, $resultType);
            } else {
                $wpdb->query($query);
                $result['rows'] = array();
            }
            $this->harvestWpdbResult($result);
        }

        if (!$options['skip_repair'] && $result['last_error'] !== '' && $this->core->errorClassifier()->isMissingPluginTableError(is_string($result['last_error']) ? $result['last_error'] : '')) {
            $this->core->attemptMissingTableRepairAndRetry($query, $result);
        }

        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';

        if ($lastError !== '' && $this->core->errorClassifier()->isInvalidDataError($lastError)) {
            $this->core->attemptInvalidDataRetry($query, $result);
        }

        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';

        if ($lastError !== '' && $this->core->errorClassifier()->isDeadlockOrLockTimeoutError($lastError)) {
            /** @var wpdb $wpdb */
            usleep(50000);
            if ($producesRows) {
                $result['rows'] = $wpdb->get_results($query, $resultType);
            } else {
                $wpdb->query($query);
                $result['rows'] = array();
            }
            $this->harvestWpdbResult($result);
            $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
            if ($lastError !== '' && $this->core->errorClassifier()->isDeadlockOrLockTimeoutError($lastError)) {
                // allow-em-dash: copied verbatim from existing user-facing localized string in DataAccess.php
                $this->core->setPluginDbNotice('lock_timeout', $this->core->noticeState()->localizeOrDefault('A database lock wait timeout occurred. If this persists, contact your host — another process may be holding a long-running lock.'), $lastError);
            }
        }

        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
        if ($lastError !== '' && $this->core->errorClassifier()->isCollationError($lastError)) {
            $this->core->recoverFromCollationMismatchAndRetry($query, $result, $producesRows, $resultType);
        }

        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
        if ($lastError !== '' && $this->core->errorClassifier()->isQueryTimeoutError($lastError)) {
            $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
            $this->logger->warn(
                'Query timed out after ' . $timeoutSeconds . 's. ' .
                'Query: ' . substr(preg_replace('/\s+/', ' ', trim($sqlInfo)) ?? $sqlInfo, 0, 500)
            );
            $result['rows'] = array();
            $result['timed_out'] = true;
        }

        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
        if ($lastError !== '') {
            $this->core->noteDatabaseIssueFromError($lastError);
        }

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
                $this->core->repairCorruptedTableAndRetry($query, $result);
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
                $this->core->isTransientConnectionError($lastErrorForClassification) ||
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

                $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);

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
                $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
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

    /**
     * Resolve a stable source identifier for safe logging.
     *
     * @param string $query
     * @return string
     */
    public function extractSqlFilename($query) {
        if (is_string($query) && $query !== '') {
            if (preg_match('/\/\*\s*abj404:src=([A-Za-z0-9_:#.\\\\\-]+)\s*\*\//i', $query, $m)) {
                return $m[1];
            }
            if (preg_match('/\/\*\s*-+\s*(.+?\.sql)\s+BEGIN\s*-+\s*\*\//i', $query, $m)) {
                return basename($m[1]);
            }
        }
        return $this->resolveCallerFromBacktrace();
    }

    /** @return string */
    public function resolveCallerFromBacktrace() {
        static $internalMethods = array(
            'extractSqlFilename' => true,
            'resolveCallerFromBacktrace' => true,
            'queryAndGetResults' => true,
            'attemptInvalidDataRetry' => true,
            'attemptMissingTableRepairAndRetry' => true,
            'repairCorruptedTableAndRetry' => true,
            'recoverFromCollationMismatchAndRetry' => true,
            'call_user_func_array' => true,
            'call_user_func' => true,
            '__call' => true,
        );
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40);
        foreach ($frames as $frame) {
            $fn = $frame['function'];
            if ($fn === '' || isset($internalMethods[$fn])) {
                continue;
            }
            if (strpos($fn, '{closure') !== false) {
                continue;
            }
            $cls = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
            $fullFile = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            if ($cls !== '' && (
                strpos($cls, 'Patchwork') !== false ||
                strpos($cls, 'PHPUnit\\') === 0
            )) {
                continue;
            }
            if (strpos($fn, 'Patchwork\\') !== false) {
                continue;
            }
            if ($fullFile !== '' && (
                strpos($fullFile, '/patchwork/') !== false ||
                strpos($fullFile, '\\patchwork\\') !== false
            )) {
                continue;
            }
            $file = $fullFile !== '' ? basename($fullFile) : '';
            $fileLabel = preg_replace('/\.php$/i', '', $file);
            if (!is_string($fileLabel)) {
                $fileLabel = $file;
            }
            if (($cls === 'ABJ_404_Solution_DatabaseCore'
                    || $cls === 'ABJ_404_Solution_DatabaseQueryExecutor'
                    || $cls === 'ABJ_404_Solution_DataAccess')
                && $fileLabel !== '' && $fileLabel !== 'DatabaseCore'
                && $fileLabel !== 'DatabaseQueryExecutor' && $fileLabel !== 'DataAccess') {
                return $fileLabel . '::' . $fn;
            }
            if ($cls !== '') {
                $shortClass = $cls;
                $nsPos = strrpos($shortClass, '\\');
                if ($nsPos !== false) {
                    $shortClass = substr($shortClass, $nsPos + 1);
                }
                if (strpos($shortClass, 'ABJ_404_Solution_') === 0) {
                    $shortClass = substr($shortClass, strlen('ABJ_404_Solution_'));
                }
                return $shortClass . '::' . $fn;
            }
            if ($fileLabel !== '') {
                return $fileLabel . '::' . $fn;
            }
            return $fn;
        }
        return 'unknown-source';
    }

    /** @return void */
    public function applyDiagnosticLatencyIfConfigured(): void {
        if (!function_exists('abj404_get_simulated_db_latency_ms')) {
            return;
        }
        $delayMs = absint(abj404_get_simulated_db_latency_ms());
        if ($delayMs <= 0) {
            return;
        }
        $delayMs = min(5000, $delayMs);
        usleep($delayMs * 1000);
    }

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    public function harvestWpdbResult(array &$result): void {
        global $wpdb;
        $result['last_error'] = (string)($wpdb->last_error ?? '');
        $result['last_result'] = $wpdb->last_result ?? array();
        $result['rows_affected'] = $wpdb->rows_affected ?? 0;
        $result['insert_id'] = $wpdb->insert_id ?? 0;
    }

    /**
     * Run a list of SQL statements as a single transaction with deadlock-aware retry.
     *
     * @param array<int, string> $statementArray
     * @return void
     */
    public function executeAsTransaction(array $statementArray): void {
        global $wpdb;
        $maxAttempts = 3;
        $lastException = null;
        $lastError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $allIsWell = true;
            $lastError = '';
            $lastException = null;
            try {
                $wpdb->query('START TRANSACTION');
                foreach ($statementArray as $statement) {
                    $wpdb->query($statement);
                    if ($wpdb->last_error != null && trim((string)$wpdb->last_error) !== '') {
                        $allIsWell = false;
                        $lastError = (string)$wpdb->last_error;
                        if (!$this->core->classifyAndHandleInfrastructureError($lastError)) {
                            $this->logger->errorMessage("Error executing SQL transaction: " . $lastError);
                            $this->logger->errorMessage("SQL causing the transaction error: " . $statement);
                        }
                        break;
                    }
                }
            } catch (Throwable $ex) {
                $allIsWell = false;
                $lastException = $ex;
                $lastError = $ex->getMessage();
            }

            if ($allIsWell && $lastException == null) {
                $wpdb->query('commit');
                return;
            }

            $wpdb->query('rollback');
            $retryable = $this->core->errorClassifier()->isDeadlockOrLockTimeoutError($lastError);
            if (!$retryable || $attempt >= $maxAttempts) {
                break;
            }
            $sleepMicros = 100000 + random_int(0, 200000);
            usleep($sleepMicros);
        }

        if ($lastException != null) {
            throw $lastException;
        }
        if ($lastError !== '') {
            throw new Exception($lastError); // allow-raw-error: behavior preserved from pre-extraction DatabaseCore::executeAsTransaction
        }
    }
}
