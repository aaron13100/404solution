<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Self-healing table repair + invalid-data retry for plugin SQL queries.
 *
 * Extracted from DatabaseCore as part of the (4/6) DatabaseCore decomposition.
 * Owns two cohesive responsibilities that are both error-driven, single-retry,
 * recursion-guarded recovery paths for SQL errors observed by queryAndGetResults:
 *
 *   1. REPAIR TABLE for "marked as crashed" and "Incorrect key file" errors.
 *      Parses the affected table name out of the wpdb error message, validates
 *      it as a plugin table (abj404 prefix only), runs REPAIR TABLE, and (for
 *      the "Incorrect key file" path) flushes and retries the original query
 *      once. If the table name cannot be sanitized, surfaces a deduplicated
 *      admin notice instead.
 *   2. Invalid-data retry. When wpdb reports an invalid-data error, asks
 *      WPDBExtension to strip the invalid bytes from the query, then flushes
 *      and retries the stripped query once. Recursion guard prevents infinite
 *      loops if the stripped query also fails.
 *   3. Duplicate-id repair for the ALTER TABLE auto_increment resequencing
 *      failure mode. Parses the duplicate id out of the error and the target
 *      table out of the SQL, validates both, and deletes the conflicting row.
 *
 * This class holds no DatabaseCore back-reference. It receives:
 *   - a query-runner callable bound over DatabaseCore::queryAndGetResults
 *     (signature: function(string, array<string,mixed>): array<string,mixed>);
 *   - a result-harvester callable bound over DatabaseWpdbResultHarvester
 *     (signature: function(array<string,mixed>): void, by-reference);
 *   - a result-type getter bound over DatabaseCore::getCurrentResultType
 *     (signature: function(): string);
 *   - a notice setter bound over DatabaseNoticeStateHolder::setPluginDbNotice
 *     (signature: function(string, string, string, string): void);
 *   - a connection-reset callable bound over
 *     DatabaseConnectionManager::resetForRetry (signature: function(string): bool);
 *   - ABJ_404_Solution_Functions for regex/string helpers and the plugin logger.
 *
 * The recursion guards are static properties on this class (they must survive
 * across helper invocations within a single request) and are functionally
 * identical to the former DatabaseCore::$tableRepairInProgress and
 * DatabaseCore::$invalidDataRetryInProgress.
 */
class ABJ_404_Solution_DatabaseTableRepairer {

    /** @var bool Prevent recursive auto-repair attempts on SQL errors. */
    private static $tableRepairInProgress = false;

    /** @var bool Prevent recursive invalid-data retry attempts. */
    private static $invalidDataRetryInProgress = false;

    /** @var callable(string, array<string,mixed>): array<string,mixed> */
    private $queryRunner;

    /** @var callable(array<string,mixed>): void */
    private $resultHarvester;

    /** @var callable(): string */
    private $resultTypeGetter;

    /** @var callable(string, string, string, string): void */
    private $noticeSetter;

