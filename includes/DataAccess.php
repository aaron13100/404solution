<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewBuildCollaborator.php';
require_once __DIR__ . '/ViewQueriesStaged.php';
require_once __DIR__ . '/ViewBuildStageCallbacks.php';
require_once __DIR__ . '/ViewBuildBatchExecutor.php';
require_once __DIR__ . '/ViewBuildStageRunner.php';
require_once __DIR__ . '/ViewBuildAdaptive.php';
require_once __DIR__ . '/ViewBuildProgressOptions.php';
require_once __DIR__ . '/ViewBuildStagedSqlExecutor.php';
require_once __DIR__ . '/ViewBuildStateProbe.php';
require_once __DIR__ . '/ViewBuildSqlModeProbe.php';
require_once __DIR__ . '/ViewBuildRebuildReconcile.php';
require_once __DIR__ . '/ViewBuildLockAndCron.php';
require_once __DIR__ . '/ViewBuildPhpEnvProbe.php';
require_once __DIR__ . '/ViewBuildSessionEnvProbe.php';
require_once __DIR__ . '/ViewBuildHostFailurePolicy.php';
require_once __DIR__ . '/ViewBuildForceRestart.php';
require_once __DIR__ . '/ViewBuildForegroundLease.php';
require_once __DIR__ . '/ViewBuildReadGateway.php';
require_once __DIR__ . '/ViewBuildAdvanceCoordinator.php';
require_once __DIR__ . '/ViewBuildStagePipeline.php';
require_once __DIR__ . '/ViewDoneState.php';
require_once __DIR__ . '/ViewBuildPageLoadFallback.php';
require_once __DIR__ . '/DatabaseRuntimeState.php';
require_once __DIR__ . '/ViewReadRuntimeState.php';
require_once __DIR__ . '/DatabaseConnectionManager.php';
require_once __DIR__ . '/DatabaseQueryTimeoutManager.php';
require_once __DIR__ . '/ViewBuildOrchestratorInterface.php';
require_once __DIR__ . '/ViewBuildOrchestrator.php';
require_once __DIR__ . '/ViewReadServiceInterface.php';
require_once __DIR__ . '/ViewDiagnostics.php';
require_once __DIR__ . '/ViewCacheInvalidator.php';
require_once __DIR__ . '/ViewQueryBuilder.php';
require_once __DIR__ . '/ViewSnapshotCache.php';
require_once __DIR__ . '/ViewReadService.php';
require_once __DIR__ . '/LogsRepositoryInterface.php';
require_once __DIR__ . '/LogsRepository.php';
require_once __DIR__ . '/StatsRepositoryInterface.php';
require_once __DIR__ . '/StatsRepository.php';
require_once __DIR__ . '/ContentRepositoryInterface.php';
require_once __DIR__ . '/ContentRepository.php';
require_once __DIR__ . '/RedirectsRepositoryInterface.php';
require_once __DIR__ . '/RedirectsRepository.php';
require_once __DIR__ . '/RedirectsRetentionServiceInterface.php';
require_once __DIR__ . '/RedirectsRetentionService.php';
require_once __DIR__ . '/DatabaseErrorClassifier.php';
require_once __DIR__ . '/DatabaseSqlErrorReporter.php';
require_once __DIR__ . '/ViewQueryFailureException.php';
require_once __DIR__ . '/ViewBuildPendingException.php';
require_once __DIR__ . '/DatabaseCoreInterface.php';
require_once __DIR__ . '/DatabaseCore.php';

