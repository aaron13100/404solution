<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ShortCode {
    
	/** @var self|null */
	private static $instance = null;

	/** @return self */
	public static function getInstance(): self {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_ShortCode();
		}
		
		return self::$instance;
	}
	
	/** If we're currently redirecting to a custom 404 page and we are about to show page
	 * suggestions then update the URL displayed to the user.
	 * @return void
	 */
	static function updateURLbarIfNecessary(): void {
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

		$dest404pageRaw = (isset($options['dest404page']) ?
			$options['dest404page'] :
			ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED);
		$dest404page = is_string($dest404pageRaw) ? $dest404pageRaw : (ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED);

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
			$requestUriRaw = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
			$requestUriPath = parse_url($requestUriRaw, PHP_URL_PATH);
			$permLinkStr = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
			$requestUriPathStr = is_string($requestUriPath) ? $requestUriPath : '';
			if (!$f->endsWithCaseSensitive($permLinkStr, $requestUriPathStr) &&
					$permalink['status'] != 'trash') {

				$shouldUpdateURL = false;
				$debugMessage .= "do not update (not on custom 404 page (" .
					$permLinkStr . ")), ";

			} else {
				$debugMessage .= "ok to update (displaying custom 404 page (" .
					$permLinkStr . ")), ";
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

			$currentReqUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
			$debugMessage .= "Updating the URL from " . $currentReqUri .
				" to " . esc_url($userFriendlyURL) . ", ";
		}
		
		if ($content != '') {
			$content = '<script language="JavaScript">' . "\n" . 
				$content .
				"\n</script>\n\n";
			echo $content;
		}
		
		$scAutoRedirects = isset($options['auto_redirects']) && is_scalar($options['auto_redirects']) ? (string)$options['auto_redirects'] : '';
		$scAutoScore = isset($options['auto_score']) && is_scalar($options['auto_score']) ? (string)$options['auto_score'] : '';
		$scTemplatePriority = isset($options['template_redirect_priority']) && is_scalar($options['template_redirect_priority']) ? (string)$options['template_redirect_priority'] : '';
		$scAutoCats = isset($options['auto_cats']) && is_scalar($options['auto_cats']) ? (string)$options['auto_cats'] : '';
		$scAutoTags = isset($options['auto_tags']) && is_scalar($options['auto_tags']) ? (string)$options['auto_tags'] : '';
		$scDest404 = isset($options['dest404page']) && is_scalar($options['dest404page']) ? (string)$options['dest404page'] : '';
		$debugMessage .= "is404: " . is_404() . ", " .
			esc_html('auto_redirects: ' . $scAutoRedirects .
			', auto_score: ' . $scAutoScore .
			', template_redirect_priority: ' . $scTemplatePriority .
            ', auto_cats: ' . $scAutoCats .
			', auto_tags: ' . $scAutoTags .
			', dest404page: ' . $scDest404) . ", ";
		
		$debugMessage .= "is_single(): " . is_single() . " | " . "is_page(): " . is_page() .
			" | is_feed(): " . is_feed() . " | is_trackback(): " . is_trackback() . " | is_preview(): " .
			is_preview();
		
		$abj404logging->debugMessage("updateURLbarIfNecessary: " . $debugMessage);
	}
	
	/**
     * @param array<string, mixed> $atts
     * @return string
     */
    static function shortcodePageSuggestions( array $atts ): string {
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
        $cookieVal = isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) ? $_COOKIE[$cookieName] : '';
        if ($cookieVal !== '') {
            // Normalize URL using centralized function for consistency
            $urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($cookieVal));
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
        $updateCookieVal = isset($_COOKIE[$updateURLCookieName]) && is_string($_COOKIE[$updateURLCookieName]) ? $_COOKIE[$updateURLCookieName] : '';
        if ($updateCookieVal !== '') {
        	// Use UPDATE_URL cookie as fallback if primary cookie wasn't set
        	// (fixes: manual redirects to custom 404 pages not showing suggestions)
        	if ($urlRequest == '') {
        		// Normalize URL using centralized function for consistency
        		$urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($updateCookieVal));
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
        $getParamVal = isset($_GET[$queryParamName]) && is_string($_GET[$queryParamName]) ? $_GET[$queryParamName] : '';
        if ($urlRequest == '' && $getParamVal !== '') {
            // Normalize URL using centralized function for consistency
            $urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($getParamVal));
        }

        if ($urlRequest == '') {
            // if no 404 was detected then we don't offer any suggestions
            return "<!-- " . ABJ404_PP . " - No 404 was detected. No suggestions to offer. -->\n";
        }

        // Check for cached suggestion computation (transient-based)
        $urlKey = md5($urlRequest);
        $transientKey = 'abj404_suggest_' . $urlKey;
        $cachedData = get_transient($transientKey);

        if ($cachedData !== false && is_array($cachedData)) {
            if (isset($cachedData['status']) && $cachedData['status'] === 'complete') {
                // Suggestions ready - use cached data
                /** @var array<int, mixed> $cachedSuggestions */
                $cachedSuggestions = isset($cachedData['suggestions']) && is_array($cachedData['suggestions']) ? $cachedData['suggestions'] : array();
                $content .= self::renderSuggestionsHTML(
                    $cachedSuggestions,
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
            $suggestCatsOpt = isset($options['suggest_cats']) && is_string($options['suggest_cats']) ? $options['suggest_cats'] : '1';
            $suggestTagsOpt = isset($options['suggest_tags']) && is_string($options['suggest_tags']) ? $options['suggest_tags'] : '1';
            $permalinkSuggestionsPacket = $abj404spellChecker->findMatchingPosts($urlSlugOnly,
                    $suggestCatsOpt, $suggestTagsOpt);
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
                    $idStr = is_string($id) ? $id : (string)$id;
                    $pipePos = $f->strpos($idStr, '|');
                    $postIDs[$index] = $f->substr($idStr, 0, $pipePos !== false ? $pipePos : null);
                }

                $rawExtraData = $abj404dao->getExtraDataToPermalinkSuggestions($postIDs);
                foreach ($rawExtraData as $dataItem) {
                    if (!is_array($dataItem)) {
                        continue;
                    }
                    $postIdVal = isset($dataItem['post_id']) ? (string)$dataItem['post_id'] : '';
                    $termIdVal = isset($dataItem['term_id']) ? (string)$dataItem['term_id'] : '';
                    $extraDataById['post_id_' . $postIdVal] = $dataItem;
                    $extraDataById['term_id_' . $termIdVal] = $dataItem;
                }
            }
        }

        // allow some HTML.
        $content .= '<div class="suggest-404s">' . "\n";
        $suggestTitleStr = isset($options['suggest_title']) && is_string($options['suggest_title']) ? $options['suggest_title'] : '';
        $content .= wp_kses_post(
            str_replace('{suggest_title_text}', __('Here are some other great pages', '404-solution'),
                $suggestTitleStr )) . "\n";
        
        $requestUriVal = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $currentSlug = $abj404logic->removeHomeDirectory(
                $f->regexReplace('\?.*', '', $f->normalizeUrlString($requestUriVal)));
        $displayed = 0;
        $commentPartAndQueryPart = $abj404logic->getCommentPartAndQueryPartOfRequest();

        // Check if minimum score filtering is enabled
        $minScoreEnabled = isset($options['suggest_minscore_enabled']) && $options['suggest_minscore_enabled'] == '1';
        $suggestMinscoreRaw = isset($options['suggest_minscore']) && is_scalar($options['suggest_minscore']) ? $options['suggest_minscore'] : 25;
        $minScore = $minScoreEnabled ? intval($suggestMinscoreRaw) : 0;

        foreach ($permalinkSuggestions as $idAndType => $linkScore) {
            $idAndTypeStr = is_string($idAndType) ? $idAndType : (string)$idAndType;
            $linkScoreFloat = is_scalar($linkScore) ? (float)$linkScore : 0.0;
            $rowTypeStr = is_string($rowType) ? $rowType : null;
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndTypeStr, $linkScoreFloat,
            	$rowTypeStr, $options);

            $permLink = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
            // Skip if we're currently on the page we're about to suggest
            if (basename($permLink) == $currentSlug) {
                continue;
            }

            // Skip if minimum score filtering is enabled and score is below threshold
            if ($minScoreEnabled && $permalink['score'] < $minScore) {
                continue;
            }

            $suggestBefore = isset($options['suggest_before']) && is_string($options['suggest_before']) ? $options['suggest_before'] : '';
            $suggestEntryBefore = isset($options['suggest_entrybefore']) && is_string($options['suggest_entrybefore']) ? $options['suggest_entrybefore'] : '';
            $permTitle = isset($permalink['title']) && is_string($permalink['title']) ? $permalink['title'] : '';
            $permScore = isset($permalink['score']) && is_numeric($permalink['score']) ? (float)$permalink['score'] : 0.0;

            if ($displayed == 0) {
                // <ol>
                $content .= wp_kses_post($suggestBefore);
            }

            // <li>
            $content .= wp_kses_post($suggestEntryBefore);

            $content .= "<a href=\"" . esc_url($permLink) . $commentPartAndQueryPart .
                "\" title=\"" . esc_attr($permTitle) . "\">" .
                esc_attr($permTitle) . "</a>";

            // display the score after the page link

            if ($showExtraAdminData) {
                $idParts = explode('|', $idAndTypeStr);
                $currentId = isset($idParts[0]) ? (int)$idParts[0] : null;
                $typeCode  = isset($idParts[1]) ? $idParts[1] : null;

                $currentSuggestionData = [
                    'Title' => $permTitle,
                    'Link' => $permLink,
                    'Score' => number_format($permScore, 2),
                    'ID_Type_Code' => $idAndTypeStr, // e.g., "123|1" or "94|2"
                ];

                // Extract ID for lookup
                $idParts = explode('|', $idAndTypeStr);
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
                            '">' . number_format($permScore, 2) . // Format score
                            '</a>)';
            }

            // </li>
            $suggestEntryAfter = isset($options['suggest_entryafter']) && is_string($options['suggest_entryafter']) ? $options['suggest_entryafter'] : '';
            $content .= wp_kses_post($suggestEntryAfter) . "\n";
            $displayed++;
            $suggestMaxOpt = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? (int)$options['suggest_max'] : 5;
            if ($displayed >= $suggestMaxOpt) {
                break;
            }
        }
        $suggestAfter = isset($options['suggest_after']) && is_string($options['suggest_after']) ? $options['suggest_after'] : '';
        $suggestNoresults = isset($options['suggest_noresults']) && is_string($options['suggest_noresults']) ? $options['suggest_noresults'] : '';
        if ($displayed >= 1) {
            // </ol>
            $content .= wp_kses_post($suggestAfter) . "\n";

        } else {
            $content .= wp_kses_post(
                str_replace('{suggest_noresults_text}', __('No suggestions. :/ ', '404-solution'),
                    $suggestNoresults ));
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
     * @param array<int, mixed> $suggestionsPacket The suggestions data from findMatchingPosts()
     * @param string $requestedURL The original 404 URL (for debugging)
     * @return string HTML content for suggestions
     */
    public static function renderSuggestionsHTML(array $suggestionsPacket, string $requestedURL = ''): string {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        // Rendering should be side-effect free (no upgrade/migration work triggered on frontend/AJAX).
        $options = $abj404logic->getOptions(true);

        // Ensure suggestions is an array (cache may return stdClass from json_decode)
        $permalinkSuggestions = isset($suggestionsPacket[0]) ? (array)$suggestionsPacket[0] : [];
        $rowType = isset($suggestionsPacket[1]) ? $suggestionsPacket[1] : 'pages';

        // Check if user is plugin admin to show scores
        $showExtraAdminData = (is_user_logged_in() && $abj404logic->userIsPluginAdmin());

        // Extract option strings safely
        $rSuggestTitle = isset($options['suggest_title']) && is_string($options['suggest_title']) ? $options['suggest_title'] : '';
        $rSuggestBefore = isset($options['suggest_before']) && is_string($options['suggest_before']) ? $options['suggest_before'] : '';
        $rSuggestEntryBefore = isset($options['suggest_entrybefore']) && is_string($options['suggest_entrybefore']) ? $options['suggest_entrybefore'] : '';
        $rSuggestEntryAfter = isset($options['suggest_entryafter']) && is_string($options['suggest_entryafter']) ? $options['suggest_entryafter'] : '';
        $rSuggestAfter = isset($options['suggest_after']) && is_string($options['suggest_after']) ? $options['suggest_after'] : '';
        $rSuggestNoresults = isset($options['suggest_noresults']) && is_string($options['suggest_noresults']) ? $options['suggest_noresults'] : '';

        $content = '<div class="suggest-404s">' . "\n";
        $content .= wp_kses_post(
            str_replace('{suggest_title_text}', __('Here are some other great pages', '404-solution'),
                $rSuggestTitle )) . "\n";

        $currentSlug = '';
        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $currentSlug = $abj404logic->removeHomeDirectory(
                $f->regexReplace('\?.*', '', $f->normalizeUrlString($_SERVER['REQUEST_URI'])));
        }

        $displayed = 0;
        $commentPartAndQueryPart = $abj404logic->getCommentPartAndQueryPartOfRequest();

        // Check if minimum score filtering is enabled
        $minScoreEnabled = isset($options['suggest_minscore_enabled']) && $options['suggest_minscore_enabled'] == '1';
        $rMinscoreRaw = isset($options['suggest_minscore']) && is_scalar($options['suggest_minscore']) ? $options['suggest_minscore'] : 25;
        $minScore = $minScoreEnabled ? intval($rMinscoreRaw) : 0;

        foreach ($permalinkSuggestions as $idAndType => $linkScore) {
            $rIdAndTypeStr = is_string($idAndType) ? $idAndType : (string)$idAndType;
            $rLinkScoreFloat = is_scalar($linkScore) ? (float)$linkScore : 0.0;
            $rRowTypeStr = is_string($rowType) ? $rowType : null;
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($rIdAndTypeStr, $rLinkScoreFloat,
                $rRowTypeStr, $options);

            $rPermLink = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
            $rPermTitle = isset($permalink['title']) && is_string($permalink['title']) ? $permalink['title'] : '';
            $rPermScore = isset($permalink['score']) && is_numeric($permalink['score']) ? (float)$permalink['score'] : 0.0;

            // Skip if we're currently on the page we're about to suggest
            if ($currentSlug !== '' && basename($rPermLink) == $currentSlug) {
                continue;
            }

            // Skip if minimum score filtering is enabled and score is below threshold
            if ($minScoreEnabled && $rPermScore < $minScore) {
                continue;
            }

            if ($displayed == 0) {
                // <ol>
                $content .= wp_kses_post($rSuggestBefore);
            }

            // <li>
            $content .= wp_kses_post($rSuggestEntryBefore);

            $content .= "<a href=\"" . esc_url($rPermLink) . $commentPartAndQueryPart .
                "\" title=\"" . esc_attr($rPermTitle) . "\">" .
                esc_attr($rPermTitle) . "</a>";

            // Display the score after the page link (admin only)
            if ($showExtraAdminData) {
                $content .= ' (' . number_format($rPermScore, 4) . ')';
            }

            // </li>
            $content .= wp_kses_post($rSuggestEntryAfter) . "\n";
            $displayed++;
            $rSuggestMaxOpt = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? (int)$options['suggest_max'] : 5;
            if ($displayed >= $rSuggestMaxOpt) {
                break;
            }
        }

        if ($displayed >= 1) {
            // </ol>
            $content .= wp_kses_post($rSuggestAfter) . "\n";
        } else {
            $content .= wp_kses_post(
                str_replace('{suggest_noresults_text}', __('No suggestions. :/ ', '404-solution'),
                    $rSuggestNoresults ));
        }

        $content .= "\n</div>";

        return $content;
    }

    /**
     * Render a loading placeholder for async suggestions.
     * Shows skeleton loading animation while suggestions are being computed.
     *
     * @param string $requestedURL The 404 URL being looked up
     * @param array<string, mixed> $options Plugin options
     * @return string HTML placeholder with loading state
     */
    public static function renderAsyncPlaceholder(string $requestedURL, array $options): string {
        $suggestMaxVal = isset($options['suggest_max']) && is_scalar($options['suggest_max']) ? $options['suggest_max'] : 5;
        $suggestMax = intval($suggestMaxVal);

        // Generate skeleton items based on suggest_max
        $skeletons = '';
        for ($i = 0; $i < $suggestMax; $i++) {
            $skeletons .= '<li class="abj404-skeleton"></li>' . "\n";
        }

        $pSuggestTitle = isset($options['suggest_title']) && is_string($options['suggest_title']) ? $options['suggest_title'] : '';
        $pSuggestBefore = isset($options['suggest_before']) && is_string($options['suggest_before']) ? $options['suggest_before'] : '';
        $pSuggestAfter = isset($options['suggest_after']) && is_string($options['suggest_after']) ? $options['suggest_after'] : '';

        $content = '<div id="abj404-suggestions-placeholder" class="suggest-404s" ' .
            'data-requested-url="' . esc_attr($requestedURL) . '">' . "\n";
        $content .= wp_kses_post(
            str_replace('{suggest_title_text}', __('Here are some other great pages', '404-solution'),
                $pSuggestTitle )) . "\n";
        $content .= wp_kses_post($pSuggestBefore);
        $content .= '<div class="abj404-loading">' . "\n";
        $content .= '<p class="abj404-loading-text">' . esc_html__('Loading page suggestions...', '404-solution') . '</p>' . "\n";
        $content .= $skeletons;
        $content .= '</div>' . "\n";
        $content .= wp_kses_post($pSuggestAfter) . "\n";
        $content .= '</div>';

        return $content;
    }

    /**
     * Enqueue the async suggestion polling JavaScript.
     *
     * @param string $requestedURL The 404 URL for polling
     * @return void
     */
    public static function enqueueAsyncPollingScript(string $requestedURL): void {
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
