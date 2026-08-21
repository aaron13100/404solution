<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseCoreInterface.php';
require_once __DIR__ . '/DatabaseQueryInterface.php';
require_once __DIR__ . '/DatabaseRuntimeState.php';
require_once __DIR__ . '/DatabaseConnectionManager.php';
require_once __DIR__ . '/DatabaseQueryTimeoutManager.php';
require_once __DIR__ . '/DatabaseInfrastructureErrorTaxonomy.php';
require_once __DIR__ . '/DatabaseErrorTableInspector.php';
require_once __DIR__ . '/DatabasePrefixDiagnostics.php';
require_once __DIR__ . '/DatabaseErrorClassifier.php';
require_once __DIR__ . '/DatabaseRepairPolicy.php';
require_once __DIR__ . '/DatabaseSqlErrorReporter.php';
require_once __DIR__ . '/DatabaseTableNameResolver.php';
require_once __DIR__ . '/DatabaseNoticeStateHolder.php';
require_once __DIR__ . '/DatabaseCollationHelper.php';
require_once __DIR__ . '/DatabaseTableRepairer.php';
require_once __DIR__ . '/DatabaseWpdbResultHarvester.php';
require_once __DIR__ . '/DatabaseQueryDiagnostics.php';
require_once __DIR__ . '/DatabaseTransactionExecutor.php';
require_once __DIR__ . '/DatabaseQueryRecoveryPolicy.php';
require_once __DIR__ . '/DatabaseQueryExecutor.php';
require_once __DIR__ . '/DatabaseRecoveryServices.php';
require_once __DIR__ . '/DatabaseQueryServices.php';

/**
 * Shared database infrastructure: query execution, error recovery, timeouts,
 * connection management, table-name resolution, and error classification.
 *
 * Composition root for the database infrastructure components. Two cohesive
 * sub-composition-roots own the bulk of the collaborator graph:
 *
 *   - DatabaseRecoveryServices: error classifier, repair policy, sql error
 *     reporter, collation helper, table repairer (the "what to do when a
 *     query fails" cluster).
 *   - DatabaseQueryServices: query executor, query timeout manager, query
 *     recovery policy, result harvester, query diagnostics, transaction
 *     executor (the "run a SQL query and surface its result" cluster).
 *
 * DatabaseCore retains direct ownership of the three infrastructure
 * collaborators that don't fit either cluster: connection manager (the
 * dbh lifecycle), table name resolver (DDL/prefix queries), and notice
 * state holder (admin-notice + runtime-flag bookkeeping).
 *
 * Public surface:
 *   - Query-interface methods (DatabaseQueryInterface) for callers that need
 *     the centralized query pipeline. Each is a one-line delegate to the
 *     relevant component.
 *   - Core-interface methods (DatabaseCoreInterface) for callers that need
 *     database component accessors.
 *   - Component accessor methods (connectionManager(), errorClassifier(),
 *     etc.) for DAO-internal callers that need non-interface behavior.
 *   - Lazy clock() resolver and the two static SET STATEMENT wrapper
 *     helpers that own per-request state.
 *
 * There is no __call() dispatch and no non-interface delegate surface:
 * every component method is reached through its component accessor
 * (e.g. errorClassifier()->taxonomy()->connectivity()->isTransientConnectionError(), not
 * DatabaseCore::isTransientConnectionError()). The previous explicit
 * delegate section was removed in i812; see design-audit-2026-06-02.md
 * M202.
 */
