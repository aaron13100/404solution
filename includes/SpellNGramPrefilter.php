<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies N-gram cache health gates before expensive spell-candidate scans.
 */
class ABJ_404_Solution_SpellNGramPrefilter {

	const NGRAM_PREFILTER_THRESHOLD = 0.3;
	const NGRAM_PREFILTER_MAX_CANDIDATES = 500;
	const NGRAM_MIN_CACHE_ENTRIES = 50;
	const NGRAM_SECONDARY_THRESHOLD = 0.4;
	const NGRAM_SECONDARY_MAX_CANDIDATES = 100;
	const NGRAM_MIN_COVERAGE_RATIO = 0.8;
	const NGRAM_SECONDARY_MIN_CANDIDATES = 50;

	/** @var ABJ_404_Solution_NGramFilter */
	private $ngramFilter;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/**
	 * @param ABJ_404_Solution_NGramFilter $ngramFilter
	 * @param ABJ_404_Solution_Logging $logger
	 */
	public function __construct($ngramFilter, $logger) {
		$this->ngramFilter = $ngramFilter;
		$this->logger = $logger;
	}

	/**
	 * @param string $rowType
	 * @param array<int, array<string, mixed>>|null $rows
	 * @param string $requestedURLCleaned
	 * @param ABJ_404_Solution_PublishedPostsProvider|null $publishedPostsProvider
	 * @param bool $skipNgramGate4
	 * @return string 'applied' if prefilter was used, 'early_return' if no matches exist, 'skipped' otherwise
	 */
	public function tryApply(
		string $rowType,
		?array $rows,
		string $requestedURLCleaned,
		?ABJ_404_Solution_PublishedPostsProvider $publishedPostsProvider,
		bool $skipNgramGate4
	): string {
		if ($rowType != 'pages' || $rows !== null) {
			return 'skipped';
		}

		$cacheCount = $this->ngramFilter->getCacheCount();

		if ($cacheCount < self::NGRAM_MIN_CACHE_ENTRIES) {
			$this->logger->debugMessage(sprintf(
				"N-gram prefilter skipped (gate 1: min entries): count=%d (need %d)",
				$cacheCount,
				self::NGRAM_MIN_CACHE_ENTRIES
			));
			return 'skipped';
		}
		if (!$this->ngramFilter->isCacheInitialized()) {
			$this->logger->debugMessage(sprintf(
				"N-gram prefilter skipped (gate 2: not initialized): count=%d",
				$cacheCount
			));
			return 'skipped';
		}
		$coverageRatio = $this->ngramFilter->getCacheCoverageRatio();
		if ($coverageRatio < self::NGRAM_MIN_COVERAGE_RATIO) {
			$this->logger->debugMessage(sprintf(
				"N-gram prefilter skipped (gate 3: low coverage): ratio=%.2f (need %.2f)",
				$coverageRatio,
				self::NGRAM_MIN_COVERAGE_RATIO
			));
			return 'skipped';
		}

		$similarPages = $this->ngramFilter->findSimilarPages(
			$requestedURLCleaned,
			self::NGRAM_PREFILTER_THRESHOLD,
			self::NGRAM_PREFILTER_MAX_CANDIDATES
		);

		if (!empty($similarPages) && $publishedPostsProvider !== null) {
			$candidateIds = array_keys($this->similarityByScalarId($similarPages));
			$publishedPostsProvider->resetBatch();
			$publishedPostsProvider->restrictToIds($candidateIds);
			$this->logger->debugMessage(sprintf(
				"N-gram prefilter: Restricted to %d candidates (cache has %d entries, coverage=%.2f)",
				count($candidateIds),
				$cacheCount,
				$coverageRatio
			));
			return 'applied';
		}

		if ($skipNgramGate4) {
			$this->logger->debugMessage(
				"N-gram prefilter: zero candidates at Dice >= 0.3 - skipNgramGate4 is set, falling through to full scan"
			);
			return 'skipped';
		}

		$this->logger->debugMessage(
			"N-gram prefilter: zero candidates at Dice >= 0.3 - no similar pages exist, returning early"
		);
		return 'early_return';
	}

	/**
	 * @param array<int, mixed> $candidateIds
	 * @param bool $ngramPrefilterApplied
	 * @param string $requestedURLCleaned
	 * @return array<int, int|string>
	 */
	public function applySecondaryFilter(array $candidateIds, bool $ngramPrefilterApplied, string $requestedURLCleaned): array {
			$beforeNGramCount = count($candidateIds);
		if ($ngramPrefilterApplied
			|| $beforeNGramCount <= self::NGRAM_SECONDARY_MIN_CANDIDATES
			|| $this->ngramFilter->getCacheCount() < self::NGRAM_MIN_CACHE_ENTRIES
			|| !$this->ngramFilter->isCacheInitialized()
			|| $this->ngramFilter->getCacheCoverageRatio() < self::NGRAM_MIN_COVERAGE_RATIO) {
			return $this->normalizeScalarIds($candidateIds);
		}

		$similarPages = $this->ngramFilter->findSimilarPages(
			$requestedURLCleaned,
			self::NGRAM_SECONDARY_THRESHOLD,
			min($beforeNGramCount, self::NGRAM_SECONDARY_MAX_CANDIDATES)
		);
		if (empty($similarPages)) {
			return $this->normalizeScalarIds($candidateIds);
		}

		$similarityById = $this->similarityByScalarId($similarPages);
		$ngramFilteredIDs = array_keys($similarityById);
		$candidateIds = array_intersect($this->normalizeScalarIds($candidateIds), $ngramFilteredIDs);
		usort($candidateIds, function($a, $b) use ($similarityById) {
			$keyA = (string)$a;
			$keyB = (string)$b;
			$simA = isset($similarityById[$keyA]) ? $similarityById[$keyA] : 0;
			$simB = isset($similarityById[$keyB]) ? $similarityById[$keyB] : 0;
			return $simB <=> $simA;
		});
		$this->logger->debugMessage(sprintf(
			"N-gram filter (secondary): %d to %d candidates (%.1f%% reduction)",
			$beforeNGramCount,
			count($candidateIds),
			100 * (1 - count($candidateIds) / max(1, $beforeNGramCount))
		));

		return $candidateIds;
	}

	/**
	 * @param array<int|string, mixed> $similarPages
	 * @return array<string, float>
	 */
	private function similarityByScalarId(array $similarPages): array {
		$normalized = array();
		foreach ($similarPages as $id => $similarity) {
			$normalized[(string)$id] = is_numeric($similarity) ? (float)$similarity : 0.0;
		}
		return $normalized;
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
