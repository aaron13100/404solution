<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin admin-notice renderers shown only on the plugin's own admin page.
 *
 * abj404_show_runtime_integrity_notice() warns when required plugin files are
 * missing.
 *
 * abj404_show_plugin_db_notice() surfaces user-actionable database errors
 * (never the developer-level collation notices) on the plugin page.
 *
 * The add_action('admin_notices', ...) registrations stay in 404-solution.php;
 * this file only defines the renderers.
 */
// allow-no-test-found: boot-time admin_notices global renderers wired via add_action in 404-solution.php; no same-named unit file. abj404_show_plugin_db_notice (and the other notice renderers) are exercised in CollationAutoRecoveryTest.

if (!function_exists('abj404_show_plugin_db_notice')) {
	/** @return void */
	function abj404_show_plugin_db_notice() {
		if (!is_admin() || !abj404_current_user_is_plugin_admin()) {
			return;
		}
		$page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
		if ($page !== ABJ404_PP) {
			return;
		}
		$notice = get_transient('abj404_plugin_db_notice');
		if (!is_array($notice)) {
			return;
		}
		$type = isset($notice['type']) ? $notice['type'] : '';
		// Collation issues are developer-level; don't show them to the user.
		if ($type === 'collation') {
			return;
		}
		$noticeMessage = isset($notice['message']) && is_string($notice['message']) ? $notice['message'] : '';
		if ($noticeMessage === '') {
			return;
		}
		$guidance = isset($notice['guidance']) && is_string($notice['guidance']) ? $notice['guidance'] : '';
		echo '<div class="notice notice-error"><p><strong>404 Solution:</strong> ' . esc_html($noticeMessage) . '</p>';
		if ($guidance !== '') {
			echo '<p>' . esc_html($guidance) . '</p>';
		}
		if (!empty($notice['error_string'])) {
			$errorString = is_string($notice['error_string']) ? $notice['error_string'] : '';
			echo '<details><summary>' . esc_html(__('Show database error details', '404-solution')) . '</summary>';
			echo '<pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">' . esc_html($errorString) . '</pre></details>';
		}
		echo '</div>';
	}
}

if (!function_exists('abj404_show_runtime_integrity_notice')) {
	/** @return void */
	function abj404_show_runtime_integrity_notice() {
		if (!is_admin() || !abj404_current_user_is_plugin_admin()) {
			return;
		}
		$page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
		if ($page !== ABJ404_PP) {
			return;
		}
		$missing = get_transient('abj404_runtime_missing_files');
		if (!is_array($missing) || count($missing) === 0) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>404 Solution:</strong> ';
		echo esc_html(__('Some required plugin files are missing. Please reinstall the plugin package.', '404-solution'));
		$missingBasenames = array();
		foreach ($missing as $missingPath) {
			if (is_string($missingPath)) {
				$missingBasenames[] = basename($missingPath);
			}
		}
		echo '</p><p><code>' . esc_html(implode(', ', $missingBasenames)) . '</code></p></div>';
	}
}
