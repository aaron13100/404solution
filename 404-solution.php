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

	Version: 4.3.4
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
// Content-addressed release marker compiled into the earliest boot file.
// DiagnosticModuleManifestTest recomputes it from every covered PHP module.
if (!defined('ABJ404_DIAGNOSTIC_BUILD_ID')) {
define('ABJ404_DIAGNOSTIC_BUILD_ID', 'c27332fdd6d42f1ba2109a879a82e4514fec75b6');
}

// The plugin version is read from this file's own header (single source of
// truth) and is defined HERE rather than in Loader.php because the
// post-upgrade opcache guard below needs it before any other plugin file
// loads. Loader.php keeps its own guarded define for loaders that reach it
// without going through this entry point; it becomes a no-op in normal boots.
// get_file_data() reads from disk, so the version is correct even when this
// entry point is itself being served from stale bytecode.
if (!defined('ABJ404_VERSION') && function_exists('get_file_data')) {
	$__abj404_header = get_file_data(ABJ404_FILE, array('Version' => 'Version'));
	define('ABJ404_VERSION', isset($__abj404_header['Version']) ? $__abj404_header['Version'] : '');
	unset($__abj404_header);
}

// POST-UPGRADE OPCACHE GUARD -- must stay the FIRST plugin file required, ahead
// of every other require and ahead of spl_autoload_register() below.
//
// PHP revalidates cached bytecode per file on its own schedule, so for a few
// seconds after an update the class graph can be MIXED: a file at a path that
// is new in this release compiles fresh from disk while a file whose path did
// not change is still the previous release's bytecode. When a parent and its
// subclass land on opposite sides of that split and a signature changed, PHP
// raises an uncatchable E_ERROR while LINKING them -- which is exactly what
// killed requests on a real 4.2.0 -> 4.3.1 upgrade (see the file header of
// includes/root-boot/OpcacheUpgradeGuard.php for the incident detail).
//
// Because the fatal happens at class-linking time, the flush is only useful if
// it runs before the autoloader can link anything. Anything later (the old
// PluginLogicVersionUpgrader::invalidateOpcacheForCriticalFiles(), which ran
// inside the booted plugin) is structurally incapable of saving the request
// that dies. The call is gated on a persisted version stamp -- the
// `abj404_opcache_version` option, which doubles as the support-visible record
// of when this last ran -- so the work happens once per upgrade, not once per
// request. There is no logger this early in the boot, by construction.
require_once __DIR__ . '/includes/root-boot/OpcacheUpgradeGuard.php';
abj404_opcache_refresh_after_upgrade();

require_once __DIR__ . '/includes/core/PhpErrorLogFallback.php';
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

	// Early settings and path helpers (abj404_getUploadsDir, abj404_get_settings_options).
	// Required before Loader.php because boot-time paths call them before the service
	// container is available. Defined in includes/root-boot/EarlySettingsAccess.php.
	require_once __DIR__ . '/includes/root-boot/EarlySettingsAccess.php';

// Debug whitelist - only includes localhost/development environments by default
// WARNING: Only add trusted domains to this list. External domains could be a security risk.
// This list is used to enable detailed error logging for debugging purposes.
$GLOBALS['abj404_whitelist'] = array('127.0.0.1', '::1', 'localhost');

// Allow filtering the whitelist for advanced users who need to add custom domains
// Usage: add_filter('abj404_debug_whitelist', function($whitelist) { $whitelist[] = 'yourdomain.com'; return $whitelist; });
if (has_filter('abj404_debug_whitelist')) {
    $GLOBALS['abj404_whitelist'] = apply_filters('abj404_debug_whitelist', $GLOBALS['abj404_whitelist']);
}

// The class autoloader (abj404_autoloader) is defined in
// includes/root-boot/Autoloader.php. Required FIRST (plain require, before any
// class use) so the function is available to register below; it loads its
// class->collaborator dependency map from the external data file
// includes/data/autoloader-trait-dependencies.php.
require_once __DIR__ . '/includes/root-boot/Autoloader.php';
spl_autoload_register('abj404_autoloader');

