<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/SpellCheckerTrait_PostListeners.php';
require_once __DIR__ . '/SpellCheckerTrait_URLMatching.php';
require_once __DIR__ . '/SpellCheckerTrait_CandidateFiltering.php';
require_once __DIR__ . '/SpellCheckerTrait_LevenshteinEngine.php';

/* Finds similar pages.
 * Finds search suggestions. */

class ABJ_404_Solution_SpellChecker {

	use SpellCheckerTrait_PostListeners,
		SpellCheckerTrait_URLMatching,
		SpellCheckerTrait_CandidateFiltering,
		SpellCheckerTrait_LevenshteinEngine;

	/** @var array<int, string> */
	private array $separatingCharacters = array("-","_",".","~",'%20');

    /** Same as above except without the period (.) because of the extension in the file name.
	 * @var array<int, string> */
	private array $separatingCharactersForImages = array("-","_","~",'%20');

	private ?ABJ_404_Solution_PublishedPostsProvider $publishedPostsProvider = null;

	const MAX_DIST = 2083;

	/** Upper bound for the length-based distance buckets used to pre-filter candidates. */
	const MAX_LIKELY_DISTANCE = 300;

	/** Similarity threshold for N-gram prefiltering (lower = more candidates, slower but safer). */
	const NGRAM_PREFILTER_THRESHOLD = 0.3;

	/** Maximum candidates to retrieve during N-gram prefiltering. */
	const NGRAM_PREFILTER_MAX_CANDIDATES = 500;

	/** Minimum N-gram cache entries required to enable prefiltering.
	 * Small sites don't need prefiltering; this also prevents use during partial cache builds. */
	const NGRAM_MIN_CACHE_ENTRIES = 50;

	/** Similarity threshold for secondary N-gram filtering (higher = stricter, fewer candidates).
	 * More conservative than prefilter since we're refining an already-filtered list. */
	const NGRAM_SECONDARY_THRESHOLD = 0.4;

	/** Maximum candidates for secondary N-gram filtering. */
	const NGRAM_SECONDARY_MAX_CANDIDATES = 100;

	/** Minimum cache coverage ratio (ngram entries / permalink entries) to trust prefiltering.
	 * 0.8 = require at least 80% of permalink cache entries to be in N-gram cache. */
	const NGRAM_MIN_COVERAGE_RATIO = 0.8;

	/** Minimum candidate count to trigger secondary N-gram filtering.
	 * Below this threshold, Levenshtein on all candidates is fast enough. */
	const NGRAM_SECONDARY_MIN_CANDIDATES = 50;

	private static ?self $instance = null;

	// Performance counters (for testing efficiency - disabled by default)
	private bool $enablePerformanceCounters = false;

	// When true, skip the N-gram gate 4 early return so the full Levenshtein
	// scan runs.  The async page-suggestions worker sets this because the
	// 5-second scan is acceptable in a background process.
	private bool $skipNgramGate4 = false;
	private int $levenshteinCallCount = 0;
	private int $totalPagesConsidered = 0;

	/** @var string|int|null */
	private $custom404PageID = null;

	/** Prepared regex pattern cache for the current request lifecycle.
	 * @var array<string, string> */
	private array $preparedRegexPatternCache = array();

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_PermalinkCache */
	private $permalinkCache;

	/** @var ABJ_404_Solution_NGramFilter */
	private $ngramFilter;

	/**
	 * Constructor with dependency injection.
	 * Dependencies are now explicit and visible.
	 *
	 * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
	 * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 * @param ABJ_404_Solution_PermalinkCache|null $permalinkCache Permalink caching service
	 * @param ABJ_404_Solution_NGramFilter|null $ngramFilter N-gram filter for optimization
	 */
	public function __construct($functions = null, $pluginLogic = null, $dataAccess = null, $logging = null, $permalinkCache = null, $ngramFilter = null) {
		// Use injected dependencies or fall back to getInstance() for backward compatibility
		$this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
		$this->logic = $pluginLogic !== null ? $pluginLogic : ABJ_404_Solution_PluginLogic::getInstance();
		$this->dao = $dataAccess !== null ? $dataAccess : ABJ_404_Solution_DataAccess::getInstance();
		$this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
		$this->permalinkCache = $permalinkCache !== null ? $permalinkCache : ABJ_404_Solution_PermalinkCache::getInstance();
		$this->ngramFilter = $ngramFilter !== null ? $ngramFilter : ABJ_404_Solution_NGramFilter::getInstance();

		// Set the custom 404 page id if there is one
		$options = $this->logic->getOptions();
		$custom404PageIDRaw =
			(is_array($options) && isset($options['dest404page']) ?
			$options['dest404page'] : null);
		$custom404PageID = is_string($custom404PageIDRaw) ? $custom404PageIDRaw : (is_int($custom404PageIDRaw) ? (string)$custom404PageIDRaw : null);
		if ($this->logic->thereIsAUserSpecified404Page($custom404PageID)) {
			$this->custom404PageID = $custom404PageID;
		}
	}

