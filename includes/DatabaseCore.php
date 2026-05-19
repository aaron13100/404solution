<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseCoreInterface.php';
require_once __DIR__ . '/DatabaseRepairDelegate.php';
require_once __DIR__ . '/DataAccessTrait_Connection.php';
require_once __DIR__ . '/DataAccessTrait_QueryTimeouts.php';
require_once __DIR__ . '/DataAccessTrait_ErrorClassification.php';
require_once __DIR__ . '/DataAccessTrait_SqlErrorReporting.php';

/**
 * Shared database infrastructure: query execution, error recovery, timeouts,
 * connection management, table-name resolution, and error classification.
 *
 * Extracted from the DataAccess monolith (Phase 0 of the DataAccess refactor).
 * Every DAO module receives a DatabaseCore instance via constructor injection.
 */
class ABJ_404_Solution_DatabaseCore implements ABJ_404_Solution_DatabaseCoreInterface {

    use ABJ_404_Solution_DataAccess_ConnectionTrait;
    use ABJ_404_Solution_DataAccess_QueryTimeoutsTrait;
    use ABJ_404_Solution_DataAccess_ErrorClassificationTrait;
    use ABJ_404_Solution_DataAccess_SqlErrorReportingTrait;

    /** @var int Cooldown when DB query quota is exceeded. */
    const DB_QUOTA_COOLDOWN_SECONDS = 900;
    /** @var int Cooldown when DB is read-only or storage is full. */
    const DB_WRITE_BLOCK_COOLDOWN_SECONDS = 900;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Clock|null */
    private $clock = null;

    /** @var bool Prevent recursive auto-repair attempts on SQL errors. */
    private static $tableRepairInProgress = false;

    /** @var bool Prevent recursive invalid-data retry attempts. */
    private static $invalidDataRetryInProgress = false;

    /**
     * @var bool Per-request cache: this server rejected the
     *  `SET STATEMENT max_statement_time=N FOR ...` timeout wrapper, so
     *  applyQueryTimeout() must skip wrapping for the rest of the request.
     */
    private static $setStatementWrapperUnsupported = false;

    /** @var string Current wpdb result type for queryAndGetResults (ARRAY_A or OBJECT). */
    private $currentResultType = ARRAY_A;

    /** @var bool Whether a server-side DB issue was noted this request (for auto-clear). */
    private $serverSideIssueNoted = false;

    /** @var bool Whether we already checked for a stale notice transient this request. */
    private $serverSideIssueChecked = false;

    /**
     * @var ABJ_404_Solution_DatabaseRepairDelegate|null Temporary bridge to
     *  Maintenance-trait repair methods on DataAccess. Removed in Phase 5 when
     *  those methods move here.
     */
    private $repairDelegate = null;

    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct($functions = null, $logging = null) {
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    /**
     * @param ABJ_404_Solution_DatabaseRepairDelegate $delegate
     * @return void
     */
    public function setRepairDelegate(ABJ_404_Solution_DatabaseRepairDelegate $delegate): void {
        $this->repairDelegate = $delegate;
    }

    /** @param ABJ_404_Solution_Clock $clock @return void */
    public function setClock(ABJ_404_Solution_Clock $clock): void {
        $this->clock = $clock;
    }

    /** @return ABJ_404_Solution_Clock */
    public function clock(): ABJ_404_Solution_Clock {
        if ($this->clock !== null) { return $this->clock; }
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $resolved = ABJ_404_Solution_ServiceContainer::safeGet('clock');
            if ($resolved instanceof ABJ_404_Solution_Clock) {
                $this->clock = $resolved;
                return $this->clock;
            }
        }
        $this->clock = new ABJ_404_Solution_SystemClock();
        return $this->clock;
    }

    /**
     * Reset the per-request "SET STATEMENT wrapper unsupported" cache.
     *
     * @param bool $value
     * @return void
     */
    public static function setSetStatementWrapperUnsupported(bool $value): void {
        self::$setStatementWrapperUnsupported = $value;
    }

    /** @return bool */
    public static function isSetStatementWrapperUnsupported(): bool {
        return self::$setStatementWrapperUnsupported;
    }

