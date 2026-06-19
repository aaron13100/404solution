<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scores spell suggestion candidates after likely IDs have been selected.
 */
class ABJ_404_Solution_SpellSuggestionScorer {

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_SpellURLMatcher */
	private $urlMatcher;

	/** @var ABJ_404_Solution_SpellLevenshteinEngine */
	private $levenshteinEngine;

	/** @var array<int, string> */
	private array $separatingCharacters;

	/** @var array<int, string> */
	private array $separatingCharactersForImages;

	/** @var ABJ_404_Solution_TermCandidateSource Sources cats/tags candidate rows (bounded prefilter or full scan). */
	private $termCandidateSource;

	/**
	 * @param ABJ_404_Solution_Functions $functions
	 * @param ABJ_404_Solution_PluginLogic $logic
	 * @param ABJ_404_Solution_Logging $logger
	 * @param ABJ_404_Solution_ContentRepository $contentRepository
	 * @param ABJ_404_Solution_SpellURLMatcher $urlMatcher
	 * @param ABJ_404_Solution_SpellLevenshteinEngine $levenshteinEngine
	 * @param array<int, string> $separatingCharacters
	 * @param array<int, string> $separatingCharactersForImages
	 * @param ABJ_404_Solution_TermCandidateSource|null $termCandidateSource When null, a full-scan-only source is built from $contentRepository.
	 */
	public function __construct(
		$functions, $logic, $logger, $contentRepository, $urlMatcher, $levenshteinEngine,
		array $separatingCharacters, array $separatingCharactersForImages,
		$termCandidateSource = null
	) {
		$this->f = $functions;
		$this->logic = $logic;
		$this->logger = $logger;
		$this->urlMatcher = $urlMatcher;
		$this->levenshteinEngine = $levenshteinEngine;
		$this->separatingCharacters = $separatingCharacters;
		$this->separatingCharactersForImages = $separatingCharactersForImages;
		$this->termCandidateSource = $termCandidateSource instanceof ABJ_404_Solution_TermCandidateSource
			? $termCandidateSource
			: new ABJ_404_Solution_TermCandidateSource($contentRepository);
	}

	/**
	 * @param array<string, string> $permalinks
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspacesCleaned
	 * @return array<string, string>
	 */
	public function matchOnCats(array $permalinks, string $requestedURLCleaned, string $fullURLspacesCleaned): array {
		$rows = $this->termCandidateSource->getCandidateTermRows('category', $requestedURLCleaned);
		$rows = $this->urlMatcher->getOnlyIDandTermID($rows);

		return $this->scoreTaxonomyCandidates(
			$permalinks,
			$requestedURLCleaned,
			$fullURLspacesCleaned,
			'categories',
			ABJ404_TYPE_CAT,
			$rows,
			true
		);
	}

	/**
	 * @param array<string, string> $permalinks
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspacesCleaned
	 * @return array<string, string>
	 */
	public function matchOnTags(array $permalinks, string $requestedURLCleaned, string $fullURLspacesCleaned): array {
		$rows = $this->termCandidateSource->getCandidateTermRows('tag', $requestedURLCleaned);
		$rows = $this->urlMatcher->getOnlyIDandTermID($rows);

		return $this->scoreTaxonomyCandidates(
			$permalinks,
			$requestedURLCleaned,
			$fullURLspacesCleaned,
			'tags',
			ABJ404_TYPE_TAG,
			$rows,
			false
		);
	}

	/**
	 * @param array<string, string> $permalinks
	 * @param string $requestedURLRaw
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspacesCleaned
	 * @param string $rowType
	 * @return array<string, string>
	 */
	public function matchOnPosts(
		array $permalinks,
		string $requestedURLRaw,
		string $requestedURLCleaned,
		string $fullURLspacesCleaned,
		string $rowType
	): array {
		$likelyMatchIDsAndPermalinks = $this->levenshteinEngine->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rowType);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		$this->logger->debugMessage("Found " . count($likelyMatchIDs) . " likely match IDs.");

		$suggestMax = $this->getSuggestMax();
		$topKScores = $this->makeScoreHeap();
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		while (count($likelyMatchIDs) > 0) {
			$id = array_shift($likelyMatchIDs);
			$the_permalink = $likelyMatchIDsAndPermalinks[$id];
			$thePermalinkStr = is_string($the_permalink) ? $the_permalink : '';
			$urlParts = parse_url($thePermalinkStr);
			if (!is_array($urlParts) || !isset($urlParts['path'])) {
				continue;
			}
			$existingPageURL = $this->logic->urlNormalization()->removeHomeDirectory($urlParts['path']);
			$existingPageURLSpaces = $this->f->str_replace($this->separatingCharacters, " ", $existingPageURL);

			$existingPageURLCleaned = $this->urlMatcher->getLastURLPart($existingPageURLSpaces);
			$scoreBasis = $this->f->strlen($existingPageURLCleaned) * 3;
			if ($scoreBasis == 0) {
				continue;
			}

			if ($this->cannotBeatCurrentTopK($topKScores, $suggestMax, $requestedURLCleanedLength, $this->f->strlen($existingPageURLCleaned), $scoreBasis)) {
				continue;
			}

			$levscore = $this->levenshteinEngine->customLevenshtein($requestedURLCleaned, $existingPageURLCleaned);

			if ($fullURLspacesCleaned != '') {
				$tentativeScore = 100 - (($levscore / $scoreBasis) * 100);
				if ($tentativeScore < 95) {
					$levscore = min($levscore, $this->levenshteinEngine->customLevenshtein($fullURLspacesCleaned, $existingPageURLCleaned));
				}
			}

			if ($rowType == 'image') {
				$levscore = $this->scoreImageFilename($levscore, $requestedURLRaw, $existingPageURL, $existingPageURLCleaned);
			}
			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_POST] = number_format($score, 4, '.', '');

