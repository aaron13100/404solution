<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the Google Search Console admin settings/status section.
 */
class ABJ_404_Solution_GscAdminSectionRenderer {

    /** @var ABJ_404_Solution_GscOAuthTokenStore */
    private $oauthStore;

    /** @var ABJ_404_Solution_GscSearchAnalyticsClient */
    private $searchAnalytics;

    public function __construct(
        ABJ_404_Solution_GscOAuthTokenStore $oauthStore,
        ABJ_404_Solution_GscSearchAnalyticsClient $searchAnalytics
    ) {
        $this->oauthStore = $oauthStore;
        $this->searchAnalytics = $searchAnalytics;
    }

    /**
     * Render the inner content for the GSC settings/status card.
     *
     * @param string $state Current facade state.
     * @return string HTML.
     */
    public function renderAdminSection(string $state): string {
        switch ($state) {
            case 'not_configured':
                return $this->renderNotConfiguredState();
            case 'configured_not_connected':
                return $this->renderConfiguredNotConnectedState();
            case 'error':
                return $this->renderErrorState();
            default:
                return $this->renderConnectedState();
        }
    }

    /**
     * State: no custom credentials entered and centralized mode not yet authorized.
     *
     * @return string
     */
    private function renderNotConfiguredState(): string {
        $html  = '<p>' . esc_html__('Connect to Google Search Console to see which broken URLs were getting real search traffic.', '404-solution') . '</p>';
        $html .= '<details class="abj404-gsc-advanced">';
        $html .= '<summary style="cursor:pointer;margin-top:12px;color:var(--abj404-text-muted);">' . esc_html__('Advanced: use your own Google Cloud credentials', '404-solution') . '</summary>';
        $html .= '<div style="margin-top:10px;">';
        $html .= $this->renderCustomCredentialsForm();
        $html .= '</div>';
        $html .= '</details>';

        return $html;
    }

    /**
     * State: configured but OAuth not yet completed.
     *
     * @return string
     */
    private function renderConfiguredNotConnectedState(): string {
        $authUrl   = $this->oauthStore->buildAuthUrl();
        $revokeUrl = wp_nonce_url(admin_url('admin-ajax.php?action=abj404_gsc_revoke'), 'abj404_gsc_revoke');

        if ($this->oauthStore->isCentralizedMode()) {
            $html  = '<p>' . esc_html__('Connect to Google Search Console to see which broken URLs were getting real search traffic.', '404-solution') . '</p>';
            $html .= '<a href="' . esc_url($authUrl) . '" class="abj404-btn abj404-btn-primary">' . esc_html__('Connect to Google Search Console', '404-solution') . '</a>';
            $html .= '<details class="abj404-gsc-advanced" style="margin-top:16px;">';
            $html .= '<summary style="cursor:pointer;color:var(--abj404-text-muted);">' . esc_html__('Advanced: use your own Google Cloud credentials', '404-solution') . '</summary>';
            $html .= '<div style="margin-top:10px;">';
            $html .= $this->renderCustomCredentialsForm();
            $html .= '</div>';
            $html .= '</details>';

            return $html;
        }

        $html  = '<div class="abj404-gsc-status abj404-gsc-status--amber">';
        // allow-em-dash: preserving existing translated admin status label during renderer extraction.
        $html .= esc_html__('Credentials saved — authorization required', '404-solution');
        $html .= '</div>';
        $html .= '<p>' . esc_html__('Click the button below to authorize access to your Search Console data.', '404-solution') . '</p>';
        $html .= '<a href="' . esc_url($authUrl) . '" class="abj404-btn abj404-btn-primary">' . esc_html__('Authorize with Google', '404-solution') . '</a>';
        $html .= ' <a href="' . esc_url($revokeUrl) . '" class="abj404-btn abj404-btn-secondary">' . esc_html__('Remove Credentials', '404-solution') . '</a>';

        return $html;
    }

    /**
     * Render the custom credentials form used in advanced sections.
     *
     * @return string HTML.
     */
    private function renderCustomCredentialsForm(): string {
        $callbackUrl = $this->oauthStore->getCallbackUrl();
        $s           = $this->oauthStore->getSettings();
        $copiedLabel = esc_js(__('Copied!', '404-solution'));

        $html  = '<p><strong>' . esc_html__('Setup steps:', '404-solution') . '</strong></p>';
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
     * State: fully connected.
     *
     * @return string
     */
    private function renderConnectedState(): string {
        $revokeUrl = wp_nonce_url(admin_url('admin-ajax.php?action=abj404_gsc_revoke'), 'abj404_gsc_revoke');

        $html  = '<div class="abj404-gsc-status abj404-gsc-status--green">';
        $html .= esc_html__('Connected to Google Search Console', '404-solution');
        $html .= '</div>';
        $html .= '<p>' . esc_html__('Search traffic data for your captured 404 URLs is shown below. Data is refreshed nightly.', '404-solution') . '</p>';
        $html .= '<a href="' . esc_url($revokeUrl) . '" class="abj404-btn abj404-btn-secondary">' . esc_html__('Disconnect', '404-solution') . '</a>';

        $cached = $this->searchAnalytics->getCachedData();

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
                $rowUrl = isset($row['url']) && is_scalar($row['url']) ? (string)$row['url'] : '';
                $rowClicks = isset($row['clicks']) && is_scalar($row['clicks']) ? (string)$row['clicks'] : '0';
                $rowImpressions = isset($row['impressions']) && is_scalar($row['impressions']) ? (string)$row['impressions'] : '0';
                $rowPosition = isset($row['position']) && is_scalar($row['position']) ? (string)$row['position'] : '-';
                $html .= '<td>' . esc_html($rowUrl) . '</td>';
                $html .= '<td>' . esc_html($rowClicks) . '</td>';
                $html .= '<td>' . esc_html($rowImpressions) . '</td>';
                $html .= '<td>' . esc_html($rowPosition) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        } elseif ($this->searchAnalytics->isRefreshNeeded()) {
            $html .= '<p style="margin-top:12px;color:var(--abj404-text-muted);">' . esc_html__('GSC data is being fetched in the background. Reload this page in a few minutes.', '404-solution') . '</p>';
        } else {
            $html .= '<p style="margin-top:12px;color:var(--abj404-text-muted);">' . esc_html__('No search traffic data found for your captured 404 URLs in the last 90 days.', '404-solution') . '</p>';
        }

        return $html;
    }

    /**
     * State: authorization was attempted but failed.
     *
     * @return string
     */
    private function renderErrorState(): string {
        $error     = $this->oauthStore->getLastOAuthError();
        $authUrl   = $this->oauthStore->buildAuthUrl();
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
