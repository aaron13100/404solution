<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Index discovery, parsing, verification, and add-index DDL helpers for
 * ABJ_404_Solution_DatabaseUpgradesEtc, plus the small ensureLogs* helpers
 * that gate online DDL on the logsv2 table.
 *
 * Extracted from DatabaseUpgradesEtc.php in 4.1.12 to keep the host class
 * under the FileSizeLimitsTest line budget. No behavior change.
 */
trait ABJ_404_Solution_DatabaseUpgradesEtc_IndexesTrait {

    /** @return void */
    function createIndexes() {
    	foreach ($this->discoverPermanentDDLFiles() as $ddlEntry) {
    		$tableName = $this->dao->doTableNameReplacements($ddlEntry['placeholder']);
    		$query = $this->dao->doTableNameReplacements($ddlEntry['ddlContent']);
    		$this->verifyIndexes($tableName, $query);
    	}
    }

    /**
     * @param string $tableName
     * @param string $createTableStatementGoal
     * @return void
     */
    function verifyIndexes($tableName, $createTableStatementGoal) {

	    	// get the indexes.
	    	// Pattern matches lines starting with "KEY" / "UNIQUE KEY" - handles composite indexes with commas inside parens
	    	// Indexes: treat the CREATE TABLE SQL as source of truth, and treat the database as truth
	    	// for what exists (SHOW INDEX). Avoid parsing SHOW CREATE TABLE output, which is vendor/format dependent.
	    	$goalSpecsByName = $this->parseIndexSpecsFromCreateTableSql($createTableStatementGoal);

	    	$missingIndexNames = [];
	    	foreach (array_keys($goalSpecsByName) as $indexName) {
	    		if (!$this->indexExists($tableName, $indexName)) {
	    			$missingIndexNames[] = $indexName;
	    		}
	    	}

	    	if (count($missingIndexNames) > 0) {
	    		$this->logger->infoMessage(self::$uniqID . ": On {$tableName} I'm adding missing indexes: " . implode(', ', $missingIndexNames));
	    	}

	    	// Get actual columns in the table so we can skip indexes that reference missing columns.
	    	$existingColumns = [];
	    	$showColResult = $this->dao->queryAndGetResults("SHOW COLUMNS FROM " . $tableName);
	    	$showColRows = is_array($showColResult['rows'] ?? null) ? $showColResult['rows'] : [];
	    	foreach ($showColRows as $colRow) {
	    		if (!is_array($colRow)) { continue; }
	    		foreach ($colRow as $key => $value) {
	    			if (strtolower((string)$key) === 'field') {
	    				$existingColumns[] = strtolower((string)$value);
	    				break;
	    			}
	    		}
	    	}

	    	foreach ($missingIndexNames as $indexName) {
	    		$spec = $goalSpecsByName[$indexName] ?? null;
	    		if (empty($spec)) {
	    			continue;
	    		}

	    		// Verify all columns referenced by this index actually exist in the table.
	    		if (!empty($existingColumns)) {
	    			$indexColNames = [];
	    			preg_match_all('/`([^`]+)`/', $spec['columns'], $colMatches);
	    			if (!empty($colMatches[1])) {
	    				$indexColNames = array_map('strtolower', $colMatches[1]);
	    			}
	    			$missingCols = array_diff($indexColNames, $existingColumns);
	    			if (!empty($missingCols)) {
	    				$this->logger->warn("Skipping index {$indexName} on {$tableName}: " .
	    					"column(s) " . implode(', ', $missingCols) . " do not exist in the table.");
	    				continue;
	    			}
	    		}

		    		$spellingCacheTableName = $this->dao->doTableNameReplacements('{wp_abj404_spelling_cache}');
		    		$tableNameLower = strtolower($tableName);
		    		if ($tableNameLower == $spellingCacheTableName && !empty($spec['unique'])) {
		    			$this->dao->deleteSpellingCache();
		    		}

	    		$addStatement = $this->buildAddIndexStatementFromParts($tableName, $spec['name'], $spec['columns'], $spec['unique']);
	    		$this->dao->queryAndGetResults($addStatement);
	    		$this->logger->infoMessage("I added an index: " . $addStatement);
	    	}
	    }

