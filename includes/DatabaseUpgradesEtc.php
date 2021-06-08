<?php

// turn on debug for localhost etc
if ($GLOBALS['abj404_display_errors']) {
	error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should all reference one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DatabaseUpgradesEtc {

    /** Create the tables when the plugin is first activated. 
     * @global type $wpdb
     */
    function createDatabaseTables() {
        $refreshPermalinkCache = false;
        
        $this->runInitialCreateTables();
        $this->doRedirectsTableUpdates();
        $refreshPermalinkCache = $this->doPermalinkCacheTableUpdates();
        $this->doLogsTableUpdates();
        
        $me = new ABJ_404_Solution_DatabaseUpgradesEtc();
        $me->correctSpellingCacheTable();        
        
        $me->correctCollations();
        
        $me->updateTableEngineToInnoDB();

        if ($refreshPermalinkCache) {
            $plCache = new ABJ_404_Solution_PermalinkCache();
            $plCache->updatePermalinkCache(1);
        }
    }

    function doLogsTableUpdates() {
    	global $wpdb;
    	$logsTable = $wpdb->prefix . 'abj404_logsv2';
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();

        $result = $abj404dao->queryAndGetResults("show create table " . $logsTable);
        // this encode/decode turns the results into an array from a "stdClass"
        $rows = $result['rows'];
        $row1 = array_values($rows[0]);
        $tableSQL = $row1[1];
        // if the column does not have btree then drop and recreate the index. ""
        if (!$f->regexMatchi("requested_url[^\n]+ USING BTREE", $tableSQL)) {
            if ($f->regexMatchi("KEY[^\n]+requested_url", $tableSQL)) {
                $query = "ALTER TABLE " . $logsTable . " DROP INDEX requested_url";
                $abj404dao->queryAndGetResults($query);
            }
            $query = "ALTER TABLE " . $logsTable . " ADD INDEX requested_url (`requested_url`) USING BTREE";
            $abj404dao->queryAndGetResults($query);
        }
        if ($f->regexMatchi("referrer2[^\n]+", $tableSQL)) {
            $query = 'ALTER TABLE ' . $logsTable . ' drop column `referrer2`';
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Dropped column referrer2 on " . $logsTable);
        }
        if (!$f->regexMatchi("referrer[^\n]+DEFAULT NULL", $tableSQL)) {
            $query = 'ALTER TABLE ' . $logsTable . ' CHANGE `referrer` `referrer` VARCHAR(512) NULL DEFAULT NULL';
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Changed referrer to allow null on " . $logsTable);
        }
        if (!$f->regexMatchi("requested_url_detail", $tableSQL)) {
            $query = 'ALTER TABLE ' . $logsTable . ' ADD `requested_url_detail` varchar(512) DEFAULT NULL '
                    . 'after `requested_url` ';
            $abj404dao->queryAndGetResults($query);
        }
        if (!$f->regexMatchi("username[^\n]+bigint", $tableSQL)) {
            $query = 'ALTER TABLE ' . $logsTable . ' ADD `username` bigint(20) DEFAULT NULL '
                    . 'after `requested_url_detail` ';
            $abj404dao->queryAndGetResults($query);
        }
        if (!$f->regexMatchi("username[^\n]+ USING BTREE", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " ADD INDEX username (`username`) USING BTREE";
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Added index for username on " . $logsTable);
        }
        if (!$f->regexMatchi("min_log_id[^\n]+ DEFAULT NULL", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " ADD min_log_id BOOLEAN NULL DEFAULT NULL";
            $abj404dao->queryAndGetResults($query);

            // set the min_log_id for all logs entries that were created before the column was created.
            $abj404logging->infoMessage("Setting the min_log_id for the logs table: Begin.");
            $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/logsSetMinLogID.sql");
            $query = $f->str_replace('{wp_abj404_logsv2}', $logsTable, $query);
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Setting the min_log_id for the logs table: Done.");
        }
        if (!$f->regexMatchi("KEY [^\n]+min_log_id", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " ADD INDEX min_log_id (min_log_id)";
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Added index for min_log_id on " . $logsTable);
        }
        if (!$f->regexMatchi("requested_url[^\n]+2048", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " CHANGE `requested_url` `requested_url` VARCHAR(2048) ";
            $abj404dao->queryAndGetResults($query);
        }
        if (!$f->regexMatchi("requested_url_detail[^\n]+2048", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " CHANGE `requested_url_detail` `requested_url_detail` VARCHAR(2048) ";
            $abj404dao->queryAndGetResults($query);
        }
        if (!$f->regexMatchi("dest_url[^\n]+2048", $tableSQL)) {
            $query = "ALTER TABLE " . $logsTable . " CHANGE `dest_url` `dest_url` VARCHAR(2048) ";
            $abj404dao->queryAndGetResults($query);
        }
    }

    function doPermalinkCacheTableUpdates() {
    	global $wpdb;
    	$permalinkCacheTable = $wpdb->prefix . 'abj404_permalink_cache';
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        $refreshPermalinkCache = false;

        $result = $abj404dao->queryAndGetResults("show create table " . $permalinkCacheTable);
        $rows = $result['rows'];
        $row1 = array_values($rows[0]);
        $tableSQL = $row1[1];

        if ($f->regexMatchi("structure", $tableSQL)) {
            $query = "ALTER TABLE " . $permalinkCacheTable . " DROP `structure`;";
            $abj404dao->queryAndGetResults($query);
        }
        
        if (!$f->regexMatchi("meta", $tableSQL)) {
            // clear the table because without the meta column it's useless.
            $abj404dao->truncatePermalinkCacheTable();
            // create the column.
            $query = "ALTER TABLE " . $permalinkCacheTable . " ADD `meta` TINYTEXT NOT NULL;";
            $abj404dao->queryAndGetResults($query);

            $refreshPermalinkCache = true;
        }        
        
        if (!$f->regexMatchi("url_length", $tableSQL)) {
            $query = "ALTER TABLE " . $permalinkCacheTable . " ADD `url_length` INT NULL DEFAULT NULL, ADD INDEX (`url_length`);";
            $abj404dao->queryAndGetResults($query);
            $query = "update " . $permalinkCacheTable . " set url_length = length(url)";
            $abj404dao->queryAndGetResults($query);
        }

        return $refreshPermalinkCache;
    }

    function doRedirectsTableUpdates() {
    	global $wpdb;
    	$redirectsTable = $wpdb->prefix . "abj404_redirects";
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();

        // since 2.3.1. changed from fulltext to btree for Christos. https://github.com/aaron13100/404solution/issues/21
        $result = $abj404dao->queryAndGetResults("show create table " . $redirectsTable);
        $rows = $result['rows'];
        $row1 = array_values($rows[0]);
        $tableSQL = $row1[1];
        // if the column does not have btree then drop and recreate the index.
        if (!$f->regexMatchi("url[^\n]+ USING BTREE", $tableSQL)) {
            if ($f->regexMatchi("KEY[^\n]+url", $tableSQL)) {
                $query = "ALTER TABLE " . $redirectsTable . " DROP INDEX url";
                $abj404dao->queryAndGetResults($query);
            }
            $query = "ALTER TABLE " . $redirectsTable . " ADD INDEX url (`url`) USING BTREE";
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Updated redirects table URL column to use a btree index.");
        }
        if (!$f->regexMatchi("KEY[^\n]+status", $tableSQL)) {
            $query = "ALTER TABLE " . $redirectsTable . " ADD INDEX status (`status`)";
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Updated redirects table. Added index to the STATUS column.");
        }
        if (!$f->regexMatchi("KEY[^\n]+disabled", $tableSQL)) {
            $query = "ALTER TABLE " . $redirectsTable . " ADD INDEX disabled (`disabled`)";
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Updated redirects table. Added index to the DISABLED column.");
        }
        if (!$f->regexMatchi("final_dest[^\n]+ USING BTREE", $tableSQL)) {
            if ($f->regexMatchi("KEY[^\n]+final_dest", $tableSQL)) {
                $query = "ALTER TABLE " . $redirectsTable . " DROP INDEX final_dest";
                $abj404dao->queryAndGetResults($query);
            }
            $query = "ALTER TABLE " . $redirectsTable . " ADD INDEX final_dest (`final_dest`) USING BTREE";
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Updated redirects table FINAL_DEST column to use a btree index.");
        }
        if (!$f->regexMatchi("status[^\n]+TINYINT\(1\)", $tableSQL)) {
            $query = "ALTER TABLE " . $redirectsTable . "   CHANGE `status` `status` TINYINT(1) NOT NULL, \n" .
                    "  CHANGE `type` `type` TINYINT(1) NOT NULL, \n" .
                    "  CHANGE `code` `code` SMALLINT(3) NOT NULL, \n" .
                    "  CHANGE `disabled` `disabled` TINYINT(1) NOT NULL DEFAULT '0' \n"; 
            $abj404dao->queryAndGetResults($query);
            $abj404logging->infoMessage("Updated redirects table STATUS column type to TINYINT.");
        }
        if (!$f->regexMatchi("url[^\n]+2048", $tableSQL)) {
            $query = "ALTER TABLE " . $redirectsTable . " CHANGE `url` `url` VARCHAR(2048)";
            $abj404dao->queryAndGetResults($query);
        }
        if (!$f->regexMatchi("final_dest[^\n]+2048", $tableSQL)) {
            $query = "ALTER TABLE " . $redirectsTable . " CHANGE `final_dest` `final_dest` VARCHAR(2048)";
            $abj404dao->queryAndGetResults($query);
        }
    }

    function runInitialCreateTables() {
    	global $wpdb;
    	$redirectsTable = $wpdb->prefix . "abj404_redirects";
    	$logsTable = $wpdb->prefix . 'abj404_logsv2';
    	$lookupTable = $wpdb->prefix . 'abj404_lookup';
    	$permalinkCacheTable = $wpdb->prefix . 'abj404_permalink_cache';
    	$spellingCacheTable = $wpdb->prefix . 'abj404_spelling_cache';
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createPermalinkCacheTable.sql");
        $query = $f->str_replace('{wp_abj404_permalink_cache}', $permalinkCacheTable, $query);
        $abj404dao->queryAndGetResults($query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createSpellingCacheTable.sql");
        $query = $f->str_replace('{wp_abj404_spelling_cache}', $spellingCacheTable, $query);
        $abj404dao->queryAndGetResults($query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createRedirectsTable.sql");
        $query = $f->str_replace('{redirectsTable}', $redirectsTable, $query);
        $abj404dao->queryAndGetResults($query);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLogTable.sql");
        $query = $f->str_replace('{wp_abj404_logsv2}', $logsTable, $query);
        $abj404dao->queryAndGetResults($query);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/createLookupTable.sql");
        $query = $f->str_replace('{wp_abj404_lookup}', $lookupTable, $query);
        $abj404dao->queryAndGetResults($query);
    }
    
    function correctSpellingCacheTable() {
    	global $wpdb;
    	$spellingCacheTable = $wpdb->prefix . 'abj404_spelling_cache';
    	$abj404logging = ABJ_404_Solution_Logging::getInstance();
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	$spellingCacheTable = $wpdb->prefix . 'abj404_spelling_cache';
    	$f = ABJ_404_Solution_Functions::getInstance();
    	
    	$result = $abj404dao->queryAndGetResults("show create table " . $spellingCacheTable);
    	$rows = $result['rows'];
    	$row1 = array_values($rows[0]);
    	$tableSQL = $row1[1];
    	
    	// look for this line.
    	// UNIQUE KEY `url` (`url`(250)) USING BTREE
    	if (!$f->regexMatchi("UNIQUE KEY `url[^\n]+[\d]+[^\n]+ USING BTREE", $tableSQL) ||
            !$f->regexMatchi("ENGINE[^\n]*=[^\n]*InnoDB", $tableSQL)) {
            
    		if ($f->regexMatchi("KEY[^\n]+url", $tableSQL)) {
    			$query = "ALTER TABLE " . $spellingCacheTable . " DROP INDEX url";
    			$abj404dao->queryAndGetResults($query);
    		}

            // make it use InnoDB if it doesn't already.
            $query = 'alter table ' . $spellingCacheTable . ' engine = InnoDB;';
            $abj404dao->queryAndGetResults($query);

    		// the column will be unique so we remove any data that might not be unique.
    		$query = "truncate TABLE " . $spellingCacheTable;
    		$abj404dao->queryAndGetResults($query);
    		
    		// try to create an index of 250 and if it doesn't work then keep trying to
    		// create a smaller and smaller index until it works.
    		for ($i = 250; $i > 50; $i -= 10) {
    			$query = "ALTER TABLE " . $spellingCacheTable . 
    				" ADD UNIQUE url (`url`(" . $i . ")) USING BTREE";
    			$results = $abj404dao->queryAndGetResults($query, 
    				array('log_errors' => false));
    			
    			if (!empty($results['last_error'])) {
    				$abj404logging->infoMessage("Tried creating an index (" . 
    					$i . ") on " . $spellingCacheTable . " but it didn't work.");
    				
    			} else {
    				$abj404logging->infoMessage("Successfully created an index (" .
    					$i . ") on " . $spellingCacheTable . ".");
    				break;
    			}
    		}
    		$abj404logging->infoMessage("Updated spelling cache table URL column index length.");
    	}
    }
    
    function updateTableEngineToInnoDB() {
    	// get a list of all tables.
    	$abj404logging = ABJ_404_Solution_Logging::getInstance();
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    	$result = $abj404dao->getMyISAMTables();
    	
    	// if any rows are found then update the tables.
    	if (array_key_exists('rows', $result) && !empty($result['rows'])) {
    		$rows = $result['rows'];
    		foreach ($rows as $row) {
    			$schema = $row['table_schema'];
    			$table = $row['table_name'];
    			$query = 'alter table `' . $schema . '`.`' . $table . 
    				'` engine = InnoDB;';
    			$abj404logging->infoMessage("Updating " . $schema . '.' . 
    				$table . "to InnoDB.");
    			$abj404dao->queryAndGetResults($query);
    		}
    	}
    }

    function correctCollations() {
        global $wpdb;
        $f = ABJ_404_Solution_Functions::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        
        $collationNeedsUpdating = false;
        
        $redirectsTable = $wpdb->prefix . "abj404_redirects";
        $logsTable = $wpdb->prefix . "abj404_logsv2";
        $lookupTable = $wpdb->prefix . "abj404_lookup";
        $permalinkCacheTable = $wpdb->prefix . "abj404_permalink_cache";
        $spellingCacheTable = $wpdb->prefix . "abj404_spelling_cache";
        $postsTable = $wpdb->prefix . 'posts';
        
        $abjTableNames = array($redirectsTable, $logsTable, $lookupTable, $permalinkCacheTable, $spellingCacheTable);

        // get the target collation
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getCollations.sql");
        $query = $f->str_replace('{table_names}', "'" . $postsTable . "'", $query);
        $query = $f->str_replace('{TABLE_SCHEMA}', $wpdb->dbname, $query);
        $results = $abj404dao->queryAndGetResults($query);
        $rows = $results['rows'];
        $row = $rows[0];
        $postsTableCollation = $row['table_collation'];
        $postsTableCharset = $row['character_set_name'];
        
        // check our own tables to see if they match.
        foreach ($abjTableNames as $tableName) {
            // get collations of our tables and a target table.
            $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getCollations.sql");
            $query = $f->str_replace('{table_names}', "'" . $tableName . "'", $query);
            $query = $f->str_replace('{TABLE_SCHEMA}', $wpdb->dbname, $query);
            $results = $abj404dao->queryAndGetResults($query);
            $rows = $results['rows'];
            $row = $rows[0];
            $abjTableCollation = $row['table_collation'];
            
            if ($abjTableCollation != $postsTableCollation) {
                $collationNeedsUpdating = true;
                break;
            }
        }
        
        // if they match then we're done.
        if (!$collationNeedsUpdating) {
            return;
        }
        
        // if they don't match then update our tables to match teh target tables.
        $abj404logging->infoMessage("Updating collation from " . $abjTableCollation . " to " .
                $postsTableCollation);

        foreach ($abjTableNames as $tableName) {
            $query = "alter table {table_name} convert to charset " . $postsTableCharset . 
                    " collate " . $postsTableCollation;
            $query = $f->str_replace('{table_name}', $tableName, $query);
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
        $abj404logic = new ABJ_404_Solution_PluginLogic();
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
        
        if (in_array($_SERVER['SERVER_NAME'], array('127.0.0.1', '::1', 'localhost'))) {
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
