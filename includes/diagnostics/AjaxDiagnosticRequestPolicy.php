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
        if ($action !== ABJ_404_Solution_AjaxRequestLedger::INSTRUMENTED_ACTION || !self::isEnabled()) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::normalizeId($context['request_id'] ?? null);
    }

    /**
     * Request ID for a durable table or canary trace, or an inert empty ID.
     *
     * @param array<array-key, mixed> $context
     */
    public static function diagnosticRequestId(array $context): string {
        $action = is_scalar($context['action'] ?? null) ? (string)$context['action'] : '';
        if (!isset(ABJ_404_Solution_AjaxRequestLedger::BOOT_WAYPOINT_ACTIONS[$action]) || !self::isEnabled()) {
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
}
