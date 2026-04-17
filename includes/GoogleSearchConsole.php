<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Search Console integration.
 *
 * Connects to the Search Console API to surface search traffic data
 * for URLs that generate 404s, helping admins understand which broken
 * URLs were actually getting real search traffic.
 *
 * Setup requires:
 *  1. A Google Cloud project with the Search Console API enabled.
 *  2. OAuth 2.0 credentials (Client ID + Client Secret).
 *  3. The redirect URI registered in Google Cloud must match the OAuth
 *     callback URL shown in the plugin settings.
 */
class ABJ_404_Solution_GoogleSearchConsole {

    const OPTION_KEY        = 'abj404_gsc_settings';
    const TOKEN_OPTION_KEY  = 'abj404_gsc_token';
    const ERROR_OPTION_KEY  = 'abj404_gsc_last_error';
    const TRANSIENT_KEY     = 'abj404_gsc_data';
    const TRANSIENT_TTL     = 3600; // 1 hour cache

    const OAUTH_AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    const OAUTH_TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    const API_BASE_URL      = 'https://www.googleapis.com/webmasters/v3';
    const SCOPE             = 'https://www.googleapis.com/auth/webmasters.readonly';

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // Settings helpers
    // -------------------------------------------------------------------------

    /**
     * Get the stored GSC settings (client_id, client_secret, site_url).
     * @return array{client_id: string, client_secret: string, site_url: string}
     */
    public function getSettings(): array {
        $raw = get_option(self::OPTION_KEY, array());
        if (!is_array($raw)) {
            $raw = array();
        }
        return array(
            'client_id'     => isset($raw['client_id'])     && is_string($raw['client_id'])     ? $raw['client_id']     : '',
            'client_secret' => isset($raw['client_secret']) && is_string($raw['client_secret']) ? $raw['client_secret'] : '',
            'site_url'      => isset($raw['site_url'])      && is_string($raw['site_url'])      ? $raw['site_url']      : home_url('/'),
        );
    }

    /**
     * Save GSC settings. Returns an error message string or '' on success.
     * @param array<string, mixed> $postData
     * @return string
     */
    public function saveSettings(array $postData): string {
        $clientId     = isset($postData['gsc_client_id'])     ? sanitize_text_field((string)(is_scalar($postData['gsc_client_id'])     ? $postData['gsc_client_id']     : '')) : '';
        $clientSecret = isset($postData['gsc_client_secret']) ? sanitize_text_field((string)(is_scalar($postData['gsc_client_secret']) ? $postData['gsc_client_secret'] : '')) : '';
        $siteUrl      = isset($postData['gsc_site_url'])      ? esc_url_raw((string)(is_scalar($postData['gsc_site_url'])      ? $postData['gsc_site_url']      : ''))              : home_url('/');

        update_option(self::OPTION_KEY, array(
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'site_url'      => $siteUrl,
        ), false);

        // Saving new credentials starts fresh — clear any previous OAuth error.
        $this->clearLastOAuthError();
        return '';
    }

    /**
     * Is the integration configured (credentials entered)?
     * @return bool
     */
    public function isConfigured(): bool {
        $s = $this->getSettings();
        return $s['client_id'] !== '' && $s['client_secret'] !== '';
    }

