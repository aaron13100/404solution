<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseUpgradesEtcTrait_NGram.php';
require_once __DIR__ . '/DatabaseUpgradesEtcTrait_Maintenance.php';
require_once __DIR__ . '/DatabaseUpgradesEtcTrait_PluginUpdate.php';
require_once __DIR__ . '/DatabaseUpgradesEtcTrait_TableRepair.php';

/* Functions in this class should all reference one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DatabaseUpgradesEtc {

	/** @var self|null */
	private static $instance = null;

	/** @var string|null */
	private static $uniqID = null;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PermalinkCache */
	private $permalinkCache;

	/** @var ABJ_404_Solution_SynchronizationUtils */
	private $syncUtils;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_NGramFilter */
	private $ngramFilter;

	use ABJ_404_Solution_DatabaseUpgradesEtc_NGramTrait;
	use ABJ_404_Solution_DatabaseUpgradesEtc_MaintenanceTrait;
	use ABJ_404_Solution_DatabaseUpgradesEtc_PluginUpdateTrait;
	use ABJ_404_Solution_DatabaseUpgradesEtc_TableRepairTrait;

	/**
	 * Constructor with dependency injection.
	 *
	 * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 * @param ABJ_404_Solution_Functions|null $functions String utilities
	 * @param ABJ_404_Solution_PermalinkCache|null $permalinkCache Permalink cache service
	 * @param ABJ_404_Solution_SynchronizationUtils|null $syncUtils Sync utilities
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
	 * @param ABJ_404_Solution_NGramFilter|null $ngramFilter N-gram filter service
	 */
	public function __construct($dataAccess = null, $logging = null, $functions = null, $permalinkCache = null, $syncUtils = null, $pluginLogic = null, $ngramFilter = null) {
		// Use injected dependencies or fall back to getInstance() for backward compatibility
		$this->dao = $dataAccess !== null ? $dataAccess : abj_service('data_access');
		$this->logger = $logging !== null ? $logging : abj_service('logging');
		$this->f = $functions !== null ? $functions : abj_service('functions');
		$this->permalinkCache = $permalinkCache !== null ? $permalinkCache : abj_service('permalink_cache');
		$this->syncUtils = $syncUtils !== null ? $syncUtils : abj_service('sync_utils');
		$this->logic = $pluginLogic !== null ? $pluginLogic : abj_service('plugin_logic');
		$this->ngramFilter = $ngramFilter !== null ? $ngramFilter : abj_service('ngram_filter');
	}

	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_DatabaseUpgradesEtc();
			self::$uniqID = uniqid("", true);
		}

		return self::$instance;
	}
	
	/** Create the tables when the plugin is first activated.
     * @param bool $updatingToNewVersion
     * @return void
     */
    function createDatabaseTables($updatingToNewVersion = false, bool $force = false) {

    	$synchronizedKeyFromUser = "create_db_tables";
    	$uniqueID = null;

    	if (!$force) {
    		$uniqueID = $this->syncUtils->synchronizerAcquireLockTry($synchronizedKeyFromUser);

    		if ($uniqueID == '' || $uniqueID == null) {
    			$this->logger->debugMessage("Avoiding multiple calls for creating database tables.");
    			return;
    		}
    	}

    	// Fixed: Use finally block to ensure lock is ALWAYS released, even on fatal errors
    	try {
    		$this->reallyCreateDatabaseTables($updatingToNewVersion);

    	} catch (\Exception $e) {
    		$this->logger->errorMessage("Error creating database tables. ", $e);
    		throw $e;  // Re-throw to propagate the error
    	} finally {
    		// Release the lock only if one was acquired (non-forced path).
    		if ($uniqueID !== null && $uniqueID !== '') {
    			$this->syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
    		}
    	}
    }
    
    /**
     * @param bool $updatingToNewVersion
     * @return void
     */
    private function reallyCreateDatabaseTables($updatingToNewVersion = false) {
    	if ($updatingToNewVersion) {
    		$this->correctIssuesBefore();
    	}

    	// MULTISITE: Process current site immediately, schedule background task for remaining sites
    	if ($this->isNetworkActivated() && !$updatingToNewVersion) {
    		// Activation path: create tables for current site + schedule background for others.
    		$currentBlogId = get_current_blog_id();
    		$this->runInitialCreateTables();
    		$this->correctCollations();
    		$this->updateTableEngineToInnoDB();
    		$this->createIndexes();

    		// First chunk of the canonical_url backfill runs in-band so newly
    		// upgraded small sites finish in one shot. Larger sites converge
    		// over subsequent daily-maintenance cron ticks (same method).
    		$this->backfillRedirectsCanonicalUrl();

    		$this->logger->infoMessage(sprintf(
    			"Network activation: Created tables for current site (ID %d). Scheduling background task for remaining sites.",
    			$currentBlogId
    		));

    		$this->scheduleBackgroundMultisiteActivation($currentBlogId);

    	} else if ($this->isNetworkActivated() && $updatingToNewVersion) {
    		// Upgrade path on a network install: update tables for current site + schedule
    		// background upgrade for other sites (so sub-site tables are also updated).
    		$currentBlogId = get_current_blog_id();
    		$this->runInitialCreateTables();
    		$this->correctCollations();
    		$this->updateTableEngineToInnoDB();
    		$this->createIndexes();

    		// First chunk of the canonical_url backfill runs in-band so newly
    		// upgraded small sites finish in one shot. Larger sites converge
    		// over subsequent daily-maintenance cron ticks (same method).
    		$this->backfillRedirectsCanonicalUrl();

    		$this->logger->infoMessage(sprintf(
    			"Network upgrade: Updated tables for current site (ID %d). Scheduling background upgrade for remaining sites.",
    			$currentBlogId
    		));

    		$this->scheduleBackgroundMultisiteUpgrade($currentBlogId);

    	} else {
    		// Single site (or non-network-activated): create/update tables for current site only.
    		$this->runInitialCreateTables();
    		$this->correctCollations();
    		$this->updateTableEngineToInnoDB();
    		$this->createIndexes();

    		// First chunk of the canonical_url backfill runs in-band so newly
    		// upgraded small sites finish in one shot. Larger sites converge
    		// over subsequent daily-maintenance cron ticks (same method).
    		$this->backfillRedirectsCanonicalUrl();
    	}

    	// Adopt orphaned tables AFTER target tables exist (rename handles prefix mismatches).
    	$this->renameAbj404TablesToLowerCase();

    	// we could do this only when a table is created or when the "meta" column is created
    	// but it doesn't take long anyway so we do it every night.
    	$this->permalinkCache->updatePermalinkCache(1);

    	// One-time N-gram cache initialization (async via WP-Cron to prevent blocking)
    	// MULTISITE: Use network-aware option getter to check initialization status
    	if ($this->getNetworkAwareOption('abj404_ngram_cache_initialized') !== '1') {
    		$this->logger->debugMessage("N-gram cache not initialized. Scheduling background build...");

    		// Schedule async rebuild via WP-Cron instead of blocking activation
    		$this->scheduleNGramCacheRebuild();

    		// Show admin notice that build is scheduled
    		if ($updatingToNewVersion && function_exists('add_settings_error')) {
    			$context = is_multisite() && $this->isNetworkActivated() ? ' across all sites in the network' : '';
    			$message = sprintf(
    				__('404 Solution: N-gram spell check cache is being built in the background%s to optimize performance. This may take a few minutes on large sites.', '404-solution'),
    				$context
    			);
    			add_settings_error('abj404_settings', 'ngram_cache_scheduled', $message, 'updated');
    		}

    		$this->logger->infoMessage("N-gram cache rebuild scheduled via WP-Cron.");
    	} else {
    		$this->logger->debugMessage("N-gram cache already initialized. Skipping rebuild.");
    	}

    	// Run one-time migration to relative paths (Issue #24)
    	if (get_option('abj404_migrated_to_relative_paths') !== '1') {
    		$migrationResults = $this->migrateURLsToRelativePaths();

    		// Show admin notice if migration occurred
    		if ($updatingToNewVersion && !empty($migrationResults['redirects_updated'])) {
    			$rawRedirectsUpdated = $migrationResults['redirects_updated'];
    			$redirectsUpdated = is_scalar($rawRedirectsUpdated) ? (int)$rawRedirectsUpdated : 0;
    			$message = sprintf(
    				_n(
    					'404 Solution: Migrated %d redirect to subdirectory-independent format.',
    					'404 Solution: Migrated %d redirects to subdirectory-independent format.',
    					$redirectsUpdated,
    					'404-solution'
    				),
    				$redirectsUpdated
    			);
    			if (function_exists('add_settings_error')) {
    				add_settings_error('abj404_settings', 'migration_success', $message, 'updated');
    			}
    		}
    	}

    	if ($updatingToNewVersion) {
    		$this->correctIssuesAfter();
    	}
    }

    /**
     * Makes all plugin table names lowercase, in case someone thought it was funny to use
	 * the lower_case_table_names=0 setting. Also detects and adopts orphaned plugin tables
	 * under old prefixes (from site migrations or the rename bug in v2.35.16–v3.x).
     * @return void
     */
	function renameAbj404TablesToLowerCase() {
		global $wpdb;

		// On case-insensitive MySQL (lower_case_table_names >= 1), table names
		// are already treated as lowercase internally. Renaming is pointless and
		// can cause issues on some hosting setups.
		// DAO-bypass-approved: Schema-bootstrap inside renameAbj404TablesToLowerCase() — runs before plugin DAO is fully wired during DB upgrades
		$lctnResult = $wpdb->get_row("SHOW VARIABLES LIKE 'lower_case_table_names'", ARRAY_A);
		if (is_array($lctnResult)) {
			$lctnValue = null;
			foreach ($lctnResult as $key => $value) {
				if (strtolower((string)$key) === 'value') {
					$lctnValue = $value;
					break;
				}
			}
			if ($lctnValue !== null && (int)$lctnValue >= 1) {
				// MySQL already handles table names case-insensitively.
				// Still run adoption check in case of prefix mismatch.
				$this->adoptOrphanedTables();
				return;
			}
		}

		// Fetch all tables containing "abj404", case-insensitive
		$dbNameRaw = $wpdb->dbname ?? '';
		if ($dbNameRaw === '') {
			$this->logger->warn("Could not determine database name for lowercase rename.");
			return;
		}
		$dbNameEscaped = esc_sql($dbNameRaw);
		$dbName = is_array($dbNameEscaped) ? '' : $dbNameEscaped;
		$query = "SELECT table_name
			FROM information_schema.tables
			WHERE table_schema = '{$dbName}'
			AND LOWER(table_name) LIKE '%abj404%'";
		$results = $this->dao->queryAndGetResults($query);

		if (!is_array($results['rows'])) {
			$this->logger->warn("Could not query information_schema tables for lowercase rename.");
			return;
		}

		foreach ($results['rows'] as $row) {
			// Case-insensitive key lookup: MySQL drivers return information_schema
			// column names in varying cases (table_name, TABLE_NAME, Table_Name).
			$tableName = null;
			foreach ($row as $key => $value) {
				if (strtolower((string)$key) === 'table_name') {
					$tableName = $value;
					break;
				}
			}

			if (!empty($tableName)) {
				$lowercaseName = strtolower($tableName);

				// Check if the table name is already lowercase, skip if it is
				if ($tableName !== $lowercaseName) {
					// Rename the table to lowercase
					$renameQuery = "RENAME TABLE `{$tableName}` TO `{$lowercaseName}`";
					$this->dao->queryAndGetResults($renameQuery,
						['ignore_errors' => ["already exists"]]);
					$this->logger->infoMessage("Renamed table {$tableName} to {$lowercaseName}\n");
				}
			} else {
				$this->logger->warn("I didn't find a table name in the results of this row: " .
					print_r($row, true));
			}
		}

		// After renaming, check for orphaned tables under old prefixes.
		$this->adoptOrphanedTables();
	}

	/**
	 * Number of rows updated per chunk by backfillRedirectsCanonicalUrl().
	 * Sized so a single chunk completes well under the standard 60s query
	 * timeout even on slow disks; the chunk loop will keep going until the
	 * per-invocation budget is exhausted.
	 *
	 * Defined here (not on the trait) because trait constants require PHP 8.2+
	 * and the plugin supports PHP 7.4. The trait references this via self::
	 * which resolves to the using class at compile time.
	 */
	const CANONICAL_URL_BACKFILL_CHUNK_SIZE = 5000;

	/**
	 * Per-invocation wall-clock budget (seconds) for backfillRedirectsCanonicalUrl().
	 * Bounds how long the daily cron / activation handler will spend on this
	 * task in one call so a 350K-row site finishes over a few cron ticks
	 * instead of all in one request that risks PHP max_execution_time.
	 */
	const CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC = 25;

	/**
	 * Known plugin table suffixes for adoption.
	 * @var array<int, string>
	 */
	private const PLUGIN_TABLE_SUFFIXES = [
		'abj404_redirects',
		'abj404_logsv2',
		'abj404_spelling_cache',
		'abj404_permalink_cache',
		'abj404_lookup',
		'abj404_ngram_cache',
		'abj404_logs_hits',
		'abj404_redirect_conditions',
		'abj404_engine_profiles',
		'abj404_view_cache',
	];

	/**
	 * Detect orphaned plugin tables under old prefixes and adopt their data
	 * into the current-prefix tables. Uses slug verification against the logs
	 * table to confirm ownership before adopting.
	 *
	 * @return void
	 */
	private function adoptOrphanedTables(): void {
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
		$results = $this->dao->queryAndGetResults($query);

		if (!is_array($results['rows']) || empty($results['rows'])) {
			return;
		}

		$currentPrefix = $this->dao->getLowercasePrefix();

		// Group tables by their prefix (everything before 'abj404_').
		/** @var array<string, array<string>> prefix => [table_name, ...] */
		$tablesByPrefix = [];
		foreach ($results['rows'] as $row) {
			$tableName = null;
			foreach ($row as $key => $value) {
				if (strtolower((string)$key) === 'table_name') {
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
			$tablesByPrefix[$prefix][] = $tableName;
		}

		// Skip prefixes we've already adopted.
		$adoptedPrefixes = get_option('abj404_adopted_prefixes', array());
		if (!is_array($adoptedPrefixes)) {
			$adoptedPrefixes = array();
		}

		// Process each OLD prefix (not the current one).
		foreach ($tablesByPrefix as $oldPrefix => $tables) {
			if ($oldPrefix === $currentPrefix) {
				continue;
			}
			if (in_array($oldPrefix, $adoptedPrefixes, true)) {
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

			// Ownership positively verified — adopt the data.
			$this->adoptDataFromPrefix($oldPrefix, $currentPrefix, $tables);
		}
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
		foreach (self::PLUGIN_TABLE_SUFFIXES as $suffix) {
			$tableName = $oldPrefix . $suffix;
			if (!in_array($tableName, $knownTables, true)) {
				continue;
			}
			$result = $this->dao->queryAndGetResults(
				"SELECT COUNT(*) AS cnt FROM `{$tableName}`",
				['ignore_errors' => ["doesn't exist", "not found"]]
			);
			if (is_array($result['rows']) && !empty($result['rows'])) {
				$row = $result['rows'][0];
				$cnt = is_array($row) ? (int)($row['cnt'] ?? $row['CNT'] ?? 0) : 0;
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

		$result = $this->dao->queryAndGetResults($query,
			['ignore_errors' => ["doesn't exist", "not found"]]);

		if (!is_array($result['rows']) || empty($result['rows'])) {
			return null;
		}

		$row = $result['rows'][0];
		$total = 0;
		$matches = 0;
		foreach ($row as $key => $value) {
			$lk = strtolower((string)$key);
			if ($lk === 'total') { $total = (int)$value; }
			if ($lk === 'matches') { $matches = (int)$value; }
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

		$result = $this->dao->queryAndGetResults($query,
			['ignore_errors' => ["doesn't exist", "not found"]]);

		if (!is_array($result['rows']) || empty($result['rows'])) {
			return null;
		}

		$row = $result['rows'][0];
		$total = 0;
		$matches = 0;
		foreach ($row as $key => $value) {
			$lk = strtolower((string)$key);
			if ($lk === 'total') { $total = (int)$value; }
			if ($lk === 'matches') { $matches = (int)$value; }
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

		foreach (self::PLUGIN_TABLE_SUFFIXES as $suffix) {
			$oldTable = $oldPrefix . $suffix;
			if (!in_array($oldTable, $knownTables, true)) {
				continue;
			}
			$newTable = $currentPrefix . $suffix;

			// Check if old table exists and has rows.
			$countResult = $this->dao->queryAndGetResults(
				"SELECT COUNT(*) AS cnt FROM `{$oldTable}`",
				['ignore_errors' => ["doesn't exist", "not found"]]
			);
			if (!is_array($countResult['rows']) || empty($countResult['rows'])) {
				continue;
			}
			$row = $countResult['rows'][0];
			$oldCount = is_array($row) ? (int)($row['cnt'] ?? $row['CNT'] ?? 0) : 0;
			if ($oldCount === 0) {
				continue;
			}

			// Check if new table exists (it should — auto-repair creates them).
			$newExists = $this->dao->queryAndGetResults(
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
			$insertResult = $this->dao->queryAndGetResults($insertQuery,
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
		$result = $this->dao->queryAndGetResults(
			"SHOW COLUMNS FROM `{$tableName}`",
			['ignore_errors' => ["doesn't exist", "not found"]]
		);

		if (!is_array($result['rows']) || empty($result['rows'])) {
			return [];
		}

		$columns = [];
		foreach ($result['rows'] as $row) {
			// SHOW COLUMNS returns 'Field' key — case-insensitive lookup.
			$colName = null;
			foreach ($row as $key => $value) {
				if (strtolower((string)$key) === 'field') {
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

	/** When certain columns are created we have to populate data.
     * @param string $tableName
     * @param string $colName
     * @return void
     */
	    function handleSpecificCases($tableName, $colName) {
	    	if (empty($tableName) || !is_string($tableName)) {
	    		return;
	    	}

	    	if (strpos($tableName, 'abj404_logsv2') !== false && $colName == 'min_log_id') {
	    		global $wpdb;
	    		$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/logsSetMinLogID.sql");
	    		$this->dao->queryAndGetResults($query);
            // Ensure composite index exists after backfilling min_log_id.
            $this->ensureLogsCompositeIndex($tableName);
    	}
    	if (strpos($tableName, 'abj404_permalink_cache') !== false && $colName == 'url_length') {
    		// clear the permalink cache so that the url length column will be populated.
    		// this could be more efficient but I'll assume that's not necessary.
    		$this->dao->truncatePermalinkCacheTable();
    	}
    }
    
	    /**
	     * Discover all permanent (non-Temp) DDL files and extract table metadata.
	     *
	     * @return array<int, array{placeholder: string, bareTableName: string, ddlContent: string}>
	     */
	    function discoverPermanentDDLFiles(): array {
	    	$sqlDir = __DIR__ . '/sql';
	    	$files = glob($sqlDir . '/create*Table.sql');
	    	if (!is_array($files)) {
	    		$files = [];
	    	}
	    	sort($files);

	    	$result = [];
	    	foreach ($files as $file) {
	    		if (stripos(basename($file), 'Temp') !== false) {
	    			continue;
	    		}
	    		$ddlContent = ABJ_404_Solution_Functions::readFileContents($file);
	    		if (!is_string($ddlContent) || trim($ddlContent) === '') {
	    			continue;
	    		}
	    		if (!preg_match('/\{(wp_(abj404_\w+))\}/', $ddlContent, $m)) {
	    			continue;
	    		}
	    		$result[] = [
	    			'placeholder' => '{' . $m[1] . '}',
	    			'bareTableName' => $m[2],
	    			'ddlContent' => $ddlContent,
	    		];
	    	}
	    	return $result;
	    }

	    /** @return void */
	    function runInitialCreateTables() {
	    	// Drop stripped tables BEFORE any CREATE TABLE IF NOT EXISTS runs.  Without
	    	// this, an existing-but-broken table (missing the file's `id` PRIMARY KEY)
	    	// would survive the IF NOT EXISTS check and verifyColumns would only ALTER
	    	// ADD the missing non-PK columns — the auto_increment PK can never be
	    	// retro-added by ALTER, leaving the table permanently broken.  Previously
	    	// this lived only in correctIssuesBefore() (upgrade-flow only), so cron
	    	// callers like deleteOldRedirectsCron's createDatabaseTables() (no $updatingToNewVersion
	    	// flag) silently propagated the broken state.
	    	$this->repairStrippedViewCacheTable();

	    	foreach ($this->discoverPermanentDDLFiles() as $ddlEntry) {
	    		$query = $this->applyPluginTableCharsetCollate($ddlEntry['ddlContent']);
	    		$this->dao->queryAndGetResults($query);

	    		$tableName = $this->dao->doTableNameReplacements($ddlEntry['placeholder']);

	    		// Per-table post-CREATE verification: confirm the table actually
	    		// exists on disk. queryAndGetResults logs SQL errors generically,
	    		// but a silently-failing CREATE (concurrent DROP, swallowed parse
	    		// error, prefix drift, or insufficient privileges) is invisible
	    		// without an explicit existence check. Log per-table so the debug
	    		// log identifies which DDL didn't materialize and why downstream
	    		// auto-repair attempts will keep failing.
	    		if (!$this->verifyTableMaterialized($tableName, $ddlEntry['placeholder'])) {
	    			// Don't abort the loop — other tables can still get created.
	    			continue;
	    		}

	    		$this->verifyColumns($tableName, $query);
	    	}

	    	// Table-specific post-creation steps.
	    	$logsTable = $this->dao->doTableNameReplacements("{wp_abj404_logsv2}");
	    	$this->ensureLogsCompositeIndex($logsTable);

	    	// Mark view cache table as ensured so ensureViewSnapshotTableExists() skips redundant DDL.
	    	ABJ_404_Solution_DataAccess::setViewSnapshotTableEnsured(true);
	    }

	    /**
	     * Verify that a CREATE TABLE actually materialized the named table on disk.
	     * Returns true if the table exists, false (and logs a per-table error) if not.
	     *
	     * Distinguishes silently-failing CREATEs from generic SQL errors so the
	     * debug log identifies which specific DDL didn't materialize. Common causes:
	     * concurrent DROP from a parallel cron, SQL parse error swallowed by
	     * queryAndGetResults, prefix drift between request and table_prefix in
	     * wp-config, or missing CREATE TABLE privileges on the DB user.
	     *
	     * @param string $tableName  Fully-qualified table name (with prefix).
	     * @param string $placeholder Original placeholder (e.g. "{wp_abj404_redirects}") for diagnostic context.
	     * @return bool True if table exists post-CREATE, false otherwise.
	     */
	    private function verifyTableMaterialized(string $tableName, string $placeholder): bool {
	    	global $wpdb;
	    	if (!isset($wpdb)) {
	    		return false;
	    	}
	    	// @utf8-audit: opt-out — $tableName is fully-qualified plugin table
	    	// name from doTableNameReplacements / $wpdb->prefix; never user input.
	    	// DAO-bypass-approved: Schema-bootstrap inside verifyTableMaterialized() — verifies CREATE TABLE actually materialized; DAO timeout wrapper is irrelevant for DDL existence probe
	    	$found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tableName) . "'");
	    	if ($found === $tableName) {
	    		return true;
	    	}
	    	$this->logger->errorMessage(
	    		"CREATE TABLE did not materialize '" . $tableName . "' "
	    		. "(placeholder " . $placeholder . "). "
	    		. "Table is still missing on disk after CREATE TABLE IF NOT EXISTS ran. "
	    		. "Likely causes: concurrent DROP from a parallel request, "
	    		. "SQL parse error suppressed by queryAndGetResults, "
	    		. "prefix mismatch between request and wp-config table_prefix, "
	    		. "or insufficient CREATE TABLE privileges on the DB user."
	    	);
	    	return false;
	    }

	    /**
	     * @param string $createTableSql
	     * @return string
	     */
	    function applyPluginTableCharsetCollate($createTableSql) {
	    	global $wpdb;
	    	if (!is_string($createTableSql) || $createTableSql === '') {
	    		return $createTableSql;
	    	}
	    	// If the statement already specifies charset/collation, don't override.
	    	if (preg_match('/\b(?:default\s+)?(?:character\s+set|charset|collate)\b/i', $createTableSql)) {
	    		return $createTableSql;
	    	}
	    	
	    	// Always prefer utf8mb4 for plugin tables, regardless of site defaults.
	    	$collate = 'utf8mb4_unicode_ci';
	    	if (!empty($wpdb->collate) && stripos($wpdb->collate, 'utf8mb4') !== false) {
	    		$collate = $wpdb->collate;
	    	}
	    	
	    	return rtrim($createTableSql) . " DEFAULT CHARACTER SET utf8mb4 COLLATE {$collate}";
	    }

    /**
     * Schedule a background multisite batch operation.
     *
     * @param string $optionPrefix e.g. 'abj404_activation' or 'abj404_upgrade'
     * @param string $hookName     e.g. 'abj404_network_activation_background'
     * @param string $label        Human-readable label for log messages, e.g. 'activation'
     * @param int $alreadyProcessedBlogId Blog ID already processed on this request.
     * @return void
     */
    private function scheduleBackgroundMultisiteBatch(string $optionPrefix, string $hookName, string $label, int $alreadyProcessedBlogId): void {
        update_site_option($optionPrefix . '_processed_blogs', array($alreadyProcessedBlogId));
        update_site_option($optionPrefix . '_in_progress', true);

        if (wp_next_scheduled($hookName)) {
            $this->logger->debugMessage("Background multisite $label already scheduled.");
            return;
        }

        $scheduled = wp_schedule_single_event(time() + 30, $hookName);

        if ($scheduled === false) {
            $this->logger->errorMessage("Failed to schedule background multisite $label. Remaining sites will not be processed automatically.");
        } else {
            $this->logger->infoMessage("Background multisite $label scheduled successfully.");
        }
    }

    /**
     * Process a batch of multisite sites with the given per-site action.
     *
     * @param string $optionPrefix e.g. 'abj404_activation' or 'abj404_upgrade'
     * @param string $hookName     e.g. 'abj404_network_activation_background'
     * @param string $label        Human-readable label for log messages, e.g. 'activation'
     * @param callable $perSiteAction Called for each site (receives int $siteId).
     * @return bool True if all sites are done, false if more batches needed.
     */
    public function processMultisiteBatch(string $optionPrefix, string $hookName, string $label, callable $perSiteAction): bool {
        $processedBlogs = get_site_option($optionPrefix . '_processed_blogs', array());
        if (!is_array($processedBlogs)) {
            $processedBlogs = array();
        }

        $allSites = get_sites(array('fields' => 'ids', 'number' => 0));
        $remainingSites = array_diff($allSites, $processedBlogs);

        if (empty($remainingSites)) {
            delete_site_option($optionPrefix . '_processed_blogs');
            delete_site_option($optionPrefix . '_in_progress');
            $this->logger->infoMessage("Background multisite $label complete. All sites processed.");
            return true;
        }

        $batchSize = 10;
        $sitesToProcess = array_slice($remainingSites, 0, $batchSize);

        $this->logger->infoMessage(sprintf(
            "Processing multisite $label batch: %d sites (of %d remaining)",
            count($sitesToProcess),
            count($remainingSites)
        ));

        foreach ($sitesToProcess as $siteId) {
            try {
                switch_to_blog($siteId);
                $this->logger->debugMessage(sprintf("Processing $label for site ID %d...", $siteId));

                $perSiteAction((int)$siteId);

                $processedBlogs[] = $siteId;
                update_site_option($optionPrefix . '_processed_blogs', $processedBlogs);

                $this->logger->debugMessage(sprintf("Successfully processed $label for site ID %d", $siteId));
            } catch (Throwable $e) {
                $this->logger->errorMessage(sprintf(
                    "Failed to process $label for site ID %d: %s",
                    $siteId,
                    $e->getMessage()
                ));
                $processedBlogs[] = $siteId;
                update_site_option($optionPrefix . '_processed_blogs', $processedBlogs);
            } finally {
                restore_current_blog();
            }
        }

        $stillRemaining = count($remainingSites) - count($sitesToProcess);
        if ($stillRemaining > 0) {
            $this->logger->infoMessage(sprintf(
                "Batch complete. Rescheduling for %d remaining sites.",
                $stillRemaining
            ));
            wp_schedule_single_event(time() + 30, $hookName);
            return false;
        } else {
            delete_site_option($optionPrefix . '_processed_blogs');
            delete_site_option($optionPrefix . '_in_progress');
            $this->logger->infoMessage("Background multisite $label complete. All sites processed.");
            return true;
        }
    }

    /**
     * Schedule a background activation for all network sites except the one that
     * was just activated synchronously.
     *
     * @param int $alreadyProcessedBlogId Blog ID of the site already activated.
     * @return void
     */
    private function scheduleBackgroundMultisiteActivation(int $alreadyProcessedBlogId): void {
        $this->scheduleBackgroundMultisiteBatch(
            'abj404_activation', 'abj404_network_activation_background', 'activation', $alreadyProcessedBlogId
        );
    }

    /**
     * Process multisite activation in batches (called by WP-Cron).
     *
     * Processes remaining sites that weren't handled during initial activation.
     * Processes up to 10 sites per run to avoid timeouts, then reschedules itself
     * if more sites remain.
     *
     * @return bool True if all sites processed, false if more remain
     */
    public function processMultisiteActivationBatch(): bool {
        return $this->processMultisiteBatch(
            'abj404_activation',
            'abj404_network_activation_background',
            'activation',
            function (int $siteId): void {
                add_option('abj404_settings', '', '', false);

                $this->runInitialCreateTables();
                $this->correctCollations();
                $this->updateTableEngineToInnoDB();
                $this->createIndexes();
                $this->backfillRedirectsCanonicalUrl();
                $this->renameAbj404TablesToLowerCase();

                ABJ_404_Solution_PluginLogic::doRegisterCrons();

                $logic = abj_service('plugin_logic');
                $logic->doUpdateDBVersionOption();
            }
        );
    }

    /**
     * Schedule a background upgrade for all network sites except the one that
     * was just upgraded synchronously.
     *
     * @param int $alreadyProcessedBlogId Blog ID of the site already upgraded.
     * @return void
     */
    private function scheduleBackgroundMultisiteUpgrade(int $alreadyProcessedBlogId): void {
        $this->scheduleBackgroundMultisiteBatch(
            'abj404_upgrade', 'abj404_network_upgrade_background', 'upgrade', $alreadyProcessedBlogId
        );
    }

    /**
     * Process multisite plugin upgrade in batches (called by WP-Cron).
     *
     * Upgrades remaining sites that weren't handled during the initial upgrade.
     * Processes up to 10 sites per run to avoid timeouts, then reschedules itself
     * if more sites remain.
     *
     * @return bool True if all sites processed, false if more remain.
     */
    public function processMultisiteUpgradeBatch(): bool {
        return $this->processMultisiteBatch(
            'abj404_upgrade',
            'abj404_network_upgrade_background',
            'upgrade',
            function (int $siteId): void {
                // Run the full upgrade sequence for this site without going through
                // createDatabaseTables() — that would re-schedule more background tasks.
                $this->correctIssuesBefore();
                $this->runInitialCreateTables();
                $this->correctCollations();
                $this->updateTableEngineToInnoDB();
                $this->createIndexes();
                $this->backfillRedirectsCanonicalUrl();
                $this->renameAbj404TablesToLowerCase();
                $this->correctIssuesAfter();

                $logic = abj_service('plugin_logic');
                $logic->doUpdateDBVersionOption();
            }
        );
    }

    /**
     * Create tables for all sites in a multisite network.
     *
     * This function iterates through all sites in the network and creates
     * the plugin's database tables for each site. This ensures that when
     * the plugin is network-activated, all sites have the necessary tables.
     *
     * @since 3.0.1
     */
    /**
     * @return void
     * @phpstan-ignore-next-line method.unused
     */
    private function createTablesForAllSites() {
        global $wpdb;

        // Get all sites in the network
        $sites = get_sites(array('fields' => 'ids', 'number' => 0));
        $totalSites = count($sites);
        $successCount = 0;
        $failureCount = 0;

        $this->logger->infoMessage(sprintf(
            "Starting network-wide table creation for %d sites.",
            $totalSites
        ));

        foreach ($sites as $siteId) {
            try {
                // Switch to the site
                switch_to_blog($siteId);

                $currentPrefix = $wpdb->prefix;
                $this->logger->debugMessage(sprintf(
                    "Creating tables for site ID %d (prefix: %s)...",
                    $siteId,
                    $currentPrefix
                ));

                // Create tables for this site
                $this->runInitialCreateTables();
                $this->correctCollations();
                $this->updateTableEngineToInnoDB();
                $this->createIndexes();
                $this->backfillRedirectsCanonicalUrl();

                $successCount++;
                $this->logger->debugMessage(sprintf(
                    "Successfully created tables for site ID %d (prefix: %s)",
                    $siteId,
                    $currentPrefix
                ));

            } catch (Throwable $e) {
                $failureCount++;
                $this->logger->errorMessage(sprintf(
                    "Failed to create tables for site ID %d (prefix: %s): %s",
                    $siteId,
                    $wpdb->prefix,
                    $e->getMessage()
                ));
            } finally {
                // Always restore blog context
                restore_current_blog();
            }
        }

        // Log summary
        $this->logger->infoMessage(sprintf(
            "Network-wide table creation complete: %d successful, %d failed out of %d total sites.",
            $successCount,
            $failureCount,
            $totalSites
        ));

        if ($failureCount > 0) {
            $this->logger->errorMessage(sprintf(
                "Warning: Table creation failed for %d sites. Check error logs for details.",
                $failureCount
            ));
        }
    }

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
     * @param string $tableName
     * @param string $createTableStatementGoal
     * @return void
     */
    function verifyColumns($tableName, $createTableStatementGoal) {
    	$updatesWereNeeded = false;
    	
    	// find the differences
    	$tableDifferences = $this->getTableDifferences($tableName, $createTableStatementGoal);
    	$updateCols = is_array($tableDifferences['updateTheseColumns']) ? $tableDifferences['updateTheseColumns'] : [];
    	$createCols = is_array($tableDifferences['createTheseColumns']) ? $tableDifferences['createTheseColumns'] : [];
    	if (count($updateCols) > 0 ||
    		count($createCols) > 0) {
    		$updatesWereNeeded = true;
    	}
    	// make the changes
    	$this->updateATableBasedOnDifferences($tableName, $tableDifferences);

    	// verify that there are now no changes that need to be made.
    	$tableDifferences = $this->getTableDifferences($tableName, $createTableStatementGoal);
    	$updateCols = is_array($tableDifferences['updateTheseColumns']) ? $tableDifferences['updateTheseColumns'] : [];
    	$createCols = is_array($tableDifferences['createTheseColumns']) ? $tableDifferences['createTheseColumns'] : [];

    	if (count($updateCols) > 0 ||
    		count($createCols) > 0) {
    	
    		$this->logger->errorMessage("There are still differences after updating the " . 
    			$tableName . " table. " . print_r($tableDifferences, true));
    		
    	} else if ($updatesWereNeeded) {
    		$this->logger->infoMessage("No more differences found after updating the " .
    			$tableName . " table columns. All is well.");
    	}
    }
    
    /**
     * @param string $tableName
     * @param string $createTableStatementGoal
     * @return array<string, mixed>
     */
    function getTableDifferences($tableName, $createTableStatementGoal) {

    	// get the current create table statement
    	$existingTableSQL = $this->dao->getCreateTableDDL($tableName);
    	
    	$existingTableSQL = strtolower($this->removeCommentsFromColumns($existingTableSQL));
    	$createTableStatementGoal = strtolower(
    		$this->removeCommentsFromColumns($createTableStatementGoal));
    	
    	// remove the "COLLATE xxx" from the columns.
    	$removeCollatePattern = '/collate[= ]\w+ ?/';
    	$existingTableSQL = preg_replace($removeCollatePattern, "", $existingTableSQL) ?? '';
    	$createTableStatementGoal = preg_replace($removeCollatePattern, "", $createTableStatementGoal) ?? '';

    	// remove the int size format from columns because it doesn't matter.
    	$removeIntSizePattern = '/( \w*?int)(\(\d+\))/m';
    	$existingTableSQL = preg_replace($removeIntSizePattern, "$1", $existingTableSQL) ?? '';
    	$createTableStatementGoal = preg_replace($removeIntSizePattern, "$1", $createTableStatementGoal) ?? '';

    	// MySQL's SHOW CREATE TABLE omits "DEFAULT NULL" for TEXT/BLOB columns
    	// (it's implicit). Normalize both sides so this doesn't flag as a mismatch.
    	$removeTextDefaultNull = '/(text|blob|mediumtext|longtext|tinytext|mediumblob|longblob|tinyblob)\s+default\s+null/';
    	$existingTableSQL = preg_replace($removeTextDefaultNull, "$1", $existingTableSQL) ?? $existingTableSQL;
    	$createTableStatementGoal = preg_replace($removeTextDefaultNull, "$1", $createTableStatementGoal) ?? $createTableStatementGoal;

    	// get column names and types pattern (backticks are optional — accept both styles);
    	// (?!key\b) guards against accidentally matching PRIMARY KEY / UNIQUE KEY lines.
    	$colNamesAndTypesPattern = "/\s+?(`?(\w+?)`? (?!key\b)(\w.+)\s?),/";
    	$existingTableMatches = null;
    	$goalTableMatches = null;
    	// match the existing table. use preg_match_all because I couldn't find an
    	// "_all" option when using mb_ereg.
    	preg_match_all($colNamesAndTypesPattern, $existingTableSQL, $existingTableMatches);
    	preg_match_all($colNamesAndTypesPattern, $createTableStatementGoal, $goalTableMatches);
    	
    	// get the matches.
    	$goalTableMatchesColumnNames = $goalTableMatches[2];
    	$existingTableMatchesColumnNames = $existingTableMatches[2];
    	
    	// remove any spaces
    	$goalTableMatchesColumnNames = array_map('trim', $goalTableMatchesColumnNames);
    	$existingTableMatchesColumnNames = array_map('trim', $existingTableMatchesColumnNames);
    	
    	// Safety guard: if the goal DDL produced zero column names the regex failed
    	// to parse it (e.g. malformed or unparseable DDL). In that case never drop
    	// any existing columns — an empty goal list would otherwise flag every real
    	// column as "extra" and wipe the table.
    	if (empty($goalTableMatchesColumnNames) && !empty($existingTableMatchesColumnNames)) {
    		$this->logger->errorMessage("Goal DDL for " . $tableName .
    			" produced no column matches -- the DDL may be malformed or unparseable. " .
    			"Skipping column comparison to prevent data loss.");
    		$dropTheseColumns = [];
    		$createTheseColumns = [];
    		return array("updateTheseColumns" => [],
    			"dropTheseColumns" => [],
    			"createTheseColumns" => [],
    			"goalTableMatchesColumnDDL" => [],
    			"existingTableMatchesColumnDDL" => [],
    			"goalTableMatches" => $goalTableMatches,
    			"goalTableMatchesColumnNames" => []
    		);
    	}

    	// see if some columns need to be created.
    	$dropTheseColumns = array_diff($existingTableMatchesColumnNames,
    		$goalTableMatchesColumnNames);
    	$createTheseColumns = array_diff($goalTableMatchesColumnNames,
    		$existingTableMatchesColumnNames);
    	
    	// get the ddl for each column
    	$goalTableMatchesColumnDDL = $goalTableMatches[1];
    	$existingTableMatchesColumnDDL = $existingTableMatches[1];
    	
    	// remove any spaces
    	$goalTableMatchesColumnDDL = array_map('trim', $goalTableMatchesColumnDDL);
    	$existingTableMatchesColumnDDL = array_map('trim', $existingTableMatchesColumnDDL);
    	
    	// normalize minor differences between mysql versions (strip backticks so DDL
    	// files using either quoting style compare equal to SHOW CREATE TABLE output)
    	$goalTableMatchesColumnDDL = array_map([$this, 'normalizeColumnDDL'], $goalTableMatchesColumnDDL);
    	$existingTableMatchesColumnDDL = array_map([$this, 'normalizeColumnDDL'], $existingTableMatchesColumnDDL);
    	
    	// see if anything needs to be updated or created.
    	$updateTheseColumns = array_diff($goalTableMatchesColumnDDL,
    		$existingTableMatchesColumnDDL);
    	
    	// wrap the results
    	$results = array("updateTheseColumns" => $updateTheseColumns, 
    			"dropTheseColumns" => $dropTheseColumns,
    			"createTheseColumns" => $createTheseColumns,
    			"goalTableMatchesColumnDDL" => $goalTableMatchesColumnDDL,
    			"existingTableMatchesColumnDDL" => $existingTableMatchesColumnDDL,
    			"goalTableMatches" => $goalTableMatches,
    			"goalTableMatchesColumnNames" => $goalTableMatchesColumnNames
    	);
    	return $results;
    }
    
    /**
     * @param string $tableName
     * @param array<string, mixed> $tableDifferences
     * @return void
     */
    function updateATableBasedOnDifferences($tableName, $tableDifferences) {

    	/** @var array<int|string, mixed> $dropTheseColumns */
    	$dropTheseColumns = is_array($tableDifferences['dropTheseColumns']) ? $tableDifferences['dropTheseColumns'] : [];
    	/** @var array<int|string, mixed> $updateTheseColumns */
    	$updateTheseColumns = is_array($tableDifferences['updateTheseColumns']) ? $tableDifferences['updateTheseColumns'] : [];
    	/** @var array<int|string, mixed> $createTheseColumns */
    	$createTheseColumns = is_array($tableDifferences['createTheseColumns']) ? $tableDifferences['createTheseColumns'] : [];
    	$goalTableMatchesColumnDDL = is_array($tableDifferences['goalTableMatchesColumnDDL']) ? $tableDifferences['goalTableMatchesColumnDDL'] : [];
    	$existingTableMatchesColumnDDL = is_array($tableDifferences['existingTableMatchesColumnDDL']) ? $tableDifferences['existingTableMatchesColumnDDL'] : [];
    	/** @var array<int, array<int, mixed>> $goalTableMatches */
    	$goalTableMatches = is_array($tableDifferences['goalTableMatches']) ? $tableDifferences['goalTableMatches'] : [];
    	/** @var array<int|string, mixed> $goalTableMatchesColumnNames */
    	$goalTableMatchesColumnNames = is_array($tableDifferences['goalTableMatchesColumnNames']) ? $tableDifferences['goalTableMatchesColumnNames'] : [];

    	// drop unnecessary columns — but never drop ALL columns (MySQL error:
    	// "You can't delete all columns with ALTER TABLE; use DROP TABLE instead").
    	// This happens when a table is completely restructured and every existing
    	// column name differs from the goal schema.
    	$existingColumnCount = count($existingTableMatchesColumnDDL);
    	if (count($dropTheseColumns) > 0 && count($dropTheseColumns) >= $existingColumnCount) {
    		$this->logger->warn("Skipping column drops on " . $tableName .
    			" because it would remove all " . $existingColumnCount .
    			" existing columns. Drops requested: " . implode(', ', $dropTheseColumns));
    	} else {
    		foreach ($dropTheseColumns as $colName) {
    			$query = "alter table " . $tableName . " drop " . $colName;
    			$this->dao->queryAndGetResults($query);
    			$this->logger->infoMessage("I dropped a column (1): " . $query);
    		}
    	}

    	// say why we're doing what we're doing.
    	if (count($updateTheseColumns) > 0) {
    		$this->logger->infoMessage(self::$uniqID . ": On " . $tableName .
    			" I'm updating various columns because we want: \n`" .
    			print_r($goalTableMatchesColumnDDL, true) . "\n but we have: \n" .
    			print_r($existingTableMatchesColumnDDL, true));
    	}

    	// create missing columns
    	// Normalize $goalMatchesSub using the same normalizeColumnDDL() that
    	// getTableDifferences() uses, so array_search() can find the right index.
    	$goalMatchesSub = is_array($goalTableMatches[1] ?? null) ? $goalTableMatches[1] : [];
    	$goalMatchesSub = array_map([$this, 'normalizeColumnDDL'], $goalMatchesSub);
    	foreach ($updateTheseColumns as $colDDL) {
    		// find the colum name.
    		$matchIndex = array_search($colDDL, $goalMatchesSub);
    		if ($matchIndex === false) {
    			$this->logger->warn("Could not match column DDL to goal schema, skipping: " . $colDDL);
    			continue;
    		}
    		$colName = is_string($goalTableMatchesColumnNames[$matchIndex] ?? null) ? $goalTableMatchesColumnNames[$matchIndex] : '';

    		// if the column exists then update it. otherwise create it.
    		if (!in_array($colName, $createTheseColumns)) {
    			// update the existing column.
    			// ALTER TABLE `mywp_abj404_redirects` CHANGE `status` `status` BIGINT(19) NOT NULL;
    			$updateColStatement = "alter table " . $tableName . " change " . $colName .
    			" " . $colDDL;
    			$this->dao->queryAndGetResults($updateColStatement);
    			$this->logger->infoMessage("I updated a column: " . $updateColStatement);
    			
    		} else {
    			// create the column.
    			$createColStatement = "alter table " . $tableName . " add " . $colDDL;
    			$this->dao->queryAndGetResults($createColStatement);
    			$this->logger->infoMessage("I added a column: " . $createColStatement);
    		}
    		
    		$this->handleSpecificCases($tableName, $colName);
    	}
    }
    
    /** Create table DDL is returned without SQL comments of any kind.
     * Strips block comments (slash-star ... star-slash), line comments (-- ...),
     * and inline COMMENT 'text' column clauses so the column-name regex in
     * getTableDifferences() cannot mistake comment text for column definitions.
     * @param string|null $createTableDDL
     * @return string
     */
	    function removeCommentsFromColumns($createTableDDL) {
	    	if ($createTableDDL === null) {
	    		return '';
	    	}
	    	$ddl = (string) $createTableDDL;
	    	// Strip block comments (slash-star ... star-slash), including multi-line.
	    	$ddl = preg_replace('/\/\*.*?\*\//s', '', $ddl) ?? $ddl;
	    	// Strip line comments (-- ...).
	    	$ddl = preg_replace('/--[^\r\n]*/', '', $ddl) ?? $ddl;
	    	// Strip inline COMMENT 'text', clauses from column definitions.
	    	return preg_replace('/ (?:COMMENT.+?,[\r\n])/', ",\n", $ddl) ?? $ddl;
	    }
    /**
     * Normalize a single column DDL fragment for comparison.
     *
     * Strips backticks and unquotes integer defaults so that DDL from
     * SHOW CREATE TABLE (e.g. default '1') matches the goal DDL file
     * (e.g. default 1). Used by both getTableDifferences() and
     * updateATableBasedOnDifferences() — a single source of truth
     * prevents the two normalization sites from drifting out of sync.
     *
     * @param mixed $ddl  A column DDL string (or non-string from regex match)
     * @return string
     */
    function normalizeColumnDDL($ddl): string {
    	$ddlStr = is_string($ddl) ? $ddl : '';
    	$normalized = strtolower(str_replace('`', '', trim($ddlStr)));
    	$normalized = preg_replace("/default '(\d+)'/", 'default $1', $normalized) ?? $normalized;
    	// MySQL omits DEFAULT NULL for nullable columns — strip it so DDL file
    	// and SHOW CREATE TABLE produce identical normalized strings.
    	$normalized = preg_replace('/\s+default\s+null\b/', '', $normalized) ?? $normalized;
    	return $normalized;
    }

    /**
     * @param string $tableName
     * @return void
     */
    function deleteIndexes($tableName) {

    	// get the indexes list.
    	$results = $this->dao->queryAndGetResults("show index from " . $tableName .
    		" where key_name != 'PRIMARY'");
    	/** @var array<int, array<string, mixed>> $rows */
    	$rows = isset($results['rows']) && is_array($results['rows']) ? $results['rows'] : [];

    	if (empty($rows)) {
    		return;
    	}

    	// find the key_name column because the case can be different on different systems.
    	$keyNameColumn = 'key_name';
    	$aRow = $rows[0];
    	foreach (array_keys($aRow) as $someKey) {
    		if ($this->f->strtolower((string)$someKey) == 'key_name') {
    			$keyNameColumn = (string)$someKey;
    			break;
    		}
    	}

    	foreach ($rows as $row) {
    		// delete them
    		$indexName = $row[$keyNameColumn] ?? '';
    		if (!is_string($indexName) || $indexName === '') {
    			continue;
    		}
    		$query = "alter table " . $tableName . " drop index " . $indexName;
    		$this->dao->queryAndGetResults($query);
    	}
    }
}
