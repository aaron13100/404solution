<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders reusable admin-page chrome for plugin screens.
 *
 * Owns header tabs, footer freshness, native notices, postboxes, option
 * sections, card icons, save controls, restore modal, toast container, and
 * settings mode toggles.
 */
class ABJ_404_Solution_View_AdminChrome extends ABJ_404_Solution_ViewComponent {

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /** @param array<string,string> $vars */
    private function fillTpl(string $name, array $vars): string {
        $tpl = $this->tpl($name);
        $search = array();
        $replace = array();
        foreach ($vars as $k => $v) {
            $search[] = '{' . $k . '}';
            $replace[] = $v;
        }
        if (is_object($this->f)) {
            return (string)$this->f->str_replace($search, $replace, $tpl);
        }
        return (string)str_replace($search, $replace, $tpl);
    }

    /** @return void */
    function echoAdminFooter(): void {
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/adminFooter.html");
        $html = $this->f->str_replace('{JAPANESE_FLASHCARDS_URL}', ABJ404_FC_URL, $html);

        $html = $this->f->doNormalReplacements($html);
        echo $html;
    }

    function outputAdminHeaderTabs(string $sub = 'list', string $message = ''): void {
        ABJ_404_Solution_WPNotices::echoAdminNotices();

        echo $this->tpl('adminHeaderWrapOpen.html');

        if (isset($_GET['setup_complete']) && $_GET['setup_complete'] === '1') {
            $options = abj_service('options_repository')->getOptions();
            $auto = !empty($options['auto_redirects']) && $options['auto_redirects'] !== '0';
            $notify = !empty($options['admin_notification']) && $options['admin_notification'] !== '0';

            if ($auto && $notify) {
                $body = esc_html__('404 Solution is now monitoring your site. When visitors hit broken links, they will be automatically redirected. We will email you if something needs attention.', '404-solution');
            } elseif ($auto) {
                $body = esc_html__('404 Solution is now monitoring your site. When visitors hit broken links, they will be automatically redirected.', '404-solution');
            } elseif ($notify) {
                $body = esc_html__('404 Solution is now monitoring your site. We will email you if captured 404 URLs need attention.', '404-solution');
            } else {
                $body = esc_html__('404 Solution is now monitoring your site. You can create manual redirects anytime from the Page Redirects tab.', '404-solution');
            }
            echo $this->fillTpl('setupCompleteWelcomeBanner.html', array(
                'heading' => esc_html__("You're all set!", '404-solution'),
                'body'    => $body,
            )) . "\n";
        }

        if ($message != "") {
            $allowed_tags = array(
                'br' => array(),
                'em' => array(),
                'strong' => array(),
            );

            if (($this->f->strlen($message) >= 6) && ($this->f->substr($this->f->strtolower($message), 0, 6) == 'error:')) {
                $cssClasses = 'notice notice-error';
            } else {
                $cssClasses = 'notice notice-success';
            }

            echo $this->fillTpl('adminHeaderMessageNotice.html', array(
                'cssClasses' => $cssClasses,
                'message'    => (string)wp_kses($message, $allowed_tags),
            )) . "\n";
        }

        if (class_exists('ABJ_404_Solution_RegexAutoPromote')) {
            $regexNotice = ABJ_404_Solution_RegexAutoPromote::readNotice();
            if (is_array($regexNotice) && $regexNotice['redirect_id'] > 0) {
                $this->renderRegexAutoPromoteNotice($regexNotice);
            }
        }

        $isSimpleMode = abj_service('settings_mode_preference')->getMode() === 'simple';
        $tabs = array(
            array('abj404_redirects', __('Page Redirects', '404-solution')),
            array('abj404_captured',  __('Captured 404s', '404-solution')),
        );
        if (!$isSimpleMode) {
            $tabs[] = array('abj404_logs',  __('Logs', '404-solution'));
            $tabs[] = array('abj404_stats', __('Stats', '404-solution'));
        }
        $tabs[] = array('abj404_tools',   __('Tools', '404-solution'));
        $tabs[] = array('abj404_options', __('Options', '404-solution'));

        $itemTpl = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/adminHeaderTab.html');
        $tabsHtml = '';
        foreach ($tabs as $pair) {
            list($subKey, $label) = $pair;
            $isActive = ($sub == $subKey);
            $row = $itemTpl;
            $row = str_replace('{url}',           esc_url('?page=' . ABJ404_PP . '&subpage=' . $subKey), $row);
            $row = str_replace('{activeClass}',   $isActive ? ' nav-tab-active active' : '',             $row);
            $row = str_replace('{ariaSelected}',  $isActive ? ' aria-selected="true"' : '',              $row);
            $row = str_replace('{label}',         esc_html($label),                                      $row);
            $tabsHtml .= $row;
        }

        $outer = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/adminHeaderTabs.html');
        echo str_replace('{tabs}', $tabsHtml, $outer);
    }

