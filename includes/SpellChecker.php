<?php

/* Finds similar pages. 
 * Finds search suggestions. */

class ABJ_404_Solution_SpellChecker {
    
	private $separatingCharacters = array("-","_",".","~",'%20');

    /** Same as above except without the period (.) because of the extension in the file name. */
	private $separatingCharactersForImages = array("-","_","~",'%20');
    
	private $publishedPostsProvider = null;
    
	const MAX_DIST = 2083;

	private static $instance = null;
	
	private $custom404PageID = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_SpellChecker();

			// set the custom 404 page id if there is one
			$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
			$options = $abj404logic->getOptions();
			$me = self::$instance;
			$custom404PageID =
				(array_key_exists('dest404page', $options) && isset($options['dest404page']) ?
				$options['dest404page'] : null);
			if ($abj404logic->thereIsAUserSpecified404Page($custom404PageID)) {
				$me->custom404PageID = $custom404PageID;
			}
		}
		
		return self::$instance;
	}
	
	static function init() {
		// any time a page is saved or updated, or the permalink structure changes, then we have to clear
		// the spelling cache because the results may have changed.
		$me = ABJ_404_Solution_SpellChecker::getInstance();

		add_action('updated_option', array($me,'permalinkStructureChanged'), 10, 2);
		add_action('save_post', array($me,'save_postListener'), 10, 3);
		add_action('delete_post', array($me,'delete_postListener'), 10, 2);
	}

	function save_postListener($post_id, $post = null, $update = null) {
		if ($post == null) {
			$post = get_post($post_id);
		}
		if ($update == null) {
			$update = true;
		}
		
		$this->savePostHandler($post_id, $post, $update, 'save');
    }
    function delete_postListener($post_id, $post = null) {
    	if ($post == null) {
    		$post = get_post($post_id);
    	}
    	
        $this->savePostHandler($post_id, $post, true, 'delete');
    }

	function savePostHandler($post_id, $post, $update, $saveOrDelete) {
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
		$abj404logging = ABJ_404_Solution_Logging::getInstance();
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$f = ABJ_404_Solution_Functions::getInstance();
		$options = $abj404logic->getOptions();
		$postType = $post->post_type;

		$acceptedPostTypes = $f->explodeNewline($options['recognized_post_types']);

		// 3 options: save a new page, save an existing page (update), delete a page.
		$deleteSpellingCache = false;
		$deleteFromPermalinkCache = false;
		$reason = '';

		// 2: save an existing page. if any of the following changed then delete
		// from the permalink cache: slug, type, status.
		// if any of the following changed then delete the entire spelling cache: 
		// slug, type, status.
		$cacheRow = $abj404dao->getPermalinkEtcFromCache($post_id);
		$cacheRow = (isset($cacheRow)) ? $cacheRow : array();
		$oldSlug = (array_key_exists('url', $cacheRow)) ? 
			rtrim(ltrim($cacheRow['url'], '/'), '/') : '(not found)';
		$newSlug = $post->post_name;
		$matches = array();
		$metaRow = array_key_exists('meta', $cacheRow) ? $cacheRow['meta'] : '';
		preg_match('/s:(\\w+?),/', $metaRow, $matches);
		$oldStatus = count($matches) > 1 ? $matches[1] : '(not found)';
		preg_match('/t:(\\w+?),/', $metaRow, $matches);
		$oldPostType = count($matches) > 1 ? $matches[1] : '(not found)';
		if ($update && $saveOrDelete == 'save' && 
				($oldSlug != $newSlug ||
				$oldStatus != $post->post_status ||
				$oldPostType != $post->post_type)
			) {
			$deleteSpellingCache = true; // TODO only delete where the page is referenced.
			$deleteFromPermalinkCache = true;
			$reason = 'change. slug (' . $oldSlug . '(to)' . $newSlug . '), status (' . 
				$oldStatus . '(to)' . $post->post_status . '), type (' . $oldPostType . 
				'(to)' . $post->post_type . ')';
		}

		// if the post type is uninteresting then ignore it.
		if (!in_array($oldPostType, $acceptedPostTypes) &&
			!in_array($post->post_type, $acceptedPostTypes)) {
	
			$httpUserAgent = "(none)";
			if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
				$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
			}
			$abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Ignored savePost change (uninteresting post types). " . 
				"Action: " . $saveOrDelete . ", ID: " . $post_id . ", types: " . 
				$oldPostType . "/" . $post->post_type . ", agent: " . 
					$httpUserAgent);
			return;
		}
		
		// if the status is uninteresting then ignore it.
		$interestingStatuses = array('publish', 'published');
		if (!in_array($oldStatus, $interestingStatuses) &&
			!in_array($post->post_status, $interestingStatuses)) {
				
			$httpUserAgent = "(none)";
			if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
				$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
			}
			$abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Ignored savePost change (uninteresting post statuses). " .
				"Action: " . $saveOrDelete . ", ID: " . $post_id . ", statuses: " .
				$oldStatus . "/" . $post->post_status . ", agent: " .
				$httpUserAgent);
			return;
		}

		// save a new page. the cache is null. delete the spelling cache because
		// the new page may match searches better than the other previous matches.
		if (!$update && $saveOrDelete == 'save') {
			$deleteSpellingCache = true; // delete all.
			$deleteFromPermalinkCache = false; // it's not there anyway.
			$reason = 'new page';
		}

		// delete a page. 
		if ($saveOrDelete == 'delete') {
			$deleteSpellingCache = true; // TODO only delete where the page is referenced.
			$deleteFromPermalinkCache = true;
			$reason = 'deleted page';
		}

		if ($deleteFromPermalinkCache) {
			$abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Delete from permalink cache: " . $post_id . ", action: " . 
				$saveOrDelete . ", reason: " . $reason);
			$abj404dao->removeFromPermalinkCache($post_id);
			// let's update some links.
			$plCache = ABJ_404_Solution_PermalinkCache::getInstance();
			$plCache->updatePermalinkCache(0.1);
		}

		if ($deleteSpellingCache) {
			// TODO only delete the items from the cache that refer
			// to the post ID that was deleted?
			$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
			$abj404dao->deleteSpellingCache();

			if ($abj404logging->isDebug()) {
				$httpUserAgent = "(none)";
				if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
					$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
				}
				
				$abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Spelling cache deleted (post change). Action: " . $saveOrDelete .
					", ID: " . $post_id . ", type: " . $postType . ", reason: " . 
					$reason . ", agent: " . $httpUserAgent);
			}
		}
	}

	function permalinkStructureChanged($var1, $newStructure) {
		if ($var1 != 'permalink_structure') {
			return;
		}

		$structure = empty($newStructure) ? '(empty)' : $newStructure;
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
		$abj404logging = ABJ_404_Solution_Logging::getInstance();
		$abj404dao->deleteSpellingCache();
		$abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Spelling cache deleted because the permalink structure changed " . "to " . $structure);
	}

    /** Find a match using the user-defined regex patterns.
	 * @global type $abj404dao
	 * @param string $requestedURL
	 * @return array
	 */
	function getPermalinkUsingRegEx($requestedURL) {
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
		$f = ABJ_404_Solution_Functions::getInstance();
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$options = $abj404logic->getOptions();

		$regexURLsRows = $abj404dao->getRedirectsWithRegEx();

		foreach ($regexURLsRows as $row) {
			$regexURL = $row['url'];

            $_REQUEST[ABJ404_PP]['debug_info'] = 'Applying custom regex "' . $regexURL . '" to URL: ' . 
                    $requestedURL;
			$preparedURL = $f->str_replace('/', '\/', $regexURL);
			if ($f->regexMatch($preparedURL, $requestedURL)) {
				$_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after regex.';
				$idAndType = $row['final_dest'] . '|' . $row['type'];
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, '0', 
                	null, $options);
				$permalink['matching_regex'] = $regexURL;
				$originalPermalink = $permalink;

				// if the matching regex contains a group and the destination contains a replacement,
				// then use them
				$regexMatchResult = $f->regexMatch("\.*\(.+\).*", $regexURL);
				$replacementStrPosResult = $f->strpos($permalink['link'], '$');
				if (($regexMatchResult != 0) && ($replacementStrPosResult !== FALSE)) {
					$results = array();
					$f->regexMatch($regexURL, $requestedURL, $results);

					// do a repacement for all of the groups found.
					$final = $permalink['link'];
					for ($x = 1; $x < count($results); $x++) {
						$final = $f->str_replace('$' . $x, $results[$x], $final);
					}

					$permalink['link'] = $final;
				}
				
				$abj404logging = ABJ_404_Solution_Logging::getInstance();
				$abj404logging->debugMessage("Found matching regex. Original permalink" . 
				    json_encode($originalPermalink) . ", final: " . 
				    json_encode($permalink));

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
	 * @param string $requestedURL
	 * @return array|null
	 */
	function getPermalinkUsingSlug($requestedURL) {
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
		$abj404logging = ABJ_404_Solution_Logging::getInstance();

		$exploded = array_filter(explode('/', $requestedURL));
		if ($exploded == null || empty($exploded)) {
			return null;
		}
		$postSlug = end($exploded);
		$postsBySlugRows = $abj404dao->getPublishedPagesAndPostsIDs($postSlug);
		if (count($postsBySlugRows) == 1) {
			$post = reset($postsBySlugRows);
			$permalink = array();
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
	 * @param string $requestedURL
	 * @return array|null
	 */
	function getPermalinkUsingSpelling($requestedURL) {
		$abj404spellChecker = ABJ_404_Solution_SpellChecker::getInstance();
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$abj404logging = ABJ_404_Solution_Logging::getInstance();

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
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, $linkScore, 
            	$rowType, $options);

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
	 * @param string $requestedURL
	 */
	function requestIsForAnImage($requestedURL) {
		$f = ABJ_404_Solution_Functions::getInstance();
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
	 * @param string $requestedURLRaw
	 * @param string $includeCats
	 * @param string $includeTags
	 * @return array
	 */
	function findMatchingPosts($requestedURLRaw, $includeCats = '1', $includeTags = '1') {
		$f = ABJ_404_Solution_Functions::getInstance();
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

		$options = $abj404logic->getOptions();
		// the number of pages to cache is (max suggestions) + (the number of exlude pages).
		// (if either of these numbers increases then we need to clear the spelling cache.)
		$excluePagesCount = 0;
		if (!trim($options['excludePages[]']) == '') {
			$jsonResult = json_decode($options['excludePages[]']);
			if (!is_array($jsonResult)) {
				$jsonResult = array($jsonResult);
			}
			$excluePagesCount = count($jsonResult);
		}
		$maxCacheCount = absint($options['suggest_max']) + $excluePagesCount;

		$requestedURLSpaces = $f->str_replace($this->separatingCharacters, " ", $requestedURLRaw);
		$requestedURLCleaned = $this->getLastURLPart($requestedURLSpaces);
		$fullURLspacesCleaned = $f->str_replace('/', " ", $requestedURLSpaces);
		// if there is no extra stuff in the path then we ignore this to save time.
		if ($fullURLspacesCleaned == $requestedURLCleaned) {
			$fullURLspacesCleaned = '';
		}

		// prepare to get some posts.
		$this->initializePublishedPostsProvider();

		$rowType = 'pages';
		$permalinks = array();
		// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - match on posts
        $permalinks = $this->matchOnPosts($permalinks, $requestedURLRaw, $requestedURLCleaned, 
                $fullURLspacesCleaned, $rowType);

		// if we only need images then we're done.
		if ($rowType == 'image') {
			// This is sorted so that the link with the highest score will be first when iterating through.
			arsort($permalinks);
			$anArray = array($permalinks,$rowType);
			return $anArray;
		}

		// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - match on tags
		// search for a similar tag.
		if ($includeTags == "1") {
			$permalinks = $this->matchOnTags($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, 'tags');
		}

		// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - match on categories
		// search for a similar category.
		if ($includeCats == "1") {
			$permalinks = $this->matchOnCats($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, 'categories');
		}

		// remove excluded pages
		$permalinks = $this->removeExcludedPages($options, $permalinks);

		// This is sorted so that the link with the highest score will be first when iterating through.
		arsort($permalinks);

		// only keep what we need. store them for later if necessary.
		$permalinks = array_splice($permalinks, 0, $maxCacheCount);

		$returnValue = array($permalinks,$rowType);
		$abj404dao->storeSpellingPermalinksToCache($requestedURLRaw, $returnValue);
		$_REQUEST[ABJ404_PP]['permalinks_found'] = json_encode($returnValue);
		$_REQUEST[ABJ404_PP]['permalinks_kept'] = json_encode($permalinks);

		return $returnValue;
	}

	function removeExcludedPages($options, $permalinks) {
		$excludePagesJson = $options['excludePages[]'];
		if (trim($excludePagesJson) == '' && $this->custom404PageID == null) {
			return $permalinks;
		}

		// look at every ID to exclude.
		$excludePages = json_decode($excludePagesJson);
		if (!is_array($excludePages)) {
			$excludePages = array($excludePages);
		}
		
		// don't include the user specified 404 page in the spelling results..
		if ($this->custom404PageID != null) {
			array_push($excludePages, $this->custom404PageID);
		}
		
		for ($i = 0; $i < count($excludePages); $i++) {
			$excludePage = $excludePages[$i];
			if ($excludePage == null || trim($excludePage) == '') {
				continue;
			}
			$items = explode("|\\|", $excludePage);
			$idAndTypeToExclude = $items[0];

			// remove it from the results list.
			unset($permalinks[$idAndTypeToExclude]);
		}

		return $permalinks;
	}

	function getOnlyIDandTermID($rowsAsObject) {
		$rows = array();
		$objectRow = array_pop($rowsAsObject);
		while ($objectRow != null) {
            $rows[] = array(
                'id' => property_exists($objectRow, 'id') == true ? $objectRow->id : null,
                'term_id' => property_exists($objectRow, 'term_id') == true ? $objectRow->term_id : null,
            	'url' => property_exists($objectRow, 'url') == true ? $objectRow->url : null
                );
            $objectRow = array_pop($rowsAsObject);
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
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
		$returnValue = $abj404dao->getSpellingPermalinksFromCache($requestedURL);
		if (!empty($returnValue)) {
			return $returnValue;
		}

		return array();
	}

	function matchOnCats($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rowType) {
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$f = ABJ_404_Solution_Functions::getInstance();

		$rows = $abj404dao->getPublishedCategories();
		$rows = $this->getOnlyIDandTermID($rows);

		// pre-filter some pages based on the min and max possible levenshtein distances.
		$likelyMatchIDsAndPermalinks = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, 'categories', $rows);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

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
				$pathOnlySpaces = $f->str_replace($this->separatingCharacters, " ", $pathOnly);
				$pathOnlySpaces = trim($f->str_replace('/', " ", $pathOnlySpaces));
				$levscore = min($levscore, $this->customLevenshtein($fullURLspacesCleaned, $pathOnlySpaces));
			}

			$onlyLastPart = $this->getLastURLPart($pathOnly);
			if ($onlyLastPart != '' && $onlyLastPart != $pathOnly) {
				$levscore = min($levscore, $this->customLevenshtein($requestedURLCleaned, $onlyLastPart));
			}

			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_CAT] = number_format($score, 4, '.', '');
		}

		return $permalinks;
	}

	function matchOnTags($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rowType) {
		$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$f = ABJ_404_Solution_Functions::getInstance();

		$rows = $abj404dao->getPublishedTags();
		$rows = $this->getOnlyIDandTermID($rows);

		// pre-filter some pages based on the min and max possible levenshtein distances.
		$likelyMatchIDsAndPermalinks = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, 'tags', $rows);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

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
				$pathOnlySpaces = $f->str_replace($this->separatingCharacters, " ", $pathOnly);
				$pathOnlySpaces = trim($f->str_replace('/', " ", $pathOnlySpaces));
				$levscore = min($levscore, $this->customLevenshtein($fullURLspacesCleaned, $pathOnlySpaces));
			}
			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_TAG] = number_format($score, 4, '.', '');
		}

		return $permalinks;
	}

	function matchOnPosts($permalinks, $requestedURLRaw, $requestedURLCleaned, $fullURLspacesCleaned, $rowType) {
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$f = ABJ_404_Solution_Functions::getInstance();
		$abj404logger = ABJ_404_Solution_Logging::getInstance();
	
		// pre-filter some pages based on the min and max possible levenshtein distances.
		$likelyMatchIDsAndPermalinks = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rowType);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);
	
		$abj404logger->debugMessage("Found " . count($likelyMatchIDs) . " likely match IDs.");
	
		// access the array directly instead of using a foreach loop so we can remove items
		// from the end of the array in the middle of the loop.
		while (count($likelyMatchIDs) > 0) {
			$id = array_pop($likelyMatchIDs);
	
			// use the levenshtein distance formula here.
			$the_permalink = $likelyMatchIDsAndPermalinks[$id];
			$urlParts = parse_url($the_permalink);
			$existingPageURL = $abj404logic->removeHomeDirectory($urlParts['path']);
			$existingPageURLSpaces = $f->str_replace($this->separatingCharacters, " ", $existingPageURL);
	
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
				$strippedImageName = $f->regexReplace('(.+)([-]\d{1,5}[x]\d{1,5})([.].+)', 
						'\\1\\3', $requestedURLRaw);
	
				if (($strippedImageName != null) && ($strippedImageName != $requestedURLRaw)) {
					$strippedImageName = $f->str_replace($this->separatingCharactersForImages, " ", $strippedImageName);
					$levscore = min($levscore, $this->customLevenshtein($strippedImageName, $existingPageURL));
	
					$strippedImageName = $this->getLastURLPart($strippedImageName);
					$levscore = min($levscore, $this->customLevenshtein($strippedImageName, $existingPageURLCleaned));
				}
			}
			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_POST] = number_format($score, 4, '.', '');
		}
	
		return $permalinks;
	}

	function initializePublishedPostsProvider() {
		if ($this->publishedPostsProvider == null) {
			$this->publishedPostsProvider = ABJ_404_Solution_PublishedPostsProvider::getInstance();
		}
		$plCache = ABJ_404_Solution_PermalinkCache::getInstance();
		$plCache->updatePermalinkCache(1);
	}

	/**
	 * Get the permalink for the passed in type (pages, tags, categories, image, etc.
	 * @param int $id
	 * @param string $rowType
	 * @return string
	 * @throws Exception
	 */
	function getPermalink($id, $rowType) {
		if ($rowType == 'pages') {
			$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
			$link = $abj404dao->getPermalinkFromCache($id);

			if ($link == null || trim($link) == '') {
				$link = get_the_permalink($id);
			}
			return urldecode($link);

		} else if ($rowType == 'tags') {
			return urldecode(get_tag_link($id));

		} else if ($rowType == 'categories') {
			return urldecode(get_category_link($id));

		} else if ($rowType == 'image') {
			$src = wp_get_attachment_image_src($id, "attached-image");
			if ($src == false || !is_array($src)) {
				return null;
			}
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
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspaces
	 * @param array $publishedPages
	 * @param string $rowType
	 * @return array
	 */
	function getLikelyMatchIDs($requestedURLCleaned, $fullURLspaces, $rowType, $rows = null) {
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$abj404logging = ABJ_404_Solution_Logging::getInstance();
		$f = ABJ_404_Solution_Functions::getInstance();
		
		$options = $abj404logic->getOptions();
		// we get more than we need because the algorithm we actually use
		// is not based solely on the Levenshtein distance.
		$onlyNeedThisManyPages = min(5 * absint($options['suggest_max']), 100);

		// create a list sorted by min levenshstein distance and max levelshtein distance.
        /* 1) Get a list of minumum and maximum levenshtein distances - two lists, one ordered by the min 
         * distance and one ordered by the max distance. */
		$minDistances = array();
		$maxDistances = array();
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= self::MAX_DIST; $currentDistanceIndex++) {
			$maxDistances[$currentDistanceIndex] = array();
			$minDistances[$currentDistanceIndex] = array();
		}

		$requestedURLCleanedLength = $f->strlen($requestedURLCleaned);
		$fullURLspacesLength = $f->strlen($fullURLspaces);

		$userRequestedURLWords = explode(" ", (empty($fullURLspaces) ? $requestedURLCleaned : $fullURLspaces));
		$idsWithWordsInCommon = array();
		$wasntReadyCount = 0;
		$idToPermalink = array();

		// get the next X pages in batches until enough matches are found.
		$this->publishedPostsProvider->resetBatch();
		if ($rows != null) {
			$this->publishedPostsProvider->useThisData($rows);
		}
		$currentBatch = $this->publishedPostsProvider->getNextBatch($requestedURLCleanedLength);

		$row = array_pop($currentBatch);
		while ($row != null) {
			$row = (array)$row;

			$id = null;
			$the_permalink = null;
			$urlParts = null;
			if ($rowType == 'pages') {
				$id = $row['id'];
            	
			} else if ($rowType == 'tags') {
				$id = array_key_exists('term_id', $row) ? $row['term_id'] : null;
            	
			} else if ($rowType == 'categories') {
				$id = array_key_exists('term_id', $row) ? $row['term_id'] : null;
            	
			} else if ($rowType == 'image') {
				$id = $row['id'];
            	
			} else {
				throw Exception("Unknown row type ... " . $rowType);
			}

			if (array_key_exists('url', $row)) {
			    $the_permalink = isset($row['url']) ? $row['url'] : '';
			    $the_permalink = urldecode($the_permalink);
			    $urlParts = parse_url($the_permalink);
			    
			    if (is_bool($urlParts)) {
			        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
			        $abj404dao->removeFromPermalinkCache($id);
			    }
			}
			if (!array_key_exists('url', $row) || (isset($urlParts) && is_bool($urlParts))) {
			    $wasntReadyCount++;
			    $the_permalink = $this->getPermalink($id, $rowType);
			    $the_permalink = urldecode($the_permalink);
			    $urlParts = parse_url($the_permalink);
			}
			
			$_REQUEST[ABJ404_PP]['debug_info'] = 'Likely match IDs processing permalink: ' . 
				$the_permalink . ', $wasntReadyCount: ' . $wasntReadyCount;
			$idToPermalink[$id] = $the_permalink;

			if (!array_key_exists('path', $urlParts)) {
				continue;
			}
			$existingPageURL = $abj404logic->removeHomeDirectory($urlParts['path']);
			$urlParts = null;

			// this line used to take too long to execute.
			$existingPageURLSpaces = $f->str_replace($this->separatingCharacters, " ", $existingPageURL);

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

			// -----------------
			// split the links into words.
			$existingPageURLCleanedWords = explode(" ", $existingPageURLCleaned);
			$wordsInCommon = array_intersect($userRequestedURLWords, $existingPageURLCleanedWords);
			$wordsInCommon = array_merge(array_unique($wordsInCommon, SORT_REGULAR), array());
			if (count($wordsInCommon) > 0) {
				// if any words match then save the link to the $idsWithWordsInCommon list.
				array_push($idsWithWordsInCommon, $id);
				// also lower the $maxDist accordingly.
				$lengthOfTheLongestWordInCommon = max(array_map(array($f,'strlen'), $wordsInCommon));
				$maxDist = $maxDist - $lengthOfTheLongestWordInCommon;
			}
			// -----------------

			// add the ID to the list.
			if (isset($minDistances[$minDist]) && is_array($minDistances[$minDist])) {
			    array_push($minDistances[$minDist], $id);
			} else {
			    $minDistances[$minDist] = [$id];
			}
			
			if ($maxDist < 0) {
            	$abj404logging->errorMessage("maxDist is less than 0 (" . $maxDist . 
            			") for '" . $existingPageURLCleaned . "', wordsInCommon: " .
            			json_encode($wordsInCommon) . ", ");
            	
			} else if ($maxDist > self::MAX_DIST) {
				$maxDist = self::MAX_DIST;
			}

			if (is_array($maxDistances[$maxDist])) {
				array_push($maxDistances[$maxDist], $id);
			}

			// get the next row in the current batch.
			$row = array_pop($currentBatch);
			if ($row == null) {
				// get the best maxDistance pages and then trim the next batch using that info.
				$maxAcceptableDistance = $this->getMaxAcceptableDistance($maxDistances, $onlyNeedThisManyPages);

				// get the next batch if there are no more rows in the current batch.
            	$currentBatch = $this->publishedPostsProvider->getNextBatch(
            		$requestedURLCleanedLength, 1000, $maxAcceptableDistance);
				$row = array_pop($currentBatch);
			}
		}
		$_REQUEST[ABJ404_PP]['debug_info'] = '';
			
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
			$listOfIDsToReturn = array_merge($listOfIDsToReturn, $listOfMinDistanceIDs);
		}

		// if there are more than X IDs to return, then only use the matches where words match.
		if (count($listOfIDsToReturn) > 300 && count($idsWithWordsInCommon) >= $onlyNeedThisManyPages) {
			$maybeOKguesses = array_intersect($listOfIDsToReturn, $idsWithWordsInCommon);

			if (count($maybeOKguesses) >= $onlyNeedThisManyPages) {
				return $maybeOKguesses;
			}
			return $idsWithWordsInCommon;
		}

		$result = array();
		foreach ($listOfIDsToReturn as $id) {
			if (isset($idToPermalink[$id])) {
				$result[$id] = $idToPermalink[$id];
			}
		}
		return $result;
	}

	/**
	 * @param array $maxDistances
	 * @param int $onlyNeedThisManyPages
	 * @return int the maximum acceptable distance to use when searching for similar permalinks.
	 */
	function getMaxAcceptableDistance($maxDistances, $onlyNeedThisManyPages) {
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

		// we multiply by X because the distance algorithm doesn't only use the levenshtein.
		$acceptableDistance = (int)($maxDistFound * 1.1);
		return $acceptableDistance;
	}

    /** Turns "/abc/defg" into "defg"
	 * @param string $url
	 * @return string
	 */
	function getLastURLPart($url) {
		$parts = explode("/", $url);
		for ($i = count($parts) - 1; $i >= 0; $i--) {
			$lastPart = $parts[$i];
			if (trim($lastPart) != "") {
				break;
			}
		}

		if (trim($lastPart) == "") {
			return $url;
		}

		return $lastPart;
	}

	/**
	 * @param string $str
	 * @return array
	 */
	private function multiByteStringToArray($str) {
		$f = ABJ_404_Solution_Functions::getInstance();
		$length = $f->strlen($str);
		$array = array();
		for ($i = 0; $i < $length; $i++) {
			$array[$i] = $f->substr($str, $i, 1);
		}
		return $array;
	}

    /** This custom levenshtein function has no 255 character limit.
	 * From https://www.codeproject.com/Articles/13525/Fast-memory-efficient-Levenshtein-algorithm
	 * @param string $str1
	 * @param string $str2
	 * @return int
	 * @throws Exception
	 */
	function customLevenshtein($str1, $str2) {
	    $f = ABJ_404_Solution_Functions::getInstance();
	    $_REQUEST[ABJ404_PP]['debug_info'] = 'customLevenshtein. str1: ' . esc_html($str1) . ', str2: ' . esc_html($str2);

	    $RowLen = $f->strlen($str1);
	    $ColLen = $f->strlen($str2);
		$cost = 0;

		// / Test string length. URLs should not be more than 2,083 characters
		if (max($RowLen, $ColLen) > ABJ404_MAX_URL_LENGTH) {
            throw new Exception("Maximum string length in customLevenshtein is " . 
            	ABJ404_MAX_URL_LENGTH . ". Yours is " . max($RowLen, $ColLen) . ".");
		}

		// Step 1
		if ($RowLen == 0) {
			return $ColLen;
		} else if ($ColLen == 0) {
			return $RowLen;
		}

		// / Create the two vectors
		$v0 = array_fill(0, $RowLen + 1, 0);
		$v1 = array_fill(0, $RowLen + 1, 0);

		// / Step 2
		// / Initialize the first vector
		for ($RowIdx = 1; $RowIdx <= $RowLen; $RowIdx++) {
			$v0[$RowIdx] = $RowIdx;
		}

		// Step 3
		// / For each column
		for ($ColIdx = 1; $ColIdx <= $ColLen; $ColIdx++) {
			// / Set the 0'th element to the column number
			$v1[0] = $ColIdx;

			// Step 4
			// / For each row
			for ($RowIdx = 1; $RowIdx <= $RowLen; $RowIdx++) {
			    $cost = ($str1[$RowIdx - 1] == $str2[$ColIdx - 1]) ? 0 : 1;
			    $v1[$RowIdx] = min($v0[$RowIdx] + 1, $v1[$RowIdx - 1] + 1, $v0[$RowIdx - 1] + $cost);
			}

			// / Swap the vectors
			$vTmp = $v0;
			$v0 = $v1;
			$v1 = $vTmp;
		}

		$_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after customLevenshtein.';
		return $v0[$RowLen];
	}

}
