<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders a UTC epoch as a display string in the WordPress site's configured
 * timezone (Settings > General), on every WordPress version this plugin
 * declares support for.
 *
 * wp_date() arrived in WordPress 5.3, but 404-solution.php and readme.txt both
 * declare `Requires at least: 5.0`, so a bare wp_date() call is a
 * `Call to undefined function` fatal on every site the declaration invites in.
 * Eight such calls sat in the two main admin tables (RedirectRowPresenter,
 * View_CapturedURLsTable, AdminLogsTable) and in the n-gram scheduler's refusal
 * reports, which is the whole plugin surface a WP 5.0-5.2 admin would open.
 *
 * The rest of the codebase already guards its post-5.0 core calls one by one
 * (ABJ_404_Solution_SiteTimezone::resolve, ScheduledEventInspector,
 * RequestEnvironmentFingerprint, WPUtils::addScriptTranslations,
 * FrontendSuggestionLocaleScope). This class exists so the timestamp axis is
 * guarded ONCE rather than eight times: adding a ninth inline function_exists()
 * check would spread the same decision across nine files, and the ninth is the
 * one somebody forgets.
 *
 * The WP < 5.3 path is not a degradation to UTC: it reuses
 * ABJ_404_Solution_SiteTimezone, so the rendered wall-clock time is the same
 * one wp_date() would produce. Only WordPress's localized month, weekday and
 * am/pm strings are unavailable, because those live behind $wp_locale's
 * date translation helpers that wp_date() itself introduced.
 *
 * Pure presentation policy: no business decisions, no data store access.
 *
 * @see scripts/lint/lint-wp-version-compat.php which fails the build on any
 *      shipped call to a core function newer than the declared floor.
 */
final class ABJ_404_Solution_SiteLocalTimestamp {

    /**
     * Format a UTC epoch for display in the site's timezone.
     *
     * Argument order mirrors wp_date() so call sites read identically.
     *
     * A wp_date() that returns false (WordPress's documented failure return)
     * falls through to the same fallback rather than yielding the empty string
     * the previous `(string)wp_date(...)` casts produced: an empty date cell
     * tells the admin nothing, and the fallback can still answer correctly.
     *
     * Exceptions thrown by wp_date() itself are deliberately NOT caught. On
     * WordPress 5.3+ that means the site's timezone options are corrupt, which
     * is a real fault the admin needs to see; swallowing it here would only
     * hide it behind a plausible-looking timestamp.
     *
     * @param string $format A PHP date() format string.
     * @param int $timestamp Seconds since the Unix epoch, UTC.
     * @return string The formatted timestamp; never false, never null.
     */
    public static function format(string $format, int $timestamp): string {
        if (function_exists('wp_date')) {
            $rendered = wp_date($format, $timestamp);
            if (is_string($rendered) && $rendered !== '') {
                return $rendered;
            }
        }

        return self::formatFallback($format, $timestamp);
    }

    /**
     * The WordPress < 5.3 rendering path, split out (exactly as
     * ABJ_404_Solution_SiteTimezone::resolveFallback is) so it can be unit
     * tested directly. Once any test in a worker stubs wp_date() through
     * Brain\Monkey, Patchwork keeps it defined for the rest of that PHP
     * process (R6 in .claude/rules/testing.md), so function_exists('wp_date')
     * cannot be driven to false from inside the suite.
     *
     * @param string $format A PHP date() format string.
     * @param int $timestamp Seconds since the Unix epoch, UTC.
     * @return string The formatted timestamp.
     */
    public static function formatFallback(string $format, int $timestamp): string {
        try {
            $moment = new DateTimeImmutable('@' . $timestamp);
            return $moment->setTimezone(ABJ_404_Solution_SiteTimezone::resolve())->format($format);
        } catch (Throwable $e) {
            // Only reachable when the site's timezone options are corrupt
            // enough that resolving a DateTimeZone throws at all. Rendering
            // in UTC keeps the admin table readable; the warning says why the
            // number may not match the site's clock.
            if (function_exists('abj_service_optional')) {
                $logging = abj_service_optional('logging');
                if ($logging !== null) {
                    $logging->warn('SiteLocalTimestamp: could not resolve the site timezone (' .
                        $e->getMessage() . '); rendering "' . $format . '" in UTC instead.');
                }
            }
            return gmdate($format, $timestamp);
        }
    }
}
