<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Finds similar pages.
 * Finds search suggestions. */

class ABJ_404_Solution_SpellChecker {

	/** @var array<int, string> */
	private array $separatingCharacters = array("-","_",".","~",'%20');

    /** Same as above except without the period (.) because of the extension in the file name.
	 * @var array<int, string> */
	private array $separatingCharactersForImages = array("-","_","~",'%20');

	const MAX_DIST = 2083;

	const MAX_LIKELY_DISTANCE = 300;

	const NGRAM_PREFILTER_THRESHOLD = 0.3;

	const NGRAM_PREFILTER_MAX_CANDIDATES = 500;

	const NGRAM_MIN_CACHE_ENTRIES = 50;

	const NGRAM_SECONDARY_THRESHOLD = 0.4;

	const NGRAM_SECONDARY_MAX_CANDIDATES = 100;

	const NGRAM_MIN_COVERAGE_RATIO = 0.8;

	const NGRAM_SECONDARY_MIN_CANDIDATES = 50;

	private static ?self $instance = null;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_NotFoundResponseService|null */
	private $notFoundResponse;

	/** @var ABJ_404_Solution_ContentRepository */
	private $contentRepository;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_SpellURLMatcher */
	private $urlMatcher;

	/** @var ABJ_404_Solution_SpellLevenshteinEngine */
	private $levenshteinEngine;

	/** @var ABJ_404_Solution_SpellCandidateFilter */
	private $candidateFilter;

	/** @var ABJ_404_Solution_SpellPostListeners */
	private $postListeners;

	/** @var ABJ_404_Solution_SpellSuggestionShortcodeDetector */
	private $shortcodeDetector;

	/**
	 * @param ABJ_404_Solution_SpellCheckerDependencies|null $deps
	 */
	public function __construct(?ABJ_404_Solution_SpellCheckerDependencies $deps = null) {
		$deps = $deps ?? new ABJ_404_Solution_SpellCheckerDependencies();
		$contentRepository = $deps->contentRepository;
		$this->f = $deps->functions !== null ? $deps->functions : abj_service('functions');
		$this->logic = $deps->pluginLogic !== null ? $deps->pluginLogic : abj_service('plugin_logic');
		$resolvedNotFoundResponse = function_exists('abj_service_optional')
			? abj_service_optional('not_found_response') : null;
		$this->notFoundResponse = $resolvedNotFoundResponse instanceof ABJ_404_Solution_NotFoundResponseService
			? $resolvedNotFoundResponse : null;
		$this->contentRepository = $contentRepository !== null ? $contentRepository : abj_service('content_repository');
		$this->logger = $deps->logging !== null ? $deps->logging : abj_service('logging');
		$permalinkCacheResolved = $deps->permalinkCache !== null ? $deps->permalinkCache : abj_service('permalink_cache');
		$ngramFilterResolved = $deps->ngramFilter !== null ? $deps->ngramFilter : abj_service('ngram_filter');
		$viewReadServiceResolved = $deps->viewReadService !== null ? $deps->viewReadService :
			(is_object($contentRepository) && method_exists($contentRepository, 'getRedirectsWithRegEx') ? $contentRepository : abj_service('view_read_service'));

		$options = abj_service('options_repository')->getOptions(true);
		$custom404PageIDRaw =
			(is_array($options) && isset($options['dest404page']) ?
			$options['dest404page'] : null);
		$custom404PageID = is_string($custom404PageIDRaw) ? $custom404PageIDRaw : (is_int($custom404PageIDRaw) ? (string)$custom404PageIDRaw : null);
		$custom404PageIDResolved = null;
		if ($this->notFoundResponse instanceof ABJ_404_Solution_NotFoundResponseService
				&& $this->notFoundResponse->thereIsAUserSpecified404Page($custom404PageID)) {
			$custom404PageIDResolved = $custom404PageID;
		}

		$this->urlMatcher = new ABJ_404_Solution_SpellURLMatcher(
			$this->f, $this->logger, $this->contentRepository,
			$viewReadServiceResolved, $custom404PageIDResolved
		);

		$this->postListeners = new ABJ_404_Solution_SpellPostListeners(
			$this->f, $this->logger, $this->contentRepository,
			$permalinkCacheResolved, $ngramFilterResolved
		);

		$this->levenshteinEngine = new ABJ_404_Solution_SpellLevenshteinEngine(
			new ABJ_404_Solution_SpellLevenshteinEngineDependencies(
				$this->f, $this->logic, $this->logger, $this->contentRepository,
				$ngramFilterResolved, $this->urlMatcher, $this->separatingCharacters
			)
		);

		$this->candidateFilter = new ABJ_404_Solution_SpellCandidateFilter(
			$this->f, $this->logic, $this->logger, $this->contentRepository,
			$this->urlMatcher, $this->levenshteinEngine, $this->postListeners,
			$custom404PageIDResolved, $this->separatingCharacters, $this->separatingCharactersForImages
		);

		$this->shortcodeDetector = new ABJ_404_Solution_SpellSuggestionShortcodeDetector(
			$this->notFoundResponse
		);
	}

