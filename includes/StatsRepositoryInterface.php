<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public contract for stats aggregation, dashboard snapshots, and digest data.
 *
 * Extracted from DataAccess in Phase 4 of the DataAccess refactor. Callers that
 * need stats counts, periodic summaries, dashboard snapshots, digest data,
 * or content-keyword operations program against this interface.
 */
interface ABJ_404_Solution_StatsRepositoryInterface {

    // =========================================================================
    // Core stats queries
    // =========================================================================

    /**
     * @param string $query
     * @param array<int|string, mixed> $valueParams
     * @return int
     */
    public function getStatsCount($query, array $valueParams);

    /**
     * @param int $sinceTimestamp
     * @param string $notFoundDest
     * @return array{
     *   disp404:int,
     *   distinct404:int,
     *   visitors404:int,
     *   refer404:int,
     *   redirected:int,
     *   distinctredirected:int,
     *   distinctvisitors:int,
     *   distinctrefer:int
     * }
     */
    public function getPeriodicStatsSummary($sinceTimestamp, $notFoundDest = '404');

    /**
     * @param string $notFoundDest
     * @return array{
     *   today:array<string,int>,
     *   month:array<string,int>,
     *   year:array<string,int>,
     *   all:array<string,int>
     * }
     */
    public function getPeriodicStatsSummariesCached($notFoundDest = '404');

    // =========================================================================
    // Dashboard snapshot
    // =========================================================================

    /**
     * @param bool $allowStale
     * @return array{refreshed_at:int,hash:string,data:array<string, mixed>}
     */
    public function getStatsDashboardSnapshot($allowStale = true);

    /**
     * @param bool $force
     * @return array{refreshed_at:int,hash:string,data:array<string, mixed>}
     */
    public function refreshStatsDashboardSnapshot($force = false);

    // =========================================================================
    // Log timestamp
    // =========================================================================

    /** @return int */
    public function getEarliestLogTimestamp();

    // =========================================================================
    // Email digest
    // =========================================================================

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getTopCapturedForDigest(int $limit): array;

    /**
     * @param int $limit
     * @return string
     */
    public function buildTopCapturedForDigestQuery(int $limit): string;

    /**
     * @return array{total_captured: int, total_manual: int, total_auto: int}
     */
    public function getDigestSummaryStats(): array;

    /** @return int */
    public function getCapturedCountForNotification(): int;

    // =========================================================================
    // Content keywords (permalink cache)
    // =========================================================================

    /**
     * @param int $limit
     * @return array<int, object>
     */
    public function getPostsNeedingContentKeywords(int $limit = 500): array;

    /**
     * @param array<int, string> $idToKeywords
     * @return void
     */
    public function bulkUpdateContentKeywords(array $idToKeywords): void;
}

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
        $service = class_exists('ABJ_404_Solution_ServiceContainer')
            ? ABJ_404_Solution_ServiceContainer::safeGet('stats_repository')
            : null;
        if ($service instanceof ABJ_404_Solution_StatsRepositoryInterface) {
            return $service;
        }

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
    public function getTopCapturedForDigest(int $limit): array { $this->unavailable(); }
    public function buildTopCapturedForDigestQuery(int $limit): string { $this->unavailable(); }
    public function getDigestSummaryStats(): array { $this->unavailable(); }
    public function getCapturedCountForNotification(): int { $this->unavailable(); }
    public function getPostsNeedingContentKeywords(int $limit = 500): array { $this->unavailable(); }
    public function bulkUpdateContentKeywords(array $idToKeywords): void { $this->unavailable(); }
}
