<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public contract for the shared database infrastructure layer.
 *
 * Every DAO module (RedirectsRepository, LogsRepository, etc.) receives a
 * DatabaseCore instance through this interface. It encapsulates:
 *   - The centralized error-handling query pipeline (queryAndGetResults)
 *   - Table-name resolution and DDL introspection
 *   - Query timeouts (engine-aware: MariaDB SET STATEMENT, MySQL hints)
 *   - Error classification and infrastructure-error recovery
 *   - Connection management and reconnection
 *   - Runtime flags, admin notices, and write-block detection
 */
interface ABJ_404_Solution_DatabaseCoreInterface {

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
     * Get the normalized (lowercase) WordPress table prefix.
     *
     * @return string
     */
    public function getLowercasePrefix(): string;

    /**
     * Build a fully-qualified plugin table name.
     *
     * @param string $tableSuffix e.g. 'abj404_redirects'
     * @return string
     */
    public function getPrefixedTableName($tableSuffix): string;

    /**
     * Get the CREATE TABLE DDL for an existing table.
     *
     * @param string $tableName
     * @return string
     */
    public function getCreateTableDDL($tableName): string;

    /**
     * Get the table-level default collation for an existing table.
     *
     * @param string $tableName
     * @return string
     */
    public function getTableCollationString(string $tableName): string;

    /**
     * Get the column-level collation for an existing character column.
     *
     * @param string $tableName
     * @param string $columnName
     * @return string
     */
    public function getColumnCollationString(string $tableName, string $columnName): string;

    /**
     * Check whether a database table exists.
     *
     * @param string $tableName
     * @return bool
     */
    public function tableExists($tableName): bool;

    /**
     * Get column names from an actual database table.
     *
     * @param string $tableName
     * @return array<int, string>
     */
    public function getTableColumnNames(string $tableName): array;

    /**
     * Build a SQL-safe list from recognized_post_types option.
     *
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildPostTypeSqlList(array $options): string;

    /**
     * Build a SQL-safe list from recognized_categories option.
     *
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildCategorySqlList(array $options): string;

    /**
     * Set SQL session variables to allow large queries.
     *
     * @return void
     */
    public function setSqlBigSelects(): void;

    /**
     * Classify an error as infrastructure (server-side) and handle it.
     *
     * @param string $errorText
     * @return bool True if handled as infrastructure error.
     */
    public function classifyAndHandleInfrastructureError(string $errorText): bool;

    /**
     * Classify a staged-build failure for the stage runner.
     *
     * @param int $stageNumber
     * @param string $errorText
     * @return string 'resumable', 'skip', 'halt', or 'rethrow'.
     */
    public function classifyStageFailure(int $stageNumber, string $errorText): string;

    /**
     * @param string $errorText
     * @return bool
     */
    public function isOutOfMemoryError(string $errorText): bool;

    /**
     * Inject the clock instance for testability.
     *
     * @param ABJ_404_Solution_Clock $clock
     * @return void
     */
    public function setClock(ABJ_404_Solution_Clock $clock): void;

    /**
     * Set/get runtime flags (transients with option fallback).
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttlSeconds
     * @return void
     */
    public function setRuntimeFlag(string $key, $value, int $ttlSeconds): void;

    /**
     * @param string $key
     * @return mixed
     */
    public function getRuntimeFlag(string $key);

    /**
     * Surface a plugin-specific admin notice about a DB issue.
     *
     * @param string $type
     * @param string $message
     * @param string $errorString
     * @return void
     */
    public function setPluginDbNotice(string $type, string $message, string $errorString = ''): void;

    /**
     * Clear the plugin DB notice only when its current type matches.
     *
     * @param string $type
     * @return void
     */
    public function clearPluginDbNoticeIfType(string $type): void;

    /**
     * @return bool True when a write-block cooldown is active (disk full or read-only).
     */
    public function isWriteBlockActive(): bool;

    /**
     * @return bool True when non-essential DB writes should be skipped.
     */
    public function shouldSkipNonEssentialDbWrites(): bool;

    /**
     * Attempt REPAIR TABLE for crashed or corrupted-key-file tables.
     *
     * @param string $errorMessage The MySQL error string.
     * @return void
     */
    public function repairTable(string $errorMessage): void;

    /**
     * Attempt to fix duplicate auto_increment IDs caused by ALTER TABLE resequencing.
     *
     * @param string $errorMessage The MySQL error string.
     * @param string $sqlThatWasRun The SQL that triggered the error.
     * @return void
     */
    public function repairDuplicateIDs(string $errorMessage, string $sqlThatWasRun): void;

    /**
     * Auto-recover from collation mismatches by running correctCollations()
     * then retrying the original query.
     *
     * @param string $query The SQL query to retry.
     * @param array<string, mixed> $result Passed by reference; updated on successful retry.
     * @param bool $producesRows Whether the query returns result rows.
     * @param string $resultType wpdb output type (ARRAY_A or OBJECT).
     * @return void
     */
    public function recoverFromCollationMismatchAndRetry(
        string $query, array &$result, bool $producesRows, string $resultType
    ): void;

    /**
     * Execute an array of SQL statements as a single transaction with deadlock retry.
     *
     * @param array<int, string> $statementArray
     * @return void
     * @throws \Exception on non-retryable failure.
     */
    public function executeAsTransaction(array $statementArray): void;
}
