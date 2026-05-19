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
