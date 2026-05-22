<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewBuildCollaborator.php';
require_once __DIR__ . '/ViewQueriesStaged.php';
require_once __DIR__ . '/ViewBuildStageCallbacks.php';
require_once __DIR__ . '/ViewBuildStageRunner.php';
require_once __DIR__ . '/ViewBuildStartedWatermark.php';
require_once __DIR__ . '/ViewBuildAdaptive.php';
require_once __DIR__ . '/ViewBuildHelpers.php';
require_once __DIR__ . '/ViewBuildLockAndCron.php';
require_once __DIR__ . '/ViewBuildPhpEnvProbe.php';
require_once __DIR__ . '/ViewBuildSessionEnvProbe.php';
require_once __DIR__ . '/ViewBuildHostFailurePolicy.php';
require_once __DIR__ . '/ViewBuildForceRestart.php';
require_once __DIR__ . '/MutationWatermarkSeam.php';
require_once __DIR__ . '/AdminMutationGate.php';
require_once __DIR__ . '/DatabaseRuntimeState.php';
require_once __DIR__ . '/ViewReadRuntimeState.php';
require_once __DIR__ . '/DatabaseConnectionManager.php';
require_once __DIR__ . '/DatabaseQueryTimeoutManager.php';
require_once __DIR__ . '/ViewBuildOrchestratorInterface.php';
require_once __DIR__ . '/ViewBuildOrchestrator.php';
require_once __DIR__ . '/ViewReadServiceInterface.php';
require_once __DIR__ . '/ViewReadService.php';
require_once __DIR__ . '/LogsRepositoryInterface.php';
require_once __DIR__ . '/LogsRepository.php';
require_once __DIR__ . '/StatsRepositoryInterface.php';
require_once __DIR__ . '/StatsRepository.php';
require_once __DIR__ . '/ContentRepositoryInterface.php';
require_once __DIR__ . '/ContentRepository.php';
require_once __DIR__ . '/RedirectsRepositoryInterface.php';
require_once __DIR__ . '/RedirectsRepository.php';
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

class ABJ_404_Solution_DataAccess implements ABJ_404_Solution_ContentRepositoryInterface {

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

    /** @var ABJ_404_Solution_ContentRepository The extracted content/cache repository. */
    private $contentRepo;

    /** @var ABJ_404_Solution_RedirectsRepository The extracted redirects repository. */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_LogsRepository The extracted logs repository. */
    private $logsRepo;

    /** @var ABJ_404_Solution_StatsRepository The extracted stats repository. */
    private $statsRepo;

    /** @var ABJ_404_Solution_ViewReadService The extracted view read service (Phase 6). */
    private $viewReadService;

