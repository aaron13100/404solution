<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Degraded-boot admin surface: support-request wiring and the degraded menu.
 *
 * abj404_degraded_register_support_request() wires the "send debug log" AJAX
 * flow on the corrupt-install path so a stuck admin can still reach the
 * developer. abj404_degraded_admin_menu() registers the degraded menu item that
 * points at the corrupt-install diagnostics page.
 *
 * abj404_degraded_admin_notice() and abj404_degraded_admin_page() render the
 * corrupt-install admin notice and diagnostics page (with reinstall guidance and
 * a support-request card). The add_action() registrations that schedule these on
 * the degraded boot path stay in 404-solution.php; this file only defines the
 * functions.
 */
// allow-no-test-found: boot-time degraded-path global functions wired via add_action in 404-solution.php; no same-named unit file. The degraded support-request and admin-page behavior is exercised in BlankPagePreventionTest and OpcacheStaleAfterUpgradeTest (both reference abj404_degraded_*).

if (!function_exists('abj404_degraded_register_support_request')) {
	/**
	 * Wire the support-request flow on the degraded boot path so an admin
	 * stuck on the corrupt-install screen can still send a debug log. The
	 * normal registration runs inside WordPress_Connector::registerAdminHooks()
	 * which only executes on a successful boot; without this helper, the
	 * AJAX handler that the modal POSTs to does not exist on the degraded
	 * path and the click silently 400s.
	 *
	 * Strictly best-effort. Every step is guarded with file_exists() and
	 * class_exists() so a corrupted install that is missing any of the
	 * support-request files just falls back to the mailto link in the
	 * degraded admin page. We must not throw a fatal here, because we
	 * already ARE on the degraded path.
	 *
	 * @return void
	 */
	function abj404_degraded_register_support_request() {
		$inc = ABJ404_PATH . 'includes/';
		$supportFiles = array(
			$inc . 'ajax/AjaxSecurityGate.php',
			$inc . 'feedback/FeedbackTransport.php',
			$inc . 'feedback/SupportRequestButton.php',
			$inc . 'ajax/Ajax_SupportRequest.php',
			$inc . 'ajax/Ajax_SupportRequestPreview.php',
		);
		foreach ($supportFiles as $file) {
			if (!file_exists($file)) {
				return;
			}
		}
		try {
			foreach ($supportFiles as $file) {
				require_once $file;
			}
		// allow-silent-catch: degraded boot path. If any of the support-request files compile-fatals on require we want to fall through to the mailto fallback rather than crash the corrupt-install screen the user is here to read.
		} catch (\Throwable $e) {
			return;
		}
		if (!class_exists('ABJ_404_Solution_Ajax_SupportRequest')
			|| !class_exists('ABJ_404_Solution_Ajax_SupportRequestPreview')) {
			return;
		}
		// Register the AJAX handlers directly. The normal init() path goes
		// through ABJ_404_Solution_WPUtils::safeAddAction() but that helper
		// may itself be missing on a corrupt install; falling back to
		// add_action() avoids that dependency.
		$supportInstance = ABJ_404_Solution_Ajax_SupportRequest::getInstance();
		$previewInstance = ABJ_404_Solution_Ajax_SupportRequestPreview::getInstance();
		add_action('wp_ajax_abj404_support_request', array($supportInstance, 'handleRequest'));
		add_action('wp_ajax_abj404_support_request_preview', array($previewInstance, 'handleRequest'));
		// Enqueue the JS assets on the degraded admin page only. Using a
		// closure keeps this self-contained without registering a new
		// global function on the corrupted boot path.
		add_action('admin_enqueue_scripts', function($hook) use ($inc) {
			$ppSlug = defined('ABJ404_PP') ? ABJ404_PP : 'abj404_solution';
			$isOurPage = is_string($hook) && (
				strpos($hook, $ppSlug) !== false
				|| strpos($hook, 'abj404_solution') !== false
			);
			if (!$isOurPage) {
				return;
			}
			$clientJs = $inc . 'ajax/SupportRequest.js';
			$buttonJs = $inc . 'js/support-request-button.js';
			$transportJs = $inc . 'js/support-request-transport.js';
			$modalViewJs = $inc . 'js/support-request-modal-view.js';
			if (!file_exists($clientJs) || !file_exists($buttonJs)
				|| !file_exists($transportJs) || !file_exists($modalViewJs)) {
				return;
			}
			$baseUrl = plugin_dir_url(ABJ404_FILE) . 'includes/';
			$ver = defined('ABJ404_VERSION') ? ABJ404_VERSION : (string)abj404_now();
			wp_enqueue_script('abj404-support-request-client',
				$baseUrl . 'ajax/SupportRequest.js', array(), $ver, true);
			wp_enqueue_script('abj404-support-request-transport',
				$baseUrl . 'js/support-request-transport.js',
				array('abj404-support-request-client'), $ver, true);
			wp_enqueue_script('abj404-support-request-modal-view',
				$baseUrl . 'js/support-request-modal-view.js', array(), $ver, true);
			wp_enqueue_script('abj404-support-request-button',
				$baseUrl . 'js/support-request-button.js',
				array('abj404-support-request-client', 'abj404-support-request-transport', 'abj404-support-request-modal-view'),
				$ver, true);
			$supportNonce = wp_create_nonce(ABJ_404_Solution_Ajax_SupportRequest::NONCE_ACTION);
			$previewNonce = wp_create_nonce(ABJ_404_Solution_Ajax_SupportRequestPreview::NONCE_ACTION);
			$ajaxUrl = admin_url('admin-ajax.php');
			$payload = wp_json_encode(array(
				'ajaxurl' => $ajaxUrl,
				'nonces' => array(
					'support_request' => $supportNonce,
					'support_request_preview' => $previewNonce,
				),
			));
			$bootstrap = 'window.ABJ404=window.ABJ404||{};Object.assign(window.ABJ404,'
				. (is_string($payload) ? $payload : '{}') . ');';
			wp_add_inline_script('abj404-support-request-client', $bootstrap, 'before');
		});
	}
}

