<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ShortCode {
    
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
	public static function getInstance(): self {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_ShortCode();
		}
		
		return self::$instance;
	}

    /** @return ABJ_404_Solution_FrontendSuggestionLocaleScope */
    private static function localeScope(): ABJ_404_Solution_FrontendSuggestionLocaleScope {
        return new ABJ_404_Solution_FrontendSuggestionLocaleScope();
    }

    /** @return ABJ_404_Solution_ShortcodeRequestedUrlResolver */
    private static function requestedUrlResolver(): ABJ_404_Solution_ShortcodeRequestedUrlResolver {
        return new ABJ_404_Solution_ShortcodeRequestedUrlResolver();
    }

    /** @return ABJ_404_Solution_ShortcodeUrlBarUpdater */
    private static function urlBarUpdater(): ABJ_404_Solution_ShortcodeUrlBarUpdater {
        return new ABJ_404_Solution_ShortcodeUrlBarUpdater();
    }

    /** @return ABJ_404_Solution_ShortcodeSuggestionsPresenter */
    private static function suggestionsPresenter(): ABJ_404_Solution_ShortcodeSuggestionsPresenter {
        return new ABJ_404_Solution_ShortcodeSuggestionsPresenter();
    }
	
	/** If we're currently redirecting to a custom 404 page and we are about to show page
	 * suggestions then update the URL displayed to the user.
	 * @return void
	 */
	static function updateURLbarIfNecessary(): void {
		self::urlBarUpdater()->updateIfNecessary();
	}
	
    /**
     * @param array<string, mixed> $atts
     * @return string
     */
    static function shortcodePageSuggestions( array $atts ): string {
        $localeScope = self::localeScope();
        $didSwitchLocale = $localeScope->switchToFrontendLocale();
        try {
        $abj404logic = abj_service('plugin_logic');
        $abj404spellChecker = abj_service('spell_checker');
        $f = abj_service('functions');
        
        // Attributes
        $atts = shortcode_atts(
                array(
                    ),
                $atts
            );

        $options = abj_service('options_repository')->getOptions();
        
        $content = "\n<!-- " . ABJ404_PP . " - Begin 404 suggestions. -->\n";

        $urlResult = self::requestedUrlResolver()->resolve($f);
        $content .= $urlResult['cookieScripts'];
        $urlRequest = $urlResult['url'];

        if ($urlRequest == '') {
            // if no 404 was detected then we don't offer any suggestions
            return "<!-- " . ABJ404_PP . " - No 404 was detected. No suggestions to offer. -->\n";
        }

        // Check for cached suggestion computation (transient-based).
        // Normalize at the boundary: see ABJ_404_Solution_SuggestionTransient.
        $urlForCacheKey = ABJ_404_Solution_SuggestionTransient::normalizedUrl($urlRequest);
        $transientKey = ABJ_404_Solution_SuggestionTransient::transientKeyForNormalizedUrl($urlForCacheKey);
        $cached = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($transientKey));

        if ($cached !== null) {
            if ($cached->isComplete()) {
                // Suggestions ready, use cached data
                $content .= self::suggestionsPresenter()->renderSuggestionsHTML(
                    $cached->getSuggestionsPacket(),
                    $urlRequest,
                    $options,
                    true
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
        $urlSlugOnly = $abj404logic->urlNormalization()->removeHomeDirectory($urlRequest);

        // Try cache first (populated by processRedirect() for existing redirects)
        $permalinkSuggestionsPacket = $abj404spellChecker->getFromPermalinkCache($urlSlugOnly);

        // If cache miss, compute suggestions
        if (empty($permalinkSuggestionsPacket) || empty($permalinkSuggestionsPacket[0])) {
            $suggestCatsOpt = isset($options['suggest_cats']) && is_string($options['suggest_cats']) ? $options['suggest_cats'] : '1';
            $suggestTagsOpt = isset($options['suggest_tags']) && is_string($options['suggest_tags']) ? $options['suggest_tags'] : '1';
            $permalinkSuggestionsPacket = $abj404spellChecker->findMatchingPosts($urlSlugOnly,
                    $suggestCatsOpt, $suggestTagsOpt);
        }

        $content .= self::suggestionsPresenter()->renderSuggestionsHTML(
            array_values($permalinkSuggestionsPacket),
            $urlRequest,
            $options,
            true
        );

        $content .= "\n<!-- " . ABJ404_PP . " - End 404 suggestions for slug " . esc_html($urlSlugOnly) . " -->\n";

        return $content;
        } finally {
            $localeScope->restore($didSwitchLocale);
        }
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
        $localeScope = self::localeScope();
        $didSwitchLocale = $localeScope->switchToFrontendLocale();
        try {
            return self::suggestionsPresenter()->renderSuggestionsHTML($suggestionsPacket, $requestedURL, null, false);
        } finally {
            $localeScope->restore($didSwitchLocale);
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
        $localeScope = self::localeScope();
        $didSwitchLocale = $localeScope->switchToFrontendLocale();
        try {
            return self::suggestionsPresenter()->renderAsyncPlaceholder($requestedURL, $options);
        } finally {
            $localeScope->restore($didSwitchLocale);
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

        // Enqueue polling script. Use ABJ404_URL (plugin root) rather than
        // plugin_dir_url(__FILE__): this file lives in includes/frontend/ but
        // the JS lives in includes/ajax/, so a relative-to-__FILE__ URL points
        // at a non-existent path (i961).
        wp_enqueue_script(
            'abj404-suggestion-polling',
            ABJ404_URL . 'includes/ajax/SuggestionPolling.js',
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

        // Enqueue the front-end suggestions CSS (loading skeleton + the
        // admin-only note the finished list carries). See note above on
        // ABJ404_URL vs. plugin_dir_url. The handle comes from the note
        // presenter, which enqueues the same sheet on the synchronous path,
        // so the two can never drift into two <link> tags for one file.
        wp_enqueue_style(
            ABJ_404_Solution_ShortcodeSuggestionsAdminNotePresenter::STYLE_HANDLE,
            ABJ404_URL . 'includes/css/suggestions-loading.css',
            array(),
            ABJ404_VERSION
        );
    }

}
