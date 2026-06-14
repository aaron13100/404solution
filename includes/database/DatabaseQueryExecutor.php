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
 *                              recovery-policy dispatch, and final error reporter
 *                              dispatch).
 *   - queryScalarInt():        thin wrapper that runs a query and returns the
 *                              first scalar column of the first row as an int.
 *
 * The executor holds a DatabaseCore back-reference and calls back into core's
 * already-extracted helpers (connection manager, query-timeout manager, error
 * classifier, sql-error reporter, recovery policy, table-name resolver,
 * notice-state holder, collation helper, table repairer) by their public method
 * names. This mirrors
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

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseWpdbResultHarvester */
    private $resultHarvester;

    /** @var ABJ_404_Solution_DatabaseQueryDiagnostics */
    private $queryDiagnostics;

    /** @var ABJ_404_Solution_DatabaseQueryRecoveryPolicy */
    private $queryRecoveryPolicy;

    /** @var string Current wpdb result type for queryAndGetResults (ARRAY_A or OBJECT). */
    private $currentResultType = ARRAY_A;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseWpdbResultHarvester $resultHarvester
     * @param ABJ_404_Solution_DatabaseQueryDiagnostics $queryDiagnostics
     * @param ABJ_404_Solution_DatabaseQueryRecoveryPolicy $queryRecoveryPolicy
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $core,
        $logger,
        ABJ_404_Solution_DatabaseWpdbResultHarvester $resultHarvester,
        ABJ_404_Solution_DatabaseQueryDiagnostics $queryDiagnostics,
        ABJ_404_Solution_DatabaseQueryRecoveryPolicy $queryRecoveryPolicy
    ) {
        $this->core = $core;
        $this->logger = $logger;
        $this->resultHarvester = $resultHarvester;
        $this->queryDiagnostics = $queryDiagnostics;
        $this->queryRecoveryPolicy = $queryRecoveryPolicy;
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

        // wpdb unavailable: degrade to an empty result rather than crashing on
        // method_exists(null, ...) or null->method() downstream. Happens in
        // very early-life code paths (fresh-install background workers reaching
        // the DAO before WordPress has populated $wpdb, CLI bootstrap, unit
        // tests that exercise the suggestion pipeline without a real wpdb).
        //
        // "Unavailable" is not just null: an object that is not a real wpdb --
        // one missing prepare()/get_results()/query() -- is equally unusable and
        // must degrade the same way instead of fataling with
        // "Call to undefined method ...::prepare()" inside prepareQueryParameters()
        // / executeWpdbQuery(). This mirrors the method_exists() guard already
        // used for suppress_errors() below.
        //
        // The DAO result contract (last_error populated, rows as an empty array)
        // is preserved so queryAndGetResults remains the centralized
        // graceful-degradation seam (Defensive Coding #2/#11).
        if (!$this->wpdbCanRunQueries($wpdb)) {
            return array(
                'rows' => array(),
                'rows_affected' => 0,
                'last_error' => 'wpdb unavailable',
                'elapsed_time' => 0.0,
            );
        }

        $ignoreErrorStrings = $this->normalizeIgnoreErrorStrings($options['ignore_errors']);
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
        if ($lastErrorForObservedLog === '' || !$this->core->errorClassifier()->taxonomy()->connectivity()->isTransientConnectionError($lastErrorForObservedLog)) {
            $this->core->sqlErrorReporter()->logObservedSqlError($query, $result, $options, $producesRows);
        }

        if ($producesRows && !is_array($result['rows'])) {
            $this->queryDiagnostics->logMalformedRowsIfNeeded($query, $result['rows']);
        }

        $producesRows = $this->queryRecoveryPolicy->recoverQueryResult(
            $query, $result, $options, $resultType, $producesRows, $timeoutSeconds
        );

        if ($suppressWpdbErrors) {
            /** @var wpdb $wpdb */
            $wpdb->suppress_errors($previousSuppressState);
        }

        $this->core->sqlErrorReporter()->handleFinalSqlErrorReporting(
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
     * @param mixed $ignoreErrors
     * @return array<int|string, string>
     */
    private function normalizeIgnoreErrorStrings($ignoreErrors): array {
        if (!is_array($ignoreErrors)) {
            return array();
        }

        $ignoreErrorStrings = array();
        foreach ($ignoreErrors as $key => $value) {
            if (is_string($value)) {
                $ignoreErrorStrings[$key] = $value;
            }
        }
        return $ignoreErrorStrings;
    }

    /**
     * Whether $wpdb is a usable query object for this executor's needs.
     *
     * A usable wpdb must be able to bind parameters (prepare()) and run at
     * least one kind of statement (get_results() for SELECTs, query() for
     * everything else). A non-object (null during early boot / CLI) -- or an
     * object that is not a real wpdb and cannot answer those calls -- must
     * degrade to the empty-result contract rather than fatal with
     * "Call to undefined method ...". A real wpdb (and every db drop-in:
     * HyperDB, LudicrousDB, ...) implements all of these, so this is a no-op on
     * a live site and only changes behavior for a malformed $wpdb.
     *
     * We require prepare() plus *either* read method rather than all three so a
     * legitimate read-only double (a wpdb that only ever runs SELECTs through
     * this executor) is not rejected, while a bare foreign object with none of
     * them still is.
     *
     * Uses is_callable() rather than method_exists() so it also accepts test
     * doubles that route methods through __call() (e.g. Mockery wpdb mocks),
     * while still rejecting a bare object that has neither the methods nor a
     * __call() handler.
     *
     * @param mixed $wpdb
     * @return bool
     */
    private function wpdbCanRunQueries($wpdb): bool {
        return is_object($wpdb)
            && is_callable(array($wpdb, 'prepare'))
            && (is_callable(array($wpdb, 'get_results')) || is_callable(array($wpdb, 'query')));
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

}
