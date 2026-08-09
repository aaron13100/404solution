<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseErrorClassifier.php';
require_once __DIR__ . '/DatabaseRepairPolicy.php';
require_once __DIR__ . '/DatabaseSqlErrorReporter.php';
require_once __DIR__ . '/DatabaseCollationHelper.php';
require_once __DIR__ . '/DatabaseTableRepairer.php';

/**
 * Sub-composition-root for the cohesive "recover and repair from DB failures"
 * cluster: classify a query error, decide whether to repair, run the repair,
 * recover from collation mismatches, and report SQL errors to the debug log.
 *
 * Owns five collaborators that previously lived as separate fields on
 * DatabaseCore. They are grouped here because they share one cohesive
 * concern (what to do when a query fails) and because each one's wiring
 * needs the same back-references (queryAndGetResults, harvest result,
 * runtime flags, plugin notices). Concentrating that wiring in one place
 * shrinks DatabaseCore's field count and centralizes recovery wiring.
 *
 * DatabaseCore exposes each of the five components via its existing
 * per-component accessor methods (errorClassifier(), repairPolicy(),
 * sqlErrorReporter(), collationHelper(), tableRepairer()); those
 * accessors now delegate here. The public contract from
 * DatabaseCoreInterface is unchanged.
 */
class ABJ_404_Solution_DatabaseRecoveryServices {

    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;

    /** @var ABJ_404_Solution_DatabaseRepairPolicy */
    private $repairPolicy;

    /** @var ABJ_404_Solution_DatabaseSqlErrorReporter */
    private $sqlErrorReporter;

    /** @var ABJ_404_Solution_DatabaseCollationHelper */
    private $collationHelper;

    /** @var ABJ_404_Solution_DatabaseTableRepairer */
    private $tableRepairer;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseWpdbResultHarvester $resultHarvester
     * @param ABJ_404_Solution_DatabaseNoticeStateHolder $noticeState
     * @param ABJ_404_Solution_DatabaseQueryExecutor $queryExecutor
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $core,
        $functions,
        $logger,
        ABJ_404_Solution_DatabaseWpdbResultHarvester $resultHarvester,
        ABJ_404_Solution_DatabaseNoticeStateHolder $noticeState,
        ABJ_404_Solution_DatabaseQueryExecutor $queryExecutor
    ) {
        $this->errorClassifier = new ABJ_404_Solution_DatabaseErrorClassifier($core, $functions, $logger);
        $this->repairPolicy = new ABJ_404_Solution_DatabaseRepairPolicy($core, $this->errorClassifier, $functions, $logger);
        $this->sqlErrorReporter = new ABJ_404_Solution_DatabaseSqlErrorReporter($core, $logger);
        $this->collationHelper = new ABJ_404_Solution_DatabaseCollationHelper(
            function (string $query, array $options) use ($core): array {
                return $core->queryAndGetResults($query, $options);
            },
            function (string $tableName) use ($core): string {
                // Call through $core so test-stub subclass overrides of
                // getCreateTableDDL are honored, matching pre-extraction behavior.
                return $core->tableNameResolver()->getCreateTableDDL($tableName);
            },
            function (string $key) use ($noticeState) {
                return $noticeState->getRuntimeFlag($key);
            },
            function (string $key, $value, int $ttl) use ($noticeState): void {
                $noticeState->setRuntimeFlag($key, $value, $ttl);
            },
            $logger
        );
        $this->tableRepairer = new ABJ_404_Solution_DatabaseTableRepairer(
            function (string $query, array $options) use ($core): array {
                return $core->queryAndGetResults($query, $options);
            },
            function (array &$result) use ($resultHarvester): void {
                $resultHarvester->harvestWpdbResult($result);
            },
            function () use ($queryExecutor): string {
                return $queryExecutor->getCurrentResultType();
            },
            function (string $type, string $message, string $guidance, string $errorString) use ($noticeState): void {
                $noticeState->setPluginDbNotice($type, $message, $guidance, $errorString);
            },
            function (string $errorText) use ($core): bool {
                return $core->connectionManager()->resetForRetry($errorText);
            },
            $functions,
            $logger
        );
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

    /** @return ABJ_404_Solution_DatabaseCollationHelper */
    public function collationHelper(): ABJ_404_Solution_DatabaseCollationHelper {
        return $this->collationHelper;
    }

    /** @return ABJ_404_Solution_DatabaseTableRepairer */
    public function tableRepairer(): ABJ_404_Solution_DatabaseTableRepairer {
        return $this->tableRepairer;
    }
}
