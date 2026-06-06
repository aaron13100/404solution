<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents advanced and general settings section templates.
 */
class ABJ_404_Solution_View_SettingsSections extends ABJ_404_Solution_ViewComponent {

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageAutoRedirects(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);

        $spaces = esc_html("&nbsp;&nbsp;&nbsp;");
        $content = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/adminOptionsDefault404Destination.html");
        $content = $this->f->str_replace(
            array('{default_404_destination_label}', '{behavior_tiles_html}'),
            array(esc_html__('Default 404 destination', '404-solution'), $this->optionsPresenter->getBehaviorTilesHTML($options)),
            $content
        );

        $selectedAutoRedirects = $this->optionsPresenter->getCheckedAttr($options, 'auto_redirects');
        $selectedAutoSlugs = $this->optionsPresenter->getCheckedAttr($options, 'auto_slugs');
        $selectedAutoCats = $this->optionsPresenter->getCheckedAttr($options, 'auto_cats');
        $selectedAutoTags = $this->optionsPresenter->getCheckedAttr($options, 'auto_tags');
        $selectedAutoTrashRedirect = $this->optionsPresenter->getCheckedAttr($options, 'auto_trash_redirect');

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/adminOptionsAutoRedirects.html");
        $html = $this->f->str_replace('{selectedAutoRedirects}', $selectedAutoRedirects, $html);
        $html = $this->f->str_replace('{selectedAutoSlugs}', $selectedAutoSlugs, $html);
        $html = $this->f->str_replace('{selectedAutoCats}', $selectedAutoCats, $html);
        $html = $this->f->str_replace('{selectedAutoTags}', $selectedAutoTags, $html);
        $html = $this->f->str_replace('{selectedAutoTrashRedirect}', $selectedAutoTrashRedirect, $html);
        $html = $this->f->str_replace('{auto_deletion}', esc_attr($this->optionsPresenter->optStr($options, 'auto_deletion')), $html);
        $html = $this->f->str_replace('{auto_302_expiration_days}', esc_attr($this->optionsPresenter->optStr($options, 'auto_302_expiration_days')), $html);
        $html = $this->f->str_replace('{spaces}', $spaces, $html);
        $html = $this->f->doNormalReplacements($html);
        $content .= $html;

        return $content;
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageAdvancedContent(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);
        $allPostTypesTemp = $this->viewReadService->getAllPostTypes();
        $allPostTypes = esc_html(implode(', ', $allPostTypesTemp));

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/settingsAdvancedContent.html");

