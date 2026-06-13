<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('abj404_logPhpFallback')) {
/**
 * Emit a last-resort PHP error-log breadcrumb when plugin logging is
 * unavailable or unsafe to call.
 *
 * This is the only production sink for raw error_log(). Normal code should
 * attempt the plugin logger first, then call this helper only for the final
 * PHP-log fallback path.
 *
 * @param string $category One of the audit categories for fallback logging.
 * @param string $message Human-readable diagnostic context.
 * @return void
 */
function abj404_logPhpFallback(string $category, string $message): void {
    $allowedCategories = array(
        'early-boot',
        'logger-internal',
        'service-resolution-fallback',
        'fatal-handler-fallback',
        'transport-fallback',
    );

    $normalizedCategory = in_array($category, $allowedCategories, true)
        ? $category
        : 'uncategorized:' . $category;
    $normalizedMessage = str_replace(array("\r", "\n"), ' ', $message);

    // @abj404-raw-error-log-allowed: logger-internal centralized PHP error-log fallback is the single approved raw sink when plugin logging is unavailable.
    @error_log('404 Solution: ' . $normalizedMessage . ' [' . $normalizedCategory . ']');
}
}
