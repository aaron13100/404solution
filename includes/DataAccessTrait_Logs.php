<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_LogsTrait {

    /** @return bool */
    function hitsTableNeedsRebuild() {
        $storedMaxId = $this->getStoredMaxLogId();
        $currentMaxId = $this->getMaxLogId();

        // Check if log entries have changed
        if ($currentMaxId != $storedMaxId) {
            $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (max_id changed: stored=$storedMaxId, current=$currentMaxId)");
            return true;
        }

        // Check if table is too old (staleness check)
        $lastUpdated = $this->getLogsHitsTableLastUpdated();
        if ($lastUpdated !== null) {
            $age = time() - $lastUpdated;
            if ($age > self::HITS_TABLE_MAX_AGE_SECONDS) {
                $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (stale: age={$age}s > " . self::HITS_TABLE_MAX_AGE_SECONDS . "s)");
                return true;
            }
        }

        $this->logger->debugMessage(__FUNCTION__ . " rebuild=no (max_id=$currentMaxId unchanged, not stale)");
        return false;
    }

    /**
     * Get the last update time of the logs_hits table.
     *
     * Uses the MySQL table creation time from information_schema since
     * the table is dropped and recreated on each rebuild.
     *
     * @return int|null Unix timestamp of last update, or null if table doesn't exist
     */
    function getLogsHitsTableLastUpdated() {
        $rawRefreshedFlag = $this->getRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG);
        $runtimeRefreshedAt = is_scalar($rawRefreshedFlag) ? (int)$rawRefreshedFlag : 0;
        $runtimeRefreshedAt = $runtimeRefreshedAt > 0 ? $runtimeRefreshedAt : null;

        $query = "SELECT create_time FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        if ($results['rows'] == null || empty($results['rows'])) {
            if (!empty($results['last_error'])) {
                $statusRow = $this->getLogsHitsTableStatusRow();
                $dateValue = '';
                if (is_array($statusRow)) {
                    $dateValue = $statusRow['update_time'] ?? ($statusRow['create_time'] ?? '');
                }
                if ($dateValue !== '') {
                    $fallbackTimestamp = strtotime(is_string($dateValue) ? $dateValue : '');
                    if ($fallbackTimestamp !== false) {
                        if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $fallbackTimestamp) {
                            return $runtimeRefreshedAt;
                        }
                        return $fallbackTimestamp;
                    }
                }
            }
            return $runtimeRefreshedAt;
        }

        $hitsRows = is_array($results['rows']) ? $results['rows'] : array();
        $row = is_array($hitsRows[0] ?? null) ? $hitsRows[0] : array();
        $row = array_change_key_case($row);
        $createTime = $row['create_time'] ?? null;

        if ($createTime === null) {
            return $runtimeRefreshedAt;
        }

        // Convert MySQL datetime to Unix timestamp
        $schemaTimestamp = strtotime(is_string($createTime) ? $createTime : '');
        if ($schemaTimestamp === false) {
            return $runtimeRefreshedAt;
        }
        if ($runtimeRefreshedAt !== null && $runtimeRefreshedAt > $schemaTimestamp) {
            return $runtimeRefreshedAt;
        }
        return $schemaTimestamp;
    }

    /** @return array<string, mixed> */
    private function getLogsHitsTableStatusRow() {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return array();
        }
        $tableName = $this->doTableNameReplacements('{wp_abj404_logs_hits}');
        /** @var wpdb $wpdb */
        $query = $wpdb->prepare("SHOW TABLE STATUS LIKE %s", $tableName);
        if ($query === null) {
            return array();
        }
        $results = $this->queryAndGetResults($query, array('log_errors' => false));
        if (!is_array($results['rows']) || empty($results['rows']) || !is_array($results['rows'][0])) {
            return array();
        }
        return array_change_key_case($results['rows'][0], CASE_LOWER);
    }

    /**
     * Get a human-readable "time ago" string for the hits table's last update.
     *
     * @return string e.g., "2 minutes ago", "1 hour ago", or empty string if unknown
     */
    function getLogsHitsTableLastUpdatedHuman() {
        $timestamp = $this->getLogsHitsTableLastUpdated();

        if ($timestamp === null) {
            return '';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return __('Just now', '404-solution');
        } elseif ($diff < 3600) {
            $minutes = (int)floor($diff / 60);
            return sprintf(_n('%d minute ago', '%d minutes ago', $minutes, '404-solution'), $minutes);
        } elseif ($diff < 86400) {
            $hours = (int)floor($diff / 3600);
            return sprintf(_n('%d hour ago', '%d hours ago', $hours, '404-solution'), $hours);
        } else {
            $days = (int)floor($diff / 86400);
            return sprintf(_n('%d day ago', '%d days ago', $days, '404-solution'), $days);
        }
    }

    /** @return bool */
    function createRedirectsForViewHitsTable(): bool {
        $wasRefreshed = false;
        if ($this->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return false;
        }
        if (!$this->acquireHitsTableRebuildLock()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped because rebuild lock is already held.");
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'running', 86400);
            return false;
        }
        try {
        
        $finalDestTable = $this->doTableNameReplacements("{wp_abj404_logs_hits}");
        $tempDestTable = $this->doTableNameReplacements("{wp_abj404_logs_hits}_temp");
        $ttSelectQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
        	"/sql/getRedirectsForViewTempTable.sql");
        $ttSelectQuery = $this->doTableNameReplacements($ttSelectQuery);
        
        // create a temp table
        $this->queryAndGetResults("drop table if exists " . $tempDestTable);
        $createTempTableQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
        	"/sql/createLogsHitsTempTable.sql");
        $createTempTableQuery = $this->doTableNameReplacements($createTempTableQuery);
        $this->queryAndGetResults($createTempTableQuery);
        $this->queryAndGetResults("truncate table " . $tempDestTable);
        
        // Capture a pre-insert snapshot watermark.
        // This keeps rebuild checks consistent with getMaxLogId() while avoiding
        // claiming coverage for rows that may arrive during/after the insert.
        $maxLogIdSnapshot = $this->getMaxLogId();

        // insert the data into the temp table (this may take time).
        $ttInsertQuery = "insert into " . $tempDestTable . " (requested_url, logsid, " .
        	"last_used, logshits) \n " . $ttSelectQuery;
        $results = $this->queryAndGetResults($ttInsertQuery, array('log_too_slow' => false));

        // Store elapsed time and max log ID in comment for invalidation check
        // Format: "elapsed_time|max_log_id" (e.g., "0.35|12345")
        $elapsedTime = $results['elapsed_time'];
        $comment = $elapsedTime . '|' . $maxLogIdSnapshot;
        // Escape comment and truncate to MySQL's 2048 char limit for table comments
        $comment = substr(esc_sql($comment), 0, 2048);
        $addComment = "ALTER TABLE " . $tempDestTable . " COMMENT '" . $comment . "'";
        $this->queryAndGetResults($addComment);
        
        // drop the old hits table and rename the temp table to the hits table as a transaction
        $statements = array(
            "drop table if exists " . $finalDestTable,
            "rename table " . $tempDestTable . ' to ' . $finalDestTable
        );
        $this->executeAsTransaction($statements);
        $this->setRuntimeFlag(self::HITS_TABLE_LAST_REFRESHED_FLAG, time(), 86400);
        $wasRefreshed = true;
        
        $this->logger->debugMessage(__FUNCTION__ . " refreshed " . $finalDestTable . " in " . $elapsedTime . 
                " seconds.");
        } catch (Throwable $e) {
            // Never break the admin request because a shutdown refresh fails.
            $this->logger->errorMessage(__FUNCTION__ . " failed: " . $e->getMessage(), $e instanceof \Exception ? $e : null);
            $this->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
        } finally {
            $this->releaseHitsTableRebuildLock();
        }
        return $wasRefreshed;
    }
    
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    function populateLogsData($rows) {
        global $wpdb;

        // If no rows, return early
        if (empty($rows)) {
            return $rows;
        }

        // Extract all non-empty URLs from rows.
        // Keep lookup variants so legacy rows (e.g. missing leading slash) still map.
        $urls = array();
        foreach ($rows as $row) {
            if ($row['url'] != null && !empty($row['url'])) {
                $variants = $this->buildHitsLookupUrlVariants($row['url']);
                foreach ($variants as $variant) {
                    $urls[] = $variant;
                }
            }
        }

        // If no valid URLs, return rows unchanged
        if (empty($urls)) {
            return $rows;
        }

        // Remove duplicates to avoid unnecessary work
        $urls = array_unique($urls);

        // Fetch all logs data in a single batch query
        $placeholders = implode(',', array_fill(0, count($urls), '%s'));
        $logsTable = $this->getPrefixedTableName('abj404_logsv2');
        $query = $wpdb->prepare(
            "SELECT requested_url,
                    MIN(id) AS logsid,
                    MAX(timestamp) AS last_used,
                    COUNT(requested_url) AS logshits
             FROM {$logsTable}
             WHERE requested_url IN ($placeholders)
             GROUP BY requested_url",
            $urls
        );

        $logsResults = $wpdb->get_results($query, ARRAY_A);

        // Check for errors
        if ($wpdb->last_error) {
            $this->logger->errorMessage("Error executing batch logs query. Err: " . $wpdb->last_error);
            return $rows;
        }

        // Index logs data by canonical URL for fast lookup
        $logsDataByUrl = array();
        foreach ($logsResults as $logRow) {
            $canonicalUrl = $this->canonicalizeUrlForHitsMatch($logRow['requested_url'] ?? '');
            if ($canonicalUrl === '') {
                continue;
            }
            if (!isset($logsDataByUrl[$canonicalUrl])) {
                $logsDataByUrl[$canonicalUrl] = array(
                    'logsid' => (int)($logRow['logsid'] ?? 0),
                    'logshits' => (int)($logRow['logshits'] ?? 0),
                    'last_used' => (int)($logRow['last_used'] ?? 0),
                );
                continue;
            }
            $existing = $logsDataByUrl[$canonicalUrl];
            $currentLogsid = (int)($logRow['logsid'] ?? 0);
            $existingLogsid = (int)$existing['logsid'];
            $logsDataByUrl[$canonicalUrl]['logsid'] = ($existingLogsid > 0 && $currentLogsid > 0)
                ? min($existingLogsid, $currentLogsid)
                : max($existingLogsid, $currentLogsid);
            $logsDataByUrl[$canonicalUrl]['logshits'] = (int)$existing['logshits'] + (int)($logRow['logshits'] ?? 0);
            $logsDataByUrl[$canonicalUrl]['last_used'] = max((int)$existing['last_used'], (int)($logRow['last_used'] ?? 0));
        }

        // Populate rows with logs data using indexed lookup
        foreach ($rows as &$row) {
            if ($row['url'] != null && !empty($row['url'])) {
                $canonicalUrl = $this->canonicalizeUrlForHitsMatch($row['url']);
                if (isset($logsDataByUrl[$canonicalUrl])) {
                    $logData = $logsDataByUrl[$canonicalUrl];
                    $row['logsid'] = $logData['logsid'];
                    $row['logshits'] = $logData['logshits'];
                    $row['last_used'] = $logData['last_used'];
                }
            }
        }

        return $rows;
    }

    /**
     * @param mixed $url
     * @return string
     */
    private function canonicalizeUrlForHitsMatch($url): string {
        if (!is_string($url)) {
            return '';
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $fragment = '';
        $fragmentPos = strpos($url, '#');
        if ($fragmentPos !== false) {
            $fragment = substr($url, $fragmentPos);
            $url = substr($url, 0, $fragmentPos);
        }

        $query = '';
        $queryPos = strpos($url, '?');
        if ($queryPos !== false) {
            $query = substr($url, $queryPos);
            $url = substr($url, 0, $queryPos);
        }

        $path = trim($url, '/');
        $normalizedPath = ($path === '') ? '/' : '/' . $path;

        return $normalizedPath . $query . $fragment;
    }

    /**
     * @param mixed $url
     * @return array<int, string>
     */
    private function buildHitsLookupUrlVariants($url) {
        $variants = array();
        if (!is_string($url)) {
            return $variants;
        }

        $raw = trim($url);
        if ($raw !== '') {
            $variants[] = $raw;
        }

        $canonical = $this->canonicalizeUrlForHitsMatch($url);
        if ($canonical !== '') {
            $variants[] = $canonical;
            $parts = $this->splitCanonicalHitsUrl($canonical);
            $pathPart = $parts['path'];
            $suffixPart = $parts['suffix'];

            $pathVariants = array($pathPart);
            $noLeadingPath = ltrim($pathPart, '/');
            if ($noLeadingPath !== '') {
                $pathVariants[] = $noLeadingPath;
            }

            if ($pathPart !== '/') {
                if (substr($pathPart, -1) === '/') {
                    $toggleTrailingPath = rtrim($pathPart, '/');
                } else {
                    $toggleTrailingPath = $pathPart . '/';
                }
                $pathVariants[] = $toggleTrailingPath;
                $toggleNoLeadingPath = ltrim($toggleTrailingPath, '/');
                if ($toggleNoLeadingPath !== '') {
                    $pathVariants[] = $toggleNoLeadingPath;
                }
            }

            foreach (array_unique($pathVariants) as $pathVariant) {
                $variants[] = $pathVariant . $suffixPart;
            }
        }

        return array_values(array_unique($variants));
    }

    /** @return array{path: string, suffix: string} */
    private function splitCanonicalHitsUrl(string $canonicalUrl): array {
        $firstQueryPos = strpos($canonicalUrl, '?');
        $firstFragmentPos = strpos($canonicalUrl, '#');

        if ($firstQueryPos === false && $firstFragmentPos === false) {
            return array('path' => $canonicalUrl, 'suffix' => '');
        }

        if ($firstQueryPos === false) {
            $splitPos = $firstFragmentPos;
        } elseif ($firstFragmentPos === false) {
            $splitPos = $firstQueryPos;
        } else {
            $splitPos = min($firstQueryPos, $firstFragmentPos);
        }

        return array(
            'path' => substr($canonicalUrl, 0, $splitPos),
            'suffix' => substr($canonicalUrl, $splitPos),
        );
    }

    /**
     * @param string $specificURL
     * @return array<int, array<string, mixed>>
     */
    function getLogsIDandURL($specificURL = '') {
        global $wpdb;
    	$whereClause = '';
        if ($specificURL != '') {
            // Escape user input to prevent SQL injection
            $escapedURL = esc_sql($specificURL);
            $whereClause = "where requested_url = '" . $escapedURL . "'";
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsIDandURL.sql");
        $query = $this->f->str_replace('{where_clause_here}', $whereClause, $query);

        $results = $this->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : array();

        return $rows;
    }
    
    /**
     * @param string $specificURL
     * @param string|int $limitResults
     * @return array<int, array<string, mixed>>
     */
    function getLogsIDandURLLike($specificURL, $limitResults) {
        global $wpdb;
    	$whereClause = '';
        if ($specificURL != '') {
            // Escape user input to prevent SQL injection
            // Use esc_like for LIKE queries, then add wildcards, then esc_sql for the full string.
            // esc_like escapes '%' and '_' so callers must pass the raw search term (no wildcards).
            $likePattern = '%' . $wpdb->esc_like($specificURL) . '%';
            $escapedURL = esc_sql($likePattern);
            $whereClause = "where lower(requested_url) like lower('" . $escapedURL . "')\n";
            $whereClause .= "and min_log_id = true";
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsIDandURLForAjax.sql");
        $query = $this->f->str_replace('{where_clause_here}', $whereClause, $query);
        $query = $this->f->str_replace('{limit-results}', 'limit ' . absint($limitResults), $query);

        $results = $this->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : array();

        return $rows;
    }
    
    /**
     * @param array<string, mixed> $tableOptions orderby, paged, perpage, etc.
     * @return array<int, array<string, mixed>> rows from querying the logs table.
     */
    function getLogRecords($tableOptions) {
    	$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

    	$logsid_included = '';
        $logsid = '';
        $rawLogsId = $tableOptions['logsid'];
        if ($rawLogsId != 0) {
            $logsid_included = 'specific logs id included. */';
            $logsid = esc_sql($abj404logic->sanitizeForSQL(is_string($rawLogsId) ? $rawLogsId : ''));
        }

        // Whitelist allowed columns for orderby to prevent SQL injection
        $allowedOrderbyColumns = array(
            'timestamp',
            'requested_url',
            'url',
            'dest_url',
            'id',
            'referrer',
            'min_log_id',
            'logshits',
            'action',
            'remote_host',
            'user_ip',
            'username',
            'engine',
        );
        $rawOrderByVal = $tableOptions['orderby'];
        $orderby = sanitize_text_field($abj404logic->sanitizeForSQL(is_string($rawOrderByVal) ? $rawOrderByVal : ''));
        if (!in_array($orderby, $allowedOrderbyColumns, true)) {
            $orderby = 'timestamp'; // Safe default
        }

        // Whitelist allowed order directions
        $rawOrderVal2 = $tableOptions['order'];
        $order = strtoupper(sanitize_text_field($abj404logic->sanitizeForSQL(is_string($rawOrderVal2) ? $rawOrderVal2 : '')));
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC'; // Safe default
        }

        $paged = absint(is_scalar($tableOptions['paged'] ?? 1) ? ($tableOptions['paged'] ?? 1) : 1);
        if ($paged < 1) {
            $paged = 1;
        }
        $perpage = absint(is_scalar($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) ? ($tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE) : ABJ404_OPTION_DEFAULT_PERPAGE);
        if ($perpage < 1) {
            $perpage = ABJ404_OPTION_DEFAULT_PERPAGE;
        }
        $start = ($paged - 1) * $perpage;
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogRecords.sql");
        $query = $this->f->str_replace('{logsid_included}', $logsid_included, $query);
        $query = $this->f->str_replace('{logsid}', $logsid, $query);
        $query = $this->f->str_replace('{orderby}', $orderby, $query);
        $query = $this->f->str_replace('{order}', $order, $query);
        $query = $this->f->str_replace('{start}', (string)$start, $query);
        $query = $this->f->str_replace('{perpage}', (string)$perpage, $query);

        $results = $this->queryAndGetResults($query);
        $rawRows = $results['rows'];
        return is_array($rawRows) ? $rawRows : array();
    }

    /**
     * Privacy exporter/eraser support: fetch logsv2 IDs for a given lookup value (usually a username).
     *
     * @param string $lkupValue
     * @param int $page 1-based page
     * @param int $perPage
     * @return int[]
     */
    public function getLogsv2IdsForLookupValue($lkupValue, $page = 1, $perPage = 100) {
        global $wpdb;

        $lkupValue = trim($lkupValue);
        if ($lkupValue === '') {
            return array();
        }

        $page = max(1, absint($page));
        $perPage = max(1, min(500, absint($perPage)));
        $offset = ($page - 1) * $perPage;

        $logsTable = $this->doTableNameReplacements("{wp_abj404_logsv2}");
        $lookupTable = $this->doTableNameReplacements("{wp_abj404_lookup}");

        $sql = "SELECT l.id
            FROM `{$logsTable}` l
            INNER JOIN `{$lookupTable}` u ON l.username = u.id
            WHERE u.lkup_value = %s
            ORDER BY l.id DESC
            LIMIT %d OFFSET %d";

        $prepared = $wpdb->prepare($sql, $lkupValue, $perPage, $offset);
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        $ids = array();
        foreach ((array)$rows as $row) {
            if (isset($row['id'])) {
                $ids[] = absint($row['id']);
            }
        }
        return array_values(array_filter($ids));
    }

    /**
     * Privacy exporter support: fetch logsv2 rows for a given lookup value (usually a username).
     *
     * @param string $lkupValue
     * @param int $page
     * @param int $perPage
     * @return array<int, array<string, mixed>>
     */
    public function getLogsv2RowsForLookupValue($lkupValue, $page = 1, $perPage = 50) {
        global $wpdb;

        $ids = $this->getLogsv2IdsForLookupValue($lkupValue, $page, $perPage);
        if (empty($ids)) {
            return array();
        }

        $logsTable = $this->doTableNameReplacements("{wp_abj404_logsv2}");

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT id, timestamp, user_ip, referrer, requested_url, requested_url_detail, dest_url
            FROM `{$logsTable}`
            WHERE id IN ({$placeholders})
            ORDER BY id DESC";

        // WPDB::prepare historically varies in how it accepts arrays; use varargs for compatibility.
        /** @var wpdb $wpdb */
        $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $ids));
        $preparedQuery = is_string($prepared) ? $prepared : $sql;
        return (array)$wpdb->get_results($preparedQuery, ARRAY_A);
    }

    /**
     * Privacy eraser support: anonymize a set of logsv2 rows by IDs.
     *
     * We preserve non-user-identifying fields so site owners can still debug patterns,
     * while removing IP/username/referrer detail.
     *
     * @param int[] $ids
     * @return bool
     */
    public function anonymizeLogsv2RowsByIds($ids) {
        global $wpdb;

        if (!is_array($ids) || empty($ids)) {
            return true;
        }

        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (empty($ids)) {
            return true;
        }

        $logsTable = $this->doTableNameReplacements("{wp_abj404_logsv2}");
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = "UPDATE `{$logsTable}`
            SET user_ip = %s,
                referrer = NULL,
                requested_url_detail = NULL,
                username = NULL
            WHERE id IN ({$placeholders})";

        $params = array_merge(array('(Anonymized)'), $ids);
        /** @var wpdb $wpdb */
        $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $params));
        $preparedQuery = is_string($prepared) ? $prepared : $sql;
        $result = $wpdb->query($preparedQuery);

        // wpdb::query returns false on error.
        return ($result !== false);
    }
    
    /** 
     * Log that a redirect was done. Insert into the logs table.
     * @param string $requested_url
     * @param string $action
     * @param string $matchReason
     * @param string|null $requestedURLDetail the exact URL that was requested, for cases when a regex URL was matched.
     * @param list<array{step: string, outcome: string, detail: string}>|null $pipelineTrace
     */
    function logRedirectHit(string $requested_url, string $action, string $matchReason, ?string $requestedURLDetail = null, ?array $pipelineTrace = null): void {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $logTableName = $this->doTableNameReplacements("{wp_abj404_logsv2}");

        $now = time();

        // remove non-printable control characters while preserving valid multibyte Unicode
        $requested_url = preg_replace('/[\x00-\x1F\x7F]/u', '', $requested_url) ?? $requested_url;

        // Normalize to relative path before storing (Issue #24)
        $requested_url = $abj404logic->normalizeToRelativePath($requested_url);

        // If the database can't store utf8 URLs then URL-encode before saving (avoid insert errors).
        try {
            static $requestedUrlColumnMeta = null;

            if ($requestedUrlColumnMeta === null && function_exists('get_transient')) {
                $requestedUrlColumnMeta = get_transient('abj404_logs_requested_url_column_meta');
                if ($requestedUrlColumnMeta === false) {
                    $requestedUrlColumnMeta = null;
                }
            }

            // Backward compatibility: if only legacy charset transient exists, keep using it.
            if ($requestedUrlColumnMeta === null && function_exists('get_transient')) {
                $legacyCharset = get_transient('abj404_logs_requested_url_charset');
                if (is_string($legacyCharset) && $legacyCharset !== '') {
                    $requestedUrlColumnMeta = array(
                        'charset_name' => $legacyCharset,
                        'collation_name' => null,
                    );
                }
            }

            $getCharsetQuery = $wpdb->prepare("SELECT character_set_name as charset_name, collation_name as collation_name \n " .
                "FROM information_schema.columns \n " .
                "WHERE lower(table_schema) = lower(%s) \n " .
                "AND lower(table_name) = lower(%s) \n " .
                "AND lower(column_name) = lower(%s) ",
                DB_NAME, $logTableName, 'requested_url');

            if ($requestedUrlColumnMeta === null) {
                $resultArray = $wpdb->get_results($getCharsetQuery, ARRAY_A);
                if (!empty($resultArray)) {
                    $requestedUrlColumnMeta = array(
                        'charset_name' => $resultArray[0]['charset_name'] ?? $resultArray[0]['CHARSET_NAME'] ?? null,
                        'collation_name' => $resultArray[0]['collation_name'] ?? $resultArray[0]['COLLATION_NAME'] ?? null,
                    );
                    if (function_exists('set_transient')) {
                        $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
                        set_transient('abj404_logs_requested_url_column_meta', $requestedUrlColumnMeta, $ttl);
                        // Keep legacy key in sync for older code paths.
                        if (!empty($requestedUrlColumnMeta['charset_name'])) {
                            set_transient('abj404_logs_requested_url_charset', $requestedUrlColumnMeta['charset_name'], $ttl);
                        }
                    }
                }
            }

            $requestedUrlCharset = is_array($requestedUrlColumnMeta) ? ($requestedUrlColumnMeta['charset_name'] ?? null) : null;
            $requestedUrlCollation = is_array($requestedUrlColumnMeta) ? ($requestedUrlColumnMeta['collation_name'] ?? null) : null;

            if (!empty($requestedUrlCharset) && strpos(strtolower($requestedUrlCharset), 'utf8') === false) {
                    $requested_url = $this->f->encodeUrlForLegacyMatch($requested_url);

                    // Avoid spamming logs on every redirect hit.
                    if (function_exists('get_transient') && function_exists('set_transient')) {
                        $warnKey = 'abj404_warned_logs_charset_mismatch';
                        $warnVal = $logTableName . '|' . strtolower($requestedUrlCharset);
                        $already = get_transient($warnKey);
                        if ($already !== $warnVal) {
                            $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
                            set_transient($warnKey, $warnVal, $ttl);
                            $this->logger->warn("Logs table column charset is '{$requestedUrlCharset}' for {$logTableName}. URL-encoding stored requested URLs to avoid charset issues.");
                        }
                    }
            }
        } catch (Exception $e) {
            // not so important.
            $this->logger->debugMessage(__FUNCTION__ . 
                " error. Issue getting character set for table: " . $logTableName . 
                ", column: requested_url. Error message: " . $e->getMessage());                
        }

        // no nonce here because redirects are not user generated.

        $options = $abj404logic->getOptions(true);
        $referer = wp_get_referer();
        if ($referer !== null && $referer !== false) {
            $referer = esc_url_raw($referer);
            // this length matches the maximum length of the data field on the logs table.
        	$referer = substr($referer, 0, 512);
        } else {
            $referer = '';
        }
        $current_user = wp_get_current_user();
        $current_user_name = $current_user->user_login;
        $ipAddressToSave = is_string($_SERVER['REMOTE_ADDR'] ?? '') ? (string)$_SERVER['REMOTE_ADDR'] : '';
        $ipAddressToSave = filter_var($ipAddressToSave, FILTER_VALIDATE_IP) ? 
            esc_sql($ipAddressToSave) : '';
        if (!array_key_exists('log_raw_ips', $options) || $options['log_raw_ips'] != '1') {
        	$ipAddressToSave = $this->f->md5lastOctet($ipAddressToSave);
        }
        if (!empty($ipAddressToSave)) {
            $ipAddressToSave = substr($ipAddressToSave, 0, 512);
        } else {
            $ipAddressToSave = '(Unknown)';
        }
        
        // we have to know what to set for the $minLogID value
        $minLogID = false;
        $comparisonCollation = $this->sanitizeCollationIdentifier(isset($requestedUrlCollation) ? (string)$requestedUrlCollation : '');
        if ($comparisonCollation === '' || stripos($comparisonCollation, 'utf8mb4') === false) {
            $comparisonCollation = $this->getPreferredUtf8mb4Collation();
        }
        $requestedUrlCharsetLower = isset($requestedUrlCharset) ? strtolower((string)$requestedUrlCharset) : '';
        $canUseUtf8Cast = ($requestedUrlCharsetLower === '' || strpos($requestedUrlCharsetLower, 'utf8') !== false);
        if ($canUseUtf8Cast) {
            $checkMinIDQuery = $wpdb->prepare("SELECT id FROM `" . $logTableName . "` \n " .
                "WHERE CAST(requested_url AS CHAR CHARACTER SET utf8mb4) COLLATE " . $comparisonCollation . " = %s \n " .
                "LIMIT 1", $requested_url);
        } else {
            $checkMinIDQuery = $wpdb->prepare("SELECT id FROM `" . $logTableName . "` \n " .
                "WHERE requested_url = %s \n " .
                "LIMIT 1", $requested_url);
        }
        $checkMinIDQueryResults = $wpdb->get_results($checkMinIDQuery, ARRAY_A);
        if (!empty($wpdb->last_error) && $this->isInvalidDataError($wpdb->last_error) && $canUseUtf8Cast) {
            $fallbackResult = $this->queryAndGetResults(
                "SELECT id FROM `" . $logTableName . "` \n WHERE requested_url = %s \n LIMIT 1",
                array('query_params' => array($requested_url), 'log_errors' => false)
            );
            $checkMinIDQueryResults = $fallbackResult['rows'] ?? array();
        }
    
        if (empty($checkMinIDQueryResults)) {
            $minLogID = true;
        }

        // extra escaping suggestions from chatgpt
        // Don't escape "404" as a URL since it's not a URL, it's a status indicator
        if (trim($action) != "404") {
            $action = esc_url_raw($action);
        }
            
        // ------------ debug message begin
        $helperFunctions = ABJ_404_Solution_Functions::getInstance();
        $reasonMessage = trim(implode(", ",
                    array_filter(
                    array($_REQUEST[ABJ404_PP]['ignore_doprocess'] ?? '', $_REQUEST[ABJ404_PP]['ignore_donotprocess'] ?? ''))));
        $permalinksKept = '(not set)';
        if ($this->logger->isDebug() && array_key_exists(ABJ404_PP, $_REQUEST) &&
        		array_key_exists('permalinks_found', $_REQUEST[ABJ404_PP])) {
       		$permalinksKept = $_REQUEST[ABJ404_PP]['permalinks_kept'];
        }
        $this->logger->debugMessage("Logging redirect. Referer: " . esc_html($referer) . 
        		" | Current user: " . $current_user_name . " | From: " . $helperFunctions->normalizeUrlString($_SERVER['REQUEST_URI']) . 
                esc_html(" to: ") . esc_html($action) . ', Reason: ' . $matchReason . ", Ignore msg(s): " . 
                $reasonMessage . ', Execution time: ' . round((float)$helperFunctions->getExecutionTime(), 2) . 
        	' seconds, permalinks found: ' . $permalinksKept);
        // ------------ debug message end
        
        // insert the username into the lookup table and get the ID from the lookup table.
        $usernameLookupID = $this->insertLookupValueAndGetID($current_user_name);

        // Queue the log entry for batch INSERT at shutdown
        $this->queueLogEntry([
            'timestamp' => $now,
            'user_ip' => $ipAddressToSave,
            'referrer' => $referer,
            'dest_url' => $action,
            'requested_url' => esc_url_raw($requested_url),
            'requested_url_detail' => $requestedURLDetail,
            'username' => $usernameLookupID,
            'min_log_id' => $minLogID,
            'engine' => substr($matchReason, 0, 64),
            'pipeline_trace' => $this->serializePipelineTrace($pipelineTrace),
        ]);
    }

    /**
     * Queue a log entry for batch INSERT at shutdown.
     * Registers shutdown hook on first entry.
     *
     * @param array<string, mixed> $entry Log entry data
     */
    function queueLogEntry(array $entry): void {
        self::$logQueue[] = $entry;

        // Register shutdown hook on first entry only
        if (!self::$shutdownHookRegistered) {
            self::$shutdownHookRegistered = true;
            // Slightly earlier than default (10) to reduce chance other shutdown handlers poison the DB connection.
            add_action('shutdown', [$this, 'flushLogQueue'], 9);
        }
    }

    /**
     * Flush queued log entries with a batch INSERT.
     * Called automatically at shutdown.
     */
    function flushLogQueue(): void {
        if (self::$isFlushingLogQueue) {
            return;
        }
        self::$isFlushingLogQueue = true;
        if (empty(self::$logQueue)) {
            // Reset shutdown hook flag for next request (persistent hosting protection)
            self::$shutdownHookRegistered = false;
            self::$isFlushingLogQueue = false;
            return;
        }

        global $wpdb;
        $tableName = $this->doTableNameReplacements('{wp_abj404_logsv2}');

        // Get column names from first entry and validate as safe SQL identifiers
        $columns = array_keys(self::$logQueue[0]);
        $validatedColumns = [];
        foreach ($columns as $col) {
            // Validate column name is a safe SQL identifier (alphanumeric + underscore)
            if (preg_match('/^[a-z_][a-z0-9_]*$/i', $col)) {
                $validatedColumns[] = $col;
            }
        }

        // Schema drift tolerance: filter out columns that don't exist in the actual
        // table. Old installations may lack columns added in newer versions (e.g. 'engine').
        $schemaColumns = $this->getTableColumnNames($tableName);
        if (!empty($schemaColumns)) {
            $validatedColumns = array_intersect($validatedColumns, $schemaColumns);
        }

        if (empty($validatedColumns)) {
            // No valid columns - clear queue and reset flag
            self::$logQueue = [];
            self::$shutdownHookRegistered = false;
            self::$isFlushingLogQueue = false;
            return;
        }

        $columnList = '`' . implode('`, `', $validatedColumns) . '`';

        // Build VALUES for each entry with proper validation
        $valuesSets = [];
        $sanitizedEntries = [];
        foreach (self::$logQueue as $entry) {
            // Detect complex types early (kept for legacy test expectations).
            foreach ($entry as $val) {
                if (is_object($val) || is_array($val)) {
                    // Handled in sanitizeLogEntry (converted to NULL)
                    break;
                }
            }
            // Validate entry has same structure as first entry
            $entryColumns = array_keys($entry);
            $missingCols = array_diff($validatedColumns, $entryColumns);
            if (!empty($missingCols)) {
                // Skip entries with missing columns to prevent data corruption
                continue;
            }

            $sanitized = $this->sanitizeLogEntry($entry);
            if ($sanitized === null) {
                continue;
            }

            $sanitizedEntries[] = $sanitized;
        }

        if (empty($sanitizedEntries)) {
            // No valid entries - clear queue and reset flag
            self::$logQueue = [];
            self::$shutdownHookRegistered = false;
            self::$isFlushingLogQueue = false;
            return;
        }

        // Build placeholder-based batch insert with IGNORE to tolerate duplicates
        $formats = [];
        $flattenedValues = [];
        foreach ($sanitizedEntries as $entry) {
            $rowFormats = [];
            foreach ($validatedColumns as $col) {
                $value = $entry[$col];
                if ($value === null) {
                    $rowFormats[] = 'NULL';
                    continue;
                }
                if (is_int($value)) {
                    $rowFormats[] = '%d';
                } else {
                    $rowFormats[] = '%s';
                }
                $flattenedValues[] = $value;
            }
            $formats[] = '(' . implode(', ', $rowFormats) . ')';
        }

        $sql = "INSERT IGNORE INTO `{$tableName}` ({$columnList}) VALUES " . implode(', ', $formats);
        $prepared = $wpdb->prepare($sql, $flattenedValues);

        // Execute batch INSERT
        $wpdb->flush();
        $result = $wpdb->query($prepared);

        // Check for errors - if batch insert fails, try individual inserts
        if ($result === false && !empty($wpdb->last_error)) {
            $batchError = $wpdb->last_error;

            // Auto-trim oldest log entries when the log table is full (errno 1114 "table is full").
            // Rate-limited to once per hour to avoid thrashing on a genuinely full disk.
            if ($this->isTableFullError($batchError)) {
                $trimmed = $this->autoTrimLogsv2IfNeeded($tableName, $batchError);
                if ($trimmed) {
                    // Retry the INSERT after freeing space.
                    /** @var \wpdb $wpdb */
                    $wpdb->flush();
                    $retryResult = $wpdb->query($prepared);
                    if ($retryResult !== false) {
                        self::$logQueue = [];
                        self::$shutdownHookRegistered = false;
                        self::$isFlushingLogQueue = false;
                        return;
                    }
                    $batchError = $wpdb->last_error;
                }
                // Still failing after trim (or trim rate-limited): surface admin notice.
                $this->setLogsv2FullNotice($batchError);
            }

            // Attempt a one-time recovery for known connection-state issues (e.g., "Commands out of sync").
            if ($this->isCommandsOutOfSyncError($batchError)) {
                $isolated = $this->getIsolatedWpdb();
                if ($isolated !== null) {
                    $isolated->flush();
                    /** @var literal-string $sql */
                    $isolatedPrepared = $isolated->prepare($sql, $flattenedValues);
                    $isolatedResult = $isolated->query($isolatedPrepared !== null ? $isolatedPrepared : $sql);
                    if ($isolatedResult !== false) {
                        // Clear queue and reset flag for next request
                        self::$logQueue = [];
                        self::$shutdownHookRegistered = false;
                        self::$isFlushingLogQueue = false;
                        $context = $this->getWpdbRecentQueryContextForLogs();
                        $suffix = ($context !== '') ? " | savequeries_context={$context}" : '';
                        $this->logger->warn("flushLogQueue batch INSERT succeeded using isolated DB connection (commands out of sync on shared connection).{$suffix}");
                        return;
                    }
                    $batchError .= " | isolated_error=" . $isolated->last_error;
                } else {
                    $batchError .= " | isolated_error=no_isolated_connection";
                }
            }

            // Retry each entry individually to salvage what we can
            $successCount = 0;
            $failCount = 0;
            $failureDetails = [];
            foreach ($sanitizedEntries as $index => $entry) {
                $rowFormats = [];
                $rowValues = [];
                foreach ($validatedColumns as $col) {
                    $value = $entry[$col];
                    if ($value === null) {
                        $rowFormats[] = 'NULL';
                    } else {
                        $rowFormats[] = is_int($value) ? '%d' : '%s';
                        $rowValues[] = $value;
                    }
                }
                $rowPlaceholder = '(' . implode(', ', $rowFormats) . ')';
                /** @var literal-string $singleSqlTemplate */
                $singleSqlTemplate = "INSERT IGNORE INTO `{$tableName}` ({$columnList}) VALUES {$rowPlaceholder}";
                /** @var wpdb $wpdb */
                $singleSql = $wpdb->prepare($singleSqlTemplate, $rowValues);
                $wpdb->flush();
                $singleResult = $wpdb->query((string)$singleSql);

                if ($singleResult === false && !empty($wpdb->last_error)) {
                    $lastError = $wpdb->last_error;

                    // One retry on known connection-state errors.
                    if ($this->isCommandsOutOfSyncError($wpdb->last_error)) {
                        /** @var wpdb|null $isolated */
                        $isolated = $this->getIsolatedWpdb();
                        if ($isolated !== null) {
                            $isolated->flush();
                            /** @var literal-string $singleSqlTemplate */
                            $isolatedSingleSql = $isolated->prepare($singleSqlTemplate, $rowValues);
                            $isolatedSingleResult = $isolated->query((string)$isolatedSingleSql);
                            if ($isolatedSingleResult !== false) {
                                $successCount++;
                                continue;
                            }
                            $lastError = $lastError . " | isolated_error=" . $isolated->last_error;
                        } else {
                            $lastError = $lastError . " | isolated_error=no_isolated_connection";
                        }
                    }

                    $failCount++;
                    $payload = function_exists('wp_json_encode') ? wp_json_encode($entry) : json_encode($entry);
                    if (is_string($payload) && strlen($payload) > 1024) {
                        $payload = substr($payload, 0, 1024) . '...';
                    }
                    $failureDetails[] = [
                        'index' => $index,
                        'error' => $lastError,
                        'payload' => $payload,
                    ];
                } else {
                    $successCount++;
                }
            }

            if ($failCount > 0) {
                $detailsParts = [];
                $maxDetails = 3;
                foreach (array_slice($failureDetails, 0, $maxDetails) as $detail) {
                    $detailsParts[] = "entry {$detail['index']}: {$detail['error']} | payload={$detail['payload']}";
                }
                $detailsSuffix = '';
                if (count($failureDetails) > $maxDetails) {
                    $detailsSuffix = ' | (additional failures omitted)';
                }

                // Use a single ERROR line so email summaries include the actual DB error(s).
                $context = $this->getWpdbRecentQueryContextForLogs();
                $contextSuffix = ($context !== '') ? (" | savequeries_context=" . $context) : '';
                $this->logger->errorMessage(
                    "flushLogQueue recovery incomplete: {$successCount} inserted, {$failCount} failed." .
                    " | batch_error=" . $batchError .
                    " | failures=" . implode(' || ', $detailsParts) . $detailsSuffix .
                    $contextSuffix
                );
            } else {
                // Batch insert failure was recovered; don't escalate as an error.
                $this->logger->warn("flushLogQueue batch INSERT failed but recovered: all {$successCount} entries inserted individually. | batch_error=" . $batchError);
            }
        }

        // Clear queue and reset flag for next request
        self::$logQueue = [];
        self::$shutdownHookRegistered = false;
        self::$isFlushingLogQueue = false;
    }

    private function isCommandsOutOfSyncError(string $error): bool {
        return stripos($error, 'commands out of sync') !== false;
    }

    /** @param string $error @return bool */
    private function isTableFullError(string $error): bool {
        $lower = strtolower($error);
        return stripos($lower, 'is full') !== false || stripos($lower, 'table full') !== false;
    }

    /**
     * Delete the oldest 1000 rows from logsv2 to free space, rate-limited to once per hour.
     *
     * @param string $tableName Fully-resolved logsv2 table name.
     * @param string $errorMessage The error that triggered the call.
     * @return bool True if a trim was attempted (regardless of success), false if rate-limited.
     */
    private function autoTrimLogsv2IfNeeded(string $tableName, string $errorMessage): bool {
        // Defense-in-depth: verify the table name is valid before using in SQL
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || strpos($tableName, 'abj404_logsv2') === false) {
            $this->logger->warn("autoTrimLogsv2IfNeeded: rejected unexpected table name: " . substr($tableName, 0, 100));
            return false;
        }

        $cooldownKey = 'abj404_logsv2_trim_cooldown_until';
        $alreadyTrimmed = function_exists('get_transient') ? get_transient($cooldownKey) : false;
        if ($alreadyTrimmed) {
            return false;
        }

        global $wpdb;
        // ORDER BY ensures we delete oldest first. LIMIT keeps the DELETE bounded.
        $trimSql = "DELETE FROM `{$tableName}` ORDER BY timestamp ASC LIMIT 1000";
        $wpdb->query($trimSql);

        $ttl = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
        if (function_exists('set_transient')) {
            set_transient($cooldownKey, 1, $ttl);
        }

        if (!empty($wpdb->last_error)) {
            $this->logger->warn("Log table full — auto-trim failed: " . $wpdb->last_error);
        } else {
            $this->logger->warn("Log table full — auto-trimmed 1000 oldest entries to free space.");
        }
        return true;
    }

    /** @param string $errorMessage @return void */
    private function setLogsv2FullNotice(string $errorMessage): void {
        $message = function_exists('__')
            ? __('The 404 Solution log table is full and cannot accept new entries. This is usually caused by a full disk. Please contact your host or manually prune the logs table.', '404-solution')
            : 'The 404 Solution log table is full and cannot accept new entries. This is usually caused by a full disk. Please contact your host or manually prune the logs table.';
        if (function_exists('set_transient')) {
            set_transient('abj404_plugin_db_notice', array(
                'type'         => 'log_table_full',
                'message'      => $message,
                'timestamp'    => time(),
                'error_string' => $errorMessage,
            ), 86400);
        }
    }

    /**
     * Create an isolated DB connection (separate from the shared $wpdb connection).
     * This avoids failures caused by other code leaving the shared mysqli connection in a bad state.
     */
    private function getIsolatedWpdb(): ?wpdb {
        static $isolated = null;

        if ($isolated !== null) {
            return $isolated;
        }
        if (!class_exists('wpdb')) {
            return null;
        }
        if (!defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME') || !defined('DB_HOST')) {
            return null;
        }

        // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__wpdb
        $isolated = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $isolated->show_errors(false);
        $isolated->suppress_errors(true);

        return $isolated;
    }

    /**
     * If WordPress query recording is already enabled (SAVEQUERIES), return a safe summary
     * of recent DB callers to help identify the component that poisoned the shared connection.
     *
     * Returns empty string when SAVEQUERIES isn't enabled.
     */
    private function getWpdbRecentQueryContextForLogs(): string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return '';
        }
        if (!defined('SAVEQUERIES') || SAVEQUERIES !== true) {
            return '';
        }
        if (empty($wpdb->queries) || !is_array($wpdb->queries)) {
            return '';
        }

        $recent = array_slice($wpdb->queries, -5);
        $parts = [];
        foreach ($recent as $q) {
            $sql = $q[0] ?? '';
            $time = $q[1] ?? null;
            $caller = $q[2] ?? '';
            $hash = is_string($sql) ? substr(sha1($sql), 0, 10) : 'n/a';
            $who = $this->extractWpComponentFromString(is_string($caller) ? $caller : '');
            $t = is_numeric($time) ? round((float)$time, 3) : 'n/a';
            $parts[] = "{$who}:{$hash}@{$t}";
        }
        return implode(', ', $parts);
    }

    private function extractWpComponentFromString(string $text): string {
        $normalized = str_replace('\\', '/', $text);

        $pos = strpos($normalized, '/wp-content/mu-plugins/');
        if ($pos !== false) {
            $rest = substr($normalized, $pos + strlen('/wp-content/mu-plugins/'));
            $name = explode('/', ltrim($rest, '/'))[0] ?? '';
            return $name !== '' ? "mu-plugin:{$name}" : 'mu-plugin:unknown';
        }

        $pos = strpos($normalized, '/wp-content/plugins/');
        if ($pos !== false) {
            $rest = substr($normalized, $pos + strlen('/wp-content/plugins/'));
            $name = explode('/', ltrim($rest, '/'))[0] ?? '';
            return $name !== '' ? "plugin:{$name}" : 'plugin:unknown';
        }

        $pos = strpos($normalized, '/wp-content/themes/');
        if ($pos !== false) {
            $rest = substr($normalized, $pos + strlen('/wp-content/themes/'));
            $name = explode('/', ltrim($rest, '/'))[0] ?? '';
            return $name !== '' ? "theme:{$name}" : 'theme:unknown';
        }

        // Caller strings are often like "require_once('...')" or "SomeClass->method", so we keep it generic.
        return 'unknown';
    }

    /**
     * Validate and sanitize a log entry before insertion.
     * Returns sanitized array or null if invalid.
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    private function sanitizeLogEntry(array $entry): ?array {
        // Required fields
        $required = array('timestamp', 'user_ip', 'referrer', 'dest_url', 'requested_url', 'requested_url_detail', 'username', 'min_log_id', 'engine');
        foreach ($required as $key) {
            if (!array_key_exists($key, $entry)) {
                return null;
            }
        }

        $normalizeString = function($value, $maxLen) {
            if (is_object($value) || is_array($value)) {
                return null;
            }
            return substr((string)$value, 0, $maxLen);
        };

        $sanitized = array();

        $tsVal = $entry['timestamp'] ?? time();
        $sanitized['timestamp'] = absint(is_scalar($tsVal) ? $tsVal : time());
        $sanitized['user_ip'] = $normalizeString($entry['user_ip'], 512);
        $sanitized['referrer'] = $normalizeString($entry['referrer'], 512);
        $sanitized['dest_url'] = $normalizeString($entry['dest_url'], 512);

        // Enforce lengths on URL fields (match schema)
        $sanitized['requested_url'] = $normalizeString($entry['requested_url'], 2048);
        $sanitized['requested_url_detail'] = $normalizeString($entry['requested_url_detail'], 2048);

        $usernameVal = $entry['username'] ?? null;
        $sanitized['username'] = ($usernameVal === null || !is_scalar($usernameVal))
            ? null : absint($usernameVal);
        $minLogIdVal = $entry['min_log_id'] ?? null;
        $sanitized['min_log_id'] = ($minLogIdVal === null || !is_scalar($minLogIdVal))
            ? null : absint($minLogIdVal);
        $sanitized['engine'] = $normalizeString($entry['engine'], 64);

        // pipeline_trace: pass through as-is (base64-encoded gzip string or null)
        if (array_key_exists('pipeline_trace', $entry)) {
            $traceVal = $entry['pipeline_trace'];
            $sanitized['pipeline_trace'] = ($traceVal === null || is_string($traceVal)) ? $traceVal : null;
        } else {
            $sanitized['pipeline_trace'] = null;
        }

        // Drop rows without required URL data
        if ($sanitized['requested_url'] === '' || $sanitized['dest_url'] === '') {
            return null;
        }

        return $sanitized;
    }

    /**
     * Serialize a pipeline trace array for storage as a BLOB.
     * Returns base64(gzip(json)) so the value is safe ASCII for SQL.
     *
     * @param array<int, array{step: string, outcome: string, detail: string}>|null $trace
     * @return string|null
     */
    private function serializePipelineTrace(?array $trace): ?string {
        if ($trace === null || empty($trace)) {
            return null;
        }
        $json = json_encode($trace);
        if ($json === false) {
            return null;
        }
        $compressed = gzcompress($json, 6);
        if ($compressed === false) {
            return null;
        }
        return base64_encode($compressed);
    }

    /**
     * Decompress and decode a stored pipeline trace blob.
     *
     * @param string|null $raw Base64-encoded gzip-compressed JSON string
     * @return array<int, array{step: string, outcome: string, detail: string}>|null
     */
    public static function decompressPipelineTrace(?string $raw): ?array {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            return null;
        }
        $json = @gzuncompress($decoded);
        if ($json === false) {
            return null;
        }
        $result = json_decode($json, true);
        return is_array($result) ? $result : null;
    }

    /** Insert a value into the lookup table and return the ID of the value.
     * Uses upsert pattern (INSERT ... ON DUPLICATE KEY UPDATE) for atomic operation.
     * @param string $valueToInsert
     * @return int
     */
    function insertLookupValueAndGetID($valueToInsert) {
        global $wpdb;

        // Use upsert pattern: single atomic query that handles both insert and duplicate cases
        // ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) ensures insert_id is set even for existing rows
        $query = "INSERT INTO {wp_abj404_lookup} (lkup_value) VALUES (%s)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";
        $this->queryAndGetResults($query, array(
            'query_params' => array($valueToInsert)
        ));

        return intval($wpdb->insert_id);
    }

    /**
     * Get daily 404/redirect activity for the last N days.
     *
     * Returns array of rows, one per day (including days with zero activity),
     * sorted ascending by date.  Each row has:
     *   'date'          => 'YYYY-MM-DD'
     *   'hits_404'      => int  (rows where dest_url is '' or NULL)
     *   'hits_redirect' => int  (rows where dest_url is non-empty)
     *   'new_captures'  => int  (same as hits_404 for trend purposes)
     *
     * @param int $days Number of days (default 30, clamped to 1-90)
     * @return array<int, array<string, mixed>>
     */
    public function getDailyActivityTrend(int $days = 30): array {
        $days = max(1, min(90, $days));

        $logsTable = $this->doTableNameReplacements('{wp_abj404_logsv2}');
        $cutoff = time() - ($days * 86400);

        $query = "SELECT
                    DATE(FROM_UNIXTIME(`timestamp`)) AS `date`,
                    SUM(CASE WHEN (`dest_url` IS NULL OR `dest_url` = '') THEN 1 ELSE 0 END) AS `hits_404`,
                    SUM(CASE WHEN (`dest_url` IS NOT NULL AND `dest_url` <> '') THEN 1 ELSE 0 END) AS `hits_redirect`
                  FROM " . $logsTable . "
                  WHERE `timestamp` >= " . intval($cutoff) . "
                  GROUP BY DATE(FROM_UNIXTIME(`timestamp`))
                  ORDER BY `date` ASC";

        $result = $this->queryAndGetResults($query);
        $rows = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : array();

        // Build a date-keyed map from query results.
        $byDate = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $d = isset($row['date']) ? (string)$row['date'] : '';
            if ($d === '') {
                continue;
            }
            $byDate[$d] = array(
                'date'          => $d,
                'hits_404'      => intval($row['hits_404'] ?? 0),
                'hits_redirect' => intval($row['hits_redirect'] ?? 0),
                'new_captures'  => intval($row['hits_404'] ?? 0),
            );
        }

        // Fill in days with zero activity so the chart always shows N points.
        $output = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', time() - ($i * 86400));
            if (isset($byDate[$d])) {
                $output[] = $byDate[$d];
            } else {
                $output[] = array(
                    'date'          => $d,
                    'hits_404'      => 0,
                    'hits_redirect' => 0,
                    'new_captures'  => 0,
                );
            }
        }

        return $output;
    }

    /**
     * @param string $userName
     * @return int
     */
    function getLookupIDForUser($userName) {
    	// Use prepared statement to prevent SQL injection
    	$query = "select id from {wp_abj404_lookup} where lkup_value = %s";
    	$results = $this->queryAndGetResults($query, array(
    	    'query_params' => array($userName)
    	));

    	$lookupRows = is_array($results['rows']) ? $results['rows'] : array();
    	if (count($lookupRows) > 0) {
    		// the value already exists so we only need to return the ID.
    		$rows = $lookupRows;
    		$row1 = is_array($rows[0]) ? $rows[0] : array();
    		$id = isset($row1['id']) ? $row1['id'] : 0;
    		return is_scalar($id) ? intval($id) : 0;
    	}
    	return -1;
    }
}