/* Functions in this class should all reference one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DataAccess {

    const UPDATE_LOGS_HITS_TABLE_HOOK = 'abj404_updateLogsHitsTableAction';

    const KEY_REDIRECTS_FOR_VIEW_COUNT = 'abj404_redirects-for-view-count';

    /** @var int Maximum age in seconds before hits table is considered stale */
    const HITS_TABLE_MAX_AGE_SECONDS = 300; // 5 minutes
    /** @var int Minimum interval between hits-table rebuild schedules (server-side dedupe). */
    const HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS = 30;
    /** @var int Short-lived cache for admin list snapshots (fast first paint). */
    const VIEW_SNAPSHOT_CACHE_TTL_SECONDS = 120;
    /** @var int Minimum interval between expensive refreshes for the same view key. */
    const VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS = 30;
    /** @var int DB timeout budget for each resumable table-cache warmup stage. */
    const VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS = 28;
    /** @var int Age after which a running warmup stage is treated as killed/stalled. */
    const VIEW_SNAPSHOT_WARMUP_STALE_SECONDS = 35;
    /** @var int Max killed/timeout attempts for one warmup stage before blocking retries. */
    const VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS = 3;
    /** @var int Safety cap: avoid storing extremely large payloads in cache. */
    const VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES = 2097152; // 2 MiB
    /** @var int Cross-request lock timeout for logs-hits rebuild jobs. */
    const HITS_TABLE_REBUILD_LOCK_TTL_SECONDS = 180;
    /** @var int Number of logsv2 IDs to process per chunk during pre-aggregation. */
    const HITS_TABLE_PREAGG_CHUNK_SIZE = 100000;
    /**
     * @var int If MAX(id) - MIN(id) is at or below this threshold, the rebuild
     *          uses the single-statement direct path; above it, the chunked
     *          two-phase path. Threshold is intentionally far smaller than
     *          HITS_TABLE_PREAGG_CHUNK_SIZE: log retention by timestamp lets
     *          MIN(id) climb monotonically, so MAX-MIN converges to the live
     *          row count, and the direct path's CONCAT/COALESCE-derived JOIN
     *          times out at 60s on shared hosts at row counts well below
     *          HITS_TABLE_PREAGG_CHUNK_SIZE. Only truly tiny tables benefit
     *          from skipping the pre-agg overhead.
     */
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = 5000;
    /** @var int Max age for cached stats-periodic aggregates. */
    const PERIODIC_STATS_CACHE_TTL_SECONDS = 300;
    /** @var int Minimum interval before recalculating expensive stats aggregates. */
    const PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS = 30;
    /** @var int Max age for cached daily-activity trend data (Stats tab Chart.js). */
    const TREND_DATA_CACHE_TTL_SECONDS = 900;
    /**
     * @var int Short TTL for the cached `getLogsCount(0)` total row count.
     *          Audit F4: InnoDB has no maintained row counter, so the
     *          Logs admin tab's `SELECT COUNT(id) FROM logsv2` is a full
     *          index scan. New inserts move the cache key (`max_log_id`)
     *          so fresh data is picked up immediately; bulk deletes do
     *          not move the key, so the TTL bounds staleness at 60 s.
     */
    const LOGS_COUNT_CACHE_TTL_SECONDS = 60;
    /** @var int Retention for dashboard stats snapshot payload (stale snapshot is acceptable for fast first paint). */
    const STATS_DASHBOARD_CACHE_TTL_SECONDS = 86400;
    /** @var int Minimum time between full stats snapshot recomputes. */
    const STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS = 30;
    /** @var int Cooldown when DB query quota is exceeded. */
    const DB_QUOTA_COOLDOWN_SECONDS = ABJ_404_Solution_DatabaseRuntimeState::DB_QUOTA_COOLDOWN_SECONDS;
    /** @var int Cooldown when DB is read-only or storage is full. */
    const DB_WRITE_BLOCK_COOLDOWN_SECONDS = ABJ_404_Solution_DatabaseRuntimeState::DB_WRITE_BLOCK_COOLDOWN_SECONDS;

    /** @var string Runtime flag: last time we checked whether logs-hits needs rebuild (Unix timestamp). */
    const HITS_TABLE_LAST_CHECKED_FLAG = 'abj404_logs_hits_last_checked_at';
    /** @var string Runtime flag: last time we scheduled a rebuild (Unix timestamp). */
    const HITS_TABLE_LAST_SCHEDULED_FLAG = 'abj404_logs_hits_last_scheduled_at';
    /** @var string Runtime flag: last schedule decision ('scheduled','running','cooldown','paused','not_needed'). */
    const HITS_TABLE_LAST_DECISION_FLAG = 'abj404_logs_hits_last_decision';
    /** @var string Runtime flag: last successful hits-table rebuild completion (Unix timestamp). */
    const HITS_TABLE_LAST_REFRESHED_FLAG = 'abj404_logs_hits_last_refreshed_at';
    /**
     * @var string Runtime flag: Unix timestamp of the first request that
     *             observed MAX(logsv2.id) > stored rollup watermark and the
     *             gap has remained open since. Drives the broken-cron
     *             admin notice; cleared on rebuild or when the gap closes.
     */
    const HITS_TABLE_FIRST_STALE_DETECTED_FLAG = 'abj404_logs_hits_first_stale_detected_at';
    /** @var string Deduplicated admin-notice transient for stale logs_hits rollup. */
    const HITS_TABLE_STALE_NOTICE_TRANSIENT = 'abj404_logs_hits_rollup_stale';
    /**
     * @var int Minimum age (seconds) of a persisted MAX(logsv2.id) >
     *          rollup-watermark gap before surfacing a broken-cron admin
     *          notice. 1 hour is well past the normal cron cycle for the
     *          5-minute HITS_TABLE_MAX_AGE_SECONDS rollup, so a gap that
     *          stays open this long is unambiguously a broken or
     *          stopped cron event (abj404_updateLogsHitsTableAction).
     */
    const HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS = 3600;

    /** @var self|null */
    private static $instance = null;

    /** @var ABJ_404_Solution_DatabaseCore The extracted database infrastructure layer. */
    private $dbCore;

    /** @var ABJ_404_Solution_DatabaseConnectionManager The connection-management infrastructure component. */
    private $connectionManager;

    /** @var ABJ_404_Solution_ContentRepository The extracted content/cache repository. */
    private $contentRepo;

    /** @var ABJ_404_Solution_RedirectsRepository The extracted redirects repository. */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_RedirectsRetentionService|null Lazy-initialized retention workflow service. */
    private $retentionService = null;

    /** @var ABJ_404_Solution_LogsRepository The extracted logs repository. */
    private $logsRepo;

    /**
     * @var ABJ_404_Solution_StatsRepository
     *
     * Test-only composition surface. Production code MUST resolve the stats
     * repository through StatsRepositoryInterface (constructor-injected) or via
     * ABJ_404_Solution_UnavailableStatsRepository::resolve(). Held on DataAccess
     * exclusively so tests that construct a real (or stubbed-deps) DataAccess
     * can drive the real StatsRepository against a custom DbCore/LogsRepo.
     *
     * Production prohibition is enforced by
     * StatsRepositoryExtractionTest::testProductionCallersDoNotResolveStatsRepositoryThroughDataAccess.
     * The 12 prior pass-through methods (getStatsCount, getPeriodicStatsSummary,
     * getStatsDashboardSnapshot, refreshStatsDashboardSnapshot,
     * getEarliestLogTimestamp, getTopCapturedForDigest,
     * buildTopCapturedForDigestQuery, getDigestSummaryStats,
     * getCapturedCountForNotification, getPostsNeedingContentKeywords,
     * bulkUpdateContentKeywords, getPeriodicStatsSummariesCached) have been
     * removed; see StatsRepositoryExtractionTest::testDataAccessNoLongerExposesStatsRepoPassThroughs.
     */
    private $statsRepo;

    /** @var ABJ_404_Solution_ViewReadService The extracted view read service (Phase 6). */
    private $viewReadService;

    /** @var ABJ_404_Solution_ViewBuildOrchestrator The extracted view build orchestrator (Phase 7). */
    private $viewBuildOrchestrator;

    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        ABJ_404_Solution_ViewReadService::setViewSnapshotTableEnsured($value);
    }

    /** @return void */
    public static function resetViewBuildOncePerRequestGuard(): void {
        ABJ_404_Solution_ViewBuildOrchestrator::resetViewBuildOncePerRequestGuard();
    }