class ABJ_404_Solution_DatabaseCore implements
    ABJ_404_Solution_DatabaseCoreInterface,
    ABJ_404_Solution_DatabaseQueryInterface {

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

    /** @var ABJ_404_Solution_DatabaseTableNameResolver */
    private $tableNameResolver;

    /** @var ABJ_404_Solution_DatabaseNoticeStateHolder */
    private $noticeState;

    /** @var ABJ_404_Solution_DatabaseRecoveryServices */
    private $recoveryServices;

    /** @var ABJ_404_Solution_DatabaseQueryServices */
    private $queryServices;

    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct($functions = null, $logging = null) {
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->connectionManager = new ABJ_404_Solution_DatabaseConnectionManager($this, $this->logger);
        $this->queryServices = new ABJ_404_Solution_DatabaseQueryServices($this, $this->logger);
        $this->tableNameResolver = new ABJ_404_Solution_DatabaseTableNameResolver(
            $this->f,
            function (string $query, array $options): array {
                return $this->queryAndGetResults($query, $options);
            },
            $this->logger
        );
        $this->noticeState = new ABJ_404_Solution_DatabaseNoticeStateHolder(
            function (): bool {
                // Deferred lookup: recoveryServices is assigned below.
                return $this->recoveryServices->errorClassifier()->isQuotaCooldownActive();
            }
        );
        $this->recoveryServices = new ABJ_404_Solution_DatabaseRecoveryServices(
            $this,
            $this->f,
            $this->logger,
            $this->queryServices->resultHarvester(),
            $this->noticeState,
            $this->queryServices->queryExecutor()
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

    /** @return ABJ_404_Solution_DatabaseQueryServices */
    public function queryServices(): ABJ_404_Solution_DatabaseQueryServices {
        return $this->queryServices;
    }

    /** @return ABJ_404_Solution_DatabaseQueryTimeoutManager */
    public function queryTimeoutManager(): ABJ_404_Solution_DatabaseQueryTimeoutManager {
        return $this->queryServices->queryTimeoutManager();
    }

    /** @return ABJ_404_Solution_DatabaseRecoveryServices */
    public function recoveryServices(): ABJ_404_Solution_DatabaseRecoveryServices {
        return $this->recoveryServices;
    }

    /** @return ABJ_404_Solution_DatabaseErrorClassifier */
    public function errorClassifier(): ABJ_404_Solution_DatabaseErrorClassifier {
        return $this->recoveryServices->errorClassifier();
    }

    /** @return ABJ_404_Solution_DatabaseRepairPolicy */
    public function repairPolicy(): ABJ_404_Solution_DatabaseRepairPolicy {
        return $this->recoveryServices->repairPolicy();
    }

    /** @return ABJ_404_Solution_DatabaseSqlErrorReporter */
    public function sqlErrorReporter(): ABJ_404_Solution_DatabaseSqlErrorReporter {
        return $this->recoveryServices->sqlErrorReporter();
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
        return $this->recoveryServices->collationHelper();
    }

    /** @return ABJ_404_Solution_DatabaseTableRepairer */
    public function tableRepairer(): ABJ_404_Solution_DatabaseTableRepairer {
        return $this->recoveryServices->tableRepairer();
    }

    /** @return ABJ_404_Solution_DatabaseWpdbResultHarvester */
    public function resultHarvester(): ABJ_404_Solution_DatabaseWpdbResultHarvester {
        return $this->queryServices->resultHarvester();
    }

    /** @return ABJ_404_Solution_DatabaseQueryDiagnostics */
    public function queryDiagnostics(): ABJ_404_Solution_DatabaseQueryDiagnostics {
        return $this->queryServices->queryDiagnostics();
    }

    /** @return ABJ_404_Solution_DatabaseTransactionExecutor */
    public function transactionExecutor(): ABJ_404_Solution_DatabaseTransactionExecutor {
        return $this->queryServices->transactionExecutor();
    }

    /** @return ABJ_404_Solution_DatabaseQueryRecoveryPolicy */
    public function queryRecoveryPolicy(): ABJ_404_Solution_DatabaseQueryRecoveryPolicy {
        return $this->queryServices->queryRecoveryPolicy();
    }

    /** @return ABJ_404_Solution_DatabaseQueryExecutor */
    public function queryExecutor(): ABJ_404_Solution_DatabaseQueryExecutor {
        return $this->queryServices->queryExecutor();
    }

    // =========================================================================
    // Interface-required methods (DatabaseQueryInterface). These remain
    // explicit so PHP's type system sees the contract.
    // =========================================================================

    /** @inheritDoc */
    public function queryAndGetResults($query, $options = array()): array {
        return $this->queryServices->queryExecutor()->queryAndGetResults($query, $options);
    }

    /** @inheritDoc */
    public function queryScalarInt($query, $options = array()): int {
        return $this->queryServices->queryExecutor()->queryScalarInt($query, $options);
    }

    /** @inheritDoc */
    public function doTableNameReplacements($query): string {
        return $this->tableNameResolver->doTableNameReplacements($query);
    }

    /** @inheritDoc */
    public function executeAsTransaction(array $statementArray): void {
        try {
            $this->queryServices->transactionExecutor()->executeAsTransaction($statementArray);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * Retry a query after the server rejects the MariaDB SET STATEMENT timeout wrapper.
     *
     * @param string $query Passed by reference; mutated to the unwrapped query on success.
     * @param array<string, mixed> $result Passed by reference; updated with retry result fields.
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType wpdb output type for get_results().
     * @return void
     */
    public function retryWithoutSetStatementWrapper(string &$query, array &$result, string $resultType): void {
        $this->queryServices->queryTimeoutManager()->retryWithoutSetStatementWrapper($query, $result, $resultType);
    }

    // =========================================================================
    // Special-case public surface: methods with local state or lazy init that
    // cannot be a pure pass-through.
    // =========================================================================

    /**
     * Lazy-resolve the Clock instance: previously cached value wins, then the
     * service container (the standard test-injection seam per
     * clock_injection_pattern.md), then a fresh SystemClock.
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
     * Update the request-local "SET STATEMENT wrapper unsupported" cache.
     * Confirmed rejection is also transient-backed across requests.
     *
     * @param bool $value
     * @return void
     */
    public static function setSetStatementWrapperUnsupported(bool $value): void {
        ABJ_404_Solution_DatabaseRuntimeState::setSetStatementWrapperUnsupported($value);
    }

    /** @return bool */
    public static function isSetStatementWrapperUnsupported(): bool {
        return ABJ_404_Solution_DatabaseRuntimeState::isSetStatementWrapperUnsupported();
    }
}
