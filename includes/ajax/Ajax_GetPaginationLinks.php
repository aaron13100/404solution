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

    /** @return void */
    public function handle() {
        ABJ_404_Solution_AjaxRequestContractValidator::enforceCurrentRequest('ajax-update-pagination');

        $functions = ABJ_404_Solution_Ajax_AdminEndpointSupport::getRequestReader();
        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
        $viewReadService = abj_service('view_read_service');
        $abj404logic = abj_service('plugin_logic');
        global $abj404view;

        $rowsPerPage = absint($functions->getPostOrGetSanitize('rowsPerPage'));
        $subpage = $functions->getPostOrGetSanitize('subpage');
        $nonce = $functions->getPostOrGetSanitize('nonce');
        $page = $functions->getPostOrGetSanitize('page', '');
        $filterText = $functions->getPostOrGetSanitize('filterText', '');
        $filter = $functions->getPostOrGetSanitize('filter', '');
        $detectOnly = ((string)$functions->getPostOrGetSanitize('detectOnly', '0') === '1');
        $cacheMode = self::normalizeCacheMode((string)$functions->getPostOrGetSanitize('cacheMode', 'normal'));
        $currentSignature = self::normalizeCurrentSignature((string)$functions->getPostOrGetSanitize('currentSignature', ''));

        $isPluginAdmin = false;
        $context = array(
            'action' => 'ajaxUpdatePaginationLinks',
            'page' => $page,
            'subpage' => $subpage,
            'rowsPerPage' => $rowsPerPage,
            'filterText_length' => strlen((string)$filterText),
            'filter' => $filter,
            'detectOnly' => $detectOnly ? 1 : 0,
            'cacheMode' => $cacheMode,
            'currentSignature_length' => strlen($currentSignature),
            'request_uri' => array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        );
        $context = ABJ_404_Solution_Ajax_AdminEndpointSupport::startAjaxDebugContext($context, 'Ajax_GetPaginationLinks::handle');

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

            // Update the perpage option (but only if provided). Some environments may omit
            // rowsPerPage on Enter key events; avoid unnecessary option writes.
            self::updatePerPageOption($abj404logic, $rowsPerPage);

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
                ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'detectOnlySignature');
                $tableSignature = (string)$view->computeTableDataSignature($subpage);
                $data = array(
                    'tableSignature' => $tableSignature,
                    'hasUpdate' => self::hasSignatureUpdate($currentSignature, $tableSignature),
                );
                ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
                ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($data, 200);
                return;
            }

            // The single-table denorm read (denorm Step 3b) is always
            // serveable, so the table is rendered synchronously here. No
            // view-build / cache-warm gate is consulted: an AJAX fetch never
            // triggers a staged build and never returns a pending response.
            $data = self::fetchTableDataForSubpage($subpage, $view, $viewReadService, $context);

            $tableSignature = self::getCurrentTableSignature($view, $subpage);
            $data['tableSignature'] = $tableSignature;
            if ($detectOnly) {
                $data['hasUpdate'] = self::hasSignatureUpdate($currentSignature, $tableSignature);
            }

            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'paginationLinksTop');
            $data['paginationLinksTop'] = $view->getPaginationLinks($subpage);
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'paginationLinksBottom');
            $data['paginationLinksBottom'] = $view->getPaginationLinks($subpage, false);

            ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($data, 200);
            return;

        } catch (Throwable $e) {
            // allow-silent-catch: handlePaginationLinksException embeds/logs the throwable in the AJAX response path.
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
        if (!ABJ_404_Solution_Ajax_Php::consumeRateLimit('update_pagination', $maxRequestsPerMinute, 60)) {
            return true;
        }

        ABJ_404_Solution_Ajax_AdminEndpointSupport::safeLogAjaxFailure('AJAX rate limit in ajaxUpdatePaginationLinks.', $context);
        ABJ_404_Solution_Ajax_AdminEndpointSupport::markAjaxResponseSent();
        $payload = ABJ_404_Solution_Ajax_AdminEndpointSupport::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
        ABJ_404_Solution_Ajax_AdminEndpointSupport::getAndClearAjaxBufferedOutput();
        ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 429);
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
     * @param mixed $view
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
     * Fetch table data and tab counts for a given admin subpage.
     *
     * @param string $subpage
     * @param ABJ_404_Solution_View $view
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewReadService
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function fetchTableDataForSubpage(string $subpage, $view, $viewReadService, array &$context): array {
        $data = array();
        if ($subpage == 'abj404_redirects') {
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'table_redirects');
            $data['table'] = $view->getAdminRedirectsPageTable($subpage);

            // Include tab counts so the page shell can render instantly with placeholders.
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'redirect_status_counts');
            $statusCounts = $viewReadService->getRedirectStatusCounts();
            $data['tabCounts'] = array(
                '0' => $statusCounts['all'] ?? 0,
                (string)ABJ404_STATUS_MANUAL => $statusCounts['manual'] ?? 0,
                (string)ABJ404_STATUS_AUTO => $statusCounts['auto'] ?? 0,
                (string)ABJ404_TRASH_FILTER => $statusCounts['trash'] ?? 0,
            );

        } else if ($subpage == 'abj404_captured') {
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'table_captured');
            $data['table'] = $view->getCapturedURLSPageTable($subpage);

            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'captured_status_counts');
            $statusCounts = $viewReadService->getCapturedStatusCounts();
            $data['statusCounts'] = $statusCounts;
            $data['tabCounts'] = array(
                '0' => $statusCounts['all'] ?? 0,
                (string)ABJ404_STATUS_CAPTURED => $statusCounts['captured'] ?? 0,
                (string)ABJ404_STATUS_IGNORED => $statusCounts['ignored'] ?? 0,
                (string)ABJ404_STATUS_LATER => $statusCounts['later'] ?? 0,
                (string)ABJ404_TRASH_FILTER => $statusCounts['trash'] ?? 0,
                (string)ABJ404_HANDLED_FILTER => ($statusCounts['ignored'] ?? 0) + ($statusCounts['later'] ?? 0) + ($statusCounts['trash'] ?? 0),
            );

        } else if ($subpage == 'abj404_logs') {
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'table_logs');
            $data['table'] = $view->getAdminLogsPageTable($subpage);

        } else {
            $data['table'] = 'Error: Unexpected subpage requested.';
        }
        return $data;
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
        ABJ_404_Solution_Ajax_AdminEndpointSupport::sendJsonResponseAndExit($payload, 500);
    }
}