    /**
     * @param string $tableName
     * @param string $indexName
     * @return bool
     */
    private function indexExists($tableName, $indexName) {
        global $wpdb;
        $sql = $wpdb->prepare("SHOW INDEX FROM {$tableName} WHERE Key_name = %s", $indexName);
        // DAO-bypass-approved: indexExists() schema-introspection helper (already prepared); DDL pre-check before ALTER TABLE
        $results = $wpdb->get_results($sql, ARRAY_A);
        return !empty($results);
    }

	    /**
	     * Parse an index DDL line from our CREATE TABLE SQL into a structured spec.
	     *
	     * Accepts forms like:
	     * - KEY `name` (`col`(190), `other`)
	     * - UNIQUE KEY `name` (`col`)
	     * - KEY `name` (`col`) USING BTREE
	     *
	     * Returns null if the line doesn't look like a KEY/UNIQUE KEY definition.
	     *
	     * @param string $indexDDL
	     * @return array{name: string, columns: string, unique: bool}|null
	     */
	    private function parseIndexDDLToSpec($indexDDL) {
	        $indexDDL = trim($indexDDL);
	        // Tolerate a trailing comma — the line-extracting regex pulls each
	        // KEY definition out as-is from the surrounding CREATE TABLE list,
	        // and any KEY that isn't the LAST one will end with a comma. Same
	        // canonical form either way.
	        $indexDDL = rtrim($indexDDL, ',');
	        $matches = [];
	        if (!preg_match('/^(unique\\s+)?key\\s+`?([^`\\s]+)`?\\s*(\\(.+\\))\\s*(?:using\\s+\\w+)?\\s*$/i', $indexDDL, $matches)) {
	            return null;
	        }

	        return [
	            'name' => $matches[2],
	            'columns' => $matches[3],
	            'unique' => !empty($matches[1]),
	        ];
	    }

	    /**
	     * Extract index specs from a CREATE TABLE statement (plugin SQL templates).
	     *
	     * @param string $createTableSql
	     * @return array<string, array{name:string, columns:string, unique:bool}> keyed by index name
	     */
	    private function parseIndexSpecsFromCreateTableSql($createTableSql) {
	        if (!is_string($createTableSql) || $createTableSql === '') {
	            return [];
	        }

	        $matches = [];
	        preg_match_all('/^\\s*(?:unique\\s+)?key\\s+.+?\\s*$/im', $createTableSql, $matches);
	        $lines = $matches[0];

	        $specsByName = [];
	        foreach ($lines as $line) {
	            $spec = $this->parseIndexDDLToSpec($line);
	            if (empty($spec) || empty($spec['name'])) {
	                continue;
	            }
	            $specsByName[$spec['name']] = $spec;
	        }

	        return $specsByName;
	    }

	    /**
	     * Build a valid ALTER TABLE ... ADD INDEX statement from structured parts.
	     *
	     * @param string $tableName
	     * @param string $indexName
	     * @param string $columnsSql Must include surrounding parentheses, e.g. "(`a`, `b`(190))"
	     * @param bool $unique
	     * @return string
	     */
	    private function buildAddIndexStatementFromParts($tableName, $indexName, $columnsSql, $unique) {
	        global $wpdb;
	        /** @var \wpdb $wpdb */
	        $serverVersion = method_exists($wpdb, 'db_version') ? ($wpdb->db_version() ?: '') : '';
	        $serverInfo = property_exists($wpdb, 'db_server_info') ? ($wpdb->db_server_info ?? '') : '';

	        $isMaria = stripos($serverInfo, 'mariadb') !== false || stripos($serverVersion, 'maria') !== false;
	        $cleanedVersion = preg_replace('/[^\d\.]/', '', $serverVersion) ?? '';
	        $supportsIfNotExists = $isMaria && version_compare($cleanedVersion, '10.5', '>=');

	        $indexType = $unique ? 'unique index' : 'index';
	        $ifNotExists = $supportsIfNotExists ? ' if not exists' : '';

	        return "alter table " . $tableName . " add " . $indexType . $ifNotExists . " `" . $indexName . "` " . trim($columnsSql);
	    }

