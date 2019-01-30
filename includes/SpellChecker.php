<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Finds similar pages. 
 * Finds search suggestions. */

class ABJ_404_Solution_SpellChecker {
    
    private $separatingCharacters = array("-", "_", ".", "~", '%20');
    
    /** Same as above except without the period (.) because of the extension in the file name. */
    private $separatingCharactersForImages = array("-", "_", "~", '%20');

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
                
                // if the matching regex contains a group and the destination contains a replacement, 
                // then use them
                if ((preg_match("/\.*\(.+\).*/", $regexURL) != 0) && (strpos($permalink['link'], '$') !== FALSE)) {
                    $results = '';
                    $matcherQuoted = str_replace('~', '\~', $regexURL);
                    preg_match("~" . $matcherQuoted . "~", $requestedURL, $results);
                    
                    // do a repacement for all of the groups found.
                    $final = $permalink['link'];
                    for ($x = 1; $x < count($results); $x++) {
                        $final = str_replace('$' . $x , $results[$x] , $final);
                    } 
                    
                    $permalink['link'] = $final;
                }
                
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
            $permalinksPacket = $abj404spellChecker->findMatchingPosts($requestedURL, 
                    $options['auto_cats'], $options['auto_tags']);
            $permalinks = $permalinksPacket[0];
            $rowType = $permalinksPacket[1];
            
            $minScore = $options['auto_score'];

