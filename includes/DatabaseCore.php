<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseCoreInterface.php';
require_once __DIR__ . '/DatabaseRuntimeState.php';
require_once __DIR__ . '/DatabaseConnectionManager.php';
require_once __DIR__ . '/DatabaseQueryTimeoutManager.php';
require_once __DIR__ . '/DatabaseErrorClassifier.php';
require_once __DIR__ . '/DatabaseSqlErrorReporter.php';
require_once __DIR__ . '/DatabaseTableNameResolver.php';
require_once __DIR__ . '/DatabaseNoticeStateHolder.php';
require_once __DIR__ . '/DatabaseCollationHelper.php';
require_once __DIR__ . '/DatabaseTableRepairer.php';

/**
 * Shared database infrastructure: query execution, error recovery, timeouts,
 * connection management, table-name resolution, and error classification.
 *
 * Extracted from the DataAccess monolith (Phase 0 of the DataAccess refactor).
 * Every DAO module receives a DatabaseCore instance via constructor injection.
 */
class ABJ_404_Solution_DatabaseCore implements ABJ_404_Solution_DatabaseCoreInterface {

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

    /** @var ABJ_404_Solution_DatabaseConnectionManager */
    private $connectionManager;

    /** @var ABJ_404_Solution_DatabaseQueryTimeoutManager */
    private $queryTimeoutManager;

    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;

    /** @var ABJ_404_Solution_DatabaseSqlErrorReporter */
    private $sqlErrorReporter;

    /** @var ABJ_404_Solution_DatabaseTableNameResolver */
    private $tableNameResolver;

    /** @var ABJ_404_Solution_DatabaseNoticeStateHolder */
    private $noticeState;

    /** @var ABJ_404_Solution_DatabaseCollationHelper */
    private $collationHelper;

    /** @var ABJ_404_Solution_DatabaseTableRepairer */
    private $tableRepairer;

    /**
     * @var bool Per-request cache: this server rejected the
     *  `SET STATEMENT max_statement_time=N FOR ...` timeout wrapper, so
     *  applyQueryTimeout() must skip wrapping for the rest of the request.
     */
    private static $setStatementWrapperUnsupported = false;

    /** @var string Current wpdb result type for queryAndGetResults (ARRAY_A or OBJECT). */
    private $currentResultType = ARRAY_A;

    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct($functions = null, $logging = null) {
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->connectionManager = new ABJ_404_Solution_DatabaseConnectionManager($this, $this->logger);
        $this->queryTimeoutManager = new ABJ_404_Solution_DatabaseQueryTimeoutManager($this, $this->logger);
        $this->errorClassifier = new ABJ_404_Solution_DatabaseErrorClassifier($this, $this->f, $this->logger);
        $this->sqlErrorReporter = new ABJ_404_Solution_DatabaseSqlErrorReporter($this, $this->logger);
        $this->tableNameResolver = new ABJ_404_Solution_DatabaseTableNameResolver(
            $this->f,
            function (string $query, array $options): array {
                return $this->queryAndGetResults($query, $options);
            }
        );
        $this->noticeState = new ABJ_404_Solution_DatabaseNoticeStateHolder(
            function (): bool {
                return $this->errorClassifier->isQuotaCooldownActive();
            }
        );
        $this->collationHelper = new ABJ_404_Solution_DatabaseCollationHelper(
            function (string $query, array $options): array {
                return $this->queryAndGetResults($query, $options);
            },
            function (string $tableName): string {
                // Call through $this so test-stub subclass overrides of
                // getCreateTableDDL are honored, matching pre-extraction behavior.
                return $this->getCreateTableDDL($tableName);
            },
            function (string $key) {
                return $this->noticeState->getRuntimeFlag($key);
            },
            function (string $key, $value, int $ttl): void {
                $this->noticeState->setRuntimeFlag($key, $value, $ttl);
            },
            function (array &$result): void {
                $this->harvestWpdbResult($result);
            },
            $this->logger
        );
        $this->tableRepairer = new ABJ_404_Solution_DatabaseTableRepairer(
            function (string $query, array $options): array {
                return $this->queryAndGetResults($query, $options);
            },
            function (array &$result): void {
                $this->harvestWpdbResult($result);
            },
            function (): string {
                return $this->currentResultType;
            },
            function (string $type, string $message, string $errorString): void {
                $this->noticeState->setPluginDbNotice($type, $message, $errorString);
            },
            function (string $text): string {
                return $this->noticeState->localizeOrDefault($text);
            },
            $this->f,
            $this->logger
        );
    }

