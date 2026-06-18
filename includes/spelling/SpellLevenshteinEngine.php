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

	const NGRAM_PREFILTER_THRESHOLD = 0.3;

	const NGRAM_PREFILTER_MAX_CANDIDATES = 500;

	const NGRAM_MIN_CACHE_ENTRIES = 50;

	const NGRAM_SECONDARY_THRESHOLD = 0.4;

	const NGRAM_SECONDARY_MAX_CANDIDATES = 100;

	const NGRAM_MIN_COVERAGE_RATIO = 0.8;

	const NGRAM_SECONDARY_MIN_CANDIDATES = 50;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_ContentRepository */
	private $contentRepository;

	/** @var ABJ_404_Solution_SpellURLMatcher */
	private $urlMatcher;

	/** @var ABJ_404_Solution_SpellNGramPrefilter */
	private $ngramPrefilter;

	/** @var ABJ_404_Solution_SpellCandidatePermalinkLookup */
	private $permalinkLookup;

	/** @var ABJ_404_Solution_SpellLevenshteinEngineDependencies the bundle reused to build a per-call distance ranker */
	private $deps;

	private bool $enablePerformanceCounters = false;

	private bool $skipNgramGate4 = false;

	private int $levenshteinCallCount = 0;

	private int $totalPagesConsidered = 0;

	/** @var ABJ_404_Solution_PublishedPostsProvider|null */
	private ?ABJ_404_Solution_PublishedPostsProvider $publishedPostsProvider = null;

	/**
	 * @param ABJ_404_Solution_SpellLevenshteinEngineDependencies $deps
	 */
	public function __construct(ABJ_404_Solution_SpellLevenshteinEngineDependencies $deps) {
		$this->deps = $deps;
		$this->f = $deps->functions;
		$this->logger = $deps->logger;
		$this->contentRepository = $deps->contentRepository;
		$this->urlMatcher = $deps->urlMatcher;
		$this->ngramPrefilter = new ABJ_404_Solution_SpellNGramPrefilter($deps->ngramFilter, $deps->logger);
		$this->permalinkLookup = new ABJ_404_Solution_SpellCandidatePermalinkLookup($deps->contentRepository, $deps->urlMatcher);
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

		$options = abj_service('options_repository')->getOptions();
		$suggestMaxLikely = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$onlyNeedThisManyPages = min(5 * absint($suggestMaxLikely), 100);

		$ngramPrefilterResult = $this->ngramPrefilter->tryApply(
			$rowType,
			$rows,
			$requestedURLCleaned,
			$this->publishedPostsProvider,
			$this->skipNgramGate4
		);
		if ($ngramPrefilterResult === 'early_return') {
			return array();
		}
		$ngramPrefilterApplied = ($ngramPrefilterResult === 'applied');

		$ranker = new ABJ_404_Solution_SpellCandidateDistanceRanker($this->deps, $this->ngramPrefilter);

		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);
		$fullURLspacesLength = $this->f->strlen($fullURLspaces);

		$userRequestedURLWords = explode(" ", (empty($fullURLspaces) ? $requestedURLCleaned : $fullURLspaces));
		$observedPermalinksById = array();
		$wasntReadyCount = 0;

		if ($this->publishedPostsProvider === null) {
			return array();
		}
		$postsProvider = $this->publishedPostsProvider;
		if (!$ngramPrefilterApplied) {
			$postsProvider->resetBatch();
		}
		if ($rows != null) {
			$postsProvider->useThisData($rows);
		}
		$currentBatch = $postsProvider->getNextBatch($requestedURLCleanedLength);

		$row = array_pop($currentBatch);
		while ($row != null) {
			$row = (array)$row;

			if ($this->enablePerformanceCounters) {
				$this->totalPagesConsidered++;
			}

			$id = $this->extractRowCandidateId($row, $rowType);
			if ($id === null) {
				$row = array_pop($currentBatch);
				continue;
			}
			$idInt = is_scalar($id) ? (int)$id : 0;

			$the_permalink = null;
			$urlPath = null;
			$this->resolveCandidatePermalinkParts(
				$row, $idInt, $rowType, $wasntReadyCount, $the_permalink, $urlPath
			);

			abj_service('request_context')->debug_info = 'Likely match IDs processing permalink: ' .
				$the_permalink . ', $wasntReadyCount: ' . $wasntReadyCount;

			if ($urlPath === null) {
				// Skip this candidate (no parseable path) AND advance to the next
				// row, exactly like the $id === null skip above. A bare `continue`
				// here would re-evaluate the SAME row forever -- a per-request
				// infinite loop that hangs the 404 response until PHP's
				// max_execution_time kills it (the worst "not fast" outcome on a
				// large/diverse site where some candidate has an unparseable URL).
				$row = array_pop($currentBatch);
				continue;
			}
			if (is_string($the_permalink)) {
				$observedPermalinksById[$idInt] = $the_permalink;
			}

			$ranker->score(
				$id, $urlPath, $requestedURLCleanedLength,
				$fullURLspaces, $fullURLspacesLength, $userRequestedURLWords
			);

			$row = array_pop($currentBatch);
			if ($row == null) {
				$maxAcceptableDistance = $ranker->getMaxAcceptableDistance($onlyNeedThisManyPages);

            	$currentBatch = $postsProvider->getNextBatch(
            		$requestedURLCleanedLength, 1000, $maxAcceptableDistance);
				$row = array_pop($currentBatch);
			}
		}
		abj_service('request_context')->debug_info = '';

		if ($wasntReadyCount > 0) {
			$this->logger->infoMessage("The permalink cache wasn't ready for " . $wasntReadyCount . " IDs.");
		}

		$candidateIds = $ranker->prioritize(
			$onlyNeedThisManyPages, $ngramPrefilterApplied, $requestedURLCleaned
		);

		return $this->permalinkLookup->lookup(
			array_values(array_unique($candidateIds)), $rowType, $observedPermalinksById
		);
	}

	/**
	 * Pull the candidate id out of one published-content row, dispatching on
	 * the row type. Returns null when the row carries no usable id (the caller
	 * skips it); throws for an unrecognized row type.
	 *
	 * @param array<mixed, mixed> $row
	 * @param string $rowType
	 * @return mixed the raw candidate id as stored in the row, or null when absent
	 */
	private function extractRowCandidateId(array $row, string $rowType) {
		if ($rowType == 'pages') {
			return $row['id'];

		} else if ($rowType == 'tags') {
			return array_key_exists('term_id', $row) ? $row['term_id'] : null;

		} else if ($rowType == 'categories') {
			return array_key_exists('term_id', $row) ? $row['term_id'] : null;

		} else if ($rowType == 'image') {
			return $row['id'];
		}

		throw new \Exception("Unknown row type ... " . esc_html($rowType)); // allow-raw-error: assertion, should never reach user
	}

	/**
	 * Resolve a candidate's permalink and the path portion of its URL. Prefers
	 * the url carried on the row; falls back to the permalink cache (incrementing
	 * $wasntReadyCount) when the row has no usable url or the row url failed to
	 * parse. The permalink and url path are returned through the by-reference
	 * out-parameters to keep this hot-path step allocation-free. $urlPath is null
	 * when no parseable path could be resolved (the caller then skips the row).
	 *
	 * @param array<mixed, mixed> $row
	 * @param int $idInt
	 * @param string $rowType
	 * @param int $wasntReadyCount incremented by reference on a cache fallback
	 * @param string|null $the_permalink
	 * @param string|null $urlPath
	 * @param-out string|null $the_permalink
	 * @param-out string|null $urlPath
	 */
	private function resolveCandidatePermalinkParts(
		array $row, int $idInt, string $rowType, int &$wasntReadyCount, &$the_permalink, &$urlPath
	): void {
		$the_permalink = null;
		$urlPath = null;
		$urlParts = null;
		if (array_key_exists('url', $row)) {
		    $the_permalink = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
		    $the_permalink = abj_service('sanitizer')->normalizeUrlString($the_permalink);
		    $urlParts = parse_url($the_permalink);

		    if (is_bool($urlParts)) {
		        $this->contentRepository->removeFromPermalinkCache($idInt);
		    }
		}
		if (!array_key_exists('url', $row) || (isset($urlParts) && is_bool($urlParts))) {
		    $wasntReadyCount++;
		    $the_permalink = $this->urlMatcher->getPermalink($idInt, $rowType);
		    $the_permalink = abj_service('sanitizer')->normalizeUrlString($the_permalink);
		    $urlParts = parse_url($the_permalink);
		}

		if (is_array($urlParts) && array_key_exists('path', $urlParts)) {
		    $urlPath = $urlParts['path'];
		}
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
