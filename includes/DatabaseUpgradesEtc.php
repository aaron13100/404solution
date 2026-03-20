<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseUpgradesEtcTrait_NGram.php';
require_once __DIR__ . '/DatabaseUpgradesEtcTrait_Maintenance.php';
require_once __DIR__ . '/DatabaseUpgradesEtcTrait_PluginUpdate.php';

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
		$this->dao = $dataAccess !== null ? $dataAccess : ABJ_404_Solution_DataAccess::getInstance();
		$this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
		$this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
		$this->permalinkCache = $permalinkCache !== null ? $permalinkCache : ABJ_404_Solution_PermalinkCache::getInstance();
		$this->syncUtils = $syncUtils !== null ? $syncUtils : ABJ_404_Solution_SynchronizationUtils::getInstance();
		$this->logic = $pluginLogic !== null ? $pluginLogic : ABJ_404_Solution_PluginLogic::getInstance();
		$this->ngramFilter = $ngramFilter !== null ? $ngramFilter : ABJ_404_Solution_NGramFilter::getInstance();
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
		$this->renameAbj404TablesToLowerCase();

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
    	}

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
    		if ($updatingToNewVersion && !empty($migrationResults['redirects_updated']) && function_exists('add_settings_error')) {
    			$rawRedirectsUpdated = $migrationResults['redirects_updated'];
    			$redirectsUpdated = is_scalar($rawRedirectsUpdated) ? (int)$rawRedirectsUpdated : 0;
    			$message = sprintf(
    				__('404 Solution: Migrated %d redirects to subdirectory-independent format.', '404-solution'),
    				$redirectsUpdated
    			);
    			add_settings_error('abj404_settings', 'migration_success', $message, 'updated');
    		}
    	}

    	if ($updatingToNewVersion) {
    		$this->correctIssuesAfter();
    	}
    }
    
    /**
     * Correct any possible outstanding issues.
     * @return void
     */
    function correctIssuesBefore() {
    	$this->dao->correctDuplicateLookupValues();

    	// 3.3.4+: Repair any plugin table that was stripped of all columns by a
    	// DDL parsing bug.  The 3.3.3 bug only affected view_cache, but any future
    	// DDL file shipped without parseable column syntax could wipe any table.
    	// Dropped tables are pure caches or safely recreatable; runInitialCreateTables()
    	// will recreate them immediately after.
    	$this->repairStrippedViewCacheTable();

    	$this->correctMatchData();
    }

    /**
     * For every permanent plugin table, check whether the table exists but is
     * missing its primary `id` column — the signature of the 3.3.3 column-drop
     * bug.  If a table is stripped, drop it so that runInitialCreateTables() can
     * recreate it cleanly from the DDL file.
     *
     * Generalised in 3.3.5 from a view_cache-only fix to cover all plugin tables:
     * the 3.3.3 bug only affected view_cache.sql, but any future DDL file shipped
     * without parseable backtick column syntax would trigger the same data wipe on
     * that table with no repair path.
     *
     * @return void
     */
    function repairStrippedViewCacheTable() {
    	$sqlDir = __DIR__ . '/sql';
    	$files = glob($sqlDir . '/create*Table.sql') ?: [];

    	foreach ($files as $file) {
    		if (stripos(basename($file), 'Temp') !== false) {
    			continue;
    		}
    		$ddlTemplate = ABJ_404_Solution_Functions::readFileContents($file);
    		if (!is_string($ddlTemplate) || trim($ddlTemplate) === '') {
    			continue;
    		}
    		if (!preg_match('/\{(wp_abj404_\w+)\}/', $ddlTemplate, $matches)) {
    			continue;
    		}
    		$placeholder = '{' . $matches[1] . '}';
    		$tableName = $this->dao->doTableNameReplacements($placeholder);
    		$ddl = $this->dao->getCreateTableDDL($tableName);

    		// Table doesn't exist at all — nothing to repair.
    		if (empty($ddl)) {
    			continue;
    		}

    		// If the DDL contains the `id` column the table is intact.
    		if (stripos($ddl, '`id`') !== false || preg_match('/\bid\b/', $ddl)) {
    			continue;
    		}

    		// Table exists but is missing its primary column — it was stripped.
    		$this->logger->infoMessage("Repairing stripped plugin table " . $tableName .
    			" (missing id column — caused by DDL parsing bug). Dropping for clean recreation.");
    		$this->dao->queryAndGetResults("DROP TABLE IF EXISTS " . $tableName);
    	}
    }
    
    /**
     * Correct any possible outstanding issues.
     * @return void
     */
    function correctIssuesAfter() {
    	$this->correctMatchData();
    }

    /**
     * Makes all plugin table names lowercase, in case someone thought it was funny to use
	 * the lower_case_table_names=0 setting.
     * @return void
     */
		function renameAbj404TablesToLowerCase() {
			global $wpdb;
			// Fetch all tables starting with "abj404", case-insensitive
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
	}
    
    /** @return void */
    function correctMatchData() {
    	$this->dao->queryAndGetResults("delete from {wp_abj404_spelling_cache} " .
    		"where matchdata is null or matchdata = ''");
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
    
	    /** @return void */
	    function runInitialCreateTables() {
	    	// Discover all permanent table DDL files dynamically.
	    	// Adding a new table = adding a create*Table.sql file. No other code changes needed.
	    	$sqlDir = __DIR__ . '/sql';
	    	$files = glob($sqlDir . '/create*Table.sql');
	    	if (!is_array($files)) {
	    		$files = array();
	    	}
	    	sort($files);

	    	foreach ($files as $file) {
	    		// Skip temporary tables (e.g., createLogsHitsTempTable.sql).
	    		if (stripos(basename($file), 'Temp') !== false) {
	    			continue;
	    		}

	    		$query = ABJ_404_Solution_Functions::readFileContents($file);
	    		if (!is_string($query) || trim($query) === '') {
	    			continue;
	    		}
	    		$query = $this->applyPluginTableCharsetCollate($query);
	    		$this->dao->queryAndGetResults($query);

	    		// Extract the table placeholder (e.g. "{wp_abj404_redirects}") from the DDL
	    		// and resolve it to the actual prefixed table name for verifyColumns().
	    		if (preg_match('/\{(wp_abj404_\w+)\}/', $query, $matches)) {
	    			$tableName = $this->dao->doTableNameReplacements('{' . $matches[1] . '}');
	    			$this->verifyColumns($tableName, $query);
	    		}
	    	}

	    	// Table-specific post-creation steps.
	    	$logsTable = $this->dao->doTableNameReplacements("{wp_abj404_logsv2}");
	    	$this->ensureLogsCompositeIndex($logsTable);

	    	// Mark view cache table as ensured so ensureViewSnapshotTableExists() skips redundant DDL.
	    	ABJ_404_Solution_DataAccess::setViewSnapshotTableEnsured(true);
	    }

	    /**
	     * @param string $createTableSql
	     * @return string
	     */
	    private function applyPluginTableCharsetCollate($createTableSql) {
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
     * Schedule background multisite activation to process remaining sites via WP-Cron.
     *
     * @param int $alreadyProcessedBlogId Blog ID that was already processed during activation
     * @return void
     */
    private function scheduleBackgroundMultisiteActivation($alreadyProcessedBlogId) {
        // Store the processed blog ID so cron handler knows to skip it
        update_site_option('abj404_activation_processed_blogs', array($alreadyProcessedBlogId));
        update_site_option('abj404_activation_in_progress', true);

        // Schedule immediate execution (within 30 seconds)
        $hookName = 'abj404_network_activation_background';

        // Check if already scheduled
        if (wp_next_scheduled($hookName)) {
            $this->logger->debugMessage("Background multisite activation already scheduled.");
            return;
        }

        $scheduled = wp_schedule_single_event(time() + 30, $hookName);

        if ($scheduled === false) {
            $this->logger->errorMessage("Failed to schedule background multisite activation. Remaining sites will not have tables created automatically.");
        } else {
            $this->logger->infoMessage("Background multisite activation scheduled successfully.");
        }
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
    public function processMultisiteActivationBatch() {
        global $wpdb;

        // Get list of already processed blogs
        $processedBlogs = get_site_option('abj404_activation_processed_blogs', array());
        if (!is_array($processedBlogs)) {
            $processedBlogs = array();
        }

        // Get all sites in the network
        $allSites = get_sites(array('fields' => 'ids', 'number' => 0));
        $remainingSites = array_diff($allSites, $processedBlogs);

        if (empty($remainingSites)) {
            // All sites processed - cleanup and exit
            delete_site_option('abj404_activation_processed_blogs');
            delete_site_option('abj404_activation_in_progress');
            $this->logger->infoMessage("Background multisite activation complete. All sites processed.");
            return true;
        }

        // Process up to 10 sites per batch to avoid timeouts
        $batchSize = 10;
        $sitesToProcess = array_slice($remainingSites, 0, $batchSize);
        $totalRemaining = count($remainingSites);

        $this->logger->infoMessage(sprintf(
            "Processing multisite activation batch: %d sites (of %d remaining)",
            count($sitesToProcess),
            $totalRemaining
        ));

        foreach ($sitesToProcess as $siteId) {
            try {
                switch_to_blog($siteId);

                $this->logger->debugMessage(sprintf(
                    "Activating site ID %d (prefix: %s)...",
                    $siteId,
                    $wpdb->prefix
                ));

                // Run full activation for this site (not just table creation)
                add_option('abj404_settings', '', '', false);

                $this->runInitialCreateTables();
                $this->correctCollations();
                $this->updateTableEngineToInnoDB();
                $this->createIndexes();

                ABJ_404_Solution_PluginLogic::doRegisterCrons();

                // Update DB version for this site
                $logic = ABJ_404_Solution_PluginLogic::getInstance();
                $logic->doUpdateDBVersionOption();

                $processedBlogs[] = $siteId;
                update_site_option('abj404_activation_processed_blogs', $processedBlogs);

                $this->logger->debugMessage(sprintf(
                    "Successfully activated site ID %d",
                    $siteId
                ));

            } catch (Throwable $e) {
                $this->logger->errorMessage(sprintf(
                    "Failed to activate site ID %d: %s",
                    $siteId,
                    $e->getMessage()
                ));
                // Mark as processed anyway to avoid getting stuck
                $processedBlogs[] = $siteId;
                update_site_option('abj404_activation_processed_blogs', $processedBlogs);
            } finally {
                restore_current_blog();
            }
        }

        // If more sites remain, reschedule
        $stillRemaining = count($remainingSites) - count($sitesToProcess);
        if ($stillRemaining > 0) {
            $this->logger->infoMessage(sprintf(
                "Batch complete. Rescheduling for %d remaining sites.",
                $stillRemaining
            ));

            // Schedule next batch in 30 seconds
            wp_schedule_single_event(time() + 30, 'abj404_network_activation_background');
            return false;
        } else {
            // All done
            delete_site_option('abj404_activation_processed_blogs');
            delete_site_option('abj404_activation_in_progress');
            $this->logger->infoMessage("Background multisite activation complete. All sites processed.");
            return true;
        }
    }

    /**
     * Schedule a background upgrade for all network sites except the one that
     * was just upgraded synchronously.
     *
     * @param int $alreadyProcessedBlogId Blog ID of the site already upgraded.
     * @return void
     */
    private function scheduleBackgroundMultisiteUpgrade($alreadyProcessedBlogId) {
        update_site_option('abj404_upgrade_processed_blogs', array($alreadyProcessedBlogId));
        update_site_option('abj404_upgrade_in_progress', true);

        $hookName = 'abj404_network_upgrade_background';

        if (wp_next_scheduled($hookName)) {
            $this->logger->debugMessage("Background multisite upgrade already scheduled.");
            return;
        }

        $scheduled = wp_schedule_single_event(time() + 30, $hookName);

        if ($scheduled === false) {
            $this->logger->errorMessage("Failed to schedule background multisite upgrade. Remaining sites will not have tables updated automatically.");
        } else {
            $this->logger->infoMessage("Background multisite upgrade scheduled successfully.");
        }
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
    public function processMultisiteUpgradeBatch() {
        $processedBlogs = get_site_option('abj404_upgrade_processed_blogs', array());
        if (!is_array($processedBlogs)) {
            $processedBlogs = array();
        }

        $allSites = get_sites(array('fields' => 'ids', 'number' => 0));
        $remainingSites = array_diff($allSites, $processedBlogs);

        if (empty($remainingSites)) {
            delete_site_option('abj404_upgrade_processed_blogs');
            delete_site_option('abj404_upgrade_in_progress');
            $this->logger->infoMessage("Background multisite upgrade complete. All sites processed.");
            return true;
        }

        $batchSize = 10;
        $sitesToProcess = array_slice($remainingSites, 0, $batchSize);
        $totalRemaining = count($remainingSites);

        $this->logger->infoMessage(sprintf(
            "Processing multisite upgrade batch: %d sites (of %d remaining)",
            count($sitesToProcess),
            $totalRemaining
        ));

        foreach ($sitesToProcess as $siteId) {
            try {
                switch_to_blog($siteId);

                $this->logger->debugMessage(sprintf(
                    "Upgrading site ID %d...",
                    $siteId
                ));

                // Run the full upgrade sequence for this site without going through
                // createDatabaseTables() — that would re-schedule more background tasks.
                $this->correctIssuesBefore();
                $this->runInitialCreateTables();
                $this->correctCollations();
                $this->updateTableEngineToInnoDB();
                $this->createIndexes();
                $this->correctIssuesAfter();

                $logic = ABJ_404_Solution_PluginLogic::getInstance();
                $logic->doUpdateDBVersionOption();

                $processedBlogs[] = $siteId;
                update_site_option('abj404_upgrade_processed_blogs', $processedBlogs);

                $this->logger->debugMessage(sprintf("Successfully upgraded site ID %d", $siteId));

            } catch (Throwable $e) {
                $this->logger->errorMessage(sprintf(
                    "Failed to upgrade site ID %d: %s",
                    $siteId,
                    $e->getMessage()
                ));
                $processedBlogs[] = $siteId;
                update_site_option('abj404_upgrade_processed_blogs', $processedBlogs);
            } finally {
                restore_current_blog();
            }
        }

        $stillRemaining = count($remainingSites) - count($sitesToProcess);
        if ($stillRemaining > 0) {
            $this->logger->infoMessage(sprintf(
                "Upgrade batch complete. Rescheduling for %d remaining sites.",
                $stillRemaining
            ));
            wp_schedule_single_event(time() + 30, 'abj404_network_upgrade_background');
            return false;
        } else {
            delete_site_option('abj404_upgrade_processed_blogs');
            delete_site_option('abj404_upgrade_in_progress');
            $this->logger->infoMessage("Background multisite upgrade complete. All sites processed.");
            return true;
        }
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
    	// Loop over every permanent table DDL file (same discovery as runInitialCreateTables).
    	// doTableNameReplacements() handles all {wp_abj404_*} placeholders in one call,
    	// so new tables are automatically included without modifying this method.
    	$sqlDir = __DIR__ . '/sql';
    	$files = glob($sqlDir . '/create*Table.sql');
    	if (!is_array($files)) {
    		$files = [];
    	}
    	sort($files);

    	foreach ($files as $file) {
    		if (stripos(basename($file), 'Temp') !== false) {
    			continue;
    		}

    		$query = ABJ_404_Solution_Functions::readFileContents($file);
    		if (!is_string($query) || trim($query) === '') {
    			continue;
    		}

    		// Replace all {wp_abj404_*} placeholders using the shared helper.
    		$query = $this->dao->doTableNameReplacements($query);

    		// Extract the resolved table name from the DDL so we can pass it to verifyIndexes().
    		if (!preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\S+?)[`"]?\s*\(/i', $query, $m)) {
    			continue;
    		}
    		$tableName = trim($m[1], '`"');

    		$this->verifyIndexes($tableName, $query);
    	}
    }

    /**
     * @param string $tableName
     * @param string $createTableStatementGoal
     * @return void
     */
    function verifyIndexes($tableName, $createTableStatementGoal) {

    	// get the current create table statement
    	$existingTableSQL = $this->dao->getCreateTableDDL($tableName);
    	
    	$existingTableSQL = strtolower($this->removeCommentsFromColumns($existingTableSQL));
    	$createTableStatementGoal = strtolower(
    		$this->removeCommentsFromColumns($createTableStatementGoal));
    	
    	// get column names and types pattern (backticks are optional — accept both styles);
    	// (?!key\b) guards against accidentally matching PRIMARY KEY / UNIQUE KEY lines,
    	// where "PRIMARY" would appear as the column name and "key" as the type.
    	$colNamesAndTypesPattern = "/\s+?(`?(\w+?)`? (?!key\b)(\w.+?) .+?),/";
    	// remove the columns.
    	$existingTableSQL = preg_replace($colNamesAndTypesPattern, "", $existingTableSQL) ?? '';
    	$createTableStatementGoal = preg_replace($colNamesAndTypesPattern, "",
    		$createTableStatementGoal) ?? '';

    	// remove the create table and primary key
    	$primaryPos = strpos($existingTableSQL, 'primary');
    	$existingTableSQL = $primaryPos !== false ? substr($existingTableSQL, $primaryPos) : '';
    	$newlinePos = strpos($existingTableSQL, "\n");
    	$existingTableSQL = $newlinePos !== false ? substr($existingTableSQL, $newlinePos) : '';
    	$primaryPos = strpos($createTableStatementGoal, 'primary');
    	$createTableStatementGoal = $primaryPos !== false ? substr($createTableStatementGoal, $primaryPos) : '';
    	$newlinePos = strpos($createTableStatementGoal, "\n");
    	$createTableStatementGoal = $newlinePos !== false ? substr($createTableStatementGoal, $newlinePos) : '';
    	
    	// remove the engine= ...
    	$engineLoc = $this->f->strpos($existingTableSQL, ") engine");
    	if ($engineLoc !== false) {
    		$existingTableSQL = substr($existingTableSQL, 0, $engineLoc);
    	}
    	$commentLoc = $this->f->strpos($existingTableSQL, ") comment");
    	if ($commentLoc !== false) {
    		$existingTableSQL = substr($existingTableSQL, 0, $commentLoc);
    	}
    	$engineLoc = $this->f->strpos($createTableStatementGoal, ") engine");
    	if ($engineLoc !== false) {
    		$createTableStatementGoal = substr($createTableStatementGoal, 0, $engineLoc);
    	}
    	$commentLoc = $this->f->strpos($createTableStatementGoal, ") comment");
    	if ($commentLoc !== false) {
    		$createTableStatementGoal = substr($createTableStatementGoal, 0, $commentLoc);
    	}
    	
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

	    	foreach ($missingIndexNames as $indexName) {
	    		$spec = $goalSpecsByName[$indexName] ?? null;
	    		if (empty($spec)) {
	    			continue;
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
        $results = $wpdb->get_results($sql, ARRAY_A);
        return !empty($results);
    }

	    /**
	     * @param string $tableName
	     * @param string $indexDDL
	     * @return string
	     * @phpstan-ignore-next-line method.unused
	     */
	    private function buildAddIndexStatement($tableName, $indexDDL) {
	        global $wpdb;
	        /** @var \wpdb $wpdb */
	        $serverVersion = method_exists($wpdb, 'db_version') ? ($wpdb->db_version() ?: '') : '';
	        $serverInfo = property_exists($wpdb, 'db_server_info') ? ($wpdb->db_server_info ?? '') : '';

	        $isMaria = stripos($serverInfo, 'mariadb') !== false || stripos($serverVersion, 'maria') !== false;
	        $cleanedVersion = preg_replace('/[^\d\.]/', '', $serverVersion) ?? '';
	        $supportsIfNotExists = $isMaria && version_compare($cleanedVersion, '10.5', '>=');

	        $indexDDL = trim($indexDDL);

	        // Normalize "KEY `name` (...)" / "UNIQUE KEY `name` (...)" into a form usable with "IF NOT EXISTS".
	        // MariaDB supports: ADD [UNIQUE] INDEX IF NOT EXISTS `name` (...)
		        if ($supportsIfNotExists) {
		            $matches = [];
		            if (preg_match('/^(unique\\s+)?key\\s+`([^`]+)`\\s*(\\(.+\\))\\s*$/i', $indexDDL, $matches)) {
		                $unique = !empty($matches[1]);
		                $name = $matches[2];
		                $cols = $matches[3];
		                $type = $unique ? 'unique index' : 'index';
		                return "alter table " . $tableName . " add " . $type . " if not exists `" . $name . "` " . $cols;
		            }
		            if (preg_match('/^`([^`]+)`\\s*(\\(.+\\))\\s*$/', $indexDDL, $matches)) {
		                $name = $matches[1];
		                $cols = $matches[2];
		                return "alter table " . $tableName . " add index if not exists `" . $name . "` " . $cols;
		            }
		        }

		        // If we were given a bare index DDL like "`name` (...)", make it valid for MySQL too.
		        if (preg_match('/^`[^`]+`\\s*\\(.+\\)\\s*$/', $indexDDL)) {
		            return "alter table " . $tableName . " add index " . $indexDDL;
		        }

		        // If we were given a bare index DDL like "name (...)", make it valid too.
		        if (preg_match('/^([A-Za-z0-9_]+)\\s*(\\(.+\\))\\s*$/', $indexDDL, $matches)) {
		            $name = $matches[1];
		            $cols = $matches[2];
		            return "alter table " . $tableName . " add index `" . $name . "` " . $cols;
		        }

		        // Fallback: use the DDL as-is (already contains KEY/UNIQUE KEY).
		        return "alter table " . $tableName . " add " . $indexDDL;
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
    	$removeCollatePattern = '/collate \w+ ?/';
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
    	$newGoalTableDDL = array();
    	foreach ($goalTableMatchesColumnDDL as $oneDDLLine) {
    		$newVal = str_replace('`', '', $oneDDLLine);
    		$newVal = str_replace("default '0'", "default 0", $newVal);
    		array_push($newGoalTableDDL, $newVal);
    	}
    	$goalTableMatchesColumnDDL = $newGoalTableDDL;
    	$newExistingTableDDL = array();
    	foreach ($existingTableMatchesColumnDDL as $oneDDLLine) {
    		$newVal = str_replace('`', '', $oneDDLLine);
    		$newVal = str_replace("default '0'", "default 0", $newVal);
    		array_push($newExistingTableDDL, $newVal);
    	}
    	$existingTableMatchesColumnDDL = $newExistingTableDDL;
    	
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

    	// drop unnecessary columns.
    	foreach ($dropTheseColumns as $colName) {
    		$query = "alter table " . $tableName . " drop " . $colName;
    		$this->dao->queryAndGetResults($query);
    		$this->logger->infoMessage("I dropped a column (1): " . $query);
    	}

    	// say why we're doing what we're doing.
    	if (count($updateTheseColumns) > 0) {
    		$this->logger->infoMessage(self::$uniqID . ": On " . $tableName .
    			" I'm updating various columns because we want: \n`" .
    			print_r($goalTableMatchesColumnDDL, true) . "\n but we have: \n" .
    			print_r($existingTableMatchesColumnDDL, true));
    	}

    	// create missing columns
    	// Normalize $goalMatchesSub the same way getTableDifferences() normalizes
    	// $goalTableMatchesColumnDDL so array_search() can find the right index.
    	$goalMatchesSub = is_array($goalTableMatches[1] ?? null) ? $goalTableMatches[1] : [];
    	$goalMatchesSub = array_map(function ($ddl) {
    		$ddlStr = is_string($ddl) ? $ddl : '';
		return str_replace("default '0'", "default 0", str_replace('`', '', trim($ddlStr)));
    	}, $goalMatchesSub);
    	foreach ($updateTheseColumns as $colDDL) {
    		// find the colum name.
    		$matchIndex = array_search($colDDL, $goalMatchesSub);
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
