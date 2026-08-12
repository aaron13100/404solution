<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cross-cutting infrastructure consumed by every admin-AJAX endpoint
 * handler in includes/ajax/Ajax_*.php. Owns the request lifecycle that
 * surrounds a response: debug-context start, response-sent marker, output
 * buffer management, error envelope construction, admin-status fallback
 * resolution, request reader, failure logging shim, and view instance
 * resolution. Actually emitting the JSON response (header/ledger stamping,
 * the encode+echo boundary, output-buffer drain, connection-detach, exit)
 * is ABJ_404_Solution_AjaxResponseEmitter's own cohesive responsibility --
 * see that class for why it is split out rather than kept here.
 *
 * Shared cross-cutting helpers (error-envelope builder, fatal-error
 * classifier, debug-context starter, admin-nonce action list) for the
 * per-endpoint admin-table AJAX handlers, so each handler can own a single
 * endpoint's logic in its own file while reusing this common surface.
 *
 * Named without the `Ajax_` endpoint prefix (it is infrastructure, not a
 * request handler) so it stays out of the per-endpoint contract / auth /
 * adversarial structural test globs, matching AjaxAdminEndpointRegistrar and
 * AjaxSecurityGate. It registers no `wp_ajax_*` action and has no request
 * entry point; every method here runs only after a real handler has already
 * authorized the request. Carrying the handler prefix made the auth glob scan
 * this file as though it were an endpoint, which it passed by coincidence
 * (a substring in policy code) until that code moved to
 * AdminStatusFallbackResolver.
 */
