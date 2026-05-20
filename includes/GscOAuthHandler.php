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
        if (!current_user_can('manage_options')) {
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
            $gsc->setLastOAuthError(__('Authorization was denied or cancelled.', '404-solution'));
            wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
            exit;
        }

        $error = $gsc->exchangeCodeForToken($code);

        if ($error !== '') {
            $gsc->setLastOAuthError($error);
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

        $error = isset($_GET['abj404_gsc_error']) ? sanitize_text_field((string)$_GET['abj404_gsc_error']) : '';
        if ($error !== '') {
            $gsc->setLastOAuthError($error);
            wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
            exit;
        }

        $accessToken  = isset($_GET['access_token'])  ? sanitize_text_field((string)$_GET['access_token'])  : '';
        $refreshToken = isset($_GET['refresh_token']) ? sanitize_text_field((string)$_GET['refresh_token']) : '';
        $expiresIn    = isset($_GET['expires_in'])    ? (int)$_GET['expires_in']                             : 3600;

        if ($accessToken === '') {
            $gsc->setLastOAuthError(__('No access token received from authorization.', '404-solution'));
            wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
            exit;
        }

        $gsc->storeCentralizedTokens($accessToken, $refreshToken, $expiresIn);

        wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
        exit;
    }

    /**
     * AJAX handler: revoke GSC authorization.
     *
     * @return void
     */
    public static function handleRevoke() {
        if (!current_user_can('manage_options') || !check_admin_referer('abj404_gsc_revoke')) {
            wp_die(__('Security check failed.', '404-solution'), 403);
        }

        $logger = abj_service('logging');
        $gsc    = new ABJ_404_Solution_GoogleSearchConsole($logger);
        $gsc->revokeAuthorization();

        wp_safe_redirect(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'));
        exit;
    }
}
