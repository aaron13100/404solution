<?php


if (!defined('ABSPATH')) {
    exit;
}

/*
	Plugin Name: 404 Solution
	Plugin URI:  https://www.ajexperience.com/404-solution/
	Description: The smartest 404 plugin - uses intelligent matching and spell-checking to find what visitors were actually looking for, not just redirect to homepage
	Author:      Aaron J
	Author URI:  https://www.ajexperience.com/404-solution/

	Version: 3.2.0
	Requires at least: 5.0
	Requires PHP: 7.4

	License: GPL-3.0-or-later
	License URI: https://www.gnu.org/licenses/gpl-3.0.html
	Domain Path: /languages
	Text Domain: 404-solution

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Guard constant definitions so unit tests (and unusual loaders) can define them first.
if (!defined('ABJ404_PP')) {
	define('ABJ404_PP', 'abj404_solution');
}
if (!defined('ABJ404_FILE')) {
	define('ABJ404_FILE', __FILE__);
}
if (!defined('ABJ404_PATH')) {
	define('ABJ404_PATH', plugin_dir_path(ABJ404_FILE));
}
	if (!defined('ABJ404_SHORTCODE_NAME')) {
		define('ABJ404_SHORTCODE_NAME', 'abj404_solution_page_suggestions');
	}
	if (!isset($GLOBALS['abj404_display_errors'])) {
		$GLOBALS['abj404_display_errors'] = false;
	}

	// Used by multiple classes during early admin initialization (e.g. upgrade/migration paths).
	// This must be defined before any Loader.php initialization that might touch Logging/SynchronizationUtils.
	if (!function_exists('abj404_getUploadsDir')) {
		function abj404_getUploadsDir() {
			$uploadsDirArray = wp_upload_dir(null, false);
			$uploadsDir = $uploadsDirArray['basedir'] ?? '';
			$uploadsDir .= DIRECTORY_SEPARATOR . 'temp_' . ABJ404_PP . DIRECTORY_SEPARATOR;
			return $uploadsDir;
		}
	}

	if (!function_exists('abj404_get_settings_options')) {
		/**
		 * Centralized settings read so call sites don't repeat option-shape checks.
		 *
		 * @return array
		 */
		function abj404_get_settings_options() {
			$options = get_option('abj404_settings');
			return is_array($options) ? $options : array();
		}
	}

// Debug whitelist - only includes localhost/development environments by default
// WARNING: Only add trusted domains to this list. External domains could be a security risk.
// This list is used to enable detailed error logging for debugging purposes.
$GLOBALS['abj404_whitelist'] = array('127.0.0.1', '::1', 'localhost');

// Allow filtering the whitelist for advanced users who need to add custom domains
// Usage: add_filter('abj404_debug_whitelist', function($whitelist) { $whitelist[] = 'yourdomain.com'; return $whitelist; });
if (has_filter('abj404_debug_whitelist')) {
    $GLOBALS['abj404_whitelist'] = apply_filters('abj404_debug_whitelist', $GLOBALS['abj404_whitelist']);
}

function abj404_autoloader($class) {
	// some people were having issues with possibly parent classes not being loaded before their children.
	$childParentMap = [
		'ABJ_404_Solution_FunctionsMBString' => 'ABJ_404_Solution_Functions',
		'ABJ_404_Solution_FunctionsPreg' => 'ABJ_404_Solution_Functions',
	];

	// only pay attention if it's for us. don't bother for other things.
	if (substr($class, 0, 16) !== 'ABJ_404_Solution') {
		return;
	}

	// Use a deterministic classmap to avoid runtime glob() scans on real sites.
	static $abj404_autoLoaderClassMap = null;
	if ($abj404_autoLoaderClassMap === null) {
		$mapFile = __DIR__ . '/includes/classmap.php';
		$abj404_autoLoaderClassMap = file_exists($mapFile) ? require $mapFile : array();
	}

	if (!array_key_exists($class, $abj404_autoLoaderClassMap)) {
		return;
	}

	// Ensure the parent class is loaded first.
	if (array_key_exists($class, $childParentMap)) {
		$parentClass = $childParentMap[$class];
		if (!class_exists($parentClass, false) && array_key_exists($parentClass, $abj404_autoLoaderClassMap)) {
			require_once $abj404_autoLoaderClassMap[$parentClass];
		}
	}

	require_once $abj404_autoLoaderClassMap[$class];
}
spl_autoload_register('abj404_autoloader');

