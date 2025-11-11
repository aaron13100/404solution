<?php

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

	/**
	 * Constructor with dependency injection.
	 *
	 * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 * @param ABJ_404_Solution_Functions|null $functions String utilities
	 * @param ABJ_404_Solution_PermalinkCache|null $permalinkCache Permalink cache service
	 * @param ABJ_404_Solution_SynchronizationUtils|null $syncUtils Sync utilities
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
	 */
	public function __construct($dataAccess = null, $logging = null, $functions = null, $permalinkCache = null, $syncUtils = null, $pluginLogic = null) {
		// Use injected dependencies or fall back to getInstance() for backward compatibility
		$this->dao = $dataAccess !== null ? $dataAccess : ABJ_404_Solution_DataAccess::getInstance();
		$this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
		$this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
		$this->permalinkCache = $permalinkCache !== null ? $permalinkCache : ABJ_404_Solution_PermalinkCache::getInstance();
		$this->syncUtils = $syncUtils !== null ? $syncUtils : ABJ_404_Solution_SynchronizationUtils::getInstance();
		$this->logic = $pluginLogic !== null ? $pluginLogic : ABJ_404_Solution_PluginLogic::getInstance();
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
    	
    	try {
    		$this->reallyCreateDatabaseTables($updatingToNewVersion);
    		
    	} catch (Exception $e) {
    		$this->logger->errorMessage("Error creating database tables. ", $e);
    	}
    	$this->syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
    }
    
    private function reallyCreateDatabaseTables($updatingToNewVersion = false) {
		$this->renameAbj404TablesToLowerCase();
		
    	if ($updatingToNewVersion) {
    		$this->correctIssuesBefore();
    	}
    	
    	$this->runInitialCreateTables();
    	
    	$this->correctCollations();
    	
    	$this->updateTableEngineToInnoDB();
    	
    	$this->createIndexes();
    	
    	// we could do this only when a table is created or when the "meta" column is created
    	// but it doesn't take long anyway so we do it every night.
    	$this->permalinkCache->updatePermalinkCache(1);

    	// Run one-time migration to relative paths (Issue #24)
    	if (get_option('abj404_migrated_to_relative_paths') !== '1') {
    		$migrationResults = $this->migrateURLsToRelativePaths();

    		// Show admin notice if migration occurred
    		if ($updatingToNewVersion && !empty($migrationResults['redirects_updated'])) {
    			$message = sprintf(
    				__('404 Solution: Migrated %d redirects and %d log entries to subdirectory-independent format.', '404solution'),
    				$migrationResults['redirects_updated'],
    				$migrationResults['logs_updated']
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
		$dbName = esc_sql($wpdb->dbname);
		$query = "SELECT table_name 
			FROM information_schema.tables 
			WHERE table_schema = '{$dbName}' 
			AND LOWER(table_name) LIKE '%abj404%'";
		$results = $this->dao->queryAndGetResults($query);
		
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
    	if (strpos($tableName, 'abj404_logsv2') !== false && $colName == 'min_log_id') {
    		global $wpdb;
    		$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/logsSetMinLogID.sql");
    		$this->dao->queryAndGetResults($query);
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

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createPermalinkCacheTable.sql");
        $this->dao->queryAndGetResults($query);
        $this->verifyColumns($permalinkCacheTable, $query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createSpellingCacheTable.sql");
        $this->dao->queryAndGetResults($query);
        $this->verifyColumns($spellingCacheTable, $query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createRedirectsTable.sql");
        $this->dao->queryAndGetResults($query);
        $this->verifyColumns($redirectsTable, $query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogTable.sql");
        $this->dao->queryAndGetResults($query);
        $this->verifyColumns($logsTable, $query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLookupTable.sql");
        $this->dao->queryAndGetResults($query);
        $this->verifyColumns($lookupTable, $query);
    }
    
    function createIndexes() {
    	global $wpdb;
    	$redirectsTable = $this->dao->doTableNameReplacements("{wp_abj404_redirects}");
    	$logsTable = $this->dao->doTableNameReplacements("{wp_abj404_logsv2}");
    	$lookupTable = $this->dao->doTableNameReplacements("{wp_abj404_lookup}");
    	$permalinkCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_permalink_cache}");
    	$spellingCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_spelling_cache}");
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createPermalinkCacheTable.sql");
    	$query = $this->f->str_replace('{wp_abj404_permalink_cache}', $permalinkCacheTable, $query);
    	$this->verifyIndexes($permalinkCacheTable, $query);
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createSpellingCacheTable.sql");
    	$query = $this->f->str_replace('{wp_abj404_spelling_cache}', $spellingCacheTable, $query);
    	$this->verifyIndexes($spellingCacheTable, $query);
    	
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
    	$existingTableMatches = null;
    	$goalTableMatches = null;
    	preg_match_all('/\s*?(\w+[^,]*)(,?)[\r\n]/', $existingTableSQL, $existingTableMatches);
    	preg_match_all('/\s*?(\w+[^,]*)(,?)[\r\n]/', $createTableStatementGoal, $goalTableMatches);
    	
    	// create missing columns
    	$goalTableMatchesColumnDDL = $goalTableMatches[1];
    	$existingTableMatchesColumnDDL = $existingTableMatches[1];
    	$createTheseIndexes = array_diff($goalTableMatchesColumnDDL,
    		$existingTableMatchesColumnDDL);
    	
    	// say why we're doing what we're doing.
    	if (count($createTheseIndexes) > 0) {
    		$this->logger->infoMessage(self::$uniqID . ": On " . $tableName . 
    			" I'm adding/updating various indexes because we want: \n`" .
    			print_r($goalTableMatchesColumnDDL, true) . "\n but we have: \n" .
    			print_r($existingTableMatchesColumnDDL, true));
    	}
    	
    	foreach ($createTheseIndexes as $indexDDL) {
    		// get the key name
    		$matches = null;
    		preg_match('/\w+?\s+?\(?`(\w+?)`/', $indexDDL, $matches);
    		$colName = $matches[1];
    		$query = "alter table " . $tableName . " drop index " . $colName;
    		// drop the index in case it already exists.
    		$results = $this->dao->queryAndGetResults($query, 
    			array('ignore_errors' => array("check that column/key exists",
    			"check that it exists")));
    		if ($results['last_error'] == null || $results['last_error'] == '') {
    			$this->logger->infoMessage("Successfully dropped index: " . $query);
    		} else {
    			$this->logger->infoMessage("Failed to drop index with query: " . $query . 
    				";;; because: " . $results['last_error']);
    		}
    		
    		// if we're adding a unique key then remove the duplicates.
    		// this was causing issues for some people.
    		$spellingCacheTableName = $this->dao->doTableNameReplacements('{wp_abj404_spelling_cache}');
    		if (strtolower($tableName) == $spellingCacheTableName) {
    			$this->dao->deleteSpellingCache();
    		}
    		
    		// create the index.
    		$addStatement = "alter table " . $tableName . " add " . $indexDDL;
    		$this->dao->queryAndGetResults($addStatement);
    		$this->logger->infoMessage("I added an index: " . $addStatement);
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
    	return preg_replace('/ (?:COMMENT.+?,[\r\n])/', ",\n", $createTableDDL);
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
     * @param ABJ_404_Solution_DataAccess $abj404dao
     * @param ABJ_404_Solution_Logging $abj404logging
     * @return string|null The collation for the table, or null if the query failed.
     */
	function getTableCollation($tableName) {

		$query = "SHOW CREATE TABLE `$tableName`";
		$results = $this->dao->queryAndGetResults($query);
	
		if (!empty($results['rows'][0]['Create Table'])) {
			$createTableSQL = $results['rows'][0]['Create Table'];
	
			preg_match('/COLLATE=([\w\d_]+)/', $createTableSQL, $collationMatch);
			preg_match('/CHARSET=([\w\d]+)/', $createTableSQL, $charsetMatch);
	
			$collation = $collationMatch[1] ?? null;
			$charset = $charsetMatch[1] ?? null;

			return ($collation && $charset) ? [$collation, $charset] : null;
		} else {
			$this->logger->warn("SHOW CREATE TABLE returned no data for $tableName.");
			return null;
		}
	}
	
	/** Make the collations of our tables match the WP_POSTS table collation. */
	function correctCollations() {
		global $wpdb;
		
		$collationNeedsUpdating = false;
		
		$redirectsTable = $this->dao->doTableNameReplacements("{wp_abj404_redirects}");
		$logsTable = $this->dao->doTableNameReplacements("{wp_abj404_logsv2}");
		$lookupTable = $this->dao->doTableNameReplacements("{wp_abj404_lookup}");
		$permalinkCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_permalink_cache}");
		$spellingCacheTable = $this->dao->doTableNameReplacements("{wp_abj404_spelling_cache}");
		$postsTable = $wpdb->prefix . 'posts';
		
		$abjTableNames = array($redirectsTable, $logsTable, $lookupTable, $permalinkCacheTable, $spellingCacheTable);
	
		// Get collation and charset for wp_posts
		$postsTableData = $this->getTableCollation($postsTable);
	
		if ($postsTableData === null) {
			$this->logger->warn("Failed to retrieve collation/charset for $postsTable. Aborting collation checks.");
			return;
		}
	
		[$postsTableCollation, $postsTableCharset] = $postsTableData;
		
		// Check our own tables to see if they match.
		foreach ($abjTableNames as $tableName) {
			$abjTableData = $this->getTableCollation($tableName);
	
			if ($abjTableData === null) {
				$this->logger->warn("Failed to retrieve collation for $tableName.");
				continue;  // Skip this table if collation can't be determined
			}
	
			[$abjTableCollation, $abjTableCharset] = $abjTableData;
	
			// Compare collations
			if ($abjTableCollation != $postsTableCollation) {
				$collationNeedsUpdating = true;
				break;  // Exit early if update is needed
			}
		}
		
        // if they match then we're done.
		if (!$collationNeedsUpdating) {
			return;
		}
		
		// if they don't match then update our tables to match the target tables.
		$this->logger->infoMessage("Updating collation from $abjTableCollation to $postsTableCollation");
	
		foreach ($abjTableNames as $tableName) {
			// Update the collation
			$query = "ALTER TABLE {table_name} CONVERT TO CHARSET " . $postsTableCharset . 
					 " COLLATE " . $postsTableCollation;
			$query = str_replace('{table_name}', $tableName, $query);
			$results = $this->dao->queryAndGetResults($query, 
				array('ignore_errors' => array("Index column size too large")));
	
			if ($results['last_error'] != null && $results['last_error'] != '' && 
				strpos($results['last_error'], "Index column size too large") !== false) {
				
				$this->logger->infoMessage("Collation change for $tableName failed due to 'Index column size too large'. Deleting indexes and retrying...");
	
				// delete indexes and try again.
				$this->deleteIndexes($tableName);
				
				$this->dao->queryAndGetResults($query);
            	$this->logger->infoMessage("I tried to change a collation again: " . $query);
	
			} else if ($results['last_error'] == null || $results['last_error'] == '') {
				$this->logger->infoMessage("Successfully changed collation of $tableName to $postsTableCollation");
			}
		}
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
     * Migrate existing redirects and logs from absolute paths to relative paths.
     * This is a one-time migration for upgrading from versions prior to 2.37.0.
     * Fixes Issue #24: Redirects now survive WordPress subdirectory changes.
     *
     * @return array Migration results with counts
     */
    function migrateURLsToRelativePaths() {
        global $wpdb;

        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        // Fix CRITICAL #1 (3rd review): Use Functions class for consistent character encoding
        $f = ABJ_404_Solution_Functions::getInstance();

        // Fix CRITICAL #2: Add migration lock to prevent race conditions
        // Fix HIGH #2 (3rd review): Extend lock timeout to 1 hour for large datasets
        if (get_transient('abj404_migration_in_progress')) {
            $abj404logging->infoMessage("Migration already in progress, skipping.");
            return array('errors' => array('Migration already in progress'));
        }
        set_transient('abj404_migration_in_progress', '1', 3600); // 1 hour lock (was 10 min)

        // Get current WordPress subdirectory
        $homeURL = get_home_url();
        $urlPath = parse_url($homeURL, PHP_URL_PATH);

        // Fix Issue #1: Handle parse_url() failure
        if ($urlPath === false || $urlPath === null) {
            $urlPath = '';
        }

        $subdirectory = rtrim($urlPath, '/');

        $results = array(
            'redirects_updated' => 0,
            'logs_updated' => 0,
            'subdirectory' => $subdirectory,
            'errors' => array()
        );

        // Skip if WordPress is at domain root (no subdirectory)
        if (empty($subdirectory) || $subdirectory === '/') {
            $abj404logging->debugMessage("No subdirectory detected. Migration skipped.");
            delete_transient('abj404_migration_in_progress');
            return $results;
        }

        try {
            // Fix HIGH #1: Start transaction for atomic migration
            $wpdb->query('START TRANSACTION');

            // MIGRATE REDIRECTS TABLE
            $redirectsTable = $wpdb->prefix . 'abj404_redirects';

            $abj404logging->infoMessage("Migrating redirects table to relative paths...");

            // Fix CRITICAL #3: Query should find exact matches too (not just LIKE)
            $redirectsQuery = $wpdb->prepare(
                "SELECT id, url FROM {$redirectsTable}
                 WHERE url = %s OR url = %s OR url LIKE %s",
                $subdirectory,                              // Exact match: /blog
                $subdirectory . '/',                        // With slash: /blog/
                $wpdb->esc_like($subdirectory . '/') . '%' // With path: /blog/*
            );

            $redirectsToMigrate = $wpdb->get_results($redirectsQuery);

            // Fix Issue #7: Check for database errors
            if ($redirectsToMigrate === null) {
                throw new Exception("Failed to query redirects table: " . $wpdb->last_error);
            }

            foreach ($redirectsToMigrate as $redirect) {
                // Fix CRITICAL #3: Handle exact subdirectory match specially
                if ($redirect->url === $subdirectory || $redirect->url === $subdirectory . '/') {
                    // Convert exact subdirectory match to root path
                    $newURL = '/';
                    $abj404logging->debugMessage("Converting exact subdirectory match to root for redirect ID {$redirect->id}");
                } else {
                    // Remove subdirectory prefix
                    // Fix CRITICAL #1 (3rd review): Use $f->substr for consistent character encoding
                    $newURL = $f->substr($redirect->url, $f->strlen($subdirectory));

                    // Skip if result is empty or just slash (shouldn't happen due to query, but defensive)
                    if (empty($newURL) || $newURL === '/') {
                        $abj404logging->debugMessage("Skipping redirect ID {$redirect->id} with unexpected empty result");
                        continue;
                    }

                    // Ensure leading slash
                    $newURL = '/' . ltrim($newURL, '/');
                }

                // Update the record
                $updated = $wpdb->update(
                    $redirectsTable,
                    array('url' => $newURL),
                    array('id' => $redirect->id),
                    array('%s'),
                    array('%d')
                );

                if ($updated === false) {
                    throw new Exception("Failed to update redirect ID {$redirect->id}: " . $wpdb->last_error);
                }

                $results['redirects_updated']++;
            }

            $abj404logging->infoMessage("Migrated {$results['redirects_updated']} redirects.");

            // MIGRATE LOGS TABLE
            $logsTable = $wpdb->prefix . 'abj404_logsv2';

            $abj404logging->infoMessage("Migrating logs table to relative paths...");

            // Fix CRITICAL #3: Query should find exact matches too (not just LIKE)
            $logsQuery = $wpdb->prepare(
                "SELECT id, requested_url FROM {$logsTable}
                 WHERE requested_url = %s OR requested_url = %s OR requested_url LIKE %s",
                $subdirectory,                              // Exact match: /blog
                $subdirectory . '/',                        // With slash: /blog/
                $wpdb->esc_like($subdirectory . '/') . '%' // With path: /blog/*
            );

            $logsToMigrate = $wpdb->get_results($logsQuery);

            // Fix Issue #7: Check for database errors
            if ($logsToMigrate === null) {
                throw new Exception("Failed to query logs table: " . $wpdb->last_error);
            }

            foreach ($logsToMigrate as $log) {
                // Fix CRITICAL #3: Handle exact subdirectory match specially
                if ($log->requested_url === $subdirectory || $log->requested_url === $subdirectory . '/') {
                    // Convert exact subdirectory match to root path
                    $newURL = '/';
                    $abj404logging->debugMessage("Converting exact subdirectory match to root for log ID {$log->id}");
                } else {
                    // Remove subdirectory prefix
                    // Fix CRITICAL #1 (3rd review): Use $f->substr for consistent character encoding
                    $newURL = $f->substr($log->requested_url, $f->strlen($subdirectory));

                    // Skip if result is empty or just slash (shouldn't happen due to query, but defensive)
                    if (empty($newURL) || $newURL === '/') {
                        $abj404logging->debugMessage("Skipping log ID {$log->id} with unexpected empty result");
                        continue;
                    }

                    // Ensure leading slash
                    $newURL = '/' . ltrim($newURL, '/');
                }

                // Update the record
                $updated = $wpdb->update(
                    $logsTable,
                    array('requested_url' => $newURL),
                    array('id' => $log->id),
                    array('%s'),
                    array('%d')
                );

                if ($updated === false) {
                    throw new Exception("Failed to update log ID {$log->id}: " . $wpdb->last_error);
                }

                $results['logs_updated']++;
            }

            $abj404logging->infoMessage("Migrated {$results['logs_updated']} log entries.");

            // Fix HIGH #1: Commit transaction
            $wpdb->query('COMMIT');

            // Fix Issue #2: Only mark migration as complete if there are no errors
            if (empty($results['errors'])) {
                update_option('abj404_migrated_to_relative_paths', '1');
                update_option('abj404_migration_results', $results);
                $abj404logging->infoMessage("Migration to relative paths completed successfully.");
            } else {
                $abj404logging->errorMessage("Migration completed with errors. Will retry on next run. Errors: " . implode('; ', $results['errors']));
            }

        } catch (Exception $e) {
            // Fix HIGH #1: Rollback transaction on error
            $wpdb->query('ROLLBACK');
            $results['errors'][] = $e->getMessage();
            $abj404logging->errorMessage("Migration failed and rolled back: " . $e->getMessage());
        } finally {
            // Fix CRITICAL #2: Always release the lock
            delete_transient('abj404_migration_in_progress');
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
}
