<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared nonce + admin permission gate for AJAX handlers.
 */
class ABJ_404_Solution_AjaxSecurityGate {

    /**
     * Sentinel for a constructor argument that was not supplied. A dependency
     * left at this value is resolved lazily from the service container at use
     * time, instead of being captured at construction. This keeps the gate
     * from holding a stale admin_access_policy / logging reference when those
     * services are (re)registered after the gate is first built. The container
     * caches the gate instance, so a value captured in the constructor would
     * otherwise never reflect a later re-registration.
     */
    const RESOLVE_FROM_CONTAINER = "\0__abj404_resolve_from_container__";

    /** @var object|null|string */
    private $adminAccessPolicy;

    /** @var object|null|string */
    private $logger;

    /**
     * @param object|null|string $adminAccessPolicy Service exposing isPluginAdmin().
     *        Omit (or pass RESOLVE_FROM_CONTAINER) to resolve 'admin_access_policy'
     *        lazily from the container on each authorization.
     * @param object|null|string $logger Service exposing infoMessage()/warn().
     *        Omit to resolve 'logging' lazily from the container.
     */
    public function __construct($adminAccessPolicy = self::RESOLVE_FROM_CONTAINER, $logger = self::RESOLVE_FROM_CONTAINER) {
        $this->adminAccessPolicy = $adminAccessPolicy;
        $this->logger = $logger;
    }

    /**
     * Resolve the admin-access policy, honoring an explicitly injected value
     * (including an explicit null, which means "no policy") and otherwise
     * pulling the current 'admin_access_policy' service from the container.
     *
     * @return object|null
     */
    protected function getAdminAccessPolicy() {
        $policy = $this->adminAccessPolicy;
        if (is_string($policy)) {
            // Either the resolve-from-container sentinel or, defensively, any
            // other raw string (never a valid policy): resolve from the
            // container in both cases.
            return abj_service_optional('admin_access_policy');
        }
        return $policy;
    }

    /**
     * Resolve the logger, honoring an explicitly injected value and otherwise
     * pulling the current 'logging' service from the container.
     *
     * @return object|null
     */
    protected function getLogger() {
        $logger = $this->logger;
        if (is_string($logger)) {
            // Either the resolve-from-container sentinel or, defensively, any
            // other raw string (never a valid logger): resolve from the
            // container in both cases.
            return abj_service_optional('logging');
        }
        return $logger;
    }

    /**
     * Verify nonce and admin permissions. Sends JSON error and terminates on failure.
     *
     * @param string $action The nonce action string.
     * @param string $nonceParam The POST/GET parameter name holding the nonce.
     * @param array<string, mixed> $options Optional nonce_value override.
     * @return void
     */
    public function requireAdminWithNonce(string $action, string $nonceParam = 'nonce', array $options = array()): void {
        $options['nonce_param'] = $nonceParam;
        $result = $this->authorizeAdminWithNonce($action, $options);
        if ($result['ok']) {
            return;
        }

        wp_send_json_error(array('message' => $result['message']), $result['status']);
        return; // @phpstan-ignore deadCode.unreachable
    }

    /**
     * Verify nonce and capability. Sends JSON error and terminates on failure.
     *
     * @param string $action The nonce action string.
     * @param string $capability Required WordPress capability.
     * @param string $nonceParam The POST/GET parameter name holding the nonce.
     * @param array<string, mixed> $options Optional nonce_value override.
     * @return void
     */
    public function requireCapabilityWithNonce(
        string $action,
        string $capability,
        string $nonceParam = 'nonce',
        array $options = array()
    ): void {
        $options['capability'] = $capability;
        $options['nonce_param'] = $nonceParam;
        $result = $this->authorizeAdminWithNonce($action, $options);
        if ($result['ok']) {
            return;
        }

        wp_send_json_error(array('message' => $result['message']), $result['status']);
        return; // @phpstan-ignore deadCode.unreachable
    }