add_action('doing_it_wrong_run', function($function_name, $message, $version) {
	if (strpos($message, '404-solution') !== false &&
		$function_name == '_load_textdomain_just_in_time') {
		
        try {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

            // Prepare the plugin path from ABJ404_FILE
            $pluginPath = trailingslashit(plugin_dir_path(ABJ404_FILE)); // e.g., /var/www/html/wp-content/plugins/404-solution/

			$logMessage = '';
            $isOurPlugin = false;

            foreach ($backtrace as $index => $frame) {
                $file = isset($frame['file']) ? $frame['file'] : '[internal function]';
                $line = isset($frame['line']) ? $frame['line'] : '';
                $func = isset($frame['function']) ? $frame['function'] : '[unknown function]';

                if (!$isOurPlugin && is_string($file) && strpos($file, $pluginPath) !== false) {
                    $isOurPlugin = true;
                }

                $logMessage .= "#$index $func at [$file:$line]\n";
            }

            if ($isOurPlugin) {
				$header = "=== Detected Early Translation ===\n" .
					"Function: $function_name\n" .
					"Message: $message\n" .
					"Version: $version\n";
  
				if (!isset($GLOBALS['abj404_pending_errors'])) {
					$GLOBALS['abj404_pending_errors'] = [];
				}
				$GLOBALS['abj404_pending_errors'][] = $header . $logMessage;
			}

        } catch (Throwable $e) {
            // error_log('Failed to log early translation stack trace: ' . $e->getMessage());
        }
    }
}, 10, 3);

// shortcode
add_shortcode(ABJ404_SHORTCODE_NAME, 'abj404_shortCodeListener');
if (!function_exists('abj404_shortCodeListener')) {
	function abj404_shortCodeListener($atts) {
		abj404_load_textdomain_if_needed();
	    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	    return ABJ_404_Solution_ShortCode::shortcodePageSuggestions($atts);
	}

	if (!function_exists('abj404_get_required_runtime_files')) {
		/**
		 * Files required for a healthy runtime/plugin package.
		 *
		 * @return array
		 */
		function abj404_get_required_runtime_files() {
			return array(
				ABJ404_PATH . 'includes/sql/getPermalinkFromURL.sql',
				ABJ404_PATH . 'includes/sql/getRedirectsForView.sql',
				ABJ404_PATH . 'includes/sql/createRedirectsTable.sql',
				ABJ404_PATH . 'includes/sql/createLogTable.sql',
				ABJ404_PATH . 'includes/WordPress_Connector.php',
				ABJ404_PATH . 'includes/FrontendRequestPipeline.php',
				ABJ404_PATH . 'includes/ImportExportService.php',
			);
		}
	}

	if (!function_exists('abj404_verify_runtime_integrity')) {
		/**
		 * Validate that required plugin files are present.
		 *
		 * @return array Missing file paths.
		 */
		function abj404_verify_runtime_integrity() {
			$missing = array();
			foreach (abj404_get_required_runtime_files() as $path) {
				if (!file_exists($path)) {
					$missing[] = $path;
				}
			}
			return $missing;
		}
	}

	if (!function_exists('abj404_is_benchmark_request')) {
		/**
		 * Benchmark instrumentation is disabled by default and only enabled per-request.
		 *
		 * @return bool
		 */
		function abj404_is_benchmark_request() {
			return isset($_GET['abj404_bench']) && (string)$_GET['abj404_bench'] === '1';
		}
	}

	if (!function_exists('abj404_benchmark_bootstrap_start')) {
		function abj404_benchmark_bootstrap_start() {
			if (!abj404_is_benchmark_request()) {
				return;
			}
			if (!isset($GLOBALS['abj404_benchmark_state']) || !is_array($GLOBALS['abj404_benchmark_state'])) {
				$GLOBALS['abj404_benchmark_state'] = array(
					'start' => microtime(true),
					'bootstrap_done' => 0.0,
					'db_query_count' => 0,
					'db_query_ms' => 0.0,
					'redirect_lookup_ms' => 0.0,
				);
			}
		}
	}

	if (!function_exists('abj404_benchmark_mark_bootstrap_done')) {
		function abj404_benchmark_mark_bootstrap_done() {
			if (!abj404_is_benchmark_request() || !isset($GLOBALS['abj404_benchmark_state'])) {
				return;
			}
			$GLOBALS['abj404_benchmark_state']['bootstrap_done'] = microtime(true);
		}
	}

	if (!function_exists('abj404_benchmark_record_db_query')) {
		/**
		 * @param float $elapsedMs
		 * @return void
		 */
		function abj404_benchmark_record_db_query($elapsedMs) {
			if (!abj404_is_benchmark_request() || !isset($GLOBALS['abj404_benchmark_state'])) {
				return;
			}
			$elapsedMs = max(0.0, (float)$elapsedMs);
			$GLOBALS['abj404_benchmark_state']['db_query_count']++;
			$GLOBALS['abj404_benchmark_state']['db_query_ms'] += $elapsedMs;
		}
	}

	if (!function_exists('abj404_benchmark_record_redirect_lookup')) {
		/**
		 * @param float $elapsedMs
		 * @return void
		 */
		function abj404_benchmark_record_redirect_lookup($elapsedMs) {
			if (!abj404_is_benchmark_request() || !isset($GLOBALS['abj404_benchmark_state'])) {
				return;
			}
			$GLOBALS['abj404_benchmark_state']['redirect_lookup_ms'] += max(0.0, (float)$elapsedMs);
		}
	}

	if (!function_exists('abj404_benchmark_emit_headers')) {
		function abj404_benchmark_emit_headers() {
			if (!abj404_is_benchmark_request() || headers_sent() || !isset($GLOBALS['abj404_benchmark_state'])) {
				return;
			}
			$state = $GLOBALS['abj404_benchmark_state'];
			$start = isset($state['start']) ? (float)$state['start'] : 0.0;
			$bootstrapDone = isset($state['bootstrap_done']) ? (float)$state['bootstrap_done'] : 0.0;
			$now = microtime(true);
			$totalMs = ($start > 0.0) ? (($now - $start) * 1000.0) : 0.0;
			$bootstrapMs = ($start > 0.0 && $bootstrapDone > 0.0) ? (($bootstrapDone - $start) * 1000.0) : 0.0;
			$dbQueryCount = isset($state['db_query_count']) ? (int)$state['db_query_count'] : 0;
			$dbQueryMs = isset($state['db_query_ms']) ? (float)$state['db_query_ms'] : 0.0;
			$redirectLookupMs = isset($state['redirect_lookup_ms']) ? (float)$state['redirect_lookup_ms'] : 0.0;

			header(
				'X-ABJ404-Benchmark: ' .
				'total_ms=' . round($totalMs, 3) . ';' .
				'bootstrap_ms=' . round($bootstrapMs, 3) . ';' .
				'db_query_count=' . $dbQueryCount . ';' .
				'db_query_ms=' . round($dbQueryMs, 3) . ';' .
				'redirect_lookup_ms=' . round($redirectLookupMs, 3)
			);
		}
	}

	abj404_benchmark_bootstrap_start();
	if (abj404_is_benchmark_request()) {
		add_action('send_headers', 'abj404_benchmark_emit_headers', PHP_INT_MAX);
	}
}

