<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility stub. The watermark-counter primitive that lived here
 * (a side table `wp_abj404_mutation_watermark` plus an upsert-on-every-
 * mutation `bump()` call) was removed in t_260523_224315_207 -- see
 * docs/design-lesson-watermark-overengineering.md for the full design
 * post-mortem.
 *
 * "Did anything change since I last looked?" is now answered by
 * `ABJ_404_Solution_MutationDataSignature::current()`, which derives the
 * signal from data already in `wp_abj404_redirects` (the row's
 * `timestamp` column plus `COUNT(*)` to catch DELETE-only mutations). No
 * hot-path writes, no parallel coordination structure.
 *
 * This file is kept as a no-op shim so existing tests that call
 * `resetEnsuredForTesting()` or reference the legacy class constants
 * compile without modification.
 * Production code no longer references this class; the
 * `wp_abj404_mutation_watermark` table is dropped by the upgrade
 * routine.
 */
final class ABJ_404_Solution_MutationWatermark {

    const NAME_REDIRECTS = 'redirects';
    const CACHE_GROUP = 'abj404';
    const CACHE_KEY_PREFIX = 'mutation_watermark.';

    /**
     * Historical accessor for the dropped counter table name. Returns the
     * pre-removal name (`{$prefix}abj404_mutation_watermark`) so callers
     * still resolving the legacy table can locate it for cleanup.
     */
    public static function tableName(): string {
        global $wpdb;
        $prefix = isset($wpdb) && isset($wpdb->prefix) ? strtolower((string)$wpdb->prefix) : 'wp_';
        return $prefix . 'abj404_mutation_watermark';
    }

    /** No-op. Retained because legacy tests call it in tearDown. */
    public static function resetEnsuredForTesting(): void {
    }

    /** No-op. The side table no longer exists. */
    public static function ensureTable(): void {
    }
}
