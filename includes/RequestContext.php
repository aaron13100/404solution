<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped context object that replaces $_REQUEST[ABJ404_PP] as an
 * intra-request message bus.
 *
 * Holds debug traces, cached permalink results, timing data, and per-request
 * ignore flags — all state that was previously smuggled through the HTTP
 * superglobal.
 */
class ABJ_404_Solution_RequestContext {

    /** @var ABJ_404_Solution_RequestContext|null */
    private static $instance = null;

    /** @var string Debug breadcrumb for error handler context. */
    public $debug_info = '';

    /** @var string JSON-encoded permalink results (fast-path cache for shortcode). */
    public $permalinks_found = '';

    /** @var string JSON-encoded kept permalinks (for debug logging). */
    public $permalinks_kept = '';

    /** @var float|null Process start time for benchmarking. */
    public $process_start_time = null;

    /** @var string|false Reason to ignore this request (do not process). */
    public $ignore_donotprocess = false;

    /** @var string|false Reason to ignore this request (process ok). */
    public $ignore_doprocess = false;

    /** @var string The requested URL stored for the shortcode to read. */
    public $requested_url = '';

    /** @return ABJ_404_Solution_RequestContext */
    public static function getInstance(): ABJ_404_Solution_RequestContext {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Reset the singleton (for tests). */
    public static function reset(): void {
        self::$instance = null;
    }
}
