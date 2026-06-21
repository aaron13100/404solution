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

		$transient_key = 'abj404_rate_limit_' . $action . '_' . $identifier;
		$request_count = get_transient($transient_key);

		if ($request_count === false) {
			set_transient($transient_key, 1, $time_window);
			return false;
		} elseif ($request_count >= $max_requests) {
			return true;
		} else {
			set_transient($transient_key, $request_count + 1, $time_window);
			return false;
		}
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