/**
     * @param string|null $raw
     * @return array<int, array{step: string, outcome: string, detail: string}>|null
     */
    public static function decompressPipelineTrace(?string $raw): ?array {
        return ABJ_404_Solution_LogsRepository::decompressPipelineTrace($raw);
    }

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** Cache key for redirect status counts */
    const CACHE_KEY_REDIRECT_STATUS = 'abj404_redirect_status_counts';

    /** Cache key for captured status counts */
    const CACHE_KEY_CAPTURED_STATUS = 'abj404_captured_status_counts';

    /** Cache key for high-impact captured URL count (3+ hits) */
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = 'abj404_high_impact_captured';

    /** Cache TTL in seconds (24 hours - safety net, primary refresh is event-driven invalidation) */
    const STATUS_CACHE_TTL = 86400;

    /**
     * Short-TTL window used after a query timeout to break the
     * "page reloads, page re-times-out" loop on slow hosts. 5 minutes is
     * long enough that an admin browsing session does not re-pay the
     * timeout cost, and short enough that once the scheduled hits-table
     * rebuild completes, the next request after the window picks up the
     * rebuilt rollup. See getHighImpactCapturedCount() self-heal branch.
     */
    const STATUS_CACHE_TIMEOUT_SELFHEAL_TTL = 300;

    /** Maximum number of regex redirects to cache per-request (memory guard) */
    const REGEX_CACHE_MAX_COUNT = 50;

    // $regexRedirectsCache and $regexCacheDisabled moved to RedirectsRepository (Phase 2).

    /** @var array<string, string> Legacy reflection bridge for view-build progress options. */
    private static $viewBuildProgressOptionNames = array(
        'started_at' => 'abj404_view_build_started_at',
        'current_stage' => 'abj404_view_build_current_stage',
        'last_started_stage' => 'abj404_view_build_last_started_stage',
        'last_started_at' => 'abj404_view_build_last_started_at',
        'last_completed_stage' => 'abj404_view_build_last_completed_stage',
        'last_completed_at' => 'abj404_view_build_last_completed_at',
        's2_high_water' => 'abj404_view_build_s2_high_water',
        's4_high_water' => 'abj404_view_build_s4_high_water',
        's5_high_water' => 'abj404_view_build_s5_high_water',
        's2_batch_size' => 'abj404_view_build_s2_batch_size',
        's4_batch_size' => 'abj404_view_build_s4_batch_size',
        's5_batch_size' => 'abj404_view_build_s5_batch_size',
        's3_kill_streak' => 'abj404_view_build_s3_kill_streak',
        's9_kill_streak' => 'abj404_view_build_s9_kill_streak',
        's10_kill_streak' => 'abj404_view_build_s10_kill_streak',
        's1_no_progress_streak' => 'abj404_view_build_s1_no_progress',
        's2_no_progress_streak' => 'abj404_view_build_s2_no_progress',
        's3_no_progress_streak' => 'abj404_view_build_s3_no_progress',
        's4_no_progress_streak' => 'abj404_view_build_s4_no_progress',
        's5_no_progress_streak' => 'abj404_view_build_s5_no_progress',
        's6_no_progress_streak' => 'abj404_view_build_s6_no_progress',
        's7_no_progress_streak' => 'abj404_view_build_s7_no_progress',
        's8_no_progress_streak' => 'abj404_view_build_s8_no_progress',
        's9_no_progress_streak' => 'abj404_view_build_s9_no_progress',
        's10_no_progress_streak' => 'abj404_view_build_s10_no_progress',
        's11_no_progress_streak' => 'abj404_view_build_s11_no_progress',
    );

    /** @var array<int, array<string, mixed>> Legacy reflection bridge; actual queue is owned by LogsRepository. */
    private static $logQueue = array();
    /** @var bool Legacy reflection bridge; actual hook state is owned by LogsRepository. */
    private static $shutdownHookRegistered = false;
    /** @var bool Legacy reflection bridge; actual flush state is owned by LogsRepository. */
    private static $isFlushingLogQueue = false;

    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_DatabaseCore|null $dbCore
     * @param ABJ_404_Solution_ContentRepository|null $contentRepo
     * @param ABJ_404_Solution_RedirectsRepository|null $redirectsRepo
     * @param ABJ_404_Solution_LogsRepository|null $logsRepo
     * @param ABJ_404_Solution_StatsRepository|null $statsRepo
     * @param ABJ_404_Solution_ViewReadService|null $viewReadService
     * @param ABJ_404_Solution_ViewBuildOrchestrator|null $viewBuildOrchestrator
     */
    public function __construct($functions = null, $logging = null, $dbCore = null, $contentRepo = null, $redirectsRepo = null, $logsRepo = null, $statsRepo = null, $viewReadService = null, $viewBuildOrchestrator = null) {
        $this->f = self::resolveFunctions($functions);
        $this->logger = $this->resolveLogger($logging);
        $this->dbCore = $dbCore !== null ? $dbCore : $this->createDbCore();
        $this->connectionManager = $this->dbCore->connectionManager();
        if ($contentRepo !== null) {
            $this->contentRepo = $contentRepo;
        } else {
            $this->contentRepo = new ABJ_404_Solution_ContentRepository($this->dbCore, $this->f, $this->logger);
        }

        if ($redirectsRepo !== null) {
            $this->redirectsRepo = $redirectsRepo;
        } else {
            $this->redirectsRepo = new ABJ_404_Solution_RedirectsRepository($this->dbCore, $this->f, $this->logger);
        }

        $this->logsRepo = $logsRepo !== null
            ? $logsRepo
            : new ABJ_404_Solution_LogsRepository($this->dbCore, $this->f, $this->logger);

        if ($statsRepo !== null) {
            $this->statsRepo = $statsRepo;
        } else {
            $this->statsRepo = new ABJ_404_Solution_StatsRepository($this->dbCore, $this->logsRepo, $this->f, $this->logger);
        }

        if ($viewReadService !== null) {
            $this->viewReadService = $viewReadService;
        } else {
            $this->viewReadService = new ABJ_404_Solution_ViewReadService(
                $this->dbCore, $this->logsRepo, $this->redirectsRepo, $this->f, $this->logger
            );
        }

        $this->viewBuildOrchestrator = $viewBuildOrchestrator !== null
            ? $viewBuildOrchestrator
            : new ABJ_404_Solution_ViewBuildOrchestrator(
                $this->dbCore, $this->f, $this->logger, $this->resolveRebuildHealthState()
            );
        $this->viewBuildOrchestrator->setViewReadService($this->viewReadService);
        $this->viewBuildOrchestrator->setLogsRepository($this->logsRepo);
        $this->viewReadService->setViewBuildOrchestrator($this->viewBuildOrchestrator);
    }

    /**
     * @param mixed $functions
     * @return ABJ_404_Solution_Functions
     */
    private static function resolveFunctions($functions) {
        if ($functions instanceof ABJ_404_Solution_Functions) {
            return $functions;
        }
        return abj_service('functions');
    }

    /**
     * @param ABJ_404_Solution_Logging|null $logging
     * @return ABJ_404_Solution_Logging
     */
    private function resolveLogger($logging) {
        if ($logging instanceof ABJ_404_Solution_Logging) {
            return $logging;
        }
        return abj_service('logging');
    }

    /** @return ABJ_404_Solution_DatabaseCore */
    private function createDbCore() {
        return new ABJ_404_Solution_DatabaseCore($this->f, $this->logger);
    }

    /** @return ABJ_404_Solution_DatabaseCore */
    public function getDbCore(): ABJ_404_Solution_DatabaseCore {
        if ($this->dbCore === null) {
            $this->dbCore = new ABJ_404_Solution_DatabaseCore($this->f, $this->logger);
        }
        return $this->dbCore;
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

    public function getPostOrGetSanitize($name, $defaultValue = null) {
        if (is_object($this->f) && method_exists($this->f, 'getPostOrGetSanitize')) {
            return $this->f->getPostOrGetSanitize($name, $defaultValue);
        }
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        if ($returnValue === null && $name === 'action') {
            $returnValue = isset($_GET['abj404action']) ? $_GET['abj404action'] : (isset($_POST['abj404action']) ? $_POST['abj404action'] : null);
        }
        $returnValue = self::applyAbj404ActionBulkFallback($name, $returnValue);
        if ($returnValue !== null && function_exists('sanitize_text_field')) {
            $returnValue = is_array($returnValue) ? array_map('sanitize_text_field', $returnValue) : sanitize_text_field($returnValue);
        }
        $finalValue = $returnValue ?? $defaultValue;
        return is_string($finalValue) ? $finalValue : (is_string($defaultValue) ? $defaultValue : '');
    }

    public function getPostOrGetSanitizeUrl($name, $defaultValue = null) {
        if (is_object($this->f) && method_exists($this->f, 'getPostOrGetSanitizeUrl')) {
            return $this->f->getPostOrGetSanitizeUrl($name, $defaultValue);
        }
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        return $returnValue === null ? $defaultValue : $returnValue;
    }

    /**
     * Fallback shim for the top/bottom bulk-action selects used by the native
     * WP_List_Table utility-row shape. See Functions::applyBulkActionFallback
     * for the rationale.
     *
     * @param string $name
     * @param mixed $current
     * @return mixed
     */
    private static function applyAbj404ActionBulkFallback($name, $current) {
        if ($name !== 'abj404action') {
            return $current;
        }
        if ($current !== null && $current !== '' && $current !== '-1') {
            return $current;
        }
        $alt = isset($_GET['abj404action2']) ? $_GET['abj404action2'] : (isset($_POST['abj404action2']) ? $_POST['abj404action2'] : null);
        if ($alt === null || $alt === '' || $alt === '-1') {
            return $current;
        }
        return $alt;
    }

    /** @return ABJ_404_Solution_ContentRepository */
    public function getContentRepo(): ABJ_404_Solution_ContentRepository {
        if ($this->contentRepo === null) {
            $this->contentRepo = new ABJ_404_Solution_ContentRepository($this->getDbCore(), $this->f, $this->logger);
        }
        return $this->contentRepo;
    }

    /** @return ABJ_404_Solution_RedirectsRepository */
    public function getRedirectsRepo(): ABJ_404_Solution_RedirectsRepository {
        if ($this->redirectsRepo === null) {
            $this->redirectsRepo = new ABJ_404_Solution_RedirectsRepository($this->getDbCore(), $this->f, $this->logger);
        }
        return $this->redirectsRepo;
    }

    /** @return ABJ_404_Solution_RedirectsRetentionService */
    public function getRetentionService(): ABJ_404_Solution_RedirectsRetentionService {
        if ($this->retentionService === null) {
            $this->retentionService = new ABJ_404_Solution_RedirectsRetentionService(
                $this->dbCore !== null ? $this->dbCore : $this->getDbCore(),
                $this->redirectsRepo !== null ? $this->redirectsRepo : $this->getRedirectsRepo(),
                $this->f,
                $this->logger
            );
        }
        return $this->retentionService;
    }

    /** @return int */
    public function cleanupOrphanedAutoRedirects(): int {
        return $this->getRetentionService()->cleanupOrphanedAutoRedirects();
    }

    public function deleteOldRedirectsCron() {
        $this->connectionManager->ensureConnection();
        return $this->getRetentionService()->deleteOldRedirectsCron();
    }

    public function limitDebugFileSize(): bool {
        return $this->getRetentionService()->limitDebugFileSize();
    }

    public function removeDuplicatesCron(): int {
        return $this->getRetentionService()->removeDuplicatesCron();
    }

    public function autoTrashJunkCapturedUrls(array $options): int {
        return $this->getRetentionService()->autoTrashJunkCapturedUrls($options);
    }

    /** @return ABJ_404_Solution_LogsRepository */
    public function getLogsRepo(): ABJ_404_Solution_LogsRepository {
        if ($this->logsRepo === null) {
            $this->logsRepo = new ABJ_404_Solution_LogsRepository($this->getDbCore(), $this->f, $this->logger);
        }
        return $this->logsRepo;
    }

    public function isTableFullError(string $error): bool { return $this->getLogsRepo()->isTableFullError($error); }

    public function autoTrimLogsv2IfNeeded(string $tableName, string $errorMessage): bool {
        return $this->getLogsRepo()->autoTrimLogsv2IfNeeded($tableName, $errorMessage);
    }

    public function getIsolatedWpdb() { return $this->getLogsRepo()->getIsolatedWpdb(); }

    public function sanitizeLogEntry(array $entry): ?array { return $this->getLogsRepo()->sanitizeLogEntry($entry); }

    /**
     * Test-only composition surface. Production callers must inject
     * StatsRepositoryInterface via constructor or call
     * ABJ_404_Solution_UnavailableStatsRepository::resolve(); using
     * $dao->getStatsRepo() in includes/ is forbidden and is enforced by
     * StatsRepositoryExtractionTest::testProductionCallersDoNotResolveStatsRepositoryThroughDataAccess
     * (also catches the obfuscated 'get'.'StatsRepo' and quoted-string variants).
     *
     * Retained only as a composition exposure for tests that subclass
     * DataAccess (e.g. StatsCacheInv_TestDAO, the makeRecordingDao() pattern in
     * DataAccessQueryTimeoutAuditTest) and need the StatsRepository instance
     * composed from the same private deps the test wired into DataAccess.
     *
     * @return ABJ_404_Solution_StatsRepository
     */
    public function getStatsRepo(): ABJ_404_Solution_StatsRepository {
        if ($this->statsRepo === null) {
            $this->statsRepo = new ABJ_404_Solution_StatsRepository($this->getDbCore(), $this->getLogsRepo(), $this->f, $this->logger);
        }
        return $this->statsRepo;
    }

    /** @return ABJ_404_Solution_ViewReadService */
    public function getViewReadService(): ABJ_404_Solution_ViewReadService {
        if ($this->viewReadService === null) {
            $this->viewReadService = new ABJ_404_Solution_ViewReadService(
                $this->getDbCore(), $this->getLogsRepo(), $this->getRedirectsRepo(), $this->f, $this->logger
            );
            if ($this->viewBuildOrchestrator !== null) {
                $this->viewReadService->setViewBuildOrchestrator($this->viewBuildOrchestrator);
            }
        }
        return $this->viewReadService;
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestrator */
    public function getViewBuildOrchestrator(): ABJ_404_Solution_ViewBuildOrchestrator {
        if ($this->viewBuildOrchestrator === null) {
            $this->viewBuildOrchestrator = new ABJ_404_Solution_ViewBuildOrchestrator(
                $this->getDbCore(), $this->f, $this->logger, $this->resolveRebuildHealthState()
            );
            $this->viewBuildOrchestrator->setViewReadService($this->getViewReadService());
            $this->viewBuildOrchestrator->setLogsRepository($this->getLogsRepo());
        }
        return $this->viewBuildOrchestrator;
    }

    /** @return string */
    private function viewDoneDataBuiltAtOptionName(): string {
        return $this->getViewBuildOrchestrator()->viewDoneDataBuiltAtOptionName();
    }

    /** @param mixed $tableOptions @return array<int, mixed> */
    public function getLogRecords($tableOptions) {
        return $this->getLogsRepo()->getLogRecords($tableOptions);
    }

    public function flushLogQueue(): void {
        $this->getLogsRepo()->flushLogQueue();
    }

    /** @return bool */
    public function viewDoneIsServeable(): bool {
        return $this->viewBuildOrchestrator->viewDoneIsServeable();
    }


    /** @return void */
    public function markViewDoneBuildCompleted(): void { $this->viewBuildOrchestrator->markViewDoneBuildCompleted(); }


    /** @return array{ran:bool, reason:string, progress:array<string,mixed>} */
    public function runPageLoadFallbackAdvance(): array {
        if (get_class($this) !== __CLASS__
            && method_exists($this, 'advanceViewBuildOnce')
            && (new \ReflectionMethod($this, 'advanceViewBuildOnce'))->getDeclaringClass()->getName() !== __CLASS__) {
            if ($this->viewBuildOrchestrator->getCronStuckHours() < 24) { return array('ran' => false, 'reason' => 'cron_healthy', 'progress' => $this->viewBuildOrchestrator->getViewBuildProgress()); }
            if ($this->viewDoneIsServeable()) { return array('ran' => false, 'reason' => 'not_needed', 'progress' => $this->viewBuildOrchestrator->getViewBuildProgress()); }
            $haveTransientApi = function_exists('get_transient') && function_exists('set_transient');
            $gateKey = ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_GATE_KEY;
            if ($haveTransientApi && get_transient($gateKey) !== false) { return array('ran' => false, 'reason' => 'gate_active', 'progress' => $this->viewBuildOrchestrator->getViewBuildProgress()); }
            if ($haveTransientApi) { set_transient($gateKey, 1, (int)ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_GATE_SECONDS); }
            $budgetSeconds = (float)ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_BUDGET_SECONDS;
            $budgetFilter = static function ($incoming) use ($budgetSeconds) {
                $value = is_scalar($incoming) ? (float)$incoming : $budgetSeconds;
                return min($value, $budgetSeconds);
            };
            $filterRegistered = false;
            if (function_exists('add_filter')) { add_filter('abj404_view_build_per_stage_budget_seconds', $budgetFilter, 100); $filterRegistered = true; }
            try {
                $progress = $this->advanceViewBuildOnce(false);
            } finally {
                if ($filterRegistered && function_exists('remove_filter')) { remove_filter('abj404_view_build_per_stage_budget_seconds', $budgetFilter, 100); }
            }
            return array('ran' => true, 'reason' => !empty($progress['locked']) ? 'locked' : 'advanced', 'progress' => $progress);
        }
        return $this->viewBuildOrchestrator->runPageLoadFallbackAdvance();
    }


    /** @return void */
    public function invalidateViewDoneAndScheduleRebuild(): void { $this->viewBuildOrchestrator->invalidateViewDoneAndScheduleRebuild(); }


    public function invalidateViewDoneServeableCache(): void { $this->viewBuildOrchestrator->invalidateViewDoneServeableCacheBridge(); }
    public function runStagedSqlFile(string $relativePath, array $extraTranslations = array()): void { $this->viewBuildOrchestrator->runStagedSqlFile($relativePath, $extraTranslations); }

    public function normalizeViewWarmupState($state): array {
        $default = array(
            'status' => 'idle',
            'stage' => 'rows',
            'stage_started_at' => 0,
            'stage_completed_at' => 0,
            'attempts_by_stage' => array('rows' => 0, 'count' => 0),
            'timings_by_stage' => array(
                'rows' => array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => ''),
                'count' => array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => ''),
            ),
        );
        if (!is_array($state)) {
            return $default;
        }
        $out = array_merge($default, $state);
        $attempts = is_array($out['attempts_by_stage']) ? $out['attempts_by_stage'] : array();
        $out['attempts_by_stage'] = array(
            'rows' => is_scalar($attempts['rows'] ?? 0) ? intval($attempts['rows'] ?? 0) : 0,
            'count' => is_scalar($attempts['count'] ?? 0) ? intval($attempts['count'] ?? 0) : 0,
        );
        $timings = is_array($out['timings_by_stage']) ? $out['timings_by_stage'] : array();
        $out['timings_by_stage'] = array(
            'rows' => $this->normalizeViewWarmupStageTiming($timings['rows'] ?? null),
            'count' => $this->normalizeViewWarmupStageTiming($timings['count'] ?? null),
        );
        return $out;
    }

    private function normalizeViewWarmupStageTiming($timing): array {
        $default = array('last_ms' => 0, 'max_ms' => 0, 'last_completed_at' => 0, 'last_error' => '');
        if (!is_array($timing)) {
            return $default;
        }
        $out = array_merge($default, $timing);
        $out['last_ms'] = is_scalar($out['last_ms']) ? intval($out['last_ms']) : 0;
        $out['max_ms'] = is_scalar($out['max_ms']) ? intval($out['max_ms']) : 0;
        $out['last_completed_at'] = is_scalar($out['last_completed_at']) ? intval($out['last_completed_at']) : 0;
        $out['last_error'] = is_string($out['last_error']) ? $out['last_error'] : '';
        return $out;
    }


    /**
     * Routes method calls to the extracted sub-service that owns them.
     *
     * DatabaseCore is intentionally absent from the delegate chain: its methods
     * are DB-infrastructure concerns and must be reached via DatabaseCoreInterface,
     * not through this facade. Calls for relocated DatabaseCore or ViewRead
     * methods fall through to the trailing "method not found" branch.
     *
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     * @throws \BadMethodCallException
     */
    public function __call(string $name, array $arguments) {
        $delegates = [
            $this->redirectsRepo,
            $this->getRetentionService(),
            $this->contentRepo,
            $this->viewBuildOrchestrator,
            $this->viewReadService,
        ];
        foreach ($delegates as $delegate) {
            if ($delegate === null) {
                continue;
            }
            if (method_exists($delegate, $name)) {
                return $delegate->$name(...$arguments);
            }
        }
        throw new \BadMethodCallException(
            'Method ' . $name . '() not found on ' . static::class . ' or its sub-services.'
        );
    }

    /** @return string */
    public function stageFailurePolicy(): string {
        return 'database-core-classifier';
    }

    /** @return self */
    public static function getInstance() {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // If the DI container is initialized, prefer it.
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $resolved = ABJ_404_Solution_ServiceContainer::safeGet('data_access');
            if ($resolved instanceof self) {
                self::$instance = $resolved;
                return self::$instance;
            }
        }

        // For backward compatibility, create with no arguments
        // The constructor will use getInstance() for dependencies
        self::$instance = new ABJ_404_Solution_DataAccess();

        return self::$instance;
    }

    /**
     * Check if a database table exists.
     *
     * Fix for missing table error (reported by 2 users - 4% of errors)
     * This prevents crashes when querying tables that don't exist or have
     * incorrect table prefixes, returning false instead of causing fatal errors.
     *
     * @param string $tableName Full table name to check (including prefix)
     * @return bool True if table exists, false otherwise
     */
    private function tableExists($tableName) {
        return $this->dbCore->tableExists($tableName);
    }

    /**
     * Get the column names of an actual database table via SHOW COLUMNS.
     * Returns empty array on failure (table missing, permissions, etc.)
     * so callers can fall back to their default behavior.
     *
     * @param string $tableName Full table name (including prefix)
     * @return array<int, string>
     */
    private function getTableColumnNames(string $tableName): array {
        return $this->dbCore->getTableColumnNames($tableName);
    }

}
