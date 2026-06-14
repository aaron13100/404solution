<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats the current instant as a log-line timestamp in the site's configured
 * timezone.
 *
 * Both WordPress option sources this reads (`timezone_string` and `gmt_offset`)
 * can hold garbage on a corrupted site: a stray value written into the wrong
 * options row, an out-of-range offset, a non-IANA name. A bad value must never
 * fatal the logging path, so DateTimeZone construction for either source shares
 * one guarded fallback to the server default and emits a breadcrumb through the
 * raw PHP fallback sink (never through the logger itself, which would risk
 * recursion if the timezone failure also breaks the logger's own DateTime use).
 *
 * Pure formatting policy: no business decisions, no data store of its own.
 */
class ABJ_404_Solution_LogTimestampFormatter {

    /**
     * Current time formatted for the active site timezone.
     *
     * @return string A `Y-m-d H:i:s T` timestamp.
     */
    public function format(): string {
        $timezoneStringRaw = get_option('timezone_string');
        $timezoneString = is_string($timezoneStringRaw) ? $timezoneStringRaw : '';

        if (!empty($timezoneString)) {
            // WordPress stores an IANA name (e.g. "America/New_York") here.
            $tzString = $timezoneString;
        } else {
            $gmtOffsetRaw = get_option('gmt_offset');
            // WordPress's gmt_offset is hours and may be fractional
            // (e.g. 5.5 India, 5.75 Nepal, -3.5 Newfoundland).
            $gmtOffsetHours = is_scalar($gmtOffsetRaw) ? (float)$gmtOffsetRaw : 0.0;
            $totalMinutes = (int) round($gmtOffsetHours * 60);
            $sign = $totalMinutes < 0 ? '-' : '+';
            $absMinutes = abs($totalMinutes);
            $tzString = sprintf('%s%02d:%02d', $sign, intdiv($absMinutes, 60), $absMinutes % 60);
        }

        // Both option sources can hold garbage on a corrupted site: a stray
        // value written into the wrong options row, an out-of-range offset,
        // etc. A bad value must never fatal the logging path, so the
        // DateTimeZone construction for EITHER source shares one guarded
        // fallback to the server default.
        try {
            $date = new DateTime('@' . abj_clock()->now());
            $date->setTimezone(new DateTimeZone($tzString));
        } catch (Exception $e) {
            // Use the raw fallback sink because this method is part of
            // the logging path; calling warn here would risk recursion if
            // timezone failure also breaks warn's own DateTime use.
            abj404_logPhpFallback(
                'logger-internal',
                'timezone constructor failed (' . $e->getMessage() . '); using server default'
            );
            $date = new DateTime('@' . abj_clock()->now());
            $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }

        return $date->format('Y-m-d H:i:s T');
    }
}
