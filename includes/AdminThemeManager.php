<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin theme detection and critical CSS output.
 *
 * Detects dark mode from WordPress color schemes, dark mode plugins,
 * and browser preferences. Outputs critical theme CSS inline in the
 * <head> to prevent flash of unstyled content.
 */
class ABJ_404_Solution_AdminThemeManager {

    /** Detect if dark mode is enabled from various sources.
     *
     * @return bool True if dark mode is detected
     */
    static function isDarkModeDetected() {
        $current_user_id = get_current_user_id();
        if ($current_user_id) {
            $admin_color = get_user_meta($current_user_id, 'admin_color', true);
            $dark_schemes = array('midnight', 'ectoplasm', 'coffee');
            if (in_array($admin_color, $dark_schemes)) {
                return true;
            }
        }

        if (get_option('wp_dark_mode_enabled')) {
            return true;
        }

        if (get_option('dark_mode_for_wp_dashboard_enabled')) {
            return true;
        }

        if (class_exists('WP_Dark_Mode') || class_exists('Dark_Mode_For_WP_Dashboard')) {
            return true;
        }

        return false;
    }

    /**
     * @return string The theme to use ('obsidian' for dark mode, 'default' otherwise)
     */
    static function getAutoSelectedTheme() {
        if (self::isDarkModeDetected()) {
            return 'obsidian';
        }
        return 'default';
    }

    /**
     * Output critical theme CSS inline to prevent FOUC.
     *
     * @return void
     */
    static function outputCriticalThemeCSS() {
        try {
            if (!array_key_exists('abj404_settingsPageName', $GLOBALS) ||
                !array_key_exists('page', $_GET) ||
                $_GET['page'] != ABJ404_PP) {
                return;
            }

            $logic = abj_service('plugin_logic');
            $options = $logic->getOptions();
            $theme = (isset($options['admin_theme']) && is_string($options['admin_theme'])) ? $options['admin_theme'] : 'default';

            $auto_dark_mode = !isset($options['disable_auto_dark_mode']) || $options['disable_auto_dark_mode'] != '1';

            if ($theme === 'default' && $auto_dark_mode) {
                $theme = self::getAutoSelectedTheme();
            }

            $allowed_themes = array('default', 'calm', 'mono', 'neon', 'obsidian');
            if (!in_array($theme, $allowed_themes)) {
                $theme = 'default';
            }

            if ($theme === 'default') {
                $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/themeRemoverScript.html");
                echo $html;
                return;
            }

            $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/themeSetterScript.html");
            $f = abj_service('functions');
            $html = $f->str_replace('{theme}', esc_js($theme), $html);
            echo $html;

            $themeVariables = array(
            'mono' => array(
                '--abj404-bg' => '#F8FAFC',
                '--abj404-bg-muted' => '#F5F7FA',
                '--abj404-surface' => '#ffffff',
                '--abj404-surface-muted' => '#F1F5F9',
                '--abj404-text' => '#111827',
                '--abj404-text-muted' => '#6B7280',
                '--abj404-border' => '#E5E7EB',
                '--abj404-primary' => '#374151',
                '--abj404-accent' => '#2563EB',
                '--abj404-info' => '#3B82F6',
                '--abj404-success' => '#10B981',
                '--abj404-warning' => '#F59E0B',
                '--abj404-danger' => '#EF4444',
                '--abj404-focus' => '#93C5FD',
                '--abj404-table-header' => '#F1F5F9',
                '--abj404-row-hover' => '#F5F7FA',
                '--abj404-row-selected' => '#DBEAFE',
                '--abj404-badge-bg' => '#EFF1F5',
                '--abj404-badge-text' => '#374151',
            ),
            'calm' => array(
                '--abj404-bg' => '#F7FAFD',
                '--abj404-bg-muted' => '#F1F6FE',
                '--abj404-surface' => '#ffffff',
                '--abj404-surface-muted' => '#E9F0FB',
                '--abj404-text' => '#17223B',
                '--abj404-text-muted' => '#5A6B86',
                '--abj404-border' => '#E1E8F5',
                '--abj404-primary' => '#1E6BD6',
                '--abj404-accent' => '#00A27A',
                '--abj404-info' => '#2B8AE2',
                '--abj404-success' => '#20B67A',
                '--abj404-warning' => '#F6A700',
                '--abj404-danger' => '#D53F3F',
                '--abj404-focus' => '#5AA2FF',
                '--abj404-table-header' => '#E9F0FB',
                '--abj404-row-hover' => '#F1F6FE',
                '--abj404-row-selected' => '#D7E8FF',
                '--abj404-badge-bg' => '#EEF2F8',
                '--abj404-badge-text' => '#3E546E',
            ),
            'neon' => array(
                '--abj404-bg' => '#0C0F13',
                '--abj404-bg-muted' => '#11151A',
                '--abj404-surface' => '#151A21',
                '--abj404-surface-muted' => '#1B222B',
                '--abj404-text' => '#E5EAF2',
                '--abj404-text-muted' => '#A6B0C3',
                '--abj404-border' => '#273141',
                '--abj404-primary' => '#7C3AED',
                '--abj404-accent' => '#22D3EE',
                '--abj404-info' => '#60A5FA',
                '--abj404-success' => '#34D399',
                '--abj404-warning' => '#F59E0B',
                '--abj404-danger' => '#F87171',
                '--abj404-focus' => '#38BDF8',
                '--abj404-table-header' => '#1F2732',
                '--abj404-row-hover' => '#192028',
                '--abj404-row-selected' => '#0E2936',
                '--abj404-badge-bg' => '#202734',
                '--abj404-badge-text' => '#CFD8E6',
            ),
            'obsidian' => array(
                '--abj404-bg' => '#0A0F1A',
                '--abj404-bg-muted' => '#0E1522',
                '--abj404-surface' => '#121826',
                '--abj404-surface-muted' => '#172032',
                '--abj404-text' => '#E6ECF7',
                '--abj404-text-muted' => '#A9B7CC',
                '--abj404-border' => '#223149',
                '--abj404-primary' => '#1D4ED8',
                '--abj404-accent' => '#A78BFA',
                '--abj404-info' => '#60A5FA',
                '--abj404-success' => '#22C55E',
                '--abj404-warning' => '#F59E0B',
                '--abj404-danger' => '#EF4444',
                '--abj404-focus' => '#93C5FD',
                '--abj404-table-header' => '#1B253A',
                '--abj404-row-hover' => '#141C2C',
                '--abj404-row-selected' => '#1A2A46',
                '--abj404-badge-bg' => '#1A2438',
                '--abj404-badge-text' => '#DCE6F7',
            ),
        );

            /** @var string $themeKey */
            $themeKey = $theme;
            if (isset($themeVariables[$themeKey])) {
                $cssVars = '';
                foreach ($themeVariables[$themeKey] as $var => $value) {
                    $cssVars .= esc_html($var) . ':' . esc_html($value) . ';';
                }

                $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/criticalThemeCSS.html");
                $f = abj_service('functions');
                $html = $f->str_replace('{css_variables}', $cssVars, $html);
                echo $html;
            }
        } catch (Throwable $e) {
            ABJ_404_Solution_WordPress_Connector::reportAdminRuntimeError('admin_head', $e);
        }
    }
}
