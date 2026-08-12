<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Engine-aware per-query timeout helpers.
 *
 * Centralizes the SQL-level timeout mechanism so every query routed through
 * queryAndGetResults() inherits a fail-fast deadline before the host's silent
 * connection-drop kicks in:
 *
 *   - MySQL 5.7.8+ pure SELECT: MAX_EXECUTION_TIME(ms) optimizer hint.
 *   - MySQL non-SELECT: no SQL-level mechanism (left unchanged).
 *   - MariaDB 10.1+ any DML/DDL: SET STATEMENT max_statement_time=N FOR ...
 *
 * Also provides query-shape probes used by the routing layer in
 * queryAndGetResults() to decide between $wpdb->get_results() vs
 * $wpdb->query() based on whether a wrapped query produces rows.
 *
 * Composed into ABJ_404_Solution_DataAccess. No state of its own; uses the
 * global $wpdb to detect engine version. Pure functions otherwise.
 */
class ABJ_404_Solution_DatabaseQueryTimeoutManager {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $core, $logger) {
        $this->core = $core;
        $this->logger = $logger;
    }

    /**
     * Forward DatabaseCore infrastructure calls that remain owned by the core.
     *
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        return $this->core->$name(...$arguments);
    }

    /**
     * @param string $query
     * @return bool
     */
    public function queryStartsWithSelect(string $query): bool {
        // SQL loaded from .sql files is wrapped in leading comments.
        // Treat "/* ... */ SELECT ..." as a SELECT query for timeout purposes.
        return preg_match('/^\s*(?:\/\*[\s\S]*?\*\/\s*)*SELECT\s/i', $query) === 1;
    }

    /**
     * Returns true if the query produces a result set (rows), so it should
     * be sent through $wpdb->get_results(). Returns false for INSERT, UPDATE,
     * DELETE, REPLACE, DDL, SET, etc. Those should go through $wpdb->query().
     *
     * Sees past leading SQL comments and any `SET STATEMENT max_statement_time=N FOR `
     * timeout wrapper. The wrapper is critical because applyQueryTimeout() prepends
     * it on MariaDB, which would otherwise mask the underlying statement type.
     *
     * Misclassification triggered the 4.1.7 spell-check `mysqli_num_fields(true)`
     * TypeError on PHP 8.1+ MariaDB sites. See DataAccessNonSelectRoutingTest.
     *
     * @param string $query
     * @return bool
     */
    public function queryProducesResultRows(string $query): bool {
        $stripped = (string)preg_replace('/^\s*(?:\/\*[\s\S]*?\*\/\s*)+/', '', $query);
        $stripped = (string)preg_replace(
            '/^\s*SET\s+STATEMENT\s+\w+\s*=\s*\d+\s+FOR\s+/i',
            '',
            $stripped,
            1
        );
        // Strip nested leading comments inside the SET STATEMENT wrapper too.
        $stripped = (string)preg_replace('/^\s*(?:\/\*[\s\S]*?\*\/\s*)+/', '', $stripped);
        return preg_match('/^\s*(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC)\s/i', $stripped) === 1;
    }

    /**
     * Apply a DB-level timeout to any query type.
     *
     * Dispatches to the appropriate engine-specific mechanism:
     * - Pure SELECT: MySQL optimizer hint or MariaDB SET STATEMENT
     * - INSERT...SELECT (or any non-leading SELECT): MariaDB SET STATEMENT
     *   or MySQL hint injected into the embedded SELECT
     * - Other DML/DDL: MariaDB SET STATEMENT (MySQL has no mechanism for
     *   non-SELECT timeouts; these queries are typically fast)
     *
     * Skips queries that already carry a timeout hint to prevent double-wrapping
     * (e.g. callers that used to apply timeouts manually before this was centralized).
     *
     * @param string $query Any SQL query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout applied (or unchanged if no mechanism)
     */
    public function applyQueryTimeout(
        string $query,
        int $timeoutSeconds,
        ?ABJ_404_Solution_DatabaseQueryPreflightTracer $preflight = null
    ): string {
        // Skip if a timeout hint is already present (prevents double-wrapping).
        if (preg_match('/MAX_EXECUTION_TIME|max_statement_time/i', $query)) {
            return $query;
        }

        if ($this->queryStartsWithSelect($query)) {
            return $this->applySelectTimeout($query, $timeoutSeconds, $preflight);
        }
        if (preg_match('/SELECT\s/i', $query)) {
            // INSERT...SELECT, CREATE TABLE...SELECT, etc.
            return $this->applyNonLeadingSelectTimeout($query, $timeoutSeconds, $preflight);
        }
        // Plain INSERT, UPDATE, DELETE, DDL: only MariaDB has a timeout mechanism.
        return $this->applyStatementTimeout($query, $timeoutSeconds, $preflight);
    }

    /**
     * Detect the DB engine. Returns true for MariaDB, false for MySQL/unknown.
     * @return bool
     */
    public function isMariaDB(
        ?ABJ_404_Solution_DatabaseQueryPreflightTracer $preflight = null
    ): bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return false;
        }
        $source = isset($wpdb->dbh)
            && function_exists('mysqli_get_server_info')
            && $wpdb->dbh instanceof \mysqli
                ? 'mysqli_server_info'
                : 'wpdb_db_version';
        $detect = static function () use ($wpdb): bool {
            if (isset($wpdb->dbh) && function_exists('mysqli_get_server_info')
                    && $wpdb->dbh instanceof \mysqli) {
                $dbVersion = mysqli_get_server_info($wpdb->dbh);
            } else {
                /** @var wpdb $wpdb */
                $dbVersion = $wpdb->db_version() ?? '';
            }
            return stripos((string)$dbVersion, 'mariadb') !== false;
        };
        try {
            if ($preflight === null) {
                return $detect();
            }
            return $preflight->trace(
                ABJ_404_Solution_DatabaseQueryPreflightTracer::ENGINE_DETECTION,
                $detect,
                array(
                    'fields' => array('engine_source' => $source),
                    'result_fields' => static fn(bool $isMariaDb): array => array(
                        'engine' => $isMariaDb ? 'mariadb' : 'mysql_or_unknown',
                    ),
                )
            );
        } catch (\Throwable $e) {
            // Falling back to "not MariaDB" is safe (it only forgoes MariaDB's
            // timeout syntax), and on a test double or an early-boot wpdb
            // without db_version() it is also expected. But safe is not the
            // same as uninteresting: if this starts throwing at runtime, the
            // statement-timeout path quietly turns itself off on every query
            // for the rest of the request, and a silent catch here is the
            // reason nobody would ever find out. Record it and degrade.
            $this->logger->debugMessage(
                'DB engine detection failed; assuming not-MariaDB and skipping the MariaDB '
                . 'statement-timeout syntax. Source: ' . $source . '. '
                . get_class($e) . ' (code ' . (string)$e->getCode() . '): ' . $e->getMessage(),
                $e
            );
            return false;
        }
    }

    /**
     * Apply timeout to a pure SELECT query.
     *
     * MySQL 5.7.8+: MAX_EXECUTION_TIME(ms) optimizer hint.
     * MariaDB 10.1+: SET STATEMENT max_statement_time=N FOR ...
     *
     * @param string $query A SELECT query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout hint applied
     */
    public function applySelectTimeout(
        string $query,
        int $timeoutSeconds,
        ?ABJ_404_Solution_DatabaseQueryPreflightTracer $preflight = null
    ): string {
        if ($this->isMariaDB($preflight)
                && !$this->isSetStatementWrapperUnsupported($preflight)) {
            return "SET STATEMENT max_statement_time=" . $timeoutSeconds . " FOR " . $query;
        }
        // MySQL hint also works for the MariaDB-with-disabled-wrapper case:
        // MariaDB silently ignores unrecognized optimizer hints (parses as a
        // comment), so the SELECT runs without a per-statement deadline.
        $timeoutMs = $timeoutSeconds * 1000;
        $timedQuery = preg_replace(
            '/^(\s*(?:\/\*[\s\S]*?\*\/\s*)*SELECT\s)/i',
            '$1/*+ MAX_EXECUTION_TIME(' . $timeoutMs . ') */ ',
            $query
        );
        return ($timedQuery !== null) ? $timedQuery : $query;
    }

    /**
     * Apply timeout to a query containing a non-leading SELECT (INSERT...SELECT, etc.).
     *
     * MariaDB 10.1+: SET STATEMENT max_statement_time=N FOR ... (wraps entire statement).
     * MySQL 5.7.8+: MAX_EXECUTION_TIME(ms) hint injected into the first SELECT keyword.
     *
     * @param string $query An INSERT...SELECT or similar query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout applied
     */
    public function applyNonLeadingSelectTimeout(
        string $query,
        int $timeoutSeconds,
        ?ABJ_404_Solution_DatabaseQueryPreflightTracer $preflight = null
    ): string {
        if ($this->isMariaDB($preflight)
                && !$this->isSetStatementWrapperUnsupported($preflight)) {
            return "SET STATEMENT max_statement_time=" . $timeoutSeconds . " FOR " . $query;
        }
        $timeoutMs = $timeoutSeconds * 1000;
        $timedQuery = preg_replace(
            '/(SELECT\s)/i',
            'SELECT /*+ MAX_EXECUTION_TIME(' . $timeoutMs . ') */ ',
            $query,
            1
        );
        return ($timedQuery !== null) ? $timedQuery : $query;
    }

    /**
     * Apply timeout to a non-SELECT statement (INSERT, UPDATE, DELETE, DDL).
     *
     * MariaDB 10.1+: SET STATEMENT max_statement_time=N FOR ... works on all DML.
     * MySQL: has no SQL-level timeout mechanism for non-SELECT queries.
     *
     * @param string $query Any non-SELECT query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout applied (unchanged on MySQL)
     */
    public function applyStatementTimeout(
        string $query,
        int $timeoutSeconds,
        ?ABJ_404_Solution_DatabaseQueryPreflightTracer $preflight = null
    ): string {
        if ($this->isMariaDB($preflight)
                && !$this->isSetStatementWrapperUnsupported($preflight)) {
            return "SET STATEMENT max_statement_time=" . $timeoutSeconds . " FOR " . $query;
        }
        // MySQL has no timeout mechanism for non-SELECT queries. MariaDB hosts
        // that have rejected SET STATEMENT (privilege denied or syntax not
        // understood) earlier in this request fall through here too: the
        // staged build's per-tick budget enforcement degrades to the cron
        // tick's own wall-clock deadline rather than per-statement.
        return $query;
    }

    /**
     * Read the request-local/persisted wrapper capability under its own
     * preflight boundary. The result descriptor exposes only hit/miss state.
     */
    private function isSetStatementWrapperUnsupported(
        ?ABJ_404_Solution_DatabaseQueryPreflightTracer $preflight
    ): bool {
        if ($preflight === null) {
            return ABJ_404_Solution_DatabaseRuntimeState::isSetStatementWrapperUnsupported();
        }
        $source = ABJ_404_Solution_DatabaseRuntimeState::setStatementWrapperCapabilitySource();
        return $preflight->trace(
            ABJ_404_Solution_DatabaseQueryPreflightTracer::TIMEOUT_CAPABILITY_CACHE,
            static fn(): bool =>
                ABJ_404_Solution_DatabaseRuntimeState::isSetStatementWrapperUnsupported(),
            array(
                'fields' => array('cache_source' => $source),
                'result_fields' => static function (bool $unsupported) use ($source): array {
                    if ($source === 'transient') {
                        return array(
                            'cache_outcome' => $unsupported
                                ? 'hit_unsupported'
                                : 'miss_supported',
                        );
                    }
                    return array(
                        'cache_outcome' => $unsupported
                            ? 'request_local_unsupported'
                            : 'request_local_supported',
                    );
                },
            )
        );
    }

    /**
     * True when $query begins with the timeout wrapper this trait emits:
     * `SET STATEMENT max_statement_time=N FOR ...`. Used by the wrapper
     * fallback path to confirm the failed query was wrapped before stripping.
     *
     * @param string $query
     * @return bool
     */
    public function queryHasSetStatementWrapper(string $query): bool {
        return preg_match(
            '/^\s*SET\s+STATEMENT\s+max_statement_time\s*=\s*\d+\s+FOR\s+/i',
            $query
        ) === 1;
    }

    /**
     * Strip the leading `SET STATEMENT max_statement_time=N FOR ` wrapper.
     * Returns the unwrapped statement, or the input unchanged if no wrapper
     * is present.
     *
     * @param string $query
     * @return string
     */
    public function stripSetStatementWrapper(string $query): string {
        $stripped = preg_replace(
            '/^\s*SET\s+STATEMENT\s+max_statement_time\s*=\s*\d+\s+FOR\s+/i',
            '',
            $query,
            1
        );
        return is_string($stripped) ? $stripped : $query;
    }

    /**
     * Re-execute a query without the `SET STATEMENT max_statement_time=N FOR `
     * wrapper after the server rejected the wrapper itself (privilege denied
     * or syntax not understood). Caches the result in request-local state and
     * a short-lived WordPress transient so subsequent queries and fresh PHP
     * requests skip the known-unsupported wrapper until the capability is
     * probed again.
     *
     * Result harvest mirrors retrying recovery paths such as
     * attemptMissingTableRepairAndRetry():
     * write into $result by reference so the caller's downstream branches see
     * the retry's outcome instead of the original error.
     *
     * $query is also passed by reference and mutated to the unwrapped form
     * on success. Downstream retry paths in queryAndGetResults() (transient
     * reconnect, deadlock retry, etc.) re-execute $query, so leaving the
     * wrapper in place would re-trigger the same rejection on every retry.
     *
     * @param string $query        Passed by reference. Mutated to the unwrapped form.
     * @param array<string, mixed> $result Passed by reference; updated with retry rows / error.
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType wpdb output type for get_results().
     * @param ABJ_404_Solution_DatabaseQueryRecoveryTracer|null $tracer
     * @return void
     */
    public function retryWithoutSetStatementWrapper(
        string &$query,
        array &$result,
        string $resultType,
        ?ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer = null
    ): void {
        if (!$this->queryHasSetStatementWrapper($query)) {
            // Defensive: nothing to strip. Caller misclassified the error.
            return;
        }
        $unwrapped = $this->stripSetStatementWrapper($query);
        // Cache the negative result locally and across requests so the host
        // does not repeatedly pay for the same known-failing capability probe.
        ABJ_404_Solution_DatabaseRuntimeState::setSetStatementWrapperUnsupported(true);
        if (class_exists('ABJ_404_Solution_AjaxStageDiagnostics')) {
            ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata(array(
                'db_timeout_mode' => 'unwrapped',
            ));
        }
        $this->logger->warn(
            'SET STATEMENT timeout wrapper rejected by server; '
            . 'retrying query without a DB-level timeout and caching the '
            . 'unsupported capability for one hour.'
        );

        global $wpdb;
        /** @var wpdb $wpdb */
        $retryError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error']
            : '';
        if ($tracer === null) {
            if (!$this->core->connectionManager()->resetForRetry($retryError)) {
                return;
            }
        } else {
            $reset = $tracer->traceOperation(
                'timeout_wrapper',
                'connection_retry_reset',
                fn(): bool => $this->core->connectionManager()->resetForRetry($retryError)
            );
            if (!$reset) {
                return;
            }
        }
        // Mutate $query so downstream retry paths execute the unwrapped form.
        $query = $unwrapped;
        // Re-route classification past any leading comments and the (now-absent)
        // wrapper. Using queryProducesResultRows on the unwrapped query keeps
        // the routing correct for INSERT/UPDATE/DELETE/DDL.
        // SET STATEMENT wrapper-rejection recovery is a DAO-internal primitive
        // (parallel to attemptMissingTableRepairAndRetry). It must call
        // $wpdb directly: re-routing through queryAndGetResults() would
        // re-enter the same SET STATEMENT detection path, deepening the call
        // stack on every retry. Per-bypass approval markers are inline below.
        $unwrappedProducesRows = $this->queryProducesResultRows($unwrapped);
        $retry = function () use ($wpdb, $unwrapped, $resultType, $unwrappedProducesRows): array {
            if ($unwrappedProducesRows) {
                // DAO-bypass-approved: SET STATEMENT wrapper-rejection retry primitive.
                $retried = array('rows' => $wpdb->get_results($unwrapped, $resultType));
            } else {
                // DAO-bypass-approved: SET STATEMENT wrapper-rejection retry primitive.
                $wpdb->query($unwrapped);
                $retried = array('rows' => array());
            }
            $this->core->resultHarvester()->harvestWpdbResult($retried);
            return $retried;
        };
        $retried = $tracer === null
            ? $retry()
            : $tracer->traceAttempt(
                'timeout_wrapper',
                'timeout_wrapper_rejected',
                $retry
            );
        $result = array_merge($result, $retried);
    }
}
