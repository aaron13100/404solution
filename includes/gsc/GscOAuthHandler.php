<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Search Console OAuth callback and revocation handler.
 *
 * Supports both custom-credentials flow (code exchange) and centralized
 * flow (Worker has already exchanged tokens).
 */
class ABJ_404_Solution_GscOAuthHandler {

    /**
     * AJAX handler: OAuth callback from Google (custom mode) or from the
     * centralized Worker (centralized mode).
     *
     * @return void
     */
    public static function handleCallback() {
        if (!ABJ_404_Solution_PluginAdminAccessPolicy::currentUserCanAccessPluginAdmin()) {
            wp_die(__('Insufficient permissions.', '404-solution'), 403);
        }

        $logger = abj_service('logging');
        $gsc    = new ABJ_404_Solution_GoogleSearchConsole($logger);

        $isCentralized = isset($_GET['abj404_gsc_centralized']) && $_GET['abj404_gsc_centralized'] === '1';

        if ($isCentralized) {
            self::handleCentralizedCallback($gsc);
            return;
        }

        $code  = isset($_GET['code'])  ? sanitize_text_field((string)$_GET['code'])  : '';
        $state = isset($_GET['state']) ? sanitize_text_field((string)$_GET['state']) : '';

        if (!wp_verify_nonce($state, 'abj404_gsc_oauth')) {
            wp_die(__('Security check failed.', '404-solution'), 403);
        }

        if ($code === '') {
            $gsc->oauthStore()->setLastOAuthError(__('Authorization was denied or cancelled.', '404-solution'));
            wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
            exit;
        }

        $error = $gsc->oauthStore()->exchangeCodeForToken($code);

        if ($error !== '') {
            $gsc->oauthStore()->setLastOAuthError($error);
        }
        wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
        exit;
    }

    /**
     * Handle the centralized OAuth callback.
     *
     * @param ABJ_404_Solution_GoogleSearchConsole $gsc
     * @return void
     */
    private static function handleCentralizedCallback(ABJ_404_Solution_GoogleSearchConsole $gsc): void {
        $nonce = isset($_GET['nonce']) ? sanitize_text_field((string)$_GET['nonce']) : '';

        if (!wp_verify_nonce($nonce, 'abj404_gsc_oauth')) {
            wp_die(__('Security check failed.', '404-solution'), 403);
        }

        $payload = self::verifiedCentralizedPayload($nonce);

        $error = self::payloadString($payload, 'abj404_gsc_error');
        if ($error !== '') {
            $gsc->oauthStore()->setLastOAuthError($error);
            wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
            exit;
        }

        $accessToken  = self::payloadString($payload, 'access_token');
        $refreshToken = self::payloadString($payload, 'refresh_token');
        $expiresIn    = self::payloadInt($payload, 'expires_in', 3600);

        if ($accessToken === '') {
            $gsc->oauthStore()->setLastOAuthError(__('No access token received from authorization.', '404-solution'));
            wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
            exit;
        }

        $gsc->oauthStore()->storeCentralizedTokens($accessToken, $refreshToken, $expiresIn);

        wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
        exit;
    }

    /**
     * Verify and decode the centralized Worker's signed callback payload.
     *
     * @param string $nonce WordPress OAuth callback nonce.
     * @return array<string, mixed>
     */
    private static function verifiedCentralizedPayload(string $nonce): array {
        $rawEncodedPayload = $_GET['abj404_gsc_payload'] ?? '';
        $encodedPayload = is_scalar($rawEncodedPayload)
            ? sanitize_text_field((string)$rawEncodedPayload)
            : '';
        $rawSignature = $_GET['abj404_gsc_signature'] ?? '';
        $signature = is_scalar($rawSignature)
            ? sanitize_text_field((string)$rawSignature)
            : '';

        $secretKey = ABJ_404_Solution_GscConfig::centralizedCallbackSecretTransientKey($nonce);
        $secret = get_transient($secretKey);

        if ($encodedPayload === '' || $signature === '' || !is_string($secret) || $secret === '') {
            wp_die(__('Security check failed.', '404-solution'), 403);
        }

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $secret);
        if (!hash_equals($expectedSignature, $signature)) {
            wp_die(__('Security check failed.', '404-solution'), 403);
        }

        $json = self::base64UrlDecode($encodedPayload);
        if ($json === '') {
            wp_die(__('Security check failed.', '404-solution'), 403);
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            wp_die(__('Security check failed.', '404-solution'), 403);
        }

        $normalizedPayload = array();
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                wp_die(__('Security check failed.', '404-solution'), 403);
            }
            $normalizedPayload[$key] = $value;
        }

        $payloadNonce = $normalizedPayload['nonce'] ?? null;
        if (!is_scalar($payloadNonce) || (string)$payloadNonce !== $nonce) {
            wp_die(__('Security check failed.', '404-solution'), 403);
        }

        delete_transient($secretKey);

        return $normalizedPayload;
    }

    private static function base64UrlDecode(string $value): string {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function payloadString(array $payload, string $key): string {
        $value = $payload[$key] ?? '';
        return is_scalar($value) ? sanitize_text_field((string)$value) : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function payloadInt(array $payload, string $key, int $default): int {
        $value = $payload[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }
        return $default;
    }

    /**
     * AJAX handler: revoke GSC authorization.
     *
     * @return void
     */
    public static function handleRevoke() {
        if (!ABJ_404_Solution_PluginAdminAccessPolicy::currentUserCanAccessPluginAdmin() ||
                !check_admin_referer('abj404_gsc_revoke')) {
            wp_die(__('Security check failed.', '404-solution'), 403);
        }

        $logger = abj_service('logging');
        $gsc    = new ABJ_404_Solution_GoogleSearchConsole($logger);
        $gsc->oauthStore()->revokeAuthorization();

        wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
        exit;
    }
}
