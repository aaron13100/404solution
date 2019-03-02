<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_PermalinkCache {
    
    static function init() {
        $me = new ABJ_404_Solution_PermalinkCache();
        
        add_action('updated_option', array($me, 'permalinkStructureChanged'), 10, 2);
        add_action('save_post', array($me, 'save_postListener'), 10, 1);
        add_action('updatePermalinkCache_hook', array($me, 'updatePermalinkCache'), 10, 1);
    }
    
    /** We'll just make sure the permalink gets updated in case it's changed.
     * @global type $abj404dao
     * @param type $post_id
     */
    function save_postListener($post_id) {
        global $abj404dao;
        global $abj404logging;
        
        $abj404logging->debugMessage("Delete from permalink cache: " . $post_id);
        $abj404dao->removeFromPermalinkCache($post_id);
        
        // let's update some links.
        $this->updatePermalinkCache(0.1);
    }

    /** If the permalink structure changes then truncate the cache table and update some values.
     * @global type $abj404logging
     * @param type $var1
     * @param type $var2
     * @return type
     */
    function permalinkStructureChanged($var1, $var2) {
        if ($var1 != 'permalink_structure') {
            return;
        }
        
        // we need to truncate the permlink cache since the structure changed
        global $abj404dao;
        global $abj404logging;
        
        $abj404logging->debugMessage("Updating permalink cache because the permalink structure changed.");
        
        $abj404dao->truncatePermalinkCacheTable();

        // let's take this opportunity to update some of the values in the cache table.
        $this->updatePermalinkCache(5);
        
        // if the site has many pages then we might not be done yet, so we'll schedule ourselves to 
        // run this again right away as a scheduled event.
        wp_schedule_single_event( time() + 1, 'rudr_my_hook');
        wp_schedule_single_event(time() + 1, 'updatePermalinkCache_hook', array(1));
        wp_schedule_single_event(time() + 1, array(__CLASS__, 'testFunction'), array(2));
        wp_schedule_single_event(time() + 1, 'ABJ_404_Solution_PermalinkCache::testFunction', array(3));
    }
    
    static function testFunction($args) {
        global $abj404logging;
        
        $abj404logging->infoMessage("In " . __FUNCTION__ . ", arg: " . $args);
    }
    
    /** 
     * @global type $abj404dao
     * @param type $maxExecutionTime
     * @return int the number of rows inserted.
     */
    function updatePermalinkCache($maxExecutionTime) {
        global $abj404dao;
        global $abj404logging;
        
        $timer = new ABJ_404_Solution_Timer();
        $abj404logging->infoMessage("Beginning " . __FUNCTION__);
        
        $rowsInserted = 0;
        $rows = $abj404dao->getIDsNeededForPermalinkCache();
        foreach ($rows as $row) {
            $id = $row['id'];
            
            $permalink = get_the_permalink($id);
            
            $abj404dao->insertPermalinkCache($id, $permalink);
            $rowsInserted++;
            
            // if we've spent X seconds then we've spent enough time
            if ($timer->getElapsedTime() > $maxExecutionTime) {
                break;
            }
        }
        
        if ($rowsInserted > 0) {
            $abj404logging->debugMessage($rowsInserted . " rows inserted into the permalink cache table.");
        }
        
        return $rowsInserted;
    }

}

ABJ_404_Solution_PermalinkCache::init();

function rudr_change_email_in_10_min() {
    global $abj404logging;
    
    $abj404logging->infoMessage("RAN thing...");
}
 
// that's the action hook which will be executed in a 10 minutes
add_action( 'rudr_my_hook', 'rudr_change_email_in_10_min' );

if (@$_GET['activated'] == 'true') {
    wp_schedule_single_event( time() + 1, 'rudr_my_hook');
}
