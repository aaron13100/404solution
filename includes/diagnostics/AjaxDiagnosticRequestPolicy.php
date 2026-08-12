<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fail-closed opt-in policy for durable AJAX diagnostics.
 *
 * Request identity remains owned by AjaxRequestLedger. This collaborator
 * owns the separate decision of whether a request may activate expensive
 * journals and tracer callbacks through the existing debug setting.
 */
final class ABJ_404_Solution_AjaxDiagnosticRequestPolicy {

    /**
     * Actions whose durable stage trace and operation tracers are armed once
     * the debug setting opts in. A superset of AjaxRequestLedger's
     * BOOT_WAYPOINT_ACTIONS, and deliberately separate from it: a boot
     * waypoint is written before the handler is even known, while these are
     * armed inside
     * ABJ_404_Solution_AjaxAdminEndpointSupport::startAjaxDebugContext(),
     * which all three of these endpoints route through.
     *
     * ajaxRefreshHealthBar belongs here because the foreground status-count
     * work it triggers is one of the things the stall investigation is about;
     * omitting it left that whole record family unattributed on the endpoint
     * that most often performs it. There is no cost argument for leaving it
     * out: with the debug setting off every action here is inert anyway, and
     * with it on this is precisely the evidence the maintainer turned it on
     * to get.
     */
    const DIAGNOSTIC_TRACE_ACTIONS = array(
        'ajaxUpdatePaginationLinks' => true,
        'ajaxRunCanaryStep' => true,
        'ajaxRefreshHealthBar' => true,
    );

    /** Whether the stored debug setting explicitly enables diagnostics. */
    public static function isEnabled(): bool {
        if (!function_exists('abj404_get_settings_options')) {
            return false;
        }
        $options = abj404_get_settings_options();
        $value = $options['debug_mode'] ?? null;
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * Request ID for table-only micro-boundaries, or an inert empty ID.
     *
     * @param array<array-key, mixed> $context
     */
    public static function instrumentedRequestId(array $context): string {
        $action = is_scalar($context['action'] ?? null) ? (string)$context['action'] : '';
        if ($action !== ABJ_404_Solution_AjaxRequestLedger::INSTRUMENTED_ACTION
                || (!self::isEnabled() && !self::isAuthorizedRetry($context))) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::normalizeId($context['request_id'] ?? null);
    }

    /**
     * Request ID for a durable stage trace on one of the admin AJAX endpoints
     * that opt into tracing, or an inert empty ID.
     *
     * @param array<array-key, mixed> $context
     */
    public static function diagnosticRequestId(array $context): string {
        $action = is_scalar($context['action'] ?? null) ? (string)$context['action'] : '';
        if (!isset(self::DIAGNOSTIC_TRACE_ACTIONS[$action])
                || (!self::isEnabled() && !self::isAuthorizedRetry($context))) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::normalizeId($context['request_id'] ?? null);
    }

    /**
     * Request ID for an early boot waypoint, or an inert empty ID.
     *
     * Boot waypoints run before handler parsing, so this boundary reads the
     * raw WordPress request only after proving it is an AJAX request and the
     * debug setting explicitly opts into diagnostics.
     */
    public static function bootWaypointRequestId(): string {
        if (!self::isEnabled() || !function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
            return '';
        }
        $action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])
            ? (string)$_REQUEST['action'] : '';
        if (!isset(ABJ_404_Solution_AjaxRequestLedger::BOOT_WAYPOINT_ACTIONS[$action])) {
            return '';
        }
        $rawId = $_REQUEST['requestId'] ?? '';
        return ABJ_404_Solution_AjaxRequestLedger::normalizeId(is_scalar($rawId) ? $rawId : '');
    }

    /**
     * Whether the table handler has proved this is an authenticated retry.
     *
     * The authorization marker is deliberately internal: retryCount comes
     * from an untrusted request and must never arm pre-authorization writes.
     * The handler adds the marker only after nonce and plugin-admin checks.
     * Requiring both fields makes accidental reuse on another endpoint inert.
     *
     * @param array<array-key, mixed> $context
     */
    private static function isAuthorizedRetry(array $context): bool {
        if (($context['diagnostic_retry_authorized'] ?? null) !== true) {
            return false;
        }
        $action = is_scalar($context['action'] ?? null) ? (string)$context['action'] : '';
        $retryCount = $context['retry_count'] ?? null;
        return $action === ABJ_404_Solution_AjaxRequestLedger::INSTRUMENTED_ACTION
            && is_numeric($retryCount)
            && (int)$retryCount >= 1
            && (int)$retryCount <= 2;
    }
}
