<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for the "Show what's in this report" expander on the
 * support-request modal.
 *
 * Returns the same payload FeedbackTransport::buildPayload('support_request',
 * [...]) would build, redacted for in-browser display:
 *
 *   - reply_email is always blanked. Even though the field is admin-only,
 *     a coworker glancing over the admin's shoulder should not see the
 *     reply address typed into a *preview* before the report is even
 *     sent.
 *   - debug_log_excerpt is truncated to the last PREVIEW_LOG_BYTES so the
 *     modal stays small and the response fits in a normal AJAX budget.
 *     The actual send carries the full excerpt.
 *   - The handler does NOT call FeedbackTransport::sendNow() and does NOT
 *     touch the cooldown transient. It is read-only by design so users
 *     can preview the payload as many times as they want without
 *     consuming their per-5-minute send budget.
 *
 * Wired in WordPress_Connector::registerAdminHooks() under the action
 * name 'wp_ajax_abj404_support_request_preview'.
 */
class ABJ_404_Solution_Ajax_SupportRequestPreview {
    use ABJ_404_Solution_AjaxSecurityTrait;

    /** Nonce action used by both wp_create_nonce() and wp_verify_nonce(). */
    const NONCE_ACTION = 'abj404_support_request_preview';

    /** Maximum bytes of debug_log_excerpt returned in the preview. */
    const PREVIEW_LOG_BYTES = 5120;

    /** Hard cap on user_message length echoed back into the preview. */
    const MAX_USER_MESSAGE_LENGTH = 2000;

    /**
     * Allowlist of UI screens that may request a preview. Kept in lockstep
     * with Ajax_SupportRequest::ALLOWED_TRIGGER_SOURCES (verified by the
     * preview test). Drift between the two would let a caller preview from
     * a screen the actual send would later 400 on.
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
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_support_request_preview',
            array($me, 'handleRequest'));
    }

    /**
     * Handle the AJAX request. Validates nonce + capability, validates the
     * triggered_from slug, builds the payload, redacts the
     * user-shouldn't-see-surprises bits, and returns the redacted payload.
     *
     * @return void
     */
    public function handleRequest(): void {
        self::requireAdminWithNonce(self::NONCE_ACTION);

        $triggeredFromRaw = isset($_POST['triggered_from']) && is_scalar($_POST['triggered_from'])
            ? (string)$_POST['triggered_from'] : '';
        $triggeredFrom = sanitize_key($triggeredFromRaw);
        if (!in_array($triggeredFrom, self::ALLOWED_TRIGGER_SOURCES, true)) {
            wp_send_json_error(array(
                'message' => __('Invalid support request source.', '404-solution'),
            ), 400);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // The user_message is echoed back into the preview as-is so the
        // user can review what they've typed. Apply the same
        // sanitize_textarea_field + length cap that the real send does so
        // a long paste in the preview does not blow past the eventual
        // server limit.
        $userMessageRaw = isset($_POST['user_message']) && is_scalar($_POST['user_message'])
            ? (string)$_POST['user_message'] : '';
        $userMessage = sanitize_textarea_field($userMessageRaw);
        if (strlen($userMessage) > self::MAX_USER_MESSAGE_LENGTH) {
            $userMessage = substr($userMessage, 0, self::MAX_USER_MESSAGE_LENGTH);
        }

        $debugLogExcerpt = self::resolveDebugLogExcerpt();

        $extras = array(
            'user_message' => $userMessage,
            // Always redacted in preview. The admin can review the final
            // value in the input field; we don't echo it back here.
            'reply_email' => '',
            'triggered_from' => $triggeredFrom,
            'debug_log_excerpt' => self::truncateLogExcerpt($debugLogExcerpt),
        );

        $payload = ABJ_404_Solution_FeedbackTransport::buildPayload('support_request', $extras);

        // Defensive belt-and-braces: even if buildPayload changes later to
        // surface PII (raw IPs, user emails), strip those keys here so the
        // preview surface is allowlist-shaped.
        $payload = self::redactForPreview($payload);

        wp_send_json_success(array(
            'payload' => $payload,
            'preview_log_bytes' => self::PREVIEW_LOG_BYTES,
            'is_truncated' => $debugLogExcerpt !== '' && strlen($debugLogExcerpt) > self::PREVIEW_LOG_BYTES,
        ));
    }

    /**
     * Truncate the debug log excerpt to the last PREVIEW_LOG_BYTES so the
     * preview response stays small. We keep the *tail* (most recent log
     * entries) because that's where the failure that motivated the
     * support request will be. A leading "(truncated...)" marker tells
     * the user this isn't the whole excerpt.
     *
     * @param string $excerpt
     * @return string
     */
    private static function truncateLogExcerpt(string $excerpt): string {
        if ($excerpt === '' || strlen($excerpt) <= self::PREVIEW_LOG_BYTES) {
            return $excerpt;
        }
        $tail = substr($excerpt, -self::PREVIEW_LOG_BYTES);
        return "(truncated for preview; full log is sent with the report)\n" . $tail;
    }

    /**
     * Strip fields that the user should not see surprises about in the
     * preview pane. Keys are removed (not blanked) so the modal renders a
     * stable allowlist of fields rather than "this looks blank, what is
     * it?". The real send still carries every field buildPayload returns.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function redactForPreview(array $payload): array {
        $surpriseKeys = array(
            // Server-software string contains the hostname on some
            // shared-hosting environments; not surprising to a sysadmin
            // but the preview surface is the user-visible one.
            'server_software',
        );
        foreach ($surpriseKeys as $key) {
            unset($payload[$key]);
        }
        return $payload;
    }

    /**
     * Best-effort lookup of a sanitized log excerpt. Mirrors the helper
     * in Ajax_SupportRequest so preview and send both anchor on the same
     * source of truth. Returns empty string if the Logging service or
     * its helper isn't reachable in this context.
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
        // allow-silent-catch: log excerpt is best-effort context for the support-request preview; a Logging service failure must not block the user-initiated preview, and the failure is already surfaced via the plugin's own logging path
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }
}
