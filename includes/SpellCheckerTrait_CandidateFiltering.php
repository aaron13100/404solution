<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Candidate filtering, scoring, and matching on posts/tags/categories
 * for ABJ_404_Solution_SpellChecker.
 */
trait SpellCheckerTrait_CandidateFiltering {

    /** Returns a list of matching posts.
	 * @param string $requestedURLRaw
	 * @param string $includeCats
	 * @param string $includeTags
	 * @return array<int, mixed>
	 */
	function findMatchingPosts(string $requestedURLRaw, string $includeCats = '1', string $includeTags = '1') {

		$options = $this->logic->getOptions();
		// the number of pages to cache is (max suggestions) + (the number of exclude pages).
		// (if either of these numbers increases then we need to clear the spelling cache.)
		$excludePagesCount = 0;
		$excludePagesRaw = isset($options['excludePages[]']) && is_string($options['excludePages[]']) ? $options['excludePages[]'] : '';
		if (trim($excludePagesRaw) !== '') {
			$jsonResult = json_decode($excludePagesRaw);
			if (!is_array($jsonResult)) {
				$jsonResult = array($jsonResult);
			}
			$excludePagesCount = count($jsonResult);
		}
		$suggestMaxRaw = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$maxCacheCount = absint($suggestMaxRaw) + $excludePagesCount;

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

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, string> $permalinks
	 * @return array<string, string>
	 */
	function removeExcludedPages(array $options, array $permalinks): array {
		$excludePagesJsonRaw = isset($options['excludePages[]']) ? $options['excludePages[]'] : '';
		$excludePagesJson = is_string($excludePagesJsonRaw) ? $excludePagesJsonRaw : '';
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
			// remove it from the results list.
			// Entry format matches permalink key format: "id|type" (e.g. "42|1").
			unset($permalinks[(string)$excludePage]);
		}

		return $permalinks;
	}

	/**
     * Removes permalink suggestions if their URL path matches exclusion regex patterns.
     *
     * @param array<string, mixed> $options    Plugin options containing 'suggest_regex_exclusions_usable'.
     * @param array<string, string> $permalinks An array where keys are "ID|TYPE_CONSTANT" and values are scores.
     * @param int $maxCacheCount
     * @return array<string, string> The filtered $permalinks array.
     */
    function removeExcludedPagesWithRegex(array $options, array $permalinks, int $maxCacheCount): array {
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
        if (!defined('ABJ404_TYPE_POST')) define('ABJ404_TYPE_POST', 1);
        if (!defined('ABJ404_TYPE_CAT')) define('ABJ404_TYPE_CAT', 2);
        if (!defined('ABJ404_TYPE_TAG')) define('ABJ404_TYPE_TAG', 3);
        // Add other types like ABJ404_TYPE_IMAGE if needed

        $typeConstantStr = is_scalar($typeConstant) ? (string)$typeConstant : '';
        switch ($typeConstantStr) { // Cast to string for reliable comparison if needed
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

	/**
	 * @param array<string, string> $permalinks
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspacesCleaned
	 * @param string $rowType
	 * @return array<string, string>
	 */
	function matchOnCats(array $permalinks, string $requestedURLCleaned, string $fullURLspacesCleaned, string $rowType): array {

		$rows = $this->dao->getPublishedCategories();
		$rows = $this->getOnlyIDandTermID($rows);

		// pre-filter some pages based on the min and max possible levenshtein distances.
		$likelyMatchIDsAndPermalinks = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, 'categories', $rows);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		// Early termination optimization
		$options = $this->logic->getOptions();
		$suggestMaxRaw = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$suggestMax = absint($suggestMaxRaw);
		$topKScores = new SplMinHeap();
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		// access the array directly instead of using a foreach loop so we can remove items
		// from the end of the array in the middle of the loop.
		foreach ($likelyMatchIDs as $id) {
			// use the levenshtein distance formula here.
			$the_permalink = $this->getPermalink((int)$id, 'categories');
			$urlParts = parse_url(is_string($the_permalink) ? $the_permalink : '');
			if (!is_array($urlParts) || !isset($urlParts['path'])) {
				continue;
			}
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

	/**
	 * @param array<string, string> $permalinks
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspacesCleaned
	 * @param string $rowType
	 * @return array<string, string>
	 */
	function matchOnTags(array $permalinks, string $requestedURLCleaned, string $fullURLspacesCleaned, string $rowType): array {

		$rows = $this->dao->getPublishedTags();
		$rows = $this->getOnlyIDandTermID($rows);

		// pre-filter some pages based on the min and max possible levenshtein distances.
		$likelyMatchIDsAndPermalinks = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, 'tags', $rows);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		// Early termination optimization
		$options = $this->logic->getOptions();
		$suggestMaxRawT = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$suggestMax = absint($suggestMaxRawT);
		$topKScores = new SplMinHeap();
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		// access the array directly instead of using a foreach loop so we can remove items
		// from the end of the array in the middle of the loop.
		foreach ($likelyMatchIDs as $id) {
			// use the levenshtein distance formula here.
			$the_permalink = $this->getPermalink((int)$id, 'tags');
			$urlParts = parse_url(is_string($the_permalink) ? $the_permalink : '');
			if (!is_array($urlParts) || !isset($urlParts['path'])) {
				continue;
			}
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

	/**
	 * @param array<string, string> $permalinks
	 * @param string $requestedURLRaw
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspacesCleaned
	 * @param string $rowType
	 * @return array<string, string>
	 */
	function matchOnPosts(array $permalinks, string $requestedURLRaw, string $requestedURLCleaned, string $fullURLspacesCleaned, string $rowType): array {

		// pre-filter some pages based on the min and max possible levenshtein distances.
		$likelyMatchIDsAndPermalinks = $this->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rowType);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		$this->logger->debugMessage("Found " . count($likelyMatchIDs) . " likely match IDs.");

		// Early termination optimization: maintain a min-heap of top-K scores
		// Once we have K matches, we can skip candidates that can't beat the worst in heap
		$options = $this->logic->getOptions();
		$suggestMaxRawP = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$suggestMax = absint($suggestMaxRawP);
		$topKScores = new SplMinHeap(); // Min-heap: smallest score at top
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		// Process candidates in order of best match first (smallest minDist first)
		// This is critical for early termination: filling the heap with good scores early
		// allows us to skip more candidates later
		while (count($likelyMatchIDs) > 0) {
			$id = array_shift($likelyMatchIDs); // Take from beginning (best matches first)

			// use the levenshtein distance formula here.
			$the_permalink = $likelyMatchIDsAndPermalinks[$id];
			$thePermalinkStr = is_string($the_permalink) ? $the_permalink : '';
			$urlParts = parse_url($thePermalinkStr);
			if (!is_array($urlParts) || !isset($urlParts['path'])) {
				continue;
			}
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

}