// Boot lifecycle waypoint checkpoints (Bruno timeout cause matrix, gap G3):
// boot_delta_ms used to be the ONLY boot measurement, and it was written at
// trace construction -- AFTER auth and the rate limiter -- so a slow boot and
// a slow auth/DB path were indistinguishable. These checkpoints localize a
// slow boot to a phase instead of a total. Gated to our own table-AJAX and
// canary-ladder requests only (see
// ABJ_404_Solution_AjaxRequestLedger::bootWaypointRequestId()); the frontend
// 404 path is hot and must never pay this write cost.
//
// 'muplugins_loaded' is not separately hooked here: WordPress fires that
// action (wp-settings.php, do_action('muplugins_loaded')) BEFORE a regular
// (non-mu) plugin's file is even require()'d, so a normal plugin can never
// observe it firing -- registering a callback for it here would silently
// never run. This waypoint, the earliest point our own code can reach the
// checkpoint logger, is therefore the honest measurement of that entire
// pre-active-plugin window: mu-plugins, every listener on muplugins_loaded,
// and any plugin that loads ahead of us in the active-plugins list.
ABJ_404_Solution_BootWaypointRecorder::record('boot_plugin_entry', array(
	'module' => '404-solution',
	'path' => __FILE__,
	'build_id' => ABJ404_DIAGNOSTIC_BUILD_ID,
));
add_action('plugins_loaded', static function () {
	ABJ_404_Solution_BootWaypointRecorder::record('plugins_loaded', array(
		'module' => '404-solution',
		'path' => __FILE__,
		'build_id' => ABJ404_DIAGNOSTIC_BUILD_ID,
	));
	// Same-site concurrency census (Bruno timeout cause matrix, gap GC).
	// Registered here rather than at the plugin file's first line because it
	// needs the service container, which Loader.php builds further down this
	// file; 'plugins_loaded' is the earliest hook that is guaranteed to run
	// after that for EVERY request, including the wp-cron loopbacks and the
	// other plugins' admin-ajax polls whose contention is the thing being
	// counted. The census itself decides which requests are in scope (see
	// ABJ_404_Solution_SameSiteRequestCensus::channelForThisRequest); the hot
	// front-end 404 path is not one of them.
	//
	// class_exists() first (Defensive Coding #1): the safe autoloader returns
	// SILENTLY for a missing class file, so an install with a corrupt or
	// partially-updated plugin directory would turn this diagnostic into a
	// fatal on every admin request. A missing diagnostic is the correct
	// degradation; a broken admin screen is not.
	if (class_exists('ABJ_404_Solution_SameSiteRequestCensus')) {
		ABJ_404_Solution_SameSiteRequestCensus::join();
	}
}, PHP_INT_MIN);
add_action('init', static function () {
	ABJ_404_Solution_BootWaypointRecorder::record('init', array(
		'module' => '404-solution',
		'path' => __FILE__,
		'build_id' => ABJ404_DIAGNOSTIC_BUILD_ID,
	));
}, PHP_INT_MIN);
add_action('admin_init', static function () {
	ABJ_404_Solution_BootWaypointRecorder::record('admin_init', array(
		'module' => '404-solution',
		'path' => __FILE__,
		'build_id' => ABJ404_DIAGNOSTIC_BUILD_ID,
	));
}, PHP_INT_MIN);

// Root-boot procedural functions. Each file only DEFINES functions (the
// add_action/add_filter/add_shortcode registrations stay below in this file).
// Requiring them here, right after the autoloader is registered, guarantees
// every function is defined before any top-level executable statement that
// references it runs.
require_once __DIR__ . '/includes/root-boot/RuntimeHelpers.php';
require_once __DIR__ . '/includes/root-boot/RequestBenchmark.php';
require_once __DIR__ . '/includes/root-boot/BootGuard.php';
require_once __DIR__ . '/includes/root-boot/ShortcodeAndIntegrity.php';
require_once __DIR__ . '/includes/root-boot/DegradedSupportRequest.php';
require_once __DIR__ . '/includes/root-boot/CronListeners.php';
require_once __DIR__ . '/includes/root-boot/LocaleAndFrontendOptions.php';
require_once __DIR__ . '/includes/root-boot/AdminNotices.php';
require_once __DIR__ . '/includes/root-boot/LocalDebugDiagnostics.php';
require_once __DIR__ . '/includes/root-boot/AdminInitHandlers.php';
require_once __DIR__ . '/includes/root-boot/AdminPageCallback.php';
require_once __DIR__ . '/includes/root-boot/Frontend404Listener.php';


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
                $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '[internal function]';
                $line = isset($frame['line']) && is_scalar($frame['line']) ? (string)$frame['line'] : '';
                $func = $frame['function'];

                if (!$isOurPlugin && $file !== '[internal function]' && strpos($file, $pluginPath) !== false) {
                    $isOurPlugin = true;
                }

                $logMessage .= "#$index $func at [$file:$line]\n";
            }

            if ($isOurPlugin) {
				$header = "=== Detected Early Translation ===\n" .
					"Function: $function_name\n" .
					"Message: $message\n" .
					"Version: $version\n";

				if (!isset($GLOBALS['abj404_pending_errors']) || !is_array($GLOBALS['abj404_pending_errors'])) {
					$GLOBALS['abj404_pending_errors'] = [];
				}
				$GLOBALS['abj404_pending_errors'][] = $header . $logMessage;
			}

        } catch (Throwable $e) {
            abj404_logRuntimeWarning('Failed to capture early translation stack trace', $e);
        }
    }
}, 10, 3);

