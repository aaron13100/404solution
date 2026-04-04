<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_StatsTrait {

    /**
     * @param string $query
     * @param array<int|string, mixed> $valueParams
     * @return int
     */
    function getStatsCount($query, array $valueParams) {
        global $wpdb;

        if ($query == '') {
            return 0;
        }

        $results = $wpdb->get_col($wpdb->prepare($query, $valueParams));

        if (sizeof($results) == 0) {
            throw new Exception("No results for query: " . esc_html($query));
        }
        
        return intval($results[0]);
    }

    /**
     * Get periodic log statistics in one query for a given time threshold.
     *
     * This replaces multiple per-metric count queries on the stats page and
     * significantly reduces page-load query overhead.
     *
     * @param int $sinceTimestamp Include rows with timestamp >= this value.
     * @param string $notFoundDest Destination value used for "404" events.
     * @return array{
     *   disp404:int,
     *   distinct404:int,
     *   visitors404:int,
     *   refer404:int,
     *   redirected:int,
     *   distinctredirected:int,
     *   distinctvisitors:int,
     *   distinctrefer:int
     * }
     */
    function getPeriodicStatsSummary($sinceTimestamp, $notFoundDest = '404') {
        global $wpdb;

        $sinceTimestamp = absint($sinceTimestamp);
        $notFoundDest = sanitize_text_field((string)$notFoundDest);
        if ($notFoundDest === '') {
            $notFoundDest = '404';
        }

        $zero = array(
            'disp404' => 0,
            'distinct404' => 0,
            'visitors404' => 0,
            'refer404' => 0,
            'redirected' => 0,
            'distinctredirected' => 0,
            'distinctvisitors' => 0,
            'distinctrefer' => 0,
        );

        $logsTable = $this->doTableNameReplacements('{wp_abj404_logsv2}');
        $sql = "SELECT
                COUNT(CASE WHEN dest_url = %s THEN 1 END) AS disp404,
                COUNT(DISTINCT CASE WHEN dest_url = %s THEN requested_url END) AS distinct404,
                COUNT(DISTINCT CASE WHEN dest_url = %s THEN user_ip END) AS visitors404,
                COUNT(DISTINCT CASE WHEN dest_url = %s THEN referrer END) AS refer404,
                COUNT(CASE WHEN dest_url <> %s THEN 1 END) AS redirected,
                COUNT(DISTINCT CASE WHEN dest_url <> %s THEN requested_url END) AS distinctredirected,
                COUNT(DISTINCT CASE WHEN dest_url <> %s THEN user_ip END) AS distinctvisitors,
                COUNT(DISTINCT CASE WHEN dest_url <> %s THEN referrer END) AS distinctrefer
            FROM {$logsTable}
            WHERE timestamp >= %d";

        $prepared = $wpdb->prepare(
            $sql,
            $notFoundDest,
            $notFoundDest,
            $notFoundDest,
            $notFoundDest,
            $notFoundDest,
            $notFoundDest,
            $notFoundDest,
            $notFoundDest,
            $sinceTimestamp
        );

        $row = $wpdb->get_row($prepared, ARRAY_A);
        if (!is_array($row)) {
            return $zero;
        }

        foreach ($zero as $key => $unused) {
            $zero[$key] = isset($row[$key]) ? intval($row[$key]) : 0;
        }

        return $zero;
    }

    /**
     * Return periodic stats for today/month/year/all with short-lived cache.
     *
     * This avoids repeatedly running expensive DISTINCT aggregates each time the
     * stats tab is opened while still keeping data reasonably fresh.
     *
     * @param string $notFoundDest Destination value used for "404" events.
     * @return array{
     *   today:array<string,int>,
     *   month:array<string,int>,
     *   year:array<string,int>,
     *   all:array<string,int>
     * }
     */
    function getPeriodicStatsSummariesCached($notFoundDest = '404') {
        $today = mktime(0, 0, 0, abs(intval(date('m'))), abs(intval(date('d'))), abs(intval(date('Y'))));
        $firstm = mktime(0, 0, 0, abs(intval(date('m'))), 1, abs(intval(date('Y'))));
        $firsty = mktime(0, 0, 0, 1, 1, abs(intval(date('Y'))));

        $thresholds = array(
            'today' => intval($today),
            'month' => intval($firstm),
            'year' => intval($firsty),
            'all' => 0,
        );

        $zero = array(
            'disp404' => 0,
            'distinct404' => 0,
            'visitors404' => 0,
            'refer404' => 0,
            'redirected' => 0,
            'distinctredirected' => 0,
            'distinctvisitors' => 0,
            'distinctrefer' => 0,
        );
        $emptyPayload = array(
            'today' => $zero,
            'month' => $zero,
            'year' => $zero,
            'all' => $zero,
        );

        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = absint(get_current_blog_id());
            if ($blogId <= 0) {
                $blogId = 1;
            }
        }

        $cacheKey = 'abj404_stats_periodic_v1_' . $blogId . '_' . md5(
            $notFoundDest . '|' . $thresholds['today'] . '|' . $thresholds['month'] . '|' . $thresholds['year']
        );
        $cached = null;
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
        }

        $isCachedValid = (is_array($cached) && isset($cached['periods']) && is_array($cached['periods']));
        $currentMaxLogId = -1;
        try {
            $currentMaxLogId = intval($this->getMaxLogId());
        } catch (Throwable $unused) {
            $currentMaxLogId = -1;
        }

        if ($isCachedValid) {
            $refreshedAt = intval($cached['refreshed_at'] ?? 0);
            $ageSeconds = max(0, time() - $refreshedAt);
            $cachedMaxLogId = intval($cached['max_log_id'] ?? -1);
            if ($currentMaxLogId >= 0 && $cachedMaxLogId === $currentMaxLogId) {
                /** @var array{today: array<string, int>, month: array<string, int>, year: array<string, int>, all: array<string, int>} */
                $merged = array_merge($emptyPayload, $cached['periods']);
                return $merged;
            }
            if ($ageSeconds < self::PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS) {
                /** @var array{today: array<string, int>, month: array<string, int>, year: array<string, int>, all: array<string, int>} */
                $merged = array_merge($emptyPayload, $cached['periods']);
                return $merged;
            }
        }

        $lockKey = 'stats-periodic:' . $cacheKey;
        $lockAcquired = $this->acquireViewSnapshotRefreshLock($lockKey);
        if (!$lockAcquired && $isCachedValid) {
            /** @var array{today: array<string, int>, month: array<string, int>, year: array<string, int>, all: array<string, int>} */
            $merged = array_merge($emptyPayload, $cached['periods']);
            return $merged;
        }

        try {
            $periods = array();
            foreach ($thresholds as $key => $ts) {
                $periods[$key] = $this->getPeriodicStatsSummary($ts, $notFoundDest);
            }
            /** @var array{today: array<string, int>, month: array<string, int>, year: array<string, int>, all: array<string, int>} */
            $result = array_merge($emptyPayload, $periods);

            if (function_exists('set_transient')) {
                set_transient(
                    $cacheKey,
                    array(
                        'refreshed_at' => time(),
                        'max_log_id' => $currentMaxLogId,
                        'periods' => $result,
                    ),
                    self::PERIODIC_STATS_CACHE_TTL_SECONDS
                );
            }

            return $result;
        } finally {
            if ($lockAcquired) {
                $this->releaseViewSnapshotRefreshLock($lockKey);
            }
        }
    }

    /**
     * Return a cached snapshot used by the Stats dashboard.
     *
     * For user experience, we intentionally prefer stale data over blocking
     * the request. Fresh recomputation is done by a background AJAX refresh.
     *
     * @param bool $allowStale If true, return any cached snapshot immediately.
     * @return array{refreshed_at:int,hash:string,data:array<string, mixed>}
     */
    function getStatsDashboardSnapshot($allowStale = true) {
        $cached = $this->getStatsDashboardSnapshotFromCache();
        if (is_array($cached) && !empty($cached['data']) && $allowStale) {
            /** @var array{refreshed_at: int, hash: string, data: array<string, mixed>} $cached */
            return $cached;
        }

        if ($allowStale) {
            $emptyData = $this->buildEmptyStatsDashboardSnapshotData();
            $emptyPayload = array(
                'refreshed_at' => 0,
                'hash' => $this->hashStatsDashboardSnapshot($emptyData),
                'data' => $emptyData,
            );
            if (function_exists('set_transient')) {
                set_transient($this->getStatsDashboardSnapshotCacheKey(), $emptyPayload, self::STATS_DASHBOARD_CACHE_TTL_SECONDS);
            }
            return $emptyPayload;
        }

        return $this->refreshStatsDashboardSnapshot(false);
    }

    /**
     * Recompute and store the stats dashboard snapshot.
     *
     * @param bool $force If true, bypass refresh cooldown checks.
     * @return array{refreshed_at:int,hash:string,data:array<string, mixed>}
     */
    function refreshStatsDashboardSnapshot($force = false) {
        $cached = $this->getStatsDashboardSnapshotFromCache();
        $hasCachedData = (is_array($cached) && !empty($cached['data']));
        $cachedAge = $hasCachedData ? max(0, time() - (is_scalar($cached['refreshed_at'] ?? 0) ? intval($cached['refreshed_at'] ?? 0) : 0)) : PHP_INT_MAX;

        if (!$force && $hasCachedData && $cachedAge < self::STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS) {
            /** @var array{refreshed_at: int, hash: string, data: array<string, mixed>} $cached */
            return $cached;
        }

        $lockKey = 'stats-dashboard:' . $this->getStatsDashboardSnapshotCacheKey();
        $lockAcquired = $this->acquireViewSnapshotRefreshLock($lockKey);
        if (!$lockAcquired && $hasCachedData) {
            /** @var array{refreshed_at: int, hash: string, data: array<string, mixed>} $cached */
            return $cached;
        }

        try {
            $data = $this->buildStatsDashboardSnapshotData();
            $payload = array(
                'refreshed_at' => time(),
                'hash' => $this->hashStatsDashboardSnapshot($data),
                'data' => $data,
            );
            if (function_exists('set_transient')) {
                set_transient($this->getStatsDashboardSnapshotCacheKey(), $payload, self::STATS_DASHBOARD_CACHE_TTL_SECONDS);
            }
            return $payload;
        } catch (Throwable $e) {
            if ($hasCachedData) {
                $this->logger->debugMessage(__FUNCTION__ . ' failed to recompute stats snapshot; returning cached snapshot. Error: ' . $e->getMessage());
                /** @var array{refreshed_at: int, hash: string, data: array<string, mixed>} $cached */
                return $cached;
            }
            throw $e;
        } finally {
            if ($lockAcquired) {
                $this->releaseViewSnapshotRefreshLock($lockKey);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function getStatsDashboardSnapshotFromCache() {
        if (!function_exists('get_transient')) {
            return null;
        }
        $cached = get_transient($this->getStatsDashboardSnapshotCacheKey());
        if (!is_array($cached)) {
            return null;
        }
        if (!array_key_exists('data', $cached) || !is_array($cached['data'])) {
            return null;
        }
        $cached['refreshed_at'] = intval($cached['refreshed_at'] ?? 0);
        $cached['hash'] = is_string($cached['hash'] ?? null) ? $cached['hash'] : '';
        return $cached;
    }

    /** @return string */
    private function getStatsDashboardSnapshotCacheKey(): string {
        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = absint(get_current_blog_id());
            if ($blogId <= 0) {
                $blogId = 1;
            }
        }
        return 'abj404_stats_dashboard_snapshot_v1_' . $blogId;
    }

    /**
     * @param array<string, mixed> $data
     * @return string
     */
    private function hashStatsDashboardSnapshot($data) {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        if (!is_string($encoded)) {
            $encoded = '';
        }
        return md5($encoded);
    }

    /** @return array<string, mixed> */
    private function buildStatsDashboardSnapshotData() {
        $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");

        $auto301 = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and code = 301 and status = %d",
            array(ABJ404_STATUS_AUTO)
        );
        $auto302 = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and code = 302 and status = %d",
            array(ABJ404_STATUS_AUTO)
        );
        $manual301 = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and code = 301 and status = %d",
            array(ABJ404_STATUS_MANUAL)
        );
        $manual302 = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and code = 302 and status = %d",
            array(ABJ404_STATUS_MANUAL)
        );
        $trashedRedirects = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 1 and (status = %d or status = %d)",
            array(ABJ404_STATUS_AUTO, ABJ404_STATUS_MANUAL)
        );

        $captured = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and status = %d",
            array(ABJ404_STATUS_CAPTURED)
        );
        $ignored = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 0 and status in (%d, %d)",
            array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER)
        );
        $trashedCaptured = $this->getStatsCount(
            "select count(id) from $redirectsTable where disabled = 1 and (status in (%d, %d, %d) )",
            array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER)
        );

        $thresholds = array(
            'today' => (int)mktime(0, 0, 0, abs(intval(date('m'))), abs(intval(date('d'))), abs(intval(date('Y')))),
            'month' => (int)mktime(0, 0, 0, abs(intval(date('m'))), 1, abs(intval(date('Y')))),
            'year' => (int)mktime(0, 0, 0, 1, 1, abs(intval(date('Y')))),
            'all' => 0,
        );
        $periods = array();
        foreach ($thresholds as $periodKey => $ts) {
            $periods[$periodKey] = $this->getPeriodicStatsSummary($ts, '404');
        }

        return array(
            'redirects' => array(
                'auto301' => intval($auto301),
                'auto302' => intval($auto302),
                'manual301' => intval($manual301),
                'manual302' => intval($manual302),
                'trashed' => intval($trashedRedirects),
            ),
            'captured' => array(
                'captured' => intval($captured),
                'ignored' => intval($ignored),
                'trashed' => intval($trashedCaptured),
            ),
            'periods' => $periods,
        );
    }

    /** @return array<string, mixed> */
    private function buildEmptyStatsDashboardSnapshotData() {
        $period = array(
            'disp404' => 0,
            'distinct404' => 0,
            'visitors404' => 0,
            'refer404' => 0,
            'redirected' => 0,
            'distinctredirected' => 0,
            'distinctvisitors' => 0,
            'distinctrefer' => 0,
        );
        return array(
            'redirects' => array(
                'auto301' => 0,
                'auto302' => 0,
                'manual301' => 0,
                'manual302' => 0,
                'trashed' => 0,
            ),
            'captured' => array(
                'captured' => 0,
                'ignored' => 0,
                'trashed' => 0,
            ),
            'periods' => array(
                'today' => $period,
                'month' => $period,
                'year' => $period,
                'all' => $period,
            ),
        );
    }

    /** 
     * @global type $wpdb
     * @return int
     * @throws Exception
     */
    function getEarliestLogTimestamp() {
        global $wpdb;

        $query = 'SELECT min(timestamp) as timestamp FROM {wp_abj404_logsv2}';
        $query = $this->doTableNameReplacements($query);
        $results = $wpdb->get_col($query);

        if (sizeof($results) == 0) {
            return -1;
        }
        
        return intval($results[0]);
    }
    
    /** Look at $_POST and $_GET for the specified option and return the default value if it's not set.
     * @param string $name The key to retrieve the value for.
     * @param string $defaultValue The value to return if the value is not set.
     * @return string The sanitized value.
     */
    function getPostOrGetSanitize($name, $defaultValue = null) {
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        // Back-compat: some UI flows submit actions under 'abj404action' instead of 'action'.
        // Treat it as an alias so handlers that look for 'action' still run.
        if ($returnValue === null && $name === 'action') {
            $returnValue = isset($_GET['abj404action']) ? $_GET['abj404action'] : (isset($_POST['abj404action']) ? $_POST['abj404action'] : null);
        }
        if ($returnValue !== null) {
            if (is_array($returnValue)) {
                $returnValue = array_map('sanitize_text_field', $returnValue);
            } else {
                $returnValue = sanitize_text_field($returnValue);
            }
        }
        $finalValue = $returnValue ?? $defaultValue;
        return is_string($finalValue) ? $finalValue : (is_string($defaultValue) ? $defaultValue : '');
    }

    /** Look at $_POST and $_GET for the specified URL option and return the default value if it's not set.
     * URL inputs should not use sanitize_text_field because it strips percent-encoded octets.
     * @param string $name The key to retrieve the value for.
     * @param string|null $defaultValue The value to return if the value is not set.
     * @return string|array<string>|null The normalized URL value.
     */
    function getPostOrGetSanitizeUrl($name, $defaultValue = null) {
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        if ($returnValue === null) {
            return $defaultValue;
        }

        $f = ABJ_404_Solution_Functions::getInstance();
        $unslash = function($value) {
            return function_exists('wp_unslash') ? wp_unslash($value) : $value;
        };

        if (is_array($returnValue)) {
            return array_map(function($value) use ($f, $unslash) {
                $value = $unslash($value);
                return $f->normalizeUrlString($value);
            }, $returnValue);
        }

        $returnValue = $unslash($returnValue);
        return $f->normalizeUrlString($returnValue);
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    function getRedirectsByIDs($ids) {
        global $wpdb;
        if (!is_array($ids) || empty($ids)) {
            return array();
        }
        $validids = array_map('absint', $ids);
        $multipleIds = implode(',', $validids);
    
        $query = "select id, url, type, status, final_dest, code, COALESCE(engine, '') as engine, start_ts, end_ts from {wp_abj404_redirects} " .
                "where id in (" . $multipleIds . ")";
        $query = $this->doTableNameReplacements($query);
        $rows = $wpdb->get_results($query, ARRAY_A);
        
        return $rows;
    }
    
    /** Change the status to "trash" or "ignored," for example.
     * @global type $wpdb
     * @param int $id
     * @param string $newstatus
     * @return string
     */
    function updateRedirectTypeStatus($id, $newstatus) {
        // Use prepared statement to prevent SQL injection
        $query = "update {wp_abj404_redirects} set status = %s where id = %d";
        $result = $this->queryAndGetResults($query, array(
            'query_params' => array($newstatus, absint($id))
        ));

        // Invalidate caches - status change might affect regex redirects
        $this->invalidateStatusCountsCache();
        $this->clearRegexRedirectsCache();

        return is_string($result['last_error']) ? $result['last_error'] : '';
    }

    /** Move a redirect to the "trash" folder.
     * @global type $wpdb
     * @param int $id
     * @param int $trash 1 for trash, 0 for not trash.
     * @return string
     */
    function moveRedirectsToTrash($id, $trash) {
        global $wpdb;

        $message = "";
        $result = false;
        if ($this->f->regexMatch('[0-9]+', '' . $id)) {

            $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");
            $result = $wpdb->update($redirectsTable,
                    array('disabled' => esc_html((string)$trash)), array('id' => absint($id)), array('%d'), array('%d')
            );

            // Invalidate caches - disabled change affects regex redirects
            $this->invalidateStatusCountsCache();
            $this->clearRegexRedirectsCache();
        }
        if ($result === false) {
            $message = __('Error: Unknown Database Error!', '404-solution');
        }
        return $message;
    }

    /** @return array<string, mixed> */
    function updatePermalinkCache() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
    		"/sql/updatePermalinkCache.sql");

    	$this->setSqlBigSelects();

    	$results = $this->queryAndGetResults($query);

    	return $results;
    }
    
    /** @return array<string, mixed> */
    function updatePermalinkCacheParentPages() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
    		"/sql/updatePermalinkCacheParentPages.sql");
    	
    	// depthSoFar makes sure we don't have an infinite loop somehow.
    	$depthSoFar = 0;
    	$results = array();
    	do {
    		$results = $this->queryAndGetResults($query);
    		$depthSoFar++;
    	} while ($results['rows_affected'] != 0 && $depthSoFar < 15);
    	
    	return $results;
    }

    /** @return int */
    function getPermalinkCacheCount(): int {
        $table = $this->doTableNameReplacements('{wp_abj404_permalink_cache}');
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @global type $wpdb
     * @global type $abj404logging
     * @param int $type ABJ404_EXTERNAL, ABJ404_POST, ABJ404_CAT, or ABJ404_TAG.
     * @param string $dest
     * @param string $fromURL
     * @param int $idForUpdate
     * @param string $redirectCode
     * @param string $statusType ABJ404_STATUS_MANUAL or ABJ404_STATUS_REGEX
     * @param int|null $startTs Unix timestamp when redirect becomes active (null = always)
     * @param int|null $endTs   Unix timestamp when redirect expires (null = never)
     * @return string
     */
    function updateRedirect($type, $dest, $fromURL, $idForUpdate, $redirectCode, $statusType, $startTs = null, $endTs = null) {
        global $wpdb;

        if (($type < 0) || ($idForUpdate <= 0)) {
            $this->logger->errorMessage("Bad data passed for update redirect request. Type: " .
                esc_html((string)$type) . ", Dest: " . esc_html($dest) . ", ID(s): " . esc_html((string)$idForUpdate));
            echo __('Error: Bad data passed for update redirect request.', '404-solution');
            return '';
        }

        $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");

        $updateData = array(
            'url' => $fromURL,
            'status' => $statusType,
            'type' => absint($type),
            'final_dest' => $dest,
            'code' => esc_attr($redirectCode),
        );
        $updateFormats = array('%s', '%d', '%d', '%s', '%d');

        // Include non-null timestamps in the main update.
        if ($startTs !== null) {
            $updateData['start_ts'] = (int)$startTs;
            $updateFormats[] = '%d';
        }
        if ($endTs !== null) {
            $updateData['end_ts'] = (int)$endTs;
            $updateFormats[] = '%d';
        }

        $wpdb->update(
            $redirectsTable,
            $updateData,
            array('id' => absint($idForUpdate)),
            $updateFormats,
            array('%d')
        );

        // Explicitly set timestamp columns to NULL when no schedule is set.
        // $wpdb->update() with %d format converts null to 0 via (int)null,
        // which breaks the SQL filter "end_ts IS NULL OR end_ts > UNIX_TIMESTAMP()"
        // — end_ts=0 means "expired in 1970" and silently stops the redirect from matching.
        $nullParts = [];
        if ($startTs === null) {
            $nullParts[] = '`start_ts` = NULL';
        }
        if ($endTs === null) {
            $nullParts[] = '`end_ts` = NULL';
        }
        if (!empty($nullParts)) {
            $nullSql = "UPDATE " . $redirectsTable . " SET " . implode(', ', $nullParts) .
                " WHERE id = " . absint($idForUpdate);
            $wpdb->query($nullSql);
        }

        // Invalidate caches - status/url change affects regex redirects
        $this->invalidateStatusCountsCache();
        $this->clearRegexRedirectsCache();

        // move this redirect out of the trash.
        $this->moveRedirectsToTrash(absint($idForUpdate), 0);

        return '';
    }

    /**
     * Get the top N captured 404s by hit count for the digest email.
     *
     * @param int $limit Maximum number of rows to return.
     * @return array<int, array<string, mixed>>
     */
    function getTopCapturedForDigest(int $limit): array {
        global $wpdb;

        $limit = max(1, $limit);
        $redirectsTable = $this->doTableNameReplacements('{wp_abj404_redirects}');
        $logsTable = $this->doTableNameReplacements('{wp_abj404_logsv2}');

        $sql = $wpdb->prepare(
            "SELECT r.url, COUNT(l.id) AS logshits, r.timestamp AS created
             FROM {$redirectsTable} r
             LEFT JOIN {$logsTable} l
               ON CONCAT('/', TRIM(BOTH '/' FROM l.requested_url)) =
                  CONCAT('/', TRIM(BOTH '/' FROM r.url))
             WHERE r.status = %d AND r.disabled = 0
             GROUP BY r.id, r.url, r.timestamp
             ORDER BY logshits DESC
             LIMIT %d",
            ABJ404_STATUS_CAPTURED,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        return $rows;
    }

    /**
     * Get summary stats for the digest email.
     *
     * @return array{total_captured: int, total_manual: int, total_auto: int}
     */
    function getDigestSummaryStats(): array {
        $zero = array(
            'total_captured' => 0,
            'total_manual' => 0,
            'total_auto' => 0,
        );

        $redirectsTable = $this->doTableNameReplacements('{wp_abj404_redirects}');

        try {
            $total_captured = $this->getStatsCount(
                "SELECT COUNT(id) FROM {$redirectsTable} WHERE status = %d AND disabled = 0",
                array(ABJ404_STATUS_CAPTURED)
            );
            $total_manual = $this->getStatsCount(
                "SELECT COUNT(id) FROM {$redirectsTable} WHERE status = %d AND disabled = 0",
                array(ABJ404_STATUS_MANUAL)
            );
            $total_auto = $this->getStatsCount(
                "SELECT COUNT(id) FROM {$redirectsTable} WHERE status = %d AND disabled = 0",
                array(ABJ404_STATUS_AUTO)
            );
        } catch (Throwable $e) {
            return $zero;
        }

        return array(
            'total_captured' => intval($total_captured),
            'total_manual' => intval($total_manual),
            'total_auto' => intval($total_auto),
        );
    }

    /** @return int */
    function getCapturedCountForNotification(): int {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        return $abj404dao->getRecordCount(array(ABJ404_STATUS_CAPTURED));
    }

    /**
     * Get posts whose permalink cache rows have NULL content_keywords.
     *
     * @param int $limit Maximum rows to return.
     * @return array<int, object> Each object has ->id and ->post_content.
     */
    function getPostsNeedingContentKeywords(int $limit = 500): array {
        global $wpdb;

        $limitResults = " */\n  limit " . absint($limit);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPostsNeedingContentKeywords.sql");
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{limit-results}', $limitResults, $query);

        $rows = $wpdb->get_results($query);

        if ($wpdb->last_error) {
            // "Unknown column" means content_keywords hasn't been added yet (DB migration pending,
            // e.g. sync lock was stuck for ~24h). Degrade to warning; the caller returns empty.
            if (stripos($wpdb->last_error, 'unknown column') !== false) {
                $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $wpdb->last_error);
            } else if (!$this->classifyAndHandleInfrastructureError($wpdb->last_error)) {
                $this->logger->errorMessage("Error fetching posts for content keywords: " . $wpdb->last_error);
            }
            return array();
        }

        return is_array($rows) ? $rows : array();
    }

    /**
     * Store extracted content keywords for a permalink cache entry.
     *
     * @param int    $id       The post ID (permalink cache primary key).
     * @param string $keywords Space-separated lowercase keywords.
     * @return void
     */
    function updateContentKeywordsForId(int $id, string $keywords): void {
        global $wpdb;

        $table = $this->doTableNameReplacements('{wp_abj404_permalink_cache}');
        $wpdb->update(
            $table,
            array('content_keywords' => $keywords),
            array('id' => $id),
            array('%s'),
            array('%d')
        );

        if ($wpdb->last_error && !$this->classifyAndHandleInfrastructureError($wpdb->last_error)) {
            $this->logger->errorMessage("Error updating content_keywords for id $id: " . $wpdb->last_error);
        }
    }

}
