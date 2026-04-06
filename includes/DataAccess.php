<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DataAccessTrait_Maintenance.php';
require_once __DIR__ . '/DataAccessTrait_ViewQueries.php';
require_once __DIR__ . '/DataAccessTrait_Logs.php';
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
    /** @var int Max age for cached stats-periodic aggregates. */
    const PERIODIC_STATS_CACHE_TTL_SECONDS = 300;
    /** @var int Minimum interval before recalculating expensive stats aggregates. */
    const PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS = 30;
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
    /** @var bool Whether a server-side DB issue was noted this request (for auto-clear). */
    private $serverSideIssueNoted = false;
    /** @var bool Whether we already checked for a stale notice transient this request. */
    private $serverSideIssueChecked = false;
    /** @var array<string,int> Request-local cached counts for redirects list views. */
    private $redirectsForViewCountRequestCache = array();

    use ABJ_404_Solution_DataAccess_MaintenanceTrait;
    use ABJ_404_Solution_DataAccess_ViewQueriesTrait;
    use ABJ_404_Solution_DataAccess_LogsTrait;
    use ABJ_404_Solution_DataAccess_RedirectsTrait;
    use ABJ_404_Solution_DataAccess_StatsTrait;
    use ABJ_404_Solution_DataAccess_ErrorClassificationTrait;

    /** Cache key for redirect status counts */
    const CACHE_KEY_REDIRECT_STATUS = 'abj404_redirect_status_counts';

    /** Cache key for captured status counts */
    const CACHE_KEY_CAPTURED_STATUS = 'abj404_captured_status_counts';

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
        $this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
        $this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
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
        $abj404logging = ABJ_404_Solution_Logging::getInstance();        
        
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
     * Build a stable cache key for admin list data/count snapshots.
     *
     * @param string $prefix
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function getViewSnapshotCacheKey($prefix, $sub, $tableOptions) {
        $cacheShape = array(
            'sub' => (string)$sub,
            'filter' => is_scalar($tableOptions['filter'] ?? 0) ? (int)($tableOptions['filter'] ?? 0) : 0,
            'orderby' => is_scalar($tableOptions['orderby'] ?? 'url') ? (string)($tableOptions['orderby'] ?? 'url') : 'url',
            'order' => is_scalar($tableOptions['order'] ?? 'ASC') ? (string)($tableOptions['order'] ?? 'ASC') : 'ASC',
            'paged' => is_scalar($tableOptions['paged'] ?? 1) ? (int)($tableOptions['paged'] ?? 1) : 1,
            'perpage' => is_scalar($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) ? (int)($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) : ABJ404_OPTION_DEFAULT_PERPAGE,
            'filterText' => is_scalar($tableOptions['filterText'] ?? '') ? (string)($tableOptions['filterText'] ?? '') : '',
            'score_range' => (function ($v) { return is_string($v) ? $v : 'all'; })($tableOptions['score_range'] ?? 'all'),
            'blog' => function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1,
        );
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($cacheShape) : json_encode($cacheShape);
        return $prefix . '_' . md5((string)$encoded);
    }

    /** @return void */
    private function ensureViewSnapshotTableExists(): void {
        if (self::$viewSnapshotTableEnsured) {
            return;
        }
        self::$viewSnapshotTableEnsured = true;
        $sqlFile = __DIR__ . '/sql/createViewCacheTable.sql';
        $create = ABJ_404_Solution_Functions::readFileContents($sqlFile);
        if (is_string($create) && trim($create) !== '') {
            $this->queryAndGetResults($create, array('log_errors' => false));
        }
    }

    /** @param string $cacheKey @return string */
    private function getViewSnapshotLockOptionName(string $cacheKey): string {
        return $this->getLowercasePrefix() . 'abj404_view_cache_lock_' . md5((string)$cacheKey);
    }

    /** @param string $cacheKey @return bool */
    private function isViewSnapshotRefreshLocked(string $cacheKey): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $lockKey = $this->getViewSnapshotLockOptionName($cacheKey);
        $lockValue = get_option($lockKey, false);
        if ($lockValue === false || $lockValue === '' || $lockValue === null) {
            return false;
        }
        $lockTs = is_numeric($lockValue) ? (int)$lockValue : 0;
        if ($lockTs > 0 && (time() - $lockTs) > self::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS) {
            if (function_exists('delete_option')) {
                delete_option($lockKey);
            }
            return false;
        }
        return true;
    }

    /** @param string $cacheKey @return bool */
    private function acquireViewSnapshotRefreshLock(string $cacheKey): bool {
        if (!function_exists('add_option')) {
            return true;
        }
        if ($this->isViewSnapshotRefreshLocked($cacheKey)) {
            return false;
        }
        $lockKey = $this->getViewSnapshotLockOptionName($cacheKey);
        return (bool)add_option($lockKey, time(), '', false);
    }

    /** @param string $cacheKey @return void */
    private function releaseViewSnapshotRefreshLock(string $cacheKey): void {
        if (function_exists('delete_option')) {
            delete_option($this->getViewSnapshotLockOptionName($cacheKey));
        }
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>|null
     */
    private function decodeSnapshotPayload($payload) {
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $cacheKey
     * @param bool $allowExpired
     * @param bool $respectCooldown
     * @return array<string, mixed>|null
     */
    private function getViewRowsSnapshotFromTable(string $cacheKey, bool $allowExpired = false, bool $respectCooldown = false) {
        $this->ensureViewSnapshotTableExists();
        $query = "SELECT payload, refreshed_at, expires_at
            FROM {wp_abj404_view_cache}
            WHERE cache_key = %s LIMIT 1";
        $result = $this->queryAndGetResults($query, array('query_params' => array($cacheKey), 'log_errors' => false));
        if (!is_array($result['rows']) || empty($result['rows']) || !is_array($result['rows'][0])) {
            return null;
        }
        $row = $result['rows'][0];
        $expiresAt = intval($row['expires_at'] ?? 0);
        $refreshedAt = intval($row['refreshed_at'] ?? 0);
        $now = time();
        $isFresh = ($expiresAt > $now);
        $recentEnough = ($refreshedAt > 0 && ($now - $refreshedAt) <= self::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS);
        if (!$allowExpired && !$isFresh) {
            return null;
        }
        if ($respectCooldown && !$isFresh && !$recentEnough) {
            return null;
        }
        return $this->decodeSnapshotPayload((string)($row['payload'] ?? ''));
    }

    /**
     * @param string $cacheKey
     * @param string $sub
     * @param mixed $rows
     * @param int $ttlSeconds
     * @return void
     */
    private function setViewRowsSnapshotToTable(string $cacheKey, string $sub, $rows, int $ttlSeconds): void {
        if (!is_array($rows)) {
            return;
        }
        $this->ensureViewSnapshotTableExists();
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($rows) : json_encode($rows);
        if (!is_string($encoded)) {
            return;
        }
        $bytes = strlen($encoded);
        if ($bytes > self::VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES) {
            return;
        }
        $now = time();
        $expiresAt = $now + max(1, intval($ttlSeconds));
        $query = "INSERT INTO {wp_abj404_view_cache}
            (cache_key, subpage, payload, payload_bytes, refreshed_at, expires_at, updated_at)
            VALUES (%s, %s, %s, %d, %d, %d, %d)
            ON DUPLICATE KEY UPDATE
                subpage = VALUES(subpage),
                payload = VALUES(payload),
                payload_bytes = VALUES(payload_bytes),
                refreshed_at = VALUES(refreshed_at),
                expires_at = VALUES(expires_at),
                updated_at = VALUES(updated_at)";
        $this->queryAndGetResults($query, array(
            'query_params' => array($cacheKey, (string)$sub, $encoded, $bytes, $now, $expiresAt, $now),
            'log_errors' => false,
        ));
        $this->cleanupExpiredViewSnapshotRowsIfNeeded();
    }

    /**
     * @param string $cacheKey
     * @param int $timeoutMs
     * @return array<string, mixed>|null
     */
    private function waitForViewRowsSnapshotFromTable(string $cacheKey, int $timeoutMs = 4000) {
        $deadline = microtime(true) + (max(100, intval($timeoutMs)) / 1000);
        while (microtime(true) < $deadline) {
            $rows = $this->getViewRowsSnapshotFromTable($cacheKey, false, false);
            if (is_array($rows)) {
                return $rows;
            }
            usleep(100000);
        }
        return null;
    }

    /** @return void */
    private function cleanupExpiredViewSnapshotRowsIfNeeded(): void {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return;
        }
        $marker = get_transient('abj404_view_cache_cleanup_marker');
        if ($marker !== false) {
            return;
        }
        set_transient('abj404_view_cache_cleanup_marker', time(), 1800);
        $query = "DELETE FROM {wp_abj404_view_cache} WHERE expires_at < %d";
        $this->queryAndGetResults($query, array(
            'query_params' => array(time() - self::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS),
            'log_errors' => false,
        ));
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
            $result['rows'] = $wpdb->get_results($retryQuery, ARRAY_A);
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
            'query_params' => array(), 'skip_repair' => false),
            $options);

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

        $result = array();
        $result['rows'] = $wpdb->get_results($query, ARRAY_A);
        
        $result['elapsed_time'] = $timer->stop();
        if (function_exists('abj404_benchmark_record_db_query')) {
            abj404_benchmark_record_db_query(((float)$result['elapsed_time']) * 1000.0);
        }
        $this->harvestWpdbResult($result);
        
        if (!is_array($result['rows'])) {
            // In production (WP_DEBUG off), only log SQL filename to avoid PII exposure
            $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
            $this->logger->errorMessage("Query result is not an array. Query: " . $sqlInfo,
        			new Exception("Query result is not an array."));
        }
        
        if ($result['last_error'] !== '' && $this->isTransientConnectionError($result['last_error'])) {
            // Retry once after reconnect for transient connection drops.
            $this->ensureConnection();
            $wpdb->flush();
            $result['rows'] = $wpdb->get_results($query, ARRAY_A);
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
            $result['rows'] = $wpdb->get_results($query, ARRAY_A);
            $this->harvestWpdbResult($result);
            if ($result['last_error'] !== '' && $this->isDeadlockOrLockTimeoutError($result['last_error'])) {
                $this->setPluginDbNotice('lock_timeout', $this->localizeOrDefault('A database lock wait timeout occurred. If this persists, contact your host — another process may be holding a long-running lock.'), $result['last_error']);
            }
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
                $this->isTransientConnectionError($result['last_error'])
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
                        && $existing['type'] !== 'collation'
                        && $existing['type'] !== 'stale_permalink_cache') {
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
            'timestamp' => time(),
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
        return ($diskUntil > time() || $readOnlyUntil > time());
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
            $result['rows'] = $wpdb->get_results($query, ARRAY_A);
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

            // @phpstan-ignore function.impossibleType, function.impossibleType
            if (is_wp_error($result)) {
                return 'WP_Error: ' . $result->get_error_message();
            }
    
            return $result;
    
        } catch (Exception $e) {
            // oh well.
            return null;
        }
    }
    
    /** @param string $errorMessage @return void */
}
