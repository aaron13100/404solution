<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Levenshtein distance engine and candidate pre-filtering for
 * ABJ_404_Solution_SpellChecker.
 *
 * Contains: getLikelyMatchIDs, getMaxAcceptableDistance, customLevenshtein.
 */
trait SpellCheckerTrait_LevenshteinEngine {

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
	 * @param string $rowType
	 * @param array<int, array<string, mixed>>|null $rows
	 * @return array<int|string, mixed>
	 */
	function getLikelyMatchIDs(string $requestedURLCleaned, string $fullURLspaces, string $rowType, ?array $rows = null) {

		$options = $this->logic->getOptions();
		// we get more than we need because the algorithm we actually use
		// is not based solely on the Levenshtein distance.
		$suggestMaxLikely = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$onlyNeedThisManyPages = min(5 * absint($suggestMaxLikely), 100);

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
					if (!empty($similarPages) && $this->publishedPostsProvider !== null) {
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
						if ($this->skipNgramGate4) {
							// Async path (page suggestions worker): skip gate 4 and
							// fall through to the full Levenshtein scan.
							$this->logger->debugMessage(
								"N-gram prefilter: zero candidates at Dice >= 0.3 — skipNgramGate4 is set, falling through to full scan"
							);
						} else {
							// Synchronous 404 handling: return early to avoid a ~5s scan.
							$this->logger->debugMessage(
								"N-gram prefilter: zero candidates at Dice >= 0.3 — no similar pages exist, returning early"
							);
							return array();
						}
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
		if ($this->publishedPostsProvider === null) {
			return array();
		}
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

			if ($id === null) {
				$row = array_pop($currentBatch);
				continue;
			}
			$idInt = is_scalar($id) ? (int)$id : 0;

			if (array_key_exists('url', $row)) {
			    $the_permalink = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
			    $the_permalink = $this->f->normalizeUrlString($the_permalink);
			    $urlParts = parse_url($the_permalink);

			    if (is_bool($urlParts)) {
			        $this->dao->removeFromPermalinkCache($idInt);
			    }
			}
			if (!array_key_exists('url', $row) || (isset($urlParts) && is_bool($urlParts))) {
			    $wasntReadyCount++;
			    $the_permalink = $this->getPermalink($idInt, $rowType);
			    $the_permalink = $this->f->normalizeUrlString($the_permalink);
			    $urlParts = parse_url($the_permalink);
			}

			$_REQUEST[ABJ404_PP]['debug_info'] = 'Likely match IDs processing permalink: ' .
				$the_permalink . ', $wasntReadyCount: ' . $wasntReadyCount;
			$idToPermalink[$id] = $the_permalink;

			if (!is_array($urlParts) || !array_key_exists('path', $urlParts)) {
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
				$minDist = min($minDist, abs($fullURLspacesLength - $requestedURLCleanedLength));
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
			if (isset($minDistances[$minDist])) {
			    array_push($minDistances[$minDist], $id);
			} else {
			    $minDistances[$minDist] = [$id];
			}

			if ($maxDist < 0) {
            	$this->logger->errorMessage("maxDist is less than 0 (" . $maxDist .
            			") for '" . $existingPageURLCleaned . "', wordsInCommon: " .
            			json_encode($wordsInCommon) . ", ");
            	$maxDist = 0;
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
		$maxDistFound = self::MAX_LIKELY_DISTANCE;
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= self::MAX_LIKELY_DISTANCE; $currentDistanceIndex++) {
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
		// If there are still more than 300 IDs after N-gram filtering, only use matches where words match.
		// IMPORTANT: Must return [id => permalink] map, not a plain array of IDs, so callers can look up
		// the permalink for each candidate without an extra database query.
		if (count($listOfIDsToReturn) > 300 && count($idsWithWordsInCommon) >= $onlyNeedThisManyPages) {
			$maybeOKguesses = array_intersect($listOfIDsToReturn, $idsWithWordsInCommon);

			$sourceIds = (count($maybeOKguesses) >= $onlyNeedThisManyPages)
				? $maybeOKguesses
				: $idsWithWordsInCommon;

			$result = array();
			foreach ($sourceIds as $id) {
				if (isset($idToPermalink[$id])) {
					$result[$id] = $idToPermalink[$id];
				}
			}
			return $result;
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
	 * @param array<int, array<int, mixed>> $maxDistances
	 * @param int $onlyNeedThisManyPages
	 * @return int the maximum acceptable distance to use when searching for similar permalinks.
	 */
	function getMaxAcceptableDistance(array $maxDistances, int $onlyNeedThisManyPages): int {
		$pagesSeenSoFar = 0;
		$currentDistanceIndex = 0;
		$maxDistFound = self::MAX_LIKELY_DISTANCE;
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= self::MAX_LIKELY_DISTANCE; $currentDistanceIndex++) {
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

		// Pre-split into character arrays so multibyte characters are indexed correctly.
		// Direct $str[$i] indexing accesses bytes, not characters, which corrupts the
		// distance for multibyte strings (CJK, Cyrillic, Arabic, etc.).
		$chars1 = mb_str_split($str1, 1, 'UTF-8');
		$chars2 = mb_str_split($str2, 1, 'UTF-8');

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
			    $cost = ($chars1[$RowIdx - 1] === $chars2[$ColIdx - 1]) ? 0 : 1;
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
