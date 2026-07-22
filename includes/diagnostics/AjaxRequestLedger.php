<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The immutable request ledger for admin AJAX (Bruno timeout cause matrix,
 * coverage req. 1).
 *
 * One request carries one ID, and that ID has to be recoverable from every
 * side of the exchange independently: the POST body and query string (so a
 * proxy or host access log records it), the X-ABJ404-Request-ID request
 * header, the response header, the response payload, the trace journal, and
 * the checkpoint file. When a request disappears somewhere between the
 * browser and PHP, joining those channels on one key is what turns "it timed
 * out" into "it reached the origin, encoded 41KB, and then never flushed".
 *
 * This class owns that identity end to end: the ID format, reading the
 * ledger fields off the transport, deciding which requests participate,
 * stamping outbound payloads, and recording evidence when a client or proxy
 * mutates the ID in flight. It owns no timing, no storage and no rendering;
 * it writes only the one tampering record, through the independent
 * checkpoint channel.
 */
final class ABJ_404_Solution_AjaxRequestLedger {

    /**
     * Ledger IDs are alphanumeric and 8-64 characters. Deliberately the same
     * expression the ajax-update-pagination request contract declares, so a
     * value that passes schema validation is never rejected here (and one
     * that skipped validation on a production site -- where the contract
     * validator is lenient by design -- still cannot get through).
     */
    const ID_PATTERN = '/^[A-Za-z0-9]{8,64}$/';

    /** Sentinel for "this request had no usable ID", so the field is never absent. */
    const UNKNOWN_ID = 'unknown00';

    /**
     * The one AJAX action whose per-boundary checkpoints are collected. The
     * admin table endpoint is what the timeout investigation is about; the
     * other handlers sharing the AJAX plumbing (RefreshHealthBar,
     * RefreshStatsDashboard, RunLazyBackfill, RefreshAdminNonces) must not
     * pay the file-write overhead.
     */
    const INSTRUMENTED_ACTION = 'ajaxUpdatePaginationLinks';

    /**
     * Actions whose boot-phase lifecycle (Bruno timeout cause matrix, gap
     * G3) is checkpointed: the table AJAX endpoint itself, and the canary
     * ladder that re-runs the identical boot+auth+dispatch path to isolate
     * transient host-level causes. Every other admin-ajax action, and every
     * ordinary front-end request, pays zero write cost for this.
     */
    const BOOT_WAYPOINT_ACTIONS = array(
        'ajaxUpdatePaginationLinks' => true,
        'ajaxRunCanaryStep' => true,
    );

    /**
     * Normalize a raw ID to the ledger format, degrading anything else to
     * $fallback. Every channel that reads client input normalizes through
     * here, so a malformed or hostile value can never be reflected back into
     * a response, a header, or a journal record, and every channel stays
     * joinable on the same key.
     *
     * @param mixed $raw
     */
    public static function normalizeId($raw, string $fallback = self::UNKNOWN_ID): string {
        $candidate = is_scalar($raw) ? (string)$raw : '';
        return preg_match(self::ID_PATTERN, $candidate) === 1 ? $candidate : $fallback;
    }

    /**
     * Ledger fields that ride the request alongside requestId: the browser
     * session, the attempt this retry is following up on, the client's send
     * timestamp (for queue/boot-delta math), the validated request-ID
     * header, and Cloudflare's own per-request trace ID.
     *
     * @param ABJ_404_Solution_Functions $requestReader Docblock-typed only
     *   (no native parameter type): tests substitute request-reader doubles
     *   that are not literally ABJ_404_Solution_Functions, and a native type
     *   declaration would TypeError on those at call time.
     * @return array{session_id: string, retry_parent_id: string, client_sent_at: string, header_request_id: string, cf_ray: string}
     */
    public static function readFields($requestReader): array {
        return array(
            'session_id' => substr((string)$requestReader->getPostOrGetSanitize('sessionId', ''), 0, 64),
            'retry_parent_id' => self::normalizeId($requestReader->getPostOrGetSanitize('retryParentId', ''), ''),
            'client_sent_at' => substr((string)$requestReader->getPostOrGetSanitize('clientSentAt', ''), 0, 64),
            'header_request_id' => self::readRequestIdHeader(),
            'cf_ray' => self::readCfRayHeader(),
        );
    }

    /**
     * The client sends X-ABJ404-Request-ID; this validates it. PHP maps that
     * request header to $_SERVER['HTTP_X_ABJ404_REQUEST_ID'].
     */
    public static function readRequestIdHeader(): string {
        return self::normalizeId($_SERVER['HTTP_X_ABJ404_REQUEST_ID'] ?? '', '');
    }

    /** Cloudflare's per-request trace ID, captured into the journal when present. */
    public static function readCfRayHeader(): string {
        $header = $_SERVER['HTTP_CF_RAY'] ?? '';
        return is_scalar($header) ? substr((string)$header, 0, 64) : '';
    }

