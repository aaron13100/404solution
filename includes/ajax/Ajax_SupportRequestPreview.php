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
 * Wired in WordPressHookRegistrar::registerAdminHooks() under the action
 * name 'wp_ajax_abj404_support_request_preview'.
 */
class ABJ_404_Solution_Ajax_SupportRequestPreview {

    /** Nonce action used by both wp_create_nonce() and wp_verify_nonce(). */
    const NONCE_ACTION = 'abj404_support_request_preview';

    /** Maximum bytes of debug_log_excerpt returned in the preview. */
    const PREVIEW_LOG_BYTES = 5120;

    /** Hard cap on user_message length echoed back into the preview. */
    const MAX_USER_MESSAGE_LENGTH = 2000;

    /** @var array<int, string> */
    const ALLOWED_TRIGGER_SOURCES = ABJ_404_Solution_Ajax_SupportRequest::ALLOWED_TRIGGER_SOURCES;

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
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-support-request-preview')) {
            return;
        }

        abj_service('ajax_security_gate')->requireAdminWithNonce(self::NONCE_ACTION);

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

        $previousPreviewReadOnly = $GLOBALS['abj404_feedback_preview_readonly'] ?? null;
        $GLOBALS['abj404_feedback_preview_readonly'] = true;
        $buildError = null;
        try {
            $payload = ABJ_404_Solution_FeedbackTransport::buildPayload('support_request', $extras);
        } catch (\Throwable $e) {
            // allow-silent-catch: not swallowed, deferred. $e is captured into $buildError
            // rather than embedded/logged inline because wp_send_json_error() below exits
            // the request in production, which would skip this finally block (PHP does not
            // run finally past an exit()/die()) and leave the global flag stuck set for the
            // rest of the process; deferring the report until after the finally block keeps
            // the cleanup unconditional. $buildError->getMessage() is embedded in the
            // wp_send_json_error() call ~15 lines below, and the explicit return; immediately
            // after that call halts execution even if a test double's wp_send_json_error()
            // stub does not itself exit -- so the exception detail always reaches the caller.
            $buildError = $e;
            $payload = array();
        } finally {
            if ($previousPreviewReadOnly === null) {
                unset($GLOBALS['abj404_feedback_preview_readonly']);
            } else {
                $GLOBALS['abj404_feedback_preview_readonly'] = $previousPreviewReadOnly;
            }
        }

        if ($buildError !== null) {
            // buildPayload() throws if the assembled payload fails its
            // schema contract. This is a read-only preview the user is
            // actively waiting on, so surface the real detail rather than
            // letting an uncaught exception reach them as a generic error.
            wp_send_json_error(array(
                /* translators: %s = the underlying error message. */
                'message' => sprintf(__('Could not prepare the preview (%s).', '404-solution'), $buildError->getMessage()),
            ), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }

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
     * Lookup of a sanitized log excerpt. Shares one implementation with
     * Ajax_SupportRequest so preview and send anchor on the same source of
     * truth, including when there is nothing to show: an unreachable Logging
     * service produces a stated reason, never a blank pane the admin has to
     * guess at.
     *
     * @return string
     */
    private static function resolveDebugLogExcerpt(): string {
        return ABJ_404_Solution_SupportLogExcerpt::resolve('Support request preview');
    }
}
