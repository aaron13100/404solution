<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-call accumulator that ranks candidate page IDs by approximate edit
 * distance for the spell-checking subsystem.
 *
 * One instance is created for each {@see ABJ_404_Solution_SpellLevenshteinEngine::getLikelyMatchIDs()}
 * call. The engine feeds it one candidate at a time via {@see score()}; the
 * ranker files each candidate into min/max edit-distance buckets. Once the
 * batch is exhausted the engine calls {@see prioritize()} to harvest the
 * closest-matching IDs (closest distance first, IDs that share whole words
 * with the requested URL promoted ahead of the rest).
 *
 * This responsibility was extracted from the engine: holding the bucket state
 * here (rather than passing three accumulator arrays through the hot loop by
 * reference) keeps the scoring logic independently testable and the engine's
 * orchestration loop allocation-clean.
 */
// allow-no-test-found: behavior-identical extraction from ABJ_404_Solution_SpellLevenshteinEngine::getLikelyMatchIDs; exercised end-to-end through that path in SpellCheckerMatchingTest, SpellCheckerAlgorithmTest, SpellCheckerPrefilteringTest and the @group integration SpellCheckerLargeScaleTest. Same integration-coverage strategy as the sibling spell collaborators (SpellNGramPrefilter, SpellCandidatePermalinkLookup).
class ABJ_404_Solution_SpellCandidateDistanceRanker {

	const MAX_DIST = 2083;

	const MAX_LIKELY_DISTANCE = 300;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_SpellURLMatcher */
	private $urlMatcher;

	/** @var array<int, string> */
	private array $separatingCharacters;

	/** @var ABJ_404_Solution_SpellNGramPrefilter */
	private $ngramPrefilter;

	/** @var array<int, array<int, mixed>> candidate IDs keyed by minimum possible edit distance */
	private array $minDistances;

	/** @var array<int, array<int, mixed>> candidate IDs keyed by maximum possible edit distance */
	private array $maxDistances;

	/** @var array<int, mixed> candidate IDs that share at least one whole word with the request */
	private array $idsWithWordsInCommon = array();

