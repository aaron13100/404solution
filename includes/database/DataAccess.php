<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Legacy compatibility facade for database, repository, and view services.
 *
 * Collaborator classes (LogsRepository, StatsRepository, ContentRepository,
 * RedirectsRepository, RedirectsRetentionService, ViewReadService,
 * ViewBuildOrchestrator, DatabaseCore, and the view-build/log/stats/redirects
 * support classes) are resolved on demand by the plugin classmap autoloader
 * registered in 404-solution.php (production) and tests/bootstrap.php
 * (tests). Manual require_once wiring at parse time is intentionally absent:
 * pre-loading unrelated subsystems at the facade boundary re-creates the
 * god-object coupling the 2026-06-05 audit (M200) flagged. See
 * tests/DataAccessRequireTimeWiringTest.php for the structural guard.
 */
class ABJ_404_Solution_DataAccess {

    const UPDATE_LOGS_HITS_TABLE_HOOK = ABJ_404_Solution_LogsRepository::UPDATE_LOGS_HITS_TABLE_HOOK;
    const KEY_REDIRECTS_FOR_VIEW_COUNT = 'abj404_redirects-for-view-count';
    const HITS_TABLE_MAX_AGE_SECONDS = ABJ_404_Solution_LogsRepository::HITS_TABLE_MAX_AGE_SECONDS;
    const HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS = ABJ_404_Solution_LogsRepository::HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS;
    const VIEW_SNAPSHOT_CACHE_TTL_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_CACHE_TTL_SECONDS;
    const VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS;
    const VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS;
    const VIEW_SNAPSHOT_WARMUP_STALE_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_STALE_SECONDS;
    const VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS;
    const VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES;
    const HITS_TABLE_REBUILD_LOCK_TTL_SECONDS = ABJ_404_Solution_LogsRepository::HITS_TABLE_REBUILD_LOCK_TTL_SECONDS;
    const HITS_TABLE_PREAGG_CHUNK_SIZE = ABJ_404_Solution_LogsRepository::HITS_TABLE_PREAGG_CHUNK_SIZE;
    const HITS_TABLE_DIRECT_PATH_THRESHOLD = ABJ_404_Solution_LogsRepository::HITS_TABLE_DIRECT_PATH_THRESHOLD;
    const PERIODIC_STATS_CACHE_TTL_SECONDS = ABJ_404_Solution_StatsRepository::PERIODIC_STATS_CACHE_TTL_SECONDS;
    const PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS = ABJ_404_Solution_StatsRepository::PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS;
    const TREND_DATA_CACHE_TTL_SECONDS = ABJ_404_Solution_LogsRepository::TREND_DATA_CACHE_TTL_SECONDS;
    const LOGS_COUNT_CACHE_TTL_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::LOGS_COUNT_CACHE_TTL_SECONDS;
    const STATS_DASHBOARD_CACHE_TTL_SECONDS = ABJ_404_Solution_StatsRepository::STATS_DASHBOARD_CACHE_TTL_SECONDS;
    const STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS = ABJ_404_Solution_StatsRepository::STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS;
    const DB_QUOTA_COOLDOWN_SECONDS = ABJ_404_Solution_DatabaseRuntimeState::DB_QUOTA_COOLDOWN_SECONDS;
    const DB_WRITE_BLOCK_COOLDOWN_SECONDS = ABJ_404_Solution_DatabaseRuntimeState::DB_WRITE_BLOCK_COOLDOWN_SECONDS;
    const HITS_TABLE_LAST_CHECKED_FLAG = ABJ_404_Solution_LogsRepository::HITS_TABLE_LAST_CHECKED_FLAG;
    const HITS_TABLE_LAST_SCHEDULED_FLAG = ABJ_404_Solution_LogsRepository::HITS_TABLE_LAST_SCHEDULED_FLAG;
    const HITS_TABLE_LAST_DECISION_FLAG = ABJ_404_Solution_LogsRepository::HITS_TABLE_LAST_DECISION_FLAG;
    const HITS_TABLE_LAST_REFRESHED_FLAG = ABJ_404_Solution_LogsRepository::HITS_TABLE_LAST_REFRESHED_FLAG;
    const HITS_TABLE_FIRST_STALE_DETECTED_FLAG = ABJ_404_Solution_LogsRepository::HITS_TABLE_FIRST_STALE_DETECTED_FLAG;
    const HITS_TABLE_STALE_NOTICE_TRANSIENT = ABJ_404_Solution_LogsRepository::HITS_TABLE_STALE_NOTICE_TRANSIENT;
    const HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS = ABJ_404_Solution_LogsRepository::HITS_TABLE_STALE_NOTICE_THRESHOLD_SECONDS;

