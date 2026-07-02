<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/feedback/FeedbackSiteTokenStore.php';
require_once dirname(__DIR__) . '/feedback/FeedbackDsrClient.php';

/**
 * AJAX handler for exporting this site's diagnostic data from the reports server.
 *
 * Entry point: wp_ajax_abj404_privacy_export.
 * Identity source: the local abj404_site_token option only.
 */
class ABJ_404_Solution_Ajax_PrivacyExport {

    const NONCE_ACTION = 'abj404_privacy_export';

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
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_privacy_export', array($me, 'handleRequest'));
    }

    /** @return void */
    public function handleRequest(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-privacy-export')) {
            return;
        }

        abj_service('ajax_security_gate')->requireAdminWithNonce(self::NONCE_ACTION);

        $token = ABJ_404_Solution_FeedbackSiteTokenStore::storedToken();
        if ($token === '') {
            wp_send_json_error(array(
                'message' => __('Diagnostic identity is not registered yet.', '404-solution'),
            ), 409);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $result = ABJ_404_Solution_FeedbackDsrClient::exportRows($token);
        if (empty($result['ok'])) {
            wp_send_json_error(array('message' => $result['message']), (int)$result['status']);
            return; // @phpstan-ignore deadCode.unreachable
        }

        wp_send_json_success($result['data']);
    }
}
