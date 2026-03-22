<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ABJ_404_Solution_DatabaseUpgradesEtc_MaintenanceTrait {

    /** @return void */
    function updateTableEngineToInnoDB() {
    	// get a list of all tables.
        global $wpdb;
    	$result = $this->dao->getTableEngines();
    	// if any rows are found then update the tables.
    	$resultRows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
    	if (!empty($resultRows)) {
    		foreach ($resultRows as $row) {
    			if (!is_array($row)) {
    				continue;
    			}
    		    $tableName = array_key_exists('table_name', $row) ? (string)$row['table_name'] :
    		      (array_key_exists('TABLE_NAME', $row) ? (string)$row['TABLE_NAME'] : '');
    		    $engine = array_key_exists('engine', $row) ? (string)$row['engine'] :
    		      (array_key_exists('ENGINE', $row) ? (string)$row['ENGINE'] : '');

		        $query = null;
    		    // All plugin tables use InnoDB: crash-safe, no row-count ceiling, no table-level
    		    // locking. The former MyISAM special-case for logsv2 ("OPTIMIZE TABLE is slow
    		    // otherwise") no longer applies — OPTIMIZE TABLE on InnoDB has been equivalent to
    		    // ALTER TABLE ... ENGINE=InnoDB since MySQL 5.6 (rebuilds tablespace in-place).
    		    // InnoDB also eliminates the MyISAM-specific "table is full" failure mode on sites
    		    // with disk pressure (MyISAM .MYI files cannot grow past 4 GiB by default).
                if (strtolower($engine) != 'innodb') {
                    $this->logger->infoMessage("Updating " . $tableName . " to InnoDB.");
                    $query = 'alter table `' . $tableName . '` engine = InnoDB;';
                }

                if ($query == null) {
                    // no updates are necessary for this table.
                    continue;
                }

                $result = $this->dao->queryAndGetResults($query, array("log_errors" => false));
                $this->logger->infoMessage("I changed an engine: " . $query);
                $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';

                if ($lastError !== '' &&
                  strpos($lastError, 'Index column size too large') !== false) {
                    
                    // delete the indexes, try again, and create the indexes later.
                    $this->deleteIndexes($tableName);
                  
                    $this->dao->queryAndGetResults($query,
                      array("ignore_errors" => array("Unknown storage engine")));
                    $this->logger->infoMessage("I tried to change an engine again: " . $query);
                }
    		}
    	}
    }

    /** Retrieve the collation for a given table name.
     * @param string $tableName
     * @return array{0: string, 1: string}|null Array of [collation, charset] or null if retrieval failed.
     */
	function getTableCollation($tableName) {
		// Try SHOW CREATE TABLE first
		$result = $this->getTableCollationFromShowCreate($tableName);

		if ($result !== null) {
			return $result;
		}

		// Fallback to information_schema query
		$result = $this->getTableCollationFromInformationSchema($tableName);

		if ($result !== null) {
			return $result;
		}

		$this->logger->warn("Could not retrieve collation for $tableName from SHOW CREATE TABLE or information_schema.");
		return null;
	}

	/** Parse collation/charset from SHOW CREATE TABLE output.
	 * @param string $tableName
	 * @return array{0: string, 1: string}|null Array of [collation, charset] or null if parsing failed.
	 */
	function getTableCollationFromShowCreate($tableName) {
		$query = "SHOW CREATE TABLE `$tableName`";
		$results = $this->dao->queryAndGetResults($query);

		// Check for query errors or empty results
		if (!empty($results['last_error'])) {
			$this->logger->debugMessage("SHOW CREATE TABLE failed for $tableName: " . $results['last_error']);
			return null;
		}

		$rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : [];
		$firstRow = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
		if ($firstRow === null) {
			$this->logger->debugMessage("SHOW CREATE TABLE returned no data for $tableName.");
			return null;
		}

		// Use array_values to handle varying column name cases ('Create Table', 'CREATE TABLE', etc.)
		// SHOW CREATE TABLE returns: [table_name, create_statement]
		$row = array_values($firstRow);
		if (count($row) < 2 || empty($row[1])) {
			$this->logger->debugMessage("SHOW CREATE TABLE returned unexpected format for $tableName.");
			return null;
		}

		$createTableSQL = $row[1];

		// Match multiple MySQL/MariaDB output formats for charset:
		// - CHARSET=utf8mb4
		// - DEFAULT CHARSET=utf8mb4
		// - CHARACTER SET=utf8mb4
		// - DEFAULT CHARACTER SET=utf8mb4
		// - CHARACTER SET utf8mb4 (no equals sign, space separator)
		// - CHARSET = utf8mb4 (spaces around equals)
		// Note: (?:\s*=\s*|\s+) requires either "=" (with optional spaces) or at least one space
		preg_match('/(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)(?:\s*=\s*|\s+)([\w\d]+)/i', $createTableSQL, $charsetMatch);

		// Match multiple formats for collation:
		// - COLLATE=utf8mb4_unicode_ci
		// - DEFAULT COLLATE=utf8mb4_unicode_ci
		// - COLLATE utf8mb4_unicode_ci (no equals sign, space separator)
		// - COLLATE = utf8mb4_unicode_ci (spaces around equals)
		preg_match('/(?:DEFAULT\s+)?COLLATE(?:\s*=\s*|\s+)([\w\d_]+)/i', $createTableSQL, $collationMatch);

		$charset = $charsetMatch[1] ?? null;
		$collation = $collationMatch[1] ?? null;

		// If we got charset but no explicit collation, derive default collation from charset
		if ($charset && !$collation) {
			$collation = $this->getDefaultCollationForCharset($charset);
		}

		return ($collation && $charset) ? [$collation, $charset] : null;
	}

	/** Query information_schema for table collation (fallback method).
	 * @param string $tableName
	 * @return array{0: string, 1: string}|null Array of [collation, charset] or null if query failed.
	 */
	function getTableCollationFromInformationSchema($tableName) {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT TABLE_COLLATION, " .
			"SUBSTRING_INDEX(TABLE_COLLATION, '_', 1) as TABLE_CHARSET " .
			"FROM information_schema.tables " .
			"WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()",
			$tableName
		);

		$results = $wpdb->get_results($query, ARRAY_A);

		// Check for query errors
		if (!empty($wpdb->last_error)) {
			$this->logger->debugMessage("information_schema query failed for $tableName: " . $wpdb->last_error);
			return null;
		}

		if (empty($results[0])) {
			$this->logger->debugMessage("Table $tableName not found in information_schema (may not exist).");
			return null;
		}

		// Handle case-insensitive column names (some MySQL configs return uppercase)
		$row = array_change_key_case($results[0], CASE_UPPER);
		$collation = $row['TABLE_COLLATION'] ?? null;
		$charset = $row['TABLE_CHARSET'] ?? null;

		if (empty($collation)) {
			return null;
		}

		// Handle edge case where charset extraction might fail
		if (empty($charset)) {
			$charset = explode('_', $collation)[0];
		}

		return [$collation, $charset];
	}

	/** Get the default collation for a given charset.
	 * @param string $charset
	 * @return string|null Default collation or null if unknown.
	 */
		function getDefaultCollationForCharset($charset) {
		// Common charset to default collation mappings
		$defaults = [
			'utf8mb4' => 'utf8mb4_general_ci',
			'utf8' => 'utf8_general_ci',
			'utf8mb3' => 'utf8mb3_general_ci',
			'latin1' => 'latin1_swedish_ci',
			'ascii' => 'ascii_general_ci',
		];

			$charsetLower = strtolower($charset);
			return $defaults[$charsetLower] ?? null;
		}

		/**
		 * Keep collation identifiers SQL-safe.
		 *
		 * @param string $collation
		 * @return string
		 */
		private function sanitizeCollationIdentifier($collation) {
			if (!is_string($collation) || $collation === '') {
				return '';
			}
			return preg_replace('/[^A-Za-z0-9_]/', '', $collation) ?? '';
		}

		/**
		 * Resolve the utf8mb4 collation target for plugin-table normalization.
		 *
		 * Priority:
		 * 1) Active wpdb connection collation if utf8mb4
		 * 2) Most common existing utf8mb4 plugin-table collation
		 * 3) Database default collation variable if utf8mb4
		 * 4) Safe fallback (utf8mb4_unicode_ci)
		 *
		 * @param array<int, string> $tableNames
		 * @param array<string, array{0: string, 1: string}|null> $tableCollations Optional map: table => [collation, charset]
		 * @return string
		 */
		private function resolveTargetUtf8mb4Collation($tableNames, $tableCollations = []) {
			global $wpdb;

			if (!empty($wpdb->collate)) {
				$wpdbCollation = $this->sanitizeCollationIdentifier((string)$wpdb->collate);
				if ($wpdbCollation !== '' && stripos($wpdbCollation, 'utf8mb4') !== false) {
					return $wpdbCollation;
				}
			}

			$counts = [];
			foreach ($tableNames as $tableName) {
				$row = $tableCollations[$tableName] ?? $this->getTableCollation($tableName);
				if (!is_array($row)) {
					continue;
				}
				$collation = $this->sanitizeCollationIdentifier((string)$row[0]);
				$charset = strtolower((string)$row[1]);
				if ($collation !== '' && $charset === 'utf8mb4' && stripos($collation, 'utf8mb4') !== false) {
					$counts[$collation] = ($counts[$collation] ?? 0) + 1;
				}
			}
			if (!empty($counts)) {
				arsort($counts);
				return array_key_first($counts);
			}

			$vars = $this->dao->queryAndGetResults("SHOW VARIABLES LIKE 'collation_database'");
			$varRows = isset($vars['rows']) && is_array($vars['rows']) ? $vars['rows'] : [];
			if (!empty($varRows)) {
				$row = is_array($varRows[0]) ? $varRows[0] : [];
				$value = isset($row['Value']) ? $row['Value'] : (isset($row['value']) ? $row['value'] : '');
				$value = $this->sanitizeCollationIdentifier((string)$value);
				if ($value !== '' && stripos($value, 'utf8mb4') !== false) {
					return $value;
				}
			}

			return 'utf8mb4_unicode_ci';
		}
		
			/**
		 * Ensure our tables use utf8mb4 (do not alter WordPress core tables).
		 * @return void
		 */
			function correctCollations() {
				global $wpdb;
			
			// Discover all plugin tables dynamically so new tables are automatically included.
			// Use queryAndGetResults() so the SHOW TABLES call goes through the same DAO
			// layer as all other queries (enables testability via mock injection).
			// {wp_prefix} is resolved by doTableNameReplacements inside queryAndGetResults.
			$rawResult = $this->dao->queryAndGetResults("SHOW TABLES LIKE '{wp_prefix}abj404_%'");
			$abjTableNames = [];
			if (isset($rawResult['rows']) && is_array($rawResult['rows'])) {
				foreach ($rawResult['rows'] as $row) {
					$abjTableNames[] = is_array($row) ? reset($row) : (string)$row;
				}
			}

				$tableCollations = [];
				foreach ($abjTableNames as $tableName) {
					$tableCollations[$tableName] = $this->getTableCollation($tableName);
				}

				$targetCharset = 'utf8mb4';
				$targetCollation = $this->resolveTargetUtf8mb4Collation($abjTableNames, $tableCollations);
				
				foreach ($abjTableNames as $tableName) {
					$abjTableData = $tableCollations[$tableName] ?? null;
			
					if ($abjTableData === null) {
						$this->logger->warn("Failed to retrieve collation for $tableName.");
					continue;  // Skip this table if collation can't be determined
				}
		
				[$abjTableCollation, $abjTableCharset] = $abjTableData;
		
				$needsUpdate = !($abjTableCharset === $targetCharset && $abjTableCollation === $targetCollation);
				if (!$needsUpdate) {
					// Table default matches, but individual columns can still drift (e.g., some columns left as *_bin).
					$columnMismatch = $this->tableHasMismatchedCharacterColumnCollation($tableName, $targetCharset, $targetCollation);
					if ($columnMismatch === true) {
						$needsUpdate = true;
						$this->logger->infoMessage("Detected column-level collation mismatch on {$tableName}; normalizing to {$targetCharset}/{$targetCollation}");
					} else if ($columnMismatch === null) {
						$this->logger->warn("Could not verify column collations for {$tableName}; skipping collation normalization.");
						continue;
					}
				}
				if (!$needsUpdate) {
					continue;
				}
				
				$this->logger->infoMessage("Updating charset/collation on {$tableName} from {$abjTableCharset}/{$abjTableCollation} to {$targetCharset}/{$targetCollation}");

				$query = "ALTER TABLE {table_name} CONVERT TO CHARSET " . $targetCharset .
						 " COLLATE " . $targetCollation;
				$query = str_replace('{table_name}', $tableName, $query);
				$results = $this->dao->queryAndGetResults($query,
					array('ignore_errors' => array("Index column size too large")));

				$lastErr = isset($results['last_error']) && is_string($results['last_error']) ? $results['last_error'] : '';
			if ($lastErr !== '' &&
					strpos($lastErr, "Index column size too large") !== false) {

					$this->logger->warn("Charset/collation change for $tableName failed: Index column size too large. Deleting indexes and retrying...");

					// delete indexes and try again.
					$this->deleteIndexes($tableName);

					$retryResults = $this->dao->queryAndGetResults($query);
					if (!empty($retryResults['last_error'])) {
						$this->logger->warn("Charset/collation retry for $tableName failed: " . $retryResults['last_error']);
					} else {
						$this->logger->infoMessage("Successfully changed charset/collation of $tableName after retry.");
					}

				} else if (empty($results['last_error'])) {
					$this->logger->infoMessage("Successfully changed charset/collation of $tableName to {$targetCharset}/{$targetCollation}");
				} else {
					$this->logger->warn("Charset/collation change for $tableName failed: " . $results['last_error']);
				}
			}
		}

		/**
		 * Detect character column collation drift on a table.
		 *
		 * Some environments can end up with per-column collations that differ from the table default
		 * (e.g., `utf8mb4_bin` on one VARCHAR column while the table default is `utf8mb4_unicode_520_ci`).
		 * This causes MySQL errors in string operations (REPLACE/LOWER) that mix collations.
		 *
		 * @param string $tableName Fully qualified table name (with prefix)
		 * @param string $targetCharset Expected charset (e.g., utf8mb4)
		 * @param string $targetCollation Expected collation (e.g., utf8mb4_unicode_ci)
		 * @return bool|null True if mismatch found, false if all match, null if query failed
		 */
		private function tableHasMismatchedCharacterColumnCollation($tableName, $targetCharset, $targetCollation) {
			$results = $this->dao->queryAndGetResults("SHOW FULL COLUMNS FROM " . $tableName);
			if (!empty($results['last_error'])) {
				$this->logger->warn("Failed to read columns for {$tableName}: " . $results['last_error']);
				return null;
			}
			/** @var array<int, array<string, mixed>> $rows */
			$rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : [];
			if (empty($rows)) {
				return false;
			}

			$collationKey = null;
			$firstRow = $rows[0];
			foreach (array_keys($firstRow) as $key) {
				if ($this->f->strtolower((string)$key) === 'collation') {
					$collationKey = $key;
					break;
				}
			}
			if ($collationKey === null) {
				$this->logger->warn("SHOW FULL COLUMNS returned no Collation column for {$tableName}");
				return null;
			}

			foreach ($rows as $row) {
				if (!is_array($row)) {
					continue;
				}
				$rawColCollation = $row[$collationKey] ?? null;
				if ($rawColCollation === null || !is_string($rawColCollation) || trim($rawColCollation) === '') {
					continue; // Non-character columns
				}
				$colCollation = trim($rawColCollation);
				$colCharset = explode('_', $colCollation)[0] ?? '';

				if ($colCharset !== $targetCharset || $colCollation !== $targetCollation) {
					return true;
				}
			}

			return false;
		}

    /** @return void */
    public function runDailyInsuranceCheck() {
        // Always verify current site only
        // Per-site cron execution ensures network coverage without O(N²) duplication
        $this->verifyAndRepairCurrentSite();
    }

    /**
     * Verify and repair tables for the current site only.
     *
     * Derives the list of required tables dynamically from create*Table.sql files
     * (same source of truth as runInitialCreateTables()), so new tables are
     * automatically included without any code changes here.
     *
     * If ANY table is missing, triggers full table creation/repair.
     *
     * @return void
     */
    private function verifyAndRepairCurrentSite() {
        global $wpdb;

        // Derive required tables from SQL DDL files — same source of truth as runInitialCreateTables().
        // Adding a new create*Table.sql file automatically includes it here.
        $requiredTables = [];
        $sqlDir = __DIR__ . '/sql';
        $ddlFiles = glob($sqlDir . '/create*Table.sql');
        if (!is_array($ddlFiles)) {
            $ddlFiles = [];
        }
        foreach ($ddlFiles as $ddlFile) {
            if (stripos(basename($ddlFile), 'Temp') !== false) {
                continue;
            }
            // Use file_get_contents() directly to avoid the WordPress-dependent
            // ABJ_404_Solution_Functions::readFileContents() wrapper here.
            $ddlContent = @file_get_contents($ddlFile);
            if ($ddlContent !== false && preg_match('/\{wp_(abj404_\w+)\}/', $ddlContent, $m)) {
                $requiredTables[] = $m[1];
            }
        }

        $missingTables = [];
        $normalizedPrefix = $this->dao->getLowercasePrefix();

        // Check each required table
        foreach ($requiredTables as $tableName) {
            $fullTableName = $this->dao->getPrefixedTableName($tableName);
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$fullTableName}'");

            if (!$tableExists) {
                $missingTables[] = $tableName;
            }
        }

        // If any tables are missing, run repair
        if (!empty($missingTables)) {
            $this->logger->infoMessage(sprintf(
                "Site %d (prefix: %s, normalized: %s) is missing %d table(s): %s. Running repair...",
                get_current_blog_id(),
                $wpdb->prefix,
                $normalizedPrefix,
                count($missingTables),
                implode(', ', $missingTables)
            ));

            // Repair: call the same idempotent routine activation uses
            // This is safe because createDatabaseTables() is idempotent
            $this->createDatabaseTables(false);  // false = not updating to new version

            $this->logger->infoMessage("Table repair complete for site " . get_current_blog_id());
        } else {
            // Tables exist - insurance: verify/correct collations and ensure indexes exist.
            // This catches collation drift (including column-level drift) and missed index additions.
            $this->correctCollations();
            $this->createIndexes();
        }
    }

    /**
     * Clean up expired rate limit transients from wp_options table.
     *
     * WordPress transients are supposed to auto-delete when they expire, but in practice
     * they can accumulate over time. This maintenance task removes expired rate limit
     * transients to prevent wp_options table bloat.
     *
     * Called during daily maintenance cron job.
     *
     * @return array<string, mixed> Statistics: ['deleted' => int, 'errors' => int]
     */
    function cleanupExpiredRateLimitTransients() {
        global $wpdb;

        $this->logger->debugMessage("Cleaning up expired rate limit transients...");

        $stats = ['deleted' => 0, 'errors' => 0];

        // Delete expired rate limit transients
        // WordPress stores transients as two rows: _transient_* and _transient_timeout_*
        // The timeout row contains the expiration timestamp
        // We delete both the value and timeout rows for expired transients

        $currentTime = time();

        // Find all expired rate limit timeout keys
        $query = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_abj404_rate_limit_') . '%',
            $currentTime
        );

        $expiredTimeouts = $wpdb->get_col($query);

        if ($wpdb->last_error) {
            $this->logger->errorMessage("Failed to query for expired rate limit transients: " . $wpdb->last_error);
            return ['deleted' => 0, 'errors' => 1, 'error' => $wpdb->last_error];
        }

        if (!empty($expiredTimeouts)) {
            $this->logger->debugMessage("Found " . count($expiredTimeouts) . " expired rate limit transients to delete.");

            foreach ($expiredTimeouts as $timeoutKey) {
                // Get the corresponding value key (remove '_timeout' from the name)
                $valueKey = str_replace('_transient_timeout_', '_transient_', $timeoutKey);

                // Delete both the timeout and value rows
                $timeoutDeleted = delete_option($timeoutKey);
                $valueDeleted = delete_option($valueKey);

                if ($timeoutDeleted || $valueDeleted) {
                    $stats['deleted']++;
                } else {
                    $stats['errors']++;
                }
            }

            $this->logger->debugMessage("Deleted {$stats['deleted']} expired rate limit transients, {$stats['errors']} errors.");
        } else {
            $this->logger->debugMessage("No expired rate limit transients found.");
        }

        return $stats;
    }

    /**
     * Run all database maintenance tasks.
     *
     * This is the main orchestrator method called by the daily maintenance cron job.
     * It coordinates all database-related maintenance tasks in the proper order.
     *
     * Called by: abj404_dailyMaintenanceCronJobListener() in 404-solution.php
     *
     * @return void
     */
    public function runDatabaseMaintenanceTasks() {
        // Insurance: Verify tables exist (per-site or network-wide based on activation mode)
        // This catches failed activations, database corruption, and edge cases
        $this->runDailyInsuranceCheck();

        // Ngram cache maintenance: sync missing entries and cleanup orphaned ones
        $this->syncMissingNGrams();
        $this->cleanupOrphanedNGrams();

        // Clean up expired rate limit transients to prevent wp_options bloat
        $this->cleanupExpiredRateLimitTransients();

        // Flag redirects whose destination URL is generating 404s (drives redirect suspension)
        ABJ_404_Solution_DataAccess::getInstance()->flagDeadDestinationRedirects();

        // Expire auto-created redirects that exceed the configured age threshold
        ABJ_404_Solution_DataAccess::getInstance()->expireOldAutoRedirects();

        // Nightly internal-link scan: find broken internal links in published content.
        if (class_exists('ABJ_404_Solution_InternalLinkScanner')) {
            $scanner = new ABJ_404_Solution_InternalLinkScanner();
            $scanner->runNightlyScan();
        }
    }
}
