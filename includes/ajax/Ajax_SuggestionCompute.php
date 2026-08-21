<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for background suggestion computation.
 * Called via non-blocking wp_remote_post from SuggestionPublisher::triggerAsyncSuggestions().
 * Runs in a separate PHP process to avoid blocking the user's redirect.
 */
class ABJ_404_Solution_Ajax_SuggestionCompute {

    /**
     * Per-IP rate limit for the anonymous compute endpoint.
     * Generous enough for legitimate crawler 404 storms (a single bot hitting
     * a few dozen broken links per minute), but caps the total expensive
     * computations triggered by any one source.
     */
    const COMPUTE_RATE_LIMIT_MAX_REQUESTS = 30;
    const COMPUTE_RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * Resolve the time source. Tests bind a `FrozenClock` via the
     * service container so the single-flight claim window (90s),
     * worker-recovery window, and `created`/`completed` timestamps can
     * be asserted exactly. When no container is bound the fallback is
     * the production `SystemClock`.
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
     * Compute suggestions for a 404 URL and store results in transient.
     * This runs in a background HTTP request.
     *
     * Cost ordering of checks (cheapest first, so an unauthorized hit
     * never reaches class loading or DB lookups beyond a single transient
     * read):
     *   1. Per-IP rate limit (one transient read).
     *   2. URL parameter present + non-empty after normalization.
     *   3. Token transient lookup + token match.
     *   4. Single-flight claim (status=='pending', started==0).
     *   5. Resolve service singletons (Logging / PluginLogic / SpellChecker).
     *   6. Run findMatchingPosts(), gated on corpus size.
     *
     * @return void
     */
    public static function computeSuggestions(): void {
        ABJ_404_Solution_AjaxRequestContractValidator::enforceCurrentRequest('ajax-suggestion-compute');

        // (1) Per-IP rate limit FIRST - cheapest possible rejection so an
        // attacker rotating fresh URLs (each producing a new token via a
        // real 404) cannot trigger unbounded expensive computations.
        // Single-flight protects same-token replay; this protects distinct-
        // token flooding from one source.
        if (class_exists('ABJ_404_Solution_Ajax_Php') &&
                ABJ_404_Solution_Ajax_Php::consumeRateLimit(
                    'compute_suggestions',
                    self::COMPUTE_RATE_LIMIT_MAX_REQUESTS,
                    self::COMPUTE_RATE_LIMIT_WINDOW_SECONDS
                )) {
            wp_die('Rate limit exceeded');
        }

        // (2) Validate URL parameter present.  No class loading yet.
        if (!isset($_POST['url'])) {
            wp_die('Missing required parameters');
        }

        // URL normalization requires the Sanitizer service.
        $rawUrl = function_exists('wp_unslash') ? wp_unslash($_POST['url']) : $_POST['url'];
        $requestedURL = abj_service('sanitizer')->normalizeUrlString($rawUrl);
        if (empty($requestedURL)) {
            wp_die('Missing required parameters');
        }

        // (3) Token check via transient lookup.
        // Use the same normalizeURLForCacheKey() pipeline as the producer
        // (SuggestionPublisher::triggerAsyncSuggestions) and the polling
        // consumer (Ajax_SuggestionPolling::pollSuggestions). Without this,
        // any URL that esc_url touches - spaces, unicode, double ampersands -
        // hashes to a different transient key than the producer wrote, and
        // this worker reports "Unauthorized" while the polling client never
        // finds the result. Sibling shape of 73f21bce / 6e0908a8 / 83b9fb85.
        $normalizedURL = ABJ_404_Solution_SuggestionTransient::normalizedUrl($requestedURL);
        $transientKey = ABJ_404_Solution_SuggestionTransient::transientKeyForNormalizedUrl($normalizedURL);

        $existing = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($transientKey));

        $providedToken = ABJ_404_Solution_RequestInputNormalizer::readText($_POST, 'token');

        // Security: Require a valid token for ALL computation requests.
        // This prevents DoS attacks via direct calls to admin-ajax.php.
        if ($existing === null || $existing->getToken() === '') {
            // No transient or no token stored, unauthorized direct call.
            wp_die('Unauthorized');
        }

        $storedToken = $existing->getToken();

        if ($existing->isComplete()) {
            wp_die(); // Already done, nothing to do
        }

        // Verify token matches: authenticates that request came from a legitimate trigger.
        if (empty($providedToken) || $providedToken !== $storedToken) {
            wp_die('Invalid token');
        }

        $workerStartedAt = self::claimPendingWork(array(
            'transientKey' => $transientKey,
            'normalizedURL' => $normalizedURL,
            'providedToken' => $storedToken,
        ));
        if ($workerStartedAt === null) {
            wp_die();
        }

        // Register crash detection handler BEFORE expensive computation
        // This detects fatal errors (memory exhaustion, etc.) and marks transient as 'error'
        register_shutdown_function(
            array(__CLASS__, 'handleComputationCrash'),
            $transientKey,
            $storedToken,
            $requestedURL,
            null,
            $workerStartedAt
        );

        // Get dependencies
        $abj404logic = abj_service('plugin_logic');
        $spellChecker = abj_service('spell_checker');
        $logger = abj_service('logging');

