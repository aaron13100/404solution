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
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Additional security: Verify user has permission to delete plugins
if (!current_user_can('activate_plugins')) {
    exit;
}

// Load the Uninstaller class
require_once __DIR__ . '/includes/Uninstaller.php';

// Get saved preferences from the uninstall modal (stored as transient)
$preferences = get_transient('abj404_uninstall_preferences');

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

// Delete the transient (cleanup)
delete_transient('abj404_uninstall_preferences');

// Handle both single-site and multisite installations
if (is_multisite()) {
    // Multisite: Uninstall from all sites in the network
    ABJ_404_Solution_Uninstaller::multisite_uninstall($preferences);
} else {
    // Single site: Standard uninstall
    ABJ_404_Solution_Uninstaller::uninstall($preferences);
}

// All done! WordPress will now delete the plugin files.
