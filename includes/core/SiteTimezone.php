<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the WordPress site's configured timezone (Settings > General).
 *
 * wp_timezone() (WP 5.3+) already implements this; the fallback below
 * replicates wp_timezone_string()'s logic (timezone_string option, else a
 * fixed offset from gmt_offset) for older installs, since this plugin's
 * readme declares WP 5.0 compatibility.
 *
 * Shared by every caller that needs to interpret or produce a WP-site-local
 * calendar date/time as a true UTC epoch: redirect scheduling
 * (RedirectScheduleTimezone) and feedback log-timestamp parsing
 * (FeedbackEnvironmentExtras_DebugLogSignatures). Deliberately NOT used by
 * LogTimestampFormatter, which needs a self-contained, never-throws,
 * never-logs implementation to avoid recursing back into the logger it is
 * formatting a timestamp for (see that class's docblock).
 */
final class ABJ_404_Solution_SiteTimezone {

    /**
     * @return DateTimeZone
     */
    public static function resolve(): DateTimeZone {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $timezoneStringRaw = function_exists('get_option') ? get_option('timezone_string') : '';
        $timezoneString = is_string($timezoneStringRaw) ? $timezoneStringRaw : '';
        $offsetRaw = function_exists('get_option') ? get_option('gmt_offset') : 0.0;
        $offset = is_scalar($offsetRaw) ? (float)$offsetRaw : 0.0;
        return self::resolveFallback($timezoneString, $offset);
    }

    /**
     * Pure computation half of the WP<5.3 fallback, split out so it is
     * unit-testable without depending on wp_timezone()'s function_exists()
     * state -- once a test stubs wp_timezone() via Brain\Monkey, Patchwork
     * keeps it defined for the rest of that PHP process/worker (see
     * .claude/rules/testing.md R6), so function_exists('wp_timezone')
     * cannot be relied on to be false later in the same run.
     *
     * @param string $timezoneString get_option('timezone_string') value
     * @param float $gmtOffset get_option('gmt_offset') value
     * @return DateTimeZone
     */
    public static function resolveFallback(string $timezoneString, float $gmtOffset): DateTimeZone {
        if ($timezoneString !== '') {
            try {
                return new DateTimeZone($timezoneString);
            } catch (Exception $e) {
                if (function_exists('abj_service_optional')) {
                    $logging = abj_service_optional('logging');
                    if ($logging !== null) {
                        $logging->warn('SiteTimezone: invalid timezone_string option "' .
                            $timezoneString . '", falling back to gmt_offset: ' . $e->getMessage());
                    }
                }
                // Falls through to the offset-based fallback below.
            }
        }

        $hours = (int)$gmtOffset;
        $minutes = abs(($gmtOffset - (int)$gmtOffset) * 60);
        return new DateTimeZone(sprintf('%+03d:%02d', $hours, $minutes));
    }
}
