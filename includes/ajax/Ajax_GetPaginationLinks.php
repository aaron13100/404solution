<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin AJAX endpoint: ajaxUpdatePaginationLinks. Serves the admin redirects,
 * captured, and logs tables: fetches the subpage-specific table HTML, status
 * tab counts, pagination links, and the current data signature for client
 * change detection. The single-table denorm read (denorm Step 3b) is always
 * serveable, so there is no view-build gate: the table is rendered
 * synchronously on every request. All exceptions route through
 * handlePaginationLinksException for a consistent diagnostics envelope.
 *
 * This class owns the request boundary only: reading and normalizing the input,
 * authorization, rate limiting, choosing between the background poll and the
 * foreground build, and emitting a response or an error envelope. Building the
 * response body belongs to ABJ_404_Solution_AdminTableResponseParts.
 */
class ABJ_404_Solution_Ajax_GetPaginationLinks {

    /** @return void */
    public function handle() {
        ABJ_404_Solution_AjaxRequestContractValidator::enforceCurrentRequest('ajax-update-pagination');

        $requestReader = ABJ_404_Solution_AjaxAdminEndpointSupport::getRequestReader();
        // Read+normalize the request ID before any service resolution so the
        // checkpoint pairs below (matrix coverage req. 2) can be correlated
        // to this request from their very first boundary.
        $requestId = ABJ_404_Solution_AjaxRequestLedger::normalizeId(
            $requestReader->getPostOrGetSanitize('requestId', ABJ_404_Solution_AjaxRequestLedger::UNKNOWN_ID));
        $checkpointRequestId = ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestId(array(
            'action' => 'ajaxUpdatePaginationLinks',
            'request_id' => $requestId,
        ));

        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
        $viewReadService = ABJ_404_Solution_AjaxCheckpointLogger::around(
            $checkpointRequestId,
            'service_resolve_view_read_service',
            static fn() => abj_service('view_read_service')
        );
        $abj404logic = ABJ_404_Solution_AjaxCheckpointLogger::around(
            $checkpointRequestId,
            'service_resolve_plugin_logic',
            static fn() => abj_service('plugin_logic')
        );
        global $abj404view;

        $rowsPerPage = absint($requestReader->getPostOrGetSanitize('rowsPerPage'));
        $subpage = $requestReader->getPostOrGetSanitize('subpage');
        $nonce = $requestReader->getPostOrGetSanitize('nonce');
        $page = $requestReader->getPostOrGetSanitize('page', '');
        $filterText = $requestReader->getPostOrGetSanitize('filterText', '');
        $filter = $requestReader->getPostOrGetSanitize('filter', '');
        $orderby = $requestReader->getPostOrGetSanitize('orderby', '');
        $detectOnly = ((string)$requestReader->getPostOrGetSanitize('detectOnly', '0') === '1');
        $part = self::normalizePart((string)$requestReader->getPostOrGetSanitize('part', 'all'));
        $cacheMode = self::normalizeCacheMode((string)$requestReader->getPostOrGetSanitize('cacheMode', 'normal'));
        $currentSignature = self::normalizeCurrentSignature((string)$requestReader->getPostOrGetSanitize('currentSignature', ''));
        $retryCount = min(2, absint($requestReader->getPostOrGetSanitize('retryCount', '0')));
        $detachAbPayloadKey = ABJ_404_Solution_AjaxRequestLedger::detachAbPayloadKey(array(
            'subpage' => (string)$subpage, 'page' => (string)$page,
            'rows_per_page' => $rowsPerPage, 'filter_text' => (string)$filterText,
            'filter' => (string)$filter, 'orderby' => (string)$orderby,
            'detect_only' => $detectOnly ? 1 : 0, 'cache_mode' => $cacheMode,
            'current_signature' => $currentSignature,
        ));
        // Ledger identity fields join this workload fingerprint to the browser session.
        $ledger = ABJ_404_Solution_AjaxRequestLedger::readFields($requestReader);

        $isPluginAdmin = false;
        $context = array_merge(array(
            'action' => 'ajaxUpdatePaginationLinks',
            'page' => $page,
            'subpage' => $subpage,
            'rowsPerPage' => $rowsPerPage,
            'filterText_length' => strlen((string)$filterText),
            'filter' => $filter,
            'orderby' => $orderby,
            'part' => $part,
            'detach_ab_payload_key' => $detachAbPayloadKey,
            'request_id' => $requestId,
            'retry_count' => $retryCount,
            'detectOnly' => $detectOnly ? 1 : 0,
            'cacheMode' => $cacheMode,
            'currentSignature_length' => strlen($currentSignature),
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
            'handler_class' => __CLASS__,
        ), $ledger);
        $context = ABJ_404_Solution_AjaxAdminEndpointSupport::startAjaxDebugContext($context, 'Ajax_GetPaginationLinks::handle');

        ABJ_404_Solution_AjaxRequestLedger::recordHeaderMismatchIfAny(
            $checkpointRequestId, (string)$ledger['header_request_id']);

        // Entering the handler proper: authorization, queries and rendering.
        // A census row stranded here is a request that never finished its own
        // work, which is a different bug from one that finished in 1.3s and
        // then held its worker for three minutes (report 193's actual shape).
        ABJ_404_Solution_SameSiteRequestCensus::markPhase(
            ABJ_404_Solution_SameSiteRequestCensus::PHASE_HANDLER);

        try {
            if (!ABJ_404_Solution_AjaxAdminEndpointSupport::requireAdminWithNonceOrRespond(
                'abj404_updatePaginationLink',
                $context,
                'ajaxUpdatePaginationLinks',
                array('nonce_value' => $nonce)
            )) {
                return;
            }
            $isPluginAdmin = true;

            // The first attempt stays on the zero-write fast path. A browser
            // retry means the user already observed a transient failure, so
            // arm the durable flight recorder now that nonce and admin access
            // have been proved. Never trust raw retryCount before this point.
            if ($retryCount > 0) {
                $context = ABJ_404_Solution_AjaxAdminEndpointSupport::
                    armAuthorizedRetryDiagnostics($context);
                $checkpointRequestId = ABJ_404_Solution_AjaxRequestLedger::
                    instrumentedRequestId($context);
            }

            // Rate limiting to prevent abuse. High ceilings: this endpoint is hit by first-paint
            // table loads, filter typing, pagination, and background detect-only checks.
            $maxRequestsPerMinute = $detectOnly ? 3000 : 1500;
            if (!self::checkRateLimitOrRespond($maxRequestsPerMinute, $context)) {
                return;
            }
            ABJ_404_Solution_AjaxStageDiagnostics::beginRequest($context);
            if (ABJ_404_Solution_AjaxClientReportBeaconResponder::respondIfReportOnly($requestId)) {
                return;
            }

            // Update the perpage option (but only if provided). Some environments may omit
            // rowsPerPage on Enter key events; avoid unnecessary option writes. Wrapped as
            // its own checkpoint pair: the elapsed time includes any update_option hook
            // callbacks other plugins have registered (matrix coverage req. 2).
            if ($part === 'all' || $part === 'table') {
                ABJ_404_Solution_AjaxCheckpointLogger::around(
                    $checkpointRequestId,
                    'update_per_page_option',
                    static function () use ($abj404logic, $rowsPerPage) {
                        self::updatePerPageOption($abj404logic, $rowsPerPage);
                    }
                );
            }

            /** @var ABJ_404_Solution_View $view */
            $view = ABJ_404_Solution_AjaxAdminEndpointSupport::resolveViewInstance($abj404view);

            // Background detect-only refresh: a "did anything change?" poll the
            // client fires every ~30s while the admin is idle. It must NOT do the
            // foreground work -- table HTML render, status-count aggregates, and
            // the two pagination-link builds. For the redirects / captured tabs
            // we compute ONLY the table-data signature off the same one-page read
            // a full render uses (so the signature still matches), and return
            // {tableSignature, hasUpdate}. The logs tab keeps the full path
            // (bounded, append-only; not the constant-write pressure case).
            if ($detectOnly && ABJ_404_Solution_AdminTableResponseParts::canComputeCheapSignature($subpage)
                    && is_object($view) && method_exists($view, 'computeTableDataSignature')) {
                $tableSignature = (string)ABJ_404_Solution_AjaxStageDiagnostics::runStage(
                    $context,
                    'detectOnlySignature',
                    static function () use ($view, $subpage) {
                        return $view->computeTableDataSignature(
                            $subpage, ABJ_404_Solution_AdminTableResponseParts::queryBudgetOptions(true));
                    }
                );
                $data = array(
                    'tableSignature' => $tableSignature,
                    'hasUpdate' => ABJ_404_Solution_AdminTableResponseParts::hasSignatureUpdate(
                        $currentSignature, $tableSignature),
                    'requestId' => $requestId,
                    'retryCount' => $retryCount,
                );
                ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('complete');
                ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($data, 200);
                return;
            }

            $data = ABJ_404_Solution_AdminTableResponseParts::build(
                $part,
                $subpage,
                $view,
                $viewReadService,
                $context
            );
            if ($detectOnly && isset($data['tableSignature'])) {
                $tableSignature = is_scalar($data['tableSignature']) ? (string)$data['tableSignature'] : '';
                $data['hasUpdate'] = ABJ_404_Solution_AdminTableResponseParts::hasSignatureUpdate(
                    $currentSignature, $tableSignature);
            }
            $data['requestId'] = $requestId;
            $data['retryCount'] = $retryCount;

            ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('complete');
            ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($data, 200);
            return;

        } catch (Throwable $e) {
            ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('error');
            self::handlePaginationLinksException(
                $e, $isPluginAdmin, $context
            );
            return;
        }
    }

