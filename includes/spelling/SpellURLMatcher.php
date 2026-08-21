<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL-level matching: regex-based matching, slug matching, image detection,
 * permalink lookup, cache retrieval, and URL utility helpers.
 *
 * Extracted from SpellCheckerTrait_URLMatching as a standalone class with
 * explicit dependency injection.
 */
class ABJ_404_Solution_SpellURLMatcher {

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_ContentRepository */
	private $contentRepository;

	/** @var mixed */
	private $viewReadService;

	/** @var string|int|null */
	private $custom404PageID;

	/** @var array<string, string> */
	private array $preparedRegexPatternCache = array();

	/**
	 * @param ABJ_404_Solution_Functions $functions
	 * @param ABJ_404_Solution_Logging $logger
	 * @param ABJ_404_Solution_ContentRepository $contentRepository
	 * @param mixed $viewReadService
	 * @param string|int|null $custom404PageID
	 */
	public function __construct($functions, $logger, $contentRepository, $viewReadService, $custom404PageID) {
		$this->f = $functions;
		$this->logger = $logger;
		$this->contentRepository = $contentRepository;
		$this->viewReadService = $viewReadService;
		$this->custom404PageID = $custom404PageID;
	}

    /** @return iterable<int, array<string, mixed>> */
    private function getRedirectsWithRegEx(): iterable {
		if (!is_object($this->viewReadService) || !is_callable(array($this->viewReadService, 'getRedirectsWithRegEx'))) {
			return array();
		}
		$rows = call_user_func(array($this->viewReadService, 'getRedirectsWithRegEx'));
		return is_iterable($rows) ? $rows : array();
	}

	/** @return array<int, array<string, mixed>> */
	private function getManualRedirectsWithRegexMetachars(): array {
		if (!is_object($this->viewReadService) || !is_callable(array($this->viewReadService, 'getManualRedirectsWithRegexMetachars'))) {
			return array();
		}
		$rows = call_user_func(array($this->viewReadService, 'getManualRedirectsWithRegexMetachars'));
		return $this->normalizeRows($rows);
	}

