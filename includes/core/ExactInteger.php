<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read a whole number from an untrusted scalar, or refuse it.
 *
 * `is_numeric($v) ? (int)$v : $fallback` is the shape this replaces, and it is
 * wrong in a specific way: is_numeric() admits '1.9', '0.5', 1.0, '1e1' and
 * ' 3', and the cast then answers each with a confident WRONG whole number
 * rather than with "unreadable". Where that number decides an identity -- a
 * column position, a pair slot, a repetition ordinal -- the caller cannot tell
 * a real value from a truncated one, and every downstream check passes on the
 * fabrication.
 *
 * Two places in this plugin found that out independently:
 *
 *   1. ABJ_404_Solution_ShowIndexRowReader, where '0.5' became the 0 that means
 *      UNIQUE and the comparison it fed answers a difference with destructive
 *      DDL.
 *   2. ABJ_404_Solution_DetachAbAttempt, where '0.5' and '1.9' truncate into
 *      positions 0 and 1 of the SAME counterbalanced pair and satisfy every
 *      condition the detach A/B decision rule applies -- manufacturing a causal
 *      verdict out of two corrupt journal lines.
 *
 * The first fixed it locally and explained it thoroughly; nothing generalised
 * the rule, so the second was written fresh with the same defect. This class is
 * the generalisation.
 *
 * Integrality is decided from the TEXT, never from the number the text converts
 * to. Past 2^53 a double has no room left for the fractional part it was
 * handed, so '9007199254740992.5' arrives already rounded and a floor() check
 * downstream sees nothing wrong.
 */
final class ABJ_404_Solution_ExactInteger {

    /**
     * The exact whole number this value spells, or null when it does not spell
     * one at or above $minimum.
     *
     * Deliberately does NOT trim: whitespace means something wrote the field
     * other than the code that owns it, and only a caller knows whether its
     * source legitimately pads (a database driver reporting metadata does; a
     * JSON journal written by this plugin does not). Callers that need it trim
     * before calling, which keeps the decision visible at the site that can
     * justify it.
     *
     * Booleans are refused rather than cast: (string) renders true as '1' and
     * false as '', so accepting them would read one as a confident flag and the
     * other as absent.
     *
     * @param mixed $value
     * @param int $minimum Smallest value the field's documented domain allows.
     * @return int|null
     */
    public static function read($value, int $minimum): ?int {
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = (string)$value;
        // Two checks, not one. is_numeric() is the wide gate that makes the
        // arithmetic below well-defined (and is what lets a static analyser see
        // that it is); the pattern is the narrow one that rejects every
        // spelling is_numeric() accepts but this method refuses to truncate.
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        if (preg_match('/\A[+-]?[0-9]+\z/', $text) !== 1) {
            return null;
        }
        $number = $text + 0;
        if (is_float($number)) {
            // A digit run too long for an int converts to a float instead:
            // still whole, but possibly infinite and possibly outside the range
            // an int holds. (int) answers both with a different value.
            if (!is_finite($number)
                    || $number < (float)PHP_INT_MIN || $number >= (float)PHP_INT_MAX) {
                return null;
            }
        }
        $integer = (int)$number;
        return $integer < $minimum ? null : $integer;
    }

    /**
     * read(), with a caller-supplied answer for "this value is unreadable".
     *
     * For the many call sites whose domain has a sentinel already -- -1 for "no
     * coordinate", 0 for "not supplied" -- so an unreadable field and an absent
     * one land on the same value without each site repeating the null check.
     *
     * @param mixed $value
     * @param int $minimum Smallest value the field's documented domain allows.
     * @param int $whenUnreadable Returned when $value does not spell a whole
     *   number at or above $minimum.
     */
    public static function readOr($value, int $minimum, int $whenUnreadable): int {
        $read = self::read($value, $minimum);
        return $read === null ? $whenUnreadable : $read;
    }
}
