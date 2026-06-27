<?php

if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_DatabaseUpgradeOrphanAdoption extends ABJ_404_Solution_DatabaseUpgradeComponent {

	/**
	 * Detect orphaned plugin tables under old prefixes and adopt their data
	 * into the current-prefix tables. Uses slug verification against the logs
	 * table to confirm ownership before adopting.
	 *
	 * @return void
	 */
	public function adoptOrphanedTables(): void {
		global $wpdb;

		$dbNameRaw = $wpdb->dbname ?? '';
		if ($dbNameRaw === '') {
			return;
		}
		// @utf8-audit: opt-out — $wpdb->dbname is set by WordPress at
		// bootstrap from wp-config.php; never user input.
		$dbNameEscaped = esc_sql($dbNameRaw);
		$dbName = is_array($dbNameEscaped) ? '' : $dbNameEscaped;

		// Find all abj404 tables in the database, grouped by prefix.
		$query = "SELECT table_name
			FROM information_schema.tables
			WHERE table_schema = '{$dbName}'
			AND LOWER(table_name) LIKE '%abj404\\_%'";
		$results = $this->dbCore->queryAndGetResults($query);

		if (!is_array($results['rows']) || empty($results['rows'])) {
			return;
		}

		$currentPrefix = $this->dbCore->tableNameResolver()->getLowercasePrefix();

		// Group tables by their prefix (everything before 'abj404_').
		$tablesByPrefix = $this->groupAbj404TablesByPrefix($results['rows']);

		// Skip prefixes we've already adopted.
		$adoptedPrefixes = get_option('abj404_adopted_prefixes', array());
		if (!is_array($adoptedPrefixes)) {
			$adoptedPrefixes = array();
		}

		// Authoritative tenant-isolation boundary: any prefix that belongs to an
		// active multisite blog (a sibling subsite) is NEVER an orphan to adopt,
		// regardless of how well its content happens to match this site. The
		// slug-match heuristic below is only a tie-breaker among genuine
		// migration leftovers, never the authorization boundary.
		$activeBlogPrefixes = $this->getActiveBlogPrefixesLowercase();

		// Process each OLD prefix (not the current one).
		foreach ($tablesByPrefix as $oldPrefix => $tables) {
			$oldPrefix = (string)$oldPrefix;
			if ($oldPrefix === $currentPrefix) {
				continue;
			}
			if (in_array($oldPrefix, $adoptedPrefixes, true)) {
				continue;
			}
			if (in_array($oldPrefix, $activeBlogPrefixes, true)) {
				$this->logger->infoMessage(
					"Orphaned tables under prefix '{$oldPrefix}' belong to an active "
					. "multisite blog (sibling subsite). Skipping adoption to preserve "
					. "tenant isolation."
				);
				continue;
			}

			$this->logger->infoMessage(
				"Found orphaned plugin tables under prefix '{$oldPrefix}' "
				. "(current prefix is '{$currentPrefix}'): " . implode(', ', $tables)
			);

			// Check if old tables have any data at all.
			$totalRows = $this->countOldPrefixRows($oldPrefix, $tables);
			if ($totalRows === 0) {
				$this->logger->infoMessage(
					"Orphaned tables under prefix '{$oldPrefix}' are all empty. Skipping adoption."
				);
				continue;
			}

			// Verify ownership via logs dest_url slug matching.
			$matchResult = $this->verifyOwnershipViaLogs($oldPrefix);

			if ($matchResult === null) {
				// Logs verification returned no data — fall back to redirects post-ID check.
				$matchResult = $this->verifyOwnershipViaRedirects($oldPrefix);
			}

			if ($matchResult !== true) {
				// false = data doesn't match this site; null = insufficient data to verify.
				// Either way, do not adopt — absence of veto is not permission.
				$reason = ($matchResult === false)
					? "Data does not appear to belong to this site."
					: "Insufficient data in logs and redirects to verify ownership.";
				$this->logger->infoMessage(
					"Orphaned tables under prefix '{$oldPrefix}' — skipping adoption. {$reason}"
				);
				continue;
			}

			// Ownership positively verified. Adopt the data.
			$this->adoptDataFromPrefix($oldPrefix, $currentPrefix, $tables);
		}
	}

	/**
	 * Parse information_schema rows into a prefix => [lowercase table name, ...]
	 * map. Each table name is lowercased and grouped by the substring before its
	 * 'abj404_' segment; rows without a recognizable table_name or abj404_
	 * segment are skipped. Extracted from adoptOrphanedTables() so that method's
	 * branching stays within the cyclomatic-complexity budget.
	 *
	 * @param array<array-key, mixed> $rows
	 * @return array<string, array<int, string>>
	 */
	private function groupAbj404TablesByPrefix(array $rows): array {
		$tablesByPrefix = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$tableName = null;
			foreach ($row as $key => $value) {
				if (strtolower((string)$key) === 'table_name' && is_scalar($value)) {
					$tableName = strtolower((string)$value);
					break;
				}
			}
			if ($tableName === null) {
				continue;
			}

			$abj404Pos = strpos($tableName, 'abj404_');
			if ($abj404Pos === false) {
				continue;
			}

			$prefix = substr($tableName, 0, $abj404Pos);
			if (!is_string($prefix)) {
				continue;
			}
			$tablesByPrefix[$prefix][] = $tableName;
		}
		return $tablesByPrefix;
	}

	/**
	 * Count total rows across all known plugin tables for a given prefix.
	 *
	 * @param string $oldPrefix
	 * @param array<int, string> $knownTables  Table names actually found in information_schema.
	 * @return int
	 */
	private function countOldPrefixRows(string $oldPrefix, array $knownTables): int {
		$total = 0;
		foreach ($this->getPluginTableSuffixes() as $suffix) {
			$tableName = $oldPrefix . $suffix;
			if (!in_array($tableName, $knownTables, true)) {
				continue;
			}
			$result = $this->dbCore->queryAndGetResults(
				"SELECT COUNT(*) AS cnt FROM `{$tableName}`",
				['ignore_errors' => ["doesn't exist", "not found"]]
			);
			if (is_array($result['rows']) && !empty($result['rows'])) {
				$row = $result['rows'][0];
				$countValue = is_array($row) ? ($row['cnt'] ?? $row['CNT'] ?? 0) : 0;
				$cnt = is_numeric($countValue) ? (int)$countValue : 0;
				$total += $cnt;
			}
		}
		return $total;
	}

	/**
	 * Verify ownership of orphaned tables by matching logs dest_url against
	 * current site's published post slugs.
	 *
	 * @param string $oldPrefix   The old table prefix.
	 * @return bool|null  true = verified, false = failed, null = no data to verify.
	 */
	private function verifyOwnershipViaLogs(string $oldPrefix): ?bool {
		global $wpdb;
		$logsTable = $oldPrefix . 'abj404_logsv2';
		// WordPress core posts table uses the original $wpdb->prefix (possibly mixed-case),
		// NOT our lowercased prefix. Only plugin tables were renamed to lowercase.
		$postsTable = ($wpdb->prefix ?? 'wp_') . 'posts';

		// Check distinct internal dest_urls against published post slugs.
		$query = "SELECT COUNT(*) AS total,
				SUM(CASE WHEN matched = 1 THEN 1 ELSE 0 END) AS matches
			FROM (
				SELECT DISTINCT dest_url,
					EXISTS(SELECT 1 FROM `{$postsTable}` p
						WHERE p.post_status = 'publish'
						AND LENGTH(p.post_name) >= 3
						AND LOCATE(p.post_name, dest_url) > 0) AS matched
				FROM `{$logsTable}` l
				WHERE dest_url IS NOT NULL
					AND dest_url != ''
					AND dest_url != '404'
					AND dest_url NOT LIKE 'http://%'
					AND dest_url NOT LIKE 'https://%'
				LIMIT 500
			) sub";

		$result = $this->dbCore->queryAndGetResults($query,
			['ignore_errors' => ["doesn't exist", "not found"]]);

		if (!is_array($result['rows']) || empty($result['rows'])) {
			return null;
		}

		$row = is_array($result['rows'][0] ?? null) ? $result['rows'][0] : [];
		$total = 0;
		$matches = 0;
		foreach ($row as $key => $value) {
			$lk = strtolower((string)$key);
			if ($lk === 'total' && is_numeric($value)) { $total = (int)$value; }
			if ($lk === 'matches' && is_numeric($value)) { $matches = (int)$value; }
		}

		if ($total === 0) {
			return null; // No internal dest_urls to verify.
		}

		$matchPct = ($matches / max(1, $total)) * 100;
		$this->logger->infoMessage(
			"Logs ownership verification for prefix '{$oldPrefix}': "
			. "{$matches}/{$total} distinct internal dest_urls match published post slugs "
			. "({$matchPct}%)"
		);

		return $matchPct >= 80;
	}

	/**
	 * Fallback ownership verification using redirects table post-ID existence.
	 * Weaker than slug matching but useful when logs have no internal dest_urls.
	 *
	 * @param string $oldPrefix
	 * @return bool|null  true = verified, false = failed, null = no data.
	 */
	private function verifyOwnershipViaRedirects(string $oldPrefix): ?bool {
		global $wpdb;
		$redirectsTable = $oldPrefix . 'abj404_redirects';
		$postsTable = ($wpdb->prefix ?? 'wp_') . 'posts';

		$query = "SELECT COUNT(*) AS total,
				SUM(CASE WHEN p.ID IS NOT NULL THEN 1 ELSE 0 END) AS matches
			FROM `{$redirectsTable}` r
			LEFT JOIN `{$postsTable}` p
				ON p.ID = CAST(r.final_dest AS UNSIGNED)
				AND p.post_status IN ('publish', 'draft', 'private')
			WHERE r.type IN (1, 2, 3)";

		$result = $this->dbCore->queryAndGetResults($query,
			['ignore_errors' => ["doesn't exist", "not found"]]);

		if (!is_array($result['rows']) || empty($result['rows'])) {
			return null;
		}

		$row = is_array($result['rows'][0] ?? null) ? $result['rows'][0] : [];
		$total = 0;
		$matches = 0;
		foreach ($row as $key => $value) {
			$lk = strtolower((string)$key);
			if ($lk === 'total' && is_numeric($value)) { $total = (int)$value; }
			if ($lk === 'matches' && is_numeric($value)) { $matches = (int)$value; }
		}

		if ($total === 0) {
			return null;
		}

		$matchPct = ($matches / max(1, $total)) * 100;
		$this->logger->infoMessage(
			"Redirects fallback ownership verification for prefix '{$oldPrefix}': "
			. "{$matches}/{$total} type 1/2/3 redirects point to existing posts ({$matchPct}%)"
		);

		return $matchPct >= 80;
	}

	/**
	 * Adopt data from orphaned tables under an old prefix into current-prefix tables.
	 * Uses INSERT IGNORE to avoid duplicate key conflicts.
	 *
	 * @param string $oldPrefix
	 * @param string $currentPrefix
	 * @param array<string> $knownTables
	 * @return void
	 */
	private function adoptDataFromPrefix(string $oldPrefix, string $currentPrefix, array $knownTables): void {
		$this->logger->infoMessage(
			"Beginning adoption of data from prefix '{$oldPrefix}' to '{$currentPrefix}'"
		);

		$totalAdopted = 0;

		foreach ($this->getPluginTableSuffixes() as $suffix) {
			$oldTable = $oldPrefix . $suffix;
			if (!in_array($oldTable, $knownTables, true)) {
				continue;
			}
			$newTable = $currentPrefix . $suffix;

			// Check if old table exists and has rows.
			$countResult = $this->dbCore->queryAndGetResults(
				"SELECT COUNT(*) AS cnt FROM `{$oldTable}`",
				['ignore_errors' => ["doesn't exist", "not found"]]
			);
			if (!is_array($countResult['rows']) || empty($countResult['rows'])) {
				continue;
			}
			$row = $countResult['rows'][0];
			$countValue = is_array($row) ? ($row['cnt'] ?? $row['CNT'] ?? 0) : 0;
			$oldCount = is_numeric($countValue) ? (int)$countValue : 0;
			if ($oldCount === 0) {
				continue;
			}

			// Check if new table exists (it should — auto-repair creates them).
			$newExists = $this->dbCore->queryAndGetResults(
				"SELECT 1 FROM `{$newTable}` LIMIT 1",
				['ignore_errors' => ["doesn't exist", "not found"]]
			);
			if (!empty($newExists['last_error'])) {
				$this->logger->infoMessage(
					"Target table '{$newTable}' does not exist yet. Skipping adoption for '{$suffix}'."
				);
				continue;
			}

			// Build a column-matched INSERT to handle schema drift between old and new tables.
			// Old tables from older plugin versions may have fewer or different columns.
			$commonColumns = $this->getCommonColumns($oldTable, $newTable);
			if (empty($commonColumns)) {
				$this->logger->infoMessage(
					"No common columns found between '{$oldTable}' and '{$newTable}'. Skipping."
				);
				continue;
			}

			$columnList = implode('`, `', $commonColumns);
			$insertQuery = "INSERT IGNORE INTO `{$newTable}` (`{$columnList}`) "
				. "SELECT `{$columnList}` FROM `{$oldTable}`";
			$insertResult = $this->dbCore->queryAndGetResults($insertQuery,
				['ignore_errors' => ["doesn't exist", "not found", "Duplicate"]]);

			$affectedRows = 0;
			if (is_array($insertResult) && isset($insertResult['rows_affected'])) {
				$rawAffected = $insertResult['rows_affected'];
				$affectedRows = is_numeric($rawAffected) ? (int)$rawAffected : 0;
			}

			if ($affectedRows > 0) {
				$totalAdopted += $affectedRows;
				$this->logger->infoMessage(
					"Adopted {$affectedRows} rows from '{$oldTable}' into '{$newTable}'"
				);
			}
		}

		$this->logger->infoMessage(
			"Adoption complete: {$totalAdopted} total rows adopted from prefix '{$oldPrefix}' to '{$currentPrefix}'"
		);

		// Record this prefix as adopted so we don't re-detect it on every page load.
		$adoptedPrefixes = get_option('abj404_adopted_prefixes', array());
		if (!is_array($adoptedPrefixes)) {
			$adoptedPrefixes = array();
		}
		if (!in_array($oldPrefix, $adoptedPrefixes, true)) {
			$adoptedPrefixes[] = $oldPrefix;
			update_option('abj404_adopted_prefixes', $adoptedPrefixes, false);
		}
	}

	/**
	 * Get the list of column names that exist in both tables.
	 * Used by adoptDataFromPrefix() to build column-matched INSERTs
	 * that survive schema drift between plugin versions.
	 *
	 * @param string $tableA
	 * @param string $tableB
	 * @return array<int, string>  Column names present in both tables (lowercase).
	 */
	private function getCommonColumns(string $tableA, string $tableB): array {
		$colsA = $this->getTableColumns($tableA);
		$colsB = $this->getTableColumns($tableB);

		if (empty($colsA) || empty($colsB)) {
			return [];
		}

		return array_values(array_intersect($colsA, $colsB));
	}

	/**
	 * Get column names for a table via SHOW COLUMNS.
	 *
	 * @param string $tableName
	 * @return array<int, string>  Column names (lowercase).
	 */
	private function getTableColumns(string $tableName): array {
		$result = $this->dbCore->queryAndGetResults(
			"SHOW COLUMNS FROM `{$tableName}`",
			['ignore_errors' => ["doesn't exist", "not found"]]
		);

		if (!is_array($result['rows']) || empty($result['rows'])) {
			return [];
		}

		$columns = [];
		$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			// SHOW COLUMNS returns 'Field' key — case-insensitive lookup.
			$colName = null;
			foreach ($row as $key => $value) {
				if (strtolower((string)$key) === 'field' && is_scalar($value)) {
					$colName = strtolower((string)$value);
					break;
				}
			}
			if ($colName !== null) {
				$columns[] = $colName;
			}
		}

		return $columns;
	}

}
