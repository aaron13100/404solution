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
        // query to get the posts.
        $rowsOtherTypes = $abj404dao->getPublishedPagesAndPostsIDs('', $term);
        // order the results. this also sets the page depth (for child pages).
        $rowsOtherTypes = $abj404logic->orderPageResults($rowsOtherTypes, true);
        
        $publishedPosts = $abj404AjaxPhp->formatRedirectDestinations($rowsOtherTypes);
        /*
        $cats = $abj404dao->getPublishedCategories();
        foreach ($cats as $cat) {
            $taxonomy = $cat->taxonomy;
            if ($taxonomy != 'category') {
                // for custom categories we create a Map<String, List> where the key is the name
                // of the taxonomy and the list holds the rows that have the category info.
                if (!array_key_exists($taxonomy, $customTagsEtc) || $customTagsEtc[$taxonomy] == null) {
                    $customTagsEtc[$taxonomy] = array($cat);
                } else {
                    array_push($customTagsEtc[$taxonomy], $cat);
                }
                
                continue;
            }
            $suggestion = array();
            $suggestion['label'] = $cat->name;
            $suggestion['value'] = $cat->term_id . "|" . ABJ404_TYPE_CAT;
            $suggestion['category'] = __('Category', '404-solution');

            $suggestions[] = $suggestion;
        }
    	*/
        
        $suggestions = array_merge($specialPages, $publishedPosts);
    	echo json_encode($suggestions);
        
        /* $abj404logging->debugMessage("echoRedirectToPages() suggestions found for '" . 
                esc_html($term) . "': " . sizeof($suggestions) . ' : ' . json_encode($suggestions));
         * 
         */
        
    	exit();
    }
    
    function getDefaultRedirectDestinations() {
        $suggestion = array();
        $newSuggestion = array();
        
        $newSuggestion['category'] = __('Special', '404-solution');
        $newSuggestion['label'] = __('Home Page', '404-solution');
        $newSuggestion['value'] = ABJ404_TYPE_HOME;
        
        $suggestion[] = $newSuggestion;
        return $suggestion;
    }
    
    function formatRedirectDestinations($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row->post_title;
            $suggestion['category'] = ucwords($row->post_type);
            $suggestion['value'] = $row->id . "|" . ABJ404_TYPE_POST;
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }
    
    static function echoRedirectToPages_2() {
        global $abj404logging;
        
        $term = strtolower( $_GET['term'] );
        $suggestions = array();

        $loop = new WP_Query( 's=' . $term );

        while( $loop->have_posts() ) {
                $loop->the_post();
                $suggestion = array();
                $suggestion['label'] = '99_' . get_the_title();
                $suggestion['value'] = '88_' . get_permalink();
                $suggestion['category'] = 'CAT_65_';

                $suggestions[] = $suggestion;
        }

        wp_reset_query();
    	
    	$response = json_encode( $suggestions );

    	echo $response;
        
        $abj404logging->debugMessage("echoRedirectToPages() suggestions found for '" . 
                esc_html($term) . "': " . sizeof($suggestions));
        
    	exit();
    }

}
