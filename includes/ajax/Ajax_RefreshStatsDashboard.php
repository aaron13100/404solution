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

        $requestReader = ABJ_404_Solution_AjaxAdminEndpointSupport::getRequestReader();
        $statsRepository = ABJ_404_Solution_StatsRepositoryResolver::resolve(__CLASS__);
        $abj404logic = abj_service('plugin_logic');

        $page = $requestReader->getPostOrGetSanitize('page', '');
        $subpage = $requestReader->getPostOrGetSanitize('subpage', '');
        $currentHash = $requestReader->getPostOrGetSanitize('currentHash', '');

        $isPluginAdmin = false;
        $context = array(
            'action' => 'ajaxRefreshStatsDashboard',
            'page' => $page,
            'subpage' => $subpage,
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        );
        $context = ABJ_404_Solution_AjaxAdminEndpointSupport::startAjaxDebugContext($context, 'Ajax_RefreshStatsDashboard::handle');

        try {
            if (!ABJ_404_Solution_AjaxAdminEndpointSupport::requireAdminWithNonceOrRespond(
                'abj404_refreshStatsDashboard',
                $context,
                'ajaxRefreshStatsDashboard'
            )) {
                return;
            }
            $isPluginAdmin = true;

            if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('refresh_stats_dashboard', 30, 60)) {
                ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxRefreshStatsDashboard.', $context);
                ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
                $payload = ABJ_404_Solution_AjaxAdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
                ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 429);
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

            ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($response, 200);
            return;

        } catch (Throwable $e) {
            $isPluginAdmin = ABJ_404_Solution_AjaxAdminEndpointSupport::resolveIsPluginAdminFallback($isPluginAdmin);

            $details = array(
                'exception' => array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ),
                'context' => $context,
            );
            ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailure('AJAX exception in ajaxRefreshStatsDashboard.', $details, $e);
            $capturedOutput = ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
            $payload = ABJ_404_Solution_AjaxAdminEndpointSupport::buildAjaxErrorResponse(
                'Server error while refreshing stats.',
                $details,
                $isPluginAdmin
            );
            ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 500);
            return;
        }
    }
}
