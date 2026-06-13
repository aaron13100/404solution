<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct owner of the admin view snapshot rebuild lifecycle.
 *
 * The public surface is intentionally stable: AJAX, cron, admin mutation
 * handlers, and ViewReadService still call the same methods. Internally the
 * old S1-S11 collaborator graph is gone. One rebuild attempt now acquires one
 * writer lock, rebuilds the buffer with bounded SQL batches, swaps it into
 * view_done, records freshness, and returns a one-step progress shape.
 */
class ABJ_404_Solution_ViewBuildOrchestrator implements ABJ_404_Solution_ViewBuildOrchestratorInterface {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;
    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_RebuildHealthState|null */
    private $rebuildHealth;
    /** @var ABJ_404_Solution_ViewReadService|null */
    private $viewReadService;
    /** @var ABJ_404_Solution_LogsRepository|null */
    private $logsRepo;
    /** @var bool|null */
    private $viewDoneIsServeableCache = null;
    /** @var int */
    private $stagedQueryTimeoutSeconds = 0;
    /** @var bool */
    private $usingFallbackLock = false;
    /** @var bool|null */
    private static $namedLockSupportedThisRequest = null;
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
        $this->dbCore = $dbCore;
        $this->f = $f instanceof ABJ_404_Solution_Functions ? $f : abj_service('functions');
        $this->logger = $logger instanceof ABJ_404_Solution_Logging ? $logger : abj_service('logging');
        $this->rebuildHealth = $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState
            ? $rebuildHealth
            : $this->resolveRebuildHealthState();
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
        self::$namedLockSupportedThisRequest = null;
    }

    /** @return void */
    public function claimForegroundViewBuildLease(): void {
        if (function_exists('update_option')) {
            update_option($this->prefixedOptionName('abj404_view_build_foreground_until'),
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
        if (!empty($tableOptions['_abj404_force_view_rebuild'])) {
            $this->forceRestartViewBuild(0);
        }

        if ($this->viewDoneIsServeable()) {
            if (!$this->viewDoneIsFresh()) {
                $this->scheduleViewDoneRebuild();
            }
            return $this->requireViewReadService()->readFromViewDone($sub, $tableOptions);
        }

        $this->scheduleViewDoneRebuild();
        throw new ABJ_404_Solution_ViewBuildPendingException(
            'View build pending; background rebuild scheduled. Progress: not yet started',
            'not yet started'
        );
    }

    /** @return bool */
    public function viewDoneIsServeable(): bool {
        if ($this->viewDoneIsServeableCache !== null) {
            return $this->viewDoneIsServeableCache;
        }
        if (!$this->tableExists($this->viewDoneTableName())) {
            $this->viewDoneIsServeableCache = false;
            return false;
        }
        if ($this->viewDoneHasRows()) {
            $this->viewDoneIsServeableCache = true;
            return true;
        }
        $this->viewDoneIsServeableCache = $this->viewDoneDataBuiltAt() > 0;
        return $this->viewDoneIsServeableCache;
    }

    /** @return int */
    public function getViewDoneBuiltAtTimestamp(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $value = get_option($this->viewDoneFreshnessOptionName(), 0);
        return is_scalar($value) ? max(0, intval($value)) : 0;
    }

    /** @return void */
    public function markViewDoneBuildCompleted(): void {
        if (function_exists('update_option')) {
            $now = abj_clock()->now();
            update_option($this->viewDoneFreshnessOptionName(), $now, false);
            update_option($this->viewDoneDataBuiltAtOptionName(), $now, false);
        }
        $this->invalidateViewDoneServeableCacheBridge();
    }

    /** @return array<string, mixed> */
    public function getViewBuildProgress(): array {
        return $this->formatProgress($this->viewDoneIsServeable() ? 'ready' : 'pending',
            $this->viewDoneIsServeable() ? 'ready' : 'not yet started');
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
        if (!$forceRebuild && $this->viewDoneIsFresh() && $this->viewDoneIsServeable()) {
            return $this->getViewBuildProgress();
        }

        if (!$this->acquireViewBuildLock($forceRebuild ? 10 : 0)) {
            $progress = $this->formatProgress('pending', 'locked');
            $progress['locked'] = true;
            return $progress;
        }

        self::$viewBuildAlreadyRanThisRequest = true;
        try {
            if ($forceRebuild) {
                $this->runForceRestartCleanupInsideLock();
            }
            $this->runDirectRebuild();
        } finally {
            $this->releaseViewBuildLock();
        }

        $progress = $this->formatProgress('ready', 'rebuilt');
        $progress['locked'] = false;
        return $progress;
    }

    /** @return array{ran:bool, reason:string, progress:array<string,mixed>} */
    public function runPageLoadFallbackAdvance(): array {
        if ($this->viewDoneIsServeable()) {
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
        if (!$this->viewDoneIsServeable()) {
            $this->scheduleViewDoneRebuild();
            throw new ABJ_404_Solution_ViewBuildPendingException(
                'View-count build pending; background rebuild scheduled. Progress: not yet started',
                'not yet started'
            );
        }
        if (!$this->viewDoneIsFresh()) {
            $this->scheduleViewDoneRebuild();
        }
        $sql = $this->requireViewReadService()->buildViewDoneCountQuery($sub, $tableOptions);
        $result = $this->dbCore->queryAndGetResults($sql, $this->getStagedQueryOptionsForRead());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $row = $rows[0];
        $raw = $row['cnt'] ?? reset($row);
        return is_scalar($raw) ? intval($raw) : 0;
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
        if ($this->tableExists($this->viewBuildTableName()) && !$this->tableExists($this->viewDoneTableName())) {
            $this->renameBuildToDone();
            $this->markViewDoneBuildCompleted();
            return 'promoted';
        }
        if ($this->tableExists($this->viewDeletemeTableName())) {
            $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->viewDeletemeTableName() . '`',
                array('log_errors' => false), 'drop stale view_deleteme');
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
            update_option($this->prefixedOptionName('abj404_view_build_prefix_at_s1'), $this->prefix(), false);
        }
    }

    /** @return bool */
    public function verifyPrefixUnchangedSinceStageOne(): bool {
        if (!function_exists('get_option')) {
            return true;
        }
        $captured = get_option($this->prefixedOptionName('abj404_view_build_prefix_at_s1'), '');
        return $captured === '' || $captured === $this->prefix();
    }

    /** @return void */
    public function clearPrefixAtStageOne(): void {
        if (function_exists('delete_option')) {
            delete_option($this->prefixedOptionName('abj404_view_build_prefix_at_s1'));
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
        if (!$this->acquireViewBuildLock(0)) {
            return false;
        }
        try {
            if (!function_exists('update_option') || !function_exists('get_option') || !function_exists('delete_option')) {
                return false;
            }
            $optionName = $this->prefixedOptionName('abj404_view_build_lock_writer_probe');
            $value = (string)abj_clock()->nowFloat();
            update_option($optionName, $value, false);
            $readBack = get_option($optionName, '');
            delete_option($optionName);
            return $readBack === $value;
        } finally {
            $this->releaseViewBuildLock();
        }
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
        return $this->tableExists($this->viewDoneTableName());
    }

    /** @return array<string, mixed> */
    public function probeSessionVariablesAtS1Entry(): array {
        return array('status' => 'not_probed');
    }

    /** @return void */
    public function invalidateViewDoneAndScheduleRebuild(): void {
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneFreshnessOptionName());
        }
        $this->invalidateViewDoneServeableCacheBridge();
        $this->scheduleViewDoneRebuild();
    }

    /** @param int $lockTimeoutSeconds @return bool */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool {
        if ($this->rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState) {
            $this->rebuildHealth->reset();
            $this->rebuildHealth->acquireTrialToken();
        }
        if (!$this->acquireViewBuildLock(max(0, $lockTimeoutSeconds))) {
            return false;
        }
        try {
            $this->runForceRestartCleanupInsideLock();
        } finally {
            $this->releaseViewBuildLock();
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
        $this->logsRepo = $logsRepo;
    }

    /** @return void */
    public function invalidateViewDoneServeableCacheBridge(): void {
        $this->viewDoneIsServeableCache = null;
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
        $value = get_option($this->prefixedOptionName('abj404_view_build_' . $shortName), $default);
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
    private function runDirectRebuild(): void {
        $this->dropTransientBuildTables();
        $this->createBuildTable();
        $this->runInsertRedirectsBatches();
        $this->runSqlTemplate('03_index_fd.sql', array(), true);
        $this->runIdRangeTemplate('04_update_posts.sql');
        $this->runIdRangeTemplate('05_update_terms.sql');
        $this->runSqlTemplate('06_update_home.sql', array(), false);
        $this->runSqlTemplate('07_update_external.sql', array(), false);
        $this->runSqlTemplate('08_update_special.sql', array(), false);
        if ($this->logsHitsTableExists()) {
            $collation = $this->dbCore->collationHelper()->getColumnCollationString($this->logsHitsTableName(), 'requested_url');
            $collation = $collation !== '' ? $collation : 'utf8mb4_unicode_ci';
            $extra = array('{S9_COLLATION}' => $collation);
            $this->runSqlTemplate('09a_drop_hits_temp.sql', array(), false);
            $this->runSqlTemplate('09b_create_hits_temp.sql', $extra, false);
            $this->runSqlTemplate('09c_insert_hits_temp.sql', array(), false);
            $this->runSqlTemplate('09_update_hits.sql', $extra, false);
            $this->runSqlTemplate('09a_drop_hits_temp.sql', array(), false);
        }
        $this->runSqlTemplate('10_index_sort.sql', array(), true);
        if (function_exists('do_action')) {
            do_action('abj404_view_build_before_rename_swap');
        }
        $this->renameBuildToDone();
        $this->markViewDoneBuildCompleted();
        $this->clearBuildProgressOptions();
    }

    /** @return void */
    private function runInsertRedirectsBatches(): void {
        $batchSize = $this->viewBuildBatchSize();
        $lo = 0;
        do {
            $result = $this->runSqlTemplate('02_insert.sql', array(
                '{LO_BOUND}' => (string)$lo,
                '{BATCH_SIZE}' => (string)$batchSize,
            ), false);
            $affected = isset($result['rows_affected']) && is_scalar($result['rows_affected'])
                ? intval($result['rows_affected']) : 0;
            if ($affected <= 0 || $affected < $batchSize) {
                break;
            }
            $nextLo = $this->queryScalar('SELECT COALESCE(MAX(id), 0) AS max_id FROM `' . $this->viewBuildTableName() . '`');
            if ($nextLo <= $lo) {
                break;
            }
            $lo = $nextLo;
        } while (true);
    }

    /** @param string $relativePath @return void */
    private function runIdRangeTemplate(string $relativePath): void {
        $batchSize = $this->viewBuildBatchSize();
        $maxId = $this->queryScalar('SELECT COALESCE(MAX(id), 0) AS max_id FROM `' . $this->viewBuildTableName() . '`');
        if ($maxId <= 0) {
            $this->runSqlTemplate($relativePath, array('{LO_BOUND}' => '0', '{HI_BOUND}' => (string)PHP_INT_MAX), false);
            return;
        }
        for ($lo = 0; $lo < $maxId; $lo += $batchSize) {
            $this->runSqlTemplate($relativePath, array(
                '{LO_BOUND}' => (string)$lo,
                '{HI_BOUND}' => (string)min($maxId, $lo + $batchSize),
            ), false);
        }
    }

    /**
     * @param string $relativePath
     * @param array<string, string> $extra
     * @param bool $tolerateDuplicateKey
     * @return array<string, mixed>
     */
    private function runSqlTemplate(string $relativePath, array $extra, bool $tolerateDuplicateKey): array {
        $path = __DIR__ . '/../sql/getRedirectsForViewStaged/' . $relativePath;
        $template = ABJ_404_Solution_FileSystemService::readFileContents($path);
        if (!is_string($template) || trim($template) === '') {
            throw new \RuntimeException('View SQL template missing or empty: ' . $relativePath); // allow-raw-error: unrecoverable plugin asset corruption
        }
        $sql = $this->dbCore->doTableNameReplacements($template);
        if (!empty($extra)) {
            $sql = str_replace(array_keys($extra), array_values($extra), $sql);
        }
        if (method_exists($this->f, 'doNormalReplacements')) {
            $sql = $this->f->doNormalReplacements($sql);
        }
        $result = $this->dbCore->queryAndGetResults($sql, $this->getStagedQueryOptionsForRead());
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '') {
            if ($tolerateDuplicateKey
                    && (stripos($err, 'Duplicate key name') !== false || stripos($err, 'errno: 1061') !== false)) {
                $this->logger->debugMessage($relativePath . ': index already exists, tolerated.');
                return $result;
            }
            throw new \RuntimeException('View SQL ' . $relativePath . ' failed: ' . $err); // allow-raw-error: includes database error for admin diagnostics
        }
        return $result;
    }

    /** @return void */
    private function createBuildTable(): void {
        $template = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../sql/createViewBuildTable.sql');
        $base = $this->dbCore->doTableNameReplacements(is_string($template) ? $template : '');
        if (trim($base) === '') {
            throw new \RuntimeException('createViewBuildTable.sql is empty or unreadable.'); // allow-raw-error: unrecoverable plugin asset corruption
        }
        $attempts = array($base, $base . ' ENGINE=MyISAM', $base . ' ENGINE=InnoDB');
        $lastError = '';
        foreach ($attempts as $sql) {
            $result = $this->dbCore->queryAndGetResults($sql, array('log_errors' => false));
            $lastError = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
            if ($lastError === '') {
                return;
            }
        }
        throw new \RuntimeException('Could not create view build table: ' . $lastError); // allow-raw-error: includes database error for admin diagnostics
    }

    /** @return void */
    private function renameBuildToDone(): void {
        $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->viewDeletemeTableName() . '`',
            array('log_errors' => false), 'drop stale view_deleteme');
        if ($this->tableExists($this->viewDoneTableName())) {
            $sql = 'RENAME TABLE `' . $this->viewDoneTableName() . '` TO `' . $this->viewDeletemeTableName()
                . '`, `' . $this->viewBuildTableName() . '` TO `' . $this->viewDoneTableName() . '`';
        } else {
            $sql = 'RENAME TABLE `' . $this->viewBuildTableName() . '` TO `' . $this->viewDoneTableName() . '`';
        }
        $this->queryAndRequireSuccess($sql, array('log_errors' => true), 'rename view build into place');
        $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->viewDeletemeTableName() . '`',
            array('log_errors' => false), 'drop replaced view_done');
        $this->invalidateViewDoneServeableCacheBridge();
    }

    /** @return void */
    private function runForceRestartCleanupInsideLock(): void {
        $this->dropTransientBuildTables();
        $this->clearBuildProgressOptions();
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneFreshnessOptionName());
        }
        $this->invalidateViewDoneServeableCacheBridge();
    }

    /** @return void */
    private function dropTransientBuildTables(): void {
        $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->viewBuildTableName() . '`',
            array('log_errors' => false), 'drop view_build');
        $this->queryAndRequireSuccess('DROP TABLE IF EXISTS `' . $this->viewDeletemeTableName() . '`',
            array('log_errors' => false), 'drop view_deleteme');
    }

    /** @return void */
    private function clearBuildProgressOptions(): void {
        if (!function_exists('delete_option')) {
            return;
        }
        foreach (array('started_at', 'current_stage', 'last_started_stage', 'last_completed_stage') as $name) {
            delete_option($this->prefixedOptionName('abj404_view_build_' . $name));
        }
        $this->clearPrefixAtStageOne();
    }

    /**
     * @param string $sql
     * @param array<string, mixed> $options
     * @param string $context
     * @return array<string, mixed>
     */
    private function queryAndRequireSuccess(string $sql, array $options, string $context): array {
        $result = $this->dbCore->queryAndGetResults($sql, $options);
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '') {
            throw new \RuntimeException($context . ' failed: ' . $err); // allow-raw-error: includes database error for admin diagnostics
        }
        return $result;
    }

    /** @param string $sql @return int */
    private function queryScalar(string $sql): int {
        $result = $this->dbCore->queryAndGetResults($sql, $this->getStagedQueryOptionsForRead());
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $row = $rows[0];
        $value = reset($row);
        return is_scalar($value) ? intval($value) : 0;
    }

    /** @param int $timeoutSeconds @return bool */
    private function acquireViewBuildLock(int $timeoutSeconds): bool {
        $name = $this->prefix() . ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_BUILD_LOCK_NAME;
        if (self::$namedLockSupportedThisRequest === false) {
            return $this->acquireFallbackLock($name);
        }
        $result = $this->dbCore->queryAndGetResults(
            "SELECT GET_LOCK('" . esc_sql($name) . "', " . max(0, $timeoutSeconds) . ") AS got",
            array('log_errors' => false)
        );
        $err = isset($result['last_error']) && is_string($result['last_error']) ? trim($result['last_error']) : '';
        if ($err !== '' && stripos($err, 'get_lock') !== false) {
            self::$namedLockSupportedThisRequest = false;
            return $this->acquireFallbackLock($name);
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return false;
        }
        $got = $rows[0]['got'] ?? reset($rows[0]);
        if ($got === null) {
            self::$namedLockSupportedThisRequest = false;
            return $this->acquireFallbackLock($name);
        }
        if (is_scalar($got) && intval($got) === 1) {
            self::$namedLockSupportedThisRequest = true;
            $this->usingFallbackLock = false;
            return true;
        }
        return false;
    }

    /** @return void */
    private function releaseViewBuildLock(): void {
        $name = $this->prefix() . ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_BUILD_LOCK_NAME;
        if ($this->usingFallbackLock) {
            $this->usingFallbackLock = false;
            if (function_exists('delete_option')) {
                delete_option($name . '_transient_lock');
            }
            return;
        }
        $this->dbCore->queryAndGetResults("SELECT RELEASE_LOCK('" . esc_sql($name) . "')", array('log_errors' => false));
    }

    /** @param string $name @return bool */
    private function acquireFallbackLock(string $name): bool {
        if (!function_exists('add_option') || !function_exists('get_option')) {
            return false;
        }
        $optionName = $name . '_transient_lock';
        $now = abj_clock()->now();
        $existing = get_option($optionName, 0);
        $existingExpires = is_scalar($existing) ? intval($existing) : 0;
        if ($existingExpires > 0 && $existingExpires <= $now && function_exists('delete_option')) {
            delete_option($optionName);
        }
        if (add_option($optionName, (string)($now + ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_TRANSIENT_LOCK_TTL_SECONDS), '', false)) {
            $this->usingFallbackLock = true;
            return true;
        }
        return false;
    }

    /** @return bool */
    private function viewDoneIsFresh(): bool {
        $builtAt = $this->getViewDoneBuiltAtTimestamp();
        return $builtAt > 0
            && (abj_clock()->now() - $builtAt) < ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_FRESHNESS_TTL_SECONDS
            && $this->viewDoneIsServeable();
    }

    /** @return bool */
    private function viewDoneHasRows(): bool {
        $result = $this->dbCore->queryAndGetResults('SELECT 1 FROM `' . $this->viewDoneTableName() . '` LIMIT 1',
            array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return !empty($rows);
    }

    /** @return int */
    private function viewDoneDataBuiltAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $value = get_option($this->viewDoneDataBuiltAtOptionName(), 0);
        return is_scalar($value) ? max(0, intval($value)) : 0;
    }

    /** @param string $tableName @return bool */
    private function tableExists(string $tableName): bool {
        if (method_exists($this->dbCore, 'tableNameResolver')) {
            return $this->dbCore->tableNameResolver()->tableExists($tableName);
        }
        return false;
    }

    /** @return bool */
    private function logsHitsTableExists(): bool {
        if ($this->logsRepo instanceof ABJ_404_Solution_LogsRepository) {
            return (bool)$this->logsRepo->logsHitsTableExists();
        }
        return $this->tableExists($this->logsHitsTableName());
    }

    /** @param array<string, mixed> $tableOptions @return void */
    private function setReadQueryTimeout(array $tableOptions): void {
        $this->stagedQueryTimeoutSeconds = isset($tableOptions['_abj404_query_timeout'])
            && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(0, intval($tableOptions['_abj404_query_timeout'])) : 0;
    }

    /**
     * @param string $status
     * @param string $text
     * @return array<string, mixed>
     */
    private function formatProgress(string $status, string $text): array {
        $fingerprint = array();
        if ($this->viewReadService instanceof ABJ_404_Solution_ViewReadService) {
            $fingerprint = $this->viewReadService->getViewBuildProgressFingerprint();
        }
        return array(
            'status' => $status,
            'stage' => $status === 'ready' ? 1 : 0,
            'of' => 1,
            'build_started' => 0,
            'progress_text' => $text,
            'fingerprint' => $fingerprint,
        );
    }

    /** @return int */
    private function viewBuildBatchSize(): int {
        $size = ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEFAULT_BATCH_SIZE;
        if (defined('ABJ404_VIEW_BUILD_BATCH_SIZE')) {
            $size = intval(ABJ404_VIEW_BUILD_BATCH_SIZE);
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_view_build_batch_size', $size);
            if (is_scalar($filtered)) {
                $size = intval($filtered);
            }
        }
        return max(1, $size);
    }

    /** @return string */
    private function prefix(): string {
        return $this->dbCore->tableNameResolver()->getLowercasePrefix();
    }

    /** @param string $name @return string */
    private function prefixedOptionName(string $name): string {
        return $this->prefix() . $name;
    }

    /** @return string */
    private function viewBuildTableName(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_build}');
    }

    /** @return string */
    private function viewDoneTableName(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_done}');
    }

    /** @return string */
    private function viewDeletemeTableName(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_deleteme}');
    }

    /** @return string */
    private function logsHitsTableName(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
    }

    /** @return string */
    private function viewDoneFreshnessOptionName(): string {
        return $this->prefixedOptionName('abj404_view_done_built_at');
    }

    /** @return string */
    private function viewDoneDataBuiltAtOptionName(): string {
        return $this->prefixedOptionName('abj404_view_done_data_built_at');
    }

    /** @return ABJ_404_Solution_ViewReadService */
    private function requireViewReadService(): ABJ_404_Solution_ViewReadService {
        if (!$this->viewReadService instanceof ABJ_404_Solution_ViewReadService) {
            throw new \RuntimeException('ViewBuildOrchestrator requires ViewReadService (call setViewReadService first)'); // allow-raw-error: assertion, should never reach user
        }
        return $this->viewReadService;
    }
}
