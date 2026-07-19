<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/StatsRepositoryInterface.php';
require_once __DIR__ . '/StatsRefreshLock.php';
require_once __DIR__ . '/StatsReadRepository.php';
require_once __DIR__ . '/StatsDashboardSnapshotCache.php';
require_once __DIR__ . '/StatsDigestDataProvider.php';
require_once __DIR__ . '/../repositories/ContentKeywordsRepository.php';

/**
 * Stable facade for stats aggregation, dashboard snapshots, digest data, and keyword updates.
 */
class ABJ_404_Solution_StatsRepository implements ABJ_404_Solution_StatsRepositoryInterface {

    /** @var int Max age for cached stats-periodic aggregates. */
    const PERIODIC_STATS_CACHE_TTL_SECONDS = ABJ_404_Solution_StatsReadRepository::PERIODIC_STATS_CACHE_TTL_SECONDS;
    /** @var int Minimum interval before recalculating expensive stats aggregates. */
    const PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS = ABJ_404_Solution_StatsReadRepository::PERIODIC_STATS_REFRESH_COOLDOWN_SECONDS;
    /** @var int Retention for dashboard stats snapshot payload. */
    const STATS_DASHBOARD_CACHE_TTL_SECONDS = ABJ_404_Solution_StatsDashboardSnapshotCache::STATS_DASHBOARD_CACHE_TTL_SECONDS;
    /** @var int Minimum time between full stats snapshot recomputes. */
    const STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS = ABJ_404_Solution_StatsDashboardSnapshotCache::STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS;
    /** @var int Cooldown for distributed refresh locks. */
    const REFRESH_LOCK_COOLDOWN_SECONDS = ABJ_404_Solution_StatsRefreshLock::REFRESH_LOCK_COOLDOWN_SECONDS;

    /** @var ABJ_404_Solution_StatsReadRepository */
    private $statsReadRepository;

    /** @var ABJ_404_Solution_StatsDashboardSnapshotCache */
    private $dashboardSnapshotCache;

    /** @var ABJ_404_Solution_StatsDigestDataProvider */
    private $digestDataProvider;

    /** @var ABJ_404_Solution_ContentKeywordsRepository */
    private $contentKeywordsRepository;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_LogsRepositoryInterface $logsRepo
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_LogsRepositoryInterface $logsRepo,
        $functions = null,
        $logging = null
    ) {
        $f = $functions !== null ? $functions : abj_service('functions');
        $logger = $logging !== null ? $logging : abj_service('logging');
        $refreshLock = new ABJ_404_Solution_StatsRefreshLock($dbCore);

        $this->statsReadRepository = new ABJ_404_Solution_StatsReadRepository(
            $dbCore,
            $logsRepo,
            $logger,
            $refreshLock
        );
        $this->dashboardSnapshotCache = new ABJ_404_Solution_StatsDashboardSnapshotCache(
            $this->statsReadRepository,
            $refreshLock,
            $logger
        );
        $this->digestDataProvider = new ABJ_404_Solution_StatsDigestDataProvider(
            $dbCore,
            $logsRepo,
            $this->statsReadRepository,
            $logger,
            $dbCore->collationHelper()
        );
        $this->contentKeywordsRepository = new ABJ_404_Solution_ContentKeywordsRepository(
            $dbCore,
            $f,
            $logger
        );
    }

    /** @inheritDoc */
    function getStatsCount($query, array $valueParams) {
        return $this->statsReadRepository->getStatsCount($query, $valueParams);
    }

    /** @inheritDoc */
    function getPeriodicStatsSummary($sinceTimestamp, $notFoundDest = '404') {
        return $this->statsReadRepository->getPeriodicStatsSummary($sinceTimestamp, $notFoundDest);
    }

    /** @inheritDoc */
    function getPeriodicStatsSummariesCached($notFoundDest = '404') {
        return $this->statsReadRepository->getPeriodicStatsSummariesCached($notFoundDest);
    }

    /** @inheritDoc */
    function getStatsDashboardSnapshot($allowStale = true) {
        return $this->dashboardSnapshotCache->getStatsDashboardSnapshot($allowStale);
    }

    /** @inheritDoc */
    function refreshStatsDashboardSnapshot($force = false) {
        return $this->dashboardSnapshotCache->refresh($force);
    }

    /** @inheritDoc */
    function getEarliestLogTimestamp() {
        return $this->statsReadRepository->getEarliestLogTimestamp();
    }

    /** @inheritDoc */
    function getConfidenceBandCounts() {
        return $this->statsReadRepository->getConfidenceBandCounts();
    }

    /** @inheritDoc */
    function getTopCapturedForDigest(int $limit): array {
        return $this->digestDataProvider->getTopCapturedForDigest($limit);
    }

    /** @inheritDoc */
    function buildTopCapturedForDigestQuery(int $limit): string {
        return $this->digestDataProvider->buildTopCapturedForDigestQuery($limit);
    }

    /** @inheritDoc */
    function getDigestSummaryStats(): array {
        return $this->digestDataProvider->getDigestSummaryStats(array($this, 'getStatsCount'));
    }

    /** @inheritDoc */
    function getCapturedCountForNotification(): int {
        return $this->digestDataProvider->getCapturedCountForNotification();
    }

    /** @inheritDoc */
    function getPostsNeedingContentKeywords(int $limit = 500): array {
        return $this->contentKeywordsRepository->getPostsNeedingContentKeywords($limit);
    }

    /** @inheritDoc */
    function bulkUpdateContentKeywords(array $idToKeywords): void {
        $this->contentKeywordsRepository->bulkUpdateContentKeywords($idToKeywords);
    }
}
