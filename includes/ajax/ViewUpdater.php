<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Funtcions supporting Ajax stuff.  */

class ABJ_404_Solution_ViewUpdater {

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
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshStatsDashboard',
                array($me, 'refreshStatsDashboard'));
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxRefreshHealthBar',
                array($me, 'refreshHealthBar'));
        // wp_ajax_nopriv_ is for normal users
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
            }
            header('Content-type: application/json; charset=UTF-8');
            if (function_exists('status_header')) {
                status_header($httpStatus);
            } else if (function_exists('http_response_code')) {
                http_response_code($httpStatus);
            }
        }
        echo json_encode($payload);

        // Test hook: allow unit tests to call handlers without terminating the test process.
        if (defined('ABJ404_TEST_NO_EXIT') && ABJ404_TEST_NO_EXIT) {
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
            try {
                $resolved = abj_service('view');
                if (is_object($resolved)) {
                    $abj404view = $resolved;
                    return $abj404view;
                }
            } catch (Throwable $e) {
                // fall through to error below
            }
        }
        throw new Exception('ABJ404 view service not initialized (abj404view is null).');
    }

	    /**
	     * @param mixed $value
	     * @return string
	     */
	    private static function safeJsonEncode($value) {
	        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
	        if ($encoded === false) {
	            return '(json_encode failed) ' . print_r($value, true);
	        }
	        return $encoded;
	    }

    /**
     * @param mixed $sql
     * @return string
     */
    private static function redactSqlShape($sql) {
        if (!is_string($sql) || $sql === '') {
            return '';
        }

        $out = $sql;

        // Replace quoted strings (single and double quotes) with placeholders.
        // Note: $wpdb->last_query is a final SQL string and may contain user input values.
        $out = preg_replace("~'(?:\\\\'|''|[^'])*'~", "?", $out) ?? $out;
        $out = preg_replace('~"(?:\\\\"|""|[^"])*"~', "?", $out) ?? $out;

        // Replace hex literals and numbers.
        $out = preg_replace('~\\b0x[0-9A-Fa-f]+\\b~', '?', $out) ?? $out;
        $out = preg_replace('~\\b\\d+(?:\\.\\d+)?\\b~', '?', $out) ?? $out;

        // Collapse long IN (...) / value lists to a single placeholder.
        $out = preg_replace('~\\(\\s*\\?\\s*(?:,\\s*\\?\\s*)+\\)~', '(?)', $out) ?? $out;
        $out = preg_replace('~\\bIN\\s*\\(\\?\\)\\b~i', 'IN (?)', $out) ?? $out;

        // Normalize whitespace and cap length (shape only).
        $out = preg_replace('~\\s+~', ' ', trim($out)) ?? $out;
        if (strlen($out) > 4000) {
            $out = substr($out, 0, 4000) . '…';
        }
        return $out;
    }

    /**
     * @param string $summary
     * @param mixed $details
     * @param \Throwable|null $throwable
     * @return void
     */
    private static function safeLogAjaxFailure($summary, $details = null, $throwable = null) {
        $line = date('c') . ' (ERROR): ' . $summary;
        if ($details !== null) {
            $line .= ' Details: ' . self::safeJsonEncode($details);
        }
        if ($throwable instanceof Throwable) {
            $line .= ' Exception: ' . $throwable->getMessage() . ' @ ' . $throwable->getFile() . ':' . $throwable->getLine() .
                ' Trace: ' . $throwable->getTraceAsString();
        }

        // Always attempt to write to the plugin debug file.
        try {
            $logger = ABJ_404_Solution_Logging::getInstance();
            if (is_object($logger) && method_exists($logger, 'writeLineToDebugFile')) {
                $logger->writeLineToDebugFile($line);
                return;
            }
        } catch (Throwable $e) {
            // fall back below
        }

        // Last-resort fallback (should be rare): write next to the plugin.
        // This ensures we still capture the error even if options/services are broken.
        try {
            $logger = ABJ_404_Solution_Logging::getInstance();
            if (is_object($logger) && method_exists($logger, 'sanitizeLogLine')) {
                $line = $logger->sanitizeLogLine($line);
            }
        } catch (Throwable $e) {
            // ignore; still write the best-effort line below
        }
        @file_put_contents(ABJ404_PATH . 'abj404_debug_fallback.txt', $line . "\n", FILE_APPEND);
    }

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
            @ini_set('display_errors', '0');
        }
        if (!(defined('ABJ404_TEST_DISABLE_OB') && ABJ404_TEST_DISABLE_OB)) {
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
        if (defined('ABJ404_TEST_DISABLE_OB') && ABJ404_TEST_DISABLE_OB) {
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
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        global $abj404view;
        
        $rowsPerPage = absint($abj404dao->getPostOrGetSanitize('rowsPerPage'));
        $subpage = $abj404dao->getPostOrGetSanitize('subpage');
        $nonce = $abj404dao->getPostOrGetSanitize('nonce');
        $page = $abj404dao->getPostOrGetSanitize('page', '');
        $filterText = $abj404dao->getPostOrGetSanitize('filterText', '');
        $filter = $abj404dao->getPostOrGetSanitize('filter', '');
        $detectOnly = ((string)$abj404dao->getPostOrGetSanitize('detectOnly', '0') === '1');
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
            $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
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

            $data = array();
            if ($subpage == 'abj404_redirects') {
                $context['stage'] = 'table_redirects';
                $data['table'] = $view->getAdminRedirectsPageTable($subpage);

                // Include tab counts so the page shell can render instantly with
                // placeholders and fill them in. The slower health-bar query
                // (getHighImpactCapturedCount, see refreshHealthBar()) is fetched
                // in a separate AJAX call so it never blocks first paint of the table.
                $context['stage'] = 'redirect_status_counts';
                $statusCounts = $abj404dao->getRedirectStatusCounts();
                // Tab counts keyed by filter value for JS tab updates.
                $data['tabCounts'] = array(
                    '0' => $statusCounts['all'] ?? 0,
                    (string)ABJ404_STATUS_MANUAL => $statusCounts['manual'] ?? 0,
                    (string)ABJ404_STATUS_AUTO => $statusCounts['auto'] ?? 0,
                    (string)ABJ404_TRASH_FILTER => $statusCounts['trash'] ?? 0,
                );

            } else if ($subpage == 'abj404_captured') {
                $context['stage'] = 'table_captured';
                $data['table'] = $view->getCapturedURLSPageTable($subpage);

                // Include tab counts so the page shell can render instantly.
                $context['stage'] = 'captured_status_counts';
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
                $context['stage'] = 'table_logs';
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

            $context['stage'] = 'paginationLinksTop';
            $data['paginationLinksTop'] = $view->getPaginationLinks($subpage);
            $context['stage'] = 'paginationLinksBottom';
            $data['paginationLinksBottom'] = $view->getPaginationLinks($subpage, false);

            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            self::sendJsonResponseAndExit($data, 200);
            return;

        } catch (Throwable $e) {
            // Determine admin status for diagnostics (never shown to non-admins).
            // If PluginLogic is broken/throws, fall back to WordPress capability checks so real admins can still see details.
            if (!$isPluginAdmin) {
                try {
                    $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
                    if (is_object($abj404logic) && method_exists($abj404logic, 'userIsPluginAdmin')) {
                        $isPluginAdmin = (bool)$abj404logic->userIsPluginAdmin();
                    }
                } catch (Throwable $ignored) {
                    // ignore and try WP fallback below
                }
                if (!$isPluginAdmin) {
                    try {
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
                    } catch (Throwable $ignored) {
                        // ignore
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
    function refreshStatsDashboard() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

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
                try {
                    $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
                    if (is_object($abj404logic) && method_exists($abj404logic, 'userIsPluginAdmin')) {
                        $isPluginAdmin = (bool)$abj404logic->userIsPluginAdmin();
                    }
                } catch (Throwable $ignored) {
                    // ignore and try fallback
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
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

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

            $context['stage'] = 'redirect_status_counts';
            $statusCounts = $abj404dao->getRedirectStatusCounts();
            // Provide the captured filter constant so JS can build the "View" link.
            $statusCounts['_capturedFilter'] = ABJ404_STATUS_CAPTURED;

            $context['stage'] = 'high_impact_count';
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
                try {
                    $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
                    if (is_object($abj404logic) && method_exists($abj404logic, 'userIsPluginAdmin')) {
                        $isPluginAdmin = (bool)$abj404logic->userIsPluginAdmin();
                    }
                } catch (Throwable $ignored) {
                    // ignore and try fallback
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

}
