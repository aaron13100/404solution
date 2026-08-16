<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/GscConfig.php';

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

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger, ABJ_404_Solution_GscOAuthTokenStore $oauthStore) {
        $this->logger = $logger;
        $this->oauthStore = $oauthStore;
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

        $allRows = $this->doFetchFromApi($urls, $days);
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

        if (!$this->claimFetchLock()) {
            return;
        }

        try {
            $urls = $this->getUrlsToQuery();
            $allRows = $this->doFetchFromApi($urls);
            // allow-cache-empty: empty GSC result sets are valid recent fetches and drive the explicit no-data UI state.
            set_transient(ABJ_404_Solution_GscConfig::TRANSIENT_KEY, $allRows, ABJ_404_Solution_GscConfig::TRANSIENT_TTL);
            update_option(ABJ_404_Solution_GscConfig::LAST_FETCH_OPTION_KEY, abj_clock()->now(), false);
        } finally {
            $this->releaseFetchLock();
        }
    }

    /**
     * Take the fetch lock, so exactly one request talks to Google.
     *
     * The lock used to be a transient tested with `if (get_transient(...))
     * return;` followed by a set. That is a read and then a write, and
     * WordPress answers the read from the object cache (a transient with no
     * persistent cache installed is an option row, read through get_option),
     * so two requests arriving together both saw no lock and both went to the
     * API: duplicate calls against a quota'd external service, and two writers
     * racing to cache the answer. It is the same defect that handed two
     * requests the 'update_db_version' lock in error report 270, in a
     * different storage.
     *
     * What replaces it is one INSERT that UNIQUE(option_name) can satisfy only
     * once. The stored value is the acquisition time so a fetch that died
     * mid-flight (a fatal, a killed cron) does not hold the lock forever: the
     * next attempt displaces a holder older than LOCK_TTL, conditionally on
     * the exact value it read, and then races for the row like anybody else.
     * A plain option row does not expire on its own the way the transient did,
     * so that displacement is what now bounds a leaked lock.
     *
     * @return bool true only if this request holds the lock.
     */
    private function claimFetchLock(): bool {
        $lockRow = $this->fetchLockRow();
        $now = abj_clock()->now();

        if ($lockRow->claim(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY, (string)$now)) {
            return true;
        }

        $heldSince = $lockRow->valueOf(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY);
        if (!$this->fetchLockHasAgedOut($heldSince, $now)) {
            return false;
        }

        $lockRow->releaseIfValueIs(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY, $heldSince);

        return $lockRow->claim(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY, (string)$now);
    }

    /** @return void */
    private function releaseFetchLock(): void {
        $this->fetchLockRow()->release(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY);
    }

    /** Whether a fetch is running right now.
     *
     * Reads the row rather than the option cache for the same reason the claim
     * does, and applies the TTL so a leaked lock cannot suppress background
     * refreshes forever.
     *
     * @return bool
     */
    private function isFetchLockHeld(): bool {
        $heldSince = $this->fetchLockRow()->valueOf(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY);

        return $heldSince !== '' && !$this->fetchLockHasAgedOut($heldSince, abj_clock()->now());
    }

    /**
     * @param string $heldSince the value recorded when the lock was taken
     * @param int $now
     * @return bool true when no live fetch can still be behind this record.
     */
    private function fetchLockHasAgedOut(string $heldSince, int $now): bool {
        if ($heldSince === '' || !is_numeric($heldSince)) {
            // Nothing writes a non-numeric value, so this is a row left by an
            // older version (which stored '1') or a partial write. Treating it
            // as aged out clears it; treating it as a holder would wedge every
            // future fetch, because a value with no timestamp can never expire.
            return true;
        }

        return ($now - (int)$heldSince) > ABJ_404_Solution_GscConfig::LOCK_TTL;
    }

    /** The lock row itself. Stateless, so a fresh instance costs nothing.
     * @return ABJ_404_Solution_ExclusiveOptionRow */
    private function fetchLockRow(): ABJ_404_Solution_ExclusiveOptionRow {
        return new ABJ_404_Solution_ExclusiveOptionRow();
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
        if ($this->isFetchLockHeld()) {
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
     * @return array<int, array<string, mixed>>
     */
    private function doFetchFromApi(array $urls, int $days = 90): array {
        $s = $this->oauthStore->getSettings();
        $token = get_option(ABJ_404_Solution_GscConfig::TOKEN_OPTION_KEY, false);
        $accessToken = $this->tokenAccessToken($token);
        if ($accessToken === '') {
            return array();
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

        return $allRows;
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
