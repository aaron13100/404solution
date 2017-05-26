<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist) && is_admin()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Finds similar pages. 
 * Finds search suggestions. */

class ABJ_404_Solution_SpellChecker {
    
    /** Spell check the user's word against correctly spelled words.
     * 
     * @param type $misspelledWord
     * @param type $correctlySpelledWord
     * @return type
     */
    function getSpellingMatch($misspelledWord, $correctlySpelledWord) {
        $matches = array();
        $word = strtolower($misspelledWord);
        
        // give every word a likelihood score.
        foreach ($correctlySpelledWord as $potentialMatch) {
            $potential = strtolower($potentialMatch);
            
            if ($word == $potential) {
                $matches[$potential] = 100;
                continue;
            }
            
            $levscore = levenshtein($word, $potential);
            $scoreBasis = strlen($potential) * 3;
            $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
            
            $matches[$potential] = $score;
        }
        
        // sort the array to find the words with the highest scores.
        arsort($matches);
        
        // if the top two words have the same score then return null.
        $bestMatch = array_slice($matches, 0, 1);
        $secondBest = array_slice($matches, 1, 1);
        
        // if the top two results have the same levenshiein score then we can't really tell which
        // one is the more correct answer.
        if (levenshtein($word, key($bestMatch)) == levenshtein($word, key($secondBest))) {
            return null;
        }
        
        if (reset($bestMatch) > 90) {
            return key($bestMatch);
        }
    
        return null;
    }

    /** If there is a post that has a slug that matches the user requested slug exactly, then return the permalink for that 
     * post. Otherwise return null.
     * @global type $abj404dao
     * @param type $requestedURL
     * @return type
     */
    function getPermalinkUsingSlug($requestedURL) {
        global $abj404dao;
        global $abj404logging;
        
        $exploded = preg_split('@/@', $requestedURL, -1, PREG_SPLIT_NO_EMPTY);
        $postSlug = end($exploded);
        $postsBySlugRows = $abj404dao->getPublishedPagesAndPostsIDs($postSlug);
        if (count($postsBySlugRows) == 1) {
            $post = reset($postsBySlugRows);
            $permalink['id'] = $post->id;
            $permalink['type'] = ABJ404_TYPE_POST;
            // the score doesn't matter.
            $permalink['score'] = 100;
            $permalink['title'] = get_the_title($post->id);
            $permalink['link'] = get_permalink($post->id);
            
            return $permalink;
            
        } else if (count($postsBySlugRows) > 1) {
            // more than one post has the same slug. I don't know what to do.
            $abj404logging->debugMessage("More than one post found with the slug, so no redirect was " .
                    "created. Slug: " . $postSlug);
        } else {
            $abj404logging->debugMessage("No posts or pages matching slug: " . esc_html($postSlug));
        }
        
        return null;
    }
    
    /** Use spell checking to find the correct link. Return the permalink (map) if there is one, otherwise return null.
     * @global type $abj404spellChecker
     * @global type $abj404logic
     * @param type $requestedURL
     * @return type
     */
    function getPermalinkUsingSpelling($requestedURL) {
        global $abj404spellChecker;
        global $abj404logic;
        global $abj404logging;

        $options = $abj404logic->getOptions();

        $found = 0;
        if (@$options['auto_redirects'] == '1') {
            // Site owner wants automatic redirects.
            $permalinks = $abj404spellChecker->findMatchingPosts($requestedURL, $options['auto_cats'], $options['auto_tags']);
            $minScore = $options['auto_score'];

            // since the links were previously sorted so that the highest score would be first, 
            // we only use the first element of the array;
            $linkScore = reset($permalinks);
            $idAndType = key($permalinks);
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, $linkScore);

            if ($permalink['score'] >= $minScore) {
                $found = 1;
            }

            if ($found == 1) {
                // We found a permalink that will work!
                $redirectType = $permalink['type'];
                if (('' . $redirectType != ABJ404_TYPE_404_DISPLAYED) && ('' . $redirectType != ABJ404_TYPE_HOME)) {
                    return $permalink;

                } else {
                    $abj404logging->errorMessage("Unhandled permalink type: " . 
                            wp_kses_post(json_encode($permalink)));
                    return null;
                }
            }
        }
        
        return null;
    }

    /** Returns a list of 
     * @global type $wpdb
     * @param type $url
     * @param type $includeCats
     * @param type $includeTags
     * @return type
     */
    function findMatchingPosts($url, $includeCats = '1', $includeTags = '1') {
        global $abj404dao;
        global $abj404logic;
        
        $permalinks = array();

        $rows = $abj404dao->getPublishedPagesAndPostsIDs();
        foreach ($rows as $row) {
            $id = $row->id;
            $the_permalink = get_permalink($id);
            $urlParts = parse_url($the_permalink);
            $urlPath = $abj404logic->removeHomeDirectory($urlParts['path']);
            $levscore = levenshtein($url, $urlPath, 1, 1, 1);
            $scoreBasis = strlen($urlPath) * 3;
            if ($scoreBasis == 0) {
                continue;
            }
            $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
            $permalinks[$id . "|" . ABJ404_TYPE_POST] = number_format($score, 4, '.', '');
        }

        if ($includeTags == "1") {
            $rows = $abj404dao->getPublishedTags();
            foreach ($rows as $row) {
                $id = $row->term_id;
                $the_permalink = get_tag_link($id);
                $urlParts = parse_url($the_permalink);
                $scoreBasis = strlen($urlParts['path']);
                $levscore = levenshtein($url, $urlParts['path'], 1, 1, 1);
                $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
                $permalinks[$id . "|" . ABJ404_TYPE_TAG] = number_format($score, 4, '.', '');
            }
        }

        if ($includeCats == "1") {
            $rows = $abj404dao->getPublishedCategories();
            foreach ($rows as $row) {
                $id = $row->term_id;
                $the_permalink = get_category_link($id);
                $urlParts = parse_url($the_permalink);
                $scoreBasis = strlen($urlParts['path']);
                $levscore = levenshtein($url, $urlParts['path'], 1, 1, 1);
                $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
                $permalinks[$id . "|" . ABJ404_TYPE_CAT] = number_format($score, 4, '.', '');
            }
        }

        // This is sorted so that the link with the highest score will be first when iterating through.
        arsort($permalinks);
        
        return $permalinks;
    }

}
