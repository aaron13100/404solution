<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct owner of the admin view snapshot rebuild lifecycle.
 *
 * The public surface is intentionally stable: AJAX, cron, admin mutation
 * handlers, and ViewReadService still call the same methods. Internally the
 * work is split across four collaborators:
 *
 *   - ViewBuildTableNames: physical table + prefixed option name resolution.
 *   - ViewBuildWriterLock: GET_LOCK (or option-fallback) writer serialization.
 *   - ViewDoneRebuildExecutor: the staged SQL rebuild into view_done.
 *   - ViewDoneFreshnessState: serveability/freshness state + progress shape.
 *
 * This class decides *when* a rebuild happens (lock, health gate, once-per-
 * request guard, scheduling) and serves reads; the collaborators own *how*.
 */
class ABJ_404_Solution_ViewBuildOrchestrator implements ABJ_404_Solution_ViewBuildOrchestratorInterface {

    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;
    /** @var ABJ_404_Solution_ViewReadService|null */
    private $viewReadService;
    /** @var ABJ_404_Solution_ViewBuildTableNames */
    private $tableNames;
    /** @var ABJ_404_Solution_ViewBuildWriterLock */
    private $lock;
    /** @var ABJ_404_Solution_ViewDoneRebuildExecutor */
    private $rebuildExecutor;
    /** @var ABJ_404_Solution_ViewDoneFreshnessState */
    private $freshness;
    /** @var int */
    private $stagedQueryTimeoutSeconds = 0;
    /** @var bool */
    private static $viewBuildAlreadyRanThisRequest = false;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $f Falls back to abj_service('functions')
     * @param ABJ_404_Solution_Logging|null $logger Falls back to abj_service('logging')
     * @param ABJ_404_Solution_RebuildHealthState|null $rebuildHealth shared rebuild health gate
     * @param ABJ_404_Solution_DatabaseConnectionManager|null $connectionManager retained for constructor compatibility
     * @param ABJ_404_Solution_DatabaseErrorClassifier|null $errorClassifier retained for constructor compatibility
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $f = null,
        $logger = null,
        $rebuildHealth = null,
        $connectionManager = null,
        $errorClassifier = null
    ) {
        unset($connectionManager, $errorClassifier);
        $this->f = $f instanceof ABJ_404_Solution_Functions ? $f : abj_service('functions');
        $this->logger = $logger instanceof ABJ_404_Solution_Logging ? $logger : abj_service('logging');
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : $this->resolveRebuildHealthState();

        $this->tableNames = new ABJ_404_Solution_ViewBuildTableNames($dbCore);
        $this->lock = new ABJ_404_Solution_ViewBuildWriterLock($dbCore, $this->tableNames);
        $this->rebuildExecutor = new ABJ_404_Solution_ViewDoneRebuildExecutor($dbCore, $this->f, $this->logger, $this->tableNames);
        $this->freshness = new ABJ_404_Solution_ViewDoneFreshnessState($dbCore, $this->tableNames);
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
        self::$viewBuildAlreadyRanThisRequest = false;
    }

    /** @return void */
    public static function resetViewBuildLockFallbackMemos(): void {
        ABJ_404_Solution_ViewBuildWriterLock::resetFallbackMemos();
    }

    /** @return void */
    public function claimForegroundViewBuildLease(): void {
        if (function_exists('update_option')) {
            update_option($this->tableNames->prefixedOption('abj404_view_build_foreground_until'),
                abj_clock()->now() + ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_FOREGROUND_LEASE_SECONDS, false);
        }
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array {
        $this->setReadQueryTimeout($tableOptions);
        // Denorm Step 3b: reads serve straight off wp_abj404_redirects with the
        // four derived columns resolved live per visible row. The single-table
        // read is always serveable (it needs no staged view_done materialization
        // and works even when the derived columns are still empty right after
        // upgrade), so there is no pending state and no read-time rebuild gate.
        return $this->requireViewReadService()->readRedirectsSingleTable($sub, $tableOptions);
    }

    /** @return bool */
    public function viewDoneIsServeable(): bool {
        return $this->freshness->isServeable();
    }

    /** @return bool */
    public function viewDoneIsFresh(): bool {
        return $this->freshness->isFresh();
    }

    /** @return int */
    public function getViewDoneBuiltAtTimestamp(): int {
        return $this->freshness->builtAtTimestamp();
    }

    /** @return void */
    public function markViewDoneBuildCompleted(): void {
        $this->freshness->markBuildCompleted();
    }

    /** @return array<string, mixed> */
    public function getViewBuildProgress(): array {
        return $this->freshness->getProgress();
    }

    /**
     * @param bool $forceRebuild
     * @return array<string, mixed>
     */
    public function advanceViewBuildOnce(bool $forceRebuild = false): array {
        if ($forceRebuild) {
            self::$viewBuildAlreadyRanThisRequest = false;
            if ($this->rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState) {
                $this->rebuildHealth->reset();
                $this->rebuildHealth->acquireTrialToken();
            }
        } elseif ($this->rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
                && !$this->rebuildHealth->beginExpensiveRebuildAttempt()) {
            $this->logger->debugMessage('advanceViewBuildOnce skipped because rebuild health gate is closed.');
            return array('status' => 'paused', 'locked' => false, 'healthGateClosed' => true, 'stage' => 0, 'of' => 1);
        }

        if (!$forceRebuild && self::$viewBuildAlreadyRanThisRequest) {
            return $this->getViewBuildProgress();
        }
        if (!$forceRebuild && $this->freshness->isFresh() && $this->freshness->isServeable()) {
            return $this->getViewBuildProgress();
        }

        if (!$this->lock->acquire($forceRebuild ? 10 : 0)) {
            $progress = $this->freshness->formatProgress('pending', 'locked');
            $progress['locked'] = true;
            return $progress;
        }

        self::$viewBuildAlreadyRanThisRequest = true;
        try {
            if ($forceRebuild) {
                $this->runForceRestartCleanupInsideLock();
            }
            $this->rebuildExecutor->run();
            $this->markViewDoneBuildCompleted();
        } finally {
            $this->lock->release();
        }

        $progress = $this->freshness->formatProgress('ready', 'rebuilt');
        $progress['locked'] = false;
        return $progress;
    }

    /** @return array{ran:bool, reason:string, progress:array<string,mixed>} */
    public function runPageLoadFallbackAdvance(): array {
        if ($this->freshness->isServeable()) {
            return array('ran' => false, 'reason' => 'already_ready', 'progress' => $this->getViewBuildProgress());
        }
        $progress = $this->advanceViewBuildOnce(false);
        $reason = isset($progress['progress_text']) && is_scalar($progress['progress_text'])
            ? (string)$progress['progress_text'] : '';
        return array('ran' => $progress['status'] === 'ready', 'reason' => $reason, 'progress' => $progress);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int {
        $this->setReadQueryTimeout($tableOptions);
        // Denorm Step 3b: filtered counts run single-table against
        // wp_abj404_redirects with the same WHERE the read uses, so a filtered
        // count always equals the unpaginated row set. No staged gate.
        return $this->requireViewReadService()->countRedirectsSingleTable($sub, $tableOptions);
    }

    /** @return void */
    public function rebuildViewDoneInBackground(): void {
        $this->advanceViewBuildOnce(false);
    }

    /** @return void */
    public function syncViewDoneWithSource(): void {
        $this->invalidateViewDoneAndScheduleRebuild();
    }

    /** @return string */
    public function reconcileStagedTablesAtRunnerStartup(): string {
        if ($this->tableNames->tableExists($this->tableNames->viewBuild())
                && !$this->tableNames->tableExists($this->tableNames->viewDone())) {
            $this->rebuildExecutor->renameBuildToDone();
            $this->markViewDoneBuildCompleted();
            return 'promoted';
        }
        if ($this->tableNames->tableExists($this->tableNames->viewDeleteme())) {
            $this->rebuildExecutor->dropStaleViewDeleteme();
            return 'dropped_deleteme';
        }
        return 'none';
    }

    /**
     * @param string $optionName
     * @param mixed $expected
     * @return bool
     */
    public function verifyOptionWriteCoherent(string $optionName, $expected): bool {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            return false;
        }
        update_option($optionName, $expected, false);
        return get_option($optionName, null) === $expected;
    }

    /** @return void */
    public function capturePrefixAtBuildStart(): void {
        if (function_exists('update_option')) {
            update_option($this->tableNames->prefixedOption('abj404_view_build_prefix_at_s1'), $this->tableNames->prefix(), false);
        }
    }

    /** @return bool */
    public function verifyPrefixUnchangedSinceStageOne(): bool {
        if (!function_exists('get_option')) {
            return true;
        }
        $captured = get_option($this->tableNames->prefixedOption('abj404_view_build_prefix_at_s1'), '');
        return $captured === '' || $captured === $this->tableNames->prefix();
    }

    /** @return void */
    public function clearPrefixAtStageOne(): void {
        if (function_exists('delete_option')) {
            delete_option($this->tableNames->prefixedOption('abj404_view_build_prefix_at_s1'));
        }
    }

    /** @return array<string, mixed> */
    public function probeSqlModeForBuild(): array {
        return array('truncate_url_to' => 2048, 'status' => 'not_probed');
    }

    /** @return array<string, mixed> */
    public function detectAndAdjustSqlMode(): array {
        return $this->probeSqlModeForBuild();
    }

    /** @param string $url @param int $maxLength @return string */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $url);
        if (!is_string($clean)) {
            $clean = $url;
        }
        $limit = $maxLength > 0 ? $maxLength : 2048;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($clean) > $limit ? mb_substr($clean, 0, $limit) : $clean;
        }
        return strlen($clean) > $limit ? substr($clean, 0, $limit) : $clean;
    }

    /** @return bool */
    public function verifyBuildLockSerializesWriter(): bool {
        return $this->lock->verifySerializesWriter();
    }

    /** @param int $delaySeconds @return void */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void {
        if ($this->rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
                && !$this->rebuildHealth->mayStartExpensiveRebuild()) {
            $this->logger->debugMessage(__FUNCTION__ . ' skipped because rebuild health gate is closed.');
            return;
        }
        $scheduled = abj_cron_scheduler()->scheduleSingleIfMissing(
            ABJ_404_Solution_CronScheduler::HOOK_REBUILD_VIEW_DONE,
            max(1, intval($delaySeconds))
        );
        if (!$scheduled && function_exists('set_transient')) {
            // allow-cache-empty: schedule failure notice is a fixed non-empty diagnostic payload.
            set_transient('abj404_view_build_cron_schedule_failed', array(
                'type' => 'view_build_schedule_failed',
                'message' => 'Scheduling the 404 Solution view rebuild cron event failed. ' . abj_cron_scheduler()->lastFailureDetail(),
                'timestamp' => abj_cron_scheduler()->now(),
                'error_string' => abj_cron_scheduler()->lastFailureDetail(),
            ), ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS);
        }
    }

    /** @return array<string, mixed> */
    public function probePhpEnvironmentForBuild(): array {
        return array('status' => 'not_probed');
    }

    /** @return bool */
    public function probeSetTimeLimitAvailability(): bool {
        return function_exists('set_time_limit');
    }

    /** @return int */
    public function probeMemoryLimitForS9(): int {
        return 0;
    }

    /** @return array<string, mixed> */
    public function probeFilesystemEnvironmentForBuild(): array {
        return array('status' => 'not_probed');
    }

    /** @return void */
    public function clearStagedBuildDegradedState(): void {
        if (function_exists('delete_transient')) {
            delete_transient('abj404_view_build_get_lock_unsupported_notice');
            delete_transient('abj404_view_build_cron_schedule_failed');
        }
    }

    /** @return bool */
    public function reconcilePostStageElevenState(): bool {
        return $this->tableNames->tableExists($this->tableNames->viewDone());
    }

    /** @return array<string, mixed> */
    public function probeSessionVariablesAtS1Entry(): array {
        return array('status' => 'not_probed');
    }

    /** @return void */
    public function invalidateViewDoneAndScheduleRebuild(): void {
        if (function_exists('delete_option')) {
            delete_option($this->tableNames->viewDoneFreshnessOption());
        }
        $this->freshness->invalidateCache();
        $this->scheduleViewDoneRebuild();
    }

    /** @param int $lockTimeoutSeconds @return bool */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool {
        if ($this->rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState) {
            $this->rebuildHealth->reset();
            $this->rebuildHealth->acquireTrialToken();
        }
        if (!$this->lock->acquire(max(0, $lockTimeoutSeconds))) {
            return false;
        }
        try {
            $this->runForceRestartCleanupInsideLock();
        } finally {
            $this->lock->release();
        }
        $this->scheduleViewDoneRebuild();
        return true;
    }

    /** @param ABJ_404_Solution_ViewReadService $viewReadService @return void */
    public function setViewReadService(ABJ_404_Solution_ViewReadService $viewReadService): void {
        $this->viewReadService = $viewReadService;
    }

    /** @param ABJ_404_Solution_LogsRepository $logsRepo @return void */
    public function setLogsRepository(ABJ_404_Solution_LogsRepository $logsRepo): void {
        $this->rebuildExecutor->setLogsRepository($logsRepo);
    }

    /** @return void */
    public function invalidateViewDoneServeableCacheBridge(): void {
        $this->freshness->invalidateCache();
    }

    /** @return array<string, mixed> */
    public function getStagedQueryOptionsForRead(): array {
        return $this->stagedQueryTimeoutSeconds > 0 ? array('timeout' => $this->stagedQueryTimeoutSeconds) : array();
    }

    /** @param string $shortName @param int $default @return int */
    public function readBuildProgressOption(string $shortName, int $default = 0): int {
        if (!function_exists('get_option')) {
            return $default;
        }
        $value = get_option($this->tableNames->prefixedOption('abj404_view_build_' . $shortName), $default);
        return is_scalar($value) ? intval($value) : $default;
    }

    /**
     * Legacy diagnostic seam retained for old tests. Direct rebuilds do not
     * have timed sub-stage wrappers, so this simply executes the callback.
     *
     * @param int $stageNumber
     * @param string $stageKey
     * @param callable $callback
     * @return mixed
     */
    public function runTimedViewBuildStage(int $stageNumber, string $stageKey, callable $callback) {
        return $callback();
    }

    /** @return void */
    private function runForceRestartCleanupInsideLock(): void {
        $this->rebuildExecutor->dropTransientBuildTables();
        $this->rebuildExecutor->clearBuildProgressOptions();
        if (function_exists('delete_option')) {
            delete_option($this->tableNames->viewDoneFreshnessOption());
        }
        $this->freshness->invalidateCache();
    }

    /** @param array<string, mixed> $tableOptions @return void */
    private function setReadQueryTimeout(array $tableOptions): void {
        $this->stagedQueryTimeoutSeconds = isset($tableOptions['_abj404_query_timeout'])
            && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(0, intval($tableOptions['_abj404_query_timeout'])) : 0;
    }

    /** @return ABJ_404_Solution_ViewReadService */
    private function requireViewReadService(): ABJ_404_Solution_ViewReadService {
        if (!$this->viewReadService instanceof ABJ_404_Solution_ViewReadService) {
            throw new \RuntimeException('ViewBuildOrchestrator requires ViewReadService (call setViewReadService first)'); // allow-raw-error: assertion, should never reach user
        }
        return $this->viewReadService;
    }
}
