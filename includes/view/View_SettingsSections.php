<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents advanced and general settings section templates.
 */
class ABJ_404_Solution_View_SettingsSections extends ABJ_404_Solution_ViewComponent {

    /** @param array<string,string> $vars */
    private function fillSettingsTemplate(string $templateName, array $vars): string {
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $templateName);
        foreach ($vars as $key => $value) {
            $html = $this->f->str_replace('{' . $key . '}', $value, $html);
        }
        return (string)$html;
    }

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

        $viewData = array_merge(
            $this->generalSettingsFieldState($options),
            $this->generalSettingsRuntimeDisplayData(),
            array(
                'admin_notification' => esc_attr($this->optionsPresenter->optStr($options, 'admin_notification')),
                'capture_deletion' => esc_attr($this->optionsPresenter->optStr($options, 'capture_deletion')),
                'manual_deletion' => esc_attr($this->optionsPresenter->optStr($options, 'manual_deletion')),
                'maximum_log_disk_usage' => esc_attr($this->optionsPresenter->optStr($options, 'maximum_log_disk_usage')),
                'admin_notification_email' => esc_attr($this->optionsPresenter->optStr($options, 'admin_notification_email')),
                'PHP_VERSION' => PHP_VERSION,
            )
        );

        $html = $this->fillSettingsTemplate('adminOptionsGeneral.html', $viewData);
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /**
     * Build selected and checked attributes for the general settings template.
     *
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function generalSettingsFieldState(array $options): array {
        $viewData = array(
            'selectedDefaultRedirect301' => ($options['default_redirect'] == '301') ? ' selected' : '',
            'selectedDefaultRedirect302' => ($options['default_redirect'] == '302') ? ' selected' : '',
            'selectedDefaultRedirect307' => ($options['default_redirect'] == '307') ? ' selected' : '',
            'selectedDefaultRedirect308' => ($options['default_redirect'] == '308') ? ' selected' : '',
            'selectedCapture404' => $this->optionsPresenter->getCheckedAttr($options, 'capture_404'),
            'selectedSendErrorLogs' => $this->optionsPresenter->getCheckedAttr($options, 'send_error_logs'),
            'selectedRemoveMatches' => $this->optionsPresenter->getCheckedAttr($options, 'remove_matches'),
            'selectedAutoTrashJunk' => $this->optionsPresenter->getCheckedAttr($options, 'auto_trash_junk_urls'),
            'selectedUnderSettings' => ($options['menuLocation'] == 'settingsLevel') ? '' : ' selected',
            'selecteSsettingsLevel' => ($options['menuLocation'] == 'settingsLevel') ? ' selected' : '',
            'theme_default' => __('Default', '404-solution'),
            'disableAutoDarkModeChecked' => isset($options['disable_auto_dark_mode']) && $options['disable_auto_dark_mode'] == '1'
                ? ' checked'
                : '',
        );

        $adminTheme = isset($options['admin_theme']) ? $options['admin_theme'] : 'default';
        foreach (array(
            'selectedThemeDefault' => 'default',
            'selectedThemeCalm' => 'calm',
            'selectedThemeMono' => 'mono',
            'selectedThemeNeon' => 'neon',
            'selectedThemeObsidian' => 'obsidian',
        ) as $placeholder => $theme) {
            $viewData[$placeholder] = ($adminTheme == $theme) ? ' selected' : '';
        }

        $pluginLanguage = isset($options['plugin_language_override']) ? $options['plugin_language_override'] : '';
        foreach (array(
            'selectedLanguageDefault' => '',
            'selectedLanguageEnUS' => 'en_US',
            'selectedLanguageDeDE' => 'de_DE',
            'selectedLanguageEsES' => 'es_ES',
            'selectedLanguageFrFR' => 'fr_FR',
            'selectedLanguageItIT' => 'it_IT',
            'selectedLanguagePtBR' => 'pt_BR',
            'selectedLanguageNlNL' => 'nl_NL',
            'selectedLanguageRuRU' => 'ru_RU',
            'selectedLanguageJa' => 'ja',
            'selectedLanguageZhCN' => 'zh_CN',
            'selectedLanguageIdID' => 'id_ID',
            'selectedLanguageSvSE' => 'sv_SE',
        ) as $placeholder => $language) {
            $viewData[$placeholder] = ($pluginLanguage == $language) ? ' selected' : '';
        }

        $notifyFrequencyRaw = $options['admin_notification_frequency'] ?? 'instant';
        $notifyFrequency = is_scalar($notifyFrequencyRaw) ? (string)$notifyFrequencyRaw : 'instant';
        foreach (array(
            'selectedNotifyInstant' => 'instant',
            'selectedNotifyDaily' => 'daily',
            'selectedNotifyWeekly' => 'weekly',
            'selectedNotifyNever' => 'never',
        ) as $placeholder => $frequency) {
            $viewData[$placeholder] = ($notifyFrequency === $frequency) ? ' selected' : '';
        }

        return $viewData;
    }

    /** @return array<string, string> */
    private function generalSettingsRuntimeDisplayData(): array {
        $timeToDisplay = $this->statsRepository->getEarliestLogTimestamp();
        $earliestLogDate = $timeToDisplay >= 0
            ? date('Y/m/d', $timeToDisplay) . ' ' . date('h:i:s', $timeToDisplay) . '&nbsp;' . date('A', $timeToDisplay)
            : 'N/A';
        $adminEmail = get_option('admin_email');

        return array(
            'logCurrentSizeDiskUsage' => (string)round($this->viewReadService->getLogDiskUsage() / (1024 * 1000), 2),
            'logCurrentRowCount' => (string)$this->viewReadService->getLogsCount(0),
            'earliestLogDate' => $earliestLogDate,
            'default_wordpress_admin_email' => is_string($adminEmail) ? $adminEmail : '',
        );
    }

    /** @param array<string, mixed> $options */
    function getAdminOptionsPageDiagnosticData(array $options): string {
        if (!class_exists('ABJ_404_Solution_FeedbackSiteTokenStore')) {
            require_once dirname(__DIR__) . '/feedback/FeedbackSiteTokenStore.php';
        }
        if (!class_exists('ABJ_404_Solution_Ajax_PrivacyExport')) {
            require_once dirname(__DIR__) . '/ajax/Ajax_PrivacyExport.php';
        }
        if (!class_exists('ABJ_404_Solution_Ajax_PrivacyDelete')) {
            require_once dirname(__DIR__) . '/ajax/Ajax_PrivacyDelete.php';
        }

        $rawToken = get_option(ABJ_404_Solution_FeedbackSiteTokenStore::TOKEN_OPTION, '');
        $hasToken = is_string($rawToken) && $rawToken !== '';
        $exportNonce = wp_create_nonce(ABJ_404_Solution_Ajax_PrivacyExport::NONCE_ACTION);
        $deleteNonce = wp_create_nonce(ABJ_404_Solution_Ajax_PrivacyDelete::NONCE_ACTION);

        if (!$hasToken) {
            $body = $this->fillSettingsTemplate('viewSettingsDiagnosticDataNoToken.html', array(
                'emptyDescription' => esc_html__('Diagnostic reporting has never been enabled for this site, so there is nothing stored on the developer\'s server for you to download or delete.', '404-solution'),
            ));
        } else {
            $sendErrorLogs = $options['send_error_logs'] ?? '0';
            $enabled = $sendErrorLogs === '1' || $sendErrorLogs === 1 || $sendErrorLogs === true;
            $state = $enabled ? esc_html__('enabled', '404-solution') : esc_html__('disabled', '404-solution');
            $description = sprintf(
                esc_html__('This site has diagnostic reporting %s. You can download everything stored on the developer\'s server for this site, or permanently delete it.', '404-solution'),
                $state
            );
            $body = $this->fillSettingsTemplate('viewSettingsDiagnosticDataActions.html', array(
                'description'      => $description,
                'exportNonce'      => esc_attr($exportNonce),
                'deleteNonce'      => esc_attr($deleteNonce),
                'downloadLabel'    => esc_html__('Download my data', '404-solution'),
                'deleteLabel'      => esc_html__('Delete my data', '404-solution'),
                'emptyAfterDelete' => esc_html__('Nothing currently stored.', '404-solution'),
                'modalTitle'       => esc_html__('Delete your diagnostic data?', '404-solution'),
                'modalBody'        => esc_html__('This permanently deletes every diagnostic report this site has ever sent. This cannot be undone. Your redirects and settings are not affected -- only diagnostic/telemetry history is removed.', '404-solution'),
                'cancelLabel'      => esc_html__('Cancel', '404-solution'),
                'confirmLabel'     => esc_html__('Yes, delete permanently', '404-solution'),
            ));
        }

        return $this->fillSettingsTemplate('viewSettingsDiagnosticDataSection.html', array(
            'downloadDate' => esc_attr(gmdate('Y-m-d', abj_clock()->now())),
            'exportNonce'  => esc_attr($exportNonce),
            'deleteNonce'  => esc_attr($deleteNonce),
            'hasToken'     => $hasToken ? '1' : '0',
            'body'         => $body,
        ));
    }
}
