<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Funtcions supporting Ajax stuff.  */

class ABJ_404_Solution_Ajax_Php {

	private static $instance = null;
	
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
	static function checkRateLimit($action, $max_requests = 100, $time_window = 60) {
		// Get user identifier (prefer user ID, fallback to IP)
		$user_id = get_current_user_id();
		if ($user_id) {
			$identifier = 'user_' . $user_id;
		} else {
			$remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
			$identifier = 'ip_' . md5($remote);
		}

		$transient_key = 'abj404_rate_limit_' . $action . '_' . $identifier;
		$request_count = get_transient($transient_key);

		if ($request_count === false) {
			// First request in this time window
			set_transient($transient_key, 1, $time_window);
			return false;
		} elseif ($request_count >= $max_requests) {
			// Rate limit exceeded
			return true;
		} else {
			// Increment counter
			set_transient($transient_key, $request_count + 1, $time_window);
			return false;
		}
	}

	private static function getServiceIfAvailable($serviceName) {
		if (!function_exists('abj_service') || !class_exists('ABJ_404_Solution_ServiceContainer')) {
			return null;
		}
		try {
			$c = ABJ_404_Solution_ServiceContainer::getInstance();
			if (is_object($c) && method_exists($c, 'has') && $c->has($serviceName)) {
				return $c->get($serviceName);
			}
		} catch (Throwable $e) {
			// fall through
		}
		return null;
	}

	/** Update plugin options via AJAX. */
	static function updateOptions() {
		$logic = self::getServiceIfAvailable('plugin_logic');
		$abj404logic = $logic !== null ? $logic : ABJ_404_Solution_PluginLogic::getInstance();

		// Verify user has appropriate capabilities (respects plugin admin users)
		if (!$abj404logic->userIsPluginAdmin()) {
			wp_send_json_error(array('message' => 'Unauthorized'), 403);
			return;
		}

		// Verify nonce for CSRF protection
		// The nonce is sent as part of the form data which is JSON-encoded in 'encodedData'
		if (isset($_POST['encodedData'])) {
			$f = ABJ_404_Solution_Functions::getInstance();
			$postData = $f->decodeComplicatedData($_POST['encodedData']);
			$nonce = isset($postData['nonce']) ? $postData['nonce'] : '';
			if (!wp_verify_nonce($nonce, 'abj404UpdateOptions')) {
				wp_send_json_error(array('message' => 'Invalid security token'), 403);
				return;
			}
		} else {
			wp_send_json_error(array('message' => 'Missing form data'), 400);
			return;
		}

		$result = $abj404logic->updateOptionsFromPOST();
		if (!is_array($result) || !array_key_exists('success', $result)) {
			wp_send_json_error(array('message' => 'Server error'), 500);
			return;
		}

		if (!$result['success']) {
			$status = array_key_exists('status', $result) ? intval($result['status']) : 400;
			$message = array_key_exists('message', $result) ? (string)$result['message'] : 'Server error';
			wp_send_json_error(array('message' => $message), $status);
			return;
		}

		$data = array_key_exists('data', $result) ? $result['data'] : array();
		wp_send_json_success($data, 200);
	}

	/**
	 * Consistent JSON response helper for endpoints that previously echoed JSON and exited.
	 *
	 * In production, wp_send_json() terminates the request.
	 * In unit tests, callers can stub wp_send_json() or define ABJ404_TEST_NO_EXIT.
	 *
	 * @param mixed $payload
	 * @param int $status
	 * @return void
	 */
	private static function sendJson($payload, $status = 200) {
		if (function_exists('wp_send_json')) {
			wp_send_json($payload, $status);
			if (defined('ABJ404_TEST_NO_EXIT') && ABJ404_TEST_NO_EXIT) {
				return;
			}
			// wp_send_json() calls wp_die(); this is a safety net for environments that stub it.
			exit;
		}

		// Fallback for unusual environments (should not happen in WordPress).
		if (!headers_sent()) {
			header('Content-type: application/json; charset=UTF-8');
			if (function_exists('status_header')) {
				status_header($status);
			} elseif (function_exists('http_response_code')) {
				http_response_code($status);
			}
		}
		echo json_encode($payload);
		if (defined('ABJ404_TEST_NO_EXIT') && ABJ404_TEST_NO_EXIT) {
			return;
		}
		exit;
	}

