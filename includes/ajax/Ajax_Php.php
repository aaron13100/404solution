<?php

/* Funtcions supporting Ajax stuff.  */

class ABJ_404_Solution_Ajax_Php {

	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_Ajax_Php();
		}
		
		return self::$instance;
	}
	
	/** Find logs to display. */
	static function updateOptions() {
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		
		$abj404logic->updateOptionsFromPOST();
	}
	
    /** Find logs to display. */
    static function echoViewLogsFor() {
    	$abj404AjaxPhp = ABJ_404_Solution_Ajax_Php::getInstance();;
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $term = $f->strtolower(sanitize_text_field($_GET['term']));
        $suggestions = array();

        $suggestion = array();
        $suggestion['label'] = __('(Show All Logs)', '404-solution');
        $suggestion['category'] = 'Special';
        $suggestion['value'] = 0;
        $specialSuggestion = array();
        $specialSuggestion[] = $suggestion;
        
        $rows = $abj404dao->getLogsIDandURLLike('%' . $term . '%', ABJ404_MAX_AJAX_DROPDOWN_SIZE);
        $results = $abj404AjaxPhp->formatLogResults($rows);
        
        // limit search results
        $suggestions = $abj404AjaxPhp->provideSearchFeedback($results, $term);
        
        $suggestions = array_merge($specialSuggestion, $suggestions);
                
        echo json_encode($suggestions);
        
    	exit();
    }
    
    /** Find pages to redirect to that match a search term, then echo the results in a json format. */
    static function echoRedirectToPages() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404AjaxPhp = ABJ_404_Solution_Ajax_Php::getInstance();
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $term = $f->strtolower(sanitize_text_field($_GET['term']));
        $includeDefault404Page = $_GET['includeDefault404Page'] == "true";
        $includeSpecial = array_key_exists('includeSpecial', $_GET) && 
        	$_GET['includeSpecial'] == "true";
        $suggestions = array();
        
        // add the "Home Page" destination.
        $specialPages = $abj404AjaxPhp->getDefaultRedirectDestinations($includeDefault404Page, 
        	$includeSpecial);
        
        // query to get the posts and pages.
        $rowsOtherTypes = $abj404dao->getPublishedPagesAndPostsIDs('', $term, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
        // order the results. this also sets the page depth (for child pages).
        $rowsOtherTypes = $abj404logic->orderPageResults($rowsOtherTypes, true);
        $publishedPosts = $abj404AjaxPhp->formatRedirectDestinations($rowsOtherTypes);
        
        $cats = $abj404dao->getPublishedCategories();
        $categoryOptions = $abj404AjaxPhp->formatCategoryDestinations($cats);
        
        $tags = $abj404dao->getPublishedTags();
        $tagOptions = $abj404AjaxPhp->formatTagDestinations($tags);
        
        $customCategoriesMap = $abj404logic->getMapOfCustomCategories($cats);
        $customCategoryOptions = $abj404AjaxPhp->formatCustomCategoryDestinations($customCategoriesMap);
        
        // --------------------------------------- 
        // now we filter the results based on the search term.
        $specialPages = $abj404AjaxPhp->filterPages($specialPages, $term);
        $categoryOptions = $abj404AjaxPhp->filterPages($categoryOptions, $term);
        $tagOptions = $abj404AjaxPhp->filterPages($tagOptions, $term);
        $customCategoryOptions = $abj404AjaxPhp->filterPages($customCategoryOptions, $term);
        
        // combine and display the search results.
        $suggestions = array_merge($specialPages, $publishedPosts, $categoryOptions, $tagOptions, 
                $customCategoryOptions);

        // limit search results
        $suggestions = $abj404AjaxPhp->provideSearchFeedback($suggestions, $term);
                
        echo json_encode($suggestions);
        
    	exit();
    }
    
    /** Add a message about whether there are too many results or none at all.
     * @param array $suggestions
     * @param string $suggestions
     * @return string
     */
    function provideSearchFeedback($suggestions, $term) {
        $f = ABJ_404_Solution_Functions::getInstance();
        $category = '';
        
        if (empty($suggestions)) {
            // tell the user if there are no resluts.
            if (trim($f->strlen($term)) == 0) {
                $category = sprintf(__("(No matching results found.)", '404-solution'));
            } else {
                $category = sprintf(__("(No matching results found for \"%s.\")", '404-solution'), $term);
            }
            
        } else if (count($suggestions) > ABJ404_MAX_AJAX_DROPDOWN_SIZE) {
            // limit the results if there are too many
            $suggestions = array_slice($suggestions, 0, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
            if (trim($f->strlen($term)) == 0) {
                $category = sprintf(__("(Data truncated. Too many results!)", '404-solution'));
            } else {
                $category = sprintf(__("(Data truncated. Too many results for \"%s!\".)", '404-solution'), $term);
            }
            
        } else {
            if (trim($f->strlen($term)) == 0) {
                $category = sprintf(__("(All results displayed.)", '404-solution'));
            } else {
                $category = sprintf(__("(All results displayed for \"%s.\")", '404-solution'), $term);
            }
        }
        
        $suggestion = array();
        $suggestion['label'] = '';
        $suggestion['category'] = $category;
        $suggestion['value'] = '';
        $suggestion['data_overflow_item'] = 'true';
        $suggestions[] = $suggestion;
        
        return $suggestions;
    }
    
    /** Remove any results from the list that don't match the search term.
     * @param array $pagesToFilter
     * @param string $searchTerm
     * @return array
     */
    function filterPages($pagesToFilter, $searchTerm) {
        $f = ABJ_404_Solution_Functions::getInstance();
        if ($searchTerm == "") {
            return $pagesToFilter;
        }        

        // build a new list with only the included results to return.
        $newPagesList = array();
        
        foreach ($pagesToFilter as $page) {
        	$haystack = $f->strtolower($page['label']);
        	$haystack2 = $f->strtolower($page['category']);
        	$needle = $f->strtolower($searchTerm);
        	if ($f->strpos($haystack, $needle) !== false) {
        		$newPagesList[] = $page;
        	} else if ($f->strpos($haystack2, $needle) !== false) {
        		$newPagesList[] = $page;
        	}
        }
        
        return $newPagesList;
    }
    
    /** Create a "Home Page" destination.
     * @return string
     */
    function getDefaultRedirectDestinations($includeDefault404Page, $includeSpecial) {
        $arrayWrapper = array();
        $suggestion = array();
        
        // --- default 404 page
        if ($includeSpecial && $includeDefault404Page) {
            $suggestion['category'] = __('Special', '404-solution');
            $suggestion['label'] = __('(Default 404 Page)', '404-solution');
            $suggestion['value'] = ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED;
            // depth 0 means it's not a child page
            $suggestion['depth'] = '0';
            $arrayWrapper[] = $suggestion;
        }
        
        if ($includeSpecial) {
	        // --- home page
	        $suggestion['category'] = __('Special', '404-solution');
	        $suggestion['label'] = __('Home Page', '404-solution');
	        $suggestion['value'] = ABJ404_TYPE_HOME . '|' . ABJ404_TYPE_HOME;
	        // depth 0 means it's not a child page
	        $suggestion['depth'] = '0';
	        $arrayWrapper[] = $suggestion;
        }
        
        return $arrayWrapper;
    }
    
    /** Prepare categories for json output.
     * @param array $rows
     * @return string
     */
    function formatCategoryDestinations($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            if ($row->taxonomy != 'category') {
                continue;
            }
            
            $suggestion = array();
            $suggestion['label'] = $row->name;
            $suggestion['category'] = __('Categories', '404-solution');
            $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_CAT;
            // depth 0 means it's not a child page
            $suggestion['depth'] = '0';
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }
    
    /** Prepare tags for json output.
     * @param array $rows
     * @return string
     */
    function formatTagDestinations($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row->name;
            $suggestion['category'] = __('Tags', '404-solution');
            $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_TAG;
            // depth 0 means it's not a child page
            $suggestion['depth'] = '0';
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }
    
    /** Prepare custom categories for json output. 
     * @param array $customCategoriesMap
     * @return string
     */
    function formatCustomCategoryDestinations($customCategoriesMap) {
        $suggestions = array();
        
        foreach ($customCategoriesMap as $taxonomy => $rows) {
        
            foreach ($rows as $row) {

                $suggestion = array();
                $suggestion['label'] = $row->name;
                $suggestion['category'] = $taxonomy;
                $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_CAT;
                // depth 0 means it's not a child page
                $suggestion['depth'] = '0';

                $suggestions[] = $suggestion;
            }
        }
        
        return $suggestions;
    }
    
    /** Prepare pages and posts for json output. 
     * @param array $rows
     * @return array
     */
    function formatRedirectDestinations($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row->post_title;
            $suggestion['category'] = ucwords($row->post_type);
            $suggestion['value'] = $row->id . "|" . ABJ404_TYPE_POST;
            // depth 0 means it's not a child page
            $suggestion['depth'] = $row->depth;
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }

    /** Prepare log results for json output. 
     * @param array $rows
     * @return array
     */
    function formatLogResults($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row['requested_url'];
            $suggestion['category'] = 'Normal';
            $suggestion['value'] = $row['logsid'];
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }
    
}
