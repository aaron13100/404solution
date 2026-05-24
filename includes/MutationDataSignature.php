<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-line replacement for the watermark-counter primitive that lived
 * in {@see ABJ_404_Solution_MutationWatermark} before
 * t_260523_224315_207. Answers "did anything change since I last looked?"
 * by reading data the schema already encodes (the row's `timestamp`
 * column on `wp_abj404_redirects`, plus `COUNT(*)` to catch DELETEs that
 * don't advance MAX(timestamp)).
 *
 * Why a composite and not just MAX(timestamp). The redirect row's
 * `timestamp` is set to `time()` on INSERT and UPDATE, so MAX(timestamp)
 * advances on both. It does NOT change on DELETE (the deleted row's
 * timestamp leaves with it), so a pure-DELETE mutation would be
 * invisible to a MAX-only signature. Combining with `COUNT(*)` covers
 * DELETE without introducing a new hot-path write. The pair is encoded
 * as a single bigint signature so callers can `===`-compare two values.
 *
 * Same-second resolution. Two mutations landing in the same Unix second
 * with a matching net COUNT change (e.g. one INSERT cancelled by one
 * DELETE) produce an unchanged signature. This is vanishingly rare and,
 * per the product owner's stance recorded in the design lesson, stale-
 * by-one-page-load is acceptable. The next mutation (or the next
 * `time()` tick) reveals the drift and the build re-runs.
 *
 * Read cost. One indexed `SELECT MAX(timestamp), COUNT(*)` against
 * `wp_abj404_redirects`. MAX uses the `timestamp` index; COUNT scans the
 * primary key. Sub-millisecond on a 100K-row table; replaces what used
 * to be one upsert per mutation plus a sibling SELECT per stage
 * boundary. Per-request memoisation is provided because the same value
 * may be read multiple times during one staged-build tick.
 *
 * @see docs/design-lesson-watermark-overengineering.md
 */
final class ABJ_404_Solution_MutationDataSignature {

    /** Sentinel returned when the redirects table is unreadable. */
    const UNAVAILABLE = -1;

    /** @var array<string,int>|null Per-request memoisation, keyed by prefix. */
    private static $cache = null;

    /**
     * Reset the per-request memoisation. Tests that mutate the redirects
     * table between assertions call this so the next read re-issues the
     * SELECT against the post-mutation state. Production code never needs
     * to call this: the cache is rebuilt on every fresh request and the
     * in-build abort gate intentionally treats it as a stable per-tick
     * value (each runner tick is one PHP request).
     */
    public static function resetForTesting(): void {
        self::$cache = null;
    }

    /**
     * Read the data signature for the redirects table on the active blog
     * prefix. Returns {@see UNAVAILABLE} when the table cannot be read
     * (test stubs without a real `$wpdb`, dropped table, transient DB
     * error). Callers treat UNAVAILABLE the same way the old
     * the removed `MutationWatermark` primitive's `current()` returned 0 on cold-bootstrap: as
     * "no signal", which makes downstream comparisons (gate-blocks,
     * abort-when-advanced) fall through to their safe default.
     *
     * The encoded signature is `(MAX(timestamp) << 32) | (COUNT(*) & 0xffffffff)`.
     * MAX(timestamp) is a Unix epoch second (10 digits today, 11 in year
     * 2286). COUNT(*) up to 4 billion rows fits in the low 32 bits;
     * sites with more redirect rows than that have problems this gate
     * cannot solve. The shift keeps lexicographic ordering meaningful
     * for diagnostic display (later timestamps sort after earlier ones).
     */
    public static function current(): int {
        $prefix = self::activePrefix();
        if (isset(self::$cache[$prefix])) {
            return self::$cache[$prefix];
        }
        $value = self::computeSignature();
        if (self::$cache === null) {
            self::$cache = array();
        }
        self::$cache[$prefix] = $value;
        return $value;
    }

    /** @return int */
    private static function computeSignature(): int {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            return self::UNAVAILABLE;
        }
        $table = self::redirectsTableName();
        $sql = "SELECT COALESCE(MAX(`timestamp`), 0) AS max_ts, COUNT(*) AS row_count FROM `{$table}`";
        try {
            // DAO-bypass-approved: stage-boundary signature read must not auto-CREATE on missing-table; callers fall through to UNAVAILABLE.
            $row = $wpdb->get_row($sql, ARRAY_A);
            // allow-silent-catch: degraded wpdb (test mocks, transient connection errors) collapses to UNAVAILABLE; the signal is best-effort by design.
        } catch (\Throwable $e) {
            return self::UNAVAILABLE;
        }
        if (!is_array($row) || !isset($row['max_ts']) || !isset($row['row_count'])) {
            return self::UNAVAILABLE;
        }
        $maxTsRaw = $row['max_ts'];
        $countRaw = $row['row_count'];
        if (!is_scalar($maxTsRaw) || !is_scalar($countRaw)) {
            return self::UNAVAILABLE;
        }
        $maxTs = intval($maxTsRaw);
        $count = intval($countRaw);
        if ($maxTs < 0) { $maxTs = 0; }
        if ($count < 0) { $count = 0; }
        return ($maxTs << 32) | ($count & 0xffffffff);
    }

    /** @return string */
    private static function activePrefix(): string {
        global $wpdb;
        return isset($wpdb) && isset($wpdb->prefix) ? strtolower((string)$wpdb->prefix) : 'wp_';
    }

    /** @return string */
    private static function redirectsTableName(): string {
        return self::activePrefix() . 'abj404_redirects';
    }
}
