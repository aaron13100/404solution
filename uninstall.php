<?php
/**
 * Uninstall handler for 404 Solution plugin
 *
 * This file is triggered when the plugin is deleted via WordPress admin.
 * It handles cleanup of database tables, options, and cron jobs based on
 * user preferences saved from the uninstall modal.
 *
 * @package 404Solution
 * @since 2.36.11
 */

// Security: Ensure this file is called by WordPress during uninstall
// WordPress core enforces the 'delete_plugins' capability in wp-admin/plugins.php
// before this file is ever reached, so no additional permission checks are needed.
// The WP_UNINSTALL_PLUGIN constant check prevents direct file access and is the
// WordPress-recommended security pattern per the Plugin Handbook.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Additional security: Verify user has permission to delete plugins
// Allow WP-CLI and other automated contexts where current_user_can() returns false
if (!defined('WP_CLI') && !current_user_can('activate_plugins')) {
    exit;
}

// Read version from main plugin file header (single source of truth)
$plugin_data = get_file_data(__DIR__ . '/404-solution.php', array('Version' => 'Version'));
define('ABJ404_VERSION', $plugin_data['Version']);

// Load the Uninstaller class
require_once __DIR__ . '/includes/Uninstaller.php';

// Get saved preferences from the uninstall modal
// Use site option for network-activated plugins, regular option for single-site
$option_name = 'abj404_uninstall_preferences';
$preferences = false;

if (is_multisite()) {
    // Check if the plugin is network-activated
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_file = '404-solution/404-solution.php';
    $is_network_active = is_plugin_active_for_network($plugin_file);

    if ($is_network_active) {
        // Network-activated: Get from site options
        $preferences = get_site_option($option_name);
    } else {
        // Site-specific activation: Get from regular options
        $preferences = get_option($option_name);
    }
} else {
    // Single-site: Get from regular options
    $preferences = get_option($option_name);
}

// If no preferences were saved, use safe defaults
// This happens if user deleted plugin without using the modal
if (false === $preferences || !is_array($preferences)) {
    $preferences = array(
        'delete_redirects' => false,  // Default: KEEP redirects (user might reinstall)
        'delete_logs' => false,       // Default: KEEP logs (historical data)
        'delete_cache' => true,       // Default: DELETE cache (can be rebuilt)
        'send_feedback' => false,     // Default: Don't send feedback
        'uninstall_reason' => '',
        'feedback_email' => '',
        'feedback_details' => ''
    );
}

// Delete the preferences (cleanup)
if (is_multisite() && isset($is_network_active) && $is_network_active) {
    delete_site_option($option_name);
} else {
    delete_option($option_name);
}

// Handle both single-site and multisite installations
if (is_multisite()) {
    // Check if the plugin is network-activated
    // Only run network-wide uninstall if it was activated network-wide
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Determine the plugin file path relative to plugins directory
    $plugin_file = '404-solution/404-solution.php';
    $is_network_active = is_plugin_active_for_network($plugin_file);

    if ($is_network_active) {
        // Network-activated: Uninstall from all sites in the network
        ABJ_404_Solution_Uninstaller::multisite_uninstall($preferences);
    } else {
        // Single-site activation: Only uninstall from current site
        ABJ_404_Solution_Uninstaller::uninstall($preferences);
    }
} else {
    // Single site installation: Standard uninstall
    ABJ_404_Solution_Uninstaller::uninstall($preferences);
}

// All done! WordPress will now delete the plugin files.
