<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * admin_init readiness handlers and the lazy textdomain loader.
 *
 * abj404_load_textdomain_if_needed() loads plugin translations once, lazily,
 * honoring the optional locale override. abj404_maybe_refresh_runtime_integrity_cache()
 * refreshes the missing-files cache at most once per TTL window.
 * abj404_loadSomethingWhenWordPressIsReady() is the admin_init callback that runs
 * after WordPress finishes enqueuing: it loads translations, enables localhost
 * debug output, refreshes the integrity cache, and handles the export action.
 *
 * The add_action('admin_init', ...) registration stays in 404-solution.php;
 * this file only defines the callbacks. The lazy Loader.php requires use
 * ABJ404_FILE so they resolve to the plugin root.
 */
// allow-no-test-found: boot-time admin_init global callbacks wired via add_action in 404-solution.php; no same-named unit file. abj404_loadSomethingWhenWordPressIsReady is exercised in integration boot coverage.

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
		if (isset($GLOBALS['abj404_plugin_language_override']) && is_scalar($GLOBALS['abj404_plugin_language_override'])
				&& (string)$GLOBALS['abj404_plugin_language_override'] !== '') {
			$override_locale = (string)$GLOBALS['abj404_plugin_language_override'];
		} else {
			$options = abj404_get_settings_options();
			if (isset($options['plugin_language_override']) && is_scalar($options['plugin_language_override'])) {
				$override_locale = (string)$options['plugin_language_override'];
			}
		}

		if ($override_locale !== '') {
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
		$serverNameRaw = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
		$serverName = is_scalar($serverNameRaw) ? (string)$serverNameRaw : '(not found)';
		$whitelist = isset($GLOBALS['abj404_whitelist']) && is_array($GLOBALS['abj404_whitelist']) ? $GLOBALS['abj404_whitelist'] : array();
		$serverNameIsInTheWhiteList = in_array($serverName, $whitelist);

		// Keep localhost debug helper on admin screens only; frontend requests stay lean.
		if ($serverNameIsInTheWhiteList && function_exists('wp_get_current_user')) {
	    require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
		if (abj_service('admin_access_policy')->isPluginAdmin()) {
			$GLOBALS['abj404_display_errors'] = true;
		}
	}
	}

	$action = null;
	if ($isAdminRequest) {
		$actionGet = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
		$actionPost = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
		if ($actionGet !== '') {
			$action = sanitize_text_field($actionGet);
		} else if ($actionPost !== '') {
			$action = sanitize_text_field($actionPost);
		} else {
			$action = null;
		}
	}
	if ($isAdminRequest && abj404_is_local_debug_host() && abj404_current_user_is_plugin_admin() && isset($_GET['abj404_set_sim_db_ms'])) {
		$nonce = isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
		$nonceOk = $nonce !== '' ? wp_verify_nonce($nonce, 'abj404_set_sim_db_ms') : false;
		if ($nonceOk) {
			$simMsRaw = $_GET['abj404_set_sim_db_ms'];
			$newMs = max(0, min(5000, absint(is_scalar($simMsRaw) ? $simMsRaw : 0)));
			update_option('abj404_simulated_db_latency_ms', $newMs, false);
		}
	}

	$ttl = defined('HOUR_IN_SECONDS') ? (12 * HOUR_IN_SECONDS) : 43200;
	abj404_maybe_refresh_runtime_integrity_cache($ttl);

	if ($isAdminRequest && $action === 'exportRedirects') {
	    require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
			$abj404logic->adminActions()->handleActionExport();
	}
}
}