// admin
if (is_admin()) {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	ABJ_404_Solution_WordPress_Connector::init();
	ABJ_404_Solution_ViewUpdater::init();
}

// ----
// get the plugin priority to use before adding the template_redirect action.
$__abj404_options = abj404_get_settings_options();
$__abj404_template_redirect_priority = absint($__abj404_options['template_redirect_priority'] ?? 9);
$GLOBALS['abj404_frontend_runtime_flags'] = array(
	'redirect_all_requests' => (is_array($__abj404_options) &&
		array_key_exists('redirect_all_requests', $__abj404_options) &&
		(string)$__abj404_options['redirect_all_requests'] === '1'),
	'update_suggest_url' => (is_array($__abj404_options) &&
		array_key_exists('update_suggest_url', $__abj404_options) &&
		(string)$__abj404_options['update_suggest_url'] === '1'),
);
$GLOBALS['abj404_plugin_language_override'] = (
	is_array($__abj404_options) &&
	!empty($__abj404_options['plugin_language_override'])
) ? (string)$__abj404_options['plugin_language_override'] : '';

add_action('template_redirect', 'abj404_404listener', $__abj404_template_redirect_priority);

unset($__abj404_options);
unset($__abj404_template_redirect_priority);
abj404_benchmark_mark_bootstrap_done();
// ---

