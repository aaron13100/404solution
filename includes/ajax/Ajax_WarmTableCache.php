<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX endpoint: ajaxWarmTableCache. Stage-by-stage snapshot warmup
 * for the admin redirects / captured table caches. Honors the same
 * view-build gate as ajaxUpdatePaginationLinks: returns `ready=false` with
 * `viewBuildPending` while view_done is not serveable so the JS placeholder
 * hydration loop keeps polling instead of triggering an inline build.
 */
class ABJ_404_Solution_Ajax_WarmTableCache {

    /** @return void */
    public function handle() {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-warm-table-cache')) {
            return;
        }

        $functions = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
        /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator */
        $viewBuildOrchestrator = abj_service('view_build_orchestrator');
        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
        $viewReadService = abj_service('view_read_service');
        $abj404logic = abj_service('plugin_logic');

        $rowsPerPage = absint($functions->getPostOrGetSanitize('rowsPerPage'));
        $subpage = $functions->getPostOrGetSanitize('subpage');
        $nonce = $functions->getPostOrGetSanitize('nonce');
        $page = $functions->getPostOrGetSanitize('page', '');
        $filterText = $functions->getPostOrGetSanitize('filterText', '');
        $filter = $functions->getPostOrGetSanitize('filter', '');

        $isPluginAdmin = false;
        $context = array(
            'action' => 'ajaxWarmTableCache',
            'page' => $page,
            'subpage' => $subpage,
            'rowsPerPage' => $rowsPerPage,
            'filterText_length' => strlen((string)$filterText),
            'filter' => $filter,
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        );
        $context = ABJ_404_Solution_Ajax_AdminEndpointSupport::startAjaxDebugContext($context, 'ViewUpdater::warmTableCache');

        try {
            if (!wp_verify_nonce($nonce, 'abj404_updatePaginationLink')) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX invalid nonce in ajaxWarmTableCache.', $context);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Invalid security token', null, false), 403);
                return;
            }

            $isPluginAdmin = abj_service('admin_access_policy')->isPluginAdmin();
            if (!$isPluginAdmin) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX unauthorized in ajaxWarmTableCache.', $context);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Unauthorized', null, false), 403);
                return;
            }

            if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('warm_table_cache', 1500, 60)) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxWarmTableCache.', $context);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false), 429);
                return;
            }

            if ($rowsPerPage > 0) {
                $abj404logic->adminActions()->updatePerPageOption($rowsPerPage);
            }

            if ($subpage !== 'abj404_redirects' && $subpage !== 'abj404_captured') {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(array(
                    'status' => 'ready',
                    'ready' => true,
                    'uncached' => true,
                    'stage' => 'rows',
                    'stageNumber' => 1,
                    'queryLabel' => 'getRedirectsForView',
                ), 200);
                return;
            }

            // Same view-build gate as the fetch endpoint: warming the snapshot cache
            // calls getRedirectsForView, which would inline-build view_done if missing.
            // When view_done is not serveable, the JS poller must advance the build via
            // ajaxAdvanceViewBuild before the snapshot warm can start.
            if (!$viewBuildOrchestrator->viewDoneIsServeable()) {
                $progress = $viewBuildOrchestrator->getViewBuildProgress();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(array(
                    'status' => 'pending',
                    'ready' => false,
                    'viewBuildPending' => true,
                    'stage' => 'rows',
                    'stageNumber' => 1,
                    'queryLabel' => 'getRedirectsForView',
                    'progress' => $progress,
                ), 200);
                return;
            }

            $tableOptions = $abj404logic->settingsUpdate()->getTableOptions($subpage);
            $stage = 'table_cache_rows';
            if ($viewReadService->viewRowsSnapshotAvailable($subpage, $tableOptions)) {
                $stage = 'table_cache_count';
            }
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, $stage);
            $warmup = $viewReadService->warmViewTableSnapshotStage($subpage, $tableOptions);

            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($warmup, 200);
            return;
        } catch (Throwable $e) {
            // Race recovery: same defense as getPaginationLinks. The warm path uses
            // a different response shape because the JS placeholder hydration
            // consumes ready=false directly.
            $pending = ABJ_404_Solution_ViewBuildPendingResponseBuilder::find($e);
            if ($pending !== null) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(
                    ABJ_404_Solution_ViewBuildPendingResponseBuilder::warmResponse($viewBuildOrchestrator, $pending),
                    200
                );
                return;
            }

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
            $viewQueryDiagnostics = ABJ_404_Solution_Ajax_AdminEndpointSupport::extractViewQueryDiagnostics($e);
            if ($viewQueryDiagnostics !== null) {
                $details['view_query_diagnostics'] = $viewQueryDiagnostics;
            }
            ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX exception in ajaxWarmTableCache.', $details, $e);
            $capturedOutput = ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(
                ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Server error while preparing table data.', $details, $isPluginAdmin),
                500
            );
            return;
        }
    }
}
