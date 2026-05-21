<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Levenshtein distance engine and candidate pre-filtering for the
 * spell-checking subsystem.
 *
 * Extracted from SpellCheckerTrait_LevenshteinEngine as a standalone class
 * with explicit dependency injection.
 */
class ABJ_404_Solution_SpellLevenshteinEngine {

	const MAX_DIST = 2083;

	const MAX_LIKELY_DISTANCE = 300;

	const NGRAM_PREFILTER_THRESHOLD = 0.3;

	const NGRAM_PREFILTER_MAX_CANDIDATES = 500;

	const NGRAM_MIN_CACHE_ENTRIES = 50;

	const NGRAM_SECONDARY_THRESHOLD = 0.4;

	const NGRAM_SECONDARY_MAX_CANDIDATES = 100;

	const NGRAM_MIN_COVERAGE_RATIO = 0.8;

	const NGRAM_SECONDARY_MIN_CANDIDATES = 50;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_ContentRepository */
	private $contentRepository;

	/** @var ABJ_404_Solution_NGramFilter */
	private $ngramFilter;

	/** @var ABJ_404_Solution_SpellURLMatcher */
	private $urlMatcher;

	/** @var array<int, string> */
	private array $separatingCharacters;

	private bool $enablePerformanceCounters = false;

	private bool $skipNgramGate4 = false;

	private int $levenshteinCallCount = 0;

	private int $totalPagesConsidered = 0;

	/** @var ABJ_404_Solution_PublishedPostsProvider|null */
	private ?ABJ_404_Solution_PublishedPostsProvider $publishedPostsProvider = null;

	/**
	 * @param ABJ_404_Solution_Functions $functions
	 * @param ABJ_404_Solution_PluginLogic $logic
	 * @param ABJ_404_Solution_Logging $logger
	 * @param ABJ_404_Solution_ContentRepository $contentRepository
	 * @param ABJ_404_Solution_NGramFilter $ngramFilter
	 * @param ABJ_404_Solution_SpellURLMatcher $urlMatcher
	 * @param array<int, string> $separatingCharacters
	 */
	public function __construct($functions, $logic, $logger, $contentRepository, $ngramFilter, $urlMatcher, array $separatingCharacters) {
		$this->f = $functions;
		$this->logic = $logic;
		$this->logger = $logger;
		$this->contentRepository = $contentRepository;
		$this->ngramFilter = $ngramFilter;
		$this->urlMatcher = $urlMatcher;
		$this->separatingCharacters = $separatingCharacters;
	}

	/** @param ABJ_404_Solution_PublishedPostsProvider|null $provider */
	public function setPublishedPostsProvider(?ABJ_404_Solution_PublishedPostsProvider $provider): void {
		$this->publishedPostsProvider = $provider;
	}

	public function enablePerformanceCounters(bool $enable = true): void {
		$this->enablePerformanceCounters = $enable;
		if ($enable) {
			$this->resetPerformanceCounters();
		}
	}

	public function setSkipNgramGate4(bool $skip = true): void {
		$this->skipNgramGate4 = $skip;
	}

	public function resetPerformanceCounters(): void {
		$this->levenshteinCallCount = 0;
		$this->totalPagesConsidered = 0;
	}

	/**
	 * @return array{levenshtein_calls: int, pages_considered: int, efficiency_percent: float}
	 */
	public function getPerformanceCounters(): array {
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
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspaces
	 * @param string $rowType
	 * @param array<int, array<string, mixed>>|null $rows
	 * @return array<int|string, mixed>
	 */
	function getLikelyMatchIDs(string $requestedURLCleaned, string $fullURLspaces, string $rowType, ?array $rows = null) {

		$options = $this->logic->getOptions();
		$suggestMaxLikely = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$onlyNeedThisManyPages = min(5 * absint($suggestMaxLikely), 100);

		$ngramPrefilterResult = $this->tryApplyNgramPrefilter($rowType, $rows, $requestedURLCleaned);
		if ($ngramPrefilterResult === 'early_return') {
			return array();
		}
		$ngramPrefilterApplied = ($ngramPrefilterResult === 'applied');

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
		$observedPermalinksById = array();
		$wasntReadyCount = 0;

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
				throw new \Exception("Unknown row type ... " . esc_html($rowType)); // allow-raw-error: assertion, should never reach user
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
			        $this->contentRepository->removeFromPermalinkCache($idInt);
			    }
			}
			if (!array_key_exists('url', $row) || (isset($urlParts) && is_bool($urlParts))) {
			    $wasntReadyCount++;
			    $the_permalink = $this->urlMatcher->getPermalink($idInt, $rowType);
			    $the_permalink = $this->f->normalizeUrlString($the_permalink);
			    $urlParts = parse_url($the_permalink);
			}

