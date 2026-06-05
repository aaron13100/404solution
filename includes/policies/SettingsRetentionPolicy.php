<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies retention/deletion numeric settings from the settings form payload.
 */
class ABJ_404_Solution_SettingsRetentionPolicy {

    /** @var ABJ_404_Solution_SettingsFieldValidator */
    private $fieldValidator;

    /** @param ABJ_404_Solution_SettingsFieldValidator $fieldValidator */
    public function __construct(ABJ_404_Solution_SettingsFieldValidator $fieldValidator) {
        $this->fieldValidator = $fieldValidator;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Any translated validation messages.
     */
    public function apply(array &$options, array $postData): string {
        $message = "";

        $message .= $this->fieldValidator->validateAndSetNumericField($options, $postData, 'capture_deletion',
            __('Error: Collected URL deletion value must be a number greater than or equal to zero', '404-solution'));
        $message .= $this->fieldValidator->validateAndSetNumericField($options, $postData, 'manual_deletion',
            __('Error: Manual redirect deletion value must be a number greater than or equal to zero', '404-solution'));
        $message .= $this->fieldValidator->validateAndSetNumericField($options, $postData, 'log_deletion',
            __('Error: Log deletion value must be a number greater than or equal to zero', '404-solution'));
        $message .= $this->fieldValidator->validateAndSetNumericField($options, $postData, 'auto_deletion',
            __('Error: Auto redirect deletion value must be a number greater than or equal to zero', '404-solution'));
        $message .= $this->fieldValidator->validateAndSetNumericField($options, $postData, 'auto_302_expiration_days',
            __('Error: Auto-redirect expiration days must be a number greater than or equal to zero', '404-solution'));
        $message .= $this->fieldValidator->validateAndSetNumericField($options, $postData, 'maximum_log_disk_usage',
            __('Error: Maximum log disk usage must be a number greater than zero', '404-solution'), 0, true);

        return $message;
    }
}
