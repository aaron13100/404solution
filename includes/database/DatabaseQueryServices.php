<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseQueryTimeoutManager.php';
require_once __DIR__ . '/DatabaseWpdbResultHarvester.php';
require_once __DIR__ . '/DatabaseQueryDiagnostics.php';
require_once __DIR__ . '/DatabaseQueryRecoveryPolicy.php';
require_once __DIR__ . '/DatabaseQueryExecutor.php';
require_once __DIR__ . '/DatabaseTransactionExecutor.php';

/**
 * Sub-composition-root for the cohesive "execute a SQL query and surface its
 * result" cluster: queryExecutor (the pipeline), queryTimeoutManager (SET
 * STATEMENT wrapping), queryRecoveryPolicy (retry-after-timeout-fallback),
 * resultHarvester (wpdb output normalization), queryDiagnostics (sql-source
 * extraction), and transactionExecutor (multi-statement transactions).
 *
 * Owns six collaborators that previously lived as separate fields on
 * DatabaseCore. They are grouped here because each is part of the query
 * pipeline (build SQL, apply timeout, run, harvest result, dispatch
 * recovery, normalize output) and because every one of them needs the
 * same DatabaseCore back-reference plus the Logging dependency.
 * Concentrating their wiring shrinks DatabaseCore's collaborator-field
 * count from 10 to 5.
 *
 * DatabaseCore exposes each of the six components via its existing
 * per-component accessor methods (queryExecutor(), queryTimeoutManager(),
 * queryRecoveryPolicy(), resultHarvester(), queryDiagnostics(),
 * transactionExecutor()); those accessors now delegate here. The public
 * contract from DatabaseQueryInterface is unchanged.
 */
class ABJ_404_Solution_DatabaseQueryServices {

    /** @var ABJ_404_Solution_DatabaseQueryTimeoutManager */
    private $queryTimeoutManager;

    /** @var ABJ_404_Solution_DatabaseWpdbResultHarvester */
    private $resultHarvester;

    /** @var ABJ_404_Solution_DatabaseQueryDiagnostics */
    private $queryDiagnostics;

    /** @var ABJ_404_Solution_DatabaseQueryRecoveryPolicy */
    private $queryRecoveryPolicy;

    /** @var ABJ_404_Solution_DatabaseQueryExecutor */
    private $queryExecutor;

    /** @var ABJ_404_Solution_DatabaseTransactionExecutor */
    private $transactionExecutor;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $core,
        $logger
    ) {
        $this->queryTimeoutManager = new ABJ_404_Solution_DatabaseQueryTimeoutManager($core, $logger);
        $this->resultHarvester = new ABJ_404_Solution_DatabaseWpdbResultHarvester();
        $this->queryDiagnostics = new ABJ_404_Solution_DatabaseQueryDiagnostics($logger);
        $this->queryRecoveryPolicy = new ABJ_404_Solution_DatabaseQueryRecoveryPolicy(
            $core,
            $logger,
            $this->resultHarvester,
            $this->queryDiagnostics
        );
        $this->queryExecutor = new ABJ_404_Solution_DatabaseQueryExecutor(
            $core,
            $logger,
            $this->resultHarvester,
            $this->queryDiagnostics,
            $this->queryRecoveryPolicy
        );
        $this->transactionExecutor = new ABJ_404_Solution_DatabaseTransactionExecutor($core, $logger);
    }

    /** @return ABJ_404_Solution_DatabaseQueryTimeoutManager */
    public function queryTimeoutManager(): ABJ_404_Solution_DatabaseQueryTimeoutManager {
        return $this->queryTimeoutManager;
    }

    /** @return ABJ_404_Solution_DatabaseWpdbResultHarvester */
    public function resultHarvester(): ABJ_404_Solution_DatabaseWpdbResultHarvester {
        return $this->resultHarvester;
    }

    /** @return ABJ_404_Solution_DatabaseQueryDiagnostics */
    public function queryDiagnostics(): ABJ_404_Solution_DatabaseQueryDiagnostics {
        return $this->queryDiagnostics;
    }

    /** @return ABJ_404_Solution_DatabaseQueryRecoveryPolicy */
    public function queryRecoveryPolicy(): ABJ_404_Solution_DatabaseQueryRecoveryPolicy {
        return $this->queryRecoveryPolicy;
    }

    /** @return ABJ_404_Solution_DatabaseQueryExecutor */
    public function queryExecutor(): ABJ_404_Solution_DatabaseQueryExecutor {
        return $this->queryExecutor;
    }

    /** @return ABJ_404_Solution_DatabaseTransactionExecutor */
    public function transactionExecutor(): ABJ_404_Solution_DatabaseTransactionExecutor {
        return $this->transactionExecutor;
    }
}
