<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Staged view-build pipeline and mutation watermark system.
 *
 * Extracted from DataAccess in Phase 7 of the DataAccess refactor.
 * Absorbs 13 traits that previously composed into DataAccess.
 *
 * @see docs/dataaccess-refactor-plan.md Phase 7.
 */
class ABJ_404_Solution_ViewBuildOrchestrator implements ABJ_404_Solution_ViewBuildOrchestratorInterface {

    use ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildStageRunnerTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildStageCallbacksTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildAdaptiveTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildHelpersTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildStartedWatermarkTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildLockAndCronTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildPhpEnvProbeTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildSessionEnvProbeTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildHostFailurePolicyTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildForceRestartTrait;
    use ABJ_404_Solution_DataAccess_MutationWatermarkSeamTrait;
    use ABJ_404_Solution_DataAccess_AdminMutationGateTrait;

    // --- Dependencies (constructor injection) ---

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    // --- Setter-injected dependencies (circular reference resolution) ---

    /** @var ABJ_404_Solution_ViewReadService|null */
    private $viewReadService;

    /** @var ABJ_404_Solution_LogsRepository|null */
    private $logsRepo;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $f Falls back to abj_service('functions')
     * @param ABJ_404_Solution_Logging|null $logger Falls back to abj_service('logging')
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $f = null,
        $logger = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f !== null ? $f : abj_service('functions');
        $this->logger = $logger !== null ? $logger : abj_service('logging');
    }

    /**
     * @param ABJ_404_Solution_ViewReadService $viewReadService
     * @return void
     */
    public function setViewReadService(ABJ_404_Solution_ViewReadService $viewReadService): void {
        $this->viewReadService = $viewReadService;
    }

    /**
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @return void
     */
    public function setLogsRepository(ABJ_404_Solution_LogsRepository $logsRepo): void {
        $this->logsRepo = $logsRepo;
    }

    /** @return ABJ_404_Solution_ViewReadService */
    private function requireViewReadService(): ABJ_404_Solution_ViewReadService {
        if ($this->viewReadService === null) {
            throw new \RuntimeException('ViewBuildOrchestrator requires ViewReadService (call setViewReadService first)');
        }
        return $this->viewReadService;
    }

    /** @return ABJ_404_Solution_LogsRepository */
    private function requireLogsRepo(): ABJ_404_Solution_LogsRepository {
        if ($this->logsRepo === null) {
            throw new \RuntimeException('ViewBuildOrchestrator requires LogsRepository (call setLogsRepository first)');
        }
        return $this->logsRepo;
    }

    // --- Bridge methods for ViewReadService (interface contract) ---

    /** @return void */
    public function invalidateViewDoneServeableCacheBridge(): void {
        $this->invalidateViewDoneServeableCache();
    }

    /** @return array<string, mixed> */
    public function getStagedQueryOptionsForRead(): array {
        return $this->stagedQueryOptions();
    }

    /**
     * @param string $shortName
     * @param int $default
     * @return int
     */
    public function readBuildProgressOption(string $shortName, int $default = 0): int {
        return $this->readProgressOption($shortName, $default);
    }

    // --- Delegation methods for external dependencies the traits call via $this-> ---

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function queryAndGetResults($query, $options = array()) {
        return $this->dbCore->queryAndGetResults($query, $options);
    }

    /** @param string $query @return string */
    private function doTableNameReplacements(string $query): string {
        return $this->dbCore->doTableNameReplacements($query);
    }

    /** @return string */
    private function getLowercasePrefix() {
        return $this->dbCore->getLowercasePrefix();
    }

    /** @return void */
    private function ensureConnection(): void {
        $this->dbCore->ensureConnection();
    }

    /** @return ABJ_404_Solution_Clock */
    protected function clock(): ABJ_404_Solution_Clock {
        return $this->dbCore->clock();
    }

    /**
     * @param int $stageNumber
     * @param string $errorText
     * @return string
     */
    private function classifyStageFailure(int $stageNumber, string $errorText): string {
        return $this->dbCore->classifyStageFailure($stageNumber, $errorText);
    }

    /** @param string $errorText @return bool */
    private function isResumableStagedKill(string $errorText): bool {
        return $this->dbCore->isResumableStagedKill($errorText);
    }

    /** @param string|null $errorText @return bool */
    private function isTransientConnectionError(?string $errorText): bool {
        return $this->dbCore->isTransientConnectionError($errorText);
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return string
     */
    private function getColumnCollationString(string $tableName, string $columnName): string {
        return $this->dbCore->getColumnCollationString($tableName, $columnName);
    }

    // --- Delegation methods for ViewReadService methods the traits call ---

    /** @return array<string, string> */
    private function viewBuildOnlyTranslations(): array {
        return $this->requireViewReadService()->viewBuildOnlyTranslations();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    private function readFromViewDone(string $sub, array $tableOptions): array {
        return $this->requireViewReadService()->readFromViewDone($sub, $tableOptions);
    }

    /** @return array<string, int> */
    private function getViewBuildProgressFingerprint(): array {
        return $this->requireViewReadService()->getViewBuildProgressFingerprint();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        return $this->requireViewReadService()->buildViewDoneCountQuery($sub, $tableOptions);
    }

    // --- Delegation for LogsRepository ---

    /** @return bool */
    private function logsHitsTableExists() {
        return $this->requireLogsRepo()->logsHitsTableExists();
    }
}
