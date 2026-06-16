<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/StatsRepositoryInterface.php';

/**
 * Explicit placeholder for legacy construction paths that do not exercise
 * stats behavior. Calling a stats method without injecting/registering the
 * real repository is a configuration error, not a DataAccess fallback.
 */
class ABJ_404_Solution_UnavailableStatsRepository implements ABJ_404_Solution_StatsRepositoryInterface {

    /** @var string */
    private $context;

    public function __construct(string $context = '') {
        $this->context = $context;
    }

    public static function resolve(string $context = ''): ABJ_404_Solution_StatsRepositoryInterface {
        return new self($context);
    }

    /** @return never */
    private function unavailable() {
        throw new RuntimeException(
            'StatsRepositoryInterface is required'
            . ($this->context !== '' ? ' for ' . $this->context : '')
            . '; inject stats_repository instead of resolving stats through DataAccess.'
        );
    }

    public function getStatsCount($query, array $valueParams) { $this->unavailable(); }
    public function getPeriodicStatsSummary($sinceTimestamp, $notFoundDest = '404') { $this->unavailable(); }
    public function getPeriodicStatsSummariesCached($notFoundDest = '404') { $this->unavailable(); }
    public function getStatsDashboardSnapshot($allowStale = true) { $this->unavailable(); }
    public function refreshStatsDashboardSnapshot($force = false) { $this->unavailable(); }
    public function getEarliestLogTimestamp() { $this->unavailable(); }
    public function getConfidenceBandCounts() { $this->unavailable(); }
    public function getTopCapturedForDigest(int $limit): array { $this->unavailable(); }
    public function buildTopCapturedForDigestQuery(int $limit): string { $this->unavailable(); }
    public function getDigestSummaryStats(): array { $this->unavailable(); }
    public function getCapturedCountForNotification(): int { $this->unavailable(); }
    public function getPostsNeedingContentKeywords(int $limit = 500): array { $this->unavailable(); }
    public function bulkUpdateContentKeywords(array $idToKeywords): void { $this->unavailable(); }
}