	    /**
	     * @param string $logsTable
	     * @param string|null $createSqlOverride
	     * @return void
	     */
	    private function ensureLogsCompositeIndex($logsTable, $createSqlOverride = null) {
	        $indexName = 'idx_requested_url_timestamp';
	        $createSql = is_string($createSqlOverride) ? $createSqlOverride : ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogTable.sql");
	        $specsByName = $this->parseIndexSpecsFromCreateTableSql($createSql);
	        $spec = $specsByName[$indexName] ?? null;
	        if (empty($spec)) {
	            $this->logger->errorMessage("Failed to add {$indexName} to {$logsTable}: index definition not found in createLogTable.sql");
	            return;
	        }

	        if ($this->indexExists($logsTable, $indexName)) {
	            return;
	        }
	        $query = $this->buildAddIndexStatementFromParts($logsTable, $spec['name'], $spec['columns'], $spec['unique']);
	        $results = $this->dao->queryAndGetResults($query);
        if (!empty($results['last_error'])) {
            $this->logger->errorMessage("Failed to add {$indexName} to {$logsTable}: " . $results['last_error'] . " (query: {$query})");
        } else {
            $this->logger->infoMessage("Added {$indexName} to {$logsTable} using query: {$query}");
        }
    }

	    /**
	     * Add the canonical_url column to logsv2 with online DDL when supported.
	     *
	     * Mirrors ensureLogsCompositeIndex(): a small idempotent helper that runs
	     * ahead of the generic verifyColumns() flow so the column add can use
	     * ALGORITHM=INPLACE, LOCK=NONE on InnoDB ≥ 5.6 (no table lock during the
	     * rewrite). On engines that don't support online DDL for ADD COLUMN the
	     * explicit clause causes the statement to fail with
	     * ER_ALTER_OPERATION_NOT_SUPPORTED; we then fall back to a bare ALTER —
	     * which is what verifyColumns() also runs as the safety net.
	     *
	     * The matching idx_canonical_url is added by the standard verifyIndexes()
	     * flow — index adds use online DDL by default on InnoDB ≥ 5.6 so a
	     * separate ensure helper isn't required for the index.
	     *
	     * @param string $logsTable
	     * @return void
	     */
	    private function ensureLogsv2CanonicalUrlColumn(string $logsTable): void {
	        if ($this->columnExists($logsTable, 'canonical_url')) {
	            return;
	        }
	        $inplaceQuery = "ALTER TABLE " . $logsTable .
	            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL," .
	            " ALGORITHM=INPLACE, LOCK=NONE";
	        $result = $this->dao->queryAndGetResults($inplaceQuery,
	            array('log_too_slow' => false, 'log_errors' => false));
	        if (empty($result['last_error'])) {
	            $this->logger->infoMessage("Added canonical_url to {$logsTable} (ALGORITHM=INPLACE, LOCK=NONE).");
	            return;
	        }
	        // Engine didn't support online DDL for ADD COLUMN — bare ALTER falls
	        // back to whatever algorithm the engine picks (COPY on MyISAM / very
	        // old InnoDB). On modern InnoDB the bare ALTER is itself implicitly
	        // INPLACE for ADD COLUMN ... DEFAULT NULL, so this branch only runs
	        // on legacy engines where some lock is unavoidable.
	        $bareQuery = "ALTER TABLE " . $logsTable .
	            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL";
	        $bare = $this->dao->queryAndGetResults($bareQuery,
	            array('log_too_slow' => false));
	        if (empty($bare['last_error'])) {
	            $this->logger->infoMessage("Added canonical_url to {$logsTable} (bare ALTER fallback).");
	        }
	    }
}