    /**
     * Delegate extracted database infrastructure methods to their focused components.
     *
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        foreach (array($this->connectionManager, $this->queryTimeoutManager, $this->errorClassifier, $this->sqlErrorReporter) as $component) {
            if (method_exists($component, $name)) {
                return $component->$name(...$arguments);
            }
        }
        throw new BadMethodCallException('Unknown DatabaseCore method: ' . $name);
    }

    /** @param object $wpdb @param bool $allowReconnect @return bool */
    public function safeCheckConnection($wpdb, bool $allowReconnect = false): bool { return $this->connectionManager->safeCheckConnection($wpdb, $allowReconnect); }
    /** @return bool */
    public function ensureConnection() { return $this->connectionManager->ensureConnection(); }
    /** @param string $errorText @return bool */
    public function classifyAndHandleInfrastructureError(string $errorText): bool { return $this->errorClassifier->classifyAndHandleInfrastructureError($errorText); }
    /** @param int $stageNumber @param string $errorText @return string */
    public function classifyStageFailure(int $stageNumber, string $errorText): string { return $this->errorClassifier->classifyStageFailure($stageNumber, $errorText); }
    /** @param string $errorText @return bool */
    public function isOutOfMemoryError(string $errorText): bool { return $this->errorClassifier->isOutOfMemoryError($errorText); }
    /** @param mixed $errorText @return bool */
    public function isInvalidDataError($errorText): bool { return $this->errorClassifier->isInvalidDataError($errorText); }
    /** @param string|null $errorText @return bool */
    public function isTransientConnectionError(?string $errorText): bool { return $this->errorClassifier->isTransientConnectionError($errorText); }
    /** @param string $errorText @return bool */
    public function isQuotaLimitError(string $errorText): bool { return $this->errorClassifier->isQuotaLimitError($errorText); }
    /** @param string $errorText @return bool */
    public function isDiskFullError(string $errorText): bool { return $this->errorClassifier->isDiskFullError($errorText); }
    /** @param string $errorText @return bool */
    public function isReadOnlyError(string $errorText): bool { return $this->errorClassifier->isReadOnlyError($errorText); }
    /** @param string $errorText @return bool */
    public function isAccessDeniedError(string $errorText): bool { return $this->errorClassifier->isAccessDeniedError($errorText); }
    /** @param string $errorText @return bool */
    public function classifySetStatementFailure(string $errorText): bool { return $this->errorClassifier->classifySetStatementFailure($errorText); }
    /** @param string $errorText @return bool */
    public function isCollationError(string $errorText): bool { return $this->errorClassifier->isCollationError($errorText); }
    /** @param string $errorText @return bool */
    public function isCrashedTableError(string $errorText): bool { return $this->errorClassifier->isCrashedTableError($errorText); }
    /** @param string $errorText @return bool */
    public function isIncorrectKeyFileError(string $errorText): bool { return $this->errorClassifier->isIncorrectKeyFileError($errorText); }
    /** @param string $errorText @return bool */
    public function isQueryTimeoutError(string $errorText): bool { return $this->errorClassifier->isQueryTimeoutError($errorText); }
    /** @param string $errorText @return bool */
    public function isPacketTooLarge(string $errorText): bool { return $this->errorClassifier->isPacketTooLarge($errorText); }
    /** @param string $errorText @return bool */
    public function isDeadlockOrLockTimeoutError(string $errorText): bool { return $this->errorClassifier->isDeadlockOrLockTimeoutError($errorText); }
    /** @param string $errorText @return bool */
    public function isGaleraConflictError(string $errorText): bool { return $this->errorClassifier->isGaleraConflictError($errorText); }
    /** @param string $errorText @return bool */
    public function isPermanentHostSideStagedFailure(string $errorText): bool { return $this->errorClassifier->isPermanentHostSideStagedFailure($errorText); }
    /** @param string $errorText @return bool */
    public function isResumableStagedKill(string $errorText): bool { return $this->errorClassifier->isResumableStagedKill($errorText); }
    /** @param string $errorText @return string|null */
    public function extractTableNameFromFullError(string $errorText): ?string { return $this->errorClassifier->extractTableNameFromFullError($errorText); }
    /** @param string $tableName @return bool */
    public function isInnoDBTable(string $tableName): bool { return $this->errorClassifier->isInnoDBTable($tableName); }
    /** @param string $errorText @return void */
    public function noteDatabaseIssueFromError(string $errorText): void { $this->errorClassifier->noteDatabaseIssueFromError($errorText); }
    /** @return bool */
    public function isQuotaCooldownActive(): bool { return $this->errorClassifier->isQuotaCooldownActive(); }
    /** @param string $errorText @return bool */
    public function isMissingPluginTableError(string $errorText): bool { return $this->errorClassifier->isMissingPluginTableError($errorText); }
    /** @param string $errorText @return bool */
    public function isTransientViewBuildTableError(string $errorText): bool { return $this->errorClassifier->isTransientViewBuildTableError($errorText); }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    public function attemptMissingTableRepairAndRetry($query, array &$result): void {
        $this->errorClassifier->attemptMissingTableRepairAndRetry($query, $result);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return bool
     */
    public function handleTransientViewBuildTableMissing($query, array &$result): bool {
        return $this->errorClassifier->handleTransientViewBuildTableMissing($query, $result);
    }

    /** @param array<string, mixed> $result @param string $repairCooldownKey @return bool */
    public function isMissingTableRepairOnCooldown(array &$result, string $repairCooldownKey): bool {
        return $this->errorClassifier->isMissingTableRepairOnCooldown($result, $repairCooldownKey);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param string $repairCooldownKey
     * @param int $cooldownTtlSeconds
     * @param string $originalSqlError
     * @param string $missingTable
     * @return void
     */
    public function runRepairCreateRetryAndReport(
        $query, array &$result, string $repairCooldownKey, int $cooldownTtlSeconds,
        string $originalSqlError, string $missingTable
    ): void {
        $this->errorClassifier->runRepairCreateRetryAndReport(
            $query, $result, $repairCooldownKey, $cooldownTtlSeconds, $originalSqlError, $missingTable
        );
    }

    /**
     * @param array<string, mixed> $result
     * @param string $repairCooldownKey
     * @param int $cooldownTtlSeconds
     * @param string $originalSqlError
     * @param string $missingTable
     * @return void
     */
    public function reportRepairRetryFailure(
        array &$result, string $repairCooldownKey, int $cooldownTtlSeconds,
        string $originalSqlError, string $missingTable
    ): void {
        $this->errorClassifier->reportRepairRetryFailure(
            $result, $repairCooldownKey, $cooldownTtlSeconds, $originalSqlError, $missingTable
        );
    }

    /** @param array<string, mixed> $result @param string $missingTable @param string $prefixDiag @return void */
    public function setMissingTablePluginDbNotice(array $result, string $missingTable, string $prefixDiag): void {
        $this->errorClassifier->setMissingTablePluginDbNotice($result, $missingTable, $prefixDiag);
    }

    /** @param string $errorText @return string */
    public function extractMissingTableNameFromError(string $errorText): string {
        return $this->errorClassifier->extractMissingTableNameFromError($errorText);
    }

    /** @return string */
    public function diagnosePrefixMismatch(): string {
        return $this->errorClassifier->diagnosePrefixMismatch();
    }

    /** @param string $errorText @return bool */
    public function isMultisiteCrossPrefixError(string $errorText): bool {
        return $this->errorClassifier->isMultisiteCrossPrefixError($errorText);
    }

    /** @param string $query @return bool */
    public function queryStartsWithSelect(string $query): bool {
        return $this->queryTimeoutManager->queryStartsWithSelect($query);
    }

    /** @param string $query @return bool */
    public function queryProducesResultRows(string $query): bool {
        return $this->queryTimeoutManager->queryProducesResultRows($query);
    }

    /** @param string $query @param int $timeoutSeconds @return string */
    public function applyQueryTimeout(string $query, int $timeoutSeconds): string {
        return $this->queryTimeoutManager->applyQueryTimeout($query, $timeoutSeconds);
    }

    /** @return bool */
    public function isMariaDB(): bool {
        return $this->queryTimeoutManager->isMariaDB();
    }

    /** @param string $query @param int $timeoutSeconds @return string */
    public function applySelectTimeout(string $query, int $timeoutSeconds): string {
        return $this->queryTimeoutManager->applySelectTimeout($query, $timeoutSeconds);
    }

    /** @param string $query @param int $timeoutSeconds @return string */
    public function applyNonLeadingSelectTimeout(string $query, int $timeoutSeconds): string {
        return $this->queryTimeoutManager->applyNonLeadingSelectTimeout($query, $timeoutSeconds);
    }

    /** @param string $query @param int $timeoutSeconds @return string */
    public function applyStatementTimeout(string $query, int $timeoutSeconds): string {
        return $this->queryTimeoutManager->applyStatementTimeout($query, $timeoutSeconds);
    }

    /** @param string $insertSelectQuery @param int $timeoutSeconds @return string */
    public function applyTimeoutToInsertSelect(string $insertSelectQuery, int $timeoutSeconds): string {
        return $this->queryTimeoutManager->applyTimeoutToInsertSelect($insertSelectQuery, $timeoutSeconds);
    }

    /** @param string $query @return bool */
    public function queryHasSetStatementWrapper(string $query): bool {
        return $this->queryTimeoutManager->queryHasSetStatementWrapper($query);
    }

    /** @param string $query @return string */
    public function stripSetStatementWrapper(string $query): string {
        return $this->queryTimeoutManager->stripSetStatementWrapper($query);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @return void
     */
    public function retryWithoutSetStatementWrapper(string &$query, array &$result, string $resultType): void {
        $this->queryTimeoutManager->retryWithoutSetStatementWrapper($query, $result, $resultType);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param array<string, mixed> $options
     * @param bool $producesRows
     * @return void
     */
    public function logObservedSqlError(string $query, array $result, array $options, bool $producesRows): void {
        $this->sqlErrorReporter->logObservedSqlError($query, $result, $options, $producesRows);
    }

    /** @param string $errorText @return bool */
    public function isInfrastructureSqlError(string $errorText): bool {
        return $this->sqlErrorReporter->isInfrastructureSqlError($errorText);
    }

    /**
     * @param string $query
     * @param Throwable $e
     * @param array<string, mixed> $options
     * @param bool $producesRows
     * @return void
     */
    public function logSqlThrowable(string $query, Throwable $e, array $options, bool $producesRows): void {
        $this->sqlErrorReporter->logSqlThrowable($query, $e, $options, $producesRows);
    }

    /** @return void */
    public function markServerSideIssueNoted(): void {
        $this->noticeState->markServerSideIssueNoted();
    }

    /** @return bool */
    public function isTableRepairInProgress(): bool {
        return $this->tableRepairer->isTableRepairInProgress();
    }

    /** @param bool $value @return void */
    public function setTableRepairInProgress(bool $value): void {
        $this->tableRepairer->setTableRepairInProgress($value);
    }

    /** @return string */
    public function getCurrentResultType(): string {
        return $this->currentResultType;
    }

    /** @param ABJ_404_Solution_Clock $clock @return void */
    public function setClock(ABJ_404_Solution_Clock $clock): void {
        $this->clock = $clock;
        $this->noticeState->setClock($clock);
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
        ABJ_404_Solution_DatabaseRuntimeState::setSetStatementWrapperUnsupported($value);
    }

    /** @return bool */
    public static function isSetStatementWrapperUnsupported(): bool {
        return self::$setStatementWrapperUnsupported
            || ABJ_404_Solution_DatabaseRuntimeState::isSetStatementWrapperUnsupported();
    }

    /**
     * Check if a database table exists.
     *
     * @param string $tableName Full table name to check (including prefix)
     * @return bool
     */
    public function tableExists($tableName): bool {
        return $this->tableNameResolver->tableExists($tableName);
    }

    /**
     * Get the column names of an actual database table via SHOW COLUMNS.
     *
     * @param string $tableName Full table name (including prefix)
     * @return array<int, string>
     */
    public function getTableColumnNames(string $tableName): array {
        return $this->tableNameResolver->getTableColumnNames($tableName);
    }

    /**
     * @param string $query
     * @return string
     */
    public function doTableNameReplacements($query): string {
        return $this->tableNameResolver->doTableNameReplacements($query);
    }

    /** @return string */
    public function getLowercasePrefix(): string {
        return $this->tableNameResolver->getLowercasePrefix();
    }

    /**
     * @param string $tableSuffix
     * @return string
     */
    public function getPrefixedTableName($tableSuffix): string {
        return $this->tableNameResolver->getPrefixedTableName($tableSuffix);
    }

    /**
     * @param string $tableName
     * @return string
     */
    public function getCreateTableDDL($tableName): string {
        return $this->tableNameResolver->getCreateTableDDL($tableName);
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildPostTypeSqlList(array $options): string {
        return $this->tableNameResolver->buildPostTypeSqlList($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildCategorySqlList(array $options): string {
        return $this->tableNameResolver->buildCategorySqlList($options);
    }

    /** @return void */
    public function setSqlBigSelects(): void {
        $this->tableNameResolver->setSqlBigSelects();
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
                $this->repairTable($result['last_error']);
            }
            if ($this->f->strpos($result['last_error'],
                    "ALTER TABLE causes auto_increment resequencing") !== false &&
                    $this->f->strpos($result['last_error'], "resulting in duplicate entry") !== false) {
                $this->repairDuplicateIDs($result['last_error'], $query);
            }
            if ($this->isIncorrectKeyFileError($result['last_error'])) {
                $this->repairCorruptedTableAndRetry($query, $result);
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
                if (!$this->noticeState->isServerSideIssueNoted() && !$this->noticeState->isServerSideIssueChecked()) {
                    $this->noticeState->markServerSideIssueChecked();
                    $existing = $this->getRuntimeFlag('abj404_plugin_db_notice');
                    $excludedTypes = array('stale_permalink_cache', 'missing_table');
                    if (is_array($existing) && !empty($existing['type'])
                        && !in_array($existing['type'], $excludedTypes, true)) {
                        $this->noticeState->markServerSideIssueNoted();
                    }
                }
                if ($this->noticeState->isServerSideIssueNoted() && !$this->isWriteBlockActive() && !$this->isQuotaCooldownActive()) {
                    $this->clearServerSideDbNotice();
                }
            }
        }
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

        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';

        if ($lastError !== '' && $this->isInvalidDataError($lastError)) {
            $this->attemptInvalidDataRetry($query, $result);
        }

        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';

        if ($lastError !== '' && $this->isDeadlockOrLockTimeoutError($lastError)) {
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
            if ($lastError !== '' && $this->isDeadlockOrLockTimeoutError($lastError)) {
                // allow-em-dash: copied verbatim from existing user-facing localized string in DataAccess.php
                $this->setPluginDbNotice('lock_timeout', $this->localizeOrDefault('A database lock wait timeout occurred. If this persists, contact your host — another process may be holding a long-running lock.'), $lastError);
            }
        }

        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
        if ($lastError !== '' && $this->isCollationError($lastError)) {
            $this->recoverFromCollationMismatchAndRetry($query, $result, $producesRows, $resultType);
        }

        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
        if ($lastError !== '' && $this->isQueryTimeoutError($lastError)) {
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
            $this->noteDatabaseIssueFromError($lastError);
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
     * Delegate: sanitize a raw collation identifier (strip non-word chars).
     *
     * @param string $collation
     * @return string
     */
    public function sanitizeCollationIdentifier($collation): string {
        return $this->collationHelper->sanitizeCollationIdentifier($collation);
    }

    /**
     * Delegate: get the table-level default collation for a given table.
     *
     * @param string $tableName Fully-qualified table name (including prefix).
     * @return string
     */
    public function getTableCollationString(string $tableName): string {
        return $this->collationHelper->getTableCollationString($tableName);
    }

    /**
     * Delegate: get the column-level collation for a specific column.
     *
     * @param string $tableName  Fully-qualified table name (including prefix).
     * @param string $columnName Column name to look up.
     * @return string
     */
    public function getColumnCollationString(string $tableName, string $columnName): string {
        return $this->collationHelper->getColumnCollationString($tableName, $columnName);
    }

    /**
     * Delegate: return the preferred utf8mb4 collation for this wpdb connection.
     *
     * @return string
     */
    public function getPreferredUtf8mb4Collation(): string {
        return $this->collationHelper->getPreferredUtf8mb4Collation();
    }

    /**
     * Delegate: attempt a single invalid-data retry by asking WPDBExtension to
     * strip invalid bytes from the query, then re-running the stripped query.
     *
     * @param string $query
     * @param array<string, mixed> $result Passed by reference.
     * @return void
     */
    public function attemptInvalidDataRetry($query, &$result) {
        $this->tableRepairer->attemptInvalidDataRetry($query, $result);
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
        $this->noticeState->setRuntimeFlag($key, $value, $ttlSeconds);
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getRuntimeFlag(string $key) {
        return $this->noticeState->getRuntimeFlag($key);
    }

    /**
     * @param string $type
     * @param string $message
     * @param string $errorString
     * @return void
     */
    public function setPluginDbNotice(string $type, string $message, string $errorString = ''): void {
        $this->noticeState->setPluginDbNotice($type, $message, $errorString);
    }

    /**
     * @param string $type
     * @return void
     */
    public function clearPluginDbNoticeIfType(string $type): void {
        $this->noticeState->clearPluginDbNoticeIfType($type);
    }

    /** @return void */
    public function clearServerSideDbNotice(): void {
        $this->noticeState->clearServerSideDbNotice();
    }

    /** @param string $text @return string */
    public function localizeOrDefault(string $text): string {
        return $this->noticeState->localizeOrDefault($text);
    }

    /** @return bool */
    public function isWriteBlockActive(): bool {
        return $this->noticeState->isWriteBlockActive();
    }

    /** @return bool */
    public function shouldSkipNonEssentialDbWrites(): bool {
        return $this->noticeState->shouldSkipNonEssentialDbWrites();
    }

    /**
     * Delegate: REPAIR TABLE after errno 1034, then retry the original query once.
     *
     * @param string $query
     * @param array<string, mixed> $result Passed by reference.
     * @return void
     */
    public function repairCorruptedTableAndRetry(string $query, array &$result): void {
        $this->tableRepairer->repairCorruptedTableAndRetry($query, $result);
    }

    /**
     * Delegate: ask WPDBExtension to strip invalid bytes from a query string.
     *
     * @param string $query
     * @return NULL|string|WP_Error
     */
    public function get_stripped_query_result($query) {
        return $this->tableRepairer->get_stripped_query_result($query);
    }

    // =========================================================================
    // Repair / recovery methods (moved from DataAccessTrait_Maintenance, Phase 5)
    // =========================================================================

    /**
     * Delegate: auto-recover from a collation mismatch detected at query time.
     *
     * @param string $query
     * @param array<string, mixed> $result passed by reference
     * @param bool   $producesRows Whether the query returns result rows.
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType wpdb output type for get_results().
     * @return void
     */
    public function recoverFromCollationMismatchAndRetry(string $query, array &$result, bool $producesRows, string $resultType): void {
        $this->collationHelper->recoverFromCollationMismatchAndRetry($query, $result, $producesRows, $resultType);
    }

    /**
     * Delegate: validate and sanitize a table name extracted from error
     * messages or SQL. Kept private to preserve the pre-extraction visibility;
     * the canonical implementation lives on DatabaseTableRepairer.
     *
     * @param string $name Raw table name.
     * @return string|null Sanitized name, or null if invalid.
     */
    private function sanitizeTableName(string $name): ?string {
        return $this->tableRepairer->sanitizeTableName($name);
    }

    /** @inheritDoc */
    public function repairTable(string $errorMessage): void {
        $this->tableRepairer->repairTable($errorMessage);
    }

    /** @inheritDoc */
    public function repairDuplicateIDs(string $errorMessage, string $sqlThatWasRun): void {
        $this->tableRepairer->repairDuplicateIDs($errorMessage, $sqlThatWasRun);
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