	/**
	 * Test seam (M105): install the cached singleton; pass null to clear it.
	 * @param self|null $instance
	 * @return void
	 */
	public static function setInstance($instance) {
		self::$instance = $instance;
	}

	/**
	 * Return the already-built singleton without resolving the container or
	 * building a new one, so the `spell_checker` factory can honor a
	 * test-installed override. Mirrors PluginLogic / Logging peekInstance().
	 * @return self|null
	 */
	public static function peekInstance(): ?self {
		return self::$instance;
	}

	public static function getInstance(): self {
		if (self::$instance !== null) {
			return self::$instance;
		}

		if (class_exists('ABJ_404_Solution_ServiceContainer')) {
			$resolved = ABJ_404_Solution_ServiceContainer::safeGet('spell_checker');
			if ($resolved instanceof self) {
				self::$instance = $resolved;
				return self::$instance;
			}
		}

		self::$instance = new ABJ_404_Solution_SpellChecker();

		return self::$instance;
	}

	public function enablePerformanceCounters(bool $enable = true): void {
		$this->levenshteinEngine->enablePerformanceCounters($enable);
	}

	public function setSkipNgramGate4(bool $skip = true): void {
		$this->levenshteinEngine->setSkipNgramGate4($skip);
	}

	public function resetPerformanceCounters(): void {
		$this->levenshteinEngine->resetPerformanceCounters();
	}

	/**
	 * @return array{levenshtein_calls: int, pages_considered: int, efficiency_percent: float}
	 */
	public function getPerformanceCounters(): array {
		return $this->levenshteinEngine->getPerformanceCounters();
	}

	/** @return array<string, mixed>|null */
	function getPermalinkUsingRegEx(string $requestedURL, $options = null) {
		return $this->urlMatcher->getPermalinkUsingRegEx($requestedURL, $options);
	}

	/** @return array<string, mixed>|null */
	function getPermalinkUsingSlug(string $requestedURL) {
		return $this->urlMatcher->getPermalinkUsingSlug($requestedURL);
	}

	function requestIsForAnImage(string $requestedURL): bool {
		return $this->urlMatcher->requestIsForAnImage($requestedURL);
	}

	/** @return array<int, array<string, mixed>> */
	function getOnlyIDandTermID(array $rowsAsObject): array {
		return $this->urlMatcher->getOnlyIDandTermID($rowsAsObject);
	}

	/** @return array<int|string, mixed> */
	function getFromPermalinkCache(string $requestedURL): array {
		return $this->urlMatcher->getFromPermalinkCache($requestedURL);
	}

	/**
	 * @return string|null
	 * @throws Exception
	 */
	function getPermalink($id, $rowType) {
		return $this->urlMatcher->getPermalink($id, $rowType);
	}

	function getLastURLPart($url) {
		return $this->urlMatcher->getLastURLPart($url);
	}

