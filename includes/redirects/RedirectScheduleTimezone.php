<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts between the WP-site-local calendar dates shown in the redirect
 * schedule form ("Active From" / "Active Until") and the UTC epoch stored
 * in `start_ts`/`end_ts`.
 *
 * The runtime activation check (getPermalinkFromURL.sql) compares
 * `start_ts`/`end_ts` against MySQL's `UNIX_TIMESTAMP()` -- a true,
 * timezone-independent UTC epoch. Both the write path (parsing the admin's
 * "2026-07-15" input) and the read path (redisplaying that epoch in the
 * edit form) must anchor to the same true-UTC convention using the site's
 * configured timezone (ABJ_404_Solution_SiteTimezone), not PHP's implicit
 * default timezone (frequently UTC regardless of what the WordPress site is
 * configured to). Using PHP's default timezone instead of the site's
 * silently shifts the activation moment by the site's UTC offset on every
 * non-UTC site.
 */
final class ABJ_404_Solution_RedirectScheduleTimezone {

    /**
     * Parse a "Y-m-d" (or any DateTime-parseable) date plus a literal time
     * string in the WordPress site's configured timezone, returning a true
     * UTC epoch. Returns null on empty or unparseable input (caller treats
     * null as "no schedule bound", matching prior strtotime()===false
     * handling).
     *
     * @param string $dateRaw
     * @param string $time "H:i:s" literal, e.g. '00:00:00' or '23:59:59'
     * @return int|null
     */
    public static function toEpoch(string $dateRaw, string $time): ?int {
        if ($dateRaw === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($dateRaw . ' ' . $time, ABJ_404_Solution_SiteTimezone::resolve());
        } catch (Exception $e) {
            // $dateRaw is non-empty here (the empty-string case returns
            // above) but failed to parse as a date -- a real, unexpected
            // input shape (the admin's date-picker should only ever submit
            // "Y-m-d"), not the routine "no schedule bound" case. Silently
            // dropping the schedule with zero trace would leave an admin
            // wondering why their Active From/Until didn't take effect.
            if (function_exists('abj404_logRuntimeWarning')) {
                abj404_logRuntimeWarning('RedirectScheduleTimezone: unparseable schedule date "' . $dateRaw . '"', $e);
            }
            return null;
        }
        return $dt->getTimestamp();
    }

    /**
     * Format a UTC epoch (as stored in start_ts/end_ts) as a "Y-m-d" date
     * in the WordPress site's configured timezone, for redisplay in the
     * edit form.
     *
     * @param int $epoch
     * @return string
     */
    public static function toDateString(int $epoch): string {
        return (new DateTimeImmutable('@' . $epoch))->setTimezone(ABJ_404_Solution_SiteTimezone::resolve())->format('Y-m-d');
    }
}
