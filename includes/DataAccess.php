<?php

/* Functions in this class should all reference one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DataAccess {

    const UPDATE_LOGS_HITS_TABLE_HOOK = 'abj404_updateLogsHitsTableAction';

    const KEY_REDIRECTS_FOR_VIEW_COUNT = 'abj404_redirects-for-view-count';

    /** @var int Maximum age in seconds before hits table is considered stale */
    const HITS_TABLE_MAX_AGE_SECONDS = 300; // 5 minutes

    private static $instance = null;

    /** @var bool Whether the hits table rebuild has been scheduled for this request */
    private static $hitsTableRebuildScheduled = false;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * Constructor with dependency injection.
     * Dependencies are now explicit and visible.
     *
     * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
     * @param ABJ_404_Solution_Logging|null $logging Logging service
     */
    public function __construct($functions = null, $logging = null) {
        // Use injected dependencies or fall back to getInstance() for backward compatibility
        $this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
        $this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
    }

    public static function getInstance() {
        if (self::$instance == null) {
            // For backward compatibility, create with no arguments
            // The constructor will use getInstance() for dependencies
            self::$instance = new ABJ_404_Solution_DataAccess();
        }

        return self::$instance;
    }

    /**
     * Ensure database connection is active and reconnect if necessary.
     *
     * Fix for MySQL Server Gone Away error (reported by 3 users - 7% of errors)
     * This prevents "MySQL server has gone away" errors during long-running operations
     * by checking the connection status and reconnecting if needed.
     *
     * @return bool True if connection is active, false otherwise
     */
    private function ensureConnection() {
        global $wpdb;

        // Check if wpdb exists
        if (!isset($wpdb)) {
            return true; // Assume connection is OK if wpdb doesn't exist
        }

        // Try to check connection (WordPress 3.9+)
        try {
            // Try to call check_connection - if it doesn't exist, we'll catch the error
            $isConnected = $wpdb->check_connection(false);

            // If not connected, attempt reconnection
            if (!$isConnected) {
                $this->logger->debugMessage("Database connection lost, attempting to reconnect...");

                // Attempt to reconnect
                $wpdb->db_connect();

                // Verify reconnection succeeded
                if ($wpdb->check_connection(false)) {
                    $this->logger->debugMessage("Database reconnection successful");
                    return true;
                } else {
                    $this->logger->errorMessage("Failed to reconnect to database");
                    return false;
                }
            }
        } catch (Exception $e) {
            // If check fails, assume connection is OK to avoid breaking functionality
            $this->logger->debugMessage("Connection check failed: " . $e->getMessage());
            return true;
        } catch (Error $e) {
            // Handle fatal errors (e.g., method doesn't exist)
            $this->logger->debugMessage("Connection check not available: " . $e->getMessage());
            return true;
        }

        return true;
    }

    /**
     * Check if a database table exists.
     *
     * Fix for missing table error (reported by 2 users - 4% of errors)
     * This prevents crashes when querying tables that don't exist or have
     * incorrect table prefixes, returning false instead of causing fatal errors.
     *
     * @param string $tableName Full table name to check (including prefix)
     * @return bool True if table exists, false otherwise
     */
    private function tableExists($tableName) {
        global $wpdb;

        if (!isset($wpdb)) {
            return false;
        }

        // Use SHOW TABLES to check existence
        $table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tableName));

        return ($table == $tableName);
    }

    function getLatestPluginVersion() {
        if (!function_exists('plugins_api')) {
              require_once(ABSPATH . 'wp-admin/includes/plugin-install.php');
        }
        if (!function_exists('plugins_api')) {
            $this->logger->infoMessage("I couldn't find the plugins_api function to check for the latest version.");
            return ABJ404_VERSION;
        }

        $pluginSlug = dirname(ABJ404_NAME);

        // set the arguments to get latest info from repository via API ##
        $args = array(
            'slug' => $pluginSlug,
            'fields' => array(
                'version' => true,
                'last_updated' => true,
            )
        );

        /** Prepare our query */
        $call_api = plugins_api('plugin_information', $args);

        /** Check for Errors & Display the results */
        if (is_wp_error($call_api)) {
            $api_error = $call_api->get_error_message();
            $this->logger->infoMessage("There was an API issue checking the latest plugin version ("
                    . $api_error . ")");

            return array('version' => ABJ404_VERSION, 'last_updated' => null);
        }

        return array('version' => $call_api->version, 'last_updated' => $call_api->last_updated);
    }
    
    /** Check wordpress.org for the latest version of this plugin. Return true if the latest version is installed, 
     * false otherwise.
     * @return boolean
     */
    function shouldEmailErrorFile() {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();        
        
        $pluginInfo = $this->getLatestPluginVersion();
        
        $latestVersion = $pluginInfo['version'];
        $currentVersion = ABJ404_VERSION;
        if ($latestVersion == $currentVersion) {
            return true;
        }
        
        if (version_compare(ABJ404_VERSION, $latestVersion) == 1) {
            $this->logger->infoMessage("Development version: A more recent version is installed than " . 
                    "what is available on the WordPress site (" . ABJ404_VERSION . " / " . 
                     $latestVersion . ").");
            return true;
        }
        
        $currentArray = explode(".", $currentVersion);
        $latestArray = explode(".", $latestVersion);
        
        // verify that the version numbers were parsed correctly.
        if (count($currentArray) != 3 || count($latestArray) != 3) {
            $this->logger->errorMessage("Issue parsing version numbers. " . 
                    $currentVersion . ' / ' . $latestVersion);
            
        } else if ($currentArray[0] == $latestArray[0] && $currentArray[1] == $latestArray[1]) {
        	// get the difference in the version numbers.
            $difference = absint(absint($latestArray[2]) - absint($currentArray[2]));
            
            // if the major versions mostly match then send the error file.
            if ($difference <= 1) {
                return true;
            }
        }

        return (ABJ404_VERSION == $pluginInfo['version']);
    }
    
    /** 
     * @global type $wpdb
     */
    function importDataFromPluginRedirectioner() {
        global $wpdb;
        
        $oldTable = $wpdb->prefix . 'wbz404_redirects';
        $newTable = $this->doTableNameReplacements('{wp_abj404_redirects}');
        // wp_wbz404_redirects -- old table
        // wp_abj404_redirects -- new table
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/importDataFromPluginRedirectioner.sql");
        $query = $this->f->str_replace('{OLD_TABLE}', $oldTable, $query);
        $query = $this->f->str_replace('{NEW_TABLE}', $newTable, $query);
        
        $result = $this->queryAndGetResults($query);

        $this->logger->infoMessage("Importing redirectioner SQL result: " . 
                wp_kses_post(json_encode($result)));
        
        return $result;
    }
    
    function doTableNameReplacements($query) {
        global $wpdb;
        
        $replacements = array();
        foreach ($wpdb->tables as $tableName) {
            $replacements['{wp_' . $tableName . '}'] = $wpdb->prefix . $tableName;
        }
        $replacements['{wp_users}'] = $wpdb->users;
        $replacements['{wp_prefix}'] = $wpdb->prefix;
        $replacements['{wp_prefix_lower}'] = $this->getLowercasePrefix();
        
        // wp database table replacements
        $query = $this->f->str_replace(array_keys($replacements), array_values($replacements), $query);
        
        // custom table replacements.
        // for some strings (/404solution-site/%BA%D0%25/) the mb_ereg_replace doesn't work.
        $fpreg = ABJ_404_Solution_FunctionsPreg::getInstance();
        $query = $fpreg->regexReplace('[{]wp_abj404_(.*?)[}]', 
            $this->getLowercasePrefix() . "abj404_\\1", $query);
        
        return $query;
    }

    /**
     * Get the normalized (lowercase) prefix used for all plugin tables.
     * This avoids case-sensitive MySQL filesystems from treating mixed-case
     * prefixes as distinct tables.
     *
     * @return string
     */
    public function getLowercasePrefix() {
        global $wpdb;
        return $this->f->strtolower($wpdb->prefix);
    }

    /**
     * Build a fully-qualified plugin table name using the normalized prefix.
     *
     * @param string $tableSuffix Table name without the WordPress prefix.
     * @return string
     */
    public function getPrefixedTableName($tableSuffix) {
        return $this->getLowercasePrefix() . ltrim($tableSuffix, '_');
    }
    
    /** Returns the create table statement.
     * @param string $tableName */
    function getCreateTableDDL($tableName) {
    	$query = "show create table " . $tableName;
    	$result = $this->queryAndGetResults($query);
    	$rows = $result['rows'];

    	// Handle case where query returns no results (e.g., in test environment)
    	if (empty($rows) || !isset($rows[0]) || !is_array($rows[0])) {
    	    return '';
    	}

    	$row1 = array_values($rows[0]);
    	$existingTableSQL = $row1[1];

    	return $existingTableSQL;
    }

    /**
     * Extract filename from SQL comment wrapper for safe logging.
     *
     * When SQL files are loaded, they're wrapped in comment blocks with the filename.
     * This extracts just the filename (e.g. "file.sql") for production logging
     * without exposing potentially sensitive query content or PII.
     *
     * @param string $query The SQL query potentially containing a filename comment
     * @return string The extracted filename or 'inline-query' if no comment found
     */
    private function extractSqlFilename($query) {
        // Extract filename from: /* -- /path/to/file.sql BEGIN -- */
        if (preg_match('/\/\*\s*-+\s*(.+?\.sql)\s+BEGIN\s*-+\s*\*\//i', $query, $matches)) {
            return basename($matches[1]);
        }
        return 'inline-query';
    }

    /** Return the results of the query in a variable.
     * @param string $query
     * @param array $options
     * @return array
     */
    function queryAndGetResults($query, $options = array()) {
        global $wpdb;

        // Ensure database connection is active (prevents "MySQL server has gone away" errors)
        $this->ensureConnection();

        $ignoreErrorStrings = array();

        $options = array_merge(array('log_errors' => true,
            'log_too_slow' => true, 'ignore_errors' => array(),
            'query_params' => array()),
            $options);

       	$ignoreErrorStrings = $options['ignore_errors'];
        $queryParameters = $options['query_params'];

        $query = $this->doTableNameReplacements($query);

        if (!empty($queryParameters)) {
            $query = $wpdb->prepare($query, $queryParameters);
        }
        
        $timer = new ABJ_404_Solution_Timer();
        
        $result = array();
       	$result['rows'] = $wpdb->get_results($query, ARRAY_A);
        
        $result['elapsed_time'] = $timer->stop();
        $result['last_error'] = $wpdb->last_error;
        $result['last_result'] = $wpdb->last_result;
        $result['rows_affected'] = $wpdb->rows_affected;
        
        if ($wpdb->dbh != null) {
	        try {
	            $result['rows_affected'] = $wpdb->rows_affected;
	        } catch (Exception $ex) {
	    		// don't care. we did our best.
	    	}
        }
        
        $result['insert_id'] = $wpdb->insert_id;
        
        if (!is_array($result['rows'])) {
            // In production (WP_DEBUG off), only log SQL filename to avoid PII exposure
            $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
            $this->logger->errorMessage("Query result is not an array. Query: " . $sqlInfo,
        			new Exception("Query result is not an array."));
        }
        
        if ($options['log_errors'] && $result['last_error'] != '') {
            if ($this->f->strpos($result['last_error'], 
                    " is marked as crashed ") !== false) {
                $this->repairTable($result['last_error']);
            }
            if ($this->f->strpos($result['last_error'],
            		"ALTER TABLE causes auto_increment resequencing") !== false && 
            		$this->f->strpos($result['last_error'], "resulting in duplicate entry") !== false) {
            		$this->repairDuplicateIDs($result['last_error'], $query);
            }

            // ignore any specific errors.
            $reportError = true;
            foreach ($ignoreErrorStrings as $ignoreThis) {
            	if (strpos($result['last_error'], $ignoreThis) !== false) {
            		$reportError = false;
            		break;
            	}
            }
            
            if ($reportError) {
                $stripped_query = 'n/a';
                if ($this->f->strpos($result['last_error'],
                    "WordPress database error: Could not perform query because it contains invalid data") !== false) {
                    $stripped_query = $this->get_stripped_query_result($query);
                }
                
                $extraDataQuery = "select @@max_join_size as max_join_size, " . 
            		"@@sql_big_selects as sql_big_selects, " .
                    "@@character_set_database as character_set_database";
            	$someMySQLVariables = $wpdb->get_results($extraDataQuery, ARRAY_A);
            	$variables = print_r($someMySQLVariables, true);

                if (is_wp_error($query) && $query instanceof WP_Error) {
                    /** @var WP_Error $query */
                    $query = "((" . ABJ_404_Solution_WPUtils::stringify_wp_error($query) . "))";
                }
                if (is_wp_error($variables) && $variables instanceof WP_Error) {
                    /** @var WP_Error $variables */
                    $variables = "((" . ABJ_404_Solution_WPUtils::stringify_wp_error($variables) . "))";
                }
                if (is_wp_error($stripped_query) && $stripped_query instanceof WP_Error) {
                    /** @var WP_Error $stripped_query */
                    $stripped_query = "((" . ABJ_404_Solution_WPUtils::stringify_wp_error($stripped_query) . "))";
                }

                // In production (WP_DEBUG off), only log SQL filename to avoid PII exposure
                $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);

                $this->logger->errorMessage("Ugh. SQL query error: " . $result['last_error'] .
					    ", SQL: " . $sqlInfo .
	            	    ", Execution time: " . round($timer->getElapsedTime(), 2) .
	            	    ", DB ver: " . $wpdb->db_version() .
            		    ", Variables: " . $variables .
            	        ", stripped_query: " . $stripped_query);
            }
            
        } else {
            if ($options['log_too_slow'] && $timer->getElapsedTime() > 5) {
                // In production (WP_DEBUG off), only log SQL filename to avoid PII exposure
                $sqlInfo = (defined('WP_DEBUG') && WP_DEBUG) ? $query : $this->extractSqlFilename($query);
                $this->logger->debugMessage("Slow query (" . round($timer->getElapsedTime(), 2) . " seconds): " .
                        $sqlInfo);
            }
        }
        
        return $result;
    }
    
    /** Try to call strip_invalid_text_from_query and return the result. 
     * @param string $query
     * @return NULL|string|WP_Error
     */
    function get_stripped_query_result($query) {
        try {
            if (!class_exists('wpdb')) {
                return null;
            }
            if (!method_exists('wpdb', 'strip_invalid_text_from_query')) {
                return null;
            }
            
            $filename = ABJ404_PATH . 'includes/php/wordpress/WPDBExtension.php';
            if (!file_exists($filename)) {
                return null;
            }
            require_once $filename;

            $my_custom_db = null;
            if (class_exists('ABJ_404_Solution_WPDBExtension_PHP7')) {
                $my_custom_db = new ABJ_404_Solution_WPDBExtension_PHP7(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
                
            } else if (class_exists('ABJ_404_Solution_WPDBExtension_PHP5')) {
                $my_custom_db = new ABJ_404_Solution_WPDBExtension_PHP5(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            }
            if ($my_custom_db == null) {
                return null;
            }
                        
            $result = $my_custom_db->public_strip_invalid_text_from_query($query);

            // Convert WP_Error to string
            if (is_wp_error($result)) {
                return 'WP_Error: ' . $result->get_error_message();
            }
    
            return $result;
    
        } catch (Exception $e) {
            // oh well.
            return null;
        }
        return null;
    }
    
    function repairTable($errorMessage) {
        
        $re = "Table '(.*\/)?(.+)' is marked as crashed and ";
        $matches = array();

        $this->f->regexMatch($re, $errorMessage, $matches);
        if (!empty($matches) && count($matches) > 2 && $this->f->strlen($matches[2]) > 0) {
            $tableToRepair = $matches[2];
            if ($this->f->strpos($tableToRepair, "abj404") !== false) {
                $query = "repair table " . $tableToRepair;
                $result = $this->queryAndGetResults($query, array('log_errors' => false));
                $this->logger->infoMessage("Attempted to repair table " . $tableToRepair . ". Result: " . 
                        json_encode($result));

                // track how many times we've tried to repair something.
                // only for the certain tables. Exclude the redirects table because people
                // may have spent time creating entries there. Other tables are generated 
                // automatically.
                if (strpos($tableToRepair, 'redirects') === false) {
	                $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
	                $options = $abj404logic->getOptions();
	                if (!array_key_exists('repaired_count', $options)) {
	                	$options['repaired_count'] = 0;
	                }
	                $options['repaired_count'] = intval($options['repaired_count']) + 1;
	                $abj404logic->updateOptions($options);
	                
	                if (intval($options['repaired_count']) > 3 && 
	                		intval($options['repaired_count']) < 7) {
	                		
	                	$upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
	                	$this->queryAndGetResults('drop table ' . $tableToRepair);
	                	$upgradesEtc->createDatabaseTables(false);
	                }
                }
                
            } else {
                // tell someone the table $tableToRepair is broken.
            	$this->logger->warn("The table " . $tableToRepair . " needs to be " . 
            		"repaired with something like: repair table " . $tableToRepair);
            }
        }
    }
    
    function repairDuplicateIDs($errorMessage, $sqlThatWasRun) {
    	
    	$reForID = 'resulting in duplicate entry \'(.+)\' for key';
    	$reForTableName = "ALTER TABLE (.+) ADD ";
    	$matchesForID = null;
    	$matchesForTableName = null;
    	
    	$this->f->regexMatch($reForID, $errorMessage, $matchesForID);
    	$this->f->regexMatch($reForTableName, $sqlThatWasRun, $matchesForTableName);
    	if ($matchesForID != null && $this->f->strlen($matchesForID[1]) > 0 &&
    			$matchesForTableName != null && $this->f->strlen($matchesForTableName[1]) > 0) {

    		$idWithDuplicate = $matchesForID[1];
    		$tableName = $matchesForTableName[1];

    		// Validate that ID is numeric to prevent SQL injection
    		if (!is_numeric($idWithDuplicate)) {
    			$this->logger->errorMessage("Invalid ID extracted from error message: " . $idWithDuplicate);
    			return;
    		}

    		if ($idWithDuplicate == 1) {
    			$idWithDuplicate = 0;
    		}

    		// Use prepared statement to prevent SQL injection
    		$result = $this->queryAndGetResults("delete from " . $tableName . " where id = %d",
    			array('log_errors' => false, 'query_params' => array(absint($idWithDuplicate))));
   			$this->logger->infoMessage("Attempted to fix a duplicate entry issue. Table: " .
   				$tableName . ", Result: " . json_encode($result));
    	}
    }
    
    function executeAsTransaction($statementArray) {
        $exception = null;
        $allIsWell = true;

        global $wpdb;

        try {
            $wpdb->query('START TRANSACTION');

            foreach ($statementArray as $statement) {
                $wpdb->query($statement);
                if ($wpdb->last_error != null) {
                    $allIsWell = false;
                    $this->logger->errorMessage("Error executing SQL transaction: " . $wpdb->last_error);
                    $this->logger->errorMessage("SQL causing the transaction error: " . $statement);
                    break;
                }
            }
        } catch (Throwable $ex) {  // Fixed: Catch Throwable (Exception + Error) for PHP 7+ compatibility
            $allIsWell = false;
            $exception = $ex;
        }

        if ($allIsWell && $exception == null) {
            $wpdb->query('commit');

        } else {
            $wpdb->query('rollback');
        }

        if ($exception != null) {
            throw $exception;
        }
    }
    
    function getOldSlug($post_id) {
    	// Sanitize post_id to prevent SQL injection
    	$post_id = absint($post_id);

    	// we order by meta_id desc so that the first row will have the most recent value.
    	$query = "select meta_value from {wp_postmeta} \nwhere post_id = {post_id} " .
    		" and meta_key = '_wp_old_slug' \n" .
    		" order by meta_id desc";
    	$query = $this->f->str_replace('{post_id}', $post_id, $query);
    	
    	$results = $this->queryAndGetResults($query);
    	
    	$rows = $results['rows'];
    	if ($rows == null || empty($rows)) {
    		return null;
    	}
    	
    	$row = $rows[0];
    	return $row['meta_value'];
    }
    
    function truncatePermalinkCacheTable() {
        global $wpdb;

        $query = "truncate table {wp_abj404_permalink_cache}";
        $this->queryAndGetResults($query);

        // Invalidate coverage ratio since permalink count changed
        ABJ_404_Solution_NGramFilter::getInstance()->invalidateCoverageCaches();
    }
    
    function removeFromPermalinkCache($post_id) {
        global $wpdb;

        $query = "delete from {wp_abj404_permalink_cache} where id = %d";
        $this->queryAndGetResults($query, array('query_params' => array($post_id)));

        // Invalidate coverage ratio since permalink count changed
        ABJ_404_Solution_NGramFilter::getInstance()->invalidateCoverageCaches();
    }
    
    function getIDsNeededForPermalinkCache() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        
        // get the valid post types
        $options = $abj404logic->getOptions();
        $postTypes = $this->f->explodeNewline($options['recognized_post_types']);
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($this->f->strtolower($postType)) . "', ";
        }
        $recognizedPostTypes = rtrim($recognizedPostTypes, ", ");
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getIDsNeededForPermalinkCache.sql");
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        
        $results = $this->queryAndGetResults($query);
        
        return $results['rows'];
    }
    
    function getPermalinkFromCache($id) {
        // Sanitize id to prevent SQL injection
        $id = absint($id);
        $query = "select url from {wp_abj404_permalink_cache} where id = " . $id;
        $results = $this->queryAndGetResults($query);

        $rows = $results['rows'];
        if (empty($rows)) {
            return null;
        }

        $row1 = $rows[0];
        return $row1['url'];
    }

    function getPermalinkEtcFromCache($id) {
        // Sanitize id to prevent SQL injection
        $id = absint($id);
        $query = "select id, url, meta, url_length, post_parent from {wp_abj404_permalink_cache} where id = " . $id;
        $results = $this->queryAndGetResults($query);
        
        $rows = $results['rows'];
        if (empty($rows)) {
            return null;
        }
        
        return $rows[0];
    }
    
    function correctDuplicateLookupValues() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/correctLookupTableIssue.sql");
    	$this->queryAndGetResults($query);
    }
    
    function storeSpellingPermalinksToCache($requestedURLRaw, $returnValue) {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/insertSpellingCache.sql");

        // Sanitize invalid UTF-8 sequences before storing to database
        // This prevents "Could not perform query because it contains invalid data" errors
        // when URLs contain invalid UTF-8 byte sequences (e.g., %c1%1c from scanner probes)
        $cleanURL = $this->f->sanitizeInvalidUTF8($requestedURLRaw);

        $query = $this->f->str_replace('{url}', esc_sql($cleanURL), $query);
        $query = $this->f->str_replace('{matchdata}', esc_sql(json_encode($returnValue)), $query);

        $this->queryAndGetResults($query);
    }
    
    function deleteSpellingCache() {
        $query = "truncate table {wp_abj404_spelling_cache}";

        $this->queryAndGetResults($query);
    }
    
    function getSpellingPermalinksFromCache($requestedURLRaw) {
        // Sanitize invalid UTF-8 before SQL to prevent database errors
        $requestedURLRaw = $this->f->sanitizeInvalidUTF8($requestedURLRaw);
        $query = "select id, url, matchdata from {wp_abj404_spelling_cache} where url = '" . esc_sql($requestedURLRaw) . "'";
        $results = $this->queryAndGetResults($query);
        
        $rows = $results['rows'];
        
        if (empty($rows)) {
            return array();
        }
        
        $row = $rows[0];
        $json = $row['matchdata'];
        $returnValue = json_decode($json);
        
        return $returnValue;
    }
    
    function getTableEngines() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/selectTableEngines.sql");
    	$results = $this->queryAndGetResults($query);
    	return $results;
    }
    
    function isMyISAMSupported() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $supportResults = $abj404dao->queryAndGetResults("SELECT ENGINE, SUPPORT " .
            "FROM information_schema.ENGINES WHERE lower(ENGINE) = 'myisam'",
            array('log_errors' => false));
        
        if (!empty($supportResults) && !empty($supportResults['rows'])) {
            $rows = $supportResults['rows'];
            if (!empty($rows)) {
                $row = $rows[0];
                $supportValue = array_key_exists('support', $row) ? $row['support'] :
                (array_key_exists('SUPPORT', $row) ? $row['SUPPORT'] : "nope");
                
                return strtolower($supportValue) == 'yes';
            }
        }
        return false;
    }
    
    /** Insert data into the database.
     * Create my own insert statement because wordpress messes it up when the field
     * length is too long. this also returns the correct value for the last_query.
     * @global type $wpdb
     * @param string $tableName
     * @param array $dataToInsert
     * @return array
     */
    function insertAndGetResults($tableName, $dataToInsert) {
        $tableName = $this->doTableNameReplacements($tableName);
    
        $columns = array();
        $placeholders = array();
        $values = array();
    
        foreach ($dataToInsert as $column => $value) {
            $columns[] = '`' . $column . '`';
    
            if ($value === null) {
                $placeholders[] = 'NULL';
                // Do not add null values to $values array
            } else {
                $currentDataType = gettype($value);
                if ($currentDataType == 'integer' || $currentDataType == 'double') {
                    $placeholders[] = '%d';
                    $values[] = $value;
                } elseif ($currentDataType == 'boolean') {
                    $placeholders[] = '%d';
                    $values[] = $value ? 1 : 0;
                } else {
                    $placeholders[] = '%s';
                    $values[] = (string)$value;
                }
            }
        }
    
        $sql = 'INSERT INTO `' . $tableName . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    
        return $this->queryAndGetResults($sql, ['query_params' => $values]);
    }
    
   /**
    * @global type $wpdb
    * @return int the total number of redirects that have been captured.
    */
   function getCapturedCount() {
       global $wpdb;
       
       $query = "select count(id) from {wp_abj404_redirects} where status = " . ABJ404_STATUS_CAPTURED;
       $query = $this->doTableNameReplacements($query);
       
       $captured = $wpdb->get_col($query, 0);
       if (empty($captured)) {
           $captured[0] = 0;
       }
       return intval($captured[0]);
   }
    
   /** Get all of the post types from the wp_posts table.
    * @return array An array of post type names. */
   function getAllPostTypes() {
       $query = "SELECT DISTINCT post_type FROM {wp_posts} order by post_type";
       $results = $this->queryAndGetResults($query);
       $rows = $results['rows'];

       $postType = array();

       // Ensure rows is an array before iterating
       if (is_array($rows)) {
           foreach ($rows as $row) {
               array_push($postType, $row['post_type']);
           }
       }

       return $postType;
   }
   
   /** Get the approximate number of bytes used by the logs table.
    * @global type $wpdb
    * @return int
    */
   function getLogDiskUsage() {
       global $wpdb;

       // we have to analyze the table first for the query to be valid.
       $result = $this->queryAndGetResults("ANALYZE TABLE {wp_abj404_logsv2}");

       if ($result['last_error'] != '') {
           $this->logger->errorMessage("Error: " . esc_html($result['last_error']));
           return -1;
       }
       
       $query = 'SELECT (data_length+index_length) tablesize FROM information_schema.tables ' . 
               'WHERE table_name=\'{wp_abj404_logsv2}\'';
       $query = $this->doTableNameReplacements($query);

       $size = $wpdb->get_col($query, 0);
       if (empty($size)) {
           $size[0] = 0;
       }
       return intval($size[0]);
   }

    /**
     * @global type $wpdb
     * @param array $types specified types such as ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED.
     * @param int $trashed 1 to only include disabled redirects. 0 to only include enabled redirects.
     * @return int the number of records matching the specified types.
     */
    function getRecordCount($types = array(), $trashed = 0) {
        $recordCount = 0;

        if (count($types) >= 1) {
            $query = "select count(id) as count from {wp_abj404_redirects} where 1 and (status in (";

            // Use absint() for proper integer sanitization to prevent SQL injection
            $filteredTypes = array_map('absint', $types);
            $typesForSQL = implode(", ", $filteredTypes);
            $query .= $typesForSQL . "))";

            // Use absint() for integer parameter sanitization
            $query .= " and disabled = " . absint($trashed);

            $result = $this->queryAndGetResults($query);
            $rows = $result['rows'];
            if (!empty($rows)) {
	            $row = $rows[0];
	            $recordCount = $row['count'];
            }
        }

        return $recordCount;
    }

    /** Cache key for redirect status counts */
    const CACHE_KEY_REDIRECT_STATUS = 'abj404_redirect_status_counts';

    /** Cache key for captured status counts */
    const CACHE_KEY_CAPTURED_STATUS = 'abj404_captured_status_counts';

    /** Cache TTL in seconds (24 hours - safety net, primary refresh is event-driven invalidation) */
    const STATUS_CACHE_TTL = 86400;

    /** Maximum number of regex redirects to cache per-request (memory guard) */
    const REGEX_CACHE_MAX_COUNT = 50;

    /** Per-request cache for regex redirects (static to persist across getInstance calls) */
    private static $regexRedirectsCache = null;

    /** Flag indicating if regex cache should be skipped (too many redirects) */
    private static $regexCacheDisabled = false;

    /** Queue of log entries to be flushed at shutdown */
    private static $logQueue = [];

    /** Whether shutdown hook has been registered */
    private static $shutdownHookRegistered = false;

    /** Prevent re-entrancy during flush */
    private static $isFlushingLogQueue = false;


    /**
     * Get counts for each redirect status type for display in tabs.
     * Uses transient caching for performance.
     * @param bool $bypassCache If true, skip cache and query database directly
     * @return array An array with keys: all, manual, auto, regex, trash
     */
    function getRedirectStatusCounts($bypassCache = false) {
        // Try to get cached value first
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_REDIRECT_STATUS);
            if ($cached !== false) {
                return $cached;
            }
        }

        $query = "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_MANUAL . " THEN 1 ELSE 0 END) as manual,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_AUTO . " THEN 1 ELSE 0 END) as auto,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_REGEX . " THEN 1 ELSE 0 END) as regex,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash
            FROM {wp_abj404_redirects}";
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query);
        $rows = $result['rows'];

        $counts = array('all' => 0, 'manual' => 0, 'auto' => 0, 'regex' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = $rows[0];
            $counts = array(
                'all' => intval($row['active']),
                'manual' => intval($row['manual']),
                'auto' => intval($row['auto']),
                'regex' => intval($row['regex']),
                'trash' => intval($row['trash'])
            );
        }

        // Cache the result
        set_transient(self::CACHE_KEY_REDIRECT_STATUS, $counts, self::STATUS_CACHE_TTL);

        return $counts;
    }

    /**
     * Get counts for each captured URL status type.
     * Uses transient caching for performance.
     * @param bool $bypassCache If true, skip cache and query database directly
     * @return array Array with keys: all, captured, ignored, later, trash
     */
    function getCapturedStatusCounts($bypassCache = false) {
        // Try to get cached value first
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE_KEY_CAPTURED_STATUS);
            if ($cached !== false) {
                return $cached;
            }
        }

        $query = "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN disabled = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_CAPTURED . " THEN 1 ELSE 0 END) as captured,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_IGNORED . " THEN 1 ELSE 0 END) as ignored,
            SUM(CASE WHEN disabled = 0 AND status = " . ABJ404_STATUS_LATER . " THEN 1 ELSE 0 END) as later,
            SUM(CASE WHEN disabled = 1 THEN 1 ELSE 0 END) as trash
            FROM {wp_abj404_redirects}
            WHERE status IN (" . ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER . ")";
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query);
        $rows = $result['rows'];

        $counts = array('all' => 0, 'captured' => 0, 'ignored' => 0, 'later' => 0, 'trash' => 0);
        if (!empty($rows)) {
            $row = $rows[0];
            $counts = array(
                'all' => intval($row['active']),
                'captured' => intval($row['captured']),
                'ignored' => intval($row['ignored']),
                'later' => intval($row['later']),
                'trash' => intval($row['trash'])
            );
        }

        // Cache the result
        set_transient(self::CACHE_KEY_CAPTURED_STATUS, $counts, self::STATUS_CACHE_TTL);

        return $counts;
    }

    /**
     * Invalidate cached status counts.
     * Call this when redirects are created, updated, or deleted.
     */
    function invalidateStatusCountsCache() {
        delete_transient(self::CACHE_KEY_REDIRECT_STATUS);
        delete_transient(self::CACHE_KEY_CAPTURED_STATUS);
    }

    /**
     * Clear the per-request regex redirects cache.
     * Primarily used for testing. In production, the cache resets automatically
     * on each new request since it uses static variables.
     */
    function clearRegexRedirectsCache() {
        self::$regexRedirectsCache = null;
        self::$regexCacheDisabled = false;
    }

    /**
     * @global type $wpdb
     * @param int $logID only return results that correspond to the URL of this $logID. Use 0 to get all records.
     * @return int the number of records found.
     */
    function getLogsCount($logID) {
        global $wpdb;
        // Sanitize logID to prevent SQL injection
        $logID = absint($logID);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsCount.sql");
        $query = $this->doTableNameReplacements($query);

        if ($logID != 0) {
            $query = $this->f->str_replace('/* {SPECIFIC_ID}', '', $query);
            $query = $this->f->str_replace('{logID}', $logID, $query);
        }
        
        $row = $wpdb->get_row($query, ARRAY_N);
        if (empty($row)) {
            $row[0] = 0;
        }
        $records = $row[0];

        return intval($records);
    }

    /** 
     * @global type $wpdb
     * @return array
     */
    function getRedirectsAll() {
        global $wpdb;
        $query = "select id, url from {wp_abj404_redirects} order by url";
        $query = $this->doTableNameReplacements($query);
        
        $rows = $wpdb->get_results($query, ARRAY_A);
        return $rows;
    }
    
    function doRedirectsExport($tempFile) {
    	global $wpdb;
    	
    	if (file_exists($tempFile)) {
    		ABJ_404_Solution_Functions::safeUnlink($tempFile);
    	}
    	
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
    		"/sql/getRedirectsExport.sql");
    	$query = $this->doTableNameReplacements($query);
    	
    	// we use mysqli here instead of the normal wordpress get_results in order
    	// to get one row at a time, so we don't run out of memory by trying to store
    	// everything in memory all at once.
    	$result = mysqli_query($wpdb->dbh, $query);
    	if ($result) {
    		// write the header
    		$line = 'from_url,status,type,to_url,wp_type';
    		file_put_contents($tempFile, $line . "\n", FILE_APPEND);
    		
    		while (($row = mysqli_fetch_array($result, MYSQLI_ASSOC))) {
    			$line = $row['from_url'] . ',' .
     			$row['status'] . ',' .
     			$row['type'] . ',' .
     			$row['to_url'] . ', ' .
    			$row['type_wp'];
     			file_put_contents($tempFile, $line . "\n", FILE_APPEND);
    		}
    		mysqli_free_result($result);
    	}
    }
    
    /** Only return redirects that have a log entry.
     * @global type $wpdb
     * @global type $abj404dao
     * @return array
     */
    function getRedirectsWithLogs() {
        global $wpdb;
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsWithLogs.sql");
        $query = $this->doTableNameReplacements($query);
        
        $rows = $wpdb->get_results($query, ARRAY_A);
        return $rows;
    }

    /**
     * Get all regex redirects for pattern matching.
     * Uses per-request caching when redirect count is <= 50 to avoid repeated queries.
     * Cache is automatically skipped if there are too many regex redirects (memory guard).
     *
     * @global type $wpdb
     * @return array
     */
    function getRedirectsWithRegEx() {
        // Return cached results if available (and caching wasn't disabled due to count)
        if (self::$regexRedirectsCache !== null && !self::$regexCacheDisabled) {
            return self::$regexRedirectsCache;
        }

        // If caching was disabled due to too many redirects, just query without caching
        if (self::$regexCacheDisabled) {
            return $this->queryRegexRedirects();
        }

        // First query - check count and decide whether to cache
        $results = $this->queryRegexRedirects();

        // Only cache if count is within safe memory limits
        if (count($results) <= self::REGEX_CACHE_MAX_COUNT) {
            self::$regexRedirectsCache = $results;
        } else {
            // Too many regex redirects - disable caching for this request
            self::$regexCacheDisabled = true;
        }

        return $results;
    }

    /**
     * Execute the regex redirects query.
     * Separated from getRedirectsWithRegEx() for cache logic clarity.
     *
     * @return array
     */
    private function queryRegexRedirects() {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
                . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
                . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n ";
        $query .= "from {wp_abj404_redirects}\n " .
                "  LEFT OUTER JOIN {wp_posts} \n " .
                "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n ";

        $query .= "where status in (" . ABJ404_STATUS_REGEX . ") \n " .
                "     and disabled = 0";
        $results = $this->queryAndGetResults($query);

        return $results['rows'];
    }

    /** Returns the redirects that are in place.
     * @global type $wpdb
     * @param string $sub either "redirects" or "captured".
     * @param array $tableOptions filter, order by, paged, perpage etc.
     * @return array rows from the redirects table.
     */
    function getRedirectsForView($sub, $tableOptions) {
    	
    	// for normal page views we limit the rows returned based on user preferences for paginaiton.
        $limitStart = ( absint(sanitize_text_field($tableOptions['paged']) - 1)) * absint(sanitize_text_field($tableOptions['perpage']));
        $limitEnd = absint(sanitize_text_field($tableOptions['perpage']));
        
        $queryAllRowsAtOnce = ($tableOptions['perpage'] > 5000) || ($tableOptions['orderby'] == 'logshits')
                || ($tableOptions['orderby'] == 'last_used');
        
        $query = $this->getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
        	$limitStart, $limitEnd, false);

        // if this takes too long then rewrite how specific URLs are linked to from the redirects table.
        // they can use a different ID - not the ID from the logs table.
        $ignoreErrorsOoptions = array('log_errors' => false);
        $this->queryAndGetResults("set session max_join_size = 18446744073709551615",
        	$ignoreErrorsOoptions);
        $this->queryAndGetResults("set session sql_big_selects = 1", $ignoreErrorsOoptions);
        $results = $this->queryAndGetResults($query);

        // Handle race condition: logs_hits table may have been dropped between existence check and query
        // (fixes bug: "Table 'xxx.wp_abj404_logs_hits' doesn't exist" error during shutdown)
        $usedFallbackForLogsHits = false;
        $needsPhpSortAndLimit = false;
        if (!empty($results['last_error']) && strpos($results['last_error'], 'logs_hits') !== false) {
            $this->logger->debugMessage("logs_hits table unavailable, retrying without JOIN: " . $results['last_error']);
            // Retry with queryAllRowsAtOnce = false to skip logs_hits JOIN
            // (The query builder only adds the JOIN when queryAllRowsAtOnce is true)
            $usedFallbackForLogsHits = true;
            $queryAllRowsAtOnce = false;

            // If sorting by logshits/last_used, we must query ALL rows first,
            // then sort in PHP, then apply limit - otherwise we get wrong results
            if ($tableOptions['orderby'] == 'logshits' || $tableOptions['orderby'] == 'last_used') {
                $needsPhpSortAndLimit = true;
                // Query all rows (no limit) so we can sort properly in PHP
                $query = $this->getRedirectsForViewQuery($sub, $tableOptions, false,
                    0, PHP_INT_MAX, false);
            } else {
                // Other sort columns work fine with normal limit
                $query = $this->getRedirectsForViewQuery($sub, $tableOptions, false,
                    $limitStart, $limitEnd, false);
            }
            $results = $this->queryAndGetResults($query);
        }

        $rows = $results['rows'];
        $foundRowsBeforeLogsData = count($rows);

        // populate the logs data if we need to
        if (!$queryAllRowsAtOnce) {
            $rows = $this->populateLogsData($rows);

            // If fallback was used and user wanted to sort by logshits/last_used,
            // we need to sort in PHP since the DB query sorted on NULL placeholders,
            // then apply the limit that was skipped in the query
            if ($needsPhpSortAndLimit && !empty($rows)) {
                $orderBy = $tableOptions['orderby'];
                $orderDir = strtoupper($tableOptions['order'] ?? 'DESC');
                usort($rows, function($a, $b) use ($orderBy, $orderDir) {
                    $valA = isset($a[$orderBy]) ? $a[$orderBy] : 0;
                    $valB = isset($b[$orderBy]) ? $b[$orderBy] : 0;
                    // For last_used (timestamp), compare as integers
                    // For logshits (count), compare as integers
                    $cmp = $valA <=> $valB;
                    return $orderDir === 'DESC' ? -$cmp : $cmp;
                });
                // Now apply the limit that was skipped in the query
                $rows = array_slice($rows, $limitStart, $limitEnd);
            }
        }
        $this->logger->debugMessage("Found " . $foundRowsBeforeLogsData . 
        	" rows to display before log data and " . count($rows) . 
        	" rows to display after log data for page: ". $sub);
        
        return $rows;
    }
    
    function getRedirectsForViewCount($sub, $tableOptions) {
    	if (array_key_exists(self::KEY_REDIRECTS_FOR_VIEW_COUNT, $_REQUEST) && 
    		isset($_REQUEST[self::KEY_REDIRECTS_FOR_VIEW_COUNT])) {
    			
   			return $_REQUEST[self::KEY_REDIRECTS_FOR_VIEW_COUNT];
   		}
    	
        $query = $this->getRedirectsForViewQuery($sub, $tableOptions, false, 0, PHP_INT_MAX,
        	true);

        $ignoreErrorsOoptions = array('log_errors' => false);
        $this->queryAndGetResults("set session max_join_size = 18446744073709551615", 
        	$ignoreErrorsOoptions);
        $this->queryAndGetResults("set session sql_big_selects = 1", $ignoreErrorsOoptions);
        $results = $this->queryAndGetResults($query);
        
        if ($results['last_error'] != null && trim($results['last_error']) != '') {
        	throw new \Exception("Error getting redirect count: " . esc_html($results['last_error']));
        }
        $rows = $results['rows'];
        if (empty($rows)) {
        	return -1;
        }
        $row = $rows[0];
        
        $_REQUEST[self::KEY_REDIRECTS_FOR_VIEW_COUNT] = $row['count'];
        return $row['count'];
    }
    
    function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce, 
    	$limitStart, $limitEnd, $selectCountOnly) {
        global $abj404_redirect_types;
        global $abj404_captured_types;
        global $wpdb;

        $logsTableColumns = '';
        $logsTableJoin = '';
        $statusTypes = '';
        $trashValue = '';
        $selectCountReplacement = '/* selecting data as usual */';
        
        /* if we only want the count(*) then comment out everything else. */
        if ($selectCountOnly) {
        	$selectCountReplacement = "\n /*+ SET_VAR(max_join_size=18446744073709551615) */\n" . 
        		"count(*) as count\n /* only selecting for count";
        }

        // if we're showing all rows include all of the log data in the query already. this makes the query very slow. 
        // this should be replaced by the dynamic loading of log data using ajax queries as the page is viewed.
        if ($queryAllRowsAtOnce) {
             $logsTableColumns = "logstable.logshits as logshits, \n" .
                    "logstable.logsid, \n" .
                    "logstable.last_used, \n";
        } else {
            $logsTableColumns = "null as logshits, \n null as logsid, \n null as last_used, \n";
        }        

        if ($queryAllRowsAtOnce) {
            // create a temp table and use that instead of a subselect to avoid the sql error
            // "The SELECT would examine more than MAX_JOIN_SIZE rows"
            $this->maybeUpdateRedirectsForViewHitsTable();

            // Verify table was actually created before using it (handles silent creation failures)
            if ($this->logsHitsTableExists()) {
                $logsTableJoin = "  LEFT OUTER JOIN {wp_abj404_logs_hits} logstable \n " .
                        "  on binary wp_abj404_redirects.url = binary logstable.requested_url \n ";
            } else {
                // Fall back to null columns if table creation failed
                $logsTableColumns = "null as logshits, \n null as logsid, \n null as last_used, \n";
                $this->logger->debugMessage("logs_hits table not available, falling back to null columns");
            }
        }
        
        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            if ($sub == 'abj404_redirects') {
                $statusTypes = implode(", ", $abj404_redirect_types);

            } else if ($sub == 'abj404_captured') {
                $statusTypes = implode(", ", $abj404_captured_types);

            } else {
                $this->logger->errorMessage("Unrecognized sub type: " . esc_html($sub));
            }
            
        } else if ($tableOptions['filter'] == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(", ", array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));
            
        } else {
            $statusTypes = $tableOptions['filter'];
        }
        $statusTypes = preg_replace('/[^\d, ]/', '', trim($statusTypes));

        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $trashValue = 1;
        } else {
            $trashValue = 0;
        }

        /* only try to order by if we're actually selecting data and not only
         * counting the number of rows. */
        $orderByString = '';
        if (!$selectCountOnly) {
            $orderBy = $this->f->strtolower($tableOptions['orderby']);
            if ($orderBy == "final_dest") {
                // TODO change the final dest type to an integer and store external URLs somewhere else.
                $orderBy = "case when post_title is null then 1 else 0 end asc, post_title";
            } else {
                // only allow letters and the underscore in the orderby string.
                $orderBy = preg_replace('/[^a-zA-Z_]/', '', trim($orderBy));
            }
            $order = preg_replace('/[^a-zA-Z_]/', '', trim($tableOptions['order']));
            $orderByString = "order by published_status asc, " . $orderBy . " " . $order;
        }

        $searchFilterForRedirectsExists = "no redirects fiter text found";
        $searchFilterForCapturedExists = "no captured 404s filter text found";
        $filterText = '';
        if ($tableOptions['filterText'] != '') {
            if ($sub == 'abj404_redirects') {
                // Close the comment without including user input to avoid comment breakout.
                $searchFilterForRedirectsExists = ' filter text enabled */';
                
            } else if ($sub == 'abj404_captured') {
                // Close the comment without including user input to avoid comment breakout.
                $searchFilterForCapturedExists = ' filter text enabled */';
                
            } else {
                throw new Exception("Unrecognized page for filter text request.");
            }
        }

        // Sanitize filter text for use inside LIKE; strip comment markers and escape for SQL LIKE.
        $filterTextRaw = $tableOptions['filterText'];
        $filterTextRaw = str_replace(array('*', '/', '$'), '', $filterTextRaw);
        if (isset($wpdb) && method_exists($wpdb, 'esc_like')) {
            $filterTextRaw = $wpdb->esc_like($filterTextRaw);
        } else {
            $filterTextRaw = addcslashes($filterTextRaw, '_%\\');
        }
        $filterText = esc_sql($filterTextRaw);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsForView.sql");
        // Ensure consistent collation for string operations (e.g., REPLACE/LOWER) to avoid
        // "Illegal mix of collations" errors when plugin tables use *_bin collations.
        $wpdbCollate = 'utf8mb4_unicode_ci';
        if (isset($wpdb) && isset($wpdb->collate) && !empty($wpdb->collate)) {
            $wpdbCollate = preg_replace('/[^A-Za-z0-9_]/', '', $wpdb->collate);
        }
        if ($wpdbCollate === '') {
            $wpdbCollate = 'utf8mb4_unicode_ci';
        }
        $query = $this->f->str_replace('{selecting-for-count-true-false}', $selectCountReplacement, $query);
        $query = $this->f->str_replace('{statusTypes}', $statusTypes, $query);
        $query = $this->f->str_replace('{orderByString}', $orderByString, $query);
        $query = $this->f->str_replace('{limitStart}', $limitStart, $query);
        $query = $this->f->str_replace('{limitEnd}', $limitEnd, $query);
        $query = $this->f->str_replace('{searchFilterForRedirectsExists}', $searchFilterForRedirectsExists, $query);
        $query = $this->f->str_replace('{searchFilterForCapturedExists}', $searchFilterForCapturedExists, $query);
        $query = $this->f->str_replace('{filterText}', $filterText, $query);
        $query = $this->f->str_replace('{wpdb_collate}', $wpdbCollate, $query);
        $query = $this->f->str_replace('{logsTableColumns}', $logsTableColumns, $query);
        $query = $this->f->str_replace('{logsTableJoin}', $logsTableJoin, $query);
        $query = $this->f->str_replace('{trashValue}', $trashValue, $query);
        $query = $this->doTableNameReplacements($query);
        
        if (array_key_exists('translations', $tableOptions)) {
            $keys = array_keys($tableOptions['translations']);
            $values = array_values($tableOptions['translations']);
            $query = $this->f->str_replace($keys, $values, $query);
        }
        
        $query = $this->f->doNormalReplacements($query);
        
        return $query;
    }

    function getExtraDataToPermalinkSuggestions($postIDs) {
        // Sanitize all post IDs to prevent SQL injection
        $postIDs = array_map('absint', $postIDs);
        $postIDJoined = implode(", ", $postIDs);

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getAdditionalPostData.sql");
        $query = $this->f->str_replace('{IDS_TO_INCLUDE}', $postIDJoined, $query);
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);
        
        $results = $this->queryAndGetResults($query);

        return $results['rows'];
    }

    /**
     * Prepare a WordPress SQL query with placeholders and an associative data array.
     *
     * @param string $query The SQL query string with {placeholder} style placeholders.
     * @param array $data An associative array with keys matching the placeholders in the query.
     * @return string The fully prepared SQL query.
     */
    function prepare_query_wp($query, $data) {
        global $wpdb;
        list($prepared_query, $ordered_values) = $this->prepare_query($query, $data);
        return $wpdb->prepare($prepared_query, $ordered_values);
    }
    
    /**
     * Prepare a SQL query with placeholders and an associative data array.
     *
     * @param string $query The SQL query string with {placeholder} style placeholders.
     * @param array $data An associative array with keys matching the placeholders in the query.
     * @return array Returns an array containing two elements: the prepared query string with %s or %d placeholders, and an ordered array of values for those placeholders.
     */
    function prepare_query($query, $data) {
        $ordered_values = [];
        $prepared_query = preg_replace_callback('/\{(\w+)\}/', function($matches) use ($data, &$ordered_values) {
            $key = $matches[1];
            if (!isset($data[$key])) {
                // Placeholder key not found in data array, ignore and continue
                return $matches[0];
            }
            $value = $data[$key];
            
            // Append the value to the ordered values array
            $ordered_values[] = $value;
            
            // Determine the placeholder type
            $placeholder_type = is_int($value) ? '%d' : '%s';
            
            return $placeholder_type;
        }, $query);
            
        return [$prepared_query, $ordered_values];
    }
    
    function maybeUpdateRedirectsForViewHitsTable() {

        // Check if the table exists
        if (!$this->logsHitsTableExists()) {
            // First-time creation: table must exist before query runs, so create synchronously
            $this->logger->debugMessage(__FUNCTION__ . " creating now because the table doesn't exist (first time).");
            $this->createRedirectsForViewHitsTable();
            return;
        }

        // Check if rebuild is needed (logs have changed since last build)
        if (!$this->hitsTableNeedsRebuild()) {
            // No new log entries - skip rebuild to reduce server load
            return;
        }

        // Table exists and logs have changed - defer to shutdown hook
        $this->scheduleHitsTableRebuild();
    }

    /**
     * Schedule the hits table to be rebuilt at shutdown.
     *
     * Uses a static flag to ensure the hook is only registered once per request,
     * even if multiple calls to getRedirectsForView with hits sorting occur.
     *
     * The shutdown hook runs after the response is sent, so the admin sees the page
     * immediately with existing data, and fresh data is available on next load.
     */
    function scheduleHitsTableRebuild() {
        if (!self::$hitsTableRebuildScheduled) {
            self::$hitsTableRebuildScheduled = true;
            $this->logger->debugMessage(__FUNCTION__ . " scheduling hits table rebuild for shutdown hook.");
            add_action('shutdown', [$this, 'createRedirectsForViewHitsTable']);
        }
    }

    /**
     * Check if the logs_hits table exists.
     * Used to verify table was created before using it in queries.
     * @return bool
     */
    function logsHitsTableExists() {
        $query = "SELECT 1 FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE() LIMIT 1";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        return ($results['rows'] != null && !empty($results['rows']));
    }

    /**
     * Get the maximum log ID from the logs table.
     *
     * Used to detect if logs have changed since the hits table was last built.
     * O(1) query using primary key index.
     *
     * @return int Maximum log ID, or 0 if table is empty
     */
    function getMaxLogId() {
        $query = "SELECT MAX(id) FROM {wp_abj404_logsv2}";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        if ($results['rows'] == null || empty($results['rows'])) {
            return 0;
        }

        $row = $results['rows'][0];
        // Handle both object and array results
        $maxId = is_array($row) ? array_values($row)[0] : (array_values((array)$row)[0] ?? 0);
        return (int)($maxId ?? 0);
    }

    /**
     * Get the stored max log ID from the hits table comment.
     *
     * Comment format: "elapsed_time|max_log_id" (e.g., "0.35|12345")
     *
     * @return int Stored max log ID, or 0 if not found
     */
    function getStoredMaxLogId() {
        $query = "SELECT table_comment FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        if ($results['rows'] == null || empty($results['rows'])) {
            return 0;
        }

        $row = $results['rows'][0];
        $row = array_change_key_case($row);
        $comment = $row['table_comment'] ?? '';

        // Parse comment format: "elapsed_time|max_log_id"
        $parts = explode('|', $comment);
        if (count($parts) >= 2) {
            return (int)$parts[1];
        }

        // Old format (just elapsed time) or empty - treat as needing rebuild
        return 0;
    }

    /**
     * Check if the hits table needs to be rebuilt.
     *
     * Rebuild is needed if:
     * 1. MAX(id) from logs differs from stored value (new entries or deletions)
     * 2. Table is older than HITS_TABLE_MAX_AGE_SECONDS (staleness check)
     *
     * @return bool True if rebuild needed
     */
    function hitsTableNeedsRebuild() {
        $storedMaxId = $this->getStoredMaxLogId();
        $currentMaxId = $this->getMaxLogId();

        // Check if log entries have changed
        if ($currentMaxId != $storedMaxId) {
            $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (max_id changed: stored=$storedMaxId, current=$currentMaxId)");
            return true;
        }

        // Check if table is too old (staleness check)
        $lastUpdated = $this->getLogsHitsTableLastUpdated();
        if ($lastUpdated !== null) {
            $age = time() - $lastUpdated;
            if ($age > self::HITS_TABLE_MAX_AGE_SECONDS) {
                $this->logger->debugMessage(__FUNCTION__ . " rebuild=yes (stale: age={$age}s > " . self::HITS_TABLE_MAX_AGE_SECONDS . "s)");
                return true;
            }
        }

        $this->logger->debugMessage(__FUNCTION__ . " rebuild=no (max_id=$currentMaxId unchanged, not stale)");
        return false;
    }

    /**
     * Get the last update time of the logs_hits table.
     *
     * Uses the MySQL table creation time from information_schema since
     * the table is dropped and recreated on each rebuild.
     *
     * @return int|null Unix timestamp of last update, or null if table doesn't exist
     */
    function getLogsHitsTableLastUpdated() {
        $query = "SELECT create_time FROM information_schema.tables WHERE table_name = '{wp_abj404_logs_hits}' AND table_schema = DATABASE()";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);

        if ($results['rows'] == null || empty($results['rows'])) {
            return null;
        }

        $row = $results['rows'][0];
        $row = array_change_key_case($row);
        $createTime = $row['create_time'] ?? null;

        if ($createTime === null) {
            return null;
        }

        // Convert MySQL datetime to Unix timestamp
        return strtotime($createTime);
    }

    /**
     * Get a human-readable "time ago" string for the hits table's last update.
     *
     * @return string e.g., "2 minutes ago", "1 hour ago", or empty string if unknown
     */
    function getLogsHitsTableLastUpdatedHuman() {
        $timestamp = $this->getLogsHitsTableLastUpdated();

        if ($timestamp === null) {
            return '';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return __('Just now', '404-solution');
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return sprintf(_n('%d minute ago', '%d minutes ago', $minutes, '404-solution'), $minutes);
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return sprintf(_n('%d hour ago', '%d hours ago', $hours, '404-solution'), $hours);
        } else {
            $days = floor($diff / 86400);
            return sprintf(_n('%d day ago', '%d days ago', $days, '404-solution'), $days);
        }
    }

    function createRedirectsForViewHitsTable() {
        
        $finalDestTable = $this->doTableNameReplacements("{wp_abj404_logs_hits}");
        $tempDestTable = $this->doTableNameReplacements("{wp_abj404_logs_hits}_temp");
        $ttSelectQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
        	"/sql/getRedirectsForViewTempTable.sql");
        $ttSelectQuery = $this->doTableNameReplacements($ttSelectQuery);
        
        // create a temp table
        $this->queryAndGetResults("drop table if exists " . $tempDestTable);
        $createTempTableQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
        	"/sql/createLogsHitsTempTable.sql");
        $createTempTableQuery = $this->doTableNameReplacements($createTempTableQuery);
        $this->queryAndGetResults($createTempTableQuery);
        $this->queryAndGetResults("truncate table " . $tempDestTable);
        
        // insert the data into the temp table (this may take time).
        $ttInsertQuery = "insert into " . $tempDestTable . " (requested_url, logsid, " .
        	"last_used, logshits) \n " . $ttSelectQuery;
        $results = $this->queryAndGetResults($ttInsertQuery, array('log_too_slow' => false));

        // Store elapsed time and max log ID in comment for invalidation check
        // Format: "elapsed_time|max_log_id" (e.g., "0.35|12345")
        $elapsedTime = $results['elapsed_time'];
        $maxLogId = $this->getMaxLogId();
        $comment = $elapsedTime . '|' . $maxLogId;
        // Escape comment and truncate to MySQL's 2048 char limit for table comments
        $comment = substr(esc_sql($comment), 0, 2048);
        $addComment = "ALTER TABLE " . $tempDestTable . " COMMENT '" . $comment . "'";
        $this->queryAndGetResults($addComment);
        
        // drop the old hits table and rename the temp table to the hits table as a transaction
        $statements = array(
            "drop table if exists " . $finalDestTable,
            "rename table " . $tempDestTable . ' to ' . $finalDestTable
        );
        $this->executeAsTransaction($statements);
        
        $this->logger->debugMessage(__FUNCTION__ . " refreshed " . $finalDestTable . " in " . $elapsedTime . 
                " seconds.");
    }
    
    /**
     * @param array $rows
     */
    function populateLogsData($rows) {
        global $wpdb;

        // If no rows, return early
        if (empty($rows)) {
            return $rows;
        }

        // Extract all non-empty URLs from rows
        $urls = array();
        foreach ($rows as $row) {
            if ($row['url'] != null && !empty($row['url'])) {
                $urls[] = $row['url'];
            }
        }

        // If no valid URLs, return rows unchanged
        if (empty($urls)) {
            return $rows;
        }

        // Remove duplicates to avoid unnecessary work
        $urls = array_unique($urls);

        // Fetch all logs data in a single batch query
        $placeholders = implode(',', array_fill(0, count($urls), '%s'));
        $logsTable = $this->getPrefixedTableName('abj404_logsv2');
        $query = $wpdb->prepare(
            "SELECT requested_url,
                    MIN(id) AS logsid,
                    MAX(timestamp) AS last_used,
                    COUNT(requested_url) AS logshits
             FROM {$logsTable}
             WHERE requested_url IN ($placeholders)
             GROUP BY requested_url",
            $urls
        );

        $logsResults = $wpdb->get_results($query, ARRAY_A);

        // Check for errors
        if ($wpdb->last_error) {
            $this->logger->errorMessage("Error executing batch logs query. Err: " . $wpdb->last_error);
            return $rows;
        }

        // Index logs data by URL for fast lookup
        $logsDataByUrl = array();
        foreach ($logsResults as $logRow) {
            $logsDataByUrl[$logRow['requested_url']] = $logRow;
        }

        // Populate rows with logs data using indexed lookup
        foreach ($rows as &$row) {
            if ($row['url'] != null && !empty($row['url'])) {
                if (isset($logsDataByUrl[$row['url']])) {
                    $logData = $logsDataByUrl[$row['url']];
                    $row['logsid'] = $logData['logsid'];
                    $row['logshits'] = $logData['logshits'];
                    $row['last_used'] = $logData['last_used'];
                }
            }
        }

        return $rows;
    }

    /**
     * @global type $wpdb
     * @param string $specificURL
     * @return array
     */
    function getLogsIDandURL($specificURL = '') {
        global $wpdb;
    	$whereClause = '';
        if ($specificURL != '') {
            // Escape user input to prevent SQL injection
            $escapedURL = esc_sql($specificURL);
            $whereClause = "where requested_url = '" . $escapedURL . "'";
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsIDandURL.sql");
        $query = $this->f->str_replace('{where_clause_here}', $whereClause, $query);

        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        return $rows;
    }
    
    /**
     * @global type $wpdb
     * @param string $specificURL
     * @param string $limitResults
     * @return array
     */
    function getLogsIDandURLLike($specificURL, $limitResults) {
        global $wpdb;
    	$whereClause = '';
        if ($specificURL != '') {
            // Escape user input to prevent SQL injection
            // Use esc_like for LIKE queries, then add wildcards, then esc_sql for the full string.
            // esc_like escapes '%' and '_' so callers must pass the raw search term (no wildcards).
            $likePattern = '%' . $wpdb->esc_like($specificURL) . '%';
            $escapedURL = esc_sql($likePattern);
            $whereClause = "where lower(requested_url) like lower('" . $escapedURL . "')\n";
            $whereClause .= "and min_log_id = true";
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsIDandURLForAjax.sql");
        $query = $this->f->str_replace('{where_clause_here}', $whereClause, $query);
        $query = $this->f->str_replace('{limit-results}', 'limit ' . absint($limitResults), $query);

        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        return $rows;
    }
    
    /**
     * @global type $wpdb
     * @param array $tableOptions orderby, paged, perpage, etc.
     * @return array rows from querying the logs table.
     */
    function getLogRecords($tableOptions) {
    	$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

    	$logsid_included = '';
        $logsid = '';
        if ($tableOptions['logsid'] != 0) {
            $logsid_included = 'specific logs id included. */';
            $logsid = esc_sql($abj404logic->sanitizeForSQL($tableOptions['logsid']));
        }

        // Whitelist allowed columns for orderby to prevent SQL injection
        $allowedOrderbyColumns = array('timestamp', 'requested_url', 'dest_url', 'id', 'referrer', 'min_log_id', 'logshits', 'action');
        $orderby = sanitize_text_field($abj404logic->sanitizeForSQL($tableOptions['orderby']));
        if (!in_array($orderby, $allowedOrderbyColumns, true)) {
            $orderby = 'timestamp'; // Safe default
        }

        // Whitelist allowed order directions
        $order = strtoupper(sanitize_text_field($abj404logic->sanitizeForSQL($tableOptions['order'])));
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC'; // Safe default
        }

        $start = ( absint(sanitize_text_field($tableOptions['paged']) - 1)) * absint(sanitize_text_field($tableOptions['perpage']));
        $perpage = absint(sanitize_text_field($tableOptions['perpage']));
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogRecords.sql");
        $query = $this->f->str_replace('{logsid_included}', $logsid_included, $query);
        $query = $this->f->str_replace('{logsid}', $logsid, $query);
        $query = $this->f->str_replace('{orderby}', $orderby, $query);
        $query = $this->f->str_replace('{order}', $order, $query);
        $query = $this->f->str_replace('{start}', $start, $query);
        $query = $this->f->str_replace('{perpage}', $perpage, $query);

        $results = $this->queryAndGetResults($query);
        return $results['rows'];
    }
    
    /** 
     * Log that a redirect was done. Insert into the logs table.
     * @param string $requestedURL
     * @param string $action
     * @param string $matchReason
     * @param string $requestedURLDetail the exact URL that was requested, for cases when a regex URL was matched.
     */
    function logRedirectHit($requested_url, $action, $matchReason, $requestedURLDetail = null) {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $logTableName = $this->doTableNameReplacements("{wp_abj404_logsv2}");

        $now = time();

        // remove ridiculous non-printable characters
        $requested_url = preg_replace('/[^\x20-\x7E]/', '', $requested_url); // Remove non-printable ASCII characters

        // Normalize to relative path before storing (Issue #24)
        // Fix HIGH #1 (5th review): Abort operation if normalization fails
        // Storing un-normalized URLs causes permanent lookup failures
        if ($abj404logic === null) {
            $abj404logging = ABJ_404_Solution_Logging::getInstance();
            $abj404logging->errorMessage("CRITICAL: PluginLogic singleton not initialized in logRedirectHit()! Cannot normalize URL, aborting: " . $requested_url);
            return;  // Abort - don't log un-normalized URL
        }
        $requested_url = $abj404logic->normalizeToRelativePath($requested_url);

        // If the database can't store utf8 URLs then URL-encode before saving (avoid insert errors).
        try {
            static $requestedUrlCharsetCached = null;

            if ($requestedUrlCharsetCached === null && function_exists('get_transient')) {
                $requestedUrlCharsetCached = get_transient('abj404_logs_requested_url_charset');
                if ($requestedUrlCharsetCached === false) {
                    $requestedUrlCharsetCached = null;
                }
            }

            $getCharsetQuery = $wpdb->prepare("SELECT character_set_name as charset_name \n " .
                "FROM information_schema.columns \n " .
                "WHERE lower(table_schema) = lower(%s) \n " .
                "AND lower(table_name) = lower(%s) \n " .
                "AND lower(column_name) = lower(%s) ",
                DB_NAME, $logTableName, 'requested_url');

            if ($requestedUrlCharsetCached === null) {
                $resultArray = $wpdb->get_results($getCharsetQuery, ARRAY_A);
                if (!empty($resultArray)) {
                    $requestedUrlCharsetCached = $resultArray[0]['charset_name'] ?? $resultArray[0]['CHARSET_NAME'];
                    if (function_exists('set_transient')) {
                        $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
                        set_transient('abj404_logs_requested_url_charset', $requestedUrlCharsetCached, $ttl);
                    }
                }
            }

            if (!empty($requestedUrlCharsetCached) && strpos(strtolower($requestedUrlCharsetCached), 'utf8') === false) {
                    $requested_url = $this->f->encodeUrlForLegacyMatch($requested_url);

                    // Avoid spamming logs on every redirect hit.
                    if (function_exists('get_transient') && function_exists('set_transient')) {
                        $warnKey = 'abj404_warned_logs_charset_mismatch';
                        $warnVal = $logTableName . '|' . strtolower($requestedUrlCharsetCached);
                        $already = get_transient($warnKey);
                        if ($already !== $warnVal) {
                            $ttl = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
                            set_transient($warnKey, $warnVal, $ttl);
                            $this->logger->warn("Logs table column charset is '{$requestedUrlCharsetCached}' for {$logTableName}. URL-encoding stored requested URLs to avoid charset issues.");
                        }
                    }
            }
        } catch (Exception $e) {
            // not so important.
            $this->logger->debugMessage(__FUNCTION__ . 
                " error. Issue getting character set for table: " . $logTableName . 
                ", column: requested_url. Error message: " . $e->getMessage());                
        }

        // no nonce here because redirects are not user generated.

        $options = $abj404logic->getOptions(true);
        $referer = wp_get_referer();
        if ($referer !== null && $referer !== false) {
            $referer = esc_url_raw($referer);
            // this length matches the maximum length of the data field on the logs table.
        	$referer = substr($referer, 0, 512);
        } else {
            $referer = '';
        }
        $current_user = wp_get_current_user();
        $current_user_name = null;
        if (isset($current_user)) {
            $current_user_name = $current_user->user_login;
        }
        $ipAddressToSave = $_SERVER['REMOTE_ADDR'];
        $ipAddressToSave = filter_var($ipAddressToSave, FILTER_VALIDATE_IP) ? 
            esc_sql($ipAddressToSave) : '';
        if (!array_key_exists('log_raw_ips', $options) || $options['log_raw_ips'] != '1') {
        	$ipAddressToSave = $this->f->md5lastOctet($ipAddressToSave);
        }
        if (!empty($ipAddressToSave)) {
            $ipAddressToSave = substr($ipAddressToSave, 0, 512);
        } else {
            $ipAddressToSave = '(Unknown)';
        }
        
        // we have to know what to set for the $minLogID value
        $minLogID = false;
        $checkMinIDQuery = $wpdb->prepare("SELECT id FROM `" . $logTableName . "` \n " .
            "WHERE CAST(requested_url AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci = %s \n " .
            "LIMIT 1", $requested_url);
        $checkMinIDQueryResults = $wpdb->get_results($checkMinIDQuery, ARRAY_A);
    
        if (empty($checkMinIDQueryResults)) {
            $minLogID = true;
        }

        // extra escaping suggestions from chatgpt
        // Don't escape "404" as a URL since it's not a URL, it's a status indicator
        if (trim($action) != "404") {
            $action = esc_url_raw($action);
        }
            
        // ------------ debug message begin
        $helperFunctions = ABJ_404_Solution_Functions::getInstance();
        $reasonMessage = trim(implode(", ", 
                    array_filter(
                    array($_REQUEST[ABJ404_PP]['ignore_doprocess'], $_REQUEST[ABJ404_PP]['ignore_donotprocess']))));
        $permalinksKept = '(not set)';
        if ($this->logger->isDebug() && array_key_exists(ABJ404_PP, $_REQUEST) &&
        		array_key_exists('permalinks_found', $_REQUEST[ABJ404_PP])) {
       		$permalinksKept = $_REQUEST[ABJ404_PP]['permalinks_kept'];
        }
        $this->logger->debugMessage("Logging redirect. Referer: " . esc_html($referer) . 
        		" | Current user: " . $current_user_name . " | From: " . $helperFunctions->normalizeUrlString($_SERVER['REQUEST_URI']) . 
                esc_html(" to: ") . esc_html($action) . ', Reason: ' . $matchReason . ", Ignore msg(s): " . 
                $reasonMessage . ', Execution time: ' . round($helperFunctions->getExecutionTime(), 2) . 
        	' seconds, permalinks found: ' . $permalinksKept);
        // ------------ debug message end
        
        // insert the username into the lookup table and get the ID from the lookup table.
        $usernameLookupID = $this->insertLookupValueAndGetID($current_user_name);

        // Queue the log entry for batch INSERT at shutdown
        $this->queueLogEntry([
            'timestamp' => $now,
            'user_ip' => $ipAddressToSave,
            'referrer' => $referer,
            'dest_url' => $action,
            'requested_url' => esc_url_raw($requested_url),
            'requested_url_detail' => $requestedURLDetail,
            'username' => $usernameLookupID,
            'min_log_id' => $minLogID,
        ]);
    }

    /**
     * Queue a log entry for batch INSERT at shutdown.
     * Registers shutdown hook on first entry.
     *
     * @param array $entry Log entry data
     */
    function queueLogEntry(array $entry): void {
        self::$logQueue[] = $entry;

        // Register shutdown hook on first entry only
        if (!self::$shutdownHookRegistered) {
            self::$shutdownHookRegistered = true;
            // Slightly earlier than default (10) to reduce chance other shutdown handlers poison the DB connection.
            add_action('shutdown', [$this, 'flushLogQueue'], 9);
        }
    }

    /**
     * Flush queued log entries with a batch INSERT.
     * Called automatically at shutdown.
     */
    function flushLogQueue(): void {
        if (self::$isFlushingLogQueue) {
            return;
        }
        self::$isFlushingLogQueue = true;
        if (empty(self::$logQueue)) {
            // Reset shutdown hook flag for next request (persistent hosting protection)
            self::$shutdownHookRegistered = false;
            self::$isFlushingLogQueue = false;
            return;
        }

        global $wpdb;
        $tableName = $this->doTableNameReplacements('{wp_abj404_logsv2}');

        // Get column names from first entry and validate as safe SQL identifiers
        $columns = array_keys(self::$logQueue[0]);
        $validatedColumns = [];
        foreach ($columns as $col) {
            // Validate column name is a safe SQL identifier (alphanumeric + underscore)
            if (preg_match('/^[a-z_][a-z0-9_]*$/i', $col)) {
                $validatedColumns[] = $col;
            }
        }

        if (empty($validatedColumns)) {
            // No valid columns - clear queue and reset flag
            self::$logQueue = [];
            self::$shutdownHookRegistered = false;
            self::$isFlushingLogQueue = false;
            return;
        }

        $columnList = '`' . implode('`, `', $validatedColumns) . '`';

        // Build VALUES for each entry with proper validation
        $valuesSets = [];
        $sanitizedEntries = [];
        foreach (self::$logQueue as $entry) {
            // Detect complex types early (kept for legacy test expectations).
            foreach ($entry as $val) {
                if (is_object($val) || is_array($val)) {
                    // Handled in sanitizeLogEntry (converted to NULL)
                    break;
                }
            }
            // Validate entry has same structure as first entry
            $entryColumns = array_keys($entry);
            $missingCols = array_diff($validatedColumns, $entryColumns);
            if (!empty($missingCols)) {
                // Skip entries with missing columns to prevent data corruption
                continue;
            }

            $sanitized = $this->sanitizeLogEntry($entry);
            if ($sanitized === null) {
                continue;
            }

            $sanitizedEntries[] = $sanitized;
        }

        if (empty($sanitizedEntries)) {
            // No valid entries - clear queue and reset flag
            self::$logQueue = [];
            self::$shutdownHookRegistered = false;
            self::$isFlushingLogQueue = false;
            return;
        }

        // Build placeholder-based batch insert with IGNORE to tolerate duplicates
        $formats = [];
        $flattenedValues = [];
        foreach ($sanitizedEntries as $entry) {
            $rowFormats = [];
            foreach ($validatedColumns as $col) {
                $value = $entry[$col];
                if ($value === null) {
                    $rowFormats[] = 'NULL';
                    continue;
                }
                if (is_int($value)) {
                    $rowFormats[] = '%d';
                } else {
                    $rowFormats[] = '%s';
                }
                $flattenedValues[] = $value;
            }
            $formats[] = '(' . implode(', ', $rowFormats) . ')';
        }

        $sql = "INSERT IGNORE INTO `{$tableName}` ({$columnList}) VALUES " . implode(', ', $formats);
        $prepared = $wpdb->prepare($sql, $flattenedValues);

        // Execute batch INSERT
        $wpdb->flush();
        $result = $wpdb->query($prepared);

        // Check for errors - if batch insert fails, try individual inserts
        if ($result === false && !empty($wpdb->last_error)) {
            $batchError = $wpdb->last_error;

            // Attempt a one-time recovery for known connection-state issues (e.g., "Commands out of sync").
            if ($this->isCommandsOutOfSyncError($batchError)) {
                $isolated = $this->getIsolatedWpdb();
                if ($isolated !== null) {
                    $isolated->flush();
                    $isolatedPrepared = $isolated->prepare($sql, $flattenedValues);
                    $isolatedResult = $isolated->query($isolatedPrepared);
                    if ($isolatedResult !== false) {
                        // Clear queue and reset flag for next request
                        self::$logQueue = [];
                        self::$shutdownHookRegistered = false;
                        self::$isFlushingLogQueue = false;
                        $context = $this->getWpdbRecentQueryContextForLogs();
                        $suffix = ($context !== '') ? " | savequeries_context={$context}" : '';
                        $this->logger->warn("flushLogQueue batch INSERT succeeded using isolated DB connection (commands out of sync on shared connection).{$suffix}");
                        return;
                    }
                    $batchError .= " | isolated_error=" . ($isolated->last_error ?? '');
                } else {
                    $batchError .= " | isolated_error=no_isolated_connection";
                }
            }

            // Retry each entry individually to salvage what we can
            $successCount = 0;
            $failCount = 0;
            $failureDetails = [];
            foreach ($sanitizedEntries as $index => $entry) {
                $rowFormats = [];
                $rowValues = [];
                foreach ($validatedColumns as $col) {
                    $value = $entry[$col];
                    if ($value === null) {
                        $rowFormats[] = 'NULL';
                    } else {
                        $rowFormats[] = is_int($value) ? '%d' : '%s';
                        $rowValues[] = $value;
                    }
                }
                $rowPlaceholder = '(' . implode(', ', $rowFormats) . ')';
                $singleSqlTemplate = "INSERT IGNORE INTO `{$tableName}` ({$columnList}) VALUES {$rowPlaceholder}";
                $singleSql = $wpdb->prepare($singleSqlTemplate, $rowValues);
                $wpdb->flush();
                $singleResult = $wpdb->query($singleSql);

                if ($singleResult === false && !empty($wpdb->last_error)) {
                    $lastError = $wpdb->last_error;

                    // One retry on known connection-state errors.
                    if ($this->isCommandsOutOfSyncError($wpdb->last_error)) {
                        $isolated = $this->getIsolatedWpdb();
                        if ($isolated !== null) {
                            $isolated->flush();
                            $isolatedSingleSql = $isolated->prepare($singleSqlTemplate, $rowValues);
                            $isolatedSingleResult = $isolated->query($isolatedSingleSql);
                            if ($isolatedSingleResult !== false) {
                                $successCount++;
                                continue;
                            }
                            $lastError = ($lastError ?? '') . " | isolated_error=" . ($isolated->last_error ?? '');
                        } else {
                            $lastError = ($lastError ?? '') . " | isolated_error=no_isolated_connection";
                        }
                    }

                    $failCount++;
                    $payload = function_exists('wp_json_encode') ? wp_json_encode($entry) : json_encode($entry);
                    if (is_string($payload) && strlen($payload) > 1024) {
                        $payload = substr($payload, 0, 1024) . '...';
                    }
                    $failureDetails[] = [
                        'index' => $index,
                        'error' => $lastError,
                        'payload' => $payload,
                    ];
                } else {
                    $successCount++;
                }
            }

            if ($failCount > 0) {
                $detailsParts = [];
                $maxDetails = 3;
                foreach (array_slice($failureDetails, 0, $maxDetails) as $detail) {
                    $detailsParts[] = "entry {$detail['index']}: {$detail['error']} | payload={$detail['payload']}";
                }
                $detailsSuffix = '';
                if (count($failureDetails) > $maxDetails) {
                    $detailsSuffix = ' | (additional failures omitted)';
                }

                // Use a single ERROR line so email summaries include the actual DB error(s).
                $context = $this->getWpdbRecentQueryContextForLogs();
                $contextSuffix = ($context !== '') ? (" | savequeries_context=" . $context) : '';
                $this->logger->errorMessage(
                    "flushLogQueue recovery incomplete: {$successCount} inserted, {$failCount} failed." .
                    " | batch_error=" . $batchError .
                    " | failures=" . implode(' || ', $detailsParts) . $detailsSuffix .
                    $contextSuffix
                );
            } else {
                // Batch insert failure was recovered; don't escalate as an error.
                $this->logger->warn("flushLogQueue batch INSERT failed but recovered: all {$successCount} entries inserted individually. | batch_error=" . $batchError);
            }
        }

        // Clear queue and reset flag for next request
        self::$logQueue = [];
        self::$shutdownHookRegistered = false;
        self::$isFlushingLogQueue = false;
    }

    private function isCommandsOutOfSyncError(string $error): bool {
        return stripos($error, 'commands out of sync') !== false;
    }

    /**
     * Create an isolated DB connection (separate from the shared $wpdb connection).
     * This avoids failures caused by other code leaving the shared mysqli connection in a bad state.
     */
    private function getIsolatedWpdb(): ?wpdb {
        static $isolated = null;

        if ($isolated !== null) {
            return $isolated;
        }
        if (!class_exists('wpdb')) {
            return null;
        }
        if (!defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME') || !defined('DB_HOST')) {
            return null;
        }

        // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__wpdb
        $isolated = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $isolated->show_errors(false);
        $isolated->suppress_errors(true);

        return $isolated;
    }

    /**
     * If WordPress query recording is already enabled (SAVEQUERIES), return a safe summary
     * of recent DB callers to help identify the component that poisoned the shared connection.
     *
     * Returns empty string when SAVEQUERIES isn't enabled.
     */
    private function getWpdbRecentQueryContextForLogs(): string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return '';
        }
        if (!defined('SAVEQUERIES') || SAVEQUERIES !== true) {
            return '';
        }
        if (empty($wpdb->queries) || !is_array($wpdb->queries)) {
            return '';
        }

        $recent = array_slice($wpdb->queries, -5);
        $parts = [];
        foreach ($recent as $q) {
            $sql = $q[0] ?? '';
            $time = $q[1] ?? null;
            $caller = $q[2] ?? '';
            $hash = is_string($sql) ? substr(sha1($sql), 0, 10) : 'n/a';
            $who = $this->extractWpComponentFromString(is_string($caller) ? $caller : '');
            $t = is_numeric($time) ? round((float)$time, 3) : 'n/a';
            $parts[] = "{$who}:{$hash}@{$t}";
        }
        return implode(', ', $parts);
    }

    private function extractWpComponentFromString(string $text): string {
        $normalized = str_replace('\\', '/', $text);

        $pos = strpos($normalized, '/wp-content/mu-plugins/');
        if ($pos !== false) {
            $rest = substr($normalized, $pos + strlen('/wp-content/mu-plugins/'));
            $name = explode('/', ltrim($rest, '/'))[0] ?? '';
            return $name !== '' ? "mu-plugin:{$name}" : 'mu-plugin:unknown';
        }

        $pos = strpos($normalized, '/wp-content/plugins/');
        if ($pos !== false) {
            $rest = substr($normalized, $pos + strlen('/wp-content/plugins/'));
            $name = explode('/', ltrim($rest, '/'))[0] ?? '';
            return $name !== '' ? "plugin:{$name}" : 'plugin:unknown';
        }

        $pos = strpos($normalized, '/wp-content/themes/');
        if ($pos !== false) {
            $rest = substr($normalized, $pos + strlen('/wp-content/themes/'));
            $name = explode('/', ltrim($rest, '/'))[0] ?? '';
            return $name !== '' ? "theme:{$name}" : 'theme:unknown';
        }

        // Caller strings are often like "require_once('...')" or "SomeClass->method", so we keep it generic.
        return 'unknown';
    }

    /**
     * Validate and sanitize a log entry before insertion.
     * Returns sanitized array or null if invalid.
     */
    private function sanitizeLogEntry(array $entry): ?array {
        // Required fields
        $required = array('timestamp', 'user_ip', 'referrer', 'dest_url', 'requested_url', 'requested_url_detail', 'username', 'min_log_id');
        foreach ($required as $key) {
            if (!array_key_exists($key, $entry)) {
                return null;
            }
        }

        $normalizeString = function($value, $maxLen) {
            if (is_object($value) || is_array($value)) {
                return null;
            }
            return substr((string)$value, 0, $maxLen);
        };

        $sanitized = array();

        $sanitized['timestamp'] = absint(is_object($entry['timestamp']) || is_array($entry['timestamp']) ? time() : ($entry['timestamp'] ?? time()));
        $sanitized['user_ip'] = $normalizeString($entry['user_ip'], 512);
        $sanitized['referrer'] = $normalizeString($entry['referrer'], 512);
        $sanitized['dest_url'] = $normalizeString($entry['dest_url'], 512);

        // Enforce lengths on URL fields (match schema)
        $sanitized['requested_url'] = $normalizeString($entry['requested_url'], 2048);
        $sanitized['requested_url_detail'] = $normalizeString($entry['requested_url_detail'], 2048);

        $sanitized['username'] = ($entry['username'] === null || is_object($entry['username']) || is_array($entry['username']))
            ? null : absint($entry['username']);
        $sanitized['min_log_id'] = ($entry['min_log_id'] === null || is_object($entry['min_log_id']) || is_array($entry['min_log_id']))
            ? null : absint($entry['min_log_id']);

        // Drop rows without required URL data
        if ($sanitized['requested_url'] === '' || $sanitized['dest_url'] === '') {
            return null;
        }

        return $sanitized;
    }

    /** Insert a value into the lookup table and return the ID of the value.
     * Uses upsert pattern (INSERT ... ON DUPLICATE KEY UPDATE) for atomic operation.
     * @param string $valueToInsert
     */
    function insertLookupValueAndGetID($valueToInsert) {
        global $wpdb;

        // Use upsert pattern: single atomic query that handles both insert and duplicate cases
        // ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) ensures insert_id is set even for existing rows
        $query = "INSERT INTO {wp_abj404_lookup} (lkup_value) VALUES (%s)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";
        $this->queryAndGetResults($query, array(
            'query_params' => array($valueToInsert)
        ));

        return intval($wpdb->insert_id);
    }

    function getLookupIDForUser($userName) {
    	// Use prepared statement to prevent SQL injection
    	$query = "select id from {wp_abj404_lookup} where lkup_value = %s";
    	$results = $this->queryAndGetResults($query, array(
    	    'query_params' => array($userName)
    	));

    	if (sizeof($results['rows']) > 0) {
    		// the value already exists so we only need to return the ID.
    		$rows = $results['rows'];
    		$row1 = $rows[0];
    		$id = $row1['id'];
    		return intval($id);
    	}
    	return -1;
    }

    /**
     * @global type $wpdb
     * @param int $id
     */
    function deleteRedirect($id) {
        global $wpdb;
        $cleanedID = absint(sanitize_text_field($id));

        // no nonce here because this action is not always user generated.

        if ($cleanedID >= 0 && is_numeric($id)) {
            $query = "delete from {wp_abj404_redirects} where id = %d";
            $this->queryAndGetResults($query, array('query_params' => array($cleanedID)));

            // Invalidate caches
            $this->invalidateStatusCountsCache();
            $this->clearRegexRedirectsCache();
        }
    }

    /** Helper method to delete old redirects of a specific type.
     * Extracted common logic from deleteOldRedirectsCron() to eliminate duplication.
     *
     * @param array $options Plugin options
     * @param int $now Current timestamp
     * @param string $optionKey Option key for deletion threshold ('capture_deletion', 'auto_deletion', 'manual_deletion')
     * @param string $statusList Comma-separated list of status codes to delete
     * @param string $debugMessageType Type description for debug logging ('Captured 404', 'Automatic redirect', 'Manual redirect')
     * @return int Count of deleted redirects
     */
    private function deleteOldRedirectsByType($options, $now, $optionKey, $statusList, $debugMessageType) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $deletedCount = 0;

        // Calculate time threshold
        $deletionDays = $options[$optionKey];
        $deletionTime = $deletionDays * 86400;
        $then = $now - $deletionTime;

        // Load and prepare SQL query
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getMostUnusedRedirects.sql");
        $query = $this->f->str_replace('{status_list}', $statusList, $query);
        $query = $this->f->str_replace('{timelimit}', $then, $query);

        // Fix for MAX_JOIN_SIZE error (reported by 24 users - 53% of errors)
        // Set SQL_BIG_SELECTS=1 to allow large queries during maintenance operations
        // IMPORTANT: This is a SESSION-LEVEL setting that only affects this connection
        // and automatically expires when the script finishes (no permanent database changes)
        // This is safe for cron jobs and prevents "The SELECT would examine more than MAX_JOIN_SIZE rows" error
        global $wpdb;
        $wpdb->query("SET SQL_BIG_SELECTS=1");

        // Execute query and get results
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        // Delete each redirect and log
        foreach ($rows as $row) {
            // Build debug message based on redirect type
            if ($debugMessageType === 'Captured 404') {
                $this->logger->debugMessage("Captured 404 for \"" . $row['from_url'] .
                    '" deleted (last used: ' . $row['last_used_formatted'] . ').');
            } else {
                // Auto and Manual redirects show from/to URLs
                $this->logger->debugMessage($debugMessageType . " from: " . $row['from_url'] . ' to: ' .
                    $row['best_guess_dest'] . ' deleted (last used: ' . $row['last_used_formatted'] . ').');
            }

            $abj404dao->deleteRedirect($row['id']);
            $deletedCount++;
        }

        return $deletedCount;
    }

    /** Delete old redirects based on how old they are. This runs daily.
     * @global type $wpdb
     * @global type $abj404dao
     * @global type $abj404logic
     */
    function deleteOldRedirectsCron() {
        global $wpdb;
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        
        $options = $abj404logic->getOptions();
        $now = time();
        $capturedURLsCount = 0;
        $autoRedirectsCount = 0;
        $manualRedirectsCount = 0;
        $oldLogRowsDeleted = 0;

        // If true then the user clicked the button to execute the mantenance.
        $manually_fired = $abj404dao->getPostOrGetSanitize('manually_fired', false);
        if ($this->f->strtolower($manually_fired) == 'true') {
            $manually_fired = true;
        } else {
            $manually_fired = false;
        }

        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->createDatabaseTables(false);

        // Ensure database connection is active for long-running maintenance operations
        // This prevents "MySQL server has gone away" errors
        $this->ensureConnection();

        // delete the export file
        $tempFile = $abj404logic->getExportFilename();
        if (file_exists($tempFile)) {
        	ABJ_404_Solution_Functions::safeUnlink($tempFile);
        }
        
        // reset the crashed table count
        $options['repaired_count'] = 0;
        $abj404logic->updateOptions($options);

        $duplicateRowsDeleted = $abj404dao->removeDuplicatesCron();

        // Remove Captured URLs
        if (array_key_exists('capture_deletion', $options) && $options['capture_deletion'] != '0') {
            $status_list = ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER;
            $capturedURLsCount = $this->deleteOldRedirectsByType($options, $now, 'capture_deletion', $status_list, 'Captured 404');
        }

        // Remove Automatic Redirects
        if (isset($options['auto_deletion']) && $options['auto_deletion'] != '0') {
            $status_list = ABJ404_STATUS_AUTO;
            $autoRedirectsCount = $this->deleteOldRedirectsByType($options, $now, 'auto_deletion', $status_list, 'Automatic redirect');
        }

        // Remove Manual Redirects
        if (isset($options['manual_deletion']) && $options['manual_deletion'] != '0') {
            $status_list = ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_REGEX;
            $manualRedirectsCount = $this->deleteOldRedirectsByType($options, $now, 'manual_deletion', $status_list, 'Manual redirect');
        }
        
        //Clean up old logs. prepare the query. get the disk usage in bytes. compare to the max requested
        // disk usage (MB to bytes). delete 1k rows at a time until the size is acceptable.
        $logsSizeBytes = $abj404dao->getLogDiskUsage();
        $maxLogSizeBytes = (array_key_exists('maximum_log_disk_usage', $options) ? $options['maximum_log_disk_usage'] : 100) * 1024 * 1000;
        
        $totalLogLines = $abj404dao->getLogsCount(0);
        $averageSizePerLine = max($logsSizeBytes, 1) / max($totalLogLines, 1);
        $logLinesToKeep = ceil($maxLogSizeBytes / $averageSizePerLine);
        $logLinesToDelete = max($totalLogLines - $logLinesToKeep, 0);
        if ($logLinesToDelete == null || trim($logLinesToDelete) == '') {
        	$logLinesToDelete = 0;
        }
        if ($logLinesToDelete > 0) {
	        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/deleteOldLogs.sql");
	        $query = $this->f->str_replace('{lines_to_delete}', $logLinesToDelete, $query);
	        $results = $this->queryAndGetResults($query);
	        $oldLogRowsDeleted = $results['rows_affected'];
        }
        
        $logsSizeBytes = $abj404dao->getLogDiskUsage();
        $logSizeMB = round($logsSizeBytes / (1024 * 1000), 2);
        
        $renamed = $abj404dao->limitDebugFileSize();
        $renamed = $renamed ? "true" : "false";
        
        $message = "deleteOldRedirectsCron. Old captured URLs removed: " . 
                $capturedURLsCount . ", Old automatic redirects removed: " . $autoRedirectsCount .
                ", Old manual redirects removed: " . $manualRedirectsCount . 
                ", Old log lines removed: " . $oldLogRowsDeleted . ", New log size: " . $logSizeMB . "MB" . 
                ", Duplicate rows deleted: " . $duplicateRowsDeleted . ", Debug file size limited: " . 
                $renamed;
        
        // only send a 404 notification email during daily maintenance.
        if (isset($options['admin_notification_email']) &&
                $this->f->strlen(trim($options['admin_notification_email'])) > 5) {
            
            if ($manually_fired) {
                $message .= ', The admin email notification option is skipped for user '
                        . 'initiated maintenance runs.';
            } else {
                $message .= ', ' . $abj404logic->emailCaptured404Notification();
            }
        } else {
            $message .= ', Admin email notification option turned off.';
        }

        if (isset($options['send_error_logs']) &&
                $options['send_error_logs'] == '1') {
            if ($this->logger->emailErrorLogIfNecessary()) {
                $message .= ", Log file emailed to developer.";
            }
        }
        
        // add some entries to the permalink cache if necessary
        $abj404permalinkCache = ABJ_404_Solution_PermalinkCache::getInstance();
        $rowsUpdated = $abj404permalinkCache->updatePermalinkCache(15);
        $message .= ", Permlink cache rows updated: " . $rowsUpdated;
        
        $manually_fired_String = ($manually_fired) ? 'true' : 'false';
        $message .= ", User initiated: " . $manually_fired_String;
                
        $this->logger->infoMessage($message);
        
        // fix any lingering errors
        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->createDatabaseTables();
        
        $this->queryAndGetResults("optimize table {wp_abj404_redirects}");
        
        $upgradesEtc->updatePluginCheck();
        
        return $message;
    }
    
    function limitDebugFileSize() {
        $renamed = false;
        
        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        if ($mbFileSize > 10) {
            $this->logger->limitDebugFileSize();
            $renamed = true;
        }
        
        return $renamed;
    }
    
    /** Remove duplicates. 
     * @global type $wpdb
     */
    function removeDuplicatesCron() {
        $rowsDeleted = 0;
        $query = "SELECT COUNT(id) as repetitions, url FROM {wp_abj404_redirects} GROUP BY url HAVING repetitions > 1 ";
        $result = $this->queryAndGetResults($query);
        $outerRows = $result['rows'];
        foreach ($outerRows as $row) {
            $url = $row['url'];

            // Fix HIGH #2 (5th review): Use prepared statements instead of manual escaping
            $queryr1 = $this->prepare_query_wp(
                "select id from {wp_abj404_redirects} where url = {url} order by timestamp desc limit 0,1",
                array("url" => $url)
            );
            $result = $this->queryAndGetResults($queryr1);
            $innerRows = $result['rows'];
            if (count($innerRows) >= 1) {
                $row = $innerRows[0];
                $original = $row['id'];

                // Fix HIGH #2 (5th review): Use prepared statements instead of manual escaping
                $queryl = $this->prepare_query_wp(
                    "delete from {wp_abj404_redirects} where url = {url} and id != {original}",
                    array("url" => $url, "original" => $original)
                );
                $this->queryAndGetResults($queryl);
                $rowsDeleted++;
            }
        }

        // Invalidate status counts cache if any duplicates were removed
        if ($rowsDeleted > 0) {
            $this->invalidateStatusCountsCache();
        }

        return $rowsDeleted;
    }

    /**
     * Store a redirect for future use.
     * @global type $wpdb
     * @param string $fromURL
     * @param string $status ABJ404_STATUS_MANUAL etc
     * @param string $type ABJ404_TYPE_POST, ABJ404_TYPE_CAT, ABJ404_TYPE_TAG, etc.
     * @param string $final_dest
     * @param string $code
     * @param int $disabled
     * @return int
     */
    function setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled = 0) {
        global $wpdb;

        // nonce is verified outside of this method. We can't verify here because 
        // automatic redirects are sometimes created without user interaction.

        if (!is_numeric($type)) {
            $this->logger->errorMessage("Wrong data type for redirect. TYPE is non-numeric. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        } else if (absint($type) < 0) {
            $this->logger->errorMessage("Wrong range for redirect TYPE. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        } else if (!is_numeric($status)) {
            $this->logger->errorMessage("Wrong data type for redirect. STATUS is non-numeric. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        }

        // if we should not capture a 404 then don't.
        if (!array_key_exists(ABJ404_PP, $_REQUEST) ||
        		!array_key_exists('ignore_doprocess', $_REQUEST[ABJ404_PP]) ||
        		!@$_REQUEST[ABJ404_PP]['ignore_doprocess']) {
            $now = time();
            $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");

            // Normalize to relative path before storing (Issue #24)
            // Fix HIGH #1 (5th review): Abort operation if normalization fails
            // Storing un-normalized URLs causes permanent lookup failures
            $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
            if ($abj404logic === null) {
                $abj404logging = ABJ_404_Solution_Logging::getInstance();
                $abj404logging->errorMessage("CRITICAL: PluginLogic singleton not initialized in setupRedirect()! Cannot normalize URL, aborting: " . $fromURL);
                return 0;  // Abort - don't store un-normalized URL
            }
            $fromURL = $abj404logic->normalizeToRelativePath($fromURL);

            // Fix HIGH #1 (3rd review): Remove esc_sql() - wpdb->insert handles escaping
            $wpdb->insert($redirectsTable, array(
                'url' => $fromURL,
                'status' => $status,
                'type' => $type,
                'final_dest' => $final_dest,
                'code' => $code,
                'disabled' => $disabled,
                'timestamp' => $now
                    ), array(
                '%s',
                '%d',
                '%d',
                '%s',
                '%d',
                '%d',
                '%d'
                    )
            );

            // Invalidate caches
            $this->invalidateStatusCountsCache();
            // Clear regex cache in case a regex redirect was added
            if ($status == ABJ404_STATUS_REGEX) {
                $this->clearRegexRedirectsCache();
            }
        }

        return $wpdb->insert_id;
    }

    /** Get the redirect for the URL. 
     * @global type $wpdb
     * @param string $url
     * @return array
     */
    function getActiveRedirectForURL($url) {
        // Strip invalid UTF-8/control bytes but keep valid unicode for multilingual slugs.
        $url = $this->f->sanitizeInvalidUTF8($url);

        // Normalize to relative path before querying (Issue #24)
        // Fix HIGH #1 (5th review): Abort operation if normalization fails
        // Querying with un-normalized URLs causes lookup failures
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        if ($abj404logic === null) {
            $abj404logging = ABJ_404_Solution_Logging::getInstance();
            $abj404logging->errorMessage("CRITICAL: PluginLogic singleton not initialized in getActiveRedirectForURL()! Cannot normalize URL, aborting: " . $url);
            return array('id' => 0);  // Return empty result - no redirect found
        }
        $candidates = $abj404logic->getNormalizedUrlCandidates($url);
        foreach ($candidates as $candidate) {
            $redirect = $this->getActiveRedirectForNormalizedUrl($candidate);
            if ($redirect['id'] !== 0) {
                return $redirect;
            }
        }

        return array('id' => 0);
    }

    /** Get the redirect for the URL. 
     * @param string $url
     * @return array
     */
    function getExistingRedirectForURL($url) {
        // Strip invalid UTF-8/control bytes but keep valid unicode for multilingual slugs.
        $url = $this->f->sanitizeInvalidUTF8($url);

        // Normalize to relative path before querying (Issue #24)
        // Fix HIGH #1 (5th review): Abort operation if normalization fails
        // Querying with un-normalized URLs causes lookup failures
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        if ($abj404logic === null) {
            $abj404logging = ABJ_404_Solution_Logging::getInstance();
            $abj404logging->errorMessage("CRITICAL: PluginLogic singleton not initialized in getExistingRedirectForURL()! Cannot normalize URL, aborting: " . $url);
            return array('id' => 0);  // Return empty result - no redirect found
        }
        $candidates = $abj404logic->getNormalizedUrlCandidates($url);
        foreach ($candidates as $candidate) {
            $redirect = $this->getExistingRedirectForNormalizedUrl($candidate);
            if ($redirect['id'] !== 0) {
                return $redirect;
            }
        }

        return array('id' => 0);
    }

    private function getActiveRedirectForNormalizedUrl($url) {
        $redirect = array();

        // we look for two URLs that might match. one with a trailing slash and one without.
        // the one the user entered takes priority in case the admin added separate redirects for
        // cases with and without the slash (and for backward compatibility).
        $url1 = $url;
        $url2 = $url;
        if (substr($url, -1) === '/') {
            $url2 = rtrim($url, '/');
        } else {
            $url2 = $url2 . '/';
        }

        // join to the wp_posts table to make sure the post exists.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPermalinkFromURL.sql");
        // Fix HIGH #2 (5th review): Use prepared statements instead of manual escaping
        $query = $this->prepare_query_wp($query, array("url1" => $url1, "url2" => $url2));
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        if (is_array($rows)) {
            if (empty($rows)) {
                $redirect['id'] = 0;
            } else {
                foreach ($rows[0] as $key => $value) {
                    $redirect[$key] = $value;
                }
            }
        }

        if (!isset($redirect['id'])) {
            $redirect['id'] = 0;
        }

        return $redirect;
    }

    private function getExistingRedirectForNormalizedUrl($url) {
        $redirect = array();

        // a disabled value of '1' means in the trash.
        $query = $this->prepare_query_wp('select * from {wp_abj404_redirects} where BINARY url = BINARY {url} ' .
            " and disabled = 0 ", array("url" => $url));
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        if (is_array($rows)) {
            if (empty($rows)) {
                $redirect['id'] = 0;
            } else {
                foreach ($rows[0] as $key => $value) {
                    $redirect[$key] = $value;
                }
            }
        }

        if (!isset($redirect['id'])) {
            $redirect['id'] = 0;
        }

        return $redirect;
    }
    
    /** Returns rows with the IDs of the published items.
     * @global type $wpdb
     * @global type $abj404logic
     * @global type $abj404dao
     * @global type $abj404logging
     * @param string $slug only get results for this slug. (empty means all posts)
     * @param string $searchTerm use this string in a LIKE on the sql.
     * @param string $extraWhereClause use this string in a where on the sql.
     * @return array
     */
    function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '',
    	$limitResults = '', $orderResults = '', $extraWhereClause = '') {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        // Fix for missing table error (reported by 2 users - 4% of errors)
        // Check if wp_posts table exists before querying
        if (!$this->tableExists($wpdb->posts)) {
            $this->logger->errorMessage("WordPress posts table not found: " . $wpdb->posts .
                ". This may indicate an incorrect table prefix or database configuration issue.");
            return array(); // Return empty array instead of crashing
        }

        // get the valid post types
        $options = $abj404logic->getOptions();
        $postTypes = $this->f->explodeNewline($options['recognized_post_types']);
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($this->f->strtolower($postType)) . "', ";
        }
        $recognizedPostTypes = rtrim($recognizedPostTypes, ", ");
        // ----------------
        
        if ($slug != "") {
            // Sanitize invalid UTF-8 before SQL to prevent database errors
            // (fixes bug: URLs like %9F%9F%9F%9F-%9F%9F%9F-1.png cause "invalid data" errors)
            $slug = $this->f->sanitizeInvalidUTF8($slug);

            // Check if the post_name column supports utf8mb4 collation
            // (fixes bug: Arabic sites on latin1 databases get "invalid data" errors)
            // Note: Check actual column collation, not database default - on mixed setups
            // the database may be latin1 but wp_posts.post_name is utf8mb4
            $columnCollation = $wpdb->get_var($wpdb->prepare(
                "SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = %s
                 AND COLUMN_NAME = 'post_name'",
                $wpdb->posts
            ));
            if ($columnCollation !== null && strpos($columnCollation, 'utf8mb4') !== false) {
                // Column supports utf8mb4 - use CAST for proper Unicode comparison
                $specifiedSlug = " */\n and CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci = "
                        . "'" . esc_sql($slug) . "' \n ";
            } else {
                // Legacy column (latin1, utf8, etc.) - use simple comparison
                $specifiedSlug = " */\n and wp_posts.post_name = "
                        . "'" . esc_sql($slug) . "' \n ";
            }
        } else {
            $specifiedSlug = '';
        }
        
        if ($searchTerm != "") {
        	$searchTerm = " */\n and lower(wp_posts.post_title) like "
        		. "'%" . esc_sql($this->f->strtolower($searchTerm)) . "%' \n ";
        } else {
        	$searchTerm = '';
        }
        
        if ($extraWhereClause != "") {
        	$extraWhereClause = " */\n " . $extraWhereClause;
        }
        
        if (!empty($limitResults)) {
            $limitResults = " */\n  limit " . $limitResults;
        }
        if (!empty($orderResults)) {
        	$orderResults = " */\n  order by " . $orderResults;
        }
        
        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedPagesAndPostsIDs.sql");
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        $query = $this->f->str_replace('{specifiedSlug}', $specifiedSlug, $query);
        $query = $this->f->str_replace('{searchTerm}', $searchTerm, $query);
        $query = $this->f->str_replace('{extraWhereClause}', $extraWhereClause, $query);
        $query = $this->f->str_replace('{limit-results}', $limitResults, $query);
        $query = $this->f->str_replace('{order-results}', $orderResults, $query);
        
        $rows = $wpdb->get_results($query);

        // check for errors
        if ($wpdb->last_error) {
            $this->logger->errorMessage("Error executing query. Err: " . $wpdb->last_error . ", Query: " . $query);
        }
        
        return $rows;
    }

    /** Returns rows with the IDs of the published images.
     * @return array
     */
    function getPublishedImagesIDs() {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        
        // get the valid post types
        $options = $abj404logic->getOptions();
        $postTypes = $this->f->explodeNewline($options['recognized_post_types']);
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($this->f->strtolower($postType)) . "', ";
        }
        $recognizedPostTypes = rtrim($recognizedPostTypes, ", ");
        // ----------------
        
        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedImageIDs.sql");
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        
        $rows = $wpdb->get_results($query);
        // check for errors
        if ($wpdb->last_error) {
            $this->logger->errorMessage("Error executing query. Err: " . $wpdb->last_error . ", Query: " . $query);
        }
        
        return $rows;
    }

    /** Returns rows with the defined terms (tags).
     * @global type $wpdb
     * @return array
     */
    function getPublishedTags($slug = null, $limit = null) {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        // get the valid post types
        $options = $abj404logic->getOptions();

        $categories = $this->f->explodeNewline($options['recognized_categories']);
        $recognizedCategories = '';
        foreach ($categories as $category) {
            $recognizedCategories .= "'" . trim($this->f->strtolower($category)) . "', ";
        }
        $recognizedCategories = rtrim($recognizedCategories, ", ");

        if ($slug != null) {
            // Sanitize invalid UTF-8 before SQL to prevent database errors
            $slug = $this->f->sanitizeInvalidUTF8($slug);
            $slug = "*/ and wp_terms.slug = '" . esc_sql($slug) . "'\n";
        }

        $limitClause = '';
        if ($limit !== null && is_numeric($limit) && $limit > 0) {
            $limitClause = "LIMIT " . intval($limit);
        }

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedTags.sql");
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);
        
        $rows = $wpdb->get_results($query);
        // check for errors
        if ($wpdb->last_error) {
            $this->logger->errorMessage("Error executing query. Err: " . $wpdb->last_error . ", Query: " . $query);
        }
        
        $rows = $this->addURLToTermsRows($rows);
        
        return $rows;
    }
    
    function addURLToTermsRows($rows) {
    	// add url data
    	global $wp_rewrite;
    	$extraPermaStructureCache = array();
    	foreach ($rows as $row) {
    		$taxonomy = $row->taxonomy;
    		if (!array_key_exists($taxonomy, $extraPermaStructureCache)) {
    			$extraPermaStructureCache[$taxonomy] = $wp_rewrite->get_extra_permastruct($taxonomy);
    		}
    		$struct = $extraPermaStructureCache[$taxonomy];
    		
    		$url = str_replace('%' . $taxonomy . '%', $row->slug, $struct);
    		
    		// TODO verify one of the urls?
    		/*
    		if (!$verifiedOne) {
    			$id = $row->term_id;
    			$link = get_tag_link($id);
    			$link = get_category_link($id);
    			// $link should equal $url
		    	$verifiedOne = true;
    		}
    		*/
    		
    		$row->url = $url;
    	}
    	
    	return $rows;
    }
    
    /** Returns rows with the defined categories.
     * @global type $wpdb
     * @param int $term_id
     * @return array
     */
    function getPublishedCategories($term_id = null, $slug = null, $limit = null) {
        global $wpdb;
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

        // get the valid post types
        $options = $abj404logic->getOptions();

        $categories = $this->f->explodeNewline($options['recognized_categories']);
        $recognizedCategories = '';
        if (empty($categories)) {
            $recognizedCategories = "''";
        }
        foreach ($categories as $category) {
            $recognizedCategories .= "'" . trim($this->f->strtolower($category)) . "', ";
        }
        $recognizedCategories = rtrim($recognizedCategories, ", ");

        if ($term_id != null) {
            // Cast to integer for safety even though term_id is currently always null from callers
            $term_id = "*/ and {wp_terms}.term_id = " . intval($term_id) . "\n";
        }

        if ($slug != null) {
            // Sanitize invalid UTF-8 before SQL to prevent database errors
            $slug = $this->f->sanitizeInvalidUTF8($slug);
            $slug = "*/ and {wp_terms}.slug = '" . esc_sql($slug) . "'\n";
        }

        $limitClause = '';
        if ($limit !== null && is_numeric($limit) && $limit > 0) {
            $limitClause = "LIMIT " . intval($limit);
        }

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedCategories.sql");
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);
        $query = $this->f->str_replace('{term_id}', $term_id, $query);
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->doTableNameReplacements($query);
        
        $rows = $wpdb->get_results($query);
        // check for errors
        if ($wpdb->last_error) {
            $this->logger->errorMessage("Error executing query. Err: " . $wpdb->last_error . ", Query: " . $query);
        }
        
        $rows = $this->addURLToTermsRows($rows);
        
        return $rows;
    }

    /** Delete stored redirects based on passed in POST data.
     * @global type $wpdb
     * @return string
     */
    function deleteSpecifiedRedirects() {
        global $wpdb;
        $message = "";

        // nonce already verified.

        if (!array_key_exists('sanity_purge', $_POST) || $_POST['sanity_purge'] != "1") {
            $message = __('Error: You didn\'t check the I understand checkbox. No purging of records for you!', '404-solution');
            return $message;
        }
        
        if (!isset($_POST['types']) || $_POST['types'] == '') {
            $message = __('Error: No redirect types were selected. No purges will be done.', '404-solution');
            return $message;
        }
        
        if (is_array($_POST['types'])) {
            $type = array_map('sanitize_text_field', $_POST['types']);
        } else {
            $type = sanitize_text_field($_POST['types']);
        }

        if (!is_array($type)) {
            $message = __('An unknown error has occurred.', '404-solution');
            return $message;
        }
        
        $redirectTypes = array();
        foreach ($type as $aType) {
            if (('' . $aType != ABJ404_TYPE_HOME) && ('' . $aType != ABJ404_TYPE_HOME)) {
                array_push($redirectTypes, absint($aType));
            }
        }

        if (empty($redirectTypes)) {
            $message = __('Error: No valid redirect types were selected. Exiting.', '404-solution');
            $this->logger->debugMessage("Error: No valid redirect types were selected. Types: " .
                    wp_kses_post(json_encode($redirectTypes)));
            return $message;
        }
        $purge = sanitize_text_field($_POST['purgetype']);

        if ($purge != 'abj404_logs' && $purge != 'abj404_redirects') {
            $message = __('Error: An invalid purge type was selected. Exiting.', '404-solution');
            $this->logger->debugMessage("Error: An invalid purge type was selected. Type: " .
                    wp_kses_post(json_encode($purge)));
            return $message;
        }
        
        // always add the type "0" because it's an invalid type that may exist in the databse.
        // Adding it here does some cleanup if any is necessary.
        array_push($redirectTypes, 0);

        // Ensure all values are integers to prevent SQL injection
        $redirectTypes = array_map('absint', $redirectTypes);
        $typesForSQL = implode(',', $redirectTypes);

        if ($purge == 'abj404_redirects') {
            $query = "update {wp_abj404_redirects} set disabled = 1 where status in (" . $typesForSQL . ")";
            $query = $this->doTableNameReplacements($query);
            $redirectCount = $wpdb->query($query);
            
            $message .= sprintf( _n( '%s redirect entry was moved to the trash.', 
                    '%s redirect entries were moved to the trash.', $redirectCount, '404-solution'), $redirectCount);
        }

        return $message;
    }

    /**
     * This returns only the first column of the first row of the result.
     * @global type $wpdb
     * @param string $query a query that starts with "select count(id) from ..."
     * @param array $valueParams values to use to prepare the query.
     * @return int the count (result) of the query.
     */
    function getStatsCount($query, array $valueParams) {
        global $wpdb;

        if ($query == '') {
            return 0;
        }

        $results = $wpdb->get_col($wpdb->prepare($query, $valueParams));

        if (sizeof($results) == 0) {
            throw new Exception("No results for query: " . esc_html($query));
        }
        
        return intval($results[0]);
    }

    /** 
     * @global type $wpdb
     * @return int
     * @throws Exception
     */
    function getEarliestLogTimestamp() {
        global $wpdb;

        $query = 'SELECT min(timestamp) as timestamp FROM {wp_abj404_logsv2}';
        $query = $this->doTableNameReplacements($query);
        $results = $wpdb->get_col($query);

        if (sizeof($results) == 0) {
            return -1;
        }
        
        return intval($results[0]);
    }
    
    /** Look at $_POST and $_GET for the specified option and return the default value if it's not set.
     * @param string $name The key to retrieve the value for.
     * @param string $defaultValue The value to return if the value is not set.
     * @return string The sanitized value.
     */
    function getPostOrGetSanitize($name, $defaultValue = null) {
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        // Back-compat: some UI flows submit actions under 'abj404action' instead of 'action'.
        // Treat it as an alias so handlers that look for 'action' still run.
        if ($returnValue === null && $name === 'action') {
            $returnValue = isset($_GET['abj404action']) ? $_GET['abj404action'] : (isset($_POST['abj404action']) ? $_POST['abj404action'] : null);
        }
        if ($returnValue !== null) {
            if (is_array($returnValue)) {
                $returnValue = array_map('sanitize_text_field', $returnValue);
            } else {
                $returnValue = sanitize_text_field($returnValue);
            }
        }
        return $returnValue ?? $defaultValue;
    }

    /** Look at $_POST and $_GET for the specified URL option and return the default value if it's not set.
     * URL inputs should not use sanitize_text_field because it strips percent-encoded octets.
     * @param string $name The key to retrieve the value for.
     * @param string $defaultValue The value to return if the value is not set.
     * @return string The normalized URL value.
     */
    function getPostOrGetSanitizeUrl($name, $defaultValue = null) {
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        if ($returnValue === null) {
            return $defaultValue;
        }

        $f = ABJ_404_Solution_Functions::getInstance();
        $unslash = function($value) {
            return function_exists('wp_unslash') ? wp_unslash($value) : $value;
        };

        if (is_array($returnValue)) {
            return array_map(function($value) use ($f, $unslash) {
                $value = $unslash($value);
                return $f->normalizeUrlString($value);
            }, $returnValue);
        }

        $returnValue = $unslash($returnValue);
        return $f->normalizeUrlString($returnValue);
    }

    /** 
     * @global type $wpdb
     * @param array $ids
     * @return array
     */
    function getRedirectsByIDs($ids) {
        global $wpdb;
        $validids = array_map('absint', $ids);
        $multipleIds = implode(',', $validids);
    
        $query = "select id, url, type, status, final_dest, code from {wp_abj404_redirects} " .
                "where id in (" . $multipleIds . ")";
        $query = $this->doTableNameReplacements($query);
        $rows = $wpdb->get_results($query, ARRAY_A);
        
        return $rows;
    }
    
    /** Change the status to "trash" or "ignored," for example.
     * @global type $wpdb
     * @param int $id
     * @param string $newstatus
     * @return string
     */
    function updateRedirectTypeStatus($id, $newstatus) {
        // Use prepared statement to prevent SQL injection
        $query = "update {wp_abj404_redirects} set status = %s where id = %d";
        $result = $this->queryAndGetResults($query, array(
            'query_params' => array($newstatus, absint($id))
        ));

        // Invalidate caches - status change might affect regex redirects
        $this->invalidateStatusCountsCache();
        $this->clearRegexRedirectsCache();

        return $result['last_error'];
    }

    /** Move a redirect to the "trash" folder.
     * @global type $wpdb
     * @param int $id
     * @param int $trash 1 for trash, 0 for not trash.
     * @return string
     */
    function moveRedirectsToTrash($id, $trash) {
        global $wpdb;

        $message = "";
        $result = false;
        if ($this->f->regexMatch('[0-9]+', '' . $id)) {

            $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");
            $result = $wpdb->update($redirectsTable,
                    array('disabled' => esc_html($trash)), array('id' => absint($id)), array('%d'), array('%d')
            );

            // Invalidate caches - disabled change affects regex redirects
            $this->invalidateStatusCountsCache();
            $this->clearRegexRedirectsCache();
        }
        if ($result == false) {
            $message = __('Error: Unknown Database Error!', '404-solution');
        }
        return $message;
    }

    function updatePermalinkCache() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
    		"/sql/updatePermalinkCache.sql");

    	// Fix for MAX_JOIN_SIZE error (reported by 8 users - 18% of errors)
    	// Set SQL_BIG_SELECTS=1 to allow large queries during permalink cache updates
    	// IMPORTANT: This is a SESSION-LEVEL setting that only affects this connection
    	// and automatically expires when the script finishes (no permanent database changes)
    	// This prevents "The SELECT would examine more than MAX_JOIN_SIZE rows" error on large sites
    	global $wpdb;
    	$wpdb->query("SET SQL_BIG_SELECTS=1");

    	$results = $this->queryAndGetResults($query);

    	return $results;
    }
    
    function updatePermalinkCacheParentPages() {
    	$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
    		"/sql/updatePermalinkCacheParentPages.sql");
    	
    	// depthSoFar makes sure we don't have an infinite loop somehow.
    	$depthSoFar = 0;
    	$results = array();
    	do {
    		$results = $this->queryAndGetResults($query);
    		$depthSoFar++;
    	} while ($results['rows_affected'] != 0 && $depthSoFar < 15);
    	
    	return $results;
    }

    /** 
     * @global type $wpdb
     * @global type $abj404logging
     * @param int $type ABJ404_EXTERNAL, ABJ404_POST, ABJ404_CAT, or ABJ404_TAG.
     * @param string $dest
     * @param string $fromURL
     * @param int $idForUpdate
     * @param string $redirectCode
     * @param string $statusType ABJ404_STATUS_MANUAL or ABJ404_STATUS_REGEX
     * @return string
     */
    function updateRedirect($type, $dest, $fromURL, $idForUpdate, $redirectCode, $statusType) {
        global $wpdb;
        
        if (($type < 0) || ($idForUpdate <= 0)) {
            $this->logger->errorMessage("Bad data passed for update redirect request. Type: " .
                esc_html($type) . ", Dest: " . esc_html($dest) . ", ID(s): " . esc_html($idForUpdate));
            echo __('Error: Bad data passed for update redirect request.', '404-solution');
            return;
        }
        
        $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");
        $wpdb->update($redirectsTable, array(
        	'url' => $fromURL,
            'status' => $statusType,
            'type' => absint($type),
            'final_dest' => $dest,
            'code' => esc_attr($redirectCode)
                ), array(
            'id' => absint($idForUpdate)
                ), array(
            '%s',
            '%d',
            '%d',
            '%s',
            '%d'
                ), array(
            '%d'
                )
        );

        // Invalidate caches - status/url change affects regex redirects
        $this->invalidateStatusCountsCache();
        $this->clearRegexRedirectsCache();

        // move this redirect out of the trash.
        $this->moveRedirectsToTrash(absint($idForUpdate), 0);
    }

    /** 
     * @return int
     */
    function getCapturedCountForNotification() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        return $abj404dao->getRecordCount(array(ABJ404_STATUS_CAPTURED));
    }
    
}
