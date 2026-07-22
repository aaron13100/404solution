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
