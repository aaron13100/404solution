<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Functions in this class should all reference one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DatabaseUpgradesEtc {

	private static $instance = null;

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

	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_DatabaseUpgradesEtc();
			self::$uniqID = uniqid("", true);
		}

		return self::$instance;
	}
	
	/** Create the tables when the plugin is first activated. 
     * @global type $wpdb
     */
    function createDatabaseTables($updatingToNewVersion = false) {

    	$synchronizedKeyFromUser = "create_db_tables";
    	$uniqueID = $this->syncUtils->synchronizerAcquireLockTry($synchronizedKeyFromUser);

    	if ($uniqueID == '' || $uniqueID == null) {
    		$this->logger->debugMessage("Avoiding multiple calls for creating database tables.");
    		return;
    	}

    	// Fixed: Use finally block to ensure lock is ALWAYS released, even on fatal errors
    	try {
    		$this->reallyCreateDatabaseTables($updatingToNewVersion);

    	} catch (Throwable $e) {  // Fixed: Catch Throwable (Exception + Error) instead of just Exception
    		$this->logger->errorMessage("Error creating database tables. ", $e);
    		throw $e;  // Re-throw to propagate the error
    	} finally {
    		// This ALWAYS executes, even on fatal errors or exceptions
    		$this->syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
    	}
    }
    
    private function reallyCreateDatabaseTables($updatingToNewVersion = false) {
		$this->renameAbj404TablesToLowerCase();

    	if ($updatingToNewVersion) {
    		$this->correctIssuesBefore();
    	}

    	// MULTISITE: Process current site immediately, schedule background task for remaining sites
    	// Only during activation, not during updates/repairs
    	if ($this->isNetworkActivated() && !$updatingToNewVersion) {
    		// Create tables for current site immediately (prevents timeout on activation)
    		$currentBlogId = get_current_blog_id();
    		$this->runInitialCreateTables();
    		$this->correctCollations();
    		$this->updateTableEngineToInnoDB();
    		$this->createIndexes();

    		$this->logger->infoMessage(sprintf(
    			"Network activation: Created tables for current site (ID %d). Scheduling background task for remaining sites.",
    			$currentBlogId
    		));

    		// Schedule background processing for all other sites
    		$this->scheduleBackgroundMultisiteActivation($currentBlogId);
    	} else {
    		// Single site or site-activated: create tables for current site only
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
    			$message = sprintf(
    				__('404 Solution: Migrated %d redirects to subdirectory-independent format.', '404-solution'),
    				$migrationResults['redirects_updated']
    			);
    			add_settings_error('abj404_settings', 'migration_success', $message, 'updated');
    		}
    	}

    	if ($updatingToNewVersion) {
    		$this->correctIssuesAfter();
    	}
    }
    
    /** Correct any possible outstanding issues. */
    function correctIssuesBefore() {
    	$this->dao->correctDuplicateLookupValues();
    	
    	$this->correctMatchData();
    }
    
    /** Correct any possible outstanding issues. */
    function correctIssuesAfter() {
    	$this->correctMatchData();
    }

    /** Makes all plugin table names lowercase, in case someone thought it was funny to use
	 * the lower_case_table_names=0 setting. */
		function renameAbj404TablesToLowerCase() {
			global $wpdb;
			// Fetch all tables starting with "abj404", case-insensitive
			$dbNameRaw = $wpdb->dbname ?? '';
			if ($dbNameRaw === '') {
				$this->logger->warn("Could not determine database name for lowercase rename.");
				return;
			}
			$dbName = esc_sql($dbNameRaw);
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
			$tableName = $row['table_name'] ?? $row['TABLE_NAME'];

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
    
    function correctMatchData() {
    	$this->dao->queryAndGetResults("delete from {wp_abj404_spelling_cache} " .
    		"where matchdata is null or matchdata = ''");
    }
    
	/** When certain columns are created we have to populate data.
     * @param string $tableName
     * @param string $colName
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
    
	    function runInitialCreateTables() {
	    	global $wpdb;
	    	$redirectsTable = $this->dao->doTableNameReplacements("{wp_abj404_redirects}");
	    	$logsTable = $this->dao->doTableNameReplacements("{wp_abj404_logsv2}");
	    	$lookupTable = $this->dao->doTableNameReplacements("{wp_abj404_lookup}");
	    	$permalinkCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_permalink_cache}");
	    	$spellingCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_spelling_cache}");
	    	$ngramCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_ngram_cache}");

	        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createPermalinkCacheTable.sql");
	        $query = $this->applyPluginTableCharsetCollate($query);
	        $this->dao->queryAndGetResults($query);
	        $this->verifyColumns($permalinkCacheTable, $query);

	        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createSpellingCacheTable.sql");
	        $query = $this->applyPluginTableCharsetCollate($query);
	        $this->dao->queryAndGetResults($query);
	        $this->verifyColumns($spellingCacheTable, $query);

	        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createNGramCacheTable.sql");
	        $query = $this->applyPluginTableCharsetCollate($query);
	        $this->dao->queryAndGetResults($query);
	        $this->verifyColumns($ngramCacheTable, $query);

	        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createRedirectsTable.sql");
	        $query = $this->applyPluginTableCharsetCollate($query);
	        $this->dao->queryAndGetResults($query);
	        $this->verifyColumns($redirectsTable, $query);

	        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogTable.sql");
	        $query = $this->applyPluginTableCharsetCollate($query);
	        $this->dao->queryAndGetResults($query);
	        $this->verifyColumns($logsTable, $query);
	        $this->ensureLogsCompositeIndex($logsTable);

	        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLookupTable.sql");
	        $query = $this->applyPluginTableCharsetCollate($query);
	        $this->dao->queryAndGetResults($query);
	        $this->verifyColumns($lookupTable, $query);
	    }

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
                add_option('abj404_settings', '', '', 'no');

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
     * Create tables for all sites in a multisite network.
     *
     * This function iterates through all sites in the network and creates
     * the plugin's database tables for each site. This ensures that when
     * the plugin is network-activated, all sites have the necessary tables.
     *
     * @since 3.0.1
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

    function createIndexes() {
    	global $wpdb;
    	$redirectsTable = $this->dao->doTableNameReplacements("{wp_abj404_redirects}");
    	$logsTable = $this->dao->doTableNameReplacements("{wp_abj404_logsv2}");
    	$lookupTable = $this->dao->doTableNameReplacements("{wp_abj404_lookup}");
    	$permalinkCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_permalink_cache}");
    	$spellingCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_spelling_cache}");
    	$ngramCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_ngram_cache}");

    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createPermalinkCacheTable.sql");
    	$query = $this->f->str_replace('{wp_abj404_permalink_cache}', $permalinkCacheTable, $query);
    	$this->verifyIndexes($permalinkCacheTable, $query);

    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createSpellingCacheTable.sql");
    	$query = $this->f->str_replace('{wp_abj404_spelling_cache}', $spellingCacheTable, $query);
    	$this->verifyIndexes($spellingCacheTable, $query);

    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createNGramCacheTable.sql");
    	$query = $this->f->str_replace('{wp_abj404_ngram_cache}', $ngramCacheTable, $query);
    	$this->verifyIndexes($ngramCacheTable, $query);

    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createRedirectsTable.sql");
    	$query = $this->f->str_replace('{redirectsTable}', $redirectsTable, $query);
    	$this->verifyIndexes($redirectsTable, $query);

    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogTable.sql");
    	$query = $this->f->str_replace('{wp_abj404_logsv2}', $logsTable, $query);
    	$this->verifyIndexes($logsTable, $query);

    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLookupTable.sql");
    	$query = $this->f->str_replace('{wp_abj404_lookup}', $lookupTable, $query);
    	$this->verifyIndexes($lookupTable, $query);
    }

    function verifyIndexes($tableName, $createTableStatementGoal) {
    	
    	// get the current create table statement
    	$existingTableSQL = $this->dao->getCreateTableDDL($tableName);
    	
    	$existingTableSQL = strtolower($this->removeCommentsFromColumns($existingTableSQL));
    	$createTableStatementGoal = strtolower(
    		$this->removeCommentsFromColumns($createTableStatementGoal));
    	
    	// get column names and types pattern;
    	$colNamesAndTypesPattern = "/\s+?(`(\w+?)` (\w.+?) .+?),/";
    	// remove the columns.
    	$existingTableSQL = preg_replace($colNamesAndTypesPattern, "", $existingTableSQL);
    	$createTableStatementGoal = preg_replace($colNamesAndTypesPattern, "", 
    		$createTableStatementGoal);
    	
    	// remove the create table and primary key
    	$existingTableSQL = substr($existingTableSQL, 
    		strpos($existingTableSQL, 'primary'));
    	$existingTableSQL = substr($existingTableSQL,
    		strpos($existingTableSQL, "\n"));
    	$createTableStatementGoal = substr($createTableStatementGoal,
    		strpos($createTableStatementGoal, 'primary'));
    	$createTableStatementGoal = substr($createTableStatementGoal,
    		strpos($createTableStatementGoal, "\n"));
    	
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
		    		$tableNameLower = is_string($tableName) ? strtolower($tableName) : '';
		    		if ($tableNameLower == $spellingCacheTableName && !empty($spec['unique'])) {
		    			$this->dao->deleteSpellingCache();
		    		}

	    		$addStatement = $this->buildAddIndexStatementFromParts($tableName, $spec['name'], $spec['columns'], $spec['unique']);
	    		$this->dao->queryAndGetResults($addStatement);
	    		$this->logger->infoMessage("I added an index: " . $addStatement);
	    	}
	    }

    private function indexExists($tableName, $indexName) {
        global $wpdb;
        $sql = $wpdb->prepare("SHOW INDEX FROM {$tableName} WHERE Key_name = %s", $indexName);
        $results = $wpdb->get_results($sql, ARRAY_A);
        return !empty($results);
    }

	    private function buildAddIndexStatement($tableName, $indexDDL) {
	        global $wpdb;
	        $serverVersion = method_exists($wpdb, 'db_version') ? $wpdb->db_version() : '';
	        $serverInfo = property_exists($wpdb, 'db_server_info') ? $wpdb->db_server_info : '';

	        $isMaria = stripos($serverInfo, 'mariadb') !== false || stripos($serverVersion, 'maria') !== false;
	        $supportsIfNotExists = $isMaria && version_compare(preg_replace('/[^\d\.]/', '', $serverVersion), '10.5', '>=');

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
	     * @return array|null {name:string, columns:string, unique:bool}
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
	        $lines = $matches[0] ?? [];

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
	        $serverVersion = method_exists($wpdb, 'db_version') ? $wpdb->db_version() : '';
	        $serverInfo = property_exists($wpdb, 'db_server_info') ? $wpdb->db_server_info : '';

	        $isMaria = stripos($serverInfo, 'mariadb') !== false || stripos($serverVersion, 'maria') !== false;
	        $supportsIfNotExists = $isMaria && version_compare(preg_replace('/[^\d\.]/', '', $serverVersion), '10.5', '>=');

	        $indexType = $unique ? 'unique index' : 'index';
	        $ifNotExists = $supportsIfNotExists ? ' if not exists' : '';

	        return "alter table " . $tableName . " add " . $indexType . $ifNotExists . " `" . $indexName . "` " . trim($columnsSql);
	    }

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
    
    function verifyColumns($tableName, $createTableStatementGoal) {
    	$updatesWereNeeded = false;
    	
    	// find the differences
    	$tableDifferences = $this->getTableDifferences($tableName, $createTableStatementGoal);
    	if (count($tableDifferences['updateTheseColumns']) > 0 ||
    		count($tableDifferences['createTheseColumns']) > 0) {
    		$updatesWereNeeded = true;
    	}
    	// make the changes
    	$this->updateATableBasedOnDifferences($tableName, $tableDifferences);
    	
    	// verify that there are now no changes that need to be made.
    	$tableDifferences = $this->getTableDifferences($tableName, $createTableStatementGoal);
    	
    	if (count($tableDifferences['updateTheseColumns']) > 0 || 
    		count($tableDifferences['createTheseColumns']) > 0) {
    	
    		$this->logger->errorMessage("There are still differences after updating the " . 
    			$tableName . " table. " . print_r($tableDifferences, true));
    		
    	} else if ($updatesWereNeeded) {
    		$this->logger->infoMessage("No more differences found after updating the " .
    			$tableName . " table columns. All is well.");
    	}
    }
    
    function getTableDifferences($tableName, $createTableStatementGoal) {
    	
    	// get the current create table statement
    	$existingTableSQL = $this->dao->getCreateTableDDL($tableName);
    	
    	$existingTableSQL = strtolower($this->removeCommentsFromColumns($existingTableSQL));
    	$createTableStatementGoal = strtolower(
    		$this->removeCommentsFromColumns($createTableStatementGoal));
    	
    	// remove the "COLLATE xxx" from the columns.
    	$removeCollatePattern = '/collate \w+ ?/';
    	$existingTableSQL = preg_replace($removeCollatePattern, "", $existingTableSQL);
    	$createTableStatementGoal = preg_replace($removeCollatePattern, "", $createTableStatementGoal);
    	
    	// remove the int size format from columns because it doesn't matter.
    	$removeIntSizePattern = '/( \w*?int)(\(\d+\))/m';
    	$existingTableSQL = preg_replace($removeIntSizePattern, "$1", $existingTableSQL);
    	$createTableStatementGoal = preg_replace($removeIntSizePattern, "$1", $createTableStatementGoal);
    	
    	// get column names and types pattern;
    	$colNamesAndTypesPattern = "/\s+?(`(\w+?)` (\w.+)\s?),/";
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
    	
    	// normalize minor differences between mysql versions
    	$newGoalTableDDL = array();
    	foreach ($goalTableMatchesColumnDDL as $oneDDLLine) {
    		$newVal = str_replace("default '0'", "default 0", $oneDDLLine);
    		array_push($newGoalTableDDL, $newVal);
    	}
    	$goalTableMatchesColumnDDL = $newGoalTableDDL;
    	$newExistingTableDDL = array();
    	foreach ($existingTableMatchesColumnDDL as $oneDDLLine) {
    		$newVal = str_replace("default '0'", "default 0", $oneDDLLine);
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
    
    function updateATableBasedOnDifferences($tableName, $tableDifferences) {
    	
    	$dropTheseColumns = $tableDifferences['dropTheseColumns'];
    	$updateTheseColumns = $tableDifferences['updateTheseColumns'];
    	$createTheseColumns = $tableDifferences['createTheseColumns'];
    	$goalTableMatchesColumnDDL = $tableDifferences['goalTableMatchesColumnDDL'];
    	$existingTableMatchesColumnDDL = $tableDifferences['existingTableMatchesColumnDDL'];
    	$goalTableMatches = $tableDifferences['goalTableMatches'];
    	$goalTableMatchesColumnNames = $tableDifferences['goalTableMatchesColumnNames'];
    	
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
    	foreach ($updateTheseColumns as $colDDL) {
    		// find the colum name.
    		$matchIndex = array_search($colDDL, $goalTableMatches[1]);
    		$colName = $goalTableMatchesColumnNames[$matchIndex];
    		
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
    
    /** Create table DDL is returned without comments on any columns.
     * @param string $existingTableSQL
     */
	    function removeCommentsFromColumns($createTableDDL) {
	    	if ($createTableDDL === null) {
	    		return '';
	    	}
	    	return preg_replace('/ (?:COMMENT.+?,[\r\n])/', ",\n", (string) $createTableDDL);
	    }

    function updateTableEngineToInnoDB() {
    	// get a list of all tables.
        global $wpdb;
    	$result = $this->dao->getTableEngines();
    	$logsTable = $this->dao->doTableNameReplacements("{wp_abj404_logsv2}");
    	
    	// if any rows are found then update the tables.
    	if (array_key_exists('rows', $result) && !empty($result['rows'])) {
    		$rows = $result['rows'];
    		foreach ($rows as $row) {
    		    $tableName = array_key_exists('table_name', $row) ? $row['table_name'] :
    		      (array_key_exists('TABLE_NAME', $row) ? $row['TABLE_NAME'] : '');
    		    $engine = array_key_exists('engine', $row) ? $row['engine'] :
    		      (array_key_exists('ENGINE', $row) ? $row['ENGINE'] : '');
    		    
		        $query = null;
    		    // Use MyISAM because optimize table is slow otherwise.
                if ($tableName == $logsTable && $this->dao->isMyISAMSupported()) {
                    if (strtolower($engine) != 'myisam') {
                        $this->logger->infoMessage("Updating " . $tableName . " to MyISAM.");
                        $query = 'alter table `' . $tableName . '` engine = MyISAM;';
                    }
                  
                } else if (strtolower($engine) != 'innodb') {
                    $this->logger->infoMessage("Updating " . $tableName . " to InnoDB.");
                    $query = 'alter table `' . $tableName . '` engine = InnoDB;';
                }
                
                if ($query == null) {
                    // no updates are necessary for this table.
                    continue;  
                }
                
                $result = $this->dao->queryAndGetResults($query, array("log_errors" => false));
                $this->logger->infoMessage("I changed an engine: " . $query);
                
                if ($result['last_error'] != null && $result['last_error'] != '' &&
                  strpos($result['last_error'], 'Index column size too large') !== false) {
                    
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
     * @return array|null Array of [collation, charset] or null if retrieval failed.
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
	 * @return array|null Array of [collation, charset] or null if parsing failed.
	 */
	function getTableCollationFromShowCreate($tableName) {
		$query = "SHOW CREATE TABLE `$tableName`";
		$results = $this->dao->queryAndGetResults($query);

		// Check for query errors or empty results
		if (!empty($results['last_error'])) {
			$this->logger->debugMessage("SHOW CREATE TABLE failed for $tableName: " . $results['last_error']);
			return null;
		}

		if (empty($results['rows'][0]) || !is_array($results['rows'][0])) {
			$this->logger->debugMessage("SHOW CREATE TABLE returned no data for $tableName.");
			return null;
		}

		// Use array_values to handle varying column name cases ('Create Table', 'CREATE TABLE', etc.)
		// SHOW CREATE TABLE returns: [table_name, create_statement]
		$row = array_values($results['rows'][0]);
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
	 * @return array|null Array of [collation, charset] or null if query failed.
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
			return preg_replace('/[^A-Za-z0-9_]/', '', $collation);
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
		 * @param array $tableNames
		 * @param array $tableCollations Optional map: table => [collation, charset]
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
				if (!is_array($row) || count($row) < 2) {
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
			$rows = $vars['rows'] ?? [];
			if (!empty($rows)) {
				$row = $rows[0];
				$value = $row['Value'] ?? ($row['value'] ?? '');
				$value = $this->sanitizeCollationIdentifier((string)$value);
				if ($value !== '' && stripos($value, 'utf8mb4') !== false) {
					return $value;
				}
			}

			return 'utf8mb4_unicode_ci';
		}
		
			/** Ensure our tables use utf8mb4 (do not alter WordPress core tables). */
			function correctCollations() {
				global $wpdb;
			
			$redirectsTable = $this->dao->doTableNameReplacements("{wp_abj404_redirects}");
			$logsTable = $this->dao->doTableNameReplacements("{wp_abj404_logsv2}");
			$lookupTable = $this->dao->doTableNameReplacements("{wp_abj404_lookup}");
			$permalinkCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_permalink_cache}");
			$spellingCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_spelling_cache}");
			
			$abjTableNames = array($redirectsTable, $logsTable, $lookupTable, $permalinkCacheTable, $spellingCacheTable);

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

				if (!empty($results['last_error']) &&
					strpos($results['last_error'], "Index column size too large") !== false) {

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
			$rows = $results['rows'];
			if (empty($rows)) {
				return false;
			}

			$collationKey = null;
			$firstRow = $rows[0];
			foreach (array_keys($firstRow) as $key) {
				if ($this->f->strtolower($key) === 'collation') {
					$collationKey = $key;
					break;
				}
			}
			if ($collationKey === null) {
				$this->logger->warn("SHOW FULL COLUMNS returned no Collation column for {$tableName}");
				return null;
			}

			foreach ($rows as $row) {
				$colCollation = $row[$collationKey] ?? null;
				if ($colCollation === null || trim((string)$colCollation) === '') {
					continue; // Non-character columns
				}
				$colCollation = trim((string)$colCollation);
				$colCharset = explode('_', $colCollation)[0] ?? '';

				if ($colCharset !== $targetCharset || $colCollation !== $targetCollation) {
					return true;
				}
			}

			return false;
		}
    
    /** Delete all non-primary indexes from a table.
     * @param string $tableName */
    function deleteIndexes($tableName) {
    	
    	// get the indexes list.
    	$results = $this->dao->queryAndGetResults("show index from " . $tableName . 
    		" where key_name != 'PRIMARY'");
    	$rows = $results['rows'];
    	
    	if (empty($rows)) {
    		return;
    	}
    	
    	// find the key_name column because the case can be different on different systems.
    	$keyNameColumn = 'key_name';
    	$aRow = $rows[0];
    	foreach (array_keys($aRow) as $someKey) {
    		if ($this->f->strtolower($someKey) == 'key_name') {
    			$keyNameColumn = $someKey;
    			break;
    		}
    	}
    	
    	foreach ($rows as $row) {
    		// delete them
    		$query = "alter table " . $tableName . " drop index " . $row[$keyNameColumn];
    		$this->dao->queryAndGetResults($query);
    	}
    }

    /**
     * Migrate existing redirects from absolute paths to relative paths.
     * This is a one-time migration for upgrading from versions prior to 2.37.0.
     * Fixes Issue #24: Redirects now survive WordPress subdirectory changes.
     *
     * Uses a single atomic SQL UPDATE statement - no locks or transactions needed.
     *
     * @return array Migration results with counts
     */
    function migrateURLsToRelativePaths() {
        global $wpdb;

        $abj404logging = ABJ_404_Solution_Logging::getInstance();

        // Get current WordPress subdirectory
        $homeURL = get_home_url();
        $urlPath = parse_url($homeURL, PHP_URL_PATH);

        if ($urlPath === false || $urlPath === null) {
            $urlPath = '';
        }

        $decodedPath = rawurldecode(rtrim($urlPath, '/'));
        $subdirectory = preg_replace('/[\x00-\x1F\x7F]/', '', $decodedPath);

        $results = array(
            'redirects_updated' => 0,
            'subdirectory' => $subdirectory,
            'errors' => array()
        );

        // Skip if WordPress is at domain root (no subdirectory)
        if (empty($subdirectory) || $subdirectory === '/') {
            $abj404logging->debugMessage("No subdirectory detected. Migration skipped.");
            return $results;
        }

        $startTime = microtime(true);
        $redirectsTable = $this->dao->getPrefixedTableName('abj404_redirects');

        $abj404logging->infoMessage("Migrating redirects table to relative paths...");

        // Single SQL UPDATE - atomic at database level, no transaction needed
        // Uses CHAR_LENGTH() for UTF-8 multibyte character safety
        $subdirectoryWithSlash = $subdirectory . '/';

        $updateQuery = $wpdb->prepare(
            "UPDATE {$redirectsTable}
             SET url = CASE
                 WHEN url = %s OR url = %s THEN '/'
                 WHEN url LIKE %s THEN CONCAT('/', SUBSTRING(url, CHAR_LENGTH(%s) + 1))
                 ELSE url
             END
             WHERE url = %s OR url = %s OR url LIKE %s",
            $subdirectory,                                  // CASE: exact match /blog
            $subdirectoryWithSlash,                         // CASE: with slash /blog/
            $wpdb->esc_like($subdirectoryWithSlash) . '%', // CASE: with path /blog/*
            $subdirectoryWithSlash,                         // SUBSTRING length calculation
            $subdirectory,                                  // WHERE: exact match
            $subdirectoryWithSlash,                         // WHERE: with slash
            $wpdb->esc_like($subdirectoryWithSlash) . '%'  // WHERE: with path
        );

        $updateResult = $wpdb->query($updateQuery);

        // Check for errors
        if ($updateResult === false) {
            $results['errors'][] = "Failed to update redirects: " . $wpdb->last_error;
            $abj404logging->errorMessage("Migration failed: " . $wpdb->last_error);
        } else {
            $results['redirects_updated'] = $updateResult;

            $duration = microtime(true) - $startTime;
            $abj404logging->infoMessage(sprintf(
                "Migrated %d redirects in %.4f seconds.",
                $results['redirects_updated'],
                $duration
            ));

            // Note: Log entries are intentionally NOT migrated for performance.
            // Historical logs with absolute paths are display-only and don't affect functionality.

            // Mark migration as complete
            update_option('abj404_migrated_to_relative_paths', '1');
            update_option('abj404_migration_results', $results);
            $abj404logging->infoMessage("Migration to relative paths completed successfully.");
        }

        return $results;
    }


    function updatePluginCheck() {
        
        $pluginInfo = $this->dao->getLatestPluginVersion();
        
        $shouldUpdate = $this->shouldUpdate($pluginInfo);
        
        if ($shouldUpdate) {
            $this->doUpdatePlugin($pluginInfo);
        }
    }
    
    function doUpdatePlugin($pluginInfo) {

        $this->logger->infoMessage("Attempting update to " . $pluginInfo['version']);
        
        // do the update.
        if (!class_exists('WP_Upgrader')) {
        	$this->logger->infoMessage("Including WP_Upgrader for update.");
        	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }        
        if (!class_exists('Plugin_Upgrader')) {
        	$this->logger->infoMessage("Including Plugin_Upgrader for update.");
        	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        }
        if (!function_exists('show_message')) {
        	$this->logger->infoMessage("Including misc.php for update.");
        	require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
        	$this->logger->warn("There was an issue including the Plugin_Upgrader class.");
        	return;
        }
        if (!function_exists('show_message')) {
        	$this->logger->warn("There was an issue including the misc.php class.");
        	return;
        }
        
        $this->logger->infoMessage("Includes for update complete. Updating... ");
        
        ob_start();
        $upgrader = new Plugin_Upgrader();
        $upret = $upgrader->upgrade(ABJ404_SOLUTION_BASENAME);
        if ($upret) {
            $this->logger->infoMessage("Plugin successfully upgraded to " . $pluginInfo['version']);
            
        } else if ($upret instanceof WP_Error) {
            $this->logger->infoMessage("Plugin upgrade error " . 
                json_encode($upret->get_error_codes()) . ": " . json_encode($upret->get_error_messages()));
        }
        $output = "";
        if (@ob_get_contents()) {
        	$output = @ob_get_contents();
        	@ob_end_clean();
        }
        if ($this->f->strlen(trim($output)) > 0) {
            $this->logger->infoMessage("Upgrade output: " . $output);
        }
        
        $activateResult = activate_plugin(ABJ404_NAME);
        if ($activateResult instanceof WP_Error) {
            $this->logger->errorMessage("Plugin activation error " . 
                json_encode($upret->get_error_codes()) . ": " . json_encode($upret->get_error_messages()));
            
        } else if ($activateResult == null) {
            $this->logger->infoMessage("Successfully reactivated plugin after upgrade to version " . 
                $pluginInfo['version']);
        }        
    }
    
    function shouldUpdate($pluginInfo) {
        
        
        $options = $this->logic->getOptions(true);
        $latestVersion = $pluginInfo['version'];
        
        if (ABJ404_VERSION == $latestVersion) {
            $this->logger->debugMessage("The latest plugin version is already installed (" . 
                    ABJ404_VERSION . ").");
            return false;
        }
        
        // don't overwrite development versions.
        if (version_compare(ABJ404_VERSION, $latestVersion) == 1) {
            $this->logger->infoMessage("Development version: A more recent version is installed than " . 
                    "what is available on the WordPress site (" . ABJ404_VERSION . " / " . 
                     $latestVersion . ").");
            return false;
        }
        
        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
        if (in_array($serverName, array('127.0.0.1', '::1', 'localhost'))) {
            $this->logger->infoMessage("Update narrowly avoided on localhost.");
            return false;
        }        
        
        // 1.12.0 becomes array("1", "12", "0")
        $myVersionArray = explode(".", ABJ404_VERSION);
        $latestVersionArray = explode(".", $latestVersion);

        // check the latest date to see if it's been long enough to update.
        $lastUpdated = $pluginInfo['last_updated'];
        $lastReleaseDate = new DateTime($lastUpdated);
        $todayDate = new DateTime();
        $dateInterval = $lastReleaseDate->diff($todayDate);
        $daysDifference = $dateInterval->days;
        
        // if there's a new minor version then update.
        // only update if it was released at least 3 days ago.
        if ($myVersionArray[0] == $latestVersionArray[0] && 
        	$myVersionArray[1] == $latestVersionArray[1] && 
        	intval($myVersionArray[2]) < intval($latestVersionArray[2]) &&
        	$daysDifference >= 3) {
        		
            $this->logger->infoMessage("A new minor version is available (" . 
                    $latestVersion . "), currently version " . ABJ404_VERSION . " is installed.");
            return true;
        }

        $minDaysDifference = $options['days_wait_before_major_update'];
        if ($daysDifference >= $minDaysDifference) {
            $this->logger->infoMessage("The latest major version is old enough for updating automatically (" . 
                    $minDaysDifference . "days minimum, version " . $latestVersion . " is " . $daysDifference . 
                    " days old), currently version " . ABJ404_VERSION . " is installed.");
            return true;
        }

        return false;
    }

    /**
     * Schedule N-gram cache rebuild via WP-Cron (async, non-blocking).
     *
     * This schedules background processing of N-gram cache to prevent blocking
     * plugin activation on large sites. The rebuild happens in small batches.
     *
     * MULTISITE COORDINATION:
     * - Uses network-wide state (site_option) to prevent race conditions
     * - Only the main site (blog_id 1) schedules the rebuild
     * - All sites share the same rebuild state via network options
     * - Distributed locking prevents concurrent execution
     *
     * @return bool True if scheduled successfully
     */
    function scheduleNGramCacheRebuild() {
        global $wpdb;

        // MULTISITE: Acquire network-wide lock to prevent race conditions during scheduling
        $lockKey = 'ngram_schedule';
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry($lockKey);

        if (empty($uniqueID)) {
            $this->logger->debugMessage("N-gram rebuild scheduling: Another process holds the lock. Skipping.");
            return true; // Another site is already handling scheduling
        }

        try {
            // MULTISITE: Use network-aware option getter
            $currentOffset = $this->getNetworkAwareOption('abj404_ngram_rebuild_offset', 0);

            // MULTISITE: Count pages across all sites if network-activated
            $totalPages = $this->countTotalPagesForNGramRebuild();

            // If offset is between 0 and total (exclusive), rebuild is in progress
            if ($currentOffset > 0 && $currentOffset < $totalPages) {
                $this->logger->debugMessage("N-gram cache rebuild already in progress at offset {$currentOffset} of {$totalPages}");
                return true;
            }

            // Check if already scheduled
            $nextScheduled = wp_next_scheduled('abj404_rebuild_ngram_cache_hook');
            if ($nextScheduled) {
                $this->logger->debugMessage("N-gram cache rebuild already scheduled for " . date('Y-m-d H:i:s', $nextScheduled));
                return true;
            }

            // MULTISITE: Reset offset using network-aware setter
            $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', 0);

            // Schedule to run in 30 seconds (gives time for activation to complete)
            $scheduleTime = time() + 30;
            $hookName = 'abj404_rebuild_ngram_cache_hook';
            $scheduled = wp_schedule_single_event($scheduleTime, $hookName);

            if ($scheduled === false) {
                // Quick check for DISABLE_WP_CRON as immediate diagnostic
                if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                    $this->logger->errorMessage(
                        "Cannot schedule N-gram cache rebuild: WP-Cron is disabled (DISABLE_WP_CRON=true). " .
                        "Consider enabling WP-Cron or using server-side cron with a fallback mechanism."
                    );
                    return false;
                }

                global $wpdb;

                // Gather comprehensive diagnostic information for troubleshooting
                $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
                $alreadyScheduled = wp_next_scheduled($hookName);
                $dbError = !empty($wpdb->last_error) ? $wpdb->last_error : 'none';
                $rebuildOffset = $this->getNetworkAwareOption('abj404_ngram_rebuild_offset', 'not set');
                $cacheInitialized = $this->getNetworkAwareOption('abj404_ngram_cache_initialized', 'not set');

                $errorMsg = sprintf(
                    "Failed to schedule N-gram cache rebuild. Hook: %s, Schedule time: %d (current: %d), " .
                    "Already scheduled: %s, WP-Cron disabled: %s, DB error: %s, " .
                    "Rebuild offset: %s, Cache initialized: %s, Multisite: %s, Blog ID: %d",
                    $hookName,
                    $scheduleTime,
                    time(),
                    $alreadyScheduled ? date('Y-m-d H:i:s', $alreadyScheduled) : 'no',
                    $cronDisabled ? 'yes' : 'no',
                    $dbError,
                    $rebuildOffset,
                    $cacheInitialized,
                    is_multisite() ? 'yes' : 'no',
                    get_current_blog_id()
                );

                $this->logger->errorMessage($errorMsg);
                return false;
            }

            $context = is_multisite() ? ' (network-wide)' : '';
            $this->logger->infoMessage("N-gram cache rebuild scheduled to start in 30 seconds{$context}.");
            return true;

        } finally {
            // Always release the lock
            $this->syncUtils->synchronizerReleaseLock($uniqueID, $lockKey);
        }
    }

    /**
     * WP-Cron callback: Rebuild N-gram cache in batches (async).
     *
     * Lock Acquisition Flow (per-batch):
     * 1. Create unique ID
     * 2. Write unique ID to lock if empty
     * 3. Sleep 30ms (allows race condition resolution)
     * 4. Read lock back and verify ownership
     * 5. Process batch only if lock belongs to this process
     * 6. Release lock in finally block
     *
     * MULTISITE BEHAVIOR (FIXED):
     * - Processes one site at a time completely before moving to the next site
     * - Uses network options to track: pending sites, current site, and offset within current site
     * - Switches to each site's blog context before processing its pages
     * - Prevents the bug where only the first site got cache entries
     * - Progress tracking shows per-site and network-wide completion status
     *
     * SINGLE SITE BEHAVIOR:
     * - Uses simple offset tracking with network-aware options
     * - Processes all pages in batches until complete
     *
     * @param int $offset Current batch offset (default: 0, overridden by network options)
     * @return void
     */
    function rebuildNGramCacheAsync($offset = 0) {
        global $wpdb;

        // Acquire lock using SynchronizationUtils (per-batch lock)
        $uniqueID = $this->syncUtils->synchronizerAcquireLockTry('ngram_rebuild');
        if (empty($uniqueID)) {
            $this->logger->debugMessage("N-gram async rebuild batch already processing (another process holds lock). Skipping.");
            return;
        }

        try {
            $batchSize = 50; // Smaller batches for async processing
            $maxBatchesPerRun = 20; // Process up to 1000 pages per cron run

            // MULTISITE: Process one site at a time to ensure all sites get cache entries
            if ($this->isNetworkActivated()) {
                // Get or initialize list of pending sites
                $pendingSites = $this->getNetworkAwareOption('abj404_ngram_pending_sites', null);

                if ($pendingSites === null) {
                    // First run: Initialize site list and tracking
                    $sites = get_sites(array('fields' => 'ids', 'number' => 0));
                    $this->updateNetworkAwareOption('abj404_ngram_pending_sites', $sites);
                    $this->updateNetworkAwareOption('abj404_ngram_total_sites', count($sites));
                    $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', 0);
                    $pendingSites = $sites;
                }

                if (empty($pendingSites)) {
                    // All sites processed!
                    $this->updateNetworkAwareOption('abj404_ngram_cache_initialized', '1');
                    $this->updateNetworkAwareOption('abj404_ngram_pending_sites', null);
                    $this->updateNetworkAwareOption('abj404_ngram_total_sites', null);
                    $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', null);
                    $this->logger->infoMessage("N-gram cache rebuild complete for all sites in network!");
                    return;
                }

                // Get current site to process
                $currentSiteId = $pendingSites[0];
                $offset = $this->getNetworkAwareOption('abj404_ngram_current_site_offset', 0);
                $totalSites = $this->getNetworkAwareOption('abj404_ngram_total_sites', count($pendingSites));
                $completedSites = $totalSites - count($pendingSites);

                // Switch to the site being processed
                switch_to_blog($currentSiteId);

                // Count pages for THIS site only
                $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');
                $sitePages = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");

                if ($sitePages == 0) {
                    // This site has no pages, move to next site
                    array_shift($pendingSites);
                    $this->updateNetworkAwareOption('abj404_ngram_pending_sites', $pendingSites);
                    $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', 0);
                    restore_current_blog();

                    $this->logger->infoMessage(sprintf(
                        "Site %d has no pages. Moving to next site. Progress: %d/%d sites completed.",
                        $currentSiteId,
                        $completedSites + 1,
                        $totalSites
                    ));

                    // Reschedule immediately for next site
                    wp_schedule_single_event(time(), 'abj404_rebuild_ngram_cache_hook');
                    return;
                }

                $this->logger->infoMessage(sprintf(
                    "Processing N-gram cache for site %d (Site %d of %d): Offset %d of %d pages",
                    $currentSiteId,
                    $completedSites + 1,
                    $totalSites,
                    $offset,
                    $sitePages
                ));

                // Process batches for current site
                $batchesProcessed = 0;
                $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];

                while ($batchesProcessed < $maxBatchesPerRun && $offset < $sitePages) {
                    try {
                        // Process batch (already switched to correct blog)
                        $stats = $this->ngramFilter->rebuildCache($batchSize, $offset);

                        $totalStats['processed'] += $stats['processed'];
                        $totalStats['success'] += $stats['success'];
                        $totalStats['failed'] += $stats['failed'];

                        $offset += $batchSize;
                        $batchesProcessed++;

                        // Update offset for current site
                        $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', $offset);

                        // Stop if we processed fewer pages than expected (end of site data)
                        if ($stats['processed'] < $batchSize) {
                            break;
                        }

                    } catch (Exception $e) {
                        $this->logger->errorMessage("Error during N-gram rebuild for site {$currentSiteId} at offset {$offset}: " . $e->getMessage());
                        $totalStats['failed'] += $batchSize;
                        $offset += $batchSize;
                        $batchesProcessed++;
                        $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', $offset);
                    }
                }

                $progress = $sitePages > 0 ? round(($offset / $sitePages) * 100, 1) : 100;

                $this->logger->infoMessage(sprintf(
                    "Site %d progress: %d%% complete (%d/%d pages), %d success, %d failed",
                    $currentSiteId,
                    $progress,
                    $offset,
                    $sitePages,
                    $totalStats['success'],
                    $totalStats['failed']
                ));

                // Check if current site is complete
                if ($offset >= $sitePages) {
                    // Site complete! Move to next site
                    array_shift($pendingSites);
                    $this->updateNetworkAwareOption('abj404_ngram_pending_sites', $pendingSites);
                    $this->updateNetworkAwareOption('abj404_ngram_current_site_offset', 0);

                    $this->logger->infoMessage(sprintf(
                        "Site %d complete! Progress: %d/%d sites completed.",
                        $currentSiteId,
                        $completedSites + 1,
                        $totalSites
                    ));
                }

                restore_current_blog();

                // Reschedule for next batch or next site
                wp_schedule_single_event(time() + 10, 'abj404_rebuild_ngram_cache_hook');

            } else {
                // SINGLE SITE: Use original simple logic
                $offset = $this->getNetworkAwareOption('abj404_ngram_rebuild_offset', 0);
                $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');
                $totalPages = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");

                if ($totalPages == 0) {
                    $this->logger->debugMessage("No pages to process. Setting initialized flag.");
                    $this->updateNetworkAwareOption('abj404_ngram_cache_initialized', '1');
                    $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', 0);
                    return;
                }

                $this->logger->infoMessage(sprintf(
                    "Async N-gram rebuild: Processing batch at offset %d of %d total pages",
                    $offset,
                    $totalPages
                ));

                // Process batches
                $batchesProcessed = 0;
                $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];

                while ($batchesProcessed < $maxBatchesPerRun && $offset < $totalPages) {
                    try {
                        $stats = $this->ngramFilter->rebuildCache($batchSize, $offset);

                        $totalStats['processed'] += $stats['processed'];
                        $totalStats['success'] += $stats['success'];
                        $totalStats['failed'] += $stats['failed'];

                        $offset += $batchSize;
                        $batchesProcessed++;

                        $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', $offset);

                        if ($stats['processed'] < $batchSize) {
                            break;
                        }

                    } catch (Exception $e) {
                        $this->logger->errorMessage("Error during async N-gram cache rebuild at offset {$offset}: " . $e->getMessage());
                        $totalStats['failed'] += $batchSize;
                        $offset += $batchSize;
                        $batchesProcessed++;
                        $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', $offset);
                    }
                }

                $progress = $totalPages > 0 ? round(($offset / $totalPages) * 100, 1) : 100;

                $this->logger->infoMessage(sprintf(
                    "Async N-gram rebuild progress: %d%% complete (%d/%d pages), %d success, %d failed",
                    $progress,
                    $offset,
                    $totalPages,
                    $totalStats['success'],
                    $totalStats['failed']
                ));

                if ($offset < $totalPages) {
                    $scheduleTime = time() + 10;
                    $hookName = 'abj404_rebuild_ngram_cache_hook';
                    $scheduled = wp_schedule_single_event($scheduleTime, $hookName, [$offset]);

                    if ($scheduled === false) {
                        // Quick check for DISABLE_WP_CRON as immediate diagnostic
                        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                            $this->logger->errorMessage(
                                "Cannot schedule next N-gram rebuild batch at offset {$offset}: WP-Cron is disabled (DISABLE_WP_CRON=true). " .
                                "Consider enabling WP-Cron or using server-side cron with a fallback mechanism."
                            );
                            // Don't return - let the rebuild complete gracefully, just log the issue
                        } else {
                            global $wpdb;

                            // Gather comprehensive diagnostic information for troubleshooting
                            $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
                        $alreadyScheduled = wp_next_scheduled($hookName, [$offset]);
                        $dbError = !empty($wpdb->last_error) ? $wpdb->last_error : 'none';
                        $cacheInitialized = $this->getNetworkAwareOption('abj404_ngram_cache_initialized', 'not set');

                        $errorMsg = sprintf(
                            "Failed to schedule next N-gram rebuild batch at offset %d. Hook: %s, Schedule time: %d (current: %d), " .
                            "Already scheduled: %s, WP-Cron disabled: %s, DB error: %s, " .
                            "Cache initialized: %s, Progress: %.1f%%, Multisite: %s, Blog ID: %d",
                            $offset,
                            $hookName,
                            $scheduleTime,
                            time(),
                            $alreadyScheduled ? date('Y-m-d H:i:s', $alreadyScheduled) : 'no',
                            $cronDisabled ? 'yes' : 'no',
                            $dbError,
                            $cacheInitialized,
                            $progress,
                            is_multisite() ? 'yes' : 'no',
                            get_current_blog_id()
                        );

                            $this->logger->errorMessage($errorMsg);
                        }
                    }
                } else {
                    // All done!
                    $this->updateNetworkAwareOption('abj404_ngram_cache_initialized', '1');
                    $this->updateNetworkAwareOption('abj404_ngram_rebuild_offset', 0);
                    $this->logger->infoMessage("N-gram cache rebuild complete! Total: {$totalStats['processed']} processed, {$totalStats['success']} success, {$totalStats['failed']} failed.");
                }
            }

        } finally {
            // Always release lock, even if exception occurs
            $this->syncUtils->synchronizerReleaseLock($uniqueID, 'ngram_rebuild');
        }
    }

    /**
     * Rebuild the N-gram cache for all pages (synchronous).
     *
     * WARNING: This method is synchronous and can take minutes on large sites.
     * Use scheduleNGramCacheRebuild() instead for non-blocking background processing.
     *
     * This method is kept for manual rebuilds and testing purposes.
     *
     * @param int $batchSize Number of pages to process per batch (default: 100)
     * @param bool $forceRebuild Force rebuild even if cache is already populated (default: false)
     * @return array Statistics: ['total_pages' => int, 'processed' => int, 'success' => int, 'failed' => int]
     */
    function rebuildNGramCache($batchSize = 100, $forceRebuild = false) {
        global $wpdb;

        // Race condition protection: Use transient lock
        $lockKey = 'abj404_ngram_rebuild_lock';
        if (get_transient($lockKey)) {
            $this->logger->infoMessage("N-gram rebuild already in progress (locked). Skipping.");
            return [
                'total_pages' => 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'locked' => true
            ];
        }

        // Set lock (30 minute timeout for very large sites)
        set_transient($lockKey, time(), 1800);

        try {
            $ngramTable = $this->dao->getPrefixedTableName('abj404_ngram_cache');
            $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');

            // Check if cache is already populated (unless force rebuild)
            if (!$forceRebuild) {
                $existingCount = $wpdb->get_var("SELECT COUNT(*) FROM {$ngramTable}");
                if ($existingCount > 0) {
                    $this->logger->debugMessage("N-gram cache already contains {$existingCount} entries. Skipping rebuild (use forceRebuild=true to override).");
                    delete_transient($lockKey);
                    return [
                        'total_pages' => $existingCount,
                        'processed' => 0,
                        'success' => $existingCount,
                        'failed' => 0,
                        'skipped' => true
                    ];
                }
            }

            $this->logger->debugMessage("Starting N-gram cache rebuild...");

            // Clear existing N-gram cache (only if force rebuild or empty)
            $result = $wpdb->query("TRUNCATE TABLE {$ngramTable}");
            if ($result === false) {
                $this->logger->errorMessage("Failed to truncate N-gram cache table: " . $wpdb->last_error);
                delete_transient($lockKey);
                return ['total_pages' => 0, 'processed' => 0, 'success' => 0, 'failed' => 1, 'error' => $wpdb->last_error];
            }

            // Invalidate coverage ratio caches immediately after truncate
            // This prevents stale transient data from making SpellChecker believe
            // the cache is populated when it's actually empty
            $this->ngramFilter->invalidateCoverageCaches();

            // Get total page count from permalink cache
            $totalPages = $wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");

            if ($totalPages === null) {
                $this->logger->errorMessage("Failed to query permalink cache table: " . $wpdb->last_error);
                delete_transient($lockKey);
                return ['total_pages' => 0, 'processed' => 0, 'success' => 0, 'failed' => 1, 'error' => $wpdb->last_error];
            }

            if ($totalPages == 0) {
                $this->logger->debugMessage("No pages in permalink cache. N-gram cache rebuild skipped (will rebuild when pages are added).");
                delete_transient($lockKey);
                return ['total_pages' => 0, 'processed' => 0, 'success' => 0, 'failed' => 0];
            }

            $this->logger->infoMessage("Rebuilding N-gram cache for {$totalPages} pages in batches of {$batchSize}...");

            // Process in batches
            $offset = 0;
            $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];

            while ($offset < $totalPages) {
                try {
                    $stats = $this->ngramFilter->rebuildCache($batchSize, $offset);

                    $totalStats['processed'] += $stats['processed'];
                    $totalStats['success'] += $stats['success'];
                    $totalStats['failed'] += $stats['failed'];

                    $offset += $batchSize;

                    // Stop if we processed fewer pages than expected (end of data)
                    if ($stats['processed'] < $batchSize) {
                        break;
                    }

                } catch (Exception $e) {
                    $this->logger->errorMessage("Error during N-gram cache rebuild at offset {$offset}: " . $e->getMessage());
                    $totalStats['failed'] += $batchSize; // Mark batch as failed
                    $offset += $batchSize; // Continue to next batch
                }
            }

            $totalStats['total_pages'] = $totalPages;

            $successRate = $totalStats['processed'] > 0 ?
                round(($totalStats['success'] / $totalStats['processed']) * 100, 1) : 0;

            $this->logger->infoMessage(sprintf(
                "N-gram cache rebuild complete: %d pages processed, %d success, %d failed (%.1f%% success rate)",
                $totalStats['processed'],
                $totalStats['success'],
                $totalStats['failed'],
                $successRate
            ));

            return $totalStats;

        } finally {
            // Always release the lock
            delete_transient($lockKey);
        }
    }

    /**
     * Sync missing ngram entries for posts/pages and categories that don't have them yet.
     * This runs as a background task to add entries for newly published content.
     *
     * Uses the same lock as rebuildNGramCache to prevent concurrent execution.
     *
     * @param int $batchSize Number of entries to process per batch (default: 50)
     * @return array Statistics: ['posts_added' => int, 'posts_failed' => int, 'categories_added' => int, 'categories_failed' => int]
     */
    function syncMissingNGrams($batchSize = 50) {
        global $wpdb;

        // Use the same lock as rebuild to prevent concurrent execution
        $lockKey = 'abj404_ngram_rebuild_lock';
        if (get_transient($lockKey)) {
            $this->logger->debugMessage("Ngram sync skipped - rebuild/sync already in progress.");
            return ['posts_added' => 0, 'posts_failed' => 0, 'categories_added' => 0, 'categories_failed' => 0, 'locked' => true];
        }

        // Set lock (30 minute timeout)
        set_transient($lockKey, time(), 1800);

        try {
            $ngramTable = $this->dao->getPrefixedTableName('abj404_ngram_cache');
            $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');

            $stats = ['posts_added' => 0, 'posts_failed' => 0, 'categories_added' => 0, 'categories_failed' => 0];

            // ===== SYNC POSTS =====
            // Find posts in permalink cache that don't have ngram entries
            // Using LEFT JOIN to find missing entries
            $query = $wpdb->prepare(
                "SELECT pc.id
                 FROM {$permalinkCacheTable} pc
                 LEFT JOIN {$ngramTable} ng ON pc.id = ng.id AND ng.type = 'post'
                 WHERE ng.id IS NULL
                 LIMIT %d",
                $batchSize
            );

            $missingIds = $wpdb->get_col($query);

            if ($wpdb->last_error) {
                $this->logger->errorMessage("Failed to query for missing post ngram entries: " . $wpdb->last_error);
                delete_transient($lockKey);
                return array_merge($stats, ['error' => $wpdb->last_error]);
            }

            if (!empty($missingIds)) {
                $this->logger->infoMessage("Found " . count($missingIds) . " posts missing ngram entries. Adding...");

                // Add ngrams for missing posts
                $result = $this->ngramFilter->updateNGramsForPages($missingIds);

                if (isset($result['success'])) {
                    $stats['posts_added'] = $result['success'];
                }
                if (isset($result['failed'])) {
                    $stats['posts_failed'] = $result['failed'];
                }
            } else {
                $this->logger->debugMessage("No missing post ngram entries found. All posts are synced.");
            }

            // ===== SYNC CATEGORIES =====
            // Get all published categories
            $categories = $this->dao->getPublishedCategories();

            if (!empty($categories)) {
                $missingCategories = [];

                // Check which categories are missing from ngram cache
                foreach ($categories as $category) {
                    $termId = $category->term_id;

                    // Check if this category already has an ngram entry
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$ngramTable} WHERE id = %d AND type = 'category'",
                        $termId
                    ));

                    if ($exists == 0) {
                        $missingCategories[] = $category;
                    }
                }

                if (!empty($missingCategories)) {
                    $this->logger->infoMessage("Found " . count($missingCategories) . " categories missing ngram entries. Adding...");

                    // Add ngrams for missing categories
                    foreach ($missingCategories as $category) {
                        try {
                            $termId = $category->term_id;
                            $url = $category->url;

                            if (empty($url) || $url === 'in code') {
                                $this->logger->debugMessage("Skipping category {$termId} - no valid URL");
                                continue;
                            }

                            // Normalize URL
                            $urlNormalized = $this->f->strtolower(trim($url));

                            // Extract N-grams
                            $ngrams = $this->ngramFilter->extractNGrams($urlNormalized);

                            // Store with type='category'
                            $success = $this->ngramFilter->storeNGrams($termId, $url, $urlNormalized, $ngrams, 'category');

                            if ($success) {
                                $stats['categories_added']++;
                            } else {
                                $stats['categories_failed']++;
                            }
                        } catch (Exception $e) {
                            $this->logger->errorMessage("Failed to add ngram for category {$termId}: " . $e->getMessage());
                            $stats['categories_failed']++;
                        }
                    }
                } else {
                    $this->logger->debugMessage("No missing category ngram entries found. All categories are synced.");
                }
            }

            $this->logger->infoMessage("Ngram sync complete: {$stats['posts_added']} posts added, {$stats['posts_failed']} posts failed, {$stats['categories_added']} categories added, {$stats['categories_failed']} categories failed.");

            return $stats;

        } finally {
            // Always release the lock
            delete_transient($lockKey);
        }
    }

    /**
     * Cleanup orphaned ngram entries that don't have corresponding posts/pages or categories.
     * This removes stale entries when posts are deleted or categories are removed.
     *
     * @return array Statistics: ['posts_deleted' => int, 'categories_deleted' => int, 'errors' => int]
     */
    function cleanupOrphanedNGrams() {
        global $wpdb;

        $ngramTable = $this->dao->getPrefixedTableName('abj404_ngram_cache');
        $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');

        $this->logger->debugMessage("Checking for orphaned ngram entries...");

        $stats = ['posts_deleted' => 0, 'categories_deleted' => 0, 'errors' => 0];

        // ===== CLEANUP ORPHANED POSTS =====
        // Find ngram entries for posts that don't exist in permalink cache
        // Using LEFT JOIN to find orphaned entries
        $query = "SELECT ng.id, ng.type
                  FROM {$ngramTable} ng
                  LEFT JOIN {$permalinkCacheTable} pc ON ng.id = pc.id AND ng.type = 'post'
                  WHERE ng.type = 'post' AND pc.id IS NULL";

        $orphanedPosts = $wpdb->get_results($query);

        if ($wpdb->last_error) {
            $this->logger->errorMessage("Failed to query for orphaned post ngram entries: " . $wpdb->last_error);
            return array_merge($stats, ['error' => $wpdb->last_error]);
        }

        if (!empty($orphanedPosts)) {
            $this->logger->infoMessage("Found " . count($orphanedPosts) . " orphaned post ngram entries. Deleting...");

            // Delete each orphaned post entry
            foreach ($orphanedPosts as $entry) {
                $result = $wpdb->delete(
                    $ngramTable,
                    ['id' => $entry->id, 'type' => $entry->type],
                    ['%d', '%s']
                );

                if ($result === false) {
                    $this->logger->errorMessage("Failed to delete orphaned post ngram entry ID {$entry->id}: " . $wpdb->last_error);
                    $stats['errors']++;
                } else {
                    $stats['posts_deleted']++;
                }
            }
        } else {
            $this->logger->debugMessage("No orphaned post ngram entries found.");
        }

        // ===== CLEANUP ORPHANED CATEGORIES =====
        // Get all published categories
        $publishedCategories = $this->dao->getPublishedCategories();
        $publishedCategoryIds = [];

        if (!empty($publishedCategories)) {
            foreach ($publishedCategories as $category) {
                $publishedCategoryIds[] = $category->term_id;
            }
        }

        // Get all category ngram entries
        $categoryNGramEntries = $wpdb->get_results(
            "SELECT DISTINCT id FROM {$ngramTable} WHERE type = 'category'"
        );

        if ($wpdb->last_error) {
            $this->logger->errorMessage("Failed to query for category ngram entries: " . $wpdb->last_error);
            return array_merge($stats, ['error' => $wpdb->last_error]);
        }

        if (!empty($categoryNGramEntries)) {
            $orphanedCategories = [];

            // Find category ngram entries that don't have corresponding published categories
            foreach ($categoryNGramEntries as $entry) {
                if (!in_array($entry->id, $publishedCategoryIds)) {
                    $orphanedCategories[] = $entry->id;
                }
            }

            if (!empty($orphanedCategories)) {
                $this->logger->infoMessage("Found " . count($orphanedCategories) . " orphaned category ngram entries. Deleting...");

                // Delete orphaned category entries
                foreach ($orphanedCategories as $categoryId) {
                    $result = $wpdb->delete(
                        $ngramTable,
                        ['id' => $categoryId, 'type' => 'category'],
                        ['%d', '%s']
                    );

                    if ($result === false) {
                        $this->logger->errorMessage("Failed to delete orphaned category ngram entry ID {$categoryId}: " . $wpdb->last_error);
                        $stats['errors']++;
                    } else {
                        $stats['categories_deleted']++;
                    }
                }
            } else {
                $this->logger->debugMessage("No orphaned category ngram entries found.");
            }
        }

        $this->logger->infoMessage("Orphaned ngram cleanup complete: {$stats['posts_deleted']} posts deleted, {$stats['categories_deleted']} categories deleted, {$stats['errors']} errors.");

        return $stats;
    }

    /**
     * Daily insurance check: verify tables exist and repair if needed.
     *
     * This is a safety net that runs during daily maintenance to catch:
     * - Failed table creation during activation/upgrade
     * - Database corruption or manual table deletions
     * - Edge cases we haven't anticipated
     *
     * Behavior:
     * - Verifies CURRENT site only (per-site cron execution)
     * - In multisite networks, each site's cron verifies its own tables
     * - This avoids O(N²) performance when N sites each loop through N sites
     *
     * The check is lightweight (6 SHOW TABLES queries) and the repair
     * reuses the same idempotent table creation logic used during activation.
     *
     * @return void
     */
    public function runDailyInsuranceCheck() {
        // Always verify current site only
        // Per-site cron execution ensures network coverage without O(N²) duplication
        $this->verifyAndRepairCurrentSite();
    }

    /**
     * Verify and repair tables for the current site only.
     *
     * Checks all 6 required tables for the plugin:
     * - abj404_redirects (redirect rules)
     * - abj404_logsv2 (404 hits and redirect logs)
     * - abj404_lookup (user/location lookups)
     * - abj404_permalink_cache (performance cache)
     * - abj404_spelling_cache (spell-check results cache)
     * - abj404_ngram_cache (n-gram search cache)
     *
     * If ANY table is missing, triggers full table creation/repair.
     *
     * @return void
     */
    private function verifyAndRepairCurrentSite() {
        global $wpdb;

        // Define all required tables
        $requiredTables = [
            'abj404_redirects',
            'abj404_logsv2',
            'abj404_lookup',
            'abj404_permalink_cache',
            'abj404_spelling_cache',
            'abj404_ngram_cache',
        ];

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
     * @return array Statistics: ['deleted' => int, 'errors' => int]
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
    }

    /**
     * Build ngrams for all categories.
     * Should be called during initial setup or manual rebuild.
     *
     * @param int $batchSize Number of categories to process per batch (default: 50)
     * @return array Statistics: ['processed' => int, 'success' => int, 'failed' => int]
     */
    function buildNGramsForCategories($batchSize = 50) {
        $this->logger->debugMessage("Building N-grams for categories...");

        $categories = $this->dao->getPublishedCategories();

        if (empty($categories)) {
            $this->logger->debugMessage("No published categories found.");
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $stats = ['processed' => 0, 'success' => 0, 'failed' => 0];

        foreach ($categories as $category) {
            try {
                $termId = $category->term_id;
                $url = $category->url;

                if (empty($url) || $url === 'in code') {
                    $this->logger->debugMessage("Skipping category {$termId} - no valid URL");
                    continue;
                }

                // Normalize URL
                $urlNormalized = $this->f->strtolower(trim($url));

                // Extract N-grams
                $ngrams = $this->ngramFilter->extractNGrams($urlNormalized);

                // Store with type='category'
                $success = $this->ngramFilter->storeNGrams($termId, $url, $urlNormalized, $ngrams, 'category');

                $stats['processed']++;
                if ($success) {
                    $stats['success']++;
                } else {
                    $stats['failed']++;
                }
            } catch (Exception $e) {
                $this->logger->errorMessage("Failed to build ngram for category {$termId}: " . $e->getMessage());
                $stats['processed']++;
                $stats['failed']++;
            }
        }

        $this->logger->infoMessage("Category N-grams built: {$stats['processed']} processed, {$stats['success']} success, {$stats['failed']} failed.");

        return $stats;
    }

    /**
     * Build ngrams for all tags.
     * Should be called during initial setup or manual rebuild.
     *
     * @param int $batchSize Number of tags to process per batch (default: 50)
     * @return array Statistics: ['processed' => int, 'success' => int, 'failed' => int]
     */
    function buildNGramsForTags($batchSize = 50) {
        $this->logger->debugMessage("Building N-grams for tags...");

        $tags = $this->dao->getPublishedTags();

        if (empty($tags)) {
            $this->logger->debugMessage("No published tags found.");
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $stats = ['processed' => 0, 'success' => 0, 'failed' => 0];

        foreach ($tags as $tag) {
            try {
                $termId = $tag->term_id;
                $url = $tag->url;

                if (empty($url) || $url === 'in code') {
                    $this->logger->debugMessage("Skipping tag {$termId} - no valid URL");
                    continue;
                }

                // Normalize URL
                $urlNormalized = $this->f->strtolower(trim($url));

                // Extract N-grams
                $ngrams = $this->ngramFilter->extractNGrams($urlNormalized);

                // Store with type='tag'
                $success = $this->ngramFilter->storeNGrams($termId, $url, $urlNormalized, $ngrams, 'tag');

                $stats['processed']++;
                if ($success) {
                    $stats['success']++;
                } else {
                    $stats['failed']++;
                }
            } catch (Exception $e) {
                $this->logger->errorMessage("Failed to build ngram for tag {$termId}: " . $e->getMessage());
                $stats['processed']++;
                $stats['failed']++;
            }
        }

        $this->logger->infoMessage("Tag N-grams built: {$stats['processed']} processed, {$stats['success']} success, {$stats['failed']} failed.");

        return $stats;
    }

    /**
     * Build ngrams for all content types (posts, pages, categories, tags).
     * This is the comprehensive rebuild that should be called from the Tools page.
     *
     * @param int $batchSize Number of items to process per batch
     * @return array Combined statistics
     */
    function buildNGramsForAllContent($batchSize = 100) {
        $this->logger->infoMessage("Starting comprehensive N-gram cache build for all content types...");

        // Rebuild posts/pages (existing functionality)
        $postsStats = $this->rebuildNGramCache($batchSize, true);

        // Build categories
        $categoriesStats = $this->buildNGramsForCategories($batchSize);

        // Build tags
        $tagsStats = $this->buildNGramsForTags($batchSize);

        $totalStats = [
            'posts' => $postsStats,
            'categories' => $categoriesStats,
            'tags' => $tagsStats,
            'total_processed' => ($postsStats['processed'] ?? 0) + ($categoriesStats['processed'] ?? 0) + ($tagsStats['processed'] ?? 0),
            'total_success' => ($postsStats['success'] ?? 0) + ($categoriesStats['success'] ?? 0) + ($tagsStats['success'] ?? 0),
            'total_failed' => ($postsStats['failed'] ?? 0) + ($categoriesStats['failed'] ?? 0) + ($tagsStats['failed'] ?? 0)
        ];

        $this->logger->infoMessage("Comprehensive N-gram build complete: {$totalStats['total_processed']} total processed, {$totalStats['total_success']} success, {$totalStats['total_failed']} failed.");

        return $totalStats;
    }

    /**
     * Check if the plugin is network-activated in a multisite environment.
     *
     * @return bool True if network-activated, false otherwise
     */
    private function isNetworkActivated() {
        if (!is_multisite()) {
            return false;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename(ABJ404_FILE));
    }

    /**
     * Get an option value, using network-wide storage in multisite when network-activated.
     *
     * MULTISITE BEHAVIOR:
     * - Network-activated: Uses get_site_option() for network-wide state
     * - Single-site or per-site activation: Uses get_option() for site-specific state
     *
     * This ensures that N-gram rebuild state is shared across all sites in network-activated
     * scenarios, preventing race conditions and duplicate work.
     *
     * @param string $option_name The option name
     * @param mixed $default Default value if option doesn't exist
     * @return mixed The option value
     */
    private function getNetworkAwareOption($option_name, $default = false) {
        if ($this->isNetworkActivated()) {
            return get_site_option($option_name, $default);
        }
        return get_option($option_name, $default);
    }

    /**
     * Update an option value, using network-wide storage in multisite when network-activated.
     *
     * MULTISITE BEHAVIOR:
     * - Network-activated: Uses update_site_option() for network-wide state
     * - Single-site or per-site activation: Uses update_option() for site-specific state
     *
     * This ensures that N-gram rebuild state is shared across all sites in network-activated
     * scenarios, preventing race conditions and duplicate work.
     *
     * @param string $option_name The option name
     * @param mixed $value The value to store
     * @return bool True if updated successfully
     */
    private function updateNetworkAwareOption($option_name, $value) {
        if ($this->isNetworkActivated()) {
            return update_site_option($option_name, $value);
        }
        return update_option($option_name, $value);
    }

    /**
     * Count total pages for N-gram rebuild across all sites if network-activated.
     *
     * MULTISITE BEHAVIOR:
     * - Network-activated: Counts permalink cache entries across ALL sites in the network
     * - Single-site: Counts only current site's permalink cache entries
     *
     * This allows the rebuild process to accurately track progress when processing
     * pages from multiple sites.
     *
     * @return int Total number of pages to process
     */
    private function countTotalPagesForNGramRebuild() {
        global $wpdb;

        if (!$this->isNetworkActivated()) {
            // Single site: count only current site's pages
            $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');
            return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");
        }

        // Multisite network-activated: count pages across all sites
        $sites = get_sites(array('fields' => 'ids', 'number' => 0));
        $totalPages = 0;

        foreach ($sites as $blog_id) {
            switch_to_blog($blog_id);
            $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');
            $sitePages = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$permalinkCacheTable}");
            $totalPages += $sitePages;
            restore_current_blog();
        }

        return $totalPages;
    }
}
