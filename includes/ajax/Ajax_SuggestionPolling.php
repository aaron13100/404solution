<?php


if (!defined('ABSPATH')) {
    exit;
}

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
            wp_send_json(array('status' => 'error', 'message' => 'Security check failed'), 403);
            return;
        }

        // Rate limit polling to avoid admin-ajax.php abuse on high-traffic 404 pages.
        // Uses the same transient-based limiter as other AJAX endpoints (user ID or IP).
        if (class_exists('ABJ_404_Solution_Ajax_Php') &&
            ABJ_404_Solution_Ajax_Php::checkRateLimit('poll_suggestions', 120, 60)) {
            wp_send_json(array('status' => 'error', 'message' => 'Rate limit exceeded. Please try again later.'), 429);
            return;
        }

        // Sanitize input
        $f = ABJ_404_Solution_Functions::getInstance();
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('functions')) {
                    $f = $c->get('functions');
                }
            } catch (Throwable $e) {
                // fall back
            }
        }
        if (isset($_POST['url'])) {
            $rawUrl = function_exists('wp_unslash') ? wp_unslash($_POST['url']) : $_POST['url'];
            $requestedURL = $f->normalizeUrlString($rawUrl);
        } else {
            $requestedURL = '';
        }

        if (empty($requestedURL)) {
            wp_send_json(array('status' => 'error', 'message' => 'Missing URL parameter'), 400);
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
            wp_send_json(array('status' => 'error', 'message' => 'Invalid transient data'), 500);
            return;
        }

        if ($data['status'] === 'pending') {
            // Check if computation has been running too long (indicates worker crash)
            // Worker claims work by setting started=time(), if still pending after 90s, it likely crashed
            // Matches the worker recovery threshold in Ajax_SuggestionCompute.php:67
            $startedAt = isset($data['started']) ? (int)$data['started'] : 0;
            if ($startedAt > 0 && (time() - $startedAt) > 90) {
                // Computation started but hasn't completed in 90 seconds - worker likely crashed
                wp_send_json(array('status' => 'timeout', 'message' => 'Computation timed out'), 504);
                return;
            }
            // Still computing normally
            wp_send_json(array('status' => 'pending'));
            return;
        }

        if ($data['status'] === 'error') {
            // Computation crashed - return error immediately with generic message
            // Detailed error info is logged server-side, not exposed to frontend
            wp_send_json(array('status' => 'error', 'message' => 'Suggestion computation failed'), 500);
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
        wp_send_json(array('status' => 'error', 'message' => 'Unknown status: ' . esc_html($data['status'])), 500);
    }
}
