<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies configured page and regex exclusions to spell suggestions.
 */
class ABJ_404_Solution_SpellSuggestionExclusionPolicy {

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_SpellURLMatcher */
	private $urlMatcher;

	/** @var string|int|null */
	private $custom404PageID;

	/**
	 * @param ABJ_404_Solution_Functions $functions
	 * @param ABJ_404_Solution_PluginLogic $logic
	 * @param ABJ_404_Solution_Logging $logger
	 * @param ABJ_404_Solution_SpellURLMatcher $urlMatcher
	 * @param string|int|null $custom404PageID
	 */
	public function __construct($functions, $logic, $logger, $urlMatcher, $custom404PageID) {
		$this->f = $functions;
		$this->logic = $logic;
		$this->logger = $logger;
		$this->urlMatcher = $urlMatcher;
		$this->custom404PageID = $custom404PageID;
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, string> $permalinks
	 * @return array<string, string>
	 */
	public function removeExcludedPages(array $options, array $permalinks): array {
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
			if (!is_scalar($excludePage)) {
				continue;
			}
			$excludePageKey = trim((string)$excludePage);
			if ($excludePageKey == '') {
				continue;
			}
			unset($permalinks[$excludePageKey]);
		}

		return $permalinks;
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, string> $permalinks
	 * @param int $maxCacheCount
	 * @return array<string, string>
	 */
	public function removeExcludedPagesWithRegex(array $options, array $permalinks, int $maxCacheCount): array {
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

			$stringToMatch = $this->pathForRegexExclusion($id, $typeConstant, $key);
			if ($stringToMatch === null) {
				continue;
			}

			$kept = true;
			if ($this->matchesAnyRegexExclusion(array_values($regexExclusions), $stringToMatch, $key)) {
				unset($permalinks[$key]);
				$kept = false;
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
	private function pathForRegexExclusion(int $id, $typeConstant, string $key): ?string {
		$rowTypeString = $this->mapTypeConstantToString($typeConstant);
		if ($rowTypeString === null) {
			$typeConstantForLog = is_scalar($typeConstant) ? (string)$typeConstant : gettype($typeConstant);
			$this->logger->debugMessage("Skipping unknown type constant in removeExcludedPagesWithRegex: " . $typeConstantForLog . " for key: " . $key);
			return null;
		}

		$urlOfPage = $this->urlMatcher->getPermalink($id, $rowTypeString);
		if ($urlOfPage === null || trim($urlOfPage) === '') {
			$this->logger->debugMessage("Skipping null/empty URL for key in removeExcludedPagesWithRegex: " . $key);
			return null;
		}

		$urlParts = parse_url($urlOfPage);
		if (!is_array($urlParts) || !isset($urlParts['path'])) {
			$this->logger->debugMessage("Skipping URL that failed parse_url for key in removeExcludedPagesWithRegex: " . $key . ", URL: " . esc_url($urlOfPage));
			return null;
		}

		$pathOnly = $this->logic->urlNormalization()->removeHomeDirectory($urlParts['path']);
		if ($pathOnly !== '' && substr($pathOnly, 0, 1) !== '/') {
			$pathOnly = '/' . $pathOnly;
		}
		if ($pathOnly === '') {
			return '/';
		}

		return $pathOnly;
	}

	/**
	 * @param array<int, mixed> $regexExclusions
	 */
	private function matchesAnyRegexExclusion(array $regexExclusions, string $stringToMatch, string $key): bool {
		foreach ($regexExclusions as $pattern) {
			if (!is_scalar($pattern)) {
				$this->logger->debugMessage("Skipping non-scalar regex exclusion pattern.");
				continue;
			}
			$patternToExcludeNoSlashes = stripslashes((string)$pattern);
			$matches = array();

			if ($this->f->regexMatch($patternToExcludeNoSlashes, $stringToMatch, $matches)) {
				$this->logger->debugMessage("Regex excluded suggestion. Key: " . $key .
					", Path: '" . esc_html($stringToMatch) . "', Pattern: '" . esc_html($patternToExcludeNoSlashes) . "'");
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $typeConstant
	 * @return string|null
	 */
	public function mapTypeConstantToString($typeConstant) {
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
}
