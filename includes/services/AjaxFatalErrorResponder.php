<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Emits the JSON fatal-error fallback for AJAX table-update requests.
 */
class ABJ_404_Solution_AjaxFatalErrorResponder {

    /** @var ABJ_404_Solution_ErrorDiagnosticsReporter */
    private $diagnostics;

    /** @param ABJ_404_Solution_ErrorDiagnosticsReporter|null $diagnostics */
    public function __construct($diagnostics = null) {
        $this->diagnostics = $diagnostics !== null ? $diagnostics : new ABJ_404_Solution_ErrorDiagnosticsReporter();
    }

    /**
     * @param array<string,mixed>|null $context
     * @return bool
     */
    public function isAjaxContext($context): bool {
        return is_array($context) &&
            !empty($context['ajax_expected_json']) &&
            empty($context['response_sent']) &&
            array_key_exists('action', $context) &&
            $context['action'] === 'ajaxUpdatePaginationLinks';
    }

    /**
     * @param array<string,mixed> $lasterror
     * @param array<string,mixed> $context
     * @return bool
     */
    public function process(array $lasterror, array $context): bool {
        $contextSourceOk = array_key_exists('abj404_context_source', $context) &&
            $context['abj404_context_source'] === 'Ajax_GetPaginationLinks::handle';
        if (!$contextSourceOk) {
            return false;
        }

        $bufferedOutput = $this->captureAndClearBufferedOutput($context);
        $details = $this->buildDetails($lasterror, $context, $bufferedOutput);
        $line = date('c', abj_clock()->now()) . ' (ERROR): AJAX fatal error in ajaxUpdatePaginationLinks. Details: ' .
            $this->diagnostics->safeJsonEncode($details);
        $this->diagnostics->writeLine($line);

        $payload = array(
            'success' => false,
            'data' => array(
                'message' => 'Server error while updating the table.',
            ),
        );
        if ($this->isPluginAdmin($context)) {
            $payload['data']['details'] = $details;
        }

        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $GLOBALS['abj404_ajax_context']['response_sent'] = true;
        }
        return $this->emitJsonAndExit($payload, 500);
    }

    /**
     * @param array<string,mixed> $context
     * @return string
     */
    private function captureAndClearBufferedOutput(array $context): string {
        $bufferedOutput = '';
        $shouldManageOb = function_exists('apply_filters')
            ? apply_filters('abj404_should_manage_output_buffer', true, array('source' => 'errorHandler_processFatalError'))
            : true;
        if ($shouldManageOb) {
            if (ob_get_level() > 0) {
                $bufferedOutput = (string)ob_get_contents();
            }
            $rawMinLevel = array_key_exists('ob_level_before', $context) ? $context['ob_level_before'] : 0;
            $minLevel = is_scalar($rawMinLevel) ? intval($rawMinLevel) : 0;
            if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
                $minLevel = max($minLevel, ob_get_level());
            }
            while (ob_get_level() > $minLevel) {
                @ob_end_clean();
            }
        }
        return $bufferedOutput;
    }

    /**
     * @param array<string,mixed> $lasterror
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildDetails(array $lasterror, array $context, string $bufferedOutput): array {
        $details = array(
            'fatal_error' => $lasterror,
            'context' => $context,
        );
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $lastQuery = $GLOBALS['wpdb']->last_query ?? '';
            $details['wpdb'] = array(
                'last_error' => $GLOBALS['wpdb']->last_error ?? '',
                'last_query_redacted' => $this->diagnostics->redactSqlShape($lastQuery),
                'last_query_length' => is_string($lastQuery) ? strlen($lastQuery) : 0,
            );
        }
        if ($bufferedOutput !== '') {
            $details['buffered_output'] = substr($bufferedOutput, 0, 8000);
        }
        return $details;
    }

    /**
     * @param array<string,mixed> $context
     * @return bool
     */
    private function isPluginAdmin(array $context): bool {
        $isPluginAdmin = array_key_exists('is_plugin_admin', $context) ? (bool)$context['is_plugin_admin'] : null;
        if ($isPluginAdmin === null) {
            try {
                $adminAccessPolicy = abj_service('admin_access_policy');
                if (is_object($adminAccessPolicy) && method_exists($adminAccessPolicy, 'isPluginAdmin')) {
                    $isPluginAdmin = $adminAccessPolicy->isPluginAdmin();
                }
            } catch (Throwable $e) {
                abj404_logPhpFallback(
                    'fatal-handler-fallback',
                    'AJAX fatal admin-status detection failed (code ' . $e->getCode() . '): ' . $e->getMessage()
                );
                $isPluginAdmin = null;
            }
        }
        if ($isPluginAdmin === null) {
            if (function_exists('wp_get_current_user')) {
                $user = ABJ_404_Solution_UserRef::fromWpUser(wp_get_current_user());
                if ($user !== null) {
                    $isPluginAdmin = $user->isAdministrator();
                }
            }
            if ($isPluginAdmin !== true && function_exists('is_super_admin') && is_super_admin()) {
                $isPluginAdmin = true;
            }
        }
        if ($isPluginAdmin === null) {
            $isPluginAdmin = false;
        }
        return (bool)$isPluginAdmin;
    }

    /**
     * @param array<string,mixed> $payload
     * @param int $httpStatus
     * @return bool
     */
    private function emitJsonAndExit(array $payload, int $httpStatus): bool {
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
            } elseif (function_exists('http_response_code')) {
                http_response_code($httpStatus);
            }
        }
        echo json_encode($payload);
        $shouldExit = function_exists('apply_filters')
            ? apply_filters('abj404_should_exit', true, array('source' => 'errorHandler_emitJson'))
            : true;
        if (!$shouldExit) {
            return true;
        }
        exit;
    }
}
