<?php

/* Finds similar pages. 
 * Finds search suggestions. */

class ABJ_404_Solution_SpellChecker {

	private $separatingCharacters = array("-","_",".","~",'%20');

    /** Same as above except without the period (.) because of the extension in the file name. */
	private $separatingCharactersForImages = array("-","_","~",'%20');

	private $publishedPostsProvider = null;

	const MAX_DIST = 2083;

	/** Similarity threshold for N-gram prefiltering (lower = more candidates, slower but safer). */
	const NGRAM_PREFILTER_THRESHOLD = 0.3;

	/** Maximum candidates to retrieve during N-gram prefiltering. */
	const NGRAM_PREFILTER_MAX_CANDIDATES = 500;

	/** Minimum N-gram cache entries required to enable prefiltering.
	 * Small sites don't need prefiltering; this also prevents use during partial cache builds. */
	const NGRAM_MIN_CACHE_ENTRIES = 50;

	/** Similarity threshold for secondary N-gram filtering (higher = stricter, fewer candidates).
	 * More conservative than prefilter since we're refining an already-filtered list. */
	const NGRAM_SECONDARY_THRESHOLD = 0.4;

	/** Maximum candidates for secondary N-gram filtering. */
	const NGRAM_SECONDARY_MAX_CANDIDATES = 100;

	/** Minimum cache coverage ratio (ngram entries / permalink entries) to trust prefiltering.
	 * 0.8 = require at least 80% of permalink cache entries to be in N-gram cache. */
	const NGRAM_MIN_COVERAGE_RATIO = 0.8;

	/** Minimum candidate count to trigger secondary N-gram filtering.
	 * Below this threshold, Levenshtein on all candidates is fast enough. */
	const NGRAM_SECONDARY_MIN_CANDIDATES = 50;

	private static $instance = null;

	// Performance counters (for testing efficiency - disabled by default)
	private $enablePerformanceCounters = false;
	private $levenshteinCallCount = 0;
	private $totalPagesConsidered = 0;

	private $custom404PageID = null;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_PermalinkCache */
	private $permalinkCache;

	/** @var ABJ_404_Solution_NGramFilter */
	private $ngramFilter;

