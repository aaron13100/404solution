<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_MaintenanceTrait {

    /**
     * Tables that may be safely dropped and recreated after repeated repair failures.
     * Tables NOT in this list (e.g. redirects, engine_profiles, redirect_conditions,
     * ngram_cache) will never be auto-dropped because they contain user-configured
     * data or are expensive to rebuild.
     *
     * New tables default to NOT droppable (safe-by-default).
     *
     * @var array<int, string>
     */
    private static $droppableTables = array(
        'logsv2', 'permalink_cache', 'spelling_cache',
        'lookup', 'logs_hits', 'view_cache',
    );

    /**
     * Check whether a table name matches one of the droppable table suffixes.
     *
     * @param string $tableName Sanitized table name.
     * @return bool
     */
    private function isDroppableTable(string $tableName): bool {
        foreach (self::$droppableTables as $suffix) {
            if (substr($tableName, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate and sanitize a table name extracted from error messages or SQL.
     * Only allows alphanumeric characters and underscores, and requires 'abj404' in the name.
     *
     * @param string $name Raw table name
     * @return string|null Sanitized name, or null if invalid
     */
    private function sanitizeTableName(string $name): ?string {
        // Strip any backticks that may already be present
        $name = trim($name, '`');
        // Only allow safe characters
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            $this->logger->warn("sanitizeTableName: rejected invalid table name: " . substr($name, 0, 100));
            return null;
        }
        // Must be a plugin table
        if (strpos($name, 'abj404') === false) {
            $this->logger->warn("sanitizeTableName: rejected non-plugin table name: " . $name);
            return null;
        }
        return $name;
    }

    /** @param string $errorMessage @return void */
    function repairTable(string $errorMessage): void {

        // Match "Table '...' is marked as crashed" (errno 1194/1195).
        $re1 = "Table '(.*\/)?(.+)' is marked as crashed and ";
        // Match "Incorrect key file for table '...'" (errno 1034).
        // The table name may include a path prefix (./db/name) and a .MYI suffix.
        $re2 = "Incorrect key file for table '(?:.*\/)?([^'.]+?)(?:\\.MYI)?'";

        $matches = array();
        $this->f->regexMatch($re1, $errorMessage, $matches);

        if (empty($matches) || count($matches) <= 2 || $this->f->strlen($matches[2]) === 0) {
            // Try the errno 1034 pattern. Use a single capture group (no path prefix group)
            // so $matches[1] is the bare table name.
            $this->f->regexMatch($re2, $errorMessage, $matches);
            // Shift result to match[2] position expected by the code below.
            if (!empty($matches) && isset($matches[1]) && $this->f->strlen($matches[1]) > 0) {
                $matches[2] = $matches[1];
            }
        }

        if (!empty($matches) && count($matches) > 2 && $this->f->strlen($matches[2]) > 0) {
            $rawTableName = $matches[2];
            $tableToRepair = $this->sanitizeTableName($rawTableName);
            if ($tableToRepair !== null) {
                $query = "REPAIR TABLE `{$tableToRepair}`";
                $result = $this->queryAndGetResults($query, array('log_errors' => false));
                $this->logger->infoMessage("Attempted to repair table " . $tableToRepair . ". Result: " .
                        json_encode($result));

                // Track repair attempts only for tables that are safe to drop+recreate.
                // Tables not in the droppable whitelist (e.g. redirects, engine_profiles,
                // redirect_conditions, ngram_cache) are never auto-dropped because they
                // contain user data or are expensive to rebuild.
                if ($this->isDroppableTable($tableToRepair)) {
	                $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
	                $options = $abj404logic->getOptions();

	                // Migrate from old global scalar to per-table counts
	                if (isset($options['repaired_count']) && is_scalar($options['repaired_count'])) {
	                	unset($options['repaired_count']);
	                }
	                if (!isset($options['repaired_counts']) || !is_array($options['repaired_counts'])) {
	                	$options['repaired_counts'] = array();
	                }

	                $tableKey = $tableToRepair;
	                $prevCount = isset($options['repaired_counts'][$tableKey]) ? intval($options['repaired_counts'][$tableKey]) : 0;
	                $options['repaired_counts'][$tableKey] = $prevCount + 1;
	                $abj404logic->updateOptions($options);

	                if ($prevCount + 1 > 3 && $prevCount + 1 < 7) {
	                	// Before dropping, check if the last error was disk-full.
	                	// Dropping + recreating on a full disk will just fail again.
	                	$lowerError = strtolower($errorMessage);
	                	if (strpos($lowerError, 'is full') !== false ||
	                		strpos($lowerError, 'no space left') !== false ||
	                		strpos($lowerError, 'table full') !== false) {
	                		$this->logger->warn("Skipping drop+recreate for " . $tableToRepair .
	                			" — disk appears full. Repair count: " . ($prevCount + 1));
	                	} else {
	                		$upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
	                		$this->queryAndGetResults("DROP TABLE `{$tableToRepair}`");
	                		$upgradesEtc->createDatabaseTables(false);
	                	}
	                }
                }

            } else {
                // Non-plugin table or invalid name: the plugin cannot repair it,
                // but we can notify the admin once per day so they can contact their host.
                $this->logger->warn("The table " . $rawTableName . " needs to be " .
                    "repaired with something like: repair table " . $rawTableName);

                $cooldownKey = 'abj404_corrupted_temp_table_notice_until';
                $alreadyNotified = function_exists('get_transient') ? get_transient($cooldownKey) : false;
                if (!$alreadyNotified) {
                    $noticeMessage = $this->localizeOrDefault(
                        'A database temporary table is corrupted — this is usually caused by a full or failing disk. Please contact your host. (MySQL error 1034)');
                    $this->setPluginDbNotice('corrupted_temp_table', $noticeMessage, $errorMessage);
                    if (function_exists('set_transient')) {
                        set_transient($cooldownKey, 1, 86400);
                    }
                }
            }
        }
    }

    /** @param string $errorMessage @param string $sqlThatWasRun @return void */
    function repairDuplicateIDs(string $errorMessage, string $sqlThatWasRun): void {

    	$reForID = 'resulting in duplicate entry \'(.+)\' for key';
    	$reForTableName = "ALTER TABLE (.+) ADD ";
    	$matchesForID = null;
    	$matchesForTableName = null;

    	$this->f->regexMatch($reForID, $errorMessage, $matchesForID);
    	$this->f->regexMatch($reForTableName, $sqlThatWasRun, $matchesForTableName);
    	if (is_array($matchesForID) && isset($matchesForID[1]) && $this->f->strlen($matchesForID[1]) > 0 &&
    			is_array($matchesForTableName) && isset($matchesForTableName[1]) && $this->f->strlen($matchesForTableName[1]) > 0) {

    		$idWithDuplicate = $matchesForID[1];
    		$tableName = $this->sanitizeTableName($matchesForTableName[1]);
    		if ($tableName === null) {
    			$this->logger->warn("repairDuplicateIDs: rejected invalid table name from SQL: " . substr($matchesForTableName[1], 0, 100));
    			return;
    		}

    		// Validate that ID is numeric to prevent SQL injection
    		if (!is_numeric($idWithDuplicate)) {
    			$this->logger->errorMessage("Invalid ID extracted from error message: " . $idWithDuplicate);
    			return;
    		}

    		if ($idWithDuplicate == 1) {
    			$idWithDuplicate = 0;
    		}

    		// Use prepared statement to prevent SQL injection
    		$result = $this->queryAndGetResults("DELETE FROM `{$tableName}` where id = %d",
    			array('log_errors' => false, 'query_params' => array(absint($idWithDuplicate))));
   			$this->logger->infoMessage("Attempted to fix a duplicate entry issue. Table: " .
   				$tableName . ", Result: " . json_encode($result));
    	}
    }

    /**
     * @param array<int, string> $statementArray
     * @return void
     */
    function executeAsTransaction(array $statementArray): void {
        global $wpdb;
        $maxAttempts = 3;
        $lastException = null;
        $lastError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $allIsWell = true;
            $lastError = '';
            $lastException = null;
            try {
                $wpdb->query('START TRANSACTION');
                foreach ($statementArray as $statement) {
                    $wpdb->query($statement);
                    if ($wpdb->last_error != null && trim((string)$wpdb->last_error) !== '') {
                        $allIsWell = false;
                        $lastError = (string)$wpdb->last_error;
                        if (!$this->classifyAndHandleInfrastructureError($lastError)) {
                            $this->logger->errorMessage("Error executing SQL transaction: " . $lastError);
                            $this->logger->errorMessage("SQL causing the transaction error: " . $statement);
                        }
                        break;
                    }
                }
            } catch (Throwable $ex) {  // Fixed: Catch Throwable (Exception + Error) for PHP 7+ compatibility
                $allIsWell = false;
                $lastException = $ex;
                $lastError = $ex->getMessage();
            }

            if ($allIsWell && $lastException == null) {
                $wpdb->query('commit');
                return;
            }

            $wpdb->query('rollback');
            $retryable = $this->isDeadlockOrLockTimeoutError($lastError);
            if (!$retryable || $attempt >= $maxAttempts) {
                break;
            }
            // Small jitter prevents immediate lock re-collision.
            $sleepMicros = 100000 + random_int(0, 200000);
            usleep($sleepMicros);
        }

        if ($lastException != null) {
            throw $lastException;
        }
        if ($lastError !== '') {
            throw new Exception($lastError);
        }
    }

    /**
     * @param int|string $post_id
     * @return string|null
     */
    function getOldSlug($post_id) {
    	// Sanitize post_id to prevent SQL injection
    	$post_id = absint($post_id);

    	// we order by meta_id desc so that the first row will have the most recent value.
    	$query = "select meta_value from {wp_postmeta} \nwhere post_id = {post_id} " .
    		" and meta_key = '_wp_old_slug' \n" .
    		" order by meta_id desc";
    	$query = $this->f->str_replace('{post_id}', (string)$post_id, $query);

    	$results = $this->queryAndGetResults($query);

    	$rows = $results['rows'];
    	if ($rows == null || empty($rows)) {
    		return null;
    	}

    	$rows = is_array($rows) ? $rows : array();
    	$row = is_array($rows[0] ?? null) ? $rows[0] : array();
    	return isset($row['meta_value']) && is_string($row['meta_value']) ? $row['meta_value'] : null;
    }

    /** @return void */
    function truncatePermalinkCacheTable(): void {
        global $wpdb;

        $query = "truncate table {wp_abj404_permalink_cache}";
        $this->queryAndGetResults($query);

        // Invalidate coverage ratio since permalink count changed
        ABJ_404_Solution_NGramFilter::getInstance()->invalidateCoverageCaches();
    }

    /** @param int $post_id @return void */
    function removeFromPermalinkCache(int $post_id): void {
        global $wpdb;

        $query = "delete from {wp_abj404_permalink_cache} where id = %d";
        $this->queryAndGetResults($query, array('query_params' => array($post_id)));

        // Invalidate coverage ratio since permalink count changed
        ABJ_404_Solution_NGramFilter::getInstance()->invalidateCoverageCaches();
    }

    /** @return array<int, array<string, mixed>>|null */
    function getIDsNeededForPermalinkCache() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        // get the valid post types
        $options = $abj404logic->getOptions();
        $recognizedPostTypes = $this->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return null;
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getIDsNeededForPermalinkCache.sql");
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);

        $results = $this->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>>|null $rows */
        $rows = $results['rows'];
        return $rows;
    }

    /**
     * @param int|string $id
     * @return string|null
     */
    function getPermalinkFromCache($id) {
        // Sanitize id to prevent SQL injection
        $id = absint($id);
        $query = "select url from {wp_abj404_permalink_cache} where id = " . $id;
        $results = $this->queryAndGetResults($query);

        $rows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($rows)) {
            return null;
        }

        $row1 = is_array($rows[0] ?? null) ? $rows[0] : array();
        return isset($row1['url']) && is_string($row1['url']) ? $row1['url'] : null;
    }

    /**
     * Batch-fetch permalinks for multiple IDs from the permalink cache.
     *
     * @param array<int, int> $ids
     * @return array<int, object> Rows with id and url columns
     */
    function getPermalinksByIds(array $ids) {
        if (empty($ids)) {
            return array();
        }
        $sanitized = array_map('absint', $ids);
        $placeholders = implode(',', $sanitized);
        $query = "select id, url from {wp_abj404_permalink_cache} where id in (" . $placeholders . ")";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        return is_array($results['rows']) ? $results['rows'] : array();
    }

    /**
     * @param int|string $id
     * @return array<string, mixed>|null
     */
    function getPermalinkEtcFromCache($id) {
        // Sanitize id to prevent SQL injection
        $id = absint($id);
        $query = "select id, url, meta, url_length, post_parent from {wp_abj404_permalink_cache} where id = " . $id;
        $results = $this->queryAndGetResults($query);

        $rows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($rows)) {
            return null;
        }

        return is_array($rows[0] ?? null) ? $rows[0] : null;
    }

    /** @return void */
    function correctDuplicateLookupValues(): void {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/correctLookupTableIssue.sql");
    	$this->queryAndGetResults($query);
    }

    /**
     * @param string $requestedURLRaw
     * @param mixed $returnValue
     * @return void
     */
    function storeSpellingPermalinksToCache(string $requestedURLRaw, $returnValue): void {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/insertSpellingCache.sql");

        // Sanitize invalid UTF-8 sequences before storing to database
        // This prevents "Could not perform query because it contains invalid data" errors
        // when URLs contain invalid UTF-8 byte sequences (e.g., %c1%1c from scanner probes)
        $cleanURL = $this->f->sanitizeInvalidUTF8($requestedURLRaw);

        $query = $this->f->str_replace('{url}', esc_sql($cleanURL), $query);
        $jsonEncoded = json_encode($returnValue);
        $query = $this->f->str_replace('{matchdata}', esc_sql(is_string($jsonEncoded) ? $jsonEncoded : ''), $query);

        $this->queryAndGetResults($query);
    }

    /** @return void */
    function deleteSpellingCache(): void {
        $query = "truncate table {wp_abj404_spelling_cache}";

        $this->queryAndGetResults($query);
    }

    /**
     * Find redirects whose destination URL appears in the 404 log as a recent 404.
     * Only internal URL destinations can be detected this way (external 404s are not
     * logged by this plugin). Stores flagged redirect IDs in a transient for fast
     * lookup at redirect-processing time.
     *
     * @return void
     */
    function flagDeadDestinationRedirects(): void {
        $cutoff         = time() - 7 * 86400;
        $redirectsTable = $this->doTableNameReplacements('{wp_abj404_redirects}');
        $logsTable      = $this->doTableNameReplacements('{wp_abj404_logsv2}');

        // Route through queryAndGetResults() so this redirects-vs-logsv2 JOIN
        // inherits the centralized 60-second timeout. The JOIN runs in a cron
        // context, but a bad index/lock contention can still hang long enough
        // to back up the cron runner. Always store an empty flag list on
        // failure so stale data does not poison redirect handling.
        $sql = "SELECT DISTINCT r.id
             FROM `{$redirectsTable}` r
             INNER JOIN `{$logsTable}` l ON l.requested_url = r.final_dest
             WHERE l.timestamp > %d
               AND (l.dest_url = '' OR l.dest_url IS NULL)
               AND r.disabled = 0
               AND r.final_dest != ''
               AND r.final_dest != '0'";

        $result = $this->queryAndGetResults($sql, array('query_params' => array($cutoff)));

        $flaggedIds = array();
        if (empty($result['timed_out']) && (!isset($result['last_error']) || $result['last_error'] == '')) {
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $value = $row['id'] ?? reset($row);
                } elseif (is_object($row)) {
                    $value = $row->id ?? null;
                } else {
                    $value = $row;
                }
                if ($value !== null && $value !== '') {
                    $flaggedIds[] = (string)$value;
                }
            }
        }

        if (function_exists('set_transient')) {
            $ttl = defined('HOUR_IN_SECONDS') ? 25 * (int) HOUR_IN_SECONDS : 90000;
            set_transient('abj404_dead_dest_ids', $flaggedIds, $ttl);
        }

        if (!empty($flaggedIds)) {
            $this->logger->infoMessage(
                __CLASS__ . '/' . __FUNCTION__ . ': Flagged ' . count($flaggedIds) .
                ' redirect(s) with dead destinations: ' . implode(', ', $flaggedIds)
            );
        }
    }

    /**
     * Move auto-created redirects to trash if they are older than the configured expiration.
     *
     * Uses the `timestamp` column (creation time) of the redirects table. Only affects
     * redirects with status = ABJ404_STATUS_AUTO that are not already disabled. The
     * threshold is controlled by the `auto_302_expiration_days` option (0 = disabled).
     *
     * @return int Number of redirects moved to trash
     */
    public function expireOldAutoRedirects(): int {
        $options = ABJ_404_Solution_PluginLogic::getInstance()->getOptions();
        $daysRaw = isset($options['auto_302_expiration_days']) ? $options['auto_302_expiration_days'] : 0;
        $days = is_numeric($daysRaw) ? (int)$daysRaw : 0;
        if ($days <= 0) {
            return 0;
        }

        $redirectsTable = $this->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->tableExists($redirectsTable)) {
            $this->logger->warn("expireOldAutoRedirects: redirects table missing, skipping.");
            return 0;
        }

        $cutoff = time() - ($days * 86400);

        // Route through queryAndGetResults() so this cron query inherits the
        // centralized timeout, retry, and corrupted-table recovery. The query
        // is small (redirects table only) but the table can grow on busy
        // sites and a long-held write lock could otherwise hang the cron.
        $sql = "SELECT id FROM `{$redirectsTable}`
             WHERE status = %d
               AND disabled = 0
               AND `timestamp` > 0
               AND `timestamp` < %d";

        $result = $this->queryAndGetResults($sql, array(
            'query_params' => array(ABJ404_STATUS_AUTO, $cutoff),
        ));

        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            // queryAndGetResults() already logged the error/timeout; treat as no-op.
            return 0;
        }

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $value = $row['id'] ?? reset($row);
            } elseif (is_object($row)) {
                $value = $row->id ?? null;
            } else {
                $value = $row;
            }
            if ($value !== null && $value !== '') {
                $ids[] = absint($value);
            }
        }

        if (empty($ids)) {
            return 0;
        }

        $moved = 0;
        foreach ($ids as $id) {
            $this->moveRedirectsToTrash($id, 1);
            $moved++;
        }

        $this->logger->infoMessage("expireOldAutoRedirects: moved {$moved} expired auto-redirect(s) to trash (threshold: {$days} days).");
        return $moved;
    }

    /**
     * @param string $requestedURLRaw
     * @return mixed
     */
    function getSpellingPermalinksFromCache(string $requestedURLRaw) {
        // Sanitize invalid UTF-8 before SQL to prevent database errors
        $requestedURLRaw = $this->f->sanitizeInvalidUTF8($requestedURLRaw);
        $query = "select id, url, matchdata from {wp_abj404_spelling_cache} where url = '" . esc_sql($requestedURLRaw) . "'";
        $results = $this->queryAndGetResults($query);

        $rows = is_array($results['rows']) ? $results['rows'] : array();

        if (empty($rows)) {
            return array();
        }

        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        $json = isset($row['matchdata']) && is_string($row['matchdata']) ? $row['matchdata'] : '';
        $returnValue = json_decode($json, true);

        return $returnValue;
    }

}
