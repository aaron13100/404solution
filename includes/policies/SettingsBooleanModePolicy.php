<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies boolean settings while respecting simple vs advanced settings mode.
 */
class ABJ_404_Solution_SettingsBooleanModePolicy {

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /** @var object|null */
    private $modePreference;

    /**
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param object|null $modePreference Optional service exposing getMode().
     */
    public function __construct($contentRepo, $modePreference = null) {
        $this->contentRepo = $contentRepo;
        $this->modePreference = $modePreference;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Empty string; kept for section-updater message contract.
     */
    public function apply(array &$options, array $postData): string {
        $settingsMode = $this->settingsMode();
        $allBooleanOptions = array(
            'remove_matches',
            'debug_mode',
            'suggest_cats',
            'suggest_tags',
            'auto_redirects',
            'auto_slugs',
            'auto_cats',
            'auto_tags',
            'auto_trash_redirect',
            'capture_404',
            'send_error_logs',
            'log_raw_ips',
            'redirect_all_requests',
            'update_suggest_url',
            'suggest_minscore_enabled',
            'auto_trash_junk_urls',
        );
        $simpleModeOptions = array('auto_redirects', 'capture_404', 'auto_trash_junk_urls');
        $optionsToProcess = $settingsMode === 'simple' ? $simpleModeOptions : $allBooleanOptions;

        foreach ($optionsToProcess as $optionName) {
            $newVal = (array_key_exists($optionName, $postData) && $postData[$optionName] == "1") ? 1 : 0;
            if (!array_key_exists($optionName, $options) || $options[$optionName] != $newVal) {
                $this->contentRepo->deleteSpellingCache();
            }
            $options[$optionName] = $newVal;
        }

        if ($settingsMode === 'simple') {
            $autoRedirectsValue = isset($options['auto_redirects']) ? $options['auto_redirects'] : 0;
            $options['auto_cats'] = $autoRedirectsValue;
            $options['auto_tags'] = $autoRedirectsValue;
        }

        return "";
    }

    private function settingsMode(): string {
        if ($this->modePreference === null) {
            $this->modePreference = abj_service('settings_mode_preference');
        }
        return is_callable(array($this->modePreference, 'getMode')) ?
            (string)call_user_func(array($this->modePreference, 'getMode')) : 'advanced';
    }
}
