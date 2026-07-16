<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps foreground status-count reads cache-only and coordinates cron refreshes.
 */
class ABJ_404_Solution_StatusCountsRefreshCoordinator {

    const SCOPE_REDIRECTS = 'redirects';
    const SCOPE_CAPTURED = 'captured';
    const SCOPE_HIGH_IMPACT = 'high-impact';

    /** @var ABJ_404_Solution_StatusCountsRepository */
    private $statusCounts;

    /** @var ABJ_404_Solution_StatsRefreshLock */
    private $refreshLock;

    /** @var callable(string):void */
    private $warn;

    public function __construct(
        ABJ_404_Solution_StatusCountsRepository $statusCounts,
        ABJ_404_Solution_StatsRefreshLock $refreshLock,
        callable $warn
    ) {
        $this->statusCounts = $statusCounts;
        $this->refreshLock = $refreshLock;
        $this->warn = $warn;
    }

    /** @return array<string, int> */
    public function getRedirectStatusCounts(): array {
        return $this->resolveRead(
            self::SCOPE_REDIRECTS,
            $this->statusCounts->readRedirectStatusCountsCache()
        );
    }

    /** @return array<string, int> */
    public function getCapturedStatusCounts(): array {
        return $this->resolveRead(
            self::SCOPE_CAPTURED,
            $this->statusCounts->readCapturedStatusCountsCache()
        );
    }

    /** @return int|null */
    public function getHighImpactCapturedCount(): ?int {
        $state = $this->statusCounts->readHighImpactCapturedCountCache();
        if ($state['needs_refresh']) {
            $this->scheduleRefresh(self::SCOPE_HIGH_IMPACT);
        }
        return $state['count'];
    }

    /**
     * Cron-only recomputation. A direct foreground call can only enqueue work.
     */
    public function refresh(string $scope): void {
        $refreshers = array(
            self::SCOPE_REDIRECTS => array(
                'cache_key' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS,
                'callback' => array($this->statusCounts, 'recomputeRedirectStatusCounts'),
            ),
            self::SCOPE_CAPTURED => array(
                'cache_key' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS,
                'callback' => array($this->statusCounts, 'recomputeCapturedStatusCounts'),
            ),
            self::SCOPE_HIGH_IMPACT => array(
                'cache_key' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED,
                'callback' => array($this->statusCounts, 'recomputeHighImpactCapturedCount'),
            ),
        );
        if (!isset($refreshers[$scope])) {
            call_user_func($this->warn, 'Ignoring unknown status-count refresh scope: ' . $scope);
            return;
        }
        if (!$this->isCronRequest()) {
            $this->scheduleRefresh($scope);
            return;
        }

        $refresh = $refreshers[$scope];
        $cacheKey = $refresh['cache_key'];
        if (!$this->refreshLock->acquire($cacheKey)) {
            return;
        }
        try {
            $refreshed = call_user_func($refresh['callback']);
            if ($refreshed !== true) {
                call_user_func($this->warn,
                    'Status-count refresh failed for scope ' . $scope . '; retaining the last-known cache.'
                );
            }
        } finally {
            $this->refreshLock->release($cacheKey);
        }
    }

    /**
     * @param array{counts: array<string, int>, needs_refresh: bool, incomplete: bool} $state
     * @return array<string, int>
     */
    private function resolveRead(string $scope, array $state): array {
        if ($state['needs_refresh']) {
            $this->scheduleRefresh($scope);
        }
        $counts = $state['counts'];
        if ($state['incomplete']) {
            $counts['_incomplete'] = 1;
        }
        return $counts;
    }

    private function scheduleRefresh(string $scope): void {
        abj_cron_scheduler()->scheduleSingleIfMissing(
            ABJ_404_Solution_CronScheduler::HOOK_REFRESH_STATUS_COUNTS,
            0,
            array($scope)
        );
    }

    private function isCronRequest(): bool {
        return function_exists('wp_doing_cron') && wp_doing_cron();
    }
}
