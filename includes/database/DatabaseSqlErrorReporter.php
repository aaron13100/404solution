<?php
/**
 * SQL error reporting helpers for DataAccess.
 *
 * Extracted from DataAccess.php to keep the main class under the file-size
 * limit. All methods are called via $this-> from DataAccess (trait context)
 * and rely on sibling traits: ErrorClassificationTrait for the isXxxError()
 * predicates, plus the host class's $this->logger and query diagnostics.
 *
 * @since 4.1.8
 */

if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_DatabaseSqlErrorReporter {

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
     * Log the first observed database error for every query, before retry and
     * recovery paths can mutate or clear wpdb::last_error.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @param bool $producesRows
     * @return void
     */
    public function logObservedSqlError(string $query, array $result, array $options, bool $producesRows): void {
        $lastError = isset($result['last_error']) && is_string($result['last_error'])
            ? trim($result['last_error']) : '';
        if ($lastError === '') {
            return;
        }

        // Honor an explicit log_errors=false: the caller has accepted that
        // this query may fail and does not want the failure routed through
        // Logging::errorMessage() (which can email the developer and surface
        // admin notices). The error is still returned in $result['last_error']
        // so callers can react to it. May 2026: a regression in this function
        // was emailing 35 of 38 4.1.15 sites about benign SHOW CREATE TABLE
        // probes of the transient view_build table. log_errors=false must
        // mean "do not log".
        $logErrors = !array_key_exists('log_errors', $options) || (bool)$options['log_errors'];
        if (!$logErrors) {
            return;
        }

        // Honor ignore_errors: callers pass substring patterns for errors
        // that are expected and benign for that query (e.g. RENAME TABLE
        // with ignore_errors=["already exists"] in renameAbj404TablesToLowerCase
        // when the lowercase target name pre-exists). Without this check,
        // the observation logger fires ERROR before the downstream
        // ignore_errors branch can suppress, which emails the developer.
        // May 2026: 16+ sites in the email-flood cohort hit this path.
        $ignoreErrorStrings = isset($options['ignore_errors']) && is_array($options['ignore_errors'])
            ? $options['ignore_errors'] : array();
        foreach ($ignoreErrorStrings as $needle) {
            if (is_string($needle) && $needle !== '' && strpos($lastError, $needle) !== false) {
                return;
            }
        }

        $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->core->queryDiagnostics()->extractSqlFilename($query);
        $elapsed = isset($result['elapsed_time']) && is_numeric($result['elapsed_time'])
            ? round((float)$result['elapsed_time'], 4) : 0;
        $message = 'SQL query error observed: ' . $lastError
            . ', SQL: ' . $sqlInfo
            . ', source: ' . $this->core->queryDiagnostics()->extractSqlFilename($query)
            . ', route: ' . ($producesRows ? 'get_results' : 'query')
            . ', log_errors_option: ' . ($logErrors ? 'true' : 'false') /** @phpstan-ignore ternary.alwaysTrue */
            . ', execution_time: ' . $elapsed;

        // Infrastructure errors (collation mismatch, disk full, read-only,
        // host quota, deadlock, transient connection drop, etc.) are server
        // and host issues, not plugin bugs. Log as WARN so they appear in
        // the debug log without triggering Logging::errorMessage() email
        // reports. The downstream code at queryAndGetResults() lines 1067+
        // already classifies these for its own reporting branch; doing the
        // same classification here keeps the two layers consistent.
        // May 2026: 4 of 38 4.1.13 sites in the email-flood cohort were
        // "Illegal mix of collations" reports that should have been WARN.
        if ($this->isInfrastructureSqlError($lastError)) {
            $this->logger->warn($message);
            return;
        }

        // The statement asked for a schema change that was already made. That
        // is the state the caller wanted, so the plugin can still do its job --
        // the project's own test for warning versus error -- and the developer
        // has nothing to act on. Recorded, not reported. August 2026: report
        // 270 was two concurrent upgrade requests racing to add the same index,
        // and this line is where four of its five ERROR entries came from.
        if ($this->isRedundantSchemaChangeError($lastError)) {
            $this->logger->warn($message);
            return;
        }

        $this->logger->errorMessage($message);
    }

    /**
     * Pure classifier: true if the SQL error string says the schema already
     * reflects the change the statement asked for. Mirrors
     * {@see isInfrastructureSqlError()} in shape so the observed-error layer
     * and the final-error layer classify from one definition rather than two
     * copies of a string list.
     *
     * @param string $errorText
     * @return bool
     */
    public function isRedundantSchemaChangeError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        return $this->core->errorClassifier()->isRedundantSchemaChangeError($errorText);
    }

    /**
     * Pure classifier: true if the SQL error string matches a known
     * infrastructure / host / hosting-environment failure pattern. These
     * are conditions the plugin can detect and degrade past, not plugin
     * bugs. Centralizing the union here keeps logObservedSqlError() and
     * the downstream reporting branch in queryAndGetResults() consistent.
     *
     * @param string $errorText
     * @return bool
     */
    public function isInfrastructureSqlError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        return $this->core->errorClassifier()->isInfrastructureSqlError($errorText);
    }

    /**
     * @param string $query
     * @param Throwable $e
     * @param array<string, mixed> $options
     * @param bool $producesRows
     * @return void
     */
    public function logSqlThrowable(string $query, Throwable $e, array $options, bool $producesRows): void {
        $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->core->queryDiagnostics()->extractSqlFilename($query);
        $logErrors = !array_key_exists('log_errors', $options) || (bool)$options['log_errors'];
        $message = 'SQL query threw exception: ' . $e->getMessage()
            . ', SQL: ' . $sqlInfo
            . ', source: ' . $this->core->queryDiagnostics()->extractSqlFilename($query)
            . ', route: ' . ($producesRows ? 'get_results' : 'query')
            . ', log_errors_option: ' . ($logErrors ? 'true' : 'false');

        $exception = $e instanceof Exception ? $e : new Exception($e->getMessage(), (int)$e->getCode(), $e);
        $this->logger->errorMessage($message, $exception);
    }

    /**
     * Handle final SQL reporting and stale-notice cleanup after recovery.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @param array<int|string, string> $ignoreErrorStrings
     * @param ABJ_404_Solution_Timer $timer
     * @param ABJ_404_Solution_DatabaseQueryRecoveryTracer|null $tracer
     * @return void
     */
    public function handleFinalSqlErrorReporting(
        string $query,
        array &$result,
        array $options,
        array $ignoreErrorStrings,
        ABJ_404_Solution_Timer $timer,
        ?ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer = null
    ): void {
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';

        if ($options['log_errors'] && $lastError !== '') {
            $this->runRepairHooksForFinalError(
                $query,
                $result,
                $lastError,
                $tracer
            );
            $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
                ? (string)$result['last_error'] : '';
            if ($lastError === '') {
                return;
            }

            if (!$this->shouldReportFinalError($lastError, $ignoreErrorStrings)) {
                return;
            }

            if ($this->isInfrastructureSqlError($lastError)) {
                $this->logger->warn("Server-side DB issue (handled): " . $lastError);
                return;
            }

            // Same reasoning as the observed-error layer above: a schema change
            // that was already applied reached its goal, so it is recorded
            // rather than reported. Both layers fire on one failed statement,
            // so demoting only one of them would still email the developer.
            if ($this->isRedundantSchemaChangeError($lastError)) {
                $this->logger->warn("Schema change was already applied (handled): " . $lastError);
                return;
            }

            $this->logDetailedFinalSqlError($query, $lastError, $timer);
            return;
        }

        if ($options['log_too_slow'] && $timer->getElapsedTime() > 5) {
            $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->core->queryDiagnostics()->extractSqlFilename($query);
            $this->logger->debugMessage("Slow query (" . round($timer->getElapsedTime(), 2) . " seconds): " .
                    $sqlInfo);
        }

        if ($lastError === '') {
            $this->clearRecoveredServerSideNoticeIfNeeded();
        }
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param string $lastError
     * @param ABJ_404_Solution_DatabaseQueryRecoveryTracer|null $tracer
     * @return void
     */
    private function runRepairHooksForFinalError(
        string $query,
        array &$result,
        string $lastError,
        ?ABJ_404_Solution_DatabaseQueryRecoveryTracer $tracer = null
    ): void {
        if (strpos($lastError, " is marked as crashed ") !== false) {
            $repair = function () use ($lastError): void {
                $this->core->tableRepairer()->repairTable($lastError);
            };
            if ($tracer === null) {
                $repair();
            } else {
                $tracer->traceBranch('corrupted_table', $repair);
            }
        }
        if (strpos($lastError, "ALTER TABLE causes auto_increment resequencing") !== false &&
                strpos($lastError, "resulting in duplicate entry") !== false) {
            $repair = function () use ($lastError, $query): void {
                $this->core->tableRepairer()->repairDuplicateIDs($lastError, $query);
            };
            if ($tracer === null) {
                $repair();
            } else {
                $tracer->traceBranch('duplicate_id', $repair);
            }
        }
        if ($this->core->errorClassifier()->isIncorrectKeyFileError($lastError)) {
            $repair = function () use ($query, &$result, $tracer): void {
                $this->core->tableRepairer()->repairCorruptedTableAndRetry(
                    $query,
                    $result,
                    $tracer
                );
            };
            if ($tracer === null) {
                $repair();
            } else {
                $tracer->traceBranch('corrupted_table', $repair);
            }
        }
    }

    /**
     * @param string $lastError
     * @param array<int|string, string> $ignoreErrorStrings
     * @return bool
     */
    private function shouldReportFinalError(string $lastError, array $ignoreErrorStrings): bool {
        foreach ($ignoreErrorStrings as $ignoreThis) {
            if (is_string($ignoreThis) && strpos($lastError, $ignoreThis) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $query
     * @param string $lastError
     * @param ABJ_404_Solution_Timer $timer
     * @return void
     */
    private function logDetailedFinalSqlError(string $query, string $lastError, ABJ_404_Solution_Timer $timer): void {
        global $wpdb;

        $strippedQuery = 'n/a';
        if ($this->core->errorClassifier()->isInvalidDataError($lastError)) {
            $strippedResult = $this->core->tableRepairer()->get_stripped_query_result($query);
            $strippedQuery = is_string($strippedResult) ? $strippedResult : 'n/a';
        }

        $extraDataQuery = "select @@max_join_size as max_join_size, " .
            "@@sql_big_selects as sql_big_selects, " .
            "@@character_set_database as character_set_database";
        // DAO-bypass-approved: SQL-error diagnostics must read server variables without recursive DAO error handling.
        $someMySQLVariables = $wpdb->get_results($extraDataQuery, ARRAY_A);
        $variables = print_r($someMySQLVariables, true);

        $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->core->queryDiagnostics()->extractSqlFilename($query);
        $dbVer = $wpdb->db_version();
        $this->logger->errorMessage("Ugh. SQL query error: " . $lastError .
                ", SQL: " . $sqlInfo .
                ", Execution time: " . round($timer->getElapsedTime(), 2) .
                ", DB ver: " . (is_string($dbVer) ? $dbVer : 'unknown') .
                ", Variables: " . $variables .
                ", stripped_query: " . $strippedQuery);
    }

    /** @return void */
    private function clearRecoveredServerSideNoticeIfNeeded(): void {
        if (!$this->core->noticeState()->isServerSideIssueNoted() && !$this->core->noticeState()->isServerSideIssueChecked()) {
            $this->core->noticeState()->markServerSideIssueChecked();
            $existing = $this->core->noticeState()->getRuntimeFlag('abj404_plugin_db_notice');
            $excludedTypes = array('stale_permalink_cache', 'missing_table');
            if (is_array($existing) && !empty($existing['type'])
                && !in_array($existing['type'], $excludedTypes, true)) {
                $this->core->noticeState()->markServerSideIssueNoted();
            }
        }
        if ($this->core->noticeState()->isServerSideIssueNoted() && !$this->core->noticeState()->isWriteBlockActive() && !$this->core->errorClassifier()->isQuotaCooldownActive()) {
            $this->core->noticeState()->clearServerSideDbNotice();
        }
    }
}