    /**
     * @param array{redirect_id: int, original_url: string, new_url: string, url_rewritten: bool, created_at: int} $notice
     * @return void
     */
    public function renderRegexAutoPromoteNotice(array $notice): void {
        $redirectId = (int)$notice['redirect_id'];
        $originalUrl = (string)$notice['original_url'];
        $newUrl = (string)$notice['new_url'];
        $urlRewritten = !empty($notice['url_rewritten']);

        $editUrl = admin_url('admin.php?page=' . ABJ404_PP . '&subpage=abj404_edit&id=' . $redirectId);
        $undoBase = admin_url('admin.php?page=' . ABJ404_PP . '&subpage=abj404_redirects&action=undoRegexAutoPromote');
        $undoUrl = wp_nonce_url($undoBase, 'abj404undoRegexAutoPromote');

        if ($urlRewritten) {
            $message = sprintf(
                __('Detected as a regex pattern. Stored "%1$s" as "%2$s".', '404-solution'),
                $originalUrl,
                $newUrl
            );
        } else {
            $message = sprintf(
                __('Detected as a regex pattern. Stored "%s" with Regex status.', '404-solution'),
                $originalUrl
            );
        }

        echo $this->fillTpl('regexAutoPromoteNotice.html', array(
            'label'      => esc_html__('404 Solution:', '404-solution'),
            'message'    => esc_html($message),
            'editUrl'    => esc_url($editUrl),
            'editLabel'  => esc_html__('Edit', '404-solution'),
            'undoUrl'    => esc_url($undoUrl),
            'confirm'    => esc_js(__('Undo regex auto-promotion and restore Manual status?', '404-solution')),
            'undoLabel'  => esc_html__('Undo', '404-solution'),
        )) . "\n";
    }

    /**
     * @param string|int $id
     * @param mixed $content
     */
    function echoPostBox($id, string $title, $content): void {
        echo $this->fillTpl('postBox.html', array(
            'id'      => esc_attr((string)$id),
            'title'   => esc_html($title),
            'content' => (string)$content,
        ));
    }

    function echoOptionsSection(ABJ_404_Solution_OptionsSectionView $section): void {
        $expandedClass = $section->initiallyVisible ? ' expanded' : '';
        $badgeHtml = '';
        if ($section->badge) {
            $badgeHtml = $this->fillTpl('optionsSectionBadge.html', array(
                'badge' => esc_html($section->badge),
            ));
        }

        echo $this->fillTpl('optionsSection.html', array(
            'expandedClass' => esc_attr($expandedClass),
            'sectionId'     => esc_attr($section->sectionId),
            'postboxId'     => esc_attr($section->postboxId),
            'ariaExpanded'  => $section->initiallyVisible ? 'true' : 'false',
            'icon'          => (string)$section->icon,
            'title'         => esc_html($section->title),
            'badge'         => $badgeHtml,
            'content'       => (string)$section->content,
        ));
    }

    function getCardIcon(string $iconName): string {
        $icons = array(
            'lightning' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>',
            'gear' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>',
            'filter' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>',
            'document' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>',
            'sliders' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>',
            'lightbulb' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>',
            'chart' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>',
            'warning' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
            'clock' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            'download' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>',
            'upload' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>',
            'trash' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>',
            'database' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>',
            'cog' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>'
        );
        return isset($icons[$iconName]) ? $icons[$iconName] : '';
    }

