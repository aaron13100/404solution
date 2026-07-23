<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Composition root and singleton lifecycle for the database/repository/view
 * collaborator graph.
 *
 * Exposes typed accessors so callers obtain a concrete collaborator
 * (DatabaseCore, ContentRepository, RedirectsRepository,
 * RedirectsRetentionService, LogsRepository, StatsRepository,
 * ViewReadService) without depending on this class
 * to dispatch unrelated work for them. Collaborators are resolved on demand
 * by the plugin classmap autoloader registered in 404-solution.php
 * (production) and tests/bootstrap.php (tests); manual require_once wiring
 * at parse time is intentionally absent (see
 * tests/DataAccessRequireTimeWiringTest.php for the structural guard).
 *
 * Three classes of legacy facade surface were removed by the 2026-06-06
 * audit (M200) narrowing. Each was a test-only compatibility shim that
 * recreated god-object coupling:
 *   1. 33 constant re-exports from {LogsRepository, StatsRepository,
 *      RedirectsRepository, ViewReadRuntimeState, DatabaseRuntimeState}.
 *      Tests now reach the owning class directly.
 *   2. getPostOrGetSanitize / getPostOrGetSanitizeUrl request-sanitization
 *      fallbacks. The authoritative implementation lives on
 *      RequestInputNormalizer; no production caller routed through
 *      DataAccess.
 *   3. stageFailurePolicy() vestigial classifier marker. The staged
 *      view-build pipeline it classified for was itself removed (denorm
 *      Step 3e-A); the classifier and its policy config were deleted
 *      as dead code rather than re-exported here.
 * The __call() rejector below is the structural guard preventing ad-hoc
 * pass-throughs from being re-added.
 */
class ABJ_404_Solution_DataAccess {

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

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

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
     * @param mixed $logging
     * @return ABJ_404_Solution_Logging
     */
    private function resolveLogger($logging) {
        if ($logging instanceof ABJ_404_Solution_Logging) {
            return $logging;
        }
        // Accept duck-typed test spy loggers (warn/errorMessage/debugMessage)
        // so per-test log-level assertions can observe what production code
        // dispatched. Without this, the strict instanceof check above silently
        // drops the spy and DAO sub-services capture the production singleton
        // instead, making warn() / errorMessage() invisible to the test.
        // The chain below ($contentRepo, $redirectsRepo, $logsRepo,
        // $statsRepo, $viewReadService, and DbCore
        // including its recovery sub-services) accept untyped $logger
        // parameters, so the spy reaches all of them.
        if (is_object($logging) && method_exists($logging, 'warn') && method_exists($logging, 'errorMessage')) {
            /** @var ABJ_404_Solution_Logging $logging */
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
     * @return ABJ_404_Solution_StatsRepositoryInterface
     */
    public function getStatsRepo(): ABJ_404_Solution_StatsRepositoryInterface {
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
        }
        return $this->viewReadService;
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

    /**
     * Return the current singleton instance without consulting the container
     * or building a new one. Used by `abj_service()` to honor a test-installed
     * singleton override without forcing the container to cache a stale
     * binding. Mirrors the pattern on PluginLogic / Logging.
     *
     * @return self|null
     */
    public static function peekInstance() {
        return self::$instance;
    }

    /**
     * Install a singleton instance directly. Symmetric with `peekInstance()`;
     * the canonical seam for tests that need to swap in a test double, and
     * for callers that have already constructed a fully configured instance.
     * Pass `null` to clear the cached singleton.
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
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
