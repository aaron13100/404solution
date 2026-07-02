<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/feedback/FeedbackSiteTokenStore.php';
require_once dirname(__DIR__) . '/feedback/FeedbackDsrClient.php';

/**
 * AJAX handler for deleting this site's diagnostic data from the reports server.
 *
 * Entry point: wp_ajax_abj404_privacy_delete.
 * Identity source: the local abj404_site_token option only.
 */
class ABJ_404_Solution_Ajax_PrivacyDelete {

    const NONCE_ACTION = 'abj404_privacy_delete';

    /** @var self|null */
    private static $instance = null;

    /**
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }

    /** @return self */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** @return void */
    public static function init(): void {
        $me = self::getInstance();
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_privacy_delete', array($me, 'handleRequest'));
    }

    /** @return void */
    public function handleRequest(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-privacy-delete')) {
            return;
        }

        abj_service('ajax_security_gate')->requireAdminWithNonce(self::NONCE_ACTION);

        $confirm = $_POST['confirm'] ?? null;
        if (!is_scalar($confirm) || (string)$confirm !== '1') {
            wp_send_json_error(array(
                'message' => __('Confirm diagnostic data deletion before continuing.', '404-solution'),
            ), 400);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $token = ABJ_404_Solution_FeedbackSiteTokenStore::storedToken();
        if ($token === '') {
            wp_send_json_error(array(
                'message' => __('Diagnostic identity is not registered yet.', '404-solution'),
            ), 409);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $result = ABJ_404_Solution_FeedbackDsrClient::deleteRows($token, true);
        if (empty($result['ok'])) {
            wp_send_json_error(array('message' => $result['message']), (int)$result['status']);
            return; // @phpstan-ignore deadCode.unreachable
        }

        wp_send_json_success($result['data']);
    }
}
