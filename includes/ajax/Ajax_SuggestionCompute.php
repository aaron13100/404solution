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
     * @return void
     */
    public static function computeSuggestions(): void {
        // Sanitize inputs
        $f = ABJ_404_Solution_Functions::getInstance();
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('functions')) {
                    $svc = $c->get('functions');
                    if ($svc instanceof ABJ_404_Solution_Functions) { $f = $svc; }
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
        $existingRaw = get_transient($transientKey);
        /** @var array<string, mixed>|false $existing */
        $existing = is_array($existingRaw) ? $existingRaw : false;

        // Get provided token from request
        $providedToken = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

        // Security: Require a valid token for ALL computation requests
        // This prevents DoS attacks via direct calls to admin-ajax.php
        if (empty($existing) || !isset($existing['token'])) {
            // No transient or no token stored - this is an unauthorized direct call
            wp_die('Unauthorized');
        }

        $storedToken = $existing['token'];

        $existingStatus = isset($existing['status']) && is_string($existing['status']) ? $existing['status'] : '';
        if ($existingStatus === 'complete') {
            wp_die(); // Already done, nothing to do
        }

        // Verify token matches - authenticates that request came from legitimate trigger
        if (empty($providedToken) || $providedToken !== $storedToken) {
            wp_die('Invalid token');
        }

        // Check if we should compute or skip (handles duplicate workers)
        // started=0 means no worker has claimed yet (trigger sets this)
        // started>0 means a worker has claimed the work
        if ($existingStatus === 'pending') {
            $startedAt = isset($existing['started']) && is_scalar($existing['started']) ? (int)$existing['started'] : 0;

            if ($startedAt === 0) {
                // First worker - claim the work by setting started=time()
                // TTL of 120s gives slow hosts enough time to complete computation
                $existingUrl = isset($existing['url']) ? $existing['url'] : '';
                set_transient($transientKey, array(
                    'status' => 'pending',
                    'url' => $existingUrl,
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
                    if ($c->has('plugin_logic')) { $svc = $c->get('plugin_logic'); if ($svc instanceof ABJ_404_Solution_PluginLogic) { $abj404logic = $svc; } }
                    if ($c->has('spell_checker')) { $svc = $c->get('spell_checker'); if ($svc instanceof ABJ_404_Solution_SpellChecker) { $spellChecker = $svc; } }
                    if ($c->has('logging')) { $svc = $c->get('logging'); if ($svc instanceof ABJ_404_Solution_Logging) { $logger = $svc; } }
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
        $suggestCatsRaw = isset($options['suggest_cats']) ? $options['suggest_cats'] : '';
        $suggestTagsRaw = isset($options['suggest_tags']) ? $options['suggest_tags'] : '';
        $suggestionsPacket = $spellChecker->findMatchingPosts(
            $urlSlugOnly,
            is_string($suggestCatsRaw) ? $suggestCatsRaw : (is_scalar($suggestCatsRaw) ? (string)$suggestCatsRaw : ''),
            is_string($suggestTagsRaw) ? $suggestTagsRaw : (is_scalar($suggestTagsRaw) ? (string)$suggestTagsRaw : '')
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
     * @param array{type: int, message: string, file: string, line: int}|null $error
     * @return void
     */
    public static function handleComputationCrash(string $transientKey, string $token, string $requestedURL, $error = null): void {
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
        $existingData = get_transient($transientKey);
        if (is_array($existingData) && isset($existingData['status']) && $existingData['status'] === 'complete') {
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
