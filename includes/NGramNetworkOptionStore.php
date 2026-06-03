<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Network-aware option storage helper for the n-gram cache rebuild
 * subsystem.
 *
 * In a multisite network-activated installation, n-gram rebuild
 * progress (offset, current site, total sites, initialization flag)
 * must be shared across all sites in the network so each scheduled
 * batch sees the same state. In a per-site install, the same options
 * live in `wp_options`.
 *
 * This collaborator picks the right WordPress option API (site option
 * vs option) based on `is_plugin_active_for_network(ABJ404_FILE)` and
 * surfaces a stable get/set contract to callers (scheduler, async
 * batch runner, orchestrator).
 */
class ABJ_404_Solution_NGramNetworkOptionStore {

    /**
     * Returns true when the plugin is network-activated in a multisite
     * install — i.e. one shared activation across all sites.
     *
     * Falls through to false on single-site (no `is_multisite()`).
     *
     * @return bool
     */
    public function isNetworkActivated() {
        if (!is_multisite()) {
            return false;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename(ABJ404_FILE));
    }

    /**
     * Read an option from the network-wide store when network-activated,
     * otherwise from the site-local store.
     *
     * @param string $option_name
     * @param mixed $default
     * @return mixed
     */
    public function getOption($option_name, $default = false) {
        if ($this->isNetworkActivated()) {
            return get_site_option($option_name, $default);
        }
        return get_option($option_name, $default);
    }

    /**
     * Write an option to the network-wide store when network-activated,
     * otherwise to the site-local store.
     *
     * @param string $option_name
     * @param mixed $value
     * @return bool
     */
    public function updateOption($option_name, $value) {
        if ($this->isNetworkActivated()) {
            return update_site_option($option_name, $value);
        }
        return update_option($option_name, $value);
    }
}