    /**
     * Return a shared authorization decision without emitting the response.
     * Callers with non-standard transports (diagnostic envelopes, Select2
     * result arrays) use this to keep the same nonce/capability contract.
     *
     * @param string $action The nonce action string.
     * @param array<string, mixed> $options Supports nonce_param, nonce_value, capability.
     * @return array{ok: bool, code: string, message: string, status: int, is_plugin_admin: bool}
     */
    public function authorizeAdminWithNonce(string $action, array $options = array()): array {
        $nonce = $this->resolveNonce($options);

        if (!$this->nonceIsValid($nonce, $action, $options)) {
            return $this->failure('invalid_nonce', __('Invalid security token', '404-solution'), 403);
        }

        $capability = isset($options['capability']) && is_string($options['capability'])
            ? $options['capability'] : '';
        $isAuthorized = $capability !== ''
            ? $this->currentUserCan($action, $capability)
            : $this->isPluginAdmin($action);

        if (!$isAuthorized) {
            $message = $capability !== ''
                ? __('Unauthorized: Insufficient permissions', '404-solution')
                : __('Unauthorized', '404-solution');
            return $this->failure('unauthorized', $message, 403);
        }

        $this->logAuthorizedAction($action);
        return array(
            'ok' => true,
            'code' => 'ok',
            'message' => '',
            'status' => 200,
            'is_plugin_admin' => $capability === '',
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return bool
     */
    private function nonceIsValid(string $nonce, string $action, array $options): bool {
        if (function_exists('wp_verify_nonce')) {
            return (bool)wp_verify_nonce($nonce, $action);
        }
        if (function_exists('check_ajax_referer')) {
            $nonceParam = isset($options['nonce_param']) && is_string($options['nonce_param'])
                ? $options['nonce_param'] : 'nonce';
            return check_ajax_referer($action, $nonceParam, false) !== false;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveNonce(array $options): string {
        if (array_key_exists('nonce_value', $options)) {
            return $this->sanitizeNonceValue($options['nonce_value']);
        }

        $nonceParam = isset($options['nonce_param']) && is_string($options['nonce_param'])
            ? $options['nonce_param'] : 'nonce';
        if (isset($_POST[$nonceParam])) {
            return $this->sanitizeNonceValue($_POST[$nonceParam]);
        }
        if (isset($_GET[$nonceParam])) {
            return $this->sanitizeNonceValue($_GET[$nonceParam]);
        }

        return '';
    }

    /**
     * @param mixed $raw
     */
    private function sanitizeNonceValue($raw): string {
        if (!is_scalar($raw)) {
            return '';
        }
        return sanitize_text_field((string)$raw);
    }

    /**
     * @return array{ok: false, code: string, message: string, status: int, is_plugin_admin: false}
     */
    private function failure(string $code, string $message, int $status): array {
        return array(
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'status' => $status,
            'is_plugin_admin' => false,
        );
    }

    private function currentUserCan(string $action, string $capability): bool {
        try {
            return function_exists('current_user_can') && current_user_can($capability);
        } catch (\Throwable $e) {
            $this->warn('AJAX authorization failed for ' . $action .
                ' while checking capability ' . $capability .
                ' (code ' . $e->getCode() . '): ' . $e->getMessage());
            return false;
        }
    }

    private function isPluginAdmin(string $action): bool {
        $adminAccessPolicy = $this->getAdminAccessPolicy();

        if (!is_object($adminAccessPolicy)) {
            $this->warn('AJAX authorization failed for ' . $action .
                ' because admin_access_policy service is unavailable.');
            return false;
        }

        try {
            if (method_exists($adminAccessPolicy, 'isPluginAdmin')) {
                return (bool)$adminAccessPolicy->isPluginAdmin();
            }
            $this->warn('AJAX authorization failed for ' . $action .
                ' because admin_access_policy service has no admin-check method.');
            return false;
        } catch (\Throwable $e) {
            $this->warn('AJAX authorization failed for ' . $action .
                ' (code ' . $e->getCode() . '): ' . $e->getMessage());
            return false;
        }
    }

    protected function logAuthorizedAction(string $action): void {
        $logger = $this->getLogger();
        try {
            if (!is_object($logger) || !method_exists($logger, 'infoMessage')) {
                throw new RuntimeException('logging service is unavailable');
            }
            $logger->infoMessage('AJAX authorized: ' . $action);
        } catch (\Throwable $e) {
            $this->warn('AJAX authorization logging failed for ' . $action .
                ' (code ' . $e->getCode() . '): ' . $e->getMessage());
        }
    }

    private function warn(string $message): void {
        $logger = $this->getLogger();
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }

        if (is_object($logger) && method_exists($logger, 'errorMessage')) {
            $logger->errorMessage($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
