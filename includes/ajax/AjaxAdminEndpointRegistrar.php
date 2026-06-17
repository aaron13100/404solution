<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the seven admin-table AJAX endpoints with WordPress.
 *
 * Each `wp_ajax_*` action name maps to a dedicated handler class
 * (Ajax_GetPaginationLinks, Ajax_WarmTableCache, Ajax_RefreshStatsDashboard,
 * Ajax_RefreshHealthBar, Ajax_FetchInflightStage, Ajax_AdvanceViewBuild,
 * Ajax_RefreshAdminNonces). Each handler owns the logic for its own endpoint.
 *
 * Registration uses a per-hook closure so the handler is constructed lazily,
 * only when its action actually fires, rather than building all seven handler
 * objects on every admin page load.
 *
 * Single-responsibility class: it exists only to wire admin-table AJAX
 * actions to their handler classes and implements no endpoint logic itself.
 * Named without the `Ajax_` endpoint prefix (it is infrastructure, not a
 * request handler) so it stays out of the per-endpoint contract / auth /
 * adversarial structural test globs.
 */
class ABJ_404_Solution_AjaxAdminEndpointRegistrar {

    /**
     * Wire each admin-table AJAX action to its handler. Safe to call once
     * per request from the plugin bootstrap (admin context only).
     *
     * @return void
     */
    public static function register() {
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxUpdatePaginationLinks',
                function() { (new ABJ_404_Solution_Ajax_GetPaginationLinks())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxWarmTableCache',
                function() { (new ABJ_404_Solution_Ajax_WarmTableCache())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshStatsDashboard',
                function() { (new ABJ_404_Solution_Ajax_RefreshStatsDashboard())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshHealthBar',
                function() { (new ABJ_404_Solution_Ajax_RefreshHealthBar())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxFetchInflightStage',
                function() { (new ABJ_404_Solution_Ajax_FetchInflightStage())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxAdvanceViewBuild',
                function() { (new ABJ_404_Solution_Ajax_AdvanceViewBuild())->handle(); });
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshAdminNonces',
                function() { (new ABJ_404_Solution_Ajax_RefreshAdminNonces())->handle(); });
        // wp_ajax_nopriv_ is for normal users; these endpoints are admin-only.
    }
}
