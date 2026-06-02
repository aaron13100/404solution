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
        $raw = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/' . $name, false);
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

        $options = $this->shared->getOptionsWithDefaults();

        // Get the current user's settings mode preference
        $settingsMode = abj_service('settings_mode_preference')->getMode();

        // if the current URL does not match the chosen menuLocation then redirect to the correct URL
        $helperFunctions = abj_service('functions');
        $urlParts = parse_url($helperFunctions->normalizeUrlString($_SERVER['REQUEST_URI'] ?? ''));
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
        $abj404view->echoToastNotification();

        // Options page header: header-row open + h2 + header-controls open,
        // then the inline mode toggle (which echoes its own block), then the
        // expand/collapse button and the closing tags. Split into two
        // templates around the toggle so we do not have to capture its
        // output buffer (and so View_UI keeps its existing echo-based API).
        echo $this->fillTpl('viewSettingsHeaderRowOpen.html', array(
            '{titleOptions}' => esc_html__('Options', '404-solution'),
        ));
        $this->ui->echoInlineModeToggle();
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
            $abj404view->echoSimpleModeOptions($options);
        } else {
            // Advanced Mode: Show all card sections with icons

            // Render each section with card structure and icons
            $contentAutomaticRedirects = $abj404view->getAdminOptionsPageAutoRedirects($options);
            $abj404view->echoOptionsSection(
                "abj404-autooptions",
                "abj404-autooptions",
                __('Automatic Redirects', '404-solution'),
                $contentAutomaticRedirects,
                true,
                $abj404view->getCardIcon('lightning')
            );

            $contentGeneralSettings = $abj404view->getAdminOptionsPageGeneralSettings($options);
            $abj404view->echoOptionsSection(
                "abj404-generaloptions",
                "abj404-generaloptions",
                __('General Settings', '404-solution'),
                $contentGeneralSettings,
                true,
                $abj404view->getCardIcon('gear')
            );

            $contentAdvancedContent = $abj404view->getAdminOptionsPageAdvancedContent($options);
            $abj404view->echoOptionsSection(
                "abj404-advanced-content",
                "abj404-advanced-content",
                __('Content & URL Filtering', '404-solution'),
                $contentAdvancedContent,
                true,
                $abj404view->getCardIcon('filter')
            );

            $contentAdvancedLogging = $abj404view->getAdminOptionsPageAdvancedLogging($options);
            $abj404view->echoOptionsSection(
                "abj404-advanced-logging",
                "abj404-advanced-logging",
                __('Logging & Privacy', '404-solution'),
                $contentAdvancedLogging,
                true,
                $abj404view->getCardIcon('document')
            );

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
            $abj404view->echoOptionsSection(
                "abj404-support-request-section",
                "abj404-support-request-section",
                __('Need help? Contact the developer', '404-solution'),
                $supportSectionHtml,
                true,
                $abj404view->getCardIcon('lightbulb')
            );

            $contentAdvancedSystem = $abj404view->getAdminOptionsPageAdvancedSystem($options);
            $abj404view->echoOptionsSection(
                "abj404-advanced-system",
                "abj404-advanced-system",
                __('Advanced Configuration', '404-solution'),
                $contentAdvancedSystem,
                true,
                $abj404view->getCardIcon('sliders')
            );

            // Only render suggestions section if the suggestions view is available
            if ($abj404viewSuggestions !== null && is_object($abj404viewSuggestions) && method_exists($abj404viewSuggestions, 'getAdminOptionsPage404Suggestions')) {
                /** @var ABJ_404_Solution_View_Suggestions $abj404viewSuggestions */
                $content404PageSuggestions = $abj404viewSuggestions->getAdminOptionsPage404Suggestions($options);
                $abj404view->echoOptionsSection(
                    "abj404-suggestoptions",
                    "abj404-suggestoptions",
                    __('404 Page Suggestions', '404-solution'),
                    $content404PageSuggestions,
                    true,
                    $abj404view->getCardIcon('lightbulb')
                );
            }
        }

        echo $this->tpl('viewSettingsFormClose.html');

        // Engine Profiles and GSC are advanced features — hidden in simple mode
        if ($settingsMode === 'advanced') {
            // Engine Profiles — outside the main form (uses its own AJAX save)
            $epHtml = ABJ_404_Solution_Functions::readFileContents(__DIR__ . '/html/engineProfilesSection.html');
            $epHtml = $this->f->doNormalReplacements($epHtml);
            $abj404view->echoOptionsSection('settings-engine-profiles', 'abj404-engineProfiles', __('Engine Profiles', '404-solution'), $epHtml, false, $abj404view->getCardIcon('filter'));

            // Google Search Console — deferred via AJAX so the options page shell
            // renders immediately and is not blocked by logs/GSC data fetches.
            $gscPlaceholder = $this->fillTpl('viewSettingsGscDeferred.html', array(
                '{gscNonce}' => esc_attr(wp_create_nonce('abj404_gsc_deferred')),
                // allow-em-dash: pre-existing translation string with U+2026 ellipsis in published .po files
                '{labelLoadingGsc}' => esc_html__('Loading Google Search Console section…', '404-solution'),
            ));
            $abj404view->echoOptionsSection(
                'settings-gsc',
                'abj404-gsc-section',
                __('Google Search Console', '404-solution'),
                $gscPlaceholder,
                true,
                $abj404view->getCardIcon('chart')
            );
        }

        // Sticky save bar — outside the form but linked via form="admin-options-page" on the submit button
        $abj404view->echoStickySaveBar();

        // Close abj404-settings-content and abj404-container
        echo $this->tpl('viewSettingsContainerClose.html');
    }
    
    /**
     * @return void
     */

    
    /**
     * @param array<string, mixed> $options
     * @return string
     */
    function getAdminOptionsPageAutoRedirects($options) {
        $options = $this->shared->normalizeOptionsForView($options);

        $spaces = esc_html("&nbsp;&nbsp;&nbsp;");
        $content = "";

        // Behavior tiles (replaces the old page-search dropdown)
        $content .= '<div class="abj404-form-group">';
        $content .= '<label class="abj404-form-label">' . __('Default 404 destination', '404-solution') . '</label>';
        $content .= $this->shared->getBehaviorTilesHTML($options);
        $content .= '</div>';

        // -----------------------------------------------
        // Load auto redirects options template
        $selectedAutoRedirects = $this->shared->getCheckedAttr($options, 'auto_redirects');
        $selectedAutoSlugs = $this->shared->getCheckedAttr($options, 'auto_slugs');
        $selectedAutoCats = $this->shared->getCheckedAttr($options, 'auto_cats');
        $selectedAutoTags = $this->shared->getCheckedAttr($options, 'auto_tags');
        $selectedAutoTrashRedirect = $this->shared->getCheckedAttr($options, 'auto_trash_redirect');

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminOptionsAutoRedirects.html");
        $html = $this->f->str_replace('{selectedAutoRedirects}', $selectedAutoRedirects, $html);
        $html = $this->f->str_replace('{selectedAutoSlugs}', $selectedAutoSlugs, $html);
        $html = $this->f->str_replace('{selectedAutoCats}', $selectedAutoCats, $html);
        $html = $this->f->str_replace('{selectedAutoTags}', $selectedAutoTags, $html);
        $html = $this->f->str_replace('{selectedAutoTrashRedirect}', $selectedAutoTrashRedirect, $html);
        $html = $this->f->str_replace('{auto_deletion}', esc_attr($this->shared->optStr($options, 'auto_deletion')), $html);
        $html = $this->f->str_replace('{auto_302_expiration_days}', esc_attr($this->shared->optStr($options, 'auto_302_expiration_days')), $html);
        $html = $this->f->str_replace('{spaces}', $spaces, $html);
        $html = $this->f->doNormalReplacements($html);
        $content .= $html;

        return $content;
    }

	    /**
	     * @param array<string, mixed> $options
	     * @return string
	     */
    /**
     * Get the HTML content for the Content & URL Filtering section
     * @param array<string, mixed> $options
     * @return string
     */
    function getAdminOptionsPageAdvancedContent($options) {
        $options = $this->shared->normalizeOptionsForView($options);
        $allPostTypesTemp = $this->viewReadService->getAllPostTypes();
        $allPostTypes = esc_html(implode(', ', $allPostTypesTemp));

        // Read the html content
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsAdvancedContent.html");

        $html = $this->f->str_replace('{recognized_post_types}',
            str_replace('\\n', "\n", wp_kses_post($this->shared->optStr($options, 'recognized_post_types'))), $html);
        $html = $this->f->str_replace('{all_post_types}', $allPostTypes, $html);

        $html = $this->f->str_replace('{recognized_categories}',
            str_replace('\\n', "\n", wp_kses_post($this->shared->optStr($options, 'recognized_categories'))), $html);
        $html = $this->f->str_replace('{folders_files_ignore}',
            str_replace('\\n', "\n", wp_kses_post($this->shared->optStr($options, 'folders_files_ignore'))), $html);
        $html = $this->f->str_replace('{suggest_regex_exclusions}',
            str_replace('\\n', "\n", esc_textarea($this->shared->optStr($options, 'suggest_regex_exclusions'))), $html);

        $html = $this->f->str_replace('{add-exclude-page-data-url}',
            "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=false&includeSpecial=false&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
            __('(Type a page name)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
            __('(A page has been selected.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
            __('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
            __('(An external URL will be used.)', '404-solution'), $html);
        $html = $this->f->str_replace('{loaded-excluded-pages}',
            urlencode($this->shared->optStr($options, 'excludePages[]')), $html);

        // Constants and translations
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /**
     * Get the HTML content for the Logging & Privacy section
     * @param array<string, mixed> $options
     * @return string
     */
	    function getAdminOptionsPageAdvancedLogging($options) {
	        $options = $this->shared->normalizeOptionsForView($options);
	        $selectedLogRawIPs = $this->shared->getCheckedAttr($options, 'log_raw_ips');
	        $selectedDebugLogging = $this->shared->getCheckedAttr($options, 'debug_mode');

        $debugExplanation = __('<a>View</a> the debug file.', '404-solution');
        $debugLogLink = '?page=' . ABJ404_PP . '&subpage=abj404_debugfile';
        $debugExplanation = $this->f->str_replace('<a>', '<a href="' . $debugLogLink . '" target="_blank" >', $debugExplanation);

        $kbFileSize = $this->logger->getDebugFileSize() / 1024;
        $kbFileSizePretty = number_format($kbFileSize, 2, ".", ",");
        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        $mbFileSizePretty = number_format($mbFileSize, 2, ".", ",");
        /* Translators: 1: The file size in KB. 2: The file size in MB. */
        $debugFileSize = sprintf(__('Debug file size: %1$s KB (%2$s MB).', '404-solution'),
                $kbFileSizePretty, $mbFileSizePretty);

        // Read the html content
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsAdvancedLogging.html");

        $html = $this->f->str_replace('checked="log_raw_ips"', $selectedLogRawIPs, $html);
        $html = $this->f->str_replace('checked="debug_mode"', $selectedDebugLogging, $html);
        $html = $this->f->str_replace('{<a>View</a> the debug file.}', $debugExplanation, $html);
        $html = $this->f->str_replace('{Debug file size: %s KB.}', $debugFileSize, $html);

        $html = $this->f->str_replace('{ignore_dontprocess}',
            str_replace('\\n', "\n", wp_kses_post($this->shared->optStr($options, 'ignore_dontprocess'))), $html);
        $html = $this->f->str_replace('{ignore_doprocess}',
            str_replace('\\n', "\n", wp_kses_post($this->shared->optStr($options, 'ignore_doprocess'))), $html);

        // Constants and translations
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /**
     * Get the HTML content for the Advanced Configuration section
     * @param array<string, mixed> $options
     * @return string
     */
	    function getAdminOptionsPageAdvancedSystem($options) {
	        $options = $this->shared->normalizeOptionsForView($options);
	        $selectedRedirectAllRequests = $this->shared->getCheckedAttr($options, 'redirect_all_requests');

        $hideRedirectAllRequests = "false";
        if (array_key_exists('disallow-redirect-all-requests', $options)
                && $options['disallow-redirect-all-requests'] == '1') {
            $hideRedirectAllRequests = "true";
        }

        // Read the html content
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsAdvancedSystem.html");

        $html = $this->f->str_replace('{DATABASE_VERSION}', esc_html($this->shared->optStr($options, 'DB_VERSION')), $html);
        $html = $this->f->str_replace('checked="redirect_all_requests"', $selectedRedirectAllRequests, $html);
        $html = $this->f->str_replace('{disallow-redirect-all-requests}', $hideRedirectAllRequests, $html);

        $html = $this->f->str_replace('{OPTION_MIN_AUTO_SCORE}', esc_attr($this->shared->optStr($options, 'auto_score')), $html);
        $html = $this->f->str_replace('{OPTION_AUTO_SCORE_TITLE}', esc_attr($this->shared->optStr($options, 'auto_score_title')), $html);
        $html = $this->f->str_replace('{OPTION_AUTO_SCORE_CATEGORY_TAG}', esc_attr($this->shared->optStr($options, 'auto_score_category_tag')), $html);
        $html = $this->f->str_replace('{OPTION_AUTO_SCORE_CONTENT}', esc_attr($this->shared->optStr($options, 'auto_score_content')), $html);
        $html = $this->f->str_replace('{OPTION_TEMPLATE_REDIRECT_PRIORITY}', esc_attr($this->shared->optStr($options, 'template_redirect_priority')), $html);
        $html = $this->f->str_replace('{days_wait_before_major_update}', esc_attr($this->shared->optStr($options, 'days_wait_before_major_update')), $html);

        // Handle plugin_admin_users - convert array to string first before sanitization
        $pluginAdminUsersRaw2 = $options['plugin_admin_users'];
        if (is_array($pluginAdminUsersRaw2)) {
            $pluginAdminUsers = implode("\n", $pluginAdminUsersRaw2);
        } else {
            $pluginAdminUsers = is_string($pluginAdminUsersRaw2) ? $pluginAdminUsersRaw2 : '';
        }
        $pluginAdminUsers = str_replace('\\n', "\n", wp_kses_post($pluginAdminUsers));
        $html = $this->f->str_replace('{plugin_admin_users}', wp_kses_post($pluginAdminUsers), $html);

        // Constants and translations
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
	    function getAdminOptionsPageGeneralSettings($options) {
	        $options = $this->shared->normalizeOptionsForView($options);
	        
	        $selectedDefaultRedirect301 = ($options['default_redirect'] == '301') ? ' selected' : '';
        $selectedDefaultRedirect302 = ($options['default_redirect'] == '302') ? ' selected' : '';
        $selectedDefaultRedirect307 = ($options['default_redirect'] == '307') ? ' selected' : '';
        $selectedDefaultRedirect308 = ($options['default_redirect'] == '308') ? ' selected' : '';

        $selectedCapture404 = $this->shared->getCheckedAttr($options, 'capture_404');
        $selectedSendErrorLogs = $this->shared->getCheckedAttr($options, 'send_error_logs');

        $selectedUnderSettings = "";
        $selecteSsettingsLevel = "";
        if ($options['menuLocation'] == 'settingsLevel') {
            $selecteSsettingsLevel = " selected";
        } else {
            $selectedUnderSettings = " selected";
        }

        // Theme selection
        $adminTheme = isset($options['admin_theme']) ? $options['admin_theme'] : 'default';
        $selectedThemeDefault = ($adminTheme == 'default') ? " selected" : "";
        $selectedThemeCalm = ($adminTheme == 'calm') ? " selected" : "";
        $selectedThemeMono = ($adminTheme == 'mono') ? " selected" : "";
        $selectedThemeNeon = ($adminTheme == 'neon') ? " selected" : "";
        $selectedThemeObsidian = ($adminTheme == 'obsidian') ? " selected" : "";

        // Theme name translations
        $themeDefault = __('Default', '404-solution');

        // Language override selection
        $pluginLanguage = isset($options['plugin_language_override']) ? $options['plugin_language_override'] : '';
        $selectedLanguageDefault = ($pluginLanguage == '') ? " selected" : "";
        $selectedLanguageEnUS = ($pluginLanguage == 'en_US') ? " selected" : "";
        $selectedLanguageDeDE = ($pluginLanguage == 'de_DE') ? " selected" : "";
        $selectedLanguageEsES = ($pluginLanguage == 'es_ES') ? " selected" : "";
        $selectedLanguageFrFR = ($pluginLanguage == 'fr_FR') ? " selected" : "";
        $selectedLanguageItIT = ($pluginLanguage == 'it_IT') ? " selected" : "";
        $selectedLanguagePtBR = ($pluginLanguage == 'pt_BR') ? " selected" : "";
        $selectedLanguageNlNL = ($pluginLanguage == 'nl_NL') ? " selected" : "";
        $selectedLanguageRuRU = ($pluginLanguage == 'ru_RU') ? " selected" : "";
        $selectedLanguageJa = ($pluginLanguage == 'ja') ? " selected" : "";
        $selectedLanguageZhCN = ($pluginLanguage == 'zh_CN') ? " selected" : "";
        $selectedLanguageIdID = ($pluginLanguage == 'id_ID') ? " selected" : "";
        $selectedLanguageSvSE = ($pluginLanguage == 'sv_SE') ? " selected" : "";

        // Auto dark mode detection checkbox
        $disableAutoDarkMode = isset($options['disable_auto_dark_mode']) && $options['disable_auto_dark_mode'] == '1';
        $disableAutoDarkModeChecked = $disableAutoDarkMode ? " checked" : "";

        $logSizeBytes = $this->viewReadService->getLogDiskUsage();
        $logSizeMB = round($logSizeBytes / (1024 * 1000), 2);
        $totalLogLines = $this->viewReadService->getLogsCount(0);

        $timeToDisplay = $this->statsRepository->getEarliestLogTimestamp();
        $earliestLogDate = 'N/A';
        if ($timeToDisplay >= 0) {
            $earliestLogDate = date('Y/m/d', $timeToDisplay) . ' ' . date('h:i:s', $timeToDisplay) . '&nbsp;' . 
            date('A', $timeToDisplay);
        }


        $selectedRemoveMatches = $this->shared->getCheckedAttr($options, 'remove_matches');

        // Notification frequency selection
        $notifyFrequency = isset($options['admin_notification_frequency']) ? (string)(is_scalar($options['admin_notification_frequency']) ? $options['admin_notification_frequency'] : 'instant') : 'instant';
        $selectedNotifyInstant = ($notifyFrequency === 'instant') ? ' selected' : '';
        $selectedNotifyDaily   = ($notifyFrequency === 'daily')   ? ' selected' : '';
        $selectedNotifyWeekly  = ($notifyFrequency === 'weekly')  ? ' selected' : '';

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminOptionsGeneral.html");
        $html = $this->f->str_replace('{selectedSendErrorLogs}', $selectedSendErrorLogs, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect301}', $selectedDefaultRedirect301, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect302}', $selectedDefaultRedirect302, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect307}', $selectedDefaultRedirect307, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect308}', $selectedDefaultRedirect308, $html);
        $html = $this->f->str_replace('{selectedCapture404}', $selectedCapture404, $html);
        $html = $this->f->str_replace('{admin_notification}', esc_attr($this->shared->optStr($options, 'admin_notification')), $html);
        $html = $this->f->str_replace('{capture_deletion}', esc_attr($this->shared->optStr($options, 'capture_deletion')), $html);
        $html = $this->f->str_replace('{manual_deletion}', esc_attr($this->shared->optStr($options, 'manual_deletion')), $html);
        $html = $this->f->str_replace('{maximum_log_disk_usage}', esc_attr($this->shared->optStr($options, 'maximum_log_disk_usage')), $html);
        $html = $this->f->str_replace('{logCurrentSizeDiskUsage}', (string)$logSizeMB, $html);
        $html = $this->f->str_replace('{logCurrentRowCount}', (string)$totalLogLines, $html);
        $html = $this->f->str_replace('{earliestLogDate}', $earliestLogDate, $html);
        $html = $this->f->str_replace('{selectedRemoveMatches}', $selectedRemoveMatches, $html);
        $html = $this->f->str_replace('{selectedUnderSettings}', $selectedUnderSettings, $html);
        $html = $this->f->str_replace('{selecteSsettingsLevel}', $selecteSsettingsLevel, $html);
        $html = $this->f->str_replace('{selectedThemeDefault}', $selectedThemeDefault, $html);
        $html = $this->f->str_replace('{selectedThemeCalm}', $selectedThemeCalm, $html);
        $html = $this->f->str_replace('{selectedThemeMono}', $selectedThemeMono, $html);
        $html = $this->f->str_replace('{selectedThemeNeon}', $selectedThemeNeon, $html);
        $html = $this->f->str_replace('{selectedThemeObsidian}', $selectedThemeObsidian, $html);
        $html = $this->f->str_replace('{theme_default}', $themeDefault, $html);
        $html = $this->f->str_replace('{selectedLanguageDefault}', $selectedLanguageDefault, $html);
        $html = $this->f->str_replace('{selectedLanguageEnUS}', $selectedLanguageEnUS, $html);
        $html = $this->f->str_replace('{selectedLanguageDeDE}', $selectedLanguageDeDE, $html);
        $html = $this->f->str_replace('{selectedLanguageEsES}', $selectedLanguageEsES, $html);
        $html = $this->f->str_replace('{selectedLanguageFrFR}', $selectedLanguageFrFR, $html);
        $html = $this->f->str_replace('{selectedLanguageItIT}', $selectedLanguageItIT, $html);
        $html = $this->f->str_replace('{selectedLanguagePtBR}', $selectedLanguagePtBR, $html);
        $html = $this->f->str_replace('{selectedLanguageNlNL}', $selectedLanguageNlNL, $html);
        $html = $this->f->str_replace('{selectedLanguageRuRU}', $selectedLanguageRuRU, $html);
        $html = $this->f->str_replace('{selectedLanguageJa}', $selectedLanguageJa, $html);
        $html = $this->f->str_replace('{selectedLanguageZhCN}', $selectedLanguageZhCN, $html);
        $html = $this->f->str_replace('{selectedLanguageIdID}', $selectedLanguageIdID, $html);
        $html = $this->f->str_replace('{selectedLanguageSvSE}', $selectedLanguageSvSE, $html);
        $html = $this->f->str_replace('{disableAutoDarkModeChecked}', $disableAutoDarkModeChecked, $html);
        $html = $this->f->str_replace('{admin_notification_email}', esc_attr($this->shared->optStr($options, 'admin_notification_email')), $html);
        $adminEmail = get_option('admin_email');
        $html = $this->f->str_replace('{default_wordpress_admin_email}', is_string($adminEmail) ? $adminEmail : '', $html);
        $html = $this->f->str_replace('{PHP_VERSION}', PHP_VERSION, $html);
        $html = $this->f->str_replace('{selectedNotifyInstant}', $selectedNotifyInstant, $html);
        $html = $this->f->str_replace('{selectedNotifyDaily}', $selectedNotifyDaily, $html);
        $html = $this->f->str_replace('{selectedNotifyWeekly}', $selectedNotifyWeekly, $html);
        $selectedAutoTrashJunk = $this->shared->getCheckedAttr($options, 'auto_trash_junk_urls');
        $html = $this->f->str_replace('{selectedAutoTrashJunk}', $selectedAutoTrashJunk, $html);
        // constants and translations.
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }


}