			abj_service('request_context')->debug_info = 'Likely match IDs processing permalink: ' .
				$the_permalink . ', $wasntReadyCount: ' . $wasntReadyCount;

			if (!is_array($urlParts) || !array_key_exists('path', $urlParts)) {
				continue;
			}
			if (is_string($the_permalink)) {
				$observedPermalinksById[$idInt] = $the_permalink;
			}
			$existingPageURL = $this->logic->removeHomeDirectory($urlParts['path']);
			$urlParts = null;

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
				array_push($idsWithWordsInCommon, $id);
				$lengthOfTheLongestWordInCommon = max(array_map(array($this->f,'strlen'), $wordsInCommon));
				$maxDist = $maxDist - $lengthOfTheLongestWordInCommon;
			}

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

			$row = array_pop($currentBatch);
			if ($row == null) {
				$maxAcceptableDistance = $this->getMaxAcceptableDistance($maxDistances, $onlyNeedThisManyPages);

            	$currentBatch = $this->publishedPostsProvider->getNextBatch(
            		$requestedURLCleanedLength, 1000, $maxAcceptableDistance);
				$row = array_pop($currentBatch);
			}
		}
		abj_service('request_context')->debug_info = '';

		if ($wasntReadyCount > 0) {
			$this->logger->infoMessage("The permalink cache wasn't ready for " . $wasntReadyCount . " IDs.");
		}

		$candidateIds = $this->pruneAndPrioritizeCandidates(
			$maxDistances, $minDistances, $onlyNeedThisManyPages,
			$idsWithWordsInCommon, $ngramPrefilterApplied, $requestedURLCleaned
		);

		return $this->batchLookupPermalinks(
			array_values(array_unique($candidateIds)), $rowType, $observedPermalinksById
		);
	}

	/**
	 * @param string $rowType
	 * @param array<int, array<string, mixed>>|null $rows
	 * @param string $requestedURLCleaned
	 * @return string 'applied' if prefilter was used, 'early_return' if no matches exist, 'skipped' otherwise
	 */
	private function tryApplyNgramPrefilter(string $rowType, ?array $rows, string $requestedURLCleaned): string {
		if ($rowType != 'pages' || $rows !== null) {
			return 'skipped';
		}

		$cacheCount = $this->ngramFilter->getCacheCount();

		if ($cacheCount < self::NGRAM_MIN_CACHE_ENTRIES) {
			$this->logger->debugMessage(sprintf(
				"N-gram prefilter skipped (gate 1: min entries): count=%d (need %d)",
				$cacheCount, self::NGRAM_MIN_CACHE_ENTRIES
			));
			return 'skipped';
		}
		if (!$this->ngramFilter->isCacheInitialized()) {
			$this->logger->debugMessage(sprintf(
				"N-gram prefilter skipped (gate 2: not initialized): count=%d", $cacheCount
			));
			return 'skipped';
		}
		$coverageRatio = $this->ngramFilter->getCacheCoverageRatio();
		if ($coverageRatio < self::NGRAM_MIN_COVERAGE_RATIO) {
			$this->logger->debugMessage(sprintf(
				"N-gram prefilter skipped (gate 3: low coverage): ratio=%.2f (need %.2f)",
				$coverageRatio, self::NGRAM_MIN_COVERAGE_RATIO
			));
			return 'skipped';
		}

		$similarPages = $this->ngramFilter->findSimilarPages(
			$requestedURLCleaned, self::NGRAM_PREFILTER_THRESHOLD, self::NGRAM_PREFILTER_MAX_CANDIDATES
		);

		if (!empty($similarPages) && $this->publishedPostsProvider !== null) {
			$candidateIds = array_keys($similarPages);
			$this->publishedPostsProvider->resetBatch();
			$this->publishedPostsProvider->restrictToIds($candidateIds);
			$this->logger->debugMessage(sprintf(
				"N-gram prefilter: Restricted to %d candidates (cache has %d entries, coverage=%.2f)",
				count($candidateIds), $cacheCount, $coverageRatio
			));
			return 'applied';
		}

		if ($this->skipNgramGate4) {
			$this->logger->debugMessage(
				"N-gram prefilter: zero candidates at Dice >= 0.3 \xe2\x80\x94 skipNgramGate4 is set, falling through to full scan"
			);
			return 'skipped';
		}

		$this->logger->debugMessage(
			"N-gram prefilter: zero candidates at Dice >= 0.3 \xe2\x80\x94 no similar pages exist, returning early"
		);
		return 'early_return';
	}

	/**
	 * @param array<int, array<int, mixed>> $maxDistances
	 * @param array<int, array<int, mixed>> $minDistances
	 * @param int $onlyNeedThisManyPages
	 * @param array<int, mixed> $idsWithWordsInCommon
	 * @param bool $ngramPrefilterApplied
	 * @param string $requestedURLCleaned
	 * @return array<int, mixed>
	 */
	private function pruneAndPrioritizeCandidates(
		array $maxDistances, array $minDistances, int $onlyNeedThisManyPages,
		array $idsWithWordsInCommon, bool $ngramPrefilterApplied, string $requestedURLCleaned
	): array {
		$pagesSeenSoFar = 0;
		$maxDistFound = self::MAX_LIKELY_DISTANCE;
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= self::MAX_LIKELY_DISTANCE; $currentDistanceIndex++) {
			$pagesSeenSoFar += sizeof($maxDistances[$currentDistanceIndex]);
			if ($pagesSeenSoFar >= $onlyNeedThisManyPages) {
				$maxDistFound = $currentDistanceIndex;
				break;
			}
		}

		$listOfIDsToReturn = array();
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= $maxDistFound; $currentDistanceIndex++) {
			$listOfMinDistanceIDs = $minDistances[$currentDistanceIndex];
			$listOfIDsToReturn = array_merge($listOfIDsToReturn, $listOfMinDistanceIDs);
		}

		$idsWithWords = array_intersect($listOfIDsToReturn, $idsWithWordsInCommon);
		$idsWithoutWords = array_diff($listOfIDsToReturn, $idsWithWordsInCommon);
		$listOfIDsToReturn = array_merge($idsWithWords, $idsWithoutWords);

		$beforeNGramCount = count($listOfIDsToReturn);
		if (!$ngramPrefilterApplied
			&& $beforeNGramCount > self::NGRAM_SECONDARY_MIN_CANDIDATES
			&& $this->ngramFilter->getCacheCount() >= self::NGRAM_MIN_CACHE_ENTRIES
			&& $this->ngramFilter->isCacheInitialized()
			&& $this->ngramFilter->getCacheCoverageRatio() >= self::NGRAM_MIN_COVERAGE_RATIO) {
			$similarPages = $this->ngramFilter->findSimilarPages(
				$requestedURLCleaned,
				self::NGRAM_SECONDARY_THRESHOLD,
				min($beforeNGramCount, self::NGRAM_SECONDARY_MAX_CANDIDATES)
			);
			if (!empty($similarPages)) {
				$ngramFilteredIDs = array_keys($similarPages);
				$listOfIDsToReturn = array_intersect($listOfIDsToReturn, $ngramFilteredIDs);
				usort($listOfIDsToReturn, function($a, $b) use ($similarPages) {
					$simA = isset($similarPages[$a]) ? $similarPages[$a] : 0;
					$simB = isset($similarPages[$b]) ? $similarPages[$b] : 0;
					return $simB <=> $simA;
				});
				$this->logger->debugMessage(sprintf(
					"N-gram filter (secondary): %d to %d candidates (%.1f%% reduction)",
					$beforeNGramCount, count($listOfIDsToReturn),
					100 * (1 - count($listOfIDsToReturn) / max(1, $beforeNGramCount))
				));
			}
		}

		if (count($listOfIDsToReturn) > 300 && count($idsWithWordsInCommon) >= $onlyNeedThisManyPages) {
			$maybeOKguesses = array_intersect($listOfIDsToReturn, $idsWithWordsInCommon);
			return (count($maybeOKguesses) >= $onlyNeedThisManyPages)
				? $maybeOKguesses : $idsWithWordsInCommon;
		}
		return $listOfIDsToReturn;
	}

	/**
	 * @param array<int, mixed> $ids
	 * @param string $rowType
	 * @param array<int, string> $observedPermalinksById
	 * @return array<int|string, string>
	 */
	private function batchLookupPermalinks(array $ids, string $rowType, array $observedPermalinksById = array()): array {
		if (empty($ids)) {
			return [];
		}
		$result = [];
		if ($rowType === 'pages' || $rowType === 'image') {
			$intIds = array_map(function($v) { return is_scalar($v) ? (int)$v : 0; }, $ids);
			$rows = $this->contentRepository->getPermalinksByIds($intIds);
			foreach ($rows as $row) {
				$row = (array)$row;
				if (isset($row['id'], $row['url']) && is_string($row['url'])) {
					$result[(int)$row['id']] = $this->f->normalizeUrlString($row['url']);
				}
			}
			foreach ($intIds as $id) {
				if (!isset($result[$id]) && isset($observedPermalinksById[$id])) {
					$result[$id] = $this->f->normalizeUrlString($observedPermalinksById[$id]);
				}
			}
		} else {
			foreach ($ids as $id) {
				$idInt = is_scalar($id) ? (int)$id : 0;
				$permalink = $this->urlMatcher->getPermalink($idInt, $rowType);
				if (is_string($permalink) && $permalink !== '') {
					$result[$id] = $permalink;
				}
			}
		}
		return $result;
	}

	/**
	 * @param array<int, array<int, mixed>> $maxDistances
	 * @param int $onlyNeedThisManyPages
	 * @return int
	 */
	function getMaxAcceptableDistance(array $maxDistances, int $onlyNeedThisManyPages): int {
		$pagesSeenSoFar = 0;
		$currentDistanceIndex = 0;
		$maxDistFound = self::MAX_LIKELY_DISTANCE;
		for ($currentDistanceIndex = 0; $currentDistanceIndex <= self::MAX_LIKELY_DISTANCE; $currentDistanceIndex++) {
			$pagesSeenSoFar += sizeof($maxDistances[$currentDistanceIndex]);

			if ($pagesSeenSoFar >= $onlyNeedThisManyPages) {
				$maxDistFound = $currentDistanceIndex;
				break;
			}
		}

		$acceptableDistance = (int)($maxDistFound * 1.1);
		return $acceptableDistance;
	}

    /**
	 * @param string $str1
	 * @param string $str2
	 * @return int
	 * @throws Exception
	 */
	function customLevenshtein($str1, $str2) {
		if ($this->enablePerformanceCounters) {
			$this->levenshteinCallCount++;
		}
	    abj_service('request_context')->debug_info = 'customLevenshtein. str1: ' . esc_html($str1) . ', str2: ' . esc_html($str2);

	    $RowLen = $this->f->strlen($str1);
	    $ColLen = $this->f->strlen($str2);
		$cost = 0;

		if (max($RowLen, $ColLen) > ABJ404_MAX_URL_LENGTH) {
            throw new \Exception("Maximum string length in customLevenshtein is " . // allow-raw-error: assertion, should never reach user
            	ABJ404_MAX_URL_LENGTH . ". Yours is " . max($RowLen, $ColLen) . ".");
		}

		if (strlen($str1) <= 255 && strlen($str2) <= 255) {
			return levenshtein($str1, $str2);
		}

		if ($RowLen == 0) {
			return $ColLen;
		} else if ($ColLen == 0) {
			return $RowLen;
		}

		$chars1 = mb_str_split($str1, 1, 'UTF-8');
		$chars2 = mb_str_split($str2, 1, 'UTF-8');

		$v0 = array_fill(0, $RowLen + 1, 0);
		$v1 = array_fill(0, $RowLen + 1, 0);

		for ($RowIdx = 1; $RowIdx <= $RowLen; $RowIdx++) {
			$v0[$RowIdx] = $RowIdx;
		}

		for ($ColIdx = 1; $ColIdx <= $ColLen; $ColIdx++) {
			$v1[0] = $ColIdx;

			for ($RowIdx = 1; $RowIdx <= $RowLen; $RowIdx++) {
			    $cost = ($chars1[$RowIdx - 1] === $chars2[$ColIdx - 1]) ? 0 : 1;
			    $v1[$RowIdx] = min($v0[$RowIdx] + 1, $v1[$RowIdx - 1] + 1, $v0[$RowIdx - 1] + $cost);
			}

			$vTmp = $v0;
			$v0 = $v1;
			$v1 = $vTmp;
		}

		abj_service('request_context')->debug_info = 'Cleared after customLevenshtein.';
		return $v0[$RowLen];
	}

}
