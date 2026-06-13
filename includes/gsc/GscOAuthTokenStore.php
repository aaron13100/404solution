<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/GscConfig.php';

/**
 * Owns Google Search Console settings, OAuth URLs, token persistence, refresh,
 * revocation, and OAuth error state.
 */
class ABJ_404_Solution_GscOAuthTokenStore {
    /** @return array{client_id: string, client_secret: string, site_url: string} */
    public function getSettings(): array {
        $raw = get_option(ABJ_404_Solution_GscConfig::OPTION_KEY, array());
        if (!is_array($raw)) {
            $raw = array();
        }
        return array(
            'client_id'     => isset($raw['client_id']) && is_string($raw['client_id']) ? $raw['client_id'] : '',
            'client_secret' => isset($raw['client_secret']) && is_string($raw['client_secret']) ? $raw['client_secret'] : '',
            'site_url'      => isset($raw['site_url']) && is_string($raw['site_url']) ? $raw['site_url'] : home_url('/'),
        );
    }

    /** @param array<string, mixed> $postData */
    public function saveSettings(array $postData): string {
        $clientId     = isset($postData['gsc_client_id']) ? sanitize_text_field((string)(is_scalar($postData['gsc_client_id']) ? $postData['gsc_client_id'] : '')) : '';
        $clientSecret = isset($postData['gsc_client_secret']) ? sanitize_text_field((string)(is_scalar($postData['gsc_client_secret']) ? $postData['gsc_client_secret'] : '')) : '';
        $siteUrl      = isset($postData['gsc_site_url']) ? esc_url_raw((string)(is_scalar($postData['gsc_site_url']) ? $postData['gsc_site_url'] : '')) : home_url('/');

        update_option(ABJ_404_Solution_GscConfig::OPTION_KEY, array(
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'site_url'      => $siteUrl,
        ), false);

        $this->clearLastOAuthError();
        return '';
    }

    public function isCentralizedMode(): bool {
        $s = $this->getSettings();
        if ($s['client_id'] !== '' && $s['client_secret'] !== '') {
            return false;
        }
        return true;
    }

    public function isConfigured(): bool {
        if ($this->isCentralizedMode()) {
            return true;
        }
        $s = $this->getSettings();
        return $s['client_id'] !== '' && $s['client_secret'] !== '';
    }

    public function isAuthorized(): bool {
        $token = $this->getStoredToken();
        if ($token === false || $this->payloadString($token, 'access_token') === '') {
            return false;
        }
        $expiresAt = $this->payloadInt($token, 'expires_at', 0);
        if ($expiresAt > 0 && $expiresAt < abj_clock()->now()) {
            return $this->refreshToken();
        }
        return true;
    }
    public function buildAuthUrl(): string {
        if ($this->isCentralizedMode()) {
            $nonce = wp_create_nonce('abj404_gsc_oauth');
            $params = array(
                'site_callback_url' => $this->getCallbackUrl(),
                'nonce'             => $nonce,
                'callback_signing_secret' => $this->createCentralizedCallbackSecret($nonce),
                'scope'             => ABJ_404_Solution_GscConfig::SCOPE,
            );
            return ABJ_404_Solution_GscConfig::CENTRALIZED_AUTH_URL . '/authorize?' . http_build_query($params);
        }

        $s = $this->getSettings();
        $params = array(
            'client_id'     => $s['client_id'],
            'redirect_uri'  => $this->getCallbackUrl(),
            'response_type' => 'code',
            'scope'         => ABJ_404_Solution_GscConfig::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => wp_create_nonce('abj404_gsc_oauth'),
        );
        return ABJ_404_Solution_GscConfig::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }
    private function createCentralizedCallbackSecret(string $nonce): string {
        $secret = function_exists('wp_generate_password')
            ? wp_generate_password(64, false, false)
            : bin2hex(random_bytes(32));

        set_transient( // allow-cache-empty: OAuth callback signing secret is generated non-empty; storage is required for Worker HMAC verification.
            ABJ_404_Solution_GscConfig::centralizedCallbackSecretTransientKey($nonce),
            $secret,
            ABJ_404_Solution_GscConfig::CENTRALIZED_CALLBACK_SECRET_TTL
        );

        return $secret;
    }

    public function getCallbackUrl(): string {
        return admin_url('admin-ajax.php?action=abj404_gsc_oauth_callback');
    }

    public function storeCentralizedTokens(string $accessToken, string $refreshToken, int $expiresIn): void {
        $token = array(
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_at'    => $expiresIn > 0 ? (abj_clock()->now() + $expiresIn - 60) : 0,
            'refresh_token' => $refreshToken,
        );
        update_option(ABJ_404_Solution_GscConfig::TOKEN_OPTION_KEY, $token, false);
        $this->clearLastOAuthError();
    }

