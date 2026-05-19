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
require_once __DIR__ . '/DataAccessTrait_Logs.php';
require_once __DIR__ . '/DataAccessTrait_LogsHitsRebuild.php';
require_once __DIR__ . '/DataAccessTrait_Redirects.php';
require_once __DIR__ . '/DataAccessTrait_PublishedContent.php';
require_once __DIR__ . '/DataAccessTrait_Stats.php';
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

    /** @var bool Whether the hits table rebuild has been scheduled for this request */
    private static $hitsTableRebuildScheduled = false;

    /** @var bool Ensure view cache table DDL runs at most once per request. */
    private static $viewSnapshotTableEnsured = false;

    /** @var ABJ_404_Solution_DatabaseCore The extracted database infrastructure layer. */
    private $dbCore;

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
    use ABJ_404_Solution_DataAccess_LogsTrait;
    use ABJ_404_Solution_DataAccess_LogsHitsRebuildTrait;
    use ABJ_404_Solution_DataAccess_RedirectsTrait;
    use ABJ_404_Solution_DataAccess_PublishedContentTrait;
    use ABJ_404_Solution_DataAccess_StatsTrait;

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

    /** @var array<int, array<string, mixed>>|null Per-request cache for regex redirects (static to persist across getInstance calls) */
    private static $regexRedirectsCache = null;

    /** @var bool Flag indicating if regex cache should be skipped (too many redirects) */
    private static $regexCacheDisabled = false;

    /** @var array<int, array<string, mixed>> Queue of log entries to be flushed at shutdown */
    private static $logQueue = [];

    /** @var bool Whether shutdown hook has been registered */
    private static $shutdownHookRegistered = false;

    /** @var bool Prevent re-entrancy during flush */
    private static $isFlushingLogQueue = false;



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
     */
    public function __construct($functions = null, $logging = null, $dbCore = null) {
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');

        if ($dbCore !== null) {
            $this->dbCore = $dbCore;
        } else {
            $this->dbCore = new ABJ_404_Solution_DatabaseCore($this->f, $this->logger);
        }
        $this->dbCore->setRepairDelegate($this);
    }

    /** @return ABJ_404_Solution_DatabaseCore */
    public function getDbCore(): ABJ_404_Solution_DatabaseCore {
        return $this->dbCore;
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
    private function isCollationError(string $errorText): bool {
        return $this->dbCore->isCollationError($errorText);
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
