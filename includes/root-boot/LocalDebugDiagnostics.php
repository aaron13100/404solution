<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Localhost-only debug diagnostics helpers.
 *
 * abj404_is_local_debug_host() decides whether the current request comes from a
 * recognized local development host. abj404_get_simulated_db_latency_ms() reads
 * the optional simulated-latency knob (clamped, localhost-only) used to exercise
 * slow-DB code paths. abj404_show_diagnostic_latency_notice() is an intentional
 * no-op kept so the admin_notices wiring (if any) has a stable callback; the
 * real status lives on the plugin's Tools > Diagnostics card.
 */
// allow-no-test-found: boot-time global debug helpers (localhost-only) wired in 404-solution.php; no same-named unit file. abj404_get_simulated_db_latency_ms is exercised in DatabaseQueryDiagnosticsTest, and the helpers are loaded through LoaderLazyLoadTest.

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
	/**
	 * admin_notices-style callback for the simulated-DB-latency status.
	 *
	 * This callback occupies an output-producing (impure) slot: historically it
	 * echoed a floating "Simulated DB latency is ON/OFF" notice. That intrusive
	 * notice was removed by design (see docs/ui-aesthetic/UI_AESTHETIC.md: default
	 * to no new admin surface, prefer quiet status); the live status now lives on
	 * the plugin's Tools > Diagnostics card (ToolsDiagnostics). The guarded checks
	 * below are retained so this callback only ever considers acting in the same
	 * context it used to (admin, plugin-admin capability, plugin page, local debug
	 * host) -- but in every case it now renders nothing. Regression tests in
	 * LoaderLazyLoadTest assert the empty output so the floating notice cannot be
	 * silently reintroduced.
	 *
	 * Marked impure because it is a notice-output callback by role even though it
	 * currently emits nothing.
	 *
	 * @phpstan-impure
	 * @return void
	 */
	function abj404_show_diagnostic_latency_notice() {
		if (!is_admin() || !abj404_current_user_is_plugin_admin()) {
			return;
		}
		$page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
		if ($page !== ABJ404_PP) {
			return;
		}
		if (!abj404_is_local_debug_host()) {
			return;
		}
		// Intentionally render nothing: simulated-latency status is shown on the
		// plugin's Tools > Diagnostics card, not as an intrusive floating notice.
	}
}
