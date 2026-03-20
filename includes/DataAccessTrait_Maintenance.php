<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DataAccess_MaintenanceTrait {

    /** @param string $errorMessage @return void */
    function repairTable(string $errorMessage): void {

        $re = "Table '(.*\/)?(.+)' is marked as crashed and ";
        $matches = array();

        $this->f->regexMatch($re, $errorMessage, $matches);
        if (!empty($matches) && count($matches) > 2 && $this->f->strlen($matches[2]) > 0) {
            $tableToRepair = $matches[2];
            if ($this->f->strpos($tableToRepair, "abj404") !== false) {
                $query = "repair table " . $tableToRepair;
                $result = $this->queryAndGetResults($query, array('log_errors' => false));
                $this->logger->infoMessage("Attempted to repair table " . $tableToRepair . ". Result: " .
                        json_encode($result));

                // track how many times we've tried to repair something.
                // only for the certain tables. Exclude the redirects table because people
                // may have spent time creating entries there. Other tables are generated
                // automatically.
                if (strpos($tableToRepair, 'redirects') === false) {
	                $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
	                $options = $abj404logic->getOptions();
	                if (!array_key_exists('repaired_count', $options)) {
	                	$options['repaired_count'] = 0;
	                }
	                $options['repaired_count'] = (is_scalar($options['repaired_count']) ? intval($options['repaired_count']) : 0) + 1;
	                $abj404logic->updateOptions($options);

	                if (intval($options['repaired_count']) > 3 &&
	                		intval($options['repaired_count']) < 7) {

	                	$upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
	                	$this->queryAndGetResults('drop table ' . $tableToRepair);
	                	$upgradesEtc->createDatabaseTables(false);
	                }
                }

            } else {
                // tell someone the table $tableToRepair is broken.
            	$this->logger->warn("The table " . $tableToRepair . " needs to be " .
            		"repaired with something like: repair table " . $tableToRepair);
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
    		$tableName = $matchesForTableName[1];

    		// Validate that ID is numeric to prevent SQL injection
    		if (!is_numeric($idWithDuplicate)) {
    			$this->logger->errorMessage("Invalid ID extracted from error message: " . $idWithDuplicate);
    			return;
    		}

    		if ($idWithDuplicate == 1) {
    			$idWithDuplicate = 0;
    		}

    		// Use prepared statement to prevent SQL injection
    		$result = $this->queryAndGetResults("delete from " . $tableName . " where id = %d",
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
                        $this->logger->errorMessage("Error executing SQL transaction: " . $lastError);
                        $this->logger->errorMessage("SQL causing the transaction error: " . $statement);
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
        $rptValCache = $options['recognized_post_types'] ?? '';
        $postTypes = $this->f->explodeNewline(is_string($rptValCache) ? $rptValCache : '');
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($this->f->strtolower($postType)) . "', ";
        }
        $recognizedPostTypes = rtrim($recognizedPostTypes, ", ");

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
        $returnValue = json_decode($json);

        return $returnValue;
    }

}
