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
require_once __DIR__ . '/DatabaseQueryExecutor.php';

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

    /** @var ABJ_404_Solution_DatabaseQueryExecutor */
    private $queryExecutor;

    /**
     * @var bool Per-request cache: this server rejected the
     *  `SET STATEMENT max_statement_time=N FOR ...` timeout wrapper, so
     *  applyQueryTimeout() must skip wrapping for the rest of the request.
     */
    private static $setStatementWrapperUnsupported = false;

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
        $this->queryExecutor = new ABJ_404_Solution_DatabaseQueryExecutor($this, $this->f, $this->logger);
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
                return $this->queryExecutor->getCurrentResultType();
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
        return $this->queryExecutor->getCurrentResultType();
    }

    /** @return bool */
    public function isServerSideIssueNoted(): bool {
        return $this->noticeState->isServerSideIssueNoted();
    }

    /** @return bool */
    public function isServerSideIssueChecked(): bool {
        return $this->noticeState->isServerSideIssueChecked();
    }

    /** @return void */
    public function markServerSideIssueChecked(): void {
        $this->noticeState->markServerSideIssueChecked();
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
        return $this->queryExecutor->queryScalarInt($query, $options);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function queryAndGetResults($query, $options = array()): array {
        return $this->queryExecutor->queryAndGetResults($query, $options);
    }

    /**
     * Resolve a stable source identifier for safe logging.
     *
     * @param string $query
     * @return string
     */
    public function extractSqlFilename($query) {
        return $this->queryExecutor->extractSqlFilename($query);
    }

    /** @return string */
    public function resolveCallerFromBacktrace() {
        return $this->queryExecutor->resolveCallerFromBacktrace();
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
        $this->queryExecutor->applyDiagnosticLatencyIfConfigured();
    }

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    public function harvestWpdbResult(array &$result): void {
        $this->queryExecutor->harvestWpdbResult($result);
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
        $this->queryExecutor->executeAsTransaction($statementArray);
    }
}
