<?php

/* Funtcions supporting Ajax stuff.  */

class ABJ_404_Solution_ViewUpdater {

	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_ViewUpdater();
		}
		
		return self::$instance;
	}
		
    static function init() {
        $me = ABJ_404_Solution_ViewUpdater::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_ajaxUpdatePaginationLinks',
                array($me, 'getPaginationLinks'));
        // wp_ajax_nopriv_ is for normal users
    }

    public static function isFatalErrorType($type) {
        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        return in_array($type, $fatalTypes, true);
    }

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
        exit;
    }

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

	    private static function safeJsonEncode($value) {
	        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
	        if ($encoded === false) {
	            return '(json_encode failed) ' . print_r($value, true);
	        }
	        return $encoded;
	    }

    private static function redactSqlShape($sql) {
        if (!is_string($sql) || $sql === '') {
            return '';
        }

        $out = $sql;

        // Replace quoted strings (single and double quotes) with placeholders.
        // Note: $wpdb->last_query is a final SQL string and may contain user input values.
        $out = preg_replace("~'(?:\\\\'|''|[^'])*'~", "?", $out);
        $out = preg_replace('~"(?:\\\\"|""|[^"])*"~', "?", $out);

        // Replace hex literals and numbers.
        $out = preg_replace('~\\b0x[0-9A-Fa-f]+\\b~', '?', $out);
        $out = preg_replace('~\\b\\d+(?:\\.\\d+)?\\b~', '?', $out);

        // Collapse long IN (...) / value lists to a single placeholder.
        $out = preg_replace('~\\(\\s*\\?\\s*(?:,\\s*\\?\\s*)+\\)~', '(?)', $out);
        $out = preg_replace('~\\bIN\\s*\\(\\?\\)\\b~i', 'IN (?)', $out);

        // Normalize whitespace and cap length (shape only).
        $out = preg_replace('~\\s+~', ' ', trim($out));
        if (strlen($out) > 4000) {
            $out = substr($out, 0, 4000) . '…';
        }
        return $out;
    }

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

    private static function markAjaxResponseSent() {
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $GLOBALS['abj404_ajax_context']['response_sent'] = true;
        }
    }

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

        $isPluginAdmin = false;
        $context = array(
            'action' => 'ajaxUpdatePaginationLinks',
            'page' => $page,
            'subpage' => $subpage,
            'rowsPerPage' => $rowsPerPage,
            'filterText_length' => is_string($filterText) ? strlen($filterText) : 0,
            'filter' => $filter,
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

            // Rate limiting to prevent abuse (100 requests per minute)
            if (ABJ_404_Solution_Ajax_Php::checkRateLimit('update_pagination', 100, 60)) {
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

            $view = self::resolveViewInstance($abj404view);

            $data = array();
            $context['stage'] = 'paginationLinksTop';
            $data['paginationLinksTop'] = $view->getPaginationLinks($subpage);
            $context['stage'] = 'paginationLinksBottom';
            $data['paginationLinksBottom'] = $view->getPaginationLinks($subpage, false);
            if ($subpage == 'abj404_redirects') {
                $context['stage'] = 'table_redirects';
                $data['table'] = $view->getAdminRedirectsPageTable($subpage);

            } else if ($subpage == 'abj404_captured') {
                $context['stage'] = 'table_captured';
                $data['table'] = $view->getCapturedURLSPageTable($subpage);

            } else if ($subpage == 'abj404_logs') {
                $context['stage'] = 'table_logs';
                $data['table'] = $view->getAdminLogsPageTable($subpage);

            } else {
                $data['table'] = 'Error: Unexpected subpage requested.';
            }

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
    
}
