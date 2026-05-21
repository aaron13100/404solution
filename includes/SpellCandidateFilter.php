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

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_ContentRepository */
	private $contentRepository;

	/** @var ABJ_404_Solution_SpellURLMatcher */
	private $urlMatcher;

	/** @var ABJ_404_Solution_SpellLevenshteinEngine */
	private $levenshteinEngine;

	/** @var ABJ_404_Solution_SpellPostListeners */
	private $postListeners;

	/** @var string|int|null */
	private $custom404PageID;

	/** @var array<int, string> */
	private array $separatingCharacters;

	/** @var array<int, string> */
	private array $separatingCharactersForImages;

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
		$this->logic = $logic;
		$this->logger = $logger;
		$this->contentRepository = $contentRepository;
		$this->urlMatcher = $urlMatcher;
		$this->levenshteinEngine = $levenshteinEngine;
		$this->postListeners = $postListeners;
		$this->custom404PageID = $custom404PageID;
		$this->separatingCharacters = $separatingCharacters;
		$this->separatingCharactersForImages = $separatingCharactersForImages;
	}

    /**
	 * @param string $requestedURLRaw
	 * @param string $includeCats
	 * @param string $includeTags
	 * @return array<int, mixed>
	 */
	function findMatchingPosts(string $requestedURLRaw, string $includeCats = '1', string $includeTags = '1') {

		$options = $this->logic->getOptions();
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
		$excludePagesJsonRaw = isset($options['excludePages[]']) ? $options['excludePages[]'] : '';
		$excludePagesJson = is_string($excludePagesJsonRaw) ? $excludePagesJsonRaw : '';
		if (trim($excludePagesJson) == '' && $this->custom404PageID == null) {
			return $permalinks;
		}

		$excludePages = json_decode($excludePagesJson);
		if (!is_array($excludePages)) {
			$excludePages = array($excludePages);
		}

		if ($this->custom404PageID != null) {
			array_push($excludePages, $this->custom404PageID);
		}

		for ($i = 0; $i < count($excludePages); $i++) {
			$excludePage = $excludePages[$i];
			if ($excludePage == null || trim($excludePage) == '') {
				continue;
			}
			unset($permalinks[(string)$excludePage]);
		}

		return $permalinks;
	}

	/**
     * @param array<string, mixed> $options
     * @param array<string, string> $permalinks
     * @param int $maxCacheCount
     * @return array<string, string>
     */
    function removeExcludedPagesWithRegex(array $options, array $permalinks, int $maxCacheCount): array {
        if (!isset($options['suggest_regex_exclusions_usable']) ||
            !is_array($options['suggest_regex_exclusions_usable']) ||
            empty($options['suggest_regex_exclusions_usable'])) {
            return $permalinks;
        }

		$suggestionsKeptSoFar = 0;
        $regexExclusions = $options['suggest_regex_exclusions_usable'];

        $keys_to_check = array_keys($permalinks);

        foreach ($keys_to_check as $key) {
            if (!array_key_exists($key, $permalinks)) {
                continue;
            }

            $keyParts = explode('|', $key);
            if (count($keyParts) !== 2 || !is_numeric($keyParts[0])) {
                $this->logger->debugMessage("Skipping invalid key format in removeExcludedPagesWithRegex: " . $key);
                continue;
            }

            $id = (int)$keyParts[0];
            $typeConstant = $keyParts[1];

            $rowTypeString = $this->mapTypeConstantToString($typeConstant);
            if ($rowTypeString === null) {
                $this->logger->debugMessage("Skipping unknown type constant in removeExcludedPagesWithRegex: " . $typeConstant . " for key: " . $key);
                continue;
            }

            $urlOfPage = $this->urlMatcher->getPermalink($id, $rowTypeString);
            if ($urlOfPage === null || trim($urlOfPage) === '') {
                $this->logger->debugMessage("Skipping null/empty URL for key in removeExcludedPagesWithRegex: " . $key);
                continue;
            }

            $urlParts = parse_url($urlOfPage);
            if (!is_array($urlParts) || !isset($urlParts['path'])) {
                 $this->logger->debugMessage("Skipping URL that failed parse_url for key in removeExcludedPagesWithRegex: " . $key . ", URL: " . esc_url($urlOfPage));
                 continue;
            }
            $pathOnly = $this->logic->removeHomeDirectory($urlParts['path']);
             if ( $pathOnly !== '' && substr($pathOnly, 0, 1) !== '/' ) {
                $pathOnly = '/' . $pathOnly;
             }
             if ( $pathOnly === '' ) {
                 $pathOnly = '/';
             }

            $stringToMatch = $pathOnly;

			$kept = true;
            foreach ($regexExclusions as $pattern) {
                $patternToExcludeNoSlashes = stripslashes($pattern);
                $matches = array();

                if ($this->f->regexMatch($patternToExcludeNoSlashes, $stringToMatch, $matches)) {
                    unset($permalinks[$key]);
                    $this->logger->debugMessage("Regex excluded suggestion. Key: " . $key .
                        ", Path: '" . esc_html($stringToMatch) . "', Pattern: '" . esc_html($patternToExcludeNoSlashes) . "'");
					$kept = false;
                    break;
                }
            }

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
     * @param mixed $typeConstant
     * @return string|null
     */
    private function mapTypeConstantToString($typeConstant) {
        if (!defined('ABJ404_TYPE_POST')) define('ABJ404_TYPE_POST', 1);
        if (!defined('ABJ404_TYPE_CAT')) define('ABJ404_TYPE_CAT', 2);
        if (!defined('ABJ404_TYPE_TAG')) define('ABJ404_TYPE_TAG', 3);

        $typeConstantStr = is_scalar($typeConstant) ? (string)$typeConstant : '';
        switch ($typeConstantStr) {
            case ABJ404_TYPE_POST:
                return 'pages';
            case ABJ404_TYPE_TAG:
                return 'tags';
            case ABJ404_TYPE_CAT:
                return 'categories';
            default:
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

		$rows = $this->contentRepository->getPublishedCategories();
		$rows = $this->urlMatcher->getOnlyIDandTermID($rows);

		$likelyMatchIDsAndPermalinks = $this->levenshteinEngine->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, 'categories', $rows);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		$options = $this->logic->getOptions();
		$suggestMaxRaw = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$suggestMax = absint($suggestMaxRaw);
		$topKScores = new SplMinHeap();
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		foreach ($likelyMatchIDs as $id) {
			$the_permalink = $this->urlMatcher->getPermalink((int)$id, 'categories');
			$urlParts = parse_url(is_string($the_permalink) ? $the_permalink : '');
			if (!is_array($urlParts) || !isset($urlParts['path'])) {
				continue;
			}
			$pathOnly = $this->logic->removeHomeDirectory($urlParts['path']);
			$scoreBasis = $this->f->strlen($pathOnly);
			if ($scoreBasis == 0) {
				continue;
			}

			if ($topKScores->count() >= $suggestMax) {
				$worstAcceptableScore = $topKScores->top();

				$maxAllowedLevenshtein = ((100 - $worstAcceptableScore) * $scoreBasis) / 100;
				$pathOnlyLength = $this->f->strlen($pathOnly);
				$minPossibleDistance = abs($requestedURLCleanedLength - $pathOnlyLength);

				if ($minPossibleDistance > $maxAllowedLevenshtein) {
					continue;
				}
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

			$onlyLastPart = $this->urlMatcher->getLastURLPart($pathOnly);
			if ($onlyLastPart != '' && $onlyLastPart != $pathOnly) {
				$levscore = min($levscore, $this->levenshteinEngine->customLevenshtein($requestedURLCleaned, $onlyLastPart));
			}

			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_CAT] = number_format($score, 4, '.', '');

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

		$rows = $this->contentRepository->getPublishedTags();
		$rows = $this->urlMatcher->getOnlyIDandTermID($rows);

		$likelyMatchIDsAndPermalinks = $this->levenshteinEngine->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, 'tags', $rows);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		$options = $this->logic->getOptions();
		$suggestMaxRawT = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$suggestMax = absint($suggestMaxRawT);
		$topKScores = new SplMinHeap();
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		foreach ($likelyMatchIDs as $id) {
			$the_permalink = $this->urlMatcher->getPermalink((int)$id, 'tags');
			$urlParts = parse_url(is_string($the_permalink) ? $the_permalink : '');
			if (!is_array($urlParts) || !isset($urlParts['path'])) {
				continue;
			}
			$pathOnly = $this->logic->removeHomeDirectory($urlParts['path']);
			$scoreBasis = $this->f->strlen($pathOnly);
			if ($scoreBasis == 0) {
				continue;
			}

			if ($topKScores->count() >= $suggestMax) {
				$worstAcceptableScore = $topKScores->top();

				$maxAllowedLevenshtein = ((100 - $worstAcceptableScore) * $scoreBasis) / 100;
				$pathOnlyLength = $this->f->strlen($pathOnly);
				$minPossibleDistance = abs($requestedURLCleanedLength - $pathOnlyLength);

				if ($minPossibleDistance > $maxAllowedLevenshtein) {
					continue;
				}
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
			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_TAG] = number_format($score, 4, '.', '');

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

		$likelyMatchIDsAndPermalinks = $this->levenshteinEngine->getLikelyMatchIDs($requestedURLCleaned, $fullURLspacesCleaned, $rowType);
		$likelyMatchIDs = array_keys($likelyMatchIDsAndPermalinks);

		$this->logger->debugMessage("Found " . count($likelyMatchIDs) . " likely match IDs.");

		$options = $this->logic->getOptions();
		$suggestMaxRawP = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
		$suggestMax = absint($suggestMaxRawP);
		$topKScores = new SplMinHeap();
		$requestedURLCleanedLength = $this->f->strlen($requestedURLCleaned);

		while (count($likelyMatchIDs) > 0) {
			$id = array_shift($likelyMatchIDs);

			$the_permalink = $likelyMatchIDsAndPermalinks[$id];
			$thePermalinkStr = is_string($the_permalink) ? $the_permalink : '';
			$urlParts = parse_url($thePermalinkStr);
			if (!is_array($urlParts) || !isset($urlParts['path'])) {
				continue;
			}
			$existingPageURL = $this->logic->removeHomeDirectory($urlParts['path']);
			$existingPageURLSpaces = $this->f->str_replace($this->separatingCharacters, " ", $existingPageURL);

			$existingPageURLCleaned = $this->urlMatcher->getLastURLPart($existingPageURLSpaces);
			$scoreBasis = $this->f->strlen($existingPageURLCleaned) * 3;
			if ($scoreBasis == 0) {
				continue;
			}

			if ($topKScores->count() >= $suggestMax) {
				$worstAcceptableScore = $topKScores->top();

				$maxAllowedLevenshtein = ((100 - $worstAcceptableScore) * $scoreBasis) / 100;

				$existingURLCleanedLength = $this->f->strlen($existingPageURLCleaned);
				$minPossibleDistance = abs($requestedURLCleanedLength - $existingURLCleanedLength);

				if ($minPossibleDistance > $maxAllowedLevenshtein) {
					continue;
				}
			}

			$levscore = $this->levenshteinEngine->customLevenshtein($requestedURLCleaned, $existingPageURLCleaned);

			if ($fullURLspacesCleaned != '') {
				$tentativeScore = 100 - (($levscore / $scoreBasis) * 100);
				if ($tentativeScore < 95) {
					$levscore = min($levscore, $this->levenshteinEngine->customLevenshtein($fullURLspacesCleaned, $existingPageURLCleaned));
				}
			}

			if ($rowType == 'image') {
				$strippedImageName = $this->f->regexReplace('(.+)([-]\d{1,5}[x]\d{1,5})([.].+)',
						'\\1\\3', $requestedURLRaw);

				if (($strippedImageName != null) && ($strippedImageName != $requestedURLRaw)) {
					$strippedImageName = $this->f->str_replace($this->separatingCharactersForImages, " ", $strippedImageName);
					$levscore = min($levscore, $this->levenshteinEngine->customLevenshtein($strippedImageName, $existingPageURL));

					$strippedImageName = $this->urlMatcher->getLastURLPart($strippedImageName);
					$levscore = min($levscore, $this->levenshteinEngine->customLevenshtein($strippedImageName, $existingPageURLCleaned));
				}
			}
			$score = 100 - (($levscore / $scoreBasis) * 100);
			$permalinks[$id . "|" . ABJ404_TYPE_POST] = number_format($score, 4, '.', '');

			$topKScores->insert($score);
			if ($topKScores->count() > $suggestMax) {
				$topKScores->extract();
			}
		}

		return $permalinks;
	}

}