    /** @var callable(string): bool */
    private $connectionResetter;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param callable(string, array<string,mixed>): array<string,mixed> $queryRunner
     *   Runs a SQL query through the centralized error-handling pipeline and
     *   returns its result array. Bound by DatabaseCore over queryAndGetResults().
     * @param callable(array<string,mixed>): void $resultHarvester
     *   Copies wpdb->last_error / rows_affected / insert_id into the result array,
     *   by reference. Bound by DatabaseCore over DatabaseWpdbResultHarvester.
     * @param callable(): string $resultTypeGetter
     *   Returns the current wpdb result type (ARRAY_A or OBJECT) for retries.
     * @param callable(string, string, string, string): void $noticeSetter
     *   Persists a plugin-db admin notice (type, message, guidance, errorString).
     * @param callable(string): bool $connectionResetter
     *   Resets the active wpdb connection before a recovery retry.
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        callable $queryRunner,
        callable $resultHarvester,
        callable $resultTypeGetter,
        callable $noticeSetter,
        callable $connectionResetter,
        $functions,
        $logger
    ) {
        $this->queryRunner = $queryRunner;
        $this->resultHarvester = $resultHarvester;
        $this->resultTypeGetter = $resultTypeGetter;
        $this->noticeSetter = $noticeSetter;
        $this->connectionResetter = $connectionResetter;
        $this->f = $functions;
        $this->logger = $logger;
    }

    /**
     * Reset the static recursion guards. Intended for test setUp/tearDown only.
     *
     * @return void
     */
    public static function resetRecursionGuardsForTests(): void {
        self::$tableRepairInProgress = false;
        self::$invalidDataRetryInProgress = false;
    }

    /** @return bool */
    public function isTableRepairInProgress(): bool {
        return self::$tableRepairInProgress;
    }

    /** @param bool $value @return void */
    public function setTableRepairInProgress(bool $value): void {
        self::$tableRepairInProgress = $value;
    }

    /**
     * Validate and sanitize a table name extracted from error messages or SQL.
     *
     * Rejects anything that isn't [A-Za-z0-9_]+ and anything that doesn't
     * contain the plugin's "abj404" prefix substring. Logs (warn) on reject so
     * the rejection is visible at audit time without surfacing to the admin.
     *
     * @param string $name Raw table name (may include backticks).
     * @return string|null Sanitized name, or null if invalid.
     */
    public function sanitizeTableName(string $name): ?string {
        $name = trim($name, '`');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            $this->logger->warn("sanitizeTableName: rejected invalid table name: " . substr($name, 0, 100));
            return null;
        }
        if (strpos($name, 'abj404') === false) {
            $this->logger->warn("sanitizeTableName: rejected non-plugin table name: " . $name);
            return null;
        }
        return $name;
    }

    /**
     * Run REPAIR TABLE for a table named in a wpdb error string.
     *
     * Recognizes both the "is marked as crashed" message and the "Incorrect
     * key file for table" message. If the table name cannot be sanitized
     * (typically because it is a temporary table outside our prefix, e.g.
     * "#sql_xxx_0.MYI" on a corrupted-disk host), falls back to a 24-hour
     * deduplicated admin notice telling the site owner to contact their host.
     *
     * @param string $errorMessage The wpdb->last_error string.
     * @return void
     */
    public function repairTable(string $errorMessage): void {
        $re1 = "Table '(.*\/)?(.+)' is marked as crashed and ";
        $re2 = "Incorrect key file for table '(?:.*\/)?([^'.]+?)(?:\\.MYI)?'";

        $matches = array();
        $this->f->regexMatch($re1, $errorMessage, $matches);

        if (empty($matches) || count($matches) <= 2 || $this->f->strlen($matches[2]) === 0) {
            $this->f->regexMatch($re2, $errorMessage, $matches);
            if (!empty($matches) && isset($matches[1]) && $this->f->strlen($matches[1]) > 0) {
                $matches[2] = $matches[1];
            }
        }

        if (!empty($matches) && count($matches) > 2 && $this->f->strlen($matches[2]) > 0) {
            $rawTableName = $matches[2];
            $tableToRepair = $this->sanitizeTableName($rawTableName);
            if ($tableToRepair !== null) {
                $query = "REPAIR TABLE `{$tableToRepair}`";
                $result = ($this->queryRunner)($query, array('log_errors' => false));
                $this->logger->infoMessage("Attempted to repair table " . $tableToRepair . ". Result: " .
                        json_encode($result));
            } else {
                $this->logger->warn("The table " . $rawTableName . " needs to be " .
                    "repaired with something like: repair table " . $rawTableName);

                $cooldownKey = 'abj404_corrupted_temp_table_notice_until';
                $alreadyNotified = function_exists('get_transient') ? get_transient($cooldownKey) : false;
                if (!$alreadyNotified) {
                    ($this->noticeSetter)(
                        'corrupted_temp_table',
                        function_exists('__') ? __('A database temporary table is corrupted - this is usually caused by a full or failing disk. Please contact your host. (MySQL error 1034)', '404-solution') : 'A database temporary table is corrupted - this is usually caused by a full or failing disk. Please contact your host. (MySQL error 1034)',
                        function_exists('__') ? __('A temporary MySQL table was corrupted, usually caused by disk or hardware issues. The plugin cannot repair it. Please contact your hosting provider.', '404-solution') : 'A temporary MySQL table was corrupted, usually caused by disk or hardware issues. The plugin cannot repair it. Please contact your hosting provider.',
                        $errorMessage
                    );
                    if (function_exists('set_transient')) {
                        // @cache-write-audit: opt-out - admin-notice dedup cooldown
                        // (one notice per 24h per failure type), not a query result.
                        set_transient($cooldownKey, 1, 86400);
                    }
                }
            }
        }
    }

    /**
     * Resolve the ALTER TABLE auto_increment resequencing duplicate-id case.
     *
     * Parses the duplicate id from the error message and the table name from
     * the original ALTER TABLE SQL, validates both, and deletes the conflicting
     * row so the ALTER can be retried by the caller.
     *
     * @param string $errorMessage  The wpdb->last_error string.
     * @param string $sqlThatWasRun The ALTER TABLE statement wpdb just ran.
     * @return void
     */
    public function repairDuplicateIDs(string $errorMessage, string $sqlThatWasRun): void {
        $reForID = 'resulting in duplicate entry \'(.+)\' for key';
        $reForTableName = "ALTER TABLE (.+) ADD ";
        $matchesForID = null;
        $matchesForTableName = null;

        $this->f->regexMatch($reForID, $errorMessage, $matchesForID);
        $this->f->regexMatch($reForTableName, $sqlThatWasRun, $matchesForTableName);
        if (is_array($matchesForID) && isset($matchesForID[1]) && $this->f->strlen($matchesForID[1]) > 0 &&
                is_array($matchesForTableName) && isset($matchesForTableName[1]) && $this->f->strlen($matchesForTableName[1]) > 0) {

            $idWithDuplicate = $matchesForID[1];
            $tableName = $this->sanitizeTableName($matchesForTableName[1]);
            if ($tableName === null) {
                $this->logger->warn("repairDuplicateIDs: rejected invalid table name from SQL: " . substr($matchesForTableName[1], 0, 100));
                return;
            }

            if (!is_numeric($idWithDuplicate)) {
                $this->logger->errorMessage("Invalid ID extracted from error message: " . $idWithDuplicate);
                return;
            }

            if ($idWithDuplicate == 1) {
                $idWithDuplicate = 0;
            }

            $result = ($this->queryRunner)("DELETE FROM `{$tableName}` where id = %d",
                array('log_errors' => false, 'query_params' => array(absint($idWithDuplicate))));
            $this->logger->infoMessage("Attempted to fix a duplicate entry issue. Table: " .
                $tableName . ", Result: " . json_encode($result));
        }
    }

    /**
     * Attempt REPAIR TABLE after MySQL errno 1034 ("Incorrect key file"), then
     * retry the original query once.
     *
     * On retry success, $result is mutated to the retried result; on retry
     * failure, $result carries the retried last_error so the surrounding
     * pipeline can continue its error-handling.
     *
     * @param string $query
     * @param array<string, mixed> $result Passed by reference.
     * @param ABJ_404_Solution_DatabaseQueryRecoveryTracer|null $tracer
     * @return void
     */
    public function repairCorruptedTableAndRetry(
        string $query,
        array &$result,
        ?ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer = null
    ): void {
        $errorMessage = is_string($result['last_error']) ? $result['last_error'] : '';
        $this->repairTable($errorMessage);
        if (stripos($errorMessage, 'abj404') !== false) {
            global $wpdb;
            if ($tracer === null) {
                if (!(($this->connectionResetter)($errorMessage))) {
                    return;
                }
            } else {
                $reset = $tracer->traceOperation(
                    'corrupted_table',
                    'connection_retry_reset',
                    fn(): bool => ($this->connectionResetter)($errorMessage)
                );
                if (!$reset) {
                    return;
                }
            }
            $resultType = ($this->resultTypeGetter)();
            // DAO-bypass-approved: retry-after-repair is part of the DAO's
            // self-healing pipeline; calling queryAndGetResults() here would
            // re-enter the error-handler that just invoked us.
            $retry = function () use ($wpdb, $query, $resultType): array {
                $retried = array('rows' => $wpdb->get_results($query, $resultType));
                $retried['last_error'] = (string)($wpdb->last_error ?? '');
                $retried['last_result'] = $wpdb->last_result ?? array();
                $retried['rows_affected'] = $wpdb->rows_affected ?? 0;
                $retried['insert_id'] = $wpdb->insert_id ?? 0;
                return $retried;
            };
            $retried = $tracer === null
                ? $retry()
                : $tracer->traceAttempt(
                    'corrupted_table',
                    'corrupted_table',
                    $retry
                );
            $result = array_merge($result, $retried);
            if ($result['last_error'] === '') {
                $this->logger->infoMessage("Retry after 'Incorrect key file' repair succeeded for plugin table.");
            }
        }
    }

    /**
     * Attempt a single invalid-data retry by asking WPDBExtension to strip
     * invalid bytes from the query, then re-running the stripped query.
     *
     * Recursion-guarded: if the stripped query also produces an invalid-data
     * error, the second call short-circuits. The `abj404_invalid_data_retry_query`
     * filter lets site owners override the stripped query (e.g. to enforce a
     * stricter sanitization policy).
     *
     * @param string $query
     * @param array<string, mixed> $result Passed by reference.
     * @param ABJ_404_Solution_DatabaseQueryRecoveryTracer|null $tracer
     * @return void
     */
    public function attemptInvalidDataRetry(
        $query,
        &$result,
        ?ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer = null
    ) {
        if (self::$invalidDataRetryInProgress) {
            return;
        }
        self::$invalidDataRetryInProgress = true;
        try {
            $prepareRetry = function () use ($query) {
                $retryQuery = $this->get_stripped_query_result($query);
                return function_exists('apply_filters')
                    ? apply_filters('abj404_invalid_data_retry_query', $retryQuery, $query)
                    : $retryQuery;
            };
            $retryQuery = $tracer === null
                ? $prepareRetry()
                : $tracer->traceOperation(
                    'invalid_data',
                    'retry_prepare',
                    $prepareRetry
                );
            if (!is_string($retryQuery) || trim($retryQuery) === '' || $retryQuery === $query) {
                return;
            }
            global $wpdb;
            $retryError = isset($result['last_error']) && is_scalar($result['last_error'])
                ? (string)$result['last_error']
                : '';
            if ($tracer === null) {
                if (!(($this->connectionResetter)($retryError))) {
                    return;
                }
            } else {
                $reset = $tracer->traceOperation(
                    'invalid_data',
                    'connection_retry_reset',
                    fn(): bool => ($this->connectionResetter)($retryError)
                );
                if (!$reset) {
                    return;
                }
            }
            $resultType = ($this->resultTypeGetter)();
            // DAO-bypass-approved: retry-after-strip is part of the DAO's
            // self-healing pipeline; calling queryAndGetResults() here would
            // re-enter the error-handler that just invoked us.
            $retry = function () use ($wpdb, $retryQuery, $resultType): array {
                $retried = array('rows' => $wpdb->get_results($retryQuery, $resultType));
                ($this->resultHarvester)($retried);
                return $retried;
            };
            $retried = $tracer === null
                ? $retry()
                : $tracer->traceAttempt('invalid_data', 'invalid_data', $retry);
            $result = array_merge($result, $retried);
        } catch (Throwable $e) {
            $this->logger->warn("Invalid-data retry failed: " . $e->getMessage());
        } finally {
            self::$invalidDataRetryInProgress = false;
        }
    }

    /**
     * Ask WPDBExtension to strip invalid bytes from a query string so the
     * caller can re-issue a safe version.
     *
     * Returns null on any failure (missing extension file, wpdb method
     * unavailable, DB constants undefined, extension constructor throws).
     * Logs (warn) on exception so the caller does not need to.
     *
     * @param string $query
     * @return NULL|string|WP_Error
     */
    public function get_stripped_query_result($query) {
        try {
            if (!class_exists('wpdb')) {
                return null;
            }
            if (!method_exists('wpdb', 'strip_invalid_text_from_query')) {
                return null;
            }

            $filename = ABJ404_PATH . 'includes/php/wordpress/WPDBExtension.php';
            if (!file_exists($filename)) {
                return null;
            }
            require_once $filename;

            $my_custom_db = null;
            if (class_exists('ABJ_404_Solution_WPDBExtension_PHP7')) {
                $my_custom_db = new ABJ_404_Solution_WPDBExtension_PHP7(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            } else if (class_exists('ABJ_404_Solution_WPDBExtension_PHP5')) {
                $my_custom_db = new ABJ_404_Solution_WPDBExtension_PHP5(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            }
            if ($my_custom_db == null) {
                return null;
            }

            $result = $my_custom_db->public_strip_invalid_text_from_query($query);

            if (is_wp_error($result)) {
                return 'WP_Error: ' . $result->get_error_message();
            }

            return $result;

        } catch (Throwable $e) {
            $this->logger->warn(
                'get_stripped_query_result failed; returning null: ' . $e->getMessage()
            );
            return null;
        }
    }

}