    private static function normalizeCacheMode(string $cacheModeRaw): string {
        return in_array($cacheModeRaw, array('normal', 'cache_or_pending', 'refresh_cache'), true)
            ? $cacheModeRaw : 'normal';
    }

    private static function normalizePart(string $partRaw): string {
        return in_array($partRaw, array('all', 'table', 'counts', 'pagination'), true)
            ? $partRaw : 'all';
    }

    private static function normalizeCurrentSignature(string $currentSignature): string {
        $currentSignature = strtolower(trim($currentSignature));
        if (strlen($currentSignature) > 128) {
            return substr($currentSignature, 0, 128);
        }
        return $currentSignature;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function checkRateLimitOrRespond(int $maxRequestsPerMinute, array $context): bool {
        $checkpointRequestId = ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestId($context);
        $rateLimited = ABJ_404_Solution_AjaxCheckpointLogger::around(
            $checkpointRequestId,
            'rate_limit_check',
            static fn() => ABJ_404_Solution_Ajax_Php::consumeRateLimit('update_pagination', $maxRequestsPerMinute, 60)
        );
        if (!$rateLimited) {
            return true;
        }

        ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'rate_limit_branch', array(
            'max_requests_per_minute' => $maxRequestsPerMinute,
        ));
        ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailureBranch(
            'rate_limit',
            'AJAX rate limit in ajaxUpdatePaginationLinks.',
            static fn() => $context
        );
        ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
        $payload = ABJ_404_Solution_AjaxAdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
        ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
        ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 429);
        return false;
    }

    /**
     * @param mixed $abj404logic
     */
    private static function updatePerPageOption($abj404logic, int $rowsPerPage): void {
        if ($rowsPerPage <= 0 || !is_object($abj404logic)) {
            return;
        }
        if (method_exists($abj404logic, 'adminActions')) {
            $abj404logic->adminActions()->updatePerPageOption($rowsPerPage);
            return;
        }
        if (method_exists($abj404logic, 'updatePerPageOption')) {
            $abj404logic->updatePerPageOption($rowsPerPage);
        }
    }

    /**
     * Handle endpoint exceptions with the standard admin diagnostics envelope.
     * @param Throwable $e
     * @param bool $isPluginAdmin
     * @param array<string, mixed> $context
     * @return void
     */
    private static function handlePaginationLinksException(
        Throwable $e, bool $isPluginAdmin, array $context
    ): void {
        $failure = ABJ_404_Solution_AjaxAdminEndpointSupport::safeLogAjaxFailureBranch(
            'exception_caught',
            'AJAX exception in ajaxUpdatePaginationLinks.',
            static function () use ($e, $isPluginAdmin, $context): array {
                $resolvedIsPluginAdmin = ABJ_404_Solution_AjaxAdminEndpointSupport::
                    resolveIsPluginAdminFallback($isPluginAdmin, true);
                if (isset($GLOBALS['abj404_ajax_context'])
                        && is_array($GLOBALS['abj404_ajax_context'])) {
                    $GLOBALS['abj404_ajax_context']['is_plugin_admin'] = $resolvedIsPluginAdmin;
                }
                $details = ABJ_404_Solution_AjaxFailureDetailsBuilder::pagination($e, $context);
                return array(
                    'is_plugin_admin' => $resolvedIsPluginAdmin,
                    'details' => $details,
                );
            },
            $e
        );
        if (!is_array($failure)) {
            $failure = array();
        }
        $isPluginAdmin = (bool)($failure['is_plugin_admin'] ?? $isPluginAdmin);
        $details = isset($failure['details']) && is_array($failure['details'])
            ? $failure['details']
            : array();
        $capturedOutput = ABJ_404_Solution_AjaxAdminEndpointSupport::getAndClearAjaxBufferedOutput();
        if ($capturedOutput !== '') {
            $details['buffered_output'] = substr($capturedOutput, 0, 8000);
        }

        ABJ_404_Solution_AjaxAdminEndpointSupport::markAjaxResponseSent();
        $payload = ABJ_404_Solution_AjaxAdminEndpointSupport::buildAjaxErrorResponse(
            'Server error while updating the table.',
            $details,
            $isPluginAdmin
        );
        $responseRequestId = $context['request_id'] ?? null;
        $responseRetryCount = $context['retry_count'] ?? null;
        $payload['requestId'] = is_string($responseRequestId) ? $responseRequestId : 'unknown00';
        $payload['retryCount'] = is_numeric($responseRetryCount)
            ? max(0, min(2, (int)$responseRetryCount)) : 0;
        ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($payload, 500);
    }
}
