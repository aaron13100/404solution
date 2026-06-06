<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public facade for the staged view-build pipeline.
 *
 * Inter-collaborator wiring lives in ViewBuildCollaborationContext so this
 * class exposes only real caller entry points and no string-keyed magic
 * dispatch surface.
 */
class ABJ_404_Solution_ViewBuildOrchestrator implements ABJ_404_Solution_ViewBuildOrchestratorInterface {

    /** @var ABJ_404_Solution_ViewBuildCollaborationContext */
    private $collaborationContext;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $f Falls back to abj_service('functions')
     * @param ABJ_404_Solution_Logging|null $logger Falls back to abj_service('logging')
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth shared rebuild health gate
     * @param ABJ_404_Solution_DatabaseConnectionManager|null $connectionManager Falls back to $dbCore->connectionManager()
     * @param ABJ_404_Solution_DatabaseErrorClassifier|null $errorClassifier Falls back to $dbCore->errorClassifier()
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $f = null,
        $logger = null,
        $rebuildHealth = null,
        $connectionManager = null,
        $errorClassifier = null
    ) {
        $functions = $f !== null ? $f : abj_service('functions');
        $log = $logger !== null ? $logger : abj_service('logging');
        $health = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : $this->resolveRebuildHealthState();
        $this->collaborationContext = new ABJ_404_Solution_ViewBuildCollaborationContext(
            $dbCore,
            $functions,
            $log,
            $health,
            $connectionManager !== null ? $connectionManager : $dbCore->connectionManager(),
            $errorClassifier !== null ? $errorClassifier : $dbCore->errorClassifier()
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
        ABJ_404_Solution_ViewBuildStagePipeline::resetViewBuildOncePerRequestGuard();
        ABJ_404_Solution_ViewBuildShutdownDiagnostics::resetViewBuildShutdownLoggerRegistration();
    }

    /** @return void */
    public static function resetViewBuildLockFallbackMemos(): void {
        ABJ_404_Solution_ViewBuildLockCoordinator::resetViewBuildLockFallbackMemos();
    }

    /** @return void */
    public function claimForegroundViewBuildLease(): void { $this->collaborationContext->recoveryServices()->foregroundLease()->claimForegroundViewBuildLease(); }

    /** @param string $sub @param array<string, mixed> $tableOptions @return array<int, array<string, mixed>> */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array { return $this->collaborationContext->recoveryServices()->readGateway()->runRedirectsForViewStaged($sub, $tableOptions); }

    /** @return bool */
    public function viewDoneIsServeable(): bool { return $this->collaborationContext->stageServices()->viewDoneState()->viewDoneIsServeable(); }

    /** @return int */
    public function getViewDoneBuiltAtTimestamp(): int { return $this->collaborationContext->stageServices()->viewDoneState()->getViewDoneBuiltAtTimestamp(); }

    /** @return void */
    public function markViewDoneBuildCompleted(): void { $this->collaborationContext->stageServices()->viewDoneState()->markViewDoneBuildCompleted(); }

    /** @return array<string, mixed> */
    public function getViewBuildProgress(): array { return $this->collaborationContext->recoveryServices()->readGateway()->getViewBuildProgress(); }

    /** @param bool $forceRebuild @return array<string, mixed> */
    public function advanceViewBuildOnce(bool $forceRebuild = false): array { return $this->collaborationContext->recoveryServices()->advanceCoordinator()->advanceViewBuildOnce($forceRebuild); }

    /** @return array{ran:bool, reason:string, progress:array<string,mixed>} */
    public function runPageLoadFallbackAdvance(): array { return $this->collaborationContext->recoveryServices()->pageLoadFallback()->runPageLoadFallbackAdvance(); }

    /** @param string $sub @param array<string, mixed> $tableOptions @return int */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int { return $this->collaborationContext->recoveryServices()->readGateway()->runRedirectsForViewCountStaged($sub, $tableOptions); }

    /** @return void */
    public function rebuildViewDoneInBackground(): void { $this->collaborationContext->recoveryServices()->rebuildReconcile()->rebuildViewDoneInBackground(); }

    /** @return string */
    public function reconcileStagedTablesAtRunnerStartup(): string { return $this->collaborationContext->recoveryServices()->rebuildReconcile()->reconcileStagedTablesAtRunnerStartup(); }

    /** @param string $optionName @param mixed $expected @return bool */
    public function verifyOptionWriteCoherent(string $optionName, $expected): bool { return $this->collaborationContext->stageServices()->optionWriteVerifier()->verifyOptionWriteCoherent($optionName, $expected); }

    /** @return void */
    public function capturePrefixAtBuildStart(): void { $this->collaborationContext->stageServices()->prefixDriftGuard()->capturePrefixAtBuildStart(); }

    /** @return bool */
    public function verifyPrefixUnchangedSinceStageOne(): bool { return $this->collaborationContext->stageServices()->prefixDriftGuard()->verifyPrefixUnchangedSinceStageOne(); }

    /** @return void */
    public function clearPrefixAtStageOne(): void { $this->collaborationContext->stageServices()->prefixDriftGuard()->clearPrefixAtStageOne(); }

    /** @return array<string, mixed> */
    public function probeSqlModeForBuild(): array { return $this->collaborationContext->stageServices()->sqlModeProbe()->probeSqlModeForBuild(); }

    /** @return array<string, mixed> */
    public function detectAndAdjustSqlMode(): array { return $this->collaborationContext->stageServices()->sqlModeProbe()->detectAndAdjustSqlMode(); }

    /** @param string $url @param int $maxLength @return string */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string { return $this->collaborationContext->stageServices()->stagedSqlExecutor()->sanitizeUrlBeforeInsert($url, $maxLength); }

    /**
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    public function runStagedSqlFile(string $relativePath, array $extraTranslations): void { $this->collaborationContext->stageServices()->stagedSqlExecutor()->runStagedSqlFile($relativePath, $extraTranslations); }

    /** @return bool */
    public function verifyBuildLockSerializesWriter(): bool { return $this->collaborationContext->recoveryServices()->lockCoordinator()->verifyBuildLockSerializesWriter(); }

    /** @param int $delaySeconds @return void */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void { $this->collaborationContext->recoveryServices()->cronScheduler()->scheduleViewDoneRebuild($delaySeconds); }

    /** @return array<string, mixed> */
    public function probePhpEnvironmentForBuild(): array { return $this->collaborationContext->recoveryServices()->hostEnvironmentProbe()->probePhpEnvironmentForBuild(); }

    /** @return bool */
    public function probeSetTimeLimitAvailability(): bool { return $this->collaborationContext->recoveryServices()->hostEnvironmentProbe()->probeSetTimeLimitAvailability(); }

    /** @return int */
    public function probeMemoryLimitForS9(): int { return $this->collaborationContext->recoveryServices()->hostEnvironmentProbe()->probeMemoryLimitForS9(); }

    /** @return array<string, mixed> */
    public function probeFilesystemEnvironmentForBuild(): array { return $this->collaborationContext->recoveryServices()->filesystemEnvironmentProbe()->probeFilesystemEnvironmentForBuild(); }

    /** @return void */
    public function clearStagedBuildDegradedState(): void { $this->collaborationContext->recoveryServices()->hostFailureState()->clearStagedBuildDegradedState(); }

    /** @return bool */
    public function reconcilePostStageElevenState(): bool { return $this->collaborationContext->recoveryServices()->rebuildReconcile()->reconcilePostStageElevenState(); }

    /** @return array<string, mixed> */
    public function probeSessionVariablesAtS1Entry(): array { return $this->collaborationContext->recoveryServices()->sessionVariablesProbe()->probeSessionVariablesAtS1Entry(); }

    /** @return void */
    public function invalidateViewDoneAndScheduleRebuild(): void {
        $this->invalidateViewDoneServeableCacheBridge();
        $this->scheduleViewDoneRebuild();
    }

    /** @param int $lockTimeoutSeconds @return bool */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool { return $this->collaborationContext->recoveryServices()->forceRestart()->forceRestartViewBuild($lockTimeoutSeconds); }

    /** @param ABJ_404_Solution_ViewReadService $viewReadService @return void */
    public function setViewReadService(ABJ_404_Solution_ViewReadService $viewReadService): void { $this->collaborationContext->setViewReadService($viewReadService); }

    /** @param ABJ_404_Solution_LogsRepository $logsRepo @return void */
    public function setLogsRepository(ABJ_404_Solution_LogsRepository $logsRepo): void { $this->collaborationContext->setLogsRepository($logsRepo); }

    /** @return void */
    public function invalidateViewDoneServeableCacheBridge(): void { $this->collaborationContext->stageServices()->viewDoneState()->invalidateViewDoneServeableCache(); }

    /** @return array<string, mixed> */
    public function getStagedQueryOptionsForRead(): array { return $this->collaborationContext->stageServices()->stagedSqlExecutor()->stagedQueryOptions(); }

    /** @param string $shortName @param int $default @return int */
    public function readBuildProgressOption(string $shortName, int $default = 0): int { return $this->collaborationContext->stageServices()->progressOptions()->readProgressOption($shortName, $default); }

    /** @param string $shortName @param int $default @return int */
    public function readProgressOption(string $shortName, int $default = 0): int { return $this->collaborationContext->stageServices()->progressOptions()->readProgressOption($shortName, $default); }

    /** @return void */
    public function releaseViewBuildLock(): void { $this->collaborationContext->recoveryServices()->lockCoordinator()->releaseViewBuildLock(); }

    /** @param int $stageNumber @param string $errorText @return string */
    public function classifyStageFailure(int $stageNumber, string $errorText): string { return $this->collaborationContext->dataBoundary()->classifyStageFailure($stageNumber, $errorText); }

    /** @return string */
    public function viewDoneDataBuiltAtOptionName(): string { return $this->collaborationContext->stageServices()->stateProbe()->viewDoneDataBuiltAtOptionName(); }
    /** @param int $stageNumber @param string $stageKey @param string $errMsg @param float $started @return string */
    public function classifyAndHandleStageFailure(int $stageNumber, string $stageKey, string $errMsg, float $started): string { return $this->collaborationContext->recoveryServices()->hostFailurePolicy()->classifyAndHandleStageFailure($stageNumber, $stageKey, $errMsg, $started); }

    /**
     * Run a single staged-build step under the timed-stage runner. Forwards to
     * the stage runner sub-service so tests and external callers can drive a
     * stage callback without reaching into the collaboration context directly.
     *
     * @param int $stageNumber
     * @param string $stageKey
     * @param callable $callback
     * @return mixed
     */
    public function runTimedViewBuildStage(int $stageNumber, string $stageKey, callable $callback) {
        return $this->collaborationContext->stageServices()->stageRunner()->runTimedViewBuildStage($stageNumber, $stageKey, $callback);
    }

    /** @return void */
    public function clearPhpEnvironmentProbeCache(): void { $this->collaborationContext->recoveryServices()->hostEnvironmentProbe()->clearPhpEnvironmentProbeCache(); }

    /**
     * @param mixed $actual
     * @param mixed $expected
     * @return bool
     */
    public function optionReadBackMatches($actual, $expected): bool { return $this->collaborationContext->stageServices()->optionWriteVerifier()->optionReadBackMatches($actual, $expected); }

    /** @param string $shortName @return string */
    public function progressOptionName(string $shortName): string { return $this->collaborationContext->stageServices()->progressOptions()->progressOptionName($shortName); }

    /** @param string $shortName @param int $value @return void */
    public function writeProgressOption(string $shortName, int $value): void { $this->collaborationContext->stageServices()->progressOptions()->writeProgressOption($shortName, $value); }

    /** @return void */
    public function clearAllProgressOptions(): void { $this->collaborationContext->stageServices()->progressOptions()->clearAllProgressOptions(); }

    /** @return string */
    public function capturedPrefixForLog(): string { return $this->collaborationContext->stageServices()->prefixDriftGuard()->capturedPrefixForLog(); }

    /** @return void */
    public function stageAddPreJoinIndexes(): void { $this->collaborationContext->stageServices()->stageCallbacks()->stageAddPreJoinIndexes(); }

    /** @return void */
    public function stageUpdateHome(): void { $this->collaborationContext->stageServices()->stageCallbacks()->stageUpdateHome(); }

    /** @return void */
    public function stageUpdateExternal(): void { $this->collaborationContext->stageServices()->stageCallbacks()->stageUpdateExternal(); }

    /** @return void */
    public function stageUpdateSpecial(): void { $this->collaborationContext->stageServices()->stageCallbacks()->stageUpdateSpecial(); }

    /** @return void */
    public function stageUpdateHits(): void { $this->collaborationContext->stageServices()->stageCallbacks()->stageUpdateHits(); }

    /** @return void */
    public function stageAddSortIndexes(): void { $this->collaborationContext->stageServices()->stageCallbacks()->stageAddSortIndexes(); }

    /** @return void */
    public function stageRenameSwap(): void { $this->collaborationContext->stageServices()->stageCallbacks()->stageRenameSwap(); }
    /** @return float */
    public function viewBuildPerStageBudgetSeconds(): float { return (float)$this->collaborationContext->stageServices()->stagePipeline()->viewBuildPerStageBudgetSeconds(); }

    /** @param int $stageNumber @return bool */
    public function isStageMarkedSkipped(int $stageNumber): bool { return $this->collaborationContext->recoveryServices()->hostFailureState()->isStageMarkedSkipped($stageNumber); }

    /** @return void */
    public function clearSessionVariablesProbeCache(): void { $this->collaborationContext->recoveryServices()->sessionVariablesProbe()->clearSessionVariablesProbeCache(); }

    /** @param int $stageNumber @return string */
    public function stageNoProgressStreakOptionName(int $stageNumber): string { return $this->collaborationContext->stageServices()->progressOptions()->stageNoProgressStreakOptionName($stageNumber); }
}