        $logger->debugMessage("Ajax_SuggestionCompute: Starting computation for " . esc_html($requestedURL));

        // Extract URL slug for spell checking
        $urlSlugOnly = $abj404logic->urlNormalization()->removeHomeDirectory($requestedURL);

        // Get options for suggestion settings
        $options = abj_service('options_repository')->getOptions();

        // Gate 4 is the early return that fires when the N-gram prefilter
        // finds zero candidates at Dice >= 0.3. That is useful in the
        // synchronous redirect path, but it suppresses page suggestions for
        // long or low-overlap 404 URLs. This worker is already asynchronous
        // and rate-limited, so prioritize recall and let the full
        // Levenshtein fallback produce the best available suggestions.
        $spellChecker->setSkipNgramGate4(true);

        // Perform the expensive computation
        $suggestCatsRaw = isset($options['suggest_cats']) ? $options['suggest_cats'] : '';
        $suggestTagsRaw = isset($options['suggest_tags']) ? $options['suggest_tags'] : '';
        $suggestionsPacket = $spellChecker->findMatchingPosts(
            $urlSlugOnly,
            is_string($suggestCatsRaw) ? $suggestCatsRaw : (is_scalar($suggestCatsRaw) ? (string)$suggestCatsRaw : ''),
            is_string($suggestTagsRaw) ? $suggestTagsRaw : (is_scalar($suggestTagsRaw) ? (string)$suggestTagsRaw : '')
        );

        // Store results in transient (preserve token for audit trail)
        // TTL of 120 seconds: enough time for polling to retrieve results on slow hosts.
        // allow-cache-empty: factory-built typed array; completeArray always returns a
        // non-empty associative array with at minimum a 'status' key.
        self::storeCompletedResultOrDie(array(
            'transientKey' => $transientKey,
            'requestedURL' => $requestedURL,
            'storedToken' => $storedToken,
            'workerStartedAt' => $workerStartedAt,
            'suggestionsPacket' => is_array($suggestionsPacket) ? $suggestionsPacket : [],
            'logger' => $logger,
        ));

        $suggestionCount = isset($suggestionsPacket[0]) ? count((array)$suggestionsPacket[0]) : 0;
        $logger->debugMessage("Ajax_SuggestionCompute: Completed computation for " .
            esc_html($requestedURL) . " - found " . $suggestionCount . " suggestions");

