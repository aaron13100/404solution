<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], array($GLOBALS['abj404_whitelist']))) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Finds similar pages. 
 * Finds search suggestions. */

class ABJ_404_Solution_SpellChecker {
    
    private $separatingCharacters = array("-", "_", ".", "~", '%20');
    
    /** Same as above except without the period (.) because of the extension in the file name. */
    private $separatingCharactersForImages = array("-", "_", "~", '%20');

    static function init() {
        // any time a page is saved or updated, or the permalink structure changes, then we have to clear
        // the spelling cache because the results may have changed.
        $me = new ABJ_404_Solution_SpellChecker();
        
        add_action('updated_option', array($me, 'permalinkStructureChanged'), 10, 2);
        add_action('save_post', array($me, 'save_postListener'), 10, 1);
        add_action('delete_post', array($me, 'save_postListener'), 10, 1);
    }
    
    function save_postListener($post_id) {
        $abj404dao = new ABJ_404_Solution_DataAccess();
        $abj404logging = new ABJ_404_Solution_Logging();
        $abj404dao->deleteSpellingCache();
        $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . 
                ": Spelling cache deleted because a post was saved or updated.");
    }

    function permalinkStructureChanged($var1, $newStructure) {
        if ($var1 != 'permalink_structure') {
            return;
        }
        
        $abj404dao = new ABJ_404_Solution_DataAccess();
        $abj404logging = new ABJ_404_Solution_Logging();
        $abj404dao->deleteSpellingCache();
        $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . 
                ": Spelling cache deleted because the permalink structure changed.");
    }
    
    /** Find a match using the user-defined regex patterns.
     * @global type $abj404dao
     * @param type $requestedURL
     * @return type
     */
    function getPermalinkUsingRegEx($requestedURL) {
        $abj404dao = new ABJ_404_Solution_DataAccess();
        $f = new ABJ_404_Solution_Functions();
        
        $regexURLsRows = $abj404dao->getRedirectsWithRegEx();
        
        foreach ($regexURLsRows as $row) {
            $regexURL = $row['url'];
            
            $_REQUEST[ABJ404_PP]['debug_info'] = 'Applying custom regex "' . $regexURL . '" to URL: ' . 
                    $requestedURL;
            $preparedURL = str_replace('/', '\/', $regexURL);
            if ($f->regexMatch('/' . $preparedURL . '/', $requestedURL)) {
                $_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after regex.';
                $idAndType = $row['final_dest'] . '|' . $row['type'];
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, '0');
                $permalink['matching_regex'] = $regexURL;
                
                // if the matching regex contains a group and the destination contains a replacement, 
                // then use them
                if (($f->regexMatch("/\.*\(.+\).*/", $regexURL) != 0) && ($f->strpos($permalink['link'], '$') !== FALSE)) {
                    $results = '';
                    $matcherQuoted = str_replace('~', '\~', $regexURL);
                    $f->regexMatch("~" . $matcherQuoted . "~", $requestedURL, $results);
                    
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

    /** Find a match using the an exact slug match.    
     * If there is a post that has a slug that matches the user requested slug exactly, 
     * then return the permalink for that post. Otherwise return null.
     * @global type $abj404dao
     * @param type $requestedURL
     * @return type
     */
    function getPermalinkUsingSlug($requestedURL) {
        $abj404dao = new ABJ_404_Solution_DataAccess();
        $abj404logging = new ABJ_404_Solution_Logging();
        
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
    
    /** Find a match using the an exact slug match.    
     * Use spell checking to find the correct link. Return the permalink (map) if there is one, otherwise return null.
     * @global type $abj404spellChecker
     * @global type $abj404logic
     * @param type $requestedURL
     * @return type
     */
    function getPermalinkUsingSpelling($requestedURL) {
        $abj404spellChecker = new ABJ_404_Solution_SpellChecker();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404logging = new ABJ_404_Solution_Logging();

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
        $f = new ABJ_404_Solution_Functions();
        $imageExtensions = array(".jpg", ".jpeg", ".gif", ".png", ".tif", ".tiff", ".bmp", ".pdf", 
            ".jif", ".jif", ".jp2", ".jpx", ".j2k", ".j2c", ".pcd");
        
        $returnVal = false;
        
        foreach ($imageExtensions as $extension) {
            if ($f->endsWithCaseInsensitive($requestedURL, $extension)) {
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
        $abj404dao = new ABJ_404_Solution_DataAccess();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        
        $options = $abj404logic->getOptions();
        $onlyNeedThisManyPages = absint($options['suggest_max']);
        
        $permalinks = $this->getFromPermalinkCache($requestedURLRaw);
        if (!empty($permalinks)) {
            return $permalinks;
        }
        
        $requestedURLSpaces = str_replace($this->separatingCharacters, " ", $requestedURLRaw);
        $requestedURLCleaned = $this->getLastURLPart($requestedURLSpaces);
        $fullURLspacesCleaned = str_replace('/', " ", $requestedURLSpaces);
        // if there is no extra stuff in the path then we ignore this to save time.
        if ($fullURLspacesCleaned == $requestedURLCleaned) {
            $fullURLspacesCleaned = '';
        }
        
        // prepare to search using permalinks.
        // first get all permalinks
        $permalinkCache = new ABJ_404_Solution_PermalinkCache();
        $permalinkCache->updatePermalinkCache(25);
        $permalinkCache = null;
        
        $rowType = 'pages';
        $rowsAsObject = array();
        if ($this->requestIsForAnImage($requestedURLRaw)) {
            $rowsAsObject = $abj404dao->getPublishedImagesIDs();
            $rowType = 'image';
            
        } else {
            // match based on the slug.
            $rowsAsObject = $abj404dao->getPublishedPagesAndPostsIDs('');
        }
        
        // free memory in the published pages by removing all column data except id and term_id
        $rows = $this->getOnlyIDandTermID($rowsAsObject);
        unset($rowsAsObject);
        
        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - match on posts
        $permalinks = $this->matchOnPosts($permalinks, $requestedURLRaw, $requestedURLCleaned, 
                $fullURLspacesCleaned, $rows, $rowType);

        // if we only need images then we're done.
        if ($rowType == 'image') {
            // This is sorted so that the link with the highest score will be first when iterating through.
            arsort($permalinks);
            $anArray = array($permalinks, $rowType);
            return $anArray;
        }
        
        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - match on tags
        // search for a similar tag.
        if ($includeTags == "1") {
            $permalinks = $this->matchOnTags($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rows, 'tags');
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - match on categories
        // search for a similar category.
        if ($includeCats == "1") {
            $permalinks = $this->matchOnCats($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rows, 'categories');
        }

        // This is sorted so that the link with the highest score will be first when iterating through.
        arsort($permalinks);
        
        // only keep what we need. store them for later if necessary.
        $permalinks = array_splice($permalinks, 0, $onlyNeedThisManyPages);

        $returnValue = array($permalinks, $rowType);
        $abj404dao->storeSpellingPermalinksToCache($requestedURLRaw, $returnValue);
        $_REQUEST[ABJ404_PP]['permalinks_found'] = json_encode($returnValue);
        
        return $returnValue;
    }
    
    function getOnlyIDandTermID($rowsAsObject) {
        $rows = array();
        $objectRow = array_shift($rowsAsObject);
        while ($objectRow != null) {
            $rows[] = array(
                'id' => property_exists($objectRow, 'id') == true ? $objectRow->id : null,
                'term_id' => property_exists($objectRow, 'term_id') == true ? $objectRow->term_id : null
                );
            $objectRow = array_shift($rowsAsObject);
        }
        
        return $rows;
    }
    
    function getFromPermalinkCache($requestedURL) {
        // The request cache is used when the suggested pages shortcode is used.
        if (array_key_exists(ABJ404_PP, $_REQUEST) && array_key_exists('permalinks_found', $_REQUEST[ABJ404_PP]) &&
                !empty($_REQUEST[ABJ404_PP]['permalinks_found'])) {
            $permalinks = json_decode($_REQUEST[ABJ404_PP]['permalinks_found'], true);
            return $permalinks;
        }
        
        // check the database cache.
        $abj404dao = new ABJ_404_Solution_DataAccess();
        $returnValue = $abj404dao->getSpellingPermalinksFromCache($requestedURL);
        if (!empty($returnValue)) {
            return $returnValue;
        }
        
        return array();
    }
    
    function matchOnCats($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rows, $rowType) {
        $abj404dao = new ABJ_404_Solution_DataAccess();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $f = new ABJ_404_Solution_Functions();

        unset($rows);
        $rows = $abj404dao->getPublishedCategories();
        $rows = $this->getOnlyIDandTermID($rows);

        // pre-filter some pages based on the min and max possible levenshtein distances.
        $likelyMatchIDs = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rows, 'categories');

        // access the array directly instead of using a foreach loop so we can remove items
        // from the end of the array in the middle of the loop.
        foreach ($likelyMatchIDs as $id) {
            // use the levenshtein distance formula here.
            $the_permalink = $this->getPermalink($id, 'categories');
            $urlParts = parse_url($the_permalink);
            $pathOnly = $abj404logic->removeHomeDirectory($urlParts['path']);
            $scoreBasis = $f->strlen($pathOnly);
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
        
        return $permalinks;
    }
    
    function matchOnTags($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rows, $rowType) {
        $abj404dao = new ABJ_404_Solution_DataAccess();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $f = new ABJ_404_Solution_Functions();
        
        unset($rows);
        $rows = $abj404dao->getPublishedTags();
        $rows = $this->getOnlyIDandTermID($rows);

        // pre-filter some pages based on the min and max possible levenshtein distances.
        $likelyMatchIDs = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rows, 'tags');

        // access the array directly instead of using a foreach loop so we can remove items
        // from the end of the array in the middle of the loop.
        foreach ($likelyMatchIDs as $id) {        
            // use the levenshtein distance formula here.
            $the_permalink = $this->getPermalink($id, 'tags');
            $urlParts = parse_url($the_permalink);
            $pathOnly = $abj404logic->removeHomeDirectory($urlParts['path']);
            $scoreBasis = $f->strlen($pathOnly);
            if ($scoreBasis == 0) {
                continue;
            }

            $levscore = $this->customLevenshtein($requestedURLCleaned, $pathOnly);
            if ($fullURLspacesCleaned != '') {
                $pathOnlySpaces = str_replace('/', " ", $pathOnly);
                $levscore = min($levscore, $this->customLevenshtein($fullURLspacesCleaned, $pathOnlySpaces));
            }
            $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
            $permalinks[$id . "|" . ABJ404_TYPE_TAG] = number_format($score, 4, '.', '');
        }
        
        return $permalinks;
    }
    
    function matchOnPosts($permalinks, $requestedURLRaw, $requestedURLCleaned, $fullURLspacesCleaned, $rows, $rowType) {
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $f = new ABJ_404_Solution_Functions();
        
        // pre-filter some pages based on the min and max possible levenshtein distances.
        $likelyMatchIDs = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rows, $rowType);
    
        // access the array directly instead of using a foreach loop so we can remove items
        // from the end of the array in the middle of the loop.
        $topLevScores = array();
        while (count($likelyMatchIDs) > 0) {
            $id = array_shift($likelyMatchIDs);
            
            // use the levenshtein distance formula here.
            $the_permalink = $this->getPermalink($id, $rowType);
            $urlParts = parse_url($the_permalink);
            $existingPageURL = $abj404logic->removeHomeDirectory($urlParts['path']);
            $existingPageURLSpaces = str_replace($this->separatingCharacters, " ", $existingPageURL);
            $existingPageURLCleaned = $this->getLastURLPart($existingPageURLSpaces);
            $scoreBasis = $f->strlen($existingPageURLCleaned) * 3;
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
                $strippedImageName = $f->regexReplace('/(.+)([-]\d{1,5}[x]\d{1,5})([.].+)/', 
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
            $topLevScores[] = $levscore;
        }
        
        return $permalinks;
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
     * @param type $publishedPages 
     * @param type $rowType
     * @return array
     * @throws type
     */
    function getLikelyMatchIDs($requestedURLCleaned, $fullURLspaces, $publishedPages, $rowType) {
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404logging = new ABJ_404_Solution_Logging();
        $f = new ABJ_404_Solution_Functions();
        
        // if there were no results then there are no likely matches.
        if (!is_array($publishedPages)) {
            $abj404logging->errorMessage("Non-array value found in getLikelyMatchIDs: " . $publishedPages);
            return array();
        }
        
        $options = $abj404logic->getOptions();
        $onlyNeedThisManyPages = absint($options['suggest_max']);
        
        // create a list sorted by min levenshstein distance and max levelshtein distance.
        /* 1) Get a list of minumum and maximum levenshtein distances - two lists, one ordered by the min 
         * distance and one ordered by the max distance. */
        $minDistances = array();
        $maxDistances = array();
        for ($currentDistanceIndex = 0; $currentDistanceIndex <= 2083; $currentDistanceIndex++) {
            $maxDistances[$currentDistanceIndex] = array();
            $minDistances[$currentDistanceIndex] = array();
        }
        
        $requestedURLCleanedLength = $f->strlen($requestedURLCleaned);
        $fullURLspacesLength = $f->strlen($fullURLspaces);
        
        if ($rowType == 'pages') {
            $permalinkCacheObj = new ABJ_404_Solution_PermalinkCache();
            $permalinkCache = $permalinkCacheObj->getPermalinkCacheCopy();
            $permalinkCacheObj = null;
        }
        
        $wasntReadyCount = 0;
        $row = array_shift($publishedPages);
        while ($row != null) {
            $id = null;
            if ($rowType == 'pages') {
                $id = $row['id'];
                
            } else if ($rowType == 'tags') {
                $id = $row['term_id'];
                
            } else if ($rowType == 'categories') {
                $id = $row['term_id'];
                
            } else if ($rowType == 'image') {
                $id = $row['id'];
                
            } else {
                throw Exception("Unknown row type ... " . $rowType);
            }

            // use the permalink cache table if possible.
            $the_permalink = null;
            if ($rowType == 'pages') {
                $the_permalink = array_key_exists($id, $permalinkCache) == true ? $permalinkCache[$id] : null;
                $permalinkCache[$id] = null;
                unset($permalinkCache[$id]);
                
                if ($the_permalink == null) {
                    $the_permalink = $this->getPermalink($id, $rowType);
                    $wasntReadyCount++;
                }
                
            } else {
                // this line takes too long to execute when there are 10k+ pages.
                $the_permalink = $this->getPermalink($id, $rowType);
            }
            
            $the_permalink = urldecode($the_permalink);
            $urlParts = parse_url($the_permalink);
            $the_permalink = null;
            
            $existingPageURL = $abj404logic->removeHomeDirectory($urlParts['path']);
            $urlParts = null;
            
            $existingPageURLSpaces = str_replace($this->separatingCharacters, " ", $existingPageURL);
            $existingPageURLCleaned = $this->getLastURLPart($existingPageURLSpaces);
            $existingPageURLSpaces = null;

            // the minimum distance is the minimum of the two possibilities. one is longer anyway, so 
            // it shouldn't matter.
            $minDist = abs($f->strlen($existingPageURLCleaned) - $requestedURLCleanedLength);
            if ($fullURLspaces != '') {
                $minDist = min($minDist, abs($f->strlen($fullURLspacesLength) - $requestedURLCleanedLength));
            }
            $maxDist = $f->strlen($existingPageURLCleaned);
            if ($fullURLspaces != '') {
                $maxDist = min($maxDist, $fullURLspacesLength);
            }
            
            // add the ID to the list.
            if (is_array($minDistances[$minDist])) {
                array_push($minDistances[$minDist], $id);
            }
            if (is_array($maxDistances[$maxDist])) {
                array_push($maxDistances[$maxDist], $id);
            }
            
            $row = array_shift($publishedPages);
        }
        
        if ($wasntReadyCount > 0) {
            $abj404logging->infoMessage("The permalink cache wasn't ready for " . $wasntReadyCount . " IDs.");
        }

        // look at the first X IDs with the lowest maximum levenshtein distance.
        /* 2) Get the first X strings from the max-distance list. The X is the number we have to display in the 
         * list of suggestions on the 404 page. Note the highest max distance of the strings we're using here. */
        $pagesSeenSoFar = 0;
        $currentDistanceIndex = 0;
        $maxDistFound = 300;
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
        $listOfIDsToReturn = array();
        for ($currentDistanceIndex = 0; $currentDistanceIndex <= $maxDistFound; $currentDistanceIndex++) {
            $listOfMinDistanceIDs = $minDistances[$currentDistanceIndex];
            foreach ($listOfMinDistanceIDs as $oneID) {
                $listOfIDsToReturn[] = $oneID;
            }
        }

        return $listOfIDsToReturn;
    }
    
    /** Turns "/abc/defg" into "defg"
     * @param type $url
     * @return type
     */
    function getLastURLPart($url) {
        $f = new ABJ_404_Solution_Functions();
        $newURL = $url;
        
        if (strrpos($url, "/")) {
            $newURL = $f->substr($url, strrpos($url, "/") + 1);
        }
        
        return $newURL;
    }

    /** 
     * @param type $str
     * @return type
     */
    private function multiByteStringToArray($str) {
        $f = new ABJ_404_Solution_Functions();
        $length = $f->strlen($str);
        $array = array();
        for ($i = 0; $i < $length; $i++) {
            $array[$i] = $f->substr($str, $i, 1);
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

ABJ_404_Solution_SpellChecker::init();

