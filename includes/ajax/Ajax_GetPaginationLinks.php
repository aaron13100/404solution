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
 */
class ABJ_404_Solution_Ajax_GetPaginationLinks {

    private const QUERY_TIMEOUT_SECONDS = 20;
    private const DETECT_ONLY_QUERY_TIMEOUT_SECONDS = 10;

    /** @return void */
    public function handle() {
        ABJ_404_Solution_AjaxRequestContractValidator::enforceCurrentRequest('ajax-update-pagination');

        $requestReader = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
        // Read+normalize the request ID before any service resolution so the
        // checkpoint pairs below (matrix coverage req. 2) can be correlated
        // to this request from their very first boundary.
        $requestId = ABJ_404_Solution_AjaxRequestLedger::normalizeId(
            $requestReader->getPostOrGetSanitize('requestId', ABJ_404_Solution_AjaxRequestLedger::UNKNOWN_ID));

        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
        $viewReadService = ABJ_404_Solution_AjaxCheckpointLogger::around(
            $requestId,
            'service_resolve_view_read_service',
            static fn() => abj_service('view_read_service')
        );
        $abj404logic = ABJ_404_Solution_AjaxCheckpointLogger::around(
            $requestId,
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
        // Immutable request ledger (matrix coverage req. 1): session ID and
        // retry-parent ID ride the same POST-body/query-string channel as
        // requestId; the client side of sending them is a separate task
        // (client transport telemetry), so these default to empty until
        // that ships -- reading them here now is forward-compatible.
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
            'request_id' => $requestId,
            'retry_count' => $retryCount,
            'detectOnly' => $detectOnly ? 1 : 0,
            'cacheMode' => $cacheMode,
            'currentSignature_length' => strlen($currentSignature),
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
            'handler_class' => __CLASS__,
        ), $ledger);
        $context = ABJ_404_Solution_Ajax_AdminEndpointSupport::startAjaxDebugContext($context, 'Ajax_GetPaginationLinks::handle');

        ABJ_404_Solution_AjaxRequestLedger::recordHeaderMismatchIfAny(
            $requestId, (string)$ledger['header_request_id']);

