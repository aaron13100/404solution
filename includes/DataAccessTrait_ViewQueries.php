<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_ViewQueriesTrait {

    /** @return array<string, mixed> */
    function getTableEngines() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/selectTableEngines.sql");
    	$results = $this->queryAndGetResults($query);
    	return $results;
    }
    
    /** @return bool */
    function isMyISAMSupported(): bool {
        $abj404dao = abj_service('data_access');
        $supportResults = $abj404dao->queryAndGetResults("SELECT ENGINE, SUPPORT " .
            "FROM information_schema.ENGINES WHERE lower(ENGINE) = 'myisam'",
            array('log_errors' => false));
        
        if (!empty($supportResults) && !empty($supportResults['rows']) && is_array($supportResults['rows'])) {
            $rows = $supportResults['rows'];
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $supportValue = array_key_exists('support', $row) ? (string)($row['support'] ?? '') :
            (array_key_exists('SUPPORT', $row) ? (string)($row['SUPPORT'] ?? '') : "nope");

            return strtolower($supportValue) == 'yes';
        }
        return false;
    }
    
    /** Insert data into the database.
     * Create my own insert statement because wordpress messes it up when the field
     * length is too long. this also returns the correct value for the last_query.
     * @global type $wpdb
     * @param string $tableName
     * @param array<string, mixed> $dataToInsert
     * @return array<string, mixed>
     */
    function insertAndGetResults($tableName, $dataToInsert) {
        $tableName = $this->doTableNameReplacements($tableName);
    
        $columns = array();
        $placeholders = array();
        $values = array();
    
        foreach ($dataToInsert as $column => $value) {
            $columns[] = '`' . $column . '`';
    
            if ($value === null) {
                $placeholders[] = 'NULL';
                // Do not add null values to $values array
            } else {
                $currentDataType = gettype($value);
                if ($currentDataType == 'integer' || $currentDataType == 'double') {
                    $placeholders[] = '%d';
                    $values[] = $value;
                } elseif ($currentDataType == 'boolean') {
                    $placeholders[] = '%d';
                    $values[] = $value ? 1 : 0;
                } else {
                    $placeholders[] = '%s';
                    $values[] = is_scalar($value) ? (string)$value : '';
                }
            }
        }
    
        $sql = 'INSERT INTO `' . $tableName . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    
        return $this->queryAndGetResults($sql, ['query_params' => $values]);
    }
    
   /**
    * @return int the total number of redirects that have been captured.
    */
   function getCapturedCount() {
       $query = "select count(id) from {wp_abj404_redirects} where status = " . absint(ABJ404_STATUS_CAPTURED);

       // Route through queryAndGetResults() so the count query inherits the
       // centralized 60s timeout. Tiny query in normal operation, but the
       // redirects table can grow into millions of rows on busy sites.
       $result = $this->queryAndGetResults($query);
       if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
           return 0;
       }

       $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
       if (empty($rows)) {
           return 0;
       }
       $first = $rows[0];
       $value = is_array($first) ? reset($first) : $first;
       return intval($value);
   }
    
   /** Get all of the post types from the wp_posts table.
    * @return array<int, string> An array of post type names. */
   function getAllPostTypes() {
       $query = "SELECT DISTINCT post_type FROM {wp_posts} order by post_type";
       $results = $this->queryAndGetResults($query);
       $rows = $results['rows'];

       $postType = array();

       // Ensure rows is an array before iterating
       if (is_array($rows)) {
           foreach ($rows as $row) {
               array_push($postType, $row['post_type']);
           }
       }

       return $postType;
   }
   
   /** Get the approximate number of bytes used by the logs table.
    *
    * Reads data_length+index_length from information_schema.tables. InnoDB
    * (and modern MyISAM) maintain these values continuously, so an ANALYZE TABLE
    * pre-warm is unnecessary — the residual accuracy gain is irrelevant for
    * an "X MB" UI display and the bytes-per-log heuristic in deleteOldRedirectsCron.
    *
    * The previous implementation issued ANALYZE TABLE before the size lookup.
    * On sites with millions of logsv2 rows that single statement could take
    * 10–30 seconds. ANALYZE TABLE has no automatic query timeout (the SELECT
    * timeout in queryAndGetResults() applies only to SELECT statements), so
    * the Settings/Tools page render exceeded reverse-proxy timeouts (e.g.
    * Cloudflare 524, nginx 504) on large sites.
    *
    * The size SELECT now routes through queryAndGetResults() so it inherits
    * the default 60-second SELECT timeout. On timeout or other error we return
    * a non-positive sentinel; downstream callers (deleteOldRedirectsCron,
    * the Settings UI) treat that as "could not determine".
    *
    * @return int Bytes used by the logs table, 0 on missing/empty stats,
    *             or -1 if the lookup itself failed/timed out.
    */
   function getLogDiskUsage() {
       $query = 'SELECT (data_length+index_length) tablesize FROM information_schema.tables '
               . 'WHERE table_name=\'{wp_abj404_logsv2}\'';

       $result = $this->queryAndGetResults($query);

       if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
           $err = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
           if ($err !== '') {
               $this->logger->errorMessage("Error: " . esc_html($err));
           }
           return -1;
       }

       $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
       if (empty($rows)) {
           return 0;
       }

       $row = is_array($rows[0] ?? null) ? $rows[0] : array();
       $size = $row['tablesize'] ?? null;
       if ($size === null || !is_scalar($size)) {
           return 0;
       }
       return intval($size);
   }

    /**
     * @global type $wpdb
     * @param array<int, int> $types specified types such as ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED.
     * @param int $trashed 1 to only include disabled redirects. 0 to only include enabled redirects.
     * @return int the number of records matching the specified types.
     */
    function getRecordCount($types = array(), $trashed = 0) {
        $recordCount = 0;

        if (count($types) >= 1) {
            $query = "select count(id) as count from {wp_abj404_redirects} where 1 and (status in (";

            // Use absint() for proper integer sanitization to prevent SQL injection
            $filteredTypes = array_map('absint', $types);
            $typesForSQL = implode(", ", $filteredTypes);
            $query .= $typesForSQL . "))";

            // Use absint() for integer parameter sanitization
            $query .= " and disabled = " . absint($trashed);

            $result = $this->queryAndGetResults($query);
            $rows = is_array($result['rows']) ? $result['rows'] : array();
            if (!empty($rows)) {
	            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
	            $recordCount = isset($row['count']) && is_scalar($row['count']) ? intval($row['count']) : 0;
            }
        }

        return intval($recordCount);
    }


    /**
     * Get counts for each redirect status type for display in tabs.
     * Uses transient caching for performance.
     * @param bool $bypassCache If true, skip cache and query database directly
     * @return array<string, int> An array with keys: all, manual, auto, regex, trash
     */
    function getRedirectStatusCounts($bypassCache = false) {
        // Try to get cached value first
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_REDIRECT_STATUS);
            if ($cached !== false && is_array($cached)) {
                /** @var array<string, int> $cached */
                return $cached;
            }
        }

        // IMPORTANT: The redirects table also stores captured/ignored/later rows.
        // The Redirects page "All/Manual/Auto/Trash" tabs should only count actual redirects
        // (manual/auto/regex), not captured URLs.
        $query = "SELECT
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_MANUAL . " THEN 1 ELSE 0 END) as manual_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_AUTO . " THEN 1 ELSE 0 END) as auto_count,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_REGEX . " THEN 1 ELSE 0 END) as regex_count,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash_count
            FROM {wp_abj404_redirects}
            WHERE status IN (" . ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_AUTO . ", " . ABJ404_STATUS_REGEX . ")";
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query);
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $counts = array('all' => 0, 'manual' => 0, 'auto' => 0, 'regex' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $counts = array(
                'all' => intval(is_scalar($row['active_count'] ?? 0) ? $row['active_count'] : 0),
                'manual' => intval(is_scalar($row['manual_count'] ?? 0) ? $row['manual_count'] : 0),
                'auto' => intval(is_scalar($row['auto_count'] ?? 0) ? $row['auto_count'] : 0),
                'regex' => intval(is_scalar($row['regex_count'] ?? 0) ? $row['regex_count'] : 0),
                'trash' => intval(is_scalar($row['trash_count'] ?? 0) ? $row['trash_count'] : 0)
            );
        }

        // Skip the cache write when the SUM(...) query returned an error or
        // timed out: $rows is empty in that case so $counts is the all-zero
        // default, and pinning that for STATUS_CACHE_TTL (24h) would make the
        // Redirects admin page show "0 of every status" until the transient
        // expires. Same policy as 6454a7dd / b857be36.
        if (!$hadError) {
            set_transient(self::CACHE_KEY_REDIRECT_STATUS, $counts, self::STATUS_CACHE_TTL);
        }

        return $counts;
    }

    /**
     * Get counts for each captured URL status type.
     * Uses transient caching for performance.
     * @param bool $bypassCache If true, skip cache and query database directly
     * @return array<string, int> Array with keys: all, captured, ignored, later, trash
     */
    function getCapturedStatusCounts($bypassCache = false) {
        // Try to get cached value first
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_CAPTURED_STATUS);
            if ($cached !== false && is_array($cached)) {
                /** @var array<string, int> $cached */
                return $cached;
            }
        }

        $query = "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_CAPTURED . " THEN 1 ELSE 0 END) as captured,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_IGNORED . " THEN 1 ELSE 0 END) as ignored,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_LATER . " THEN 1 ELSE 0 END) as later,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash
            FROM {wp_abj404_redirects}
            WHERE status IN (" . ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER . ")";
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query);
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $counts = array('all' => 0, 'captured' => 0, 'ignored' => 0, 'later' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $counts = array(
                'all' => intval(is_scalar($row['active'] ?? 0) ? $row['active'] : 0),
                'captured' => intval(is_scalar($row['captured'] ?? 0) ? $row['captured'] : 0),
                'ignored' => intval(is_scalar($row['ignored'] ?? 0) ? $row['ignored'] : 0),
                'later' => intval(is_scalar($row['later'] ?? 0) ? $row['later'] : 0),
                'trash' => intval(is_scalar($row['trash'] ?? 0) ? $row['trash'] : 0)
            );
        }

        // Skip the cache write when the SUM(...) query returned an error or
        // timed out: $rows is empty in that case so $counts is the all-zero
        // default, and pinning that for STATUS_CACHE_TTL (24h) would make the
        // Captured-URLs admin page show "0 captured / 0 ignored / 0 later"
        // until the transient expires. Same policy as 6454a7dd / b857be36.
        if (!$hadError) {
            set_transient(self::CACHE_KEY_CAPTURED_STATUS, $counts, self::STATUS_CACHE_TTL);
        }

        return $counts;
    }

    /**
     * Count captured URLs that have been hit 3 or more times (signal of real user impact).
     * Uses transient caching for performance.
     *
     * Implementation: INNER JOIN against the pre-aggregated logs_hits rollup,
     * which already stores logshits per requested_url and is rebuilt by cron via
     * createRedirectsForViewHitsTable(). Same JOIN shape as
     * getRedirectsForViewQuery() — BINARY column equality.
     *
     * The previous implementation aggregated logsv2 with GROUP BY + HAVING per
     * call; on busy sites with millions of log rows that took 30–60s and hit
     * the AJAX timeout. The pre-aggregated table makes the count O(distinct
     * URLs) instead of O(total log rows).
     *
     * Fallback: if logs_hits is missing or empty, return 0 and schedule a
     * shutdown-time rebuild so a subsequent request can serve real data. We
     * deliberately do NOT fall back to the old logsv2 GROUP BY query: the whole
     * point of this function is to never run that scan again.
     *
     * Cache policy: only the result of a successful query against a populated
     * rollup is cached for STATUS_CACHE_TTL (24h). Errors, timeouts, missing
     * rollups, and empty-during-rebuild outcomes all return 0 *without
     * caching* so the next request retries — otherwise a single transient
     * failure would hide repeat-visitor URLs for a full day.
     *
     * @return int Number of captured URLs with 3+ log hits
     */
    function getHighImpactCapturedCount(): int {
        $cached = get_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED);
        if ($cached !== false) {
            return intval(is_scalar($cached) ? $cached : 0);
        }

        // If the rollup is not available, defer rather than scan logsv2.
        // Do not cache: the rebuild is in flight and the next request should retry.
        if (!$this->logsHitsTableExists()) {
            $this->scheduleHitsTableRebuild();
            return 0;
        }

        $query = $this->buildHighImpactCapturedCountQuery();

        $result = $this->queryWithTimeout($query, 60);
        $hadError = !empty($result['last_error']) || !empty($result['timed_out']);
        $rows = is_array($result['rows']) ? $result['rows'] : array();
        $count = (!empty($rows) && isset($rows[0]['cnt'])) ? intval($rows[0]['cnt']) : 0;

        // Do not cache on error/timeout — the rollup is fine, the query just
        // failed transiently (network blip, replication lag, etc.). Caching 0
        // for 24h would silently hide real repeat-visitor URLs.
        if ($hadError) {
            return 0;
        }

        // If the rollup exists but has no rows yet (first run, or rebuild in
        // progress), schedule a rebuild AND skip caching so the next request
        // can serve real data once the rebuild completes (typically seconds).
        if ($count === 0) {
            if ($this->isHitsTableEmpty()) {
                $this->scheduleHitsTableRebuild();
                return 0;
            }
        }

        set_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED, $count, self::STATUS_CACHE_TTL);

        return $count;
    }

    /**
     * Build the SQL for getHighImpactCapturedCount(). Exposed so structural
     * regression tests can assert no logsv2 access and verify the EXPLAIN plan.
     *
     * @return string Fully-replaced SQL (table-name placeholders resolved).
     */
    function buildHighImpactCapturedCountQuery(): string {
        // logs_hits.requested_url is stored in canonical form (leading '/',
        // no trailing '/') by createRedirectsForViewHitsTable(). Match against
        // the persisted r.canonical_url column (added 4.1.10) so the JOIN is
        // an indexed equality lookup instead of CONCAT/TRIM per row. The
        // COALESCE fallback covers rows from upgraded sites where the chunked
        // backfill hasn't reached yet.
        $query = "SELECT COUNT(*) AS cnt
            FROM {wp_abj404_redirects} r
            INNER JOIN {wp_abj404_logs_hits} h
                ON BINARY h.requested_url = BINARY
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . " AND r.disabled = 0
              AND h.logshits >= 3";
        return $this->doTableNameReplacements($query);
    }

    /**
     * Cheap probe: does the logs_hits rollup contain at least one row?
     * SELECT 1 ... LIMIT 1 against a small table.
     *
     * @return bool true when the rollup has zero rows (rebuild in progress,
     *              cold start, or post-truncate). false when at least one
     *              row exists OR when the probe itself errors (treat
     *              ambiguous probes as "not empty" so we don't spam reschedules).
     */
    private function isHitsTableEmpty(): bool {
        $check = "SELECT 1 FROM {wp_abj404_logs_hits} LIMIT 1";
        $check = $this->doTableNameReplacements($check);
        $result = $this->queryAndGetResults($check);
        if (!empty($result['last_error']) || !empty($result['timed_out'])) {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return empty($rows);
    }

    /**
     * Execute a query with a timeout to prevent it from blocking the page indefinitely.
     * On timeout, logs an error with the query shape and returns an empty result.
     *
     * Delegates to queryAndGetResults() with the 'timeout' option, which handles
     * MySQL 5.7+ (MAX_EXECUTION_TIME hint) and MariaDB 10.1+ (max_statement_time).
     *
     * @param string $query The SQL query to execute
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return array<string, mixed> Same format as queryAndGetResults()
     */
    private function queryWithTimeout(string $query, int $timeoutSeconds = 60): array {
        return $this->queryAndGetResults($query, array(
            'timeout' => $timeoutSeconds,
        ));
    }

    /**
     * Invalidate cached status counts.
     * Call this when redirects are created, updated, or deleted.
     */
    /** @return void */
    function invalidateStatusCountsCache(): void {
        delete_transient(self::CACHE_KEY_REDIRECT_STATUS);
        delete_transient(self::CACHE_KEY_CAPTURED_STATUS);
        delete_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED);
        $this->invalidateViewSnapshotCache();
    }

    /**
     * Clear the view snapshot cache so the admin redirect/captured tables
     * reflect newly created, updated, trashed, or deleted redirects immediately.
     *
     * This clears both the custom wp_abj404_view_cache table and the
     * WordPress transients used as a secondary cache layer.
     *
     * @return void
     */
    function invalidateViewSnapshotCache(): void {
        // Clear all rows from the view cache table. The 'log_errors' => false
        // option signals to queryAndGetResults() that this is a best-effort
        // operation: the cache expires naturally via TTL if the DELETE fails,
        // so the DAO layer handles the failure quietly without re-throwing.
        $query = "DELETE FROM {wp_abj404_view_cache} WHERE 1=1";
        $this->queryAndGetResults($query, array('log_errors' => false));

        // Clear WordPress transients for view row and count snapshots.
        // The transient keys are hashed (e.g. abj404_view_rows_<md5>), so
        // we delete by prefix from wp_options directly.
        global $wpdb;
        if (isset($wpdb->options) && method_exists($wpdb, 'query')) {
            // @utf8-audit: opt-out — $wpdb->options is the WordPress core
            // options table name (system value); never user input.
            /** @var string $optionsTable */
            $optionsTable = esc_sql($wpdb->options);
            // DAO-bypass-approved: View-cache clear targets wp_options — outside the plugin's owned tables; runs during cache invalidation hot path; failure is best-effort
            $wpdb->query(
                "DELETE FROM `{$optionsTable}` WHERE option_name LIKE '_transient_abj404_view_%'"
                . " OR option_name LIKE '_transient_timeout_abj404_view_%'"
            );
        }
    }

    /**
     * Clear the per-request regex redirects cache.
     * Primarily used for testing. In production, the cache resets automatically
     * on each new request since it uses static variables.
     */
    /** @return void */
    function clearRegexRedirectsCache(): void {
        self::$regexRedirectsCache = null;
        self::$regexCacheDisabled = false;
    }

    /**
     * @global type $wpdb
     * @param int $logID only return results that correspond to the URL of this $logID. Use 0 to get all records.
     * @return int the number of records found.
     */
    function getLogsCount($logID) {
        // Sanitize logID to prevent SQL injection
        $logID = absint($logID);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsCount.sql");

        if ($logID != 0) {
            $query = $this->f->str_replace('/* {SPECIFIC_ID}', '', $query);
            $query = $this->f->str_replace('{logID}', (string)$logID, $query);
        }

        // Route through queryAndGetResults() so the count query (potentially
        // a JOIN against logsv2 when SPECIFIC_ID is set) inherits the
        // centralized 60s timeout. Bypassing via $wpdb->get_row() leaves the
        // admin page with no upper bound on slow logsv2 lookups.
        $result = $this->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return 0;
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return 0;
        }
        $first = $rows[0];
        $value = is_array($first) ? reset($first) : $first;
        return intval($value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsAll() {
        $query = "select id, url from {wp_abj404_redirects} order by url";

        // Route through queryAndGetResults() so this list query inherits the
        // centralized 60s timeout. The redirects table can be very large on
        // busy sites and an unbounded ORDER BY without timeout protection
        // could exceed reverse-proxy limits.
        $result = $this->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array();
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return $rows;
    }
    
    /** @param string $tempFile @return void */
    function doRedirectsExport(string $tempFile): void {
    	global $wpdb;
    	
    	if (file_exists($tempFile)) {
    		ABJ_404_Solution_Functions::safeUnlink($tempFile);
    	}
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
    		"/sql/getRedirectsExport.sql");
    	$query = $this->doTableNameReplacements($query);
    	
    	// we use mysqli here instead of the normal wordpress get_results in order
    	// to get one row at a time, so we don't run out of memory by trying to store
    	// everything in memory all at once.
    	$result = mysqli_query($wpdb->dbh, $query);
    	if ($result instanceof \mysqli_result) {
    		$fh = fopen($tempFile, 'w');
    		if ($fh === false) {
    			return;
    		}
    		fputcsv($fh, array('from_url', 'status', 'type', 'to_url', 'wp_type', 'engine', 'code'), ',', '"', '\\');

    		while (($row = mysqli_fetch_array($result, MYSQLI_ASSOC))) {
    			fputcsv($fh, array(
    				$row['from_url'],
    				$row['status'],
    				$row['type'],
    				$row['to_url'],
    				$row['type_wp'],
    				isset($row['engine']) ? $row['engine'] : '',
    				isset($row['code']) ? $row['code'] : '301'
    			), ',', '"', '\\');
    		}
    		fclose($fh);
    		mysqli_free_result($result);
    	}
    }
    
    /** Only return redirects that have a log entry.
     * @global type $wpdb
     * @global type $abj404dao
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsWithLogs() {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsWithLogs.sql");

        // Route through queryAndGetResults() so this redirects+logs JOIN
        // inherits the centralized 60s timeout. logsv2 can be huge, and the
        // join shape is identical to the one already protected in the hits
        // table rebuild path (commit 70f3b5fe).
        $result = $this->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array();
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return $rows;
    }

    /**
     * Get all regex redirects for pattern matching.
     * Uses per-request caching when redirect count is <= 50 to avoid repeated queries.
     * Cache is automatically skipped if there are too many regex redirects (memory guard).
     *
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsWithRegEx() {
        // Return cached results if available (and caching wasn't disabled due to count)
        if (self::$regexRedirectsCache !== null && !self::$regexCacheDisabled) {
            return self::$regexRedirectsCache;
        }

        // If caching was disabled due to too many redirects, just query without caching
        if (self::$regexCacheDisabled) {
            return $this->queryRegexRedirects();
        }

        // First query - check count and decide whether to cache
        $results = $this->queryRegexRedirects();

        // Only cache if count is within safe memory limits
        if (count($results) <= self::REGEX_CACHE_MAX_COUNT) {
            self::$regexRedirectsCache = $results;
        } else {
            // Too many regex redirects - disable caching for this request
            self::$regexCacheDisabled = true;
        }

        return $results;
    }

    /**
     * Execute the regex redirects query.
     * Separated from getRedirectsWithRegEx() for cache logic clarity.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryRegexRedirects() {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
                . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
                . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n ";
        $query .= "from {wp_abj404_redirects}\n " .
                "  LEFT OUTER JOIN {wp_posts} \n " .
                "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n ";

        $query .= "where status in (" . ABJ404_STATUS_REGEX . ") \n " .
                "     and disabled = 0";
        $results = $this->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /** Returns the redirects that are in place.
     * @global type $wpdb
     * @param string $sub either "redirects" or "captured".
     * @param array<string, mixed> $tableOptions filter, order by, paged, perpage etc.
     * @return array<int|string, mixed> rows from the redirects table.
     */
    function getRedirectsForView($sub, $tableOptions) {
        $rawOrderBySnap = $tableOptions['orderby'] ?? '';
        $orderByForSnapshot = strtolower(is_string($rawOrderBySnap) ? $rawOrderBySnap : '');
        $isLogsMaintenanceSort = ($orderByForSnapshot === 'logshits' || $orderByForSnapshot === 'last_used');
        $rawPerpageSnap = $tableOptions['perpage'] ?? 0;
        $canUseSnapshotCache = absint(is_scalar($rawPerpageSnap) ? $rawPerpageSnap : 0) <= 200
            && !$isLogsMaintenanceSort;
        $snapshotCacheKey = '';
        $refreshLockHeld = false;
        if ($canUseSnapshotCache) {
            $snapshotCacheKey = $this->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
            $cachedRowsFromTable = $this->getViewRowsSnapshotFromTable($snapshotCacheKey, false, false);
            if (is_array($cachedRowsFromTable)) {
                return $cachedRowsFromTable;
            }
            if (function_exists('get_transient')) {
                $cachedRows = get_transient($snapshotCacheKey);
                if (is_array($cachedRows)) {
                    return $cachedRows;
                }
            }

            // Server-side dedupe: don't run the same refresh concurrently.
            if ($this->isViewSnapshotRefreshLocked($snapshotCacheKey)) {
                $staleRowsFromTable = $this->getViewRowsSnapshotFromTable($snapshotCacheKey, true, true);
                if (is_array($staleRowsFromTable)) {
                    return $staleRowsFromTable;
                }
                $waitedRows = $this->waitForViewRowsSnapshotFromTable($snapshotCacheKey, 4000);
                if (is_array($waitedRows)) {
                    return $waitedRows;
                }
                if (function_exists('get_transient')) {
                    $waitedTransientRows = get_transient($snapshotCacheKey);
                    if (is_array($waitedTransientRows)) {
                        return $waitedTransientRows;
                    }
                }
            } else {
                // At most once per 30s per cache key: if stale-but-recent snapshot exists, serve it.
                $recentRowsFromTable = $this->getViewRowsSnapshotFromTable($snapshotCacheKey, true, true);
                if (is_array($recentRowsFromTable)) {
                    return $recentRowsFromTable;
                }
                $refreshLockHeld = $this->acquireViewSnapshotRefreshLock($snapshotCacheKey);
            }
        }
    	
    	// for normal page views we limit the rows returned based on user preferences for paginaiton.
        $paged = absint(is_scalar($tableOptions['paged'] ?? 1) ? ($tableOptions['paged'] ?? 1) : 1);
        if ($paged < 1) {
            $paged = 1;
        }
        $perpage = absint(is_scalar($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) ? ($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) : ABJ404_OPTION_DEFAULT_PERPAGE);
        if ($perpage < 1) {
            $perpage = ABJ404_OPTION_DEFAULT_PERPAGE;
        }
        $limitStart = ($paged - 1) * $perpage;
        $limitEnd = $perpage;
        
        $queryAllRowsAtOnce = ($tableOptions['perpage'] > 5000) || ($tableOptions['orderby'] == 'logshits')
                || ($tableOptions['orderby'] == 'last_used');
        
        $query = $this->getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
        	$limitStart, $limitEnd, false);

        // if this takes too long then rewrite how specific URLs are linked to from the redirects table.
        // they can use a different ID - not the ID from the logs table.
        $this->setSqlBigSelects();
        $results = $this->queryAndGetResults($query);

        if (!empty($results['last_error']) && is_string($results['last_error']) && $this->isCollationError($results['last_error'])) {
            $retryOptions = $tableOptions;
            $retryOptions['forceCollate'] = 'utf8mb4_general_ci';
            $query = $this->getRedirectsForViewQuery($sub, $retryOptions, $queryAllRowsAtOnce,
                $limitStart, $limitEnd, false);
            $results = $this->queryAndGetResults($query);
        }

        // Handle race condition: logs_hits table may have been dropped between existence check and query
        // (fixes bug: "Table 'xxx.wp_abj404_logs_hits' doesn't exist" error during shutdown)
        $usedFallbackForLogsHits = false;
        $needsPhpSortAndLimit = false;
        if (!empty($results['last_error']) && is_string($results['last_error']) && strpos($results['last_error'], 'logs_hits') !== false) {
            $this->logger->debugMessage("logs_hits table unavailable, retrying without JOIN: " . $results['last_error']);
            // Retry with queryAllRowsAtOnce = false to skip logs_hits JOIN
            // (The query builder only adds the JOIN when queryAllRowsAtOnce is true)
            $usedFallbackForLogsHits = true;
            $queryAllRowsAtOnce = false;

            // If sorting by logshits/last_used, we need to query rows, populate logs
            // data in PHP, sort, then slice to the page. Cap the fetch to prevent
            // memory exhaustion on large sites (PHP_INT_MAX previously crashed Apache).
            if ($tableOptions['orderby'] == 'logshits' || $tableOptions['orderby'] == 'last_used') {
                $needsPhpSortAndLimit = true;
                $fallbackMaxRows = 5000;
                $query = $this->getRedirectsForViewQuery($sub, $tableOptions, false,
                    0, $fallbackMaxRows, false);
            } else {
                // Other sort columns work fine with normal limit
                $query = $this->getRedirectsForViewQuery($sub, $tableOptions, false,
                    $limitStart, $limitEnd, false);
            }
            $results = $this->queryAndGetResults($query);
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        $foundRowsBeforeLogsData = count($rows);

        // populate the logs data if we need to
        if (!$queryAllRowsAtOnce) {
            $rows = $this->populateLogsData($rows);

            // If fallback was used and user wanted to sort by logshits/last_used,
            // we need to sort in PHP since the DB query sorted on NULL placeholders,
            // then apply the limit that was skipped in the query
            if ($needsPhpSortAndLimit && !empty($rows)) {
                $orderBy = $tableOptions['orderby'];
                $rawOrderDir = $tableOptions['order'] ?? 'DESC';
                $orderDir = strtoupper(is_string($rawOrderDir) ? $rawOrderDir : 'DESC');
                usort($rows, function($a, $b) use ($orderBy, $orderDir) {
                    $valA = isset($a[$orderBy]) ? $a[$orderBy] : 0;
                    $valB = isset($b[$orderBy]) ? $b[$orderBy] : 0;
                    // For last_used (timestamp), compare as integers
                    // For logshits (count), compare as integers
                    $primaryCmp = $valA <=> $valB;
                    if ($primaryCmp !== 0) {
                        return $orderDir === 'DESC' ? -$primaryCmp : $primaryCmp;
                    }

                    // Keep URL tie-break ASC to match SQL ordering.
                    $urlCmp = strcmp(is_scalar($a['url'] ?? '') ? (string)($a['url'] ?? '') : '', is_scalar($b['url'] ?? '') ? (string)($b['url'] ?? '') : '');
                    if ($urlCmp !== 0) {
                        return $urlCmp;
                    }

                    // Final tie-break by id in the requested direction.
                    $idCmp = (is_scalar($a['id'] ?? 0) ? (int)($a['id'] ?? 0) : 0) <=> (is_scalar($b['id'] ?? 0) ? (int)($b['id'] ?? 0) : 0);
                    return $orderDir === 'DESC' ? -$idCmp : $idCmp;
                });
                // Now apply the limit that was skipped in the query
                $rows = array_slice($rows, $limitStart, $limitEnd);
            }
        }
        $this->logger->debugMessage("Found " . $foundRowsBeforeLogsData . 
        	" rows to display before log data and " . count($rows) . 
        	" rows to display after log data for page: ". $sub);

        if ($canUseSnapshotCache && $snapshotCacheKey !== '' && is_array($rows)) {
            $this->setViewRowsSnapshotToTable($snapshotCacheKey, $sub, $rows, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            if (function_exists('set_transient')) {
                set_transient($snapshotCacheKey, $rows, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            }
        }
        if ($refreshLockHeld && $snapshotCacheKey !== '') {
            $this->releaseViewSnapshotRefreshLock($snapshotCacheKey);
        }
        
        return $rows;
    }
    
    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    function getRedirectsForViewCount(string $sub, array $tableOptions): int {
        $rawOrderByCount = $tableOptions['orderby'] ?? '';
        $orderByForSnapshot = strtolower(is_string($rawOrderByCount) ? $rawOrderByCount : '');
        $isLogsMaintenanceSort = ($orderByForSnapshot === 'logshits' || $orderByForSnapshot === 'last_used');
        $rawPerpageCount = $tableOptions['perpage'] ?? 0;
        $canUseSnapshotCache = function_exists('get_transient')
            && absint(is_scalar($rawPerpageCount) ? $rawPerpageCount : 0) <= 200
            && !$isLogsMaintenanceSort;
        $requestCountCacheKey = (string)$sub . '|' . md5(serialize($tableOptions));
        $countCacheKey = '';
        if ($canUseSnapshotCache) {
            $countCacheKey = $this->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
            $cachedCount = get_transient($countCacheKey);
            if ($cachedCount !== false) {
                return intval(is_scalar($cachedCount) ? $cachedCount : 0);
            }
        }
        if (array_key_exists($requestCountCacheKey, $this->redirectsForViewCountRequestCache)) {
            return intval($this->redirectsForViewCountRequestCache[$requestCountCacheKey]);
        }
    	
        $query = $this->getRedirectsForViewQuery($sub, $tableOptions, false, 0, PHP_INT_MAX,
        	true);

        $this->setSqlBigSelects();
        $results = $this->queryAndGetResults($query);
        $lastErrorRaw = $results['last_error'] ?? '';
        $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        if (!empty($lastError) && $this->isCollationError($lastError)) {
            $retryOptions = $tableOptions;
            $retryOptions['forceCollate'] = 'utf8mb4_general_ci';
            $retryQuery = $this->getRedirectsForViewQuery($sub, $retryOptions, false, 0, PHP_INT_MAX, true);
            $results = $this->queryAndGetResults($retryQuery);
            $lastErrorRaw2 = $results['last_error'] ?? '';
            $lastError = is_string($lastErrorRaw2) ? $lastErrorRaw2 : '';
        }

        if ($lastError != '' && trim($lastError) != '') {
        	throw new \Exception("Error getting redirect count: " . esc_html($lastError));
        }
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($rows)) {
            $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
        	return -1;
        }
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        $countValue = intval(is_scalar($row['count'] ?? 0) ? $row['count'] : 0);
        $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
        if ($canUseSnapshotCache && $countCacheKey !== '') {
            set_transient($countCacheKey, $countValue, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
        }
        return $countValue;
    }
    
    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $queryAllRowsAtOnce
     * @param int $limitStart
     * @param int $limitEnd
     * @param bool $selectCountOnly
     * @return string
     */
    function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
    	$limitStart, $limitEnd, $selectCountOnly) {
        global $abj404_redirect_types;
        global $abj404_captured_types;
        global $wpdb;

        $logsTableColumns = '';
        $logsTableJoin = '';
        $statusTypes = '';
        $trashValue = '';
        $selectCountReplacement = '/* selecting data as usual */';
        
        /* if we only want the count(*) then comment out everything else. */
        if ($selectCountOnly) {
        	$selectCountReplacement = "\n /*+ SET_VAR(max_join_size=18446744073709551615) */\n" . 
        		"count(*) as count\n /* only selecting for count";
        }

        // if we're showing all rows include all of the log data in the query already. this makes the query very slow. 
        // this should be replaced by the dynamic loading of log data using ajax queries as the page is viewed.
        if ($queryAllRowsAtOnce) {
             $logsTableColumns = "logstable.logshits as logshits, \n" .
                    "logstable.logsid, \n" .
                    "logstable.last_used, \n";
        } else {
            $logsTableColumns = "null as logshits, \n null as logsid, \n null as last_used, \n";
        }        

        if ($queryAllRowsAtOnce) {
            // create a temp table and use that instead of a subselect to avoid the sql error
            // "The SELECT would examine more than MAX_JOIN_SIZE rows"
            $this->maybeUpdateRedirectsForViewHitsTable();

            // Verify table was actually created before using it (handles silent creation failures)
            if ($this->logsHitsTableExists()) {
                // canonical_url is the persisted CONCAT('/', TRIM(BOTH '/' FROM url))
                // form (added 4.1.10) so this JOIN is a single indexed equality
                // lookup against logs_hits.requested_url instead of evaluating
                // the function on every redirects row. The COALESCE fallback
                // covers rows from upgraded sites where the chunked backfill
                // hasn't reached yet — those rows merge in via the original
                // expression so behavior matches pre-upgrade exactly.
                $logsTableJoin = "  LEFT OUTER JOIN {wp_abj404_logs_hits} logstable \n " .
                        "  on binary logstable.requested_url = " .
                        "binary COALESCE(wp_abj404_redirects.canonical_url, " .
                        "concat('/', trim(both '/' from wp_abj404_redirects.url))) \n ";
            } else {
                // Fall back to null columns if table creation failed
                $logsTableColumns = "null as logshits, \n null as logsid, \n null as last_used, \n";
                $this->logger->debugMessage("logs_hits table not available, falling back to null columns");
            }
        }
        
        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            if ($sub == 'abj404_redirects') {
                $statusTypes = implode(", ", $abj404_redirect_types);

            } else if ($sub == 'abj404_captured') {
                $statusTypes = implode(", ", $abj404_captured_types);

            } else {
                $this->logger->errorMessage("Unrecognized sub type: " . esc_html($sub));
            }

        } else if ($tableOptions['filter'] == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(", ", array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));

        } else if ($tableOptions['filter'] == ABJ404_HANDLED_FILTER) {
            // Composite filter: Ignored + Later (Simple mode "Handled" tab)
            $statusTypes = implode(", ", array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));

        } else {
            $statusTypes = $tableOptions['filter'];
        }
        $statusTypes = preg_replace('/[^\d, ]/', '', trim(is_string($statusTypes) ? $statusTypes : ''));

        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $trashValue = 1;
        } else if ($tableOptions['filter'] == ABJ404_HANDLED_FILTER) {
            // Show both active (disabled=0) and trashed (disabled=1) in Handled view
            $trashValue = 0;
        } else {
            $trashValue = 0;
        }

        /* only try to order by if we're actually selecting data and not only
         * counting the number of rows. */
        $orderByString = '';
        if (!$selectCountOnly) {
            $rawOrderBy = $tableOptions['orderby'] ?? '';
            $orderBy = $this->f->strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
            if ($orderBy == "final_dest") {
                // TODO change the final dest type to an integer and store external URLs somewhere else.
                $orderBy = "case when post_title is null then 1 else 0 end asc, post_title";
            } else {
                // only allow letters and the underscore in the orderby string.
                $orderBy = preg_replace('/[^a-zA-Z_]/', '', trim($orderBy));
            }
            $rawOrderVal = $tableOptions['order'] ?? '';
            $rawOrderValX = is_string($rawOrderVal) ? $rawOrderVal : '';
            $order = strtoupper((string)preg_replace('/[^a-zA-Z_]/', '', trim($rawOrderValX)));
            if ($order !== 'DESC') {
                $order = 'ASC';
            }
            $orderByString = "order by published_status asc, " . $orderBy . " " . $order .
                ", wp_abj404_redirects.url ASC, wp_abj404_redirects.id " . $order;
        }

        // Score range filter clause.
        $rawScoreRange = is_string($tableOptions['score_range'] ?? '') ? ($tableOptions['score_range'] ?? 'all') : 'all';
        switch ($rawScoreRange) {
            case 'high':
                $scoreRangeClause = 'AND wp_abj404_redirects.score >= 80';
                break;
            case 'medium':
                $scoreRangeClause = 'AND wp_abj404_redirects.score >= 50 AND wp_abj404_redirects.score < 80';
                break;
            case 'low':
                $scoreRangeClause = 'AND wp_abj404_redirects.score IS NOT NULL AND wp_abj404_redirects.score < 50';
                break;
            case 'manual':
                $scoreRangeClause = 'AND wp_abj404_redirects.score IS NULL';
                break;
            default:
                $scoreRangeClause = '';
                break;
        }

        $searchFilterForRedirectsExists = "no redirects fiter text found";
        $searchFilterForCapturedExists = "no captured 404s filter text found";
        $filterText = '';
        $rawFilterText = is_string($tableOptions['filterText'] ?? null) ? $tableOptions['filterText'] : '';
        if ($rawFilterText != '') {
            if ($sub == 'abj404_redirects') {
                // Close the comment without including user input to avoid comment breakout.
                $searchFilterForRedirectsExists = ' filter text enabled */';
                
            } else if ($sub == 'abj404_captured') {
                // Close the comment without including user input to avoid comment breakout.
                $searchFilterForCapturedExists = ' filter text enabled */';
                
            } else {
                throw new Exception("Unrecognized page for filter text request.");
            }
        }

        // Sanitize filter text for use inside LIKE; strip comment markers and escape for SQL LIKE.
        $filterTextRaw = str_replace(array('*', '/', '$'), '', $rawFilterText);
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'esc_like')) {
            /** @var wpdb $wpdb */
            $filterTextRaw = $wpdb->esc_like($filterTextRaw);
        } else {
            $filterTextRaw = addcslashes($filterTextRaw, '_%\\');
        }
        $filterText = esc_sql($filterTextRaw);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsForView.sql");
        // Ensure consistent collation for string operations (e.g., REPLACE/LOWER) to avoid
        // "Illegal mix of collations" errors when plugin tables use *_bin collations.
        $wpdbCollate = 'utf8mb4_unicode_ci';
        $hasForcedCollate = false;
        if (array_key_exists('forceCollate', $tableOptions) && !empty($tableOptions['forceCollate'])) {
            $rawForceCollateVal = $tableOptions['forceCollate'];
            $rawForceCollate = is_string($rawForceCollateVal) ? $rawForceCollateVal : '';
            $forced = preg_replace('/[^A-Za-z0-9_]/', '', $rawForceCollate);
            if ($forced !== '') {
                $wpdbCollate = $forced;
                $hasForcedCollate = true;
            }
        }
        if (!$hasForcedCollate && isset($wpdb) && isset($wpdb->collate) && !empty($wpdb->collate)) {
            $wpdbCollate = preg_replace('/[^A-Za-z0-9_]/', '', $wpdb->collate);
        }
        if ($wpdbCollate === '') {
            $wpdbCollate = 'utf8mb4_unicode_ci';
        }
        $query = $this->f->str_replace('{selecting-for-count-true-false}', $selectCountReplacement, $query);
        $query = $this->f->str_replace('{statusTypes}', $statusTypes, $query);
        $query = $this->f->str_replace('{orderByString}', $orderByString, $query);
        $query = $this->f->str_replace('{limitStart}', (string)$limitStart, $query);
        $query = $this->f->str_replace('{limitEnd}', (string)$limitEnd, $query);
        $query = $this->f->str_replace('{searchFilterForRedirectsExists}', $searchFilterForRedirectsExists, $query);
        $query = $this->f->str_replace('{searchFilterForCapturedExists}', $searchFilterForCapturedExists, $query);
        $query = $this->f->str_replace('{filterText}', $filterText, $query);
        $query = $this->f->str_replace('{wpdb_collate}', $wpdbCollate, $query);
        $query = $this->f->str_replace('{logsTableColumns}', $logsTableColumns, $query);
        $query = $this->f->str_replace('{logsTableJoin}', $logsTableJoin, $query);
        $query = $this->f->str_replace('{trashValue}', (string)$trashValue, $query);
        $query = $this->f->str_replace('{scoreRangeClause}', $scoreRangeClause, $query);
        $query = $this->doTableNameReplacements($query);
        
        if (array_key_exists('translations', $tableOptions) && is_array($tableOptions['translations'])) {
            $keys = array_keys($tableOptions['translations']);
            $values = array_values($tableOptions['translations']);
            /** @var array<int, string> $keys */
            $query = $this->f->str_replace($keys, array_map('strval', $values), $query);
        }
        
        $query = $this->f->doNormalReplacements($query);
        
        return $query;
    }

    /**
     * @param array<int, string> $postIDs
     * @return array<int, mixed>
     */
    function getExtraDataToPermalinkSuggestions(array $postIDs): array {
        // Sanitize all post IDs to prevent SQL injection
        $postIDs = array_map('absint', $postIDs);
        $postIDJoined = implode(", ", $postIDs);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getAdditionalPostData.sql");
        $query = $this->f->str_replace('{IDS_TO_INCLUDE}', $postIDJoined, $query);
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);
        
        $results = $this->queryAndGetResults($query);

        /** @var array<int, mixed> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /**
     * Prepare a WordPress SQL query with placeholders and an associative data array.
     *
     * @param string $query The SQL query string with {placeholder} style placeholders.
     * @param array<string, mixed> $data An associative array with keys matching the placeholders in the query.
     * @return string The fully prepared SQL query.
     */
    function prepare_query_wp($query, $data) {
        global $wpdb;
        list($prepared_query, $ordered_values) = $this->prepare_query($query, $data);
        return $wpdb->prepare($prepared_query, $ordered_values);
    }
    
    /**
     * Prepare a SQL query with placeholders and an associative data array.
     *
     * @param string $query The SQL query string with {placeholder} style placeholders.
     * @param array<string, mixed> $data An associative array with keys matching the placeholders in the query.
     * @return array{0: string, 1: array<int, mixed>} Returns an array containing two elements: the prepared query string with %s or %d placeholders, and an ordered array of values for those placeholders.
     */
    function prepare_query($query, $data) {
        $ordered_values = [];
        $prepared_query = preg_replace_callback('/\{(\w+)\}/', function($matches) use ($data, &$ordered_values) {
            $key = $matches[1];
            if (!isset($data[$key])) {
                // Placeholder key not found in data array, ignore and continue
                return $matches[0];
            }
            $value = $data[$key];
            
            // Append the value to the ordered values array
            $ordered_values[] = $value;
            
            // Determine the placeholder type
            $placeholder_type = is_int($value) ? '%d' : '%s';
            
            return $placeholder_type;
        }, $query);
            
        return [$prepared_query !== null ? $prepared_query : $query, $ordered_values];
    }
    
    /** @return void */
    function maybeUpdateRedirectsForViewHitsTable(): void {
        // Record that we checked during this request (used for admin tooltip UX).
        $this->setRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG, time(), 86400);

        // Piggyback on the captured-404s tab render: also schedule a
        // 15-second logsv2.canonical_url backfill at shutdown if there's
        // legacy NULL-row backlog. The shutdown handler holds a worker
        // for the budget but the admin response is already flushed by
        // fastcgi_finish_request, so the user doesn't perceive the wait.
        // The function is internally deduped + gated on column existence,
        // probe results, and the backfill-complete option, so calling it
        // unconditionally is cheap.
        if (function_exists('abj_service')) {
            $upgradesEtc = abj_service('database_upgrades');
            if (is_object($upgradesEtc) && method_exists($upgradesEtc, 'scheduleLogsv2CanonicalUrlBackfill')) {
                $upgradesEtc->scheduleLogsv2CanonicalUrlBackfill();
            }
        }

        if ($this->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return;
        }

        // Check if the table exists
        if (!$this->logsHitsTableExists()) {
            // Defer creation to shutdown hook so the admin page loads immediately.
            // The view query gracefully falls back to null hits columns when the
            // table doesn't exist (getRedirectsForViewQuery checks logsHitsTableExists).
            // On sites with large logsv2 tables the INSERT...SELECT that populates
            // the hits table can take minutes, which exceeds proxy timeouts (e.g.
            // Cloudflare's 100-second limit → HTTP 524).
            $this->logger->debugMessage(__FUNCTION__ . " table doesn't exist, deferring creation to shutdown hook.");
            $this->scheduleHitsTableRebuild();
            return;
        }

        // Check if rebuild is needed (logs have changed since last build)
        if (!$this->hitsTableNeedsRebuild()) {
            // No new log entries - skip rebuild to reduce server load
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'not_needed', 86400);
            return;
        }

        // Table exists and logs have changed - defer to shutdown hook
        $this->scheduleHitsTableRebuild();
    }

    /**
     * Schedule the hits table to be rebuilt at shutdown.
     *
     * Uses a static flag to ensure the hook is only registered once per request,
     * even if multiple calls to getRedirectsForView with hits sorting occur.
     *
     * The shutdown hook runs after the response is sent, so the admin sees the page
     * immediately with existing data, and fresh data is available on next load.
     */
    /** @return void */
    function scheduleHitsTableRebuild(): void {
        if ($this->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return;
        }
        if (!self::$hitsTableRebuildScheduled) {
            if ($this->isHitsTableRebuildLocked()) {
                $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling because another rebuild is already running.");
                $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'running', 86400);
                return;
            }

            $rawScheduledFlag = $this->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG);
            $lastScheduled = is_scalar($rawScheduledFlag) ? (int)$rawScheduledFlag : 0;
            if ($lastScheduled > 0 && (time() - $lastScheduled) < self::HITS_TABLE_SCHEDULE_COOLDOWN_SECONDS) {
                $this->logger->debugMessage(__FUNCTION__ . " skipping scheduling due to cooldown.");
                $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'cooldown', 86400);
                return;
            }

            self::$hitsTableRebuildScheduled = true;
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG, time(), 86400);
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'scheduled', 86400);
            if ($this->shouldScheduleHitsTableRebuildViaCron()) {
                $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild via WP-Cron.");
                if (function_exists('wp_schedule_single_event')) {
                    wp_schedule_single_event(time() + 5, 'abj404_updateLogsHitsTableAction');
                }
                return;
            }

            $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild for shutdown hook.");
            add_action('shutdown', function(): void { $this->createRedirectsForViewHitsTable(); });
        }
    }

    /** @return bool */
    private function shouldScheduleHitsTableRebuildViaCron(): bool {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? $_SERVER['SCRIPT_NAME'] : '';
        if ($scriptName !== '' && basename($scriptName) === 'admin-ajax.php') {
            return true;
        }
        $pagenow = isset($GLOBALS['pagenow']) && is_string($GLOBALS['pagenow'])
            ? $GLOBALS['pagenow'] : '';
        return $pagenow === 'admin-ajax.php';
    }

    private function getHitsTableRebuildLockOptionName(): string {
        return $this->getLowercasePrefix() . 'abj404_logs_hits_rebuild_lock';
    }

    /** @return bool */
    private function isHitsTableRebuildLocked(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $lockValue = get_option($this->getHitsTableRebuildLockOptionName(), false);
        if ($lockValue === false || $lockValue === null || $lockValue === '') {
            return false;
        }
        // Defensive: if lock is corrupted (non-numeric), clear it so rebuilds can resume.
        if (!is_numeric($lockValue)) {
            if (function_exists('delete_option')) {
                delete_option($this->getHitsTableRebuildLockOptionName());
            }
            return false;
        }
        $lockTimestamp = (int)$lockValue;
        if ($lockTimestamp > 0 && (time() - $lockTimestamp) > self::HITS_TABLE_REBUILD_LOCK_TTL_SECONDS) {
            if (function_exists('delete_option')) {
                delete_option($this->getHitsTableRebuildLockOptionName());
            }
            return false;
        }
        return true;
    }

    /** @return int|null */
    function getLogsHitsTableLastCheckedAt() {
        $rawTsFlag = $this->getRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG);
        $ts = is_scalar($rawTsFlag) ? (int)$rawTsFlag : 0;
        return $ts > 0 ? $ts : null;
    }

    /** @return int|null */
    function getLogsHitsTableLastScheduledAt() {
        $rawTsFlag2 = $this->getRuntimeFlag(self::HITS_TABLE_LAST_SCHEDULED_FLAG);
        $ts = is_scalar($rawTsFlag2) ? (int)$rawTsFlag2 : 0;
        return $ts > 0 ? $ts : null;
    }

    /** @return string */
    function getLogsHitsTableLastDecision(): string {
        $v = $this->getRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG);
        return is_string($v) ? $v : '';
    }

    /** @return bool */
    private function acquireHitsTableRebuildLock(): bool {
        if (!function_exists('add_option')) {
            return true;
        }
        if ($this->isHitsTableRebuildLocked()) {
            return false;
        }
        return (bool)add_option(
            $this->getHitsTableRebuildLockOptionName(),
            (string)time(),
            '',
            false
        );
    }

    /** @return void */
    private function releaseHitsTableRebuildLock(): void {
        if (function_exists('delete_option')) {
            delete_option($this->getHitsTableRebuildLockOptionName());
        }
    }

    /** @return bool */
    private function logsHitsTableExistsViaShowTables(): bool {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return false;
        }
        $tableName = $this->doTableNameReplacements('{wp_abj404_logs_hits}');
        /** @var wpdb $wpdb */
        $showTablesQuery = $wpdb->prepare("SHOW TABLES LIKE %s", $tableName);
        if ($showTablesQuery === null) {
            return false;
        }
        $fallback = $this->queryAndGetResults($showTablesQuery, array('log_errors' => false));
        if (empty($fallback['rows'])) {
            return false;
        }
        $fbRows = is_array($fallback['rows']) ? $fallback['rows'] : array();
        $firstRow = isset($fbRows[0]) ? $fbRows[0] : null;
        if (!is_array($firstRow)) {
            return false;
        }
        $value = reset($firstRow);
        return ((string)$value === (string)$tableName);
    }

    /**
     * Check if the logs_hits table exists.
     * Used to verify table was created before using it in queries.
     * @return bool
     */
    function logsHitsTableExists() {
        $query = "SELECT 1 FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE() LIMIT 1";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        if ($results['rows'] != null && !empty($results['rows'])) {
            return true;
        }
        if (!empty($results['last_error'])) {
            // Some hosts restrict information_schema access; fall back to SHOW TABLES.
            return $this->logsHitsTableExistsViaShowTables();
        }
        return false;
    }

    /**
     * Get the maximum log ID from the logs table.
     *
     * Used to detect if logs have changed since the hits table was last built.
     * O(1) query using primary key index.
     *
     * @return int Maximum log ID, or 0 if table is empty
     */
    function getMaxLogId() {
        $query = "SELECT MAX(id) FROM {wp_abj404_logsv2}";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) {
            return 0;
        }

        $row = $resultRows[0];
        // Handle both object and array results
        $maxId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return (int)($maxId ?? 0);
    }

    /** @return int */
    function getMinLogId() {
        $query = "SELECT MIN(id) FROM {wp_abj404_logsv2}";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        $resultRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($resultRows)) {
            return 0;
        }

        $row = $resultRows[0];
        $minId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return (int)($minId ?? 0);
    }

    /**
     * Get the stored max log ID from the hits table comment.
     *
     * Comment format: "elapsed_time|max_log_id" (e.g., "0.35|12345")
     *
     * @return int Stored max log ID, or 0 if not found
     */
    function getStoredMaxLogId() {
        $query = "SELECT table_comment FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        $storedRows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($storedRows)) {
            if (!empty($results['last_error'])) {
                $statusRow = $this->getLogsHitsTableStatusRow();
                $commentFromStatus = $statusRow['comment'] ?? '';
                if ($commentFromStatus !== '') {
                    $parts = explode('|', is_string($commentFromStatus) ? $commentFromStatus : '');
                    if (count($parts) >= 2) {
                        return (int)$parts[1];
                    }
                }
            }
            return 0;
        }

        $row = is_array($storedRows[0] ?? null) ? $storedRows[0] : array();
        $row = array_change_key_case($row);
        $comment = $row['table_comment'] ?? '';

        // Parse comment format: "elapsed_time|max_log_id"
        $parts = explode('|', is_string($comment) ? $comment : '');
        if (count($parts) >= 2) {
            return (int)$parts[1];
        }

        // Old format (just elapsed time) or empty - treat as needing rebuild
        return 0;
    }

    /**
     * Check if the hits table needs to be rebuilt.
     *
     * Rebuild is needed if:
     * 1. MAX(id) from logs differs from stored value (new entries or deletions)
     * 2. Table is older than HITS_TABLE_MAX_AGE_SECONDS (staleness check)
     *
     * @return bool True if rebuild needed
     */
}
