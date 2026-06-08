<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The centralized query pipeline every DAO uses: the error-handling
 * queryAndGetResults wrapper, the scalar-int helper, table-name placeholder
 * replacement, and the transaction harness with deadlock retry.
 */
interface ABJ_404_Solution_DatabaseQueryInterface {

    /**
     * Execute a SQL query with full error handling, retry, and recovery.
     *
     * @param string $query SQL query (may contain {wp_*} table placeholders).
     * @param array<string, mixed> $options {
     *     @type bool   $log_errors    Log errors (default true).
     *     @type bool   $log_too_slow  Log slow queries (default true).
     *     @type array  $ignore_errors Error substrings to suppress.
     *     @type array  $query_params  Parameters for wpdb::prepare().
     *     @type bool   $skip_repair   Skip missing-table auto-repair.
     *     @type string $result_type   ARRAY_A or OBJECT.
     *     @type int    $timeout       Query timeout in seconds (0 = default 60s).
     * }
     * @return array<string, mixed> {
     *     @type array       $rows         Result rows (empty array on non-SELECT).
     *     @type string      $last_error   MySQL error string ('' on success).
     *     @type array       $last_result  wpdb last_result.
     *     @type int         $rows_affected Rows affected.
     *     @type int         $insert_id    Last INSERT ID.
     *     @type float       $elapsed_time Seconds elapsed.
     *     @type bool        $timed_out    True when query timed out.
     * }
     */
    public function queryAndGetResults($query, $options = array()): array;

    /**
     * Execute a SELECT query that returns a single scalar value as int.
     *
     * @param string $query
     * @param array<string, mixed> $options
     * @return int
     */
    public function queryScalarInt($query, $options = array()): int;

    /**
     * Replace {wp_*} table-name placeholders with actual prefixed names.
     *
     * @param string $query
     * @return string
     */
    public function doTableNameReplacements($query): string;

    /**
     * Execute an array of SQL statements as a single transaction with deadlock retry.
     *
     * @param array<int, string> $statementArray
     * @return void
     * @throws \Exception on non-retryable failure.
     */
    public function executeAsTransaction(array $statementArray): void;
}
