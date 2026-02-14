<?php


if (!defined('ABSPATH')) {
    exit;
}

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
        $f = ABJ_404_Solution_Functions::getInstance();
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('functions')) {
                    $f = $c->get('functions');
                }
            } catch (Throwable $e) {
                // fall back to singleton
            }
        }
        if (isset($_POST['url'])) {
            $rawUrl = function_exists('wp_unslash') ? wp_unslash($_POST['url']) : $_POST['url'];
            $requestedURL = $f->normalizeUrlString($rawUrl);
        } else {
            $requestedURL = '';
        }

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

        // Register crash detection handler BEFORE expensive computation
        // This detects fatal errors (memory exhaustion, etc.) and marks transient as 'error'
        register_shutdown_function(
            array(__CLASS__, 'handleComputationCrash'),
            $transientKey,
            $storedToken,
            $requestedURL
        );

        // Get dependencies
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $spellChecker = ABJ_404_Solution_SpellChecker::getInstance();
        $logger = ABJ_404_Solution_Logging::getInstance();

        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has')) {
                    if ($c->has('plugin_logic')) { $abj404logic = $c->get('plugin_logic'); }
                    if ($c->has('spell_checker')) { $spellChecker = $c->get('spell_checker'); }
                    if ($c->has('logging')) { $logger = $c->get('logging'); }
                }
            } catch (Throwable $e) {
                // fall back to singletons
            }
        }

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

    /**
     * Shutdown handler to detect fatal errors during computation.
     * Updates transient to 'error' status so polling can respond immediately.
     *
     * Safe with concurrent requests:
     * - Only fires on fatal errors (not normal completion)
     * - If recovery worker succeeds later, it overwrites with 'complete'
     * - Token preserved for audit trail
     *
     * Safe with other shutdown handlers:
     * - register_shutdown_function() is additive (queued, not replaced)
     * - Existing ErrorHandler::FatalErrorHandler still runs
     * - This handler only acts on fatal errors, does nothing on success
     *
     * @param string $transientKey The transient key for this computation
     * @param string $token The security token for this computation
     * @param string $requestedURL The URL being processed (for logging)
     */
    public static function handleComputationCrash($transientKey, $token, $requestedURL, $error = null) {
        // Use provided error for testing, otherwise get from PHP
        if ($error === null) {
            $error = error_get_last();
        }

        // Only handle fatal error types - do nothing on normal shutdown
        // Include E_USER_ERROR and E_RECOVERABLE_ERROR which are fatal in many environments
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if (!$error || !($error['type'] & $fatalTypes)) {
            return; // Normal exit or non-fatal error - let completion handler update transient
        }

        // Check current transient state - don't overwrite if already complete
        $existing = get_transient($transientKey);
        if ($existing && isset($existing['status']) && $existing['status'] === 'complete') {
            return; // Another worker completed successfully - don't mark as error
        }

        // Mark as error with generic user-facing message (don't leak implementation details)
        set_transient($transientKey, array(
            'status' => 'error',
            'token' => $token
        ), 120);

        // Log detailed error info for debugging (not exposed to frontend)
        $logMessage = sprintf(
            "Async suggestion computation crashed for URL '%s' (transient: %s): %s in %s on line %d",
            $requestedURL,
            $transientKey,
            $error['message'],
            basename($error['file']),
            $error['line']
        );

        // Use plugin's logging if available, fallback to error_log
        if (class_exists('ABJ_404_Solution_Logging')) {
            try {
                $logger = ABJ_404_Solution_Logging::getInstance();
                $logger->errorMessage($logMessage);
            } catch (Exception $e) {
                // Logging failed during shutdown - use error_log as fallback
                @error_log("404 Solution: " . $logMessage);
            }
        } else {
            @error_log("404 Solution: " . $logMessage);
        }
    }
}
