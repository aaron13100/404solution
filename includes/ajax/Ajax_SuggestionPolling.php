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
     * Resolve the time source. Tests bind a `FrozenClock` via the
     * service container so the worker-stuck-at-90s and worker-not-claimed-at-15s
     * thresholds can be asserted exactly. When no container is bound the
     * fallback is the production `SystemClock`.
     *
     * @return ABJ_404_Solution_Clock
     */
    private static function clock(): ABJ_404_Solution_Clock {
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('clock')) {
                    $svc = $c->get('clock');
                    if ($svc instanceof ABJ_404_Solution_Clock) {
                        return $svc;
                    }
                }
            } catch (Throwable $e) {
                // fall through
            }
        }
        return new ABJ_404_Solution_SystemClock();
    }

    /**
     * Check if suggestions are ready and return them if complete.
     * Returns JSON with status and optionally HTML content.
     * @return void
     */
    public static function pollSuggestions(): void {
        // Verify nonce for CSRF protection
        if (!check_ajax_referer('abj404_poll_suggestions', '_ajax_nonce', false)) {
            wp_send_json(array('status' => 'error', 'message' => 'Security check failed'), 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Rate limit polling to avoid admin-ajax.php abuse on high-traffic 404 pages.
        // Uses the same transient-based limiter as other AJAX endpoints (user ID or IP).
        if (class_exists('ABJ_404_Solution_Ajax_Php') &&
            ABJ_404_Solution_Ajax_Php::checkRateLimit('poll_suggestions', 120, 60)) {
            wp_send_json(array('status' => 'error', 'message' => 'Rate limit exceeded. Please try again later.'), 429);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Sanitize input
        $f = abj_service('functions');
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('functions')) {
                    $svc = $c->get('functions');
                    if ($svc instanceof ABJ_404_Solution_Functions) { $f = $svc; }
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
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Normalize URL using centralized function for consistency
        $normalizedURL = $f->normalizeURLForCacheKey($requestedURL);

        $urlKey = md5($normalizedURL);
        $transientKey = 'abj404_suggest_' . $urlKey;

        // Check transient for status
        $dataRaw = get_transient($transientKey);

        if ($dataRaw === false) {
            // Transient not found - computation may not have started
            wp_send_json(array('status' => 'not_found'));
            return; // @phpstan-ignore deadCode.unreachable
        }

        /** @var array<string, mixed> $data */
        $data = is_array($dataRaw) ? $dataRaw : array();

        if (!isset($data['status'])) {
            wp_send_json(array('status' => 'error', 'message' => 'Invalid transient data'), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }

        if ($data['status'] === 'pending') {
            // Check if computation has been running too long (indicates worker crash)
            // Worker claims work by setting started=time(), if still pending after 90s, it likely crashed
            // Matches the worker recovery threshold in Ajax_SuggestionCompute.php:67
            $startedAt = (isset($data['started']) && is_scalar($data['started'])) ? (int)$data['started'] : 0;
            $createdAt = (isset($data['created']) && is_scalar($data['created'])) ? (int)$data['created'] : 0;

            if ($startedAt > 0 && (self::clock()->now() - $startedAt) > 90) {
                // Computation started but hasn't completed in 90 seconds - worker likely crashed
                // Return 200 so the JS success handler catches it immediately (not error retry loop)
                wp_send_json(array('status' => 'timeout', 'message' => 'Computation timed out'));
                return; // @phpstan-ignore deadCode.unreachable
            }

            if ($startedAt === 0 && $createdAt > 0 && (self::clock()->now() - $createdAt) > 15) {
                // No worker has claimed work in 15 seconds - background dispatch likely failed
                // (common on single-threaded servers where wp_remote_post loopback fails)
                wp_send_json(array('status' => 'timeout', 'message' => 'Worker failed to start'));
                return; // @phpstan-ignore deadCode.unreachable
            }

            // Still computing normally
            wp_send_json(array('status' => 'pending'));
            return; // @phpstan-ignore deadCode.unreachable
        }

        if ($data['status'] === 'error') {
            // Computation crashed - return error immediately with generic message
            // Detailed error info is logged server-side, not exposed to frontend
            wp_send_json(array('status' => 'error', 'message' => 'Suggestion computation failed'), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }

        if ($data['status'] === 'complete') {
            // Suggestions ready - render HTML and return
            // Use normalized URL to match how ShortCode processes URLs
            $suggestionsData = (isset($data['suggestions']) && is_array($data['suggestions'])) ? $data['suggestions'] : array();
            $html = ABJ_404_Solution_ShortCode::renderSuggestionsHTML(
                $suggestionsData,
                $normalizedURL
            );
            wp_send_json(array('status' => 'complete', 'html' => $html));
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Unknown status
        $statusVal = $data['status'];
        $statusStr = is_string($statusVal) ? $statusVal : (is_scalar($statusVal) ? (string)$statusVal : 'unknown');
        wp_send_json(array('status' => 'error', 'message' => 'Unknown status: ' . esc_html($statusStr)), 500);
    }
}
