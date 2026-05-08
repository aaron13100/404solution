<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Funtcions supporting Ajax stuff.  */

class ABJ_404_Solution_ViewUpdater {

    use ABJ_404_Solution_AjaxFailureLoggingTrait;

    private const INFLIGHT_STAGE_EVENT_LIMIT = 5000;

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
        // wp_ajax_nopriv_ is for normal users
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
     * Update the in-flight stage marker for the current AJAX request.  Sets
     * `$context['stage']` and — when a client requestId is present — also
     * writes a short-lived transient so a follow-up `ajaxFetchInflightStage`
     * call can read which phase the server was in when a client-side timeout
     * fired (no response, no body, no headers reach the browser).
     *
     * Transient TTL is intentionally short (60s) — diagnostics that arrive
     * after a minute aren't useful for the user-visible error notice anyway.
     *
     * @param array<string, mixed> $context  Passed by reference; mutated in place.
     * @param string $stage  Stage label (e.g. 'table_captured', 'paginationLinksTop').
     * @return void
     */
    private static function setStage(&$context, $stage) {
        if (!is_array($context)) {
            $context = array();
        }
        $diagnostics = self::getStageDiagnostics($stage);
        $context['stage'] = $stage;
        $context['query_label'] = $diagnostics['query_label'];
        $context['what_happening'] = $diagnostics['what_happening'];
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $GLOBALS['abj404_ajax_context']['stage'] = $stage;
            $GLOBALS['abj404_ajax_context']['query_label'] = $diagnostics['query_label'];
            $GLOBALS['abj404_ajax_context']['what_happening'] = $diagnostics['what_happening'];
        }

