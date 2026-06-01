<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX endpoint: ajaxRefreshStatsDashboard. Refreshes the stats
 * dashboard snapshot, compares its hash against the client's last-known
 * hash, and reports whether new content is available so the JS poller can
 * trigger a redraw only on change.
 */
class ABJ_404_Solution_Ajax_RefreshStatsDashboard {

    /** @return void */
    public function handle() {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-refresh-stats-dashboard')) {
            return;
        }

        $functions = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
        $statsRepository = ABJ_404_Solution_UnavailableStatsRepository::resolve(__CLASS__);
        $abj404logic = abj_service('plugin_logic');

        $nonce = $functions->getPostOrGetSanitize('nonce');
        $page = $functions->getPostOrGetSanitize('page', '');
        $subpage = $functions->getPostOrGetSanitize('subpage', '');
        $currentHash = $functions->getPostOrGetSanitize('currentHash', '');

        $isPluginAdmin = false;
        $context = array(
            'action' => 'ajaxRefreshStatsDashboard',
            'page' => $page,
            'subpage' => $subpage,
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        );
        $context = ABJ_404_Solution_Ajax_AdminEndpointSupport::startAjaxDebugContext($context, 'ViewUpdater::refreshStatsDashboard');

        try {
            if (!wp_verify_nonce($nonce, 'abj404_refreshStatsDashboard')) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX invalid nonce in ajaxRefreshStatsDashboard.', $context);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Invalid security token', null, false);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 403);
                return;
            }

            $isPluginAdmin = abj_service('admin_access_policy')->isPluginAdmin();
            if (!$isPluginAdmin) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX unauthorized in ajaxRefreshStatsDashboard.', $context);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Unauthorized', null, false);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 403);
                return;
            }

            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('refresh_stats_dashboard', 30, 60)) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxRefreshStatsDashboard.', $context);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 429);
                return;
            }

            $snapshot = $statsRepository->refreshStatsDashboardSnapshot(false);
            $newHash = $snapshot['hash'];
            $hasUpdate = ($newHash !== '' && ($currentHash === '' || $newHash !== $currentHash));

            $response = array(
                'hasUpdate' => $hasUpdate,
                'hash' => $newHash,
                'refreshedAt' => intval($snapshot['refreshed_at']),
            );

            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($response, 200);
            return;

        } catch (Throwable $e) {
            $isPluginAdmin = ABJ_404_Solution_Ajax_AdminEndpointSupport::resolveIsPluginAdminFallback($isPluginAdmin);

            $details = array(
                'exception' => array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ),
                'context' => $context,
            );
            ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX exception in ajaxRefreshStatsDashboard.', $details, $e);
            $capturedOutput = ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse(
                'Server error while refreshing stats.',
                $details,
                $isPluginAdmin
            );
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 500);
            return;
        }
    }
}
