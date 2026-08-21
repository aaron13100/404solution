<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the admin-table AJAX endpoints with WordPress.
 *
 * Each `wp_ajax_*` action name maps to a dedicated handler class
 * (Ajax_GetPaginationLinks, Ajax_RefreshStatsDashboard, Ajax_RefreshHealthBar,
 * Ajax_RefreshAdminNonces). Each handler owns the logic for its own endpoint.
 *
 * Registration uses a per-hook closure so the handler is constructed lazily,
 * only when its action actually fires, rather than building all handler
 * objects on every admin page load.
 *
 * Single-responsibility class: it exists only to wire admin-table AJAX
 * actions to their handler classes and implements no endpoint logic itself.
 * Named without the `Ajax_` endpoint prefix (it is infrastructure, not a
 * request handler) so it stays out of the per-endpoint contract / auth /
 * adversarial structural test globs.
 */
class ABJ_404_Solution_AjaxAdminEndpointRegistrar {

    /** Compiled marker for mixed-generation comparison at AJAX dispatch. */
    const DIAGNOSTIC_BUILD_ID = 'c27332fdd6d42f1ba2109a879a82e4514fec75b6';

    /**
     * Wire each admin-table AJAX action to its handler. Safe to call once
     * per request from the plugin bootstrap (admin context only).
     *
     * @return void
     */
    public static function register() {
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxUpdatePaginationLinks',
                function() {
                    // Boot lifecycle waypoint (Bruno timeout cause matrix, gap
                    // G3): the moment WordPress dispatches to our own handler,
                    // before auth or the rate limiter run. First statement in
                    // this closure rather than a second add_action() on the
                    // same tag, since ABJ_404_Solution_WPUtils::safeAddAction()
                    // throws if the same tag is registered twice with
                    // different callbacks.
                    ABJ_404_Solution_BootWaypointRecorder::record('ajax_dispatch', array(
                        'module' => 'AjaxAdminEndpointRegistrar',
                        'path' => __FILE__,
                        'build_id' => self::DIAGNOSTIC_BUILD_ID,
                    ));
                    (new ABJ_404_Solution_Ajax_GetPaginationLinks())->handle();
                });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshStatsDashboard',
                function() { (new ABJ_404_Solution_Ajax_RefreshStatsDashboard())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshHealthBar',
                function() { (new ABJ_404_Solution_Ajax_RefreshHealthBar())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshAdminNonces',
                function() { (new ABJ_404_Solution_Ajax_RefreshAdminNonces())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_run_lazy_backfill',
                function() { (new ABJ_404_Solution_Ajax_RunLazyBackfill())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRunCanaryStep',
                function() {
                    // See the ajaxUpdatePaginationLinks closure above.
                    ABJ_404_Solution_BootWaypointRecorder::record('ajax_dispatch', array(
                        'module' => 'AjaxAdminEndpointRegistrar',
                        'path' => __FILE__,
                        'build_id' => self::DIAGNOSTIC_BUILD_ID,
                    ));
                    (new ABJ_404_Solution_Ajax_CanaryLadder())->handle();
                });
        // wp_ajax_nopriv_ is for normal users; these endpoints are admin-only.
    }
}
