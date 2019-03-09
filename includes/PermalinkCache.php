<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_PermalinkCache {
    
    const UPDATE_PERMALINK_CACHE_HOOK = 'updatePermalinkCache_hook';
    
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
     * @param type $post_id
     */
    function save_postListener($post_id) {
        global $abj404dao;
        global $abj404logging;
        
        $abj404logging->debugMessage(__FUNCTION__ . ": Delete from permalink cache: " . $post_id);
        $abj404dao->removeFromPermalinkCache($post_id);

        // let's update some links.
        $this->updatePermalinkCache(0.1);
    }

    /** If the permalink structure changes then truncate the cache table and update some values.
     * @global type $abj404logging
     * @param type $var1
     * @param type $newStructure
     * @return type
     */
    function permalinkStructureChanged($var1, $newStructure) {
        if ($var1 != 'permalink_structure') {
            return;
        }
        
        // we need to truncate the permlink cache since the structure changed
        global $abj404dao;
        global $abj404logging;
        
        $abj404logging->debugMessage("Updating permalink cache because the permalink structure changed to " . 
                $newStructure);
        
        $abj404dao->truncatePermalinkCacheTable();

        // let's take this opportunity to update some of the values in the cache table.
        // One second of runtime should update 800+ pages.
        $this->updatePermalinkCache(1);
    }
    
    /** 
     * @global type $abj404dao
     * @param type $maxExecutionTime
     * @return int the number of rows inserted.
     */
    function updatePermalinkCache($maxExecutionTime, $executionCount = 1) {
        global $abj404dao;
        global $abj404logging;

        $timer = new ABJ_404_Solution_Timer();
        $shouldRunAgain = false;
        $permalinkStructure = get_option('permalink_structure');
        $key = "updatePermalinkCache";
        $uniqueID = '';
        try {
            $uniqueID = ABJ_404_Solution_SynchronizationUtils::synchronizerAcquireLockTry($key);
            if ($uniqueID == '') {
                // the lock wasn't acquired.
                return;
            }
            
            $abj404dao->removeOldStructreFromPermalinkCache($permalinkStructure);

            $rowsInserted = 0;
            $rows = $abj404dao->getIDsNeededForPermalinkCache();
            foreach ($rows as $row) {
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
            }
            
            // if there's more work to do then do it, up to a maximum of X times.
            if ($rowsInserted > 0 && $executionCount < 10 && $shouldRunAgain) {
                // if the site has many pages then we might not be done yet, so we'll schedule ourselves to 
                // run this again right away as a scheduled event.
                wp_schedule_single_event(time() + 1, ABJ_404_Solution_PermalinkCache::UPDATE_PERMALINK_CACHE_HOOK,
                        array(25, $executionCount + 1));
                $abj404logging->debugMessage($rowsInserted . " rows inserted into the permalink cache table in " .
                        round($timer->getElapsedTime(), 2) . " seconds on execution #" . $executionCount);
            }
            
            $newPermalinkStructure = get_option('permalink_structure');
            if ($permalinkStructure != $newPermalinkStructure) {
                $abj404dao->removeOldStructreFromPermalinkCache($newPermalinkStructure);
                wp_schedule_single_event(1, ABJ_404_Solution_PermalinkCache::UPDATE_PERMALINK_CACHE_HOOK,
                        array(25, $executionCount + 1));
                $abj404logging->debugMessage("Scheduled another permalink cache updated because the structure changed "
                        . "while the cache was updating.");
            }

        } catch (Exception $ex) {
            ABJ_404_Solution_SynchronizationUtils::synchronizerReleaseLock($uniqueID, $key);
            throw new Exception($ex);
        }
        
        ABJ_404_Solution_SynchronizationUtils::synchronizerReleaseLock($uniqueID, $key);
        
        return $rowsInserted;
    }
    
    function getPermalinkFromCache($id) {
        global $abj404dao;
        return $abj404dao->getPermalinkFromCache($id);
    }
    
    function getPermalinkCacheCopy() {
        global $abj404dao;

        $rows = $abj404dao->getPermalinkCache();
        
        $cache = array();
        while (count($rows) > 0) {
            $row = array_shift($rows);
            
            $id = $row['id'];
            $link = $row['url'];
            $cache[$id] = $link;
        }
        
        return $cache;
    }

}

ABJ_404_Solution_PermalinkCache::init();
