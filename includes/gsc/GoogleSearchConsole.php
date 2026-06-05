<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/GscConfig.php';
require_once __DIR__ . '/GscOAuthTokenStore.php';
require_once __DIR__ . '/GscSearchAnalyticsClient.php';
require_once __DIR__ . '/GscAdminSectionRenderer.php';

/**
 * Backward-compatible facade for the Google Search Console integration.
 *
 * The public API stays here for existing callers while OAuth/token state,
 * Search Analytics querying, and admin rendering live in dedicated
 * collaborators.
 */
class ABJ_404_Solution_GoogleSearchConsole {

    const OPTION_KEY        = ABJ_404_Solution_GscConfig::OPTION_KEY;
    const TOKEN_OPTION_KEY  = ABJ_404_Solution_GscConfig::TOKEN_OPTION_KEY;
    const ERROR_OPTION_KEY  = ABJ_404_Solution_GscConfig::ERROR_OPTION_KEY;
    const TRANSIENT_KEY     = ABJ_404_Solution_GscConfig::TRANSIENT_KEY;
    const TRANSIENT_TTL     = ABJ_404_Solution_GscConfig::TRANSIENT_TTL;

    const CRON_HOOK               = ABJ_404_Solution_GscConfig::CRON_HOOK;
    const BACKGROUND_REFRESH_HOOK = ABJ_404_Solution_GscConfig::BACKGROUND_REFRESH_HOOK;
    const LOCK_TRANSIENT_KEY      = ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY;
    const LOCK_TTL                = ABJ_404_Solution_GscConfig::LOCK_TTL;
    const LAST_FETCH_OPTION_KEY   = ABJ_404_Solution_GscConfig::LAST_FETCH_OPTION_KEY;
    const STALE_THRESHOLD         = ABJ_404_Solution_GscConfig::STALE_THRESHOLD;

    const OAUTH_AUTH_URL  = ABJ_404_Solution_GscConfig::OAUTH_AUTH_URL;
    const OAUTH_TOKEN_URL = ABJ_404_Solution_GscConfig::OAUTH_TOKEN_URL;
    const API_BASE_URL    = ABJ_404_Solution_GscConfig::API_BASE_URL;
    const SCOPE           = ABJ_404_Solution_GscConfig::SCOPE;

    /** Base URL of the centralized OAuth proxy Worker. */
    const CENTRALIZED_AUTH_URL = ABJ_404_Solution_GscConfig::CENTRALIZED_AUTH_URL;

    const CENTRALIZED_CALLBACK_SECRET_TRANSIENT_PREFIX = ABJ_404_Solution_GscConfig::CENTRALIZED_CALLBACK_SECRET_TRANSIENT_PREFIX;
    const CENTRALIZED_CALLBACK_SECRET_TTL              = ABJ_404_Solution_GscConfig::CENTRALIZED_CALLBACK_SECRET_TTL;

    /** @var ABJ_404_Solution_GscOAuthTokenStore */
    private $oauthStore;

    /** @var ABJ_404_Solution_GscSearchAnalyticsClient */
    private $searchAnalytics;