    function echoStickySaveBar(): void {
        $version = ABJ404_VERSION;
        $restoreNonce = wp_create_nonce('abj404_restore_defaults');
        $versionLabel = esc_html(sprintf(__('Plugin v%s', '404-solution'), $version));

        echo $this->fillTpl('stickySaveBar.html', array(
            'versionLabel'  => $versionLabel,
            'restoreNonce'  => esc_attr($restoreNonce),
            'restoreLabel'  => esc_html__('Restore Defaults', '404-solution'),
            'saveLabel'     => esc_attr__('Save Settings', '404-solution'),
        ));
        $this->echoRestoreDefaultsModal();
    }

    function echoRestoreDefaultsModal(): void {
        echo "\n" . $this->fillTpl('restoreDefaultsModal.html', array(
            'title'         => esc_html__('Restore Default Settings?', '404-solution'),
            'closeLabel'    => esc_attr__('Close', '404-solution'),
            'body'          => esc_html__('This will reset all plugin settings to their default values. This cannot be undone. Your redirect rules and 404 logs will not be affected.', '404-solution'),
            'cancelLabel'   => esc_html__('Cancel', '404-solution'),
            'confirmLabel'  => esc_html__('Restore Defaults', '404-solution'),
        )) . "\n        ";
    }

    function echoToastNotification(): void {
        echo $this->fillTpl('toastNotification.html', array(
            'icon'    => '&#10003;',
            'message' => esc_html__('Settings saved successfully!', '404-solution'),
        ));
    }

    function echoExpandCollapseButton(bool $showSuggestions = true): void {
        echo "\n" . $this->fillTpl('expandCollapseButton.html', array(
            'expandAllLabel' => esc_html__('Expand All', '404-solution'),
            'saveLabel'      => esc_attr__('Save Settings', '404-solution'),
        )) . "\n        ";
    }

    function echoSettingsModeToggle(string $currentMode): void {
        $simpleActive = ($currentMode === 'simple') ? 'active' : '';
        $advancedActive = ($currentMode === 'advanced') ? 'active' : '';
        $simplePressedState = ($currentMode === 'simple') ? 'true' : 'false';
        $advancedPressedState = ($currentMode === 'advanced') ? 'true' : 'false';

        if ($currentMode === 'simple') {
            $modeDescription = __('Simple Mode shows essential options only. Switch to Advanced Mode for full configuration.', '404-solution');
        } else {
            $modeDescription = __('Advanced Mode shows all options. Switch to Simple Mode for a streamlined view.', '404-solution');
        }

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/settingsModeToggle.html");
        $html = $this->f->str_replace('{nonce}', wp_create_nonce('abj404_mode_toggle'), $html);
        $html = $this->f->str_replace('{simpleActive}', $simpleActive, $html);
        $html = $this->f->str_replace('{advancedActive}', $advancedActive, $html);
        $html = $this->f->str_replace('{simplePressedState}', $simplePressedState, $html);
        $html = $this->f->str_replace('{advancedPressedState}', $advancedPressedState, $html);
        $html = $this->f->str_replace('{Simple Mode}', __('Simple Mode', '404-solution'), $html);
        $html = $this->f->str_replace('{Advanced Mode}', __('Advanced Mode', '404-solution'), $html);
        $html = $this->f->str_replace('{mode_description}', $modeDescription, $html);

        echo $html;
    }

    function echoInlineModeToggle(): void {
        $currentMode = abj_service('settings_mode_preference')->getMode();
        $simpleActive = ($currentMode === 'simple') ? 'active' : '';
        $advancedActive = ($currentMode === 'advanced') ? 'active' : '';
        $simplePressedState = ($currentMode === 'simple') ? 'true' : 'false';
        $advancedPressedState = ($currentMode === 'advanced') ? 'true' : 'false';

        echo $this->fillTpl('inlineModeToggle.html', array(
            'nonce'                => esc_attr(wp_create_nonce('abj404_mode_toggle')),
            'simpleActive'         => esc_attr($simpleActive),
            'advancedActive'       => esc_attr($advancedActive),
            'simplePressedState'   => esc_attr($simplePressedState),
            'advancedPressedState' => esc_attr($advancedPressedState),
            'simpleLabel'          => esc_html__('Simple', '404-solution'),
            'advancedLabel'        => esc_html__('Advanced', '404-solution'),
        ));
    }
}