	/**
	 * @param ABJ_404_Solution_SpellLevenshteinEngineDependencies $deps shared collaborator bundle
	 * @param ABJ_404_Solution_SpellNGramPrefilter $ngramPrefilter the engine's prefilter, reused for the secondary filter
	 */
	public function __construct(ABJ_404_Solution_SpellLevenshteinEngineDependencies $deps,
			ABJ_404_Solution_SpellNGramPrefilter $ngramPrefilter) {
		$this->f = $deps->functions;
		$this->logic = $deps->logic;
		$this->logger = $deps->logger;
		$this->urlMatcher = $deps->urlMatcher;
		$this->separatingCharacters = $deps->separatingCharacters;
		$this->ngramPrefilter = $ngramPrefilter;

		// Allocate the empty min/max edit-distance bucket arrays, one slot per
		// possible distance from 0 to self::MAX_DIST inclusive.
		$this->minDistances = array();
		$this->maxDistances = array();
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= self::MAX_DIST; $currentDistanceIndex++) {
			$this->maxDistances[$currentDistanceIndex] = array();
			$this->minDistances[$currentDistanceIndex] = array();
		}
	}

	/**
	 * Compute the min/max edit-distance bounds for one candidate URL and file
	 * its id into the distance buckets. Pure scoring: no I/O, mutates only this
	 * instance's bucket state.
	 *
	 * @param mixed $id the raw candidate id, as gathered from the row
	 * @param string $existingPageURLPath the candidate URL path (urlParts['path'])
	 * @param int $requestedURLCleanedLength
	 * @param string $fullURLspaces
	 * @param int $fullURLspacesLength
	 * @param array<int, string> $userRequestedURLWords
	 */
	public function score($id, string $existingPageURLPath, int $requestedURLCleanedLength,
			string $fullURLspaces, int $fullURLspacesLength, array $userRequestedURLWords): void {
		$existingPageURL = $this->logic->urlNormalization()->removeHomeDirectory($existingPageURLPath);

		$existingPageURLSpaces = $this->f->str_replace($this->separatingCharacters, " ", $existingPageURL);

		$existingPageURLCleaned = $this->urlMatcher->getLastURLPart($existingPageURLSpaces);
		$existingPageURLSpaces = null;

		$minDist = abs($this->f->strlen($existingPageURLCleaned) - $requestedURLCleanedLength);
		if ($fullURLspaces != '') {
			$minDist = min($minDist, abs($fullURLspacesLength - $requestedURLCleanedLength));
		}
		$maxDist = $this->f->strlen($existingPageURLCleaned);
		if ($fullURLspaces != '') {
			$maxDist = min($maxDist, $fullURLspacesLength);
		}

		$existingPageURLCleanedWords = explode(" ", $existingPageURLCleaned);
		$wordsInCommon = array_intersect($userRequestedURLWords, $existingPageURLCleanedWords);
		$wordsInCommon = array_merge(array_unique($wordsInCommon, SORT_REGULAR), array());
		if (count($wordsInCommon) > 0) {
			array_push($this->idsWithWordsInCommon, $id);
			$lengthOfTheLongestWordInCommon = max(array_map(array($this->f,'strlen'), $wordsInCommon));
			$maxDist = $maxDist - $lengthOfTheLongestWordInCommon;
		}

		if (isset($this->minDistances[$minDist])) {
		    array_push($this->minDistances[$minDist], $id);
		} else {
		    $this->minDistances[$minDist] = [$id];
		}

		if ($maxDist < 0) {
        	$this->logger->errorMessage("maxDist is less than 0 (" . $maxDist .
        			") for '" . $existingPageURLCleaned . "', wordsInCommon: " .
        			json_encode($wordsInCommon) . ", ");
        	$maxDist = 0;
		} else if ($maxDist > self::MAX_DIST) {
			$maxDist = self::MAX_DIST;
		}

		if (is_array($this->maxDistances[$maxDist])) {
			array_push($this->maxDistances[$maxDist], $id);
		}
	}

	/**
	 * The largest max-distance bucket index we need to scan to have seen at
	 * least $onlyNeedThisManyPages candidates, scaled up 10% for headroom.
	 * Used by the engine to bound how many more rows it pulls from the batch.
	 *
	 * @param int $onlyNeedThisManyPages
	 * @return int
	 */
	public function getMaxAcceptableDistance(int $onlyNeedThisManyPages): int {
		$pagesSeenSoFar = 0;
		$maxDistFound = self::MAX_LIKELY_DISTANCE;
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= self::MAX_LIKELY_DISTANCE; $currentDistanceIndex++) {
			$pagesSeenSoFar += sizeof($this->maxDistances[$currentDistanceIndex]);

			if ($pagesSeenSoFar >= $onlyNeedThisManyPages) {
				$maxDistFound = $currentDistanceIndex;
				break;
			}
		}

		$acceptableDistance = (int)($maxDistFound * 1.1);
		return $acceptableDistance;
	}

	/**
	 * Harvest the prioritized candidate IDs from the accumulated buckets:
	 * closest min-distance first, IDs sharing whole words promoted ahead of
	 * the rest, then the n-gram secondary filter applied.
	 *
	 * @param int $onlyNeedThisManyPages
	 * @param bool $ngramPrefilterApplied
	 * @param string $requestedURLCleaned
	 * @return array<int, mixed>
	 */
	public function prioritize(int $onlyNeedThisManyPages, bool $ngramPrefilterApplied,
			string $requestedURLCleaned): array {
		$pagesSeenSoFar = 0;
		$maxDistFound = self::MAX_LIKELY_DISTANCE;
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= self::MAX_LIKELY_DISTANCE; $currentDistanceIndex++) {
			$pagesSeenSoFar += sizeof($this->maxDistances[$currentDistanceIndex]);
			if ($pagesSeenSoFar >= $onlyNeedThisManyPages) {
				$maxDistFound = $currentDistanceIndex;
				break;
			}
		}

		$listOfIDsToReturn = array();
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= $maxDistFound; $currentDistanceIndex++) {
			$listOfMinDistanceIDs = $this->minDistances[$currentDistanceIndex];
			$listOfIDsToReturn = array_merge($listOfIDsToReturn, $listOfMinDistanceIDs);
		}

		$listOfIDsToReturn = $this->normalizeScalarIds($listOfIDsToReturn);
		$idsWithWordsInCommon = $this->normalizeScalarIds($this->idsWithWordsInCommon);
		$idsWithWords = array_intersect($listOfIDsToReturn, $idsWithWordsInCommon);
		$idsWithoutWords = array_diff($listOfIDsToReturn, $idsWithWordsInCommon);
		$listOfIDsToReturn = array_merge($idsWithWords, $idsWithoutWords);

		$listOfIDsToReturn = $this->normalizeScalarIds(
			$this->ngramPrefilter->applySecondaryFilter(
				$listOfIDsToReturn,
				$ngramPrefilterApplied,
				$requestedURLCleaned
			)
		);

		if (count($listOfIDsToReturn) > 300 && count($idsWithWordsInCommon) >= $onlyNeedThisManyPages) {
			$maybeOKguesses = array_intersect($listOfIDsToReturn, $idsWithWordsInCommon);
			return (count($maybeOKguesses) >= $onlyNeedThisManyPages)
				? $maybeOKguesses : $idsWithWordsInCommon;
		}
		return $listOfIDsToReturn;
	}

	/**
	 * @param array<int, mixed> $ids
	 * @return array<int, int|string>
	 */
	private function normalizeScalarIds(array $ids): array {
		$normalized = array();
		foreach ($ids as $id) {
			if (is_int($id) || is_string($id)) {
				$normalized[] = $id;
			} else if (is_scalar($id)) {
				$normalized[] = (string)$id;
			}
		}
		return $normalized;
	}
}