    /**
     * A header ID that disagrees with the body ID means something between
     * the browser and PHP rewrote or replayed the request. That is evidence
     * about the transport, not noise to silently drop.
     */
    public static function recordHeaderMismatchIfAny(string $requestId, string $headerRequestId): void {
        if ($headerRequestId === '' || $headerRequestId === $requestId) {
            return;
        }
        ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, 'request_id_header_mismatch', array(
            'header_request_id' => $headerRequestId,
        ));
    }

    /**
     * Normalized ID for a request whose per-boundary checkpoints are being
     * collected, or '' when this request is not on the instrumented
     * endpoint. Callers use '' as the "skip the instrumentation" signal.
     *
     * @param array<array-key, mixed> $context
     */
    public static function instrumentedRequestId(array $context): string {
        $action = is_scalar($context['action'] ?? null) ? (string)$context['action'] : '';
        if ($action !== self::INSTRUMENTED_ACTION) {
            return '';
        }
        return self::normalizeId($context['request_id'] ?? null);
    }

    /** instrumentedRequestId() against the shared AJAX debug context global. */
    public static function instrumentedRequestIdFromGlobalContext(): string {
        $ctx = $GLOBALS['abj404_ajax_context'] ?? null;
        return is_array($ctx) ? self::instrumentedRequestId($ctx) : '';
    }

    /**
     * Normalized request ID for a boot-phase checkpoint, or '' when this
     * request is out of scope. Reads $_REQUEST directly (unlike
     * instrumentedRequestId(), which reads an already-built $context array)
     * because boot waypoints fire before any handler has parsed the
     * request -- the earliest one runs at the plugin file's own first
     * executable line, long before routing, auth, or the request-reader
     * service exist.
     *
     * wp_doing_ajax() (core since WP 4.7, wrapping the DOING_AJAX constant
     * that wp-admin/admin-ajax.php defines before wp-load.php even runs) is
     * already accurate by the time our plugin file is required. That is
     * what keeps every ordinary front-end request (the hot 404 path) out of
     * scope without waiting for WordPress routing. Read through the
     * function, not the raw constant directly: the function is filterable
     * (matching how WordPress itself lets other code correct the signal)
     * and, unlike a constant, it can be stubbed in tests instead of leaking
     * a permanent process-wide `true` the moment one test defines it.
     */
    public static function bootWaypointRequestId(): string {
        if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
            return '';
        }
        $action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';
        if (!isset(self::BOOT_WAYPOINT_ACTIONS[$action])) {
            return '';
        }
        $rawId = $_REQUEST['requestId'] ?? '';
        return self::normalizeId(is_scalar($rawId) ? $rawId : '');
    }

    /**
     * Normalized ID of the in-flight request, or '' when this request has no
     * ledger entry at all (a handler that never populated request_id). The
     * ledger is opt-in per endpoint and a fabricated ID would be worse than
     * none, so '' means "emit nothing", not "emit the sentinel".
     *
     * Unlike instrumentedRequestId() this is NOT gated on the action: the
     * ledger must be echoed on every response it covers, while checkpoint
     * file writes stay scoped to the endpoint under investigation.
     */
    public static function requestIdFromGlobalContext(): string {
        $ctx = $GLOBALS['abj404_ajax_context'] ?? null;
        if (!is_array($ctx) || !array_key_exists('request_id', $ctx)) {
            return '';
        }
        $raw = $ctx['request_id'];
        if (!is_scalar($raw) || (string)$raw === '') {
            return '';
        }
        return self::normalizeId($raw);
    }

    /**
     * Bruno timeout cause matrix, gap G9 (c434): a beta.2 SUCCESS is
     * unattributable unless something inside the same session separates the
     * detach fix (`607307c5`) from the other three things beta.2 also ships
     * (carried develop fixes, new instrumentation, or a transient that
     * simply passed). This alternates whether
     * Ajax_AdminEndpointSupport::checkpointedFlushAndFinish() actually calls
     * the detach function across the real table endpoint's own requests --
     * ON for one, deliberately skipped for the next -- so a clean separation
     * (ON completes, OFF times out) proves the detach fix causal instead of
     * merely correlated. AjaxCanaryLadder::interpretDetachAbResults() reads
     * the resulting per-request evidence.
     *
     * Bounded to a small number of pairs and gated behind two independent
     * opt-in signals, so a normal install never pays for this: the
     * `abj404_should_run_detach_ab_diagnostic` filter (default false --
     * nobody flips this on except a deliberately targeted diagnostic
     * session), AND a non-empty session ID, which only a beta-instrumented
     * client (view_updater_client_telemetry_env.js) ever sends. An older or
     * non-diagnostic client leaves the ledger's session_id empty and the
     * experiment inert regardless of the filter. This is "the diagnostic
     * mode the beta already gates on": callers only ever resolve this for
     * $checkpointRequestId !== '', the same INSTRUMENTED_ACTION scoping that
     * already keeps every checkpoint in this file off every handler but the
     * real table endpoint -- so the canary ladder's own requests never reach
     * this code at all, and its seven-step interpretation matrix can never
     * be confounded by it.
     */
    const AB_DETACH_MAX_PAIRS = 3;

    /** Total toggled attempts one session may consume: AB_DETACH_MAX_PAIRS ON/OFF pairs. */
    const AB_DETACH_MAX_ATTEMPTS = self::AB_DETACH_MAX_PAIRS * 2;

    /**
     * Whether a deployment has explicitly opted a diagnostic session into the
     * detach A/B experiment. False on every ordinary install: nobody wires
     * this filter except a deliberately targeted support session, so a
     * beta.2 install never randomly degrades a real admin's table load as a
     * side effect of merely shipping the beta.
     */
    public static function isDetachAbDiagnosticEnabled(): bool {
        return (bool)apply_filters('abj404_should_run_detach_ab_diagnostic', false, array());
    }

    /**
     * Pure alternation rule: attempt 0 is 'on', 1 is 'off', 2 is 'on', ...
     * Once a session has consumed AB_DETACH_MAX_ATTEMPTS slots the
     * experiment is over for that session and every later request reverts to
     * 'default' (the ordinary best-available detach, unmodified by this
     * feature) -- a diagnostic probe never permanently degrades a session.
     */
    public static function detachAbModeForAttempt(int $attemptIndex): string {
        if ($attemptIndex < 0 || $attemptIndex >= self::AB_DETACH_MAX_ATTEMPTS) {
            return 'default';
        }
        return ($attemptIndex % 2 === 0) ? 'on' : 'off';
    }

    /** The transient key one session's A/B attempt counter is stored under. */
    public static function detachAbTransientKey(string $sessionId): string {
        return 'abj404_ab_detach_' . md5($sessionId);
    }

    /**
     * Consume the next attempt slot for one session's A/B counter. Backed by
     * the WordPress transient API rather than the atomic wp_cache/DB-upsert
     * machinery ABJ_404_Solution_Ajax_Php::consumeRateLimit() uses: this is a
     * bounded diagnostic sequence, not a security ceiling, so a rare race
     * under concurrent tabs degrading to one extra or one skipped sample is
     * harmless, while pulling in the DAO layer here would give this
     * identity-only class a storage dependency it does not otherwise need.
     * Returns -1 when there is no session to key on, or the transient API is
     * unavailable (very early boot) -- both mean "nothing to pair against".
     */
    public static function nextDetachAbAttemptIndex(string $sessionId): int {
        if ($sessionId === '' || !function_exists('get_transient') || !function_exists('set_transient')) {
            return -1;
        }
        $key = self::detachAbTransientKey($sessionId);
        $current = get_transient($key);
        $index = is_numeric($current) ? (int)$current : 0;
        $ttl = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
        // allow-cache-empty: locally computed attempt counter (always a
        // valid non-negative int), not a fetched query result.
        set_transient($key, $index + 1, $ttl);
        return $index;
    }

    /**
     * The full decision for one request: gate + counter + the pure
     * alternation rule, in one call so every caller gets the same
     * opt-in-twice guarantee. 'inert' means the experiment did not run for
     * this request at all -- recorded as positive evidence by the caller,
     * the same principle checkpointedFlushAndFinish() already applies to the
     * finish-function 'none' case: absence must never be inferred.
     *
     * @return array{mode: string, attempt_index: int, diagnostic_enabled: bool}
     */
    public static function resolveDetachAbMode(string $sessionId): array {
        $diagnosticEnabled = self::isDetachAbDiagnosticEnabled();
        if (!$diagnosticEnabled) {
            return array('mode' => 'inert', 'attempt_index' => -1, 'diagnostic_enabled' => false);
        }
        $attemptIndex = self::nextDetachAbAttemptIndex($sessionId);
        if ($attemptIndex < 0) {
            return array('mode' => 'inert', 'attempt_index' => -1, 'diagnostic_enabled' => true);
        }
        return array(
            'mode' => self::detachAbModeForAttempt($attemptIndex),
            'attempt_index' => $attemptIndex,
            'diagnostic_enabled' => true,
        );
    }

    /**
     * Stamp the ledger ID onto an outbound payload that does not already
     * carry one.
     *
     * Applied at the single response choke point rather than at each call
     * site: the early-response branches (rate-limit 429, auth-failure 403)
     * are both the ones a stalled request is most likely to hit and the
     * easiest for a future branch to forget. Stamping centrally makes "an
     * error response the client cannot join back to its request ID"
     * impossible by construction instead of by discipline. A payload that
     * already set its own requestId is left exactly as its handler built it.
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function stampOnPayload($payload, string $requestId) {
        if ($requestId === '' || !is_array($payload) || array_key_exists('requestId', $payload)) {
            return $payload;
        }
        $payload['requestId'] = $requestId;
        return $payload;
    }
}
