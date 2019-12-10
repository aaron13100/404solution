<?php

// turn on debug for localhost etc
if ($GLOBALS['abj404_display_errors']) {
	error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_SlugChangeListener {
    
    static function init() {
    	$me = new ABJ_404_Solution_SlugChangeListener();
        
        add_action('save_post', array($me, 'save_postListener'), 10, 3);
    }
    
    /** We'll just make sure the permalink gets updated in case it's changed.
     * @global type $abj404dao
	 * @param int $post_id The post ID.
	 * param post $post The post object.
	 * @param bool $update Whether this is an existing post being updated or not.     
	 */
    function save_postListener($post_id, $post, $update) {
    	if (!$update) {
    		return;
    	}
    	
    	// only automatically create redirects if we're supposed to.
    	$abj404logic = new ABJ_404_Solution_PluginLogic();
    	$options = $abj404logic->getOptions();
    	if ($options['auto_redirects'] != '1') {
    		return;
    	}
    	
    	$postStatus = get_post_status($post_id);
    	if ('publish' != $postStatus) {
    		return;
    	}
    	
    	// get the old slug
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $options = $abj404logic->getOptions();
        
        $oldSlug = $abj404dao->getOldSlug($post_id);
        
        if ($oldSlug == "") {
        	$abj404logging->debugMessage("Couldn't find old slug for updated page. ID " . 
        			$post_id . ", new slug: " . $post->post_name);
        	return;
        }
        
        $newSlug = $post->post_name;

        if ($oldSlug == $newSlug) {
        	return;
        }
        
        $newPermalink = get_permalink($post);
        $parsedURL = parse_url($newPermalink);
        $newURL = $parsedURL['path'];
        $fromURL = $f->str_replace($newSlug, $oldSlug, $newURL);
        
        // create a redirect from the old to the new.
        $abj404dao->setupRedirect($fromURL, ABJ404_STATUS_AUTO, ABJ404_TYPE_POST, 
        		$post_id, $options['default_redirect'], 0);
        $abj404logging->infoMessage("Added automatic redirect after slug change from " . 
        		$fromURL . ' to ' . $newURL);
    }
}
