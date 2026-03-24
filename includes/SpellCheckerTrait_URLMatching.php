<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL-level matching helpers for ABJ_404_Solution_SpellChecker:
 * regex-based matching, slug matching, image detection, permalink lookup,
 * cache retrieval, and URL utility helpers.
 */
trait SpellCheckerTrait_URLMatching {

    /** Find a match using the user-defined regex patterns.
	 * @param string $requestedURL
	 * @param array<string, mixed>|null $options
	 * @return array<string, mixed>|null
	 */
	function getPermalinkUsingRegEx(string $requestedURL, $options = null) {
		if (!is_array($options)) {
			$options = $this->logic->getOptions();
		}
		$isDebug = $this->logger->isDebug();

		$regexURLsRows = $this->dao->getRedirectsWithRegEx();

		foreach ($regexURLsRows as $row) {
			$regexURL = $row['url'];

			if ($isDebug) {
				$_REQUEST[ABJ404_PP]['debug_info'] = 'Applying custom regex "' . $regexURL . '" to URL: ' .
					$requestedURL;
			}
			$regexURLStr = is_string($regexURL) ? $regexURL : '';
			$preparedURL = $this->getPreparedRegexPattern($regexURLStr);
			if ($this->f->regexMatch($preparedURL, $requestedURL)) {
				if ($isDebug) {
					$_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after regex.';
				}
				$rowType = isset($row['type']) && is_scalar($row['type']) ? (int)$row['type'] : 0;
				$rowDest = isset($row['final_dest']) && is_scalar($row['final_dest']) ? (string)$row['final_dest'] : '';
				if ($rowType === (int)ABJ404_TYPE_EXTERNAL) {
					// Fast path: external redirects already have a concrete target URL.
					$permalink = array(
						'id' => 0,
						'type' => ABJ404_TYPE_EXTERNAL,
						'link' => $rowDest,
						'title' => '',
						'score' => 100,
					);
				} else {
					$idAndType = $rowDest . '|' . $row['type'];
					$permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, 0,
						null, $options);
				}
				$permalink['matching_regex'] = $regexURL;
				$permalink['code'] = isset($row['code']) && is_scalar($row['code']) ? (int)$row['code'] : 0;
				$originalPermalink = $isDebug ? $permalink : null;

				// If regex has capture groups and destination has replacement markers, resolve them.
				$permLinkStr = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
				$hasCaptureGroup = ($this->f->strpos($regexURLStr, '(') !== FALSE);
				$hasReplacementToken = ($this->f->strpos($permLinkStr, '$') !== FALSE);
				if ($hasCaptureGroup && $hasReplacementToken) {
					$results = array();
					$this->f->regexMatch($regexURLStr, $requestedURL, $results);

					// do a repacement for all of the groups found.
					$final = $permLinkStr;
					for ($x = 1; $x < count($results); $x++) {
						$final = $this->f->str_replace('$' . $x, $results[$x], $final);
					}

					$permalink['link'] = $final;
				}

				if ($isDebug) {
					$this->logger->debugMessage("Found matching regex. Original permalink" .
						json_encode($originalPermalink) . ", final: " .
						json_encode($permalink));
				}

				return $permalink;
			}

			if ($isDebug) {
				$_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after regex.';
			}
		}

		return null;
	}

	/**
	 * Normalize and cache regex patterns for reuse within this request.
	 *
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
	 * If there is a post that has a slug that matches the user requested slug exactly,
	 * then return the permalink for that post. Otherwise return null.
	 * @param string $requestedURL
	 * @return array<string, mixed>|null
	 */
	function getPermalinkUsingSlug(string $requestedURL) {

		$exploded = array_filter(explode('/', $requestedURL));
		if (count($exploded) === 0) {
			return null;
		}
		$postSlug = end($exploded);
		$postsBySlugRows = $this->dao->getPublishedPagesAndPostsIDs($postSlug);
		if (count($postsBySlugRows) == 1) {
			$post = reset($postsBySlugRows);
			$postId = (is_object($post) && property_exists($post, 'id')) ? $post->id : null;
			if ($postId === null) {
				return null;
			}
			$permalink = array();
			$permalink['id'] = $postId;
			$permalink['type'] = ABJ404_TYPE_POST;
			// the score doesn't matter.
			$permalink['score'] = 100;
			$permalink['title'] = get_the_title($postId);
			$permalink['link'] = get_permalink($postId);

			return $permalink;

		} else if (count($postsBySlugRows) > 1) {
			// more than one post has the same slug. I don't know what to do.
            $this->logger->debugMessage("More than one post found with the slug, so no redirect was " .
                    "created. Slug: " . $postSlug);
		} else {
			$this->logger->debugMessage("No posts or pages matching slug: " . esc_html($postSlug));
		}

		return null;
	}

	/**
	 * Return true if the last characters of the URL represent an image extension (like jpg, gif, etc).
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
		// The request cache is used when the suggested pages shortcode is used.
        if (array_key_exists(ABJ404_PP, $_REQUEST) && is_array($_REQUEST[ABJ404_PP]) &&
                array_key_exists('permalinks_found', $_REQUEST[ABJ404_PP]) &&
                !empty($_REQUEST[ABJ404_PP]['permalinks_found'])) {
			$rawJson = $_REQUEST[ABJ404_PP]['permalinks_found'];
			$permalinks = is_string($rawJson) ? json_decode($rawJson, true) : null;
			if (is_array($permalinks)) {
				return $permalinks;
			}
		}

		// check the database cache.
		$returnValue = $this->dao->getSpellingPermalinksFromCache($requestedURL);
		if (is_array($returnValue) && !empty($returnValue)) {
			return $returnValue;
		}

		return array();
	}

	/**
	 * Get the permalink for the passed in type (pages, tags, categories, image, etc.
	 * @param int $id
	 * @param string $rowType
	 * @return string|null
	 * @throws Exception
	 */
	function getPermalink($id, $rowType) {
		if ($rowType == 'pages') {
			$link = $this->dao->getPermalinkFromCache($id);

			if ($link === null || trim((string)$link) === '') {
				$linkResult = get_the_permalink($id);
				$link = ($linkResult !== false) ? $linkResult : null;
			}
			return $this->f->normalizeUrlString($link);

		} else if ($rowType == 'tags') {
			return $this->f->normalizeUrlString(get_tag_link($id));

		} else if ($rowType == 'categories') {
			return $this->f->normalizeUrlString(get_category_link($id));

		} else if ($rowType == 'image') {
			$src = wp_get_attachment_image_src($id, "attached-image");
			if ($src == false || !is_array($src)) {
				return null;
			}
			return $this->f->normalizeUrlString($src[0]);

		} else {
			throw new \Exception("Unknown row type ...");
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
