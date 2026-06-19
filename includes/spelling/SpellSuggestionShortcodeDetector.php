<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by PatternPriorityResolutionTest via does404PageHaveSuggestionsShortcode

/**
 * Decides whether the site's configured custom 404 page embeds the suggestions
 * shortcode. The async-suggestion trigger consults this before kicking off a
 * background spell-check compute: there is no point pre-computing suggestions
 * for a 404 page that will never render them.
 *
 * Resolves the configured `dest404page` option, confirms it points at a real
 * user-specified page (via the NotFoundResponse service), loads that post, and
 * reports whether its content contains the suggestions shortcode. Pure
 * read/inspection policy: no SQL, no presentation, no spelling-match work.
 */
class ABJ_404_Solution_SpellSuggestionShortcodeDetector {

	/** @var ABJ_404_Solution_NotFoundResponseService|null */
	private $notFoundResponse;

	/**
	 * @param ABJ_404_Solution_NotFoundResponseService|null $notFoundResponse
	 */
	public function __construct($notFoundResponse) {
		$this->notFoundResponse = $notFoundResponse;
	}

	/**
	 * @return bool true only when the configured custom 404 page exists and its
	 *   content contains the suggestions shortcode.
	 */
	public function does404PageHaveSuggestionsShortcode(): bool {
		$options = abj_service('options_repository')->getOptions();
		$dest404pageRaw = isset($options['dest404page']) ? $options['dest404page'] : null;
		$dest404page = is_string($dest404pageRaw) ? $dest404pageRaw : null;

		if (!$this->notFoundResponse instanceof ABJ_404_Solution_NotFoundResponseService
				|| !$this->notFoundResponse->thereIsAUserSpecified404Page($dest404page)) {
			return false;
		}

		$parts = explode('|', $dest404page ?? '');
		$page404Id = isset($parts[0]) ? intval($parts[0]) : 0;

		if ($page404Id <= 0) {
			return false;
		}

		$page = get_post($page404Id);
		if (!$page) {
			return false;
		}

		return has_shortcode($page->post_content, ABJ404_SHORTCODE_NAME);
	}
}
