<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_LogsTrait {

    /**
     * Populate logshits / logsid / last_used on each row from the pre-aggregated
     * wp_abj404_logs_hits rollup. Used by the captured/redirects table fallback
     * path when the main getRedirectsForView JOIN cannot include the rollup
     * (logs_hits race, sort by url/status/timestamp with queryAllRowsAtOnce=false).
     *
     * Reads only from logs_hits — never scans wp_abj404_logsv2. The previous
     * implementation aggregated logsv2 with GROUP BY in 50-URL chunks; on busy
     * sites with hot URLs (100K+ hits/URL) that meant 100K rows scanned per
     * chunk, routinely hitting the centralized 60s timeout. logs_hits is
     * O(distinct URLs), so the same lookup runs in milliseconds.
     *
     * Fallback: if logs_hits is missing or the lookup errors, schedule a
     * shutdown-time rebuild (mirrors getHighImpactCapturedCount, commit
     * 9133848d) and return rows with their original null/zero hit fields.
     * Never falls back to scanning logsv2 — the whole point is to never run
     * that query again.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    function populateLogsData($rows) {
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

        if (empty($urls)) {
            return $rows;
        }

        $urls = array_values(array_unique($urls));

        // If the rollup is not available, defer to a shutdown-time rebuild
        // rather than scan raw logsv2. Caller sees rows with null hits — same
        // contract as the main getRedirectsForView path when logs_hits is missing.
        if (!$this->logsHitsTableExists()) {
            $this->scheduleHitsTableRebuild();
            return $rows;
        }

        // Chunk lookups so an absurdly large URL set doesn't build a multi-MB
        // IN() clause. logs_hits is small (O(distinct URLs)) so a generous batch
        // is fine — we cap at 200 to stay well within MySQL packet limits.
        $logsHitsTable = $this->doTableNameReplacements('{wp_abj404_logs_hits}');
        $batchSize = 200;
        $logsResults = array();
        $urlChunks = array_chunk($urls, $batchSize);

        foreach ($urlChunks as $urlChunk) {
            $placeholders = implode(',', array_fill(0, count($urlChunk), '%s'));
            // BINARY column equality matches getRedirectsForViewQuery() so case
            // and encoding edge cases behave identically across the main path
            // and this fallback. logs_hits stores rows by their exact logged
            // URL — variants like '/foo' and 'foo' may both exist as separate
            // rows; the PHP-side aggregation below merges them by canonical URL.
            $sql = "SELECT requested_url, logsid, last_used, logshits "
                 . "FROM {$logsHitsTable} "
                 . "WHERE BINARY requested_url IN ($placeholders)";

            $chunkResult = $this->queryAndGetResults($sql, array(
                'query_params' => $urlChunk,
                'log_too_slow' => false,
            ));

            // logs_hits can be dropped between the existence check above and
            // this query (rebuild race). Treat any failure as "rollup
            // unavailable": schedule a rebuild and return rows untouched.
            // Never fall back to scanning logsv2.
            if (!empty($chunkResult['timed_out']) ||
                (isset($chunkResult['last_error']) && $chunkResult['last_error'] != '')) {
                $errRaw = $chunkResult['last_error'] ?? '';
                $err = is_string($errRaw) ? $errRaw : '';
                if ($err !== '' && strpos($err, 'logs_hits') !== false) {
                    $this->scheduleHitsTableRebuild();
                }
                return $rows;
            }

            $chunkResults = is_array($chunkResult['rows'] ?? null) ? $chunkResult['rows'] : array();
            if (!empty($chunkResults)) {
                $logsResults = array_merge($logsResults, $chunkResults);
            }
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
     * Return up to 500 distinct requested URLs from the most recent log activity.
     *
     * Uses a reverse index scan on the timestamp index to fetch the 5 000 most
     * recent rows, then deduplicates.  Much faster than GROUP BY on large tables
     * because it avoids a full table scan and aggregate computation.
     *
     * @return array<int, string>
     */
    function getDistinctLoggedUrls(): array {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getDistinctLoggedUrls.sql");

        $results = $this->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : array();

        $urls = array();
        foreach ($rows as $row) {
            $url = isset($row['requested_url']) && is_string($row['requested_url']) ? $row['requested_url'] : '';
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * @param string $specificURL
     * @return array<int, array<string, mixed>>
     */
    function getLogsIDandURL($specificURL = '') {
        global $wpdb;
    	$whereClause = '';
        if ($specificURL != '') {
            // Strip invalid UTF-8 first — esc_sql does not validate UTF-8 and
            // bot-fed URLs deliver garbage bytes (Pattern 10).
            $specificURL = $this->f->sanitizeInvalidUTF8($specificURL);
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
    	$abj404logic = abj_service('plugin_logic');

    	$logsid_included = '';
        $logsid = '';
        $rawLogsId = $tableOptions['logsid'];
        if ($rawLogsId != 0) {
            $logsid_included = 'specific logs id included. */';
            $logsid = esc_sql($abj404logic->sanitizeForSQL(is_scalar($rawLogsId) ? (string)$rawLogsId : ''));
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

        // Route through queryAndGetResults() so this GDPR exporter join
        // inherits the centralized 60s timeout. The exporter runs in admin
        // request context (paginated by core), and an unbounded JOIN against
        // logsv2 could exceed reverse-proxy timeouts on large sites.
        $result = $this->queryAndGetResults($sql, array(
            'query_params' => array($lkupValue, $perPage, $offset),
        ));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array();
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();

        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
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

        // Route through queryAndGetResults() so this exporter detail query
        // inherits the centralized 60s timeout. The IN(...) is bounded by
        // the page size from getLogsv2IdsForLookupValue, but a slow disk or
        // lock contention can still hang the request.
        $result = $this->queryAndGetResults($sql, array('query_params' => $ids));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return array();
        }
        return is_array($result['rows'] ?? null) ? $result['rows'] : array();
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
        // Route through queryAndGetResults() so this GDPR eraser UPDATE
        // inherits the centralized timeout (MariaDB SET STATEMENT for non-
        // SELECT queries) and the standard retry/recovery handling.
        $result = $this->queryAndGetResults($sql, array('query_params' => $params));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return false;
        }
        return true;
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
        $abj404logic = abj_service('plugin_logic');
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
                // @cache-write-audit: opt-out — guarded by `!empty($resultArray)` below.
                // $wpdb->get_results returns null on error, and !empty(null) is false,
                // so a failed query never reaches the cache writes inside this block.
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
                            // @cache-write-audit: opt-out — log-spam dedup marker, not a
                            // query result. The cached value is the table+charset signature
                            // we have already warned about; re-warning is harmless if the
                            // transient is wrong.
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
        // Route through queryAndGetResults() so this per-404-hit lookup
        // inherits the centralized 60s timeout. The CAST(... AS CHAR) form
        // can bypass the requested_url index on some schemas, turning into a
        // full table scan on huge logsv2 tables — a hot-path slow query
        // would block the 404 page render itself.
        if ($canUseUtf8Cast) {
            $checkMinIDSql = "SELECT id FROM `" . $logTableName . "` \n " .
                "WHERE CAST(requested_url AS CHAR CHARACTER SET utf8mb4) COLLATE " . $comparisonCollation . " = %s \n " .
                "LIMIT 1";
        } else {
            $checkMinIDSql = "SELECT id FROM `" . $logTableName . "` \n " .
                "WHERE requested_url = %s \n " .
                "LIMIT 1";
        }
        $primaryResult = $this->queryAndGetResults(
            $checkMinIDSql,
            array('query_params' => array($requested_url), 'log_errors' => false)
        );
        $checkMinIDQueryResults = is_array($primaryResult['rows'] ?? null) ? $primaryResult['rows'] : array();
        $lastErrorRaw = $primaryResult['last_error'] ?? '';
        $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        if ($lastError !== '' && $this->isInvalidDataError($lastError) && $canUseUtf8Cast) {
            $fallbackResult = $this->queryAndGetResults(
                "SELECT id FROM `" . $logTableName . "` \n WHERE requested_url = %s \n LIMIT 1",
                array('query_params' => array($requested_url), 'log_errors' => false)
            );
            $checkMinIDQueryResults = is_array($fallbackResult['rows'] ?? null) ? $fallbackResult['rows'] : array();
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
        $helperFunctions = abj_service('functions');
        $reasonMessage = trim(implode(", ",
                    array_filter(
                    array(abj_service('request_context')->ignore_doprocess ?: '',
                          abj_service('request_context')->ignore_donotprocess ?: ''))));
        $permalinksKept = '(not set)';
        $ctx = abj_service('request_context');
        if ($this->logger->isDebug() && !empty($ctx->permalinks_found)) {
       		$permalinksKept = $ctx->permalinks_kept;
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
                // Pattern 7 (defense-in-depth): the bespoke recovery above
                // handles "table is full" + "commands out of sync" only. If
                // $batchError is a different infra cause (disk full, read-only,
                // crashed table, lock timeout, ...) classify it so the user
                // gets a plugin-page admin notice rather than a dev email
                // alone. classifyAndHandleInfrastructureError logs at WARN
                // and returns true for matched infra causes; in that case we
                // skip the errorMessage to avoid double-reporting (and to
                // honor rule 8: hosting issues never trigger email reports).
                if ($this->classifyAndHandleInfrastructureError($batchError)) {
                    $this->logger->warn(
                        "flushLogQueue recovery incomplete: {$successCount} inserted, {$failCount} failed." .
                        " | batch_error=" . $batchError .
                        " | failures=" . implode(' || ', $detailsParts) . $detailsSuffix .
                        $contextSuffix
                    );
                } else {
                    $this->logger->errorMessage(
                        "flushLogQueue recovery incomplete: {$successCount} inserted, {$failCount} failed." .
                        " | batch_error=" . $batchError .
                        " | failures=" . implode(' || ', $detailsParts) . $detailsSuffix .
                        $contextSuffix
                    );
                }
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
            // @cache-write-audit: opt-out — rate-limit cooldown timestamp, not a
            // query result. The cached value (1) is a sentinel meaning "auto-trim
            // attempted within the last hour"; we want it written even if the
            // DELETE failed so we do not retry immediately and pile on the disk.
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
        $message = $this->localizeOrDefault(
            'The 404 Solution log table is full and cannot accept new entries. This is usually caused by a full disk. Please contact your host or manually prune the logs table.');
        $this->setPluginDbNotice('log_table_full', $message, $errorMessage);
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
            // Per-request warn once: silently returning null here is the
            // exact pattern the error-swallow audit flagged as Smell 1 —
            // tests that don't set the constants exercise this fallback
            // branch and never the real one.
            static $warnedNoDbConsts = false;
            if (!$warnedNoDbConsts) {
                $warnedNoDbConsts = true;
                $this->logger->warn(__METHOD__ . ': DB_USER/DB_PASSWORD/DB_NAME/DB_HOST undefined; isolated wpdb unavailable');
            }
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
            $str = (string)$value;
            // Strip invalid UTF-8 sequences that cause "invalid data" SQL errors.
            if (function_exists('mb_convert_encoding')) {
                $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
            }
            return substr($str, 0, $maxLen);
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
     *   'hits_404'      => int  (rows where dest_url equals the 404 sentinel)
     *   'hits_redirect' => int  (rows where dest_url is not the 404 sentinel)
     *   'new_captures'  => int  (same as hits_404 for trend purposes)
     *
     * Result is cached in a transient keyed on (blog_id, days, max_log_id)
     * with TTL TREND_DATA_CACHE_TTL_SECONDS. New log inserts increase
     * max_log_id which moves the cache key, so fresh data appears
     * automatically as soon as a new request arrives.
     *
     * @param int $days Number of days (default 30, clamped to 1-90)
     * @return array<int, array<string, mixed>>
     */
    public function getDailyActivityTrend(int $days = 30): array {
        $days = max(1, min(90, $days));

        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = function_exists('absint')
                ? absint(get_current_blog_id())
                : abs(intval(get_current_blog_id()));
            if ($blogId <= 0) {
                $blogId = 1;
            }
        }

        $maxLogId = 0;
        try {
            $maxLogId = intval($this->getMaxLogId());
            if ($maxLogId < 0) {
                $maxLogId = 0;
            }
        } catch (Throwable $unused) {
            $maxLogId = 0;
        }

        $cacheKey = 'abj404_trend_v1_' . $blogId . '_' . $days . '_' . $maxLogId;
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $logsTable = $this->doTableNameReplacements('{wp_abj404_logsv2}');
        $cutoff = time() - ($days * 86400);

        $notFoundDest = '404';
        $query = "SELECT
                    DATE(FROM_UNIXTIME(`timestamp`)) AS `date`,
                    SUM(CASE WHEN `dest_url` = %s THEN 1 ELSE 0 END) AS `hits_404`,
                    SUM(CASE WHEN `dest_url` <> %s THEN 1 ELSE 0 END) AS `hits_redirect`
                  FROM " . $logsTable . "
                  WHERE `timestamp` >= " . intval($cutoff) . "
                  GROUP BY DATE(FROM_UNIXTIME(`timestamp`))
                  ORDER BY `date` ASC";

        $result = $this->queryAndGetResults($query, array(
            'query_params' => array($notFoundDest, $notFoundDest),
        ));
        $hadError = !empty($result['timed_out'])
            || (isset($result['last_error']) && $result['last_error'] !== '');
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

        // Only cache the result on success. A transient DB error/timeout
        // would otherwise pin a zero-filled chart for TREND_DATA_CACHE_TTL_SECONDS
        // (15 min) so the admin sees "no activity" until the cache expires —
        // misleading and harder to diagnose than letting the next request retry.
        if (!$hadError && function_exists('set_transient')) {
            set_transient($cacheKey, $output, self::TREND_DATA_CACHE_TTL_SECONDS);
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
