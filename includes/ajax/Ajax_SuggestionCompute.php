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

        // Validate inputs
        if (empty($requestedURL)) {
            wp_die('Missing required parameters');
        }

        // Compute transient key from URL
        $urlKey = md5($requestedURL);
        $transientKey = 'abj404_suggest_' . $urlKey;

        // Double-check we should compute (might already be done or in progress)
        $existing = get_transient($transientKey);

        // Get provided token from request
        $providedToken = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

        // Security: Require a valid token for ALL computation requests
        // This prevents DoS attacks via direct calls to admin-ajax.php
        if (empty($existing) || !isset($existing['token'])) {
            // No transient or no token stored - this is an unauthorized direct call
            wp_die('Unauthorized');
        }

        $storedToken = $existing['token'];

        if ($existing['status'] === 'complete') {
            wp_die(); // Already done, nothing to do
        }

        // Verify token matches - authenticates that request came from legitimate trigger
        if (empty($providedToken) || $providedToken !== $storedToken) {
            wp_die('Invalid token');
        }

        // Check if we should compute or skip (handles duplicate workers)
        // started=0 means no worker has claimed yet (trigger sets this)
        // started>0 means a worker has claimed the work
        if ($existing['status'] === 'pending') {
            $startedAt = isset($existing['started']) ? (int)$existing['started'] : 0;

            if ($startedAt === 0) {
                // First worker - claim the work by setting started=time()
                // TTL of 120s gives slow hosts enough time to complete computation
                set_transient($transientKey, array(
                    'status' => 'pending',
                    'url' => $existing['url'],
                    'started' => time(),  // Claim the work
                    'token' => $storedToken
                ), 120);
                // Proceed to compute
            } elseif ((time() - $startedAt) < 90) {
                // Another worker claimed recently and is still computing - skip
                wp_die();
            }
            // Else: started > 90s ago, worker may have died - proceed as recovery
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

        // Store results in transient (preserve token for audit trail)
        // TTL of 120 seconds: enough time for polling to retrieve results on slow hosts
        set_transient($transientKey, array(
            'status' => 'complete',
            'suggestions' => $suggestionsPacket,
            'url' => $requestedURL,
            'completed' => time(),
            'token' => $storedToken  // Preserve token for debugging/audit
        ), 120); // 2 minute TTL

        $suggestionCount = isset($suggestionsPacket[0]) ? count((array)$suggestionsPacket[0]) : 0;
        $logger->debugMessage("Ajax_SuggestionCompute: Completed computation for " .
            esc_html($requestedURL) . " - found " . $suggestionCount . " suggestions");

        wp_die(); // End AJAX request cleanly
    }
}
