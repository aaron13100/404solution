<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_MaintenanceTrait {

    /**
     * Auto-recover from a collation mismatch detected at query time.
     *
     * Strategy (matches the project's "try, recover, retry, then notify" pattern,
     * but the "notify" step is intentionally omitted per owner directive — collation
     * issues must NEVER surface to the user):
     *
     *  1. Detect "Illegal mix of collations" / "Unknown collation" in $result['last_error'].
     *  2. Honor a 1-hour cooldown transient (`abj404_collation_recovery_cooldown`) so a
     *     storm of collation errors doesn't run correctCollations() repeatedly.
     *  3. Call ABJ_404_Solution_DatabaseUpgradesEtc::correctCollations() which converges
     *     all plugin tables to a single utf8mb4 collation (column-level + table-level).
     *  4. Set the cooldown transient.
     *  5. Retry the original query once.  If the retry succeeds, harvest the result; if
     *     it still fails, return — the caller's $reportError logic downgrades collation
     *     errors to WARN log entries (no email, no admin notice).
     *
     * Self-recursion is prevented by setting a static guard while correctCollations() runs:
     * the ALTER TABLE statements that correctCollations() emits go back through
     * queryAndGetResults(), and any collation error encountered there must NOT trigger
     * another recovery (it would deadlock on the cooldown).
     *
     * @param string $query
     * @param array<string, mixed> $result passed by reference
     * @param bool   $producesRows Whether the query returns result rows.
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType wpdb output type for get_results().
     * @return void
     */
    private function recoverFromCollationMismatchAndRetry(string $query, array &$result, bool $producesRows, string $resultType): void {
        // Re-entry guard: if correctCollations()'s own ALTER TABLE hits a collation
        // error, do NOT recurse — return and let the original error propagate.
        if (self::$collationRecoveryInProgress) {
            return;
        }

        $cooldownKey = 'abj404_collation_recovery_cooldown';
        $cooldownUntil = $this->getRuntimeFlag($cooldownKey);
        $onCooldown = is_scalar($cooldownUntil) && (int)$cooldownUntil > $this->clock()->now();

        if (!$onCooldown) {
            self::$collationRecoveryInProgress = true;
            try {
                $this->logger->infoMessage("Collation mismatch detected — running correctCollations() to converge plugin tables.");
                if (class_exists('ABJ_404_Solution_DatabaseUpgradesEtc')) {
                    $upgrades = abj_service('database_upgrades');
                    if (method_exists($upgrades, 'correctCollations')) {
                        $upgrades->correctCollations();
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warn("correctCollations() threw during collation auto-recovery: " . $e->getMessage());
            } finally {
                self::$collationRecoveryInProgress = false;
                // Set the 1-hour cooldown regardless of success/failure so we don't
                // hammer correctCollations() on a hot query path.
                $this->setRuntimeFlag($cooldownKey, $this->clock()->now() + 3600, 3600);
            }
        }

        // Retry the original query once, whether or not we ran correctCollations().
        // After a successful run the underlying mismatch should be gone; if cooldown
        // was active the retry is still cheap and may succeed for transient reasons.
        global $wpdb;
        /** @var wpdb $wpdb */
        $wpdb->flush();
        if ($producesRows) {
            $result['rows'] = $wpdb->get_results($query, $resultType);
        } else {
            $wpdb->query($query);
            $result['rows'] = array();
        }
        $this->harvestWpdbResult($result);

        if ($result['last_error'] === '') {
            $this->logger->debugMessage("Collation auto-recovery succeeded; query retry passed.");
        }
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
                        // @cache-write-audit: opt-out — admin-notice dedup cooldown
                        // (one notice per 24h per failure type), not a query result.
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
        abj_service('ngram_filter')->invalidateCoverageCaches();
    }

    /** @param int $post_id @return void */
    function removeFromPermalinkCache(int $post_id): void {
        global $wpdb;

        $query = "delete from {wp_abj404_permalink_cache} where id = %d";
        $this->queryAndGetResults($query, array('query_params' => array($post_id)));

        // Invalidate coverage ratio since permalink count changed
        abj_service('ngram_filter')->invalidateCoverageCaches();
    }

    /** @return array<int, array<string, mixed>>|null */
    function getIDsNeededForPermalinkCache() {
        $abj404logic = abj_service('plugin_logic');

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

    /**
     * Delete duplicate rows in the lookup table.  Called from
     * correctIssuesBefore() during the upgrade flow, which runs *before*
     * runInitialCreateTables() — so on a fresh install (or after the
     * upgrade flow drops a stripped table for clean recreation) the lookup
     * table may not yet exist.  Suppress errors and skip the table-repair
     * retry path: there's nothing to clean up if the table doesn't exist,
     * and we don't want this maintenance call to set the missing_table
     * admin notice transient that will then surface as a `.notice-error`.
     *
     * @return void
     */
    function correctDuplicateLookupValues(): void {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/correctLookupTableIssue.sql");
    	$this->queryAndGetResults($query, array(
    		'log_errors' => false,
    		'skip_repair' => true,
    	));
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

    /**
     * @cache-write-audit: opt-out — spelling_cache is itself the cache;
     * SpellChecker recomputes lookups on demand from {wp_abj404_redirects}
     * and {wp_abj404_permalink_cache}, neither of which derives a transient
     * from spelling_cache rows. A grep for `spelling_cache` against
     * includes/ confirms no transient/option keys depend on it. No
     * dependent caches to invalidate.
     *
     * @return void
     */
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
     * Joins the pre-aggregated wp_abj404_logs_hits rollup (NOT raw logsv2) and
     * filters on the `failed_hits` column — the count of 404-only hits per
     * canonical URL, computed by the rollup builder via
     * SUM(CASE WHEN dest_url='' OR dest_url IS NULL THEN 1 ELSE 0 END). This
     * scales the cron's cost with URL cardinality (thousands), not raw hit
     * cardinality (millions). The previous implementation INNER JOINed
     * wp_abj404_logsv2 on a string column with a timestamp filter — same
     * O(N rows) shape that timed out the digest cron on busy sites
     * (audit finding G1; mirrors commit 9133848d for getHighImpactCapturedCount).
     *
     * Fallback when the rollup is missing or pre-dates the failed_hits column
     * (existing installs upgrading): schedule a rebuild and store an empty
     * list. Never falls back to scanning logsv2.
     *
     * h.requested_url is stored canonical (leading '/', no trailing '/') by the
     * rollup builder; r.final_dest is canonicalized at JOIN time so legacy
     * destinations with or without leading/trailing slashes match the same
     * indexed h.requested_url row. The CONCAT/TRIM is on r.final_dest (outer
     * side of the join), which has thousands of rows — small enough that the
     * per-row expression cost is dominated by the indexed h.requested_url
     * lookup.
     *
     * @return void
     */
    function flagDeadDestinationRedirects(): void {
        $cutoff = time() - 7 * 86400;
        $flaggedIds = array();

        // Skip silently when the rollup is missing or hasn't been rebuilt
        // since the failed_hits column was added: schedule a rebuild and
        // store an empty list. A degraded cron cycle is acceptable; falling
        // back to scanning logsv2 is not.
        if (!$this->logsHitsTableExists() || !$this->logsHitsHasFailedHitsColumn()) {
            $this->scheduleHitsTableRebuild();
            $this->storeDeadDestIdsTransient($flaggedIds);
            return;
        }

        // 30s timeout: the rollup-side JOIN should complete in milliseconds.
        // A blown timeout here signals rollup corruption / lock contention,
        // not "logsv2 is huge" — fast failure is the right behavior.
        $sql = "SELECT DISTINCT r.id
             FROM {wp_abj404_redirects} r
             INNER JOIN {wp_abj404_logs_hits} h
                 ON BINARY h.requested_url = BINARY CONCAT('/', TRIM(BOTH '/' FROM r.final_dest))
             WHERE h.last_used > %d
               AND h.failed_hits > 0
               AND r.disabled = 0
               AND r.final_dest != ''
               AND r.final_dest != '0'";
        $sql = $this->doTableNameReplacements($sql);

        $result = $this->queryAndGetResults($sql, array(
            'query_params' => array($cutoff),
            'timeout' => 30,
        ));

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

        $this->storeDeadDestIdsTransient($flaggedIds);

        if (!empty($flaggedIds)) {
            $this->logger->infoMessage(
                __CLASS__ . '/' . __FUNCTION__ . ': Flagged ' . count($flaggedIds) .
                ' redirect(s) with dead destinations: ' . implode(', ', $flaggedIds)
            );
        }
    }

    /**
     * Persist the dead-destination ID list. Extracted so the rollup-missing
     * fallback path stores the same empty-list shape as the success path —
     * stale data must never poison redirect handling.
     *
     * @param array<int, string> $flaggedIds
     * @return void
     */
    private function storeDeadDestIdsTransient(array $flaggedIds): void {
        if (function_exists('set_transient')) {
            $ttl = defined('HOUR_IN_SECONDS') ? 25 * (int) HOUR_IN_SECONDS : 90000;
            set_transient('abj404_dead_dest_ids', $flaggedIds, $ttl);
        }
    }

    /**
     * Detect whether the live wp_abj404_logs_hits table has the failed_hits
     * column. Existing installs that rebuilt the rollup before the column
     * was added will have the older 4-column schema; the next rollup pass
     * recreates the table with the new column included. Until then, the
     * cron must skip silently rather than emit a query that errors with
     * "Unknown column 'h.failed_hits'".
     *
     * Uses information_schema (single indexed lookup); the SHOW COLUMNS
     * fallback in queryAndGetResults handles hosts that restrict
     * information_schema access.
     *
     * @return bool
     */
    private function logsHitsHasFailedHitsColumn(): bool {
        $tableName = $this->doTableNameReplacements('{wp_abj404_logs_hits}');
        $sql = "SELECT 1 FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() "
            . "AND table_name = %s "
            . "AND column_name = 'failed_hits' LIMIT 1";
        $result = $this->queryAndGetResults($sql, array(
            'query_params' => array($tableName),
            'log_errors' => false,
        ));
        if (!empty($result['last_error'])) {
            return false;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        return !empty($rows);
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
        $options = abj_service('plugin_logic')->getOptions();
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
