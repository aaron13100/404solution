<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Funtcions supporting Ajax stuff.  */

class ABJ_404_Solution_Ajax_Php {

    /** Find pages to redirect to that match a search term, then echo the results in a json format. */
    static function echoRedirectToPages() {
        global $abj404logic;
        global $abj404AjaxPhp;
        global $abj404dao;
        
        $term = $_GET['term'];
        $suggestions = array();
        
        // add the "Home Page" destination.
        $specialPages = $abj404AjaxPhp->getDefaultRedirectDestinations();
        
        // query to get the posts and pages.
        $rowsOtherTypes = $abj404dao->getPublishedPagesAndPostsIDs('', $term);
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
    	echo json_encode($suggestions);
        
    	exit();
    }
    
    /** Remove any results from the list that don't match the search term.
     * @param type $pagesToFilter
     * @param type $searchTerm
     * @return type
     */
    function filterPages($pagesToFilter, $searchTerm) {
        if ($searchTerm == "") {
            return $pagesToFilter;
        }        

        // build a new list with only the included results to return.
        $newPagesList = array();
        
        foreach ($pagesToFilter as $page) {
            $haystack = mb_strtolower($page['label']);
            $needle = mb_strtolower($searchTerm);
            if (mb_strpos($haystack, $needle) !== false) {
                $newPagesList[] = $page;
            }
        }
        
        return $newPagesList;
    }
    
    /** Create a "Home Page" destination.
     * @return string
     */
    function getDefaultRedirectDestinations() {
        $arrayWrapper = array();
        $suggestion = array();
        
        $suggestion['category'] = __('Special', '404-solution');
        $suggestion['label'] = __('Home Page', '404-solution');
        $suggestion['value'] = ABJ404_TYPE_HOME . '|' . ABJ404_TYPE_HOME;
        // depth 0 means it's not a child page
        $suggestion['depth'] = '0';
        
        $arrayWrapper[] = $suggestion;
        return $arrayWrapper;
    }
    
    /** Prepare categories for json output.
     * @param type $rows
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
     * @param type $rows
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
     * @param type $customCategoriesMap
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
     * @param type $rows
     * @return type
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

}