// 404
if (!function_exists('abj404_404listener')) {
function abj404_404listener() {
	$is404 = is_404();
	if (!$is404) {
        // Performance: do NOT load the whole plugin on every frontend request unless we must.
    	if (!empty($GLOBALS['abj404_frontend_runtime_flags']['redirect_all_requests'])) {
    		require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    		$connector = ABJ_404_Solution_WordPress_Connector::getInstance();
    		$connector->processRedirectAllRequests();
    		return;
    	}

		$updateSuggestEnabled = !empty($GLOBALS['abj404_frontend_runtime_flags']['update_suggest_url']);
		$cookieName404 = ABJ404_PP . '_STATUS_404';
		$has404StatusCookie = (isset($_COOKIE[$cookieName404]) && $_COOKIE[$cookieName404] == 'true');

		// Fast path: if none of the non-404 features are active, bail immediately.
		if (!$updateSuggestEnabled && !$has404StatusCookie) {
			return;
		}

    	/** If we're currently redirecting to a custom 404 page and we are about to show page
    	 * suggestions then update the URL displayed to the user. */
    	$cookieName = ABJ404_PP . '_REQUEST_URI_UPDATE_URL';
    	$queryParamName = ABJ404_PP . '_ref';

    	$hasUpdateCookie = !empty($_COOKIE[$cookieName]);
    	$hasUpdateParam = !empty($_GET[$queryParamName]);

    	// Fast path: nothing pending from prior plugin-driven redirects.
    	if (!$hasUpdateCookie && !$hasUpdateParam && !$has404StatusCookie) {
    		return;
    	}

    	if ($has404StatusCookie) {
   			// clear the cookie
    		setcookie($cookieName404, 'false', time() - 5, "/");
    		// we're going to a custom 404 page so set the status to 404.
	    	status_header(404);
    	}

    	if (!$updateSuggestEnabled) {
    		return;
    	}

    	// Check cookie first, then query param fallback (for 301 redirects where cookies don't survive)
    	$originalURL = null;
    	if ($hasUpdateCookie) {
    		$originalURL = $_COOKIE[$cookieName];
    	} elseif ($hasUpdateParam) {
    		$originalURL = urldecode($_GET[$queryParamName]);
    	}

    	if ($originalURL !== null) {
			// clear the cookie - sanitize before writing to $_REQUEST
			$_REQUEST[ABJ404_PP . '_REQUEST_URI'] = sanitize_text_field($originalURL);
			setcookie($cookieName, '', time() - 5, "/");

			require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
			add_action('wp_head', 'ABJ_404_Solution_ShortCode::updateURLbarIfNecessary');
    	}
		return;
    }

	// ignore admin screens and login requests on 404 processing path.
	// $_SERVER['SCRIPT_NAME'] is not guaranteed (CLI, some test runners, some proxies).
	// Use a direct script-name check to avoid invoking wp_login_url() filters.
	$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
	$requestUri = $_SERVER['REQUEST_URI'] ?? '';
	$isLoginScreen = (
		($scriptName !== '' && stripos($scriptName, 'wp-login.php') !== false) ||
		($requestUri !== '' && stripos($requestUri, 'wp-login.php') !== false)
	);
	if (is_admin() || $isLoginScreen) {
		return;
	}

    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    $connector = ABJ_404_Solution_WordPress_Connector::getInstance();
    return $connector->process404();
}
}

if (!function_exists('abj404_is_redirect_all_requests_enabled')) {
	/**
	 * Small helper for testability and to keep option-parsing logic consistent.
	 *
	 * @param mixed $options Value returned by get_option('abj404_settings')
	 * @return bool
	 */
	function abj404_is_redirect_all_requests_enabled($options) {
		return is_array($options) &&
			array_key_exists('redirect_all_requests', $options) &&
			(string)$options['redirect_all_requests'] === '1';
	}
}

if (!function_exists('abj404_dailyMaintenanceCronJobListener')) {
function abj404_dailyMaintenanceCronJobListener() {
    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
    $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
    $abj404dao->deleteOldRedirectsCron();

    $dbUpgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
    $dbUpgrades->runDatabaseMaintenanceTasks();
}
}

