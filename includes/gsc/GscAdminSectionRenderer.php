<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the Google Search Console admin settings/status section.
 *
 * HTML templates live in includes/html/gsc*.html with {placeholder} tokens; this
 * class loads them via FileSystemService::readFileContents() and substitutes
 * translated labels / per-request URLs / dynamic data via str_replace().
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
     * By default the UI state is derived from the OAuth/token store this
     * renderer already holds, so callers do not have to compute and pass it.
     * A caller may pass an explicit $state to render a specific branch (used to
     * exercise states that are unreachable through normal configuration, e.g.
     * 'not_configured' while centralized mode is active).
     *
     * @param string|null $state Force a specific UI state, or null to derive it.
     * @return string HTML.
     */
    public function renderAdminSection(?string $state = null): string {
        if ($state === null) {
            $state = $this->oauthStore->getState();
        }
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
        $tpl = $this->loadTemplate('gscNotConfiguredState.html');
        return strtr($tpl, [
            '{intro_text}'              => esc_html__('Connect to Google Search Console to see which broken URLs were getting real search traffic.', '404-solution'),
            '{advanced_label}'          => esc_html__('Advanced: use your own Google Cloud credentials', '404-solution'),
            '{custom_credentials_form}' => $this->renderCustomCredentialsForm(),
        ]);
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
            $tpl = $this->loadTemplate('gscConfiguredNotConnectedCentralized.html');
            return strtr($tpl, [
                '{intro_text}'              => esc_html__('Connect to Google Search Console to see which broken URLs were getting real search traffic.', '404-solution'),
                '{auth_url}'                => esc_url($authUrl),
                '{connect_label}'           => esc_html__('Connect to Google Search Console', '404-solution'),
                '{advanced_label}'          => esc_html__('Advanced: use your own Google Cloud credentials', '404-solution'),
                '{custom_credentials_form}' => $this->renderCustomCredentialsForm(),
            ]);
        }

        $tpl = $this->loadTemplate('gscConfiguredNotConnectedCustom.html');
        // allow-em-dash: preserving existing translated admin status label during renderer extraction.
        $statusLabel = esc_html__('Credentials saved — authorization required', '404-solution');
        return strtr($tpl, [
            '{status_label}'    => $statusLabel,
            '{auth_prompt}'     => esc_html__('Click the button below to authorize access to your Search Console data.', '404-solution'),
            '{auth_url}'        => esc_url($authUrl),
            '{authorize_label}' => esc_html__('Authorize with Google', '404-solution'),
            '{revoke_url}'      => esc_url($revokeUrl),
            '{remove_label}'    => esc_html__('Remove Credentials', '404-solution'),
        ]);
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

        $stepCreateProject = sprintf(
            esc_html__('Create a project in %s.', '404-solution'),
            '<a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>'
        );

        $tpl = $this->loadTemplate('gscCustomCredentialsForm.html');
        return strtr($tpl, [
            '{setup_steps_label}'    => esc_html__('Setup steps:', '404-solution'),
            '{step_create_project}'  => $stepCreateProject,
            '{step_enable_api}'      => esc_html__('Enable the "Google Search Console API".', '404-solution'),
            '{step_create_credentials}' => esc_html__('Create OAuth 2.0 credentials (Web application type).', '404-solution'),
            '{step_add_redirect_uri}'   => esc_html__('Add this Authorized Redirect URI to your OAuth client:', '404-solution'),
            '{callback_url}'         => esc_html($callbackUrl),
            '{copy_label}'           => esc_html__('Copy', '404-solution'),
            '{copied_label}'         => (string)$copiedLabel,
            '{step_enter_credentials}' => esc_html__('Enter your Client ID and Client Secret below.', '404-solution'),
            '{nonce_field}'          => wp_nonce_field('abj404_gsc_save', '_wpnonce_gsc', true, false),
            '{client_id_label}'      => esc_html__('Client ID', '404-solution'),
            '{client_id_value}'      => esc_attr($s['client_id']),
            '{client_secret_label}'  => esc_html__('Client Secret', '404-solution'),
            '{client_secret_value}'  => esc_attr($s['client_secret']),
            '{site_url_label}'       => esc_html__('Search Console Site URL', '404-solution'),
            '{site_url_value}'       => esc_attr($s['site_url']),
            '{site_url_help}'        => esc_html__('The site URL as registered in Search Console (e.g. https://example.com/).', '404-solution'),
            '{save_label}'           => esc_html__('Save Credentials', '404-solution'),
        ]);
    }

    /**
     * State: fully connected.
     *
     * @return string
     */
    private function renderConnectedState(): string {
        $revokeUrl = wp_nonce_url(admin_url('admin-ajax.php?action=abj404_gsc_revoke'), 'abj404_gsc_revoke');

        $headerTpl = $this->loadTemplate('gscConnectedHeader.html');
        $html = strtr($headerTpl, [
            '{status_label}'      => esc_html__('Connected to Google Search Console', '404-solution'),
            '{description}'       => esc_html__('Search traffic data for your captured 404 URLs is shown below. Data is refreshed nightly.', '404-solution'),
            '{revoke_url}'        => esc_url($revokeUrl),
            '{disconnect_label}'  => esc_html__('Disconnect', '404-solution'),
        ]);

        $cached = $this->searchAnalytics->getCachedData();

        if (is_array($cached) && !empty($cached)) {
            $rowTpl = $this->loadTemplate('gscConnectedTableRow.html');
            $rows = '';
            foreach (array_slice($cached, 0, 25) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowUrl = isset($row['url']) && is_scalar($row['url']) ? (string)$row['url'] : '';
                $rowClicks = isset($row['clicks']) && is_scalar($row['clicks']) ? (string)$row['clicks'] : '0';
                $rowImpressions = isset($row['impressions']) && is_scalar($row['impressions']) ? (string)$row['impressions'] : '0';
                $rowPosition = isset($row['position']) && is_scalar($row['position']) ? (string)$row['position'] : '-';
                $rows .= strtr($rowTpl, [
                    '{url}'         => esc_html($rowUrl),
                    '{clicks}'      => esc_html($rowClicks),
                    '{impressions}' => esc_html($rowImpressions),
                    '{position}'    => esc_html($rowPosition),
                ]);
            }
            $tableTpl = $this->loadTemplate('gscConnectedTable.html');
            $html .= strtr($tableTpl, [
                '{table_heading}'  => esc_html__('404 URLs with Search Traffic (last 90 days)', '404-solution'),
                '{th_url}'         => esc_html__('URL', '404-solution'),
                '{th_clicks}'      => esc_html__('Clicks', '404-solution'),
                '{th_impressions}' => esc_html__('Impressions', '404-solution'),
                '{th_position}'    => esc_html__('Avg. Position', '404-solution'),
                '{rows}'           => $rows,
            ]);
        } elseif ($this->searchAnalytics->isRefreshNeeded()) {
            $html .= $this->renderMutedNotice(
                esc_html__('GSC data is being fetched in the background. Reload this page in a few minutes.', '404-solution')
            );
        } else {
            $html .= $this->renderMutedNotice(
                esc_html__('No search traffic data found for your captured 404 URLs in the last 90 days.', '404-solution')
            );
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

        $errorBlock = '';
        if ($error !== '') {
            $errorParaTpl = $this->loadTemplate('gscErrorMessageParagraph.html');
            $errorBlock = strtr($errorParaTpl, ['{error}' => esc_html($error)]);
        }

        $tpl = $this->loadTemplate('gscErrorState.html');
        return strtr($tpl, [
            '{title}'           => esc_html__('Authorization failed', '404-solution'),
            '{error_block}'     => $errorBlock,
            '{instructions}'    => esc_html__("Click 'Try Again' to retry authorization, or 'Remove Credentials' to start over.", '404-solution'),
            '{auth_url}'        => esc_url($authUrl),
            '{try_again_label}' => esc_html__('Try Again', '404-solution'),
            '{revoke_url}'      => esc_url($revokeUrl),
            '{remove_label}'    => esc_html__('Remove Credentials', '404-solution'),
        ]);
    }

    /**
     * Render the small muted notice paragraph used when the connected table is
     * empty (refresh-in-progress / no-data cases).
     *
     * @param string $message Already-escaped translated message.
     * @return string HTML.
     */
    private function renderMutedNotice(string $message): string {
        $tpl = $this->loadTemplate('gscMutedNotice.html');
        return strtr($tpl, ['{message}' => $message]);
    }

    /**
     * Read an HTML template from includes/html/.
     *
     * @param string $filename Template filename relative to includes/html/.
     * @return string Template contents.
     */
    private function loadTemplate(string $filename): string {
        return ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/' . $filename
        );
    }
}
