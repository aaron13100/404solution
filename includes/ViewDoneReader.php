<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Executes staged view_done row reads.
 */
class ABJ_404_Solution_ViewDoneReader {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewDoneQueryBuilder */
    private $queryBuilder;

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface|null */
    private $viewBuildOrchestrator;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_ViewDoneQueryBuilder $queryBuilder
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, ABJ_404_Solution_ViewDoneQueryBuilder $queryBuilder) {
        $this->dbCore = $dbCore;
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->viewBuildOrchestrator = $viewBuildOrchestrator;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array {
        $query = $this->queryBuilder->buildReadQuery($sub, $tableOptions);
        $result = $this->dbCore->queryAndGetResults($query, $this->requireViewBuildOrchestrator()->getStagedQueryOptionsForRead());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestratorInterface */
    private function requireViewBuildOrchestrator(): ABJ_404_Solution_ViewBuildOrchestratorInterface {
        if ($this->viewBuildOrchestrator === null) {
            throw new \RuntimeException('ViewDoneReader requires ViewBuildOrchestrator (call setViewBuildOrchestrator first)'); // allow-raw-error: assertion, should never reach user
        }
        return $this->viewBuildOrchestrator;
    }
}
