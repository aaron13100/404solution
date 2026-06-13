<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared nonce + admin permission gate for AJAX handlers.
 */
class ABJ_404_Solution_AjaxSecurityGate {

    /** @var object|null */
    private $adminAccessPolicy;

    /** @var object|null */
    private $logger;

    /**
     * @param object|null $adminAccessPolicy Service exposing isPluginAdmin().
     * @param object|null $logger Service exposing infoMessage().
     */
    public function __construct($adminAccessPolicy, $logger) {
        $this->adminAccessPolicy = $adminAccessPolicy;
        $this->logger = $logger;
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
            return $this->failure('unauthorized', __('Unauthorized', '404-solution'), 403);
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
        $adminAccessPolicy = $this->adminAccessPolicy;

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

    private function logAuthorizedAction(string $action): void {
        try {
            if (!is_object($this->logger) || !method_exists($this->logger, 'infoMessage')) {
                throw new RuntimeException('logging service is unavailable');
            }
            $this->logger->infoMessage('AJAX authorized: ' . $action);
        } catch (\Throwable $e) {
            $this->warn('AJAX authorization logging failed for ' . $action .
                ' (code ' . $e->getCode() . '): ' . $e->getMessage());
        }
    }

    private function warn(string $message): void {
        if (is_object($this->logger) && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
            return;
        }

        if (is_object($this->logger) && method_exists($this->logger, 'errorMessage')) {
            $this->logger->errorMessage($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
