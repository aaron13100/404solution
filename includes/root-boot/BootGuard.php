<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Boot-failure shutdown handler.
 *
 * Defines abj404_boot_shutdown_handler(), which captures compile/parse fatals in
 * the plugin's files during PHP shutdown and stores them in a transient so the
 * degraded admin page can display the error on the next request. This matters on
 * PHP 7.4 where syntax errors in required files produce an uncatchable
 * E_COMPILE_ERROR.
 *
 * The register_shutdown_function() call that wires this handler stays in
 * 404-solution.php (it must run as part of the boot sequence, before Loader.php
 * is required). This file only defines the function.
 */
// allow-no-test-found: boot-time global function (abj404_boot_shutdown_handler) wired via register_shutdown_function in 404-solution.php before the autoloader; it captures uncatchable E_COMPILE_ERROR fatals during real PHP shutdown, which cannot be reproduced in-process, so there is no isolated unit seam.

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
		$pluginDir = defined('ABJ404_PATH') ? ABJ404_PATH : dirname(dirname(__DIR__)) . '/';
		if (strpos($error['file'], $pluginDir) === false) {
			return;
		}
		$errorInfo = array(
			'message' => $error['message'],
			'file' => $error['file'],
			'line' => $error['line'],
			'type' => $error['type'],
			'time' => abj404_now(),
		);
		// Use update_option as a fallback: set_transient might not be available
		// during a fatal shutdown.
		if (function_exists('set_transient')) {
			set_transient('abj404_boot_fatal', $errorInfo, 3600);
		}
	}
}
