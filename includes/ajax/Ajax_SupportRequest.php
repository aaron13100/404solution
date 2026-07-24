<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for "Send debug log to developer" buttons.
 *
 * Builds a `support_request` payload via FeedbackTransport and dispatches it
 * synchronously so the caller can be told which transport (HTTP or wp_mail
 * fallback) carried the message. The handler is rate-limited per site (one
 * request per 5 minutes) to prevent a frustrated admin (or a malicious one)
 * from flooding the developer endpoint with click-spam.
 *
 * This class owns the endpoint: authentication, the cooldown, input
 * validation, dispatch, and the answer the browser gets. WHAT the report
 * carries as evidence, and the byte contract that evidence lives under, is
 * ABJ_404_Solution_SupportEvidenceExcerpt's -- a separate job with entirely
 * different failure modes, and the one the beta.1 evidence losses all
 * happened in.
 *
 * Wired in WordPressHookRegistrar::registerAdminHooks() under the action name
 * 'wp_ajax_abj404_support_request'. The matching client lives at
 * includes/ajax/SupportRequest.js. The UI buttons that call it are added in
 * follow-up tasks B (reusable button) and C (wire button into error sites).
 */
class ABJ_404_Solution_Ajax_SupportRequest {

    /** Per-site cooldown between support requests (seconds). */
    const COOLDOWN_SECONDS = 300;

    /** Transient key for the per-site cooldown timestamp. */
    const COOLDOWN_TRANSIENT = 'abj404_support_request_cooldown';

    /** Hard cap on user_message length (matches sanitize step). */
    const MAX_USER_MESSAGE_LENGTH = 2000;

    /** Nonce action used by both wp_create_nonce() and wp_verify_nonce(). */
    const NONCE_ACTION = 'abj404_support_request';

