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
 *     loopback request to admin-ajax.php to do the work. A failed dispatch
 *     leaves its owned pending marker intact so polling can report the
 *     dispatch timeout without deleting state published by another request.
 *
 * This lived on SpellChecker, which made a Levenshtein-scoring domain class
 * also own an HTTP self-request, a TLS-verification policy and a transient
 * lifecycle. SuggestionTransient owns the shared URL normalization, key shape,
 * and TTL constants used by every producer and consumer.
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
	 * already done. The completed packet is authoritative over pending work and
	 * is written directly, avoiding a read-before-write producer race.
	 *
	 * @param string $fullRequestedURL The URL as requested, before normalization.
	 * @param array<int, mixed> $permalinksPacket Two-tuple from the spell checker.
	 * @return void
	 */
	public function cacheComputedSuggestionsForShortcode(string $fullRequestedURL, array $permalinksPacket): void {
		$normalizedURL = ABJ_404_Solution_SuggestionTransient::normalizedUrl($fullRequestedURL);
		$transientKey = ABJ_404_Solution_SuggestionTransient::transientKeyForNormalizedUrl($normalizedURL);
		$claim = $this->acquireStateLock($normalizedURL);
		if ($claim === null) {
			$this->logger->debugMessage('Suggestion cache write skipped because another writer owns ' .
				esc_html($normalizedURL));
			return;
		}

		try {
			// allow-cache-empty: factory-built typed array; SuggestionTransient::completeArray
			// always returns a non-empty associative array with at minimum a 'status' key.
			$stored = set_transient(
				$transientKey,
				ABJ_404_Solution_SuggestionTransient::completeArray(
					$normalizedURL,
					$permalinksPacket,
					abj_clock()->now(),
					''
				),
				ABJ_404_Solution_SuggestionTransient::COMPLETE_TTL_SECONDS
			);
		} finally {
			$this->releaseStateLock($claim);
		}

		if (!$stored) {
			$this->logger->warn('[SUGGESTION_CACHE_WRITE_FAILED] Could not store completed suggestions for ' .
				esc_html($normalizedURL) . '. Recovery: the shortcode will compute suggestions synchronously.');
			return;
		}

		$this->logger->debugMessage("Cached spell-check suggestions for shortcode: " .
			esc_html($normalizedURL));
	}

	public function triggerAsyncSuggestions(string $requestedURL): bool {
		$normalizedURL = ABJ_404_Solution_SuggestionTransient::normalizedUrl($requestedURL);
		$transientKey = ABJ_404_Solution_SuggestionTransient::transientKeyForNormalizedUrl($normalizedURL);
		$adminAjaxUrl = $this->localAdminAjaxUrl();
		if ($adminAjaxUrl === '') {
			return false;
		}

		$claim = $this->acquireStateLock($normalizedURL);
		if ($claim === null) {
			$this->logger->debugMessage('Async suggestions: another publisher owns ' . esc_html($normalizedURL));
			return false;
		}

		try {
			$existing = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($transientKey));
			if ($existing !== null) {
				$this->logger->debugMessage("Async suggestions: skipping, transient already exists for " .
					esc_html($normalizedURL) . " (status: " . esc_html($existing->getStatus()) . ")");
				return false;
			}

			$token = wp_generate_password(32, false);

			// allow-cache-empty: pendingArray always returns a typed, non-empty state packet.
			$stored = set_transient(
				$transientKey,
				ABJ_404_Solution_SuggestionTransient::pendingArray(
					$normalizedURL,
					$token,
					0,
					abj_clock()->now()
				),
				ABJ_404_Solution_SuggestionTransient::PENDING_TTL_SECONDS
			);

			if (!$stored) {
				$this->logger->warn('[SUGGESTION_PENDING_WRITE_FAILED] Could not persist the async suggestion job for ' .
					esc_html($normalizedURL) . '. Recovery: the request will use synchronous suggestions.');
				return false;
			}
		} finally {
			$this->releaseStateLock($claim);
		}

		$this->logger->debugMessage("Async suggestions: triggering background computation for " .
			esc_html($normalizedURL));

		// Verify TLS by default because the body contains the one-shot worker
		// token. Sites with an intentionally self-signed loopback can still use
		// WordPress's standard https_local_ssl_verify filter explicitly.
		$response = wp_remote_post($adminAjaxUrl, array(
			'blocking'  => false,
			'timeout'   => 5,
			'sslverify' => apply_filters('https_local_ssl_verify', true),
			'body'      => array(
				'action'   => 'abj404_compute_suggestions',
				'url'      => $normalizedURL,
				'token'    => $token
			)
		));

		if (is_wp_error($response)) {
			$this->logger->warn('[SUGGESTION_DISPATCH_FAILED] Async suggestion dispatch failed for ' .
				esc_html($normalizedURL) . ' (' . $response->get_error_code() . '): ' .
				$response->get_error_message() . '. Recovery: polling will fall back after the dispatch timeout.');
			return false;
		}

		return true;
	}

	/** @return array{key: string, owner: string}|null */
	private function acquireStateLock(string $normalizedURL): ?array {
		$key = ABJ_404_Solution_SuggestionTransient::lockKeyForNormalizedUrl($normalizedURL);
		$owner = abj_service('sync_utils')
			->synchronizerAcquireLockTry($key);
		return $owner === '' ? null : array('key' => $key, 'owner' => $owner);
	}

	/** @param array{key: string, owner: string} $claim */
	private function releaseStateLock(array $claim): void {
		abj_service('sync_utils')
			->synchronizerReleaseLock($claim['owner'], $claim['key']);
	}

	/**
	 * Resolve the loopback endpoint and reject filters that move it off-site.
	 * The dispatch body contains a requested URL and one-shot worker token, so
	 * an externally filtered admin_url must never receive it.
	 */
	private function localAdminAjaxUrl(): string {
		$adminAjaxUrl = admin_url('admin-ajax.php');
		$adminHost = parse_url($adminAjaxUrl, PHP_URL_HOST);
		$homeHost = parse_url(home_url('/'), PHP_URL_HOST);
		if (is_string($adminHost) && $adminHost !== '' && is_string($homeHost)
			&& $homeHost !== '' && strcasecmp($adminHost, $homeHost) === 0
		) {
			return $adminAjaxUrl;
		}

		$this->logger->warn('[SUGGESTION_DISPATCH_OFFSITE] Refused async suggestion dispatch because admin_url ' .
			'does not use the site host. Recovery: remove the admin_url filter or use synchronous suggestions.');
		return '';
	}
}
