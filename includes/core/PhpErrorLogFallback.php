<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PhpRuntimeCapabilityAdapter.php';

if (!function_exists('abj404_logPhpFallback')) {
/**
 * Emit a last-resort PHP error-log breadcrumb when plugin logging is
 * unavailable or unsafe to call.
 *
 * This is the only application-level PHP-log fallback. Normal code should
 * attempt the plugin logger first, then call this helper only for the final
 * fallback path. The runtime adapter owns the raw, capability-checked call.
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

    ABJ_404_Solution_PhpRuntimeCapabilityAdapter::writeErrorLog(
        '404 Solution: ' . $normalizedMessage . ' [' . $normalizedCategory . ']'
    );
}
}
