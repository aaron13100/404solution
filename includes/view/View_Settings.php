<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ViewTrait_Settings methods.
 */
class ABJ_404_Solution_View_Settings extends ABJ_404_Solution_ViewComponent {

    /**
     * Load a settings page template fragment from includes/html/ and trim
     * the trailing newline added by the editor. Matches the pattern used by
     * View_RedirectsTable::tpl() and View_Logs::tpl() so all admin pages share
     * one template-load convention.
     *
     * @param string $name Filename relative to includes/html/
     * @return string Raw template body with the trailing newline removed.
     */
    private function tpl($name) {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /**
     * Load a settings page template fragment and substitute an associative
     * map of placeholders. Values are passed through unchanged; callers are
     * responsible for applying the same esc_*() / wp_create_nonce()
     * wrapping that the inline echo'd HTML used before externalization.
     *
     * @param string $name Filename relative to includes/html/
     * @param array<string,string> $vars Placeholder map (keys WITH braces, e.g. '{nonce}')
     * @return string Template body with placeholders substituted.
     */
    private function fillTpl($name, array $vars) {
        return (string)$this->f->str_replace(array_keys($vars), array_values($vars), $this->tpl($name));
    }


    /** @return void */
    function echoAdminOptionsPage() {
        global $abj404view;
        global $abj404viewSuggestions;

        // If globals are not set, use sensible defaults
        if ($abj404view === null) {
            $abj404view = $this->view;
        }

        $options = $this->optionsPresenter->getOptionsWithDefaults();

        // Get the current user's settings mode preference
        $settingsMode = abj_service('settings_mode_preference')->getMode();

        // if the current URL does not match the chosen menuLocation then redirect to the correct URL
        $urlParts = parse_url(abj_service('sanitizer')->normalizeUrlString($_SERVER['REQUEST_URI'] ?? ''));
        $currentURL = (is_array($urlParts) && isset($urlParts['path'])) ? $urlParts['path'] : '';
        if (is_array($options) && isset($options['menuLocation']) &&
                $options['menuLocation'] == 'settingsLevel') {
            if ($this->f->strpos($currentURL, 'options-general.php') !== false) {
                // the option changed and we're at the wrong URL now, so we redirect to the correct one.
                abj_service('not_found_response')->forceRedirect(admin_url() . "admin.php?page=" .
                        ABJ404_PP . '&subpage=abj404_options');
            }
        } else if ($this->f->strpos($currentURL, 'admin.php') !== false) {
            // if the current URL has admin.php then the URLs don't match and we need to reload.
            abj_service('not_found_response')->forceRedirect(admin_url() . "options-general.php?page=" .
                    ABJ404_PP . '&subpage=abj404_options');
        }

        // Toast notification container
        $this->adminChrome->echoToastNotification();

        // Options page header: header-row open + h2 + header-controls open,
        // then the inline mode toggle (which echoes its own block), then the
        // expand/collapse button and the closing tags. Split into two
        // templates around the toggle so we do not have to capture its
        // output buffer (and so View_UI keeps its existing echo-based API).
        echo $this->fillTpl('viewSettingsHeaderRowOpen.html', array(
            '{titleOptions}' => esc_html__('Options', '404-solution'),
        ));
        $this->adminChrome->echoInlineModeToggle();
        // Expand/Collapse All button for both Simple and Advanced modes
        echo $this->fillTpl('viewSettingsExpandCollapseButton.html', array(
            '{labelExpandAll}' => esc_html__('Expand All', '404-solution'),
        ));
        echo $this->tpl('viewSettingsHeaderRowClose.html');

        // Main container
        echo $this->tpl('viewSettingsContainerOpen.html');

        // Form opening: the data-url and nonce are populated at render time.
        // wp_create_nonce() and the action URL match the values the prior
        // inline echo produced, so the rendered output is unchanged.
        echo $this->fillTpl('viewSettingsFormOpen.html', array(
            '{data-url}' => 'admin-ajax.php?action=updateOptions',
            '{nonce}' => wp_create_nonce('abj404UpdateOptions'),
        ));

        // Loading overlay shown while a settings save is in flight.
        echo $this->fillTpl('viewSettingsSaveOverlay.html', array(
            '{labelSaving}' => esc_html__('Saving settings...', '404-solution'),
        ));

        if ($settingsMode === 'simple') {
            // Simple Mode: Show streamlined options with card layout
            $this->simpleSettings->echoSimpleModeOptions($options);
        } else {
            // Advanced Mode: Show all card sections with icons

            // Render each section with card structure and icons
            $contentAutomaticRedirects = $this->settingsSections->getAdminOptionsPageAutoRedirects($options);
            $this->adminChrome->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
                "abj404-autooptions",
                "abj404-autooptions",
                __('Automatic Redirects', '404-solution'),
                $contentAutomaticRedirects,
                true,
                $this->adminChrome->getCardIcon('lightning')
            ));

            $contentGeneralSettings = $this->settingsSections->getAdminOptionsPageGeneralSettings($options);
            $this->adminChrome->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
                "abj404-generaloptions",
                "abj404-generaloptions",
                __('General Settings', '404-solution'),
                $contentGeneralSettings,
                true,
                $this->adminChrome->getCardIcon('gear')
            ));

            $contentAdvancedContent = $this->settingsSections->getAdminOptionsPageAdvancedContent($options);
            $this->adminChrome->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
                "abj404-advanced-content",
                "abj404-advanced-content",
                __('Content & URL Filtering', '404-solution'),
                $contentAdvancedContent,
                true,
                $this->adminChrome->getCardIcon('filter')
            ));

            $contentAdvancedLogging = $this->settingsSections->getAdminOptionsPageAdvancedLogging($options);
            $this->adminChrome->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
                "abj404-advanced-logging",
                "abj404-advanced-logging",
                __('Logging & Privacy', '404-solution'),
                $contentAdvancedLogging,
                true,
                $this->adminChrome->getCardIcon('document')
            ));

            // "Need help?" support-request section. Anchored at
            // #abj404-support-request so the plugins-page row action
            // (which appends that fragment) lands the admin right here
            // and the JS component auto-opens the modal on arrival.
            // Lives on the plugin's own Settings page so it does not
            // violate CLAUDE.md Self-Healing §4 (no support buttons
            // on screens outside abj404_solution).
            $supportButton = class_exists('ABJ_404_Solution_SupportRequestButton')
                ? ABJ_404_Solution_SupportRequestButton::render('settings_debug')
                : '';
            $supportSectionHtml = $this->fillTpl('viewSettingsSupportSection.html', array(
                '{supportDescription}' => esc_html__('Having trouble? Send your debug log to the developer. This sends a one-time diagnostic report (URLs, PHP/WP/DB versions, a debug log excerpt, active plugins, site URL) so we can diagnose the issue without asking you to copy-paste anything.', '404-solution'),
                '{supportButton}' => $supportButton,
            ));
            $this->adminChrome->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
                "abj404-support-request-section",
                "abj404-support-request-section",
                __('Need help? Contact the developer', '404-solution'),
                $supportSectionHtml,
                true,
                $this->adminChrome->getCardIcon('lightbulb')
            ));

            $contentAdvancedSystem = $this->settingsSections->getAdminOptionsPageAdvancedSystem($options);
            $this->adminChrome->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
                "abj404-advanced-system",
                "abj404-advanced-system",
                __('Advanced Configuration', '404-solution'),
                $contentAdvancedSystem,
                true,
                $this->adminChrome->getCardIcon('sliders')
            ));

            // Only render suggestions section if the suggestions view is available
            if ($abj404viewSuggestions !== null && is_object($abj404viewSuggestions) && method_exists($abj404viewSuggestions, 'getAdminOptionsPage404Suggestions')) {
                /** @var ABJ_404_Solution_View_Suggestions $abj404viewSuggestions */
                $content404PageSuggestions = $abj404viewSuggestions->getAdminOptionsPage404Suggestions($options);
                $this->adminChrome->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
                    "abj404-suggestoptions",
                    "abj404-suggestoptions",
                    __('404 Page Suggestions', '404-solution'),
                    $content404PageSuggestions,
                    true,
                    $this->adminChrome->getCardIcon('lightbulb')
                ));
            }
        }

        echo $this->tpl('viewSettingsFormClose.html');

        // Engine Profiles and GSC are advanced features — hidden in simple mode
        if ($settingsMode === 'advanced') {
            // Engine Profiles — outside the main form (uses its own AJAX save)
            $epHtml = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/engineProfilesSection.html');
            $epHtml = $this->f->doNormalReplacements($epHtml);
            $this->adminChrome->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('settings-engine-profiles', 'abj404-engineProfiles', __('Engine Profiles', '404-solution'), $epHtml, false, $this->adminChrome->getCardIcon('filter')));

            // Google Search Console — deferred via AJAX so the options page shell
            // renders immediately and is not blocked by logs/GSC data fetches.
            $gscPlaceholder = $this->fillTpl('viewSettingsGscDeferred.html', array(
                '{gscNonce}' => esc_attr(wp_create_nonce('abj404_gsc_deferred')),
                // allow-em-dash: pre-existing translation string with U+2026 ellipsis in published .po files
                '{labelLoadingGsc}' => esc_html__('Loading Google Search Console section…', '404-solution'),
            ));
            $this->adminChrome->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView(
                'settings-gsc',
                'abj404-gsc-section',
                __('Google Search Console', '404-solution'),
                $gscPlaceholder,
                true,
                $this->adminChrome->getCardIcon('chart')
            ));
        }

        // Sticky save bar — outside the form but linked via form="admin-options-page" on the submit button
        $this->adminChrome->echoStickySaveBar();

        // Close abj404-settings-content and abj404-container
        echo $this->tpl('viewSettingsContainerClose.html');
    }

}
