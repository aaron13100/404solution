<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collects WordPress inventory fields for feedback payloads.
 */
class ABJ_404_Solution_FeedbackWordPressInventory {

    /**
     * @param mixed $wpdb
     * @return string
     */
    public function tablePrefix($wpdb): string {
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix) && is_string($wpdb->prefix)) {
            return $wpdb->prefix;
        }
        return '';
    }

    public function objectCacheStatus(): string {
        return (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) ? 'external' : 'default';
    }

    /**
     * @return array<int, string>
     */
    public function activePlugins(): array {
        if (!function_exists('get_option')) {
            return array();
        }
        $list = get_option('active_plugins', array());
        if (!is_array($list)) {
            return array();
        }
        $out = array();
        foreach ($list as $entry) {
            if (is_string($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    public function activeTheme(): string {
        if (!function_exists('wp_get_theme')) {
            return '';
        }
        $theme = wp_get_theme();
        if (!is_object($theme) || !method_exists($theme, 'get')) {
            return '';
        }
        $rawName = $theme->get('Name');
        $rawVer = $theme->get('Version');
        $name = is_string($rawName) ? trim($rawName) : '';
        $version = is_string($rawVer) ? trim($rawVer) : '';
        if ($name === '' && $version === '') {
            return '';
        }
        return $version === '' ? $name : trim($name . ' ' . $version);
    }
}
