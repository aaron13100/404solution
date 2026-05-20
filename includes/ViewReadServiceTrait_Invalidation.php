<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache invalidation orchestration for ViewReadService.
 *
 * Extracted from ViewReadService to keep the host class under the 1500-line
 * modularity limit. Contains bulk mutation gating, status count cache
 * invalidation, view snapshot invalidation, regex cache clearing,
 * and mutation watermark management.
 */
trait ABJ_404_Solution_ViewReadServiceTrait_Invalidation {

    /** @return int */
    private function bumpMutationWatermark(): int {
        if (!class_exists('ABJ_404_Solution_MutationWatermark')) {
            return 0;
        }
        return ABJ_404_Solution_MutationWatermark::bump();
    }

    /** @return void */
    private function setSqlBigSelects(): void {
        $ignoreErrorsOptions = array('log_errors' => false);
        $this->dbCore->queryAndGetResults("set session max_join_size = 18446744073709551615",
            $ignoreErrorsOptions);
        $this->dbCore->queryAndGetResults("set session sql_big_selects = 1", $ignoreErrorsOptions);
    }

    /**
     * Open/close the bulk-mutation window. Bulk importers (CSV import,
     * sitemap regeneration, future bulk admin actions) wrap their per-row
     * loop with this. The callable is invoked while the flag is set;
     * exceptions are rethrown but the flag is always restored.
     *
     * On window close, issues exactly one bumpMutationWatermark() to
     * represent the entire batch as a single mutation tick. Without this
     * the per-row chain bumps are all suppressed and a later
     * markViewDoneInvalidatedByAdminMutation() call would observe the
     * pre-batch counter, leaving the admin-visibility gate un-raised
     * and the next read returning the stale snapshot (the failure mode
     * WpCliMutationEndToEndCharacterizationTest::testCliBulkAddFromCsv
     * pins). The bump fires even when the callable returned early or
     * threw, because the side effect of "we entered a mutation window"
     * is what the watermark documents -- whether downstream rows landed
     * is the caller's concern.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runWithDeferredInvalidation(callable $work) {
        $prior = self::$bulkMutationInProgress;
        self::$bulkMutationInProgress = true;
        try {
            return $work();
        } finally {
            self::$bulkMutationInProgress = $prior;
            $this->bumpMutationWatermark();
        }
    }

    /**
     * Invalidate cached status counts.
     * Call this when redirects are created, updated, or deleted.
     *
     * No-op when {@see self::$bulkMutationInProgress} is set; the bulk
     * caller must issue one final invalidation after the loop completes.
     */
    /** @return void */
    function invalidateStatusCountsCache(): void {
        if (self::$bulkMutationInProgress) {
            return;
        }
        delete_transient(self::CACHE_KEY_REDIRECT_STATUS);
        delete_transient(self::CACHE_KEY_CAPTURED_STATUS);
        delete_transient(self::CACHE_KEY_HIGH_IMPACT_CAPTURED);
        $this->invalidateViewSnapshotCache();
    }

    /**
     * Clear the view snapshot cache so the admin redirect/captured tables
     * reflect newly created, updated, trashed, or deleted redirects immediately.
     *
     * This clears both the custom wp_abj404_view_cache table and the
     * WordPress transients used as a secondary cache layer.
     *
     * @return void
     */
    function invalidateViewSnapshotCache(): void {
        // Clear view_done freshness so the read path's TTL check trips and
        // a rebuild gets scheduled on the next request. The runner remains
        // the sole owner of progress markers, the S1 prefix capture, and
        // the transient buffer tables: external code (this seam included)
        // must not touch them. scheduleViewDoneRebuild() is idempotent
        // (wp_next_scheduled short-circuit) so concurrent mutators do not
        // pile up cron events.
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneFreshnessOptionName());
        }
        $this->requireViewBuildOrchestrator()->invalidateViewDoneServeableCacheBridge();
        $this->requireViewBuildOrchestrator()->scheduleViewDoneRebuild();

        // Clear all rows from the view cache table. log_errors=false marks
        // this as a best-effort operation (the cache expires naturally via
        // TTL if the DELETE fails). skip_repair=true blocks the missing-
        // table auto-create + retry path: a missing view_cache means
        // "nothing to invalidate"; spinning up the full createDatabaseTables
        // flow to make the DELETE succeed is wasteful in production and in
        // tests it cascades correctCollations -> bumpMutationWatermark, which
        // breaks the "exactly one bump per source-data mutation" contract
        // pinned by MixedSourceConcurrentMutationIntegrationTest.
        $query = "DELETE FROM {wp_abj404_view_cache} WHERE 1=1";
        $this->dbCore->queryAndGetResults($query, array('log_errors' => false, 'skip_repair' => true));

        // Clear WordPress transients for view row and count snapshots.
        // The transient keys are hashed (e.g. abj404_view_rows_<md5>), so
        // we delete by prefix from wp_options directly.
        global $wpdb;
        if (isset($wpdb->options) && method_exists($wpdb, 'query')) {
            // @utf8-audit: opt-out -- $wpdb->options is the WordPress core
            // options table name (system value); never user input.
            /** @var string $optionsTable */
            $optionsTable = esc_sql($wpdb->options);
            // DAO-bypass-approved: View-cache clear targets wp_options -- outside the plugin's owned tables; runs during cache invalidation hot path; failure is best-effort
            $wpdb->query(
                "DELETE FROM `{$optionsTable}` WHERE option_name LIKE '_transient_abj404_view_%'"
                . " OR option_name LIKE '_transient_timeout_abj404_view_%'"
            );
        }
    }

    /**
     * Clear the per-request regex redirects cache.
     * Primarily used for testing. In production, the cache resets automatically
     * on each new request since it uses static variables.
     */
    /** @return void */
    function clearRegexRedirectsCache(): void {
        $this->redirectsRepo->clearRegexRedirectsCache();
    }

    /**
     * Read the current mutation watermark for the per-blog cache key, with
     * a defensive fallback of 0 when the watermark primitive is unavailable
     * for any reason: the class is not yet loaded (cold-bootstrap path
     * before the autoloader resolved it), the global `$wpdb` does not yet
     * expose the query / prepare / get_var triple the primitive needs
     * (legacy unit-test mocks that only expose `query`), or the underlying
     * MariaDB connection is mid-failure. The fallback collapses to a
     * single shared "version 0" bucket; correctness still holds because:
     *
     *   - In a healthy install, the first real mutation produces a
     *     non-zero watermark for every subsequent read, so cache keys
     *     diverge by version as designed.
     *   - In a degraded environment where the watermark can't be read, no
     *     watermark-bumping mutation can succeed either (the same wpdb is
     *     in use), so the cache is implicitly version-stable.
     *
     * Throwable catch is intentional: this primitive sits on the read hot
     * path and must never propagate a watermark read failure as a hard
     * fault into `getViewSnapshotCacheKey()`. A swallowed read produces
     * a less-precise cache key, not a broken read.
     *
     * @return int
     */
    private function readMutationWatermarkForCacheKey(): int {
        if (!class_exists('ABJ_404_Solution_MutationWatermark')) {
            return 0;
        }
        try {
            return ABJ_404_Solution_MutationWatermark::current();
            // allow-silent-catch: degraded wpdb (e.g. unit-test mocks lacking prepare()) falls back to "version 0" cache bucket; never propagate a watermark-read fault into the read hot path
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
