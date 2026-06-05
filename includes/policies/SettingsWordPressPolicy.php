<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies WordPress and admin-display settings from the settings form payload.
 */
class ABJ_404_Solution_SettingsWordPressPolicy {

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Any translated validation messages.
     */
    public function apply(array &$options, array $postData): string {
        $message = "";

        foreach (array(
            'ignore_dontprocess',
            'ignore_doprocess',
            'recognized_post_types',
            'recognized_categories',
            'menuLocation',
        ) as $optionName) {
            if (isset($postData[$optionName])) {
                $options[$optionName] = wp_kses_post(is_string($postData[$optionName]) ? $postData[$optionName] : '');
            }
        }

        $message .= $this->applyAdminTheme($options, $postData);
        $message .= $this->applyLanguageOverride($options, $postData);
        $message .= $this->applyDarkModePreference($options, $postData);
        $message .= $this->applyMajorUpdateDelay($options, $postData);

        return $message;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applyAdminTheme(array &$options, array $postData): string {
        if (!isset($postData['admin_theme'])) {
            return "";
        }

        $allowedThemes = array('default', 'calm', 'mono', 'neon', 'obsidian');
        $theme = sanitize_text_field(is_string($postData['admin_theme']) ? $postData['admin_theme'] : '');
        if (in_array($theme, $allowedThemes, true)) {
            $options['admin_theme'] = $theme;
            return "";
        }

        return __('Error: Invalid theme selected', '404-solution') . ".<BR/>";
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applyLanguageOverride(array &$options, array $postData): string {
        if (!isset($postData['plugin_language_override'])) {
            return "";
        }

        $allowedLocales = array('', 'en_US', 'de_DE', 'es_ES', 'fr_FR', 'it_IT', 'pt_BR', 'nl_NL', 'ru_RU', 'ja', 'zh_CN', 'id_ID', 'sv_SE');
        $locale = sanitize_text_field(is_string($postData['plugin_language_override']) ? $postData['plugin_language_override'] : '');
        if (in_array($locale, $allowedLocales, true)) {
            $options['plugin_language_override'] = $locale;
            return "";
        }

        return __('Error: Invalid language selected', '404-solution') . ".<BR/>";
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applyDarkModePreference(array &$options, array $postData): string {
        $options['disable_auto_dark_mode'] = (
            isset($postData['disable_auto_dark_mode']) && $postData['disable_auto_dark_mode'] == '1'
        ) ? '1' : '0';

        return "";
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applyMajorUpdateDelay(array &$options, array $postData): string {
        if (!isset($postData['days_wait_before_major_update'])) {
            return "";
        }

        $rawDaysWait = is_scalar($postData['days_wait_before_major_update']) ?
            $postData['days_wait_before_major_update'] : '';
        if (is_numeric($rawDaysWait) && (int)$rawDaysWait >= 0) {
            $options['days_wait_before_major_update'] = (int)$rawDaysWait;
            return "";
        }

        return sprintf(
            __('Error: The time to wait before an automatic update must be a number between 0 and something around %d.', '404-solution'),
            PHP_INT_MAX
        ) . "<BR/>";
    }
}
