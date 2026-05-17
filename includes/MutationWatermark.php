<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Monotonically-increasing counter that the staged view-build runner uses
 * to know "did anything change since I started?". Phase 1 of the staged
 * view-build watermark refactor (see
 * docs/refactor-staged-view-build-watermark.md).
 *
 * Why it exists. The staged view-build runner needs a single, atomic signal
 * that source data (`abj404_redirects`) has changed. Before Phase 4, every
 * mutation call site invoked `invalidateViewDone()`, a god method that
 * dropped the runner's buffer table mid-build (the "Aharon" bug class).
 * Phase 4 (commit 2994e21c) deleted the symbol entirely; the runner is the
 * sole owner of buffer state and external callers signal "data changed"
 * exclusively via this primitive.
 *
 * Atomicity contract. `bump()` issues a single SQL statement that both
 * creates the row on first use and increments the counter on every other
 * call. Under parallel writers (cron + admin save + visitor 404 capture
 * arriving in the same tick) the row-level lock on the upsert serialises
 * the increments; no lost-update class. The post-increment value is
 * returned via the `LAST_INSERT_ID(expr)` per-connection trick, so each
 * caller observes its own contribution rather than re-reading and
 * potentially seeing a value bumped by a sibling between the write and
 * the read. See https://dev.mysql.com/doc/refman/8.0/en/information-functions.html#function_last-insert-id
 *
 * Object cache. `bump()` invalidates the per-blog cache key so the next
 * `current()` reads from disk. `current()` always issues a fresh SELECT
 * rather than trusting a possibly-stale cache: the caller is the runner
 * comparing watermarks at stage boundaries, where correctness beats the
 * one-query saving.
 *
 * Multisite. The table lives at `{$wpdb->prefix}abj404_mutation_watermark`,
 * so each blog gets its own counter automatically (each blog has its own
 * `$wpdb->prefix`). `switch_to_blog()` rotates `$wpdb->prefix` and
 * therefore implicitly rotates the watermark target table.
 *
 * Schema. `BIGINT UNSIGNED` (1.8e19 range) with `name` as the primary
 * key. Single-row today; the `name` column leaves room for future
 * separate counters (per-table mutation streams) without a schema change.
 */
final class ABJ_404_Solution_MutationWatermark {

    /**
     * Logical name of the only counter row that currently exists. Future
     * sub-watermarks (e.g. one per source table) would be additional rows
     * with different names.
     */
    const NAME_REDIRECTS = 'redirects';

    /** Object-cache group used for the post-bump invalidation key. */
    const CACHE_GROUP = 'abj404';

    /** Object-cache key prefix; final key is suffixed with `name`. */
    const CACHE_KEY_PREFIX = 'mutation_watermark.';

    /**
     * Per-PHP-process flag that `ensureTable()` has run successfully
     * against the current `$wpdb->prefix`. Reset by tests that rotate
     * the prefix (multisite simulation) or that drop the table.
     *
     * Keyed by table name, not just a bool, so that rotating
     * `$wpdb->prefix` mid-process (the multisite test does this)
     * triggers a re-ensure for the new prefix's table.
     *
     * @var array<string,true>
     */
    private static $tableEnsured = array();

    /**
     * Fully-qualified watermark table name for the active blog
     * (`{$wpdb->prefix}abj404_mutation_watermark`). Recomputed every call
     * so multisite `switch_to_blog()` is honoured automatically.
     */
    public static function tableName(): string {
        global $wpdb;
        $prefix = isset($wpdb) && isset($wpdb->prefix) ? strtolower((string)$wpdb->prefix) : 'wp_';
        return $prefix . 'abj404_mutation_watermark';
    }

    /**
     * Clear the per-process "table ensured" flag. Tests call this when
     * they have just dropped the table or rotated the active prefix; in
     * production it is never invoked because the table, once created, is
     * never dropped.
     */
    public static function resetEnsuredForTesting(): void {
        self::$tableEnsured = array();
    }