    /**
     * Allowlist of UI screens that may originate a support request. A new
     * caller MUST be added here before clicking from a new screen, so the
     * server log stays usable as "who triggered this" forensic data.
     *
     * @var array<int, string>
     */
    const ALLOWED_TRIGGER_SOURCES = [
        'redirects_page',
        'captured_404s_page',
        'plugins_row_action',
        'settings_debug',
        'system_corrupt_install',
    ];

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /** @return self */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register the AJAX action. Called from WordPress_Connector during admin hook setup.
     *
     * @return void
     */
    public static function init(): void {
        $me = self::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_support_request',
            array($me, 'handleRequest'));
    }

    /**
     * Handle the AJAX request. Validates nonce + capability, applies the
     * per-site cooldown, validates inputs, builds the payload, dispatches
     * via FeedbackTransport::sendNow(), and returns the transport result.
     *
     * @return void
     */
    public function handleRequest(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-support-request')) {
            return;
        }

        // Nonce + manage_options gate. requireAdminWithNonce() reads from
        // POST first, then GET; the matching JS posts a 'nonce' field.
        abj_service('ajax_security_gate')->requireAdminWithNonce(self::NONCE_ACTION);

        // Cooldown check (per site, cheap transient read). 'manage_options'
        // already gated above so the cooldown is purely about endpoint-spam
        // prevention, not abuse from random users.
        $cooldownStartedAt = get_transient(self::COOLDOWN_TRANSIENT);
        if (is_scalar($cooldownStartedAt) && (int)$cooldownStartedAt > 0) {
            $remaining = self::COOLDOWN_SECONDS - (abj_clock()->now() - (int)$cooldownStartedAt);
            if ($remaining > 0) {
                wp_send_json_error(array(
                    'message' => __('Please wait before sending another support request.', '404-solution'),
                    'retry_after_seconds' => $remaining,
                ), 429);
                return; // @phpstan-ignore deadCode.unreachable
            }
        }

        // Validate triggered_from. Reject unknown values so we never silently
        // accept a typo / drift from the JS side.
        $triggeredFromRaw = isset($_POST['triggered_from']) && is_scalar($_POST['triggered_from'])
            ? (string)$_POST['triggered_from'] : '';
        $triggeredFrom = sanitize_key($triggeredFromRaw);
        if (!in_array($triggeredFrom, self::ALLOWED_TRIGGER_SOURCES, true)) {
            wp_send_json_error(array(
                'message' => __('Invalid support request source.', '404-solution'),
            ), 400);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Validate optional reply_email. Empty string is allowed (anonymous).
        $replyEmailRaw = isset($_POST['reply_email']) && is_scalar($_POST['reply_email'])
            ? (string)$_POST['reply_email'] : '';
        $replyEmail = $replyEmailRaw !== '' ? sanitize_email($replyEmailRaw) : '';

        // Validate optional user_message. Cap length at the source so we
        // never POST a multi-MB blob to the developer endpoint by accident.
        $userMessageRaw = isset($_POST['user_message']) && is_scalar($_POST['user_message'])
            ? (string)$_POST['user_message'] : '';
        $userMessage = sanitize_textarea_field($userMessageRaw);
        if (strlen($userMessage) > self::MAX_USER_MESSAGE_LENGTH) {
            $userMessage = substr($userMessage, 0, self::MAX_USER_MESSAGE_LENGTH);
        }

        // The evidence the report carries. Best-effort by construction:
        // unavailable diagnostics are reported inside the excerpt, never by
        // blocking the support request the admin is waiting on.
        $debugLogExcerpt = ABJ_404_Solution_SupportEvidenceExcerpt::assemble(array(
            'telemetry' => self::readClientTelemetry(),
            'session_id' => self::readClientSessionId(),
        ));

        $extras = array(
            'user_message' => $userMessage,
            'reply_email' => $replyEmail,
            'triggered_from' => $triggeredFrom,
            'debug_log_excerpt' => $debugLogExcerpt,
        );

        try {
            $payload = ABJ_404_Solution_FeedbackTransport::buildPayload('support_request', $extras);
        } catch (\Throwable $e) {
            // buildPayload() throws if the assembled payload fails its
            // schema contract. The user is actively waiting on this
            // request, so surface the real detail rather than failing
            // silently or letting an uncaught exception reach them as a
            // generic PHP error.
            wp_send_json_error(array(
                /* translators: %s = the underlying error message. */
                'message' => sprintf(__('Could not prepare the report (%s).', '404-solution'), $e->getMessage()),
            ), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Mark cooldown BEFORE dispatch so a slow / hung HTTP transport
        // can't be exploited to bypass the rate limit by a user mashing
        // the button while the request is in flight.
        // allow-cache-empty: timestamp marker starts a support-request cooldown; not a cached fetch result.
        set_transient(self::COOLDOWN_TRANSIENT, abj_clock()->now(), self::COOLDOWN_SECONDS);

        $ok = ABJ_404_Solution_FeedbackTransport::sendNow($payload, 'support_request');
        $fallbackUsed = ABJ_404_Solution_FeedbackTransport::lastSendUsedFallback();

        if (!$ok) {
            $diag = ABJ_404_Solution_FeedbackTransport::lastSendDiagnostics();
            wp_send_json_error(array(
                'message' => self::buildFailureMessage($diag),
                'fallback_used' => $fallbackUsed,
                'http_status'   => $diag['http_status'],
                'http_reason'   => $diag['http_reason'],
                'http_detail'   => $diag['http_detail'],
                'email_attempted' => $diag['email_attempted'],
                'email_ok'      => $diag['email_ok'],
            ), 502);
            return; // @phpstan-ignore deadCode.unreachable
        }

        wp_send_json_success(array(
            'ok' => true,
            'reference_id' => self::generateReferenceId(),
            'fallback_used' => $fallbackUsed,
        ));
    }

    /**
     * Build a user-facing failure message that names the specific
     * failure (HTTP status, transport reason, email-fallback outcome)
     * instead of the generic "could not send" stock copy. Required by
     * CLAUDE.md > Error visibility: every error path must preserve the
     * underlying code and context. Without this, an admin whose
     * support request fails has no way to tell whether the developer
     * endpoint is unreachable, the response was a 5xx, wp_mail() is
     * disabled, etc.
     *
     * The phrase is intentionally explicit. The detail string from a
     * WP_Error is wrapped in parentheses so the headline stays
     * scannable.
     *
     * @param array{http_status: int|null, http_reason: string, http_detail: string, email_attempted: bool, email_ok: bool|null} $diag
     * @return string
     */
    private static function buildFailureMessage(array $diag): string {
        $status = $diag['http_status'];
        $reason = (string)$diag['http_reason'];
        $detail = (string)$diag['http_detail'];

        $httpPhrase = '';
        if (is_int($status) && $status > 0) {
            $httpPhrase = sprintf(
                /* translators: %d = numeric HTTP status code returned by the developer endpoint. */
                __('HTTP %d from the developer endpoint', '404-solution'),
                $status
            );
        } elseif ($reason === 'wp_error') {
            $httpPhrase = __('the request never reached the developer endpoint', '404-solution');
        } elseif ($reason === 'json_encode_failed' || $reason === 'gzencode_failed') {
            $httpPhrase = sprintf(
                /* translators: %s = internal reason slug, e.g. json_encode_failed. */
                __('the report could not be packaged (%s)', '404-solution'),
                $reason
            );
        } elseif ($reason !== '') {
            $httpPhrase = sprintf(
                /* translators: %s = internal reason slug from the HTTP transport. */
                __('transport failure: %s', '404-solution'),
                $reason
            );
        } else {
            $httpPhrase = __('the HTTP transport failed for an unknown reason', '404-solution');
        }

        if ($detail !== '') {
            $httpPhrase .= ' (' . $detail . ')';
        }

        // sendNow() only routes here when HTTP failed AND the wp_mail()
        // fallback ALSO returned false. We surface that explicitly so the
        // admin knows both transports were exhausted, not just one.
        if (!empty($diag['email_attempted'])) {
            $emailPhrase = __('Email fallback via wp_mail() also failed. Check that this site can send mail.', '404-solution');
        } else {
            $emailPhrase = __('No email fallback was attempted.', '404-solution');
        }

        return sprintf(
            /* translators: 1 = primary cause sentence, 2 = email-fallback status sentence. */
            __('Could not send support request: %1$s. %2$s', '404-solution'),
            $httpPhrase,
            $emailPhrase
        );
    }

    /**
     * The browser's drained transport-attempt buffer as it was actually sent.
     *
     * Read through RequestInputNormalizer for the same reason
     * RequestInputNormalizer::getPostOrGetSanitize() always is: WordPress escapes every superglobal
     * at boot, so a JSON buffer taken straight out of $_POST can never parse
     * and every real support request would report its own telemetry as
     * unparseable.
     *
     * The request boundary stays here rather than inside
     * ABJ_404_Solution_SupportEvidenceExcerpt: reading it once and passing it
     * in is what lets the same assembly run from a context that has no $_POST
     * at all, and it guarantees the attempt ids the manifest reconciles are the
     * ids in the buffer the payload actually carries.
     */
    private static function readClientTelemetry(): string {
        return isset($_POST['client_telemetry'])
            ? ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($_POST['client_telemetry']) : '';
    }

    /**
     * The browser session this report is being sent from.
     *
     * It is what scopes the detach A/B verdict: the checkpoint journal is
     * site-wide while the experiment's attempt counters are per session and
     * workload scope, so two admin tabs write independent A/B sequences into
     * one file and only the clicking tab's own matched pairs are evidence.
     *
     * Bounded to the same 64 characters ABJ_404_Solution_AjaxRequestLedger's
     * own readFields() gives the field on every other request, so a session id
     * joins across channels rather than matching itself only here. The value is
     * never echoed, never queried with, and only ever hashed into a session
     * key, so bounding it is the whole of the sanitization it needs.
     */
    private static function readClientSessionId(): string {
        return isset($_POST['sessionId'])
            ? substr(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($_POST['sessionId']), 0, 64)
            : '';
    }

    /**
     * Generate a local reference id for the AJAX response. The server
     * endpoint may eventually return a canonical id; until then the client
     * gets something it can show to the user as "your reference number".
     *
     * Format: `YYYY-MM-DD-HH-MM-SS-xxxxxxx` (UTC timestamp plus 7 hex
     * chars). The timestamp prefix is self-sorting and human-meaningful:
     * an admin can glance at the reference and see roughly when the
     * request was sent, which is far more useful than an opaque UUID
     * when paired with a support email later. The 7-char hex suffix
     * (16^7, about 268M values) disambiguates concurrent sends with
     * plenty of headroom for a non-load-bearing identifier.
     *
     * @return string
     */
    private static function generateReferenceId(): string {
        $timestamp = gmdate('Y-m-d-H-i-s', abj_clock()->now());
        try {
            $suffix = substr(bin2hex(random_bytes(4)), 0, 7);
            return $timestamp . '-' . $suffix;
        // allow-silent-catch: random_bytes only fails when CSPRNG is unavailable; the mt_rand fallback still produces a unique-enough reference id, and the id is purely informational (it's not load-bearing for any auth or correctness check)
        } catch (\Throwable $e) {
            $suffix = str_pad(dechex(mt_rand(0, 0xfffffff)), 7, '0', STR_PAD_LEFT);
            return $timestamp . '-' . $suffix;
        }
    }
}
