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
    /** 
     * @global type $abj404dao
     * @param type $requestedURL
     * @return type
     */
    function getPermalinkUsingRegEx($requestedURL) {
        global $abj404dao;
        
        $regexURLsRows = $abj404dao->getRedirectsWithRegEx();
        
        foreach ($regexURLsRows as $row) {
            $regexURL = $row['url'];
            
            $_REQUEST[ABJ404_PP]['debug_info'] = 'Applying regex "' . $regexURL . '" to URL: ' . $requestedURL;
            $preparedURL = str_replace('/', '\/', $regexURL);
            if (preg_match('/' . $preparedURL . '/', $requestedURL)) {
                $_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after regex.';
                $idAndType = $row['final_dest'] . '|' . $row['type'];
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, '0');
                $permalink['matching_regex'] = $regexURL;
                
                return $permalink;
            }
            
            $_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after regex.';
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
        $postsBySlugRows = $abj404dao->getPublishedPagesAndPostsIDs($postSlug, false);
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
     * @param type $requestedURL
     * @param type $includeCats
     * @param type $includeTags
     * @return type
     */
    function findMatchingPosts($requestedURL, $includeCats = '1', $includeTags = '1') {
        global $abj404dao;
        global $abj404logic;
        
        $permalinks = array();
        
        $separatingCharacters = array("-", "_", ".", "~", '%20');
        $requestedURLSpaces = str_replace($separatingCharacters, " ", $requestedURL);
        $requestedURLCleaned = $this->getLastURLPart($requestedURLSpaces);
        // this is the URL with the full path where / is replaces by spaces (" ").
        // this is useful for looking at URLs such as /category/behavior/. 
        $fullURLspaces = str_replace('/', " ", $requestedURLSpaces);
        // if there is no extra stuff in the path then we ignore this to save time.
        if ($fullURLspaces == $requestedURLCleaned) {
            $fullURLspaces = '';
        }

        // match based on the slug.
        $rows = $abj404dao->getPublishedPagesAndPostsIDs('', false);
        foreach ($rows as $row) {
            $id = $row->id;
            $the_permalink = get_permalink($id);
            $urlParts = parse_url($the_permalink);
            $existingPageURL = $abj404logic->removeHomeDirectory($urlParts['path']);
            $existingPageURLSpaces = str_replace($separatingCharacters, " ", $existingPageURL);
            $existingPageURLCleaned = $this->getLastURLPart($existingPageURLSpaces);
            $scoreBasis = mb_strlen($existingPageURLCleaned) * 3;
            if ($scoreBasis == 0) {
                continue;
            }
            
            $levscore = $this->customLevenshtein($requestedURLCleaned, $existingPageURLCleaned);
            if ($fullURLspaces != '') {
                $levscore = min($levscore, $this->customLevenshtein($fullURLspaces, $existingPageURLCleaned));
            }
            $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
            $permalinks[$id . "|" . ABJ404_TYPE_POST] = number_format($score, 4, '.', '');
        }

        // search for a similar tag.
        if ($includeTags == "1") {
            $rows = $abj404dao->getPublishedTags();
            foreach ($rows as $row) {
                $id = $row->term_id;
                $the_permalink = get_tag_link($id);
                $urlParts = parse_url($the_permalink);
                $pathOnly = $abj404logic->removeHomeDirectory($urlParts['path']);
                $scoreBasis = mb_strlen($pathOnly);
                
                $levscore = $this->customLevenshtein($requestedURLCleaned, $pathOnly);
                if ($fullURLspaces != '') {
                    $pathOnlySpaces = str_replace('/', " ", $pathOnly);
                    $levscore = min($levscore, $this->customLevenshtein($fullURLspaces, $pathOnlySpaces));
                }
                $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
                $permalinks[$id . "|" . ABJ404_TYPE_TAG] = number_format($score, 4, '.', '');
            }
        }

        // search for a similar category.
        if ($includeCats == "1") {
            $rows = $abj404dao->getPublishedCategories();
            foreach ($rows as $row) {
                $id = $row->term_id;
                $the_permalink = get_category_link($id);
                $urlParts = parse_url($the_permalink);
                $pathOnly = $abj404logic->removeHomeDirectory($urlParts['path']);
                $scoreBasis = mb_strlen($pathOnly);
                
                $levscore = $this->customLevenshtein($requestedURLCleaned, $pathOnly);
                if ($fullURLspaces != '') {
                    $pathOnlySpaces = str_replace('/', " ", $pathOnly);
                    $levscore = min($levscore, $this->customLevenshtein($fullURLspaces, $pathOnlySpaces));
                }
                $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
                $permalinks[$id . "|" . ABJ404_TYPE_CAT] = number_format($score, 4, '.', '');
            }
        }

        // This is sorted so that the link with the highest score will be first when iterating through.
        arsort($permalinks);
        
        return $permalinks;
    }
    
    /** Turns "/abc/defg" into "defg"
     * @param type $url
     * @return type
     */
    function getLastURLPart($url) {
        $newURL = $url;
        
        if (strrpos($url, "/")) {
            $newURL = mb_substr($url, strrpos($url, "/") + 1);
        }
        
        return $newURL;
    }

    /** 
     * @param type $str
     * @return type
     */
    private function multiByteStringToArray($str) {
        $length = mb_strlen($str);
        $array = array();
        for ($i = 0; $i < $length; $i++) {
            $array[$i] = mb_substr($str, $i, 1);
        }
        return $array;
    }
    
    /** This custom levenshtein function has no 255 character limit.
     * From https://www.codeproject.com/Articles/13525/Fast-memory-efficient-Levenshtein-algorithm
     * @param type $str1
     * @param type $str2
     * @return type
     * @throws Exception
     */
    function customLevenshtein($str1, $str2) {
        $_REQUEST[ABJ404_PP]['debug_info'] = 'customLevenshtein. str1: ' . esc_html($str1) . ', str2: ' . esc_html($str2);

        $sRow = $this->multiByteStringToArray($str1);
        $sCol = $this->multiByteStringToArray($str2);
        $RowLen = count($sRow);
        $ColLen = count($sCol);
        $cost = 0;
        
        /// Test string length. URLs should not be more than 2,083 characters
        if (max($RowLen, $ColLen) > 4096) {
            throw new Exception("Maximum string length in customLevenshtein is " . 4096 . ". Yours is " . 
                    max($RowLen, $ColLen) + ".");
        }
        
        // Step 1
        if ($RowLen == 0) {
            return $ColLen;
        } else if ($ColLen == 0) {
            return $RowLen;
        }

        /// Create the two vectors
        $v0 = array_fill(0, $RowLen + 1, 0);
        $v1 = array_fill(0, $RowLen + 1, 0);
            
        /// Step 2
        /// Initialize the first vector
        for ($RowIdx = 1; $RowIdx <= $RowLen; $RowIdx++) {
            $v0[$RowIdx] = $RowIdx;
        }
        
        // Step 3
        /// For each column
        for ($ColIdx = 1; $ColIdx <= $ColLen; $ColIdx++) {
            /// Set the 0'th element to the column number
            $v1[0] = $ColIdx;
            
            $Col_j = $sCol[$ColIdx - 1];

            // Step 4
            /// For each row
            for ($RowIdx = 1; $RowIdx <= $RowLen; $RowIdx++) {
                $Row_i = $sRow[$RowIdx - 1];
                
                // Step 5
                if ($Row_i == $Col_j) {
                    $cost = 0;
                } else {
                    $cost = 1;
                }

                // Step 6
                /// Find minimum
                $m_min = $v0[$RowIdx] + 1;
                $b = $v1[$RowIdx - 1] + 1;
                $c = $v0[$RowIdx - 1] + $cost;
                
                if ($b < $m_min) {
                    $m_min = $b;
                }
                if ($c < $m_min) {
                    $m_min = $c;
                }

                $v1[$RowIdx] = $m_min;
            }

            /// Swap the vectors
            $vTmp = $v0;
            $v0 = $v1;
            $v1 = $vTmp;
        }

        $_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after customLevenshtein.';
        return $v0[$RowLen];
    }

}
