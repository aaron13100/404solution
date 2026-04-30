<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DataAccessTrait_Maintenance.php';
require_once __DIR__ . '/DataAccessTrait_ViewQueries.php';
require_once __DIR__ . '/DataAccessTrait_ViewSnapshotCache.php';
require_once __DIR__ . '/DataAccessTrait_Logs.php';
require_once __DIR__ . '/DataAccessTrait_LogsHitsRebuild.php';
require_once __DIR__ . '/DataAccessTrait_Redirects.php';
require_once __DIR__ . '/DataAccessTrait_Stats.php';
require_once __DIR__ . '/DataAccessTrait_ErrorClassification.php';

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
    /** @var int Safety cap: avoid storing extremely large payloads in cache. */
    const VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES = 2097152; // 2 MiB
    /** @var int Cross-request lock timeout for logs-hits rebuild jobs. */
    const HITS_TABLE_REBUILD_LOCK_TTL_SECONDS = 180;
    /** @var int Number of logsv2 IDs to process per chunk during pre-aggregation. */
    const HITS_TABLE_PREAGG_CHUNK_SIZE = 100000;
    /** @var int Max age for cached stats-periodic aggregates. */
    const PERIODIC_STATS_CACHE_TTL_SECONDS = 300;
    /** @var int Minimum interval before recalculating expensive stats aggregates. */
    const PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS = 30;
    /** @var int Max age for cached daily-activity trend data (Stats tab Chart.js). */
    const TREND_DATA_CACHE_TTL_SECONDS = 900;
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

    /** @var self|null */
    private static $instance = null;

    /** @var bool Whether the hits table rebuild has been scheduled for this request */
    private static $hitsTableRebuildScheduled = false;
    /** @var bool Prevent recursive auto-repair attempts on SQL errors. */
    private static $tableRepairInProgress = false;
    /** @var bool Prevent recursive invalid-data retry attempts. */
    private static $invalidDataRetryInProgress = false;
    /** @var bool Prevent recursive collation auto-recovery — correctCollations()
     *  emits ALTER TABLE statements that re-enter queryAndGetResults(); without
     *  this guard a collation error inside correctCollations() would deadlock on
     *  the cooldown transient and recurse indefinitely. */
    private static $collationRecoveryInProgress = false;
    /** @var string Current wpdb result type for queryAndGetResults (ARRAY_A or OBJECT). */
    private $currentResultType = ARRAY_A;
    /** @var bool Ensure view cache table DDL runs at most once per request. */
    private static $viewSnapshotTableEnsured = false;
    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        self::$viewSnapshotTableEnsured = $value;
    }

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Clock|null Lazy-resolved by clock(); kept null to preserve constructor signature. */
    private $clock = null;
    /** @var bool Whether a server-side DB issue was noted this request (for auto-clear). */
    private $serverSideIssueNoted = false;
    /** @var bool Whether we already checked for a stale notice transient this request. */
    private $serverSideIssueChecked = false;
    /** @var array<string,int> Request-local cached counts for redirects list views. */
    private $redirectsForViewCountRequestCache = array();

    use ABJ_404_Solution_DataAccess_MaintenanceTrait;
    use ABJ_404_Solution_DataAccess_ViewQueriesTrait;
    use ABJ_404_Solution_DataAccess_ViewSnapshotCacheTrait;
    use ABJ_404_Solution_DataAccess_LogsTrait;
    use ABJ_404_Solution_DataAccess_LogsHitsRebuildTrait;
    use ABJ_404_Solution_DataAccess_RedirectsTrait;
    use ABJ_404_Solution_DataAccess_StatsTrait;
    use ABJ_404_Solution_DataAccess_ErrorClassificationTrait;

    /** Cache key for redirect status counts */
    const CACHE_KEY_REDIRECT_STATUS = 'abj404_redirect_status_counts';

    /** Cache key for captured status counts */
    const CACHE_KEY_CAPTURED_STATUS = 'abj404_captured_status_counts';

    /** Cache key for high-impact captured URL count (3+ hits) */
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = 'abj404_high_impact_captured';

    /** Cache TTL in seconds (24 hours - safety net, primary refresh is event-driven invalidation) */
    const STATUS_CACHE_TTL = 86400;

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
    public function __construct($functions = null, $logging = null) {
        // Use injected dependencies or fall back to getInstance() for backward compatibility
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    /**
     * Inject a specific clock instance. Tests bind a `FrozenClock` so
     * cooldown / rate-limit windows can be advanced deterministically.
     * @param ABJ_404_Solution_Clock $clock @return void
     */
    public function setClock(ABJ_404_Solution_Clock $clock): void {
        $this->clock = $clock;
    }

    /**
     * Resolve the clock used for time-based operations: injected setter
     * wins, then container `'clock'` service, then a fresh `SystemClock`
     * (CLI / fixtures that bypass `bootstrap.php`).
     * @return ABJ_404_Solution_Clock
     */
    protected function clock(): ABJ_404_Solution_Clock {
        if ($this->clock !== null) { return $this->clock; }
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('clock')) {
                    $resolved = $c->get('clock');
                    if ($resolved instanceof ABJ_404_Solution_Clock) {
                        $this->clock = $resolved;
                        return $this->clock;
                    }
                }
            } catch (Throwable $e) { /* fall through to SystemClock */ }
        }
        $this->clock = new ABJ_404_Solution_SystemClock();
        return $this->clock;
    }

    /** @return self */
    public static function getInstance() {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // If the DI container is initialized, prefer it.
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('data_access')) {
                    $resolved = $c->get('data_access');
                    if ($resolved instanceof self) {
                        self::$instance = $resolved;
                        return self::$instance;
                    }
                }
            } catch (Throwable $e) {
                // fall back to legacy singleton below
            }
        }

        // For backward compatibility, create with no arguments
        // The constructor will use getInstance() for dependencies
        self::$instance = new ABJ_404_Solution_DataAccess();

        return self::$instance;
    }

    /**
     * Ensure database connection is active and reconnect if necessary.
     *
     * Fix for MySQL Server Gone Away error (reported by 3 users - 7% of errors)
     * This prevents "MySQL server has gone away" errors during long-running operations
     * by checking the connection status and reconnecting if needed.
     *
     * @return bool True if connection is active, false otherwise
     */
    private function ensureConnection() {
        global $wpdb;

        // Check if wpdb exists
        if (!isset($wpdb)) {
            return true; // Assume connection is OK if wpdb doesn't exist
        }

        // Try to check connection (WordPress 3.9+)
        try {
            // Try to call check_connection - if it doesn't exist, we'll catch the error
            $isConnected = $wpdb->check_connection(false);

            // If not connected, attempt reconnection
            if (!$isConnected) {
                $this->logger->debugMessage("Database connection lost, attempting to reconnect...");

                // Attempt to reconnect
                $wpdb->db_connect();

                // Verify reconnection succeeded
                if ($wpdb->check_connection(false)) {
                    $this->logger->debugMessage("Database reconnection successful");
                    return true;
                } else {
                    $this->logger->errorMessage("Failed to reconnect to database");
                    return false;
                }
            }
        } catch (Exception $e) {
            // If check fails, assume connection is OK to avoid breaking functionality
            $this->logger->debugMessage("Connection check failed: " . $e->getMessage());
            return true;
        } catch (Error $e) {
            // Handle fatal errors (e.g., method doesn't exist)
            $this->logger->debugMessage("Connection check not available: " . $e->getMessage());
            return true;
        }

        return true;
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
        global $wpdb;

        if (!isset($wpdb)) {
            return false;
        }

        // @utf8-audit: opt-out — $tableName is always a system value (built
        // from $wpdb->prefix or doTableNameReplacements); never user input.
        // Use SHOW TABLES to check existence (esc_sql avoids prepare() variadic
        // arg issues with some test mocks while remaining injection-safe for a table name)
        $table = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tableName) . "'");

        return ($table == $tableName);
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
        global $wpdb;
        if (!isset($wpdb)) { return []; }
        // @utf8-audit: opt-out — $tableName is always a system value (built
        // from $wpdb->prefix or doTableNameReplacements); never user input.
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `" . esc_sql($tableName) . "`", ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) { return []; }
        $columns = [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) { $columns[] = $row['Field']; }
        }
        return $columns;
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
        global $wpdb;
        
        $replacements = array();
        $tables = (isset($wpdb->tables) && is_array($wpdb->tables)) ? $wpdb->tables : array();
        foreach ($tables as $tableName) {
            $replacements['{wp_' . $tableName . '}'] = $wpdb->prefix . $tableName;
        }
        // wpdb properties are not guaranteed on mocks; provide safe fallbacks.
        $replacements['{wp_users}'] = $wpdb->users ?? ($wpdb->prefix . 'users');
        $replacements['{wp_prefix}'] = $wpdb->prefix ?? 'wp_';
        $replacements['{wp_prefix_lower}'] = $this->getLowercasePrefix();

        // Resolve {wpdb_collate} so any SQL file can force a consistent collation
        // on cross-table string expressions (prevents "Illegal mix of collations").
        $wpdbCollate = 'utf8mb4_unicode_ci';
        if (isset($wpdb->collate) && !empty($wpdb->collate)) {
            $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $wpdb->collate);
            if ($sanitized !== '' && $sanitized !== null) {
                $wpdbCollate = $sanitized;
            }
        }
        $replacements['{wpdb_collate}'] = $wpdbCollate;

        // wp database table replacements
        $query = $this->f->str_replace(array_keys($replacements), array_values($replacements), $query);
        
        // custom table replacements.
        // for some strings (/404solution-site/%BA%D0%25/) the mb_ereg_replace doesn't work.
        $fpreg = ABJ_404_Solution_FunctionsPreg::getInstance();
        $query = $fpreg->regexReplace('[{]wp_abj404_(.*?)[}]',
            $this->getLowercasePrefix() . "abj404_\\1", $query);

        return $query !== null ? $query : '';
    }

    /**
     * Get the normalized (lowercase) prefix used for all plugin tables.
     * This avoids case-sensitive MySQL filesystems from treating mixed-case
     * prefixes as distinct tables.
     *
     * @return string
     */
    public function getLowercasePrefix() {
        global $wpdb;
        return $this->f->strtolower($wpdb->prefix ?? 'wp_');
    }

    /**
     * Build a fully-qualified plugin table name using the normalized prefix.
     *
     * @param string $tableSuffix Table name without the WordPress prefix.
     * @return string
     */
    public function getPrefixedTableName($tableSuffix) {
        return $this->getLowercasePrefix() . ltrim($tableSuffix, '_');
    }
    
    /** Returns the create table statement.
     * @param string $tableName
     * @return string
     */
    function getCreateTableDDL($tableName) {
    	$query = "show create table " . $tableName;
    	$result = $this->queryAndGetResults($query, array('log_errors' => false, 'skip_repair' => true));
    	$rows = $result['rows'];

    	// Handle case where query returns no results (e.g., in test environment)
    	if (!is_array($rows) || empty($rows) || !isset($rows[0]) || !is_array($rows[0])) {
    	    return '';
    	}

    	$row1 = array_values($rows[0]);
    	$existingTableSQL = $row1[1];

    	return $existingTableSQL;
    }

    /**
     * Extract filename from SQL comment wrapper for safe logging.
     *
     * When SQL files are loaded, they're wrapped in comment blocks with the filename.
     * This extracts just the filename (e.g. "file.sql") for production logging
     * without exposing potentially sensitive query content or PII.
     *
     * @param string $query The SQL query potentially containing a filename comment
     * @return string The extracted filename or 'inline-query' if no comment found
     */
    private function extractSqlFilename($query) {
        // Extract filename from: /* -- /path/to/file.sql BEGIN -- */
        if (preg_match('/\/\*\s*-+\s*(.+?\.sql)\s+BEGIN\s*-+\s*\*\//i', $query, $matches)) {
            return basename($matches[1]);
        }
        return 'inline-query';
    }

    /**
     * Sanitize SQL identifier-like collation names.
     *
     * @param string $collation
     * @return string
     */
    private function sanitizeCollationIdentifier($collation) {
        if (!is_string($collation) || $collation === '') {
            return '';
        }
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $collation);
        return $sanitized !== null ? $sanitized : '';
    }

    /**
     * Resolve an appropriate utf8mb4 collation for CAST/COLLATE comparisons.
     *
     * Prefer wpdb connection collation when it's utf8mb4, otherwise fall back
     * to a safe default.
     *
     * @return string
     */
    private function getPreferredUtf8mb4Collation() {
        global $wpdb;

        if (isset($wpdb) && isset($wpdb->collate) && !empty($wpdb->collate)) {
            $wpdbCollation = $this->sanitizeCollationIdentifier((string)$wpdb->collate);
            if ($wpdbCollation !== '' && stripos($wpdbCollation, 'utf8mb4') !== false) {
                return $wpdbCollation;
            }
        }
        return 'utf8mb4_unicode_ci';
    }

    /**
     * Attempt one retry for invalid-data errors using wpdb's stripped query helper.
     *
     * @param string $query
     * @param array<string, mixed> $result
     * @return void
     */
    private function attemptInvalidDataRetry($query, &$result) {
        if (self::$invalidDataRetryInProgress) {
            return;
        }

        self::$invalidDataRetryInProgress = true;
        try {
            $retryQuery = $this->get_stripped_query_result($query);
            if (!is_string($retryQuery) || trim($retryQuery) === '' || $retryQuery === $query) {
                return;
            }

            global $wpdb;
            $wpdb->flush();
            $result['rows'] = $wpdb->get_results($retryQuery, $this->currentResultType);
            $this->harvestWpdbResult($result);
        } catch (Throwable $e) {
            $this->logger->warn("Invalid-data retry failed: " . $e->getMessage());
        } finally {
            self::$invalidDataRetryInProgress = false;
        }
    }

    /** @return void */
    private function applyDiagnosticLatencyIfConfigured(): void {
        if (!function_exists('abj404_get_simulated_db_latency_ms')) {
            return;
        }
        $delayMs = absint(abj404_get_simulated_db_latency_ms());
        if ($delayMs <= 0) {
            return;
        }
        $delayMs = min(5000, $delayMs);
        usleep($delayMs * 1000);
    }

    /**
     * Harvest standard result fields from $wpdb after a query.
     *
     * @param array<string, mixed> $result The result array to populate.
     * @return void
     */
    private function harvestWpdbResult(array &$result): void {
        global $wpdb;
        $result['last_error'] = (string)($wpdb->last_error ?? '');
        $result['last_result'] = $wpdb->last_result ?? array();
        $result['rows_affected'] = $wpdb->rows_affected ?? 0;
        $result['insert_id'] = $wpdb->insert_id ?? 0;
    }

    /**
     * Build a SQL-safe comma-separated list from recognized_post_types option.
     *
     * @param array<string, mixed> $options Plugin options array.
     * @return string e.g. "'post', 'page'" or '' if empty.
     */
    function buildPostTypeSqlList(array $options): string {
        $rptVal = $options['recognized_post_types'] ?? '';
        $postTypes = $this->f->explodeNewline(is_string($rptVal) ? $rptVal : '');
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($this->f->strtolower($postType)) . "', ";
        }
        return rtrim($recognizedPostTypes, ", ");
    }

    /**
     * Build a SQL-safe comma-separated list from recognized_categories option.
     *
     * @param array<string, mixed> $options Plugin options array.
     * @return string e.g. "'category', 'post_tag'" or '' if empty.
     */
    function buildCategorySqlList(array $options): string {
        $rcVal = $options['recognized_categories'] ?? '';
        $categories = $this->f->explodeNewline(is_string($rcVal) ? $rcVal : '');
        $recognizedCategories = '';
        foreach ($categories as $category) {
            $recognizedCategories .= "'" . trim($this->f->strtolower($category)) . "', ";
        }
        return rtrim($recognizedCategories, ", ");
    }

    /**
     * Set SQL session variables to allow large queries.
     *
     * Sets max_join_size and sql_big_selects for the current session only.
     * Prevents "The SELECT would examine more than MAX_JOIN_SIZE rows" errors.
     *
     * @return void
     */
    function setSqlBigSelects(): void {
        $ignoreErrorsOptions = array('log_errors' => false);
        $this->queryAndGetResults("set session max_join_size = 18446744073709551615",
            $ignoreErrorsOptions);
        $this->queryAndGetResults("set session sql_big_selects = 1", $ignoreErrorsOptions);
    }

    /**
     * Convenience: run a query that returns a single scalar value and return it
     * as an int. The query must SELECT exactly one column from the first row;
     * the column name is irrelevant — the first value of $rows[0] is taken.
     *
     * Returns 0 when the query fails, returns no rows, or the value is not
     * scalar. Tightly typed so callers don't have to repeat the
     * `is_array($result['rows']) && is_array($result['rows'][0]) && …`
     * narrowing boilerplate at every COUNT(*) call site.
     *
     * @param string $query Any SELECT … that produces exactly one column.
     * @param array<string, mixed> $options Options to forward to queryAndGetResults().
     * @return int
     */
    public function queryScalarInt($query, $options = array()) {
        $result = $this->queryAndGetResults($query, $options);
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }
        $first = reset($rows[0]);
        return is_scalar($first) ? (int)$first : 0;
    }

    /** Return the results of the query in a variable.
     * @param string $query
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    function queryAndGetResults($query, $options = array()) {
        global $wpdb;

        // Ensure database connection is active (prevents "MySQL server has gone away" errors)
        $this->ensureConnection();

        $ignoreErrorStrings = array();

        $options = array_merge(array('log_errors' => true,
            'log_too_slow' => true, 'ignore_errors' => array(),
            'query_params' => array(), 'skip_repair' => false,
            'result_type' => ARRAY_A, 'timeout' => 0),
            $options);
        $resultType = $options['result_type'] === OBJECT ? OBJECT : ARRAY_A;
        $this->currentResultType = $resultType;

       	$ignoreErrorStrings = is_array($options['ignore_errors']) ? $options['ignore_errors'] : array();
        $queryParameters = is_array($options['query_params']) ? $options['query_params'] : array();

        $query = $this->doTableNameReplacements($query);

        if (!empty($queryParameters)) {
            // WPDB::prepare array support varies across versions/mocks.
            // Prefer varargs, but fall back to array-as-single-arg for older/custom mocks.
            /** @var literal-string $queryLiteral */
            $queryLiteral = $query;
            try {
                /** @var wpdb $wpdb */
                $preparedResult = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($queryLiteral), $queryParameters));
                $query = is_string($preparedResult) ? $preparedResult : $queryLiteral;
            } catch (Throwable $t) {
                $preparedFallback = $wpdb->prepare($queryLiteral, $queryParameters);
                $query = $preparedFallback !== null ? $preparedFallback : $queryLiteral;
            }
        }

        // Apply a DB-level timeout to every query.
        // Default timeout (60s) prevents any single query from blocking indefinitely.
        $timeoutRaw = isset($options['timeout']) && is_numeric($options['timeout']) ? (int)$options['timeout'] : 0;
        $timeoutSeconds = $timeoutRaw > 0 ? $timeoutRaw : 60;
        $query = $this->applyQueryTimeout($query, $timeoutSeconds);

        $this->applyDiagnosticLatencyIfConfigured();

        $timer = new ABJ_404_Solution_Timer();

        // When log_errors is false, also suppress $wpdb's own error output
        // (prevents best-effort queries from leaking to debug.log when WP_DEBUG is on).
        $suppressWpdbErrors = !$options['log_errors'] && method_exists($wpdb, 'suppress_errors');
        $previousSuppressState = false;
        if ($suppressWpdbErrors) {
            /** @var wpdb $wpdb */
            $previousSuppressState = $wpdb->suppress_errors(true);
        }

        // Route by query type: SELECT-style queries (SELECT, SHOW, EXPLAIN, DESCRIBE)
        // produce result rows and use $wpdb->get_results(). Other queries (INSERT,
        // UPDATE, DELETE, DDL, SET, ...) use $wpdb->query() — get_results() would
        // call mysqli_num_fields() on a `true` result on PHP 8.1+ and TypeError.
        // The 4.1.7 SET STATEMENT timeout wrapping also breaks wpdb's leading-keyword
        // routing, so the detection looks PAST any SET STATEMENT prefix.
        $producesRows = $this->queryProducesResultRows($query);

        $result = array();
        if ($producesRows) {
            $result['rows'] = $wpdb->get_results($query, $resultType);
        } else {
            $wpdb->query($query);
            $result['rows'] = array();
        }

        $result['elapsed_time'] = $timer->stop();
        $elapsedMs = ((float)$result['elapsed_time']) * 1000.0;
        if (function_exists('abj404_benchmark_record_db_query')) {
            abj404_benchmark_record_db_query($elapsedMs);
        }
        if (function_exists('abj404_query_budget_record')) {
            // Record SQL filename only (never raw SQL — PII-free).  See
            // ABJ_404_Solution_QueryBudgetInstrumentation for the contract.
            abj404_query_budget_record($this->extractSqlFilename($query), $elapsedMs, $timeoutSeconds);
        }
        $this->harvestWpdbResult($result);

        if ($producesRows && !is_array($result['rows'])) {
            // In production (WP_DEBUG off), only log SQL filename to avoid PII exposure
            $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
            $this->logger->errorMessage("Query result is not an array. Query: " . $sqlInfo,
        			new Exception("Query result is not an array."));
        }

        if ($result['last_error'] !== '' && $this->isTransientConnectionError($result['last_error'])) {
            // Retry once after reconnect for transient connection drops.
            $this->ensureConnection();
            $wpdb->flush();
            if ($producesRows) {
                $result['rows'] = $wpdb->get_results($query, $resultType);
            } else {
                $wpdb->query($query);
                $result['rows'] = array();
            }
            $this->harvestWpdbResult($result);
        }

        if (!$options['skip_repair'] && $result['last_error'] !== '' && $this->isMissingPluginTableError($result['last_error'])) {
            $this->attemptMissingTableRepairAndRetry($query, $result);
        }

        if ($result['last_error'] !== '' && $this->isInvalidDataError($result['last_error'])) {
            $this->attemptInvalidDataRetry($query, $result);
        }

        // Lock wait timeout (errno 1205) and deadlock (errno 1213): retry once after a
        // brief pause. Both errors are transient on shared hosting and usually resolve
        // on the first retry. If the retry also fails, the error is surfaced below.
        if ($result['last_error'] !== '' && $this->isDeadlockOrLockTimeoutError($result['last_error'])) {
            /** @var wpdb $wpdb */
            usleep(50000); // 50 ms — enough for most short-lived locks to release
            if ($producesRows) {
                $result['rows'] = $wpdb->get_results($query, $resultType);
            } else {
                $wpdb->query($query);
                $result['rows'] = array();
            }
            $this->harvestWpdbResult($result);
            if ($result['last_error'] !== '' && $this->isDeadlockOrLockTimeoutError($result['last_error'])) {
                $this->setPluginDbNotice('lock_timeout', $this->localizeOrDefault('A database lock wait timeout occurred. If this persists, contact your host — another process may be holding a long-running lock.'), $result['last_error']);
            }
        }

        // Collation mismatch ("Illegal mix of collations" / "Unknown collation"):
        // run correctCollations() (rate-limited 1×/hour) to converge plugin tables
        // back to a single utf8mb4 collation, then retry the query once.  This
        // path is silent — the user is never notified about collation issues.
        if ($result['last_error'] !== '' && $this->isCollationError($result['last_error'])) {
            $this->recoverFromCollationMismatchAndRetry($query, $result, $producesRows, $resultType);
        }

        // Query timeout (MySQL errno 3024 / MariaDB errno 1969): log the slow
        // query so it appears in debug reports, then return empty results.
        if ($result['last_error'] !== '' && $this->isQueryTimeoutError($result['last_error'])) {
            $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
            $this->logger->errorMessage(
                'Query timed out after ' . $timeoutSeconds . 's. ' .
                'Query: ' . substr(preg_replace('/\s+/', ' ', trim($sqlInfo)) ?? $sqlInfo, 0, 500)
            );
            $result['rows'] = array();
            $result['timed_out'] = true;
        }

        if ($result['last_error'] !== '') {
            $this->noteDatabaseIssueFromError($result['last_error']);
        }

        // Restore $wpdb error reporting after all retry paths have completed.
        if ($suppressWpdbErrors) {
            /** @var wpdb $wpdb */
            $wpdb->suppress_errors($previousSuppressState);
        }

        if ($options['log_errors'] && $result['last_error'] != '') {
            if ($this->f->strpos($result['last_error'], 
                    " is marked as crashed ") !== false) {
                $this->repairTable($result['last_error']);
            }
            if ($this->f->strpos($result['last_error'],
            		"ALTER TABLE causes auto_increment resequencing") !== false &&
            		$this->f->strpos($result['last_error'], "resulting in duplicate entry") !== false) {
            		$this->repairDuplicateIDs($result['last_error'], $query);
            }
            if ($this->isIncorrectKeyFileError($result['last_error'])) {
                $this->repairCorruptedTableAndRetry($query, $result);
            }

            // ignore any specific errors.
            $reportError = true;
            foreach ($ignoreErrorStrings as $ignoreThis) {
            	if (is_string($ignoreThis) && strpos($result['last_error'], $ignoreThis) !== false) {
            		$reportError = false;
            		break;
            	}
            }

            // Server-side and infrastructure errors are not plugin bugs.  They are
            // already handled by dedicated repair/retry handlers above or by
            // noteDatabaseIssueFromError() (admin notice + write-block cooldown).
            // Log as WARN instead of ERROR to avoid triggering dev email reports.
            if ($reportError && (
                $this->isDiskFullError($result['last_error']) ||
                $this->isReadOnlyError($result['last_error']) ||
                $this->isQuotaLimitError($result['last_error']) ||
                $this->isInvalidDataError($result['last_error']) ||
                $this->isCollationError($result['last_error']) ||
                $this->isMissingPluginTableError($result['last_error']) ||
                $this->isIncorrectKeyFileError($result['last_error']) ||
                $this->isCrashedTableError($result['last_error']) ||
                $this->isDeadlockOrLockTimeoutError($result['last_error']) ||
                $this->isTransientConnectionError($result['last_error']) ||
                $this->isQueryTimeoutError($result['last_error'])
            )) {
                $this->logger->warn("Server-side DB issue (handled): " . $result['last_error']);
                $reportError = false;
            }

            if ($reportError) {
                $stripped_query = 'n/a';
                if ($this->isInvalidDataError($result['last_error'])) {
                    $strippedResult = $this->get_stripped_query_result($query);
                    $stripped_query = is_string($strippedResult) ? $strippedResult : 'n/a';
                }
                
                $extraDataQuery = "select @@max_join_size as max_join_size, " . 
            		"@@sql_big_selects as sql_big_selects, " .
                    "@@character_set_database as character_set_database";
            	$someMySQLVariables = $wpdb->get_results($extraDataQuery, ARRAY_A);
            	$variables = print_r($someMySQLVariables, true);

                // In production (WP_DEBUG off), only log SQL filename to avoid PII exposure
                $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);

                $dbVer = $wpdb->db_version();
                $this->logger->errorMessage("Ugh. SQL query error: " . (is_string($result['last_error']) ? $result['last_error'] : '') .
					    ", SQL: " . $sqlInfo .
	            	    ", Execution time: " . round($timer->getElapsedTime(), 2) .
	            	    ", DB ver: " . (is_string($dbVer) ? $dbVer : 'unknown') .
            		    ", Variables: " . $variables .
            	        ", stripped_query: " . $stripped_query);
            }
            
        } else {
            if ($options['log_too_slow'] && $timer->getElapsedTime() > 5) {
                // In production (WP_DEBUG off), only log SQL filename to avoid PII exposure
                $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
                $this->logger->debugMessage("Slow query (" . round($timer->getElapsedTime(), 2) . " seconds): " .
                        $sqlInfo);
            }

            // Auto-clear the admin notice once queries succeed and cooldowns have expired.
            // Guard: only run when the query truly succeeded (this else branch also
            // fires when log_errors is false, which can include failed queries).
            if ($result['last_error'] === '') {
                // serverSideIssueNoted is set when an error occurs in this request;
                // also check once per request if a stale notice transient exists from
                // a previous request (the flag resets per-process).
                if (!$this->serverSideIssueNoted && !$this->serverSideIssueChecked) {
                    $this->serverSideIssueChecked = true;
                    $existing = $this->getRuntimeFlag('abj404_plugin_db_notice');
                    if (is_array($existing) && !empty($existing['type'])
                        && $existing['type'] !== 'stale_permalink_cache') {
                        // Per owner directive: collation notices must NEVER reach the
                        // user.  If a stale 'collation' transient exists from an older
                        // plugin version, opportunistically clear it so it cannot be
                        // shown by any code path.
                        $this->serverSideIssueNoted = true;
                    }
                }
                if ($this->serverSideIssueNoted && !$this->isWriteBlockActive() && !$this->isQuotaCooldownActive()) {
                    $this->clearServerSideDbNotice();
                }
            }
        }
        
        return $result;
    }

    /**
     * @param string $query
     * @return bool
     */
    private function queryStartsWithSelect(string $query): bool {
        // SQL loaded from .sql files is wrapped in leading comments.
        // Treat "/* ... */ SELECT ..." as a SELECT query for timeout purposes.
        return preg_match('/^\s*(?:\/\*[\s\S]*?\*\/\s*)*SELECT\s/i', $query) === 1;
    }

    /**
     * Returns true if the query produces a result set (rows), so it should
     * be sent through $wpdb->get_results(). Returns false for INSERT, UPDATE,
     * DELETE, REPLACE, DDL, SET, etc. — those should go through $wpdb->query().
     *
     * Sees past leading SQL comments and any `SET STATEMENT max_statement_time=N FOR `
     * timeout wrapper. The wrapper is critical because applyQueryTimeout() prepends
     * it on MariaDB, which would otherwise mask the underlying statement type.
     *
     * Misclassification triggered the 4.1.7 spell-check `mysqli_num_fields(true)`
     * TypeError on PHP 8.1+ MariaDB sites — see DataAccessNonSelectRoutingTest.
     *
     * @param string $query
     * @return bool
     */
    private function queryProducesResultRows(string $query): bool {
        $stripped = (string)preg_replace('/^\s*(?:\/\*[\s\S]*?\*\/\s*)+/', '', $query);
        $stripped = (string)preg_replace(
            '/^\s*SET\s+STATEMENT\s+\w+\s*=\s*\d+\s+FOR\s+/i',
            '',
            $stripped,
            1
        );
        // Strip nested leading comments inside the SET STATEMENT wrapper too.
        $stripped = (string)preg_replace('/^\s*(?:\/\*[\s\S]*?\*\/\s*)+/', '', $stripped);
        return preg_match('/^\s*(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC)\s/i', $stripped) === 1;
    }

    /**
     * Apply a DB-level timeout to any query type.
     *
     * Dispatches to the appropriate engine-specific mechanism:
     * - Pure SELECT: MySQL optimizer hint or MariaDB SET STATEMENT
     * - INSERT...SELECT (or any non-leading SELECT): MariaDB SET STATEMENT
     *   or MySQL hint injected into the embedded SELECT
     * - Other DML/DDL: MariaDB SET STATEMENT (MySQL has no mechanism for
     *   non-SELECT timeouts; these queries are typically fast)
     *
     * Skips queries that already carry a timeout hint to prevent double-wrapping
     * (e.g. callers that used to apply timeouts manually before this was centralized).
     *
     * @param string $query Any SQL query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout applied (or unchanged if no mechanism)
     */
    private function applyQueryTimeout(string $query, int $timeoutSeconds): string {
        // Skip if a timeout hint is already present (prevents double-wrapping).
        if (preg_match('/MAX_EXECUTION_TIME|max_statement_time/i', $query)) {
            return $query;
        }

        if ($this->queryStartsWithSelect($query)) {
            return $this->applySelectTimeout($query, $timeoutSeconds);
        }
        if (preg_match('/SELECT\s/i', $query)) {
            // INSERT...SELECT, CREATE TABLE...SELECT, etc.
            return $this->applyNonLeadingSelectTimeout($query, $timeoutSeconds);
        }
        // Plain INSERT, UPDATE, DELETE, DDL — only MariaDB has a timeout mechanism.
        return $this->applyStatementTimeout($query, $timeoutSeconds);
    }

    /**
     * Detect the DB engine. Returns true for MariaDB, false for MySQL/unknown.
     * @return bool
     */
    private function isMariaDB(): bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return false;
        }
        try {
            if (isset($wpdb->dbh) && function_exists('mysqli_get_server_info') && $wpdb->dbh instanceof \mysqli) {
                $dbVersion = mysqli_get_server_info($wpdb->dbh);
            } else {
                /** @var wpdb $wpdb */
                $dbVersion = $wpdb->db_version() ?? '';
            }
        } catch (\Throwable $e) {
            // Mockery mocks, plain stdClass, or other test doubles may not
            // have db_version(). Default to MySQL (not MariaDB).
            $dbVersion = '';
        }
        return stripos($dbVersion, 'mariadb') !== false;
    }

    /**
     * Apply timeout to a pure SELECT query.
     *
     * MySQL 5.7.8+: MAX_EXECUTION_TIME(ms) optimizer hint.
     * MariaDB 10.1+: SET STATEMENT max_statement_time=N FOR ...
     *
     * @param string $query A SELECT query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout hint applied
     */
    private function applySelectTimeout(string $query, int $timeoutSeconds): string {
        if ($this->isMariaDB()) {
            return "SET STATEMENT max_statement_time=" . $timeoutSeconds . " FOR " . $query;
        }
        $timeoutMs = $timeoutSeconds * 1000;
        $timedQuery = preg_replace(
            '/^(\s*(?:\/\*[\s\S]*?\*\/\s*)*SELECT\s)/i',
            '$1/*+ MAX_EXECUTION_TIME(' . $timeoutMs . ') */ ',
            $query
        );
        return ($timedQuery !== null) ? $timedQuery : $query;
    }

    /**
     * Apply timeout to a query containing a non-leading SELECT (INSERT...SELECT, etc.).
     *
     * MariaDB 10.1+: SET STATEMENT max_statement_time=N FOR ... (wraps entire statement).
     * MySQL 5.7.8+: MAX_EXECUTION_TIME(ms) hint injected into the first SELECT keyword.
     *
     * @param string $query An INSERT...SELECT or similar query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout applied
     */
    private function applyNonLeadingSelectTimeout(string $query, int $timeoutSeconds): string {
        if ($this->isMariaDB()) {
            return "SET STATEMENT max_statement_time=" . $timeoutSeconds . " FOR " . $query;
        }
        $timeoutMs = $timeoutSeconds * 1000;
        $timedQuery = preg_replace(
            '/(SELECT\s)/i',
            'SELECT /*+ MAX_EXECUTION_TIME(' . $timeoutMs . ') */ ',
            $query,
            1
        );
        return ($timedQuery !== null) ? $timedQuery : $query;
    }

    /**
     * Apply timeout to a non-SELECT statement (INSERT, UPDATE, DELETE, DDL).
     *
     * MariaDB 10.1+: SET STATEMENT max_statement_time=N FOR ... works on all DML.
     * MySQL: has no SQL-level timeout mechanism for non-SELECT queries.
     *
     * @param string $query Any non-SELECT query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout applied (unchanged on MySQL)
     */
    private function applyStatementTimeout(string $query, int $timeoutSeconds): string {
        if ($this->isMariaDB()) {
            return "SET STATEMENT max_statement_time=" . $timeoutSeconds . " FOR " . $query;
        }
        // MySQL has no timeout mechanism for non-SELECT queries.
        return $query;
    }

    /**
     * @deprecated Use the 'timeout' option on queryAndGetResults() instead.
     *             Kept for backward compatibility with any external callers.
     *
     * @param string $insertSelectQuery The INSERT INTO ... SELECT ... query
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string The query with timeout applied
     */
    function applyTimeoutToInsertSelect(string $insertSelectQuery, int $timeoutSeconds): string {
        return $this->applyNonLeadingSelectTimeout($insertSelectQuery, $timeoutSeconds);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $ttlSeconds
     * @return void
     */
    private function setRuntimeFlag(string $key, $value, int $ttlSeconds): void {
        if (function_exists('set_transient')) {
            set_transient($key, $value, $ttlSeconds);
            return;
        }
        if (function_exists('update_option')) {
            update_option($key, $value, false);
        }
    }

    /**
     * @param string $key
     * @return mixed
     */
    private function getRuntimeFlag(string $key) {
        if (function_exists('get_transient')) {
            return get_transient($key);
        }
        if (function_exists('get_option')) {
            return get_option($key, false);
        }
        return false;
    }

    /**
     * @param string $type
     * @param string $message
     * @param string $errorString
     * @return void
     */
    protected function setPluginDbNotice(string $type, string $message, string $errorString = ''): void {
        $payload = array(
            'type' => $type,
            'message' => $message,
            'timestamp' => $this->clock()->now(),
            'error_string' => $errorString,
        );
        $this->setRuntimeFlag('abj404_plugin_db_notice', $payload, self::DB_WRITE_BLOCK_COOLDOWN_SECONDS);
    }

    /**
     * Clear the plugin DB notice only when its current type matches.
     *
     * @param string $type
     * @return void
     */
    protected function clearPluginDbNoticeIfType(string $type): void {
        $existing = $this->getRuntimeFlag('abj404_plugin_db_notice');
        if (!is_array($existing)) {
            return;
        }
        $currentType = isset($existing['type']) && is_string($existing['type']) ? $existing['type'] : '';
        if ($currentType !== $type) {
            return;
        }
        $this->clearServerSideDbNotice();
    }

    /** @return void */
    private function clearServerSideDbNotice(): void {
        if (function_exists('delete_transient')) {
            delete_transient('abj404_plugin_db_notice');
        } elseif (function_exists('delete_option')) {
            delete_option('abj404_plugin_db_notice');
        }
        $this->serverSideIssueNoted = false;
    }

    /** @param string $text @return string */
    private function localizeOrDefault(string $text): string {
        if (function_exists('__')) {
            return __($text, '404-solution');
        }
        return $text;
    }

    /** @return bool */
    private function isWriteBlockActive(): bool {
        $rawDiskFlag = $this->getRuntimeFlag('abj404_db_disk_full_until');
        $diskUntil = is_scalar($rawDiskFlag) ? (int)$rawDiskFlag : 0;
        $rawReadOnlyFlag = $this->getRuntimeFlag('abj404_db_read_only_until');
        $readOnlyUntil = is_scalar($rawReadOnlyFlag) ? (int)$rawReadOnlyFlag : 0;
        $now = $this->clock()->now();
        return ($diskUntil > $now || $readOnlyUntil > $now);
    }

    /** @return bool */
    private function shouldSkipNonEssentialDbWrites(): bool {
        return ($this->isQuotaCooldownActive() || $this->isWriteBlockActive());
    }

    /**
     * Attempt REPAIR TABLE after errno 1034 ("Incorrect key file"), then retry the query once.
     * For plugin tables the retry is attempted after repair. For non-plugin tables the repair
     * is not our responsibility, but we surface a rate-limited admin notice.
     *
     * @param string $query
     * @param array<string, mixed> $result passed by reference
     * @return void
     */
    private function repairCorruptedTableAndRetry(string $query, array &$result): void {
        $errorMessage = is_string($result['last_error']) ? $result['last_error'] : '';
        // Delegate the REPAIR TABLE call (and the non-plugin-table notice) to the trait method.
        $this->repairTable($errorMessage);

        // Only retry for plugin tables — they may now be healthy.
        if (stripos($errorMessage, 'abj404') !== false) {
            global $wpdb;
            $wpdb->flush();
            $result['rows'] = $wpdb->get_results($query, $this->currentResultType);
            $result['last_error'] = (string)($wpdb->last_error ?? '');
            $result['last_result'] = $wpdb->last_result ?? array();
            $result['rows_affected'] = $wpdb->rows_affected ?? 0;
            $result['insert_id'] = $wpdb->insert_id ?? 0;
            if ($result['last_error'] === '') {
                $this->logger->infoMessage("Retry after 'Incorrect key file' repair succeeded for plugin table.");
            }
        }
    }

    /** Try to call strip_invalid_text_from_query and return the result.
     * @param string $query
     * @return NULL|string|WP_Error
     */
    function get_stripped_query_result($query) {
        try {
            if (!class_exists('wpdb')) {
                return null;
            }
            if (!method_exists('wpdb', 'strip_invalid_text_from_query')) {
                return null;
            }
            
            $filename = ABJ404_PATH . 'includes/php/wordpress/WPDBExtension.php';
            if (!file_exists($filename)) {
                return null;
            }
            require_once $filename;

            $my_custom_db = null;
            if (class_exists('ABJ_404_Solution_WPDBExtension_PHP7')) {
                $my_custom_db = new ABJ_404_Solution_WPDBExtension_PHP7(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
                
            } else if (class_exists('ABJ_404_Solution_WPDBExtension_PHP5')) {
                $my_custom_db = new ABJ_404_Solution_WPDBExtension_PHP5(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            }
            if ($my_custom_db == null) {
                return null;
            }
                        
            $result = $my_custom_db->public_strip_invalid_text_from_query($query);

            if (is_wp_error($result)) {
                return 'WP_Error: ' . $result->get_error_message();
            }
    
            return $result;
    
        } catch (Throwable $e) {
            // Surface the swallowed failure so the support-bundle reader can
            // see the wpdb extension fell through. The function contract
            // (NULL|string|WP_Error) is preserved by returning null.
            $this->logger->warn(
                'get_stripped_query_result failed; returning null: ' . $e->getMessage()
            );
            return null;
        }
    }
    
    /** @param string $errorMessage @return void */
}
