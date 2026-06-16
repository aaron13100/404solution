<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-request benchmark instrumentation for the plugin bootstrap.
 *
 * Disabled by default and only enabled when a request carries ?abj404_bench=1.
 * Tracks bootstrap timing, DB query count/time, and redirect-lookup time in a
 * request-scoped global, then emits an X-ABJ404-Benchmark response header.
 *
 * The executable wiring (abj404_benchmark_bootstrap_start() at boot,
 * abj404_benchmark_mark_bootstrap_done() after the template_redirect hook is
 * registered, and the send_headers add_action) stays in 404-solution.php so the
 * timing markers fire in the correct request lifecycle order. This file only
 * defines the functions.
 */
// allow-no-test-found: boot-time benchmark instrumentation global functions wired into the request lifecycle in 404-solution.php; no same-named unit file. abj404_is_benchmark_request and the abj404_benchmark_* markers are exercised in CompetitiveBenchmarkAssetsTest.

if (!function_exists('abj404_is_benchmark_request')) {
	/**
	 * Benchmark instrumentation is disabled by default and only enabled per-request.
	 *
	 * @return bool
	 */
	function abj404_is_benchmark_request() {
		if (!isset($_GET['abj404_bench'])) {
			return false;
		}
		$flag = $_GET['abj404_bench'];
		return is_scalar($flag) && (string)$flag === '1';
	}
}

if (!function_exists('abj404_benchmark_state_ref')) {
	/**
	 * Return a typed reference to the request-scoped benchmark accumulator,
	 * initializing it if necessary. Centralizing the typed shape here keeps the
	 * recorder/emitter functions free of mixed-offset access on the global.
	 *
	 * @return array{start: float, bootstrap_done: float, db_query_count: int, db_query_ms: float, redirect_lookup_ms: float}
	 */
	function &abj404_benchmark_state_ref() {
		if (!isset($GLOBALS['abj404_benchmark_state']) || !is_array($GLOBALS['abj404_benchmark_state'])) {
			$GLOBALS['abj404_benchmark_state'] = array(
				'start' => abj404_now_float(),
				'bootstrap_done' => 0.0,
				'db_query_count' => 0,
				'db_query_ms' => 0.0,
				'redirect_lookup_ms' => 0.0,
			);
		}
		$rawState = $GLOBALS['abj404_benchmark_state'];
		/** @var array<string, mixed> $rawState */
		$GLOBALS['abj404_benchmark_state'] = array(
			'start' => isset($rawState['start']) && is_numeric($rawState['start']) ? (float)$rawState['start'] : 0.0,
			'bootstrap_done' => isset($rawState['bootstrap_done']) && is_numeric($rawState['bootstrap_done']) ? (float)$rawState['bootstrap_done'] : 0.0,
			'db_query_count' => isset($rawState['db_query_count']) && is_numeric($rawState['db_query_count']) ? (int)$rawState['db_query_count'] : 0,
			'db_query_ms' => isset($rawState['db_query_ms']) && is_numeric($rawState['db_query_ms']) ? (float)$rawState['db_query_ms'] : 0.0,
			'redirect_lookup_ms' => isset($rawState['redirect_lookup_ms']) && is_numeric($rawState['redirect_lookup_ms']) ? (float)$rawState['redirect_lookup_ms'] : 0.0,
		);
		/** @var array{start: float, bootstrap_done: float, db_query_count: int, db_query_ms: float, redirect_lookup_ms: float} $state */
		$state = &$GLOBALS['abj404_benchmark_state'];
		return $state;
	}
}

if (!function_exists('abj404_benchmark_bootstrap_start')) {
	/** @return void */
	function abj404_benchmark_bootstrap_start() {
		if (!abj404_is_benchmark_request()) {
			return;
		}
		// Initialize (and normalize) the typed accumulator.
		abj404_benchmark_state_ref();
	}
}

if (!function_exists('abj404_benchmark_mark_bootstrap_done')) {
	/** @return void */
	function abj404_benchmark_mark_bootstrap_done() {
		if (!abj404_is_benchmark_request() || !isset($GLOBALS['abj404_benchmark_state'])) {
			return;
		}
		$state = &abj404_benchmark_state_ref();
		$state['bootstrap_done'] = abj404_now_float();
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
		$state = &abj404_benchmark_state_ref();
		$state['db_query_count']++;
		$state['db_query_ms'] += $elapsedMs;
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
		$state = &abj404_benchmark_state_ref();
		$state['redirect_lookup_ms'] += max(0.0, (float)$elapsedMs);
	}
}

if (!function_exists('abj404_benchmark_emit_headers')) {
	/** @return void */
	function abj404_benchmark_emit_headers() {
		if (!abj404_is_benchmark_request() || headers_sent() || !isset($GLOBALS['abj404_benchmark_state'])) {
			return;
		}
		$state = abj404_benchmark_state_ref();
		$start = $state['start'];
		$bootstrapDone = $state['bootstrap_done'];
		$now = abj404_now_float();
		$totalMs = ($start > 0.0) ? (($now - $start) * 1000.0) : 0.0;
		$bootstrapMs = ($start > 0.0 && $bootstrapDone > 0.0) ? (($bootstrapDone - $start) * 1000.0) : 0.0;
		$dbQueryCount = $state['db_query_count'];
		$dbQueryMs = $state['db_query_ms'];
		$redirectLookupMs = $state['redirect_lookup_ms'];

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
