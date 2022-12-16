<?php

class ABJ_404_Solution_SlugChangeHandler {
    
    /** We'll just make sure the permalink gets updated in case it's changed.
     * @global type $abj404dao
	 * @param int $post_id The post ID.
	 * param post $post The post object.
	 * @param bool $update Whether this is an existing post being updated or not.     
	 */
    function save_postHandler($post_id, $post, $update) {
    	$abj404logging = ABJ_404_Solution_Logging::getInstance();
    	
    	if (!$update) {
    		$abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . 
                ": Non-update skipped for post ID " . $post_id . ".");
    		return;
    	}
    	
    	// only automatically create redirects if we're supposed to.
    	$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
    	$options = $abj404logic->getOptions();
    	if ($options['auto_redirects'] != '1') {
    		$abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Auto-redirects off " . 
    			"(skipped) (post ID " . $post_id . ").");
    		return;
    	}
    	
    	$postStatus = get_post_status($post_id);
    	if (!in_array($postStatus, array('publish', 'published'))) {
    		$abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Post status: " . 
    			$postStatus . " (skipped) (post ID " . $post_id . ").");
    		return;
    	}
    	
    	// get the old slug
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $options = $abj404logic->getOptions();
        
        $oldURL = $abj404dao->getPermalinkFromCache($post_id);
        
        if ($oldURL == null || $oldURL == "") {
        	$abj404logging->debugMessage("Couldn't find old slug for updated page. ID " . 
        		$post_id . ", old URL: " . $oldURL . ", post name: " . $post->post_name . 
        		", update: " . $update);
        	return;
        }

        $newURL = get_permalink($post);
        $oldURLParsed = parse_url($oldURL);
        $oldSlug = $oldURLParsed['path'];
        
        if ($oldURL == $newURL) {
        	$abj404logging->debugMessage("Save post listener: Old and new URL are the same. (Ignored) " . 
        		"ID: " . $post_id . ", old URL: " . $oldURL . ", old slug: " . $oldSlug . 
        		", new slug: " . $post->post_name . ", update: " . $update);
        		
        		return;
        }
        
        // create a redirect from the old to the new.
        $abj404dao->setupRedirect($oldSlug, ABJ404_STATUS_AUTO, ABJ404_TYPE_POST, 
        		$post_id, $options['default_redirect'], 0);
        $abj404logging->infoMessage("Added automatic redirect after slug change from " . 
        	$oldURL . ' to ' . $newURL . " for post ID " . $post_id);
    }
}
