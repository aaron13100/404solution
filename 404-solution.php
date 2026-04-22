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

	Version: 4.1.5
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

	// Boot state: tracks whether the plugin loaded successfully.
	// If a required file is missing or Loader.php fails, these let us show
	// a degraded admin page instead of a fatal error.
	$GLOBALS['abj404_boot_ok'] = false;
	$GLOBALS['abj404_missing_files'] = array();
	$GLOBALS['abj404_boot_error'] = '';

	// Used by multiple classes during early admin initialization (e.g. upgrade/migration paths).
	// This must be defined before any Loader.php initialization that might touch Logging/SynchronizationUtils.
	if (!function_exists('abj404_getUploadsDir')) {
		/** @return string */
		function abj404_getUploadsDir() {
			$uploadsDirArray = wp_upload_dir(null, false);
			$uploadsDir = $uploadsDirArray['basedir'];
			$uploadsDir .= DIRECTORY_SEPARATOR . 'temp_' . ABJ404_PP . DIRECTORY_SEPARATOR;
			return $uploadsDir;
		}
	}

	if (!function_exists('abj404_get_settings_options')) {
		/**
		 * Centralized settings read so call sites don't repeat option-shape checks.
		 *
		 * @return array<string, mixed>
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

/**
 * @param string $class
 * @return void
 */
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

	// Trait dependency pre-check: classes that use require_once for trait files at file
	// scope will cause an uncatchable compile-time fatal if any trait file is missing.
	// Verify all trait files exist BEFORE loading the parent class.
	static $traitDependencies = null;
	if ($traitDependencies === null) {
		// Use __DIR__ (not ABJ404_PATH) to match the classmap's path resolution.
		$inc = __DIR__ . '/includes/';
		$traitDependencies = array(
			'ABJ_404_Solution_View' => array(
				$inc . 'ViewTrait_Shared.php',
				$inc . 'ViewTrait_UI.php',
				$inc . 'ViewTrait_Stats.php',
				$inc . 'ViewTrait_Settings.php',
				$inc . 'ViewTrait_Redirects.php',
				$inc . 'ViewTrait_RedirectsTable.php',
				$inc . 'ViewTrait_Logs.php',
			),
			'ABJ_404_Solution_DataAccess' => array(
				$inc . 'DataAccessTrait_Maintenance.php',
				$inc . 'DataAccessTrait_ViewQueries.php',
				$inc . 'DataAccessTrait_Logs.php',
				$inc . 'DataAccessTrait_Redirects.php',
				$inc . 'DataAccessTrait_Stats.php',
				$inc . 'DataAccessTrait_ErrorClassification.php',
			),
			'ABJ_404_Solution_PluginLogic' => array(
				$inc . 'PluginLogicTrait_UrlNormalization.php',
				$inc . 'PluginLogicTrait_AdminActions.php',
				$inc . 'PluginLogicTrait_ImportExport.php',
				$inc . 'PluginLogicTrait_SettingsUpdate.php',
				$inc . 'PluginLogicTrait_PageOrdering.php',
				$inc . 'PluginLogicTrait_Lifecycle.php',
			),
			'ABJ_404_Solution_SpellChecker' => array(
				$inc . 'SpellCheckerTrait_PostListeners.php',
				$inc . 'SpellCheckerTrait_URLMatching.php',
				$inc . 'SpellCheckerTrait_CandidateFiltering.php',
				$inc . 'SpellCheckerTrait_LevenshteinEngine.php',
			),
			'ABJ_404_Solution_DatabaseUpgradesEtc' => array(
				$inc . 'DatabaseUpgradesEtcTrait_NGram.php',
				$inc . 'DatabaseUpgradesEtcTrait_Maintenance.php',
				$inc . 'DatabaseUpgradesEtcTrait_PluginUpdate.php',
			),
		);
	}

	if (isset($traitDependencies[$class])) {
		foreach ($traitDependencies[$class] as $traitFile) {
			if (!file_exists($traitFile)) {
				$GLOBALS['abj404_missing_files'][] = $traitFile;
				// Don't load the parent class — the compile-time fatal is uncatchable.
				return;
			}
		}
	}

	// Ensure the parent class is loaded first.
	if (array_key_exists($class, $childParentMap)) {
		$parentClass = $childParentMap[$class];
		if (!class_exists($parentClass, false) && array_key_exists($parentClass, $abj404_autoLoaderClassMap)) {
			$parentFile = $abj404_autoLoaderClassMap[$parentClass];
			if (!file_exists($parentFile)) {
				$GLOBALS['abj404_missing_files'][] = $parentFile;
				return;
			}
			require_once $parentFile;
		}
	}

	$classFile = $abj404_autoLoaderClassMap[$class];
	if (!file_exists($classFile)) {
		$GLOBALS['abj404_missing_files'][] = $classFile;
		return;
	}

	require_once $classFile;
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
                $func = $frame['function'];

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
	/**
	 * @param array<string, mixed>|string $atts
	 * @return string
	 */
	function abj404_shortCodeListener($atts) {
		if (!$GLOBALS['abj404_boot_ok']) {
			return '';
		}
		abj404_load_textdomain_if_needed();
	    require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
	    /** @var array<string, mixed> $safeAtts */
	    $safeAtts = is_array($atts) ? $atts : array();
	    return ABJ_404_Solution_ShortCode::shortcodePageSuggestions($safeAtts);
	}

	if (!function_exists('abj404_get_required_runtime_files')) {
		/**
		 * Files required for a healthy runtime/plugin package.
		 * Covers boot-critical PHP files and essential SQL templates.
		 *
		 * @return array<int, string>
		 */
		function abj404_get_required_runtime_files() {
			$inc = ABJ404_PATH . 'includes/';
			return array(
				// Boot-critical
				$inc . 'Loader.php',
				$inc . 'bootstrap.php',
				$inc . 'classmap.php',
				$inc . 'ServiceContainer.php',
				$inc . 'ErrorHandler.php',
				// Core classes
				$inc . 'WordPress_Connector.php',
				$inc . 'Functions.php',
				$inc . 'Logging.php',
				$inc . 'FrontendRequestPipeline.php',
				$inc . 'ImportExportService.php',
				// View + traits
				$inc . 'View.php',
				$inc . 'ViewTrait_Shared.php',
				$inc . 'ViewTrait_UI.php',
				$inc . 'ViewTrait_Stats.php',
				$inc . 'ViewTrait_Settings.php',
				$inc . 'ViewTrait_Redirects.php',
				$inc . 'ViewTrait_RedirectsTable.php',
				$inc . 'ViewTrait_Logs.php',
				// DataAccess + traits
				$inc . 'DataAccess.php',
				$inc . 'DataAccessTrait_Maintenance.php',
				$inc . 'DataAccessTrait_ViewQueries.php',
				$inc . 'DataAccessTrait_Logs.php',
				$inc . 'DataAccessTrait_Redirects.php',
				$inc . 'DataAccessTrait_Stats.php',
				// PluginLogic + traits
				$inc . 'PluginLogic.php',
				$inc . 'PluginLogicTrait_UrlNormalization.php',
				$inc . 'PluginLogicTrait_AdminActions.php',
				$inc . 'PluginLogicTrait_ImportExport.php',
				$inc . 'PluginLogicTrait_SettingsUpdate.php',
				$inc . 'PluginLogicTrait_PageOrdering.php',
				$inc . 'PluginLogicTrait_Lifecycle.php',
				// SpellChecker + traits
				$inc . 'SpellChecker.php',
				$inc . 'SpellCheckerTrait_PostListeners.php',
				$inc . 'SpellCheckerTrait_URLMatching.php',
				$inc . 'SpellCheckerTrait_CandidateFiltering.php',
				$inc . 'SpellCheckerTrait_LevenshteinEngine.php',
				// DatabaseUpgradesEtc + traits
				$inc . 'DatabaseUpgradesEtc.php',
				$inc . 'DatabaseUpgradesEtcTrait_NGram.php',
				$inc . 'DatabaseUpgradesEtcTrait_Maintenance.php',
				$inc . 'DatabaseUpgradesEtcTrait_PluginUpdate.php',
				// SQL templates — all files required for correct operation.
				// A test (SqlFileIntegrityListCompletenessTest) verifies this list
				// stays in sync with the actual files in includes/sql/.
				$inc . 'sql/correctLookupTableIssue.sql',
				$inc . 'sql/createEngineProfilesTable.sql',
				$inc . 'sql/createLogTable.sql',
				$inc . 'sql/createLogsHitsTempTable.sql',
				$inc . 'sql/createLookupTable.sql',
				$inc . 'sql/createNGramCacheTable.sql',
				$inc . 'sql/createPermalinkCacheTable.sql',
				$inc . 'sql/createRedirectConditionsTable.sql',
				$inc . 'sql/createRedirectsTable.sql',
				$inc . 'sql/createSpellingCacheTable.sql',
				$inc . 'sql/createViewCacheTable.sql',
				$inc . 'sql/deleteOldLogs.sql',
				$inc . 'sql/getAdditionalPostData.sql',
				$inc . 'sql/getIDsNeededForPermalinkCache.sql',
				$inc . 'sql/getLogRecords.sql',
				$inc . 'sql/getLogsCount.sql',
				$inc . 'sql/getLogsIDandURL.sql',
				$inc . 'sql/getLogsIDandURLForAjax.sql',
				$inc . 'sql/getMostUnusedRedirects.sql',
				$inc . 'sql/getOrphanedAutoRedirects.sql',
				$inc . 'sql/getPermalinkFromURL.sql',
				$inc . 'sql/getPostsNeedingContentKeywords.sql',
				$inc . 'sql/getPublishedCategories.sql',
				$inc . 'sql/getPublishedImageIDs.sql',
				$inc . 'sql/getPublishedPagesAndPostsIDs.sql',
				$inc . 'sql/getPublishedTags.sql',
				$inc . 'sql/getRedirectsExport.sql',
				$inc . 'sql/getRedirectsForView.sql',
				$inc . 'sql/getRedirectsForViewTempTable.sql',
				$inc . 'sql/getRedirectsWithLogs.sql',
				$inc . 'sql/importDataFromPluginRedirectioner.sql',
				$inc . 'sql/insertPermalinkCache.sql',
				$inc . 'sql/insertSpellingCache.sql',
				$inc . 'sql/logsSetMinLogID.sql',
				$inc . 'sql/migrateToNewLogsTable.sql',
				$inc . 'sql/selectTableEngines.sql',
				$inc . 'sql/updatePermalinkCache.sql',
				$inc . 'sql/updatePermalinkCacheParentPages.sql',
			);
		}
	}

	if (!function_exists('abj404_verify_runtime_integrity')) {
		/**
		 * Validate that required plugin files are present.
		 *
		 * @return array<int, string> Missing file paths.
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
		/** @return void */
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
		/** @return void */
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
		/** @return void */
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

// Minimal shutdown handler: catches compile/parse fatals in plugin files and
// stores them in a transient so the degraded admin page can display the error
// on the next request. This is important for PHP 7.4 where syntax errors in
// required files produce uncatchable E_COMPILE_ERROR.
if (!function_exists('abj404_boot_shutdown_handler')) {
	/** @return void */
	function abj404_boot_shutdown_handler() {
		if ($GLOBALS['abj404_boot_ok']) {
			return;
		}
		$error = error_get_last();
		if ($error === null) {
			return;
		}
		// Only capture fatal/compile errors in our plugin files.
		$fatalTypes = E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR;
		if (!($error['type'] & $fatalTypes)) {
			return;
		}
		$pluginDir = defined('ABJ404_PATH') ? ABJ404_PATH : __DIR__ . '/';
		if (strpos($error['file'], $pluginDir) === false) {
			return;
		}
		$errorInfo = array(
			'message' => $error['message'],
			'file' => $error['file'],
			'line' => $error['line'],
			'type' => $error['type'],
			'time' => time(),
		);
		// Use update_option as a fallback — set_transient might not be available
		// during a fatal shutdown.
		if (function_exists('set_transient')) {
			set_transient('abj404_boot_fatal', $errorInfo, 3600);
		}
	}
}
register_shutdown_function('abj404_boot_shutdown_handler');

// Always load Loader.php to ensure plugin constants (ABJ404_TYPE_404_DISPLAYED,
// ABJ404_STATUS_MANUAL, etc.) are defined in all contexts: admin, REST API, WP-CLI
// eval, and template_redirect. Without this, direct calls to plugin classes via
// wp eval fail with "Undefined constant" errors because Loader.php was previously
// only loaded inside is_admin() — leaving WP-CLI and other non-admin contexts
// without the constants they need.
$__abj404_loader_path = plugin_dir_path( __FILE__ ) . "includes/Loader.php";
if (file_exists($__abj404_loader_path)) {
	try {
		require_once($__abj404_loader_path);
		$GLOBALS['abj404_boot_ok'] = true;
		// Clear any stale boot fatal transient from a previous failed load.
		if (function_exists('delete_transient')) {
			delete_transient('abj404_boot_fatal');
		}
	} catch (\Throwable $e) {
		$GLOBALS['abj404_boot_ok'] = false;
		$GLOBALS['abj404_boot_error'] = $e->getMessage();
		error_log('404 Solution: boot failed — ' . $e->getMessage());
	}
} else {
	$GLOBALS['abj404_boot_ok'] = false;
	$GLOBALS['abj404_missing_files'][] = $__abj404_loader_path;
	$GLOBALS['abj404_boot_error'] = 'Loader.php is missing.';
}
unset($__abj404_loader_path);

if ($GLOBALS['abj404_boot_ok']) {
	// admin
	if (is_admin()) {
		try {
			ABJ_404_Solution_WordPress_Connector::init();
			ABJ_404_Solution_ViewUpdater::init();
		} catch (\Throwable $e) {
			// init() failed — fall through to register the degraded admin page
			// so the user still has a menu item with error details instead of nothing.
			$GLOBALS['abj404_boot_ok'] = false;
			$GLOBALS['abj404_boot_error'] = 'Plugin initialization failed: ' . $e->getMessage();
			add_action('admin_menu', 'abj404_degraded_admin_menu');
			add_action('admin_notices', 'abj404_degraded_admin_notice');
		}
	}

	// REST API — deferred to rest_api_init so DataAccess/PluginLogic are only loaded on actual REST requests.
	add_action('rest_api_init', function() {
		$dao   = ABJ_404_Solution_DataAccess::getInstance();
		$logic = ABJ_404_Solution_PluginLogic::getInstance();
		$restController = new ABJ_404_Solution_RestApiController($dao, $logic);
		$restController->registerRoutes();
	});

	// WP-CLI commands.
	if (defined('WP_CLI') && WP_CLI) {
		add_action('init', function() {
			\WP_CLI::add_command('abj404', 'ABJ_404_Solution_WPCLICommands');
		}, 1);
	}
} elseif (function_exists('is_admin') && is_admin()) {
	// Boot failed — register degraded admin page so the admin sees instructions
	// instead of a white screen or missing menu item.
	add_action('admin_menu', 'abj404_degraded_admin_menu');
	add_action('admin_notices', 'abj404_degraded_admin_notice');
}

// --- Degraded-mode functions (always defined, no plugin class dependencies) ---

if (!function_exists('abj404_degraded_admin_menu')) {
	/** @return void */
	function abj404_degraded_admin_menu() {
		$options = function_exists('get_option') ? get_option('abj404_settings') : false;
		$options = is_array($options) ? $options : array();

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

		$missingFiles = isset($GLOBALS['abj404_missing_files']) ? $GLOBALS['abj404_missing_files'] : array();
		$bootError = isset($GLOBALS['abj404_boot_error']) ? $GLOBALS['abj404_boot_error'] : '';
		$pluginDir = defined('ABJ404_PATH') ? ABJ404_PATH : dirname(__FILE__) . '/';

		// Check for a stored fatal from a previous request.
		$fatalInfo = function_exists('get_transient') ? get_transient('abj404_boot_fatal') : false;

		echo '<div class="wrap">';
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
				$relative = str_replace($pluginDir, '', $file);
				echo '<li><code>' . esc_html($relative) . '</code></li>';
			}
			echo '</ul>';
			echo '</div>';
		}

		if ($bootError !== '') {
			echo '<div class="card" style="max-width:800px;">';
			echo '<h2>Error Details</h2>';
			echo '<pre style="white-space:pre-wrap;word-break:break-all;">' . esc_html($bootError) . '</pre>';
			echo '</div>';
		}

		if (is_array($fatalInfo) && !empty($fatalInfo['message'])) {
			echo '<div class="card" style="max-width:800px;">';
			echo '<h2>Fatal Error (previous request)</h2>';
			$fatalFile = isset($fatalInfo['file']) ? str_replace($pluginDir, '', $fatalInfo['file']) : 'unknown';
			$fatalLine = isset($fatalInfo['line']) ? $fatalInfo['line'] : '?';
			echo '<pre style="white-space:pre-wrap;word-break:break-all;">' . esc_html($fatalInfo['message']) . "\n" . esc_html($fatalFile) . ':' . esc_html((string)$fatalLine) . '</pre>';
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

		echo '</div>'; // .wrap
	}
}

if (!function_exists('abj404_admin_page_callback')) {
	/**
	 * Show one-time admin fatal diagnostics captured during shutdown.
	 *
	 * @return void
	 */
	function abj404_render_last_admin_fatal_notice() {
		if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
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

		$pluginDir = defined('ABJ404_PATH') ? ABJ404_PATH : __DIR__ . '/';
		$fatalFile = isset($fatalInfo['file']) ? str_replace($pluginDir, '', (string)$fatalInfo['file']) : '(unknown file)';
		$fatalLine = isset($fatalInfo['line']) ? (int)$fatalInfo['line'] : 0;

		echo '<div class="wrap">';
		echo '<div class="notice notice-error">';
		echo '<p><strong>404 Solution:</strong> A fatal error occurred while rendering the previous admin request.</p>';
		echo '<details><summary>Show error details</summary>';
		echo '<pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">' .
			esc_html((string)$fatalInfo['message'] . "\n" . $fatalFile . ':' . (string)$fatalLine) .
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

		// The false parameter avoids triggering the autoloader — if View was not
		// loaded during boot, we don't want to attempt loading it again here.
		if (class_exists('ABJ_404_Solution_View', false)) {
			ob_start();
			$renderError = null;
			try {
				ABJ_404_Solution_View::handleMainAdminPageActionAndDisplay();
			} catch (\Throwable $e) {
				$renderError = $e;
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

// ----
// get the plugin priority to use before adding the template_redirect action.
$__abj404_options = abj404_get_settings_options();
$__abj404_redirect_priority_raw = isset($__abj404_options['template_redirect_priority']) && is_scalar($__abj404_options['template_redirect_priority']) ? $__abj404_options['template_redirect_priority'] : 9;
$__abj404_template_redirect_priority = absint($__abj404_redirect_priority_raw);
$__abj404_redirect_all = isset($__abj404_options['redirect_all_requests']) && is_scalar($__abj404_options['redirect_all_requests']) ? (string)$__abj404_options['redirect_all_requests'] : '';
$__abj404_update_suggest = isset($__abj404_options['update_suggest_url']) && is_scalar($__abj404_options['update_suggest_url']) ? (string)$__abj404_options['update_suggest_url'] : '';
$GLOBALS['abj404_frontend_runtime_flags'] = array(
	'redirect_all_requests' => ($__abj404_redirect_all === '1'),
	'update_suggest_url' => ($__abj404_update_suggest === '1'),
);
$__abj404_lang_override = isset($__abj404_options['plugin_language_override']) && is_string($__abj404_options['plugin_language_override']) ? $__abj404_options['plugin_language_override'] : '';
$GLOBALS['abj404_plugin_language_override'] = $__abj404_lang_override;

add_action('template_redirect', 'abj404_404listener', $__abj404_template_redirect_priority);

unset($__abj404_options);
unset($__abj404_template_redirect_priority);
abj404_benchmark_mark_bootstrap_done();
// ---

// 404
if (!function_exists('abj404_404listener')) {
/** @return void */
function abj404_404listener() {
	if (!$GLOBALS['abj404_boot_ok']) {
		return;
	}
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
            $sanitizedOriginal = sanitize_text_field($originalURL);
			$_REQUEST[ABJ404_PP . '_REQUEST_URI'] = $sanitizedOriginal;
            $_REQUEST[ABJ404_PP . '_REQUEST_URI_UPDATE_URL'] = $sanitizedOriginal;
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
    $connector->process404();
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
/** @return void */
function abj404_dailyMaintenanceCronJobListener() {
    try {
        require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404dao->deleteOldRedirectsCron();

        $dbUpgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $dbUpgrades->runDatabaseMaintenanceTasks();
    } catch (\Throwable $e) {
        error_log('404 Solution cron (maintenance): ' . $e->getMessage());
    }
}
}

if (!function_exists('abj404_updateLogsHitsTableListener')) {
/** @return void */
function abj404_updateLogsHitsTableListener() {
    try {
        require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404dao->createRedirectsForViewHitsTable();
    } catch (\Throwable $e) {
        error_log('404 Solution cron (logs/hits): ' . $e->getMessage());
    }
}
}
if (!function_exists('abj404_updatePermalinkCacheListener')) {
/**
 * @param int $maxExecutionTime
 * @param int $executionCount
 * @return void
 */
function abj404_updatePermalinkCacheListener($maxExecutionTime, $executionCount = 1) {
    try {
        require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
        $permalinkCache = ABJ_404_Solution_PermalinkCache::getInstance();
        $permalinkCache->updatePermalinkCache($maxExecutionTime, $executionCount);
    } catch (\Throwable $e) {
        error_log('404 Solution cron (permalink cache): ' . $e->getMessage());
    }
}
}
if (!function_exists('abj404_rebuildNGramCacheListener')) {
/**
 * @param int $offset
 * @return void
 */
function abj404_rebuildNGramCacheListener($offset = 0) {
    try {
        require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
        $dbUpgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $dbUpgrades->rebuildNGramCacheAsync($offset);
    } catch (\Throwable $e) {
        error_log('404 Solution cron (ngram cache): ' . $e->getMessage());
    }
}
}
if (!function_exists('abj404_networkActivationListener')) {
/** @return void */
function abj404_networkActivationListener() {
    try {
        require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
        ABJ_404_Solution_PluginLogic::networkActivationCronHandler();
    } catch (\Throwable $e) {
        error_log('404 Solution cron (network activation): ' . $e->getMessage());
    }
}
}
if (!function_exists('abj404_networkActivationBackgroundListener')) {
/** @return void */
function abj404_networkActivationBackgroundListener() {
    try {
        require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->processMultisiteActivationBatch();
    } catch (\Throwable $e) {
        error_log('404 Solution cron (multisite activation): ' . $e->getMessage());
    }
}
}
if (!function_exists('abj404_networkUpgradeBackgroundListener')) {
/** @return void */
function abj404_networkUpgradeBackgroundListener() {
    try {
        require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
        $upgradesEtc = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
        $upgradesEtc->processMultisiteUpgradeBatch();
    } catch (\Throwable $e) {
        error_log('404 Solution cron (multisite upgrade): ' . $e->getMessage());
    }
}
}
add_action('abj404_cleanupCronAction', 'abj404_dailyMaintenanceCronJobListener');
add_action('abj404_updateLogsHitsTableAction', 'abj404_updateLogsHitsTableListener');
add_action('abj404_updatePermalinkCacheAction', 'abj404_updatePermalinkCacheListener', 10, 2);
add_action('abj404_send_digest', 'abj404_sendDigestCronListener');
if (!function_exists('abj404_sendDigestCronListener')) {
/** @return void */
function abj404_sendDigestCronListener() {
    try {
        require_once(plugin_dir_path( __FILE__ ) . "includes/Loader.php");
        $dao = ABJ_404_Solution_DataAccess::getInstance();
        $logger = ABJ_404_Solution_Logging::getInstance();
        $emailDigest = new ABJ_404_Solution_EmailDigest($dao, $logger);
        $emailDigest->onCronSendDigest();
    } catch (\Throwable $e) {
        error_log('404 Solution cron (email digest): ' . $e->getMessage());
    }
}
}
	add_action('abj404_rebuild_ngram_cache_hook', 'abj404_rebuildNGramCacheListener', 10, 1);
	add_action('abj404_network_activation_hook', 'abj404_networkActivationListener');
	add_action('abj404_network_activation_background', 'abj404_networkActivationBackgroundListener');
	add_action('abj404_network_upgrade_background', 'abj404_networkUpgradeBackgroundListener');

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
/**
 * @param string $locale
 * @param string $domain
 * @return string
 */
function abj404_override_plugin_locale($locale, $domain) {
	// Only override for our plugin's text domain.
	// Use the value cached in $GLOBALS at plugin boot to avoid a redundant get_option() call.
	if ($domain === '404-solution') {
		$override = isset($GLOBALS['abj404_plugin_language_override']) && is_string($GLOBALS['abj404_plugin_language_override']) ? $GLOBALS['abj404_plugin_language_override'] : '';
		if ($override !== '') {
			return $override;
		}
	}
	return $locale;
}
}
add_filter('plugin_locale', 'abj404_override_plugin_locale', 999, 2);

if (!function_exists('abj404_show_runtime_integrity_notice')) {
	/** @return void */
	function abj404_show_runtime_integrity_notice() {
		if (!is_admin() || !current_user_can('manage_options')) {
			return;
		}
		$page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
		if ($page !== ABJ404_PP) {
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
	/** @return void */
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
		$type = isset($notice['type']) ? $notice['type'] : '';
		// Collation issues are developer-level; don't show them to the user.
		if ($type === 'collation') {
			return;
		}
		$guidance = '';
		if ($type === 'disk_full') {
			$guidance = __('Contact your hosting provider. This is usually caused by a database quota, tablespace limit, or full /tmp partition — not necessarily a full disk.', '404-solution');
		} elseif ($type === 'read_only') {
			$guidance = __('Your database is currently in read-only mode. Contact your hosting provider.', '404-solution');
		} elseif ($type === 'query_quota') {
			$guidance = __('Your database query quota was exceeded. This usually resets automatically.', '404-solution');
		} elseif ($type === 'corrupted_temp_table') {
			$guidance = __('A temporary MySQL table was corrupted, usually caused by disk or hardware issues. The plugin cannot repair it. Please contact your hosting provider.', '404-solution');
		} elseif ($type === 'log_table_full') {
			$guidance = __('The 404 Solution log table is full. The plugin automatically trimmed the oldest 1,000 log entries to free space, but logging may still be limited. Please contact your hosting provider about disk space.', '404-solution');
		} elseif ($type === 'stale_permalink_cache') {
			$guidance = __('The permalink cache appears to be empty. Try rebuilding it from the Tools tab, or check that your site has enough disk space.', '404-solution');
		} elseif ($type === 'lock_timeout') {
			$guidance = __('A database lock wait timeout occurred. This is usually caused by another process holding a table lock on your database. It may resolve itself automatically, or contact your hosting provider if it persists.', '404-solution');
		}
		echo '<div class="notice notice-error"><p><strong>404 Solution:</strong> ' . esc_html($notice['message']) . '</p>';
		if ($guidance !== '') {
			echo '<p>' . esc_html($guidance) . '</p>';
		}
		if (!empty($notice['error_string'])) {
			echo '<details><summary>' . esc_html(__('Show database error details', '404-solution')) . '</summary>';
			echo '<pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">' . esc_html($notice['error_string']) . '</pre></details>';
		}
		echo '</div>';
	}
}
add_action('admin_notices', 'abj404_show_plugin_db_notice');

if (!function_exists('abj404_get_simulated_db_latency_ms')) {
	/** @return bool */
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

	/** @return int */
	function abj404_get_simulated_db_latency_ms() {
		if (!abj404_is_local_debug_host()) {
			return 0;
		}
		if (defined('ABJ404_SIMULATED_DB_LATENCY_MS')) {
			return max(0, min(5000, absint(ABJ404_SIMULATED_DB_LATENCY_MS)));
		}
		$value = get_option('abj404_simulated_db_latency_ms', 0);
		return max(0, min(5000, absint(is_scalar($value) ? $value : 0)));
	}
}

if (!function_exists('abj404_show_diagnostic_latency_notice')) {
	/** @return void */
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
/** @return void */
function abj404_loadSomethingWhenWordPressIsReady() {
	// If boot failed (missing files), skip all init that depends on plugin classes.
	if (!$GLOBALS['abj404_boot_ok']) {
		return;
	}

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