// shortcode (abj404_shortCodeListener is defined in
// includes/root-boot/ShortcodeAndIntegrity.php).
add_shortcode(ABJ404_SHORTCODE_NAME, 'abj404_shortCodeListener');

// Benchmark instrumentation functions are defined in
// includes/root-boot/RequestBenchmark.php. Start the per-request timer now and,
// when benchmarking is active, register the response-header emitter. These
// executable statements must run unconditionally (they previously sat inside the
// shortcode-listener function_exists guard).
abj404_benchmark_bootstrap_start();
if (abj404_is_benchmark_request()) {
	add_action('send_headers', 'abj404_benchmark_emit_headers', PHP_INT_MAX);
}

// Boot-failure shutdown handler (abj404_boot_shutdown_handler) is defined in
// includes/root-boot/BootGuard.php. It captures compile/parse fatals in plugin
// files so the degraded admin page can show them on the next request. Registered
// here as part of the boot sequence.
register_shutdown_function('abj404_boot_shutdown_handler');

// Always load Loader.php to ensure plugin constants (ABJ404_TYPE_404_DISPLAYED,
// ABJ404_STATUS_MANUAL, etc.) are defined in all contexts: admin, REST API, WP-CLI
// eval, and template_redirect. Without this, direct calls to plugin classes via
// wp eval fail with "Undefined constant" errors because Loader.php was previously
// only loaded inside is_admin(), leaving WP-CLI and other non-admin contexts
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
		abj404_logRuntimeWarning('Boot failed while loading Loader.php', $e);
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
			ABJ_404_Solution_AjaxAdminEndpointRegistrar::register();
		} catch (\Throwable $e) {
			// init() failed. Fall through to register the degraded admin page
			// so the user still has a menu item with error details instead of nothing.
			$GLOBALS['abj404_boot_ok'] = false;
			$GLOBALS['abj404_boot_error'] = 'Plugin initialization failed: ' . $e->getMessage();
			abj404_logRuntimeWarning('Admin initialization failed', $e);
			add_action('admin_menu', 'abj404_degraded_admin_menu');
			add_action('admin_notices', 'abj404_degraded_admin_notice');
		}
	}

	// REST API: deferred to rest_api_init so DataAccess/PluginLogic are only loaded on actual REST requests.
	add_action('rest_api_init', function() {
		try {
			$dao   = ABJ_404_Solution_DataAccess::getInstance();
			$logic = ABJ_404_Solution_PluginLogic::getInstance();
			$restController = new ABJ_404_Solution_RestApiController($dao, $logic);
			$restController->registerRoutes();
		} catch (\Throwable $e) {
			// rest_api_init is a shared WordPress hook every plugin's REST
			// routes register on; an uncaught error here aborts the whole
			// request and would also block other plugins' route
			// registration, not just ours. A transient failure resolving
			// this plugin's services (the VRMU incident: a class
			// momentarily missing during a plugin self-update racing a
			// live request) must degrade to "skip registering our routes
			// this request" instead.
			abj404_logRuntimeWarning('rest_api_init: route registration failed', $e);
		}
	});

	// WP-CLI commands.
	if (defined('WP_CLI') && WP_CLI) {
		add_action('init', function() {
			\WP_CLI::add_command('abj404', 'ABJ_404_Solution_WPCLICommands');
		}, 1);
	}
} elseif (function_exists('is_admin') && is_admin()) {
	// Boot failed. Register degraded admin page so the admin sees instructions
	// instead of a white screen or missing menu item.
	add_action('admin_menu', 'abj404_degraded_admin_menu');
	add_action('admin_notices', 'abj404_degraded_admin_notice');
	// Best-effort: wire the support-request flow even on the degraded
	// boot path so an admin who lands here can still send a debug report.
	// This is the most valuable placement for the button, because the user
	// often cannot reach the normal plugin UI from this screen. Deferred
	// to plugins_loaded so the function (defined in DegradedSupportRequest.php)
	// is available regardless of source-order.
	if (function_exists('add_action')) {
		add_action('plugins_loaded', 'abj404_degraded_register_support_request');
	}
}