        wp_die(); // End AJAX request cleanly
    }

    /**
     * Claim unstarted work, allow recovery of a stuck worker, or reject a live
     * sibling worker. Non-pending error state remains recoverable.
     *
     * @param array{transientKey: string, normalizedURL: string, providedToken: string} $claim
     */
    private static function claimPendingWork(array $claim): ?int {
        $lockKey = ABJ_404_Solution_SuggestionTransient::lockKeyForNormalizedUrl($claim['normalizedURL']);
        $synchronizer = abj_service('sync_utils');
        $owner = $synchronizer->synchronizerAcquireLockTry($lockKey);
        if ($owner === '') {
            abj404_logPhpFallback('suggestion-claim-lock-unavailable',
                '[SUGGESTION_CLAIM_LOCK_UNAVAILABLE] Another worker owns the claim for ' .
                $claim['transientKey'] . '. Recovery: that worker will compute or polling will retry.');
            return null;
        }

        try {
            // Re-read only after acquiring the shared state lock. Without this,
            // replayed AJAX requests can both observe started=0 and both run.
            $current = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($claim['transientKey']));
            if ($current === null || $current->isComplete()
                || $current->getToken() !== $claim['providedToken']
            ) {
                return null;
            }
            $now = self::clock()->now();
            if ($current->isPending() && $current->isClaimed() && !$current->isWorkerStuck($now)) {
                return null;
            }

            $existingCreated = $current->getCreatedAt() > 0 ? $current->getCreatedAt() : $now;
            $claimStored = set_transient(
                $claim['transientKey'],
                ABJ_404_Solution_SuggestionTransient::pendingArray(
                    $current->getUrl(),
                    $claim['providedToken'],
                    $now,
                    $existingCreated
                ),
                ABJ_404_Solution_SuggestionTransient::PENDING_TTL_SECONDS
            );
            if (!$claimStored) {
                abj404_logPhpFallback('suggestion-claim-write-failed',
                    '[SUGGESTION_CLAIM_WRITE_FAILED] Could not persist the worker claim for ' .
                    $claim['transientKey'] . '. Recovery: polling will fall back after the worker timeout.');
            }
            return $claimStored ? $now : null;
        } finally {
            $synchronizer->synchronizerReleaseLock($owner, $lockKey);
        }
    }

    /**
     * Persist a completed packet or terminate after recording the storage
     * failure; a success response must never claim an unstored result.
     *
     * @param array{transientKey: string, requestedURL: string, storedToken: string, workerStartedAt: int, suggestionsPacket: array<int, mixed>, logger: ABJ_404_Solution_Logging} $result
     */
    private static function storeCompletedResultOrDie(array $result): void {
        $normalizedURL = ABJ_404_Solution_SuggestionTransient::normalizedUrl($result['requestedURL']);
        $lockKey = ABJ_404_Solution_SuggestionTransient::lockKeyForNormalizedUrl($normalizedURL);
        $synchronizer = abj_service('sync_utils');
        $owner = $synchronizer->synchronizerAcquireLockTry($lockKey);
        if ($owner === '') {
            $result['logger']->warn('[SUGGESTION_RESULT_LOCK_UNAVAILABLE] Computation completed but another writer owns ' .
                esc_html($result['requestedURL']) . '. Recovery: the owning writer or synchronous fallback will publish results.');
            wp_die('Unable to lock suggestion results');
        }

        try {
            $current = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($result['transientKey']));
            if ($current === null || $current->isComplete()
                || $current->getToken() !== $result['storedToken']
                || $current->getStartedAt() !== $result['workerStartedAt']
            ) {
                $result['logger']->debugMessage('Skipped stale async suggestion result for ' .
                    esc_html($result['requestedURL']) . ' because state ownership changed.');
                return;
            }

            // allow-cache-empty: completeArray always returns typed status/url/token state even when the suggestion list is empty.
            $completedStored = set_transient(
                $result['transientKey'],
                ABJ_404_Solution_SuggestionTransient::completeArray(
                    $result['requestedURL'],
                    $result['suggestionsPacket'],
                    self::clock()->now(),
                    $result['storedToken']
                ),
                ABJ_404_Solution_SuggestionTransient::COMPLETE_TTL_SECONDS
            );
            if (!$completedStored) {
                $result['logger']->errorMessage(
                    '[SUGGESTION_RESULT_WRITE_FAILED] Computation completed but its result could not be stored for ' .
                    esc_html($result['requestedURL']) .
                    '. Recovery: the next request will compute suggestions synchronously.'
                );
                wp_die('Unable to store suggestion results');
            }
        } finally {
            $synchronizer->synchronizerReleaseLock($owner, $lockKey);
        }
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
     * @param int|null $workerStartedAt Exact claim timestamp registered by this worker.
     * @return void
     */
    public static function handleComputationCrash(
        string $transientKey,
        string $token,
        string $requestedURL,
        $error = null,
        ?int $workerStartedAt = null
    ): void {
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

        self::storeCrashMarker(array(
            'transientKey' => $transientKey,
            'token' => $token,
            'requestedURL' => $requestedURL,
            'workerStartedAt' => $workerStartedAt,
        ));

        // Log detailed error info for debugging (not exposed to frontend)
        $logMessage = sprintf(
            "Async suggestion computation crashed for URL '%s' (transient: %s): %s in %s on line %d",
            $requestedURL,
            $transientKey,
            $error['message'],
            basename($error['file']),
            $error['line']
        );

        // Use plugin logging when available, then the centralized PHP-log fallback.
        if (class_exists('ABJ_404_Solution_Logging')) {
            try {
                $logger = abj_service('logging');
                $logger->errorMessage($logMessage);
            } catch (Exception $e) {
                abj404_logPhpFallback('fatal-handler-fallback', $logMessage);
            }
        } else {
            abj404_logPhpFallback('fatal-handler-fallback', $logMessage);
        }
    }

    /**
     * Store a fatal-worker marker only while that worker still owns the state.
     *
     * @param array{transientKey: string, token: string, requestedURL: string, workerStartedAt: int|null} $crash
     */
    private static function storeCrashMarker(array $crash): void {
        $normalizedURL = ABJ_404_Solution_SuggestionTransient::normalizedUrl($crash['requestedURL']);
        $lockKey = ABJ_404_Solution_SuggestionTransient::lockKeyForNormalizedUrl($normalizedURL);
        $synchronizer = abj_service('sync_utils');
        $owner = $synchronizer->synchronizerAcquireLockTry($lockKey);
        if ($owner === '') {
            abj404_logPhpFallback('suggestion-crash-marker-lock-unavailable',
                '[SUGGESTION_CRASH_MARKER_LOCK_UNAVAILABLE] Could not lock the crash marker for ' .
                $crash['transientKey'] . '. Recovery: another writer owns the current state.');
            return;
        }

        try {
            $existing = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($crash['transientKey']));
            if ($existing === null || $existing->isComplete() || $existing->getToken() !== $crash['token']
                || ($crash['workerStartedAt'] !== null
                    && $existing->getStartedAt() !== $crash['workerStartedAt'])
            ) {
                return;
            }

            $errorStored = set_transient(
                $crash['transientKey'],
                ABJ_404_Solution_SuggestionTransient::errorArray($crash['token']),
                ABJ_404_Solution_SuggestionTransient::ERROR_TTL_SECONDS
            );
            if (!$errorStored) {
                abj404_logPhpFallback('suggestion-crash-marker-write-failed',
                    '[SUGGESTION_CRASH_MARKER_WRITE_FAILED] Could not store the crash marker for ' .
                    $crash['transientKey'] . '. Recovery: inspect the preceding PHP fatal error and retry the request.');
            }
        } finally {
            $synchronizer->synchronizerReleaseLock($owner, $lockKey);
        }
    }
}