if (!function_exists('abj404_updateLogsHitsTableListener')) {
function abj404_updateLogsHitsTableListener() {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();
	$abj404dao->createRedirectsForViewHitsTable();
}
}
if (!function_exists('abj404_updatePermalinkCacheListener')) {
function abj404_updatePermalinkCacheListener($maxExecutionTime, $executionCount = 1) {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	$permalinkCache = ABJ_404_Solution_PermalinkCache::getInstance();
	$permalinkCache->updatePermalinkCache($maxExecutionTime, $executionCount);
}
}
if (!function_exists('abj404_rebuildNGramCacheListener')) {
function abj404_rebuildNGramCacheListener($offset = 0) {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	$dbUpgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
	$dbUpgrades->rebuildNGramCacheAsync($offset);
}
}
if (!function_exists('abj404_networkActivationListener')) {
function abj404_networkActivationListener() {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	ABJ_404_Solution_PluginLogic::networkActivationCronHandler();
}
}
if (!function_exists('abj404_networkActivationBackgroundListener')) {
function abj404_networkActivationBackgroundListener() {
	require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	$upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
	$upgradesEtc->processMultisiteActivationBatch();
}
}
add_action('abj404_cleanupCronAction', 'abj404_dailyMaintenanceCronJobListener');
add_action('abj404_updateLogsHitsTableAction', 'abj404_updateLogsHitsTableListener');
add_action('abj404_updatePermalinkCacheAction', 'abj404_updatePermalinkCacheListener', 10, 2);
	add_action('abj404_rebuild_ngram_cache_hook', 'abj404_rebuildNGramCacheListener', 10, 1);
	add_action('abj404_network_activation_hook', 'abj404_networkActivationListener');
	add_action('abj404_network_activation_background', 'abj404_networkActivationBackgroundListener');

	/**
	 * Override the locale for this plugin if user has configured a language override.
	 * This allows users to use a different language for the 404 Solution plugin
 * than their WordPress site language or user language preference.
 *
 * @param string $locale The current locale.
 * @param string $domain The text domain.
 * @return string The locale to use for translation loading.
 */
if (!function_exists('abj404_override_plugin_locale')) {
function abj404_override_plugin_locale($locale, $domain) {
	// Only override for our plugin's text domain
	if ($domain === '404-solution') {
		$options = abj404_get_settings_options();

		// Check if language override is set and not empty
		if (is_array($options) && !empty($options['plugin_language_override'])) {
			return $options['plugin_language_override'];
		}
	}
	return $locale;
}
}
add_filter('plugin_locale', 'abj404_override_plugin_locale', 999, 2);

if (!function_exists('abj404_show_runtime_integrity_notice')) {
	function abj404_show_runtime_integrity_notice() {
		if (!is_admin() || !current_user_can('manage_options')) {
			return;
		}
		$missing = get_transient('abj404_runtime_missing_files');
		if (!is_array($missing) || count($missing) === 0) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>404 Solution:</strong> ';
		echo esc_html(__('Some required plugin files are missing. Please reinstall the plugin package.', '404-solution'));
		echo '</p><p><code>' . esc_html(implode(', ', array_map('basename', $missing))) . '</code></p></div>';
	}
}
add_action('admin_notices', 'abj404_show_runtime_integrity_notice');

if (!function_exists('abj404_show_plugin_db_notice')) {
	function abj404_show_plugin_db_notice() {
		if (!is_admin() || !current_user_can('manage_options')) {
			return;
		}
		$page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
		if ($page !== ABJ404_PP) {
			return;
		}
		$notice = get_transient('abj404_plugin_db_notice');
		if (!is_array($notice) || empty($notice['message'])) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>404 Solution:</strong> ' . esc_html($notice['message']) . '</p></div>';
	}
}
add_action('admin_notices', 'abj404_show_plugin_db_notice');

if (!function_exists('abj404_get_simulated_db_latency_ms')) {
	function abj404_is_local_debug_host() {
		$serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '');
		$serverName = strtolower(trim((string)$serverName));
		if ($serverName === '') {
			return false;
		}

		$normalizedHost = $serverName;
		if (strpos($normalizedHost, '[') === 0) {
			$endBracket = strpos($normalizedHost, ']');
			if ($endBracket !== false) {
				$normalizedHost = substr($normalizedHost, 1, $endBracket - 1);
			}
		} else {
			$colonCount = substr_count($normalizedHost, ':');
			if ($colonCount === 1 && preg_match('/:\d+$/', $normalizedHost)) {
				$normalizedHost = preg_replace('/:\d+$/', '', $normalizedHost);
			}
		}

		$normalizedHost = rtrim((string)$normalizedHost, '.');
		return in_array($normalizedHost, array('127.0.0.1', '::1', 'localhost'), true);
	}

	function abj404_get_simulated_db_latency_ms() {
		if (!abj404_is_local_debug_host()) {
			return 0;
		}
		if (defined('ABJ404_SIMULATED_DB_LATENCY_MS')) {
			return max(0, min(5000, absint(ABJ404_SIMULATED_DB_LATENCY_MS)));
		}
		$value = get_option('abj404_simulated_db_latency_ms', 0);
		return max(0, min(5000, absint($value)));
	}
}

