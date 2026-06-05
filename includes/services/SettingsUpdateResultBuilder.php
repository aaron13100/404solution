<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds structured settings-save AJAX responses.
 */
class ABJ_404_Solution_SettingsUpdateResultBuilder {

    /**
     * @return array<string, mixed>
     */
    public function baseData(): array {
        return array(
            'newURL' => admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_options',
        );
    }

    /**
     * @param string $errorMessage Concatenated translated validation messages.
     * @return array<string, mixed>
     */
    public function settingsSaveData(string $errorMessage): array {
        $returnData = $this->baseData();
        $returnData['error'] = $errorMessage;
        if ($errorMessage === "") {
            $returnData['message'] = __('Options Saved Successfully!', '404-solution');
        } else {
            $returnData['message'] = __('Some options were not saved successfully.', '404-solution') .
                '		' . $errorMessage;
        }
        return $returnData;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function success(array $data): array {
        return array(
            'success' => true,
            'status' => 200,
            'data' => $data,
        );
    }
}
