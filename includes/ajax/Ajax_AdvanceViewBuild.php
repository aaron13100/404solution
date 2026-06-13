<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX endpoint: ajaxAdvanceViewBuild. Bounded build-advance endpoint
 * paired with the fetch-only path on ajaxUpdatePaginationLinks /
 * ajaxWarmTableCache. Each call runs at most one resumable tick of the
 * staged view_done build (10s/stage budget; yields mid-stage on S2/S4/S5)
 * and returns the current progress. The JS poller fires this every ~1s
 * after a fetch returns `viewBuildPending: true`.
 *
 * Idempotent: concurrent calls fail to acquire the build lock and just
 * return the current progress. Errors are returned as 500 with the
 * standard error envelope so the JS poller can stop and surface a notice
 * instead of spinning forever.
 *
 * Reuses the `abj404_fetchInflightStage` nonce (already bound on every
 * admin page that can hit this endpoint) so no additional nonce plumbing
 * is needed.
 */
class ABJ_404_Solution_Ajax_AdvanceViewBuild {

    /** @return void */
    public function handle() {
        ABJ_404_Solution_AjaxRequestContractValidator::enforceCurrentRequest('ajax-advance-view-build');

        $functions = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
        /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator */
        $viewBuildOrchestrator = abj_service('view_build_orchestrator');
        $abj404logic = abj_service('plugin_logic');

        $page = $functions->getPostOrGetSanitize('page', '');
        $subpage = $functions->getPostOrGetSanitize('subpage', '');
        $requestId = ABJ_404_Solution_Ajax_AdminEndpointSupport::readClientRequestId();
        $forceViewRebuild = ((string)$functions->getPostOrGetSanitize('forceViewRebuild', '0') === '1');

        $isPluginAdmin = false;
        $context = array(
            'action' => 'ajaxAdvanceViewBuild',
            'page' => $page,
            'subpage' => $subpage,
            'requestId' => $requestId,
            'forceViewRebuild' => $forceViewRebuild ? 1 : 0,
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        );
        $context = ABJ_404_Solution_Ajax_AdminEndpointSupport::startAjaxDebugContext($context, 'ViewUpdater::advanceViewBuild');

        try {
            if (!ABJ_404_Solution_Ajax_AdminEndpointSupport::requireAdminWithNonceOrRespond(
                'abj404_fetchInflightStage',
                $context,
                'ajaxAdvanceViewBuild'
            )) {
                return;
            }
            $isPluginAdmin = true;

            // The poller fires this once per second per admin tab while a build
            // is in progress. A single tab might burn ~120 calls in a long
            // resumable build; keep the ceiling well above that.
            if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('advance_view_build', 600, 60)) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxAdvanceViewBuild.', $context);
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false), 429);
                return;
            }

            if (!is_object($viewBuildOrchestrator) || !method_exists($viewBuildOrchestrator, 'advanceViewBuildOnce')) {
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(array(
                    'status' => 'unsupported',
                    'progress' => array('status' => 'pending', 'stage' => 0, 'of' => 11,
                        'build_started' => 0, 'progress_text' => 'unsupported'),
                ), 200);
                return;
            }

            // The browser only sends forceViewRebuild=1 on the first advance call
            // of an ?abj404_force_view_rebuild=1 page-load. Pre-calling
            // forceRestartViewBuild() here (rather than in the fetch path) keeps
            // the rebuild owned by a single requestId so every staged sub-stage
            // shows up in the debug log. Non-blocking acquire (0s): a sibling
            // cron/tab holding the runner lock must not stall this request;
            // advanceViewBuildOnce(forceRebuild=true) below waits up to 10s and
            // runs equivalent drop/clear semantics inside its own locked region.
            if ($forceViewRebuild && method_exists($viewBuildOrchestrator, 'forceRestartViewBuild')) {
                $viewBuildOrchestrator->forceRestartViewBuild(0);
            }

            ABJ_404_Solution_Ajax_AdminEndpointSupport::tryClaimForegroundViewBuildLease($viewBuildOrchestrator);
            // Pass forceRebuild down so advanceViewBuildOnce takes the lock with
            // a 30s timeout (waiting for any in-flight cron/sibling build to
            // finish), re-invalidates inside the locked region, and runs the
            // build under THIS request's AJAX context. That is what makes every
            // staged_build_s* sub-stage event reach the browser's "AJAX Load
            // Times / Debug Info" panel.
            $progress = $viewBuildOrchestrator->advanceViewBuildOnce($forceViewRebuild);
            $statusValue = is_array($progress) && isset($progress['status']) && is_string($progress['status'])
                ? $progress['status'] : 'pending';

            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(array(
                'status' => $statusValue,
                'progress' => is_array($progress) ? $progress : array(),
            ), 200);
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
            ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX exception in ajaxAdvanceViewBuild.', $details, $e);
            $capturedOutput = ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit(
                ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Server error while advancing the view build.', $details, $isPluginAdmin),
                500
            );
            return;
        }
    }
}