	private static function buildAutocompleteErrorItem($message) {
		return array(
			array(
				'label' => '',
				'category' => (string)$message,
				'value' => '',
				'data_overflow_item' => 'true',
			),
		);
	}
	
    /** Find logs to display. */
    static function echoViewLogsFor() {
    	$abj404AjaxPhp = ABJ_404_Solution_Ajax_Php::getInstance();;
        $dao = self::getServiceIfAvailable('data_access');
        $abj404dao = $dao !== null ? $dao : ABJ_404_Solution_DataAccess::getInstance();
        $funcs = self::getServiceIfAvailable('functions');
        $f = $funcs !== null ? $funcs : ABJ_404_Solution_Functions::getInstance();

        // Verify nonce for CSRF protection
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'abj404_ajax')) {
            // Keep HTTP 200 for jQuery UI autocomplete (it does not process non-2xx JSON well).
            self::sendJson(self::buildAutocompleteErrorItem(__('Invalid security token', '404-solution')), 200);
            return;
        }

        // Verify user has appropriate capabilities (respects plugin admin users)
        $logic = self::getServiceIfAvailable('plugin_logic');
        $abj404logic = $logic !== null ? $logic : ABJ_404_Solution_PluginLogic::getInstance();
        if (!$abj404logic->userIsPluginAdmin()) {
            self::sendJson(self::buildAutocompleteErrorItem(__('Unauthorized', '404-solution')), 200);
            return;
        }

        // Rate limiting to prevent abuse (100 requests per minute)
        if (self::checkRateLimit('view_logs', 100, 60)) {
            self::sendJson(self::buildAutocompleteErrorItem(__('Rate limit exceeded. Please try again later.', '404-solution')), 200);
            return;
        }

        // Limit search term length to prevent DoS
        $termRaw = array_key_exists('term', $_GET) ? $_GET['term'] : '';
        $term = $f->strtolower(sanitize_text_field($termRaw));
        $term = substr($term, 0, 100);
        $suggestions = array();

        $suggestion = array();
        $suggestion['label'] = __('(Show All Logs)', '404-solution');
        $suggestion['category'] = 'Special';
        $suggestion['value'] = 0;
        $specialSuggestion = array();
        $specialSuggestion[] = $suggestion;
        
        // Pass the raw term; getLogsIDandURLLike() builds the LIKE pattern safely.
        $rows = $abj404dao->getLogsIDandURLLike($term, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
        $results = $abj404AjaxPhp->formatLogResults($rows);
        
        // limit search results
        $suggestions = $abj404AjaxPhp->provideSearchFeedback($results, $term);
        
        $suggestions = array_merge($specialSuggestion, $suggestions);
                
        self::sendJson($suggestions, 200);
        return;
    }
    
    /** Find pages to redirect to that match a search term, then echo the results in a json format. */
    static function echoRedirectToPages() {
        $logic = self::getServiceIfAvailable('plugin_logic');
        $abj404logic = $logic !== null ? $logic : ABJ_404_Solution_PluginLogic::getInstance();
        $abj404AjaxPhp = ABJ_404_Solution_Ajax_Php::getInstance();
        $dao = self::getServiceIfAvailable('data_access');
        $abj404dao = $dao !== null ? $dao : ABJ_404_Solution_DataAccess::getInstance();
        $funcs = self::getServiceIfAvailable('functions');
        $f = $funcs !== null ? $funcs : ABJ_404_Solution_Functions::getInstance();

        // Verify nonce for CSRF protection
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'abj404_ajax')) {
            self::sendJson(self::buildAutocompleteErrorItem(__('Invalid security token', '404-solution')), 200);
            return;
        }

        // Verify user has appropriate capabilities (respects plugin admin users)
        if (!$abj404logic->userIsPluginAdmin()) {
            self::sendJson(self::buildAutocompleteErrorItem(__('Unauthorized', '404-solution')), 200);
            return;
        }

        // Rate limiting to prevent abuse (100 requests per minute)
        if (self::checkRateLimit('redirect_pages', 100, 60)) {
            self::sendJson(self::buildAutocompleteErrorItem(__('Rate limit exceeded. Please try again later.', '404-solution')), 200);
            return;
        }

        // Limit search term length to prevent DoS
        $termRaw = array_key_exists('term', $_GET) ? $_GET['term'] : '';
        $term = $f->strtolower(sanitize_text_field($termRaw));
        $term = substr($term, 0, 100);
        $includeDefault404PageRaw = array_key_exists('includeDefault404Page', $_GET) ? $_GET['includeDefault404Page'] : 'false';
        $includeDefault404Page = $includeDefault404PageRaw == "true";
        $includeSpecial = array_key_exists('includeSpecial', $_GET) &&
        	$_GET['includeSpecial'] == "true";
        $suggestions = array();

        // add the "Home Page" destination.
        $specialPages = $abj404AjaxPhp->getDefaultRedirectDestinations($includeDefault404Page,
        	$includeSpecial);

        // Query to get the posts and pages matching the search term
        $rowsOtherTypes = $abj404dao->getPublishedPagesAndPostsIDs('', $term, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
        // order the results. this also sets the page depth (for child pages).
        $rowsOtherTypes = $abj404logic->orderPageResults($rowsOtherTypes, true);
        $publishedPosts = $abj404AjaxPhp->formatRedirectDestinations($rowsOtherTypes);

        $cats = $abj404dao->getPublishedCategories(null, null, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
        $categoryOptions = $abj404AjaxPhp->formatCategoryDestinations($cats);

        $tags = $abj404dao->getPublishedTags(null, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
        $tagOptions = $abj404AjaxPhp->formatTagDestinations($tags);

        $customCategoriesMap = $abj404logic->getMapOfCustomCategories($cats);
        $customCategoryOptions = $abj404AjaxPhp->formatCustomCategoryDestinations($customCategoriesMap);

        // ---------------------------------------
        // now we filter the results based on the search term.
        $specialPages = $abj404AjaxPhp->filterPages($specialPages, $term);
        $categoryOptions = $abj404AjaxPhp->filterPages($categoryOptions, $term);
        $tagOptions = $abj404AjaxPhp->filterPages($tagOptions, $term);
        $customCategoryOptions = $abj404AjaxPhp->filterPages($customCategoryOptions, $term);

        // combine and display the search results.
        $suggestions = array_merge($specialPages, $publishedPosts, $categoryOptions, $tagOptions,
                $customCategoryOptions);

        // limit search results
        $suggestions = $abj404AjaxPhp->provideSearchFeedback($suggestions, $term);

        self::sendJson($suggestions, 200);
        return;
    }

    /** Add a message about whether there are too many results or none at all.
     * @param array $suggestions
     * @param string $suggestions
     * @return string
     */
    function provideSearchFeedback($suggestions, $term) {
        $f = ABJ_404_Solution_Functions::getInstance();
        $category = '';
        
        if (empty($suggestions)) {
            // tell the user if there are no resluts.
            if (trim($f->strlen($term)) == 0) {
                $category = sprintf(__("(No matching results found.)", '404-solution'));
            } else {
                $category = sprintf(__("(No matching results found for \"%s.\")", '404-solution'), $term);
            }
            
        } else if (count($suggestions) > ABJ404_MAX_AJAX_DROPDOWN_SIZE) {
            // limit the results if there are too many
            $suggestions = array_slice($suggestions, 0, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
            if (trim($f->strlen($term)) == 0) {
                $category = sprintf(__("(Data truncated. Too many results!)", '404-solution'));
            } else {
                $category = sprintf(__("(Data truncated. Too many results for \"%s!\".)", '404-solution'), $term);
            }
            
        } else {
            if (trim($f->strlen($term)) == 0) {
                $category = sprintf(__("(All results displayed.)", '404-solution'));
            } else {
                $category = sprintf(__("(All results displayed for \"%s.\")", '404-solution'), $term);
            }
        }
        
        $suggestion = array();
        $suggestion['label'] = '';
        $suggestion['category'] = $category;
        $suggestion['value'] = '';
        $suggestion['data_overflow_item'] = 'true';
        $suggestions[] = $suggestion;
        
        return $suggestions;
    }
    
    /** Remove any results from the list that don't match the search term.
     * @param array $pagesToFilter
     * @param string $searchTerm
     * @return array
     */
    function filterPages($pagesToFilter, $searchTerm) {
        $f = ABJ_404_Solution_Functions::getInstance();
        if ($searchTerm == "") {
            return $pagesToFilter;
        }        

        // build a new list with only the included results to return.
        $newPagesList = array();
        
        foreach ($pagesToFilter as $page) {
        	$haystack = $f->strtolower($page['label']);
        	$haystack2 = $f->strtolower($page['category']);
        	$needle = $f->strtolower($searchTerm);
        	if ($f->strpos($haystack, $needle) !== false) {
        		$newPagesList[] = $page;
        	} else if ($f->strpos($haystack2, $needle) !== false) {
        		$newPagesList[] = $page;
        	}
        }
        
        return $newPagesList;
    }
    
    /** Create a "Home Page" destination.
     * @return string
     */
    function getDefaultRedirectDestinations($includeDefault404Page, $includeSpecial) {
        $arrayWrapper = array();
        $suggestion = array();
        
        // --- default 404 page
        if ($includeSpecial && $includeDefault404Page) {
            $suggestion['category'] = __('Special', '404-solution');
            $suggestion['label'] = __('(Default 404 Page)', '404-solution');
            $suggestion['value'] = ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED;
            // depth 0 means it's not a child page
            $suggestion['depth'] = '0';
            $arrayWrapper[] = $suggestion;
        }
        
        if ($includeSpecial) {
	        // --- home page
	        $suggestion['category'] = __('Special', '404-solution');
	        $suggestion['label'] = __('Home Page', '404-solution');
	        $suggestion['value'] = ABJ404_TYPE_HOME . '|' . ABJ404_TYPE_HOME;
	        // depth 0 means it's not a child page
	        $suggestion['depth'] = '0';
	        $arrayWrapper[] = $suggestion;
        }
        
        return $arrayWrapper;
    }
    
    /** Prepare categories for json output.
     * @param array $rows
     * @return string
     */
    function formatCategoryDestinations($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            if ($row->taxonomy != 'category') {
                continue;
            }
            
            $suggestion = array();
            $suggestion['label'] = $row->name;
            $suggestion['category'] = __('Categories', '404-solution');
            $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_CAT;
            // depth 0 means it's not a child page
            $suggestion['depth'] = '0';
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }
    
    /** Prepare tags for json output.
     * @param array $rows
     * @return string
     */
    function formatTagDestinations($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row->name;
            $suggestion['category'] = __('Tags', '404-solution');
            $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_TAG;
            // depth 0 means it's not a child page
            $suggestion['depth'] = '0';
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }
    
    /** Prepare custom categories for json output. 
     * @param array $customCategoriesMap
     * @return string
     */
    function formatCustomCategoryDestinations($customCategoriesMap) {
        $suggestions = array();
        
        foreach ($customCategoriesMap as $taxonomy => $rows) {
        
            foreach ($rows as $row) {

                $suggestion = array();
                $suggestion['label'] = $row->name;
                $suggestion['category'] = $taxonomy;
                $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_CAT;
                // depth 0 means it's not a child page
                $suggestion['depth'] = '0';

                $suggestions[] = $suggestion;
            }
        }
        
        return $suggestions;
    }
    
    /** Prepare pages and posts for json output. 
     * @param array $rows
     * @return array
     */
    function formatRedirectDestinations($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row->post_title;
            $suggestion['category'] = ucwords($row->post_type);
            $suggestion['value'] = $row->id . "|" . ABJ404_TYPE_POST;
            // depth 0 means it's not a child page
            $suggestion['depth'] = $row->depth;
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }

    /** Prepare log results for json output. 
     * @param array $rows
     * @return array
     */
    function formatLogResults($rows) {
        $suggestions = array();
        
        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row['requested_url'];
            $suggestion['category'] = 'Normal';
            $suggestion['value'] = $row['logsid'];
            
            $suggestions[] = $suggestion;
        }
        
        return $suggestions;
    }
    
}