	/**
	 * @param mixed $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizeRows($rows): array {
		if (!is_array($rows)) {
			return array();
		}
		$normalized = array();
		foreach ($rows as $row) {
			if (is_array($row)) {
				$normalized[] = $row;
			}
		}
		return $normalized;
	}

    /** Find a match using the user-defined regex patterns.
	 * @param string $requestedURL
	 * @param array<string, mixed>|null $options
	 * @return array<string, mixed>|null
	 */
	function getPermalinkUsingRegEx(string $requestedURL, $options = null) {
		if (!is_array($options)) {
				$options = abj_service('options_repository')->getOptions(true);
		}
		$isDebug = $this->logger->isDebug();

		$regexURLsRows = $this->getRedirectsWithRegEx();

		$manualWithMetachars = $this->getManualRedirectsWithRegexMetachars();
		$filtered = array();
		if (!empty($manualWithMetachars)) {
			foreach ($manualWithMetachars as $manualRow) {
				$manualUrl = isset($manualRow['url']) && is_string($manualRow['url']) ? $manualRow['url'] : '';
				if (!ABJ_404_Solution_RegexAutoPromote::looksLikeUnambiguousRegex($manualUrl)) {
					continue;
				}
				$glob = ABJ_404_Solution_RegexAutoPromote::applyGlobFixup($manualUrl);
				$manualRow['url'] = $glob['url'];
				$filtered[] = $manualRow;
			}
			if (!empty($filtered)) {
				if ($isDebug) {
					$this->logger->debugMessage(
						"Runtime regex fallback: trying " . count($filtered) .
						" MANUAL row(s) with regex metachars against URL: " . $requestedURL
					);
				}
			}
		}
		$regexURLsRows = $this->appendRegexFallbackRows($regexURLsRows, $filtered);

		foreach ($regexURLsRows as $row) {
			$regexURL = $row['url'];

			if ($isDebug) {
				abj_service('request_context')->debug_info = 'Applying custom regex "' . $regexURL . '" to URL: ' .
					$requestedURL;
			}
			$regexURLStr = is_string($regexURL) ? $regexURL : '';
			$preparedURL = $this->getPreparedRegexPattern($regexURLStr);
			if (@$this->f->regexMatch($preparedURL, $requestedURL)) {
				if ($isDebug) {
					abj_service('request_context')->debug_info = 'Cleared after regex.';
				}
				$rowType = isset($row['type']) && is_scalar($row['type']) ? (int)$row['type'] : 0;
				$rowDest = isset($row['final_dest']) && is_scalar($row['final_dest']) ? (string)$row['final_dest'] : '';
				if ($rowType === (int)ABJ404_TYPE_EXTERNAL) {
					$permalink = array(
						'id' => 0,
						'type' => ABJ404_TYPE_EXTERNAL,
						'link' => $rowDest,
						'title' => '',
						'score' => 100,
					);
				} else {
					$idAndType = $rowDest . '|' . $row['type'];
					$permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($idAndType, 0,
						null, $options);
				}
				$permalink['matching_regex'] = $regexURL;
				$permalink['code'] = isset($row['code']) && is_scalar($row['code']) ? (int)$row['code'] : 0;
				$originalPermalink = $isDebug ? $permalink : null;

				$permLinkStr = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
				$hasCaptureGroup = ($this->f->strpos($regexURLStr, '(') !== false);
				$hasReplacementToken = ($this->f->strpos($permLinkStr, '$') !== false);
				if ($hasCaptureGroup && $hasReplacementToken) {
					$results = array();
					@$this->f->regexMatch($regexURLStr, $requestedURL, $results);
					$results = is_array($results) ? $results : array();

					$permalink['link'] = $this->substituteRegexDestination($permLinkStr, $results);
				}

				if ($isDebug) {
					$this->logger->debugMessage("Found matching regex. Original permalink" .
						json_encode($originalPermalink) . ", final: " .
						json_encode($permalink));
				}

				return $permalink;
			}

			if ($isDebug) {
				abj_service('request_context')->debug_info = 'Cleared after regex.';
			}
		}

		return null;
	}

	/**
	 * @param iterable<int, array<string, mixed>> $regexRows
	 * @param array<int, array<string, mixed>> $fallbackRows
	 * @return iterable<int, array<string, mixed>>
	 */
	private function appendRegexFallbackRows(iterable $regexRows, array $fallbackRows): iterable {
		foreach ($regexRows as $row) {
			yield $row;
		}
		foreach ($fallbackRows as $row) {
			yield $row;
		}
	}

	/**
	 * @param array<int|string, string> $results
	 */
	private function substituteRegexDestination(string $template, array $results): string {
		$substituted = preg_replace_callback(
			'/\\$([1-9][0-9]*)/',
			static function(array $tokenMatch) use ($results): string {
				$groupNumber = (int)$tokenMatch[1];
				return array_key_exists($groupNumber, $results)
					? (string)$results[$groupNumber]
					: '';
			},
			$template
		);
		return is_string($substituted) ? $substituted : $template;
	}

	/**
	 * @param string $regexURL
	 * @return string
	 */
	private function getPreparedRegexPattern($regexURL) {
		if (isset($this->preparedRegexPatternCache[$regexURL])) {
			return $this->preparedRegexPatternCache[$regexURL];
		}

		$prepared = $this->f->str_replace('/', '\/', $regexURL);
		$this->preparedRegexPatternCache[$regexURL] = $prepared;
		return $prepared;
	}

    /** Find a match using an exact slug match.
	 * @param string $requestedURL
	 * @return array<string, mixed>|null
	 */
	function getPermalinkUsingSlug(string $requestedURL) {

		$exploded = array_filter(explode('/', $requestedURL));
		if (count($exploded) === 0) {
			return null;
		}
		$postSlug = end($exploded);
		$postsBySlugRows = $this->contentRepository->getPublishedPagesAndPostsIDs(array('slug' => $postSlug));
		if (count($postsBySlugRows) == 1) {
			$post = reset($postsBySlugRows);
			$postId = (is_object($post) && property_exists($post, 'id')) ? $post->id : null;
			if ($postId === null) {
				return null;
			}
			$permalink = array();
			$permalink['id'] = $postId;
			$permalink['type'] = ABJ404_TYPE_POST;
			$permalink['score'] = 100;
			$permalink['title'] = get_the_title($postId);
			$permalink['link'] = get_permalink($postId);

			return $permalink;

		} else if (count($postsBySlugRows) > 1) {
            $this->logger->debugMessage("More than one post found with the slug, so no redirect was " .
                    "created. Slug: " . $postSlug);
		} else {
			$this->logger->debugMessage("No posts or pages matching slug: " . esc_html($postSlug));
		}

		return null;
	}

