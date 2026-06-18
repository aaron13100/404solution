<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache invalidation orchestration for admin view queries. Coordinates
 * status-count cache invalidation, view snapshot invalidation, and regex
 * cache clearing when redirects mutate.
 */
class ABJ_404_Solution_ViewCacheInvalidator {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepo;

    /** @var string */
    private $viewDoneFreshnessOptionName;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepo
     * @param string $viewDoneFreshnessOptionName
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_RedirectsRepository $redirectsRepo,
        string $viewDoneFreshnessOptionName
    ) {
        $this->dbCore = $dbCore;
        $this->redirectsRepo = $redirectsRepo;
        $this->viewDoneFreshnessOptionName = $viewDoneFreshnessOptionName;
    }

    /** @return void */
    public function setSqlBigSelects(): void {
        $ignoreErrorsOptions = array('log_errors' => false);
        $this->dbCore->queryAndGetResults("set session max_join_size = 18446744073709551615",
            $ignoreErrorsOptions);
        $this->dbCore->queryAndGetResults("set session sql_big_selects = 1", $ignoreErrorsOptions);
    }

    /**
     * Open/close the bulk-mutation window.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runWithDeferredInvalidation(callable $work) {
        $prior = ABJ_404_Solution_ViewReadRuntimeState::$bulkMutationInProgress;
        ABJ_404_Solution_ViewReadRuntimeState::$bulkMutationInProgress = true;
        try {
            return $work();
        } finally {
            ABJ_404_Solution_ViewReadRuntimeState::$bulkMutationInProgress = $prior;
        }
    }

    /**
     * Invalidate cached status counts.
     * Call this when redirects are created, updated, or deleted.
     *
     * @return void
     */
    public function invalidateStatusCountsCache(): void {
        if (ABJ_404_Solution_ViewReadRuntimeState::$bulkMutationInProgress) {
            return;
        }
        delete_transient(ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS);
        delete_transient(ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS);
        delete_transient(ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED);
        $this->invalidateViewSnapshotCache();
    }

    /**
     * Debounced, captured-scoped status-count invalidation for the
     * high-frequency captured-insert path (report.md Finding 4).
     *
     * A captured 404 insert only changes the captured + high-impact counts; it
     * never changes the redirect (manual/auto/regex) counts, so this leaves the
     * REDIRECT status-count cache intact (the redirects tab stays warm through a
     * capture burst). It also collapses a burst into at most one invalidation per
     * cooldown window, so the SUM(CASE) captured-count aggregate is recomputed at
     * most once per window instead of cold on every admin load.
     *
     * It does NOT clear the view snapshot: the admin table read is live off
     * wp_abj404_redirects (Denorm Step 3b -- no snapshot result cache), so a new
     * captured row appears on the next read regardless. The detect-only poll
     * (Finding 3) reads the same live source, so staleness is still detected.
     *
     * Static + pure transient ops: holds no instance state, so the
     * frontend-capture hot path can call it without a fully-wired invalidator.
     *
     * @return void
     */
    public static function invalidateCapturedStatusCountsCacheDebounced(): void {
        if (ABJ_404_Solution_ViewReadRuntimeState::$bulkMutationInProgress) {
            return;
        }
        $cooldownKey = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_COUNT_INVALIDATE_COOLDOWN;
        if (get_transient($cooldownKey)) {
            return;
        }
        set_transient($cooldownKey, 1,
            ABJ_404_Solution_ViewReadRuntimeState::CAPTURED_COUNT_INVALIDATE_COOLDOWN_SECONDS);
        delete_transient(ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS);
        delete_transient(ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED);
    }

    /**
     * Clear the view snapshot cache.
     *
     * @return void
     */
    public function invalidateViewSnapshotCache(): void {
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneFreshnessOptionName);
        }

        $query = "DELETE FROM {wp_abj404_view_cache} WHERE 1=1";
        $this->dbCore->queryAndGetResults($query, array('log_errors' => false, 'skip_repair' => true));

        global $wpdb;
        if (isset($wpdb->options) && method_exists($wpdb, 'query')) {
            /** @var string $optionsTable */
            // @utf8-audit: opt-out — wpdb->options is a WordPress-controlled table identifier.
            $optionsTable = esc_sql($wpdb->options);
            // DAO-bypass-approved: View-cache clear targets wp_options -- outside the plugin's owned tables; runs during cache invalidation hot path; failure is best-effort
            $wpdb->query(
                "DELETE FROM `{$optionsTable}` WHERE option_name LIKE '_transient_abj404_view_%'"
                . " OR option_name LIKE '_transient_timeout_abj404_view_%'"
            );
        }
    }

    /**
     * @return void
     */
    public function clearRegexRedirectsCache(): void {
        $this->redirectsRepo->clearRegexRedirectsCache();
    }

}
