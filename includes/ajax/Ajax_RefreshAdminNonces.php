<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX endpoint: ajaxRefreshAdminNonces.
 *
 * B20: mint fresh admin AJAX nonces for the page so the JS retry helper
 * recovers transparently from a 12-24h-idle expired nonce. No nonce on the
 * request itself (the caller's nonce expired by definition); the
 * userIsPluginAdmin() capability gate is the only authorisation, which
 * also handles the genuinely-logged-out case (full page refresh needed).
 */
class ABJ_404_Solution_Ajax_RefreshAdminNonces {

    /** @return void */
    public function handle() {
        $ctx = ABJ_404_Solution_Ajax_AdminEndpointSupport::startAjaxDebugContext(array(
            'action' => 'ajaxRefreshAdminNonces',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        ), 'Ajax_RefreshAdminNonces::handle');
        try {
            $userIsPluginAdmin = (bool)abj_service('admin_access_policy')->isPluginAdmin();
            if (!$userIsPluginAdmin) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX unauthorized in ajaxRefreshAdminNonces.', $ctx);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Unauthorized', null, false), 403);
                return;
            }
            if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('refresh_admin_nonces', 60, 60)) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxRefreshAdminNonces.', $ctx);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse(
                    'Rate limit exceeded. Please try again later.', null, false), 429);
                return;
            }
            $nonces = array();
            foreach (ABJ_404_Solution_Ajax_AdminEndpointSupport::adminNonceActions() as $action) {
                $nonces[$action] = wp_create_nonce($action);
            }
            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(array('success' => true,
                'data' => array('nonces' => $nonces)), 200);
        } catch (Throwable $e) {
            ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX exception in ajaxRefreshAdminNonces.', $ctx, $e);
            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse(
                'Server error while refreshing nonces.', null, false), 500);
        }
    }
}
