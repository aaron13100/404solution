<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared Google Search Console integration configuration.
 *
 * Keeps storage keys, external endpoint URLs, TTLs, and pure key derivation
 * out of the facade so OAuth and Search Analytics collaborators do not
 * depend back on ABJ_404_Solution_GoogleSearchConsole.
 */
class ABJ_404_Solution_GscConfig {

    const OPTION_KEY        = 'abj404_gsc_settings';
    const TOKEN_OPTION_KEY  = 'abj404_gsc_token';
    const ERROR_OPTION_KEY  = 'abj404_gsc_last_error';
    const TRANSIENT_KEY     = 'abj404_gsc_data';
    const TRANSIENT_TTL     = 90000;

    const CRON_HOOK               = 'abj404_gsc_fetch_cron';
    const BACKGROUND_REFRESH_HOOK = 'abj404_gsc_background_refresh';
    const LOCK_TRANSIENT_KEY      = 'abj404_gsc_fetch_lock';
    const LOCK_TTL                = 900;
    const LAST_FETCH_OPTION_KEY   = 'abj404_gsc_last_fetch_time';
    const STALE_THRESHOLD         = 72000;

    const OAUTH_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const API_BASE_URL    = 'https://www.googleapis.com/webmasters/v3';
    const SCOPE           = 'https://www.googleapis.com/auth/webmasters.readonly';

    /** Base URL of the centralized OAuth proxy Worker. */
    const CENTRALIZED_AUTH_URL = 'https://404-solution-auth.forethought-studio.com';

    const CENTRALIZED_CALLBACK_SECRET_TRANSIENT_PREFIX = 'abj404_gsc_oauth_cb_secret_';
    const CENTRALIZED_CALLBACK_SECRET_TTL              = 900;

    /**
     * Build the transient key that stores the one-time Worker callback signing secret.
     *
     * @param string $nonce WordPress OAuth callback nonce.
     * @return string
     */
    public static function centralizedCallbackSecretTransientKey(string $nonce): string {
        return self::CENTRALIZED_CALLBACK_SECRET_TRANSIENT_PREFIX . hash('sha256', $nonce);
    }
}
