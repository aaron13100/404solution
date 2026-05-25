<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared nonce + admin permission gate for AJAX handlers.
 */
class ABJ_404_Solution_AjaxSecurityGate {

    /** @var object|null */
    private $pluginLogic;

    /** @var object|null */
    private $logger;

    /**
     * @param object|null $pluginLogic Service exposing userIsPluginAdmin().
     * @param object|null $logger Service exposing infoMessage().
     */
    public function __construct($pluginLogic, $logger) {
        $this->pluginLogic = $pluginLogic;
        $this->logger = $logger;
    }

    /**
     * Verify nonce and admin permissions. Sends JSON error and terminates on failure.
     *
     * @param string $action The nonce action string.
     * @param string $nonceParam The POST/GET parameter name holding the nonce.
     * @return void
     */
    public function requireAdminWithNonce(string $action, string $nonceParam = 'nonce'): void {
        $nonce = '';
        if (isset($_POST[$nonceParam]) && is_string($_POST[$nonceParam])) {
            $nonce = sanitize_text_field($_POST[$nonceParam]);
        } elseif (isset($_GET[$nonceParam]) && is_string($_GET[$nonceParam])) {
            $nonce = sanitize_text_field($_GET[$nonceParam]);
        }

        if (!wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(array('message' => __('Invalid security token', '404-solution')), 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        if (!$this->userIsPluginAdmin($action)) {
            wp_send_json_error(array('message' => __('Unauthorized', '404-solution')), 403);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $this->logAuthorizedAction($action);
    }

    private function userIsPluginAdmin(string $action): bool {
        if (!is_object($this->pluginLogic) || !method_exists($this->pluginLogic, 'userIsPluginAdmin')) {
            error_log('404 Solution: AJAX authorization failed for ' . $action .
                ' because plugin_logic service is unavailable.');
            return false;
        }

        try {
            return (bool)$this->pluginLogic->userIsPluginAdmin();
        } catch (\Throwable $e) {
            error_log('404 Solution: AJAX authorization failed for ' . $action .
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
            error_log('404 Solution: AJAX authorization logging failed for ' . $action .
                ' (code ' . $e->getCode() . '): ' . $e->getMessage());
        }
    }
}
