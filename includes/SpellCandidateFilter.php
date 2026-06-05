<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Candidate filtering, scoring, and matching on posts/tags/categories
 * for the spell-checking subsystem.
 *
 * Extracted from SpellCheckerTrait_CandidateFiltering as a standalone class
 * with explicit dependency injection.
 */
class ABJ_404_Solution_SpellCandidateFilter {

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_ContentRepository */
	private $contentRepository;

	/** @var ABJ_404_Solution_SpellURLMatcher */
	private $urlMatcher;

	/** @var ABJ_404_Solution_SpellLevenshteinEngine */
	private $levenshteinEngine;

	/** @var ABJ_404_Solution_SpellPostListeners */
	private $postListeners;

	/** @var ABJ_404_Solution_SpellSuggestionExclusionPolicy */
	private $exclusionPolicy;

	/** @var ABJ_404_Solution_SpellSuggestionScorer */
	private $suggestionScorer;

	/** @var array<int, string> */
	private array $separatingCharacters;

	/**
	 * @param ABJ_404_Solution_Functions $functions
	 * @param ABJ_404_Solution_PluginLogic $logic
	 * @param ABJ_404_Solution_Logging $logger
	 * @param ABJ_404_Solution_ContentRepository $contentRepository
	 * @param ABJ_404_Solution_SpellURLMatcher $urlMatcher
	 * @param ABJ_404_Solution_SpellLevenshteinEngine $levenshteinEngine
	 * @param ABJ_404_Solution_SpellPostListeners $postListeners
	 * @param string|int|null $custom404PageID
	 * @param array<int, string> $separatingCharacters
	 * @param array<int, string> $separatingCharactersForImages
	 */
	public function __construct(
		$functions, $logic, $logger, $contentRepository,
		$urlMatcher, $levenshteinEngine, $postListeners,
		$custom404PageID, array $separatingCharacters, array $separatingCharactersForImages
	) {
		$this->f = $functions;
		$this->contentRepository = $contentRepository;
		$this->urlMatcher = $urlMatcher;
		$this->levenshteinEngine = $levenshteinEngine;
		$this->postListeners = $postListeners;
		$this->separatingCharacters = $separatingCharacters;
		$this->exclusionPolicy = new ABJ_404_Solution_SpellSuggestionExclusionPolicy(
			$functions, $logic, $logger, $urlMatcher, $custom404PageID
		);
		$this->suggestionScorer = new ABJ_404_Solution_SpellSuggestionScorer(
			$functions, $logic, $logger, $contentRepository, $urlMatcher, $levenshteinEngine,
			$separatingCharacters, $separatingCharactersForImages
		);
	}

    /**
	 * @param string $requestedURLRaw
	 * @param string $includeCats
	 * @param string $includeTags
	 * @return array<int, mixed>
	 */
	function findMatchingPosts(string $requestedURLRaw, string $includeCats = '1', string $includeTags = '1') {

		$options = abj_service('options_repository')->getOptions();
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
		$requestedURLCleaned = $this->urlMatcher->getLastURLPart($requestedURLSpaces);
		$fullURLspacesCleaned = $this->f->str_replace('/', " ", $requestedURLSpaces);
		if ($fullURLspacesCleaned == $requestedURLCleaned) {
			$fullURLspacesCleaned = '';
		}

		$this->postListeners->initializePublishedPostsProvider();
		$this->levenshteinEngine->setPublishedPostsProvider(
			$this->postListeners->getPublishedPostsProvider()
		);

		$rowType = 'pages';
		$permalinks = array();
		$permalinks = $this->matchOnPosts($permalinks, $requestedURLRaw, $requestedURLCleaned,
				$fullURLspacesCleaned, $rowType);

		if ($includeTags == "1") {
			$permalinks = $this->matchOnTags($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, 'tags');
		}

		if ($includeCats == "1") {
			$permalinks = $this->matchOnCats($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, 'categories');
		}

		$permalinks = $this->removeExcludedPages($options, $permalinks);

		arsort($permalinks);

		$permalinks = $this->removeExcludedPagesWithRegex($options, $permalinks, $maxCacheCount);

		$permalinks = array_splice($permalinks, 0, $maxCacheCount);

		$returnValue = array($permalinks,$rowType);
		$this->contentRepository->storeSpellingPermalinksToCache($requestedURLRaw, $returnValue);
		$ctx = abj_service('request_context');
		$ctx->permalinks_found = (string)json_encode($returnValue);
		$ctx->permalinks_kept = (string)json_encode($permalinks);

		return $returnValue;
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, string> $permalinks
	 * @return array<string, string>
	 */
	function removeExcludedPages(array $options, array $permalinks): array {
		return $this->exclusionPolicy->removeExcludedPages($options, $permalinks);
	}

	/**
     * @param array<string, mixed> $options
     * @param array<string, string> $permalinks
     * @param int $maxCacheCount
     * @return array<string, string>
     */
    function removeExcludedPagesWithRegex(array $options, array $permalinks, int $maxCacheCount): array {
		return $this->exclusionPolicy->removeExcludedPagesWithRegex($options, $permalinks, $maxCacheCount);
    }

    /**
     * @param mixed $typeConstant
     * @return string|null
     */
    public function mapTypeConstantToString($typeConstant) {
        return $this->exclusionPolicy->mapTypeConstantToString($typeConstant);
    }

	/**
	 * @param array<string, string> $permalinks
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspacesCleaned
	 * @param string $rowType
	 * @return array<string, string>
	 */
	function matchOnCats(array $permalinks, string $requestedURLCleaned, string $fullURLspacesCleaned, string $rowType): array {
		return $this->suggestionScorer->matchOnCats($permalinks, $requestedURLCleaned, $fullURLspacesCleaned);
	}

	/**
	 * @param array<string, string> $permalinks
	 * @param string $requestedURLCleaned
	 * @param string $fullURLspacesCleaned
	 * @param string $rowType
	 * @return array<string, string>
	 */
	function matchOnTags(array $permalinks, string $requestedURLCleaned, string $fullURLspacesCleaned, string $rowType): array {
		return $this->suggestionScorer->matchOnTags($permalinks, $requestedURLCleaned, $fullURLspacesCleaned);
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
		return $this->suggestionScorer->matchOnPosts($permalinks, $requestedURLRaw, $requestedURLCleaned, $fullURLspacesCleaned, $rowType);
	}

}
