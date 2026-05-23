<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Funtcions supporting Ajax stuff.  */

class ABJ_404_Solution_ViewUpdater {

    use ABJ_404_Solution_AjaxFailureLoggingTrait;


	/** @var self|null */
	private static $instance = null;

	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_ViewUpdater();
		}
		
		return self::$instance;
	}
		
    /** @return void */
    static function init() {
        $me = ABJ_404_Solution_ViewUpdater::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxUpdatePaginationLinks',
                array($me, 'getPaginationLinks'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxWarmTableCache',
                array($me, 'warmTableCache'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshStatsDashboard',
                array($me, 'refreshStatsDashboard'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshHealthBar',
                array($me, 'refreshHealthBar'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxFetchInflightStage',
                array($me, 'fetchInflightStage'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxAdvanceViewBuild',
                array($me, 'advanceViewBuild'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshAdminNonces',
                array($me, 'refreshAdminNonces'));
        // wp_ajax_nopriv_ is for normal users
    }

    /**
     * Admin nonce action verbs JS call sites consume. Keep in sync with
     * view_updater_nonce_refresh.js NONCE_DATA_ATTRS and the wp_verify_nonce()
     * calls below + in Ajax_TrendData.php.
     * @return string[]
     */
    public static function adminNonceActions(): array {
        return array('abj404_updatePaginationLink', 'abj404_fetchInflightStage',
            'abj404_refreshStatsDashboard', 'abj404_refreshHealthBar', 'abj404_trendData');
    }

    /**
     * B20: mint fresh admin AJAX nonces for the page so the JS retry helper
     * recovers transparently from a 12-24h-idle expired nonce. No nonce on
     * the request itself (the caller's nonce expired by definition); the
     * userIsPluginAdmin() capability gate is the only authorisation - which
     * also handles the genuinely-logged-out case (full page refresh needed).
     * @return void
     */
    function refreshAdminNonces() {
        $abj404logic = abj_service('plugin_logic');
        $ctx = self::startAjaxDebugContext(array('action' => 'ajaxRefreshAdminNonces',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0));
        try {
            if (!$abj404logic->userIsPluginAdmin()) {
                self::safeLogAjaxFailure('AJAX unauthorized in ajaxRefreshAdminNonces.', $ctx);
                self::markAjaxResponseSent();
                self::sendJsonResponseAndExit(self::buildAjaxErrorResponse('Unauthorized', null, false), 403);
                return;
            }
            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('refresh_admin_nonces', 60, 60)) {
                self::safeLogAjaxFailure('AJAX rate limit in ajaxRefreshAdminNonces.', $ctx);
                self::markAjaxResponseSent();
                self::sendJsonResponseAndExit(self::buildAjaxErrorResponse(
                    'Rate limit exceeded. Please try again later.', null, false), 429);
                return;
            }
            $nonces = array();
            foreach (self::adminNonceActions() as $action) {
                $nonces[$action] = wp_create_nonce($action);
            }
            self::markAjaxResponseSent();
            self::sendJsonResponseAndExit(array('success' => true,
                'data' => array('nonces' => $nonces)), 200);
        } catch (Throwable $e) {
            self::safeLogAjaxFailure('AJAX exception in ajaxRefreshAdminNonces.', $ctx, $e);
            self::markAjaxResponseSent();
            self::sendJsonResponseAndExit(self::buildAjaxErrorResponse(
                'Server error while refreshing nonces.', null, false), 500);
        }
    }

    /**
     * Validate a client-supplied request id used as the transient suffix for
     * in-flight stage tracking.  The id is only ever written into a transient
     * key (`abj404_inflight_<id>`), never executed or logged verbatim — but we
     * still constrain it to alphanumerics so a malformed payload cannot collide
     * with other plugins' transients or blow past WP's 172-char option-name
     * limit.
     *
     * @return string  Sanitized id, or '' if missing/invalid.
     */
    private static function readClientRequestId() {
        $raw = '';
        if (isset($_REQUEST['requestId'])) {
            $raw = $_REQUEST['requestId'];
        }
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        if (!preg_match('/\A[a-zA-Z0-9]{8,64}\z/', $raw)) {
            return '';
        }
        return $raw;
    }

    /**
     * Best-effort foreground lease for admin/browser-owned rebuilds. Failure
     * only means cron may compete for the view-build lock; it must never break
     * the admin table response itself.
     *
     * @param mixed $dao
     * @return void
     */
    private static function tryClaimForegroundViewBuildLease($dao): void {
        if (!is_object($dao) || !method_exists($dao, 'claimForegroundViewBuildLease')) {
            return;
        }
        try {
            $dao->claimForegroundViewBuildLease();
        } catch (Throwable $e) {
            self::safeLogAjaxFailure(
                'claimForegroundViewBuildLease failed; cron may compete for the build lock.',
                null,
                $e
            );
        }
    }

    /**
     * @param string $stage
     * @return void
     */
    public static function markInflightStage($stage) {
        ABJ_404_Solution_AjaxStageDiagnostics::markInflightStage($stage);
    }

    /**
     * @param int $type
     * @return bool
     */
    public static function isFatalErrorType($type) {
        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        return in_array($type, $fatalTypes, true);
    }

    /**
     * @param string $message
     * @param array<string, mixed>|null $details
     * @param bool $isPluginAdmin
     * @return array<string, mixed>
     */
    public static function buildAjaxErrorResponse($message, $details, $isPluginAdmin) {
        $data = array(
            'message' => $message,
        );
        if ($isPluginAdmin && $details !== null) {
            $data['details'] = $details;
        }
        return array(
            'success' => false,
            'data' => $data,
        );
    }

    /**
     * @param mixed $payload
     * @param int $httpStatus
     * @return void
     */
    public static function sendJsonResponseAndExit($payload, $httpStatus = 200) {
        if (!headers_sent()) {
            // Marker headers help support quickly identify that this response came from our AJAX endpoint.
            // These are safe to expose (no sensitive values).
            if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
                $ctx = $GLOBALS['abj404_ajax_context'];
                if (array_key_exists('action', $ctx) && is_string($ctx['action'])) {
                    header('X-ABJ404-Ajax: ' . preg_replace('/[\r\n]+/', '', $ctx['action']));
                }
                if (array_key_exists('subpage', $ctx) && is_string($ctx['subpage']) && $ctx['subpage'] !== '') {
                    header('X-ABJ404-Subpage: ' . preg_replace('/[\r\n]+/', '', $ctx['subpage']));
                }
                if (array_key_exists('requestId', $ctx) && is_string($ctx['requestId']) && $ctx['requestId'] !== '') {
                    header('X-ABJ404-Request-Id: ' . preg_replace('/[^a-zA-Z0-9]/', '', $ctx['requestId']));
                }
            }
            header('Content-type: application/json; charset=UTF-8');
            if (function_exists('status_header')) {
                status_header($httpStatus);
            } else if (function_exists('http_response_code')) {
                http_response_code($httpStatus);
            }
        }
        echo json_encode($payload);

        // Test hook: tests register `abj404_should_exit` returning false to skip exit.
        if (!apply_filters('abj404_should_exit', true, array('source' => 'viewUpdater_emitJson'))) {
            return;
        }

        // Flush the response to the web server immediately so shutdown hooks
        // (e.g. hits table rebuild) don't block the HTTP connection. Without this,
        // reverse proxies like Cloudflare may time out (HTTP 524) if a shutdown
        // hook runs a slow query, because the HTTP response isn't delivered until
        // PHP exits.
        if (function_exists('ob_end_flush')) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
        if (function_exists('flush')) {
            flush();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        exit;
    }

    /**
     * @param object|null $abj404view
     * @return object
     */
    private static function resolveViewInstance(&$abj404view) {
        if (is_object($abj404view)) {
            return $abj404view;
        }
        if (function_exists('abj_service')) {
            $resolved = abj_service('view');
            if (is_object($resolved)) {
                $abj404view = $resolved;
                return $abj404view;
            }
        }
        throw new Exception('ABJ404 view service not initialized (abj404view is null).');
    }

    // safeJsonEncode / redactSqlShape / safeLogAjaxFailure /
    // extractViewQueryDiagnostics live on ABJ_404_Solution_AjaxFailureLoggingTrait
    // (see includes/ajax/AjaxFailureLoggingTrait.php). self::method() calls
    // resolve through the trait composition unchanged.

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function startAjaxDebugContext($context) {
        if (!is_array($context)) {
            $context = array();
        }

        // Keep minimal state in a global so the global shutdown handler can act on it.
        // Mark that this context was created internally by this handler (not user input).
        $context['abj404_context_source'] = 'ViewUpdater::getPaginationLinks';
        $context['ajax_expected_json'] = true;
        $context['response_sent'] = false;
        $context['ob_level_before'] = ob_get_level();
        // Client-supplied request id for in-flight stage diagnostics (see setStage()).
        // The browser generates this so it has a key to look up the stage even when
        // a pure timeout means no response/header ever arrived.
        $context['requestId'] = self::readClientRequestId();

        // Prevent WordPress's "critical error" HTML page from masking details for AJAX calls.
        if (!headers_sent()) {
            // Marker headers help support quickly identify that this response came from our AJAX endpoint.
            // These are safe to expose (no sensitive values).
            if (array_key_exists('action', $context) && is_string($context['action'])) {
                header('X-ABJ404-Ajax: ' . preg_replace('/[\r\n]+/', '', $context['action']));
            }
            if (array_key_exists('subpage', $context) && is_string($context['subpage']) && $context['subpage'] !== '') {
                header('X-ABJ404-Subpage: ' . preg_replace('/[\r\n]+/', '', $context['subpage']));
            }
            if ($context['requestId'] !== '') {
                header('X-ABJ404-Request-Id: ' . $context['requestId']);
            }
            @ini_set('display_errors', '0');
        }
        if (apply_filters('abj404_should_manage_output_buffer', true, array('source' => 'viewUpdater_startAjaxDebugContext'))) {
            @ob_start();
        }

        $GLOBALS['abj404_ajax_context'] = $context;
        return $context;
    }

    /** @return void */
    private static function markAjaxResponseSent() {
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $GLOBALS['abj404_ajax_context']['response_sent'] = true;
        }
    }

    /** @return string */
    private static function getAndClearAjaxBufferedOutput() {
        if (!apply_filters('abj404_should_manage_output_buffer', true, array('source' => 'viewUpdater_getAndClearAjaxBufferedOutput'))) {
            return '';
        }

        $out = '';
        if (ob_get_level() > 0) {
            $out = (string)ob_get_contents();
        }

        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $minLevel = array_key_exists('ob_level_before', $GLOBALS['abj404_ajax_context'])
                ? intval($GLOBALS['abj404_ajax_context']['ob_level_before']) : 0;
            while (ob_get_level() > $minLevel) {
                @ob_end_clean();
            }
        } else {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }

        return $out;
    }

    /** @return ABJ_404_Solution_Functions|ABJ_404_Solution_DataAccess */
    private static function getRequestReader() {
        $container = ABJ_404_Solution_ServiceContainer::getInstance();
        if ($container->has('functions')) {
            /** @var ABJ_404_Solution_Functions $functions */
            $functions = $container->get('functions');
            return $functions;
        }
        /** @var ABJ_404_Solution_Functions $functions */
        $functions = abj_service('functions');
        return $functions;
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

            // Include tab counts so the page shell can render instantly with
            // placeholders and fill them in. The slower health-bar query
            // (getHighImpactCapturedCount, see refreshHealthBar()) is fetched
            // in a separate AJAX call so it never blocks first paint of the table.
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'redirect_status_counts');
            $statusCounts = $viewReadService->getRedirectStatusCounts();
            // Tab counts keyed by filter value for JS tab updates.
            $data['tabCounts'] = array(
                '0' => $statusCounts['all'] ?? 0,
                (string)ABJ404_STATUS_MANUAL => $statusCounts['manual'] ?? 0,
                (string)ABJ404_STATUS_AUTO => $statusCounts['auto'] ?? 0,
                (string)ABJ404_TRASH_FILTER => $statusCounts['trash'] ?? 0,
            );

        } else if ($subpage == 'abj404_captured') {
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'table_captured');
            $data['table'] = $view->getCapturedURLSPageTable($subpage);

            // Include tab counts so the page shell can render instantly.
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'captured_status_counts');
            $statusCounts = $viewReadService->getCapturedStatusCounts();
            $data['statusCounts'] = $statusCounts;
            // Tab counts keyed by filter value for JS tab updates.
            // Includes the "handled" composite count for simple mode.
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
     * Handle exceptions thrown during getPaginationLinks execution.
     *
     * @param Throwable $e
     * @param mixed $viewBuildOrchestrator
     * @param string $subpage
     * @param string $cacheMode
     * @param bool $isPluginAdmin
     * @param array<string, mixed> $context
     * @return void
     */
    private static function handlePaginationLinksException(
        Throwable $e, $viewBuildOrchestrator, string $subpage, string $cacheMode,
        bool $isPluginAdmin, array $context
    ): void {
        // Race recovery: viewDoneIsServeable() can race with invalidateViewDone();
        // surface the pending shape the JS poller already handles, never a 500.
        $pending = ABJ_404_Solution_ViewBuildPendingResponseBuilder::find($e);
        if ($pending !== null) {
            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            self::sendJsonResponseAndExit(
                ABJ_404_Solution_ViewBuildPendingResponseBuilder::fetchResponse($viewBuildOrchestrator, $subpage, $cacheMode, $pending),
                200
            );
            return;
        }
        // Determine admin status for diagnostics (never shown to non-admins).
        // If PluginLogic is broken/throws, fall back to WordPress capability checks so real admins can still see details.
        if (!$isPluginAdmin) {
            $abj404logic = abj_service('plugin_logic');
            if (is_object($abj404logic) && method_exists($abj404logic, 'userIsPluginAdmin')) {
                try {
                    $isPluginAdmin = (bool)$abj404logic->userIsPluginAdmin();
                } catch (Throwable $ignored) { // allow-silent-catch: admin-status detection; PluginLogic may be the broken component, default to non-admin (hide details)
                    $isPluginAdmin = false;
                }
            }
            if (!$isPluginAdmin) {
                // Best-effort fallback: treat WordPress administrators as plugin admins for debugging
                // if PluginLogic is broken. Avoid current_user_can() to keep delegated admin semantics
                // centralized in PluginLogic.
                if (function_exists('wp_get_current_user')) {
                    $user = ABJ_404_Solution_UserRef::fromWpUser(wp_get_current_user());
                    if ($user !== null) {
                        $isPluginAdmin = $user->isAdministrator();
                    }
                }
                if (!$isPluginAdmin && function_exists('is_super_admin') && is_super_admin()) {
                    $isPluginAdmin = true;
                }
            }
            if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
                $GLOBALS['abj404_ajax_context']['is_plugin_admin'] = $isPluginAdmin;
            }
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
                'last_query_redacted' => self::redactSqlShape($lastQuery),
                'last_query_length' => is_string($lastQuery) ? strlen($lastQuery) : 0,
            );
        }
        $viewQueryDiagnostics = self::extractViewQueryDiagnostics($e);
        if ($viewQueryDiagnostics !== null) {
            $details['view_query_diagnostics'] = $viewQueryDiagnostics;
        }

        // Always log to the plugin debug file, regardless of admin status.
        self::safeLogAjaxFailure('AJAX exception in ajaxUpdatePaginationLinks.', $details, $e);
        $capturedOutput = self::getAndClearAjaxBufferedOutput();
        if ($capturedOutput !== '') {
            $details['buffered_output'] = substr($capturedOutput, 0, 8000);
        }

        self::markAjaxResponseSent();
        $payload = self::buildAjaxErrorResponse(
            'Server error while updating the table.',
            $details,
            $isPluginAdmin
        );
        self::sendJsonResponseAndExit($payload, 500);
    }

    /** @return void */
    function getPaginationLinks() {
        $functions = self::getRequestReader();
        /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator */
        $viewBuildOrchestrator = abj_service('view_build_orchestrator');
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
        $cacheModeRaw = (string)$functions->getPostOrGetSanitize('cacheMode', 'normal');
        $cacheMode = in_array($cacheModeRaw, array('normal', 'cache_or_pending', 'refresh_cache'), true)
            ? $cacheModeRaw : 'normal';
        $currentSignature = strtolower(trim((string)$functions->getPostOrGetSanitize('currentSignature', '')));
        if (strlen($currentSignature) > 128) {
            $currentSignature = substr($currentSignature, 0, 128);
        }

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
        $context = self::startAjaxDebugContext($context);

        try {
            // Verify nonce for CSRF protection
            if (!wp_verify_nonce($nonce, 'abj404_updatePaginationLink')) {
                self::safeLogAjaxFailure('AJAX invalid nonce in ajaxUpdatePaginationLinks.', $context);
                self::markAjaxResponseSent();
                $payload = self::buildAjaxErrorResponse('Invalid security token', null, false);
                self::sendJsonResponseAndExit($payload, 403);
                return;
            }

            // Verify user has appropriate capabilities (respects plugin admin users)
            $abj404logic = abj_service('plugin_logic');
            $isPluginAdmin = $abj404logic->userIsPluginAdmin();
            if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
                $GLOBALS['abj404_ajax_context']['is_plugin_admin'] = $isPluginAdmin;
            }
            if (!$isPluginAdmin) {
                self::safeLogAjaxFailure('AJAX unauthorized in ajaxUpdatePaginationLinks.', $context);
                self::markAjaxResponseSent();
                $payload = self::buildAjaxErrorResponse('Unauthorized', null, false);
                self::sendJsonResponseAndExit($payload, 403);
                return;
            }

            // Rate limiting to prevent abuse.
            // This endpoint is hit by first-paint table loads, filter typing, pagination, and
            // background detect-only checks; 100/min can throttle normal admin usage and leave
            // tables stuck on "Loading…" under active workflows.
            // Keep the protection, but use high ceilings for authenticated plugin-admin traffic.
            // Parallel admin workflows can legitimately burst well above a few hundred requests/min.
            $maxRequestsPerMinute = $detectOnly ? 3000 : 1500;
            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('update_pagination', $maxRequestsPerMinute, 60)) {
                self::safeLogAjaxFailure('AJAX rate limit in ajaxUpdatePaginationLinks.', $context);
                self::markAjaxResponseSent();
                $payload = self::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
                self::sendJsonResponseAndExit($payload, 429);
                return;
            }

            // Update the perpage option (but only if provided).
            // Some environments may omit rowsPerPage on Enter key events; avoid unnecessary option writes.
            if ($rowsPerPage > 0) {
                $abj404logic->updatePerPageOption($rowsPerPage);
            }

            /** @var ABJ_404_Solution_View $view */
            $view = self::resolveViewInstance($abj404view);

            // View-build gate: never let an AJAX fetch trigger an inline staged
            // build.  If the precomputed view_done table is not serveable
            // (missing or invalidated by a recent redirect edit), respond
            // immediately with `viewBuildPending` and let the JS poller hit
            // ajaxAdvanceViewBuild repeatedly to advance the build one tick
            // per call.  No HTTP 500 path from build pressure can happen here.
            if (($subpage === 'abj404_redirects' || $subpage === 'abj404_captured')
                    && !$detectOnly
                    && !$viewBuildOrchestrator->viewDoneIsServeable()) {
                $stage = ($subpage === 'abj404_captured') ? 'table_captured' : 'table_redirects';
                ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, $stage);
                $progress = $viewBuildOrchestrator->getViewBuildProgress();
                self::markAjaxResponseSent();
                self::getAndClearAjaxBufferedOutput();
                self::sendJsonResponseAndExit(array(
                    'viewBuildPending' => true,
                    'cacheMode' => $cacheMode,
                    'subpage' => $subpage,
                    'progress' => $progress,
                    'message' => __('Preparing the redirects view table. Please wait.', '404-solution'),
                ), 200);
                return;
            }

            if ($cacheMode === 'cache_or_pending'
                    && !$detectOnly
                    && ($subpage === 'abj404_redirects' || $subpage === 'abj404_captured')
                    && is_object($viewReadService)) {
                $stage = ($subpage === 'abj404_captured') ? 'table_captured' : 'table_redirects';
                ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, $stage);
                $tableOptions = $abj404logic->getTableOptions($subpage);
                if (!$viewReadService->viewTableSnapshotAvailable($subpage, $tableOptions)) {
                    self::markAjaxResponseSent();
                    self::getAndClearAjaxBufferedOutput();
                    self::sendJsonResponseAndExit(array(
                        'cachePending' => true,
                        'cacheMode' => $cacheMode,
                        'subpage' => $subpage,
                        'message' => __('Preparing table data in the background.', '404-solution'),
                    ), 200);
                    return;
                }
            }

            $data = self::fetchTableDataForSubpage($subpage, $view, $viewReadService, $context);

            $tableSignature = '';
            if (is_object($view) && method_exists($view, 'getCurrentTableDataSignature')) {
                $tableSignature = (string)$view->getCurrentTableDataSignature($subpage);
            }
            $data['tableSignature'] = $tableSignature;
            if ($detectOnly) {
                $signaturesMatch = false;
                if ($currentSignature !== '' && $tableSignature !== '') {
                    if (function_exists('hash_equals')) {
                        $signaturesMatch = hash_equals($currentSignature, $tableSignature);
                    } else {
                        $signaturesMatch = ($currentSignature === $tableSignature);
                    }
                }
                $data['hasUpdate'] = (
                    $currentSignature !== '' &&
                    $tableSignature !== '' &&
                    !$signaturesMatch
                );
            }

            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'paginationLinksTop');
            $data['paginationLinksTop'] = $view->getPaginationLinks($subpage);
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, 'paginationLinksBottom');
            $data['paginationLinksBottom'] = $view->getPaginationLinks($subpage, false);

            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            self::sendJsonResponseAndExit($data, 200);
            return;

        } catch (Throwable $e) {
            // allow-silent-catch: handlePaginationLinksException embeds/logs the throwable in the AJAX response path.
            self::handlePaginationLinksException(
                $e, $viewBuildOrchestrator, $subpage, $cacheMode, $isPluginAdmin, $context
            );
            return;
        }
    }

    /** @return void */
    function warmTableCache() {
        $functions = self::getRequestReader();
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
        $context = self::startAjaxDebugContext($context);

        try {
            if (!wp_verify_nonce($nonce, 'abj404_updatePaginationLink')) {
                self::safeLogAjaxFailure('AJAX invalid nonce in ajaxWarmTableCache.', $context);
                self::markAjaxResponseSent();
                self::sendJsonResponseAndExit(self::buildAjaxErrorResponse('Invalid security token', null, false), 403);
                return;
            }

            $isPluginAdmin = $abj404logic->userIsPluginAdmin();
            if (!$isPluginAdmin) {
                self::safeLogAjaxFailure('AJAX unauthorized in ajaxWarmTableCache.', $context);
                self::markAjaxResponseSent();
                self::sendJsonResponseAndExit(self::buildAjaxErrorResponse('Unauthorized', null, false), 403);
                return;
            }

            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('warm_table_cache', 1500, 60)) {
                self::safeLogAjaxFailure('AJAX rate limit in ajaxWarmTableCache.', $context);
                self::markAjaxResponseSent();
                self::sendJsonResponseAndExit(self::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false), 429);
                return;
            }

            if ($rowsPerPage > 0) {
                $abj404logic->updatePerPageOption($rowsPerPage);
            }

            if ($subpage !== 'abj404_redirects' && $subpage !== 'abj404_captured') {
                self::markAjaxResponseSent();
                self::getAndClearAjaxBufferedOutput();
                self::sendJsonResponseAndExit(array(
                    'status' => 'ready',
                    'ready' => true,
                    'uncached' => true,
                    'stage' => 'rows',
                    'stageNumber' => 1,
                    'queryLabel' => 'getRedirectsForView',
                ), 200);
                return;
            }

            // Same view-build gate as the fetch endpoint: warming the snapshot
            // cache calls getRedirectsForView, which will inline-build the
            // staged view_done if missing.  When view_done is not serveable,
            // the JS poller must advance the build via ajaxAdvanceViewBuild
            // before the snapshot warm can start.  Returning ready=false here
            // keeps the placeholder hydration loop running until then.
            if (!$viewBuildOrchestrator->viewDoneIsServeable()) {
                $progress = $viewBuildOrchestrator->getViewBuildProgress();
                self::markAjaxResponseSent();
                self::getAndClearAjaxBufferedOutput();
                self::sendJsonResponseAndExit(array(
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

            $tableOptions = $abj404logic->getTableOptions($subpage);
            $stage = 'table_cache_rows';
            if ($viewReadService->viewRowsSnapshotAvailable($subpage, $tableOptions)) {
                $stage = 'table_cache_count';
            }
            ABJ_404_Solution_AjaxStageDiagnostics::setStage($context, $stage);
            $warmup = $viewReadService->warmViewTableSnapshotStage($subpage, $tableOptions);

            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            self::sendJsonResponseAndExit($warmup, 200);
            return;
        } catch (Throwable $e) {
            // Race recovery: same defense as getPaginationLinks. The warm
            // path uses a different response shape because the JS placeholder
            // hydration consumes ready=false directly.
            $pending = ABJ_404_Solution_ViewBuildPendingResponseBuilder::find($e);
            if ($pending !== null) {
                self::markAjaxResponseSent();
                self::getAndClearAjaxBufferedOutput();
                self::sendJsonResponseAndExit(
                    ABJ_404_Solution_ViewBuildPendingResponseBuilder::warmResponse($viewBuildOrchestrator, $pending),
                    200
                );
                return;
            }
            if (!$isPluginAdmin) {
                $abj404logic = abj_service('plugin_logic');
                if (is_object($abj404logic) && method_exists($abj404logic, 'userIsPluginAdmin')) {
                    try {
                        $isPluginAdmin = (bool)$abj404logic->userIsPluginAdmin();
                    } catch (Throwable $ignored) { // allow-silent-catch: admin-status detection; PluginLogic may be the broken component, default to non-admin (hide details)
                        $isPluginAdmin = false;
                    }
                }
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
            $viewQueryDiagnostics = self::extractViewQueryDiagnostics($e);
            if ($viewQueryDiagnostics !== null) {
                $details['view_query_diagnostics'] = $viewQueryDiagnostics;
            }
            self::safeLogAjaxFailure('AJAX exception in ajaxWarmTableCache.', $details, $e);
            $capturedOutput = self::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            self::markAjaxResponseSent();
            self::sendJsonResponseAndExit(
                self::buildAjaxErrorResponse('Server error while preparing table data.', $details, $isPluginAdmin),
                500
            );
            return;
        }
    }

    /** @return void */
    function refreshStatsDashboard() {
        $functions = self::getRequestReader();
        /** @var ABJ_404_Solution_StatsRepositoryInterface $statsRepository */
        $statsRepository = abj_service('stats_repository');
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
        $context = self::startAjaxDebugContext($context);

        try {
            if (!wp_verify_nonce($nonce, 'abj404_refreshStatsDashboard')) {
                self::safeLogAjaxFailure('AJAX invalid nonce in ajaxRefreshStatsDashboard.', $context);
                self::markAjaxResponseSent();
                $payload = self::buildAjaxErrorResponse('Invalid security token', null, false);
                self::sendJsonResponseAndExit($payload, 403);
                return;
            }

            $isPluginAdmin = $abj404logic->userIsPluginAdmin();
            if (!$isPluginAdmin) {
                self::safeLogAjaxFailure('AJAX unauthorized in ajaxRefreshStatsDashboard.', $context);
                self::markAjaxResponseSent();
                $payload = self::buildAjaxErrorResponse('Unauthorized', null, false);
                self::sendJsonResponseAndExit($payload, 403);
                return;
            }

            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('refresh_stats_dashboard', 30, 60)) {
                self::safeLogAjaxFailure('AJAX rate limit in ajaxRefreshStatsDashboard.', $context);
                self::markAjaxResponseSent();
                $payload = self::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
                self::sendJsonResponseAndExit($payload, 429);
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

            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            self::sendJsonResponseAndExit($response, 200);
            return;

        } catch (Throwable $e) {
            if (!$isPluginAdmin) {
                $abj404logic = abj_service('plugin_logic');
                if (is_object($abj404logic) && method_exists($abj404logic, 'userIsPluginAdmin')) {
                    try {
                        $isPluginAdmin = (bool)$abj404logic->userIsPluginAdmin();
                    } catch (Throwable $ignored) { // allow-silent-catch: admin-status detection; PluginLogic may be the broken component, default to non-admin (hide details)
                        $isPluginAdmin = false;
                    }
                }
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
            self::safeLogAjaxFailure('AJAX exception in ajaxRefreshStatsDashboard.', $details, $e);
            $capturedOutput = self::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            self::markAjaxResponseSent();
            $payload = self::buildAjaxErrorResponse(
                'Server error while refreshing stats.',
                $details,
                $isPluginAdmin
            );
            self::sendJsonResponseAndExit($payload, 500);
            return;
        }
    }

    /**
     * Returns the data needed to render the redirects-page health bar:
     * the high-impact captured-URL count and the redirect status counts
     * (so the JS can compute "active = all - trash" and build the View link).
     *
     * Decoupled from ajaxUpdatePaginationLinks because getHighImpactCapturedCount()
     * can run for tens of seconds on a cold cache against multi-million-row logs;
     * letting it block the table response leaves the page stuck on "Loading…".
     *
     * @return void
     */
    function refreshHealthBar() {
        $functions = self::getRequestReader();
        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
        $viewReadService = abj_service('view_read_service');
        /** @var ABJ_404_Solution_LogsRepositoryInterface $logsRepository */
        $logsRepository = abj_service('logs_repository');
        $abj404logic = abj_service('plugin_logic');

        $nonce = $functions->getPostOrGetSanitize('nonce');
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
        $context = self::startAjaxDebugContext($context);

        try {
            if (!wp_verify_nonce($nonce, 'abj404_refreshHealthBar')) {
                self::safeLogAjaxFailure('AJAX invalid nonce in ajaxRefreshHealthBar.', $context);
                self::markAjaxResponseSent();
                $payload = self::buildAjaxErrorResponse('Invalid security token', null, false);
                self::sendJsonResponseAndExit($payload, 403);
                return;
            }

            $isPluginAdmin = $abj404logic->userIsPluginAdmin();
            if (!$isPluginAdmin) {
                self::safeLogAjaxFailure('AJAX unauthorized in ajaxRefreshHealthBar.', $context);
                self::markAjaxResponseSent();
                $payload = self::buildAjaxErrorResponse('Unauthorized', null, false);
                self::sendJsonResponseAndExit($payload, 403);
                return;
            }

            // Match the pagination AJAX rate limit ceiling — admin workflows
            // can re-trigger this on filter typing and tab switches.
            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('refresh_health_bar', 1500, 60)) {
                self::safeLogAjaxFailure('AJAX rate limit in ajaxRefreshHealthBar.', $context);
                self::markAjaxResponseSent();
                $payload = self::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false);
                self::sendJsonResponseAndExit($payload, 429);
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

            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            self::sendJsonResponseAndExit($response, 200);
            return;

        } catch (Throwable $e) {
            if (!$isPluginAdmin) {
                $abj404logic = abj_service('plugin_logic');
                if (is_object($abj404logic) && method_exists($abj404logic, 'userIsPluginAdmin')) {
                    try {
                        $isPluginAdmin = (bool)$abj404logic->userIsPluginAdmin();
                    } catch (Throwable $ignored) { // allow-silent-catch: admin-status detection; PluginLogic may be the broken component, default to non-admin (hide details)
                        $isPluginAdmin = false;
                    }
                }
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
            self::safeLogAjaxFailure('AJAX exception in ajaxRefreshHealthBar.', $details, $e);
            $capturedOutput = self::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            self::markAjaxResponseSent();
            $payload = self::buildAjaxErrorResponse(
                'Server error while refreshing health bar.',
                $details,
                $isPluginAdmin
            );
            self::sendJsonResponseAndExit($payload, 500);
            return;
        }
    }

    /**
     * Look up the last in-flight stage stamped by `setStage()` for a given
     * client-supplied requestId.  Used by the JS error handler when
     * `textStatus === 'timeout'` so the admin notice can name which phase the
     * server was in when the client gave up — diagnostics for pure client
     * timeouts where no response, header, or body ever arrives.
     *
     * Returns 200 with `{stage: '...'}` on success, `{stage: ''}` if the
     * transient has expired or the requestId is unknown.  Reads (but does
     * not delete) the transient — letting it expire naturally avoids a race
     * if the original AJAX is still running.
     *
     * @return void
     */
    function fetchInflightStage() {
        $functions = self::getRequestReader();
        $abj404logic = abj_service('plugin_logic');

        $nonce = $functions->getPostOrGetSanitize('nonce');
        $requestId = self::readClientRequestId();

        try {
            if (!wp_verify_nonce($nonce, 'abj404_fetchInflightStage')) {
                self::sendJsonResponseAndExit(
                    self::buildAjaxErrorResponse('Invalid security token', null, false),
                    403
                );
                return;
            }
            if (!$abj404logic->userIsPluginAdmin()) {
                self::sendJsonResponseAndExit(
                    self::buildAjaxErrorResponse('Unauthorized', null, false),
                    403
                );
                return;
            }
            // Tight rate limit — this endpoint only fires from the JS timeout
            // handler.  A real admin sees ~1 hit per stuck request.
            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('fetch_inflight_stage', 120, 60)) {
                self::sendJsonResponseAndExit(
                    self::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false),
                    429
                );
                return;
            }
            if ($requestId === '') {
                self::sendJsonResponseAndExit(array('stage' => ''), 200);
                return;
            }

            $stage = '';
            $queryLabel = '';
            $whatsHappening = '';
            $events = array();
            if (function_exists('get_transient')) {
                $value = get_transient('abj404_inflight_' . $requestId);
                if (is_array($value)) {
                    $stage = isset($value['stage']) && is_string($value['stage']) ? $value['stage'] : '';
                    $queryLabel = isset($value['query_label']) && is_string($value['query_label']) ? $value['query_label'] : '';
                    $whatsHappening = isset($value['what_happening']) && is_string($value['what_happening']) ? $value['what_happening'] : '';
                    $rawEvents = is_array($value['events'] ?? null) ? $value['events'] : array();
                    foreach ($rawEvents as $rawEvent) {
                        if (!is_array($rawEvent)) {
                            continue;
                        }
                        $eventStage = isset($rawEvent['stage']) && is_string($rawEvent['stage']) ? $rawEvent['stage'] : '';
                        if ($eventStage === '') {
                            continue;
                        }
                        $events[] = array(
                            'stage' => $eventStage,
                            'queryLabel' => isset($rawEvent['query_label']) && is_string($rawEvent['query_label']) ? $rawEvent['query_label'] : '',
                            'whatsHappening' => isset($rawEvent['what_happening']) && is_string($rawEvent['what_happening']) ? $rawEvent['what_happening'] : '',
                            'timeMs' => isset($rawEvent['time_ms']) && is_scalar($rawEvent['time_ms']) ? intval($rawEvent['time_ms']) : 0,
                        );
                    }
                } else if (is_string($value)) {
                    $stage = $value;
                    $diagnostics = ABJ_404_Solution_AjaxStageDiagnostics::getStageDiagnostics($stage);
                    $queryLabel = $diagnostics['query_label'];
                    $whatsHappening = $diagnostics['what_happening'];
                }
            }

            self::sendJsonResponseAndExit(array(
                'stage' => $stage,
                'queryLabel' => $queryLabel,
                'whatsHappening' => $whatsHappening,
                'events' => $events,
            ), 200);
            return;

        } catch (Throwable $e) { // allow-silent-catch: diagnostics endpoint is best-effort; surfacing a lookup failure is worse than returning empty stage
            self::sendJsonResponseAndExit(array('stage' => ''), 200);
            return;
        }
    }

    /**
     * Bounded build-advance endpoint paired with the fetch-only path on
     * `getPaginationLinks` / `warmTableCache`. Each call runs at most one
     * resumable tick of the staged view_done build (10s/stage budget; yields
     * mid-stage on S2/S4/S5) and returns the current progress.  The JS poller
     * fires this every ~1s after a fetch returns `viewBuildPending: true`.
     *
     * Idempotent: concurrent calls fail to acquire the build lock and just
     * return the current progress.  Errors are returned as a 500 with the
     * standard error envelope so the JS poller can stop and surface a notice
     * instead of spinning forever.
     *
     * Reuses the `abj404_fetchInflightStage` nonce (already bound on every
     * admin page that can hit this endpoint) so no additional nonce plumbing
     * is needed.
     *
     * @return void
     */
    function advanceViewBuild() {
        $functions = self::getRequestReader();
        /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator */
        $viewBuildOrchestrator = abj_service('view_build_orchestrator');
        $abj404logic = abj_service('plugin_logic');

        $nonce = $functions->getPostOrGetSanitize('nonce');
        $page = $functions->getPostOrGetSanitize('page', '');
        $subpage = $functions->getPostOrGetSanitize('subpage', '');
        $requestId = self::readClientRequestId();
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
        $context = self::startAjaxDebugContext($context);

        try {
            if (!wp_verify_nonce($nonce, 'abj404_fetchInflightStage')) {
                self::safeLogAjaxFailure('AJAX invalid nonce in ajaxAdvanceViewBuild.', $context);
                self::markAjaxResponseSent();
                self::sendJsonResponseAndExit(self::buildAjaxErrorResponse('Invalid security token', null, false), 403);
                return;
            }

            $isPluginAdmin = $abj404logic->userIsPluginAdmin();
            if (!$isPluginAdmin) {
                self::safeLogAjaxFailure('AJAX unauthorized in ajaxAdvanceViewBuild.', $context);
                self::markAjaxResponseSent();
                self::sendJsonResponseAndExit(self::buildAjaxErrorResponse('Unauthorized', null, false), 403);
                return;
            }

            // The poller fires this once per second per admin tab while a
            // build is in progress.  A single tab might burn ~120 calls in a
            // long resumable build; keep the ceiling well above that.
            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('advance_view_build', 600, 60)) {
                self::safeLogAjaxFailure('AJAX rate limit in ajaxAdvanceViewBuild.', $context);
                self::markAjaxResponseSent();
                self::sendJsonResponseAndExit(self::buildAjaxErrorResponse('Rate limit exceeded. Please try again later.', null, false), 429);
                return;
            }

            if (!is_object($viewBuildOrchestrator) || !method_exists($viewBuildOrchestrator, 'advanceViewBuildOnce')) {
                self::markAjaxResponseSent();
                self::getAndClearAjaxBufferedOutput();
                self::sendJsonResponseAndExit(array(
                    'status' => 'unsupported',
                    'progress' => array('status' => 'pending', 'stage' => 0, 'of' => 11,
                        'build_started' => 0, 'progress_text' => 'unsupported'),
                ), 200);
                return;
            }

            // The browser only sends forceViewRebuild=1 on the first advance
            // call of an ?abj404_force_view_rebuild=1 page-load. Pre-calling
            // forceRestartViewBuild() here (rather than in the fetch path)
            // keeps the rebuild owned by a single requestId so every staged
            // sub-stage shows up in the debug log. Non-blocking acquire (0s):
            // a sibling cron / tab holding the runner lock must not stall
            // this request; advanceViewBuildOnce(forceRebuild=true) below
            // waits up to 10s and runs equivalent drop/clear semantics
            // inside its own locked region. Migrated in Phase 3a step 4
            // (queue task t_260516_131200_429) from a direct
            // invalidateViewSnapshotCache() / invalidateViewDone() pre-call:
            // the runner-owned primitive (DataAccessTrait_ViewBuildForceRestart,
            // Phase 3a step 2 / c554) preserves the existing view_done
            // snapshot for parallel readers until the new S11 RENAME publishes
            // a fresh one, which is the intended force-rebuild contract.
            if ($forceViewRebuild && method_exists($viewBuildOrchestrator, 'forceRestartViewBuild')) {
                $viewBuildOrchestrator->forceRestartViewBuild(0);
            }

            self::tryClaimForegroundViewBuildLease($viewBuildOrchestrator);
            // Pass forceRebuild down so advanceViewBuildOnce takes the lock
            // with a 30s timeout (waiting for any in-flight cron/sibling
            // build to finish), re-invalidates inside the locked region,
            // and runs the build under THIS request's AJAX context. That is
            // what makes every staged_build_s* sub-stage event reach the
            // browser's "AJAX Load Times / Debug Info" panel.
            $progress = $viewBuildOrchestrator->advanceViewBuildOnce($forceViewRebuild);
            $statusValue = is_array($progress) && isset($progress['status']) && is_string($progress['status'])
                ? $progress['status'] : 'pending';

            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            self::sendJsonResponseAndExit(array(
                'status' => $statusValue,
                'progress' => is_array($progress) ? $progress : array(),
            ), 200);
            return;

        } catch (Throwable $e) {
            if (!$isPluginAdmin) {
                $abj404logic = abj_service('plugin_logic');
                if (is_object($abj404logic) && method_exists($abj404logic, 'userIsPluginAdmin')) {
                    try {
                        $isPluginAdmin = (bool)$abj404logic->userIsPluginAdmin();
                    } catch (Throwable $ignored) { // allow-silent-catch: admin-status detection; PluginLogic may be the broken component, default to non-admin (hide details)
                        $isPluginAdmin = false;
                    }
                }
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
            self::safeLogAjaxFailure('AJAX exception in ajaxAdvanceViewBuild.', $details, $e);
            $capturedOutput = self::getAndClearAjaxBufferedOutput();
            if ($capturedOutput !== '') {
                $details['buffered_output'] = substr($capturedOutput, 0, 8000);
            }

            self::markAjaxResponseSent();
            self::sendJsonResponseAndExit(
                self::buildAjaxErrorResponse('Server error while advancing the view build.', $details, $isPluginAdmin),
                500
            );
            return;
        }
    }

}
