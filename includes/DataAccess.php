<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should all reference one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DataAccess {
    
    const UPDATE_LOGS_HITS_TABLE_HOOK = 'abj404_updateLogsHitsTable_hook';
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_DataAccess();
            
            add_action(self::UPDATE_LOGS_HITS_TABLE_HOOK, 
                    array(self::$instance, 'createRedirectsForViewHitsTable'));
        }
        
        return self::$instance;
    }
    
    function getLatestPluginVersion() {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        if (!function_exists('plugins_api')) {
              require_once(ABSPATH . 'wp-admin/includes/plugin-install.php');
        }        
        if (!function_exists('plugins_api')) {
            $abj404logging->infoMessage("I couldn't find the plugins_api function to check for the latest version.");
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
            $abj404logging->errorMessage("There was an API issue checking the latest plugin version ("
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
            $abj404logging->infoMessage("Development version: A more recent version is installed than " . 
                    "what is available on the WordPress site (" . ABJ404_VERSION . " / " . 
                     $latestVersion . ").");
            return true;
        }
        
        $currentArray = explode(".", $currentVersion);
        $latestArray = explode(".", $latestVersion);
        
        if (count($currentArray) != 3 || count($latestArray) != 3) {
            $abj404logging->errorMessage("Issue parsing version numbers. " . 
                    $currentVersion . ' / ' . $latestVersion);
            
        } else if ($currentArray[0] == $latestArray[0] && $currentArray[1] == $latestArray[1]) {
            $difference = absint(absint($latestArray[2]) - absint($currentArray[2]));
            
            // if the major versions match then send the error file.
            if ($difference <= 2) {
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
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        $oldTable = $wpdb->prefix . 'wbz404_redirects';
        $newTable = $wpdb->prefix . 'abj404_redirects';
        // wp_wbz404_redirects -- old table
        // wp_abj404_redirects -- new table
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/importDataFromPluginRedirectioner.sql");
        $query = str_replace('{OLD_TABLE}', $oldTable, $query);
        $query = str_replace('{NEW_TABLE}', $newTable, $query);
        
        $result = $this->queryAndGetResults($query);

        $abj404logging->infoMessage("Importing redirectioner SQL result: " . 
                wp_kses_post(json_encode($result)));
        
        return $result;
    }
    
    function doTableNameReplacements($query) {
        global $wpdb;
        
        $repacements = array();
        foreach ($wpdb->tables as $tableName) {
            $repacements['{wp_' . $tableName . '}'] = $wpdb->prefix . $tableName;
        }
        
        // wp database table replacements
        $query = str_replace(array_keys($repacements), array_values($repacements), $query);
        
        // custom table replacements
        $query = mb_eregi_replace('[{]wp_abj404_(.*?)[}]', $wpdb->prefix . "abj404_\\1", $query);
        
        return $query;
    }
    
    /** Return the results of the query in a variable.
     * @param string $query
     * @param array $options
     * @return array
     */
    function queryAndGetResults($query, $options = array()) {
        global $wpdb;
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $options = array_merge(array('log_errors' => true, 
            'log_too_slow' => true), $options);
        
        $timer = new ABJ_404_Solution_Timer();
        
        $result = array();
        $result['rows'] = $wpdb->get_results($query, ARRAY_A);
        $result['elapsed_time'] = $timer->stop();
        $result['last_error'] = $wpdb->last_error;
        $result['last_result'] = $wpdb->last_result;
        $result['rows_affected'] = $wpdb->rows_affected;
        $result['insert_id'] = $wpdb->insert_id;
        
        if (!is_array($result['rows'])) {
        	$abj404logging->debugMessage("Query result is not an array. Query: " . $query . 
        			", result: " . json_encode($result));
        }
        
        if ($options['log_errors'] && $result['last_error'] != '') {
            if ($f->strpos($result['last_error'], 
                    "is marked as crashed and last (automatic?) repair failed") !== false) {
                $this->repairTable($result['last_error']);
            }
            
            $abj404logging->errorMessage("Ugh. SQL query error: " . $result['last_error'] . 
                    ", SQL: " . $query . ", Execution time: " . round($timer->getElapsedTime(), 2));
            
        } else {
            if ($options['log_too_slow'] && $timer->getElapsedTime() > 5) {
                $abj404logging->debugMessage("Slow query (" . round($timer->getElapsedTime(), 2) . " seconds): " . 
                        $query);
            }
        }
        
        return $result;
    }
    
    function repairTable($errorMessage) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $re = 'Table \'.*\/(.+)\' is marked as crashed and last \(automatic\?\) repair failed';
        $str = $errorMessage;
        $matches = null;

        $f->regexMatch($re, $str, $matches);
        if ($matches != null && $f->strlen($matches[1]) > 0) {
            $tableToRepair = $matches[1];
            if ($f->strpos($tableToRepair, "abj404") !== false) {
                $query = "repair table " . $tableToRepair;
                $result = $this->queryAndGetResults($query, array('log_errors' => false));
                $abj404logging->infoMessage("Attempted to repair table " . $tableToRepair . ". Result: " . 
                        json_encode($result));
            } else {
                // tell someone the table $tableToRepair is broken.
            }
        }
    }
    
    function executeAsTransaction($statementArray) {
        $logger = ABJ_404_Solution_Logging::getInstance();
        $exception = null;
        $allIsWell = true;
        
        global $wpdb;
        
        try {
            $wpdb->query('START TRANSACTION');
            
            foreach ($statementArray as $statement) {
                $wpdb->query($statement);
                if ($wpdb->last_error != null) {
                    $allIsWell = false;
                    $logger->errorMessage("Error executing SQL transaction: " . $wpdb->last_error);
                    $logger->errorMessage("SQL causing the transaction error: " . $statement);
                    break;
                }
            }
        } catch (Exception $ex) {
            $allIsWell = false;
            $exception = $ex;
        }
        
        if ($allIsWell && $exception == null) {
            $wpdb->query('commit');
            
        } else {
            $wpdb->query('rollback');
        }
        
        if ($exception != null) {
            throw new Exception($exception);
        }
    }
    
    function truncatePermalinkCacheTable() {
        global $wpdb;
       
        $permalinkCacheTable = $wpdb->prefix . 'abj404_permalink_cache';
        $query = "truncate table " . $permalinkCacheTable;
        $this->queryAndGetResults($query);
    }
    
    function removeFromPermalinkCache($post_id) {
        global $wpdb;
       
        $permalinkCacheTable = $wpdb->prefix . 'abj404_permalink_cache';
        $query = "delete from " . $permalinkCacheTable . " where id = '" . $post_id . "'";
        $this->queryAndGetResults($query);
    }
    function removeOldStructreFromPermalinkCache($correctPermalinkStructure) {
        global $wpdb;
        
        $permalinkCacheTable = $wpdb->prefix . 'abj404_permalink_cache';
        
        $query = "delete from " . $permalinkCacheTable . " where structure != '" . 
                esc_sql($correctPermalinkStructure) . "'";
        
        $this->queryAndGetResults($query);
    }
    
    function getIDsNeededForPermalinkCache() {
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        // get the valid post types
        $options = $abj404logic->getOptions();
        $postTypes = array_filter($f->regexSplit('\n', $f->strtolower($options['recognized_post_types'])),
                array($f, 'removeEmptyCustom'));
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($f->strtolower($postType)) . "', ";
        }
        $recognizedPostTypes = rtrim($recognizedPostTypes, ", ");
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getIDsNeededForPermalinkCache.sql");
        $query = str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        $query = $this->doTableNameReplacements($query);
        
        $results = $this->queryAndGetResults($query);
        
        return $results['rows'];
    }
    
    function insertPermalinkCache($id, $permalink, $permalinkStructure) {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/insertPermalinkCache.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{id}', esc_sql($id), $query);
        $query = str_replace('{url}', esc_sql($permalink), $query);
        $query = str_replace('{structure}', esc_sql($permalinkStructure), $query);

        $this->queryAndGetResults($query);
    }
    
    function getPermalinkFromCache($id) {
        $query = "select url from {wp_abj_permalink_cache} where id = " . $id;
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        
        $rows = $results['rows'];
        if (count($rows) == 0) {
            return null;
        }
        
        $row1 = $rows[0];
        return $row1['url'];
    }
    
    function getPermalinkCache() {
        $query = "select id, url from {wp_abj404_permalink_cache}";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        
        return $results['rows'];
    }
    
    function storeSpellingPermalinksToCache($requestedURLRaw, $returnValue) {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/insertSpellingCache.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{url}', esc_sql($requestedURLRaw), $query);
        $query = str_replace('{matchdata}', esc_sql(json_encode($returnValue)), $query);

        $this->queryAndGetResults($query);
    }
    
    function deleteSpellingCache() {
        $query = "truncate table {wp_abj404_spelling_cache}";
        $query = $this->doTableNameReplacements($query);

        $this->queryAndGetResults($query);
    }
    
    function getSpellingPermalinksFromCache($requestedURLRaw) {
        $query = "select * from {wp_abj404_spelling_cache} where url = '" . esc_sql($requestedURLRaw) . "'";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        
        $rows = $results['rows'];
        
        if (count($rows) == 0) {
            return array();
        }
        
        $row = $rows[0];
        $json = $row['matchdata'];
        $returnValue = json_decode($json);
        
        return $returnValue;
    }
    
    /** Insert data into the database.
     * @global type $wpdb
     * @param string $tableName
     * @param array $dataToInsert
     * @return array
     */
    function insertAndGetResults($tableName, $dataToInsert) {
        global $wpdb;
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();

        // get the data types
        $dataTypes = array();
        foreach ($dataToInsert as $key => $dataItem) {
            $currentDataType = gettype($dataItem);
            if ($currentDataType == 'double' || $currentDataType == 'integer') {
                $dataTypes[] = '%d';
                
            } else if ($currentDataType == 'boolean') {
                $dataTypes[] = '%s';
                
            } else {
                $dataTypes[] = '%s';
                
                // empty strings are stored as null in the database.
                if ($f->strlen($dataItem) == 0) {
                    $dataToInsert[$key] = null;
                }
            }
        }

        $wpdb->insert($tableName, $dataToInsert, $dataTypes);

        $results =  array();
        $errorThisRun = $wpdb->last_error;
        $queryThisRun = $wpdb->last_query;
        
        $results['last_error'] = $wpdb->last_error;
        $results['last_result'] = $wpdb->last_result;
        $results['rows_affected'] = $wpdb->rows_affected;
        $results['insert_id'] = $wpdb->insert_id;
        $results['last_query'] = $wpdb->last_query;

        if ($wpdb->last_error != '') {
            $abj404logging->errorMessage("Ugh. SQL insert error: " . $errorThisRun . 
                    ", Query: " . $queryThisRun);
        }

        return $results;
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
       if (count($captured) == 0) {
           $captured[0] = 0;
       }
       return intval($captured[0]);
   }
   
   /** Get all of the post types from the wp_posts table.
    * @global type $wpdb
    * @return string
    */
   function getAllPostTypes() {
       $query = "SELECT DISTINCT post_type FROM {wp_posts} order by post_type";
       $query = $this->doTableNameReplacements($query);
       $results = $this->queryAndGetResults($query);
       $rows = $results['rows'];
       
       $postType = array();
       
       foreach ($rows as $row) {
           array_push($postType, $row['post_type']);
       }
       
       return $postType;
   }
   
   /** Get the approximate number of bytes used by the logs table.
    * @global type $wpdb
    * @return int
    */
   function getLogDiskUsage() {
       global $wpdb;
       $abj404logging = ABJ_404_Solution_Logging::getInstance();
       
       // we have to analyze the table first for the query to be valid.
       $analyzeQuery = "OPTIMIZE TABLE {wp_abj404_logsv2}";
       $analyzeQuery = $this->doTableNameReplacements($analyzeQuery);
       $result = $this->queryAndGetResults($analyzeQuery);

       if ($result['last_error'] != '') {
           $abj404logging->errorMessage("Error: " . esc_html($result['last_error']));
           return -1;
       }
       
       $query = 'SELECT (data_length+index_length) tablesize FROM information_schema.tables ' . 
               'WHERE table_name=\'{wp_abj404_logsv2}\'';
       $query = $this->doTableNameReplacements($query);

       $size = $wpdb->get_col($query, 0);
       if (count($size) == 0) {
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
        global $wpdb;
        $recordCount = 0;

        if (count($types) >= 1) {

            $query = "select count(id) from {wp_abj404_redirects} where 1 and (";
            $query = $this->doTableNameReplacements($query);
            $x = 0;
            foreach ($types as $type) {
                if ($x >= 1) {
                    $query .= " or ";
                }
                $query .= "status = " . esc_sql($type);
                $x++;
            }
            $query .= ")";

            $query .= " and disabled = " . esc_sql($trashed);

            $row = $wpdb->get_row($query, ARRAY_N);
            $recordCount = $row[0];
        }

        return $recordCount;
    }

    /**
     * @global type $wpdb
     * @param int $logID only return results that correspond to the URL of this $logID. Use 0 to get all records.
     * @return int the number of records found.
     */
    function getLogsCount($logID) {
        global $wpdb;

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsCount.sql");
        $query = $this->doTableNameReplacements($query);
        
        if ($logID != 0) {
            $query = str_replace('/* {SPECIFIC_ID}', '', $query);
            $query = str_replace('{logID}', $logID, $query);
        }
        
        $row = $wpdb->get_row($query, ARRAY_N);
        if (count($row) == 0) {
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
     * @global type $wpdb
     * @return array
     */
    function getRedirectsWithRegEx() {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
                . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
                . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n ";
        $query .= "from {wp_abj404_redirects}\n " .
                "  LEFT OUTER JOIN {wp_posts} \n " .
                "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n ";
        
        $query .= "where status in (" . ABJ404_STATUS_REGEX . ") \n " .
                "     and disabled = 0";
        $query = $this->doTableNameReplacements($query);
        
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
        
        $query = $this->getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce, $limitStart, $limitEnd);
        
        // if this takes too long then rewrite how specific URLs are linked to from the redirects table.
        // they can use a different ID - not the ID from the logs table.
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];
        
        // populate the logs data if we need to
        if (!$queryAllRowsAtOnce) {
            $rows = $this->populateLogsData($rows);
        }
        
        return $rows;
    }
    
    function getRedirectsForViewCount($sub, $tableOptions) {
        $query = $this->getRedirectsForViewQuery($sub, $tableOptions, false, 0, PHP_INT_MAX);

        $query = 'select count(*) as count from (' . $query . "\n) bob";
        
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];
        $row = $rows[0];
        return $row['count'];
    }
    
    function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce, $limitStart, $limitEnd) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        global $abj404_redirect_types;
        global $abj404_captured_types;
        $f = ABJ_404_Solution_Functions::getInstance();

        $logsTableColumns = '';
        $logsTableJoin = '';
        $statusTypes = '';
        $trashValue = '';

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
            
            $logsTableJoin = "  LEFT OUTER JOIN {wp_abj404_logs_hits} logstable \n " . 
                    "  on wp_abj404_redirects.url = logstable.requested_url \n ";
        }
        
        if ($tableOptions['filter'] == 0 || $tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            if ($sub == 'abj404_redirects') {
                $statusTypes = implode(", ", $abj404_redirect_types);

            } else if ($sub == 'abj404_captured') {
                $statusTypes = implode(", ", $abj404_captured_types);

            } else {
                $abj404logging->errorMessage("Unrecognized sub type: " . esc_html($sub));
            }
            
        } else if ($tableOptions['filter'] == ABJ404_STATUS_MANUAL) {
            $statusTypes = implode(", ", array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));
            
        } else {
            $statusTypes = sanitize_text_field($tableOptions['filter']);
        }

        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $trashValue = 1;
        } else {
            $trashValue = 0;
        }

        $orderBy = $f->strtolower(sanitize_text_field($tableOptions['orderby']));
        if ($orderBy == "final_dest") {
            // TODO change the final dest type to an integer and store external URLs somewhere else.
            // TODO fix bug: pages that no longer exist appear for redirects. use an inner join on this query with the
            // wp_posts table. delete redirects for pages that no longer exist? what if they're in the trash and 
            // are then moved out of the trash?
            $orderBy = "IF (post_title is null, 'zzzzzzzzz', post_title)";
        }
        
        $orderByString = "order by " . $orderBy . " " . sanitize_text_field($tableOptions['order']);

        $searchFilterForRedirectsExists = "no redirects fiter text found";
        $searchFilterForCapturedExists = "no captured 404s filter text found";
        if ($tableOptions['filterText'] != '') {
            if ($sub == 'abj404_redirects') {
                $searchFilterForRedirectsExists = ' filtering on text: ' . esc_sql($tableOptions['filterText'] . ' */');
                
            } else if ($sub == 'abj404_captured') {
                $searchFilterForCapturedExists = ' filtering on text: ' . esc_sql($tableOptions['filterText'] . ' */');
                
            } else {
                throw new Exception("Unrecognized page for filter text request.");
            }
        }

        // ---
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsForView.sql");
        $query = str_replace('{logsTableColumns}', $logsTableColumns, $query);
        $query = str_replace('{logsTableJoin}', $logsTableJoin, $query);
        $query = str_replace('{statusTypes}', $statusTypes, $query);
        $query = str_replace('{trashValue}', $trashValue, $query);
        $query = str_replace('{orderByString}', $orderByString, $query);
        $query = str_replace('{limitStart}', $limitStart, $query);
        $query = str_replace('{limitEnd}', $limitEnd, $query);
        $query = str_replace('{searchFilterForRedirectsExists}', $searchFilterForRedirectsExists, $query);
        $query = str_replace('{searchFilterForCapturedExists}', $searchFilterForCapturedExists, $query);
        $query = str_replace('{filterText}', $tableOptions['filterText'], $query);
        $query = $this->doTableNameReplacements($query);
        
        if (array_key_exists('translations', $tableOptions)) {
            $keys = array_keys($tableOptions['translations']);
            $values = array_values($tableOptions['translations']);
            $query = str_replace($keys, $values, $query);
        }
        
        $query = $f->doNormalReplacements($query);
        
        return $query;
    }
    
    function maybeUpdateRedirectsForViewHitsTable() {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
                
        $query = "select table_comment from information_schema.tables where table_name = '{wp_abj404_logs_hits}'";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        
        // if the table already exists then just schedule it to be updated later.
        if ($results['rows'] != null && count($results['rows']) > 0) {
            // the table exists. let's find out how long it took to create the table last time.
            $rows = $results['rows'];
            $row1 = $rows[0];
            $timeToCreatePreviously = 999999;
            if (floatval($row1['table_comment']) > 0) {
                $timeToCreatePreviously = floatval($row1['table_comment']);
            }
            
            if ($timeToCreatePreviously < 5) {
                $abj404logging->debugMessage(__FUNCTION__ . " creating immediately because create time was " .
                        $timeToCreatePreviously . " seconds.");
                // it took less than 5 seconds less time so let's just do it again right now.
                $this->createRedirectsForViewHitsTable();
                
            } else {
                $abj404logging->debugMessage(__FUNCTION__ . " creating later because create time was " .
                        $timeToCreatePreviously . " seconds.");
                // it takes too long to make the user wait. we'll update it in the background.
                wp_schedule_single_event(1, self::UPDATE_LOGS_HITS_TABLE_HOOK);
            }
            
        } else {
            $abj404logging->debugMessage(__FUNCTION__ . " creating now because the table doesn't exist.");
            // if the table does not exist then create it right away.
            $this->createRedirectsForViewHitsTable();
        }
    }
    
    function createRedirectsForViewHitsTable() {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        $finalDestTable = $this->doTableNameReplacements("{wp_abj404_logs_hits}");
        $tempDestTable = $this->doTableNameReplacements("{wp_abj404_logs_hits}_temp");
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getRedirectsForViewTempTable.sql");
        $query = $this->doTableNameReplacements($query);
        
        // create a temp table
        $this->queryAndGetResults("drop table if exists " . $tempDestTable);
        $ttQuery = "create table " . $tempDestTable . " \n " . $query;
        $results = $this->queryAndGetResults($ttQuery, array('log_too_slow' => false));
        
        $elapsedTime = $results['elapsed_time'];
        $addComment = "ALTER TABLE " . $tempDestTable . " COMMENT '" . $results['elapsed_time'] . "'";
        $this->queryAndGetResults($addComment);
        
        // drop the old hits table and rename the temp table to the hits table as a transaction
        $statements = array(
            "drop table if exists " . $finalDestTable,
            "rename table " . $tempDestTable . ' to ' . $finalDestTable
        );
        $this->executeAsTransaction($statements);
        
        $abj404logging->debugMessage(__FUNCTION__ . " refreshed " . $finalDestTable . " in " . $elapsedTime . 
                " seconds.");
    }
    
    /** 
     * @param array $rows
     */
    function populateLogsData($rows) {
        // note: according to https://stackoverflow.com/a/10121508 we should not used a pointer here to modify
        // the data that we're currently looping through.
        foreach ($rows as &$row) {
            if ($row['url'] != null && !empty($row['url'])) {
                $logsData = $this->getLogsIDandURL($row['url']);
                if (!empty($logsData)) {
                    $row['logsid'] = $logsData[0]['logsid'];
                    $row['logshits'] = $logsData[0]['logshits'];
                    $row['last_used'] = $logsData[0]['last_used'];
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
        $whereClause = '';
        if ($specificURL != '') {
            $whereClause = "where requested_url = '" . $specificURL . "'";
        }
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsIDandURL.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{where_clause_here}', $whereClause, $query);
        
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        return $rows;
    }
    
    /** 
     * @global type $wpdb
     * @param string $specificURL
     * @return array
     */
    function getLogsIDandURLLike($specificURL = '', $limitResults) {
        $whereClause = '';
        if ($specificURL != '') {
            $whereClause = "where lower(requested_url) like lower('" . $specificURL . "')\n";
            $whereClause .= "and min_log_id = true";
        }
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogsIDandURLForAjax.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{where_clause_here}', $whereClause, $query);
        $query = str_replace('{limit-results}', 'limit ' . $limitResults, $query);
        
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
        $logsid_included = '';
        $logsid = '';
        if ($tableOptions['logsid'] != 0) {
            $logsid_included = 'specific logs id included. */';
            $logsid = esc_sql($tableOptions['logsid']);
        }
        $orderby = esc_sql(sanitize_text_field($tableOptions['orderby']));
        $order = esc_sql(sanitize_text_field($tableOptions['order']));
        $start = ( absint(sanitize_text_field($tableOptions['paged']) - 1)) * absint(sanitize_text_field($tableOptions['perpage']));
        $perpage = absint(sanitize_text_field($tableOptions['perpage']));
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getLogRecords.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{logsid_included}', $logsid_included, $query);
        $query = str_replace('{logsid}', $logsid, $query);
        $query = str_replace('{orderby}', $orderby, $query);
        $query = str_replace('{order}', $order, $query);
        $query = str_replace('{start}', $start, $query);
        $query = str_replace('{perpage}', $perpage, $query);

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
    function logRedirectHit($requestedURL, $action, $matchReason, $requestedURLDetail = null) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        
        $now = time();

        // no nonce here because redirects are not user generated.

        $options = $abj404logic->getOptions(true);
        $referer = wp_get_referer();
        $current_user = wp_get_current_user();
        $current_user_name = null;
        if (isset($current_user)) {
            $current_user_name = $current_user->user_login;
        }
        $ipAddressToSave = esc_sql($_SERVER['REMOTE_ADDR']);
        if (!array_key_exists('log_raw_ips', $options) || $options['log_raw_ips'] != '1') {
            $ipAddressToSave = md5($ipAddressToSave);
        }
        
        // we have to know what to set for the $minLogID value
        $minLogID = false;
        $query = "select id from {wp_abj404_logsv2}" . 
                " where requested_url = '" . esc_sql($requestedURL) . "' limit 1";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        if (count($results['rows']) == 0) {
            $minLogID = true;
        }
            
        // ------------ debug message begin
        $helperFunctions = ABJ_404_Solution_Functions::getInstance();
        $reasonMessage = trim(implode(", ", 
                    array_filter(
                    array($_REQUEST[ABJ404_PP]['ignore_doprocess'], $_REQUEST[ABJ404_PP]['ignore_donotprocess']))));
        $abj404logging->debugMessage("Logging redirect. Referer: " . esc_html($referer) . 
        		" | Current user: " . $current_user_name . " | From: " . urldecode($_SERVER['REQUEST_URI']) . 
                esc_html(" to: ") . esc_html($action) . ', Reason: ' . $matchReason . ", Ignore msg(s): " . 
                $reasonMessage . ', Execution time: ' . round($helperFunctions->getExecutionTime(), 2) . 
                ' seconds');
        // ------------ debug message end
        
        // insert the username into the lookup table and get the ID from the lookup table.
        $usernameLookupID = $this->insertLookupValueAndGetID($current_user_name);
        
        $logTableName = $this->doTableNameReplacements("{wp_abj404_logsv2}");

        $this->insertAndGetResults($logTableName, array(
            'timestamp' => esc_sql($now),
            'user_ip' => $ipAddressToSave,
            'referrer' => esc_sql($referer),
            'dest_url' => esc_sql($action),
            'requested_url' => esc_sql($requestedURL),
            'requested_url_detail' => esc_sql($requestedURLDetail),
            'username' => esc_sql($usernameLookupID),
            'min_log_id' => $minLogID,
        ));
    }
    
    /** Insert a value into the lookup table and return the ID of the value. 
     * @param string $valueToInsert
     */
    function insertLookupValueAndGetID($valueToInsert) {
        $query = "select id from {wp_abj404_lookup} where lkup_value = '" . $valueToInsert . "'";
        $query = $this->doTableNameReplacements($query);
        $results = $this->queryAndGetResults($query);
        
        if (sizeof($results['rows']) > 0) {
            // the value already exists so we only need to return the ID.
            $rows = $results['rows'];
            $row1 = $rows[0];
            $id = $row1['id'];
            return $id;
        }

        // insert the value since it's not there already.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/insertIntoLookupTable.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{lkup_value}', $valueToInsert, $query);
        $results = $this->queryAndGetResults($query);
        
        return $results['insert_id'];
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
            $queryRedirects = $wpdb->prepare("delete from " . $wpdb->prefix . "abj404_redirects where id = %d", $cleanedID);
            $wpdb->query($queryRedirects);
        }
    }

    /** Delete old redirects based on how old they are. This runs daily.
     * @global type $wpdb
     * @global type $abj404dao
     * @global type $abj404logic
     */
    function deleteOldRedirectsCron() {
        global $wpdb;
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $options = $abj404logic->getOptions();
        $now = time();
        $capturedURLsCount = 0;
        $autoRedirectsCount = 0;
        $manualRedirectsCount = 0;
        $oldLogRowsDeleted = 0;

        // If true then the user clicked the button to execute the mantenance.
        $manually_fired = $abj404dao->getPostOrGetSanitize('manually_fired', false);
        if ($f->strtolower($manually_fired) == 'true') {
            $manually_fired = true;
        }

        $duplicateRowsDeleted = $abj404dao->removeDuplicatesCron();

        //Remove Captured URLs
        if ($options['capture_deletion'] != '0') {
            $capture_time = $options['capture_deletion'] * 86400;
            $then = $now - $capture_time;

            //Find unused urls
            $status_list = ABJ404_STATUS_CAPTURED . ", " . ABJ404_STATUS_IGNORED . ", " . ABJ404_STATUS_LATER;

            $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getMostUnusedRedirects.sql");
            $query = $this->doTableNameReplacements($query);
            $query = str_replace('{status_list}', $status_list, $query);
            $query = str_replace('{timelimit}', $then, $query);
            
            // Find unused redirects
            $results = $this->queryAndGetResults($query);
            $rows = $results['rows'];
            
            foreach ($rows as $row) {
                // Remove Them
                $abj404logging->debugMessage("Captured 404 for \"" . $row['from_url'] . 
                        '" deleted (unused since ' . $row['last_used_formatted'] . ').');
                $abj404dao->deleteRedirect($row['id']);
                $capturedURLsCount++;
            }
        }

        // Remove Automatic Redirects
        if (array_key_exists('auto_deletion', $options) && isset($options['auto_deletion']) && $options['auto_deletion'] != '0') {
            $auto_time = $options['auto_deletion'] * 86400;
            $then = $now - $auto_time;

            $status_list = ABJ404_STATUS_AUTO;

            $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getMostUnusedRedirects.sql");
            $query = $this->doTableNameReplacements($query);
            $query = str_replace('{status_list}', $status_list, $query);
            $query = str_replace('{timelimit}', $then, $query);
            
            // Find unused redirects
            $results = $this->queryAndGetResults($query);
            $rows = $results['rows'];
            
            $rows = $results['rows'];
            foreach ($rows as $row) {
                // Remove Them
                $abj404logging->debugMessage("Automatic redirect from: " . $row['from_url'] . ' to: ' . 
                        $row['best_guess_dest'] . ' deleted (unused since ' . $row['last_used_formatted'] . ').');
                $abj404dao->deleteRedirect($row['id']);
                $autoRedirectsCount++;
            }
        }

        //Remove Manual Redirects
        if (array_key_exists('manual_deletion', $options) && isset($options['manual_deletion']) && $options['manual_deletion'] != '0') {
            $manual_time = $options['manual_deletion'] * 86400;
            $then = $now - $manual_time;
            
            $status_list = ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_REGEX;

            //Find unused urls
            $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getMostUnusedRedirects.sql");
            $query = $this->doTableNameReplacements($query);
            $query = str_replace('{wp_posts}', $wpdb->posts, $query);
            $query = str_replace('{wp_options}', $wpdb->options, $query);
            $query = str_replace('{status_list}', $status_list, $query);
            $query = str_replace('{timelimit}', $then, $query);
            
            $results = $this->queryAndGetResults($query);
            $rows = $results['rows'];
            
            foreach ($rows as $row) {
                // Remove Them
                $abj404logging->debugMessage("Manual redirect from: " . $row['from_url'] . ' to: ' . 
                        $row['best_guess_dest'] . ' deleted (unused since ' . $row['last_used_formatted'] . ').');
                $abj404dao->deleteRedirect($row['id']);
                $manualRedirectsCount++;
            }
        }
        
        //Clean up old logs. prepare the query. get the disk usage in bytes. compare to the max requested
        // disk usage (MB to bytes). delete 1k rows at a time until the size is acceptable.
        $logsSizeBytes = $abj404dao->getLogDiskUsage();
        $maxLogSizeBytes = $options['maximum_log_disk_usage'] * 1024 * 1000;
        
        $totalLogLines = $abj404dao->getLogsCount(0);
        $averageSizePerLine = max($logsSizeBytes, 1) / max($totalLogLines, 1);
        $logLinesToKeep = ceil($maxLogSizeBytes / $averageSizePerLine);
        $logLinesToDelete = max($totalLogLines - $logLinesToKeep, 0);
        
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/deleteOldLogs.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{lines_to_delete}', $logLinesToDelete, $query);
        
        $results = $this->queryAndGetResults($query);
        $oldLogRowsDeleted = $results['rows_affected'];
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
        if (array_key_exists('admin_notification_email', $options) && isset($options['admin_notification_email']) && 
                $f->strlen(trim($options['admin_notification_email'])) > 5) {
            
            if ($manually_fired) {
                $message .= ', The admin email notification option is skipped for user '
                        . 'initiated maintenance runs.';
            } else {
                $message .= ', ' . $abj404logic->emailCaptured404Notification();
            }
        } else {
            $message .= ', Admin email notification option turned off.';
        }

        if (array_key_exists('send_error_logs', $options) && isset($options['send_error_logs']) && 
                $options['send_error_logs'] == '1') {
            if ($abj404logging->emailErrorLogIfNecessary()) {
                $message .= ", Log file emailed to developer.";
            }
        }
        
        // add some entries to the permalink cache if necessary
        $abj404permalinkCache = new ABJ_404_Solution_PermalinkCache();
        $rowsUpdated = $abj404permalinkCache->updatePermalinkCache(15);
        $message .= ", Permlink cache rows updated: " . $rowsUpdated;
        
        $manually_fired_String = ($manually_fired) ? 'true' : 'false';
        $message .= ", User initiated: " . $manually_fired_String;
                
        $abj404logging->infoMessage($message);
        
        // fix any lingering errors
        $upgradesEtc = new ABJ_404_Solution_DatabaseUpgradesEtc();
        $upgradesEtc->createDatabaseTables();
        
        $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");

        $this->queryAndGetResults("optimize table " . $redirectsTable);
        
        $upgradesEtc->updatePluginCheck();
        
        return $message;
    }
    
    function limitDebugFileSize() {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $renamed = false;
        
        $mbFileSize = $abj404logging->getDebugFileSize() / 1024 / 1000;
        if ($mbFileSize > 10) {
            $abj404logging->limitDebugFileSize();
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
        $query = $this->doTableNameReplacements($query);
        $result = $this->queryAndGetResults($query);
        $outerRows = $result['rows'];
        foreach ($outerRows as $row) {
            $url = $row['url'];

            $queryr1 = "select id from {wp_abj404_redirects} where url = '" . esc_sql(esc_url($url)) . "' order by timestamp desc limit 0,1";
            $queryr1 = $this->doTableNameReplacements($queryr1);
            $result = $this->queryAndGetResults($queryr1);            
            $innerRows = $result['rows'];
            if (count($innerRows) >= 1) {
                $row = $innerRows[0];
                $original = $row['id'];

                $queryl = "delete from {wp_abj404_redirects} where url='" . esc_sql(esc_url($url)) . "' and id != " . esc_sql($original);
                $queryl = $this->doTableNameReplacements($queryl);
                $this->queryAndGetResults($queryl);
                $rowsDeleted++;
            }
        }
        
        return $rowsDeleted;
    }

    /**
     * Store a redirect for future use.
     * @global type $wpdb
     * @param string $fromURL
     * @param string $status
     * @param string $type
     * @param string $final_dest
     * @param string $code
     * @param int $disabled
     * @return int
     */
    function setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled = 0) {
        global $wpdb;
        $abj404logging = ABJ_404_Solution_Logging::getInstance();

        // nonce is verified outside of this method. We can't verify here because 
        // automatic redirects are sometimes created without user interaction.

        if (!is_numeric($type)) {
            $abj404logging->errorMessage("Wrong data type for redirect. TYPE is non-numeric. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        } else if (absint($type) < 0) {
            $abj404logging->errorMessage("Wrong range for redirect TYPE. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        } else if (!is_numeric($status)) {
            $abj404logging->errorMessage("Wrong data type for redirect. STATUS is non-numeric. From: " . 
                    esc_url($fromURL) . " to: " . esc_url($final_dest) . ", Type: " .esc_html($type) . ", Status: " . $status);
        }

        // if we should not capture a 404 then don't.
        if (!@$_REQUEST[ABJ404_PP]['ignore_doprocess']) {
            $now = time();
            $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");

            $wpdb->insert($redirectsTable, array(
                'url' => esc_sql($fromURL),
                'status' => esc_sql($status),
                'type' => esc_sql($type),
                'final_dest' => esc_sql($final_dest),
                'code' => esc_sql($code),
                'disabled' => esc_sql($disabled),
                'timestamp' => esc_sql($now)
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
        }
        
        return $wpdb->insert_id;
    }

    /** Get the redirect for the URL. 
     * @global type $wpdb
     * @param string $url
     * @return array
     */
    function getActiveRedirectForURL($url) {
        $f = ABJ_404_Solution_Functions::getInstance();
        $redirect = array();

        // join to the wp_posts table to make sure the post exists.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPermalinkFromURL.sql");
        $query = str_replace('{url}', esc_sql($url), $query);
        $query = $this->doTableNameReplacements($query);
        $query = $f->doNormalReplacements($query);
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        if (count($rows) == 0) {
            $redirect['id'] = 0;
            
        } else {
            foreach ($rows[0] as $key => $value) {
                $redirect[$key] = $value;
            }
        }
        return $redirect;
    }

    /** Get the redirect for the URL. 
     * @param string $url
     * @return array
     */
    function getExistingRedirectForURL($url) {
        $redirect = array();

        // a disabled value of '1' means in the trash.
        $query = "select * from {wp_abj404_redirects} where url = '" . esc_sql($url) . "'" .
                " and disabled = 0 "; 
        $query = $this->doTableNameReplacements($query);
        
        $results = $this->queryAndGetResults($query);
        $rows = $results['rows'];

        if (count($rows) == 0) {
            $redirect['id'] = 0;
            
        } else {
            foreach ($rows[0] as $key => $value) {
                $redirect[$key] = $value;
            }
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
     * @return array
     */
    function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '', $limitResults = '') {
        global $wpdb;
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        // get the valid post types
        $options = $abj404logic->getOptions();
        $postTypes = array_filter($f->regexSplit('\n', $f->strtolower($options['recognized_post_types'])),
                array($f, 'removeEmptyCustom'));
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($f->strtolower($postType)) . "', ";
        }
        $recognizedPostTypes = rtrim($recognizedPostTypes, ", ");
        // ----------------
        
        if ($slug != "") {
            $specifiedSlug = " */ and wp_posts.post_name = "
                    . "'" . esc_sql($slug) . "' \n ";
        } else {
            $specifiedSlug = '';
        }
        
        if ($searchTerm != "") {
            $searchTerm = " */ and lower(wp_posts.post_title) like "
                    . "'%" . esc_sql($f->strtolower($searchTerm)) . "%' \n ";
        } else {
            $searchTerm = '';
        }
        
        if (!empty($limitResults)) {
            $limitResults = " */  limit " . $limitResults;
        }
        
        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedPagesAndPostsIDs.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        $query = str_replace('{specifiedSlug}', $specifiedSlug, $query);
        $query = str_replace('{searchTerm}', $searchTerm, $query);
        $query = str_replace('{limit-results}', $limitResults, $query);
        
        $rows = $wpdb->get_results($query);

        // check for errors
        if ($wpdb->last_error) {
            $abj404logging->errorMessage("Error executing query. Err: " . $wpdb->last_error . ", Query: " . $query);
        }
        
        return $rows;
    }

    /** Returns rows with the IDs of the published images.
     * @return array
     */
    function getPublishedImagesIDs() {
        global $wpdb;
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        // get the valid post types
        $options = $abj404logic->getOptions();
        $postTypes = array_filter($f->regexSplit('\n', $f->strtolower($options['recognized_post_types'])),
                array($f, 'removeEmptyCustom'));
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($f->strtolower($postType)) . "', ";
        }
        $recognizedPostTypes = rtrim($recognizedPostTypes, ", ");
        // ----------------
        
        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedImageIDs.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        
        $rows = $wpdb->get_results($query);
        // check for errors
        if ($wpdb->last_error) {
            $abj404logging->errorMessage("Error executing query. Err: " . $wpdb->last_error . ", Query: " . $query);
        }
        
        return $rows;
    }

    /** Returns rows with the defined terms (tags).
     * @global type $wpdb
     * @return array
     */
    function getPublishedTags() {
        global $wpdb;
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        // get the valid post types
        $options = $abj404logic->getOptions();

        $categories = array_filter($f->regexSplit('\n', $f->strtolower($options['recognized_categories'])),
                array($f, 'removeEmptyCustom'));
        $recognizedCategories = '';
        foreach ($categories as $category) {
            $recognizedCategories .= "'" . trim($f->strtolower($category)) . "', ";
        }
        $recognizedCategories = rtrim($recognizedCategories, ", ");

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedTags.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{recognizedCategories}', $recognizedCategories, $query);
        
        $rows = $wpdb->get_results($query);
        // check for errors
        if ($wpdb->last_error) {
            $abj404logging->errorMessage("Error executing query. Err: " . $wpdb->last_error . ", Query: " . $query);
        }
        
        return $rows;
    }
    
    /** Returns rows with the defined categories.
     * @global type $wpdb
     * @param int $term_id
     * @return array
     */
    function getPublishedCategories($term_id = null) {
        global $wpdb;
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        // get the valid post types
        $options = $abj404logic->getOptions();

        $categories = array_filter($f->regexSplit('\n', $f->strtolower($options['recognized_categories'])),
                array($f, 'removeEmptyCustom'));
        $recognizedCategories = '';
        if (count($categories) == 0) {
            $recognizedCategories = "''";
        }
        foreach ($categories as $category) {
            $recognizedCategories .= "'" . trim($f->strtolower($category)) . "', ";
        }
        $recognizedCategories = rtrim($recognizedCategories, ", ");
        
        if ($term_id != null) {
            $term_id = "*/ and {wp_terms}.term_id = " . $term_id;
        }

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedCategories.sql");
        $query = $this->doTableNameReplacements($query);
        $query = str_replace('{recognizedCategories}', $recognizedCategories, $query);
        $query = str_replace('{term_id}', $term_id, $query);
        
        $rows = $wpdb->get_results($query);
        // check for errors
        if ($wpdb->last_error) {
            $abj404logging->errorMessage("Error executing query. Err: " . $wpdb->last_error . ", Query: " . $query);
        }
        
        return $rows;
    }

    /** Delete stored redirects based on passed in POST data.
     * @global type $wpdb
     * @return string
     */
    function deleteSpecifiedRedirects() {
        global $wpdb;
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $message = "";

        // nonce already verified.

        if (@$_POST['sanity_purge'] != "1") {
            $message = __('Error: You didn\'t check the I understand checkbox. No purging of records for you!', '404-solution');
            return $message;
        }
        
        if (!array_key_exists('types', $_POST) || !isset($_POST['types']) || $_POST['types'] == '') {
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
            $abj404logging->debugMessage("Error: No valid redirect types were selected. Types: " .
                    wp_kses_post(json_encode($redirectTypes)));
            return $message;
        }
        $purge = sanitize_text_field($_POST['purgetype']);

        if ($purge != 'abj404_logs' && $purge != 'abj404_redirects') {
            $message = __('Error: An invalid purge type was selected. Exiting.', '404-solution');
            $abj404logging->debugMessage("Error: An invalid purge type was selected. Type: " .
                    wp_kses_post(json_encode($purge)));
            return $message;
        }
        
        // always add the type "0" because it's an invalid type that may exist in the databse. 
        // Adding it here does some cleanup if any is necessary.
        array_push($redirectTypes, 0);
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
    function getStatsCount($query = '', array $valueParams) {
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
            throw new Exception("No results for query: " . esc_html($query));
        }
        
        return intval($results[0]);
    }
    
    /** 
     * Look at $_POST and $_GET for the specified option and return the default value if it's not set.
     * @param string $name
     * @param string $defaultValue
     * @return string
     */
    function getPostOrGetSanitize($name, $defaultValue = null) {
        if (array_key_exists($name, $_GET) && isset($_GET[$name])) {
            if (is_array($_GET[$name])) {
                return array_map('sanitize_text_field', $_GET[$name]);
            }
            return sanitize_text_field($_GET[$name]);

        } else if (array_key_exists($name, $_POST) && isset($_POST[$name])) {
            if (is_array($_POST[$name])) {
                return array_map('sanitize_text_field', $_POST[$name]);
            }
            return sanitize_text_field($_POST[$name]);

        } else {
            return $defaultValue;
        }
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
        $query = "update {wp_abj404_redirects} set status = '" . 
                esc_sql($newstatus) . "' where id = '" . esc_sql($id) . "'";
        $query = $this->doTableNameReplacements($query);
        $result = $this->queryAndGetResults($query);
        
        return $result['last_error'];
    }

    /** Move a redirect to the "trash" folder.
     * @global type $wpdb
     * @param int $id
     * @param int $trash
     * @return string
     */
    function moveRedirectsToTrash($id, $trash) {
        global $wpdb;
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $message = "";
        $result = false;
        if ($f->regexMatch('[0-9]+', '' . $id)) {

            $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");
            $result = $wpdb->update($redirectsTable, 
                    array('disabled' => esc_html($trash)), array('id' => absint($id)), array('%d'), array('%d')
            );
        }
        if ($result == false) {
            $message = __('Error: Unknown Database Error!', '404-solution');
        }
        return $message;
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
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        if (($type <= 0) || ($idForUpdate <= 0)) {
            $abj404logging->errorMessage("Bad data passed for update redirect request. Type: " .
                esc_html($type) . ", Dest: " . esc_html($dest) . ", ID(s): " . esc_html($idForUpdate));
            echo __('Error: Bad data passed for update redirect request.', '404-solution');
            return;
        }
        
        $redirectsTable = $this->doTableNameReplacements("{wp_abj404_redirects}");
        $wpdb->update($redirectsTable, array(
            'url' => esc_sql($fromURL),
            'status' => $statusType,
            'type' => absint($type),
            'final_dest' => esc_sql($dest),
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
