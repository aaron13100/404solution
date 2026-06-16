<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin admin-page callback and its one-time fatal-diagnostics notice.
 *
 * abj404_render_last_admin_fatal_notice() surfaces (once) a fatal captured
 * during a previous admin request's shutdown. abj404_admin_page_callback() is
 * the registered menu callback: it renders the View page, and on any failure
 * (View class not loaded, render threw, or zero output) it shows a diagnostic
 * instead of a blank page, falling back to the degraded admin page when the
 * View class itself is unavailable.
 *
 * The menu registration (which points WordPress at this callback) is performed
 * by ABJ_404_Solution_WordPress_Connector on a successful boot; this file only
 * defines the callbacks. abj404_degraded_admin_page() (the fallback) lives in
 * includes/root-boot/DegradedSupportRequest.php.
 */
// allow-no-test-found: boot-time global admin-page callback wired via the WP admin menu in 404-solution.php; no same-named unit file. The blank-page fallback behavior of abj404_admin_page_callback is exercised in BlankPagePreventionTest (which references the callback directly).

if (!function_exists('abj404_admin_page_callback')) {
	/**
	 * Show one-time admin fatal diagnostics captured during shutdown.
	 *
	 * @return void
	 */
	function abj404_render_last_admin_fatal_notice() {
		if (!abj404_current_user_is_plugin_admin()) {
			return;
		}

		$fatalInfo = function_exists('get_transient') ? get_transient('abj404_admin_fatal') : false;
		if ($fatalInfo === false && function_exists('get_option')) {
			$fatalInfo = get_option('abj404_admin_fatal_fallback', false);
		}
		if (!is_array($fatalInfo) || empty($fatalInfo['message'])) {
			return;
		}

		if (function_exists('delete_transient')) {
			delete_transient('abj404_admin_fatal');
		}
		if (function_exists('delete_option')) {
			delete_option('abj404_admin_fatal_fallback');
		}

		$pluginDir = defined('ABJ404_PATH') ? ABJ404_PATH : dirname(dirname(__DIR__)) . '/';
		$fatalFileRaw = isset($fatalInfo['file']) && is_scalar($fatalInfo['file']) ? (string)$fatalInfo['file'] : '';
		$fatalFile = $fatalFileRaw !== '' ? str_replace($pluginDir, '', $fatalFileRaw) : '(unknown file)';
		$fatalLine = isset($fatalInfo['line']) && is_scalar($fatalInfo['line']) ? (int)$fatalInfo['line'] : 0;
		$fatalMessage = isset($fatalInfo['message']) && is_scalar($fatalInfo['message']) ? (string)$fatalInfo['message'] : '';

		echo '<div class="wrap">';
		echo '<div class="notice notice-error">';
		echo '<p><strong>404 Solution:</strong> A fatal error occurred while rendering the previous admin request.</p>';
		echo '<details><summary>Show error details</summary>';
		echo '<pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">' .
			esc_html($fatalMessage . "\n" . $fatalFile . ':' . (string)$fatalLine) .
			'</pre>';
		echo '</details>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Safe wrapper for the admin page callback. Falls back to the degraded
	 * page if the View class was not loaded during boot.
	 *
	 * @return void
	 */
	function abj404_admin_page_callback() {
		abj404_render_last_admin_fatal_notice();

		// The false parameter avoids triggering the autoloader. If View was not
		// loaded during boot, we don't want to attempt loading it again here.
		if (class_exists('ABJ_404_Solution_View', false)) {
			ob_start();
			$renderError = null;
			try {
				ABJ_404_Solution_View::handleMainAdminPageActionAndDisplay();
			} catch (\Throwable $e) {
				$renderError = $e;
				abj404_logRuntimeWarning('Admin page rendering failed', $e);
			}
			$output = ob_get_clean();

			if ($renderError !== null) {
				echo '<div class="wrap">';
				echo '<div class="notice notice-error">';
				echo '<p><strong>404 Solution:</strong> An error occurred while rendering this page.</p>';
				echo '<details><summary>Show error details</summary>';
				echo '<pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">' . esc_html($renderError->getMessage() . "\n" . $renderError->getTraceAsString()) . '</pre>';
				echo '</details>';
				echo '</div>';
				echo '</div>';
			} elseif ($output === '' || $output === false) {
				// The View class was loaded and didn't throw, but produced zero output.
				// Show a diagnostic instead of a blank page.
				echo '<div class="wrap">';
				echo '<h1>404 Solution</h1>';
				echo '<div class="notice notice-error"><p>';
				echo '<strong>This page produced no output.</strong> ';
				echo 'This can happen when a required dependency failed to initialize or a template file is missing.';
				echo '</p><p>';
				echo 'Try deactivating and reactivating the plugin. If the problem persists, ';
				echo 'delete the plugin and reinstall it from the WordPress plugin directory.';
				echo '</p></div></div>';
			} else {
				echo $output;
			}
		} else {
			abj404_degraded_admin_page();
		}
	}
}
