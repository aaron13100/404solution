<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the dashboard stats snapshot cache and stale-fallback refresh policy.
 */
class ABJ_404_Solution_StatsDashboardSnapshotCache {

    /** @var int Retention for dashboard stats snapshot payload. */
    const STATS_DASHBOARD_CACHE_TTL_SECONDS = 86400;
    /** @var int Minimum time between full stats snapshot recomputes. */
    const STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS = 30;

    /** @var ABJ_404_Solution_StatsReadRepository */
    private $statsReadRepository;
    /** @var ABJ_404_Solution_StatsRefreshLock */
    private $refreshLock;
    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_StatsReadRepository $statsReadRepository
     * @param ABJ_404_Solution_StatsRefreshLock $refreshLock
     * @param ABJ_404_Solution_Logging $logging
     */
    public function __construct(
        ABJ_404_Solution_StatsReadRepository $statsReadRepository,
        ABJ_404_Solution_StatsRefreshLock $refreshLock,
        $logging
    ) {
        $this->statsReadRepository = $statsReadRepository;
        $this->refreshLock = $refreshLock;
        $this->logger = $logging;
    }

    /**
     * @param bool $allowStale
     * @return array{refreshed_at:int,hash:string,data:array<string, mixed>}
     */
    public function getStatsDashboardSnapshot($allowStale = true) {
        $cached = $this->getFromCache();
        if ($cached !== null && !empty($cached['data']) && $allowStale) {
            return $cached;
        }

        return $this->refresh(false);
    }

    /**
     * @param bool $force
     * @return array{refreshed_at:int,hash:string,data:array<string, mixed>}
     */
    public function refresh($force = false) {
        $cached = $this->getFromCache();
        $hasCachedData = ($cached !== null && !empty($cached['data']));
        $cachedAge = $hasCachedData ? max(0, abj_clock()->now() - (is_scalar($cached['refreshed_at'] ?? 0) ? intval($cached['refreshed_at'] ?? 0) : 0)) : PHP_INT_MAX;

        if (!$force && $hasCachedData && $cachedAge < self::STATS_DASHBOARD_REFRESH_COOLDOWN_SECONDS) {
            return $cached;
        }

        $lockKey = 'stats-dashboard:' . $this->getCacheKey();
        $lockAcquired = $this->refreshLock->acquire($lockKey);
        if (!$lockAcquired && $hasCachedData) {
            return $cached;
        }

        try {
            $data = $this->statsReadRepository->buildStatsDashboardSnapshotData();
            $payload = array(
                'refreshed_at' => abj_clock()->now(),
                'hash' => $this->hash($data),
                'data' => $data,
            );
            if (function_exists('set_transient')) {
                set_transient($this->getCacheKey(), $payload, self::STATS_DASHBOARD_CACHE_TTL_SECONDS);
            }
            return $payload;
        } catch (Throwable $e) {
            if ($hasCachedData) {
                $this->logger->debugMessage(__FUNCTION__ . ' failed to recompute stats snapshot; returning cached snapshot. Error: ' . $e->getMessage());
                return $cached;
            }
            throw $e;
        } finally {
            if ($lockAcquired) {
                $this->refreshLock->release($lockKey);
            }
        }
    }

    /** @return array{refreshed_at:int,hash:string,data:array<string, mixed>}|null */
    private function getFromCache() {
        if (!function_exists('get_transient')) {
            return null;
        }
        $cached = get_transient($this->getCacheKey());
        if (!is_array($cached)) {
            return null;
        }
        if (!array_key_exists('data', $cached) || !is_array($cached['data'])) {
            return null;
        }
        $data = array();
        foreach ($cached['data'] as $key => $value) {
            if (is_string($key)) {
                $data[$key] = $value;
            }
        }

        return array(
            'refreshed_at' => is_scalar($cached['refreshed_at'] ?? null) ? intval($cached['refreshed_at']) : 0,
            'hash' => is_string($cached['hash'] ?? null) ? $cached['hash'] : '',
            'data' => $data,
        );
    }

    /** @return string */
    private function getCacheKey(): string {
        $blogId = 1;
        if (function_exists('get_current_blog_id')) {
            $blogId = absint(get_current_blog_id());
            if ($blogId <= 0) {
                $blogId = 1;
            }
        }
        return 'abj404_stats_dashboard_snapshot_v1_' . $blogId;
    }

    /**
     * @param array<string, mixed> $data
     * @return string
     */
    private function hash($data) {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        if (!is_string($encoded)) {
            $encoded = '';
        }
        return md5($encoded);
    }
}