// --- Degraded-mode and admin-page callbacks ---
// abj404_degraded_register_support_request, abj404_degraded_admin_menu,
// abj404_degraded_admin_notice, and abj404_degraded_admin_page are defined in
// includes/root-boot/DegradedSupportRequest.php. abj404_admin_page_callback and
// abj404_render_last_admin_fatal_notice are defined in
// includes/root-boot/AdminPageCallback.php.

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

// abj404_404listener is defined in includes/root-boot/Frontend404Listener.php.
add_action('template_redirect', 'abj404_404listener', $__abj404_template_redirect_priority);

unset($__abj404_options);
unset($__abj404_template_redirect_priority);
abj404_benchmark_mark_bootstrap_done();
// ---

// Cron action registrations. The heavy-prologue cron listeners
// (abj404_dailyMaintenanceCronJobListener, abj404_updateLogsHitsTableListener,
// abj404_sendQueuedReportListener) and the
// secondary cron listeners are all defined in includes/root-boot/CronListeners.php;
// only their add_action wiring lives here so the entry-point self-heal audit and
// the deactivate round-trip tests can discover the hook names in one place.
add_action('abj404_cleanupCronAction', 'abj404_dailyMaintenanceCronJobListener');
add_action('abj404_updateLogsHitsTableAction', 'abj404_updateLogsHitsTableListener');
add_action('abj404_refresh_status_counts', 'abj404_refreshStatusCountsListener', 10, 1);
add_action('abj404_repair_collations', 'abj404_repairCollationsListener');
add_action('abj404_logsv2_canonical_backfill', 'abj404_logsv2CanonicalUrlBackfillListener');
add_action('abj404_redirects_denorm_backfill', 'abj404_redirectsDenormBackfillListener');
add_action('abj404_redirects_sort_key_backfill', 'abj404_redirectsSortKeyBackfillListener');
add_action('abj404_updatePermalinkCacheAction', 'abj404_updatePermalinkCacheListener', 10, 2);
add_action('abj404_send_digest', 'abj404_sendDigestCronListener');
add_action('abj404_send_queued_report', 'abj404_sendQueuedReportListener', 10, 1);
	add_action('abj404_rebuild_ngram_cache_hook', 'abj404_rebuildNGramCacheListener', 10, 1);
	add_action('abj404_network_activation_hook', 'abj404_networkActivationListener');
	add_action('abj404_network_activation_background', 'abj404_networkActivationBackgroundListener');
	add_action('abj404_network_upgrade_background', 'abj404_networkUpgradeBackgroundListener');
	add_action('abj404_gsc_fetch_cron', 'abj404_gscFetchCronListener');
	add_action('abj404_gsc_background_refresh', 'abj404_gscBackgroundRefreshListener');

// abj404_override_plugin_locale is defined in
// includes/root-boot/LocaleAndFrontendOptions.php (along with
// abj404_is_redirect_all_requests_enabled).
add_filter('plugin_locale', 'abj404_override_plugin_locale', 999, 2);

// abj404_show_runtime_integrity_notice and abj404_show_plugin_db_notice are
// defined in includes/root-boot/AdminNotices.php.
add_action('admin_notices', 'abj404_show_runtime_integrity_notice');
add_action('admin_notices', 'abj404_show_plugin_db_notice');

// abj404_is_local_debug_host, abj404_get_simulated_db_latency_ms, and
// abj404_show_diagnostic_latency_notice are defined in
// includes/root-boot/LocalDebugDiagnostics.php.

// abj404_load_textdomain_if_needed, abj404_maybe_refresh_runtime_integrity_cache,
// and abj404_loadSomethingWhenWordPressIsReady are defined in
// includes/root-boot/AdminInitHandlers.php.
add_action('admin_init', 'abj404_loadSomethingWhenWordPressIsReady');
