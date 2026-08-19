<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the frontend write side of the `abj404_suggest_<md5(url)>` transient:
 * the handoff between the request that discovered a 404 and the shortcode that
 * renders suggestions on the 404 page.
 *
 * Two ways a request can fill that slot, and this class owns both so the key
 * derivation, the TTLs and the pending/complete state machine have one home:
 *
 *   - The suggestions were already computed while resolving the request (the
 *     spelling scan ran and produced candidates that scored under the
 *     auto-redirect threshold): publish them directly.
 *   - Nothing is computed yet: mark the slot pending, dispatch a non-blocking
 *     loopback request to admin-ajax.php to do the work, and roll the marker
 *     back if the dispatch fails, so the polling UI is never left waiting on a
 *     job that was never started.
 *
 * This lived on SpellChecker, which made a Levenshtein-scoring domain class
 * also own an HTTP self-request, a TLS-verification policy and a transient
 * lifecycle. The read side (ShortCode, Ajax_SuggestionPolling) and the worker
 * side (Ajax_SuggestionCompute) still derive the same key themselves; they are
 * the next consumers to move onto this class.
 */
class ABJ_404_Solution_SuggestionPublisher {

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/**
	 * @param ABJ_404_Solution_Logging $logger
	 */
	public function __construct($logger) {
		$this->logger = $logger;
	}

	/**
	 * Publish an already-computed suggestion packet so the shortcode renders it
	 * immediately instead of dispatching a background compute for work that is
	 * already done. An existing entry (pending or complete) is left alone: the
	 * worker that owns it is authoritative.
	 *
	 * @param string $fullRequestedURL The URL as requested, before normalization.
	 * @param array<int, mixed> $permalinksPacket Two-tuple from the spell checker.
	 * @return void
	 */
	public function cacheComputedSuggestionsForShortcode(string $fullRequestedURL, array $permalinksPacket): void {
		$normalizedURL = abj_service('url_encoder')->normalizeURLForCacheKey($fullRequestedURL);

		$urlKey = md5($normalizedURL);
		$transientKey = 'abj404_suggest_' . $urlKey;

		$existing = get_transient($transientKey);
		if ($existing !== false) {
			return;
		}

		// allow-cache-empty: factory-built typed array; SuggestionTransient::completeArray
		// always returns a non-empty associative array with at minimum a 'status' key.
		set_transient(
			$transientKey,
			ABJ_404_Solution_SuggestionTransient::completeArray(
				$normalizedURL,
				$permalinksPacket,
				abj_clock()->now(),
				''
			),
			300
		); // 5 minute TTL

		$this->logger->debugMessage("Cached spell-check suggestions for shortcode: " .
			esc_html($normalizedURL));
	}

	public function triggerAndCleanupOnFailure(string $requestedURL): bool {
		$normalizedURL = abj_service('url_encoder')->normalizeURLForCacheKey($requestedURL);

		$urlKey = md5($normalizedURL);
		$transientKey = 'abj404_suggest_' . $urlKey;

		$existing = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($transientKey));
		if ($existing !== null) {
			$this->logger->debugMessage("Async suggestions: skipping, transient already exists for " .
				esc_html($normalizedURL) . " (status: " . esc_html($existing->getStatus()) . ")");
			return false;
		}

		$token = wp_generate_password(32, false);

		// allow-cache-empty: factory-built typed array; keep the TTL at 120
		// seconds so slow hosts can start before the polling UI gives up.
		set_transient(
			$transientKey,
			ABJ_404_Solution_SuggestionTransient::pendingArray(
				$normalizedURL,
				$token,
				0,
				abj_clock()->now()
			),
			120
		); // 2 minute TTL

		$this->logger->debugMessage("Async suggestions: triggering background computation for " .
			esc_html($normalizedURL));

		// Loopback self-dispatch to admin-ajax.php on this same host. The
		// sslverify default of false matches WP core's own loopback convention
		// (see wp-includes/cron.php spawn_cron(), which uses the same
		// apply_filters('https_local_ssl_verify', false) pattern) and is
		// intentional for three reasons:
		//   1. The request never leaves the host. Intercepting it requires an
		//      attacker who already controls the local machine, at which point
		//      they can read the transient and dispatch the AJAX directly
		//      without bothering with MITM on loopback.
		//   2. WP sites routinely run on self-signed or hostname-mismatched
		//      certs in dev / behind a TLS-terminating proxy. Hardcoding
		//      sslverify true would break dispatch for those installs with no
		//      affordance for the admin to recover.
		//   3. The body carries only a one-shot suggestion-compute token bound
		//      to a 2-minute pending transient (set above). Worst case for a
		//      hypothetical local MITM is they re-trigger the same compute the
		//      site is already running, which is rate-limited downstream.
		// Admins on hostile-loopback topologies (e.g. reverse proxy spanning an
		// untrusted segment) can return true from the https_local_ssl_verify
		// filter to opt into strict TLS. Tests for both behaviors live in
		// AsyncSuggestionsTest::testWpRemotePostSslVerify*. M104 in the design
		// audit re-flags this every pass; this comment is the documented
		// trade-off so the next audit can mark it accepted.
		$response = wp_remote_post(admin_url('admin-ajax.php'), array(
			'blocking'  => false,
			'timeout'   => 5,
			'sslverify' => apply_filters('https_local_ssl_verify', false),
			'body'      => array(
				'action'   => 'abj404_compute_suggestions',
				'url'      => $normalizedURL,
				'token'    => $token
			)
		));

		if (is_wp_error($response)) {
			$this->logger->debugMessage("Async suggestions: dispatch failed for " .
				esc_html($normalizedURL) . " - " . $response->get_error_message());
			delete_transient($transientKey);
			return false;
		}

		return true;
	}
}