	/** @return array<int, mixed> */
	function findMatchingPosts(string $requestedURLRaw, string $includeCats = '1', string $includeTags = '1') {
		return $this->candidateFilter->findMatchingPosts($requestedURLRaw, $includeCats, $includeTags);
	}

	/** @return array<string, string> */
	function removeExcludedPages(array $options, array $permalinks): array {
		return $this->candidateFilter->removeExcludedPages($options, $permalinks);
	}

	/** @return array<string, string> */
	function removeExcludedPagesWithRegex(array $options, array $permalinks, int $maxCacheCount): array {
		return $this->candidateFilter->removeExcludedPagesWithRegex($options, $permalinks, $maxCacheCount);
	}

	/** @return array<string, string> */
	function matchOnCats(array $permalinks, string $requestedURLCleaned, string $fullURLspacesCleaned, string $rowType): array {
		return $this->candidateFilter->matchOnCats($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rowType);
	}

	/** @return array<string, string> */
	function matchOnTags(array $permalinks, string $requestedURLCleaned, string $fullURLspacesCleaned, string $rowType): array {
		return $this->candidateFilter->matchOnTags($permalinks, $requestedURLCleaned, $fullURLspacesCleaned, $rowType);
	}

	/** @return array<string, string> */
	function matchOnPosts(array $permalinks, string $requestedURLRaw, string $requestedURLCleaned, string $fullURLspacesCleaned, string $rowType): array {
		return $this->candidateFilter->matchOnPosts($permalinks, $requestedURLRaw, $requestedURLCleaned, $fullURLspacesCleaned, $rowType);
	}

	/** @return array<int|string, mixed> */
	function getLikelyMatchIDs(string $requestedURLCleaned, string $fullURLspaces, string $rowType, ?array $rows = null) {
		return $this->levenshteinEngine->getLikelyMatchIDs($requestedURLCleaned, $fullURLspaces, $rowType, $rows);
	}

	function customLevenshtein($str1, $str2) {
		return $this->levenshteinEngine->customLevenshtein($str1, $str2);
	}

	function save_postListener($post_id, $post = null, $update = null): void {
		// @hook-lifecycle: opt-out - delegated SpellPostListeners::save_postListener owns request-level dedup.
		$this->postListeners->save_postListener($post_id, $post, $update);
	}

	function delete_postListener($post_id, $post = null): void {
		$this->postListeners->delete_postListener($post_id, $post);
	}

	function term_changedListener(int $term_id, int $tt_id = 0, string $taxonomy = ''): void {
		$this->postListeners->term_changedListener($term_id, $tt_id, $taxonomy);
	}

	function savePostHandler($post_id, $post, $update, $saveOrDelete): void {
		$this->postListeners->savePostHandler($post_id, $post, $update, $saveOrDelete);
	}

	function permalinkStructureChanged($var1, $newStructure): void {
		$this->postListeners->permalinkStructureChanged($var1, $newStructure);
	}

	function initializePublishedPostsProvider(): void {
		$this->postListeners->initializePublishedPostsProvider();
	}

	/**
	 * @return array<int, mixed>
	 */
	public function findSuggestionsForURLUsingSmartCache($requestedURL, $includeCats = '1', $includeTags = true) {
		$includeTagsStr = $includeTags ? '1' : '0';
		return $this->findMatchingPosts($requestedURL, $includeCats, $includeTagsStr);
	}

	static function init(): void {
		$me = abj_service('spell_checker');

		add_action('updated_option', array($me,'permalinkStructureChanged'), 10, 2);
		add_action('save_post', array($me,'save_postListener'), 10, 3);
		add_action('delete_post', array($me,'delete_postListener'), 10, 2);
		// A category/tag create/rename/delete changes spelling-match results, so
		// invalidate the spelling cache (including memoized no-match entries).
		add_action('created_term', array($me,'term_changedListener'), 10, 3);
		add_action('edited_term', array($me,'term_changedListener'), 10, 3);
		add_action('delete_term', array($me,'term_changedListener'), 10, 3);
	}

