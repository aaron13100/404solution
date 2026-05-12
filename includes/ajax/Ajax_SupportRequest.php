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
 * Wired in WordPress_Connector::registerAdminHooks() under the action name
 * 'wp_ajax_abj404_support_request'. The matching client lives at
 * includes/ajax/SupportRequest.js. The UI buttons that call it are added in
 * follow-up tasks B (reusable button) and C (wire button into error sites).
 */
class ABJ_404_Solution_Ajax_SupportRequest {
    use ABJ_404_Solution_AjaxSecurityTrait;

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
        // Nonce + manage_options gate. requireAdminWithNonce() reads from
        // POST first, then GET; the matching JS posts a 'nonce' field.
        self::requireAdminWithNonce(self::NONCE_ACTION);

        // Cooldown check (per site, cheap transient read). 'manage_options'
        // already gated above so the cooldown is purely about endpoint-spam
        // prevention, not abuse from random users.
        $cooldownStartedAt = get_transient(self::COOLDOWN_TRANSIENT);
        if (is_scalar($cooldownStartedAt) && (int)$cooldownStartedAt > 0) {
            $remaining = self::COOLDOWN_SECONDS - (time() - (int)$cooldownStartedAt);
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

        // Pull a sanitized log excerpt via the existing helper. Best-effort:
        // a missing log file or unavailable Logging service must not block
        // sending the support request.
        $debugLogExcerpt = self::resolveDebugLogExcerpt();

        $extras = array(
            'user_message' => $userMessage,
            'reply_email' => $replyEmail,
            'triggered_from' => $triggeredFrom,
            'debug_log_excerpt' => $debugLogExcerpt,
        );

        $payload = ABJ_404_Solution_FeedbackTransport::buildPayload('support_request', $extras);

        // Mark cooldown BEFORE dispatch so a slow / hung HTTP transport
        // can't be exploited to bypass the rate limit by a user mashing
        // the button while the request is in flight.
        set_transient(self::COOLDOWN_TRANSIENT, time(), self::COOLDOWN_SECONDS);

        $ok = ABJ_404_Solution_FeedbackTransport::sendNow($payload, 'support_request');
        $fallbackUsed = ABJ_404_Solution_FeedbackTransport::lastSendUsedFallback();

        if (!$ok) {
            wp_send_json_error(array(
                'message' => __('Failed to send support request. Please try again later.', '404-solution'),
                'fallback_used' => $fallbackUsed,
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
     * Best-effort lookup of a sanitized log excerpt. Returns empty string
     * if the Logging service or its helper isn't reachable in this context.
     *
     * @return string
     */
    private static function resolveDebugLogExcerpt(): string {
        if (!function_exists('abj_service')) {
            return '';
        }
        try {
            $logger = abj_service('logging');
            if (is_object($logger) && method_exists($logger, 'getSanitizedLogExcerptForSupport')) {
                $excerpt = $logger->getSanitizedLogExcerptForSupport();
                return is_string($excerpt) ? $excerpt : '';
            }
        // allow-silent-catch: log excerpt is best-effort context for the support request; a Logging service failure must not block the user-initiated send, and the failure is already surfaced via the plugin's own logging path
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    /**
     * Generate a local reference id for the AJAX response. The server
     * endpoint may eventually return a canonical id; until then the client
     * gets something it can show to the user as "your reference number".
     *
     * @return string
     */
    private static function generateReferenceId(): string {
        if (function_exists('wp_generate_uuid4')) {
            return (string)wp_generate_uuid4();
        }
        // Pre-WP-4.7 fallback. random_bytes is available on PHP 7+ which is
        // the plugin's floor.
        try {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        // allow-silent-catch: random_bytes only fails when CSPRNG is unavailable; uniqid fallback still produces a unique-enough reference id, and the id is purely informational (it's not load-bearing for any auth or correctness check)
        } catch (\Throwable $e) {
            return uniqid('abj404_', true);
        }
    }
}