            // since the links were previously sorted so that the highest score would be first, 
            // we only use the first element of the array;
            $linkScore = reset($permalinks);
            $idAndType = key($permalinks);
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, $linkScore, $rowType);

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
    
    /** 
     * Return true if the last characters of the URL represent an image extension (like jpg, gif, etc).
     * @param type $requestedURL
     */
    function requestIsForAnImage($requestedURL) {
        $imageExtensions = array(".jpg", ".jpeg", ".gif", ".png", ".tif", ".tiff", ".bmp", ".pdf", 
            ".jif", ".jif", ".jp2", ".jpx", ".j2k", ".j2c", ".pcd");
        
        $returnVal = false;
        
        foreach ($imageExtensions as $extension) {
            if (ABJ_404_Solution_Functions::endsWithCaseInsensitive($requestedURL, $extension)) {
                $returnVal = true;
                break;
            }
        }
        
        return $returnVal;
    }

    /** Returns a list of 
     * @global type $wpdb
     * @param type $requestedURLRaw
     * @param type $includeCats
     * @param type $includeTags
     * @return type
     */
    function findMatchingPosts($requestedURLRaw, $includeCats = '1', $includeTags = '1') {
        global $abj404dao;
        global $abj404logic;
        
        $permalinks = array();
        
        $requestedURLSpaces = str_replace($this->separatingCharacters, " ", $requestedURLRaw);
        $requestedURLCleaned = $this->getLastURLPart($requestedURLSpaces);
        // this is the URL with the full path where / is replaces by spaces (" ").
        // this is useful for looking at URLs such as /category/behavior/. 
        $fullURLRaw = str_replace('/', " ", $requestedURLRaw);
        $fullURLspacesCleaned = str_replace('/', " ", $requestedURLSpaces);
        // if there is no extra stuff in the path then we ignore this to save time.
        if ($fullURLspacesCleaned == $requestedURLCleaned) {
            $fullURLspacesCleaned = '';
        }

        $rowType = 'pages';
        $rows = array();
        if ($this->requestIsForAnImage($requestedURLRaw)) {
            $rows = $abj404dao->getPublishedImagesIDs();
            $rowType = 'image';
            
        } else {
            // match based on the slug.
            $rows = $abj404dao->getPublishedPagesAndPostsIDs('', false);
        }
        // pre-filter some pages based on the min and max possible levenshtein distances.
        $likelyMatchIDs = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rows, $rowType);
        
        // use the levenshtein distance formula here.
        foreach ($likelyMatchIDs as $id) {
            $the_permalink = $this->getPermalink($id, $rowType);
            $urlParts = parse_url($the_permalink);
            $existingPageURL = $abj404logic->removeHomeDirectory($urlParts['path']);
            $existingPageURLSpaces = str_replace($this->separatingCharacters, " ", $existingPageURL);
            $existingPageURLCleaned = $this->getLastURLPart($existingPageURLSpaces);
            $scoreBasis = mb_strlen($existingPageURLCleaned) * 3;
            if ($scoreBasis == 0) {
                continue;
            }
            
            $levscore = $this->customLevenshtein($requestedURLCleaned, $existingPageURLCleaned);
            if ($fullURLspacesCleaned != '') {
                $levscore = min($levscore, $this->customLevenshtein($fullURLspacesCleaned, $existingPageURLCleaned));
            }
            if ($rowType == 'image') {
                // strip the image size from the file name and try again.
                // the image size is at the end of the file in the format of -640x480
                $strippedImageName = preg_replace('/(.+)([-]\d{1,5}[x]\d{1,5})([.].+)/', 
                        '$1$3', $requestedURLRaw);
                
                if (($strippedImageName != null) && ($strippedImageName != $requestedURLRaw)) {
                    $strippedImageName = str_replace($this->separatingCharactersForImages, " ", $strippedImageName);
                    $levscore = min($levscore, $this->customLevenshtein($strippedImageName, $existingPageURL));
                    
                    $strippedImageName = $this->getLastURLPart($strippedImageName);
                    $levscore = min($levscore, $this->customLevenshtein($strippedImageName, $existingPageURLCleaned));
                }
            }
            $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
            
            $permalinks[$id . "|" . ABJ404_TYPE_POST] = number_format($score, 4, '.', '');
        }

        // TODO ugh. find a better way than repeating this code.
        if ($rowType == 'image') {
            // This is sorted so that the link with the highest score will be first when iterating through.
            arsort($permalinks);
            $anArray = array($permalinks, $rowType);
            return $anArray;
        }
        
        // search for a similar tag.
        if ($includeTags == "1") {
            $rows = $abj404dao->getPublishedTags();

            // pre-filter some pages based on the min and max possible levenshtein distances.
            $likelyMatchIDs = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rows, 'tags');
            
            foreach ($likelyMatchIDs as $id) {
                $the_permalink = $this->getPermalink($id, 'tags');
                $urlParts = parse_url($the_permalink);
                $pathOnly = $abj404logic->removeHomeDirectory($urlParts['path']);
                $scoreBasis = mb_strlen($pathOnly);
                
                $levscore = $this->customLevenshtein($requestedURLCleaned, $pathOnly);
                if ($fullURLspacesCleaned != '') {
                    $pathOnlySpaces = str_replace('/', " ", $pathOnly);
                    $levscore = min($levscore, $this->customLevenshtein($fullURLspacesCleaned, $pathOnlySpaces));
                }
                $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
                $permalinks[$id . "|" . ABJ404_TYPE_TAG] = number_format($score, 4, '.', '');
            }
        }

        // search for a similar category.
        if ($includeCats == "1") {
            $rows = $abj404dao->getPublishedCategories();
            
            // pre-filter some pages based on the min and max possible levenshtein distances.
            $likelyMatchIDs = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rows, 'categories');
            
            foreach ($likelyMatchIDs as $id) {
                $the_permalink = $this->getPermalink($id, 'categories');
                $urlParts = parse_url($the_permalink);
                $pathOnly = $abj404logic->removeHomeDirectory($urlParts['path']);
                $scoreBasis = mb_strlen($pathOnly);
                if ($scoreBasis == 0) {
                    continue;
                }
                
                $levscore = $this->customLevenshtein($requestedURLCleaned, $pathOnly);
                if ($fullURLspacesCleaned != '') {
                    $pathOnlySpaces = str_replace('/', " ", $pathOnly);
                    $levscore = min($levscore, $this->customLevenshtein($fullURLspacesCleaned, $pathOnlySpaces));
                }
                $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
                $permalinks[$id . "|" . ABJ404_TYPE_CAT] = number_format($score, 4, '.', '');
            }
        }

        // This is sorted so that the link with the highest score will be first when iterating through.
        arsort($permalinks);
        return array($permalinks, $rowType);
    }
    
    /** 
     * Get the permalink for the passed in type (pages, tags, categories, image, etc.
     * @param type $id
     * @param type $rowType
     * @return type
     * @throws type
     */
    function getPermalink($id, $rowType) {
        if ($rowType == 'pages') {
            return urldecode(get_permalink($id));

        } else if ($rowType == 'tags') {
            return urldecode(get_tag_link($id));

        } else if ($rowType == 'categories') {
            return urldecode(get_category_link($id));

        } else if ($rowType == 'image') {
            $src = wp_get_attachment_image_src( $id, "attached-image");
            return urldecode($src[0]);

        } else {
            throw Exception("Unknown row type ...");
        }        
    }
    
    /** This algorithm uses the lengths of the strings to weed out some strings before using the levenshtein 
     * distance formula. It uses the minimum and maximum possible levenshtein distance based on the difference in 
     * string length. The min distance based on length between "abc" and "def" is 0 and the max distance is 3. 
     * The min distance based on length between "abc" and "123456" is 3 and the max distance is 6. 
     * 1) Get a list of minimum and maximum levenshtein distances - two lists, one ordered by the min distance 
     * and one ordered by the max distance. 
     * 2) Get the first X strings from the max-distance list. The X is the number we have to display in the list 
     * of suggestions on the 404 page. Note the highest max distance of the strings we're using here.
     * 3) Look at the min distance list and remove all strings where the min distance is more than the highest 
     * max distance taken from the previous step. The strings we remove here will always be further away than the 
     * strings we found in the previous step and can be removed without applying the levenshtein algorithm.
     * *
     * @global type $abj404logic
     * @param type $requestedURLCleaned
     * @param type $fullURLspaces
     * @param type $rows
     * @param type $rowType
     * @return array
     * @throws type
     */
    function getLikelyMatchIDs($requestedURLCleaned, $fullURLspaces, $rows, $rowType) {
        global $abj404logic;
        global $abj404logging;
        
        $options = $abj404logic->getOptions();
        
        // if there were no results then there are no likely matches.
        if (!is_array($rows)) {
            $abj404logging->debugMessage("Non-array value found in getLikelyMatchIDs: " . $rows);
            return array();
        }
        
        // create a list sorted by min levenshstein distance and max levelshtein distance.
        /* 1) Get a list of minumum and maximum levenshtein distances - two lists, one ordered by the min 
         * distance and one ordered by the max distance. */
        $minDistances = array();
        $maxDistances = array();
        for ($currentDistanceIndex = 0; $currentDistanceIndex <= 2083; $currentDistanceIndex++) {
            $maxDistances[$currentDistanceIndex] = array();
            $minDistances[$currentDistanceIndex] = array();
        }
        $requestedURLCleanedLength = mb_strlen($requestedURLCleaned);
        $fullURLspacesLength = mb_strlen($fullURLspaces);
        foreach ($rows as $row) {
            $pagePacket = array();
            
            $id = null;
            
            if ($rowType == 'pages') {
                $id = $row->id;
                
            } else if ($rowType == 'tags') {
                $id = $row->term_id;
                
            } else if ($rowType == 'categories') {
                $id = $row->term_id;
                
            } else if ($rowType == 'image') {
                $id = $row->id;
                
            } else {
                throw Exception("Unknown row type ...");
            }
            $the_permalink = $this->getPermalink($id, $rowType);
            $the_permalink = urldecode($the_permalink);
            
            $urlParts = parse_url($the_permalink);
            $existingPageURL = $abj404logic->removeHomeDirectory($urlParts['path']);
            $existingPageURLSpaces = str_replace($this->separatingCharacters, " ", $existingPageURL);
            $existingPageURLCleaned = $this->getLastURLPart($existingPageURLSpaces);
            
            // the minimum distance is the minimum of the two possibilities. one is longer anyway, so 
            // it shouldn't matter.
            $minDist = abs(mb_strlen($existingPageURLCleaned) - $requestedURLCleanedLength);
            if ($fullURLspaces != '') {
                $minDist2 = abs(mb_strlen($fullURLspacesLength) - $requestedURLCleanedLength);
                $minDist = min($minDist, $minDist2);
            }
            $maxDist = mb_strlen($existingPageURLCleaned);
            if ($fullURLspaces != '') {
                $maxDist2 = $fullURLspacesLength;
                $maxDist = min($maxDist, $maxDist2);
            }
            
            // create a data packet.
            $pagePacket['id'] = $id;
            $pagePacket['min-dist'] = $minDist;
            $pagePacket['max-dist'] = $maxDist;
            
            // add the packet to the list.
            if (is_array($minDistances[$minDist])) {
                array_push($minDistances[$minDist], $pagePacket);
            }
            if (is_array($maxDistances[$maxDist])) {
                array_push($maxDistances[$maxDist], $pagePacket);
            }
        }
        
        // look at the first X IDs with the lowest maximum levenshtein distance.
        /* 2) Get the first X strings from the max-distance list. The X is the number we have to display in the 
         * list of suggestions on the 404 page. Note the highest max distance of the strings we're using here. */
        $pagesSeenSoFar = 0;
        $currentDistanceIndex = 0;
        $maxDistFound = 300;
        $onlyNeedThisManyPages = absint($options['suggest_max']);
        for ($currentDistanceIndex = 0; $currentDistanceIndex <= 300; $currentDistanceIndex++) {
            $pagesSeenSoFar += sizeof($maxDistances[$currentDistanceIndex]);
            
            // we only need the closest matching X pages. where X is the number of suggestions 
            // to display on the 404 page.
            if ($pagesSeenSoFar >= $onlyNeedThisManyPages) {
                $maxDistFound = $currentDistanceIndex;
                break;
            }
        }
        
        // now use the maxDistFound to ignore all of the pages that have a higher minimum distance
        // than that number. All of those pages could never be a better match than the pages we 
        // have already found.
        /* 3) Look at the min distance list and remove all strings where the min distance is more than the 
         * highest max distance taken from the previous step. The strings we remove here will always be further 
         * away than the strings we found in the previous step and can be removed without applying the 
         * levenshtein algorithm. */
        $listOfIDs = array();
        for ($currentDistanceIndex = 0; $currentDistanceIndex <= $maxDistFound; $currentDistanceIndex++) {
            $listOfPackets = $minDistances[$currentDistanceIndex];
            foreach ($listOfPackets as $pagePacket) {
                array_push($listOfIDs, $pagePacket['id']);
            }
        }
        
        return $listOfIDs;
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
                // cost to delete/insert: = $m_min = $v0[$RowIdx] + 1;
                // cost to delete/isnert: = $v1[$RowIdx - 1] + 1;
                // cost to replace: = $v0[$RowIdx - 1] + $cost;
                
                $v1[$RowIdx] = min($v0[$RowIdx] + 1, $v1[$RowIdx - 1] + 1, $v0[$RowIdx - 1] + $cost);
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
