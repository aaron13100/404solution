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
 *
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
    /** @var ABJ_404_Solution_ViewBuildProgressOptions */
    private $progressOptions;
    /** @var ABJ_404_Solution_ViewBuildOptionWriteVerifier */
    private $optionWriteVerifier;
    /** @var ABJ_404_Solution_ViewBuildPrefixDriftGuard */
    private $prefixDriftGuard;
    /** @var ABJ_404_Solution_ViewBuildStagedSqlExecutor */
    private $stagedSqlExecutor;
    /** @var ABJ_404_Solution_ViewBuildStateProbe */
    private $stateProbe;
    /** @var ABJ_404_Solution_ViewBuildSqlModeProbe */
    private $sqlModeProbe;
    /** @var ABJ_404_Solution_ViewBuildRebuildReconcile */
    private $rebuildReconcile;
    /** @var ABJ_404_Solution_ViewBuildLockCoordinator */
    private $lockCoordinator;
    /** @var ABJ_404_Solution_ViewBuildCronScheduler */
    private $cronScheduler;
    /** @var ABJ_404_Solution_ViewBuildHostEnvironmentProbe */
    private $hostEnvironmentProbe;
    /** @var ABJ_404_Solution_ViewBuildHostFailureNotices */
    private $hostFailureNotices;
    /** @var ABJ_404_Solution_ViewBuildHostFailureState */
    private $hostFailureState;
    /** @var ABJ_404_Solution_ViewBuildHostFailurePolicy */
    private $hostFailurePolicy;
    /** @var ABJ_404_Solution_ViewBuildForceRestart */
    private $forceRestart;
    /** @var ABJ_404_Solution_ViewDoneState */
    private $viewDoneState;
    /** @var ABJ_404_Solution_ViewBuildPageLoadFallback */
    private $pageLoadFallback;
    /** @var ABJ_404_Solution_ViewBuildForegroundLease */
    private $foregroundLease;
    /** @var ABJ_404_Solution_ViewBuildReadGateway */
    private $readGateway;
    /** @var ABJ_404_Solution_ViewBuildAdvanceCoordinator */
    private $advanceCoordinator;
    /** @var ABJ_404_Solution_ViewBuildStagePipeline */
    private $stagePipeline;
    /** @var ABJ_404_Solution_ViewBuildStageRuntimeState */
    private $stageRuntimeState;
    /** @var ABJ_404_Solution_ViewBuildStageMarkers */
    private $stageMarkers;
    /** @var ABJ_404_Solution_ViewBuildShutdownDiagnostics */
    private $shutdownDiagnostics;
    /** @var ABJ_404_Solution_ViewBuildStageLogPresenter */
    private $stageLogPresenter;

    /** @var ABJ_404_Solution_DatabaseConnectionManager */
    private $connectionManager;

    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;

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
        $this->dbCore = $dbCore;
        $this->f = $f !== null ? $f : abj_service('functions');
        $this->logger = $logger !== null ? $logger : abj_service('logging');
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : $this->resolveRebuildHealthState();
        $this->connectionManager = $connectionManager !== null ? $connectionManager : $dbCore->connectionManager();
        $this->errorClassifier = $errorClassifier !== null ? $errorClassifier : $dbCore->errorClassifier();
        $this->optionWriteVerifier = new ABJ_404_Solution_ViewBuildOptionWriteVerifier($this);
        $this->prefixDriftGuard = new ABJ_404_Solution_ViewBuildPrefixDriftGuard($this);
        $this->progressOptions = new ABJ_404_Solution_ViewBuildProgressOptions($this);
        $this->stagedSqlExecutor = new ABJ_404_Solution_ViewBuildStagedSqlExecutor($this);
        $this->stateProbe = new ABJ_404_Solution_ViewBuildStateProbe($this);
        $this->sqlModeProbe = new ABJ_404_Solution_ViewBuildSqlModeProbe($this);
        $this->rebuildReconcile = new ABJ_404_Solution_ViewBuildRebuildReconcile($this);
        $this->lockCoordinator = new ABJ_404_Solution_ViewBuildLockCoordinator($this);
        $this->cronScheduler = new ABJ_404_Solution_ViewBuildCronScheduler($this);
        $this->hostEnvironmentProbe = new ABJ_404_Solution_ViewBuildHostEnvironmentProbe($this);
        $this->hostFailureNotices = new ABJ_404_Solution_ViewBuildHostFailureNotices($this);
        $this->hostFailureState = new ABJ_404_Solution_ViewBuildHostFailureState($this);
        $this->hostFailurePolicy = new ABJ_404_Solution_ViewBuildHostFailurePolicy($this);
        $this->forceRestart = new ABJ_404_Solution_ViewBuildForceRestart($this);
        $this->viewDoneState = new ABJ_404_Solution_ViewDoneState($this);
        $this->pageLoadFallback = new ABJ_404_Solution_ViewBuildPageLoadFallback($this);
        $this->foregroundLease = new ABJ_404_Solution_ViewBuildForegroundLease($this);
        $this->readGateway = new ABJ_404_Solution_ViewBuildReadGateway($this);
        $this->advanceCoordinator = new ABJ_404_Solution_ViewBuildAdvanceCoordinator($this);
        $this->stagePipeline = new ABJ_404_Solution_ViewBuildStagePipeline($this);
        $this->stageRuntimeState = new ABJ_404_Solution_ViewBuildStageRuntimeState();
        $this->stageMarkers = new ABJ_404_Solution_ViewBuildStageMarkers($this, $this->stageRuntimeState);
        $this->shutdownDiagnostics = new ABJ_404_Solution_ViewBuildShutdownDiagnostics($this, $this->stageRuntimeState);
        $this->stageLogPresenter = new ABJ_404_Solution_ViewBuildStageLogPresenter($this, $this->stageRuntimeState);
        // view_done_state is registered BEFORE queries so explicit
        // collaborator routing resolves viewDoneIsServeable / viewDoneBuiltAt
        // / markViewDoneBuildCompleted / invalidateViewDoneServeableCache /
        // getViewDoneBuiltAtTimestamp / viewDoneFreshnessOptionName to the
        // new owning collaborator rather than to the old staged-build shell
        // (where these methods used to live before the i798 extraction).
        $this->collaborators = array(
            'view_done_state' => $this->viewDoneState,
            'page_load_fallback' => $this->pageLoadFallback,
            'foreground_lease' => $this->foregroundLease,
            'read_gateway' => $this->readGateway,
            'advance_coordinator' => $this->advanceCoordinator,
            'stage_pipeline' => $this->stagePipeline,
            'stage_runner' => new ABJ_404_Solution_ViewBuildStageRunner($this),
            'stage_markers' => $this->stageMarkers,
            'shutdown_diagnostics' => $this->shutdownDiagnostics,
            'stage_log_presenter' => $this->stageLogPresenter,
            'batch_executor' => new ABJ_404_Solution_ViewBuildBatchExecutor($this),
            'stage_callbacks' => new ABJ_404_Solution_ViewBuildStageCallbacks($this),
            'adaptive' => new ABJ_404_Solution_ViewBuildAdaptive($this),
            'option_write_verifier' => $this->optionWriteVerifier,
            'prefix_drift_guard' => $this->prefixDriftGuard,
            'progress_options' => $this->progressOptions,
            'staged_sql_executor' => $this->stagedSqlExecutor,
            'state_probe' => $this->stateProbe,
            'sql_mode_probe' => $this->sqlModeProbe,
            'rebuild_reconcile' => $this->rebuildReconcile,
            'lock_coordinator' => $this->lockCoordinator,
            'cron_scheduler' => $this->cronScheduler,
            'host_environment_probe' => $this->hostEnvironmentProbe,
            'host_failure_notices' => $this->hostFailureNotices,
            'host_failure_state' => $this->hostFailureState,
            'host_failure_policy' => $this->hostFailurePolicy,
            'force_restart' => $this->forceRestart,
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

    // --- Public ViewBuildOrchestratorInterface delegation ---

    /** @return void */
    public function claimForegroundViewBuildLease(): void {
        $this->foregroundLease->claimForegroundViewBuildLease();
    }

    /** @param string $sub @param array<string, mixed> $tableOptions @return array<int, array<string, mixed>> */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array {
        return $this->readGateway->runRedirectsForViewStaged($sub, $tableOptions);
    }

    /** @return bool */
    public function viewDoneIsServeable(): bool {
        return $this->viewDoneState->viewDoneIsServeable();
    }

    /** @return int */
    public function getViewDoneBuiltAtTimestamp(): int {
        return $this->viewDoneState->getViewDoneBuiltAtTimestamp();
    }

    /** @return void */
    public function markViewDoneBuildCompleted(): void {
        $this->viewDoneState->markViewDoneBuildCompleted();
    }

    /** @return array<string, mixed> */
    public function getViewBuildProgress(): array {
        return $this->readGateway->getViewBuildProgress();
    }

    /** @param bool $forceRebuild @return array<string, mixed> */
    public function advanceViewBuildOnce(bool $forceRebuild = false): array {
        return $this->advanceCoordinator->advanceViewBuildOnce($forceRebuild);
    }

    /** @return array{ran:bool, reason:string, progress:array<string,mixed>} */
    public function runPageLoadFallbackAdvance(): array {
        return $this->pageLoadFallback->runPageLoadFallbackAdvance();
    }

    /** @param string $sub @param array<string, mixed> $tableOptions @return int */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int {
        return $this->readGateway->runRedirectsForViewCountStaged($sub, $tableOptions);
    }

    /** @return void */
    public function rebuildViewDoneInBackground(): void {
        $this->rebuildReconcile->rebuildViewDoneInBackground();
    }

    /** @return string */
    public function reconcileStagedTablesAtRunnerStartup(): string {
        return $this->rebuildReconcile->reconcileStagedTablesAtRunnerStartup();
    }

    /** @param string $optionName @param mixed $expected @return bool */
    public function verifyOptionWriteCoherent(string $optionName, $expected): bool {
        return $this->optionWriteVerifier->verifyOptionWriteCoherent($optionName, $expected);
    }

    /** @return void */
    public function capturePrefixAtBuildStart(): void {
        $this->prefixDriftGuard->capturePrefixAtBuildStart();
    }

    /** @return bool */
    public function verifyPrefixUnchangedSinceStageOne(): bool {
        return $this->prefixDriftGuard->verifyPrefixUnchangedSinceStageOne();
    }

    /** @return void */
    public function clearPrefixAtStageOne(): void {
        $this->prefixDriftGuard->clearPrefixAtStageOne();
    }

    /** @return array<string, mixed> */
    public function probeSqlModeForBuild(): array {
        return $this->sqlModeProbe->probeSqlModeForBuild();
    }

    /** @return array<string, mixed> */
    public function detectAndAdjustSqlMode(): array {
        return $this->sqlModeProbe->detectAndAdjustSqlMode();
    }

    /** @param string $url @param int $maxLength @return string */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string {
        return $this->stagedSqlExecutor->sanitizeUrlBeforeInsert($url, $maxLength);
    }

    /**
     * @param string $relativePath
     * @param array<string, string> $extraTranslations
     * @return void
     */
    public function runStagedSqlFile(string $relativePath, array $extraTranslations): void {
        $this->stagedSqlExecutor->runStagedSqlFile($relativePath, $extraTranslations);
    }

    /** @return bool */
    public function verifyBuildLockSerializesWriter(): bool {
        return $this->lockCoordinator->verifyBuildLockSerializesWriter();
    }

    /** @param int $delaySeconds @return void */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void {
        $this->cronScheduler->scheduleViewDoneRebuild($delaySeconds);
    }

    /** @return array<string, mixed> */
    public function probePhpEnvironmentForBuild(): array {
        return $this->hostEnvironmentProbe->probePhpEnvironmentForBuild();
    }

    /** @return bool */
    public function probeSetTimeLimitAvailability(): bool {
        return $this->hostEnvironmentProbe->probeSetTimeLimitAvailability();
    }

    /** @return int */
    public function probeMemoryLimitForS9(): int {
        return $this->hostEnvironmentProbe->probeMemoryLimitForS9();
    }

    /** @return array<string, mixed> */
    public function probeFilesystemEnvironmentForBuild(): array {
        return $this->hostEnvironmentProbe->probeFilesystemEnvironmentForBuild();
    }

    /** @return void */
    public function clearStagedBuildDegradedState(): void {
        $this->hostFailureState->clearStagedBuildDegradedState();
    }

    /** @return bool */
    public function reconcilePostStageElevenState(): bool {
        return $this->rebuildReconcile->reconcilePostStageElevenState();
    }

    /** @return array<string, mixed> */
    public function probeSessionVariablesAtS1Entry(): array {
        return $this->hostEnvironmentProbe->probeSessionVariablesAtS1Entry();
    }

    /**
     * Admin mutation entry point: invalidate the cached view snapshot
     * and schedule a rebuild so the next AJAX warmup sees fresh data.
     *
     * Replaces the previous watermark-based gate (removed): admins now
     * rely on the 120s cache TTL + explicit invalidation on mutation
     * rather than a wall-clock or signature gate that blocked reads.
     *
     * @return void
     */
    public function invalidateViewDoneAndScheduleRebuild(): void {
        $this->invalidateViewDoneServeableCacheBridge();
        $this->scheduleViewDoneRebuild();
    }

    /** @param int $lockTimeoutSeconds @return bool */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool {
        return $this->forceRestart->forceRestartViewBuild($lockTimeoutSeconds);
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
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        return $this->invokeViewBuildOperation($name, $arguments);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name) {
        try {
            return $this->viewBuildCollaboratorDependency($name);
        } catch (\BadMethodCallException $e) {
            throw new \RuntimeException('Unknown ViewBuildOrchestrator property: ' . $name, 0, $e);
        }
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, $value): void {
        try {
            $this->setViewBuildCollaboratorState($name, $value);
        } catch (\BadMethodCallException $e) {
            throw new \RuntimeException('Unknown ViewBuildOrchestrator property: ' . $name, 0, $e);
        }
    }

    /**
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function invokeViewBuildOperation(string $name, array $arguments = array()) {
        $publicMethods = array_flip(get_class_methods($this));
        $hostBoundaryMethods = array(
            '__call' => true,
            '__construct' => true,
            '__get' => true,
            '__set' => true,
            'invokeViewBuildOperation' => true,
            'viewBuildCollaboratorDependency' => true,
            'setViewBuildCollaboratorState' => true,
        );
        if (isset($publicMethods[$name]) && !isset($hostBoundaryMethods[$name])) {
            return $this->$name(...$arguments);
        }

        $hostMethods = array(
            'queryAndGetResults' => true,
            'doTableNameReplacements' => true,
            'getLowercasePrefix' => true,
            'ensureConnection' => true,
            'clock' => true,
            'classifyStageFailure' => true,
            'isResumableStagedKill' => true,
            'isTransientConnectionError' => true,
            'getColumnCollationString' => true,
            'viewBuildOnlyTranslations' => true,
            'readFromViewDone' => true,
            'getViewBuildProgressFingerprint' => true,
            'buildViewDoneCountQuery' => true,
            'logsHitsTableExists' => true,
        );
        if (isset($hostMethods[$name])) {
            return call_user_func_array(array($this, $name), $arguments);
        }

        $operationOwners = array(
            'acquireViewBuildLock' => 'lock_coordinator',
            'advanceViewBuildOnce' => 'advance_coordinator',
            'bumpStageNoProgressStreak' => 'progress_options',
            'capturePrefixAtBuildStart' => 'prefix_drift_guard',
            'capturedPrefixForLog' => 'prefix_drift_guard',
            'classifyAndHandleStageFailure' => 'host_failure_policy',
            'clearAllProgressOptions' => 'progress_options',
            'clearPhpEnvironmentProbeCache' => 'host_environment_probe',
            'clearPrefixAtStageOne' => 'prefix_drift_guard',
            'clearSessionVariablesProbeCache' => 'host_environment_probe',
            'clearSqlModeProbeCache' => 'sql_mode_probe',
            'clearStagedBuildDegradedState' => 'host_failure_state',
            'clearViewBuildOpenStageForShutdown' => 'shutdown_diagnostics',
            'clearViewDoneHardStaleNotice' => 'state_probe',
            'countLiveRedirects' => 'batch_executor',
            'countViewBuildRows' => 'batch_executor',
            'describeBuildProgressForNotice' => 'state_probe',
            'dropDeletemeTable' => 'stage_callbacks',
            'dropTransientBuffersIfPresent' => 'stage_callbacks',
            'dropTransientStagedTables' => 'stage_callbacks',
            'extendedTimeoutForKilledNonBatchedStage' => 'adaptive',
            'forceRestartViewBuild' => 'force_restart',
            'foregroundViewBuildLeaseActive' => 'foreground_lease',
            'getCronStuckHours' => 'cron_scheduler',
            'getViewBuildProgress' => 'read_gateway',
            'intelligentStagedQueryTimeoutSeconds' => 'adaptive',
            'detectHostStagedQueryLimitSeconds' => 'adaptive',
            'invalidateViewDoneServeableCache' => 'view_done_state',
            'isBuildHaltedForHostFailure' => 'host_failure_state',
            'isCurrentStageOptionName' => 'option_write_verifier',
            'isStageMarkedSkipped' => 'host_failure_state',
            'localizeOrDefaultViewBuildNotice' => 'state_probe',
            'logTimedViewBuildStage' => 'stage_log_presenter',
            'logViewBuildShutdownDiagnostics' => 'shutdown_diagnostics',
            'markBuildHaltedForHostFailure' => 'host_failure_state',
            'markBuildStage' => 'stage_log_presenter',
            'markStageSkippedForHostFailure' => 'host_failure_state',
            'markViewBuildStageCompleted' => 'stage_markers',
            'markViewBuildStageStarted' => 'stage_markers',
            'markViewDoneBuildCompleted' => 'view_done_state',
            'maybeRaiseViewDoneHardStaleNotice' => 'state_probe',
            'performFreshStartCleanup' => 'progress_options',
            'optionReadBackMatches' => 'option_write_verifier',
            'phpTimeRemainingSeconds' => 'adaptive',
            'prefixAtStageOneOptionName' => 'prefix_drift_guard',
            'probeFilesystemEnvironmentForBuild' => 'host_environment_probe',
            'probePhpEnvironmentForBuild' => 'host_environment_probe',
            'probeSessionVariablesAtS1Entry' => 'host_environment_probe',
            'probeSetTimeLimitAvailability' => 'host_environment_probe',
            'probeSqlModeForBuild' => 'sql_mode_probe',
            'progressOptionName' => 'progress_options',
            'readProgressOption' => 'progress_options',
            'reconcilePostStageElevenState' => 'rebuild_reconcile',
            'reconcileStagedTablesAtRunnerStartup' => 'rebuild_reconcile',
            'recordStageBatchKilled' => 'adaptive',
            'registerViewBuildShutdownDiagnostics' => 'shutdown_diagnostics',
            'releaseViewBuildLock' => 'lock_coordinator',
            'resetStageNoProgressStreak' => 'progress_options',
            'resolveColumnCollationForStagedBuild' => 'staged_sql_executor',
            'runForceRestartCleanupInsideLock' => 'force_restart',
            'runNonBatchedStageWithKillStreakEscape' => 'stage_runner',
            'runS11Swap' => 'staged_sql_executor',
            'runStagedBuildOnce' => 'stage_pipeline',
            'runStagedSqlFile' => 'staged_sql_executor',
            'runStagedSqlFileTolerantOfDuplicateKey' => 'staged_sql_executor',
            'runTimedViewBuildStage' => 'stage_runner',
            'scheduleViewDoneRebuild' => 'cron_scheduler',
            'setStagedBuildDegradedNotice' => 'host_failure_notices',
            'setStagedBuildHaltNotice' => 'host_failure_notices',
            'stageAddPreJoinIndexes' => 'stage_callbacks',
            'stageAddSortIndexes' => 'stage_callbacks',
            'stageCreateBuildTable' => 'stage_callbacks',
            'stageInsertRedirectsBatched' => 'batch_executor',
            'stageRenameSwap' => 'stage_callbacks',
            'stageUpdateExternal' => 'stage_callbacks',
            'stageUpdateHits' => 'stage_callbacks',
            'stageUpdateHome' => 'stage_callbacks',
            'stageUpdatePostsBatched' => 'batch_executor',
            'stageUpdateSpecial' => 'stage_callbacks',
            'stageUpdateTermsBatched' => 'batch_executor',
            'stagedQueryOptions' => 'staged_sql_executor',
            'stagedTableExists' => 'state_probe',
            'stageNoProgressStreakOptionName' => 'progress_options',
            'verifyPrefixUnchangedSinceStageOne' => 'prefix_drift_guard',
            'viewBuildBatchSize' => 'stage_pipeline',
            'viewBuildBatchSizeForStage' => 'adaptive',
            'viewBuildPerStageBudgetSeconds' => 'stage_pipeline',
            'viewBuildTableName' => 'stage_pipeline',
            'viewDeletemeTableName' => 'stage_pipeline',
            'viewDoneBuiltAt' => 'view_done_state',
            'viewDoneDataBuiltAt' => 'state_probe',
            'viewDoneDataBuiltAtOptionName' => 'state_probe',
            'viewDoneFreshnessOptionName' => 'view_done_state',
            'viewDoneHasRows' => 'state_probe',
            'viewDoneIsFresh' => 'state_probe',
            'viewDoneIsServeable' => 'view_done_state',
            'viewDoneTableExists' => 'state_probe',
            'viewDoneTableName' => 'stage_pipeline',
            'writeProgressOption' => 'progress_options',
        );
        if (isset($operationOwners[$name])) {
            $owner = $operationOwners[$name];
            if (!isset($this->collaborators[$owner])) {
                throw new \BadMethodCallException('ViewBuildOrchestrator operation owner not wired: ' . $owner);
            }
            return call_user_func_array(array($this->collaborators[$owner], $name), $arguments);
        }
        throw new \BadMethodCallException('Unknown ViewBuildOrchestrator method: ' . $name);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function viewBuildCollaboratorDependency(string $name) {
        $publicProperties = get_object_vars($this);
        if (array_key_exists($name, $publicProperties)) {
            return $publicProperties[$name];
        }
        if ($name === 'f') {
            return $this->f;
        }
        if ($name === 'logger') {
            return $this->logger;
        }
        if ($name === 'rebuildHealth') {
            return $this->rebuildHealth;
        }
        if ($name === 'stagedQueryTimeoutSeconds') {
            return $this->stagedSqlExecutor->getStagedQueryTimeoutSeconds();
        }
        if ($name === 'sqlModeProbeCache') {
            return $this->sqlModeProbe->getSqlModeProbeCache();
        }
        throw new \BadMethodCallException('Unknown ViewBuildOrchestrator dependency: ' . $name);
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function setViewBuildCollaboratorState(string $name, $value): void {
        if ($name === 'stagedQueryTimeoutSeconds') {
            $this->stagedSqlExecutor->setStagedQueryTimeoutSeconds((int)$value);
            return;
        }
        throw new \BadMethodCallException('Unknown ViewBuildOrchestrator property: ' . $name);
    }

    // --- Bridge methods for ViewReadService (interface contract) ---

    /** @return void */
    public function invalidateViewDoneServeableCacheBridge(): void {
        $this->viewDoneState->invalidateViewDoneServeableCache();
    }

    /** @return array<string, mixed> */
    public function getStagedQueryOptionsForRead(): array {
        return $this->stagedSqlExecutor->stagedQueryOptions();
    }

    /**
     * @param string $shortName
     * @param int $default
     * @return int
     */
    public function readBuildProgressOption(string $shortName, int $default = 0): int {
        return $this->progressOptions->readProgressOption($shortName, $default);
    }

    // --- Internal delegation methods (non-public; reached by collaborators
    //     through invokeViewBuildOperation(), never by external callers).
    //     Marked protected (rather than private) so PHPStan's method.unused
    //     rule stays quiet, because the orchestrator is subclassed by test
    //     doubles. Removes them from the class's public API surface per
    //     design audit M202 (Export Bloat). ---

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function queryAndGetResults($query, $options = array()) {
        return $this->dbCore->queryAndGetResults($query, $options);
    }

    /** @param string $query @return string */
    protected function doTableNameReplacements($query): string {
        return $this->dbCore->doTableNameReplacements($query);
    }

    /** @return string */
    protected function getLowercasePrefix(): string {
        return $this->dbCore->getLowercasePrefix();
    }

    /** @return void */
    protected function ensureConnection(): void {
        $this->connectionManager->ensureConnection();
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
    protected function classifyStageFailure(int $stageNumber, string $errorText): string {
        return $this->dbCore->classifyStageFailure($stageNumber, $errorText);
    }

    /** @param string $errorText @return bool */
    protected function isResumableStagedKill(string $errorText): bool {
        return $this->errorClassifier->isResumableStagedKill($errorText);
    }

    /** @param string|null $errorText @return bool */
    protected function isTransientConnectionError(?string $errorText): bool {
        return $this->errorClassifier->isTransientConnectionError($errorText);
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return string
     */
    protected function getColumnCollationString(string $tableName, string $columnName): string {
        return $this->dbCore->getColumnCollationString($tableName, $columnName);
    }

    // --- Delegation methods for ViewReadService methods the traits call ---

    /** @return array<string, string> */
    protected function viewBuildOnlyTranslations(): array {
        return $this->requireViewReadService()->viewBuildOnlyTranslations();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    protected function readFromViewDone(string $sub, array $tableOptions): array {
        return $this->requireViewReadService()->readFromViewDone($sub, $tableOptions);
    }

    /** @return array<string, int> */
    protected function getViewBuildProgressFingerprint(): array {
        return $this->requireViewReadService()->getViewBuildProgressFingerprint();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    protected function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        return $this->requireViewReadService()->buildViewDoneCountQuery($sub, $tableOptions);
    }

    // --- Delegation for LogsRepository ---

    /** @return bool */
    protected function logsHitsTableExists() {
        return $this->requireLogsRepo()->logsHitsTableExists();
    }
}
