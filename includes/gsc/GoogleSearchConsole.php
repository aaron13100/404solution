<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/GscConfig.php';
require_once __DIR__ . '/GscOAuthTokenStore.php';
require_once __DIR__ . '/GscSearchAnalyticsClient.php';
require_once __DIR__ . '/GscAdminSectionRenderer.php';

/**
 * Composition root for the Google Search Console integration.
 *
 * Wires the three collaborators that do the real work and exposes them through
 * accessors. OAuth/token state, Search Analytics querying, and admin rendering
 * live in those collaborators; callers reach the behaviour they need via
 * {@see oauthStore()}, {@see searchAnalytics()}, and {@see renderer()}.
 *
 * Config constants are re-exported here as a stable reference surface for
 * callers and tests (the values themselves live in ABJ_404_Solution_GscConfig).
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
     * OAuth credentials, token lifecycle, integration state, and last-error storage.
     *
     * @return ABJ_404_Solution_GscOAuthTokenStore
     */
    public function oauthStore(): ABJ_404_Solution_GscOAuthTokenStore {
        return $this->oauthStore;
    }

    /**
     * Search Analytics querying, cache reads/writes, fetch locking, and background refresh.
     *
     * @return ABJ_404_Solution_GscSearchAnalyticsClient
     */
    public function searchAnalytics(): ABJ_404_Solution_GscSearchAnalyticsClient {
        return $this->searchAnalytics;
    }

    /**
     * Renders the GSC admin settings/status card.
     *
     * @return ABJ_404_Solution_GscAdminSectionRenderer
     */
    public function renderer(): ABJ_404_Solution_GscAdminSectionRenderer {
        return $this->renderer;
    }
}