	/**
	 * True only for a cached confirmed no-match: a [permalinks, rowType] packet
	 * whose permalink list is an empty array. A cache miss (empty array()), a
	 * positive result, and any malformed payload all return false so the caller
	 * recomputes -- the negative short-circuit can never emit a destination.
	 *
	 * @param mixed $cachedPacket the getFromPermalinkCache() return value
	 * @return bool
	 */
	private function isCachedNoMatchResult($cachedPacket): bool {
		return is_array($cachedPacket) && count($cachedPacket) === 2
			&& array_key_exists(0, $cachedPacket) && is_array($cachedPacket[0])
			&& empty($cachedPacket[0]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	function getPermalinkUsingSpelling(string $requestedURL, ?string $fullRequestedURL = null, $optionsOverride = null) {
		$abj404spellChecker = abj_service('spell_checker');

		$options = is_array($optionsOverride) ? $optionsOverride : abj_service('options_repository')->getOptions();

		if (@$options['auto_redirects'] == '1') {
            $autoCats = isset($options['auto_cats']) && is_string($options['auto_cats']) ? $options['auto_cats'] : '1';
            $autoTags = isset($options['auto_tags']) && is_string($options['auto_tags']) ? $options['auto_tags'] : '1';

            // Negative-result memoization. A repeated 404 to a URL with no
            // spelling match would otherwise re-run the full Levenshtein scan
            // on every hit: captured rows are excluded from the pre-match
            // redirect lookup (status/type filter in getPermalinkFromURL.sql),
            // so an already-seen no-match URL never short-circuits before this
            // point. We short-circuit ONLY on a cached no-match (an empty
            // permalink list). The outcome is identical to recomputing a
            // no-match (return null), so a stale entry can at worst miss a
            // redirect (graceful 404), never cause a wrong one. The spelling
            // cache is invalidated on any content change (SpellPostListeners:
            // save/delete post, created/edited/deleted category or tag), so a
            // URL that becomes matchable is recomputed on its next hit. A
            // cached POSITIVE result is intentionally ignored and recomputed
            // fresh so a deleted or unpublished destination can never be
            // served from cache. Positive matches do not recur here in any
            // case: a successful match is promoted to a stored AUTO redirect,
            // so the next hit short-circuits at getActiveRedirectForURL.
            $cachedPacket = $abj404spellChecker->getFromPermalinkCache($requestedURL);
            if ($this->isCachedNoMatchResult($cachedPacket)) {
                return null;
            }

            $permalinksPacket = $abj404spellChecker->findMatchingPosts($requestedURL,
                    $autoCats, $autoTags);

			$permalinks = $permalinksPacket[0];
			$rowType = $permalinksPacket[1];

			$minScore = $options['auto_score'];

			if (!is_array($permalinks) || empty($permalinks)) {
				return null;
			}
			$linkScore = reset($permalinks);
			$idAndType = key($permalinks);
			$idAndTypeStr = is_string($idAndType) ? $idAndType : (string)$idAndType;
			$linkScoreInt = is_scalar($linkScore) ? (int)$linkScore : 0;
            $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($idAndTypeStr, $linkScoreInt,
            	is_string($rowType) ? $rowType : null, $options);

			if ($permalink['score'] >= $minScore) {
				$redirectType = $permalink['type'];
				if (('' . $redirectType != ABJ404_TYPE_404_DISPLAYED) && ('' . $redirectType != ABJ404_TYPE_HOME)) {
					return $permalink;

				} else {
                    $permalinkJson = json_encode($permalink);
                    $this->logger->errorMessage("Unhandled permalink type: " .
                            wp_kses_post(is_string($permalinkJson) ? $permalinkJson : '{}'));
					return null;
				}
			}

			if ($fullRequestedURL !== null) {
				$this->cacheComputedSuggestionsForShortcode($fullRequestedURL, $permalinksPacket);
			}
		}

		return null;
	}

	private function cacheComputedSuggestionsForShortcode(string $fullRequestedURL, array $permalinksPacket): void {
		$normalizedURL = abj_service('url_encoder')->normalizeURLForCacheKey($fullRequestedURL);

		$urlKey = md5($normalizedURL);
		$transientKey = 'abj404_suggest_' . $urlKey;

		$existing = get_transient($transientKey);
		if ($existing !== false) {
			return;
		}

		// allow-cache-empty: factory-built typed array; SuggestionTransient::completeArray
		// always returns a non-empty associative array with at minimum a 'status' key.
		set_transient(
			$transientKey,
			ABJ_404_Solution_SuggestionTransient::completeArray(
				$normalizedURL,
				$permalinksPacket,
				abj_clock()->now(),
				''
			),
			300
		); // 5 minute TTL

		$this->logger->debugMessage("Cached spell-check suggestions for shortcode: " .
			esc_html($normalizedURL));
	}

	public function triggerAndCleanupOnFailure(string $requestedURL): bool {
		$normalizedURL = abj_service('url_encoder')->normalizeURLForCacheKey($requestedURL);

		$urlKey = md5($normalizedURL);
		$transientKey = 'abj404_suggest_' . $urlKey;

		$existing = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($transientKey));
		if ($existing !== null) {
			$this->logger->debugMessage("Async suggestions: skipping, transient already exists for " .
				esc_html($normalizedURL) . " (status: " . esc_html($existing->getStatus()) . ")");
			return false;
		}

		$token = wp_generate_password(32, false);

		// allow-cache-empty: factory-built typed array; keep the TTL at 120
		// seconds so slow hosts can start before the polling UI gives up.
		set_transient(
			$transientKey,
			ABJ_404_Solution_SuggestionTransient::pendingArray(
				$normalizedURL,
				$token,
				0,
				abj_clock()->now()
			),
			120
		); // 2 minute TTL

		$this->logger->debugMessage("Async suggestions: triggering background computation for " .
			esc_html($normalizedURL));

		// Loopback self-dispatch to admin-ajax.php on this same host. The
		// sslverify default of false matches WP core's own loopback convention
		// (see wp-includes/cron.php spawn_cron(), which uses the same
		// apply_filters('https_local_ssl_verify', false) pattern) and is
		// intentional for three reasons:
		//   1. The request never leaves the host. Intercepting it requires an
		//      attacker who already controls the local machine, at which point
		//      they can read the transient and dispatch the AJAX directly
		//      without bothering with MITM on loopback.
		//   2. WP sites routinely run on self-signed or hostname-mismatched
		//      certs in dev / behind a TLS-terminating proxy. Hardcoding
		//      sslverify true would break dispatch for those installs with no
		//      affordance for the admin to recover.
		//   3. The body carries only a one-shot suggestion-compute token bound
		//      to a 2-minute pending transient (set above). Worst case for a
		//      hypothetical local MITM is they re-trigger the same compute the
		//      site is already running, which is rate-limited downstream.
		// Admins on hostile-loopback topologies (e.g. reverse proxy spanning an
		// untrusted segment) can return true from the https_local_ssl_verify
		// filter to opt into strict TLS. Tests for both behaviors live in
		// AsyncSuggestionsTest::testWpRemotePostSslVerify*. M104 in the design
		// audit re-flags this every pass; this comment is the documented
		// trade-off so the next audit can mark it accepted.
		$response = wp_remote_post(admin_url('admin-ajax.php'), array(
			'blocking'  => false,
			'timeout'   => 5,
			'sslverify' => apply_filters('https_local_ssl_verify', false),
			'body'      => array(
				'action'   => 'abj404_compute_suggestions',
				'url'      => $normalizedURL,
				'token'    => $token
			)
		));

		if (is_wp_error($response)) {
			$this->logger->debugMessage("Async suggestions: dispatch failed for " .
				esc_html($normalizedURL) . " - " . $response->get_error_message());
			delete_transient($transientKey);
			return false;
		}

		return true;
	}

	public function does404PageHaveSuggestionsShortcode() {
		return $this->shortcodeDetector->does404PageHaveSuggestionsShortcode();
	}

}
