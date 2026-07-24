<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/AjaxServiceResolver.php';
require_once __DIR__ . '/AjaxSearchFeedback.php';
require_once __DIR__ . '/Ajax_UpdateOptions.php';
require_once __DIR__ . '/Ajax_LoadGscSection.php';
require_once __DIR__ . '/Ajax_ViewLogs.php';
require_once __DIR__ . '/Ajax_RedirectDestinationAutocomplete.php';

/**
 * Legacy AJAX facade kept for backward compatibility with existing callbacks
 * and tests. Endpoint behavior lives in focused ABJ_404_Solution_Ajax_* adapters.
 */
class ABJ_404_Solution_Ajax_Php {

	/** @var self|null */
	private static $instance = null;
	/**
	 * Test seam: install or clear the cached singleton instance without
	 * private-field reflection. Pass null to reset between tests; pass a
	 * configured instance (or double) to install it. Mirrors the setInstance()
	 * contract on DataAccess / PluginLogic (M105 singleton-reset seam).
	 *
	 * @param self|null $instance
	 * @return void
	 */
	public static function setInstance($instance) {
	    self::$instance = $instance;
	}


	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_Ajax_Php();
		}

		return self::$instance;
	}

	/** Rate limiting helper to prevent abuse of AJAX endpoints.
	 * @param string $action The action being rate limited
	 * @param int $max_requests Maximum requests allowed per time window
	 * @param int $time_window Time window in seconds (default 60)
	 * @return bool True if rate limit exceeded, false otherwise
	 */
	static function consumeRateLimit($action, $max_requests = 100, $time_window = 60) {
		$user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
		if ($user_id) {
			$identifier = 'user_' . $user_id;
		} else {
			$remote = (isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR']))
				? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
			$identifier = 'ip_' . md5($remote);
		}

		$usePersistentCache = ABJ_404_Solution_RateLimitOperationTracer::selectBackend(
			static function (): bool {
				return function_exists('wp_using_ext_object_cache')
					&& wp_using_ext_object_cache()
					&& function_exists('wp_cache_add')
					&& function_exists('wp_cache_incr');
			}
		);
		if ($usePersistentCache) {
			return self::consumeRateLimitAtomic($action, $identifier, $max_requests, $time_window);
		}

		// Fallback for sites without a persistent object cache (the common
		// case on shared hosting). These rate limits guard BOTH admin-only
		// AJAX endpoints and public, unauthenticated endpoints -- e.g.
		// Ajax_SuggestionPolling (registered for wp_ajax_nopriv_* and
		// reachable by any anonymous visitor landing on a 404 page) calls
		// consumeRateLimit('poll_suggestions', ...) through this exact path.
		// This is a real public attack surface, not an admin-only guard.
		// consumeRateLimitDbFallback() closes the TOCTOU race a plain
		// get_transient()/set_transient() pair would have here (concurrent
		// requests reading the same pre-write count and all passing the
		// check) by routing the increment through an atomic SQL upsert.
		return self::consumeRateLimitDbFallback($action, $identifier, $max_requests, $time_window);
	}

	/**
	 * Atomic rate-limit counter for sites WITHOUT a persistent object cache.
	 * Bypasses the WP Transients API's own get_transient()/set_transient()
	 * pair -- a non-atomic read-then-write that lets concurrent requests
	 * observe the same pre-increment count and all pass the check -- in
	 * favor of raw SQL against the same wp_options rows a transient would
	 * use. `INSERT ... ON DUPLICATE KEY UPDATE` is MySQL/MariaDB's atomic
	 * upsert primitive: concurrent callers serialize on the option_name
	 * unique key under an engine-level row lock, so each gets a distinct,
	 * correctly incremented count -- the same property consumeRateLimitAtomic()
	 * gets from wp_cache_incr(), just for the no-persistent-cache case.
	 *
	 * Storage shape mirrors get_transient()/set_transient() exactly
	 * (`_transient_{key}` for the count, `_transient_timeout_{key}` for the
	 * expiry) so the existing daily-cron sweep
	 * (DatabaseUpgradeDailyMaintenance::cleanupExpiredRateLimitTransients())
	 * keeps finding and reaping these rows without any change on its side.
	 *
	 * @param string $action
	 * @param string $identifier
	 * @param int $max_requests
	 * @param int $time_window
	 * @return bool True if rate limit exceeded, false otherwise
	 */
	private static function consumeRateLimitDbFallback($action, $identifier, $max_requests, $time_window) {
		$dbCore = ABJ_404_Solution_Ajax_ServiceResolver::optional('db_core');
		if (!($dbCore instanceof ABJ_404_Solution_DatabaseQueryInterface)) {
			// Defensive Coding #2 (Check before you query) / #8 (infrastructure
			// failures are warnings, not bugs unless the plugin can't
			// function): no DAO available (very early boot / degraded
			// container). Fail toward allowing the request rather than
			// blocking every visitor because the rate limiter itself is
			// unavailable.
			return false;
		}

		$now = abj_clock()->now();
		$base_key = 'abj404_rate_limit_' . $action . '_' . $identifier;
		$value_option = '_transient_' . $base_key;
		$timeout_option = '_transient_timeout_' . $base_key;

		// Step 1: clear a stale window, atomically. A single multi-table
		// DELETE gated on the CURRENT (server-side, re-validated) timeout
		// value, so it either removes both rows together or neither -- there
		// is no intermediate state where a concurrent caller could observe a
		// stale count row whose paired timeout row has already been cleared
		// (or vice versa). Safe to run unconditionally on every call: a
		// no-op when the window hasn't expired yet, or the key doesn't exist
		// at all yet.
		$deleteResult = $dbCore->queryAndGetResults(
			"DELETE o FROM {wp_options} o, "
			. "(SELECT option_value FROM {wp_options} WHERE option_name = %s) t "
			. "WHERE o.option_name IN (%s, %s) AND t.option_value < %d",
			array('query_params' => array($timeout_option, $value_option, $timeout_option, $now))
		);
		if (!empty($deleteResult['last_error'])) {
			// Infrastructure failure: queryAndGetResults() already logged it
			// (CLAUDE.md: queryAndGetResults is the centralized error
			// handler). Degrade past it rather than blocking legitimate
			// traffic on a broken rate limiter.
			return false;
		}

		// Step 2: atomic insert-or-increment. `INSERT ... ON DUPLICATE KEY
		// UPDATE option_value = option_value + 1` is a single statement:
		// MySQL/MariaDB serializes concurrent callers on the option_name
		// unique key via an engine-level row lock for the duration of the
		// statement, so two concurrent callers can never both read the same
		// pre-increment value and both write the same post-increment value
		// -- the property a get_transient()/set_transient() pair lacks.
		//
		// The immediate follow-up SELECT (same connection) reads back the
		// count this call is responsible for. This was verified empirically
		// against a real MariaDB engine to be necessary: the tempting
		// alternative -- forcing $wpdb->insert_id via a LAST_INSERT_ID(expr)
		// literal in the upsert itself (the trick
		// LogsLookupRepository::insertLookupValueAndGetID() uses) -- does
		// NOT work here, because wp_options already has its own genuine
		// AUTO_INCREMENT column (option_id): on the INSERT branch (a real
		// new row), MySQL's mysql_insert_id()/$wpdb->insert_id reports the
		// real auto-generated option_id instead of the LAST_INSERT_ID(expr)
		// override. LogsLookupRepository's table has no such conflict --
		// its auto_increment column IS the value it wants back. A same-
		// connection SELECT immediately after a write always observes at
		// least that write (MySQL read-your-own-writes), so this can only
		// ever see this call's own count or a higher one from a concurrent
		// caller that landed in between -- never lower, and never a value
		// that lets more than max_requests through undetected.
		$upsertResult = $dbCore->queryAndGetResults(
			"INSERT INTO {wp_options} (option_name, option_value, autoload) "
			. "VALUES (%s, '1', 'no') "
			. "ON DUPLICATE KEY UPDATE option_value = option_value + 1",
			array('query_params' => array($value_option))
		);
		if (!empty($upsertResult['last_error'])) {
			return false;
		}
		$readResult = $dbCore->queryAndGetResults(
			"SELECT option_value FROM {wp_options} WHERE option_name = %s",
			array('query_params' => array($value_option))
		);
		$readRows = isset($readResult['rows']) && is_array($readResult['rows']) ? $readResult['rows'] : array();
		$firstRow = isset($readRows[0]) && is_array($readRows[0]) ? $readRows[0] : array();
		$rawCount = reset($firstRow);
		$count = is_scalar($rawCount) && is_numeric($rawCount) ? (int)$rawCount : 0;
		if (!empty($readResult['last_error']) || $count <= 0) {
			// Could not read back a valid count; don't block on an
			// unreliable read (fail toward allowing, per Defensive Coding #8).
			return false;
		}

		// Step 3: best-effort GC-hint row. INSERT IGNORE only takes effect
		// the first time this key is ever written (or the first write after
		// step 1 cleared an expired window) -- every later call within the
		// same window no-ops, leaving the original expiry untouched. This is
		// the "expiry set once" requirement from the finding: the window's
		// lifetime is fixed at creation and never extended by later
		// requests, unlike the old set_transient() fallback which refreshed
		// the TTL on every increment. Failure here is non-fatal: it only
		// delays the daily-cron GC sweep finding this key, never affects the
		// rate-limit decision above.
		$dbCore->queryAndGetResults(
			"INSERT IGNORE INTO {wp_options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')",
			array('query_params' => array($timeout_option, $now + $time_window))
		);

		return $count > $max_requests;
	}

	/**
	 * Atomic rate-limit counter for sites with a persistent object cache
	 * (Redis/Memcached). wp_cache_add() + wp_cache_incr() are true
	 * compare-and-swap / atomic-increment primitives at the cache backend
	 * level, so concurrent requests each get a distinct, correctly
	 * incremented count -- unlike a get_transient()/set_transient() pair.
	 *
	 * @param string $action
	 * @param string $identifier
	 * @param int $max_requests
	 * @param int $time_window
	 * @return bool True if rate limit exceeded, false otherwise
	 */
	private static function consumeRateLimitAtomic($action, $identifier, $max_requests, $time_window) {
		$key = $action . '_' . $identifier;
		$group = 'abj404_ratelimit';

		// No-op if another concurrent request already created the key; either
		// way, the key is guaranteed to exist by the time incr() runs below.
		ABJ_404_Solution_RateLimitOperationTracer::cacheCommand(
			'cache_add_initial',
			$key,
			$group,
			static fn() => wp_cache_add($key, 0, $group, $time_window)
		);
		$count = ABJ_404_Solution_RateLimitOperationTracer::cacheCommand(
			'cache_increment',
			$key,
			$group,
			static fn() => wp_cache_incr($key, 1, $group)
		);

		if ($count === false) {
			// The key expired/was evicted between add() and incr(). Start a
			// fresh window rather than blocking the request.
			ABJ_404_Solution_RateLimitOperationTracer::cacheCommand(
				'cache_add_fallback',
				$key,
				$group,
				static fn() => wp_cache_add($key, 1, $group, $time_window)
			);
			$count = 1;
		}

		return $count > $max_requests;
	}

	/**
	 * Legacy alias for callers that still use the old read-like name.
	 *
	 * @param string $action The action being rate limited
	 * @param int $max_requests Maximum requests allowed per time window
	 * @param int $time_window Time window in seconds
	 * @return bool True if rate limit exceeded, false otherwise
	 */
	static function checkRateLimit($action, $max_requests = 100, $time_window = 60) {
		return self::consumeRateLimit($action, $max_requests, $time_window);
	}

	/** Update plugin options via AJAX.
	 * @return void
	 */
	static function updateOptions() {
		ABJ_404_Solution_Ajax_UpdateOptions::updateOptions();
	}

	/** Load the Google Search Console options section asynchronously.
	 * @return void
	 */
	static function loadGscSection() {
		ABJ_404_Solution_Ajax_LoadGscSection::loadGscSection();
	}

    /** Find logs to display.
     * @return void
     */
    static function echoViewLogsFor() {
        ABJ_404_Solution_Ajax_ViewLogs::echoViewLogsFor();
    }

    /** Find pages to redirect to that match a search term, then echo the results in a json format.
     * @return void
     */
    static function echoRedirectToPages() {
        ABJ_404_Solution_Ajax_RedirectDestinationAutocomplete::echoRedirectToPages();
    }

    /** Add a message about whether there are too many results or none at all.
     * @param array<int, array<string, string>> $suggestions
     * @param string $term
     * @return array<int, array<string, string>>
     */
    function provideSearchFeedback($suggestions, $term) {
        return (new ABJ_404_Solution_Ajax_SearchFeedback())->provideSearchFeedback($suggestions, $term);
    }

    /** Remove any results from the list that don't match the search term.
     * @param array<int, array<string, string>> $pagesToFilter
     * @param string $searchTerm
     * @return array<int, array<string, string>>
     */
    function filterPages($pagesToFilter, $searchTerm) {
        return (new ABJ_404_Solution_Ajax_SearchFeedback())->filterPages($pagesToFilter, $searchTerm);
    }

    /** Create a "Home Page" destination.
     * @param bool $includeDefault404Page
     * @param bool $includeSpecial
     * @return array<int, array<string, string>>
     */
    function getDefaultRedirectDestinations($includeDefault404Page, $includeSpecial) {
        return ABJ_404_Solution_Ajax_RedirectDestinationAutocomplete::getDefaultRedirectDestinations(
            $includeDefault404Page,
            $includeSpecial
        );
    }

    /** Prepare categories for json output.
     * @param array<int, object{taxonomy: string, name: string, term_id: int|string}> $rows
     * @return array<int, array<string, string>>
     */
    function formatCategoryDestinations($rows) {
        return ABJ_404_Solution_Ajax_RedirectDestinationAutocomplete::formatCategoryDestinations($rows);
    }

    /** Prepare tags for json output.
     * @param array<int, object{name: string, term_id: int|string}> $rows
     * @return array<int, array<string, string>>
     */
    function formatTagDestinations($rows) {
        return ABJ_404_Solution_Ajax_RedirectDestinationAutocomplete::formatTagDestinations($rows);
    }

    /** Prepare custom categories for json output.
     * @param array<string, array<int, object{name: string, term_id: int|string}>> $customCategoriesMap
     * @return array<int, array<string, string>>
     */
    function formatCustomCategoryDestinations($customCategoriesMap) {
        return ABJ_404_Solution_Ajax_RedirectDestinationAutocomplete::formatCustomCategoryDestinations($customCategoriesMap);
    }

    /** Prepare pages and posts for json output.
     * @param array<int, object{post_title: string, post_type: string, id: int|string, depth: int|string}> $rows
     * @return array<int, array<string, string>>
     */
    function formatRedirectDestinations($rows) {
        return ABJ_404_Solution_Ajax_RedirectDestinationAutocomplete::formatRedirectDestinations($rows);
    }

    /** Prepare log results for json output.
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    function formatLogResults($rows) {
        return ABJ_404_Solution_Ajax_ViewLogs::formatLogResults($rows);
    }

	/**
	 * Consistent JSON response helper for endpoints that previously echoed JSON and exited.
	 *
	 * @param mixed $payload
	 * @param int $status
	 * @return void
	 */
	public static function sendJson($payload, $status = 200) {
		if (function_exists('wp_send_json')) {
			wp_send_json($payload, $status);
		}

		if (!headers_sent()) {
			header('Content-type: application/json; charset=UTF-8');
			if (function_exists('status_header')) {
				status_header($status);
			} elseif (function_exists('http_response_code')) {
				http_response_code($status);
			}
		}
		echo json_encode($payload);
		if (!apply_filters('abj404_should_exit', true, array('source' => 'ajaxPhp_sendJson'))) {
			return;
		}
		exit;
	}
}
