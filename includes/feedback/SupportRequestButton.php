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
 *      AdminAssetEnqueuer::registerSupportRequestAssets()).
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
        $contextAttr = '';
        if ($contextSummary !== null && $contextSummary !== '') {
            $contextAttr = ' data-context-summary="' . self::escAttr($contextSummary) . '"';
        }
        $template = self::readTemplate('supportRequestButtonMount.html');
        $html = strtr($template, [
            '{triggered_from}'       => self::escAttr($triggeredFrom),
            '{context_summary_attr}' => $contextAttr,
        ]);
        return rtrim($html, "\n");
    }

    /**
     * Render an inline `<a class="abj404-support-request-link">` anchor that
     * the JS component (window.ABJ404.SupportRequestButton.mountAll) discovers
     * and binds to the modal via attachLink(). Used inside admin error
     * notices where the affordance must read as a small text link in the
     * body of the notice (UI_AESTHETIC.md "Notices"), not a button or a
     * call-to-action chip.
     *
     * The href fallback is the same-page `#abj404-support-request` anchor
     * so JS-disabled browsers degrade to a working flow. The link label
     * is the user-visible text and is escaped with esc_html() because
     * callers may pass a translated string.
     *
     * @param string      $triggeredFrom  One of
     *   Ajax_SupportRequest::ALLOWED_TRIGGER_SOURCES.
     * @param string|null $contextSummary Optional one-line summary shown
     *   inside the support modal so the admin knows which screen the
     *   report is anchored to.
     * @param string      $label          User-visible link text. Defaults
     *   to "Send debug log to developer" (translated when WP is loaded).
     * @return string HTML safe to print inside a notice body.
     */
    public static function renderInlineLink(string $triggeredFrom,
            ?string $contextSummary = null, string $label = ''): string {
        if ($label === '') {
            $label = function_exists('__')
                ? (string)__('Send debug log to developer', '404-solution')
                : 'Send debug log to developer';
        }
        $contextAttr = '';
        if ($contextSummary !== null && $contextSummary !== '') {
            $contextAttr = ' data-context-summary="' . self::escAttr($contextSummary) . '"';
        }
        $template = self::readTemplate('supportRequestInlineLink.html');
        $html = str_replace('{triggered_from}', self::escAttr($triggeredFrom), $template);
        $html = str_replace('{context_summary_attr}', $contextAttr, $html);
        $html = str_replace('{label}', self::escHtml($label), $html);
        return rtrim($html, "\n");
    }

    /**
     * Read an HTML template from includes/html/. Kept private and minimal
     * so the helper has no dependency on the main Functions facade (so it
     * works on the degraded admin page where the autoloader may be
     * incomplete).
     *
     * @param string $filename Template filename relative to includes/html/.
     * @return string Template contents or '' on read failure.
     */
    private static function readTemplate(string $filename): string {
        $path = dirname(__DIR__) . '/html/' . $filename;
        if (!is_readable($path)) {
            return '';
        }
        $contents = @file_get_contents($path);
        return is_string($contents) ? $contents : '';
    }

    /**
     * esc_html() that survives running outside of a real WordPress request.
     * Mirrors escAttr() above.
     *
     * @param string $value
     * @return string
     */
    private static function escHtml(string $value): string {
        if (function_exists('esc_html')) {
            return (string)esc_html($value);
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