    public function exchangeCodeForToken(string $code): string {
        if ($this->isCentralizedMode()) {
            return 'Code exchange is not used in centralized mode.';
        }
        $s = $this->getSettings();
        $response = wp_remote_post(ABJ_404_Solution_GscConfig::OAUTH_TOKEN_URL, array(
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

        $body = $this->decodeJsonObject(wp_remote_retrieve_body($response));
        if ($body === false || $this->payloadString($body, 'access_token') === '') {
            $error = (is_array($body) && isset($body['error_description'])) ? $body['error_description'] : __('OAuth token exchange failed.', '404-solution');
            return is_string($error) ? $error : __('OAuth token exchange failed.', '404-solution');
        }

        $token = array(
            'access_token'  => $this->payloadString($body, 'access_token'),
            'token_type'    => $this->payloadString($body, 'token_type', 'Bearer'),
            'expires_at'    => $this->expiresAtFromBody($body),
            'refresh_token' => $this->payloadString($body, 'refresh_token'),
        );
        update_option(ABJ_404_Solution_GscConfig::TOKEN_OPTION_KEY, $token, false);
        $this->clearLastOAuthError();
        return '';
    }

    private function refreshToken(): bool {
        $token = $this->getStoredToken();
        if ($token === false || $this->payloadString($token, 'refresh_token') === '') {
            return false;
        }

        if ($this->isCentralizedMode()) {
            return $this->refreshTokenViaCentralized($token);
        }

        $s = $this->getSettings();
        $response = wp_remote_post(ABJ_404_Solution_GscConfig::OAUTH_TOKEN_URL, array(
            'body' => array(
                'refresh_token' => $this->payloadString($token, 'refresh_token'),
                'client_id'     => $s['client_id'],
                'client_secret' => $s['client_secret'],
                'grant_type'    => 'refresh_token',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = $this->decodeJsonObject(wp_remote_retrieve_body($response));
        if ($body === false || $this->payloadString($body, 'access_token') === '') {
            return false;
        }

        $token['access_token'] = $this->payloadString($body, 'access_token');
        $token['expires_at']   = $this->expiresAtFromBody($body);
        update_option(ABJ_404_Solution_GscConfig::TOKEN_OPTION_KEY, $token, false);
        return true;
    }

    /** @param array<string, mixed> $token Current stored token array. */
    private function refreshTokenViaCentralized(array $token): bool {
        $response = wp_remote_post(ABJ_404_Solution_GscConfig::CENTRALIZED_AUTH_URL . '/refresh', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => (string)wp_json_encode(array(
                'refresh_token' => $this->payloadString($token, 'refresh_token'),
            )),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = $this->decodeJsonObject(wp_remote_retrieve_body($response));
        if ($body === false || $this->payloadString($body, 'access_token') === '') {
            return false;
        }

        $token['access_token'] = $this->payloadString($body, 'access_token');
        $token['expires_at']   = $this->expiresAtFromBody($body);
        update_option(ABJ_404_Solution_GscConfig::TOKEN_OPTION_KEY, $token, false);
        return true;
    }

    public function revokeAuthorization(): void {
        delete_option(ABJ_404_Solution_GscConfig::TOKEN_OPTION_KEY);
        delete_option(ABJ_404_Solution_GscConfig::OPTION_KEY);
        delete_transient(ABJ_404_Solution_GscConfig::TRANSIENT_KEY);
        $this->clearLastOAuthError();
    }

    public function setLastOAuthError(string $message): void {
        update_option(ABJ_404_Solution_GscConfig::ERROR_OPTION_KEY, $message, false);
    }

    public function getLastOAuthError(): string {
        $v = get_option(ABJ_404_Solution_GscConfig::ERROR_OPTION_KEY, '');
        return is_string($v) ? $v : '';
    }

    public function clearLastOAuthError(): void {
        delete_option(ABJ_404_Solution_GscConfig::ERROR_OPTION_KEY);
    }

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

    /** @return array<string, mixed>|false */
    private function getStoredToken() {
        $token = get_option(ABJ_404_Solution_GscConfig::TOKEN_OPTION_KEY, false);
        if (!is_array($token)) {
            return false;
        }
        return $this->normalizeStringKeyedArray($token);
    }

    /** @return array<string, mixed>|false */
    private function decodeJsonObject(string $json) {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return false;
        }
        return $this->normalizeStringKeyedArray($decoded);
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeStringKeyedArray(array $values): array {
        $normalized = array();
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    /** @param array<string, mixed> $payload */
    private function payloadString(array $payload, string $key, string $default = ''): string {
        $value = $payload[$key] ?? $default;
        return is_scalar($value) ? (string)$value : $default;
    }

    /** @param array<string, mixed> $payload */
    private function payloadInt(array $payload, string $key, int $default): int {
        $value = $payload[$key] ?? null;
        return is_numeric($value) ? (int)$value : $default;
    }

    /** @param array<string, mixed> $body */
    private function expiresAtFromBody(array $body): int {
        $expiresIn = $this->payloadInt($body, 'expires_in', 0);
        return $expiresIn > 0 ? abj_clock()->now() + $expiresIn - 60 : 0;
    }
}
