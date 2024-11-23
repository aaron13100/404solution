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
    	$abj404logging = ABJ_404_Solution_Logging::getInstance();
    	$syncUtils = ABJ_404_Solution_SynchronizationUtils::getInstance();
    	
    	$synchronizedKeyFromUser = "create_db_tables";
    	$uniqueID = $syncUtils->synchronizerAcquireLockTry($synchronizedKeyFromUser);
    	
    	if ($uniqueID == '' || $uniqueID == null) {
    		$abj404logging->debugMessage("Avoiding multiple calls for creating database tables.");
    		return;
    	}
    	
    	try {
    		$this->reallyCreateDatabaseTables($updatingToNewVersion);
    		
    	} catch (Exception $e) {
    		$abj404logging->errorMessage("Error creating database tables. ", $e);
    	}
    	$syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
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
    	$plCache = ABJ_404_Solution_PermalinkCache::getInstance();
    	$plCache->updatePermalinkCache(1);
    	
    	if ($updatingToNewVersion) {
    		$this->correctIssuesAfter();
    	}
    }
    
    /** Correct any possible outstanding issues. */
    function correctIssuesBefore() {
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	$abj404dao->correctDuplicateLookupValues();
    	
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
    	$abj404logging = ABJ_404_Solution_Logging::getInstance();
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
		// Fetch all tables starting with "abj404", case-insensitive
		$dbName = esc_sql($wpdb->dbname);
		$query = "SELECT table_name 
			FROM information_schema.tables 
			WHERE table_schema = '{$dbName}' 
			AND LOWER(table_name) LIKE '%abj404%'";
		$results = $abj404dao->queryAndGetResults($query);
		
		foreach ($results['rows'] as $row) {
			$tableName = $row['table_name'] ?? $row['TABLE_NAME'];

			if (!empty($tableName)) {
				$lowercaseName = strtolower($tableName);
		
				// Check if the table name is already lowercase, skip if it is
				if ($tableName !== $lowercaseName) {
					// Rename the table to lowercase
					$renameQuery = "RENAME TABLE `{$tableName}` TO `{$lowercaseName}`";
					$abj404dao->queryAndGetResults($renameQuery, 
						['ignore_errors' => ["already exists"]]);
					$abj404logging->infoMessage("Renamed table {$tableName} to {$lowercaseName}\n");
				}
			} else {
				$abj404logging->warn("I didn't find a table name in the results of this row: " . 
					print_r($row, true));
			}
		}
	}
    
    function correctMatchData() {
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	$abj404dao->queryAndGetResults("delete from {wp_abj404_spelling_cache} " .
    		"where matchdata is null or matchdata = ''");
    }
    
	/** When certain columns are created we have to populate data.
     * @param string $tableName
     * @param string $colName
     */
    function handleSpecificCases($tableName, $colName) {
    	if (strpos($tableName, 'abj404_logsv2') !== false && $colName == 'min_log_id') {
    		global $wpdb;
    		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    		$f = ABJ_404_Solution_Functions::getInstance();
    		$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/logsSetMinLogID.sql");
    		$abj404dao->queryAndGetResults($query);
    	}
    	if (strpos($tableName, 'abj404_permalink_cache') !== false && $colName == 'url_length') {
    		// clear the permalink cache so that the url length column will be populated.
    		// this could be more efficient but I'll assume that's not necessary.
    		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    		$abj404dao->truncatePermalinkCacheTable();
    	}
    }
    
    function runInitialCreateTables() {
    	$f = ABJ_404_Solution_Functions::getInstance();
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	global $wpdb;
    	$redirectsTable = $abj404dao->doTableNameReplacements("{wp_abj404_redirects}");
    	$logsTable = $abj404dao->doTableNameReplacements("{wp_abj404_logsv2}");
    	$lookupTable = $abj404dao->doTableNameReplacements("{wp_abj404_lookup}");
    	$permalinkCacheTable = $abj404dao->doTableNameReplacements("{wp_abj404_permalink_cache}");
    	$spellingCacheTable = $abj404dao->doTableNameReplacements("{wp_abj404_spelling_cache}");

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createPermalinkCacheTable.sql");
        $abj404dao->queryAndGetResults($query);
        $this->verifyColumns($permalinkCacheTable, $query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createSpellingCacheTable.sql");
        $abj404dao->queryAndGetResults($query);
        $this->verifyColumns($spellingCacheTable, $query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createRedirectsTable.sql");
        $abj404dao->queryAndGetResults($query);
        $this->verifyColumns($redirectsTable, $query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogTable.sql");
        $abj404dao->queryAndGetResults($query);
        $this->verifyColumns($logsTable, $query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLookupTable.sql");
        $abj404dao->queryAndGetResults($query);
        $this->verifyColumns($lookupTable, $query);
    }
    
    function createIndexes() {
    	global $wpdb;
    	$f = ABJ_404_Solution_Functions::getInstance();
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	$redirectsTable = $abj404dao->doTableNameReplacements("{wp_abj404_redirects}");
    	$logsTable = $abj404dao->doTableNameReplacements("{wp_abj404_logsv2}");
    	$lookupTable = $abj404dao->doTableNameReplacements("{wp_abj404_lookup}");
    	$permalinkCacheTable = $abj404dao->doTableNameReplacements("{wp_abj404_permalink_cache}");
    	$spellingCacheTable = $abj404dao->doTableNameReplacements("{wp_abj404_spelling_cache}");
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createPermalinkCacheTable.sql");
    	$query = $f->str_replace('{wp_abj404_permalink_cache}', $permalinkCacheTable, $query);
    	$this->verifyIndexes($permalinkCacheTable, $query);
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createSpellingCacheTable.sql");
    	$query = $f->str_replace('{wp_abj404_spelling_cache}', $spellingCacheTable, $query);
    	$this->verifyIndexes($spellingCacheTable, $query);
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createRedirectsTable.sql");
    	$query = $f->str_replace('{redirectsTable}', $redirectsTable, $query);
    	$this->verifyIndexes($redirectsTable, $query);
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogTable.sql");
    	$query = $f->str_replace('{wp_abj404_logsv2}', $logsTable, $query);
    	$this->verifyIndexes($logsTable, $query);
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLookupTable.sql");
    	$query = $f->str_replace('{wp_abj404_lookup}', $lookupTable, $query);
    	$this->verifyIndexes($lookupTable, $query);
    }

    function verifyIndexes($tableName, $createTableStatementGoal) {
    	$abj404logging = ABJ_404_Solution_Logging::getInstance();
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	$f = ABJ_404_Solution_Functions::getInstance();
    	
    	// get the current create table statement
    	$existingTableSQL = $abj404dao->getCreateTableDDL($tableName);
    	
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
    	$engineLoc = $f->strpos($existingTableSQL, ") engine");
    	if ($engineLoc !== false) {
    		$existingTableSQL = substr($existingTableSQL, 0, $engineLoc);
    	}
    	$commentLoc = $f->strpos($existingTableSQL, ") comment");
    	if ($commentLoc !== false) {
    		$existingTableSQL = substr($existingTableSQL, 0, $commentLoc);
    	}
    	$engineLoc = $f->strpos($createTableStatementGoal, ") engine");
    	if ($engineLoc !== false) {
    		$createTableStatementGoal = substr($createTableStatementGoal, 0, $engineLoc);
    	}
    	$commentLoc = $f->strpos($createTableStatementGoal, ") comment");
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
    		$abj404logging->infoMessage(self::$uniqID . ": On " . $tableName . 
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
    		$results = $abj404dao->queryAndGetResults($query, 
    			array('ignore_errors' => array("check that column/key exists",
    			"check that it exists")));
    		if ($results['last_error'] == null || $results['last_error'] == '') {
    			$abj404logging->infoMessage("Successfully dropped index: " . $query);
    		} else {
    			$abj404logging->infoMessage("Failed to drop index with query: " . $query . 
    				";;; because: " . $results['last_error']);
    		}
    		
    		// if we're adding a unique key then remove the duplicates.
    		// this was causing issues for some people.
    		$spellingCacheTableName = $abj404dao->doTableNameReplacements('{wp_abj404_spelling_cache}');
    		if (strtolower($tableName) == $spellingCacheTableName) {
    			$abj404dao->deleteSpellingCache();
    		}
    		
    		// create the index.
    		$addStatement = "alter table " . $tableName . " add " . $indexDDL;
    		$abj404dao->queryAndGetResults($addStatement);
    		$abj404logging->infoMessage("I added an index: " . $addStatement);
    	}
    }
    
    function verifyColumns($tableName, $createTableStatementGoal) {
    	$abj404logging = ABJ_404_Solution_Logging::getInstance();
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
    	
    		$abj404logging->errorMessage("There are still differences after updating the " . 
    			$tableName . " table. " . print_r($tableDifferences, true));
    		
    	} else if ($updatesWereNeeded) {
    		$abj404logging->infoMessage("No more differences found after updating the " .
    			$tableName . " table columns. All is well.");
    	}
    }
    
    function getTableDifferences($tableName, $createTableStatementGoal) {
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	
    	// get the current create table statement
    	$existingTableSQL = $abj404dao->getCreateTableDDL($tableName);
    	
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
    	$abj404logging = ABJ_404_Solution_Logging::getInstance();
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	
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
    		$abj404dao->queryAndGetResults($query);
    		$abj404logging->infoMessage("I dropped a column (1): " . $query);
    	}
    	
    	// say why we're doing what we're doing.
    	if (count($updateTheseColumns) > 0) {
    		$abj404logging->infoMessage(self::$uniqID . ": On " . $tableName .
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
    			$abj404dao->queryAndGetResults($updateColStatement);
    			$abj404logging->infoMessage("I updated a column: " . $updateColStatement);
    			
    		} else {
    			// create the column.
    			$createColStatement = "alter table " . $tableName . " add " . $colDDL;
    			$abj404dao->queryAndGetResults($createColStatement);
    			$abj404logging->infoMessage("I added a column: " . $createColStatement);
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
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	$result = $abj404dao->getTableEngines();
    	$logsTable = $abj404dao->doTableNameReplacements("{wp_abj404_logsv2}");
    	
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
                if ($tableName == $logsTable && $abj404dao->isMyISAMSupported()) {
                    if (strtolower($engine) != 'myisam') {
                        $abj404logging->infoMessage("Updating " . $tableName . " to MyISAM.");
                        $query = 'alter table `' . $tableName . '` engine = MyISAM;';
                    }
                  
                } else if (strtolower($engine) != 'innodb') {
                    $abj404logging->infoMessage("Updating " . $tableName . " to InnoDB.");
                    $query = 'alter table `' . $tableName . '` engine = InnoDB;';
                }
                
                if ($query == null) {
                    // no updates are necessary for this table.
                    continue;  
                }
                
                $result = $abj404dao->queryAndGetResults($query, array("log_errors" => false));
                $abj404logging->infoMessage("I changed an engine: " . $query);
                
                if ($result['last_error'] != null && $result['last_error'] != '' &&
                  strpos($result['last_error'], 'Index column size too large') !== false) {
                    
                    // delete the indexes, try again, and create the indexes later.
                    $this->deleteIndexes($tableName);
                  
                    $abj404dao->queryAndGetResults($query,
                      array("ignore_errors" => array("Unknown storage engine")));
                    $abj404logging->infoMessage("I tried to change an engine again: " . $query);
                }
    		}
    	}
    }

    /** Make the collations of our tables match the WP_POSTS table collation. */
    function correctCollations() {
        global $wpdb;
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        
        $collationNeedsUpdating = false;
        
        $redirectsTable = $abj404dao->doTableNameReplacements("{wp_abj404_redirects}");
        $logsTable = $abj404dao->doTableNameReplacements("{wp_abj404_logsv2}");
        $lookupTable = $abj404dao->doTableNameReplacements("{wp_abj404_lookup}");
        $permalinkCacheTable = $abj404dao->doTableNameReplacements("{wp_abj404_permalink_cache}");
        $spellingCacheTable = $abj404dao->doTableNameReplacements("{wp_abj404_spelling_cache}");
        $postsTable = $wpdb->prefix . 'posts';
        
        $abjTableNames = array($redirectsTable, $logsTable, $lookupTable, $permalinkCacheTable, $spellingCacheTable);

        // get the target collation
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getCollations.sql");
        $query = str_replace('{table_name}', $postsTable, $query);
        $query = str_replace('{TABLE_SCHEMA}', $wpdb->dbname, $query);
        $results = $abj404dao->queryAndGetResults($query);
        $rows = $results['rows'];
        $row = $rows[0];
        $row = array_change_key_case($row);
        $postsTableCollation = $row['table_collation'];
        $postsTableCharset = $row['character_set_name'];
        
        // check our own tables to see if they match.
        foreach ($abjTableNames as $tableName) {
            // get collations of our tables and a target table.
            $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getCollations.sql");
            $query = str_replace('{table_name}', $tableName, $query);
            $query = str_replace('{TABLE_SCHEMA}', $wpdb->dbname, $query);
            $results = $abj404dao->queryAndGetResults($query);
            $rows = $results['rows'];
            $row = $rows[0];
            $row = array_change_key_case($row);
            $abjTableCollation = '';
            if (array_key_exists('table_collation', $row)) {
            	$abjTableCollation = $row['table_collation'];
            } else {
            	$abj404logging->errorMessage("table_collation does not exist on sql results. "
            		. "Results: " . print_r($row, true));
            }
            
            if ($abjTableCollation != $postsTableCollation) {
                $collationNeedsUpdating = true;
                break;
            }
        }
        
        // if they match then we're done.
        if (!$collationNeedsUpdating) {
            return;
        }
        
        // if they don't match then update our tables to match the target tables.
        $abj404logging->infoMessage("Updating collation from " . $abjTableCollation . " to " .
                $postsTableCollation);

        foreach ($abjTableNames as $tableName) {
        	// update the collation
            $query = "alter table {table_name} convert to charset " . $postsTableCharset . 
                    " collate " . $postsTableCollation;
            $query = str_replace('{table_name}', $tableName, $query);
            $results = $abj404dao->queryAndGetResults($query, 
            	array('ignore_errors' => array("Index column size too large")));
            
            if ($results['last_error'] != null && $results['last_error'] != '' && 
            	strpos($results['last_error'], "Index column size too large") !== false) {
            		
            	$abj404logging->infoMessage("Collation change for " . $tableName . " failed " . 
            		"because of an Index column size too large. Deleting indexes and trying " .
            		"again..");
            		
            	// delete indexes and try again.
            	$this->deleteIndexes($tableName);
            	
            	$abj404dao->queryAndGetResults($query);
            	$abj404logging->infoMessage("I tried to change a collation again: " . $query);
            	
           	} else if ($results['last_error'] == null || $results['last_error'] == '') {
           		$abj404logging->infoMessage("I succesfully changed the collation of " . 
           			$tableName . " to " . $postsTableCollation);
           	}
        }
    }
    
    /** Delete all non-primary indexes from a table.
     * @param string $tableName */
    function deleteIndexes($tableName) {
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	$f = ABJ_404_Solution_Functions::getInstance();
    	
    	// get the indexes list.
    	$results = $abj404dao->queryAndGetResults("show index from " . $tableName . 
    		" where key_name != 'PRIMARY'");
    	$rows = $results['rows'];
    	
    	if (empty($rows)) {
    		return;
    	}
    	
    	// find the key_name column because the case can be different on different systems.
    	$keyNameColumn = 'key_name';
    	$aRow = $rows[0];
    	foreach (array_keys($aRow) as $someKey) {
    		if ($f->strtolower($someKey) == 'key_name') {
    			$keyNameColumn = $someKey;
    			break;
    		}
    	}
    	
    	foreach ($rows as $row) {
    		// delete them
    		$query = "alter table " . $tableName . " drop index " . $row[$keyNameColumn];
    		$abj404dao->queryAndGetResults($query);
    	}
    }
    
    function updatePluginCheck() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        
        $pluginInfo = $abj404dao->getLatestPluginVersion();
        
        $shouldUpdate = $this->shouldUpdate($pluginInfo);
        
        if ($shouldUpdate) {
            $this->doUpdatePlugin($pluginInfo);
        }
    }
    
    function doUpdatePlugin($pluginInfo) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();

        $abj404logging->infoMessage("Attempting update to " . $pluginInfo['version']);
        
        // do the update.
        if (!class_exists('WP_Upgrader')) {
        	$abj404logging->infoMessage("Including WP_Upgrader for update.");
        	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }        
        if (!class_exists('Plugin_Upgrader')) {
        	$abj404logging->infoMessage("Including Plugin_Upgrader for update.");
        	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        }
        if (!function_exists('show_message')) {
        	$abj404logging->infoMessage("Including misc.php for update.");
        	require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
        	$abj404logging->warn("There was an issue including the Plugin_Upgrader class.");
        	return;
        }
        if (!function_exists('show_message')) {
        	$abj404logging->warn("There was an issue including the misc.php class.");
        	return;
        }
        
        $abj404logging->infoMessage("Includes for update complete. Updating... ");
        
        ob_start();
        $upgrader = new Plugin_Upgrader();
        $upret = $upgrader->upgrade(ABJ404_SOLUTION_BASENAME);
        if ($upret) {
            $abj404logging->infoMessage("Plugin successfully upgraded to " . $pluginInfo['version']);
            
        } else if ($upret instanceof WP_Error) {
            $abj404logging->infoMessage("Plugin upgrade error " . 
                json_encode($upret->get_error_codes()) . ": " . json_encode($upret->get_error_messages()));
        }
        $output = "";
        if (@ob_get_contents()) {
        	$output = @ob_get_contents();
        	@ob_end_clean();
        }
        if ($f->strlen(trim($output)) > 0) {
            $abj404logging->infoMessage("Upgrade output: " . $output);
        }
        
        $activateResult = activate_plugin(ABJ404_NAME);
        if ($activateResult instanceof WP_Error) {
            $abj404logging->errorMessage("Plugin activation error " . 
                json_encode($upret->get_error_codes()) . ": " . json_encode($upret->get_error_messages()));
            
        } else if ($activateResult == null) {
            $abj404logging->infoMessage("Successfully reactivated plugin after upgrade to version " . 
                $pluginInfo['version']);
        }        
    }
    
    function shouldUpdate($pluginInfo) {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        $options = $abj404logic->getOptions(true);
        $latestVersion = $pluginInfo['version'];
        
        if (ABJ404_VERSION == $latestVersion) {
            $abj404logging->debugMessage("The latest plugin version is already installed (" . 
                    ABJ404_VERSION . ").");
            return false;
        }
        
        // don't overwrite development versions.
        if (version_compare(ABJ404_VERSION, $latestVersion) == 1) {
            $abj404logging->infoMessage("Development version: A more recent version is installed than " . 
                    "what is available on the WordPress site (" . ABJ404_VERSION . " / " . 
                     $latestVersion . ").");
            return false;
        }
        
        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
        if (in_array($serverName, array('127.0.0.1', '::1', 'localhost'))) {
            $abj404logging->infoMessage("Update narrowly avoided on localhost.");
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
        		
            $abj404logging->infoMessage("A new minor version is available (" . 
                    $latestVersion . "), currently version " . ABJ404_VERSION . " is installed.");
            return true;
        }

        $minDaysDifference = $options['days_wait_before_major_update'];
        if ($daysDifference >= $minDaysDifference) {
            $abj404logging->infoMessage("The latest major version is old enough for updating automatically (" . 
                    $minDaysDifference . "days minimum, version " . $latestVersion . " is " . $daysDifference . 
                    " days old), currently version " . ABJ404_VERSION . " is installed.");
            return true;
        }
        
        return false;
    }
}