	/**
	 * @param string $requestedURL
	 * @return bool
	 */
	function requestIsForAnImage(string $requestedURL): bool {
        $imageExtensions = array(".jpg", ".jpeg", ".gif", ".png", ".tif", ".tiff", ".bmp", ".pdf",
            ".jif", ".jif", ".jp2", ".jpx", ".j2k", ".j2c", ".pcd");

		$returnVal = false;

		foreach ($imageExtensions as $extension) {
			if ($this->f->endsWithCaseInsensitive($requestedURL, $extension)) {
				$returnVal = true;
				break;
			}
		}

		return $returnVal;
	}

	/**
	 * @param array<int, object> $rowsAsObject
	 * @return array<int, array<string, mixed>>
	 */
	function getOnlyIDandTermID(array $rowsAsObject): array {
		$rows = array();
		$objectRow = array_pop($rowsAsObject);
		while ($objectRow != null) {
            $rows[] = array(
                'id' => property_exists($objectRow, 'id') == true ? $objectRow->id : null,
                'term_id' => property_exists($objectRow, 'term_id') == true ? $objectRow->term_id : null,
            	'url' => property_exists($objectRow, 'url') == true ? $objectRow->url : null
                );
            $objectRow = array_pop($rowsAsObject);
		}

		return $rows;
	}

	/**
	 * @param string $requestedURL
	 * @return array<int|string, mixed>
	 */
	function getFromPermalinkCache(string $requestedURL): array {
        $ctx = abj_service('request_context');
        if (!empty($ctx->permalinks_found)) {
			$permalinks = json_decode($ctx->permalinks_found, true);
			if (is_array($permalinks)) {
				return $permalinks;
			}
		}

		$returnValue = $this->contentRepository->getSpellingPermalinksFromCache($requestedURL);
		if (is_array($returnValue) && !empty($returnValue)) {
			return $returnValue;
		}

		return array();
	}

	/**
	 * @param int $id
	 * @param string $rowType
	 * @return string|null
	 * @throws Exception
	 */
	function getPermalink($id, $rowType) {
		if ($rowType == 'pages') {
			$link = $this->contentRepository->getPermalinkFromCache($id);

			if ($link === null || trim((string)$link) === '') {
				$linkResult = get_the_permalink($id);
				$link = ($linkResult !== false) ? $linkResult : null;
			}
			return abj_service('sanitizer')->normalizeUrlString($link);

		} else if ($rowType == 'tags') {
			return abj_service('sanitizer')->normalizeUrlString(get_tag_link($id));

		} else if ($rowType == 'categories') {
			return abj_service('sanitizer')->normalizeUrlString(get_category_link($id));

		} else if ($rowType == 'image') {
			$src = wp_get_attachment_image_src($id, "attached-image");
			if ($src == false || !is_array($src)) {
				return null;
			}
			return abj_service('sanitizer')->normalizeUrlString($src[0]);

		} else {
			throw new \Exception("Unknown row type ..."); // allow-raw-error: assertion, should never reach user
		}
	}

    /** Turns "/abc/defg" into "defg"
	 * @param string $url
	 * @return string
	 */
	function getLastURLPart($url) {
		$parts = explode("/", $url);
		$lastPart = '';
		for ($i = count($parts) - 1; $i >= 0; $i--) {
			$lastPart = $parts[$i];
			if (trim($lastPart) != "") {
				break;
			}
		}

		if (trim($lastPart) == "") {
			return $url;
		}

		return $lastPart;
	}

}