class ABJ_404_Solution_AjaxAdminEndpointSupport {

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
        $checkpointRequestId = ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestId($context);
        $gate = function_exists('abj_service_optional') ? abj_service_optional('ajax_security_gate') : null;
        if (!is_object($gate) || !method_exists($gate, 'authorizeAdminWithNonce')) {
            ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'auth_service_unavailable_branch', array('handler' => $handlerName));
            self::safeLogAjaxFailure('AJAX authorization service unavailable in ' . $handlerName . '.', $context);
            self::markAjaxResponseSent();
            self::getAndClearAjaxBufferedOutput();
            ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit(
                self::buildAjaxErrorResponse('Unauthorized', null, false),
                403
            );
            return false;
        }

        $result = ABJ_404_Solution_AjaxCheckpointLogger::around(
            $checkpointRequestId,
            'auth_check',
            static fn() => $gate->authorizeAdminWithNonce($nonceAction, $options)
        );
        if (is_array($result) && !empty($result['ok'])) {
            if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
                $GLOBALS['abj404_ajax_context']['is_plugin_admin'] = true;
            }
            return true;
        }

        $code = self::authResultString($result, 'code', 'unauthorized');
        $message = self::authResultString($result, 'message', 'Unauthorized');
        $status = self::authResultStatus($result, 403);

        $summary = $code === 'invalid_nonce'
            ? 'AJAX invalid nonce in ' . $handlerName . '.'
            : 'AJAX unauthorized in ' . $handlerName . '.';
        ABJ_404_Solution_AjaxCheckpointLogger::record($checkpointRequestId, 'auth_failure_branch', array('code' => $code, 'status' => $status));
        self::safeLogAjaxFailure($summary, $context);
        self::markAjaxResponseSent();
        self::getAndClearAjaxBufferedOutput();
        ABJ_404_Solution_AjaxResponseEmitter::sendJsonResponseAndExit(
            self::buildAjaxErrorResponse($message, null, false),
            $status
        );
        return false;
    }

    /**
     * Read one string field out of an authorization result, falling back when
     * it is absent or the wrong type.
     *
     * $result is deliberately typed `mixed` rather than the authorizer's
     * declared `array{ok: bool, code: string, ...}` shape, because that shape
     * is not what this code can count on at runtime: the gate is resolved from
     * the service container and accepted on nothing stronger than
     * is_object() + method_exists(), so any object exposing the method name
     * reaches this branch and may return whatever it likes. Inlined at the
     * call site the guards read as dead code -- the declared shape guarantees
     * the keys -- and static analysis says so; taking the value through a
     * `mixed` parameter is what makes the check honest instead of removing it
     * from an authorization failure path.
     *
     * @param mixed $result
     */
    private static function authResultString($result, string $key, string $fallback): string {
        if (!is_array($result) || !isset($result[$key]) || !is_string($result[$key])) {
            return $fallback;
        }
        return $result[$key];
    }

    /**
     * The HTTP status from an authorization result, or $fallback when it is
     * absent or non-scalar. Separate from authResultString() because the
     * accepted input is wider (any scalar, intval()'d) and the output type is
     * different; see that method for why $result is typed `mixed`.
     *
     * @param mixed $result
     */
    private static function authResultStatus($result, int $fallback): int {
        if (!is_array($result) || !isset($result['status']) || !is_scalar($result['status'])) {
            return $fallback;
        }
        return intval($result['status']);
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
        self::safeLogAjaxFailureBranch(
            'failure_branch',
            $summary,
            static fn() => $details,
            $throwable
        );
    }

    /**
     * Persist a post-authorization failure fingerprint before constructing
     * details, then trace every shared failure-logging boundary.
     *
     * @param string $branch
     * @param string $summary
     * @param callable(): mixed $detailsFactory
     * @param \Throwable|null $throwable
     * @return mixed The constructed details, for the caller's response path.
     */
    public static function safeLogAjaxFailureBranch(
        string $branch,
        $summary,
        callable $detailsFactory,
        $throwable = null
    ) {
        return ABJ_404_Solution_PostAuthorizationFailureTracer::trace(
            $branch,
            $throwable,
            $detailsFactory,
            static function ($details) use ($summary, $throwable) {
                $logger = ABJ_404_Solution_PostAuthorizationFailureTracer::aroundOperation(
                    'logger_resolution',
                    static fn() => self::ajaxFailureLogger()
                );
                $logger->setOperationTracer(
                    static fn(string $operation, callable $work) =>
                        ABJ_404_Solution_PostAuthorizationFailureTracer::aroundOperation(
                            $operation,
                            $work
                        )
                );
                $logger->safeLogAjaxFailure($summary, $details, $throwable);
                return $details;
            }
        );
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
        // diagnosticRequestId, not instrumentedRequestId: this arming point is
        // shared by the table endpoint AND the canary ladder, and the
        // instrumented predicate answers for ajaxUpdatePaginationLinks alone.
        // Using it here left ajaxRunCanaryStep with every tracer null, so the
        // ladder -- which exists only to re-run the same boot/auth/dispatch
        // path and produce a COMPARABLE trace -- came back with none of the
        // records it is compared against. Both predicates still require the
        // debug opt-in, so a default GA request stays inert either way.
        $diagnosticsEnabled = ABJ_404_Solution_AjaxRequestLedger::diagnosticRequestId($context) !== '';
        self::configureDiagnosticOperationTracers($diagnosticsEnabled);
        return $context;
    }

    /**
     * Arm retry-only diagnostics after the endpoint has authorized the user.
     *
     * Raw retryCount input is insufficient: callers must reach this method
     * after nonce and plugin-admin checks. The policy validates the action,
     * bounded retry count, and this internal marker before returning an ID.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function armAuthorizedRetryDiagnostics(array $context): array {
        $context['diagnostic_retry_authorized'] = true;
        $GLOBALS['abj404_ajax_context'] = $context;
        $diagnosticsEnabled = ABJ_404_Solution_AjaxRequestLedger::diagnosticRequestId($context) !== '';
        self::configureDiagnosticOperationTracers($diagnosticsEnabled);
        return $context;
    }

    /** Enable or clear the shared operation tracer callbacks for this request. */
    private static function configureDiagnosticOperationTracers(bool $diagnosticsEnabled): void {
        $fileTracer = $diagnosticsEnabled
            ? static fn(string $operation, string $path, array $fields, callable $work) =>
                ABJ_404_Solution_TemplateFileReadTracer::trace($operation, $path, $fields, $work)
            : null;
        $routineTracer = $diagnosticsEnabled
            ? static fn(string $operation, array $fields, callable $work) =>
                ABJ_404_Solution_RoutineLogTracer::trace($operation, $fields, $work)
            : null;
        $authorizationTracer = $diagnosticsEnabled
            ? static fn(string $authorizationOperation, string $routineOperation, callable $work) =>
                ABJ_404_Solution_AuthorizationLogTracer::aroundRoutineOperation(
                    $authorizationOperation, $routineOperation, $work)
            : null;
        $sortReadinessTracer = $diagnosticsEnabled
            ? static fn(string $operation, array $fields, callable $work) =>
                ABJ_404_Solution_SortReadinessTracer::trace($operation, $fields, $work)
            : null;
        $statusCountTracer = $diagnosticsEnabled
            ? static fn(string $operation, array $fields, callable $work) =>
                ABJ_404_Solution_StatusCountsForegroundTracer::trace($operation, $fields, $work)
            : null;
        ABJ_404_Solution_FileSystemService::setOperationTracer($fileTracer);
        ABJ_404_Solution_RoutineLoggingBridge::setTracer($routineTracer);
        ABJ_404_Solution_RoutineLoggingBridge::setAuthorizationTracer($authorizationTracer);
        ABJ_404_Solution_RedirectsDenormSchemaReadiness::setOperationTracer($sortReadinessTracer);
        ABJ_404_Solution_StatusCountsRepository::setOperationTracer($statusCountTracer);
        ABJ_404_Solution_StatusCountsRefreshCoordinator::setOperationTracer($statusCountTracer);
        ABJ_404_Solution_CronScheduler::setStatusCountOperationTracer($statusCountTracer);
    }

    /** @return void */
    public static function markAjaxResponseSent() {
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $GLOBALS['abj404_ajax_context']['response_sent'] = true;
        }
    }

    /** @return string */
    public static function getAndClearAjaxBufferedOutput() {
        // This filter dispatches foreign WordPress callbacks (named + `all`)
        // before the output buffer is read or drained. On the instrumented
        // table endpoint the dispatch is bracketed and every callback attributed;
        // off it, traceDispatch() is a byte-identical pass-through.
        $shouldManageBuffer = ABJ_404_Solution_ResponseControlFilterTracer::traceDispatch(
            'abj404_should_manage_output_buffer',
            static function () {
                return apply_filters('abj404_should_manage_output_buffer', true, array('source' => 'viewUpdater_getAndClearAjaxBufferedOutput'));
            }
        );
        if (!$shouldManageBuffer) {
            return '';
        }

        $checkpointRequestId = ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext();
        $out = '';
        if (ob_get_level() > 0) {
            if ($checkpointRequestId === '') {
                $out = (string)ob_get_contents();
            } else {
                $out = (string)ABJ_404_Solution_AjaxCheckpointLogger::around(
                    $checkpointRequestId,
                    'ob_read',
                    static function () {
                        return ob_get_contents();
                    },
                    self::outputBufferCheckpointFields()
                );
            }
        }

        $minLevel = 0;
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $minLevel = array_key_exists('ob_level_before', $GLOBALS['abj404_ajax_context'])
                ? intval($GLOBALS['abj404_ajax_context']['ob_level_before']) : 0;
        }
        // Bounded with a stall check, for the same reason as the response tail:
        // each iteration here can cost a checkpoint write, so an unbounded
        // drain over a buffer that refuses to close burns CPU indefinitely.
        ABJ_404_Solution_OutputBufferDrain::drainTo($minLevel, static function () use ($checkpointRequestId) {
            if ($checkpointRequestId === '') {
                @ob_end_clean();
                return;
            }
            ABJ_404_Solution_AjaxCheckpointLogger::around(
                $checkpointRequestId,
                'ob_clear',
                static function () {
                    @ob_end_clean();
                },
                self::outputBufferCheckpointFields()
            );
        });

        return $out;
    }

    /**
     * Identify the active output-buffer stack before a read or clean call can
     * invoke a foreign handler. Kept free of buffered content so diagnostics
     * cannot expose response data.
     *
     * @return array{ob_level: int, ob_length: int, ob_handlers: array<int, string>}
     */
    private static function outputBufferCheckpointFields(): array {
        $length = ob_get_length();
        $handlers = ob_list_handlers();
        return array(
            'ob_level' => ob_get_level(),
            'ob_length' => is_int($length) ? $length : 0,
            'ob_handlers' => is_array($handlers) ? $handlers : array(),
        );
    }

    /** @return ABJ_404_Solution_RequestInputNormalizer */
    public static function getRequestReader() {
        $container = ABJ_404_Solution_ServiceContainer::getInstance();
        if ($container->has('request_input_normalizer')) {
            /** @var ABJ_404_Solution_RequestInputNormalizer $requestReader */
            $requestReader = $container->get('request_input_normalizer');
            return $requestReader;
        }
        /** @var ABJ_404_Solution_RequestInputNormalizer $requestReader */
        $requestReader = abj_service('request_input_normalizer');
        return $requestReader;
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
        return ABJ_404_Solution_AdminStatusFallbackResolver::resolve($isPluginAdmin, $includeWpUserFallback);
    }
}
