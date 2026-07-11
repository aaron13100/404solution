<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cross-cutting infrastructure consumed by every admin-AJAX endpoint
 * handler in includes/ajax/Ajax_*.php. Owns the lifecycle that wraps each
 * AJAX response: debug-context start, response-sent marker, output buffer
 * management, JSON emit + headers + exit, error envelope construction,
 * admin-status fallback resolution, request reader, failure logging shim,
 * and view instance resolution.
 *
 * Shared cross-cutting helpers (error-envelope builder, JSON responder,
 * fatal-error classifier, debug-context starter, admin-nonce action list)
 * for the per-endpoint admin-table AJAX handlers, so each handler can own a
 * single endpoint's logic in its own file while reusing this common surface.
 */
class ABJ_404_Solution_Ajax_AdminEndpointSupport {

    /**
     * Admin nonce action verbs JS call sites consume. Keep in sync with
     * view_updater_nonce_refresh.js NONCE_DATA_ATTRS and the wp_verify_nonce()
     * calls in each handler + in Ajax_TrendData.php.
     * @return string[]
     */
    public static function adminNonceActions(): array {
        return array('abj404_updatePaginationLink',
            'abj404_refreshStatsDashboard', 'abj404_refreshHealthBar',
            'abj404_runLazyBackfill', 'abj404_trendData');
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
     * Verify an admin AJAX nonce and plugin-admin authorization, then emit
     * this layer's diagnostic error envelope on failure.
     *
     * @param string $nonceAction
     * @param array<string, mixed> $context
     * @param string $handlerName Human-readable handler label for logs.
     * @param array<string, mixed> $options Passed to AjaxSecurityGate.
     * @return bool True when authorized.
     */
    public static function requireAdminWithNonceOrRespond(
        string $nonceAction,
        array $context,
        string $handlerName,
        array $options = array()
    ): bool {
        $gate = function_exists('abj_service_optional') ? abj_service_optional('ajax_security_gate') : null;
        if (!is_object($gate) || !method_exists($gate, 'authorizeAdminWithNonce')) {
            self::safeLogAjaxFailure('AJAX authorization service unavailable in ' . $handlerName . '.', $context);
            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            self::sendJsonResponseAndExit(
                self::buildAjaxErrorResponse('Unauthorized', null, false),
                403
            );
            return false;
        }

        $result = $gate->authorizeAdminWithNonce($nonceAction, $options);
        if (is_array($result) && !empty($result['ok'])) {
            if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
                $GLOBALS['abj404_ajax_context']['is_plugin_admin'] = true;
            }
            return true;
        }

        $code = isset($result['code']) && is_string($result['code'])
            ? $result['code'] : 'unauthorized';
        $message = isset($result['message']) && is_string($result['message'])
            ? $result['message'] : 'Unauthorized';
        $status = isset($result['status']) && is_scalar($result['status'])
            ? intval($result['status']) : 403;

        $summary = $code === 'invalid_nonce'
            ? 'AJAX invalid nonce in ' . $handlerName . '.'
            : 'AJAX unauthorized in ' . $handlerName . '.';
        self::safeLogAjaxFailure($summary, $context);
        self::markAjaxResponseSent();
        self::getAndClearAjaxBufferedOutput();
        self::sendJsonResponseAndExit(
            self::buildAjaxErrorResponse($message, null, false),
            $status
        );
        return false;
    }

