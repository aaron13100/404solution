<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_RedirectsTrait {

    /**
     * @param int|string $id
     * @return void
     */
    function deleteRedirect($id) {
        global $wpdb;
        $cleanedID = absint(sanitize_text_field((string)$id));

        // no nonce here because this action is not always user generated.

        if (is_numeric($id)) {
            $query = "delete from {wp_abj404_redirects} where id = %d";
            $this->queryAndGetResults($query, array('query_params' => array($cleanedID)));

            // Invalidate caches
            $this->invalidateStatusCountsCache();
            $this->clearRegexRedirectsCache();
        }
    }

    /**
     * Remove auto-created redirects whose destination post no longer exists or is not published.
     *
     * @return int Number of orphaned redirects deleted.
     */
    public function cleanupOrphanedAutoRedirects(): int {
        // Guard: skip if redirects table doesn't exist (prevents recurring cron errors)
        $redirectsTable = $this->doTableNameReplacements('{wp_abj404_redirects}');
        if (!$this->tableExists($redirectsTable)) {
            $this->logger->warn("Skipping orphaned redirect cleanup: table missing.");
            return 0;
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getOrphanedAutoRedirects.sql");
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);

        $results = $this->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : [];
        $deletedCount = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '0';
            $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
            $this->logger->debugMessage('Orphaned auto redirect deleted: "' . $url . '" (dest post ' .
                (isset($row['final_dest']) && is_scalar($row['final_dest']) ? (string)$row['final_dest'] : '?') . ' missing/unpublished).');
            $this->deleteRedirect($id);
            $deletedCount++;
        }

        return $deletedCount;
    }

    /** Helper method to delete old redirects of a specific type.
     * Extracted common logic from deleteOldRedirectsCron() to eliminate duplication.
     *
     * @param array<string, mixed> $options Plugin options
     * @param int $now Current timestamp
     * @param string $optionKey Option key for deletion threshold ('capture_deletion', 'auto_deletion', 'manual_deletion')
     * @param string $statusList Comma-separated list of status codes to delete
     * @param string $debugMessageType Type description for debug logging ('Captured 404', 'Automatic redirect', 'Manual redirect')
     * @return int Count of deleted redirects
     */
    private function deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $deletedCount = 0;

        // Calculate time threshold
        $rawDays = $options[$optionKey] ?? 0;
        $deletionDays = intval(is_scalar($rawDays) ? $rawDays : 0);
        if ($deletionDays <= 0) {
            return 0;
        }
        $deletionTime = $deletionDays * 86400;
        $then = $now - $deletionTime;

        // Load and prepare SQL query
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getMostUnusedRedirects.sql");
        $query = $this->f->str_replace('{status_list}', $statusList, $query);
        $query = $this->f->str_replace('{timelimit}', (string)$then, $query);

        $this->setSqlBigSelects();

        // Execute query and get results
        $results = $this->queryAndGetResults($query);
        $rows = is_array($results['rows']) ? $results['rows'] : array();

        // Delete each redirect and log
        foreach ($rows as $rowRaw) {
            if (!is_array($rowRaw)) {
                continue;
            }
            $row = $rowRaw;
            // Build debug message based on redirect type
            if ($debugMessageType === 'Captured 404') {
                $this->logger->debugMessage("Captured 404 for \"" . (is_string($row['from_url'] ?? '') ? $row['from_url'] : '') .
                    '" deleted (last used: ' . (is_string($row['last_used_formatted'] ?? '') ? $row['last_used_formatted'] : '') . ').');
            } else {
                // Auto and Manual redirects show from/to URLs
                $this->logger->debugMessage($debugMessageType . " from: " . (is_string($row['from_url'] ?? '') ? $row['from_url'] : '') . ' to: ' .
                    (is_string($row['best_guess_dest'] ?? '') ? $row['best_guess_dest'] : '') . ' deleted (last used: ' . (is_string($row['last_used_formatted'] ?? '') ? $row['last_used_formatted'] : '') . ').');
            }

            $abj404dao->deleteRedirect(isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '0');
            $deletedCount++;
        }

        return $deletedCount;
    }

    /**
     * Delete old rows from the logs/protocol table based on age.
     *
     * Uses batch deletes to avoid a long-running single table lock.
     *
     * @param int $daysToKeep
     * @param int $now
     * @return int
     */
    private function deleteOldLogsByAge(int $daysToKeep, int $now): int {
        if ($daysToKeep <= 0) {
            return 0;
        }

        $cutoffTimestamp = max(0, $now - ($daysToKeep * 86400));
        $deletedTotal = 0;
        $batchSize = 2000;
        $maxBatches = 200;

        for ($i = 0; $i < $maxBatches; $i++) {
            $result = $this->queryAndGetResults(
                "DELETE FROM {wp_abj404_logsv2} WHERE timestamp <= %d LIMIT %d",
                array(
                    'query_params' => array($cutoffTimestamp, $batchSize),
                    'log_errors' => true,
                )
            );
            $rowsDeletedRaw = $result['rows_affected'] ?? 0;
            $rowsDeleted = (is_int($rowsDeletedRaw) || is_float($rowsDeletedRaw) || is_string($rowsDeletedRaw))
                ? (int)$rowsDeletedRaw
                : 0;
            if ($rowsDeleted <= 0) {
                break;
            }
            $deletedTotal += $rowsDeleted;
            if ($rowsDeleted < $batchSize) {
                break;
            }
        }

        return $deletedTotal;
    }

    /** Delete old redirects based on how old they are. This runs daily.
     * @return string
     */
    function deleteOldRedirectsCron() {
        global $wpdb;
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        
        $options = $abj404logic->getOptions();
        $now = time();
        $capturedURLsCount = 0;
        $autoRedirectsCount = 0;
        $manualRedirectsCount = 0;
        $oldLogRowsDeletedBySize = 0;
        $oldLogRowsDeletedByAge = 0;

        // If true then the user clicked the button to execute the mantenance.
        $manually_fired = $abj404dao->getPostOrGetSanitize('manually_fired', 'false');
        if ($this->f->strtolower($manually_fired) == 'true') {
            $manually_fired = true;
        } else {
            $manually_fired = false;
        }

        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->createDatabaseTables(false);

        // Ensure database connection is active for long-running maintenance operations
        // This prevents "MySQL server has gone away" errors
        $this->ensureConnection();

        // delete the export file
        $tempFile = $abj404logic->getExportFilename();
        if (file_exists($tempFile)) {
        	ABJ_404_Solution_Functions::safeUnlink($tempFile);
        }
        
        // reset the crashed table count (both legacy scalar and per-table map)
        $options['repaired_count'] = 0;
        $options['repaired_counts'] = array();
        $abj404logic->updateOptions($options);

        $duplicateRowsDeleted = $abj404dao->removeDuplicatesCron();

        // Remove Captured URLs
        if (array_key_exists('capture_deletion', $options) && $options['capture_deletion'] != '0') {
            $status_list = ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER;
            $capturedURLsCount = $this->deleteOldRedirectsByType($options, $now, 'capture_deletion', $status_list, 'Captured 404');
            $captureDeletionDays = intval(is_scalar($options['capture_deletion']) ? $options['capture_deletion'] : 0);
            $oldLogRowsDeletedByAge = $this->deleteOldLogsByAge($captureDeletionDays, $now);
        }

        // Remove Automatic Redirects
        if (isset($options['auto_deletion']) && $options['auto_deletion'] != '0') {
            $status_list = (string)ABJ404_STATUS_AUTO;
            $autoRedirectsCount = $this->deleteOldRedirectsByType($options, $now, 'auto_deletion', $status_list, 'Automatic redirect');
        }

        // Remove Manual Redirects
        if (isset($options['manual_deletion']) && $options['manual_deletion'] != '0') {
            $status_list = ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_REGEX;
            $manualRedirectsCount = $this->deleteOldRedirectsByType($options, $now, 'manual_deletion', $status_list, 'Manual redirect');
        }

        // Remove orphaned auto redirects (destination post deleted/unpublished)
        $orphanedCount = $this->cleanupOrphanedAutoRedirects();

        // Auto-trash junk/bot captured URLs
        $junkTrashedCount = $this->autoTrashJunkCapturedUrls($options);

        //Clean up old logs. prepare the query. get the disk usage in bytes. compare to the max requested
        // disk usage (MB to bytes). delete 1k rows at a time until the size is acceptable.
        $logsSizeBytes = $abj404dao->getLogDiskUsage();
        $maxLogSizeBytes = (array_key_exists('maximum_log_disk_usage', $options) ? $options['maximum_log_disk_usage'] : 100) * 1024 * 1000;

        // Disk-size gate first: skip the trim entirely when we're under budget.
        // This keeps the daily cron path fast in the common case (no over-quota,
        // no scan, no destructive query) without relying on a row-count
        // approximation for any decision that drives DELETE.
        //
        // When we ARE over budget, pay for an exact COUNT(id) before computing
        // logLinesToDelete. An information_schema.TABLE_ROWS approximation
        // would be cheap, but for InnoDB it can drift by orders of magnitude
        // — using it as the denominator of a destructive DELETE … LIMIT N
        // query risks over-deleting retained logs (approx too low →
        // averageSizePerLine inflated → logLinesToKeep too small) or
        // under-deleting enough to miss the disk cap (approx too high →
        // reverse). The exact COUNT cost is paid only on the rare ticks
        // where we actually need to trim.
        if ($logsSizeBytes > $maxLogSizeBytes) {
            $totalLogLines = $abj404dao->getLogsCount(0);
            $averageSizePerLine = max($logsSizeBytes, 1) / max($totalLogLines, 1);
            $logLinesToKeep = ceil($maxLogSizeBytes / $averageSizePerLine);
            $logLinesToDelete = max($totalLogLines - $logLinesToKeep, 0);
            if ($logLinesToDelete > 0) {
                $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/deleteOldLogs.sql");
                $query = $this->f->str_replace('{lines_to_delete}', (string)$logLinesToDelete, $query);
                $results = $this->queryAndGetResults($query);
                $oldLogRowsDeletedBySizeRaw = $results['rows_affected'] ?? 0;
                $oldLogRowsDeletedBySize = (is_int($oldLogRowsDeletedBySizeRaw) || is_float($oldLogRowsDeletedBySizeRaw) || is_string($oldLogRowsDeletedBySizeRaw))
                    ? (int)$oldLogRowsDeletedBySizeRaw
                    : 0;
            }
        }
        
        $logsSizeBytes = $abj404dao->getLogDiskUsage();
        $logSizeMB = round($logsSizeBytes / (1024 * 1000), 2);
        
        $renamed = $abj404dao->limitDebugFileSize();
        $renamed = $renamed ? "true" : "false";
        
        $oldLogRowsDeleted = $oldLogRowsDeletedByAge + $oldLogRowsDeletedBySize;

        $message = "deleteOldRedirectsCron. Old captured URLs removed: " .
                $capturedURLsCount . ", Old automatic redirects removed: " . $autoRedirectsCount .
                ", Old manual redirects removed: " . $manualRedirectsCount .
                ", Orphaned auto redirects removed: " . $orphanedCount .
                ", Junk URLs auto-trashed: " . $junkTrashedCount .
                ", Old log lines removed: " . $oldLogRowsDeleted .
                " (age: " . $oldLogRowsDeletedByAge . ", size: " . $oldLogRowsDeletedBySize . ")" .
                ", New log size: " . $logSizeMB . "MB" .
                ", Duplicate rows deleted: " . $duplicateRowsDeleted . ", Debug file size limited: " .
                $renamed;
        
        // only send a 404 notification email during daily maintenance.
        $adminEmailVal = array_key_exists('admin_notification_email', $options) ? $options['admin_notification_email'] : '';
        if ($adminEmailVal !== null &&
                $this->f->strlen(trim(is_string($adminEmailVal) ? $adminEmailVal : '')) > 5) {
            
            if ($manually_fired) {
                $message .= ', The admin email notification option is skipped for user '
                        . 'initiated maintenance runs.';
            } else {
                $message .= ', ' . $abj404logic->emailCaptured404Notification();
            }
        } else {
            $message .= ', Admin email notification option turned off.';
        }

        if (isset($options['send_error_logs']) &&
                $options['send_error_logs'] == '1') {
            if ($this->logger->emailErrorLogIfNecessary()) {
                $message .= ", Log file emailed to developer.";
            } else {
                // No error to report — roll the heartbeat dice.
                if ($this->logger->sendHeartbeatIfDueRandom(200)) {
                    $message .= ", Heartbeat log emailed to developer.";
                }
            }
        }
        
        // Flag redirects whose destination URL is generating 404s (dead-destination detection).
        // This drives the "suspended redirect" warning in the admin table and allows
        // the frontend pipeline to skip known-bad destinations.
        $abj404dao->flagDeadDestinationRedirects();

        // add some entries to the permalink cache if necessary
        $abj404permalinkCache = ABJ_404_Solution_PermalinkCache::getInstance();
        $rowsUpdated = $abj404permalinkCache->updatePermalinkCache(15);
        $message .= ", Permlink cache rows updated: " . $rowsUpdated;
        
        $manually_fired_String = ($manually_fired) ? 'true' : 'false';
        $message .= ", User initiated: " . $manually_fired_String;
                
        $this->logger->infoMessage($message);
        
        // fix any lingering errors
        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->createDatabaseTables();
        
        $this->queryAndGetResults("optimize table {wp_abj404_redirects}");
        
        $upgradesEtc->updatePluginCheck();
        
        return $message;
    }
    
    /** @return bool */
    function limitDebugFileSize(): bool {
        $renamed = false;
        
        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        if ($mbFileSize > 10) {
            $this->logger->limitDebugFileSize();
            $renamed = true;
        }
        
        return $renamed;
    }
    
    /** Remove duplicates.
     * @return int
     */
    function removeDuplicatesCron(): int {
        $rowsDeleted = 0;
        $query = "SELECT COUNT(id) as repetitions, url FROM {wp_abj404_redirects} GROUP BY url HAVING repetitions > 1 ";
        $result = $this->queryAndGetResults($query);
        $outerRows = is_array($result['rows']) ? $result['rows'] : array();
        foreach ($outerRows as $outerRow) {
            if (!is_array($outerRow)) {
                continue;
            }
            $row = $outerRow;
            $url = $row['url'];

            // Fix HIGH #2 (5th review): Use prepared statements instead of manual escaping
            $queryr1 = $this->prepare_query_wp(
                "select id from {wp_abj404_redirects} where url = {url} order by timestamp desc limit 0,1",
                array("url" => $url)
            );
            $result = $this->queryAndGetResults($queryr1);
            $innerRows = is_array($result['rows']) ? $result['rows'] : array();
            if (count($innerRows) >= 1) {
                $row = is_array($innerRows[0]) ? $innerRows[0] : array();
                $original = isset($row['id']) ? $row['id'] : 0;

                // Fix HIGH #2 (5th review): Use prepared statements instead of manual escaping
                $queryl = $this->prepare_query_wp(
                    "delete from {wp_abj404_redirects} where url = {url} and id != {original}",
                    array("url" => $url, "original" => $original)
                );
                $deleteResult = $this->queryAndGetResults($queryl);
                $affected = isset($deleteResult['rows_affected']) && is_numeric($deleteResult['rows_affected'])
                    ? (int)$deleteResult['rows_affected'] : 1;
                $rowsDeleted += max($affected, 1);
            }
        }

        // Invalidate status counts cache if any duplicates were removed
        if ($rowsDeleted > 0) {
            $this->invalidateStatusCountsCache();
        }

        return $rowsDeleted;
    }

    /**
     * Store a redirect for future use.
     * @global type $wpdb
     * @param string $fromURL
     * @param string $status ABJ404_STATUS_MANUAL etc
     * @param string $type ABJ404_TYPE_POST, ABJ404_TYPE_CAT, ABJ404_TYPE_TAG, etc.
     * @param string $final_dest
     * @param string $code
     * @param int $disabled
     * @param string|null $engine  The matching engine that created this redirect (null for manual/unknown)
     * @param float|null $score   Match confidence score (0-100), NULL for manual redirects
     * @return int
     */
    function setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled = 0, $engine = null, $score = null) {
        global $wpdb;

        // nonce is verified outside of this method. We can't verify here because 
        // automatic redirects are sometimes created without user interaction.

        if (!is_numeric($type)) {
            $this->logger->errorMessage("Wrong data type for redirect. TYPE is non-numeric. From: " .
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        } else if (!is_numeric($status)) {
            $this->logger->errorMessage("Wrong data type for redirect. STATUS is non-numeric. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        }

        $statusAsInt = is_numeric($status) ? absint($status) : -1;
        $typeAsInt = is_numeric($type) ? absint($type) : -1;

        // Guard: automatic redirects must point to a currently valid destination.
        // This prevents persisting "auto" rows with missing/unpublished targets.
        if ($statusAsInt === ABJ404_STATUS_AUTO &&
                !$this->isValidAutomaticRedirectDestination($typeAsInt, $final_dest)) {
            $this->logger->debugMessage("Skipping automatic redirect with invalid destination. " .
                    "From: " . esc_url($fromURL) . ", Dest: " . esc_html((string)$final_dest) .
                    ", Type: " . esc_html((string)$type) . ", Status: " . esc_html((string)$status));
            return 0;
        }

        // if we should not capture a 404 then don't.
        if (!ABJ_404_Solution_RequestContext::getInstance()->ignore_doprocess) {
            $now = time();
            $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");

            // Normalize to relative path before storing (Issue #24)
            // Fix HIGH #1 (5th review): Abort operation if normalization fails
            // Storing un-normalized URLs causes permanent lookup failures
            $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
            $fromURL = $abj404logic->normalizeToRelativePath($fromURL);

            // Fix HIGH #1 (3rd review): Remove esc_sql() - wpdb->insert handles escaping
            $insertData = array(
                'url' => $fromURL,
                'status' => $status,
                'type' => $type,
                'final_dest' => $final_dest,
                'code' => $code,
                'disabled' => $disabled,
                'timestamp' => $now,
            );
            $insertFormats = array('%s', '%d', '%d', '%s', '%d', '%d', '%d');
            if ($engine !== null) {
                $insertData['engine'] = substr((string)$engine, 0, 64);
                $insertFormats[] = '%s';
            }
            if ($score !== null) {
                $insertData['score'] = round((float)$score, 2);
                $insertFormats[] = '%f';
            }
            $wpdb->insert($redirectsTable, $insertData, $insertFormats);

            // Invalidate caches
            $this->invalidateStatusCountsCache();
            // Clear regex cache in case a regex redirect was added
            if ($status == ABJ404_STATUS_REGEX) {
                $this->clearRegexRedirectsCache();
            }
        }

        return $wpdb->insert_id;
    }

    /**
     * Automatic redirects are only valid for published posts or existing terms.
     * If a destination is missing or unpublished, skip creating the auto redirect.
     *
     * @param int $type
     * @param mixed $finalDest
     * @return bool
     */
    private function isValidAutomaticRedirectDestination($type, $finalDest) {
        $destId = absint(is_scalar($finalDest) ? $finalDest : 0);

        if ($type === ABJ404_TYPE_POST) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_post')) {
                return true;
            }

            $post = get_post($destId);
            if (!is_object($post)) {
                return false;
            }

            $postStatus = strtolower($post->post_status);

            return in_array($postStatus, array('publish', 'published'), true);
        }

        if ($type === ABJ404_TYPE_CAT || $type === ABJ404_TYPE_TAG) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_term')) {
                return true;
            }

            $taxonomy = ($type === ABJ404_TYPE_CAT) ? 'category' : 'post_tag';
            $term = get_term($destId, $taxonomy);
            if ($term === null || is_wp_error($term)) {
                return false;
            }
            return is_object($term);
        }

        // Homepage is always a valid auto redirect destination.
        if ($type === ABJ404_TYPE_HOME) {
            return true;
        }

        // Auto redirects should not target other types.
        return false;
    }

    /** Get the redirect for the URL.
     *
     * @param string $url
     * @param bool $degradedMode When true, the lookup tolerates a partially-
     *        migrated schema by stripping predicates that reference columns
     *        not yet present (e.g. r.start_ts / r.end_ts on installs that
     *        have not run the 4.1.x scheduled-redirect migration). Skipping
     *        scheduled-redirect filtering is far better than 100% of redirects
     *        failing silently. The pipeline only enables this mode when
     *        DB_VERSION lags ABJ404_VERSION and recovery has not closed the
     *        gap, so the happy path pays no extra cost.
     * @return array<string, mixed>
     */
    function getActiveRedirectForURL($url, $degradedMode = false) {
        // Strip invalid UTF-8/control bytes but keep valid unicode for multilingual slugs.
        $url = $this->f->sanitizeInvalidUTF8($url);

        // Reject URLs still invalid after sanitization (bot garbage like %c0, null bytes)
        if (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8')) {
            return array('id' => 0);
        }

        // Normalize to relative path before querying (Issue #24)
        // Fix HIGH #1 (5th review): Abort operation if normalization fails
        // Querying with un-normalized URLs causes lookup failures
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $candidates = $abj404logic->getNormalizedUrlCandidates($url);
        foreach ($candidates as $candidate) {
            $redirect = $this->getActiveRedirectForNormalizedUrl($candidate, $degradedMode);
            if ($redirect['id'] !== 0) {
                return $redirect;
            }
        }

        return array('id' => 0);
    }

    /** Get the redirect for the URL.
     * @param string $url
     * @return array<string, mixed>
     */
    function getExistingRedirectForURL($url) {
        // Strip invalid UTF-8/control bytes but keep valid unicode for multilingual slugs.
        $url = $this->f->sanitizeInvalidUTF8($url);

        // Reject URLs still invalid after sanitization (bot garbage like %c0, null bytes)
        if (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8')) {
            return array('id' => 0);
        }

        // Normalize to relative path before querying (Issue #24)
        // Fix HIGH #1 (5th review): Abort operation if normalization fails
        // Querying with un-normalized URLs causes lookup failures
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $candidates = $abj404logic->getNormalizedUrlCandidates($url);
        foreach ($candidates as $candidate) {
            $redirect = $this->getExistingRedirectForNormalizedUrl($candidate);
            if ($redirect['id'] !== 0) {
                return $redirect;
            }
        }

        return array('id' => 0);
    }

    /**
     * @param string $url
     * @param bool $degradedMode See getActiveRedirectForURL().
     * @return array<string, mixed>
     */
    private function getActiveRedirectForNormalizedUrl($url, $degradedMode = false) {
        $redirect = array();

        // we look for two URLs that might match. one with a trailing slash and one without.
        // the one the user entered takes priority in case the admin added separate redirects for
        // cases with and without the slash (and for backward compatibility).
        $url1 = $url;
        $url2 = $url;
        if (substr($url, -1) === '/') {
            $url2 = rtrim($url, '/');
        } else {
            $url2 = $url2 . '/';
        }

        // join to the wp_posts table to make sure the post exists.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPermalinkFromURL.sql");

        // Degraded mode: when the migration that adds r.start_ts/r.end_ts has
        // not run (or is failing repeatedly), the columns may be missing from
        // wp_abj404_redirects on this install. Strip the scheduled-redirect
        // predicates so the lookup still serves manual + automatic redirects.
        // Trade-off: scheduled redirects whose start/end window is currently
        // active will still match (good); scheduled redirects whose window has
        // not yet opened or has already closed will *also* match during this
        // window (acceptable — far better than 100% of redirects failing).
        if ($degradedMode && $this->redirectsTableMissingScheduledColumns()) {
            $query = $this->stripScheduledRedirectPredicates($query);
        }

        // Fix HIGH #2 (5th review): Use prepared statements instead of manual escaping
        $query = $this->prepare_query_wp($query, array("url1" => $url1, "url2" => $url2));
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        if (is_array($rows)) {
            if (empty($rows)) {
                $redirect['id'] = 0;
            } else {
                foreach ($rows[0] as $key => $value) {
                    $redirect[$key] = $value;
                }
            }
        }

        if (!isset($redirect['id'])) {
            $redirect['id'] = 0;
        }

        return $redirect;
    }

    /**
     * Determine whether wp_abj404_redirects is missing the scheduled-redirect
     * columns (start_ts / end_ts) added in the 4.1.x migration. Result is
     * cached in a transient so subsequent 404s do not re-run SHOW COLUMNS.
     *
     * The cache is short-lived on the "missing" branch (5 min) so that once
     * the migration finally runs we pick up the new columns quickly. On the
     * "present" branch we cache for 24 h — columns don't disappear once added,
     * so a long TTL keeps the happy path fast.
     */
    private function redirectsTableMissingScheduledColumns(): bool {
        $cacheKey = 'abj404_redirects_scheduled_cols_status';
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if ($cached === 'missing') { return true; }
            if ($cached === 'present') { return false; }
        }

        $tableName = $this->doTableNameReplacements('{wp_abj404_redirects}');
        $columns = $this->getRedirectsTableColumns($tableName);

        // If we couldn't read the schema at all, do NOT strip predicates —
        // returning false keeps the standard query, which fails loudly rather
        // than masking a deeper problem.
        if (empty($columns)) {
            return false;
        }

        $colsLower = array_map('strtolower', $columns);
        $missing = !in_array('start_ts', $colsLower, true)
                || !in_array('end_ts', $colsLower, true);

        if (function_exists('set_transient')) {
            $hour = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
            set_transient(
                $cacheKey,
                $missing ? 'missing' : 'present',
                $missing ? 5 * 60 : 24 * $hour
            );
        }

        return $missing;
    }

    /**
     * Read column names for a table via SHOW COLUMNS. Returns [] on failure
     * so callers can decide to fall back to the standard query.
     *
     * @return array<int, string>
     */
    private function getRedirectsTableColumns(string $tableName): array {
        global $wpdb;
        if (!isset($wpdb)) {
            return [];
        }
        $rows = $wpdb->get_results(
            "SHOW COLUMNS FROM `" . esc_sql($tableName) . "`",
            ARRAY_A
        );
        if (!is_array($rows) || !empty($wpdb->last_error)) {
            return [];
        }
        $columns = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Field']) && is_string($row['Field'])) {
                $columns[] = $row['Field'];
            }
        }
        return $columns;
    }

    /**
     * Strip lines from getPermalinkFromURL.sql that reference r.start_ts or
     * r.end_ts. Used in degraded mode when those columns are missing on this
     * install.
     */
    private function stripScheduledRedirectPredicates(string $sql): string {
        $stripped = preg_replace(
            '/^[^\n]*\br\.(?:start_ts|end_ts)\b[^\n]*\R?/m',
            '',
            $sql
        );
        return is_string($stripped) ? $stripped : $sql;
    }

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    private function getExistingRedirectForNormalizedUrl($url) {
        $redirect = array();

        // a disabled value of '1' means in the trash.
        $query = $this->prepare_query_wp('select * from {wp_abj404_redirects} where BINARY url = BINARY {url} ' .
            " and disabled = 0 ", array("url" => $url));
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        if (is_array($rows)) {
            if (empty($rows)) {
                $redirect['id'] = 0;
            } else {
                foreach ($rows[0] as $key => $value) {
                    $redirect[$key] = $value;
                }
            }
        }

        if (!isset($redirect['id'])) {
            $redirect['id'] = 0;
        }

        return $redirect;
    }
    
    /** Returns rows with the IDs of the published items.
     * @global type $wpdb
     * @global type $abj404logic
     * @global type $abj404dao
     * @global type $abj404logging
     * @param string $slug only get results for this slug. (empty means all posts)
     * @param string $searchTerm use this string in a LIKE on the sql.
     * @param string $limitResults
     * @param string $orderResults
     * @param string $extraWhereClause use this string in a where on the sql.
     * @return array<int, object>
     */
    function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '',
    	$limitResults = '', $orderResults = '', $extraWhereClause = '') {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        // Fix for missing table error (reported by 2 users - 4% of errors)
        // Check if wp_posts table exists before querying
        if (!$this->tableExists($wpdb->posts)) {
            $this->logger->errorMessage("WordPress posts table not found: " . $wpdb->posts .
                ". This may indicate an incorrect table prefix or database configuration issue.");
            return array(); // Return empty array instead of crashing
        }

        // get the valid post types
        $options = $abj404logic->getOptions();
        $recognizedPostTypes = $this->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return array();
        }
        // ----------------

        if ($slug != "") {
            // Sanitize invalid UTF-8 before SQL to prevent database errors
            // (fixes bug: URLs like %9F%9F%9F%9F-%9F%9F%9F-1.png cause "invalid data" errors)
            $slug = $this->f->sanitizeInvalidUTF8($slug);

            // Check if the post_name column supports utf8mb4 collation
            // (fixes bug: Arabic sites on latin1 databases get "invalid data" errors)
            // Note: Check actual column collation, not database default - on mixed setups
            // the database may be latin1 but wp_posts.post_name is utf8mb4
            $columnCollation = $wpdb->get_var($wpdb->prepare(
                "SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = %s
                 AND COLUMN_NAME = 'post_name'",
                $wpdb->posts
            ));
            if ($columnCollation !== null && strpos(strtolower($columnCollation), 'utf8mb4') !== false) {
                // Column supports utf8mb4 - use CAST for proper Unicode comparison
                $resolvedCollation = $this->sanitizeCollationIdentifier($columnCollation);
                if ($resolvedCollation === '') {
                    $resolvedCollation = $this->getPreferredUtf8mb4Collation();
                }
                $specifiedSlug = " */\n and CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci = "
                        . "'" . esc_sql($slug) . "' \n ";
                $specifiedSlug = str_replace('utf8mb4_unicode_ci', $resolvedCollation, $specifiedSlug);
            } else {
                // Legacy column (latin1, utf8, etc.) - use simple comparison.
                // 4-byte UTF-8 characters (emoji, rare CJK, etc.) cannot exist in a
                // utf8mb3/latin1 column, so skip the slug comparison entirely to avoid
                // "Illegal mix of collations" errors.
                if ($this->f->containsUtf8mb4Characters($slug)) {
                    $specifiedSlug = '';
                } else {
                    $specifiedSlug = " */\n and wp_posts.post_name = "
                            . "'" . esc_sql($slug) . "' \n ";
                }
            }
        } else {
            $specifiedSlug = '';
        }
        
        if ($searchTerm != "") {
        	$searchTerm = " */\n and lower(wp_posts.post_title) like "
        		. "'%" . esc_sql($this->f->strtolower($searchTerm)) . "%' \n ";
        } else {
        	$searchTerm = '';
        }
        
        if ($extraWhereClause != "") {
        	$extraWhereClause = " */\n " . $extraWhereClause;
        }
        
        if (!empty($limitResults)) {
            $limitResults = " */\n  limit " . $limitResults;
        }
        if (!empty($orderResults)) {
        	$orderResults = " */\n  order by " . $orderResults;
        }
        
        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedPagesAndPostsIDs.sql");
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        $query = $this->f->str_replace('{specifiedSlug}', $specifiedSlug, $query);
        $query = $this->f->str_replace('{searchTerm}', $searchTerm, $query);
        $query = $this->f->str_replace('{extraWhereClause}', $extraWhereClause, $query);
        $query = $this->f->str_replace('{limit-results}', $limitResults, $query);
        $query = $this->f->str_replace('{order-results}', $orderResults, $query);
        
        $result = $this->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        // Collation-error fallback: if CONVERT(... USING utf8mb4) COLLATE still fails
        // (e.g. MySQL version quirk), retry without any COLLATE forcing — the pre-4.1.4
        // behavior that relies on implicit collation resolution.
        if (!empty($queryError) && $this->isCollationError($queryError)) {
            $fpreg = ABJ_404_Solution_FunctionsPreg::getInstance();
            $fallbackQuery = $fpreg->regexReplace(
                'CONVERT\(wpt\.name USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
                'wpt.name', $query);
            $fallbackQuery = $fpreg->regexReplace(
                'CONVERT\(usefulterms\.grouped_terms USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
                'usefulterms.grouped_terms', is_string($fallbackQuery) ? $fallbackQuery : $query);
            $fallbackResult = $this->queryAndGetResults(
                is_string($fallbackQuery) ? $fallbackQuery : $query,
                array('result_type' => OBJECT, 'log_errors' => false));
            $queryError = is_string($fallbackResult['last_error'] ?? '') ? ($fallbackResult['last_error'] ?? '') : '';
            if (empty($queryError)) {
                $rows = is_array($fallbackResult['rows']) ? $fallbackResult['rows'] : array();
            }
        }

        if (!empty($queryError) && $this->isInvalidDataError($queryError) &&
                $slug != "" && strpos($query, 'CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4)') !== false) {
            // Compatibility fallback: retry once without CAST/COLLATE for environments
            // where mixed encodings still reject utf8mb4 coercion.
            $fallbackSpecifiedSlug = " */\n and wp_posts.post_name = '" . esc_sql($slug) . "' \n ";
            $fallbackQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedPagesAndPostsIDs.sql");
            $fallbackQuery = $this->doTableNameReplacements($fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{specifiedSlug}', $fallbackSpecifiedSlug, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{searchTerm}', $searchTerm, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{extraWhereClause}', $extraWhereClause, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{limit-results}', $limitResults, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{order-results}', $orderResults, $fallbackQuery);
            $fallbackResult = $this->queryAndGetResults($fallbackQuery, array('result_type' => OBJECT, 'log_errors' => false));
            $fallbackError = is_string($fallbackResult['last_error'] ?? '') ? ($fallbackResult['last_error'] ?? '') : '';
            if (empty($fallbackError)) {
                $queryError = '';
                $rows = is_array($fallbackResult['rows']) ? $fallbackResult['rows'] : array();
            }
        }

        // check for errors (use $queryError which tracks the latest attempt)
        if ($queryError) {
            // "Unknown column 'plc.content_keywords'" occurs during the DB migration window
            // when the column hasn't been added yet (e.g. sync lock was stuck for ~24h).
            // Degrade to warning so it doesn't generate email reports for every 404 hit.
            if (stripos($queryError, 'unknown column') !== false &&
                    stripos($queryError, 'content_keywords') !== false) {
                $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $queryError);
            } else if (!$this->classifyAndHandleInfrastructureError($queryError)) {
                $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
            }
        }

        return $rows;
    }

    /** Returns rows with the IDs of the published images.
     * @return array<int, object>
     */
    function getPublishedImagesIDs() {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        
        // get the valid post types
        $options = $abj404logic->getOptions();
        $recognizedPostTypes = $this->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return array();
        }
        // ----------------

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedImageIDs.sql");
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        
        $result = $this->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }

        return is_array($result['rows']) ? $result['rows'] : array();
    }

    /** Returns rows with the defined terms (tags).
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    function getPublishedTags($slug = null, $limit = null) {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        // get the valid post types
        $options = $abj404logic->getOptions();

        $recognizedCategories = $this->buildCategorySqlList($options);

        if ($slug != null) {
            // Sanitize invalid UTF-8 before SQL to prevent database errors
            $slug = $this->f->sanitizeInvalidUTF8($slug);
            $slug = "*/ and wp_terms.slug = '" . esc_sql($slug) . "'\n";
        }

        $limitClause = '';
        if ($limit !== null && is_numeric($limit) && $limit > 0) {
            $limitClause = "LIMIT " . intval($limit);
        }

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedTags.sql");
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);

        $result = $this->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $rows = $this->addURLToTermsRows($rows);

        return $rows;
    }
    
    /**
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    function addURLToTermsRows($rows) {
    	// add url data
    	global $wp_rewrite;
    	$extraPermaStructureCache = array();
    	foreach ($rows as $row) {
    		$taxonomy = isset($row->taxonomy) ? (string)$row->taxonomy : '';
    		if (!array_key_exists($taxonomy, $extraPermaStructureCache)) {
    			$extraPermaStructureCache[$taxonomy] = $wp_rewrite->get_extra_permastruct($taxonomy);
    		}
    		$struct = $extraPermaStructureCache[$taxonomy];
    		
    		$slug = isset($row->slug) ? (string)$row->slug : '';
    		$url = str_replace('%' . $taxonomy . '%', $slug, $struct);
    		
    		// TODO verify one of the urls?
    		/*
    		if (!$verifiedOne) {
    			$id = $row->term_id;
    			$link = get_tag_link($id);
    			$link = get_category_link($id);
    			// $link should equal $url
		    	$verifiedOne = true;
    		}
    		*/
    		
    		/** @var \stdClass $row */
    		$row->url = $url;
    	}
    	
    	return $rows;
    }
    
    /** Returns rows with the defined categories.
     * @param int|null $term_id
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    function getPublishedCategories($term_id = null, $slug = null, $limit = null) {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        // get the valid post types
        $options = $abj404logic->getOptions();

        $recognizedCategories = $this->buildCategorySqlList($options);
        if ($recognizedCategories === '') {
            $recognizedCategories = "''";
        }

        if ($term_id != null) {
            // Cast to integer for safety even though term_id is currently always null from callers
            $term_id = "*/ and {wp_terms}.term_id = " . intval($term_id) . "\n";
        }

        if ($slug != null) {
            // Sanitize invalid UTF-8 before SQL to prevent database errors
            $slug = $this->f->sanitizeInvalidUTF8($slug);
            $slug = "*/ and {wp_terms}.slug = '" . esc_sql($slug) . "'\n";
        }

        $limitClause = '';
        if ($limit !== null && is_numeric($limit) && $limit > 0) {
            $limitClause = "LIMIT " . intval($limit);
        }

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedCategories.sql");
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);
        $query = $this->f->str_replace('{term_id}', $term_id !== null ? (string)$term_id : '', $query);
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $rows = $this->addURLToTermsRows($rows);

        return $rows;
    }

    /** Delete stored redirects based on passed in POST data.
     * @return string
     */
    function deleteSpecifiedRedirects() {
        global $wpdb;
        $message = "";

        // nonce already verified.

        if (!array_key_exists('sanity_purge', $_POST) || $_POST['sanity_purge'] != "1") {
            $message = __('Error: You didn\'t check the I understand checkbox. No purging of records for you!', '404-solution');
            return $message;
        }
        
        if (!isset($_POST['types']) || $_POST['types'] == '') {
            $message = __('Error: No redirect types were selected. No purges will be done.', '404-solution');
            return $message;
        }
        
        if (is_array($_POST['types'])) {
            $type = array_map('sanitize_text_field', $_POST['types']);
        } else {
            $type = sanitize_text_field($_POST['types']);
        }

        if (!is_array($type)) {
            $message = __('An unknown error has occurred.', '404-solution');
            return $message;
        }
        
        $redirectTypes = array();
        foreach ($type as $aType) {
            if (('' . $aType != ABJ404_TYPE_HOME) && ('' . $aType != ABJ404_TYPE_404_DISPLAYED)) {
                array_push($redirectTypes, absint($aType));
            }
        }

        if (empty($redirectTypes)) {
            $message = __('Error: No valid redirect types were selected. Exiting.', '404-solution');
            $this->logger->debugMessage("Error: No valid redirect types were selected. Types: " .
                    wp_kses_post((string)json_encode($redirectTypes)));
            return $message;
        }
        $purge = isset($_POST['purgetype']) ? sanitize_text_field($_POST['purgetype']) : '';

        if ($purge != 'abj404_logs' && $purge != 'abj404_redirects') {
            $message = __('Error: An invalid purge type was selected. Exiting.', '404-solution');
            $this->logger->debugMessage("Error: An invalid purge type was selected. Type: " .
                    wp_kses_post((string)json_encode($purge)));
            return $message;
        }
        
        // always add the type "0" because it's an invalid type that may exist in the databse.
        // Adding it here does some cleanup if any is necessary.
        array_push($redirectTypes, 0);

        // Ensure all values are integers to prevent SQL injection
        $redirectTypes = array_map('absint', $redirectTypes);
        $typesForSQL = implode(',', $redirectTypes);

        if ($purge == 'abj404_redirects') {
            $query = "update {wp_abj404_redirects} set disabled = 1 where status in (" . $typesForSQL . ")";
            $query = $this->doTableNameReplacements($query);
            $redirectCount = $wpdb->query($query);

            // Invalidate caches so the admin table reflects the purge immediately
            $this->invalidateStatusCountsCache();
            $this->clearRegexRedirectsCache();

            $message .= sprintf( _n( '%s redirect entry was moved to the trash.',
                    '%s redirect entries were moved to the trash.', $redirectCount, '404-solution'), $redirectCount);
        }

        return $message;
    }

    /**
     * This returns only the first column of the first row of the result.
     * @global type $wpdb
     * @param string $query a query that starts with "select count(id) from ..."
     * @param array<int, mixed> $valueParams values to use to prepare the query.
     * @return int the count (result) of the query.
     */

    /**
     * Get all conditions for a redirect, ordered by sort_order.
     *
     * Returns an empty array when the redirect has no conditions or when the
     * conditions table does not yet exist (graceful degradation).
     *
     * @param int $redirectId
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectConditions(int $redirectId): array {
        global $wpdb;

        $table = $this->doTableNameReplacements('{wp_abj404_redirect_conditions}');

        // Guard: conditions table may not exist on older installs before upgrade runs.
        if (!$this->tableExists($table)) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT id, redirect_id, logic, condition_type, operator, value, sort_order
             FROM {$table}
             WHERE redirect_id = %d
             ORDER BY sort_order ASC, id ASC",
            $redirectId
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if ($wpdb->last_error) {
            $this->logger->warn("getRedirectConditions: DB error for redirect_id={$redirectId}: " . $wpdb->last_error);
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Save conditions for a redirect (replaces all existing conditions).
     *
     * Passes through empty arrays safely — all existing conditions are deleted
     * and nothing is inserted, which is the correct behaviour for "no conditions".
     *
     * @param int   $redirectId
     * @param array<int, array<string, mixed>> $conditions  Array of condition arrays.
     *              Each must contain: logic, condition_type, operator, value, sort_order.
     * @return void
     */
    public function saveRedirectConditions(int $redirectId, array $conditions): void {
        global $wpdb;

        $table = $this->doTableNameReplacements('{wp_abj404_redirect_conditions}');

        // Guard: conditions table may not exist yet.
        if (!$this->tableExists($table)) {
            $this->logger->warn("saveRedirectConditions: conditions table missing — skipping save for redirect_id={$redirectId}.");
            return;
        }

        // Delete existing conditions for this redirect.
        $wpdb->delete($table, ['redirect_id' => $redirectId], ['%d']);

        if ($wpdb->last_error) {
            $this->logger->warn("saveRedirectConditions: error deleting old conditions for redirect_id={$redirectId}: " . $wpdb->last_error);
        }

        if (empty($conditions)) {
            return;
        }

        $allowedTypes = [
            'login_status', 'user_role', 'referrer',
            'user_agent', 'ip_range', 'http_header',
        ];
        $allowedOperators = [
            'equals', 'contains', 'regex',
            'not_equals', 'not_contains', 'cidr',
        ];
        $allowedLogic = ['AND', 'OR'];

        foreach ($conditions as $index => $cond) {
            if (!is_array($cond)) {
                continue;
            }

            $logic    = isset($cond['logic']) && is_string($cond['logic'])
                ? strtoupper(trim($cond['logic'])) : 'AND';
            $type     = isset($cond['condition_type']) && is_string($cond['condition_type'])
                ? trim($cond['condition_type']) : '';
            $operator = isset($cond['operator']) && is_string($cond['operator'])
                ? trim($cond['operator']) : 'equals';
            $value    = isset($cond['value']) && is_string($cond['value'])
                ? trim($cond['value']) : '';
            $sortOrder = isset($cond['sort_order']) ? absint(is_scalar($cond['sort_order']) ? $cond['sort_order'] : 0) : $index;

            // Validate required fields.
            if (!in_array($logic, $allowedLogic, true)) {
                $logic = 'AND';
            }
            if (!in_array($type, $allowedTypes, true)) {
                $this->logger->warn("saveRedirectConditions: unknown condition_type '{$type}' — skipping.");
                continue;
            }
            if (!in_array($operator, $allowedOperators, true)) {
                $operator = 'equals';
            }
            // Truncate value to column max (1024 chars).
            if (strlen($value) > 1024) {
                $value = substr($value, 0, 1024);
            }

            $wpdb->insert(
                $table,
                [
                    'redirect_id'    => $redirectId,
                    'logic'          => $logic,
                    'condition_type' => $type,
                    'operator'       => $operator,
                    'value'          => $value,
                    'sort_order'     => $sortOrder,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%d']
            );

            if ($wpdb->last_error) {
                $this->logger->warn("saveRedirectConditions: error inserting condition #{$index} for redirect_id={$redirectId}: " . $wpdb->last_error);
            }
        }
    }

    /**
     * Auto-trash captured URLs that match known junk/bot patterns, and
     * captured URLs with 0 hits older than 14 days.
     *
     * Rate-limited to once per hour via transient.
     *
     * @param array<string, mixed> $options Plugin options.
     * @return int Number of URLs trashed.
     */
    function autoTrashJunkCapturedUrls(array $options): int {
        // Feature must be enabled
        $enabled = $options['auto_trash_junk_urls'] ?? '0';
        if ($enabled !== '1') {
            return 0;
        }

        // Rate limit: once per hour
        $transientKey = 'abj404_last_auto_trash';
        if (get_transient($transientKey) !== false) {
            return 0;
        }
        set_transient($transientKey, time(), HOUR_IN_SECONDS);

        $patternsRaw = $options['auto_trash_junk_patterns'] ?? '';
        $patternsStr = is_string($patternsRaw) ? $patternsRaw : '';
        $lines = array_filter(array_map('trim', explode("\n", $patternsStr)));

        if (empty($lines)) {
            return 0;
        }

        global $wpdb;
        $totalTrashed = 0;

        // Build LIKE conditions for each pattern
        $likeClauses = array();
        foreach ($lines as $pattern) {
            $escaped = $wpdb->esc_like($pattern);
            $likeClauses[] = $wpdb->prepare("url LIKE %s", '%' . $escaped . '%');
        }

        // Trash captured URLs matching junk patterns (case-insensitive via LIKE)
        $wherePatterns = implode(' OR ', $likeClauses);
        $query = "UPDATE {wp_abj404_redirects}
            SET disabled = 1
            WHERE status = " . ABJ404_STATUS_CAPTURED . "
            AND disabled = 0
            AND (" . $wherePatterns . ")";
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query);
        $affected = $result['rows_affected'] ?? 0;
        $totalTrashed += is_numeric($affected) ? (int)$affected : 0;

        // Trash captured URLs with 0 log hits older than 14 days.
        // logshits is not a column — it's computed from the logs table.
        $cutoff = time() - (14 * DAY_IN_SECONDS);
        $query = $wpdb->prepare(
            "UPDATE {wp_abj404_redirects} r
            SET r.disabled = 1
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . "
            AND r.disabled = 0
            AND r.timestamp < %d
            AND NOT EXISTS (
                SELECT 1 FROM {wp_abj404_logsv2} l
                WHERE l.requested_url = r.url
                LIMIT 1
            )",
            $cutoff
        );
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query);
        $affected = $result['rows_affected'] ?? 0;
        $totalTrashed += is_numeric($affected) ? (int)$affected : 0;

        if ($totalTrashed > 0) {
            $this->logger->infoMessage("Auto-trashed " . $totalTrashed . " junk/stale captured URLs during maintenance.");
            // Invalidate the cached status counts so the UI reflects the change
            delete_transient(self::CACHE_KEY_CAPTURED_STATUS);
        }

        return $totalTrashed;
    }
}