        $html = $this->f->str_replace('{recognized_post_types}',
            str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'recognized_post_types'))), $html);
        $html = $this->f->str_replace('{all_post_types}', $allPostTypes, $html);
        $html = $this->f->str_replace('{recognized_categories}',
            str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'recognized_categories'))), $html);
        $html = $this->f->str_replace('{folders_files_ignore}',
            str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'folders_files_ignore'))), $html);
        $html = $this->f->str_replace('{suggest_regex_exclusions}',
            str_replace('\\n', "\n", esc_textarea($this->optionsPresenter->optStr($options, 'suggest_regex_exclusions'))), $html);

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
            urlencode($this->optionsPresenter->optStr($options, 'excludePages[]')), $html);
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageAdvancedLogging(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);
        $selectedLogRawIPs = $this->optionsPresenter->getCheckedAttr($options, 'log_raw_ips');
        $selectedDebugLogging = $this->optionsPresenter->getCheckedAttr($options, 'debug_mode');

        $debugExplanation = __('<a>View</a> the debug file.', '404-solution');
        $debugLogLink = '?page=' . ABJ404_PP . '&subpage=abj404_debugfile';
        $debugExplanation = $this->f->str_replace('<a>', '<a href="' . $debugLogLink . '" target="_blank" >', $debugExplanation);

        $kbFileSize = $this->logger->getDebugFileSize() / 1024;
        $kbFileSizePretty = number_format($kbFileSize, 2, ".", ",");
        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        $mbFileSizePretty = number_format($mbFileSize, 2, ".", ",");
        $debugFileSize = sprintf(__('Debug file size: %1$s KB (%2$s MB).', '404-solution'),
                $kbFileSizePretty, $mbFileSizePretty);

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/settingsAdvancedLogging.html");
        $html = $this->f->str_replace('checked="log_raw_ips"', $selectedLogRawIPs, $html);
        $html = $this->f->str_replace('checked="debug_mode"', $selectedDebugLogging, $html);
        $html = $this->f->str_replace('{<a>View</a> the debug file.}', $debugExplanation, $html);
        $html = $this->f->str_replace('{Debug file size: %s KB.}', $debugFileSize, $html);
        $html = $this->f->str_replace('{ignore_dontprocess}',
            str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'ignore_dontprocess'))), $html);
        $html = $this->f->str_replace('{ignore_doprocess}',
            str_replace('\\n', "\n", wp_kses_post($this->optionsPresenter->optStr($options, 'ignore_doprocess'))), $html);
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageAdvancedSystem(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);
        $selectedRedirectAllRequests = $this->optionsPresenter->getCheckedAttr($options, 'redirect_all_requests');

        $hideRedirectAllRequests = "false";
        if (array_key_exists('disallow-redirect-all-requests', $options)
                && $options['disallow-redirect-all-requests'] == '1') {
            $hideRedirectAllRequests = "true";
        }

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/settingsAdvancedSystem.html");
        $html = $this->f->str_replace('{DATABASE_VERSION}', esc_html($this->optionsPresenter->optStr($options, 'DB_VERSION')), $html);
        $html = $this->f->str_replace('checked="redirect_all_requests"', $selectedRedirectAllRequests, $html);
        $html = $this->f->str_replace('{disallow-redirect-all-requests}', $hideRedirectAllRequests, $html);
        $html = $this->f->str_replace('{OPTION_MIN_AUTO_SCORE}', esc_attr($this->optionsPresenter->optStr($options, 'auto_score')), $html);
        $html = $this->f->str_replace('{OPTION_AUTO_SCORE_TITLE}', esc_attr($this->optionsPresenter->optStr($options, 'auto_score_title')), $html);
        $html = $this->f->str_replace('{OPTION_AUTO_SCORE_CATEGORY_TAG}', esc_attr($this->optionsPresenter->optStr($options, 'auto_score_category_tag')), $html);
        $html = $this->f->str_replace('{OPTION_AUTO_SCORE_CONTENT}', esc_attr($this->optionsPresenter->optStr($options, 'auto_score_content')), $html);
        $html = $this->f->str_replace('{OPTION_TEMPLATE_REDIRECT_PRIORITY}', esc_attr($this->optionsPresenter->optStr($options, 'template_redirect_priority')), $html);
        $html = $this->f->str_replace('{days_wait_before_major_update}', esc_attr($this->optionsPresenter->optStr($options, 'days_wait_before_major_update')), $html);

        $pluginAdminUsersRaw2 = $options['plugin_admin_users'];
        if (is_array($pluginAdminUsersRaw2)) {
            $pluginAdminUsers = implode("\n", $pluginAdminUsersRaw2);
        } else {
            $pluginAdminUsers = is_string($pluginAdminUsersRaw2) ? $pluginAdminUsersRaw2 : '';
        }
        $pluginAdminUsers = str_replace('\\n', "\n", wp_kses_post($pluginAdminUsers));
        $html = $this->f->str_replace('{plugin_admin_users}', wp_kses_post($pluginAdminUsers), $html);
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageGeneralSettings(array $options): string {
        $options = $this->optionsPresenter->normalizeOptionsForView($options);

        $selectedDefaultRedirect301 = ($options['default_redirect'] == '301') ? ' selected' : '';
        $selectedDefaultRedirect302 = ($options['default_redirect'] == '302') ? ' selected' : '';
        $selectedDefaultRedirect307 = ($options['default_redirect'] == '307') ? ' selected' : '';
        $selectedDefaultRedirect308 = ($options['default_redirect'] == '308') ? ' selected' : '';

        $selectedCapture404 = $this->optionsPresenter->getCheckedAttr($options, 'capture_404');
        $selectedSendErrorLogs = $this->optionsPresenter->getCheckedAttr($options, 'send_error_logs');

        $selectedUnderSettings = "";
        $selecteSsettingsLevel = "";
        if ($options['menuLocation'] == 'settingsLevel') {
            $selecteSsettingsLevel = " selected";
        } else {
            $selectedUnderSettings = " selected";
        }

        $adminTheme = isset($options['admin_theme']) ? $options['admin_theme'] : 'default';
        $selectedThemeDefault = ($adminTheme == 'default') ? " selected" : "";
        $selectedThemeCalm = ($adminTheme == 'calm') ? " selected" : "";
        $selectedThemeMono = ($adminTheme == 'mono') ? " selected" : "";
        $selectedThemeNeon = ($adminTheme == 'neon') ? " selected" : "";
        $selectedThemeObsidian = ($adminTheme == 'obsidian') ? " selected" : "";
        $themeDefault = __('Default', '404-solution');

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

        $selectedRemoveMatches = $this->optionsPresenter->getCheckedAttr($options, 'remove_matches');
        $notifyFrequency = isset($options['admin_notification_frequency']) ? (string)(is_scalar($options['admin_notification_frequency']) ? $options['admin_notification_frequency'] : 'instant') : 'instant';
        $selectedNotifyInstant = ($notifyFrequency === 'instant') ? ' selected' : '';
        $selectedNotifyDaily   = ($notifyFrequency === 'daily')   ? ' selected' : '';
        $selectedNotifyWeekly  = ($notifyFrequency === 'weekly')  ? ' selected' : '';

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/adminOptionsGeneral.html");
        $html = $this->f->str_replace('{selectedSendErrorLogs}', $selectedSendErrorLogs, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect301}', $selectedDefaultRedirect301, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect302}', $selectedDefaultRedirect302, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect307}', $selectedDefaultRedirect307, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect308}', $selectedDefaultRedirect308, $html);
        $html = $this->f->str_replace('{selectedCapture404}', $selectedCapture404, $html);
        $html = $this->f->str_replace('{admin_notification}', esc_attr($this->optionsPresenter->optStr($options, 'admin_notification')), $html);
        $html = $this->f->str_replace('{capture_deletion}', esc_attr($this->optionsPresenter->optStr($options, 'capture_deletion')), $html);
        $html = $this->f->str_replace('{manual_deletion}', esc_attr($this->optionsPresenter->optStr($options, 'manual_deletion')), $html);
        $html = $this->f->str_replace('{maximum_log_disk_usage}', esc_attr($this->optionsPresenter->optStr($options, 'maximum_log_disk_usage')), $html);
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
        $html = $this->f->str_replace('{admin_notification_email}', esc_attr($this->optionsPresenter->optStr($options, 'admin_notification_email')), $html);
        $adminEmail = get_option('admin_email');
        $html = $this->f->str_replace('{default_wordpress_admin_email}', is_string($adminEmail) ? $adminEmail : '', $html);
        $html = $this->f->str_replace('{PHP_VERSION}', PHP_VERSION, $html);
        $html = $this->f->str_replace('{selectedNotifyInstant}', $selectedNotifyInstant, $html);
        $html = $this->f->str_replace('{selectedNotifyDaily}', $selectedNotifyDaily, $html);
        $html = $this->f->str_replace('{selectedNotifyWeekly}', $selectedNotifyWeekly, $html);
        $selectedAutoTrashJunk = $this->optionsPresenter->getCheckedAttr($options, 'auto_trash_junk_urls');
        $html = $this->f->str_replace('{selectedAutoTrashJunk}', $selectedAutoTrashJunk, $html);
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }
}