    /**
     * Idempotent table creator. `CREATE TABLE IF NOT EXISTS` so a
     * concurrent caller that wins the race produces no error on the
     * loser's side.
     *
     * Returns void; failure is swallowed by `$wpdb->query()` and surfaces
     * later when the bump/current SQL fails. We deliberately do not
     * raise here so a transient permission error on the first call does
     * not crash a request that would otherwise succeed once the table
     * was ensured by a sibling call.
     */
    public static function ensureTable(): void {
        global $wpdb;
        $table = self::tableName();
        if (isset(self::$tableEnsured[$table])) {
            return;
        }

        $charsetCollate = '';
        if (is_object($wpdb) && method_exists($wpdb, 'get_charset_collate')) {
            $maybe = $wpdb->get_charset_collate();
            if (is_string($maybe)) {
                $charsetCollate = $maybe;
            }
        }

        // BIGINT UNSIGNED: 1.8e19 mutations before overflow. PRIMARY KEY
        // on `name` doubles as the fast-read index; current() probes by
        // exact name and the PK answers in O(log n) (n is at most a
        // handful of rows even in the long-term expansion).
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `name` VARCHAR(64) NOT NULL,
            `counter` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`name`)
        ) {$charsetCollate}";

        // Lazy schema bootstrap for a primitive that must function before
        // the DAO is fully initialised (Phase 1 dormant primitive callable
        // from any context including cron boot, where the DAO's recovery
        // machinery may itself be mid-initialisation).
        // DAO-bypass-approved: idempotent CREATE TABLE IF NOT EXISTS, no DAO retry needed.
        $wpdb->query($sql);
        self::$tableEnsured[$table] = true;
    }

    /**
     * Atomically increment the counter for `$name` and return the new
     * value. Creates the row on first use (with counter=1) and the table
     * on first plugin call (lazy migration).
     *
     * Implementation detail. `LAST_INSERT_ID(expr)` sets the per-
     * connection last_insert_id AND returns `expr`. Combining it with
     * `ON DUPLICATE KEY UPDATE` lets the caller read the post-increment
     * value from `$wpdb->insert_id` rather than issuing a follow-up
     * SELECT (which under parallel writers can return a higher value
     * than the caller's contribution). The trick works on every supported
     * engine cell: MySQL 5.7+, MariaDB 10.3+.
     *
     * @param string $name Logical counter row. Default = the only row in
     *                     use today; pass a different name to grow the
     *                     table for per-table sub-watermarks (Phase 5).
     * @return int New post-increment counter value.
     */
    public static function bump(string $name = self::NAME_REDIRECTS): int {
        global $wpdb;
        self::ensureTable();
        $table = self::tableName();

        $sql = "INSERT INTO `{$table}` (`name`, `counter`) "
             . "VALUES (%s, LAST_INSERT_ID(1)) "
             . "ON DUPLICATE KEY UPDATE `counter` = LAST_INSERT_ID(`counter` + 1)";

        // LAST_INSERT_ID(expr) is per-connection; queryAndGetResults can
        // swap the underlying connection on transient-error retry, which
        // would lose the post-increment value we just wrote. The bump
        // must execute exactly once on exactly one connection so the
        // caller can read the contribution back from $wpdb->insert_id.
        // DAO-bypass-approved: bump must stay on one connection (LAST_INSERT_ID is per-connection).
        $prepared = $wpdb->prepare($sql, $name);
        // DAO-bypass-approved: bump must stay on one connection (LAST_INSERT_ID is per-connection).
        $wpdb->query($prepared);

        self::invalidateCache($name);

        return (int)($wpdb->insert_id ?? 0);
    }

    /**
     * Read the current counter for `$name`. Returns 0 when the row does
     * not exist (fresh install or pre-Phase-1 upgrade); callers therefore
     * never see undefined behavior, only "no mutations observed yet".
     *
     * @param string $name Logical counter row.
     * @return int Current counter value, or 0 if the row is absent.
     */
    public static function current(string $name = self::NAME_REDIRECTS): int {
        global $wpdb;
        self::ensureTable();
        $table = self::tableName();

        $sql = "SELECT `counter` FROM `{$table}` WHERE `name` = %s";
        // Stage-boundary read for a runner that has just bumped on
        // another connection; routing through the DAO's auto-CREATE-
        // and-retry machinery on missing-table would mask the "table
        // just got dropped under us" signal Phase 2 needs to detect.
        // DAO-bypass-approved: stage-boundary read must not auto-CREATE on missing-table.
        $prepared = $wpdb->prepare($sql, $name);
        // DAO-bypass-approved: stage-boundary read must not auto-CREATE on missing-table.
        $value = $wpdb->get_var($prepared);

        if ($value === null) {
            return 0;
        }
        return (int)$value;
    }

    /**
     * Drop the cached current() value for `$name`. Bump invalidates so
     * a subsequent get_option-style read elsewhere (none exists today,
     * but Phase 2 may add one) sees the fresh disk value rather than
     * a write-through stale cache.
     *
     * Best-effort: when the WP object cache stack is unavailable
     * (very early bootstrap) the call is a no-op.
     */
    private static function invalidateCache(string $name): void {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::CACHE_KEY_PREFIX . $name, self::CACHE_GROUP);
        }
    }
}
