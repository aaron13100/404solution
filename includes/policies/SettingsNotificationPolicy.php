<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies notification and email digest settings from the settings form payload.
 */
class ABJ_404_Solution_SettingsNotificationPolicy {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Any translated validation messages.
     */
    public function apply(array &$options, array $postData): string {
        $message = "";

        $message .= $this->applyAdminNotificationThreshold($options, $postData);
        if (isset($postData['admin_notification_email'])) {
            $options['admin_notification_email'] = trim(
                wp_kses_post(is_string($postData['admin_notification_email']) ? $postData['admin_notification_email'] : '')
            );
        }
        $message .= $this->applyNotificationFrequency($options, $postData);
        $message .= $this->applyDigestLimit($options, $postData);

        return $message;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applyAdminNotificationThreshold(array &$options, array $postData): string {
        if (!isset($postData['admin_notification'])) {
            return "";
        }

        $rawAdminNotification = is_scalar($postData['admin_notification']) ? $postData['admin_notification'] : '';
        if (is_numeric($rawAdminNotification) && (int)$rawAdminNotification >= 0) {
            $options['admin_notification'] = (int)$rawAdminNotification;
            return "";
        }

        if (is_numeric($rawAdminNotification)) {
            return __('Error: Admin notification threshold must be a non-negative number', '404-solution') . ".<BR/>";
        }

        return "";
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applyNotificationFrequency(array &$options, array $postData): string {
        if (!isset($postData['admin_notification_frequency'])) {
            return "";
        }

        $allowedFrequencies = array('instant', 'daily', 'weekly');
        $freq = sanitize_text_field(
            is_string($postData['admin_notification_frequency']) ? $postData['admin_notification_frequency'] : ''
        );
        if (!in_array($freq, $allowedFrequencies, true)) {
            return __('Error: Invalid email notification frequency selected', '404-solution') . ".<BR/>";
        }

        $options['admin_notification_frequency'] = $freq;
        $emailDigest = new ABJ_404_Solution_EmailDigest(
            abj_service('logs_repository'),
            ABJ_404_Solution_StatsRepositoryResolver::resolve(__CLASS__),
            $this->logger
        );
        $emailDigest->scheduleNextDigest();
        return "";
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applyDigestLimit(array &$options, array $postData): string {
        if (!isset($postData['admin_notification_digest_limit'])) {
            return "";
        }

        if (is_numeric($postData['admin_notification_digest_limit']) && $postData['admin_notification_digest_limit'] >= 1) {
            $options['admin_notification_digest_limit'] = absint($postData['admin_notification_digest_limit']);
            return "";
        }

        return __('Error: Digest limit must be a number greater than or equal to 1', '404-solution') . ".<BR/>";
    }
}
