<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page-suggestions shortcode handler and runtime file-integrity verification.
 *
 * abj404_shortCodeListener() renders the [abj404_solution_page_suggestions]
 * shortcode (loading the plugin lazily). abj404_verify_runtime_integrity()
 * reports which required plugin files are missing, using the canonical
 * required-files list that abj404_get_required_runtime_files() builds from the
 * external data file includes/data/required-runtime-files.php.
 *
 * The add_shortcode() registration stays in 404-solution.php; this file only
 * defines the callback and the integrity-check helpers.
 */
// allow-no-test-found: boot-time global functions (shortcode handler + runtime integrity helpers) wired via add_shortcode in 404-solution.php; no same-named unit file. abj404_get_required_runtime_files and abj404_verify_runtime_integrity are exercised in SqlFileIntegrityListCompletenessTest.

if (!function_exists('abj404_get_required_runtime_files')) {
	/**
	 * Files required for a healthy runtime/plugin package.
	 * Covers boot-critical PHP files and essential SQL templates.
	 *
	 * The static lists are DATA, loaded from
	 * includes/data/required-runtime-files.php; the dynamic classmap file list
	 * is merged in here at runtime.
	 *
	 * @return array<int, string>
	 */
	function abj404_get_required_runtime_files() {
		$inc = ABJ404_PATH . 'includes/';
		$classmapFile = $inc . 'classmap.php';
		if (!file_exists($classmapFile)) {
			$classmapFile = dirname(__DIR__) . '/classmap.php';
		}
		$classmapFiles = array();
		if (file_exists($classmapFile)) {
			$classmap = require $classmapFile;
			if (is_array($classmap)) {
				foreach ($classmap as $classmapPath) {
					if (is_string($classmapPath)) {
						$classmapFiles[] = $classmapPath;
					}
				}
			}
		}

		$dataFile = dirname(__DIR__) . '/data/required-runtime-files.php';
		$lists = file_exists($dataFile) ? require $dataFile : array();
		if (!is_array($lists)) {
			$lists = array();
		}
		$bootRelative = isset($lists['boot']) && is_array($lists['boot']) ? $lists['boot'] : array();
		$sqlRelative = isset($lists['sql']) && is_array($lists['sql']) ? $lists['sql'] : array();
		$rootRelative = isset($lists['root']) && is_array($lists['root']) ? $lists['root'] : array();

		$bootFiles = array();
		foreach ($bootRelative as $relative) {
			if (is_string($relative)) {
				$bootFiles[] = $inc . $relative;
			}
		}
		$sqlFiles = array();
		foreach ($sqlRelative as $relative) {
			if (is_string($relative)) {
				$sqlFiles[] = $inc . $relative;
			}
		}
		$rootFiles = array();
		foreach ($rootRelative as $relative) {
			if (is_string($relative)) {
				$rootFiles[] = ABJ404_PATH . $relative;
			}
		}

		return array_values(array_unique(array_merge(
			$bootFiles,
			$rootFiles,
			$classmapFiles,
			$sqlFiles
		)));
	}
}

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
	    require_once(plugin_dir_path( ABJ404_FILE ) . "includes/Loader.php");
	    /** @var array<string, mixed> $safeAtts */
	    $safeAtts = is_array($atts) ? $atts : array();
	    return ABJ_404_Solution_ShortCode::shortcodePageSuggestions($safeAtts);
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
