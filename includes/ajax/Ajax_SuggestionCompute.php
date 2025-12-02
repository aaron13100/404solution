<?php

/**
 * AJAX handler for background suggestion computation.
 * Called via non-blocking wp_remote_post from SpellChecker::triggerAsyncSuggestionComputation().
 * Runs in a separate PHP process to avoid blocking the user's redirect.
 */
class ABJ_404_Solution_Ajax_SuggestionCompute {

    /**
     * Compute suggestions for a 404 URL and store results in transient.
     * This runs in a background HTTP request.
     */
    public static function computeSuggestions() {
        // Sanitize inputs
        $requestedURL = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';
        $urlKey = isset($_POST['url_key']) ? sanitize_text_field($_POST['url_key']) : '';

        // Validate inputs
        if (empty($requestedURL) || empty($urlKey)) {
            wp_die('Missing required parameters');
        }

        // Verify URL key matches (basic integrity check)
        if ($urlKey !== md5($requestedURL)) {
            wp_die('Invalid request - URL key mismatch');
        }

        $transientKey = 'abj404_suggest_' . $urlKey;

        // Double-check we should compute (might already be done by another request)
        $existing = get_transient($transientKey);
        if ($existing && isset($existing['status']) && $existing['status'] === 'complete') {
            wp_die(); // Already done, nothing to do
        }

        // Get dependencies
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $spellChecker = ABJ_404_Solution_SpellChecker::getInstance();
        $logger = ABJ_404_Solution_Logging::getInstance();

        $logger->debugMessage("Ajax_SuggestionCompute: Starting computation for " . esc_html($requestedURL));

        // Extract URL slug for spell checking
        $urlSlugOnly = $abj404logic->removeHomeDirectory($requestedURL);

        // Get options for suggestion settings
        $options = $abj404logic->getOptions();

        // Perform the expensive computation
        $suggestionsPacket = $spellChecker->findMatchingPosts(
            $urlSlugOnly,
            isset($options['suggest_cats']) ? $options['suggest_cats'] : '',
            isset($options['suggest_tags']) ? $options['suggest_tags'] : ''
        );

        // Store results in transient
        set_transient($transientKey, array(
            'status' => 'complete',
            'suggestions' => $suggestionsPacket,
            'url' => $requestedURL,
            'completed' => time()
        ), 300); // 5 minute TTL

        $suggestionCount = isset($suggestionsPacket[0]) ? count((array)$suggestionsPacket[0]) : 0;
        $logger->debugMessage("Ajax_SuggestionCompute: Completed computation for " .
            esc_html($requestedURL) . " - found " . $suggestionCount . " suggestions");

        wp_die(); // End AJAX request cleanly
    }
}