if (!function_exists('abj404_show_diagnostic_latency_notice')) {
	function abj404_show_diagnostic_latency_notice() {
		// Intentionally no-op. Simulated latency status is shown in the plugin's
		// Tools > Diagnostics card to avoid intrusive floating/global notices.
		return;
	}
}

if (!function_exists('abj404_load_textdomain_if_needed')) {
	/**
	 * Load plugin translations once, lazily.
	 *
	 * @return void
	 */
	function abj404_load_textdomain_if_needed() {
		static $loaded = false;
		if ($loaded) {
			return;
		}

		$override_locale = '';
		if (!empty($GLOBALS['abj404_plugin_language_override'])) {
			$override_locale = (string)$GLOBALS['abj404_plugin_language_override'];
		} else {
			$options = abj404_get_settings_options();
			$override_locale = (is_array($options) && !empty($options['plugin_language_override']))
				? $options['plugin_language_override'] : '';
		}

		if (!empty($override_locale)) {
			$mo_file = ABJ404_PATH . 'languages/404-solution-' . $override_locale . '.mo';
			if (file_exists($mo_file)) {
				load_textdomain('404-solution', $mo_file);
			}
		} else {
			$lang_dir = dirname(plugin_basename(ABJ404_FILE)) . '/languages';
			load_plugin_textdomain('404-solution', false, $lang_dir);
		}

		$loaded = true;
	}
}

if (!function_exists('abj404_maybe_refresh_runtime_integrity_cache')) {
	/**
	 * Refresh runtime integrity cache at most once per TTL window.
	 *
	 * @param int $ttlSeconds
	 * @return void
	 */
	function abj404_maybe_refresh_runtime_integrity_cache($ttlSeconds = 43200) {
		if (!is_admin()) {
			return;
		}

		$checkedRecently = get_transient('abj404_runtime_integrity_checked');
		if ($checkedRecently) {
			return;
		}

		$missingRuntimeFiles = abj404_verify_runtime_integrity();
		if (count($missingRuntimeFiles) > 0) {
			set_transient('abj404_runtime_missing_files', $missingRuntimeFiles, $ttlSeconds);
		} else {
			delete_transient('abj404_runtime_missing_files');
		}

		set_transient('abj404_runtime_integrity_checked', 1, $ttlSeconds);
	}
}

/** This only runs after WordPress is done enqueuing scripts. */
if (!function_exists('abj404_loadSomethingWhenWordPressIsReady')) {
function abj404_loadSomethingWhenWordPressIsReady() {
	$isAdminRequest = is_admin();
	if ($isAdminRequest) {
		abj404_load_textdomain_if_needed();
	}

	// make debugging easier on localhost etc	
	if ($isAdminRequest) {
		$serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
		$serverNameIsInTheWhiteList = in_array($serverName, $GLOBALS['abj404_whitelist']);

		// Keep localhost debug helper on admin screens only; frontend requests stay lean.
		if ($serverNameIsInTheWhiteList && function_exists('wp_get_current_user')) {
	    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		if ($abj404logic->userIsPluginAdmin()) {
			$GLOBALS['abj404_display_errors'] = true;
		}
	}
	}

	$action = null;
	if ($isAdminRequest) {
		$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : (isset($_POST['action']) ? sanitize_text_field($_POST['action']) : null);
	}
	if ($isAdminRequest && abj404_is_local_debug_host() && current_user_can('manage_options') && isset($_GET['abj404_set_sim_db_ms'])) {
		$nonceOk = isset($_GET['_wpnonce']) ? wp_verify_nonce($_GET['_wpnonce'], 'abj404_set_sim_db_ms') : false;
		if ($nonceOk) {
			$newMs = max(0, min(5000, absint($_GET['abj404_set_sim_db_ms'])));
			update_option('abj404_simulated_db_latency_ms', $newMs, false);
		}
	}

	$ttl = defined('HOUR_IN_SECONDS') ? (12 * HOUR_IN_SECONDS) : 43200;
	abj404_maybe_refresh_runtime_integrity_cache($ttl);

	if ($isAdminRequest && $action === 'exportRedirects') {
	    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$abj404logic->handleActionExport();
	}
}
}
add_action('admin_init', 'abj404_loadSomethingWhenWordPressIsReady');
