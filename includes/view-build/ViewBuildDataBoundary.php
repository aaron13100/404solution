<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data and infrastructure boundary for staged view-build collaborators.
 *
 * This object keeps database core, connection, error-classification, logging,
 * functions, and late-bound read/log repositories out of the collaboration
 * context's public surface.
 */
class ABJ_404_Solution_ViewBuildDataBoundary {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;
    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;
    /** @var ABJ_404_Solution_DatabaseConnectionManager */
    private $connectionManager;
    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;
    /** @var ABJ_404_Solution_ViewReadService|null */
    private $viewReadService;
    /** @var ABJ_404_Solution_LogsRepository|null */
    private $logsRepo;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth
     * @param ABJ_404_Solution_DatabaseConnectionManager $connectionManager
     * @param ABJ_404_Solution_DatabaseErrorClassifier $errorClassifier
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $f,
        $logger,
        $rebuildHealth,
        ABJ_404_Solution_DatabaseConnectionManager $connectionManager,
        ABJ_404_Solution_DatabaseErrorClassifier $errorClassifier
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f;
        $this->logger = $logger;
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState ? $rebuildHealth : null;
        $this->connectionManager = $connectionManager;
        $this->errorClassifier = $errorClassifier;
    }

    /** @param ABJ_404_Solution_ViewReadService $viewReadService @return void */
    public function setViewReadService(ABJ_404_Solution_ViewReadService $viewReadService): void {
        $this->viewReadService = $viewReadService;
    }

    /** @param ABJ_404_Solution_LogsRepository $logsRepo @return void */
    public function setLogsRepository(ABJ_404_Solution_LogsRepository $logsRepo): void {
        $this->logsRepo = $logsRepo;
    }

    /** @return ABJ_404_Solution_Functions */
    public function functions() { return $this->f; }

    /** @return ABJ_404_Solution_Logging */
    public function logger() { return $this->logger; }

    /** @return ABJ_404_Solution_RebuildHealthState|null */
    public function rebuildHealth() { return $this->rebuildHealth; }

    /** @return ABJ_404_Solution_ViewReadService */
    public function viewReadService(): ABJ_404_Solution_ViewReadService { return $this->requireViewReadService(); }

    /** @return ABJ_404_Solution_LogsRepository */
    public function logsRepository(): ABJ_404_Solution_LogsRepository { return $this->requireLogsRepo(); }

    /**
     * @param string $query SQL query (may contain {wp_*} table placeholders).
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function queryAndGetResults($query, $options = array()): array { return $this->dbCore->queryAndGetResults($query, $options); }

    /** @param string $query @return string */
    public function doTableNameReplacements($query): string { return $this->dbCore->doTableNameReplacements($query); }

    /** @return string */
    public function getLowercasePrefix(): string { return $this->dbCore->tableNameResolver()->getLowercasePrefix(); }

    /** @return bool True if connection is active, false otherwise. */
    public function ensureConnection(): bool { return $this->connectionManager->ensureConnection(); }

    /** @return ABJ_404_Solution_Clock */
    public function clock(): ABJ_404_Solution_Clock { return $this->dbCore->clock(); }

    /**
     * @param int $stageNumber
     * @param string $errorText
     * @return string 'resumable', 'skip', 'halt', or 'rethrow'.
     */
    public function classifyStageFailure(int $stageNumber, string $errorText): string { return $this->dbCore->classifyStageFailure($stageNumber, $errorText); }

    /** @param string $errorText @return bool */
    public function isResumableStagedKill(string $errorText): bool { return $this->errorClassifier->stagedFailures()->isResumableStagedKill($errorText); }

    /** @param string|null $errorText @return bool */
    public function isTransientConnectionError(?string $errorText): bool { return $this->errorClassifier->taxonomy()->connectivity()->isTransientConnectionError($errorText); }

    /** @param string $tableName @param string $columnName @return string */
    public function getColumnCollationString(string $tableName, string $columnName): string { return $this->dbCore->collationHelper()->getColumnCollationString($tableName, $columnName); }

    /** @return array<string, string> */
    public function viewBuildOnlyTranslations(): array { return $this->requireViewReadService()->viewBuildOnlyTranslations(); }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array { return $this->requireViewReadService()->readFromViewDone($sub, $tableOptions); }

    /** @return array<string, int> */
    public function getViewBuildProgressFingerprint(): array { return $this->requireViewReadService()->getViewBuildProgressFingerprint(); }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string { return $this->requireViewReadService()->buildViewDoneCountQuery($sub, $tableOptions); }

    /** @return bool */
    public function logsHitsTableExists(): bool { return (bool)$this->requireLogsRepo()->logsHitsTableExists(); }

    /** @return ABJ_404_Solution_ViewReadService */
    private function requireViewReadService(): ABJ_404_Solution_ViewReadService {
        if ($this->viewReadService === null) {
            throw new \RuntimeException('ViewBuildDataBoundary requires ViewReadService (call setViewReadService first)');
        }
        return $this->viewReadService;
    }

    /** @return ABJ_404_Solution_LogsRepository */
    private function requireLogsRepo(): ABJ_404_Solution_LogsRepository {
        if ($this->logsRepo === null) {
            throw new \RuntimeException('ViewBuildDataBoundary requires LogsRepository (call setLogsRepository first)');
        }
        return $this->logsRepo;
    }
}
