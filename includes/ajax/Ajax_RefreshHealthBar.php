<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX endpoint: ajaxRefreshHealthBar. Returns data needed to render
 * the redirects-page health bar: high-impact captured-URL count plus
 * redirect status counts (so the JS can compute "active = all - trash" and
 * build the View link).
 *
 * Decoupled from ajaxUpdatePaginationLinks because getHighImpactCapturedCount()
 * can run for tens of seconds on a cold cache against multi-million-row logs;
 * letting it block the table response leaves the page stuck on "Loading".
 */
class ABJ_404_Solution_Ajax_RefreshHealthBar {

    /** @return void */
    public function handle() {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-refresh-health-bar')) {
            return;
        }

        $functions = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
        $viewReadService = abj_service('view_read_service');
        /** @var ABJ_404_Solution_LogsRepositoryInterface $logsRepository */
        $logsRepository = abj_service('logs_repository');
        $abj404logic = abj_service('plugin_logic');

        $page = $functions->getPostOrGetSanitize('page', '');
        $subpage = $functions->getPostOrGetSanitize('subpage', '');

        $isPluginAdmin = false;
        $context = array(
            'action' => 'ajaxRefreshHealthBar',
            'page' => $page,
            'subpage' => $subpage,
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        );
        $context = ABJ_404_Solution_Ajax_AdminEndpointSupport::startAjaxDebugContext($context, 'Ajax_RefreshHealthBar::handle');

        try {
            if (!ABJ_404_Solution_Ajax_AdminEndpointSupport::requireAdminWithNonceOrRespond(
                'abj404_refreshHealthBar',
                $context,
                'ajaxRefreshHealthBar'
            )) {
                return;
            }
            $isPluginAdmin = true;

            // Match the pagination AJAX rate limit ceiling. Admin workflows can
            // re-trigger this on filter typing and tab switches.
            if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('refresh_health_bar', 1500, 60)) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxRefreshHealthBar.', $context);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 429);
                return;
            }

            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'redirect_status_counts');
            $statusCounts = $viewReadService->getRedirectStatusCounts();
            // Provide the captured filter constant so JS can build the "View" link.
            $statusCounts['_capturedFilter'] = ABJ404_STATUS_CAPTURED;

            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'high_impact_count');
            $rollupAvailable = $logsRepository->logsHitsTableExists();
            if ($rollupAvailable) {
                $highImpactCapturedCount = (int)$viewReadService->getHighImpactCapturedCount();
            } else {
                $logsRepository->scheduleHitsTableRebuild();
                $highImpactCapturedCount = null;
            }

            $response = array(
                'highImpactCapturedCount' => $highImpactCapturedCount,
                'rollupAvailable' => $rollupAvailable,
                'statusCounts' => $statusCounts,
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
            ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX exception in ajaxRefreshHealthBar.', $details, $e);
            $capturedOutput = ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse(
                'Server error while refreshing health bar.',
                $details,
                $isPluginAdmin
            );
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 500);
            return;
        }
    }
}
