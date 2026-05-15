<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for polling suggestion computation status.
 *
 * Called by `SuggestionPolling.js` on the public-facing 404 page
 * (`#abj404-suggestions-placeholder`) so anonymous visitors landing on a
 * 404 see the async-computed "did you mean" list as soon as it is ready.
 *
 * SECURITY CONTRACT: anonymous-by-design.
 *
 *   This endpoint is registered for BOTH `wp_ajax_*` and
 *   `wp_ajax_nopriv_*` actions (see `WordPress_Connector::registerAsyncSuggestionHooks`),
 *   mirroring `Ajax_SuggestionCompute::computeSuggestions` (the producer
 *   side of the same contract). There is intentionally no
 *   `userIsPluginAdmin()` / `current_user_can()` check: the public 404
 *   page is the entire point of the polling endpoint, and a capability
 *   gate would break it for every anonymous visitor (i.e. most visitors).
 *
 *   Abuse prevention relies on three layered defences instead of a
 *   capability gate:
 *
 *     1. `check_ajax_referer('abj404_poll_suggestions', '_ajax_nonce')`:
 *        the page emitting the polling JS also emits the nonce, so a
 *        scripted abuse attempt has to first fetch the 404 page.
 *     2. The shared per-actor (user-id or IP) rate limiter via
 *        `Ajax_Php::checkRateLimit('poll_suggestions', 120, 60)`.
 *     3. The endpoint is strictly read-only and only emits status
 *        constants plus rendered suggestion HTML for the requested URL:
 *        the worst-case information leak is "which URLs on this site have
 *        had a stored suggestion computation", which is bounded.
 *
 *   This contract is pinned by `AjaxSuggestionPollingAnonymousByDesignTest`
 *   (structural assertion on the `_nopriv_` registration + behavioural
 *   assertion that an anonymous caller reaches the data-read step).
 *
 * Security audit history: a V13 audit (commit deb6e7d5) flagged
 * "nonce but no capability check" against this handler. Triage confirmed
 * the anonymous-by-design contract above; do not re-file.
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
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $svc = ABJ_404_Solution_ServiceContainer::safeGet('clock');
            if ($svc instanceof ABJ_404_Solution_Clock) {
                return $svc;
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

        // Check transient for status. Normalize at the boundary: any
        // raw-shape probing (status string check, started/created int
        // coercion) lives in SuggestionTransient::fromRaw, not here.
        $dataRaw = get_transient($transientKey);

        if ($dataRaw === false) {
            // Transient not found, computation may not have started
            wp_send_json(array('status' => 'not_found'));
            return; // @phpstan-ignore deadCode.unreachable
        }

        $transient = ABJ_404_Solution_SuggestionTransient::fromRaw($dataRaw);
        if ($transient === null) {
            wp_send_json(array('status' => 'error', 'message' => 'Invalid transient data'), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }

        if ($transient->isPending()) {
            // Worker-recovery semantics live on the VO so each consumer doesn't
            // re-derive the 90s / 15s windows. Matches Ajax_SuggestionCompute's
            // claim window (the producer side of the same contract).
            $now = self::clock()->now();
            if ($transient->isWorkerStuck($now)) {
                // Return 200 so the JS success handler catches it immediately (not error retry loop)
                wp_send_json(array('status' => 'timeout', 'message' => 'Computation timed out'));
                return; // @phpstan-ignore deadCode.unreachable
            }

            if ($transient->isDispatchStuck($now)) {
                // No worker claimed within the dispatch window; background dispatch likely failed
                // (common on single-threaded servers where wp_remote_post loopback fails)
                wp_send_json(array('status' => 'timeout', 'message' => 'Worker failed to start'));
                return; // @phpstan-ignore deadCode.unreachable
            }

            // Still computing normally
            wp_send_json(array('status' => 'pending'));
            return; // @phpstan-ignore deadCode.unreachable
        }

        if ($transient->isError()) {
            // Computation crashed; return error immediately with generic message.
            // Detailed error info is logged server-side, not exposed to frontend.
            wp_send_json(array('status' => 'error', 'message' => 'Suggestion computation failed'), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Suggestions ready (isComplete by elimination, status enum is closed).
        // Use normalized URL to match how ShortCode processes URLs.
        $html = ABJ_404_Solution_ShortCode::renderSuggestionsHTML(
            $transient->getSuggestionsPacket(),
            $normalizedURL
        );
        wp_send_json(array('status' => 'complete', 'html' => $html));
    }
}