        $requestId = isset($context['requestId']) && is_string($context['requestId']) ? $context['requestId'] : '';
        if ($requestId === '') {
            return;
        }
        if (!function_exists('set_transient')) {
            return;
        }
        $event = array(
            'stage' => (string)$stage,
            'query_label' => $diagnostics['query_label'],
            'what_happening' => $diagnostics['what_happening'],
            'time_ms' => (int)round(microtime(true) * 1000),
        );
        $events = array();
        if (function_exists('get_transient')) {
            $existing = @get_transient('abj404_inflight_' . $requestId);
            if (is_array($existing) && is_array($existing['events'] ?? null)) {
                $events = $existing['events'];
            }
        }
        $lastEvent = !empty($events) ? $events[count($events) - 1] : null;
        $lastStage = is_array($lastEvent) && isset($lastEvent['stage']) && is_string($lastEvent['stage'])
            ? $lastEvent['stage'] : '';
        if ($lastStage !== (string)$stage) {
            $events[] = $event;
            if (count($events) > self::INFLIGHT_STAGE_EVENT_LIMIT) {
                $events = array_slice($events, -self::INFLIGHT_STAGE_EVENT_LIMIT);
            }
        }
        // Diagnostics — best effort. Never let a transient write failure
        // mask the real query error we're trying to diagnose. The
        // @-suppression converts any wpdb/network warning into a no-op.
        @set_transient('abj404_inflight_' . $requestId, array(
            'stage' => (string)$stage,
            'query_label' => $diagnostics['query_label'],
            'what_happening' => $diagnostics['what_happening'],
            'events' => $events,
        ), 60);
    }

    /**
     * @param string $stage
     * @return array{query_label: string, what_happening: string}
     */
    private static function getStageDiagnostics($stage) {
        $map = array(
            'table_redirects' => array(
                'query_label' => 'getAdminRedirectsPageTable() -> getRedirectsForView() / getRedirectsForView.sql',
                'what_happening' => 'Loading Redirects table rows',
            ),
            'redirect_status_counts' => array(
                'query_label' => 'getRedirectStatusCounts()',
                'what_happening' => 'Counting Redirects status tabs',
            ),
            'table_captured' => array(
                'query_label' => 'getCapturedURLSPageTable() -> getRedirectsForView() / getRedirectsForView.sql',
                'what_happening' => 'Loading Captured 404 URLs table rows',
            ),
            'captured_status_counts' => array(
                'query_label' => 'getCapturedStatusCounts()',
                'what_happening' => 'Counting Captured 404 URLs status tabs',
            ),
            'table_logs' => array(
                'query_label' => 'getAdminLogsPageTable() -> getLogRecords()',
                'what_happening' => 'Loading Logs table rows',
            ),
            'paginationLinksTop' => array(
                'query_label' => 'getPaginationLinks(top) -> getRedirectsForViewCount() / getRedirectsForView.sql',
                'what_happening' => 'Rendering top pagination links',
            ),
            'paginationLinksBottom' => array(
                'query_label' => 'getPaginationLinks(bottom) -> getRedirectsForViewCount() / getRedirectsForView.sql',
                'what_happening' => 'Rendering bottom pagination links',
            ),
            'table_cache_rows' => array(
                'query_label' => 'getRedirectsForView',
                'what_happening' => 'Warming table row snapshot',
            ),
            'table_cache_count' => array(
                'query_label' => 'getRedirectsForViewCount',
                'what_happening' => 'Warming table count snapshot',
            ),
            'high_impact_count' => array(
                'query_label' => 'getHighImpactCapturedCount()',
                'what_happening' => 'Counting high-impact captured URLs',
            ),
            // Sub-stages of the staged view-build pipeline (see
            // DataAccessTrait_ViewQueriesStaged::runStagedBuildOnce). These
            // are emitted by markBuildStage() during cold-cache builds so the
            // .abj404-refresh-status element can show step-by-step progress
            // instead of a single frozen "stage 1" label for the whole build.
            'staged_build_s1_create' => array(
                'query_label' => 'CREATE TABLE wp_abj404_view_build',
                'what_happening' => 'Creating build buffer (1/11)',
            ),
            'staged_build_s2_insert' => array(
                'query_label' => 'INSERT INTO wp_abj404_view_build SELECT FROM wp_abj404_redirects',
                'what_happening' => 'Bulk-loading redirects into build buffer (2/11)',
            ),
            'staged_build_s3_index_fd' => array(
                'query_label' => 'ALTER TABLE wp_abj404_view_build ADD INDEX idx_fd_int',
                'what_happening' => 'Adding pre-join indexes (3/11)',
            ),
            'staged_build_s4_update_posts' => array(
                'query_label' => 'UPDATE wp_abj404_view_build LEFT JOIN wp_posts',
                'what_happening' => 'Filling published-status from wp_posts (4/11)',
            ),
            'staged_build_s5_update_terms' => array(
                'query_label' => 'UPDATE wp_abj404_view_build LEFT JOIN wp_terms',
                'what_happening' => 'Filling published-status from wp_terms (5/11)',
            ),
            'staged_build_s6_update_home' => array(
                'query_label' => 'UPDATE wp_abj404_view_build (HOME)',
                'what_happening' => 'Filling HOME-typed redirects (6/11)',
            ),
            'staged_build_s7_update_external' => array(
                'query_label' => 'UPDATE wp_abj404_view_build (EXTERNAL)',
                'what_happening' => 'Filling EXTERNAL-typed redirects (7/11)',
            ),
            'staged_build_s8_update_special' => array(
                'query_label' => 'UPDATE wp_abj404_view_build (404-displayed)',
                'what_happening' => 'Filling 404-displayed redirects (8/11)',
            ),
            'staged_build_s9_update_hits' => array(
                'query_label' => 'UPDATE wp_abj404_view_build LEFT JOIN wp_abj404_logs_hits',
                'what_happening' => 'Filling hit counts (9/11)',
            ),
            'staged_build_s10_index_sort' => array(
                'query_label' => 'ALTER TABLE wp_abj404_view_build ADD INDEX (sort indexes)',
                'what_happening' => 'Adding read-side sort indexes (10/11)',
            ),
            'staged_build_s11_swap' => array(
                'query_label' => 'RENAME TABLE wp_abj404_view_build TO wp_abj404_view_done',
                'what_happening' => 'Atomic table swap (11/11)',
            ),
        );
        if (array_key_exists($stage, $map)) {
            return $map[$stage];
        }
        // Sub-stage with a free-form ":detail" suffix (e.g. the batched insert
        // emits 'staged_build_s2_insert:batch 4/12'). Strip the detail to find
        // the base label, then append the detail to what_happening so the GUI
        // shows "Bulk-loading redirects into build buffer (2/11) — batch 4/12".
        $colonPos = is_string($stage) ? strpos((string)$stage, ':') : false;
        if ($colonPos !== false) {
            $base = substr((string)$stage, 0, $colonPos);
            $detail = trim(substr((string)$stage, $colonPos + 1));
            if (array_key_exists($base, $map)) {
                $entry = $map[$base];
                if ($detail !== '') {
                    $entry['what_happening'] = $entry['what_happening'] . ' — ' . $detail;
                }
                return $entry;
            }
        }
        return array(
            'query_label' => (string)$stage,
            'what_happening' => 'Running AJAX stage ' . (string)$stage,
        );
    }

    /**
     * Public version of setStage() that reads the AJAX requestId from the
     * global context rather than requiring a `&$context` reference.  Used by
     * code paths (e.g. the staged view-build pipeline) that run beneath
     * DataAccess and don't have $context threaded through.
     *
     * Best-effort: if no AJAX context exists (background cron, CLI), this is
     * a no-op — no transient is written and no global is mutated.
     *
     * @param string $stage  Stage label.  May be a known key in
     *                       getStageDiagnostics(), or `<key>:<detail>` where
     *                       detail is appended to what_happening for mid-stage
     *                       progress messages (e.g. 'staged_build_s2_insert:batch 4/12').
     * @return void
     */
    public static function markInflightStage($stage) {
        if (!isset($GLOBALS['abj404_ajax_context']) || !is_array($GLOBALS['abj404_ajax_context'])) {
            return;
        }
        $rawContext = $GLOBALS['abj404_ajax_context'];
        $context = array();
        foreach ($rawContext as $key => $value) {
            if (is_string($key)) {
                $context[$key] = $value;
            }
        }
        self::setStage($context, (string)$stage);
        $GLOBALS['abj404_ajax_context'] = $context;
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
    
    /** @return void */
    function getPaginationLinks() {
        $abj404dao = abj_service('data_access');
        $abj404logic = abj_service('plugin_logic');
        global $abj404view;
        
        $rowsPerPage = absint($abj404dao->getPostOrGetSanitize('rowsPerPage'));
        $subpage = $abj404dao->getPostOrGetSanitize('subpage');
        $nonce = $abj404dao->getPostOrGetSanitize('nonce');
        $page = $abj404dao->getPostOrGetSanitize('page', '');
        $filterText = $abj404dao->getPostOrGetSanitize('filterText', '');
        $filter = $abj404dao->getPostOrGetSanitize('filter', '');
        $detectOnly = ((string)$abj404dao->getPostOrGetSanitize('detectOnly', '0') === '1');
        $cacheModeRaw = (string)$abj404dao->getPostOrGetSanitize('cacheMode', 'normal');
        $cacheMode = in_array($cacheModeRaw, array('normal', 'cache_or_pending', 'refresh_cache'), true)
            ? $cacheModeRaw : 'normal';
        $currentSignature = strtolower(trim((string)$abj404dao->getPostOrGetSanitize('currentSignature', '')));
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
                    && is_object($abj404dao)
                    && method_exists($abj404dao, 'viewDoneIsServeable')
                    && !$abj404dao->viewDoneIsServeable()) {
                $stage = ($subpage === 'abj404_captured') ? 'table_captured' : 'table_redirects';
                self::setStage($context, $stage);
                $progress = method_exists($abj404dao, 'getViewBuildProgress')
                    ? $abj404dao->getViewBuildProgress()
                    : array('status' => 'pending', 'stage' => 0, 'of' => 11,
                        'build_started' => 0, 'progress_text' => 'not yet started');
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
                    && is_object($abj404dao)
                    && method_exists($abj404dao, 'viewTableSnapshotAvailable')) {
                $stage = ($subpage === 'abj404_captured') ? 'table_captured' : 'table_redirects';
                self::setStage($context, $stage);
                $tableOptions = $abj404logic->getTableOptions($subpage);
                if (!$abj404dao->viewTableSnapshotAvailable($subpage, $tableOptions)) {
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

            $data = array();
            if ($subpage == 'abj404_redirects') {
                self::setStage($context, 'table_redirects');
                $data['table'] = $view->getAdminRedirectsPageTable($subpage);

                // Include tab counts so the page shell can render instantly with
                // placeholders and fill them in. The slower health-bar query
                // (getHighImpactCapturedCount, see refreshHealthBar()) is fetched
                // in a separate AJAX call so it never blocks first paint of the table.
                self::setStage($context, 'redirect_status_counts');
                $statusCounts = $abj404dao->getRedirectStatusCounts();
                // Tab counts keyed by filter value for JS tab updates.
                $data['tabCounts'] = array(
                    '0' => $statusCounts['all'] ?? 0,
                    (string)ABJ404_STATUS_MANUAL => $statusCounts['manual'] ?? 0,
                    (string)ABJ404_STATUS_AUTO => $statusCounts['auto'] ?? 0,
                    (string)ABJ404_TRASH_FILTER => $statusCounts['trash'] ?? 0,
                );

            } else if ($subpage == 'abj404_captured') {
                self::setStage($context, 'table_captured');
                $data['table'] = $view->getCapturedURLSPageTable($subpage);

                // Include tab counts so the page shell can render instantly.
                self::setStage($context, 'captured_status_counts');
                $statusCounts = $abj404dao->getCapturedStatusCounts();
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
                self::setStage($context, 'table_logs');
                $data['table'] = $view->getAdminLogsPageTable($subpage);

            } else {
                $data['table'] = 'Error: Unexpected subpage requested.';
            }

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

            self::setStage($context, 'paginationLinksTop');
            $data['paginationLinksTop'] = $view->getPaginationLinks($subpage);
            self::setStage($context, 'paginationLinksBottom');
            $data['paginationLinksBottom'] = $view->getPaginationLinks($subpage, false);

            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            self::sendJsonResponseAndExit($data, 200);
            return;

        } catch (Throwable $e) {
            // Race recovery: viewDoneIsServeable() can race with invalidateViewDone();
            // surface the pending shape the JS poller already handles, never a 500.
            $pending = ABJ_404_Solution_ViewBuildPendingResponseBuilder::find($e);
            if ($pending !== null) {
                self::markAjaxResponseSent();
                self::getAndClearAjaxBufferedOutput();
                self::sendJsonResponseAndExit(
                    ABJ_404_Solution_ViewBuildPendingResponseBuilder::fetchResponse($abj404dao, $subpage, $cacheMode, $pending),
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
                    } catch (Throwable $ignored) {
                        $isPluginAdmin = false;
                    }
                }
                if (!$isPluginAdmin) {
                    // Best-effort fallback: treat WordPress administrators as plugin admins for debugging
                    // if PluginLogic is broken. Avoid current_user_can() to keep delegated admin semantics
                    // centralized in PluginLogic.
                    if (function_exists('wp_get_current_user')) {
                        $user = wp_get_current_user();
                        if (is_object($user) && property_exists($user, 'roles') && is_array($user->roles)) {
                            $isPluginAdmin = in_array('administrator', $user->roles, true);
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
            return;
        }
    }

    /** @return void */
    function warmTableCache() {
        $abj404dao = abj_service('data_access');
        $abj404logic = abj_service('plugin_logic');

        $rowsPerPage = absint($abj404dao->getPostOrGetSanitize('rowsPerPage'));
        $subpage = $abj404dao->getPostOrGetSanitize('subpage');
        $nonce = $abj404dao->getPostOrGetSanitize('nonce');
        $page = $abj404dao->getPostOrGetSanitize('page', '');
        $filterText = $abj404dao->getPostOrGetSanitize('filterText', '');
        $filter = $abj404dao->getPostOrGetSanitize('filter', '');

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
            if (is_object($abj404dao) && method_exists($abj404dao, 'viewDoneIsServeable')
                    && !$abj404dao->viewDoneIsServeable()) {
                $progress = method_exists($abj404dao, 'getViewBuildProgress')
                    ? $abj404dao->getViewBuildProgress()
                    : array('status' => 'pending', 'stage' => 0, 'of' => 11,
                        'build_started' => 0, 'progress_text' => 'not yet started');
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
            if (is_object($abj404dao) && method_exists($abj404dao, 'viewRowsSnapshotAvailable')
                    && $abj404dao->viewRowsSnapshotAvailable($subpage, $tableOptions)) {
                $stage = 'table_cache_count';
            }
            self::setStage($context, $stage);
            $warmup = $abj404dao->warmViewTableSnapshotStage($subpage, $tableOptions);

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
                    ABJ_404_Solution_ViewBuildPendingResponseBuilder::warmResponse($abj404dao, $pending),
                    200
                );
                return;
            }
            if (!$isPluginAdmin) {
                $abj404logic = abj_service('plugin_logic');
                if (is_object($abj404logic) && method_exists($abj404logic, 'userIsPluginAdmin')) {
                    try {
                        $isPluginAdmin = (bool)$abj404logic->userIsPluginAdmin();
                    } catch (Throwable $ignored) {
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
        $abj404dao = abj_service('data_access');
        $abj404logic = abj_service('plugin_logic');

        $nonce = $abj404dao->getPostOrGetSanitize('nonce');
        $page = $abj404dao->getPostOrGetSanitize('page', '');
        $subpage = $abj404dao->getPostOrGetSanitize('subpage', '');
        $currentHash = $abj404dao->getPostOrGetSanitize('currentHash', '');

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

            $snapshot = $abj404dao->refreshStatsDashboardSnapshot(false);
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
                    } catch (Throwable $ignored) {
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
        $abj404dao = abj_service('data_access');
        $abj404logic = abj_service('plugin_logic');

        $nonce = $abj404dao->getPostOrGetSanitize('nonce');
        $page = $abj404dao->getPostOrGetSanitize('page', '');
        $subpage = $abj404dao->getPostOrGetSanitize('subpage', '');

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

            self::setStage($context, 'redirect_status_counts');
            $statusCounts = $abj404dao->getRedirectStatusCounts();
            // Provide the captured filter constant so JS can build the "View" link.
            $statusCounts['_capturedFilter'] = ABJ404_STATUS_CAPTURED;

            self::setStage($context, 'high_impact_count');
            $rollupAvailable = $abj404dao->logsHitsTableExists();
            if ($rollupAvailable) {
                $highImpactCapturedCount = (int)$abj404dao->getHighImpactCapturedCount();
            } else {
                $abj404dao->scheduleHitsTableRebuild();
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
                    } catch (Throwable $ignored) {
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
        $abj404dao = abj_service('data_access');
        $abj404logic = abj_service('plugin_logic');

        $nonce = $abj404dao->getPostOrGetSanitize('nonce');
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
                    $diagnostics = self::getStageDiagnostics($stage);
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

        } catch (Throwable $e) {
            // Diagnostics endpoint, never fail loudly.  An admin-side notice
            // that says "stage: (lookup failed)" is a worse outcome than
            // "stage: (unknown)".
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
        $abj404dao = abj_service('data_access');
        $abj404logic = abj_service('plugin_logic');

        $nonce = $abj404dao->getPostOrGetSanitize('nonce');
        $page = $abj404dao->getPostOrGetSanitize('page', '');
        $subpage = $abj404dao->getPostOrGetSanitize('subpage', '');
        $requestId = self::readClientRequestId();
        $forceViewRebuild = ((string)$abj404dao->getPostOrGetSanitize('forceViewRebuild', '0') === '1');

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

            if (!is_object($abj404dao) || !method_exists($abj404dao, 'advanceViewBuildOnce')) {
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
            // call of an ?abj404_force_view_rebuild=1 page-load. Invalidating
            // here (rather than in the fetch path) keeps the rebuild owned by
            // a single requestId so every staged sub-stage shows up in the
            // debug log. Best-effort: any read happening in parallel sees
            // the rebuild starting; the authoritative invalidate happens
            // inside advanceViewBuildOnce's locked region (forceRebuild=true).
            if ($forceViewRebuild) {
                if (method_exists($abj404dao, 'invalidateViewSnapshotCache')) {
                    $abj404dao->invalidateViewSnapshotCache();
                } else if (method_exists($abj404dao, 'invalidateViewDone')) {
                    $abj404dao->invalidateViewDone();
                }
            }

            self::tryClaimForegroundViewBuildLease($abj404dao);
            // Pass forceRebuild down so advanceViewBuildOnce takes the lock
            // with a 30s timeout (waiting for any in-flight cron/sibling
            // build to finish), re-invalidates inside the locked region,
            // and runs the build under THIS request's AJAX context. That is
            // what makes every staged_build_s* sub-stage event reach the
            // browser's "AJAX Load Times / Debug Info" panel.
            $progress = $abj404dao->advanceViewBuildOnce($forceViewRebuild);
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
                    } catch (Throwable $ignored) {
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