    /** @var ABJ_404_Solution_ViewBuildOrchestrator The extracted view build orchestrator (Phase 7). */
    private $viewBuildOrchestrator;

    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        ABJ_404_Solution_ViewReadService::setViewSnapshotTableEnsured($value);
    }

    /**
     * Delegate to DatabaseCore for backward compatibility.
     *
     * @param bool $value
     * @return void
     */
    public static function setSetStatementWrapperUnsupported(bool $value): void {
        ABJ_404_Solution_DatabaseCore::setSetStatementWrapperUnsupported($value);
    }

    /** @return bool */
    public static function isSetStatementWrapperUnsupported(): bool {
        return ABJ_404_Solution_DatabaseCore::isSetStatementWrapperUnsupported();
    }

    /** @return void */
    public static function resetViewBuildOncePerRequestGuard(): void {
        ABJ_404_Solution_ViewBuildOrchestrator::resetViewBuildOncePerRequestGuard();
    }

    /** @param string $url @return string */
    public static function computeRedirectsCanonicalUrl($url): string {
        return ABJ_404_Solution_RedirectsRepository::computeRedirectsCanonicalUrl($url);
    }

    /** @param string $columnExpr @return string */
    public static function hitsCanonicalUrlSqlExpression(string $columnExpr): string {
        return ABJ_404_Solution_RedirectsRepository::hitsCanonicalUrlSqlExpression($columnExpr);
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

    /** @var bool|null Legacy per-request cache for DAO-shaped test subclasses. */
    private $legacyViewDoneServeableCache = null;

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
        $this->f = is_object($functions) && method_exists($functions, 'strtolower') ? $functions : abj_service('functions');
        $this->logger = is_object($logging) && (method_exists($logging, 'debugMessage') || method_exists($logging, 'errorMessage')) ? $logging : abj_service('logging');

        if ($dbCore !== null) {
            $this->dbCore = $dbCore;
        } else if (get_class($this) !== __CLASS__
            && method_exists($this, 'queryAndGetResults')
            && (new \ReflectionMethod($this, 'queryAndGetResults'))->getDeclaringClass()->getName() !== __CLASS__) {
            $owner = $this;
            $this->dbCore = new class($owner, $this->f, $this->logger) extends ABJ_404_Solution_DatabaseCore {
                private $owner;
                public function __construct($owner, $functions, $logger) {
                    $this->owner = $owner;
                    parent::__construct($functions, $logger);
                }
                public function queryAndGetResults($query, $options = array()): array {
                    return $this->owner->queryAndGetResults($query, $options);
                }
                public function doTableNameReplacements($query): string {
                    if (method_exists($this->owner, 'doTableNameReplacements')
                        && (new \ReflectionMethod($this->owner, 'doTableNameReplacements'))->getDeclaringClass()->getName() !== 'ABJ_404_Solution_DataAccess') {
                        return (string)$this->owner->doTableNameReplacements($query);
                    }
                    return parent::doTableNameReplacements($query);
                }
                public function tableExists($tableName): bool {
                    if (method_exists($this->owner, 'tableExists')
                        && (new \ReflectionMethod($this->owner, 'tableExists'))->getDeclaringClass()->getName() !== 'ABJ_404_Solution_DataAccess') {
                        return (bool)$this->owner->tableExists($tableName);
                    }
                    return parent::tableExists($tableName);
                }
                public function getLowercasePrefix(): string {
                    if (method_exists($this->owner, 'getLowercasePrefix')
                        && (new \ReflectionMethod($this->owner, 'getLowercasePrefix'))->getDeclaringClass()->getName() !== 'ABJ_404_Solution_DataAccess') {
                        return (string)$this->owner->getLowercasePrefix();
                    }
                    return parent::getLowercasePrefix();
                }
            };
        } else {
            $this->dbCore = new ABJ_404_Solution_DatabaseCore($this->f, $this->logger);
        }
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

        if ($logsRepo !== null) {
            $this->logsRepo = $logsRepo;
        } else if (get_class($this) !== __CLASS__
            && ((method_exists($this, 'logsHitsTableExists')
                    && (new \ReflectionMethod($this, 'logsHitsTableExists'))->getDeclaringClass()->getName() !== __CLASS__)
                || (method_exists($this, 'scheduleHitsTableRebuild')
                    && (new \ReflectionMethod($this, 'scheduleHitsTableRebuild'))->getDeclaringClass()->getName() !== __CLASS__))) {
            $owner = $this;
            $this->logsRepo = new class($owner, $this->dbCore, $this->f, $this->logger) extends ABJ_404_Solution_LogsRepository {
                private $owner;
                public function __construct($owner, $dbCore, $functions, $logger) {
                    $this->owner = $owner;
                    parent::__construct($dbCore, $functions, $logger);
                }
                public function logsHitsTableExists() {
                    return (bool)$this->owner->logsHitsTableExists();
                }
                public function scheduleHitsTableRebuild(): void {
                    $this->owner->scheduleHitsTableRebuild();
                }
            };
        } else {
            $this->logsRepo = new ABJ_404_Solution_LogsRepository($this->dbCore, $this->f, $this->logger);
        }

        if ($statsRepo !== null) {
            $this->statsRepo = $statsRepo;
        } else if (get_class($this) !== __CLASS__
            && method_exists($this, 'getStatsCount')
            && (new \ReflectionMethod($this, 'getStatsCount'))->getDeclaringClass()->getName() !== __CLASS__) {
            $owner = $this;
            $this->statsRepo = new class($owner, $this->dbCore, $this->logsRepo, $this->f, $this->logger) extends ABJ_404_Solution_StatsRepository {
                private $owner;
                public function __construct($owner, $dbCore, $logsRepo, $functions, $logger) {
                    $this->owner = $owner;
                    parent::__construct($dbCore, $logsRepo, $functions, $logger);
                }
                public function getStatsCount($query, array $valueParams) {
                    return $this->owner->getStatsCount($query, $valueParams);
                }
            };
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

        if ($viewBuildOrchestrator !== null) {
            $this->viewBuildOrchestrator = $viewBuildOrchestrator;
        } else if (get_class($this) !== __CLASS__
            && ((method_exists($this, 'runRedirectsForViewStaged')
                    && (new \ReflectionMethod($this, 'runRedirectsForViewStaged'))->getDeclaringClass()->getName() !== __CLASS__)
                || (method_exists($this, 'advanceViewBuildOnce')
                    && (new \ReflectionMethod($this, 'advanceViewBuildOnce'))->getDeclaringClass()->getName() !== __CLASS__)
                || (method_exists($this, 'runPageLoadFallbackAdvance')
                    && (new \ReflectionMethod($this, 'runPageLoadFallbackAdvance'))->getDeclaringClass()->getName() !== __CLASS__)
                || (method_exists($this, 'viewDoneIsServeable')
                    && (new \ReflectionMethod($this, 'viewDoneIsServeable'))->getDeclaringClass()->getName() !== __CLASS__))) {
            $owner = $this;
            $this->viewBuildOrchestrator = new class($owner, $this->dbCore, $this->f, $this->logger) extends ABJ_404_Solution_ViewBuildOrchestrator {
                private $owner;
                public function __construct($owner, $dbCore, $functions, $logger) {
                    $this->owner = $owner;
                    parent::__construct($dbCore, $functions, $logger);
                }
                public function runRedirectsForViewStaged(string $sub, array $tableOptions): array {
                    return $this->owner->runRedirectsForViewStaged($sub, $tableOptions);
                }
                public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int {
                    return $this->owner->runRedirectsForViewCountStaged($sub, $tableOptions);
                }
                public function advanceViewBuildOnce(bool $forceRebuild = false): array {
                    if (method_exists($this->owner, 'advanceViewBuildOnce')
                        && (new \ReflectionMethod($this->owner, 'advanceViewBuildOnce'))->getDeclaringClass()->getName() !== 'ABJ_404_Solution_DataAccess') {
                        return $this->owner->advanceViewBuildOnce($forceRebuild);
                    }
                    return parent::advanceViewBuildOnce($forceRebuild);
                }
                public function runPageLoadFallbackAdvance(): array {
                    if (method_exists($this->owner, 'runPageLoadFallbackAdvance')
                        && (new \ReflectionMethod($this->owner, 'runPageLoadFallbackAdvance'))->getDeclaringClass()->getName() !== 'ABJ_404_Solution_DataAccess') {
                        return $this->owner->runPageLoadFallbackAdvance();
                    }
                    return parent::runPageLoadFallbackAdvance();
                }
                public function viewDoneIsServeable(): bool {
                    if (method_exists($this->owner, 'viewDoneIsServeable')
                        && (new \ReflectionMethod($this->owner, 'viewDoneIsServeable'))->getDeclaringClass()->getName() !== 'ABJ_404_Solution_DataAccess') {
                        return (bool)$this->owner->viewDoneIsServeable();
                    }
                    return parent::viewDoneIsServeable();
                }
            };
        } else {
            $this->viewBuildOrchestrator = new ABJ_404_Solution_ViewBuildOrchestrator(
                $this->dbCore, $this->f, $this->logger, $this->resolveRebuildHealthState()
            );
        }
        $this->viewBuildOrchestrator->setViewReadService($this->viewReadService);
        $this->viewBuildOrchestrator->setLogsRepository($this->logsRepo);
        $this->viewReadService->setViewBuildOrchestrator($this->viewBuildOrchestrator);
    }

    /** @return ABJ_404_Solution_DatabaseCore */
    public function getDbCore(): ABJ_404_Solution_DatabaseCore {
        if ($this->dbCore === null) {
            $this->dbCore = new ABJ_404_Solution_DatabaseCore($this->f, $this->logger);
        }
        return $this->dbCore;
    }

    public function queryAndGetResults($query, $options = array()) {
        return $this->getDbCore()->queryAndGetResults($query, $options);
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

    public function queryScalarInt($query, $options = array()): int {
        return $this->getDbCore()->queryScalarInt($query, $options);
    }

    public function doTableNameReplacements($query): string {
        return $this->getDbCore()->doTableNameReplacements($query);
    }

    public function getLowercasePrefix(): string {
        return $this->getDbCore()->getLowercasePrefix();
    }

    public function getPrefixedTableName($tableSuffix): string {
        return $this->getDbCore()->getPrefixedTableName($tableSuffix);
    }

    /** @param string $query @return string */
    public function extractSqlFilename($query): string {
        return $this->getDbCore()->extractSqlFilename($query);
    }

    /** @param string $errorText @return bool */
    public function classifyAndHandleInfrastructureError(string $errorText): bool {
        return $this->getDbCore()->classifyAndHandleInfrastructureError($errorText);
    }

    /** @param mixed $errorText @return bool */
    public function isInvalidDataError($errorText): bool {
        return $this->getDbCore()->isInvalidDataError($errorText);
    }

    /** @param string $errorText @return bool */
    public function isCollationError(string $errorText): bool {
        return $this->getDbCore()->isCollationError($errorText);
    }

    /** @return string */
    public function diagnosePrefixMismatch(): string {
        return $this->getDbCore()->diagnosePrefixMismatch();
    }

    /** @param string $errorText @return bool */
    public function isMultisiteCrossPrefixError(string $errorText): bool {
        return $this->getDbCore()->isMultisiteCrossPrefixError($errorText);
    }

    public function isDeadlockOrLockTimeoutError(string $errorText): bool {
        return $this->getDbCore()->isDeadlockOrLockTimeoutError($errorText);
    }

    public function isTransientConnectionError(?string $errorText): bool { return $this->getDbCore()->isTransientConnectionError($errorText); }
    public function isQuotaLimitError(string $errorText): bool { return $this->getDbCore()->isQuotaLimitError($errorText); }
    public function isDiskFullError(string $errorText): bool { return $this->getDbCore()->isDiskFullError($errorText); }
    public function isReadOnlyError(string $errorText): bool { return $this->getDbCore()->isReadOnlyError($errorText); }
    public function isCrashedTableError(string $errorText): bool { return $this->getDbCore()->isCrashedTableError($errorText); }
    public function isIncorrectKeyFileError(string $errorText): bool { return $this->getDbCore()->isIncorrectKeyFileError($errorText); }
    public function isGaleraConflictError(string $errorText): bool { return $this->getDbCore()->isGaleraConflictError($errorText); }
    public function isMissingPluginTableError(string $errorText): bool { return $this->getDbCore()->isMissingPluginTableError($errorText); }
    public function isTransientViewBuildTableError(string $errorText): bool { return $this->getDbCore()->isTransientViewBuildTableError($errorText); }
    public function noteDatabaseIssueFromError(string $errorText): void { $this->getDbCore()->noteDatabaseIssueFromError($errorText); }
    public function isWriteBlockActive(): bool { return $this->getDbCore()->isWriteBlockActive(); }
    public function isQuotaCooldownActive(): bool { return $this->getDbCore()->isQuotaCooldownActive(); }
    public function getRuntimeFlag(string $name) { return $this->getDbCore()->getRuntimeFlag($name); }
    public function setRuntimeFlag(string $name, $value, int $ttlSeconds = 0): void { $this->getDbCore()->setRuntimeFlag($name, $value, $ttlSeconds); }
    public function setPluginDbNotice(string $type, string $message, string $errorString = ''): void { $this->getDbCore()->setPluginDbNotice($type, $message, $errorString); }
    public function attemptMissingTableRepairAndRetry($query, array &$result): void { $this->getDbCore()->attemptMissingTableRepairAndRetry($query, $result); }

    public function getPostOrGetSanitize($name, $defaultValue = null) {
        if (is_object($this->f) && method_exists($this->f, 'getPostOrGetSanitize')) {
            return $this->f->getPostOrGetSanitize($name, $defaultValue);
        }
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        if ($returnValue === null && $name === 'action') {
            $returnValue = isset($_GET['abj404action']) ? $_GET['abj404action'] : (isset($_POST['abj404action']) ? $_POST['abj404action'] : null);
        }
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

    /** @return ABJ_404_Solution_ContentRepository */
    public function getContentRepo(): ABJ_404_Solution_ContentRepository {
        if ($this->contentRepo === null) {
            $this->contentRepo = new ABJ_404_Solution_ContentRepository($this->getDbCore(), $this->f, $this->logger);
        }
        return $this->contentRepo;
    }

    public function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '',
        $limitResults = '', $orderResults = '', $extraWhereClause = '') {
        return $this->getContentRepo()->getPublishedPagesAndPostsIDs(
            $slug, $searchTerm, $limitResults, $orderResults, $extraWhereClause
        );
    }

    /** @return array<int, object> */
    public function getPublishedImagesIDs() {
        return $this->getContentRepo()->getPublishedImagesIDs();
    }

    public function getPublishedTags($slug = null, $limit = null) {
        return $this->getContentRepo()->getPublishedTags($slug, $limit);
    }

    public function addURLToTermsRows($rows) {
        return $this->getContentRepo()->addURLToTermsRows($rows);
    }

    public function getPublishedCategories($term_id = null, $slug = null, $limit = null) {
        return $this->getContentRepo()->getPublishedCategories($term_id, $slug, $limit);
    }

    public function truncatePermalinkCacheTable(): void {
        $this->getContentRepo()->truncatePermalinkCacheTable();
    }

    public function removeFromPermalinkCache(int $post_id): void {
        $this->getContentRepo()->removeFromPermalinkCache($post_id);
    }

    public function getPermalinkFromCache($id) {
        return $this->getContentRepo()->getPermalinkFromCache($id);
    }

    public function getPermalinksByIds(array $ids) {
        return $this->getContentRepo()->getPermalinksByIds($ids);
    }

    public function getPermalinkEtcFromCache($id) {
        return $this->getContentRepo()->getPermalinkEtcFromCache($id);
    }

    public function getIDsNeededForPermalinkCache() {
        return $this->getContentRepo()->getIDsNeededForPermalinkCache();
    }

    public function storeSpellingPermalinksToCache(string $requestedURLRaw, $returnValue): void {
        $this->getContentRepo()->storeSpellingPermalinksToCache($requestedURLRaw, $returnValue);
    }

    public function getSpellingPermalinksFromCache(string $requestedURLRaw) {
        return $this->getContentRepo()->getSpellingPermalinksFromCache($requestedURLRaw);
    }

    public function deleteSpellingCache(): void {
        $this->getContentRepo()->deleteSpellingCache();
    }

    public function getOldSlug($post_id) {
        return $this->getContentRepo()->getOldSlug($post_id);
    }

    public function updatePermalinkCache() {
        return $this->getContentRepo()->updatePermalinkCache();
    }

    public function updatePermalinkCacheParentPages() {
        return $this->getContentRepo()->updatePermalinkCacheParentPages();
    }

    public function getPermalinkCacheCount(): int {
        return $this->getContentRepo()->getPermalinkCacheCount();
    }

    /** @return ABJ_404_Solution_RedirectsRepository */
    public function getRedirectsRepo(): ABJ_404_Solution_RedirectsRepository {
        if ($this->redirectsRepo === null) {
            $this->redirectsRepo = new ABJ_404_Solution_RedirectsRepository($this->getDbCore(), $this->f, $this->logger);
        }
        return $this->redirectsRepo;
    }

    /** @return int */
    public function cleanupOrphanedAutoRedirects(): int {
        return $this->getRedirectsRepo()->cleanupOrphanedAutoRedirects();
    }

    public function deleteRedirect($id) {
        return $this->getRedirectsRepo()->deleteRedirect($id);
    }

    public function setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled = 0, $engine = null, $score = null) {
        return $this->getRedirectsRepo()->setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled, $engine, $score);
    }

    public function getActiveRedirectForURL($url, $degradedMode = false) {
        if (get_class($this) !== __CLASS__
            && (method_exists($this, 'prepare_query_wp') || method_exists($this, 'queryAndGetResults'))) {
            $url = $this->f->sanitizeInvalidUTF8($url);
            if (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8')) {
                return array('id' => 0);
            }
            $logic = abj_service('plugin_logic');
            $candidates = is_object($logic) && method_exists($logic, 'getNormalizedUrlCandidates')
                ? $logic->getNormalizedUrlCandidates($url)
                : array($url);
            foreach ($candidates as $candidate) {
                $url1 = $candidate;
                $url2 = substr($candidate, -1) === '/' ? rtrim($candidate, '/') : $candidate . '/';
                $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPermalinkFromURL.sql");
                $query = $this->prepare_query_wp($query, array("url1" => $url1, "url2" => $url2));
                $query = $this->doTableNameReplacements($query);
                $query = $this->f->doNormalReplacements($query);
                $results = $this->queryAndGetResults($query);
                $rows = is_array($results['rows'] ?? null) ? $results['rows'] : array();
                if (!empty($rows)) {
                    $redirect = array();
                    foreach ($rows[0] as $key => $value) {
                        $redirect[$key] = $value;
                    }
                    if (!isset($redirect['id'])) {
                        $redirect['id'] = 0;
                    }
                    return $redirect;
                }
            }
            return array('id' => 0);
        }
        return $this->getRedirectsRepo()->getActiveRedirectForURL($url, $degradedMode);
    }

    public function getExistingRedirectForURL($url) {
        return $this->getRedirectsRepo()->getExistingRedirectForURL($url);
    }

    public function deleteSpecifiedRedirects() {
        return $this->getRedirectsRepo()->deleteSpecifiedRedirects();
    }

    public function getRedirectConditions(int $redirectId): array {
        return $this->getRedirectsRepo()->getRedirectConditions($redirectId);
    }

    public function saveRedirectConditions(int $redirectId, array $conditions): void {
        $this->getRedirectsRepo()->saveRedirectConditions($redirectId, $conditions);
    }

    public function updateRedirect($type, $dest, $fromURL, $idForUpdate, $redirectCode, $statusType, $startTs = null, $endTs = null) {
        return $this->getRedirectsRepo()->updateRedirect(
            $type,
            $dest,
            $fromURL,
            $idForUpdate,
            $redirectCode,
            $statusType,
            $startTs,
            $endTs
        );
    }

    public function getRedirectsByIDs($ids) {
        return $this->getRedirectsRepo()->getRedirectsByIDs($ids);
    }

    public function updateRedirectTypeStatus($id, $newstatus) {
        return $this->getRedirectsRepo()->updateRedirectTypeStatus($id, $newstatus);
    }

    public function moveRedirectsToTrash($id, $trash) {
        return $this->getRedirectsRepo()->moveRedirectsToTrash($id, $trash);
    }

    public function deleteOldRedirectsCron() {
        return $this->getRedirectsRepo()->deleteOldRedirectsCron();
    }

    public function limitDebugFileSize(): bool {
        return $this->getRedirectsRepo()->limitDebugFileSize();
    }

    public function removeDuplicatesCron(): int {
        return $this->getRedirectsRepo()->removeDuplicatesCron();
    }

    public function autoTrashJunkCapturedUrls(array $options): int {
        return $this->getRedirectsRepo()->autoTrashJunkCapturedUrls($options);
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

    public function isInnoDBTable(string $tableName): bool { return $this->getDbCore()->isInnoDBTable($tableName); }

    public function getLogRecords($tableOptions) { return $this->getLogsRepo()->getLogRecords($tableOptions); }

    public function sanitizeLogEntry(array $entry): ?array { return $this->getLogsRepo()->sanitizeLogEntry($entry); }

    public function populateLogsData($rows) { return $this->getLogsRepo()->populateLogsData($rows); }

    public function getDistinctLoggedUrls(): array { return $this->getLogsRepo()->getDistinctLoggedUrls(); }

    public function getLogsIDandURL($specificURL = '') { return $this->getLogsRepo()->getLogsIDandURL($specificURL); }

    public function getLogsIDandURLLike($specificURL, $limitResults) {
        return $this->getLogsRepo()->getLogsIDandURLLike($specificURL, $limitResults);
    }

    public function queueLogEntry(array $entry): void { $this->getLogsRepo()->queueLogEntry($entry); }

    public function flushLogQueue(): void { $this->getLogsRepo()->flushLogQueue(); }

    public function insertLookupValueAndGetID($valueToInsert) { return $this->getLogsRepo()->insertLookupValueAndGetID($valueToInsert); }

    public function getLookupIDForUser($userName) { return $this->getLogsRepo()->getLookupIDForUser($userName); }

    public function correctDuplicateLookupValues(): void { $this->getLogsRepo()->correctDuplicateLookupValues(); }

    public function getDailyActivityTrend(int $days = 30): array { return $this->getLogsRepo()->getDailyActivityTrend($days); }

    public function logsHitsTableExists() { return $this->getLogsRepo()->logsHitsTableExists(); }

    public function createRedirectsForViewHitsTable(): bool { return $this->getLogsRepo()->createRedirectsForViewHitsTable(); }

    public function scheduleHitsTableRebuild(): void { $this->getLogsRepo()->scheduleHitsTableRebuild(); }

    public function getLogsHitsTableLastUpdated() { return $this->getLogsRepo()->getLogsHitsTableLastUpdated(); }

    public function getLogsHitsTableLastUpdatedHuman() { return $this->getLogsRepo()->getLogsHitsTableLastUpdatedHuman(); }

    public function hitsTableNeedsRebuild() { return $this->getLogsRepo()->hitsTableNeedsRebuild(); }

    public function getMaxLogId() { return $this->getLogsRepo()->getMaxLogId(); }

    public function getMinLogId() { return $this->getLogsRepo()->getMinLogId(); }

    public function getStoredMaxLogId() { return $this->getLogsRepo()->getStoredMaxLogId(); }

    /** @return ABJ_404_Solution_StatsRepository */
    public function getStatsRepo(): ABJ_404_Solution_StatsRepository {
        if ($this->statsRepo === null) {
            $this->statsRepo = new ABJ_404_Solution_StatsRepository($this->getDbCore(), $this->getLogsRepo(), $this->f, $this->logger);
        }
        return $this->statsRepo;
    }

    public function getStatsCount($query, array $valueParams) {
        return $this->getStatsRepo()->getStatsCount($query, $valueParams);
    }

    public function getPeriodicStatsSummary($sinceTimestamp, $notFoundDest = '404') {
        return $this->getStatsRepo()->getPeriodicStatsSummary($sinceTimestamp, $notFoundDest);
    }

    public function getPeriodicStatsSummariesCached($notFoundDest = '404') {
        return $this->getStatsRepo()->getPeriodicStatsSummariesCached($notFoundDest);
    }

    public function getStatsDashboardSnapshot($allowStale = true) {
        return $this->getStatsRepo()->getStatsDashboardSnapshot($allowStale);
    }

    public function refreshStatsDashboardSnapshot($force = false) {
        return $this->getStatsRepo()->refreshStatsDashboardSnapshot($force);
    }

    public function getEarliestLogTimestamp() {
        return $this->getStatsRepo()->getEarliestLogTimestamp();
    }

    public function getTopCapturedForDigest(int $limit): array {
        return $this->getStatsRepo()->getTopCapturedForDigest($limit);
    }

    public function buildTopCapturedForDigestQuery(int $limit): string {
        return $this->getStatsRepo()->buildTopCapturedForDigestQuery($limit);
    }

    public function getDigestSummaryStats(): array {
        return $this->getStatsRepo()->getDigestSummaryStats();
    }

    public function getCapturedCountForNotification(): int {
        return $this->getStatsRepo()->getCapturedCountForNotification();
    }

    public function getPostsNeedingContentKeywords(int $limit = 500): array {
        return $this->getStatsRepo()->getPostsNeedingContentKeywords($limit);
    }

    public function bulkUpdateContentKeywords(array $idToKeywords): void {
        $this->getStatsRepo()->bulkUpdateContentKeywords($idToKeywords);
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

    /** @return void */
    public function claimForegroundViewBuildLease(): void { $this->viewBuildOrchestrator->claimForegroundViewBuildLease(); }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array { return $this->viewBuildOrchestrator->runRedirectsForViewStaged($sub, $tableOptions); }

    public function getRedirectStatusCounts($bypassCache = false): array {
        return $this->getViewReadService()->getRedirectStatusCounts($bypassCache);
    }

    public function getCapturedStatusCounts($bypassCache = false): array {
        return $this->getViewReadService()->getCapturedStatusCounts($bypassCache);
    }

    public function getHighImpactCapturedCount(): int {
        return $this->getViewReadService()->getHighImpactCapturedCount();
    }

    public function getLogsCount($logID) {
        return $this->getViewReadService()->getLogsCount($logID);
    }

    public function getRedirectsAll() {
        return $this->getViewReadService()->getRedirectsAll();
    }

    public function getRedirectsWithLogs() {
        return $this->getViewReadService()->getRedirectsWithLogs();
    }

    public function getRedirectsWithRegEx() {
        return $this->getViewReadService()->getRedirectsWithRegEx();
    }

    public function getManualRedirectsWithRegexMetachars() {
        return $this->getViewReadService()->getManualRedirectsWithRegexMetachars();
    }

    public function getRedirectsForView($sub, $tableOptions) {
        return $this->getViewReadService()->getRedirectsForView($sub, $tableOptions);
    }

    public function getRedirectsForViewCount(string $sub, array $tableOptions): int {
        return $this->getViewReadService()->getRedirectsForViewCount($sub, $tableOptions);
    }

    public function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce, $limitStart, $limitEnd, $selectCountOnly) {
        return $this->getViewReadService()->getRedirectsForViewQuery(
            $sub,
            $tableOptions,
            $queryAllRowsAtOnce,
            $limitStart,
            $limitEnd,
            $selectCountOnly
        );
    }

    public function getTableEngines() { return $this->getViewReadService()->getTableEngines(); }

    public function invalidateStatusCountsCache(): void {
        $this->getViewReadService()->invalidateStatusCountsCache();
    }

    public function invalidateViewSnapshotCache(): void {
        $this->getViewReadService()->invalidateViewSnapshotCache();
    }

    /** @return bool */
    public function viewDoneIsServeable(): bool {
        if (get_class($this) !== __CLASS__ && method_exists($this, 'queryAndGetResults')) {
            if ($this->legacyViewDoneServeableCache !== null) {
                return $this->legacyViewDoneServeableCache;
            }
            $table = $this->getDbCore()->doTableNameReplacements('{wp_abj404_view_done}');
            $tableCheck = $this->queryAndGetResults("SHOW TABLES LIKE '" . $table . "'", array('log_errors' => false));
            if (empty($tableCheck['rows'])) {
                $this->legacyViewDoneServeableCache = false;
                return $this->legacyViewDoneServeableCache;
            }

            $observed = function_exists('get_option') ? (int)get_option($this->mutationWatermarkObservedByAdminActionOptionName(), 0) : 0;
            $observedAt = function_exists('get_option') ? (int)get_option($this->mutationWatermarkObservedByAdminActionAtOptionName(), 0) : 0;
            $built = function_exists('get_option') ? (int)get_option($this->builtWatermarkOptionName(), 0) : 0;
            $sanity = defined('ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_MUTATION_INVALIDATED_SANITY_SECONDS')
                ? ABJ_404_Solution_ViewBuildConfig::VIEW_DONE_MUTATION_INVALIDATED_SANITY_SECONDS
                : 300;
            if ($observed > 0 && $built < $observed && $observedAt > 0 && (time() - $observedAt) <= $sanity) {
                $this->legacyViewDoneServeableCache = false;
                return $this->legacyViewDoneServeableCache;
            }

            $rowCheck = $this->queryAndGetResults("SELECT 1 FROM `" . $table . "` LIMIT 1", array('log_errors' => false));
            if (!empty($rowCheck['rows'])) {
                $this->legacyViewDoneServeableCache = true;
                return $this->legacyViewDoneServeableCache;
            }
            $builtAt = function_exists('get_option') ? (int)get_option($this->viewDoneDataBuiltAtOptionName(), 0) : 0;
            $this->legacyViewDoneServeableCache = $builtAt > 0;
            return $this->legacyViewDoneServeableCache;
        }
        return $this->viewBuildOrchestrator->viewDoneIsServeable();
    }

    /** @return int */
    public function getViewDoneBuiltAtTimestamp(): int { return $this->viewBuildOrchestrator->getViewDoneBuiltAtTimestamp(); }

    /** @return void */
    public function markViewDoneBuildCompleted(): void { $this->legacyViewDoneServeableCache = null; $this->viewBuildOrchestrator->markViewDoneBuildCompleted(); }

    /** @return array<string, mixed> */
    public function getViewBuildProgress(): array { return $this->viewBuildOrchestrator->getViewBuildProgress(); }

    /**
     * @param bool $forceRebuild
     * @return array<string, mixed>
     */
    public function advanceViewBuildOnce(bool $forceRebuild = false): array { return $this->viewBuildOrchestrator->advanceViewBuildOnce($forceRebuild); }

    /** @return array{ran:bool, reason:string, progress:array<string,mixed>} */
    public function runPageLoadFallbackAdvance(): array {
        if (get_class($this) !== __CLASS__
            && method_exists($this, 'advanceViewBuildOnce')
            && (new \ReflectionMethod($this, 'advanceViewBuildOnce'))->getDeclaringClass()->getName() !== __CLASS__) {
            if ($this->viewBuildOrchestrator->getCronStuckHours() < 24) { return array('ran' => false, 'reason' => 'cron_healthy', 'progress' => $this->getViewBuildProgress()); }
            if ($this->viewDoneIsServeable()) { return array('ran' => false, 'reason' => 'not_needed', 'progress' => $this->getViewBuildProgress()); }
            $haveTransientApi = function_exists('get_transient') && function_exists('set_transient');
            $gateKey = ABJ_404_Solution_ViewBuildConfig::PAGE_LOAD_FALLBACK_GATE_KEY;
            if ($haveTransientApi && get_transient($gateKey) !== false) { return array('ran' => false, 'reason' => 'gate_active', 'progress' => $this->getViewBuildProgress()); }
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

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int { return $this->viewBuildOrchestrator->runRedirectsForViewCountStaged($sub, $tableOptions); }

    /** @return void */
    public function rebuildViewDoneInBackground(): void { $this->viewBuildOrchestrator->rebuildViewDoneInBackground(); }

    /** @return string */
    public function reconcileStagedTablesAtRunnerStartup(): string { return $this->viewBuildOrchestrator->reconcileStagedTablesAtRunnerStartup(); }

    /**
     * @param string $optionName
     * @param mixed $expected
     * @return bool
     */
    public function verifyOptionWriteCoherent(string $optionName, $expected): bool { return $this->viewBuildOrchestrator->verifyOptionWriteCoherent($optionName, $expected); }

    /** @return void */
    public function capturePrefixAtBuildStart(): void { $this->viewBuildOrchestrator->capturePrefixAtBuildStart(); }

    /** @return bool */
    public function verifyPrefixUnchangedSinceStageOne(): bool { return $this->viewBuildOrchestrator->verifyPrefixUnchangedSinceStageOne(); }

    /** @return void */
    public function clearPrefixAtStageOne(): void { $this->viewBuildOrchestrator->clearPrefixAtStageOne(); }

    /** @return array<string, mixed> */
    public function probeSqlModeForBuild(): array { return $this->viewBuildOrchestrator->probeSqlModeForBuild(); }

    /** @return array<string, mixed> */
    public function detectAndAdjustSqlMode(): array { return $this->viewBuildOrchestrator->detectAndAdjustSqlMode(); }

    /** @param string $url @param int $maxLength @return string */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string { return $this->viewBuildOrchestrator->sanitizeUrlBeforeInsert($url, $maxLength); }

    /** @return bool */
    public function verifyBuildLockSerializesWriter(): bool { return $this->viewBuildOrchestrator->verifyBuildLockSerializesWriter(); }

    /** @param int $delaySeconds @return void */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void { $this->viewBuildOrchestrator->scheduleViewDoneRebuild($delaySeconds); }

    /** @return array<string, mixed> */
    public function probePhpEnvironmentForBuild(): array { return $this->viewBuildOrchestrator->probePhpEnvironmentForBuild(); }

    /** @return bool */
    public function probeSetTimeLimitAvailability(): bool { return $this->viewBuildOrchestrator->probeSetTimeLimitAvailability(); }

    /** @return int */
    public function probeMemoryLimitForS9(): int { return $this->viewBuildOrchestrator->probeMemoryLimitForS9(); }

    /** @return array<string, mixed> */
    public function probeFilesystemEnvironmentForBuild(): array { return $this->viewBuildOrchestrator->probeFilesystemEnvironmentForBuild(); }

    /** @return void */
    public function clearStagedBuildDegradedState(): void { $this->viewBuildOrchestrator->clearStagedBuildDegradedState(); }

    /** @return bool */
    public function reconcilePostStageElevenState(): bool { return $this->viewBuildOrchestrator->reconcilePostStageElevenState(); }

    /** @return array<string, mixed> */
    public function probeSessionVariablesAtS1Entry(): array { return $this->viewBuildOrchestrator->probeSessionVariablesAtS1Entry(); }

    /** @return void */
    public function markViewDoneInvalidatedByAdminMutation(): void { $this->legacyViewDoneServeableCache = null; $this->viewBuildOrchestrator->markViewDoneInvalidatedByAdminMutation(); }

    /** @param int $lockTimeoutSeconds @return bool */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool { return $this->viewBuildOrchestrator->forceRestartViewBuild($lockTimeoutSeconds); }

    /** @return int */
    public function bumpMutationWatermark(): int { return $this->viewBuildOrchestrator->bumpMutationWatermark(); }

    /** @return void */
    public function invalidateViewDoneServeableCacheBridge(): void { $this->viewBuildOrchestrator->invalidateViewDoneServeableCacheBridge(); }

    public function invalidateViewDoneServeableCache(): void { $this->viewBuildOrchestrator->invalidateViewDoneServeableCacheBridge(); }

    public function classifyAndHandleStageFailure(int $stageNumber, string $stageKey, string $errMsg, float $started): string {
        return $this->viewBuildOrchestrator->classifyAndHandleStageFailure($stageNumber, $stageKey, $errMsg, $started);
    }

    public function stageInsertRedirectsBatched(): bool { return $this->viewBuildOrchestrator->stageInsertRedirectsBatched(); }
    public function stageUpdatePostsBatched(): bool { return $this->viewBuildOrchestrator->stageUpdatePostsBatched(); }
    public function stageUpdateTermsBatched(): bool { return $this->viewBuildOrchestrator->stageUpdateTermsBatched(); }
    public function stageUpdateHome(): void { $this->viewBuildOrchestrator->stageUpdateHome(); }
    public function runStagedSqlFile(string $relativePath, array $extraTranslations = array()): void { $this->viewBuildOrchestrator->runStagedSqlFile($relativePath, $extraTranslations); }
    public function runTimedViewBuildStage(int $stageNumber, string $stageKey, callable $callback) { return $this->viewBuildOrchestrator->runTimedViewBuildStage($stageNumber, $stageKey, $callback); }
    public function isStageMarkedSkipped(int $stageNumber): bool { return $this->viewBuildOrchestrator->isStageMarkedSkipped($stageNumber); }

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

    /** @return array<string, mixed> */
    public function getStagedQueryOptionsForRead(): array { return $this->viewBuildOrchestrator->getStagedQueryOptionsForRead(); }

    /** @param string $shortName @param int $default @return int */
    public function readBuildProgressOption(string $shortName, int $default = 0): int { return $this->viewBuildOrchestrator->readBuildProgressOption($shortName, $default); }

    public function readProgressOption(string $shortName, int $default = 0): int { return $this->viewBuildOrchestrator->readProgressOption($shortName, $default); }

    public function viewBuildPerStageBudgetSeconds(): float { return $this->viewBuildOrchestrator->viewBuildPerStageBudgetSeconds(); }

    public function optionReadBackMatches($actual, $expected): bool { return $this->viewBuildOrchestrator->optionReadBackMatches($actual, $expected); }

    public function viewDoneFreshnessOptionName(): string { return $this->viewBuildOrchestrator->viewDoneFreshnessOptionName(); }

    public function viewDoneDataBuiltAtOptionName(): string { return $this->viewBuildOrchestrator->viewDoneDataBuiltAtOptionName(); }

    public function viewDoneMutationInvalidatedAtOptionName(): string { return $this->viewBuildOrchestrator->viewDoneMutationInvalidatedAtOptionName(); }

    public function builtWatermarkOptionName(): string { return $this->viewBuildOrchestrator->builtWatermarkOptionName(); }

    public function mutationWatermarkObservedByAdminActionOptionName(): string { return $this->viewBuildOrchestrator->mutationWatermarkObservedByAdminActionOptionName(); }

    public function mutationWatermarkObservedByAdminActionAtOptionName(): string { return $this->viewBuildOrchestrator->mutationWatermarkObservedByAdminActionAtOptionName(); }

    /**
     * Backward-compatibility bridge for facade delegations removed in Phase 8e.
     * Routes method calls to the extracted sub-service that owns them.
     *
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     * @throws \BadMethodCallException
     */
    public function __call(string $name, array $arguments) {
        $delegates = [
            $this->dbCore,
            $this->logsRepo,
            $this->redirectsRepo,
            $this->contentRepo,
            $this->statsRepo,
            $this->viewBuildOrchestrator,
            $this->viewReadService,
        ];
        foreach ($delegates as $delegate) {
            if ($delegate !== null && method_exists($delegate, $name)) {
                return $delegate->$name(...$arguments);
            }
        }
        throw new \BadMethodCallException(
            'Method ' . $name . '() not found on ' . static::class . ' or its sub-services.'
        );
    }

    /** @param object $wpdb @param bool $allowReconnect @return bool */
    public function safeCheckConnection($wpdb, bool $allowReconnect = false): bool {
        return $this->dbCore->safeCheckConnection($wpdb, $allowReconnect);
    }

    /** @return bool */
    public function ensureConnection() {
        return $this->dbCore->ensureConnection();
    }

    /** @param string $query @return bool */
    public function queryStartsWithSelect(string $query): bool {
        return $this->dbCore->queryStartsWithSelect($query);
    }

    /** @return string */
    public function stageFailurePolicy(): string {
        return 'database-core-classifier';
    }

    /** @param string $query @return bool */
    public function queryProducesResultRows(string $query): bool {
        return $this->dbCore->queryProducesResultRows($query);
    }

    /** @param string $query @param int $timeoutSeconds @return string */
    public function applyQueryTimeout(string $query, int $timeoutSeconds): string {
        return $this->dbCore->applyQueryTimeout($query, $timeoutSeconds);
    }

    /** @return bool */
    public function isMariaDB(): bool {
        return $this->dbCore->isMariaDB();
    }

    /** @param string $query @param int $timeoutSeconds @return string */
    public function applySelectTimeout(string $query, int $timeoutSeconds): string {
        return $this->dbCore->applySelectTimeout($query, $timeoutSeconds);
    }

    /** @param string $query @param int $timeoutSeconds @return string */
    public function applyNonLeadingSelectTimeout(string $query, int $timeoutSeconds): string {
        return $this->dbCore->applyNonLeadingSelectTimeout($query, $timeoutSeconds);
    }

    /** @param string $query @param int $timeoutSeconds @return string */
    public function applyStatementTimeout(string $query, int $timeoutSeconds): string {
        return $this->dbCore->applyStatementTimeout($query, $timeoutSeconds);
    }

    /** @param string $insertSelectQuery @param int $timeoutSeconds @return string */
    public function applyTimeoutToInsertSelect(string $insertSelectQuery, int $timeoutSeconds): string {
        return $this->dbCore->applyTimeoutToInsertSelect($insertSelectQuery, $timeoutSeconds);
    }

    /** @param string $query @return bool */
    public function queryHasSetStatementWrapper(string $query): bool {
        return $this->dbCore->queryHasSetStatementWrapper($query);
    }

    /** @param string $query @return string */
    public function stripSetStatementWrapper(string $query): string {
        return $this->dbCore->stripSetStatementWrapper($query);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType
     * @return void
     */
    public function retryWithoutSetStatementWrapper(string &$query, array &$result, string $resultType): void {
        $this->dbCore->retryWithoutSetStatementWrapper($query, $result, $resultType);
    }

    /**
     * @param ABJ_404_Solution_Clock $clock
     * @return void
     */
    public function setClock(ABJ_404_Solution_Clock $clock): void {
        $this->dbCore->setClock($clock);
    }

    /**
     * Resolve the clock via DatabaseCore.
     *
     * @return ABJ_404_Solution_Clock
     */
    protected function clock(): ABJ_404_Solution_Clock {
        return $this->dbCore->clock();
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

    /** @return array{version: string, last_updated: string|null} */
    function getLatestPluginVersion() {
        // Cache version info to avoid repeated slow wordpress.org API calls.
        $cacheKey = 'abj404_latest_plugin_version_info';
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && isset($cached['version'])) {
                /** @var array{version: string, last_updated: string|null} $cached */
                return $cached;
            }
        }

        if (!function_exists('plugins_api')) {
              require_once(ABSPATH . 'wp-admin/includes/plugin-install.php');
        }
        if (!function_exists('plugins_api')) {
            $this->logger->infoMessage("I couldn't find the plugins_api function to check for the latest version.");
            $fallback = array('version' => ABJ404_VERSION, 'last_updated' => null);
            return $fallback;
        }

        $pluginSlug = dirname(ABJ404_NAME);

        // set the arguments to get latest info from repository via API ##
        $args = array(
            'slug' => $pluginSlug,
            'fields' => array(
                'version' => true,
                'last_updated' => true,
            )
        );

        /** Prepare our query */
        $call_api = plugins_api('plugin_information', $args);

        /** Check for Errors & Display the results */
        if (is_wp_error($call_api)) {
            $api_error = $call_api->get_error_message();
            $this->logger->infoMessage("There was an API issue checking the latest plugin version ("
                    . $api_error . ")");

            $fallback = array('version' => ABJ404_VERSION, 'last_updated' => null);
            return $fallback;
        }

        /** @var object $call_api */
        $apiVersion = property_exists($call_api, 'version') ? (string)$call_api->version : ABJ404_VERSION;
        $apiLastUpdated = property_exists($call_api, 'last_updated') ? (string)$call_api->last_updated : null;
        $result = array('version' => $apiVersion, 'last_updated' => $apiLastUpdated);
        if (function_exists('set_transient')) {
            $ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
            // allow-cache-empty: $result always carries a version string (fallback to ABJ404_VERSION when plugins_api omits it); is_wp_error early-returns above
            set_transient($cacheKey, $result, $ttl);
        }
        return $result;
    }
    
    /** Check wordpress.org for the latest version of this plugin. Return true if the latest version is installed, 
     * false otherwise.
     * @return boolean
     */
    function shouldEmailErrorFile() {
        $abj404logging = abj_service('logging');        
        
        $pluginInfo = $this->getLatestPluginVersion();
        
        $latestVersion = $pluginInfo['version'];
        $currentVersion = ABJ404_VERSION;
        if ($latestVersion == $currentVersion) {
            return true;
        }
        
        if (version_compare(ABJ404_VERSION, $latestVersion) == 1) {
            $this->logger->infoMessage("Development version: A more recent version is installed than " . 
                    "what is available on the WordPress site (" . ABJ404_VERSION . " / " . 
                     $latestVersion . ").");
            return true;
        }
        
        $currentArray = explode(".", $currentVersion);
        $latestArray = explode(".", $latestVersion);
        
        // verify that the version numbers were parsed correctly.
        if (count($currentArray) != 3 || count($latestArray) != 3) {
            $this->logger->errorMessage("Issue parsing version numbers. " . 
                    $currentVersion . ' / ' . $latestVersion);
            
        } else if ($currentArray[0] == $latestArray[0] && $currentArray[1] == $latestArray[1]) {
        	// get the difference in the version numbers.
            $difference = absint(absint($latestArray[2]) - absint($currentArray[2]));
            
            // if the major versions mostly match then send the error file.
            if ($difference <= 1) {
                return true;
            }
        }

        return (ABJ404_VERSION == $pluginInfo['version']);
    }
    
    /**
     * @return array<string, mixed>
     */
    function importDataFromPluginRedirectioner() {
        global $wpdb;
        
        $oldTable = $wpdb->prefix . 'wbz404_redirects';
        $newTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        // wp_wbz404_redirects -- old table
        // wp_abj404_redirects -- new table

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/importDataFromPluginRedirectioner.sql");
        $query = $this->f->str_replace('{OLD_TABLE}', $oldTable, $query);
        $query = $this->f->str_replace('{NEW_TABLE}', $newTable, $query);

        $result = $this->dbCore->queryAndGetResults($query);

        $this->logger->infoMessage("Importing redirectioner SQL result: " . 
                wp_kses_post((string)json_encode($result)));
        
        return $result;
    }
    
}
