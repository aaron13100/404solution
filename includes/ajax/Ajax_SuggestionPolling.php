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
        // Sanitize input
        $requestedURL = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';

        if (empty($requestedURL)) {
            wp_send_json(array('status' => 'error', 'message' => 'Missing URL parameter'));
            return;
        }

        $urlKey = md5($requestedURL);
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
            // Still computing
            wp_send_json(array('status' => 'pending'));
            return;
        }

        if ($data['status'] === 'complete') {
            // Suggestions ready - render HTML and return
            $html = ABJ_404_Solution_ShortCode::renderSuggestionsHTML(
                isset($data['suggestions']) ? $data['suggestions'] : array(),
                $requestedURL
            );
            wp_send_json(array('status' => 'complete', 'html' => $html));
            return;
        }

        // Unknown status
        wp_send_json(array('status' => 'error', 'message' => 'Unknown status: ' . esc_html($data['status'])));
    }
}