    /**
     * @param mixed $payload
     * @param int $httpStatus
     * @return void
     */
    public static function sendJsonResponseAndExit($payload, $httpStatus = 200) {
        if (!headers_sent()) {
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

        // Test hook: tests register `abj404_should_exit` returning false to skip exit.
        if (!apply_filters('abj404_should_exit', true, array('source' => 'viewUpdater_emitJson'))) {
            return;
        }

        // Flush so shutdown hooks (e.g. hits table rebuild) don't block the HTTP
        // connection, which would let reverse proxies like Cloudflare time out (524).
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
    public static function resolveViewInstance(&$abj404view) {
        if (is_object($abj404view)) {
            return $abj404view;
        }
        if (function_exists('abj_service_optional')) {
            $resolved = abj_service_optional('view');
            if (is_object($resolved)) {
                $abj404view = $resolved;
                return $abj404view;
            }
        }
        throw new Exception('ABJ404 view service not initialized (abj404view is null).'); // allow-raw-error: programmer assertion preserved verbatim from prior ViewUpdater behavior; signals service-container misconfiguration, not user-facing
    }

    /** @return ABJ_404_Solution_AjaxFailureLogger */
    private static function ajaxFailureLogger() {
        $logger = function_exists('abj_service_optional') ? abj_service_optional('ajax_failure_logger') : null;
        if ($logger instanceof ABJ_404_Solution_AjaxFailureLogger) {
            return $logger;
        }
        $logging = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        return new ABJ_404_Solution_AjaxFailureLogger(is_object($logging) ? $logging : null);
    }

    /**
     * @param mixed $sql
     * @return string
     */
    public static function redactSqlShape($sql) {
        return self::ajaxFailureLogger()->redactSqlShape($sql);
    }

    /**
     * @param string $summary
     * @param mixed $details
     * @param \Throwable|null $throwable
     * @return void
     */
    public static function safeLogAjaxFailure($summary, $details = null, $throwable = null) {
        self::ajaxFailureLogger()->safeLogAjaxFailure($summary, $details, $throwable);
    }

    /**
     * @param Throwable $throwable
     * @return array<string, mixed>|null
     */
    public static function extractViewQueryDiagnostics(Throwable $throwable) {
        return self::ajaxFailureLogger()->extractViewQueryDiagnostics($throwable);
    }

    /**
     * @param array<string, mixed> $context
     * @param string $source Identifier for the originating handler, recorded in the global context.
     * @return array<string, mixed>
     */
    public static function startAjaxDebugContext($context, string $source = 'ViewUpdater') {
        if (!is_array($context)) {
            $context = array();
        }

        $context['abj404_context_source'] = $source;
        $context['ajax_expected_json'] = true;
        $context['response_sent'] = false;
        $context['ob_level_before'] = ob_get_level();

        // Prevent WordPress's "critical error" HTML page from masking details for AJAX calls.
        if (!headers_sent()) {
            if (array_key_exists('action', $context) && is_string($context['action'])) {
                header('X-ABJ404-Ajax: ' . preg_replace('/[\r\n]+/', '', $context['action']));
            }
            if (array_key_exists('subpage', $context) && is_string($context['subpage']) && $context['subpage'] !== '') {
                header('X-ABJ404-Subpage: ' . preg_replace('/[\r\n]+/', '', $context['subpage']));
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
    public static function markAjaxResponseSent() {
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $GLOBALS['abj404_ajax_context']['response_sent'] = true;
        }
    }

    /** @return string */
    public static function getAndClearAjaxBufferedOutput() {
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

    /** @return ABJ_404_Solution_Functions */
    public static function getRequestReader() {
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
     * Re-check admin status after an exception path. PluginLogic may be the
     * broken component, so fall back to wp_get_current_user() and
     * is_super_admin() to give real admins detailed error diagnostics.
     *
     * @param bool $isPluginAdmin Current best-known admin status (e.g. from before the throw).
     * @param bool $includeWpUserFallback If true, also fall back to wp_get_current_user() and
     *                                    is_super_admin() (used only by getPaginationLinks; other
     *                                    handlers stop at the PluginLogic re-check).
     * @return bool
     */
    public static function resolveIsPluginAdminFallback(bool $isPluginAdmin, bool $includeWpUserFallback = false): bool {
        if ($isPluginAdmin) {
            return true;
        }
        $adminAccessPolicy = abj_service('admin_access_policy');
        if (is_object($adminAccessPolicy) && method_exists($adminAccessPolicy, 'isPluginAdmin')) {
            try {
                $isPluginAdmin = (bool)$adminAccessPolicy->isPluginAdmin();
            } catch (Throwable $ignored) { // allow-silent-catch: admin-status detection; PluginLogic may be the broken component, default to non-admin (hide details)
                $isPluginAdmin = false;
            }
        }
        if (!$includeWpUserFallback) {
            return $isPluginAdmin;
        }
        if (!$isPluginAdmin) {
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
        return $isPluginAdmin;
    }
}
