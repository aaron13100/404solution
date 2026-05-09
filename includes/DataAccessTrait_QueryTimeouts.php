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
trait ABJ_404_Solution_DataAccess_QueryTimeoutsTrait {

    /**
     * @param string $query
     * @return bool
     */
    private function queryStartsWithSelect(string $query): bool {
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
    private function queryProducesResultRows(string $query): bool {
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
    private function applyQueryTimeout(string $query, int $timeoutSeconds): string {
        // Skip if a timeout hint is already present (prevents double-wrapping).
        if (preg_match('/MAX_EXECUTION_TIME|max_statement_time/i', $query)) {
            return $query;
        }

        if ($this->queryStartsWithSelect($query)) {
            return $this->applySelectTimeout($query, $timeoutSeconds);
        }
        if (preg_match('/SELECT\s/i', $query)) {
            // INSERT...SELECT, CREATE TABLE...SELECT, etc.
            return $this->applyNonLeadingSelectTimeout($query, $timeoutSeconds);
        }
        // Plain INSERT, UPDATE, DELETE, DDL: only MariaDB has a timeout mechanism.
        return $this->applyStatementTimeout($query, $timeoutSeconds);
    }

    /**
     * Detect the DB engine. Returns true for MariaDB, false for MySQL/unknown.
     * @return bool
     */
    private function isMariaDB(): bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return false;
        }
        try {
            if (isset($wpdb->dbh) && function_exists('mysqli_get_server_info') && $wpdb->dbh instanceof \mysqli) {
                $dbVersion = mysqli_get_server_info($wpdb->dbh);
            } else {
                /** @var wpdb $wpdb */
                $dbVersion = $wpdb->db_version() ?? '';
            }
        } catch (\Throwable $e) {
            // Mockery mocks, plain stdClass, or other test doubles may not
            // have db_version(). Default to MySQL (not MariaDB).
            $dbVersion = '';
        }
        return stripos($dbVersion, 'mariadb') !== false;
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
    private function applySelectTimeout(string $query, int $timeoutSeconds): string {
        if ($this->isMariaDB() && !ABJ_404_Solution_DataAccess::isSetStatementWrapperUnsupported()) {
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
    private function applyNonLeadingSelectTimeout(string $query, int $timeoutSeconds): string {
        if ($this->isMariaDB() && !ABJ_404_Solution_DataAccess::isSetStatementWrapperUnsupported()) {
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
    private function applyStatementTimeout(string $query, int $timeoutSeconds): string {
        if ($this->isMariaDB() && !ABJ_404_Solution_DataAccess::isSetStatementWrapperUnsupported()) {
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
     * @deprecated Use the 'timeout' option on queryAndGetResults() instead.
     *             Kept for backward compatibility with any external callers.
     *
     * @param string $insertSelectQuery The INSERT INTO ... SELECT ... query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout applied
     */
    function applyTimeoutToInsertSelect(string $insertSelectQuery, int $timeoutSeconds): string {
        return $this->applyNonLeadingSelectTimeout($insertSelectQuery, $timeoutSeconds);
    }

    /**
     * True when $query begins with the timeout wrapper this trait emits:
     * `SET STATEMENT max_statement_time=N FOR ...`. Used by the wrapper
     * fallback path to confirm the failed query was wrapped before stripping.
     *
     * @param string $query
     * @return bool
     */
    private function queryHasSetStatementWrapper(string $query): bool {
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
    private function stripSetStatementWrapper(string $query): string {
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
     * or syntax not understood). Caches the result in
     * ABJ_404_Solution_DataAccess::$setStatementWrapperUnsupported so every
     * subsequent timeout-wrapped query in this request skips the wrapper too.
     *
     * Result harvest mirrors the other recovery paths
     * (recoverFromCollationMismatchAndRetry, attemptMissingTableRepairAndRetry):
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
     * @return void
     */
    private function retryWithoutSetStatementWrapper(
        string &$query,
        array &$result,
        string $resultType
    ): void {
        if (!$this->queryHasSetStatementWrapper($query)) {
            // Defensive: nothing to strip. Caller misclassified the error.
            return;
        }
        $unwrapped = $this->stripSetStatementWrapper($query);
        // Cache the negative result for the rest of the request so we don't
        // wrap-then-fail on every subsequent query. Reset between requests.
        ABJ_404_Solution_DataAccess::setSetStatementWrapperUnsupported(true);
        $this->logger->infoMessage(
            'SET STATEMENT timeout wrapper rejected by server; '
            . 'retrying query without wrapper and caching unsupported flag '
            . 'for the rest of this request.'
        );

        global $wpdb;
        /** @var wpdb $wpdb */
        $wpdb->flush();
        // Mutate $query so downstream retry paths execute the unwrapped form.
        $query = $unwrapped;
        // Re-route classification past any leading comments and the (now-absent)
        // wrapper. Using queryProducesResultRows on the unwrapped query keeps
        // the routing correct for INSERT/UPDATE/DELETE/DDL.
        // SET STATEMENT wrapper-rejection recovery is a DAO-internal primitive
        // (parallel to recoverFromCollationMismatchAndRetry). It must call
        // $wpdb directly: re-routing through queryAndGetResults() would
        // re-enter the same SET STATEMENT detection path, deepening the call
        // stack on every retry. Per-bypass approval markers are inline below.
        $unwrappedProducesRows = $this->queryProducesResultRows($unwrapped);
        if ($unwrappedProducesRows) {
            // DAO-bypass-approved: SET STATEMENT wrapper-rejection retry primitive.
            $result['rows'] = $wpdb->get_results($unwrapped, $resultType);
        } else {
            // DAO-bypass-approved: SET STATEMENT wrapper-rejection retry primitive.
            $wpdb->query($unwrapped);
            $result['rows'] = array();
        }
        $this->harvestWpdbResult($result);
    }
}
