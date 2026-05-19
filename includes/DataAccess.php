<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DataAccessTrait_Maintenance.php';
require_once __DIR__ . '/DataAccessTrait_Connection.php';
require_once __DIR__ . '/DataAccessTrait_ViewMetadata.php';
require_once __DIR__ . '/DataAccessTrait_ViewQueries.php';
require_once __DIR__ . '/DataAccessTrait_ViewQueriesHitsLifecycle.php';
require_once __DIR__ . '/DataAccessTrait_ViewQueriesStaged.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildStageCallbacks.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildStageRunner.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildStartedWatermark.php';
require_once __DIR__ . '/DataAccessTrait_ViewQueriesStagedRead.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildAdaptive.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildHelpers.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildLockAndCron.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildPhpEnvProbe.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildSessionEnvProbe.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildHostFailurePolicy.php';
require_once __DIR__ . '/DataAccessTrait_ViewBuildForceRestart.php';
require_once __DIR__ . '/DataAccessTrait_MutationWatermarkSeam.php';
require_once __DIR__ . '/DataAccessTrait_AdminMutationGate.php';
require_once __DIR__ . '/DataAccessTrait_ViewSnapshotCache.php';
require_once __DIR__ . '/DataAccessTrait_QueryTimeouts.php';
require_once __DIR__ . '/LogsRepositoryInterface.php';
require_once __DIR__ . '/LogsRepository.php';
require_once __DIR__ . '/DataAccessTrait_Redirects.php';
require_once __DIR__ . '/DataAccessTrait_PublishedContent.php';
require_once __DIR__ . '/DataAccessTrait_Stats.php';
require_once __DIR__ . '/StatsRepositoryInterface.php';
require_once __DIR__ . '/StatsRepository.php';
require_once __DIR__ . '/ContentRepositoryInterface.php';
require_once __DIR__ . '/ContentRepository.php';
require_once __DIR__ . '/RedirectsRepositoryInterface.php';
require_once __DIR__ . '/RedirectsRepository.php';
require_once __DIR__ . '/DataAccessTrait_ErrorClassification.php';
require_once __DIR__ . '/DataAccessTrait_SqlErrorReporting.php';
require_once __DIR__ . '/ViewQueryFailureException.php';
require_once __DIR__ . '/ViewBuildPendingException.php';
require_once __DIR__ . '/DatabaseRepairDelegate.php';
require_once __DIR__ . '/DatabaseCoreInterface.php';
require_once __DIR__ . '/DatabaseCore.php';

