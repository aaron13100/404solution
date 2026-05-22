<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache invalidation orchestration for admin view queries.
 *
 * Manages bulk mutation gating, status count cache invalidation,
 * view snapshot invalidation, regex cache clearing, and mutation
 * watermark reads.
 */
class ABJ_404_Solution_ViewCacheInvalidator {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepo;

    /** @var string */
    private $viewDoneFreshnessOptionName;

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface|null */
    private $viewBuildOrchestrator;

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

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->viewBuildOrchestrator = $viewBuildOrchestrator;
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestratorInterface */
    private function requireViewBuildOrchestrator(): ABJ_404_Solution_ViewBuildOrchestratorInterface {
        if ($this->viewBuildOrchestrator === null) {
            throw new \RuntimeException('ViewCacheInvalidator requires ViewBuildOrchestrator (call setViewBuildOrchestrator first)'); // allow-raw-error: assertion, should never reach user
        }
        return $this->viewBuildOrchestrator;
    }

    /** @return int */
    private function bumpMutationWatermark(): int {
        if (!class_exists('ABJ_404_Solution_MutationWatermark')) {
            return 0;
        }
        return ABJ_404_Solution_MutationWatermark::bump();
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
            $this->bumpMutationWatermark();
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
     * Clear the view snapshot cache.
     *
     * @return void
     */
    public function invalidateViewSnapshotCache(): void {
        if (function_exists('delete_option')) {
            delete_option($this->viewDoneFreshnessOptionName);
        }
        $this->requireViewBuildOrchestrator()->invalidateViewDoneServeableCacheBridge();
        $this->requireViewBuildOrchestrator()->scheduleViewDoneRebuild();

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

    /**
     * Read the current mutation watermark for the per-blog cache key.
     *
     * @return int
     */
    public function readMutationWatermarkForCacheKey(): int {
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
