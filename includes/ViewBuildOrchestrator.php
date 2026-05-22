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

    // --- Dependencies (constructor injection) ---

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;

    // --- Setter-injected dependencies (circular reference resolution) ---

    /** @var ABJ_404_Solution_ViewReadService|null */
    private $viewReadService;

    /** @var ABJ_404_Solution_LogsRepository|null */
    private $logsRepo;

    /** @var array<string, ABJ_404_Solution_ViewBuildCollaborator> */
    private $collaborators = array();
    /** @var ABJ_404_Solution_ViewQueriesStaged */
    private $queries;
    /** @var ABJ_404_Solution_ViewBuildHelpers */
    private $helpers;
    /** @var ABJ_404_Solution_ViewBuildLockAndCron */
    private $lockAndCron;
    /** @var ABJ_404_Solution_ViewBuildPhpEnvProbe */
    private $phpEnvProbe;
    /** @var ABJ_404_Solution_ViewBuildSessionEnvProbe */
    private $sessionEnvProbe;
    /** @var ABJ_404_Solution_ViewBuildHostFailurePolicy */
    private $hostFailurePolicy;
    /** @var ABJ_404_Solution_ViewBuildForceRestart */
    private $forceRestart;
    /** @var ABJ_404_Solution_MutationWatermarkSeam */
    private $mutationWatermark;
    /** @var ABJ_404_Solution_AdminMutationGate */
    private $adminMutationGate;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $f Falls back to abj_service('functions')
     * @param ABJ_404_Solution_Logging|null $logger Falls back to abj_service('logging')
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth shared rebuild health gate
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $f = null,
        $logger = null,
        $rebuildHealth = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f !== null ? $f : abj_service('functions');
        $this->logger = $logger !== null ? $logger : abj_service('logging');
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : $this->resolveRebuildHealthState();
        $this->queries = new ABJ_404_Solution_ViewQueriesStaged($this);
        $this->helpers = new ABJ_404_Solution_ViewBuildHelpers($this);
        $this->lockAndCron = new ABJ_404_Solution_ViewBuildLockAndCron($this);
        $this->phpEnvProbe = new ABJ_404_Solution_ViewBuildPhpEnvProbe($this);
        $this->sessionEnvProbe = new ABJ_404_Solution_ViewBuildSessionEnvProbe($this);
        $this->hostFailurePolicy = new ABJ_404_Solution_ViewBuildHostFailurePolicy($this);
        $this->forceRestart = new ABJ_404_Solution_ViewBuildForceRestart($this);
        $this->mutationWatermark = new ABJ_404_Solution_MutationWatermarkSeam($this);
        $this->adminMutationGate = new ABJ_404_Solution_AdminMutationGate($this);
        $this->collaborators = array(
            'queries' => $this->queries,
            'stage_runner' => new ABJ_404_Solution_ViewBuildStageRunner($this),
            'stage_callbacks' => new ABJ_404_Solution_ViewBuildStageCallbacks($this),
            'adaptive' => new ABJ_404_Solution_ViewBuildAdaptive($this),
            'helpers' => $this->helpers,
            'started_watermark' => new ABJ_404_Solution_ViewBuildStartedWatermark($this),
            'lock_and_cron' => $this->lockAndCron,
            'php_env_probe' => $this->phpEnvProbe,
            'session_env_probe' => $this->sessionEnvProbe,
            'host_failure_policy' => $this->hostFailurePolicy,
            'force_restart' => $this->forceRestart,
            'mutation_watermark' => $this->mutationWatermark,
            'admin_mutation_gate' => $this->adminMutationGate,
        );
    }

    /** @return ABJ_404_Solution_RebuildHealthState|null */
    private function resolveRebuildHealthState() {
        if (class_exists('ABJ_404_Solution_ServiceContainer')
                && ABJ_404_Solution_ServiceContainer::safeHas('rebuild_health')) {
            $service = ABJ_404_Solution_ServiceContainer::safeGet('rebuild_health');
            if ($service instanceof ABJ_404_Solution_RebuildHealthState) {
                return $service;
            }
        }
        return null;
    }

    /** @return void */
    public static function resetViewBuildOncePerRequestGuard(): void {
        ABJ_404_Solution_ViewQueriesStaged::resetViewBuildOncePerRequestGuard();
        ABJ_404_Solution_ViewBuildStageRunner::resetViewBuildShutdownLoggerRegistration();
    }

    /** @return void */
    public static function resetViewBuildLockFallbackMemos(): void {
        ABJ_404_Solution_ViewBuildLockAndCron::resetViewBuildLockFallbackMemos();
    }

    // --- Public ViewBuildOrchestratorInterface delegation ---

    /** @return void */
    public function claimForegroundViewBuildLease(): void {
        $this->queries->claimForegroundViewBuildLease();
    }

    /** @param string $sub @param array<string, mixed> $tableOptions @return array<int, array<string, mixed>> */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array {
        return $this->queries->runRedirectsForViewStaged($sub, $tableOptions);
    }

    /** @return bool */
    public function viewDoneIsServeable(): bool {
        return $this->queries->viewDoneIsServeable();
    }

    /** @return int */
    public function getViewDoneBuiltAtTimestamp(): int {
        return $this->queries->getViewDoneBuiltAtTimestamp();
    }

    /** @return void */
    public function markViewDoneBuildCompleted(): void {
        $this->queries->markViewDoneBuildCompleted();
    }

    /** @return array<string, mixed> */
    public function getViewBuildProgress(): array {
        return $this->queries->getViewBuildProgress();
    }

    /** @param bool $forceRebuild @return array<string, mixed> */
    public function advanceViewBuildOnce(bool $forceRebuild = false): array {
        return $this->queries->advanceViewBuildOnce($forceRebuild);
    }

    /** @return array{ran:bool, reason:string, progress:array<string,mixed>} */
    public function runPageLoadFallbackAdvance(): array {
        return $this->queries->runPageLoadFallbackAdvance();
    }

    /** @param string $sub @param array<string, mixed> $tableOptions @return int */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int {
        return $this->queries->runRedirectsForViewCountStaged($sub, $tableOptions);
    }

    /** @return void */
    public function rebuildViewDoneInBackground(): void {
        $this->queries->rebuildViewDoneInBackground();
    }

    /** @return string */
    public function reconcileStagedTablesAtRunnerStartup(): string {
        return $this->queries->reconcileStagedTablesAtRunnerStartup();
    }

    /** @param string $optionName @param mixed $expected @return bool */
    public function verifyOptionWriteCoherent(string $optionName, $expected): bool {
        return $this->helpers->verifyOptionWriteCoherent($optionName, $expected);
    }

    /** @return void */
    public function capturePrefixAtBuildStart(): void {
        $this->helpers->capturePrefixAtBuildStart();
    }

    /** @return bool */
    public function verifyPrefixUnchangedSinceStageOne(): bool {
        return $this->helpers->verifyPrefixUnchangedSinceStageOne();
    }

    /** @return void */
    public function clearPrefixAtStageOne(): void {
        $this->helpers->clearPrefixAtStageOne();
    }

    /** @return array<string, mixed> */
    public function probeSqlModeForBuild(): array {
        return $this->helpers->probeSqlModeForBuild();
    }

    /** @return array<string, mixed> */
    public function detectAndAdjustSqlMode(): array {
        return $this->helpers->detectAndAdjustSqlMode();
    }

    /** @param string $url @param int $maxLength @return string */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string {
        return $this->helpers->sanitizeUrlBeforeInsert($url, $maxLength);
    }

    /** @return bool */
    public function verifyBuildLockSerializesWriter(): bool {
        return $this->lockAndCron->verifyBuildLockSerializesWriter();
    }

    /** @param int $delaySeconds @return void */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void {
        $this->lockAndCron->scheduleViewDoneRebuild($delaySeconds);
    }

    /** @return array<string, mixed> */
    public function probePhpEnvironmentForBuild(): array {
        return $this->phpEnvProbe->probePhpEnvironmentForBuild();
    }

    /** @return bool */
    public function probeSetTimeLimitAvailability(): bool {
        return $this->phpEnvProbe->probeSetTimeLimitAvailability();
    }

    /** @return int */
    public function probeMemoryLimitForS9(): int {
        return $this->phpEnvProbe->probeMemoryLimitForS9();
    }

    /** @return array<string, mixed> */
    public function probeFilesystemEnvironmentForBuild(): array {
        return $this->phpEnvProbe->probeFilesystemEnvironmentForBuild();
    }

    /** @return void */
    public function clearStagedBuildDegradedState(): void {
        $this->hostFailurePolicy->clearStagedBuildDegradedState();
    }

    /** @return bool */
    public function reconcilePostStageElevenState(): bool {
        return $this->hostFailurePolicy->reconcilePostStageElevenState();
    }

    /** @return array<string, mixed> */
    public function probeSessionVariablesAtS1Entry(): array {
        return $this->sessionEnvProbe->probeSessionVariablesAtS1Entry();
    }

    /** @return void */
    public function markViewDoneInvalidatedByAdminMutation(): void {
        $this->adminMutationGate->markViewDoneInvalidatedByAdminMutation();
    }

    /** @param int $lockTimeoutSeconds @return bool */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool {
        return $this->forceRestart->forceRestartViewBuild($lockTimeoutSeconds);
    }

    /** @return int */
    public function bumpMutationWatermark(): int {
        return $this->mutationWatermark->bumpMutationWatermark();
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

    /**
     * Route private cross-collaborator calls through the orchestrator host.
     *
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        if (method_exists($this, $name)) {
            return $this->invokeMethod($this, $name, $arguments);
        }
        return $this->invokeCollaborator($name, $arguments);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name) {
        $publicProperties = get_object_vars($this);
        if (array_key_exists($name, $publicProperties)) {
            return $publicProperties[$name];
        }
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        foreach ($this->collaborators as $collaborator) {
            if (property_exists($collaborator, $name)) {
                $property = new \ReflectionProperty($collaborator, $name);
                return $property->getValue($collaborator);
            }
        }
        throw new \RuntimeException('Unknown ViewBuildOrchestrator property: ' . $name);
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, $value): void {
        $publicProperties = get_object_vars($this);
        if (array_key_exists($name, $publicProperties)) {
            $this->$name = $value;
            return;
        }
        if (property_exists($this, $name)) {
            $this->$name = $value;
            return;
        }
        foreach ($this->collaborators as $collaborator) {
            if (property_exists($collaborator, $name)) {
                $property = new \ReflectionProperty($collaborator, $name);
                $property->setValue($collaborator, $value);
                return;
            }
        }
        throw new \RuntimeException('Unknown ViewBuildOrchestrator property: ' . $name);
    }

    /**
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    private function invokeCollaborator(string $name, array $arguments = array()) {
        foreach ($this->collaborators as $collaborator) {
            if (method_exists($collaborator, $name)) {
                return $this->invokeMethod($collaborator, $name, $arguments);
            }
        }
        throw new \BadMethodCallException('Unknown ViewBuildOrchestrator method: ' . $name);
    }

    /**
     * @param object $target
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    private function invokeMethod($target, string $name, array $arguments) {
        $method = new \ReflectionMethod($target, $name);
        return $method->invokeArgs($target, $arguments);
    }

    // --- Bridge methods for ViewReadService (interface contract) ---

    /** @return void */
    public function invalidateViewDoneServeableCacheBridge(): void {
        $this->queries->invalidateViewDoneServeableCache();
    }

    /** @return array<string, mixed> */
    public function getStagedQueryOptionsForRead(): array {
        return $this->helpers->stagedQueryOptions();
    }

    /**
     * @param string $shortName
     * @param int $default
     * @return int
     */
    public function readBuildProgressOption(string $shortName, int $default = 0): int {
        return $this->helpers->readProgressOption($shortName, $default);
    }

    // --- Delegation methods for external dependencies the traits call via $this-> ---

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function queryAndGetResults($query, $options = array()) {
        return $this->dbCore->queryAndGetResults($query, $options);
    }

    /** @param string $query @return string */
    public function doTableNameReplacements($query): string {
        return $this->dbCore->doTableNameReplacements($query);
    }

    /** @return string */
    public function getLowercasePrefix(): string {
        return $this->dbCore->getLowercasePrefix();
    }

    /** @return void */
    public function ensureConnection(): void {
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
    public function classifyStageFailure(int $stageNumber, string $errorText): string {
        return $this->dbCore->classifyStageFailure($stageNumber, $errorText);
    }

    /** @param string $errorText @return bool */
    public function isResumableStagedKill(string $errorText): bool {
        return $this->dbCore->isResumableStagedKill($errorText);
    }

    /** @param string|null $errorText @return bool */
    public function isTransientConnectionError(?string $errorText): bool {
        return $this->dbCore->isTransientConnectionError($errorText);
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return string
     */
    public function getColumnCollationString(string $tableName, string $columnName): string {
        return $this->dbCore->getColumnCollationString($tableName, $columnName);
    }

    // --- Delegation methods for ViewReadService methods the traits call ---

    /** @return array<string, string> */
    public function viewBuildOnlyTranslations(): array {
        return $this->requireViewReadService()->viewBuildOnlyTranslations();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array {
        return $this->requireViewReadService()->readFromViewDone($sub, $tableOptions);
    }

    /** @return array<string, int> */
    public function getViewBuildProgressFingerprint(): array {
        return $this->requireViewReadService()->getViewBuildProgressFingerprint();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        return $this->requireViewReadService()->buildViewDoneCountQuery($sub, $tableOptions);
    }

    // --- Delegation for LogsRepository ---

    /** @return bool */
    public function logsHitsTableExists() {
        return $this->requireLogsRepo()->logsHitsTableExists();
    }
}