			$this->rememberTopScore($topKScores, $suggestMax, $score);
		}

		return $permalinks;
	}

	/**
	 * @param array<string, string> $permalinks
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspacesCleaned
	 * @param string $rowType
	 * @param int|string $typeConstant
	 * @param array<int, array<string, mixed>> $rows
	 * @param bool $compareLastPart
	 * @return array<string, string>
	 */
	private function scoreTaxonomyCandidates(
		array $permalinks,
		string $requestedURLCleaned,
		string $fullURLspacesCleaned,
		string $rowType,
		$typeConstant,
		array $rows,
		bool $compareLastPart
	): array {
		$likelyMatchIDsAndPermalinks = $this->levenshteinEngine->getLikelyMatchIDs(
			$requestedURLCleaned,
			$fullURLspacesCleaned,
			$rowType,
			$rows
		);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		$suggestMax = $this->getSuggestMax();
		$topKScores = $this->makeScoreHeap();
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		foreach ($likelyMatchIDs as $id) {
			$the_permalink = $this->urlMatcher->getPermalink((int)$id, $rowType);
			$urlParts = parse_url(is_string($the_permalink) ? $the_permalink : '');
			if (!is_array($urlParts) || !isset($urlParts['path'])) {
				continue;
			}
			$pathOnly = $this->logic->urlNormalization()->removeHomeDirectory($urlParts['path']);
			$scoreBasis = $this->f->strlen($pathOnly);
			if ($scoreBasis == 0) {
				continue;
			}

			if ($this->cannotBeatCurrentTopK($topKScores, $suggestMax, $requestedURLCleanedLength, $this->f->strlen($pathOnly), $scoreBasis)) {
				continue;
			}

			$levscore = $this->levenshteinEngine->customLevenshtein($requestedURLCleaned, $pathOnly);

			if ($fullURLspacesCleaned != '') {
				$tentativeScore = 100 - (($levscore / $scoreBasis) * 100);
				if ($tentativeScore < 95) {
					$pathOnlySpaces = $this->f->str_replace($this->separatingCharacters, " ", $pathOnly);
					$pathOnlySpaces = trim($this->f->str_replace('/', " ", $pathOnlySpaces));
					$levscore = min($levscore, $this->levenshteinEngine->customLevenshtein($fullURLspacesCleaned, $pathOnlySpaces));
				}
			}

			if ($compareLastPart) {
				$onlyLastPart = $this->urlMatcher->getLastURLPart($pathOnly);
				if ($onlyLastPart != '' && $onlyLastPart != $pathOnly) {
					$levscore = min($levscore, $this->levenshteinEngine->customLevenshtein($requestedURLCleaned, $onlyLastPart));
				}
			}

			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . $typeConstant] = number_format($score, 4, '.', '');

			$this->rememberTopScore($topKScores, $suggestMax, $score);
		}

		return $permalinks;
	}

	private function getSuggestMax(): int {
		$options = abj_service('options_repository')->getOptions();
		$suggestMaxRaw = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		return absint($suggestMaxRaw);
	}

	/** @return SplMinHeap<mixed> */
	private function makeScoreHeap(): SplMinHeap {
		return new SplMinHeap();
	}

	/**
	 * @param SplMinHeap<mixed> $topKScores
	 */
	private function cannotBeatCurrentTopK(SplMinHeap $topKScores, int $suggestMax, int $requestedLength, int $candidateLength, int $scoreBasis): bool {
		if ($topKScores->count() < $suggestMax) {
			return false;
		}

		$worstAcceptableScoreRaw = $topKScores->top();
		if (!is_numeric($worstAcceptableScoreRaw)) {
			return false;
		}
		$worstAcceptableScore = (float)$worstAcceptableScoreRaw;
		$maxAllowedLevenshtein = ((100 - $worstAcceptableScore) * $scoreBasis) / 100;
		$minPossibleDistance = abs($requestedLength - $candidateLength);

		return $minPossibleDistance > $maxAllowedLevenshtein;
	}

	/**
	 * @param SplMinHeap<mixed> $topKScores
	 */
	private function rememberTopScore(SplMinHeap $topKScores, int $suggestMax, float $score): void {
		$topKScores->insert($score);
		if ($topKScores->count() > $suggestMax) {
			$topKScores->extract();
		}
	}

	private function scoreImageFilename(int $levscore, string $requestedURLRaw, string $existingPageURL, string $existingPageURLCleaned): int {
		$strippedImageName = $this->f->regexReplace('(.+)([-]\d{1,5}[x]\d{1,5})([.].+)',
				'\\1\\3', $requestedURLRaw);

		if (($strippedImageName != null) && ($strippedImageName != $requestedURLRaw)) {
			$strippedImageName = $this->f->str_replace($this->separatingCharactersForImages, " ", $strippedImageName);
			$levscore = min($levscore, $this->levenshteinEngine->customLevenshtein($strippedImageName, $existingPageURL));

			$strippedImageName = $this->urlMatcher->getLastURLPart($strippedImageName);
			$levscore = min($levscore, $this->levenshteinEngine->customLevenshtein($strippedImageName, $existingPageURLCleaned));
		}

		return $levscore;
	}
}