    /** @var ABJ_404_Solution_GscAdminSectionRenderer */
    private $renderer;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger) {
        $this->oauthStore = new ABJ_404_Solution_GscOAuthTokenStore();
        $this->searchAnalytics = new ABJ_404_Solution_GscSearchAnalyticsClient($logger, $this->oauthStore);
        $this->renderer = new ABJ_404_Solution_GscAdminSectionRenderer($this->oauthStore, $this->searchAnalytics);
    }

    /**
     * Get the stored GSC settings.
     *
     * @return array{client_id: string, client_secret: string, site_url: string}
     */
    public function getSettings(): array {
        return $this->oauthStore->getSettings();
    }

    /**
     * Save GSC settings. Returns an error message string or '' on success.
     *
     * @param array<string, mixed> $postData
     * @return string
     */
    public function saveSettings(array $postData): string {
        return $this->oauthStore->saveSettings($postData);
    }

    /**
     * Whether centralized OAuth mode is active.
     *
     * @return bool
     */
    public function isCentralizedMode(): bool {
        return $this->oauthStore->isCentralizedMode();
    }

    /**
     * Is the integration configured.
     *
     * @return bool
     */
    public function isConfigured(): bool {
        return $this->oauthStore->isConfigured();
    }

    /**
     * Is an access token available and usable.
     *
     * @return bool
     */
    public function isAuthorized(): bool {
        return $this->oauthStore->isAuthorized();
    }

    /**
     * Build the Google OAuth 2.0 authorization URL.
     *
     * @return string
     */
    public function buildAuthUrl(): string {
        return $this->oauthStore->buildAuthUrl();
    }

    /**
     * Build the transient key that stores the one-time Worker callback signing secret.
     *
     * @param string $nonce WordPress OAuth callback nonce.
     * @return string
     */
    public static function centralizedCallbackSecretTransientKey(string $nonce): string {
        return ABJ_404_Solution_GscConfig::centralizedCallbackSecretTransientKey($nonce);
    }

    /**
     * The OAuth callback URL that must be registered in Google Cloud.
     *
     * @return string
     */
    public function getCallbackUrl(): string {
        return $this->oauthStore->getCallbackUrl();
    }

    /**
     * Store tokens received directly from the centralized OAuth callback.
     *
     * @param string $accessToken
     * @param string $refreshToken
     * @param int    $expiresIn Seconds until the access token expires.
     * @return void
     */
    public function storeCentralizedTokens(string $accessToken, string $refreshToken, int $expiresIn): void {
        $this->oauthStore->storeCentralizedTokens($accessToken, $refreshToken, $expiresIn);
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @param string $code
     * @return string Empty on success, error message on failure.
     */
    public function exchangeCodeForToken(string $code): string {
        return $this->oauthStore->exchangeCodeForToken($code);
    }

    /**
     * Revoke authorization and delete stored tokens.
     *
     * @return void
     */
    public function revokeAuthorization(): void {
        $this->oauthStore->revokeAuthorization();
    }

    /**
     * Fetch search analytics data for a list of URLs.
     *
     * @param string[] $urls Relative or absolute URLs to query.
     * @param int $days Number of days to look back.
     * @return array<int, array<string, mixed>>
     */
    public function getSearchAnalyticsForUrls(array $urls, int $days = 90): array {
        return $this->searchAnalytics->getSearchAnalyticsForUrls($urls, $days);
    }

    /**
     * Fetch GSC data and cache it. Called by cron and background refresh.
     *
     * @return void
     */
    public function fetchAndCacheGscData(): void {
        $this->searchAnalytics->fetchAndCacheGscData(function (): array {
            return $this->getUrlsToQuery();
        });
    }

    /**
     * Recency window scanned by the GSC URL probe (rows from logsv2).
     * Made explicit at the call site so the cap is visible here, not buried in SQL.
     */
    const GSC_URL_PROBE_RECENT_LOG_WINDOW = 5000;

    /** Max distinct URLs the GSC URL probe pulls per fetch. */
    const GSC_URL_PROBE_DISTINCT_URL_CAP = 500;

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
        return $this->searchAnalytics->getCachedData();
    }

    /**
     * Whether a background refresh should be triggered.
     *
     * @return bool
     */
    public function isRefreshNeeded(): bool {
        return $this->searchAnalytics->isRefreshNeeded();
    }

    /**
     * Schedule an immediate single-event background refresh via WP-Cron.
     *
     * @return void
     */
    public function scheduleBackgroundRefresh(): void {
        $this->searchAnalytics->scheduleBackgroundRefresh();
    }

    /**
     * Fetch top 404 URLs that also have GSC search traffic.
     *
     * @param string[] $capturedUrls Array of captured 404 URL strings.
     * @param int $days Number of days for GSC data.
     * @return array<int, array<string, mixed>>
     */
    public function getTrafficDataForCaptured404s(array $capturedUrls, int $days = 90): array {
        return $this->searchAnalytics->getTrafficDataForCaptured404s($capturedUrls, $days);
    }

    /**
     * Persist an OAuth error so it is visible after redirect.
     *
     * @param string $message
     * @return void
     */
    public function setLastOAuthError(string $message): void {
        $this->oauthStore->setLastOAuthError($message);
    }

    /**
     * Retrieve the last stored OAuth error.
     *
     * @return string
     */
    public function getLastOAuthError(): string {
        return $this->oauthStore->getLastOAuthError();
    }

    /**
     * Clear any stored OAuth error.
     *
     * @return void
     */
    public function clearLastOAuthError(): void {
        $this->oauthStore->clearLastOAuthError();
    }

    /**
     * Determine the current UI state of the GSC integration.
     *
     * @return string 'not_configured'|'configured_not_connected'|'error'|'connected'
     */
    public function getState(): string {
        return $this->oauthStore->getState();
    }

    /**
     * Render the inner content for the GSC settings/status card.
     *
     * @param string[] $capturedUrls Deprecated; kept for backward compatibility.
     * @return string HTML.
     */
    public function renderAdminSection(array $capturedUrls = []): string {
        return $this->renderer->renderAdminSection($this->getState());
    }
}