if (!function_exists('abj404_degraded_admin_menu')) {
	/** @return void */
	function abj404_degraded_admin_menu() {
		$options = abj404_get_settings_options();

		$menuName = '404 Solution';
		$badge = " <span class='update-plugins count-1'><span class='plugin-count'>!</span></span>";

		if (isset($options['menuLocation']) && $options['menuLocation'] === 'settingsLevel') {
			add_menu_page('404 Solution', $menuName . $badge, 'manage_options', 'abj404_solution', 'abj404_degraded_admin_page');
		} else {
			$ppSlug = defined('ABJ404_PP') ? ABJ404_PP : 'abj404_solution';
			add_submenu_page('options-general.php', '404 Solution', $menuName . $badge, 'manage_options', $ppSlug, 'abj404_degraded_admin_page');
		}
	}
}

if (!function_exists('abj404_degraded_admin_notice')) {
	/** @return void */
	function abj404_degraded_admin_notice() {
		if (!current_user_can('manage_options')) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>404 Solution:</strong> ';
		echo 'Plugin files are missing or corrupt. ';
		$ppSlug = defined('ABJ404_PP') ? ABJ404_PP : 'abj404_solution';
		echo '<a href="' . esc_url(admin_url('options-general.php?page=' . $ppSlug)) . '">View details</a>';
		echo '</p></div>';
	}
}

