<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared nonce + admin permission gate for AJAX handlers.
 *
 * Extracts the nonce from POST or GET, verifies it, and checks
 * userIsPluginAdmin(). On failure, sends a JSON 403 and terminates.
 */
trait ABJ_404_Solution_AjaxSecurityTrait {

    /**
     * Verify nonce and admin permissions. Sends JSON error and terminates on failure.
     *
     * @param string $action    The nonce action string.
     * @param string $nonceParam The POST/GET parameter name holding the nonce (default 'nonce').
     * @return void
     */
    private static function requireAdminWithNonce(string $action, string $nonceParam = 'nonce'): void {
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

        if (!abj_service('plugin_logic')->userIsPluginAdmin()) {
            wp_send_json_error(array('message' => __('Unauthorized', '404-solution')), 403);
            return; // @phpstan-ignore deadCode.unreachable
        }
    }
}