    /**
     * Is an access token available (OAuth has been authorized)?
     * @return bool
     */
    public function isAuthorized(): bool {
        $token = get_option(self::TOKEN_OPTION_KEY, false);
        if (!is_array($token) || empty($token['access_token'])) {
            return false;
        }
        // Treat token as valid if it doesn't have an expiry or expiry is in the future.
        if (!empty($token['expires_at']) && (int)$token['expires_at'] < time()) {
            return $this->refreshToken();
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // OAuth flow
    // -------------------------------------------------------------------------

    /**
     * Build the Google OAuth 2.0 authorization URL.
     * @return string
     */
    public function buildAuthUrl(): string {
        $s = $this->getSettings();
        $params = array(
            'client_id'     => $s['client_id'],
            'redirect_uri'  => $this->getCallbackUrl(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => wp_create_nonce('abj404_gsc_oauth'),
        );
        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * The OAuth callback URL that must be registered in Google Cloud.
     * @return string
     */
    public function getCallbackUrl(): string {
        return admin_url('admin-ajax.php?action=abj404_gsc_oauth_callback');
    }

    /**
     * Exchange an authorization code for tokens. Returns '' on success, error on failure.
     * @param string $code
     * @return string
     */
    public function exchangeCodeForToken(string $code): string {
        $s = $this->getSettings();
        $response = wp_remote_post(self::OAUTH_TOKEN_URL, array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $s['client_id'],
                'client_secret' => $s['client_secret'],
                'redirect_uri'  => $this->getCallbackUrl(),
                'grant_type'    => 'authorization_code',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            $error = (is_array($body) && isset($body['error_description'])) ? $body['error_description'] : __('OAuth token exchange failed.', '404-solution');
            return is_string($error) ? $error : __('OAuth token exchange failed.', '404-solution');
        }

        $token = array(
            'access_token'  => $body['access_token'],
            'token_type'    => isset($body['token_type']) ? $body['token_type'] : 'Bearer',
            'expires_at'    => isset($body['expires_in']) ? (time() + (int)$body['expires_in'] - 60) : 0,
            'refresh_token' => isset($body['refresh_token']) ? $body['refresh_token'] : '',
        );
        update_option(self::TOKEN_OPTION_KEY, $token, false);
        $this->clearLastOAuthError(); // authorization succeeded — clear any previous error
        return '';
    }

    /**
     * Refresh the access token using the stored refresh token.
     * @return bool true if token refreshed successfully.
     */
    private function refreshToken(): bool {
        $token = get_option(self::TOKEN_OPTION_KEY, false);
        if (!is_array($token) || empty($token['refresh_token'])) {
            return false;
        }
        $s = $this->getSettings();
        $response = wp_remote_post(self::OAUTH_TOKEN_URL, array(
            'body' => array(
                'refresh_token' => $token['refresh_token'],
                'client_id'     => $s['client_id'],
                'client_secret' => $s['client_secret'],
                'grant_type'    => 'refresh_token',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            return false;
        }

        $token['access_token'] = $body['access_token'];
        $token['expires_at']   = isset($body['expires_in']) ? (time() + (int)$body['expires_in'] - 60) : 0;
        update_option(self::TOKEN_OPTION_KEY, $token, false);
        return true;
    }

    /**
     * Revoke authorization and delete stored tokens.
     * @return void
     */
    public function revokeAuthorization(): void {
        delete_option(self::TOKEN_OPTION_KEY);
        delete_option(self::OPTION_KEY);
        delete_transient(self::TRANSIENT_KEY);
        $this->clearLastOAuthError();
    }

    // -------------------------------------------------------------------------
    // API queries
    // -------------------------------------------------------------------------

    /**
     * Fetch search analytics data for a list of URLs.
     * Returns rows: [ [ 'url' => '...', 'clicks' => N, 'impressions' => N, 'position' => F.F ] ]
     *
     * @param string[] $urls Relative or absolute URLs to query
     * @param int $days Number of days (max 16 months back)
     * @return array<int, array<string, mixed>>
     */
    public function getSearchAnalyticsForUrls(array $urls, int $days = 90): array {
        if (!$this->isAuthorized() || empty($urls)) {
            return array();
        }

        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $s = $this->getSettings();
        $token = get_option(self::TOKEN_OPTION_KEY, false);
        if (!is_array($token) || empty($token['access_token'])) {
            return array();
        }

        $siteUrl = $s['site_url'];
        $endDate = date('Y-m-d');
        $startTimestamp = strtotime("-{$days} days");
        $startDate = date('Y-m-d', $startTimestamp !== false ? $startTimestamp : 0);

        // GSC API does not support OR-filtering in dimensionFilterGroups,
        // so each URL must be queried individually.
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
                self::API_BASE_URL . "/sites/{$encodedSiteUrl}/searchAnalytics/query",
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token['access_token'],
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
            if (!is_array($data) || empty($data['rows'])) {
                continue;
            }

            foreach ($data['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $allRows[] = array(
                    'url'         => isset($row['keys'][0]) ? $row['keys'][0] : '',
                    'clicks'      => isset($row['clicks'])      ? (int)$row['clicks']           : 0,
                    'impressions' => isset($row['impressions']) ? (int)$row['impressions']       : 0,
                    'position'    => isset($row['position'])    ? round((float)$row['position'], 1) : 0.0,
                );
            }
        }

        // Sort by clicks descending
        usort($allRows, function ($a, $b) {
            return $b['clicks'] - $a['clicks'];
        });

        set_transient(self::TRANSIENT_KEY, $allRows, self::TRANSIENT_TTL);
        return $allRows;
    }

    /**
     * Fetch top N 404 URLs that also have GSC search traffic.
     * Correlates captured 404s with GSC data.
     *
     * @param array<string> $capturedUrls Array of captured 404 URL strings
     * @param int $days Number of days for GSC data
     * @return array<int, array<string, mixed>> Rows with url, clicks, impressions, position
     */
    public function getTrafficDataForCaptured404s(array $capturedUrls, int $days = 90): array {
        if (empty($capturedUrls)) {
            return array();
        }
        $data = $this->getSearchAnalyticsForUrls($capturedUrls, $days);
        return array_filter($data, function ($row) {
            return is_array($row) && isset($row['clicks']) && $row['clicks'] > 0;
        });
    }

    // -------------------------------------------------------------------------
    // OAuth error persistence (survives the post-OAuth redirect)
    // -------------------------------------------------------------------------

    /**
     * Persist an OAuth error so it is visible after the page redirect.
     * @param string $message
     * @return void
     */
    public function setLastOAuthError(string $message): void {
        update_option(self::ERROR_OPTION_KEY, $message, false);
    }

    /**
     * Retrieve the last stored OAuth error, or '' if none.
     * @return string
     */
    public function getLastOAuthError(): string {
        $v = get_option(self::ERROR_OPTION_KEY, '');
        return is_string($v) ? $v : '';
    }

    /**
     * Clear any stored OAuth error (called on successful authorization and on revoke).
     * @return void
     */
    public function clearLastOAuthError(): void {
        delete_option(self::ERROR_OPTION_KEY);
    }

    // -------------------------------------------------------------------------
    // State machine
    // -------------------------------------------------------------------------

    /**
     * Determine the current UI state of the GSC integration.
     *
     * @return string 'not_configured'|'configured_not_connected'|'error'|'connected'
     */
    public function getState(): string {
        if (!$this->isConfigured()) {
            return 'not_configured';
        }
        if ($this->isAuthorized()) {
            return 'connected';
        }
        if ($this->getLastOAuthError() !== '') {
            return 'error';
        }
        return 'configured_not_connected';
    }

    // -------------------------------------------------------------------------
    // Admin UI
    // -------------------------------------------------------------------------

    /**
     * Render the inner content for the GSC settings/status card.
     * Callers wrap this via echoOptionsSection() for card + collapse support.
     *
     * @param string[] $capturedUrls Captured 404 URLs from the logs table, used to fetch GSC data
     *                               when the connected state needs a fresh API call.
     * @return string HTML
     */
    public function renderAdminSection(array $capturedUrls = []): string {
        switch ($this->getState()) {
            case 'not_configured':
                return $this->renderNotConfiguredState();
            case 'configured_not_connected':
                return $this->renderConfiguredNotConnectedState();
            case 'error':
                return $this->renderErrorState();
            default: // 'connected'
                return $this->renderConnectedState($capturedUrls);
        }
    }

    /**
     * State: credentials not yet entered. Shows 5-step setup wizard + credential form.
     * @return string
     */
    private function renderNotConfiguredState(): string {
        $callbackUrl = $this->getCallbackUrl();
        $s           = $this->getSettings();
        $copyLabel   = esc_js(__('Copy', '404-solution'));
        $copiedLabel = esc_js(__('Copied!', '404-solution'));

        $html  = '<p>' . esc_html__('Connect to Google Search Console to see which broken URLs were getting real search traffic.', '404-solution') . '</p>';
        $html .= '<p><strong>' . esc_html__('Setup steps:', '404-solution') . '</strong></p>';
        $html .= '<ol class="abj404-wizard-steps">';
        $html .= '<li>' . sprintf(esc_html__('Create a project in %s.', '404-solution'), '<a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>') . '</li>';
        $html .= '<li>' . esc_html__('Enable the "Google Search Console API".', '404-solution') . '</li>';
        $html .= '<li>' . esc_html__('Create OAuth 2.0 credentials (Web application type).', '404-solution') . '</li>';
        $html .= '<li>' . esc_html__('Add this Authorized Redirect URI to your OAuth client:', '404-solution');
        $html .= '<div class="abj404-copy-uri-wrap">';
        $html .= '<code id="abj404-gsc-callback-uri" class="abj404-gsc-callback-code">' . esc_html($callbackUrl) . '</code>';
        $html .= '<button type="button" class="abj404-btn abj404-btn-secondary abj404-copy-btn" onclick="abj404CopyGscUri(this)">' . esc_html__('Copy', '404-solution') . '</button>';
        $html .= '</div>';
        $html .= '</li>';
        $html .= '<li>' . esc_html__('Enter your Client ID and Client Secret below.', '404-solution') . '</li>';
        $html .= '</ol>';

        // Tiny inline script: copy button handler (admin-only page, no CSP concerns)
        $html .= '<script>';
        $html .= 'function abj404CopyGscUri(btn){';
        $html .= 'var code=document.getElementById(\'abj404-gsc-callback-uri\');';
        $html .= 'if(!code||!navigator.clipboard)return;';
        $html .= 'navigator.clipboard.writeText(code.textContent.trim()).then(function(){';
        $html .= 'var orig=btn.textContent;';
        $html .= 'btn.textContent=\'' . $copiedLabel . '\';';
        $html .= 'btn.classList.add(\'abj404-copy-btn--done\');';
        $html .= 'setTimeout(function(){btn.textContent=orig;btn.classList.remove(\'abj404-copy-btn--done\');},2000);';
        $html .= '});';
        $html .= '}';
        $html .= '</script>';

        $nonceField = wp_nonce_field('abj404_gsc_save', '_wpnonce_gsc', true, false);
        $html .= '<form method="POST">';
        $html .= $nonceField;
        $html .= '<input type="hidden" name="action" value="saveGscSettings">';
        $html .= '<div class="abj404-form-group">';
        $html .= '<label class="abj404-form-label" for="gsc_client_id">' . esc_html__('Client ID', '404-solution') . '</label>';
        $html .= '<input type="text" name="gsc_client_id" id="gsc_client_id" class="abj404-form-input" value="' . esc_attr($s['client_id']) . '">';
        $html .= '</div>';
        $html .= '<div class="abj404-form-group">';
        $html .= '<label class="abj404-form-label" for="gsc_client_secret">' . esc_html__('Client Secret', '404-solution') . '</label>';
        $html .= '<input type="password" name="gsc_client_secret" id="gsc_client_secret" class="abj404-form-input" value="' . esc_attr($s['client_secret']) . '">';
        $html .= '</div>';
        $html .= '<div class="abj404-form-group">';
        $html .= '<label class="abj404-form-label" for="gsc_site_url">' . esc_html__('Search Console Site URL', '404-solution') . '</label>';
        $html .= '<input type="url" name="gsc_site_url" id="gsc_site_url" class="abj404-form-input" value="' . esc_attr($s['site_url']) . '">';
        $html .= '<p class="abj404-form-help">' . esc_html__('The site URL as registered in Search Console (e.g. https://example.com/).', '404-solution') . '</p>';
        $html .= '</div>';
        $html .= '<button type="submit" class="abj404-btn abj404-btn-primary">' . esc_html__('Save Credentials', '404-solution') . '</button>';
        $html .= '</form>';

        return $html;
    }

    /**
     * State: credentials saved but OAuth not yet completed.
     * Shows an amber status pill + Authorize button.
     * @return string
     */
    private function renderConfiguredNotConnectedState(): string {
        $authUrl   = $this->buildAuthUrl();
        $revokeUrl = wp_nonce_url(admin_url('admin-ajax.php?action=abj404_gsc_revoke'), 'abj404_gsc_revoke');

        $html  = '<div class="abj404-gsc-status abj404-gsc-status--amber">';
        $html .= esc_html__('Credentials saved — authorization required', '404-solution');
        $html .= '</div>';
        $html .= '<p>' . esc_html__('Click the button below to authorize access to your Search Console data.', '404-solution') . '</p>';
        $html .= '<a href="' . esc_url($authUrl) . '" class="abj404-btn abj404-btn-primary">' . esc_html__('Authorize with Google', '404-solution') . '</a>';
        $html .= ' <a href="' . esc_url($revokeUrl) . '" class="abj404-btn abj404-btn-secondary">' . esc_html__('Remove Credentials', '404-solution') . '</a>';

        return $html;
    }

    /**
     * State: fully connected. Shows a green status pill + traffic data table.
     * @return string
     */
    /**
     * @param string[] $capturedUrls
     * @return string
     */
    private function renderConnectedState(array $capturedUrls = []): string {
        $revokeUrl = wp_nonce_url(admin_url('admin-ajax.php?action=abj404_gsc_revoke'), 'abj404_gsc_revoke');

        $html  = '<div class="abj404-gsc-status abj404-gsc-status--green">';
        $html .= esc_html__('Connected to Google Search Console', '404-solution');
        $html .= '</div>';
        $html .= '<p>' . esc_html__('Search traffic data for your captured 404 URLs is shown below. Data is cached for 1 hour.', '404-solution') . '</p>';
        $html .= '<a href="' . esc_url($revokeUrl) . '" class="abj404-btn abj404-btn-secondary">' . esc_html__('Disconnect', '404-solution') . '</a>';

        // Fetch from API if the cache is cold and we have URLs to query.
        $cached = get_transient(self::TRANSIENT_KEY);
        if (!is_array($cached) && !empty($capturedUrls)) {
            $cached = $this->getSearchAnalyticsForUrls($capturedUrls);
        }

        if (is_array($cached) && !empty($cached)) {
            $html .= '<h4>' . esc_html__('404 URLs with Search Traffic (last 90 days)', '404-solution') . '</h4>';
            $html .= '<table class="abj404-table" style="margin-top:8px;">';
            $html .= '<thead><tr>';
            $html .= '<th>' . esc_html__('URL', '404-solution') . '</th>';
            $html .= '<th>' . esc_html__('Clicks', '404-solution') . '</th>';
            $html .= '<th>' . esc_html__('Impressions', '404-solution') . '</th>';
            $html .= '<th>' . esc_html__('Avg. Position', '404-solution') . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach (array_slice($cached, 0, 25) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $html .= '<tr>';
                $html .= '<td>' . esc_html(isset($row['url']) ? (string)$row['url'] : '') . '</td>';
                $html .= '<td>' . esc_html((string)(isset($row['clicks']) ? $row['clicks'] : 0)) . '</td>';
                $html .= '<td>' . esc_html((string)(isset($row['impressions']) ? $row['impressions'] : 0)) . '</td>';
                $html .= '<td>' . esc_html((string)(isset($row['position']) ? $row['position'] : '—')) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p style="margin-top:12px;color:#646970;">' . esc_html__('No search traffic data found for your captured 404 URLs in the last 90 days.', '404-solution') . '</p>';
        }

        return $html;
    }

    /**
     * State: authorization was attempted but failed.
     * Shows a danger box with the error message + Try Again / Remove Credentials buttons.
     * @return string
     */
    private function renderErrorState(): string {
        $error     = $this->getLastOAuthError();
        $authUrl   = $this->buildAuthUrl();
        $revokeUrl = wp_nonce_url(admin_url('admin-ajax.php?action=abj404_gsc_revoke'), 'abj404_gsc_revoke');

        $html  = '<div class="abj404-gsc-error-box">';
        $html .= '<strong>' . esc_html__('Authorization failed', '404-solution') . '</strong>';
        if ($error !== '') {
            $html .= '<p>' . esc_html($error) . '</p>';
        }
        $html .= '</div>';
        $html .= '<p>' . esc_html__("Click 'Try Again' to retry authorization, or 'Remove Credentials' to start over.", '404-solution') . '</p>';
        $html .= '<a href="' . esc_url($authUrl) . '" class="abj404-btn abj404-btn-primary">' . esc_html__('Try Again', '404-solution') . '</a>';
        $html .= ' <a href="' . esc_url($revokeUrl) . '" class="abj404-btn abj404-btn-secondary">' . esc_html__('Remove Credentials', '404-solution') . '</a>';

        return $html;
    }
}