if (!function_exists('abj404_degraded_admin_page')) {
	/** @return void */
	function abj404_degraded_admin_page() {
		if (!current_user_can('manage_options')) {
			echo '<div class="wrap">';
			echo '<h1>404 Solution</h1>';
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Permission denied.</strong> ';
			echo 'Your user account does not have permission to access this page.';
			echo '</p><p>';
			echo 'Please verify that your WordPress role has the <code>manage_options</code> capability.';
			echo '</p></div></div>';
			return;
		}

		$missingFiles = isset($GLOBALS['abj404_missing_files']) && is_array($GLOBALS['abj404_missing_files']) ? $GLOBALS['abj404_missing_files'] : array();
		$bootError = isset($GLOBALS['abj404_boot_error']) ? $GLOBALS['abj404_boot_error'] : '';
		$pluginDir = defined('ABJ404_PATH') ? ABJ404_PATH : dirname(dirname(__DIR__)) . '/';

		// Check for a stored fatal from a previous request.
		$fatalInfo = function_exists('get_transient') ? get_transient('abj404_boot_fatal') : false;

		echo '<div class="wrap">';
		// allow-em-dash: &mdash; entity is user-facing HTML output in the corrupt-install page heading, separating the plugin name from the page subtitle.
		echo '<h1>404 Solution &mdash; Plugin Files Missing</h1>';

		echo '<div class="notice notice-error inline"><p>';
		echo '<strong>The 404 Solution plugin cannot start</strong> because one or more required files are missing or corrupt. ';
		echo 'This usually happens after a failed plugin update or incomplete file upload.';
		echo '</p></div>';

		if (!empty($missingFiles)) {
			echo '<div class="card" style="max-width:800px;">';
			echo '<h2>Missing Files</h2>';
			echo '<ul style="list-style:disc;padding-left:20px;">';
			foreach ($missingFiles as $file) {
				// Show relative path for readability.
				$relative = is_string($file) ? str_replace($pluginDir, '', $file) : '';
				echo '<li><code>' . esc_html($relative) . '</code></li>';
			}
			echo '</ul>';
			echo '</div>';
		}

		$bootErrorText = is_scalar($bootError) ? (string)$bootError : '';
		if ($bootErrorText !== '') {
			echo '<div class="card" style="max-width:800px;">';
			echo '<h2>Error Details</h2>';
			echo '<pre style="white-space:pre-wrap;word-break:break-all;">' . esc_html($bootErrorText) . '</pre>';
			echo '</div>';
		}

		if (is_array($fatalInfo) && !empty($fatalInfo['message'])) {
			echo '<div class="card" style="max-width:800px;">';
			echo '<h2>Fatal Error (previous request)</h2>';
			$fatalFileRaw = isset($fatalInfo['file']) && is_scalar($fatalInfo['file']) ? (string)$fatalInfo['file'] : '';
			$fatalFile = $fatalFileRaw !== '' ? str_replace($pluginDir, '', $fatalFileRaw) : 'unknown';
			$fatalLine = isset($fatalInfo['line']) && is_scalar($fatalInfo['line']) ? (string)$fatalInfo['line'] : '?';
			$fatalMessage = isset($fatalInfo['message']) && is_scalar($fatalInfo['message']) ? (string)$fatalInfo['message'] : '';
			echo '<pre style="white-space:pre-wrap;word-break:break-all;">' . esc_html($fatalMessage) . "\n" . esc_html($fatalFile) . ':' . esc_html($fatalLine) . '</pre>';
			echo '</div>';
		}

		echo '<div class="card" style="max-width:800px;">';
		echo '<h2>How to Fix</h2>';
		echo '<ol>';
		echo '<li>Go to <strong>Plugins &rarr; Installed Plugins</strong>, deactivate <strong>404 Solution</strong>, then delete it.</li>';
		echo '<li>Reinstall from the WordPress plugin directory: ';
		$installUrl = admin_url('plugin-install.php?s=404+solution&tab=search');
		echo '<a href="' . esc_url($installUrl) . '" class="button button-primary">Search &ldquo;404 Solution&rdquo;</a>';
		echo '</li>';
		echo '<li>Activate the fresh copy. Your redirects and settings are stored in the database and will not be lost.</li>';
		echo '</ol>';
		echo '</div>';

		// Support-request card. This is the most valuable placement for
		// the "Send debug log to developer" button: an admin who reached
		// this screen needs help and may not be able to navigate the
		// normal plugin UI. When the SupportRequestButton class is
		// present (most corruption is partial), render the mount div so
		// the JS component can take over. Always emit a mailto fallback
		// underneath in case the JS files are themselves among the
		// missing files.
		echo '<div class="card" style="max-width:800px;">';
		echo '<h2>Need help? Contact the developer</h2>';
		echo '<p>Send the developer a one-time diagnostic report including the missing-file list above. Your redirects and settings are not shared.</p>';
		$missingCount = count($missingFiles);
		$contextSummary = 'Corrupt install: ' . $missingCount . ' missing file(s)';
		$bootErrorForSummary = is_scalar($bootError) ? (string)$bootError : '';
		if ($bootErrorForSummary !== '') {
			$contextSummary .= '. Boot error: ' . substr($bootErrorForSummary, 0, 200);
		}
		if (class_exists('ABJ_404_Solution_SupportRequestButton')) {
			echo ABJ_404_Solution_SupportRequestButton::render('system_corrupt_install', $contextSummary);
		}
		$mailEmail = defined('ABJ404_AUTHOR_EMAIL') ? (string)ABJ404_AUTHOR_EMAIL : '404solution@ajexperience.com';
		$mailSubject = rawurlencode('404 Solution: corrupt install report');
		$homeUrlText = '(unknown)';
		if (function_exists('home_url')) {
			$homeUrlVal = home_url();
			$homeUrlText = is_string($homeUrlVal) ? $homeUrlVal : '(unknown)';
		}
		$stringMissingFiles = array();
		foreach ($missingFiles as $entry) {
			$stringMissingFiles[] = is_scalar($entry) ? (string)$entry : '';
		}
		$missingFileLines = implode("\n", $stringMissingFiles);
		$mailBody = rawurlencode("Site URL: " . $homeUrlText . "\n"
			. "Missing files (" . (int)$missingCount . "):\n"
			. $missingFileLines
			. "\n\nBoot error:\n" . $bootErrorText);
		echo '<p style="margin-top:8px;">Or email manually: ';
		echo '<a href="mailto:' . esc_attr($mailEmail) . '?subject=' . $mailSubject . '&body=' . $mailBody . '">';
		echo esc_html($mailEmail) . '</a></p>';
		echo '</div>';

		echo '</div>'; // .wrap
	}
}