        try {
            if (!ABJ_404_Solution_Ajax_AdminEndpointSupport::requireAdminWithNonceOrRespond(
                'abj404_updatePaginationLink',
                $context,
                'ajaxUpdatePaginationLinks',
                array('nonce_value' => $nonce)
            )) {
                return;
            }
            $isPluginAdmin = true;

            // Rate limiting to prevent abuse. High ceilings: this endpoint is hit by first-paint
            // table loads, filter typing, pagination, and background detect-only checks.
            $maxRequestsPerMinute = $detectOnly ? 3000 : 1500;
            if (!self::checkRateLimitOrRespond($maxRequestsPerMinute, $context)) {
                return;
            }
            ABJ_404_Solution_AjaxStageDiagnostics::beginRequest($context);
            if (ABJ_404_Solution_Ajax_ClientReportBeacon::respondIfReportOnly($requestId)) {
                return;
            }

            // Update the perpage option (but only if provided). Some environments may omit
            // rowsPerPage on Enter key events; avoid unnecessary option writes. Wrapped as
            // its own checkpoint pair: the elapsed time includes any update_option hook
            // callbacks other plugins have registered (matrix coverage req. 2).
            if ($part === 'all' || $part === 'table') {
                ABJ_404_Solution_AjaxCheckpointLogger::around(
                    $requestId,
                    'update_per_page_option',
                    static function () use ($abj404logic, $rowsPerPage) {
                        self::updatePerPageOption($abj404logic, $rowsPerPage);
                    }
                );
            }

            /** @var ABJ_404_Solution_View $view */
            $view = ABJ_404_Solution_Ajax_AdminEndpointSupport::resolveViewInstance($abj404view);

            // Background detect-only refresh: a "did anything change?" poll the
            // client fires every ~30s while the admin is idle. It must NOT do the
            // foreground work -- table HTML render, status-count aggregates, and
            // the two pagination-link builds. For the redirects / captured tabs
            // we compute ONLY the table-data signature off the same one-page read
            // a full render uses (so the signature still matches), and return
            // {tableSignature, hasUpdate}. The logs tab keeps the full path
            // (bounded, append-only; not the constant-write pressure case).
            if ($detectOnly && self::detectOnlyCanComputeCheapSignature($subpage)
                    && is_object($view) && method_exists($view, 'computeTableDataSignature')) {
                $tableSignature = (string)ABJ_404_Solution_AjaxStageDiagnostics::runStage(
                    $context,
                    'detectOnlySignature',
                    static function () use ($view, $subpage) {
                        return $view->computeTableDataSignature($subpage, self::queryBudgetOptions(true));
                    }
                );
                $data = array(
                    'tableSignature' => $tableSignature,
                    'hasUpdate' => self::hasSignatureUpdate($currentSignature, $tableSignature),
                    'requestId' => $requestId,
                    'retryCount' => $retryCount,
                );
                ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('complete');
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit($data, 200);
                return;
            }

            $data = self::buildRequestedResponseParts(
                $part,
                $subpage,
                $view,
                $viewReadService,
                $context
            );
            if ($detectOnly && isset($data['tableSignature'])) {
                $tableSignature = is_scalar($data['tableSignature']) ? (string)$data['tableSignature'] : '';
                $data['hasUpdate'] = self::hasSignatureUpdate($currentSignature, $tableSignature);
            }
            $data['requestId'] = $requestId;
            $data['retryCount'] = $retryCount;

            ABJ_404_Solution_AjaxStageDiagnostics::finishRequest('complete');
            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
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

    /** @return array<string, int> */
    private static function queryBudgetOptions(bool $detectOnly = false): array {
        return array(
            '_abj404_query_timeout' => $detectOnly
                ? self::DETECT_ONLY_QUERY_TIMEOUT_SECONDS
                : self::QUERY_TIMEOUT_SECONDS,
        );
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
        ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxUpdatePaginationLinks.', $context);
        ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
        $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
        ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
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
     * @param ABJ_404_Solution_View $view
     */
    private static function getCurrentTableSignature($view, string $subpage): string {
        if (is_object($view) && method_exists($view, 'getCurrentTableDataSignature')) {
            return (string)$view->getCurrentTableDataSignature($subpage);
        }
        return '';
    }

    /**
     * Whether a subpage supports the cheap detect-only signature path. The
     * redirects and captured tabs both read through getRedirectsForView, so the
     * View can compute their signature from a single one-page read. The logs tab
     * reads a different source and keeps the full path.
     */
    private static function detectOnlyCanComputeCheapSignature(string $subpage): bool {
        return $subpage === 'abj404_redirects' || $subpage === 'abj404_captured';
    }

    private static function hasSignatureUpdate(string $currentSignature, string $tableSignature): bool {
        if ($currentSignature === '' || $tableSignature === '') {
            return false;
        }
        if (function_exists('hash_equals')) {
            return !hash_equals($currentSignature, $tableSignature);
        }
        return $currentSignature !== $tableSignature;
    }

    /**
     * @param ABJ_404_Solution_View $view
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewReadService
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function buildRequestedResponseParts(
        string $requestedPart,
        string $subpage,
        $view,
        $viewReadService,
        array &$context
    ): array {
        $builders = array(
            'table' => 'buildTablePart',
            'counts' => 'buildCountsPart',
            'pagination' => 'buildPaginationPart',
        );
        $parts = $requestedPart === 'all' ? array_keys($builders) : array($requestedPart);
        $data = array();
        foreach ($parts as $part) {
            $method = $builders[$part] ?? '';
            if ($method === '') {
                continue;
            }
            $data = array_merge($data, self::$method($subpage, $view, $viewReadService, $context));
        }
        return $data;
    }

    /**
     * @param ABJ_404_Solution_View $view
     * @param mixed $viewReadService
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function buildTablePart(string $subpage, $view, $viewReadService, array &$context): array {
        $renderers = array(
            'abj404_redirects' => array('stage' => 'table_redirects', 'method' => 'getAdminRedirectsPageTable'),
            'abj404_captured' => array('stage' => 'table_captured', 'method' => 'getCapturedURLSPageTable'),
            'abj404_logs' => array('stage' => 'table_logs', 'method' => 'getAdminLogsPageTable'),
        );
        if (!isset($renderers[$subpage])) {
            return array('table' => 'Error: Unexpected subpage requested.');
        }
        $renderer = $renderers[$subpage];
        $method = $renderer['method'];
        return ABJ_404_Solution_AjaxStageDiagnostics::runStage(
            $context,
            $renderer['stage'],
            static function () use ($subpage, $view, $viewReadService, $method, &$context) {
                if (($subpage === 'abj404_redirects' || $subpage === 'abj404_captured')
                        && is_object($viewReadService)
                        && method_exists($viewReadService, 'sortReadinessStatusForOrderby')) {
                    $orderby = is_scalar($context['orderby'] ?? null) ? (string)$context['orderby'] : '';
                    ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata(array(
                        'sort_readiness' => $viewReadService->sortReadinessStatusForOrderby($orderby),
                    ));
                }
                return array(
                    'table' => $view->$method($subpage, self::queryBudgetOptions()),
                    'tableSignature' => self::getCurrentTableSignature($view, $subpage),
                );
            }
        );
    }

    /**
     * @param ABJ_404_Solution_View $view
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewReadService
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function buildCountsPart(string $subpage, $view, $viewReadService, array &$context): array {
        unset($view);
        $queryOptions = self::queryBudgetOptions();
        if ($subpage === 'abj404_redirects') {
            $counts = ABJ_404_Solution_AjaxStageDiagnostics::runStage(
                $context,
                'redirect_status_counts',
                static function () use ($viewReadService, $queryOptions) {
                    $result = $viewReadService->getRedirectStatusCounts(false, $queryOptions);
                    ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata(array(
                        'cache' => !empty($result['_incomplete']) ? 'miss' : 'hit',
                    ));
                    return $result;
                }
            );
            if (!empty($counts['_incomplete'])) {
                return array('countsIncomplete' => true);
            }
            return array('tabCounts' => array(
                '0' => $counts['all'] ?? 0,
                (string)ABJ404_STATUS_MANUAL => $counts['manual'] ?? 0,
                (string)ABJ404_STATUS_AUTO => $counts['auto'] ?? 0,
                (string)ABJ404_TRASH_FILTER => $counts['trash'] ?? 0,
            ));
        }
        if ($subpage !== 'abj404_captured') {
            return array();
        }
        $counts = ABJ_404_Solution_AjaxStageDiagnostics::runStage(
            $context,
            'captured_status_counts',
            static function () use ($viewReadService, $queryOptions) {
                $result = $viewReadService->getCapturedStatusCounts(false, $queryOptions);
                ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata(array(
                    'cache' => !empty($result['_incomplete']) ? 'miss' : 'hit',
                ));
                return $result;
            }
        );
        if (!empty($counts['_incomplete'])) {
            return array('countsIncomplete' => true);
        }
        return array(
            'statusCounts' => $counts,
            'tabCounts' => array(
                '0' => $counts['all'] ?? 0,
                (string)ABJ404_STATUS_CAPTURED => $counts['captured'] ?? 0,
                (string)ABJ404_STATUS_IGNORED => $counts['ignored'] ?? 0,
                (string)ABJ404_STATUS_LATER => $counts['later'] ?? 0,
                (string)ABJ404_TRASH_FILTER => $counts['trash'] ?? 0,
                (string)ABJ404_HANDLED_FILTER => ($counts['ignored'] ?? 0) + ($counts['later'] ?? 0) + ($counts['trash'] ?? 0),
            ),
        );
    }

    /**
     * @param ABJ_404_Solution_View $view
     * @param mixed $viewReadService
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private static function buildPaginationPart(string $subpage, $view, $viewReadService, array &$context): array {
        unset($viewReadService);
        $queryOptions = self::queryBudgetOptions();
        $top = ABJ_404_Solution_AjaxStageDiagnostics::runStage(
            $context,
            'paginationLinksTop',
            static fn() => $view->getPaginationLinks($subpage, true, $queryOptions)
        );
        $bottom = ABJ_404_Solution_AjaxStageDiagnostics::runStage(
            $context,
            'paginationLinksBottom',
            static fn() => $view->getPaginationLinks($subpage, false, $queryOptions)
        );
        return array(
            'paginationLinksTop' => $top,
            'paginationLinksBottom' => $bottom,
        );
    }

    /**
     * Handle exceptions thrown during the endpoint. Emits the standard error
     * envelope with diagnostics for admins.
     *
     * @param Throwable $e
     * @param bool $isPluginAdmin
     * @param array<string, mixed> $context
     * @return void
     */
    private static function handlePaginationLinksException(
        Throwable $e, bool $isPluginAdmin, array $context
    ): void {
        $isPluginAdmin = ABJ_404_Solution_Ajax_AdminEndpointSupport::resolveIsPluginAdminFallback($isPluginAdmin, true);
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $GLOBALS['abj404_ajax_context']['is_plugin_admin'] = $isPluginAdmin;
        }

        $details = array(
            'exception' => array(
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ),
            'context' => $context,
        );
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $lastQuery = $GLOBALS['wpdb']->last_query ?? '';
            $details['wpdb'] = array(
                'last_error' => $GLOBALS['wpdb']->last_error ?? '',
                'last_query_redacted' => ABJ_404_Solution_Ajax_AdminEndpointSupport::redactSqlShape($lastQuery),
                'last_query_length' => is_string($lastQuery) ? strlen($lastQuery) : 0,
            );
        }
        $viewQueryDiagnostics = ABJ_404_Solution_Ajax_AdminEndpointSupport::extractViewQueryDiagnostics($e);
        if ($viewQueryDiagnostics !== null) {
            $details['view_query_diagnostics'] = $viewQueryDiagnostics;
        }

        // Always log to the plugin debug file, regardless of admin status.
        ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX exception in ajaxUpdatePaginationLinks.', $details, $e);
        $capturedOutput = ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
        if ($capturedOutput !== '') {
            $details['buffered_output'] = substr($capturedOutput, 0, 8000);
        }

        ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
        $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse(
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