/* Functions in this class should all reference one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DataAccess implements ABJ_404_Solution_DatabaseRepairDelegate {

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
    const DB_QUOTA_COOLDOWN_SECONDS = 900;
    /** @var int Cooldown when DB is read-only or storage is full. */
    const DB_WRITE_BLOCK_COOLDOWN_SECONDS = 900;

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

    /** @var bool Ensure view cache table DDL runs at most once per request. */
    private static $viewSnapshotTableEnsured = false;

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

    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        self::$viewSnapshotTableEnsured = $value;
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

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var array<string,int> Request-local cached counts for redirects list views. */
    private $redirectsForViewCountRequestCache = array();

    use ABJ_404_Solution_DataAccess_MaintenanceTrait;
    use ABJ_404_Solution_DataAccess_ViewMetadataTrait;
    use ABJ_404_Solution_DataAccess_ViewQueriesTrait;
    use ABJ_404_Solution_DataAccess_ViewQueriesHitsLifecycleTrait;
    use ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildStageRunnerTrait;
    use ABJ_404_Solution_DataAccess_ViewBuildStageCallbacksTrait;
    use ABJ_404_Solution_DataAccess_ViewQueriesStagedReadTrait;
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
    use ABJ_404_Solution_DataAccess_ViewSnapshotCacheTrait;

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

    /**
     * Constructor with dependency injection.
     * Dependencies are now explicit and visible.
     *
     * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
     * @param ABJ_404_Solution_Logging|null $logging Logging service
     */
    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_DatabaseCore|null $dbCore
     * @param ABJ_404_Solution_ContentRepository|null $contentRepo
     * @param ABJ_404_Solution_RedirectsRepository|null $redirectsRepo
     * @param ABJ_404_Solution_LogsRepository|null $logsRepo
     * @param ABJ_404_Solution_StatsRepository|null $statsRepo
     */
    public function __construct($functions = null, $logging = null, $dbCore = null, $contentRepo = null, $redirectsRepo = null, $logsRepo = null, $statsRepo = null) {
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');

        if ($dbCore !== null) {
            $this->dbCore = $dbCore;
        } else {
            $this->dbCore = new ABJ_404_Solution_DatabaseCore($this->f, $this->logger);
        }
        $this->dbCore->setRepairDelegate($this);

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
        } else {
            $this->logsRepo = new ABJ_404_Solution_LogsRepository($this->dbCore, $this->f, $this->logger);
        }

        if ($statsRepo !== null) {
            $this->statsRepo = $statsRepo;
        } else {
            $this->statsRepo = new ABJ_404_Solution_StatsRepository($this->dbCore, $this->logsRepo, $this->f, $this->logger);
        }
    }

    /** @return ABJ_404_Solution_DatabaseCore */
    public function getDbCore(): ABJ_404_Solution_DatabaseCore {
        return $this->dbCore;
    }

    /** @return ABJ_404_Solution_ContentRepository */
    public function getContentRepo(): ABJ_404_Solution_ContentRepository {
        return $this->contentRepo;
    }

    /** @return ABJ_404_Solution_RedirectsRepository */
    public function getRedirectsRepo(): ABJ_404_Solution_RedirectsRepository {
        return $this->redirectsRepo;
    }

    /** @return ABJ_404_Solution_LogsRepository */
    public function getLogsRepo(): ABJ_404_Solution_LogsRepository {
        return $this->logsRepo;
    }

    /** @return ABJ_404_Solution_StatsRepository */
    public function getStatsRepo(): ABJ_404_Solution_StatsRepository {
        return $this->statsRepo;
    }

    // Facade delegations to StatsRepository (Phase 4 refactor).

    /** @param string $query @param array<int|string, mixed> $valueParams @return int */
    function getStatsCount($query, array $valueParams) { return $this->statsRepo->getStatsCount($query, $valueParams); }

    /**
     * @param int $sinceTimestamp
     * @param string $notFoundDest
     * @return array{disp404:int, distinct404:int, visitors404:int, refer404:int, redirected:int, distinctredirected:int, distinctvisitors:int, distinctrefer:int}
     */
    function getPeriodicStatsSummary($sinceTimestamp, $notFoundDest = '404') { return $this->statsRepo->getPeriodicStatsSummary($sinceTimestamp, $notFoundDest); }

    /**
     * @param string $notFoundDest
     * @return array{today:array<string,int>, month:array<string,int>, year:array<string,int>, all:array<string,int>}
     */
    function getPeriodicStatsSummariesCached($notFoundDest = '404') { return $this->statsRepo->getPeriodicStatsSummariesCached($notFoundDest); }

    /** @param bool $allowStale @return array{refreshed_at:int, hash:string, data:array<string, mixed>} */
    function getStatsDashboardSnapshot($allowStale = true) { return $this->statsRepo->getStatsDashboardSnapshot($allowStale); }

    /** @param bool $force @return array{refreshed_at:int, hash:string, data:array<string, mixed>} */
    function refreshStatsDashboardSnapshot($force = false) { return $this->statsRepo->refreshStatsDashboardSnapshot($force); }

    /** @return int */
    function getEarliestLogTimestamp() { return $this->statsRepo->getEarliestLogTimestamp(); }

    /** @param int $limit @return array<int, array<string, mixed>> */
    function getTopCapturedForDigest(int $limit): array { return $this->statsRepo->getTopCapturedForDigest($limit); }

    /** @param int $limit @return string */
    function buildTopCapturedForDigestQuery(int $limit): string { return $this->statsRepo->buildTopCapturedForDigestQuery($limit); }

    /** @return array{total_captured: int, total_manual: int, total_auto: int} */
    function getDigestSummaryStats(): array { return $this->statsRepo->getDigestSummaryStats(); }

    /** @return int */
    function getCapturedCountForNotification(): int { return $this->statsRepo->getCapturedCountForNotification(); }

    /** @param int $limit @return array<int, object> */
    function getPostsNeedingContentKeywords(int $limit = 500): array { return $this->statsRepo->getPostsNeedingContentKeywords($limit); }

    /** @param array<int, string> $idToKeywords @return void */
    function bulkUpdateContentKeywords(array $idToKeywords): void { $this->statsRepo->bulkUpdateContentKeywords($idToKeywords); }

    // Facade delegations for request parameter sanitization (Phase 4: relocated to Functions).

    /**
     * @param string $name
     * @param string|null $defaultValue
     * @return string
     */
    function getPostOrGetSanitize($name, $defaultValue = null) {
        $f = abj_service('functions');
        return $f->getPostOrGetSanitize($name, $defaultValue);
    }

    /**
     * @param string $name
     * @param string|null $defaultValue
     * @return string|array<string>|null
     */
    function getPostOrGetSanitizeUrl($name, $defaultValue = null) {
        $f = abj_service('functions');
        return $f->getPostOrGetSanitizeUrl($name, $defaultValue);
    }

    // Facade delegations to RedirectsRepository (Phase 2 refactor).

    /** @param int|string $id @return void */
    function deleteRedirect($id) {
        $this->redirectsRepo->deleteRedirect($id);
    }

    /**
     * @param string $fromURL
     * @param string $status
     * @param string $type
     * @param string $final_dest
     * @param string $code
     * @param int $disabled
     * @param string|null $engine
     * @param float|null $score
     * @return int
     */
    function setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled = 0, $engine = null, $score = null) {
        return $this->redirectsRepo->setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled, $engine, $score);
    }

    /**
     * @param string $url
     * @param bool $degradedMode
     * @return array<string, mixed>
     */
    function getActiveRedirectForURL($url, $degradedMode = false) {
        return $this->redirectsRepo->getActiveRedirectForURL($url, $degradedMode);
    }

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    function getExistingRedirectForURL($url) {
        return $this->redirectsRepo->getExistingRedirectForURL($url);
    }

    /** @return string */
    function deleteSpecifiedRedirects() {
        return $this->redirectsRepo->deleteSpecifiedRedirects();
    }

    /**
     * @param int $redirectId
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectConditions(int $redirectId): array {
        return $this->redirectsRepo->getRedirectConditions($redirectId);
    }

    /**
     * @param int $redirectId
     * @param array<int, array<string, mixed>> $conditions
     * @return void
     */
    public function saveRedirectConditions(int $redirectId, array $conditions): void {
        $this->redirectsRepo->saveRedirectConditions($redirectId, $conditions);
    }

    /**
     * @param string $type
     * @param string $dest
     * @param string $fromURL
     * @param int $idForUpdate
     * @param string $redirectCode
     * @param string $statusType
     * @param int|null $startTs
     * @param int|null $endTs
     * @return string
     */
    function updateRedirect($type, $dest, $fromURL, $idForUpdate, $redirectCode, $statusType, $startTs = null, $endTs = null) {
        return $this->redirectsRepo->updateRedirect($type, $dest, $fromURL, $idForUpdate, $redirectCode, $statusType, $startTs, $endTs);
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsByIDs($ids) {
        return $this->redirectsRepo->getRedirectsByIDs($ids);
    }

    /**
     * @param int $id
     * @param string $newstatus
     * @return string
     */
    function updateRedirectTypeStatus($id, $newstatus) {
        return $this->redirectsRepo->updateRedirectTypeStatus($id, $newstatus);
    }

    /**
     * @param int $id
     * @param int $trash
     * @return string
     */
    function moveRedirectsToTrash($id, $trash) {
        return $this->redirectsRepo->moveRedirectsToTrash($id, $trash);
    }

    /** @return int */
    public function cleanupOrphanedAutoRedirects(): int {
        return $this->redirectsRepo->cleanupOrphanedAutoRedirects();
    }

    /** @return string */
    function deleteOldRedirectsCron() {
        return $this->redirectsRepo->deleteOldRedirectsCron();
    }

    /** @return int */
    function removeDuplicatesCron(): int {
        return $this->redirectsRepo->removeDuplicatesCron();
    }

    /** @return bool */
    function limitDebugFileSize(): bool {
        return $this->redirectsRepo->limitDebugFileSize();
    }

    /**
     * @param array<string, mixed> $options
     * @return int
     */
    function autoTrashJunkCapturedUrls(array $options): int {
        return $this->redirectsRepo->autoTrashJunkCapturedUrls($options);
    }

    /**
     * @param mixed $url
     * @return string
     */
    public static function computeRedirectsCanonicalUrl($url): string {
        return ABJ_404_Solution_RedirectsRepository::computeRedirectsCanonicalUrl($url);
    }

    /**
     * @param string $columnExpr
     * @return string
     */
    public static function hitsCanonicalUrlSqlExpression(string $columnExpr): string {
        return ABJ_404_Solution_RedirectsRepository::hitsCanonicalUrlSqlExpression($columnExpr);
    }

    // Facade delegations to ContentRepository (Phase 1 refactor).

    /**
     * @param string $slug
     * @param string $searchTerm
     * @param string $limitResults
     * @param string $orderResults
     * @param string $extraWhereClause
     * @return array<int, object>
     */
    function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '',
        $limitResults = '', $orderResults = '', $extraWhereClause = '') {
        return $this->contentRepo->getPublishedPagesAndPostsIDs($slug, $searchTerm,
            $limitResults, $orderResults, $extraWhereClause);
    }

    /** @return array<int, object> */
    function getPublishedImagesIDs() {
        return $this->contentRepo->getPublishedImagesIDs();
    }

    /**
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    function getPublishedTags($slug = null, $limit = null) {
        return $this->contentRepo->getPublishedTags($slug, $limit);
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    function addURLToTermsRows($rows) {
        return $this->contentRepo->addURLToTermsRows($rows);
    }

    /**
     * @param int|null $term_id
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    function getPublishedCategories($term_id = null, $slug = null, $limit = null) {
        return $this->contentRepo->getPublishedCategories($term_id, $slug, $limit);
    }

    // Facade delegations to ContentRepository: permalink cache (relocated from Stats trait, Phase 4).

    /** @return array<string, mixed> */
    function updatePermalinkCache() { return $this->contentRepo->updatePermalinkCache(); }

    /** @return array<string, mixed> */
    function updatePermalinkCacheParentPages() { return $this->contentRepo->updatePermalinkCacheParentPages(); }

    /** @return int */
    function getPermalinkCacheCount(): int { return $this->contentRepo->getPermalinkCacheCount(); }

    // Facade delegations to LogsRepository (Phase 3 refactor).

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    function populateLogsData($rows) { return $this->logsRepo->populateLogsData($rows); }

    /** @return array<int, string> */
    function getDistinctLoggedUrls(): array { return $this->logsRepo->getDistinctLoggedUrls(); }

    /** @param string $specificURL @return array<int, array<string, mixed>> */
    function getLogsIDandURL($specificURL = '') { return $this->logsRepo->getLogsIDandURL($specificURL); }

    /** @param string $specificURL @param string|int $limitResults @return array<int, array<string, mixed>> */
    function getLogsIDandURLLike($specificURL, $limitResults) { return $this->logsRepo->getLogsIDandURLLike($specificURL, $limitResults); }

    /** @param array<string, mixed> $tableOptions @return array<int, array<string, mixed>> */
    function getLogRecords($tableOptions) { return $this->logsRepo->getLogRecords($tableOptions); }

    /** @param string $lkupValue @param int $page @param int $perPage @return int[] */
    public function getLogsv2IdsForLookupValue($lkupValue, $page = 1, $perPage = 100) { return $this->logsRepo->getLogsv2IdsForLookupValue($lkupValue, $page, $perPage); }

    /** @param string $lkupValue @param int $page @param int $perPage @return array<int, array<string, mixed>> */
    public function getLogsv2RowsForLookupValue($lkupValue, $page = 1, $perPage = 50) { return $this->logsRepo->getLogsv2RowsForLookupValue($lkupValue, $page, $perPage); }

    /** @param int[] $ids @return bool */
    public function anonymizeLogsv2RowsByIds($ids) { return $this->logsRepo->anonymizeLogsv2RowsByIds($ids); }

    function logRedirectHit(string $requested_url, string $action, string $matchReason, ?string $requestedURLDetail = null, ?array $pipelineTrace = null): void { $this->logsRepo->logRedirectHit($requested_url, $action, $matchReason, $requestedURLDetail, $pipelineTrace); }

    function queueLogEntry(array $entry): void { $this->logsRepo->queueLogEntry($entry); }

    function flushLogQueue(): void { $this->logsRepo->flushLogQueue(); }

    /** @param string $valueToInsert @return int */
    function insertLookupValueAndGetID($valueToInsert) { return $this->logsRepo->insertLookupValueAndGetID($valueToInsert); }

    /** @param string $userName @return int */
    function getLookupIDForUser($userName) { return $this->logsRepo->getLookupIDForUser($userName); }

    /** @param int $days @return array<int, array<string, mixed>> */
    public function getDailyActivityTrend(int $days = 30): array { return $this->logsRepo->getDailyActivityTrend($days); }

    /** @param string|null $raw @return array<int, array{step: string, outcome: string, detail: string}>|null */
    public static function decompressPipelineTrace(?string $raw): ?array { return ABJ_404_Solution_LogsRepository::decompressPipelineTrace($raw); }

    function recordLogsHitsRollupStalenessSignal(): void { $this->logsRepo->recordLogsHitsRollupStalenessSignal(); }

    function hitsTableNeedsRebuild() { return $this->logsRepo->hitsTableNeedsRebuild(); }

    function getLogsHitsTableLastUpdated() { return $this->logsRepo->getLogsHitsTableLastUpdated(); }

    function getLogsHitsTableLastUpdatedHuman() { return $this->logsRepo->getLogsHitsTableLastUpdatedHuman(); }

    function createRedirectsForViewHitsTable(): bool { return $this->logsRepo->createRedirectsForViewHitsTable(); }

    function logsHitsTableExists() { return $this->logsRepo->logsHitsTableExists(); }

    function scheduleHitsTableRebuild(): void { $this->logsRepo->scheduleHitsTableRebuild(); }

    function getMaxLogId() { return $this->logsRepo->getMaxLogId(); }

    function getMinLogId() { return $this->logsRepo->getMinLogId(); }

    function getStoredMaxLogId() { return $this->logsRepo->getStoredMaxLogId(); }

    /** @return int|null */
    function getLogsHitsTableLastCheckedAt() { return $this->logsRepo->getLogsHitsTableLastCheckedAt(); }

    /** @return int|null */
    function getLogsHitsTableLastScheduledAt() { return $this->logsRepo->getLogsHitsTableLastScheduledAt(); }

    /** @return string */
    function getLogsHitsTableLastDecision(): string { return $this->logsRepo->getLogsHitsTableLastDecision(); }

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
        $newTable = $this->doTableNameReplacements('{wp_abj404_redirects}');
        // wp_wbz404_redirects -- old table
        // wp_abj404_redirects -- new table
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/importDataFromPluginRedirectioner.sql");
        $query = $this->f->str_replace('{OLD_TABLE}', $oldTable, $query);
        $query = $this->f->str_replace('{NEW_TABLE}', $newTable, $query);
        
        $result = $this->queryAndGetResults($query);

        $this->logger->infoMessage("Importing redirectioner SQL result: " . 
                wp_kses_post((string)json_encode($result)));
        
        return $result;
    }
    
    /**
     * @param string $query
     * @return string
     */
    function doTableNameReplacements($query) {
        return $this->dbCore->doTableNameReplacements($query);
    }

    /**
     * Get the normalized (lowercase) prefix used for all plugin tables.
     * This avoids case-sensitive MySQL filesystems from treating mixed-case
     * prefixes as distinct tables.
     *
     * @return string
     */
    public function getLowercasePrefix() {
        return $this->dbCore->getLowercasePrefix();
    }

    /**
     * Build a fully-qualified plugin table name using the normalized prefix.
     *
     * @param string $tableSuffix Table name without the WordPress prefix.
     * @return string
     */
    public function getPrefixedTableName($tableSuffix) {
        return $this->dbCore->getPrefixedTableName($tableSuffix);
    }
    
    /** Returns the create table statement.
     * @param string $tableName
     * @return string
     */
    function getCreateTableDDL($tableName) {
        return $this->dbCore->getCreateTableDDL($tableName);
    }

    // Facade delegations to DatabaseCore (Phase 0 refactor).

    /** @param string $query @param array<string, mixed> $options @return int */
    public function queryScalarInt($query, $options = array()) {
        return $this->dbCore->queryScalarInt($query, $options);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    function queryAndGetResults($query, $options = array()) {
        return $this->dbCore->queryAndGetResults($query, $options);
    }

    /** @param array<string, mixed> $options @return string */
    function buildPostTypeSqlList(array $options): string {
        return $this->dbCore->buildPostTypeSqlList($options);
    }

    /** @param array<string, mixed> $options @return string */
    function buildCategorySqlList(array $options): string {
        return $this->dbCore->buildCategorySqlList($options);
    }

    /** @return void */
    function setSqlBigSelects(): void {
        $ignoreErrorsOptions = array('log_errors' => false);
        $this->queryAndGetResults("set session max_join_size = 18446744073709551615",
            $ignoreErrorsOptions);
        $this->queryAndGetResults("set session sql_big_selects = 1", $ignoreErrorsOptions);
    }

    /** @param string $key @param mixed $value @param int $ttlSeconds @return void */
    private function setRuntimeFlag(string $key, $value, int $ttlSeconds): void {
        $this->dbCore->setRuntimeFlag($key, $value, $ttlSeconds);
    }

    /** @param string $key @return mixed */
    private function getRuntimeFlag(string $key) {
        return $this->dbCore->getRuntimeFlag($key);
    }

    /** @param string $type @param string $message @param string $errorString @return void */
    protected function setPluginDbNotice(string $type, string $message, string $errorString = ''): void {
        $this->dbCore->setPluginDbNotice($type, $message, $errorString);
    }

    /** @param string $type @return void */
    protected function clearPluginDbNoticeIfType(string $type): void {
        $this->dbCore->clearPluginDbNoticeIfType($type);
    }

    /** @return bool */
    private function isWriteBlockActive(): bool {
        return $this->dbCore->isWriteBlockActive();
    }

    /** @return bool */
    private function shouldSkipNonEssentialDbWrites(): bool {
        return $this->dbCore->shouldSkipNonEssentialDbWrites();
    }

    /** @param string $query @return NULL|string|WP_Error */
    function get_stripped_query_result($query) {
        return $this->dbCore->get_stripped_query_result($query);
    }

    /** @return void */
    private function ensureConnection(): void {
        $this->dbCore->ensureConnection();
    }

    /** @param string $errorText @return bool */
    private function isInvalidDataError($errorText) {
        return $this->dbCore->isInvalidDataError($errorText);
    }

    /** @param string $errorText @return bool */
    public function classifyAndHandleInfrastructureError(string $errorText): bool {
        return $this->dbCore->classifyAndHandleInfrastructureError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isDeadlockOrLockTimeoutError(string $errorText): bool {
        return $this->dbCore->isDeadlockOrLockTimeoutError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isResumableStagedKill(string $errorText): bool {
        return $this->dbCore->isResumableStagedKill($errorText);
    }

    /** @param string|null $errorText @return bool */
    private function isTransientConnectionError(?string $errorText): bool {
        return $this->dbCore->isTransientConnectionError($errorText);
    }

    /** @param int $stageNumber @param string $errorText @return string */
    public function classifyStageFailure(int $stageNumber, string $errorText): string {
        return $this->dbCore->classifyStageFailure($stageNumber, $errorText);
    }

    /** @param string $errorText @return bool */
    private function isDiskFullError(string $errorText): bool {
        return $this->dbCore->isDiskFullError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isReadOnlyError(string $errorText): bool {
        return $this->dbCore->isReadOnlyError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isQuotaLimitError(string $errorText): bool {
        return $this->dbCore->isQuotaLimitError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isCrashedTableError(string $errorText): bool {
        return $this->dbCore->isCrashedTableError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isGaleraConflictError(string $errorText): bool {
        return $this->dbCore->isGaleraConflictError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isIncorrectKeyFileError(string $errorText): bool {
        return $this->dbCore->isIncorrectKeyFileError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isMissingPluginTableError(string $errorText): bool {
        return $this->dbCore->isMissingPluginTableError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isQueryTimeoutError(string $errorText): bool {
        return $this->dbCore->isQueryTimeoutError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isAccessDeniedError(string $errorText): bool {
        return $this->dbCore->isAccessDeniedError($errorText);
    }

    /** @param string $errorText @return bool */
    private function isPacketTooLarge(string $errorText): bool {
        return $this->dbCore->isPacketTooLarge($errorText);
    }

    /** @param string $errorText @return bool */
    public function isOutOfMemoryError(string $errorText): bool {
        return $this->dbCore->isOutOfMemoryError($errorText);
    }

    /** @param string $errorText @return void */
    private function noteDatabaseIssueFromError(string $errorText): void {
        $this->dbCore->noteDatabaseIssueFromError($errorText);
    }

    /** @return bool */
    private function isQuotaCooldownActive(): bool {
        return $this->dbCore->isQuotaCooldownActive();
    }

    /** @param string $errorText @return bool */
    private function isMultisiteCrossPrefixError(string $errorText): bool {
        return $this->dbCore->isMultisiteCrossPrefixError($errorText);
    }

    /** @return string */
    private function diagnosePrefixMismatch(): string {
        return $this->dbCore->diagnosePrefixMismatch();
    }

    /** @param string $text @return string */
    private function localizeOrDefault(string $text): string {
        if (function_exists('__')) {
            return __($text, '404-solution');
        }
        return $text;
    }

    /** @param array<string, mixed> $result @return void */
    private function harvestWpdbResult(array &$result): void {
        global $wpdb;
        $result['last_error'] = (string)($wpdb->last_error ?? '');
        $result['last_result'] = $wpdb->last_result ?? array();
        $result['rows_affected'] = $wpdb->rows_affected ?? 0;
        $result['insert_id'] = $wpdb->insert_id ?? 0;
    }

    /** @param string $errorText @return bool */
    private function classifySetStatementFailure(string $errorText): bool {
        return $this->dbCore->classifySetStatementFailure($errorText);
    }

    /** @param string $query @return bool */
    private function queryProducesResultRows(string $query): bool {
        return $this->dbCore->queryProducesResultRows($query);
    }

    /** @return bool */
    private function isMariaDB(): bool {
        return $this->dbCore->isMariaDB();
    }

    /** @param string $query @return string */
    private function extractSqlFilename($query) {
        return $this->dbCore->extractSqlFilename($query);
    }

    /** @param string $errorText @return bool */
    private function isTransientViewBuildTableError(string $errorText): bool {
        return $this->dbCore->isTransientViewBuildTableError($errorText);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    private function attemptMissingTableRepairAndRetry($query, &$result) {
        $this->dbCore->attemptMissingTableRepairAndRetry($query, $result);
    }

    /** @param string $query @return string */
    private function applyQueryTimeout(string $query, int $timeoutSeconds): string {
        return $this->dbCore->applyQueryTimeout($query, $timeoutSeconds);
    }

    /** @param string $query @return bool */
    private function queryHasSetStatementWrapper(string $query): bool {
        return $this->dbCore->queryHasSetStatementWrapper($query);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $result
     * @param string $resultType
     * @return void
     */
    private function retryWithoutSetStatementWrapper(string &$query, array &$result, string $resultType): void {
        $this->dbCore->retryWithoutSetStatementWrapper($query, $result, $resultType);
    }

    /** @param string $query @return bool */
    private function queryStartsWithSelect(string $query): bool {
        return $this->dbCore->queryStartsWithSelect($query);
    }

    /** @param string $errorText @return bool */
    private function isPermanentHostSideStagedFailure(string $errorText): bool {
        return $this->dbCore->isPermanentHostSideStagedFailure($errorText);
    }

    /** @return string */
    private function getPreferredUtf8mb4Collation() {
        return $this->dbCore->getPreferredUtf8mb4Collation();
    }

    /** @param string $collation @return string */
    private function sanitizeCollationIdentifier($collation) {
        return $this->dbCore->sanitizeCollationIdentifier($collation);
    }

    /** @param string $query @param string $resultType @return void */
    public function applyTimeoutToInsertSelect(string &$query, string $resultType = ''): void {
        $this->dbCore->applyTimeoutToInsertSelect($query, $resultType);
    }
}