    /** @var self|null */
    private static $instance = null;

    /** @var ABJ_404_Solution_DatabaseCore The extracted database infrastructure layer. */
    private $dbCore;

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
     * ABJ_404_Solution_StatsRepositoryResolver::resolve(). Held on DataAccess
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

    const CACHE_KEY_REDIRECT_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS;
    const CACHE_KEY_CAPTURED_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS;
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED;
    const STATUS_CACHE_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TTL;
    const STATUS_CACHE_TIMEOUT_SELFHEAL_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TIMEOUT_SELFHEAL_TTL;
    const REGEX_CACHE_MAX_COUNT = ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT;

    /**
     * @param ABJ_404_Solution_DataAccessDependencies|null $dependencies
     * @throws InvalidArgumentException when legacy positional arguments are supplied.
     */
    public function __construct(?ABJ_404_Solution_DataAccessDependencies $dependencies = null) {
        if (func_num_args() > 1) {
            throw new InvalidArgumentException(
                'DataAccess constructor accepts a DataAccessDependencies bundle; positional collaborator arguments were removed.'
            );
        }

        $dependencies = $dependencies !== null ? $dependencies : new ABJ_404_Solution_DataAccessDependencies();
        $this->f = self::resolveFunctions($dependencies->functions());
        $this->logger = $this->resolveLogger($dependencies->logging());
        $dbCore = $dependencies->dbCore();
        $this->dbCore = $dbCore !== null ? $dbCore : $this->createDbCore();
        $contentRepo = $dependencies->contentRepo();
        if ($contentRepo !== null) {
            $this->contentRepo = $contentRepo;
        } else {
            $this->contentRepo = new ABJ_404_Solution_ContentRepository($this->dbCore, $this->f, $this->logger);
        }

        $redirectsRepo = $dependencies->redirectsRepo();
        if ($redirectsRepo !== null) {
            $this->redirectsRepo = $redirectsRepo;
        } else {
            $this->redirectsRepo = new ABJ_404_Solution_RedirectsRepository($this->dbCore, $this->f, $this->logger);
        }

        $logsRepo = $dependencies->logsRepo();
        $this->logsRepo = $logsRepo !== null
            ? $logsRepo
            : new ABJ_404_Solution_LogsRepository($this->dbCore, $this->f, $this->logger);

        $statsRepo = $dependencies->statsRepo();
        if ($statsRepo !== null) {
            $this->statsRepo = $statsRepo;
        } else {
            $this->statsRepo = new ABJ_404_Solution_StatsRepository($this->dbCore, $this->logsRepo, $this->f, $this->logger);
        }

        $this->retentionService = $dependencies->retentionService();
        $viewReadService = $dependencies->viewReadService();
        if ($viewReadService !== null) {
            $this->viewReadService = $viewReadService;
        } else {
            $this->viewReadService = new ABJ_404_Solution_ViewReadService(
                $this->dbCore, $this->logsRepo, $this->redirectsRepo, $this->f, $this->logger
            );
        }

        $viewBuildOrchestrator = $dependencies->viewBuildOrchestrator();
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

    /** @return ABJ_404_Solution_LogsRepository */
    public function getLogsRepo(): ABJ_404_Solution_LogsRepository {
        if ($this->logsRepo === null) {
            $this->logsRepo = new ABJ_404_Solution_LogsRepository($this->getDbCore(), $this->f, $this->logger);
        }
        return $this->logsRepo;
    }

    /**
     * Test-only composition surface. Production callers must inject
     * StatsRepositoryInterface via constructor or call
     * ABJ_404_Solution_StatsRepositoryResolver::resolve(); using
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

    /**
     * Rejects calls to removed facade pass-through methods.
     *
     * Extracted repositories and services are intentionally absent from a
     * delegate chain: callers must use the typed accessor/injected interface
     * for the owning collaborator instead of relying on this compatibility
     * facade to redispatch arbitrary public methods.
     *
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     * @throws \BadMethodCallException
     */
    public function __call(string $name, array $arguments) {
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

}
