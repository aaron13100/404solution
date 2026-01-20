<?php

/**
 * AJAX handler for polling suggestion computation status.
 * Called by JavaScript on the 404 page to check if suggestions are ready.
 */
class ABJ_404_Solution_Ajax_SuggestionPolling {

    /**
     * Check if suggestions are ready and return them if complete.
     * Returns JSON with status and optionally HTML content.
     */
    public static function pollSuggestions() {
        // Verify nonce for CSRF protection
        if (!check_ajax_referer('abj404_poll_suggestions', '_ajax_nonce', false)) {
            wp_send_json(array('status' => 'error', 'message' => 'Security check failed'));
            return;
        }

        // Sanitize input
        $f = ABJ_404_Solution_Functions::getInstance();
        if (isset($_POST['url'])) {
            $rawUrl = function_exists('wp_unslash') ? wp_unslash($_POST['url']) : $_POST['url'];
            $requestedURL = $f->normalizeUrlString($rawUrl);
        } else {
            $requestedURL = '';
        }

        if (empty($requestedURL)) {
            wp_send_json(array('status' => 'error', 'message' => 'Missing URL parameter'));
            return;
        }

        // Normalize URL using centralized function for consistency
        $normalizedURL = $f->normalizeURLForCacheKey($requestedURL);

        $urlKey = md5($normalizedURL);
        $transientKey = 'abj404_suggest_' . $urlKey;

        // Check transient for status
        $data = get_transient($transientKey);

        if ($data === false) {
            // Transient not found - computation may not have started
            wp_send_json(array('status' => 'not_found'));
            return;
        }

        if (!isset($data['status'])) {
            wp_send_json(array('status' => 'error', 'message' => 'Invalid transient data'));
            return;
        }

        if ($data['status'] === 'pending') {
            // Check if computation has been running too long (indicates worker crash)
            // Worker claims work by setting started=time(), if still pending after 90s, it likely crashed
            // Matches the worker recovery threshold in Ajax_SuggestionCompute.php:67
            $startedAt = isset($data['started']) ? (int)$data['started'] : 0;
            if ($startedAt > 0 && (time() - $startedAt) > 90) {
                // Computation started but hasn't completed in 90 seconds - worker likely crashed
                wp_send_json(array('status' => 'timeout', 'message' => 'Computation timed out'));
                return;
            }
            // Still computing normally
            wp_send_json(array('status' => 'pending'));
            return;
        }

        if ($data['status'] === 'error') {
            // Computation crashed - return error immediately with generic message
            // Detailed error info is logged server-side, not exposed to frontend
            wp_send_json(array('status' => 'error', 'message' => 'Suggestion computation failed'));
            return;
        }

        if ($data['status'] === 'complete') {
            // Suggestions ready - render HTML and return
            // Use normalized URL to match how ShortCode processes URLs
            $html = ABJ_404_Solution_ShortCode::renderSuggestionsHTML(
                isset($data['suggestions']) ? $data['suggestions'] : array(),
                $normalizedURL
            );
            wp_send_json(array('status' => 'complete', 'html' => $html));
            return;
        }

        // Unknown status
        wp_send_json(array('status' => 'error', 'message' => 'Unknown status: ' . esc_html($data['status'])));
    }
}
