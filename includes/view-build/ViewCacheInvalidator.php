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
        self::markTransientStale(array(
            'current' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS,
            'last_known' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS_LAST_KNOWN,
            'kind' => 'array',
        ));
        self::markTransientStale(array(
            'current' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS,
            'last_known' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN,
            'kind' => 'array',
        ));
        self::markTransientStale(array(
            'current' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED,
            'last_known' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED_LAST_KNOWN,
            'kind' => 'count',
        ));
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
        if (!self::claimCapturedCountInvalidateCooldown()) {
            return;
        }
        self::invalidateCapturedStatusCountsCache();
    }

    /**
     * Single-flight claim on the captured-count invalidation cooldown.
     * Returns true only for the caller that wins the race.
     *
     * A plain get_transient()/set_transient() pair is check-then-act: under
     * a burst of near-simultaneous captured-URL inserts (bot-scanner flood
     * traffic across parallel PHP-FPM workers -- the exact profile this
     * debounce exists for), multiple requests can each read an empty
     * cooldown before any of them writes it, so the "collapse a burst to
     * one invalidation" guarantee silently fails under real concurrency.
     * Same TOCTOU shape as Ajax_Php::consumeRateLimit(), fixed the same way:
     * wp_cache_add() only succeeds in creating the key if it doesn't already
     * exist, so concurrent callers on a persistent object cache serialize on
     * that add. Sites without a persistent object cache keep the narrower
     * pre-existing transient race rather than gain a DB dependency in this
     * intentionally zero-dependency method (called from the frontend
     * capture hot path without a wired invalidator instance).
     */
    private static function claimCapturedCountInvalidateCooldown(): bool {
        $key = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_COUNT_INVALIDATE_COOLDOWN;
        $ttl = ABJ_404_Solution_ViewReadRuntimeState::CAPTURED_COUNT_INVALIDATE_COOLDOWN_SECONDS;
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()
            && function_exists('wp_cache_add')) {
            return (bool)wp_cache_add($key, 1, 'abj404_view_cache_invalidate', $ttl);
        }
        if (get_transient($key)) {
            return false;
        }
        set_transient($key, 1, $ttl);
        return true;
    }

    /**
     * Mark captured-scoped count caches stale without applying a debounce.
     */
    public static function invalidateCapturedStatusCountsCache(): void {
        if (ABJ_404_Solution_ViewReadRuntimeState::$bulkMutationInProgress) {
            return;
        }
        self::markTransientStale(array(
            'current' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS,
            'last_known' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS_LAST_KNOWN,
            'kind' => 'array',
        ));
        self::markTransientStale(array(
            'current' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED,
            'last_known' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED_LAST_KNOWN,
            'kind' => 'count',
        ));
    }

    /**
     * Preserve a trustworthy current value before expiring its fresh cache key.
     *
     * @param array{current:string,last_known:string,kind:'array'|'count'} $cache
     */
    private static function markTransientStale(array $cache): void {
        $current = get_transient($cache['current']);
        $isTrustworthy = ($cache['kind'] === 'array' && is_array($current))
            || ($cache['kind'] === 'count' && is_numeric($current));
        if ($isTrustworthy) {
            // allow-cache-empty: numeric zero is a trustworthy computed count and shaped all-zero status arrays still contain their named keys.
            set_transient(
                $cache['last_known'],
                $cache['kind'] === 'count' ? intval($current) : $current,
                ABJ_404_Solution_ViewReadRuntimeState::STATUS_LAST_KNOWN_CACHE_TTL
            );
        }
        delete_transient($cache['current']);
    }

    /**
     * Expire the view snapshot: mark the built-at freshness marker stale so the
     * next admin read treats the derived view as out of date.
     *
     * Deliberately one delete_option() and nothing else. Through 4.2.x this
     * also cleared a snapshot RESULT cache -- rows in {prefix}abj404_view_cache
     * and per-key transients named abj404_view_* -- but denorm Step 3e-B
     * (5f4fcfb4, shipped in 4.3.1) removed that subsystem: the admin table read
     * is now live off the abj404_redirects denorm columns, and no code path has
     * written either store since. The two DELETEs outlived their writers and
     * ran on every redirect create, update and delete to remove rows nothing
     * can create, so they were removed rather than bounded:
     *
     *   - `DELETE FROM {wp_abj404_view_cache} WHERE 1=1` -- a table-wide
     *     destructive statement whose result set is permanently empty.
     *   - `option_name LIKE '_transient_abj404_view_%'` against wp_options --
     *     worse, because the pattern begins with `_`, LIKE's single-character
     *     wildcard, leaving the range optimizer no literal prefix to seek on.
     *     Every row of the site's largest, hottest shared table was read, on
     *     the redirect-mutation path, to delete none of them. (The plugin's
     *     other wp_options sweeps -- Uninstaller, PluginLogicLifecycle,
     *     DatabaseUpgradeDailyMaintenance -- all go through prepare() with
     *     esc_like(); this one never did.)
     *
     * Residue on sites that upgraded from a snapshot-cache version is inert:
     * the view_cache rows are read by nothing and the table is dropped at
     * uninstall, and the orphaned transients carry their `_transient_timeout_`
     * companions, so WordPress's own expired-transient collection reaps them.
     * Physically dropping the vestigial table is the separately-tracked
     * one-way-door step (i463-C/D), alongside DropStagedViewTables.
     *
     * @return void
     */
    public function invalidateViewSnapshotCache(): void {
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneFreshnessOptionName);
        }
    }

    /**
     * @return void
     */
    public function clearRegexRedirectsCache(): void {
        $this->redirectsRepo->clearRegexRedirectsCache();
    }

}
