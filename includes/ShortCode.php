<?php

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ShortCode {
    
	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_ShortCode();
		}
		
		return self::$instance;
	}
	
	/** If we're currently redirecting to a custom 404 page and we are about to show page
	 * suggestions then update the URL displayed to the user. */
	static function updateURLbarIfNecessary() {
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$f = ABJ_404_Solution_Functions::getInstance();
		$abj404logging = ABJ_404_Solution_Logging::getInstance();
		$debugMessage = '';
        $options = $abj404logic->getOptions();
		
		$shouldUpdateURL = true;
		// if we're not supposed to update the URL then don't.
		if (!array_key_exists('update_suggest_url', $options) ||
				!isset($options['update_suggest_url']) ||
				$options['update_suggest_url'] != 1) {
			$shouldUpdateURL = false;
			$debugMessage .= "do not update (update_suggest_url is off), ";
		}

		// if the cookie we need isn't set then give up.
		$updateURLCookieName = ABJ404_PP . '_REQUEST_URI';
		$updateURLCookieName .= '_UPDATE_URL';
		if (!isset($_REQUEST[$updateURLCookieName]) || empty($_REQUEST[$updateURLCookieName])) {
			$shouldUpdateURL = false;
			$debugMessage .= "do not update (no cookie found), ";
		}

		$dest404page = (isset($options['dest404page']) ?
			$options['dest404page'] :
			ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED);

		// Check if this is a manual redirect (has query param) - these bypass global 404 page check
		$queryParamName = ABJ404_PP . '_ref';
		$isManualRedirect = isset($_GET[$queryParamName]) && !empty($_GET[$queryParamName]);

		// if we're not currently loading the custom 404 page then don't change the URL.
		// Exception: manual redirects to custom 404 pages should always allow URL restoration
		if ($isManualRedirect) {
			// Manual redirect - we know we're on a custom 404 page, allow URL restoration
			$debugMessage .= "ok to update (manual redirect to custom 404 page), ";
		} else if ($abj404logic->thereIsAUserSpecified404Page($dest404page)) {

			// get the user specified 404 page.
			$permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($dest404page, 0,
				null, $options);

			// if the last part of the URL does not match the custom 404 page then
			// don't update the URL.
			// Strip query string from REQUEST_URI for comparison (query params like abj404_solution_ref)
			$requestUriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
			if (!$f->endsWithCaseSensitive($permalink['link'], $requestUriPath) &&
					$permalink['status'] != 'trash') {

				$shouldUpdateURL = false;
				$debugMessage .= "do not update (not on custom 404 page (" .
					$permalink['link'] . ")), ";

			} else {
				$debugMessage .= "ok to update (displaying custom 404 page (" .
					$permalink['link'] . ")), ";
			}
		} else {
			// the 404 page is the default 404 page. so we shouldn't change the URL.
			$shouldUpdateURL = false;
			$debugMessage .= "do not update (no custom 404 page specified), ";
		}
		
		$content = '';
		
		if ($shouldUpdateURL) {
			// replace the current URL with the user's actual requested URL.
			$requestedURL = $_REQUEST[$updateURLCookieName];
			$userFriendlyURL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ?
				"https" : "http") . "://" . $_SERVER['HTTP_HOST'] . esc_url($requestedURL);

			// Use wp_json_encode to safely encode the URL for JavaScript to prevent XSS
			$content .= "window.history.replaceState({}, null, " .
				wp_json_encode($userFriendlyURL) . ");\n";

			$debugMessage .= "Updating the URL from " . $_SERVER['REQUEST_URI'] .
				" to " . esc_url($userFriendlyURL) . ", ";
		}
		
		if ($content != '') {
			$content = '<script language="JavaScript">' . "\n" . 
				$content .
				"\n</script>\n\n";
			echo $content;
		}
		
		$debugMessage .= "is404: " . is_404() . ", " . 
			esc_html('auto_redirects: ' . $options['auto_redirects'] . ', auto_score: ' .
			$options['auto_score'] . ', template_redirect_priority: ' . $options['template_redirect_priority'] .
            ', auto_cats: ' . $options['auto_cats'] . ', auto_tags: ' .
			$options['auto_tags'] . ', dest404page: ' . $options['dest404page']) . ", ";
		
		$debugMessage .= "is_single(): " . is_single() . " | " . "is_page(): " . is_page() .
			" | is_feed(): " . is_feed() . " | is_trackback(): " . is_trackback() . " | is_preview(): " .
			is_preview();
		
		$abj404logging->debugMessage("updateURLbarIfNecessary: " . $debugMessage);
	}
	
	/** 
     * @param array $atts
     */
    static function shortcodePageSuggestions( $atts ) {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404spellChecker = ABJ_404_Solution_SpellChecker::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        
        // Attributes
        $atts = shortcode_atts(
                array(
                    ),
                $atts
            );

        $options = $abj404logic->getOptions();
        
        $content = "\n<!-- " . ABJ404_PP . " - Begin 404 suggestions. -->\n";

        // get the slug that caused the 404 from the session.
        $urlRequest = '';
        $cookieName = ABJ404_PP . '_REQUEST_URI';
        if (isset($_COOKIE[$cookieName]) && !empty($_COOKIE[$cookieName])) {
            // Normalize URL using centralized function for consistency
            $urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($_COOKIE[$cookieName]));
            // delete the cookie because the request was a one-time thing.
            // we use javascript to delete the cookie because the headers have already been sent.
            $content .= "<script> \n" .
                    "   var d = new Date(); \n" . 
                    "   d.setTime(d.getTime() - (60 * 5)); \n" .
                    '   var expires = "expires="+ d.toUTCString(); ' . "\n" . 
                    '   document.cookie = "' . $cookieName . '=;" + expires + ";path=/"; ' . "\n" .
                    "</script> \n";
        }
        
        // we delete the UPDATE_URL cookie here, where the shortcode is used so that it won't
        // get deleted too early if multiple redirects happen.
        $updateURLCookieName = ABJ404_PP . '_REQUEST_URI';
        $updateURLCookieName .= '_UPDATE_URL';
        if (isset($_COOKIE[$updateURLCookieName]) && !empty($_COOKIE[$updateURLCookieName])) {
        	// Use UPDATE_URL cookie as fallback if primary cookie wasn't set
        	// (fixes: manual redirects to custom 404 pages not showing suggestions)
        	if ($urlRequest == '') {
        		// Normalize URL using centralized function for consistency
        		$urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($_COOKIE[$updateURLCookieName]));
        	}
        	// delete the cookie since we're done with it. it's a one-time use thing.
        	$content .= "<script> \n" .
         	"   var d = new Date(); /* delete the cookie */\n" .
         	"   d.setTime(d.getTime() - (60 * 5)); \n" .
         	'   var expires = "expires="+ d.toUTCString(); ' . "\n" .
         	'   document.cookie = "' . $updateURLCookieName . '=;" + expires + ";path=/"; ' .
         	"</script> \n";
        }

        if (isset($_REQUEST[ABJ404_PP]) &&
                isset($_REQUEST[ABJ404_PP][$cookieName])) {
            // Normalize URL using centralized function for consistency
            $urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($_REQUEST[ABJ404_PP][$cookieName]));
        }

        // Fallback: check for URL passed via query parameter
        // (fixes: cookies from 301 redirects aren't stored by browsers)
        $queryParamName = ABJ404_PP . '_ref';
        if ($urlRequest == '' && isset($_GET[$queryParamName]) && !empty($_GET[$queryParamName])) {
            // Normalize URL using centralized function for consistency
            $urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($_GET[$queryParamName]));
        }

        if ($urlRequest == '') {
            // if no 404 was detected then we don't offer any suggestions
            return "<!-- " . ABJ404_PP . " - No 404 was detected. No suggestions to offer. -->\n";
        }

        // Check for cached suggestion computation (transient-based)
        $urlKey = md5($urlRequest);
        $transientKey = 'abj404_suggest_' . $urlKey;
        $cachedData = get_transient($transientKey);

        if ($cachedData !== false) {
            if (isset($cachedData['status']) && $cachedData['status'] === 'complete') {
                // Suggestions ready - use cached data
                $content .= self::renderSuggestionsHTML(
                    isset($cachedData['suggestions']) ? $cachedData['suggestions'] : array(),
                    $urlRequest
                );
                $content .= "\n<!-- " . ABJ404_PP . " - End 404 suggestions (cached) -->\n";
                return $content;

            } elseif (isset($cachedData['status']) && $cachedData['status'] === 'pending') {
                // Still computing - show loading placeholder
                self::enqueueAsyncPollingScript($urlRequest);
                $content .= self::renderAsyncPlaceholder($urlRequest, $options);
                $content .= "\n<!-- " . ABJ404_PP . " - Suggestions loading -->\n";
                return $content;
            }
        }

        // No async data - fall back to synchronous computation
        $urlSlugOnly = $abj404logic->removeHomeDirectory($urlRequest);

        // Try cache first (populated by processRedirect() for existing redirects)
        $permalinkSuggestionsPacket = $abj404spellChecker->getFromPermalinkCache($urlSlugOnly);

        // If cache miss, compute suggestions
        if (empty($permalinkSuggestionsPacket) || empty($permalinkSuggestionsPacket[0])) {
            $permalinkSuggestionsPacket = $abj404spellChecker->findMatchingPosts($urlSlugOnly,
                    @$options['suggest_cats'], @$options['suggest_tags']);
        }

        // Ensure suggestions is an array (cache may return stdClass from json_decode)
        $permalinkSuggestions = isset($permalinkSuggestionsPacket[0]) ? (array)$permalinkSuggestionsPacket[0] : [];
        $rowType = isset($permalinkSuggestionsPacket[1]) ? $permalinkSuggestionsPacket[1] : 'pages';

        $showExtraAdminData = (is_user_logged_in() && $abj404logic->userIsPluginAdmin());
        $extraData = null;
        $extraDataById = []; // <--- New: Array to hold extra data indexed by ID
        $adminDebugData = []; // <--- New: Array to collect data for JS
        
        if ($showExtraAdminData) {
            // add extra information to the permalinkSuggestionsPacket. for each permalink,
            // retrieve the post_type, taxonomy, post_author (this is an id not a name), 
            // post_date, post_name (this is the slug), 
            $postIDs = array_keys($permalinkSuggestions);
            if (!empty($postIDs)) {
                // for each id remove the part after '|' using substring
                foreach ($postIDs as $index => $id) {
                    $postIDs[$index] = $f->substr($id, 0, $f->strpos($id, '|'));
                }
                
                $rawExtraData = $abj404dao->getExtraDataToPermalinkSuggestions($postIDs); 
                foreach ($rawExtraData as $dataItem) {
                    $extraDataById['post_id_' . $dataItem['post_id']] = $dataItem;
                    $extraDataById['term_id_' . $dataItem['term_id']] = $dataItem;
                }
            }
        }

        // allow some HTML.
        $content .= '<div class="suggest-404s">' . "\n";
        $content .= wp_kses_post(
            str_replace('{suggest_title_text}', __('Here are some other great pages', '404-solution'),
                $options['suggest_title'] )) . "\n";
        
        $currentSlug = $abj404logic->removeHomeDirectory(
                $f->regexReplace('\?.*', '', $f->normalizeUrlString($_SERVER['REQUEST_URI'])));
        $displayed = 0;
        $commentPartAndQueryPart = $abj404logic->getCommentPartAndQueryPartOfRequest();

        // Check if minimum score filtering is enabled
        $minScoreEnabled = isset($options['suggest_minscore_enabled']) && $options['suggest_minscore_enabled'] == '1';
        $minScore = $minScoreEnabled ? (isset($options['suggest_minscore']) ? intval($options['suggest_minscore']) : 25) : 0;

        foreach ($permalinkSuggestions as $idAndType => $linkScore) {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, $linkScore,
            	$rowType, $options);

            // Skip if we're currently on the page we're about to suggest
            if (basename($permalink['link']) == $currentSlug) {
                continue;
            }

            // Skip if minimum score filtering is enabled and score is below threshold
            if ($minScoreEnabled && $permalink['score'] < $minScore) {
                continue;
            }

            if ($displayed == 0) {
                // <ol>
                $content .= wp_kses_post($options['suggest_before']);
            }

            // <li>
            $content .= wp_kses_post($options['suggest_entrybefore']);

            $content .= "<a href=\"" . esc_url($permalink['link']) . $commentPartAndQueryPart .
                "\" title=\"" . esc_attr($permalink['title']) . "\">" .
                esc_attr($permalink['title']) . "</a>";

            // display the score after the page link

            if ($showExtraAdminData) {
                $idParts = explode('|', $idAndType);
                $currentId = isset($idParts[0]) ? (int)$idParts[0] : null;
                $typeCode  = isset($idParts[1]) ? $idParts[1] : null;

                $currentSuggestionData = [
                    'Title' => $permalink['title'],
                    'Link' => $permalink['link'],
                    'Score' => number_format($permalink['score'], 2),
                    'ID_Type_Code' => $idAndType, // e.g., "123|1" or "94|2"
                ];

                // Extract ID for lookup
                $idParts = explode('|', $idAndType);
                $currentId = isset($idParts[0]) ? $idParts[0] : null;

                // Merge extra data if available (post may have been deleted since suggestions were cached)
                if ($typeCode == '1') { // It's a Post
                    $extraKey = 'post_id_' . $currentId;
                    if (isset($extraDataById[$extraKey])) {
                        $currentSuggestionData = $currentSuggestionData + $extraDataById[$extraKey];
                    }
                } else { // It's a Term
                    $extraKey = 'term_id_' . $currentId;
                    if (isset($extraDataById[$extraKey])) {
                        $currentSuggestionData = $currentSuggestionData + $extraDataById[$extraKey];
                    }
                }

                // Add this suggestion's data to the array for JS
                $adminDebugData[] = $currentSuggestionData;

                // Make the score clickable
                $content .= ' (<a href="#" onclick="show404AdminDebugData(); return false;" title="' .
                            esc_attr__('Click to view debug data for all suggestions', '404-solution') .
                            '">' . number_format($permalink['score'], 2) . // Format score
                            '</a>)';
            }

            // </li>
            $content .= wp_kses_post(@$options['suggest_entryafter']) . "\n";
            $displayed++;
            if ($displayed >= $options['suggest_max']) {
                break;
            }
        }
        if ($displayed >= 1) {
            // </ol>
            $content .= wp_kses_post($options['suggest_after']) . "\n";
            
        } else {
            $content .= wp_kses_post(
                str_replace('{suggest_noresults_text}', __('No suggestions. :/ ', '404-solution'),
                    $options['suggest_noresults'] ));            
        }

        $content .= "\n</div>";

        if ($showExtraAdminData && !empty($adminDebugData)) {
            // Ensure the JSON is properly encoded and escaped for JavaScript
            $allSuggestionsJson = wp_json_encode($adminDebugData);
            if ($allSuggestionsJson === false) {
                // Handle encoding error
                $allSuggestionsJson = '[]';
            }

            $content .= "<script type=\"text/javascript\">\n";
            $content .= "var abj404_suggestionData = " . $allSuggestionsJson . ";\n";
            $content .= "function show404AdminDebugData() {\n";
            $content .= "    var debugText = 'Suggestion Debug Data:\\n====================\\n\\n';\n";
            $content .= "    if (typeof abj404_suggestionData !== 'undefined' && abj404_suggestionData.length > 0) {\n";
            $content .= "        for (var i = 0; i < abj404_suggestionData.length; i++) {\n";
            $content .= "            var item = abj404_suggestionData[i];\n";
            $content .= "            debugText += 'Suggestion #' + (i + 1) + ':\\n';\n";
            $content .= "            for (var key in item) {\n";
            $content .= "                if (item.hasOwnProperty(key) && item[key]) {\n";
            $content .= "                    // Only include properties that have values\n";
            $content .= "                    // Format the key for display (capitalize first letter)\n";
            $content .= "                    var displayKey = key;\n";
            $content .= "                    // Escape any potentially harmful content using text nodes\n";
            $content .= "                    debugText += '  ' + displayKey + ': ' + String(item[key]).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '\\n';\n";
            $content .= "                }\n";
            $content .= "            }\n";
            $content .= "            debugText += '--------------------\\n';\n";
            $content .= "        }\n";
            $content .= "    } else {\n";
            $content .= "        debugText += 'No suggestion data collected.';\n";
            $content .= "    }\n";
            $content .= "    \n";
            $content .= "    // Create a modal dialog with copyable text\n";
            $content .= "    var modalOverlay = document.createElement('div');\n";
            $content .= "    modalOverlay.style.position = 'fixed';\n";
            $content .= "    modalOverlay.style.top = '0';\n";
            $content .= "    modalOverlay.style.left = '0';\n";
            $content .= "    modalOverlay.style.width = '100%';\n";
            $content .= "    modalOverlay.style.height = '100%';\n";
            $content .= "    modalOverlay.style.backgroundColor = 'rgba(0,0,0,0.5)';\n";
            $content .= "    modalOverlay.style.zIndex = '9999';\n";
            $content .= "    \n";
            $content .= "    var modalContent = document.createElement('div');\n";
            $content .= "    modalContent.style.position = 'absolute';\n";
            $content .= "    modalContent.style.top = '50%';\n";
            $content .= "    modalContent.style.left = '50%';\n";
            $content .= "    modalContent.style.transform = 'translate(-50%, -50%)';\n";
            $content .= "    modalContent.style.backgroundColor = 'white';\n";
            $content .= "    modalContent.style.padding = '20px';\n";
            $content .= "    modalContent.style.borderRadius = '5px';\n";
            $content .= "    modalContent.style.maxWidth = '80%';\n";
            $content .= "    modalContent.style.maxHeight = '80%';\n";
            $content .= "    modalContent.style.overflow = 'auto';\n";
            $content .= "    \n";
            $content .= "    var textArea = document.createElement('textarea');\n";
            $content .= "    textArea.style.width = '100%';\n";
            $content .= "    textArea.style.height = '300px';\n";
            $content .= "    textArea.style.marginBottom = '10px';\n";
            $content .= "    // Set value safely using textContent\n";
            $content .= "    textArea.value = debugText;\n";
            $content .= "    textArea.readOnly = true;\n";
            $content .= "    \n";
            $content .= "    var copyButton = document.createElement('button');\n";
            $content .= "    // Using textContent instead of innerHTML\n";
            $content .= "    copyButton.textContent = 'Copy to Clipboard';\n";
            $content .= "    copyButton.style.marginRight = '10px';\n";
            $content .= "    copyButton.onclick = function() {\n";
            $content .= "        textArea.select();\n";
            $content .= "        document.execCommand('copy');\n";
            $content .= "    };\n";
            $content .= "    \n";
            $content .= "    var closeButton = document.createElement('button');\n";
            $content .= "    // Using textContent instead of innerHTML\n";
            $content .= "    closeButton.textContent = 'Close';\n";
            $content .= "    closeButton.onclick = function() {\n";
            $content .= "        document.body.removeChild(modalOverlay);\n";
            $content .= "    };\n";
            $content .= "    \n";
            $content .= "    modalContent.appendChild(textArea);\n";
            $content .= "    modalContent.appendChild(copyButton);\n";
            $content .= "    modalContent.appendChild(closeButton);\n";
            $content .= "    modalOverlay.appendChild(modalContent);\n";
            $content .= "    document.body.appendChild(modalOverlay);\n";
            $content .= "}\n";
            $content .= "</script>\n";
        }

        $content .= "\n<!-- " . ABJ404_PP . " - End 404 suggestions for slug " . esc_html($urlSlugOnly) . " -->\n";

        return $content;
    }

    /**
     * Render suggestions HTML from pre-computed data (for AJAX polling response).
     * This method is called by Ajax_SuggestionPolling when suggestions are ready.
     *
     * @param array $suggestionsPacket The suggestions data from findMatchingPosts()
     * @param string $requestedURL The original 404 URL (for debugging)
     * @return string HTML content for suggestions
     */
    public static function renderSuggestionsHTML($suggestionsPacket, $requestedURL = '') {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        $options = $abj404logic->getOptions();

        // Ensure suggestions is an array (cache may return stdClass from json_decode)
        $permalinkSuggestions = isset($suggestionsPacket[0]) ? (array)$suggestionsPacket[0] : [];
        $rowType = isset($suggestionsPacket[1]) ? $suggestionsPacket[1] : 'pages';

        // Check if user is plugin admin to show scores
        $showExtraAdminData = (is_user_logged_in() && $abj404logic->userIsPluginAdmin());

        $content = '<div class="suggest-404s">' . "\n";
        $content .= wp_kses_post(
            str_replace('{suggest_title_text}', __('Here are some other great pages', '404-solution'),
                $options['suggest_title'] )) . "\n";

        $currentSlug = '';
        if (isset($_SERVER['REQUEST_URI'])) {
            $currentSlug = $abj404logic->removeHomeDirectory(
                $f->regexReplace('\?.*', '', $f->normalizeUrlString($_SERVER['REQUEST_URI'])));
        }

        $displayed = 0;
        $commentPartAndQueryPart = $abj404logic->getCommentPartAndQueryPartOfRequest();

        // Check if minimum score filtering is enabled
        $minScoreEnabled = isset($options['suggest_minscore_enabled']) && $options['suggest_minscore_enabled'] == '1';
        $minScore = $minScoreEnabled ? (isset($options['suggest_minscore']) ? intval($options['suggest_minscore']) : 25) : 0;

        foreach ($permalinkSuggestions as $idAndType => $linkScore) {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, $linkScore,
                $rowType, $options);

            // Skip if we're currently on the page we're about to suggest
            if ($currentSlug !== '' && basename($permalink['link']) == $currentSlug) {
                continue;
            }

            // Skip if minimum score filtering is enabled and score is below threshold
            if ($minScoreEnabled && $permalink['score'] < $minScore) {
                continue;
            }

            if ($displayed == 0) {
                // <ol>
                $content .= wp_kses_post($options['suggest_before']);
            }

            // <li>
            $content .= wp_kses_post($options['suggest_entrybefore']);

            $content .= "<a href=\"" . esc_url($permalink['link']) . $commentPartAndQueryPart .
                "\" title=\"" . esc_attr($permalink['title']) . "\">" .
                esc_attr($permalink['title']) . "</a>";

            // Display the score after the page link (admin only)
            if ($showExtraAdminData) {
                $content .= ' (' . number_format($permalink['score'], 4) . ')';
            }

            // </li>
            $content .= wp_kses_post(@$options['suggest_entryafter']) . "\n";
            $displayed++;
            if ($displayed >= $options['suggest_max']) {
                break;
            }
        }

        if ($displayed >= 1) {
            // </ol>
            $content .= wp_kses_post($options['suggest_after']) . "\n";
        } else {
            $content .= wp_kses_post(
                str_replace('{suggest_noresults_text}', __('No suggestions. :/ ', '404-solution'),
                    $options['suggest_noresults'] ));
        }

        $content .= "\n</div>";

        return $content;
    }

    /**
     * Render a loading placeholder for async suggestions.
     * Shows skeleton loading animation while suggestions are being computed.
     *
     * @param string $requestedURL The 404 URL being looked up
     * @param array $options Plugin options
     * @return string HTML placeholder with loading state
     */
    public static function renderAsyncPlaceholder($requestedURL, $options) {
        $suggestMax = isset($options['suggest_max']) ? intval($options['suggest_max']) : 5;

        // Generate skeleton items based on suggest_max
        $skeletons = '';
        for ($i = 0; $i < $suggestMax; $i++) {
            $skeletons .= '<li class="abj404-skeleton"></li>' . "\n";
        }

        $content = '<div id="abj404-suggestions-placeholder" class="suggest-404s" ' .
            'data-requested-url="' . esc_attr($requestedURL) . '">' . "\n";
        $content .= wp_kses_post(
            str_replace('{suggest_title_text}', __('Here are some other great pages', '404-solution'),
                $options['suggest_title'] )) . "\n";
        $content .= wp_kses_post($options['suggest_before']);
        $content .= '<div class="abj404-loading">' . "\n";
        $content .= $skeletons;
        $content .= '</div>' . "\n";
        $content .= wp_kses_post($options['suggest_after']) . "\n";
        $content .= '</div>';

        return $content;
    }

    /**
     * Enqueue the async suggestion polling JavaScript.
     *
     * @param string $requestedURL The 404 URL for polling
     */
    public static function enqueueAsyncPollingScript($requestedURL) {
        // Enqueue jQuery dependency
        wp_enqueue_script('jquery');

        // Enqueue polling script
        wp_enqueue_script(
            'abj404-suggestion-polling',
            plugin_dir_url(__FILE__) . 'ajax/SuggestionPolling.js',
            array('jquery'),
            ABJ404_VERSION,
            true // Load in footer
        );

        // Pass AJAX URL, nonce, and localized strings to JavaScript
        wp_localize_script('abj404-suggestion-polling', 'abj404_suggestions', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('abj404_poll_suggestions'),
            'no_suggestions_text' => __('No suggestions. :/ ', '404-solution')
        ));

        // Enqueue loading CSS
        wp_enqueue_style(
            'abj404-suggestions-loading',
            plugin_dir_url(__FILE__) . 'css/suggestions-loading.css',
            array(),
            ABJ404_VERSION
        );
    }

}
