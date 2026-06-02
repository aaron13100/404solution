<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseCoreInterface.php';
require_once __DIR__ . '/DatabaseRuntimeState.php';
require_once __DIR__ . '/DatabaseConnectionManager.php';
require_once __DIR__ . '/DatabaseQueryTimeoutManager.php';
require_once __DIR__ . '/DatabaseErrorClassifier.php';
require_once __DIR__ . '/DatabaseRepairPolicy.php';
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
 * Composition root for the nine infrastructure components
 * (DatabaseConnectionManager, DatabaseQueryTimeoutManager,
 * DatabaseErrorClassifier, DatabaseSqlErrorReporter,
 * DatabaseTableNameResolver, DatabaseNoticeStateHolder,
 * DatabaseCollationHelper, DatabaseTableRepairer, DatabaseQueryExecutor).
 *
 * Public surface:
 *   - Interface-required methods (DatabaseCoreInterface) for callers that
 *     hold the contract type.
 *   - A small set of structurally-explicit methods that cannot be reached
 *     via direct component calls: by-reference signatures, reflection
 *     targets used by tests, and static-state helpers.
 *   - Component accessor methods (connectionManager(), errorClassifier(),
 *     etc.) for DAO-internal callers that need non-interface behavior.
 *
 * There is no __call() dispatch: every non-interface call site names the
 * component class explicitly. This was reversed from commit dd7e9a30; see
 * `docs/adr/database-core-explicit-component-access.md`.
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

    /** @var ABJ_404_Solution_DatabaseRepairPolicy */
    private $repairPolicy;

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
        $this->repairPolicy = new ABJ_404_Solution_DatabaseRepairPolicy($this, $this->errorClassifier, $this->f, $this->logger);
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
                $this->queryExecutor->harvestWpdbResult($result);
            },
            $this->logger
        );
        $this->tableRepairer = new ABJ_404_Solution_DatabaseTableRepairer(
            function (string $query, array $options): array {
                return $this->queryAndGetResults($query, $options);
            },
            function (array &$result): void {
                $this->queryExecutor->harvestWpdbResult($result);
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

    // =========================================================================
    // Public accessors for the focused component classes. DAOs and other
    // infrastructure-layer callers depend directly on the component they need
    // and call methods on it. DatabaseCore itself satisfies
    // DatabaseCoreInterface for type-system callers; non-interface surface
    // does NOT dispatch through DatabaseCore (no __call, no pass-through
    // wrappers).
    // =========================================================================

    /** @return ABJ_404_Solution_DatabaseConnectionManager */
    public function connectionManager(): ABJ_404_Solution_DatabaseConnectionManager {
        return $this->connectionManager;
    }

    /** @return ABJ_404_Solution_DatabaseQueryTimeoutManager */
    public function queryTimeoutManager(): ABJ_404_Solution_DatabaseQueryTimeoutManager {
        return $this->queryTimeoutManager;
    }

    /** @return ABJ_404_Solution_DatabaseErrorClassifier */
    public function errorClassifier(): ABJ_404_Solution_DatabaseErrorClassifier {
        return $this->errorClassifier;
    }

    /** @return ABJ_404_Solution_DatabaseRepairPolicy */
    public function repairPolicy(): ABJ_404_Solution_DatabaseRepairPolicy {
        return $this->repairPolicy;
    }

    /** @return ABJ_404_Solution_DatabaseSqlErrorReporter */
    public function sqlErrorReporter(): ABJ_404_Solution_DatabaseSqlErrorReporter {
        return $this->sqlErrorReporter;
    }

    /** @return ABJ_404_Solution_DatabaseTableNameResolver */
    public function tableNameResolver(): ABJ_404_Solution_DatabaseTableNameResolver {
        return $this->tableNameResolver;
    }

    /** @return ABJ_404_Solution_DatabaseNoticeStateHolder */
    public function noticeState(): ABJ_404_Solution_DatabaseNoticeStateHolder {
        return $this->noticeState;
    }

    /** @return ABJ_404_Solution_DatabaseCollationHelper */
    public function collationHelper(): ABJ_404_Solution_DatabaseCollationHelper {
        return $this->collationHelper;
    }

    /** @return ABJ_404_Solution_DatabaseTableRepairer */
    public function tableRepairer(): ABJ_404_Solution_DatabaseTableRepairer {
        return $this->tableRepairer;
    }

    /** @return ABJ_404_Solution_DatabaseQueryExecutor */
    public function queryExecutor(): ABJ_404_Solution_DatabaseQueryExecutor {
        return $this->queryExecutor;
    }

    // =========================================================================
    // Interface-required methods (DatabaseCoreInterface). These remain
    // explicit so PHP's type system sees the contract.
    // =========================================================================

    /** @inheritDoc */
    public function queryAndGetResults($query, $options = array()): array {
        return $this->queryExecutor->queryAndGetResults($query, $options);
    }

    /** @inheritDoc */
    public function queryScalarInt($query, $options = array()): int {
        return $this->queryExecutor->queryScalarInt($query, $options);
    }

    /** @inheritDoc */
    public function doTableNameReplacements($query): string {
        return $this->tableNameResolver->doTableNameReplacements($query);
    }

    /** @inheritDoc */
    public function getLowercasePrefix(): string {
        return $this->tableNameResolver->getLowercasePrefix();
    }

    /** @inheritDoc */
    public function getPrefixedTableName($tableSuffix): string {
        return $this->tableNameResolver->getPrefixedTableName($tableSuffix);
    }

    /** @inheritDoc */
    public function getCreateTableDDL($tableName): string {
        return $this->tableNameResolver->getCreateTableDDL($tableName);
    }

    /** @inheritDoc */
    public function getTableCollationString(string $tableName): string {
        return $this->collationHelper->getTableCollationString($tableName);
    }

    /** @inheritDoc */
    public function getColumnCollationString(string $tableName, string $columnName): string {
        return $this->collationHelper->getColumnCollationString($tableName, $columnName);
    }

    /** @inheritDoc */
    public function tableExists($tableName): bool {
        return $this->tableNameResolver->tableExists($tableName);
    }

    /** @inheritDoc */
    public function getTableColumnNames(string $tableName): array {
        return $this->tableNameResolver->getTableColumnNames($tableName);
    }

    /** @inheritDoc */
    public function buildPostTypeSqlList(array $options): string {
        // Delegate preserves DatabaseTableNameResolver's rtrim($recognizedPostTypes, ", ") empty-list behavior.
        return $this->tableNameResolver->buildPostTypeSqlList($options);
    }

    /** @inheritDoc */
    public function buildCategorySqlList(array $options): string {
        return $this->tableNameResolver->buildCategorySqlList($options);
    }

    /** @inheritDoc */
    public function setSqlBigSelects(): void {
        $this->tableNameResolver->setSqlBigSelects();
    }

    /** @inheritDoc */
    public function classifyAndHandleInfrastructureError(string $errorText): bool {
        return $this->errorClassifier->classifyAndHandleInfrastructureError($errorText);
    }

    /** @inheritDoc */
    public function classifyStageFailure(int $stageNumber, string $errorText): string {
        return $this->errorClassifier->classifyStageFailure($stageNumber, $errorText);
    }

    /** @inheritDoc */
    public function isOutOfMemoryError(string $errorText): bool {
        return $this->errorClassifier->isOutOfMemoryError($errorText);
    }

    /** @inheritDoc */
    public function setClock(ABJ_404_Solution_Clock $clock): void {
        $this->clock = $clock;
        $this->noticeState->setClock($clock);
    }

    /** @inheritDoc */
    public function setRuntimeFlag(string $key, $value, int $ttlSeconds): void {
        $this->noticeState->setRuntimeFlag($key, $value, $ttlSeconds);
    }

    /** @inheritDoc */
    public function getRuntimeFlag(string $key) {
        return $this->noticeState->getRuntimeFlag($key);
    }

    /** @inheritDoc */
    public function setPluginDbNotice(string $type, string $message, string $errorString = ''): void {
        $this->noticeState->setPluginDbNotice($type, $message, $errorString);
    }

    /** @inheritDoc */
    public function clearPluginDbNoticeIfType(string $type): void {
        $this->noticeState->clearPluginDbNoticeIfType($type);
    }

    /** @inheritDoc */
    public function isWriteBlockActive(): bool {
        return $this->noticeState->isWriteBlockActive();
    }

    /** @inheritDoc */
    public function shouldSkipNonEssentialDbWrites(): bool {
        return $this->noticeState->shouldSkipNonEssentialDbWrites();
    }

    /** @inheritDoc */
    public function repairTable(string $errorMessage): void {
        $this->tableRepairer->repairTable($errorMessage);
    }

    /** @inheritDoc */
    public function repairDuplicateIDs(string $errorMessage, string $sqlThatWasRun): void {
        $this->tableRepairer->repairDuplicateIDs($errorMessage, $sqlThatWasRun);
    }

    /**
     * @inheritDoc
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     */
    public function recoverFromCollationMismatchAndRetry(string $query, array &$result, bool $producesRows, string $resultType): void {
        $this->collationHelper->recoverFromCollationMismatchAndRetry($query, $result, $producesRows, $resultType);
    }

    /** @inheritDoc */
    public function executeAsTransaction(array $statementArray): void {
        try {
            $this->queryExecutor->executeAsTransaction($statementArray);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    // =========================================================================
    // Explicit delegates kept for one of three reasons:
    //  - by-reference parameters: __call() copies args into $arguments, so
    //    `&$result` would not propagate writes back to the caller.
    //  - tests reflect on the method by name on this class (Reflection cannot
    //    see __call-routed methods, even with @method docblocks).
    //  - source-code grep tests assert the literal `function X` appears in
    //    DataAccess.php / DatabaseCore.php.
    // =========================================================================

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    public function harvestWpdbResult(array &$result): void {
        $this->queryExecutor->harvestWpdbResult($result);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    public function attemptMissingTableRepairAndRetry($query, array &$result): void {
        $this->repairPolicy->attemptMissingTableRepairAndRetry($query, $result);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return bool
     */
    public function handleTransientViewBuildTableMissing($query, array &$result): bool {
        return $this->repairPolicy->handleTransientViewBuildTableMissing($query, $result);
    }

    /**
     * @param array<string, mixed> $result
     * @param string $repairCooldownKey
     * @return bool
     */
    public function isMissingTableRepairOnCooldown(array &$result, string $repairCooldownKey): bool {
        return $this->repairPolicy->isMissingTableRepairOnCooldown($result, $repairCooldownKey);
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
        $this->repairPolicy->runRepairCreateRetryAndReport(
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
        $this->repairPolicy->reportRepairRetryFailure(
            $result, $repairCooldownKey, $cooldownTtlSeconds, $originalSqlError, $missingTable
        );
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    public function repairCorruptedTableAndRetry(string $query, array &$result): void {
        $this->tableRepairer->repairCorruptedTableAndRetry($query, $result);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    public function attemptInvalidDataRetry(string $query, array &$result): void {
        $this->tableRepairer->attemptInvalidDataRetry($query, $result);
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
     * Reflection target.
     *
     * @param string|null $errorText
     * @return bool
     */
    public function isTransientConnectionError(?string $errorText): bool {
        return $this->errorClassifier->isTransientConnectionError($errorText);
    }

    /**
     * Reflection target.
     *
     * @param string $errorText
     * @return void
     */
    public function noteDatabaseIssueFromError(string $errorText): void {
        $this->errorClassifier->noteDatabaseIssueFromError($errorText);
    }

    /**
     * Source-code-grep target (DatabaseEdgeCasesIntegrationTest).
     *
     * @return string
     */
    public function getPreferredUtf8mb4Collation(): string {
        return $this->collationHelper->getPreferredUtf8mb4Collation();
    }

    // =========================================================================
    // Special-case public surface: methods with local state or lazy init that
    // cannot be a pure pass-through.
    // =========================================================================

    /**
     * Lazy-resolve the Clock instance: explicit setClock wins, then the
     * service container, then a fresh SystemClock.
     *
     * @return ABJ_404_Solution_Clock
     */
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
}
