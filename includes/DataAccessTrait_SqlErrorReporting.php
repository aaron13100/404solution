<?php
/**
 * SQL error reporting helpers for DataAccess.
 *
 * Extracted from DataAccess.php to keep the main class under the file-size
 * limit. All methods are called via $this-> from DataAccess (trait context)
 * and rely on sibling traits: ErrorClassificationTrait for the isXxxError()
 * predicates, plus the host class's $this->logger and extractSqlFilename().
 *
 * @since 4.1.8
 */

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_SqlErrorReportingTrait {

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
    private function logObservedSqlError(string $query, array $result, array $options, bool $producesRows): void {
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

        $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
        $elapsed = isset($result['elapsed_time']) && is_numeric($result['elapsed_time'])
            ? round((float)$result['elapsed_time'], 4) : 0;
        $message = 'SQL query error observed: ' . $lastError
            . ', SQL: ' . $sqlInfo
            . ', source: ' . $this->extractSqlFilename($query)
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

        $this->logger->errorMessage($message);
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
    private function isInfrastructureSqlError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        return $this->isDiskFullError($errorText)
            || $this->isReadOnlyError($errorText)
            || $this->isQuotaLimitError($errorText)
            || $this->isInvalidDataError($errorText)
            || $this->isCollationError($errorText)
            || $this->isMissingPluginTableError($errorText)
            || $this->isIncorrectKeyFileError($errorText)
            || $this->isCrashedTableError($errorText)
            || $this->isDeadlockOrLockTimeoutError($errorText)
            || $this->isGaleraConflictError($errorText)
            || $this->isTransientConnectionError($errorText)
            || $this->isQueryTimeoutError($errorText)
            || $this->isAccessDeniedError($errorText);
    }

    /**
     * @param string $query
     * @param Throwable $e
     * @param array<string, mixed> $options
     * @param bool $producesRows
     * @return void
     */
    private function logSqlThrowable(string $query, Throwable $e, array $options, bool $producesRows): void {
        $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
        $logErrors = !array_key_exists('log_errors', $options) || (bool)$options['log_errors'];
        $message = 'SQL query threw exception: ' . $e->getMessage()
            . ', SQL: ' . $sqlInfo
            . ', source: ' . $this->extractSqlFilename($query)
            . ', route: ' . ($producesRows ? 'get_results' : 'query')
            . ', log_errors_option: ' . ($logErrors ? 'true' : 'false');

        $exception = $e instanceof Exception ? $e : new Exception($e->getMessage(), (int)$e->getCode(), $e);
        $this->logger->errorMessage($message, $exception);
    }
}
