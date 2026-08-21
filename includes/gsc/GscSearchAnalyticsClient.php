<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/GscConfig.php';
require_once __DIR__ . '/GscFetchLock.php';

/**
 * Owns Google Search Console Search Analytics requests, cache writes, fetch
 * locks, and background refresh scheduling.
 */
class ABJ_404_Solution_GscSearchAnalyticsClient {

    /**
     * Recency window scanned by the GSC URL probe (rows from logsv2).
     * Made explicit at the call site so the cap is visible here, not buried in SQL.
     */
    const GSC_URL_PROBE_RECENT_LOG_WINDOW = 5000;

    /** Max distinct URLs the GSC URL probe pulls per fetch. */
    const GSC_URL_PROBE_DISTINCT_URL_CAP = 500;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_GscOAuthTokenStore */
    private $oauthStore;

    /** @var ABJ_404_Solution_GscFetchLock */
    private $fetchLock;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger, ABJ_404_Solution_GscOAuthTokenStore $oauthStore) {
        $this->logger = $logger;
        $this->oauthStore = $oauthStore;
        $this->fetchLock = new ABJ_404_Solution_GscFetchLock($logger);
    }

    /**
     * Fetch search analytics data for a list of URLs.
     *
     * @param string[] $urls Relative or absolute URLs to query.
     * @param int $days Number of days to look back.
     * @return array<int, array<string, mixed>>
     */
    public function getSearchAnalyticsForUrls(array $urls, int $days = 90): array {
        if (!$this->oauthStore->isAuthorized() || empty($urls)) {
            return array();
        }

        $cached = get_transient(ABJ_404_Solution_GscConfig::TRANSIENT_KEY);
        $cachedRows = $this->normalizeRows($cached);
        if ($cachedRows !== false) {
            return $cachedRows;
        }

        $fetchResult = $this->doFetchFromApi($urls, $days);
        $allRows = $fetchResult['rows'];
        // allow-cache-empty: empty GSC result sets are valid recent fetches and drive the explicit no-data UI state.
        set_transient(ABJ_404_Solution_GscConfig::TRANSIENT_KEY, $allRows, ABJ_404_Solution_GscConfig::TRANSIENT_TTL);
        update_option(ABJ_404_Solution_GscConfig::LAST_FETCH_OPTION_KEY, abj_clock()->now(), false);
        return $allRows;
    }

    /**
     * Fetch GSC data and cache it. Called by cron and background refresh.
     *
     * @return void
     */
    public function fetchAndCacheGscData(): void {
        if (!$this->oauthStore->isAuthorized()) {
            return;
        }

        if (!$this->fetchLock->claim()) {
            return;
        }

        try {
            $urls = $this->getUrlsToQuery();
            $fetchResult = $this->doFetchFromApi($urls);
            if (!$fetchResult['completed']) {
                return;
            }
            $allRows = $fetchResult['rows'];
            // allow-cache-empty: empty GSC result sets are valid recent fetches and drive the explicit no-data UI state.
            set_transient(ABJ_404_Solution_GscConfig::TRANSIENT_KEY, $allRows, ABJ_404_Solution_GscConfig::TRANSIENT_TTL);
            update_option(ABJ_404_Solution_GscConfig::LAST_FETCH_OPTION_KEY, abj_clock()->now(), false);
        } finally {
            $this->fetchLock->release();
        }
    }

    /**
     * Get the list of 404 URLs to query from the logs table.
     *
     * @return string[]
     */
    protected function getUrlsToQuery(): array {
        $logsRepo = abj_service('logs_repository');
        return $logsRepo->getDistinctLoggedUrls(
            self::GSC_URL_PROBE_RECENT_LOG_WINDOW,
            self::GSC_URL_PROBE_DISTINCT_URL_CAP
        );
    }

    /**
     * Return cached GSC data, or false if the cache is empty.
     *
     * @return array<int, array<string, mixed>>|false
     */
    public function getCachedData() {
        $cached = get_transient(ABJ_404_Solution_GscConfig::TRANSIENT_KEY);
        return $this->normalizeRows($cached);
    }

    /**
     * Whether a background refresh should be triggered.
     *
     * @return bool
     */
    public function isRefreshNeeded(): bool {
        $lastFetch = get_option(ABJ_404_Solution_GscConfig::LAST_FETCH_OPTION_KEY, 0);
        $lastFetchTime = is_numeric($lastFetch) ? (int)$lastFetch : 0;
        return (abj_clock()->now() - $lastFetchTime) > ABJ_404_Solution_GscConfig::STALE_THRESHOLD;
    }

    /**
     * Schedule an immediate single-event background refresh via WP-Cron.
     *
     * @return void
     */
    public function scheduleBackgroundRefresh(): void {
        $this->fetchLock->initializeAtomicLockMigrationState();
        if ($this->fetchLock->isHeld()) {
            return;
        }
        abj_cron_scheduler()->scheduleSingleIfMissing(
            ABJ_404_Solution_GscConfig::BACKGROUND_REFRESH_HOOK
        );
    }

    /**
     * Fetch top 404 URLs that also have GSC search traffic.
     *
     * @param string[] $capturedUrls Array of captured 404 URL strings.
     * @param int $days Number of days for GSC data.
     * @return array<int, array<string, mixed>>
     */
    public function getTrafficDataForCaptured404s(array $capturedUrls, int $days = 90): array {
        if (empty($capturedUrls)) {
            return array();
        }
        $data = $this->getSearchAnalyticsForUrls($capturedUrls, $days);
        return array_values(array_filter($data, function ($row) {
            return isset($row['clicks']) && is_numeric($row['clicks']) && (int)$row['clicks'] > 0;
        }));
    }

    /**
     * Query the GSC Search Analytics API for each URL individually.
     *
     * @param string[] $urls Relative or absolute URLs to query.
     * @param int $days Number of days to look back.
     * @return array{rows: array<int, array<string, mixed>>, completed: bool}
     */
    private function doFetchFromApi(array $urls, int $days = 90): array {
        $s = $this->oauthStore->getSettings();
        $token = get_option(ABJ_404_Solution_GscConfig::TOKEN_OPTION_KEY, false);
        $accessToken = $this->tokenAccessToken($token);
        if ($accessToken === '') {
            return array('rows' => array(), 'completed' => true);
        }

        $siteUrl = $s['site_url'];
        $now = abj_clock()->now();
        $endDayIndex = intdiv($now, 86400);
        $startDayIndex = $endDayIndex - $days;
        $endDate = gmdate('Y-m-d', $endDayIndex * 86400);
        $startDate = gmdate('Y-m-d', $startDayIndex * 86400);

        $urls = array_slice($urls, 0, 500);
        $allRows = array();

        foreach ($urls as $url) {
            if (!$this->fetchLock->renewIfDue()) {
                return array('rows' => $allRows, 'completed' => false);
            }
            $absoluteUrl = (strpos($url, 'http') === 0) ? $url : rtrim(home_url('/'), '/') . '/' . ltrim($url, '/');
            $body = array(
                'startDate'       => $startDate,
                'endDate'         => $endDate,
                'dimensions'      => array('page'),
                'dimensionFilterGroups' => array(
                    array(
                        'filters' => array(
                            array(
                                'dimension'  => 'page',
                                'operator'   => 'equals',
                                'expression' => $absoluteUrl,
                            ),
                        ),
                    ),
                ),
                'rowLimit'        => 1000,
            );

            $encodedSiteUrl = urlencode($siteUrl);
            $response = wp_remote_post(
                ABJ_404_Solution_GscConfig::API_BASE_URL . "/sites/{$encodedSiteUrl}/searchAnalytics/query",
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type'  => 'application/json',
                    ),
                    'body'    => (string)wp_json_encode($body),
                    'timeout' => 20,
                )
            );

            if (is_wp_error($response)) {
                $this->logger->warn('GSC API transport error: ' . $response->get_error_message());
                break;
            }

            $httpCode = (int) wp_remote_retrieve_response_code($response);
            if ($httpCode !== 200) {
                $this->logger->warn('GSC API returned HTTP ' . $httpCode . ': ' . wp_remote_retrieve_body($response));
                break;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data) || empty($data['rows']) || !is_array($data['rows'])) {
                continue;
            }

            foreach ($data['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $allRows[] = $this->normalizeApiRow($row);
            }
        }

        usort($allRows, function ($a, $b) {
            return $b['clicks'] - $a['clicks'];
        });

        return array('rows' => $allRows, 'completed' => true);
    }

    /**
     * @param mixed $cached
     * @return array<int, array<string, mixed>>|false
     */
    private function normalizeRows($cached) {
        if (!is_array($cached)) {
            return false;
        }
        $rows = array();
        foreach ($cached as $row) {
            if (is_array($row)) {
                $normalized = array();
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $normalized[$key] = $value;
                    }
                }
                $rows[] = $normalized;
            }
        }
        return $rows;
    }

    /** @param mixed $token */
    private function tokenAccessToken($token): string {
        if (!is_array($token)) {
            return '';
        }
        $accessToken = $token['access_token'] ?? '';
        return is_scalar($accessToken) ? (string)$accessToken : '';
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array{url: string, clicks: int, impressions: int, position: float}
     */
    private function normalizeApiRow(array $row): array {
        return array(
            'url'         => $this->rowUrl($row),
            'clicks'      => $this->rowInt($row, 'clicks'),
            'impressions' => $this->rowInt($row, 'impressions'),
            'position'    => round($this->rowFloat($row, 'position'), 1),
        );
    }

    /** @param array<mixed, mixed> $row */
    private function rowUrl(array $row): string {
        $keys = $row['keys'] ?? array();
        if (!is_array($keys)) {
            return '';
        }
        $url = $keys[0] ?? '';
        return is_scalar($url) ? (string)$url : '';
    }

    /** @param array<mixed, mixed> $row */
    private function rowInt(array $row, string $key): int {
        $value = $row[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }

    /** @param array<mixed, mixed> $row */
    private function rowFloat(array $row, string $key): float {
        $value = $row[$key] ?? 0.0;
        return is_numeric($value) ? (float)$value : 0.0;
    }
}