    /**
     * Check if a database table exists.
     *
     * @param string $tableName Full table name to check (including prefix)
     * @return bool
     */
    public function tableExists($tableName): bool {
        global $wpdb;
        if (!isset($wpdb)) {
            return false;
        }
        $table = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tableName) . "'");
        return ($table == $tableName);
    }

    /**
     * Get the column names of an actual database table via SHOW COLUMNS.
     *
     * @param string $tableName Full table name (including prefix)
     * @return array<int, string>
     */
    public function getTableColumnNames(string $tableName): array {
        global $wpdb;
        if (!isset($wpdb)) { return []; }
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `" . esc_sql($tableName) . "`", ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) { return []; }
        $columns = [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) { $columns[] = $row['Field']; }
        }
        return $columns;
    }

    /**
     * @param string $query
     * @return string
     */
    public function doTableNameReplacements($query): string {
        global $wpdb;

        $replacements = array();
        $tables = (isset($wpdb->tables) && is_array($wpdb->tables)) ? $wpdb->tables : array();
        $prefix = isset($wpdb->prefix) ? $wpdb->prefix : 'wp_';
        foreach ($tables as $tableName) {
            $replacements['{wp_' . $tableName . '}'] = $prefix . $tableName;
        }
        $replacements['{wp_users}'] = isset($wpdb->users) ? $wpdb->users : ($prefix . 'users');
        $replacements['{wp_prefix}'] = $prefix;
        $replacements['{wp_prefix_lower}'] = $this->getLowercasePrefix();

        $wpdbCollate = 'utf8mb4_unicode_ci';
        if (isset($wpdb->collate) && !empty($wpdb->collate)) {
            $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $wpdb->collate);
            if ($sanitized !== '' && $sanitized !== null) {
                $wpdbCollate = $sanitized;
            }
        }
        $replacements['{wpdb_collate}'] = $wpdbCollate;

        $query = $this->f->str_replace(array_keys($replacements), array_values($replacements), $query);

        $fpreg = ABJ_404_Solution_FunctionsPreg::getInstance();
        $query = $fpreg->regexReplace('[{]wp_abj404_(.*?)[}]',
            $this->getLowercasePrefix() . "abj404_\\1", $query);

        return $query !== null ? $query : '';
    }

    /** @return string */
    public function getLowercasePrefix(): string {
        global $wpdb;
        return $this->f->strtolower($wpdb->prefix ?? 'wp_');
    }

    /**
     * @param string $tableSuffix
     * @return string
     */
    public function getPrefixedTableName($tableSuffix): string {
        return $this->getLowercasePrefix() . ltrim($tableSuffix, '_');
    }

    /**
     * @param string $tableName
     * @return string
     */
    public function getCreateTableDDL($tableName): string {
        $query = "show create table " . $tableName;
        $result = $this->queryAndGetResults($query, array('log_errors' => false, 'skip_repair' => true));
        $rows = $result['rows'];
        if (!is_array($rows) || empty($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return '';
        }
        $row1 = array_values($rows[0]);
        $existingTableSQL = $row1[1];
        return $existingTableSQL;
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildPostTypeSqlList(array $options): string {
        $rptVal = $options['recognized_post_types'] ?? '';
        $postTypes = $this->f->explodeNewline(is_string($rptVal) ? $rptVal : '');
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($this->f->strtolower($postType)) . "', ";
        }
        return rtrim($recognizedPostTypes, ", ");
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildCategorySqlList(array $options): string {
        $rcVal = $options['recognized_categories'] ?? '';
        $categories = $this->f->explodeNewline(is_string($rcVal) ? $rcVal : '');
        $recognizedCategories = '';
        foreach ($categories as $category) {
            $recognizedCategories .= "'" . trim($this->f->strtolower($category)) . "', ";
        }
        return rtrim($recognizedCategories, ", ");
    }

    /** @return void */
    public function setSqlBigSelects(): void {
        $ignoreErrorsOptions = array('log_errors' => false);
        $this->queryAndGetResults("set session max_join_size = 18446744073709551615",
            $ignoreErrorsOptions);
        $this->queryAndGetResults("set session sql_big_selects = 1", $ignoreErrorsOptions);
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

        $this->ensureConnection();

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

        $query = $this->doTableNameReplacements($query);

        if (!empty($queryParameters)) {
            /** @var literal-string $queryLiteral */
            $queryLiteral = $query;
            try {
                /** @var wpdb $wpdb */
                $preparedResult = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($queryLiteral), $queryParameters));
                $query = is_string($preparedResult) ? $preparedResult : $queryLiteral;
            } catch (Throwable $t) {
                $preparedFallback = $wpdb->prepare($queryLiteral, $queryParameters);
                $query = $preparedFallback !== null ? $preparedFallback : $queryLiteral;
            }
        }

        $timeoutRaw = isset($options['timeout']) && is_numeric($options['timeout']) ? (int)$options['timeout'] : 0;
        $timeoutSeconds = $timeoutRaw > 0 ? $timeoutRaw : 60;
        $query = $this->applyQueryTimeout($query, $timeoutSeconds);

        $this->applyDiagnosticLatencyIfConfigured();

        $timer = new ABJ_404_Solution_Timer();

        $suppressWpdbErrors = !$options['log_errors'] && method_exists($wpdb, 'suppress_errors');
        $previousSuppressState = false;
        if ($suppressWpdbErrors) {
            /** @var wpdb $wpdb */
            $previousSuppressState = $wpdb->suppress_errors(true);
        }

        $producesRows = $this->queryProducesResultRows($query);

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
            $this->logSqlThrowable($query, $e, $options, $producesRows);
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
        if ($lastErrorForObservedLog === '' || !$this->isTransientConnectionError($lastErrorForObservedLog)) {
            $this->logObservedSqlError($query, $result, $options, $producesRows);
        }

        if ($producesRows && !is_array($result['rows'])) {
            $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
            $this->logger->errorMessage("Query result is not an array. Query: " . $sqlInfo,
                        new Exception("Query result is not an array."));
        }

        $lastErrorForSetStatement = is_string($result['last_error'] ?? null) ? $result['last_error'] : '';
        if ($lastErrorForSetStatement !== ''
            && $this->classifySetStatementFailure($lastErrorForSetStatement)
            && $this->queryHasSetStatementWrapper($query)) {
            $this->retryWithoutSetStatementWrapper($query, $result, $resultType);
            $producesRows = $this->queryProducesResultRows($query);
        }

        if ($result['last_error'] !== '' && $this->isTransientConnectionError($result['last_error'])) {
            $this->ensureConnection();
            $wpdb->flush();
            if ($producesRows) {
                $result['rows'] = $wpdb->get_results($query, $resultType);
            } else {
                $wpdb->query($query);
                $result['rows'] = array();
            }
            $this->harvestWpdbResult($result);
        }

        if (!$options['skip_repair'] && $result['last_error'] !== '' && $this->isMissingPluginTableError($result['last_error'])) {
            $this->attemptMissingTableRepairAndRetry($query, $result);
        }

        if ($result['last_error'] !== '' && $this->isInvalidDataError($result['last_error'])) {
            $this->attemptInvalidDataRetry($query, $result);
        }

        if ($result['last_error'] !== '' && $this->isDeadlockOrLockTimeoutError($result['last_error'])) {
            /** @var wpdb $wpdb */
            usleep(50000);
            if ($producesRows) {
                $result['rows'] = $wpdb->get_results($query, $resultType);
            } else {
                $wpdb->query($query);
                $result['rows'] = array();
            }
            $this->harvestWpdbResult($result);
            if ($result['last_error'] !== '' && $this->isDeadlockOrLockTimeoutError($result['last_error'])) {
                // allow-em-dash: copied verbatim from existing user-facing localized string in DataAccess.php
                $this->setPluginDbNotice('lock_timeout', $this->localizeOrDefault('A database lock wait timeout occurred. If this persists, contact your host — another process may be holding a long-running lock.'), $result['last_error']);
            }
        }

        if ($result['last_error'] !== '' && $this->isCollationError($result['last_error'])) {
            if ($this->repairDelegate !== null) {
                $this->repairDelegate->recoverFromCollationMismatchAndRetry($query, $result, $producesRows, $resultType);
            }
        }

        if ($result['last_error'] !== '' && $this->isQueryTimeoutError($result['last_error'])) {
            $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
            $this->logger->warn(
                'Query timed out after ' . $timeoutSeconds . 's. ' .
                'Query: ' . substr(preg_replace('/\s+/', ' ', trim($sqlInfo)) ?? $sqlInfo, 0, 500)
            );
            $result['rows'] = array();
            $result['timed_out'] = true;
        }

        if ($result['last_error'] !== '') {
            $this->noteDatabaseIssueFromError($result['last_error']);
        }

        if ($suppressWpdbErrors) {
            /** @var wpdb $wpdb */
            $wpdb->suppress_errors($previousSuppressState);
        }

        if ($options['log_errors'] && $result['last_error'] != '') {
            if ($this->f->strpos($result['last_error'],
                    " is marked as crashed ") !== false) {
                if ($this->repairDelegate !== null) {
                    $this->repairDelegate->repairTable($result['last_error']);
                }
            }
            if ($this->f->strpos($result['last_error'],
                    "ALTER TABLE causes auto_increment resequencing") !== false &&
                    $this->f->strpos($result['last_error'], "resulting in duplicate entry") !== false) {
                if ($this->repairDelegate !== null) {
                    $this->repairDelegate->repairDuplicateIDs($result['last_error'], $query);
                }
            }
            if ($this->isIncorrectKeyFileError($result['last_error'])) {
                $this->repairCorruptedTableAndRetry($query, $result);
            }

            if ($result['last_error'] === '') { return $result; }

            $reportError = true;
            foreach ($ignoreErrorStrings as $ignoreThis) {
                if (is_string($ignoreThis) && strpos($result['last_error'], $ignoreThis) !== false) {
                    $reportError = false;
                    break;
                }
            }

            $lastErrorForClassification = is_string($result['last_error']) ? $result['last_error'] : '';
            if ($reportError && (
                $this->isDiskFullError($lastErrorForClassification) ||
                $this->isReadOnlyError($lastErrorForClassification) ||
                $this->isQuotaLimitError($lastErrorForClassification) ||
                $this->isInvalidDataError($lastErrorForClassification) ||
                $this->isCollationError($lastErrorForClassification) ||
                $this->isMissingPluginTableError($lastErrorForClassification) ||
                $this->isIncorrectKeyFileError($lastErrorForClassification) ||
                $this->isCrashedTableError($lastErrorForClassification) ||
                $this->isDeadlockOrLockTimeoutError($lastErrorForClassification) ||
                $this->isGaleraConflictError($lastErrorForClassification) ||
                $this->isTransientConnectionError($lastErrorForClassification) ||
                $this->isQueryTimeoutError($lastErrorForClassification) ||
                $this->isAccessDeniedError($lastErrorForClassification)
            )) {
                $this->logger->warn("Server-side DB issue (handled): " . $lastErrorForClassification);
                $reportError = false;
            }

            if ($reportError) {
                $stripped_query = 'n/a';
                if ($this->isInvalidDataError($result['last_error'])) {
                    $strippedResult = $this->get_stripped_query_result($query);
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
                if (!$this->serverSideIssueNoted && !$this->serverSideIssueChecked) {
                    $this->serverSideIssueChecked = true;
                    $existing = $this->getRuntimeFlag('abj404_plugin_db_notice');
                    $excludedTypes = array('stale_permalink_cache', 'missing_table');
                    if (is_array($existing) && !empty($existing['type'])
                        && !in_array($existing['type'], $excludedTypes, true)) {
                        $this->serverSideIssueNoted = true;
                    }
                }
                if ($this->serverSideIssueNoted && !$this->isWriteBlockActive() && !$this->isQuotaCooldownActive()) {
                    $this->clearServerSideDbNotice();
                }
            }
        }

        return $result;
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
            if (($cls === 'ABJ_404_Solution_DatabaseCore' || $cls === 'ABJ_404_Solution_DataAccess')
                && $fileLabel !== '' && $fileLabel !== 'DatabaseCore' && $fileLabel !== 'DataAccess') {
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

    /**
     * @param string $collation
     * @return string
     */
    public function sanitizeCollationIdentifier($collation) {
        if (!is_string($collation) || $collation === '') {
            return '';
        }
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $collation);
        return $sanitized !== null ? $sanitized : '';
    }

    /** @return string */
    public function getPreferredUtf8mb4Collation() {
        global $wpdb;
        if (isset($wpdb) && isset($wpdb->collate) && !empty($wpdb->collate)) {
            $wpdbCollation = $this->sanitizeCollationIdentifier((string)$wpdb->collate);
            if ($wpdbCollation !== '' && stripos($wpdbCollation, 'utf8mb4') !== false) {
                return $wpdbCollation;
            }
        }
        return 'utf8mb4_unicode_ci';
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    public function attemptInvalidDataRetry($query, &$result) {
        if (self::$invalidDataRetryInProgress) {
            return;
        }
        self::$invalidDataRetryInProgress = true;
        try {
            $retryQuery = $this->get_stripped_query_result($query);
            if (!is_string($retryQuery) || trim($retryQuery) === '' || $retryQuery === $query) {
                return;
            }
            global $wpdb;
            $wpdb->flush();
            $result['rows'] = $wpdb->get_results($retryQuery, $this->currentResultType);
            $this->harvestWpdbResult($result);
        } catch (Throwable $e) {
            $this->logger->warn("Invalid-data retry failed: " . $e->getMessage());
        } finally {
            self::$invalidDataRetryInProgress = false;
        }
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
     * @param string $key
     * @param mixed $value
     * @param int $ttlSeconds
     * @return void
     */
    public function setRuntimeFlag(string $key, $value, int $ttlSeconds): void {
        if (function_exists('set_transient')) {
            // allow-cache-empty: passthrough helper. Callers store admin-notice payloads, cooldown timestamps, and lock-state markers, not query results.
            set_transient($key, $value, $ttlSeconds);
            return;
        }
        if (function_exists('update_option')) {
            update_option($key, $value, false);
        }
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getRuntimeFlag(string $key) {
        if (function_exists('get_transient')) {
            return get_transient($key);
        }
        if (function_exists('get_option')) {
            return get_option($key, false);
        }
        return false;
    }

    /**
     * @param string $type
     * @param string $message
     * @param string $errorString
     * @return void
     */
    public function setPluginDbNotice(string $type, string $message, string $errorString = ''): void {
        $payload = array(
            'type' => $type,
            'message' => $message,
            'timestamp' => $this->clock()->now(),
            'error_string' => $errorString,
        );
        $this->setRuntimeFlag('abj404_plugin_db_notice', $payload, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);
    }

    /**
     * @param string $type
     * @return void
     */
    public function clearPluginDbNoticeIfType(string $type): void {
        $existing = $this->getRuntimeFlag('abj404_plugin_db_notice');
        if (!is_array($existing)) {
            return;
        }
        $currentType = isset($existing['type']) && is_string($existing['type']) ? $existing['type'] : '';
        if ($currentType !== $type) {
            return;
        }
        $this->clearServerSideDbNotice();
    }

    /** @return void */
    public function clearServerSideDbNotice(): void {
        if (function_exists('delete_transient')) {
            delete_transient('abj404_plugin_db_notice');
        } elseif (function_exists('delete_option')) {
            delete_option('abj404_plugin_db_notice');
        }
        $this->serverSideIssueNoted = false;
    }

    /** @param string $text @return string */
    public function localizeOrDefault(string $text): string {
        if (function_exists('__')) {
            return __($text, '404-solution');
        }
        return $text;
    }

    /** @return bool */
    public function isWriteBlockActive(): bool {
        $rawDiskFlag = $this->getRuntimeFlag('abj404_db_disk_full_until');
        $diskUntil = is_scalar($rawDiskFlag) ? (int)$rawDiskFlag : 0;
        $rawReadOnlyFlag = $this->getRuntimeFlag('abj404_db_read_only_until');
        $readOnlyUntil = is_scalar($rawReadOnlyFlag) ? (int)$rawReadOnlyFlag : 0;
        $now = $this->clock()->now();
        return ($diskUntil > $now || $readOnlyUntil > $now);
    }

    /** @return bool */
    public function shouldSkipNonEssentialDbWrites(): bool {
        return ($this->isQuotaCooldownActive() || $this->isWriteBlockActive());
    }

    /**
     * Attempt REPAIR TABLE after errno 1034, then retry once.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    public function repairCorruptedTableAndRetry(string $query, array &$result): void {
        $errorMessage = is_string($result['last_error']) ? $result['last_error'] : '';
        if ($this->repairDelegate !== null) {
            $this->repairDelegate->repairTable($errorMessage);
        }
        if (stripos($errorMessage, 'abj404') !== false) {
            global $wpdb;
            $wpdb->flush();
            $result['rows'] = $wpdb->get_results($query, $this->currentResultType);
            $result['last_error'] = (string)($wpdb->last_error ?? '');
            $result['last_result'] = $wpdb->last_result ?? array();
            $result['rows_affected'] = $wpdb->rows_affected ?? 0;
            $result['insert_id'] = $wpdb->insert_id ?? 0;
            if ($result['last_error'] === '') {
                $this->logger->infoMessage("Retry after 'Incorrect key file' repair succeeded for plugin table.");
            }
        }
    }

    /**
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

    /** @inheritDoc */
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
                        if (!$this->classifyAndHandleInfrastructureError($lastError)) {
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
            $retryable = $this->isDeadlockOrLockTimeoutError($lastError);
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
            throw new Exception($lastError);
        }
    }
}