	public static function getInstance(): self {
		if (self::$instance !== null) {
			return self::$instance;
		}

		// If the DI container is initialized, prefer it.
		if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
			try {
				$c = ABJ_404_Solution_ServiceContainer::getInstance();
				if (is_object($c) && method_exists($c, 'has') && $c->has('spell_checker')) {
					$resolved = $c->get('spell_checker');
					if ($resolved instanceof self) {
						self::$instance = $resolved;
						return self::$instance;
					}
				}
			} catch (Throwable $e) {
				// fall back
			}
		}

		self::$instance = new ABJ_404_Solution_SpellChecker();

		return self::$instance;
	}

	/**
	 * Enable performance counters for testing efficiency (disabled by default for production)
	 */
	public function enablePerformanceCounters(bool $enable = true): void {
		$this->enablePerformanceCounters = $enable;
		if ($enable) {
			$this->resetPerformanceCounters();
		}
	}

	/**
	 * Skip the N-gram gate 4 early return so the full Levenshtein scan runs.
	 * Used by the async page-suggestions worker where the scan time is acceptable.
	 */
	public function setSkipNgramGate4(bool $skip = true): void {
		$this->skipNgramGate4 = $skip;
	}

	/**
	 * Reset performance counters to zero
	 */
	public function resetPerformanceCounters(): void {
		$this->levenshteinCallCount = 0;
		$this->totalPagesConsidered = 0;
	}

	/**
	 * Get current performance counter values
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
	 * Find URL suggestions using smart caching (N-gram filtering).
	 * This is a wrapper around findMatchingPosts() primarily for testing.
	 *
	 * @param string $requestedURL The 404 URL to find matches for
	 * @param string $includeCats Whether to include categories (default '1')
	 * @param bool $includeTags Whether to include tags (default true, converted to '1')
	 * @return array<int, mixed> Array of matching posts/pages
	 */
	public function findSuggestionsForURLUsingSmartCache($requestedURL, $includeCats = '1', $includeTags = true) {
		// Convert boolean to string for backward compatibility
		$includeTagsStr = $includeTags ? '1' : '0';
		return $this->findMatchingPosts($requestedURL, $includeCats, $includeTagsStr);
	}

	static function init(): void {
		// any time a page is saved or updated, or the permalink structure changes, then we have to clear
		// the spelling cache because the results may have changed.
		$me = ABJ_404_Solution_SpellChecker::getInstance();

		add_action('updated_option', array($me,'permalinkStructureChanged'), 10, 2);
		add_action('save_post', array($me,'save_postListener'), 10, 3);
		add_action('delete_post', array($me,'delete_postListener'), 10, 2);
	}

    /** Find a match using spell checking.
	 * Use spell checking to find the correct link. Return the permalink (map) if there is one, otherwise return null.
	 * @param string $requestedURL The URL slug to check for spelling matches
	 * @param string|null $fullRequestedURL Optional full URL path for caching results (e.g., '/site/bad-url')
	 * @param array<string, mixed>|null $optionsOverride
	 * @return array<string, mixed>|null
	 */
	function getPermalinkUsingSpelling(string $requestedURL, ?string $fullRequestedURL = null, $optionsOverride = null) {
		$abj404spellChecker = ABJ_404_Solution_SpellChecker::getInstance();

		$options = is_array($optionsOverride) ? $optionsOverride : $this->logic->getOptions();

		if (@$options['auto_redirects'] == '1') {
			// Site owner wants automatic redirects.
            $autoCats = isset($options['auto_cats']) && is_string($options['auto_cats']) ? $options['auto_cats'] : '1';
            $autoTags = isset($options['auto_tags']) && is_string($options['auto_tags']) ? $options['auto_tags'] : '1';
            $permalinksPacket = $abj404spellChecker->findMatchingPosts($requestedURL,
                    $autoCats, $autoTags);

			$permalinks = $permalinksPacket[0];
			$rowType = $permalinksPacket[1];

			$minScore = $options['auto_score'];

			// since the links were previously sorted so that the highest score would be first,
			// we only use the first element of the array;
			if (!is_array($permalinks) || empty($permalinks)) {
				return null;
			}
			$linkScore = reset($permalinks);
			$idAndType = key($permalinks);
			$idAndTypeStr = is_string($idAndType) ? $idAndType : (string)$idAndType;
			$linkScoreInt = is_scalar($linkScore) ? (int)$linkScore : 0;
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndTypeStr, $linkScoreInt,
            	is_string($rowType) ? $rowType : null, $options);

			if ($permalink['score'] >= $minScore) {
				// We found a permalink that will work!
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

			// No match met the auto-redirect threshold - cache results for shortcode
			// This avoids recomputing suggestions when the 404 page renders
			if ($fullRequestedURL !== null) {
				$this->cacheComputedSuggestionsForShortcode($fullRequestedURL, $permalinksPacket);
			}
		}

		return null;
	}

	/**
	 * Cache computed suggestions in a transient for the shortcode to use.
	 * This avoids duplicate computation when getPermalinkUsingSpelling() runs
	 * but doesn't find a match above the auto-redirect threshold.
	 *
	 * @param string $fullRequestedURL The full URL path (e.g., '/site/bad-url')
	 * @param array<int, mixed> $permalinksPacket The computed suggestions [permalinks, rowType]
	 */
	private function cacheComputedSuggestionsForShortcode(string $fullRequestedURL, array $permalinksPacket): void {
		// Normalize URL using centralized function for consistency
		$normalizedURL = $this->f->normalizeURLForCacheKey($fullRequestedURL);

		$urlKey = md5($normalizedURL);
		$transientKey = 'abj404_suggest_' . $urlKey;

		// Don't overwrite if already set (e.g., by async trigger)
		$existing = get_transient($transientKey);
		if ($existing !== false) {
			return;
		}

		// Store as 'complete' so shortcode renders immediately
		set_transient($transientKey, array(
			'status' => 'complete',
			'suggestions' => $permalinksPacket,
			'url' => $normalizedURL,
			'completed' => time()
		), 300); // 5 minute TTL

		$this->logger->debugMessage("Cached spell-check suggestions for shortcode: " .
			esc_html($normalizedURL));
	}

	/**
	 * Trigger asynchronous suggestion computation via non-blocking HTTP request.
	 * Uses the requested URL (MD5 hashed) as the transient key.
	 *
	 * @param string $requestedURL The full requested URL that caused the 404
	 * @return bool True if computation was triggered, false if already pending/complete
	 */
	public function triggerAsyncSuggestionComputation($requestedURL) {
		$f = ABJ_404_Solution_Functions::getInstance();

		// Normalize URL using centralized function for consistency
		$normalizedURL = $f->normalizeURLForCacheKey($requestedURL);

		$urlKey = md5($normalizedURL);
		$transientKey = 'abj404_suggest_' . $urlKey;

		// Check if already computing or complete - prevent duplicate work
		$existing = get_transient($transientKey);
		if ($existing !== false) {
			$existingStatus = (is_array($existing) && isset($existing['status']) && is_string($existing['status'])) ? $existing['status'] : 'unknown';
			$this->logger->debugMessage("Async suggestions: skipping, transient already exists for " .
				esc_html($normalizedURL) . " (status: " . esc_html($existingStatus) . ")");
			return false;
		}

		// Generate a unique token for this computation request
		// This prevents unauthorized direct calls to the AJAX endpoint (DoS protection)
		$token = wp_generate_password(32, false);

		// Mark as pending BEFORE firing request (race condition protection)
		// TTL of 120 seconds: gives slow hosts enough time to start the worker
		// Note: started=0 means no worker has claimed the work yet. The first worker
		// will set started=time() when it claims the work. This prevents the bug where
		// the first worker skips itself thinking another worker is already computing.
		set_transient($transientKey, array(
			'status' => 'pending',
			'url' => $normalizedURL,
			'started' => 0,  // 0 = no worker has claimed yet; worker sets time() when claiming
			'created' => time(),  // track creation time to detect worker no-show
			'token' => $token
		), 120); // 2 minute TTL (allows slow wp_remote_post)

		$this->logger->debugMessage("Async suggestions: triggering background computation for " .
			esc_html($normalizedURL));

		// Fire non-blocking request to compute suggestions
		// Note: timeout of 5s is needed for connection establishment (TLS handshake, etc.)
		// even with blocking=false, a too-short timeout can prevent the request from being sent
		$response = wp_remote_post(admin_url('admin-ajax.php'), array(
			'blocking'  => false,
			'timeout'   => 5,  // 5 seconds for connection establishment
			'sslverify' => apply_filters('https_local_ssl_verify', false),
			'body'      => array(
				'action'   => 'abj404_compute_suggestions',
				'url'      => $normalizedURL,
				'token'    => $token
			)
		));

		// If dispatch failed, delete the pending transient so caller can compute synchronously
		if (is_wp_error($response)) {
			$this->logger->debugMessage("Async suggestions: dispatch failed for " .
				esc_html($normalizedURL) . " - " . $response->get_error_message());
			delete_transient($transientKey);
			return false;
		}

		return true;
	}

	/**
	 * Check if the configured 404 page contains the suggestions shortcode.
	 *
	 * @return bool True if 404 page has the shortcode
	 */
	public function does404PageHaveSuggestionsShortcode() {
		$options = $this->logic->getOptions();
		$dest404pageRaw = isset($options['dest404page']) ? $options['dest404page'] : null;
		$dest404page = is_string($dest404pageRaw) ? $dest404pageRaw : null;

		if (!$this->logic->thereIsAUserSpecified404Page($dest404page)) {
			return false;
		}

		// Extract page ID from dest404page (format: "123|1")
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
