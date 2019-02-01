<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_Ajax_Php {

    /** Find pages to redirect to that match a search term. */
    static function echoRedirectToPages() {
        global $abj404logging;
        global $abj404logic;
        global $abj404AjaxPhp;
        global $abj404dao;
        
        $term = $_GET['term'];
        $suggestions = array();
        $customTagsEtc = array();
        
        /*  from View.php: 
        $selectOptionsGoHere = $this->echoRedirectDestinationOptionsDefaults(''); // DONE
        $rowsOtherTypes = $abj404dao->getPublishedPagesAndPostsIDs('', true);
        $selectOptionsGoHere .= $this->echoRedirectDestinationOptionsOthers('', $rowsOtherTypes);
        $selectOptionsGoHere .= $this->echoRedirectDestinationOptionsCatsTags('');
        */
        
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
        
        $suggestions = array_merge($specialPages, $publishedPosts, $categoryOptions, $tagOptions, 
                $customCategoryOptions);
    	echo json_encode($suggestions);
        
    	exit();
    }
    
    function getDefaultRedirectDestinations() {
        $arrayWrapper = array();
        $suggestion = array();
        
        $suggestion['category'] = __('Special', '404-solution');
        $suggestion['label'] = __('Home Page', '404-solution');
        $suggestion['value'] = ABJ404_TYPE_HOME;
        $suggestion['depth'] = 'indent-depth-0';
        
        $arrayWrapper[] = $suggestion;
        return $arrayWrapper;
    }
    
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
            $suggestion['depth'] = 'indent-depth-0';
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }
    
    function formatTagDestinations($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row->name;
            $suggestion['category'] = __('Tags', '404-solution');
            $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_TAG;
            $suggestion['depth'] = 'indent-depth-0';
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }
    
    function formatCustomCategoryDestinations($customCategoriesMap) {
        $suggestions = array();
        
        foreach ($customCategoriesMap as $taxonomy => $rows) {
        
            foreach ($rows as $row) {

                $suggestion = array();
                $suggestion['label'] = $row->name;
                $suggestion['category'] = $taxonomy;
                $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_CAT;
                $suggestion['depth'] = 'indent-depth-0';

                $suggestions[] = $suggestion;
            }
        }
        
        return $suggestions;
    }
    
    function formatRedirectDestinations($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row->post_title;
            $suggestion['category'] = ucwords($row->post_type);
            $suggestion['value'] = $row->id . "|" . ABJ404_TYPE_POST;
            $suggestion['depth'] = 'indent-depth-' . $row->depth;
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }

}