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

    /**
     * Resolve frontend locale with visitor-locale-first behavior.
     *
     * @return string
     */
    private static function resolveFrontendSuggestionLocale(): string {
        // Polylang: explicit locale for current request (visitor context).
        if (function_exists('pll_current_language')) {
            $pllLocale = pll_current_language('locale');
            if (is_string($pllLocale) && $pllLocale !== '') {
                return $pllLocale;
            }
        }

        // WPML: map current language code to locale if available.
        if (function_exists('apply_filters') && has_filter('wpml_current_language')) {
            $langCode = apply_filters('wpml_current_language', null);
            if (is_string($langCode) && $langCode !== '' && has_filter('wpml_active_languages')) {
                $active = apply_filters('wpml_active_languages', null, 'skip_missing=0');
                if (is_array($active) && isset($active[$langCode]) && is_array($active[$langCode])) {
                    $entry = $active[$langCode];
                    if (!empty($entry['default_locale']) && is_string($entry['default_locale'])) {
                        return $entry['default_locale'];
                    }
                    if (!empty($entry['locale']) && is_string($entry['locale'])) {
                        return $entry['locale'];
                    }
                }
            }
        }

        if (function_exists('determine_locale')) {
            $locale = determine_locale();
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }

        if (function_exists('get_locale')) {
            $locale = get_locale();
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    /**
     * @return bool True when locale was switched.
     */
    private static function maybeSwitchToFrontendLocale(): bool {
        if (!function_exists('switch_to_locale') || !function_exists('restore_previous_locale')) {
            return false;
        }

        $targetLocale = self::resolveFrontendSuggestionLocale();
        if ($targetLocale === '') {
            return false;
        }

        return switch_to_locale($targetLocale);
    }

    /**
     * @param bool $didSwitch
     * @return void
     */
    private static function maybeRestoreFrontendLocale(bool $didSwitch): void {
        if ($didSwitch && function_exists('restore_previous_locale')) {
            restore_previous_locale();
        }
    }

    /**
     * Replace both placeholder and legacy bare token forms.
     *
     * @param string $template
     * @param string $tokenNameWithoutBraces
     * @param string $replacement
     * @return string
     */
    private static function replaceSuggestionTemplateToken(string $template, string $tokenNameWithoutBraces, string $replacement): string {
        return str_replace(
            array('{' . $tokenNameWithoutBraces . '}', $tokenNameWithoutBraces),
            $replacement,
            $template
        );
    }
	
	/** If we're currently redirecting to a custom 404 page and we are about to show page
	 * suggestions then update the URL displayed to the user.
	 * @return void
	 */
	static function updateURLbarIfNecessary(): void {
		$abj404logic = abj_service('plugin_logic');
		$f = abj_service('functions');
		$abj404logging = abj_service('logging');
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
        $legacyRequestKey = ABJ404_PP . '_REQUEST_URI';
        $requestedURLForRestore = '';
        if (isset($_REQUEST[$updateURLCookieName]) && is_string($_REQUEST[$updateURLCookieName]) &&
                $_REQUEST[$updateURLCookieName] !== '') {
            $requestedURLForRestore = $_REQUEST[$updateURLCookieName];
        } else if (isset($_REQUEST[$legacyRequestKey]) && is_string($_REQUEST[$legacyRequestKey]) &&
                $_REQUEST[$legacyRequestKey] !== '') {
            // Backward compatibility: older code paths used REQUEST_URI key directly.
            $requestedURLForRestore = $_REQUEST[$legacyRequestKey];
        }
		if ($requestedURLForRestore === '') {
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
			$requestedURL = $requestedURLForRestore;
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
        $didSwitchLocale = self::maybeSwitchToFrontendLocale();
        try {
        $abj404logic = abj_service('plugin_logic');
        $abj404spellChecker = abj_service('spell_checker');
        $f = abj_service('functions');
        $viewReadService = abj_service('view_read_service');
        
        // Attributes
        $atts = shortcode_atts(
                array(
                    ),
                $atts
            );

        $options = $abj404logic->getOptions();
        
        $content = "\n<!-- " . ABJ404_PP . " - Begin 404 suggestions. -->\n";

        $urlResult = self::resolveRequestedUrl($f);
        $content .= $urlResult['cookieScripts'];
        $urlRequest = $urlResult['url'];

        if ($urlRequest == '') {
            // if no 404 was detected then we don't offer any suggestions
            return "<!-- " . ABJ404_PP . " - No 404 was detected. No suggestions to offer. -->\n";
        }

        // Check for cached suggestion computation (transient-based).
        // Normalize at the boundary: see ABJ_404_Solution_SuggestionTransient.
        $urlForCacheKey = $f->normalizeURLForCacheKey($urlRequest);
        $urlKey = md5($urlForCacheKey);
        $transientKey = 'abj404_suggest_' . $urlKey;
        $cached = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($transientKey));

        if ($cached !== null) {
            if ($cached->isComplete()) {
                // Suggestions ready, use cached data
                $content .= self::renderSuggestionsHTML(
                    $cached->getSuggestionsPacket(),
                    $urlRequest
                );
                $content .= "\n<!-- " . ABJ404_PP . " - End 404 suggestions (cached) -->\n";
                return $content;

            } elseif ($cached->isPending()) {
                // Still computing, show loading placeholder
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
        $extraDataById = $showExtraAdminData
            ? self::collectAdminDebugExtraData($permalinkSuggestions, $viewReadService, $f)
            : [];
        $adminDebugData = [];

        // allow some HTML.
        $content .= '<div class="suggest-404s">' . "\n";
        $suggestTitleStr = isset($options['suggest_title']) && is_string($options['suggest_title']) ? $options['suggest_title'] : '';
        $content .= wp_kses_post(
            self::replaceSuggestionTemplateToken($suggestTitleStr, 'suggest_title_text',
                __('Here are some other great pages', '404-solution')
            )) . "\n";
        
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

            $content .= "<a href=\"" . esc_url($permLink . $commentPartAndQueryPart) .
                "\" title=\"" . esc_attr($permTitle) . "\">" .
                esc_attr($permTitle) . "</a>";

            // display the score after the page link

            if ($showExtraAdminData) {
                $adminDebugData[] = self::buildAdminDebugItemData(
                    $idAndTypeStr, $permTitle, $permScore, $permLink, $extraDataById
                );
                $content .= ' (<a href="#" onclick="show404AdminDebugData(); return false;" title="' .
                            esc_attr__('Click to view debug data for all suggestions', '404-solution') .
                            '">' . number_format($permScore, 2) .
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
                self::replaceSuggestionTemplateToken($suggestNoresults, 'suggest_noresults_text',
                    __('No suggestions. :/ ', '404-solution')
                ));
        }

        $content .= "\n</div>";

        if ($showExtraAdminData && !empty($adminDebugData)) {
            $allSuggestionsJson = wp_json_encode($adminDebugData);
            if ($allSuggestionsJson === false) {
                $allSuggestionsJson = '[]';
            }
            $jsContent = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/js/suggestion-debug-modal.js');
            $content .= "<script type=\"text/javascript\">\n";
            $content .= "var abj404_suggestionData = " . $allSuggestionsJson . ";\n";
            $content .= $jsContent . "\n";
            $content .= "</script>\n";
        }

        $content .= "\n<!-- " . ABJ404_PP . " - End 404 suggestions for slug " . esc_html($urlSlugOnly) . " -->\n";

        return $content;
        } finally {
            self::maybeRestoreFrontendLocale($didSwitchLocale);
        }
    }

    /**
     * @param ABJ_404_Solution_Functions $f
     * @return array{url: string, cookieScripts: string}
     */
    private static function resolveRequestedUrl($f): array {
        $urlRequest = '';
        $cookieScripts = '';

        $cookieName = ABJ404_PP . '_REQUEST_URI';
        $cookieVal = isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) ? $_COOKIE[$cookieName] : '';
        if ($cookieVal !== '') {
            $urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($cookieVal));
            $cookieScripts .= "<script> \n" .
                    "   var d = new Date(); \n" .
                    "   d.setTime(d.getTime() - (60 * 5)); \n" .
                    '   var expires = "expires="+ d.toUTCString(); ' . "\n" .
                    '   document.cookie = "' . $cookieName . '=;" + expires + ";path=/"; ' . "\n" .
                    "</script> \n";
        }

        $updateURLCookieName = ABJ404_PP . '_REQUEST_URI';
        $updateURLCookieName .= '_UPDATE_URL';
        $updateCookieVal = isset($_COOKIE[$updateURLCookieName]) && is_string($_COOKIE[$updateURLCookieName]) ? $_COOKIE[$updateURLCookieName] : '';
        if ($updateCookieVal !== '') {
            if ($urlRequest == '') {
                $urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($updateCookieVal));
            }
            $cookieScripts .= "<script> \n" .
                "   var d = new Date(); /* delete the cookie */\n" .
                "   d.setTime(d.getTime() - (60 * 5)); \n" .
                '   var expires = "expires="+ d.toUTCString(); ' . "\n" .
                '   document.cookie = "' . $updateURLCookieName . '=;" + expires + ";path=/"; ' .
                "</script> \n";
        }

        $ctxUrl = abj_service('request_context')->requested_url;
        if ($ctxUrl !== '') {
            $urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($ctxUrl));
        }

        $queryParamName = ABJ404_PP . '_ref';
        $getParamVal = isset($_GET[$queryParamName]) && is_string($_GET[$queryParamName]) ? $_GET[$queryParamName] : '';
        if ($urlRequest == '' && $getParamVal !== '') {
            $urlRequest = $f->normalizeURLForCacheKey($f->normalizeUrlString($getParamVal));
        }

        return array('url' => $urlRequest, 'cookieScripts' => $cookieScripts);
    }

    /**
     * @param array<int|string, mixed> $permalinkSuggestions
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewReadService
     * @param ABJ_404_Solution_Functions $f
     * @return array<string, array<string, mixed>>
     */
    private static function collectAdminDebugExtraData(array $permalinkSuggestions, $viewReadService, $f): array {
        $extraDataById = [];
        $postIDs = array_keys($permalinkSuggestions);
        if (empty($postIDs)) {
            return $extraDataById;
        }
        foreach ($postIDs as $index => $id) {
            $idStr = is_string($id) ? $id : (string)$id;
            $pipePos = $f->strpos($idStr, '|');
            $postIDs[$index] = $f->substr($idStr, 0, $pipePos !== false ? $pipePos : null);
        }

        $rawExtraData = $viewReadService->getExtraDataToPermalinkSuggestions($postIDs);
        foreach ($rawExtraData as $dataItem) {
            if (!is_array($dataItem)) {
                continue;
            }
            $postIdVal = isset($dataItem['post_id']) ? (string)$dataItem['post_id'] : '';
            $termIdVal = isset($dataItem['term_id']) ? (string)$dataItem['term_id'] : '';
            $extraDataById['post_id_' . $postIdVal] = $dataItem;
            $extraDataById['term_id_' . $termIdVal] = $dataItem;
        }
        return $extraDataById;
    }

    /**
     * @param string $idAndTypeStr
     * @param string $permTitle
     * @param float $permScore
     * @param string $permLink
     * @param array<string, array<string, mixed>> $extraDataById
     * @return array<string, mixed>
     */
    private static function buildAdminDebugItemData(string $idAndTypeStr, string $permTitle, float $permScore, string $permLink, array $extraDataById): array {
        $currentSuggestionData = [
            'Title' => $permTitle,
            'Link' => $permLink,
            'Score' => number_format($permScore, 2),
            'ID_Type_Code' => $idAndTypeStr,
        ];

        $idParts = explode('|', $idAndTypeStr);
        $currentId = isset($idParts[0]) ? $idParts[0] : null;
        $typeCode  = isset($idParts[1]) ? $idParts[1] : null;

        if ($typeCode == '1') {
            $extraKey = 'post_id_' . $currentId;
            if (isset($extraDataById[$extraKey])) {
                $currentSuggestionData = $currentSuggestionData + $extraDataById[$extraKey];
            }
        } else {
            $extraKey = 'term_id_' . $currentId;
            if (isset($extraDataById[$extraKey])) {
                $currentSuggestionData = $currentSuggestionData + $extraDataById[$extraKey];
            }
        }

        return $currentSuggestionData;
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
        $didSwitchLocale = self::maybeSwitchToFrontendLocale();
        try {
        $abj404logic = abj_service('plugin_logic');
        $f = abj_service('functions');
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
            self::replaceSuggestionTemplateToken($rSuggestTitle, 'suggest_title_text',
                __('Here are some other great pages', '404-solution')
            )) . "\n";

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

            // Check per-post/per-term exclusion before rendering.
            // ABJ404_TYPE_POST=1, ABJ404_TYPE_CAT=2, ABJ404_TYPE_TAG=3.
            $idTypeParts = explode('|', $rIdAndTypeStr, 2);
            $idInt = isset($idTypeParts[0]) && is_numeric($idTypeParts[0]) ? (int)$idTypeParts[0] : 0;
            $typeInt = isset($idTypeParts[1]) && is_numeric($idTypeParts[1]) ? (int)$idTypeParts[1] : 0;
            if ($idInt > 0) {
                $typePost = defined('ABJ404_TYPE_POST') ? (int)ABJ404_TYPE_POST : 1;
                $typeCat  = defined('ABJ404_TYPE_CAT')  ? (int)ABJ404_TYPE_CAT  : 2;
                $typeTag  = defined('ABJ404_TYPE_TAG')  ? (int)ABJ404_TYPE_TAG  : 3;
                if ($typeInt === $typePost) {
                    $excludeMeta = get_post_meta($idInt, '_abj404_exclude', true);
                    if ($excludeMeta === '1') {
                        continue;
                    }
                } elseif ($typeInt === $typeCat || $typeInt === $typeTag) {
                    $excludeMeta = get_term_meta($idInt, '_abj404_exclude', true);
                    if ($excludeMeta === '1') {
                        continue;
                    }
                }
            }

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

            $content .= "<a href=\"" . esc_url($rPermLink . $commentPartAndQueryPart) .
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
                self::replaceSuggestionTemplateToken($rSuggestNoresults, 'suggest_noresults_text',
                    __('No suggestions. :/ ', '404-solution')
                ));
        }

        $content .= "\n</div>";

        return $content;
        } finally {
            self::maybeRestoreFrontendLocale($didSwitchLocale);
        }
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
        $didSwitchLocale = self::maybeSwitchToFrontendLocale();
        try {
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
            self::replaceSuggestionTemplateToken($pSuggestTitle, 'suggest_title_text',
                __('Here are some other great pages', '404-solution')
            )) . "\n";
        $content .= wp_kses_post($pSuggestBefore);
        $content .= '<div class="abj404-loading">' . "\n";
        $content .= '<p class="abj404-loading-text">' . esc_html__('Loading page suggestions...', '404-solution') . '</p>' . "\n";
        $content .= $skeletons;
        $content .= '</div>' . "\n";
        $content .= wp_kses_post($pSuggestAfter) . "\n";
        $content .= '</div>';

        return $content;
        } finally {
            self::maybeRestoreFrontendLocale($didSwitchLocale);
        }
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
