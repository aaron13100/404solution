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
class ABJ_404_Solution_DatabaseUpgradeIndexes extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /** @return void */
    function createIndexes() {
	foreach ($this->upgrades()->bootstrapUpgrade()->discoverPermanentDDLFiles() as $ddlEntry) {
		$tableName = $this->dbCore->doTableNameReplacements($ddlEntry['placeholder']);
		$query = $this->dbCore->doTableNameReplacements($ddlEntry['ddlContent']);
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
		$missingIndexNames = $this->prioritizeMissingIndexNames($missingIndexNames);

		if (count($missingIndexNames) > 0) {
			$this->logger->infoMessage($this->getUpgradeRuntimeId() . ": On {$tableName} I'm adding missing indexes: " . implode(', ', $missingIndexNames));
		}

		// Get actual columns in the table so we can skip indexes that reference missing columns.
		$existingColumns = [];
		$showColResult = $this->dbCore->queryAndGetResults("SHOW COLUMNS FROM " . $tableName);
		$showColRows = is_array($showColResult['rows'] ?? null) ? $showColResult['rows'] : [];
		foreach ($showColRows as $colRow) {
			if (!is_array($colRow)) { continue; }
			foreach ($colRow as $key => $value) {
				if (strtolower((string)$key) === 'field' && is_scalar($value)) {
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

				$spellingCacheTableName = $this->dbCore->doTableNameReplacements('{wp_abj404_spelling_cache}');
				$tableNameLower = strtolower($tableName);
				if ($tableNameLower == $spellingCacheTableName && !empty($spec['unique'])) {
					$this->contentRepo->deleteSpellingCache();
				}

			$this->addIndexWithOnlineFallback($tableName, $spec['name'], $spec['columns'], $spec['unique']);
		}
	    }

	    /**
	     * Add one missing index. Try online/no-lock DDL first; if the server or
	     * storage engine rejects those hints, retry the legacy plain ADD INDEX.
	     *
	     * @param string $tableName
	     * @param string $indexName
	     * @param string $columnsSql
	     * @param bool $unique
	     * @return void
	     */
	    private function addIndexWithOnlineFallback($tableName, $indexName, $columnsSql, $unique): void {
	        $addStatement = $this->buildAddIndexStatementFromParts($tableName, $indexName, $columnsSql, $unique, true);
	        $result = $this->dbCore->queryAndGetResults($addStatement);
	        $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
	        if ($lastError !== '') {
	            $this->logger->warn("Online index add for {$indexName} on {$tableName} failed; retrying without online DDL hints: " .
	                $lastError . " (query: {$addStatement})");
	            $addStatement = $this->buildAddIndexStatementFromParts($tableName, $indexName, $columnsSql, $unique, false);
	            $result = $this->dbCore->queryAndGetResults($addStatement);
	            $lastError = isset($result['last_error']) && is_scalar($result['last_error']) ? (string)$result['last_error'] : '';
	            if ($lastError !== '') {
	                $this->logger->errorMessage("Failed to add index {$indexName} to {$tableName}: " .
	                    $lastError . " (query: {$addStatement})");
	                return;
	            }
	        }
	        $this->logger->infoMessage("I added an index: " . $addStatement);
	    }

	    /**
	     * Put redirect admin-view performance indexes before lower-impact recovery
	     * indexes. Each index is still added by its own ALTER TABLE statement; this
	     * only controls which missing index is attempted first on weak hosts.
	     *
	     * @param array<int, string> $missingIndexNames
	     * @return array<int, string>
	     */
	    private function prioritizeMissingIndexNames(array $missingIndexNames): array {
	        if (count($missingIndexNames) < 2) {
	            return $missingIndexNames;
	        }
	        $originalPosition = array();
	        foreach ($missingIndexNames as $i => $name) {
	            $originalPosition[$name] = $i;
	        }
	        $priority = array_flip(array(
	            'idx_dest_for_view_id',
	            'idx_status_disabled_url_sort_id',
	            'idx_disabled_url_sort_id',
	            'idx_status_disabled_dest_sort_id',
	            'idx_disabled_dest_sort_id',
	            'idx_status_disabled_logshits_id',
	            'idx_disabled_logshits_id',
	            'idx_status_disabled_last_used_id',
	            'idx_disabled_last_used_id',
	            'idx_status_disabled_score_id',
	            'idx_disabled_score_id',
	            'idx_status_disabled',
	            'idx_url_disabled_status',
	            'idx_canonical_url',
	        ));
	        usort($missingIndexNames, function ($a, $b) use ($priority, $originalPosition) {
	            $pa = array_key_exists($a, $priority) ? $priority[$a] : 1000;
	            $pb = array_key_exists($b, $priority) ? $priority[$b] : 1000;
	            if ($pa === $pb) {
	                return ($originalPosition[$a] ?? 0) <=> ($originalPosition[$b] ?? 0);
	            }
	            return $pa <=> $pb;
	        });
	        return $missingIndexNames;
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
	     * @param bool $online Whether to append ALGORITHM=INPLACE, LOCK=NONE.
	     * @return string
	     */
	    private function buildAddIndexStatementFromParts($tableName, $indexName, $columnsSql, $unique, $online = false) {
	        global $wpdb;
	        /** @var \wpdb $wpdb */
	        $serverVersion = is_object($wpdb) && method_exists($wpdb, 'db_version') ? ($wpdb->db_version() ?: '') : '';
	        $serverInfo = is_object($wpdb) && property_exists($wpdb, 'db_server_info') ? ($wpdb->db_server_info ?? '') : '';

	        $isMaria = stripos($serverInfo, 'mariadb') !== false || stripos($serverVersion, 'maria') !== false;
	        $cleanedVersion = preg_replace('/[^\d\.]/', '', $serverVersion) ?? '';
	        $supportsIfNotExists = $isMaria && version_compare($cleanedVersion, '10.5', '>=');

	        $indexType = $unique ? 'unique index' : 'index';
	        $ifNotExists = $supportsIfNotExists ? ' if not exists' : '';
	        $onlineClause = $online ? ', ALGORITHM=INPLACE, LOCK=NONE' : '';

	        return "alter table " . $tableName . " add " . $indexType . $ifNotExists . " `" . $indexName . "` " . trim($columnsSql) . $onlineClause;
	    }

	    /**
	     * @param string $logsTable
	     * @param string|null $createSqlOverride
	     * @return void
	     */
	    public function ensureLogsCompositeIndex($logsTable, $createSqlOverride = null) {
	        $indexName = 'idx_requested_url_timestamp';
	        $createSql = is_string($createSqlOverride) ? $createSqlOverride : ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../../sql/createLogTable.sql");
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
	        $results = $this->dbCore->queryAndGetResults($query);
        $lastError = isset($results['last_error']) && is_scalar($results['last_error'])
            ? (string)$results['last_error']
            : '';
	        if ($lastError !== '') {
	            $this->logger->errorMessage("Failed to add {$indexName} to {$logsTable}: " . $lastError . " (query: {$query})");
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
	    public function ensureLogsv2CanonicalUrlColumn(string $logsTable): void {
	        if ($this->upgrades()->canonicalUrlBackfillUpgrade()->columnExists($logsTable, 'canonical_url')) {
	            return;
	        }
	        $inplaceQuery = "ALTER TABLE " . $logsTable .
	            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL," .
	            " ALGORITHM=INPLACE, LOCK=NONE";
	        $result = $this->dbCore->queryAndGetResults($inplaceQuery,
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
	        $bare = $this->dbCore->queryAndGetResults($bareQuery,
	            array('log_too_slow' => false));
	        if (empty($bare['last_error'])) {
	            $this->logger->infoMessage("Added canonical_url to {$logsTable} (bare ALTER fallback).");
	        }
	    }

	    /**
	     * Add the canonical_url column to the redirects table with online DDL
	     * when supported.
	     *
	     * Sibling of ensureLogsv2CanonicalUrlColumn() applied to the redirects
	     * side. The column shipped in 4.1.11 and is normally added by dbDelta
	     * on plugin update. On hosts where dbDelta silently fails to ALTER ADD
	     * it, every captured-404 INSERT errors out with "Unknown column
	     * 'canonical_url' in 'field list'" until verifyColumns eventually
	     * retries the column add. One site in the May 10 debug zip emitted
	     * 1671 such errors over 10 days on 4.1.12. Calling this helper eagerly
	     * from runInitialCreateTables() shortens that window: every cron tick
	     * that runs the bootstrap loop retries the ALTER on its own,
	     * independent of the verifyColumns DDL diff path.
	     *
	     * @param string $redirectsTable
	     * @return void
	     */
	    public function ensureRedirectsCanonicalUrlColumn(string $redirectsTable): void {
	        if ($this->upgrades()->canonicalUrlBackfillUpgrade()->columnExists($redirectsTable, 'canonical_url')) {
	            return;
	        }
	        $inplaceQuery = "ALTER TABLE " . $redirectsTable .
	            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL," .
	            " ALGORITHM=INPLACE, LOCK=NONE";
	        $result = $this->dbCore->queryAndGetResults($inplaceQuery,
	            array('log_too_slow' => false, 'log_errors' => false));
	        if (empty($result['last_error'])) {
	            $this->logger->infoMessage("Added canonical_url to {$redirectsTable} (ALGORITHM=INPLACE, LOCK=NONE).");
	            return;
	        }
	        $bareQuery = "ALTER TABLE " . $redirectsTable .
	            " ADD COLUMN `canonical_url` VARCHAR(2048) DEFAULT NULL";
	        $bare = $this->dbCore->queryAndGetResults($bareQuery,
	            array('log_too_slow' => false));
	        if (empty($bare['last_error'])) {
	            $this->logger->infoMessage("Added canonical_url to {$redirectsTable} (bare ALTER fallback).");
	        }
	    }

	    /**
	     * The four denormalized derived columns added to the redirects table in
	     * Denorm Step 3a (i459), keyed by column name with the exact column DDL
	     * fragment used in ADD COLUMN. Single source of truth shared by the
	     * targeted online-DDL add here and the backfill component's
	     * column-exists guards. Must stay in sync with createRedirectsTable.sql.
	     *
	     * @var array<string, string>
	     */
	    private const REDIRECTS_DENORM_COLUMN_DDL = array(
	        'logshits'         => '`logshits` BIGINT(20) NOT NULL DEFAULT 0',
	        'last_used'        => '`last_used` BIGINT(20) DEFAULT NULL',
	        'dest_for_view'    => '`dest_for_view` VARCHAR(2048) DEFAULT NULL',
	        'dest_sort_key'    => '`dest_sort_key` VARCHAR(191) DEFAULT NULL',
	        'url_sort_key'     => '`url_sort_key` VARCHAR(191) DEFAULT NULL',
	        'published_status' => '`published_status` TINYINT(4) DEFAULT NULL',
	    );

	    /**
	     * Add the four denormalized derived columns (logshits, last_used,
	     * dest_for_view, published_status) to the redirects table with online
	     * DDL when supported.
	     *
	     * Sibling of {@see ensureRedirectsCanonicalUrlColumn()}: a small
	     * idempotent helper that runs ahead of the generic verifyColumns() flow
	     * so the column adds can use ALGORITHM=INPLACE, LOCK=NONE on InnoDB 5.6
	     * or newer (no table lock during the rewrite; 21K-row redirects tables
	     * add in seconds). Only the columns actually missing are added, so
	     * re-running this on a fully-migrated table is a no-op (each column is
	     * SHOW COLUMNS-guarded per defensive philosophy #1/#7).
	     *
	     * On engines that don't support online DDL for ADD COLUMN the explicit
	     * ALGORITHM clause causes ER_ALTER_OPERATION_NOT_SUPPORTED; we then fall
	     * back to a bare ALTER, which is what verifyColumns() also runs as the
	     * safety net. The derived columns carry sensible defaults (logshits 0;
	     * the rest NULL) so existing rows are valid immediately;
	     * backfillRedirectsDenormColumns() populates the real values across
	     * later cron ticks without ever blocking activation.
	     *
	     * @param string $redirectsTable Fully-qualified redirects table name.
	     * @return void
	     */
	    public function ensureRedirectsDenormColumns(string $redirectsTable): void {
	        $backfill = $this->upgrades()->redirectsDenormBackfillUpgrade();
	        $missingClauses = array();
	        foreach (self::REDIRECTS_DENORM_COLUMN_DDL as $columnName => $columnDdl) {
	            if (!$backfill->columnExists($redirectsTable, $columnName)) {
	                $missingClauses[] = 'ADD COLUMN ' . $columnDdl;
	            }
	        }
	        if (empty($missingClauses)) {
	            return;
	        }

	        $addClause = implode(', ', $missingClauses);
	        $inplaceQuery = "ALTER TABLE " . $redirectsTable . " " . $addClause .
	            ", ALGORITHM=INPLACE, LOCK=NONE";
	        $result = $this->dbCore->queryAndGetResults($inplaceQuery,
	            array('log_too_slow' => false, 'log_errors' => false));
	        if (empty($result['last_error'])) {
	            $this->logger->infoMessage("Added denorm columns to {$redirectsTable} " .
	                "(ALGORITHM=INPLACE, LOCK=NONE): " . $addClause);
	            return;
	        }
	        // Engine didn't support online DDL for ADD COLUMN, fall back to a
	        // bare ALTER, same as verifyColumns() would run. On modern InnoDB the
	        // bare ALTER is itself implicitly INSTANT/INPLACE for ADD COLUMN with
	        // a default, so this branch only runs on legacy engines.
	        $bareQuery = "ALTER TABLE " . $redirectsTable . " " . $addClause;
	        $bare = $this->dbCore->queryAndGetResults($bareQuery,
	            array('log_too_slow' => false));
	        if (empty($bare['last_error'])) {
	            $this->logger->infoMessage("Added denorm columns to {$redirectsTable} " .
	                "(bare ALTER fallback): " . $addClause);
	        }
	    }

}
