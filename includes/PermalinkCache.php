<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_PermalinkCache {
    
    /** The name of the hook to use in WordPress. */
    const UPDATE_PERMALINK_CACHE_HOOK = 'abj404_updatePermalinkCache_hook';
    
    /** The maximum number of times in a row to run the hook. */
    const MAX_EXECUTIONS = 15;
    
    static function init() {
        $me = new ABJ_404_Solution_PermalinkCache();
        
        add_action('updated_option', array($me, 'permalinkStructureChanged'), 10, 2);
        add_action('save_post', array($me, 'save_postListener'), 10, 1);
        add_action('delete_post', array($me, 'save_postListener'), 10, 1);
        
        add_action(ABJ_404_Solution_PermalinkCache::UPDATE_PERMALINK_CACHE_HOOK, 
                array($me, 'updatePermalinkCache'), 10, 2);
    }
    
    /** We'll just make sure the permalink gets updated in case it's changed.
     * @global type $abj404dao
     * @param int $post_id
     */
    function save_postListener($post_id) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . 
                ": Delete from permalink cache: " . $post_id);
        $abj404dao->removeFromPermalinkCache($post_id);

        // let's update some links.
        $this->updatePermalinkCache(0.1);
    }

    /** If the permalink structure changes then truncate the cache table and update some values.
     * @global type $abj404logging
     * @param string $var1
     * @param string $newStructure
     */
    function permalinkStructureChanged($var1, $newStructure) {
        if ($var1 != 'permalink_structure') {
            return;
        }
        
        // we need to truncate the permlink cache since the structure changed
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . 
                ": Truncating and updating permalink cache because the permalink structure changed to " . 
                $newStructure);
        
        $abj404dao->truncatePermalinkCacheTable();

        // let's take this opportunity to update some of the values in the cache table.
        // One second of runtime should update 800+ pages.
        $this->updatePermalinkCache(1);
    }
    
    /** 
     * @param int $maxExecutionTime
     * @param int $executionCount
     * @return int
     * @throws Exception
     */
    function updatePermalinkCache($maxExecutionTime, $executionCount = 1) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();

        $syncUtils = new ABJ_404_Solution_SynchronizationUtils();
        $key = "updatePermalinkCache";
        $uniqueID = '';
        
        try {
            $uniqueID = $syncUtils->synchronizerAcquireLockTry($key);
            if ($uniqueID == '') {
                $this->scheduleToRunAgain($executionCount);
                // the lock wasn't acquired.
                return;
            }
            
            $timer = new ABJ_404_Solution_Timer();
            $shouldRunAgain = false;
            $permalinkStructure = get_option('permalink_structure');
            
            $abj404dao->removeOldStructreFromPermalinkCache($permalinkStructure);

            $rowsInserted = 0;
            $rows = $abj404dao->getIDsNeededForPermalinkCache();
            
            $row = array_shift($rows);
            while ($row != null) {
                $id = $row['id'];

                $permalink = get_the_permalink($id);

                $abj404dao->insertPermalinkCache($id, $permalink, $permalinkStructure);
                $rowsInserted++;

                // if we've spent X seconds then we've spent enough time
                if ($timer->getElapsedTime() > $maxExecutionTime) {
                    $shouldRunAgain = true;
                    break;
                }
                
                if ($rowsInserted % 1000 == 0) {
                    $newPermalinkStructure = get_option('permalink_structure');
                    if ($permalinkStructure != $newPermalinkStructure) {
                        break;
                    }
                }
                
                $row = array_shift($rows);
            }
            
            // if there's more work to do then do it, up to a maximum of X times.
            if ($executionCount < self::MAX_EXECUTIONS && $shouldRunAgain) {
                // if the site has many pages then we might not be done yet, so we'll schedule ourselves to 
                // run this again right away as a scheduled event.
                $this->scheduleToRunAgain($executionCount + 1);
                $abj404logging->debugMessage($rowsInserted . " rows inserted into the permalink cache table in " .
                        round($timer->getElapsedTime(), 2) . " seconds on execution #" . $executionCount . 
                        ". shouldRunAgain: " . ($shouldRunAgain ? 'true' : 'false'));

            } else if (($executionCount > 1) || ($rowsInserted > 1)) {
                $abj404logging->debugMessage(__FUNCTION__ . " done updating. " . $rowsInserted . " rows inserted " .
                        "in " . round($timer->getElapsedTime(), 2) . " seconds on execution #" . $executionCount . 
                        ". shouldRunAgain: " . ($shouldRunAgain ? 'true' : 'false'));
                
                if ($executionCount == self::MAX_EXECUTIONS) {
                    $abj404logging->errorMessage(__FUNCTION__ . " max executions reached in " . __CLASS__);
                }
            }
            
            $newPermalinkStructure = get_option('permalink_structure');
            if ($permalinkStructure != $newPermalinkStructure) {
                $abj404dao->removeOldStructreFromPermalinkCache($newPermalinkStructure);
                $this->scheduleToRunAgain(1);
                $abj404logging->debugMessage("Scheduled another permalink cache updated because the structure changed "
                        . "while the cache was updating.");
            }

        } catch (Exception $ex) {
            $syncUtils->synchronizerReleaseLock($uniqueID, $key);
            throw new Exception($ex);
        }
        
        $syncUtils->synchronizerReleaseLock($uniqueID, $key);
        
        return $rowsInserted;
    }
    
    function scheduleToRunAgain($executionCount) {
        $maxExecutionTime = (int)ini_get('max_execution_time') - 5;
        $maxExecutionTime = max($maxExecutionTime, 25);
        
        wp_schedule_single_event(1, ABJ_404_Solution_PermalinkCache::UPDATE_PERMALINK_CACHE_HOOK,
                array($maxExecutionTime, $executionCount));
    }
    
    function getPermalinkFromCache($id) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        return $abj404dao->getPermalinkFromCache($id);
    }
    
    function getPermalinkCacheCopy() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        
        $timer = new ABJ_404_Solution_Timer();
        $helperFunctions = ABJ_404_Solution_Functions::getInstance();
        
        $rows = $abj404dao->getPermalinkCache();
        $_REQUEST[ABJ404_PP]['debug_info'] = __FUNCTION__ .' got ' . count($rows) . ' rows after ' . 
                round($timer->getElapsedTime(), 2) . " seconds. Total execution time so far: " . 
                round($helperFunctions->getExecutionTime(), 2) . " seconds.";
        
        $cache = array();
        $row = array_shift($rows);
        while ($row != null) {
            $id = $row['id'];
            $link = $row['url'];
            $cache[$id] = $link;
            
            $row = array_shift($rows);
        }

        $_REQUEST[ABJ404_PP]['debug_info'] = __FUNCTION__ .' created cache copy after ' . 
                round($timer->getElapsedTime(), 2) . " seconds. Total execution time so far: " . 
                round($helperFunctions->getExecutionTime(), 2) . " seconds.";
        
        return $cache;
    }

}

ABJ_404_Solution_PermalinkCache::init();
