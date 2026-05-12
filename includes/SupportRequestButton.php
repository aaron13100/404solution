<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTML mount point for the reusable "Send debug log to developer" button.
 *
 * Pure rendering helper. No DAO calls, no singleton, no globals. Returns
 * a single empty <div> carrying the data attributes the JS component
 * (includes/js/support-request-button.js) needs to bootstrap itself. The
 * component reads `data-triggered-from` (allowlisted server-side by
 * Ajax_SupportRequest::ALLOWED_TRIGGER_SOURCES) and the optional
 * `data-context-summary` (a one-line free-text hint shown to the admin in
 * the confirmation modal so they remember which screen they're sending
 * from).
 *
 * Caller responsibilities:
 *   1. wp_enqueue_script() the abj404-support-request-button bundle and
 *      its dependency abj404-support-request-client (handled by
 *      WordPress_Connector::registerSupportRequestAssets()).
 *   2. Pass a triggered_from slug that exists in the AJAX allowlist.
 *      Drift is caught at the server side (400 response) but the lint in
 *      tests/SupportRequestButtonRenderTest enforces the matching set.
 */
final class ABJ_404_Solution_SupportRequestButton {

    /**
     * Render an empty mount-point div for the support-request button. The
     * JS component (window.ABJ404.SupportRequestButton.mount) discovers
     * the div via its CSS class and bootstraps the button, modal, and
     * AJAX wiring.
     *
     * Both data attributes are escaped with esc_attr() so the caller may
     * pass arbitrary user-derived strings (e.g. an error message excerpt
     * as the context_summary) without opening an XSS hole.
     *
     * @param string      $triggeredFrom  Required. One of
     *   Ajax_SupportRequest::ALLOWED_TRIGGER_SOURCES.
     * @param string|null $contextSummary Optional one-line summary shown
     *   inside the modal so the user knows which screen the report is
     *   anchored to. Pass null or '' to omit.
     * @return string HTML safe to print directly into an admin page.
     */
    public static function render(string $triggeredFrom, ?string $contextSummary = null): string {
        $triggeredFromAttr = self::escAttr($triggeredFrom);
        $html = '<div class="abj404-support-request-mount"'
            . ' data-triggered-from="' . $triggeredFromAttr . '"';
        if ($contextSummary !== null && $contextSummary !== '') {
            $html .= ' data-context-summary="' . self::escAttr($contextSummary) . '"';
        }
        $html .= '></div>';
        return $html;
    }

    /**
     * Wrapper around esc_attr() that survives running outside of a real
     * WordPress request (PHPStan-bench, structural tests). When WP is
     * loaded the real esc_attr() handles the escaping; otherwise a
     * minimal htmlspecialchars() shim keeps the output safe.
     *
     * @param string $value
     * @return string
     */
    private static function escAttr(string $value): string {
        if (function_exists('esc_attr')) {
            return (string)esc_attr($value);
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