	/**
	 * Constructor with dependency injection.
	 * Dependencies are now explicit and visible.
	 *
	 * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
	 * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 * @param ABJ_404_Solution_PermalinkCache|null $permalinkCache Permalink caching service
	 * @param ABJ_404_Solution_NGramFilter|null $ngramFilter N-gram filter for optimization
	 */
	public function __construct($functions = null, $pluginLogic = null, $dataAccess = null, $logging = null, $permalinkCache = null, $ngramFilter = null) {
		// Use injected dependencies or fall back to getInstance() for backward compatibility
		$this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
		$this->logic = $pluginLogic !== null ? $pluginLogic : ABJ_404_Solution_PluginLogic::getInstance();
		$this->dao = $dataAccess !== null ? $dataAccess : ABJ_404_Solution_DataAccess::getInstance();
		$this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
		$this->permalinkCache = $permalinkCache !== null ? $permalinkCache : ABJ_404_Solution_PermalinkCache::getInstance();
		$this->ngramFilter = $ngramFilter !== null ? $ngramFilter : ABJ_404_Solution_NGramFilter::getInstance();

		// Set the custom 404 page id if there is one
		$options = $this->logic->getOptions();
		$custom404PageID =
			(is_array($options) && isset($options['dest404page']) ?
			$options['dest404page'] : null);
		if ($this->logic->thereIsAUserSpecified404Page($custom404PageID)) {
			$this->custom404PageID = $custom404PageID;
		}
	}

	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_SpellChecker();
		}

		return self::$instance;
	}

	/**
	 * Enable performance counters for testing efficiency (disabled by default for production)
	 */
	public function enablePerformanceCounters($enable = true) {
		$this->enablePerformanceCounters = $enable;
		if ($enable) {
			$this->resetPerformanceCounters();
		}
	}

	/**
	 * Reset performance counters to zero
	 */
	public function resetPerformanceCounters() {
		$this->levenshteinCallCount = 0;
		$this->totalPagesConsidered = 0;
	}

	/**
	 * Get current performance counter values
	 * @return array ['levenshtein_calls' => int, 'pages_considered' => int, 'efficiency_percent' => float]
	 */
	public function getPerformanceCounters() {
		$efficiency = 0;
		if ($this->totalPagesConsidered > 0) {
			$efficiency = ($this->levenshteinCallCount / $this->totalPagesConsidered) * 100;
		}

		return [
			'levenshtein_calls' => $this->levenshteinCallCount,
			'pages_considered' => $this->totalPagesConsidered,
			'efficiency_percent' => round($efficiency, 2)
		];
	}

	/**
	 * Find URL suggestions using smart caching (N-gram filtering).
	 * This is a wrapper around findMatchingPosts() primarily for testing.
	 *
	 * @param string $requestedURL The 404 URL to find matches for
	 * @param string $includeCats Whether to include categories (default '1')
	 * @param bool $includeTags Whether to include tags (default true, converted to '1')
	 * @return array Array of matching posts/pages
	 */
	public function findSuggestionsForURLUsingSmartCache($requestedURL, $includeCats = '1', $includeTags = true) {
		// Convert boolean to string for backward compatibility
		$includeTagsStr = $includeTags ? '1' : '0';
		return $this->findMatchingPosts($requestedURL, $includeCats, $includeTagsStr);
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
		$options = $this->logic->getOptions();
		$postType = $post->post_type;

		$acceptedPostTypes = $this->f->explodeNewline($options['recognized_post_types']);

		// 3 options: save a new page, save an existing page (update), delete a page.
		$deleteSpellingCache = false;
		$deleteFromPermalinkCache = false;
		$invalidateNGramCache = false;
		$reason = '';

		// 2: save an existing page. if any of the following changed then delete
		// from the permalink cache: slug, type, status.
		// if any of the following changed then delete the entire spelling cache:
		// slug, type, status.
		$cacheRow = $this->dao->getPermalinkEtcFromCache($post_id);
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
			$invalidateNGramCache = true;
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
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
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
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
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
			$invalidateNGramCache = false; // it's not there anyway.
			$reason = 'new page';
		}

		// delete a page.
		if ($saveOrDelete == 'delete') {
			$deleteSpellingCache = true; // TODO only delete where the page is referenced.
			$deleteFromPermalinkCache = true;
			$invalidateNGramCache = true;
			$reason = 'deleted page';
		}

		if ($deleteFromPermalinkCache) {
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Delete from permalink cache: " . $post_id . ", action: " .
				$saveOrDelete . ", reason: " . $reason);

			try {
				$this->dao->removeFromPermalinkCache($post_id);
				// let's update some links.
				$this->permalinkCache->updatePermalinkCache(0.1);
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while updating permalink cache for post ID " . $post_id .
					": " . $e->getMessage());
			}
		}

		if ($invalidateNGramCache) {
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Invalidate N-gram cache entry: " . $post_id . ", action: " .
				$saveOrDelete . ", reason: " . $reason);

			try {
				$result = $this->ngramFilter->invalidatePage($post_id);
				if (!$result) {
					$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
						": Failed to invalidate N-gram cache for post ID " . $post_id .
						". The cache may be out of sync until next daily maintenance.");
				}
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while invalidating N-gram cache for post ID " . $post_id .
					": " . $e->getMessage());
			}
		}

		if ($deleteSpellingCache) {
			// TODO only delete the items from the cache that refer
			// to the post ID that was deleted?
			try {
				$this->dao->deleteSpellingCache();
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while deleting spelling cache: " . $e->getMessage());
			}

			if ($this->logger->isDebug()) {
				$httpUserAgent = "(none)";
				if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
					$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
				}

				$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Spelling cache deleted (post change). Action: " . $saveOrDelete .
					", ID: " . $post_id . ", type: " . $postType . ", reason: " .
					$reason . ", agent: " . $httpUserAgent);
			}
		}

		// Update N-gram cache for single post (incremental update for performance)
		// Use incremental update API to avoid rebuilding entire cache on every post save
		if ($saveOrDelete == 'save' && in_array($post->post_status, array('publish', 'published'))) {
			try {
				// Ensure permalink cache is updated first (for new posts)
				// This is lightweight and idempotent, so safe to call even if already updated
				$this->permalinkCache->updatePermalinkCache(0.1);

				// Only update N-grams for this specific post (incremental)
				$stats = $this->ngramFilter->updateNGramsForPages(array($post_id));

				if ($stats['success'] > 0) {
					$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
						": Incrementally updated N-grams for post ID: " . $post_id .
						" (processed: {$stats['processed']}, success: {$stats['success']}, failed: {$stats['failed']})");
				} else if ($stats['failed'] > 0) {
					$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
						": Failed to update N-grams for post ID: " . $post_id .
						" (stats: " . json_encode($stats) . ")");
				}
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while updating N-grams for post ID " . $post_id .
					": " . $e->getMessage());
			}
		}
	}

	function permalinkStructureChanged($var1, $newStructure) {
		if ($var1 != 'permalink_structure') {
			return;
		}

		$structure = empty($newStructure) ? '(empty)' : $newStructure;
		$this->dao->deleteSpellingCache();
		$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Spelling cache deleted because the permalink structure changed " . "to " . $structure);
	}

    /** Find a match using the user-defined regex patterns.
	 * @global type $abj404dao
	 * @param string $requestedURL
	 * @return array
	 */
	function getPermalinkUsingRegEx($requestedURL) {
		$options = $this->logic->getOptions();

		$regexURLsRows = $this->dao->getRedirectsWithRegEx();

		foreach ($regexURLsRows as $row) {
			$regexURL = $row['url'];

            $_REQUEST[ABJ404_PP]['debug_info'] = 'Applying custom regex "' . $regexURL . '" to URL: ' .
                    $requestedURL;
			$preparedURL = $this->f->str_replace('/', '\/', $regexURL);
			if ($this->f->regexMatch($preparedURL, $requestedURL)) {
				$_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after regex.';
				$idAndType = $row['final_dest'] . '|' . $row['type'];
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, '0', 
                	null, $options);
				$permalink['matching_regex'] = $regexURL;
				$originalPermalink = $permalink;

				// if the matching regex contains a group and the destination contains a replacement,
				// then use them
				$regexMatchResult = $this->f->regexMatch("\.*\(.+\).*", $regexURL);
				$replacementStrPosResult = $this->f->strpos($permalink['link'], '$');
				if (($regexMatchResult != 0) && ($replacementStrPosResult !== FALSE)) {
					$results = array();
					$this->f->regexMatch($regexURL, $requestedURL, $results);

					// do a repacement for all of the groups found.
					$final = $permalink['link'];
					for ($x = 1; $x < count($results); $x++) {
						$final = $this->f->str_replace('$' . $x, $results[$x], $final);
					}

					$permalink['link'] = $final;
				}
				
				$this->logger->debugMessage("Found matching regex. Original permalink" . 
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

		$exploded = array_filter(explode('/', $requestedURL));
		if ($exploded == null || empty($exploded)) {
			return null;
		}
		$postSlug = end($exploded);
		$postsBySlugRows = $this->dao->getPublishedPagesAndPostsIDs($postSlug);
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
            $this->logger->debugMessage("More than one post found with the slug, so no redirect was " .
                    "created. Slug: " . $postSlug);
		} else {
			$this->logger->debugMessage("No posts or pages matching slug: " . esc_html($postSlug));
		}

		return null;
	}

    /** Find a match using the an exact slug match.    
	 * Use spell checking to find the correct link. Return the permalink (map) if there is one, otherwise return null.
	 * @global type $abj404spellChecker
	 * @global type $abj404logic
	 * @param string $requestedURL The URL slug to check for spelling matches
	 * @param string|null $fullRequestedURL Optional full URL path for caching results (e.g., '/site/bad-url')
	 * @return array|null
	 */
	function getPermalinkUsingSpelling($requestedURL, $fullRequestedURL = null) {
		$abj404spellChecker = ABJ_404_Solution_SpellChecker::getInstance();

		$options = $this->logic->getOptions();

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
                    $this->logger->errorMessage("Unhandled permalink type: " .
                            wp_kses_post(json_encode($permalink)));
					return null;
				}
			}

			// No match met the auto-redirect threshold - cache results for shortcode
			// This avoids recomputing suggestions when the 404 page renders
			if ($fullRequestedURL !== null && !empty($permalinks)) {
				$this->cacheComputedSuggestionsForShortcode($fullRequestedURL, $permalinksPacket);
			}
		}

		return null;
	}

	/**
	 * Cache computed suggestions in a transient for the shortcode to use.
	 * This avoids duplicate computation when getPermalinkUsingSpelling() runs
	 * but doesn't find a match above the auto-redirect threshold.
	 *
	 * @param string $fullRequestedURL The full URL path (e.g., '/site/bad-url')
	 * @param array $permalinksPacket The computed suggestions [permalinks, rowType]
	 */
	private function cacheComputedSuggestionsForShortcode($fullRequestedURL, $permalinksPacket) {
		// Normalize URL using centralized function for consistency
		$normalizedURL = $this->f->normalizeURLForCacheKey($fullRequestedURL);

		$urlKey = md5($normalizedURL);
		$transientKey = 'abj404_suggest_' . $urlKey;

		// Don't overwrite if already set (e.g., by async trigger)
		$existing = get_transient($transientKey);
		if ($existing !== false) {
			return;
		}

		// Store as 'complete' so shortcode renders immediately
		set_transient($transientKey, array(
			'status' => 'complete',
			'suggestions' => $permalinksPacket,
			'url' => $normalizedURL,
			'completed' => time()
		), 300); // 5 minute TTL

		$this->logger->debugMessage("Cached spell-check suggestions for shortcode: " .
			esc_html($normalizedURL));
	}

	/**
	 * Return true if the last characters of the URL represent an image extension (like jpg, gif, etc).
	 * @param string $requestedURL
	 */
	function requestIsForAnImage($requestedURL) {
        $imageExtensions = array(".jpg", ".jpeg", ".gif", ".png", ".tif", ".tiff", ".bmp", ".pdf", 
            ".jif", ".jif", ".jp2", ".jpx", ".j2k", ".j2c", ".pcd");

		$returnVal = false;

		foreach ($imageExtensions as $extension) {
			if ($this->f->endsWithCaseInsensitive($requestedURL, $extension)) {
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

		$options = $this->logic->getOptions();
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

		$requestedURLSpaces = $this->f->str_replace($this->separatingCharacters, " ", $requestedURLRaw);
		$requestedURLCleaned = $this->getLastURLPart($requestedURLSpaces);
		$fullURLspacesCleaned = $this->f->str_replace('/', " ", $requestedURLSpaces);
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

		$permalinks = $this->removeExcludedPagesWithRegex($options, $permalinks, $maxCacheCount);

		// only keep what we need. store them for later if necessary.
		$permalinks = array_splice($permalinks, 0, $maxCacheCount);

		$returnValue = array($permalinks,$rowType);
		$this->dao->storeSpellingPermalinksToCache($requestedURLRaw, $returnValue);
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

	/**
     * Removes permalink suggestions if their URL path matches exclusion regex patterns.
     *
     * @param array $options    Plugin options containing 'suggest_regex_exclusions_usable'.
     * @param array $permalinks An array where keys are "ID|TYPE_CONSTANT" and values are scores.
     * Example: [ '1204|1' => '70.0000', '2194|1' => '68.3333' ]
     * @return array The filtered $permalinks array.
     */
    function removeExcludedPagesWithRegex($options, $permalinks, $maxCacheCount) {
        // Ensure permalinks is an array
        if (!is_array($permalinks)) {
            return $permalinks;
        }

        // Check if usable regex patterns exist and are in an array format
        if (!isset($options['suggest_regex_exclusions_usable']) ||
            !is_array($options['suggest_regex_exclusions_usable']) ||
            empty($options['suggest_regex_exclusions_usable'])) {
            // No patterns to apply, return original list
            return $permalinks;
        }

		$suggestionsKeptSoFar = 0;
        $regexExclusions = $options['suggest_regex_exclusions_usable'];

        // Iterate through each permalink entry using keys directly
        // Modifying array while iterating requires careful handling, using keys is safer.
        $keys_to_check = array_keys($permalinks);

        foreach ($keys_to_check as $key) {
            // Skip if the key somehow got removed in a previous iteration (shouldn't happen here)
            if (!array_key_exists($key, $permalinks)) {
                continue;
            }

            // Split the key into ID and Type Constant
            $keyParts = explode('|', $key);
            if (count($keyParts) !== 2 || !is_numeric($keyParts[0])) {
                $this->logger->debugMessage("Skipping invalid key format in removeExcludedPagesWithRegex: " . $key);
                continue; // Skip invalid keys
            }

            $id = (int)$keyParts[0];
            $typeConstant = $keyParts[1]; // Keep as string/int as needed by mapTypeConstantToString

            // Map the type constant (e.g., '1') to the string type ('pages', 'tags', etc.)
            $rowTypeString = $this->mapTypeConstantToString($typeConstant);
            if ($rowTypeString === null) {
                $this->logger->debugMessage("Skipping unknown type constant in removeExcludedPagesWithRegex: " . $typeConstant . " for key: " . $key);
                continue; // Skip unknown types
            }

            // Get the full URL using the class's method (handles cache)
            $urlOfPage = $this->getPermalink($id, $rowTypeString);
            if ($urlOfPage === null || trim($urlOfPage) === '') {
                $this->logger->debugMessage("Skipping null/empty URL for key in removeExcludedPagesWithRegex: " . $key);
                continue; // Skip if URL couldn't be retrieved
            }

            // Parse the URL and get the path, remove home directory if needed (consistency)
            $urlParts = parse_url($urlOfPage);
            if (!is_array($urlParts) || !isset($urlParts['path'])) {
                 $this->logger->debugMessage("Skipping URL that failed parse_url for key in removeExcludedPagesWithRegex: " . $key . ", URL: " . esc_url($urlOfPage));
                 continue; // Skip invalid URLs
            }
            $pathOnly = $this->logic->removeHomeDirectory($urlParts['path']);
            // Ensure path starts with / for consistency if it's not empty
             if ( $pathOnly !== '' && substr($pathOnly, 0, 1) !== '/' ) {
                $pathOnly = '/' . $pathOnly;
             }
             // Handle case where path might be empty (e.g., homepage) which results in '/'
             if ( $pathOnly === '' ) {
                 $pathOnly = '/';
             }

            $stringToMatch = $pathOnly; // The string we will match the regex against

			$kept = true;
            // Check against each exclusion pattern
            foreach ($regexExclusions as $pattern) {
                // Remove slashes like in the example provided for folders_files_ignore
                $patternToExcludeNoSlashes = stripslashes($pattern);
                $matches = array(); // Variable for the match results

                // Use the class's regexMatch function
                if ($this->f->regexMatch($patternToExcludeNoSlashes, $stringToMatch, $matches)) {
                    // Pattern matched, remove this permalink from the list
                    unset($permalinks[$key]);
                    $this->logger->debugMessage("Regex excluded suggestion. Key: " . $key .
                        ", Path: '" . esc_html($stringToMatch) . "', Pattern: '" . esc_html($patternToExcludeNoSlashes) . "'");
					$kept = false;
                    // Break the inner loop (patterns), move to the next permalink key
                    break;
                }
            }

			// track how many suggestions we actually need and stop filtering after we reach that count
			if ($kept) {
				$suggestionsKeptSoFar++;
			}
			if ($suggestionsKeptSoFar >= $maxCacheCount) {
				break;
			}
        }

        return $permalinks;
    }

    /**
     * Maps internal type constants to string identifiers used by getPermalink.
     * NOTE: Requires ABJ404_TYPE_* constants to be defined correctly.
     *
     * @param mixed $typeConstant The type constant (e.g., ABJ404_TYPE_POST).
     * @return string|null The string identifier ('pages', 'tags', 'categories') or null if not found.
     */
    private function mapTypeConstantToString($typeConstant) {
        // Define these constants if they are not globally available or use their actual values
        if (!defined('ABJ404_TYPE_POST')) define('ABJ404_TYPE_POST', '1'); // Example value
        if (!defined('ABJ404_TYPE_TAG')) define('ABJ404_TYPE_TAG', '2');   // Example value
        if (!defined('ABJ404_TYPE_CAT')) define('ABJ404_TYPE_CAT', '3');   // Example value
        // Add other types like ABJ404_TYPE_IMAGE if needed

        switch ((string)$typeConstant) { // Cast to string for reliable comparison if needed
            case ABJ404_TYPE_POST:
                return 'pages'; // Based on getPermalink implementation which uses 'pages' for posts
            case ABJ404_TYPE_TAG:
                return 'tags';
            case ABJ404_TYPE_CAT:
                return 'categories';
            // Add 'image' case if ABJ404_TYPE_IMAGE exists and is used in $permalinks keys
            // case ABJ404_TYPE_IMAGE:
            //     return 'image';
            default:
                 // Log or handle unknown type
                return null;
        }
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
		$returnValue = $this->dao->getSpellingPermalinksFromCache($requestedURL);
		if (!empty($returnValue)) {
			return $returnValue;
		}

		return array();
	}

	function matchOnCats($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rowType) {

		$rows = $this->dao->getPublishedCategories();
		$rows = $this->getOnlyIDandTermID($rows);

		// pre-filter some pages based on the min and max possible levenshtein distances.
		$likelyMatchIDsAndPermalinks = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, 'categories', $rows);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		// Early termination optimization
		$options = $this->logic->getOptions();
		$suggestMax = absint($options['suggest_max']);
		$topKScores = new SplMinHeap();
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		// access the array directly instead of using a foreach loop so we can remove items
		// from the end of the array in the middle of the loop.
		foreach ($likelyMatchIDs as $id) {
			// use the levenshtein distance formula here.
			$the_permalink = $this->getPermalink($id, 'categories');
			$urlParts = parse_url($the_permalink);
			$pathOnly = $this->logic->removeHomeDirectory($urlParts['path']);
			$scoreBasis = $this->f->strlen($pathOnly);
			if ($scoreBasis == 0) {
				continue;
			}

			// EARLY TERMINATION: Check if this candidate can possibly beat our worst current match
			if ($topKScores->count() >= $suggestMax) {
				$worstAcceptableScore = $topKScores->top();

				// OPTIMIZATION 3: Levenshtein distance threshold pruning
				$maxAllowedLevenshtein = ((100 - $worstAcceptableScore) * $scoreBasis) / 100;
				$pathOnlyLength = $this->f->strlen($pathOnly);
				$minPossibleDistance = abs($requestedURLCleanedLength - $pathOnlyLength);

				if ($minPossibleDistance > $maxAllowedLevenshtein) {
					continue; // Can't possibly beat worst score in heap
				}
			}

			$levscore = $this->customLevenshtein($requestedURLCleaned, $pathOnly);

			// OPTIMIZATION 2: Lazy evaluation of fullURLspacesCleaned
			if ($fullURLspacesCleaned != '') {
				$tentativeScore = 100 - (($levscore / $scoreBasis) * 100);
				if ($tentativeScore < 95) {
					$pathOnlySpaces = $this->f->str_replace($this->separatingCharacters, " ", $pathOnly);
					$pathOnlySpaces = trim($this->f->str_replace('/', " ", $pathOnlySpaces));
					$levscore = min($levscore, $this->customLevenshtein($fullURLspacesCleaned, $pathOnlySpaces));
				}
			}

			$onlyLastPart = $this->getLastURLPart($pathOnly);
			if ($onlyLastPart != '' && $onlyLastPart != $pathOnly) {
				$levscore = min($levscore, $this->customLevenshtein($requestedURLCleaned, $onlyLastPart));
			}

			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_CAT] = number_format($score, 4, '.', '');

			// Update top-K heap
			$topKScores->insert($score);
			if ($topKScores->count() > $suggestMax) {
				$topKScores->extract();
			}
		}

		return $permalinks;
	}

	function matchOnTags($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rowType) {

		$rows = $this->dao->getPublishedTags();
		$rows = $this->getOnlyIDandTermID($rows);

		// pre-filter some pages based on the min and max possible levenshtein distances.
		$likelyMatchIDsAndPermalinks = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, 'tags', $rows);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		// Early termination optimization
		$options = $this->logic->getOptions();
		$suggestMax = absint($options['suggest_max']);
		$topKScores = new SplMinHeap();
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		// access the array directly instead of using a foreach loop so we can remove items
		// from the end of the array in the middle of the loop.
		foreach ($likelyMatchIDs as $id) {
			// use the levenshtein distance formula here.
			$the_permalink = $this->getPermalink($id, 'tags');
			$urlParts = parse_url($the_permalink);
			$pathOnly = $this->logic->removeHomeDirectory($urlParts['path']);
			$scoreBasis = $this->f->strlen($pathOnly);
			if ($scoreBasis == 0) {
				continue;
			}

			// EARLY TERMINATION: Check if this candidate can possibly beat our worst current match
			if ($topKScores->count() >= $suggestMax) {
				$worstAcceptableScore = $topKScores->top();

				// OPTIMIZATION 3: Levenshtein distance threshold pruning
				$maxAllowedLevenshtein = ((100 - $worstAcceptableScore) * $scoreBasis) / 100;
				$pathOnlyLength = $this->f->strlen($pathOnly);
				$minPossibleDistance = abs($requestedURLCleanedLength - $pathOnlyLength);

				if ($minPossibleDistance > $maxAllowedLevenshtein) {
					continue; // Can't possibly beat worst score in heap
				}
			}

			$levscore = $this->customLevenshtein($requestedURLCleaned, $pathOnly);

			// OPTIMIZATION 2: Lazy evaluation of fullURLspacesCleaned
			if ($fullURLspacesCleaned != '') {
				$tentativeScore = 100 - (($levscore / $scoreBasis) * 100);
				if ($tentativeScore < 95) {
					$pathOnlySpaces = $this->f->str_replace($this->separatingCharacters, " ", $pathOnly);
					$pathOnlySpaces = trim($this->f->str_replace('/', " ", $pathOnlySpaces));
					$levscore = min($levscore, $this->customLevenshtein($fullURLspacesCleaned, $pathOnlySpaces));
				}
			}
			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_TAG] = number_format($score, 4, '.', '');

			// Update top-K heap
			$topKScores->insert($score);
			if ($topKScores->count() > $suggestMax) {
				$topKScores->extract();
			}
		}

		return $permalinks;
	}

	function matchOnPosts($permalinks, $requestedURLRaw, $requestedURLCleaned, $fullURLspacesCleaned, $rowType) {

		// pre-filter some pages based on the min and max possible levenshtein distances.
		$likelyMatchIDsAndPermalinks = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rowType);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		$this->logger->debugMessage("Found " . count($likelyMatchIDs) . " likely match IDs.");

		// Early termination optimization: maintain a min-heap of top-K scores
		// Once we have K matches, we can skip candidates that can't beat the worst in heap
		$options = $this->logic->getOptions();
		$suggestMax = absint($options['suggest_max']);
		$topKScores = new SplMinHeap(); // Min-heap: smallest score at top
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		// Process candidates in order of best match first (smallest minDist first)
		// This is critical for early termination: filling the heap with good scores early
		// allows us to skip more candidates later
		while (count($likelyMatchIDs) > 0) {
			$id = array_shift($likelyMatchIDs); // Take from beginning (best matches first)

			// use the levenshtein distance formula here.
			$the_permalink = $likelyMatchIDsAndPermalinks[$id];
			$urlParts = parse_url($the_permalink);
			$existingPageURL = $this->logic->removeHomeDirectory($urlParts['path']);
			$existingPageURLSpaces = $this->f->str_replace($this->separatingCharacters, " ", $existingPageURL);

			$existingPageURLCleaned = $this->getLastURLPart($existingPageURLSpaces);
			$scoreBasis = $this->f->strlen($existingPageURLCleaned) * 3;
			if ($scoreBasis == 0) {
				continue;
			}

			// EARLY TERMINATION: Check if this candidate can possibly beat our worst current match
			if ($topKScores->count() >= $suggestMax) {
				$worstAcceptableScore = $topKScores->top();

				// OPTIMIZATION 3: Levenshtein distance threshold pruning
				// Calculate maximum Levenshtein distance that could still beat worstAcceptableScore
				// Formula: score = 100 - ((lev / scoreBasis) * 100)
				// Solving for lev: lev = (100 - score) * scoreBasis / 100
				$maxAllowedLevenshtein = ((100 - $worstAcceptableScore) * $scoreBasis) / 100;

				// Calculate minimum possible distance based on length difference
				$existingURLCleanedLength = $this->f->strlen($existingPageURLCleaned);
				$minPossibleDistance = abs($requestedURLCleanedLength - $existingURLCleanedLength);

				// If minimum possible distance already exceeds threshold, skip
				if ($minPossibleDistance > $maxAllowedLevenshtein) {
					continue; // Can't possibly beat worst score in heap
				}
			}

			$levscore = $this->customLevenshtein($requestedURLCleaned, $existingPageURLCleaned);

			// OPTIMIZATION 2: Lazy evaluation of fullURLspacesCleaned (10-20% reduction)
			// Only try the second comparison if the first score isn't already excellent (>95)
			if ($fullURLspacesCleaned != '') {
				$tentativeScore = 100 - (($levscore / $scoreBasis) * 100);
				if ($tentativeScore < 95) {
					$levscore = min($levscore, $this->customLevenshtein($fullURLspacesCleaned, $existingPageURLCleaned));
				}
			}

			if ($rowType == 'image') {
				// strip the image size from the file name and try again.
				// the image size is at the end of the file in the format of -640x480
				$strippedImageName = $this->f->regexReplace('(.+)([-]\d{1,5}[x]\d{1,5})([.].+)',
						'\\1\\3', $requestedURLRaw);

				if (($strippedImageName != null) && ($strippedImageName != $requestedURLRaw)) {
					$strippedImageName = $this->f->str_replace($this->separatingCharactersForImages, " ", $strippedImageName);
					$levscore = min($levscore, $this->customLevenshtein($strippedImageName, $existingPageURL));

					$strippedImageName = $this->getLastURLPart($strippedImageName);
					$levscore = min($levscore, $this->customLevenshtein($strippedImageName, $existingPageURLCleaned));
				}
			}
			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_POST] = number_format($score, 4, '.', '');

			// Update top-K heap with this score
			$topKScores->insert($score);
			// Keep heap size at most suggestMax (remove worst if exceeded)
			if ($topKScores->count() > $suggestMax) {
				$topKScores->extract(); // Remove the smallest (worst) score
			}
		}

		return $permalinks;
	}

	function initializePublishedPostsProvider() {
		if ($this->publishedPostsProvider == null) {
			$this->publishedPostsProvider = ABJ_404_Solution_PublishedPostsProvider::getInstance();
		}
		$this->permalinkCache->updatePermalinkCache(1);
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
			$link = $this->dao->getPermalinkFromCache($id);

			if ($link == null || trim($link) == '') {
				$link = get_the_permalink($id);
			}
			return $this->f->normalizeUrlString($link);

		} else if ($rowType == 'tags') {
			return $this->f->normalizeUrlString(get_tag_link($id));

		} else if ($rowType == 'categories') {
			return $this->f->normalizeUrlString(get_category_link($id));

		} else if ($rowType == 'image') {
			$src = wp_get_attachment_image_src($id, "attached-image");
			if ($src == false || !is_array($src)) {
				return null;
			}
			return $this->f->normalizeUrlString($src[0]);

		} else {
			throw new \Exception("Unknown row type ...");
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

		$options = $this->logic->getOptions();
		// we get more than we need because the algorithm we actually use
		// is not based solely on the Levenshtein distance.
		$onlyNeedThisManyPages = min(5 * absint($options['suggest_max']), 100);

		// EARLY N-GRAM PREFILTERING (Critical optimization for large sites)
		// Apply N-gram filtering BEFORE the main loop to reduce 20k posts to ~200 candidates
		// This prevents timeout/memory issues on sites with many posts
		$ngramPrefilterApplied = false;
		if ($rowType == 'pages' && $rows === null) {
			$cacheCount = $this->ngramFilter->getCacheCount();

			// Gate 1: Minimum entry count (checked first to short-circuit cheaply)
			if ($cacheCount < self::NGRAM_MIN_CACHE_ENTRIES) {
				$this->logger->debugMessage(sprintf(
					"N-gram prefilter skipped (gate 1: min entries): count=%d (need %d)",
					$cacheCount,
					self::NGRAM_MIN_CACHE_ENTRIES
				));
			// Gate 2: Cache must be initialized (not mid-rebuild)
			} elseif (!$this->ngramFilter->isCacheInitialized()) {
				$this->logger->debugMessage(sprintf(
					"N-gram prefilter skipped (gate 2: not initialized): count=%d",
					$cacheCount
				));
			// Gate 3: Coverage ratio must be sufficient (not stale)
			} else {
				$coverageRatio = $this->ngramFilter->getCacheCoverageRatio();
				if ($coverageRatio < self::NGRAM_MIN_COVERAGE_RATIO) {
					$this->logger->debugMessage(sprintf(
						"N-gram prefilter skipped (gate 3: low coverage): ratio=%.2f (need %.2f)",
						$coverageRatio,
						self::NGRAM_MIN_COVERAGE_RATIO
					));
				} else {
					// All gates passed - use N-gram prefiltering
					$similarPages = $this->ngramFilter->findSimilarPages(
						$requestedURLCleaned,
						self::NGRAM_PREFILTER_THRESHOLD,
						self::NGRAM_PREFILTER_MAX_CANDIDATES
					);

					// Trust the N-gram filter results if cache is well-populated.
					// Even if only a few candidates match, those ARE the relevant candidates -
					// falling back to full scan would defeat the prefilter's purpose.
					if (!empty($similarPages)) {
						$candidateIds = array_keys($similarPages);
						$this->publishedPostsProvider->resetBatch();
						$this->publishedPostsProvider->restrictToIds($candidateIds);
						$ngramPrefilterApplied = true;

						$this->logger->debugMessage(sprintf(
							"N-gram prefilter: Restricted to %d candidates (cache has %d entries, coverage=%.2f)",
							count($candidateIds),
							$cacheCount,
							$coverageRatio
						));
					} else {
						// Zero results from N-gram filter on a populated cache means
						// no pages are similar enough. Skip prefiltering for this edge case
						// to allow Levenshtein a chance (N-gram might have missed borderline matches).
						$this->logger->debugMessage(
							"N-gram prefilter skipped (gate 4: zero results): allowing fallback to full scan"
						);
					}
				}
			}
		}

		// create a list sorted by min levenshstein distance and max levelshtein distance.
		/* 1) Get a list of minumum and maximum levenshtein distances - two lists, one ordered by the min
		 * distance and one ordered by the max distance. */
		$minDistances = array();
		$maxDistances = array();
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= self::MAX_DIST; $currentDistanceIndex++) {
			$maxDistances[$currentDistanceIndex] = array();
			$minDistances[$currentDistanceIndex] = array();
		}

		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);
		$fullURLspacesLength = $this->f->strlen($fullURLspaces);

		$userRequestedURLWords = explode(" ", (empty($fullURLspaces) ? $requestedURLCleaned : $fullURLspaces));
		$idsWithWordsInCommon = array();
		$wasntReadyCount = 0;
		$idToPermalink = array();

		// get the next X pages in batches until enough matches are found.
		// Note: resetBatch is only called here if N-gram prefiltering wasn't applied
		if (!$ngramPrefilterApplied) {
			$this->publishedPostsProvider->resetBatch();
		}
		if ($rows != null) {
			$this->publishedPostsProvider->useThisData($rows);
		}
		$currentBatch = $this->publishedPostsProvider->getNextBatch($requestedURLCleanedLength);

		$row = array_pop($currentBatch);
		while ($row != null) {
			$row = (array)$row;

			// Count pages considered for performance metrics
			if ($this->enablePerformanceCounters) {
				$this->totalPagesConsidered++;
			}

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
				throw new \Exception("Unknown row type ... " . esc_html($rowType));
			}

			if (array_key_exists('url', $row)) {
			    $the_permalink = isset($row['url']) ? $row['url'] : '';
			    $the_permalink = $this->f->normalizeUrlString($the_permalink);
			    $urlParts = parse_url($the_permalink);
			    
			    if (is_bool($urlParts)) {
			        $this->dao->removeFromPermalinkCache($id);
			    }
			}
			if (!array_key_exists('url', $row) || (isset($urlParts) && is_bool($urlParts))) {
			    $wasntReadyCount++;
			    $the_permalink = $this->getPermalink($id, $rowType);
			    $the_permalink = $this->f->normalizeUrlString($the_permalink);
			    $urlParts = parse_url($the_permalink);
			}
			
			$_REQUEST[ABJ404_PP]['debug_info'] = 'Likely match IDs processing permalink: ' . 
				$the_permalink . ', $wasntReadyCount: ' . $wasntReadyCount;
			$idToPermalink[$id] = $the_permalink;

			if (!array_key_exists('path', $urlParts)) {
				continue;
			}
			$existingPageURL = $this->logic->removeHomeDirectory($urlParts['path']);
			$urlParts = null;

			// this line used to take too long to execute.
			$existingPageURLSpaces = $this->f->str_replace($this->separatingCharacters, " ", $existingPageURL);

			$existingPageURLCleaned = $this->getLastURLPart($existingPageURLSpaces);
			$existingPageURLSpaces = null;

			// the minimum distance is the minimum of the two possibilities. one is longer anyway, so
			// it shouldn't matter.
			$minDist = abs($this->f->strlen($existingPageURLCleaned) - $requestedURLCleanedLength);
			if ($fullURLspaces != '') {
				$minDist = min($minDist, abs($this->f->strlen($fullURLspacesLength) - $requestedURLCleanedLength));
			}
			$maxDist = $this->f->strlen($existingPageURLCleaned);
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
				$lengthOfTheLongestWordInCommon = max(array_map(array($this->f,'strlen'), $wordsInCommon));
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
            	$this->logger->errorMessage("maxDist is less than 0 (" . $maxDist . 
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
			$this->logger->infoMessage("The permalink cache wasn't ready for " . $wasntReadyCount . " IDs.");
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

		// OPTIMIZATION 4: Better candidate ordering
		// Prioritize candidates with word overlap to fill early termination heap faster
		// This makes subsequent filtering more effective
		$idsWithWords = array_intersect($listOfIDsToReturn, $idsWithWordsInCommon);
		$idsWithoutWords = array_diff($listOfIDsToReturn, $idsWithWordsInCommon);
		$listOfIDsToReturn = array_merge($idsWithWords, $idsWithoutWords);

		// OPTIMIZATION 5: Secondary N-gram filtering (only if prefiltering wasn't applied)
		// Skip if early prefiltering already applied - avoids calling findSimilarPages twice
		// This path handles tags, categories, and fallback cases
		$beforeNGramCount = count($listOfIDsToReturn);

		// Use short-circuit evaluation: check cheap conditions first
		if (!$ngramPrefilterApplied
			&& $beforeNGramCount > self::NGRAM_SECONDARY_MIN_CANDIDATES
			&& $this->ngramFilter->getCacheCount() >= self::NGRAM_MIN_CACHE_ENTRIES
			&& $this->ngramFilter->isCacheInitialized()
			&& $this->ngramFilter->getCacheCoverageRatio() >= self::NGRAM_MIN_COVERAGE_RATIO) {
			// Use N-gram filter to get similarity scores for all pages
			$similarPages = $this->ngramFilter->findSimilarPages(
				$requestedURLCleaned,
				self::NGRAM_SECONDARY_THRESHOLD,
				min($beforeNGramCount, self::NGRAM_SECONDARY_MAX_CANDIDATES)
			);

			// Filter listOfIDsToReturn to only include pages with good N-gram similarity
			if (!empty($similarPages)) {
				$ngramFilteredIDs = array_keys($similarPages);
				$listOfIDsToReturn = array_intersect($listOfIDsToReturn, $ngramFilteredIDs);

				// Sort by N-gram similarity (best matches first)
				usort($listOfIDsToReturn, function($a, $b) use ($similarPages) {
					$simA = isset($similarPages[$a]) ? $similarPages[$a] : 0;
					$simB = isset($similarPages[$b]) ? $similarPages[$b] : 0;
					return $simB <=> $simA;  // Descending order
				});

				$this->logger->debugMessage(sprintf(
					"N-gram filter (secondary): %d → %d candidates (%.1f%% reduction)",
					$beforeNGramCount,
					count($listOfIDsToReturn),
					100 * (1 - count($listOfIDsToReturn) / $beforeNGramCount)
				));
			}
		}

		// OPTIMIZATION 6: Early return for large candidate sets (after N-gram filtering)
		// If there are still more than 300 IDs after N-gram filtering, only use matches where words match
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
		$length = $this->f->strlen($str);
		$array = array();
		for ($i = 0; $i < $length; $i++) {
			$array[$i] = $this->f->substr($str, $i, 1);
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
		// Increment performance counter if enabled
		if ($this->enablePerformanceCounters) {
			$this->levenshteinCallCount++;
		}
	    $_REQUEST[ABJ404_PP]['debug_info'] = 'customLevenshtein. str1: ' . esc_html($str1) . ', str2: ' . esc_html($str2);

	    $RowLen = $this->f->strlen($str1);
	    $ColLen = $this->f->strlen($str2);
		$cost = 0;

		// / Test string length. URLs should not be more than 2,083 characters
		if (max($RowLen, $ColLen) > ABJ404_MAX_URL_LENGTH) {
            throw new Exception("Maximum string length in customLevenshtein is " .
            	ABJ404_MAX_URL_LENGTH . ". Yours is " . max($RowLen, $ColLen) . ".");
		}

		// OPTIMIZATION 1: Use PHP's built-in levenshtein() for short strings (30-50% faster)
		// Built-in is written in C and much faster, but limited to 255 characters
		// For multibyte strings, we need to verify byte length, not character count
		if (strlen($str1) <= 255 && strlen($str2) <= 255) {
			return levenshtein($str1, $str2);
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

	/**
	 * Trigger asynchronous suggestion computation via non-blocking HTTP request.
	 * Uses the requested URL (MD5 hashed) as the transient key.
	 *
	 * @param string $requestedURL The full requested URL that caused the 404
	 * @return bool True if computation was triggered, false if already pending/complete
	 */
	public function triggerAsyncSuggestionComputation($requestedURL) {
		$f = ABJ_404_Solution_Functions::getInstance();

		// Normalize URL using centralized function for consistency
		$normalizedURL = $f->normalizeURLForCacheKey($requestedURL);

		$urlKey = md5($normalizedURL);
		$transientKey = 'abj404_suggest_' . $urlKey;

		// Check if already computing or complete - prevent duplicate work
		$existing = get_transient($transientKey);
		if ($existing !== false) {
			$this->logger->debugMessage("Async suggestions: skipping, transient already exists for " .
				esc_html($normalizedURL) . " (status: " . esc_html($existing['status']) . ")");
			return false;
		}

		// Generate a unique token for this computation request
		// This prevents unauthorized direct calls to the AJAX endpoint (DoS protection)
		$token = wp_generate_password(32, false);

		// Mark as pending BEFORE firing request (race condition protection)
		// TTL of 120 seconds: gives slow hosts enough time to start the worker
		// Note: started=0 means no worker has claimed the work yet. The first worker
		// will set started=time() when it claims the work. This prevents the bug where
		// the first worker skips itself thinking another worker is already computing.
		set_transient($transientKey, array(
			'status' => 'pending',
			'url' => $normalizedURL,
			'started' => 0,  // 0 = no worker has claimed yet; worker sets time() when claiming
			'token' => $token
		), 120); // 2 minute TTL (allows slow wp_remote_post)

		$this->logger->debugMessage("Async suggestions: triggering background computation for " .
			esc_html($normalizedURL));

		// Fire non-blocking request to compute suggestions
		// Note: timeout of 5s is needed for connection establishment (TLS handshake, etc.)
		// even with blocking=false, a too-short timeout can prevent the request from being sent
		$response = wp_remote_post(admin_url('admin-ajax.php'), array(
			'blocking'  => false,
			'timeout'   => 5,  // 5 seconds for connection establishment
			'sslverify' => apply_filters('https_local_ssl_verify', false),
			'body'      => array(
				'action'   => 'abj404_compute_suggestions',
				'url'      => $normalizedURL,
				'token'    => $token
			)
		));

		// If dispatch failed, delete the pending transient so caller can compute synchronously
		if (is_wp_error($response)) {
			$this->logger->debugMessage("Async suggestions: dispatch failed for " .
				esc_html($normalizedURL) . " - " . $response->get_error_message());
			delete_transient($transientKey);
			return false;
		}

		return true;
	}

	/**
	 * Check if the configured 404 page contains the suggestions shortcode.
	 *
	 * @return bool True if 404 page has the shortcode
	 */
	public function does404PageHaveSuggestionsShortcode() {
		$options = $this->logic->getOptions();
		$dest404page = isset($options['dest404page']) ? $options['dest404page'] : null;

		if (!$this->logic->thereIsAUserSpecified404Page($dest404page)) {
			return false;
		}

		// Extract page ID from dest404page (format: "123|1")
		$parts = explode('|', $dest404page);
		$page404Id = isset($parts[0]) ? intval($parts[0]) : 0;

		if ($page404Id <= 0) {
			return false;
		}

		$page = get_post($page404Id);
		if (!$page) {
			return false;
		}

		return has_shortcode($page->post_content, ABJ404_SHORTCODE_NAME);
	}

}
