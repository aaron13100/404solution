<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ViewTrait_UI methods.
 */
trait ViewTrait_UI {

	/** Get the text to notify the user when some URLs have been captured and need attention. 
     * @param int $captured the number of captured URLs
     * @return string html
     */
    function getDashboardNotificationCaptured($captured) {
        /* Translators: %s is the number of captured 404 URLs. */
    	$capturedMessage = sprintf( _n( 'There is <a>%s captured 404 URL</a> that needs to be processed.',
                'There are <a>%s captured 404 URLs</a> to be processed.',
                $captured, '404-solution'), $captured);
        $capturedMessage = $this->f->str_replace("<a>",
                "<a href=\"options-general.php?page=" . ABJ404_PP . "&subpage=abj404_captured\" >",
                $capturedMessage);
        $capturedMessage = $this->f->str_replace("</a>", "</a>", $capturedMessage);

        return '<div class="notice notice-info"><p><strong>' . PLUGIN_NAME .
                ":</strong> " . $capturedMessage . "</p></div>";
    }

    /** Do an action like trash/delete/ignore/edit and display a page like stats/logs/redirects/options.
     * @return void
     */
    static function handleMainAdminPageActionAndDisplay() {
        global $abj404view;
        $instance = self::getInstance();

        try {
            $action = $instance->dao->getPostOrGetSanitize('action');

            if (!is_admin() || !$instance->logic->userIsPluginAdmin()) {
                $instance->logger->logUserCapabilities("handleMainAdminPageActionAndDisplay (" .
                        esc_html($action == '' ? '(none)' : $action) . ")");

                echo '<div class="wrap">';
                echo '<h1>' . esc_html(PLUGIN_NAME) . '</h1>';
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html__('Permission denied.', '404-solution') . '</strong> ';
                echo esc_html__('Your user account does not have permission to access this page.', '404-solution');
                echo '</p><p>';
                echo esc_html__('Please verify that your WordPress role has the', '404-solution') . ' ';
                echo '<code>manage_options</code> ' . esc_html__('capability.', '404-solution') . ' ';
                echo esc_html__('If you have a security plugin installed, it may be restricting access to this page.', '404-solution');
                echo '</p></div></div>';
                return;
            }

            $sub = "";

            // --------------------------------------------------------------------
            // Handle Post Actions
            $instance->logger->debugMessage("Processing request for action: " .
                    esc_html($action == '' ? '(none)' : $action));

            // this should really not pass things by reference so it can be more object oriented (encapsulation etc).
            $message = "";
            $message .= $instance->logic->handlePluginAction($action, $sub);
            $message .= $instance->logic->hanldeTrashAction();
            $message .= $instance->logic->handleDeleteAction();
            $message .= $instance->logic->handleIgnoreAction();
            $message .= $instance->logic->handleLaterAction();
            $message .= $instance->logic->handleActionEdit($sub, $action);
            $message .= $instance->logic->handleActionImportRedirects();
            $instance->logic->handleActionChangeItemsPerRow();
            $message .= $instance->logic->handleActionImportFile();

            // --------------------------------------------------------------------
            // Output the correct page.
            $abj404view->echoChosenAdminTab($action, $sub, $message);

        } catch (Exception $e) {
            $encodedEx = json_encode($e);
            $instance->logger->errorMessage("Caught exception: " . stripcslashes(wp_kses_post(is_string($encodedEx) ? $encodedEx : '')));
            echo '<div class="wrap">';
            echo '<div class="notice notice-error">';
            echo '<p><strong>404 Solution:</strong> An error occurred while rendering this page.</p>';
            echo '<details><summary>Show error details</summary>';
            echo '<pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">' . esc_html($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
            echo '</details>';
            echo '</div>';
            echo '</div>';
        }
    }
    
    /** Display the chosen admin page.
     * @param string $action
     * @param string $sub
     * @param string $message
     * @return void
     */
    function echoChosenAdminTab($action, $sub, $message) {
        global $abj404view;

        // If globals are not set, use sensible defaults
        if ($abj404view === null) {
            $abj404view = $this;
        }

        // Deal With Page Tabs
        if ($sub == "") {
            $sub = $this->f->strtolower($this->dao->getPostOrGetSanitize('subpage'));
        }
        if ($sub == "") {
            $sub = 'abj404_redirects';
            $this->logger->debugMessage('No tab selected. Displaying the "redirects" tab.');
        }

        // Check if we're returning from a successful redirect update
        $updated = $this->dao->getPostOrGetSanitize('updated');
        if ($updated == '1') {
            $message .= __('Redirect Information Updated Successfully!', '404-solution');
        }

        $this->logger->debugMessage("Displaying sub page: " . esc_html($sub == '' ? '(none)' : $sub));

        $abj404view->outputAdminHeaderTabs($sub, $message);

        $abj404action = $this->dao->getPostOrGetSanitize('abj404action');
        if (($action == 'editRedirect') || ($abj404action == 'editRedirect') || ($sub == 'abj404_edit')) {
            $abj404view->echoAdminEditRedirectPage();
        } else if ($sub == 'abj404_redirects') {
            $abj404view->echoAdminRedirectsPage();
        } else if ($sub == 'abj404_captured') {
            $abj404view->echoAdminCapturedURLsPage();
        } else if ($sub == "abj404_options") {
            $abj404view->echoAdminOptionsPage();
        } else if ($sub == 'abj404_logs') {
            $abj404view->echoAdminLogsPage();
        } else if ($sub == 'abj404_stats') {
            $abj404view->outputAdminStatsPage();
        } else if ($sub == 'abj404_tools') {
            $abj404view->echoAdminToolsPage();
        } else if ($sub == 'abj404_debugfile') {
            $abj404view->echoAdminDebugFile();
        } else {
            $this->logger->debugMessage('No tab selected. Displaying the "redirects" tab.');
            $abj404view->echoAdminRedirectsPage();
        }
        
        $abj404view->echoAdminFooter();
    }
    
    /**
     * Echo the text that appears at the bottom of each admin page.
     * @return void
     */
    function echoAdminFooter() {
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminFooter.html");
        $html = $this->f->str_replace('{JAPANESE_FLASHCARDS_URL}', ABJ404_FC_URL, $html);
        
        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        echo $html;
    }

    /** Output the tabs at the top of the plugin page.
     * @param string $sub
     * @param string $message
     * @return void
     */
    function outputAdminHeaderTabs($sub = 'list', $message = '') {
        ABJ_404_Solution_WPNotices::echoAdminNotices();

        echo "<div class=\"wrap\" style='z-index: 1;position: relative;'>";
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
            
            echo '<div class="' . $cssClasses . '"><p>' . wp_kses($message, $allowed_tags) . "</p></div>\n";
        }

        echo '<nav class="abj404-tab-navigation" role="tablist">';

        // Page Redirects tab
        $class = ($sub == 'abj404_redirects') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_redirects" title="' . esc_attr__('Page Redirects', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-randomize"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Page Redirects', '404-solution') . '</span>';
        echo '</a>';

        // Captured 404 URLs tab
        $class = ($sub == 'abj404_captured') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_captured" title="' . esc_attr__('Captured 404 URLs', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-search"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Captured 404s', '404-solution') . '</span>';
        echo '</a>';

        // Logs tab
        $class = ($sub == 'abj404_logs') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_logs" title="' . esc_attr__('Redirect & Capture Logs', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-list-view"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Logs', '404-solution') . '</span>';
        echo '</a>';

        // Stats tab
        $class = ($sub == 'abj404_stats') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_stats" title="' . esc_attr__('Stats', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-chart-bar"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Stats', '404-solution') . '</span>';
        echo '</a>';

        // Tools tab
        $class = ($sub == 'abj404_tools') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_tools" title="' . esc_attr__('Tools', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-admin-tools"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Tools', '404-solution') . '</span>';
        echo '</a>';

        // Options tab
        $class = ($sub == "abj404_options") ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_options" title="' . esc_attr__('Options', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-admin-generic"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Options', '404-solution') . '</span>';
        echo '</a>';

        // Plugin branding - right side of tabs
        echo '<span class="abj404-tab-branding">' . esc_html(PLUGIN_NAME) . '</span>';

        echo '</nav>';
    }
    
    /** This outputs a box with a title and some content in it.
     * It's used on the Stats, Options and Tools page (for example).
     * @param int|string $id
     * @param string $title
     * @param string $content
     * @return void
     */
    function echoPostBox($id, $title, $content) {
        echo "<div id=\"" . esc_attr((string)$id) . "\" class=\"postbox\">";
        echo "<h3 class=\"\" ><span>" . esc_html($title) . "</span></h3>";
        echo "<div class=\"inside\">" . $content /* Can't escape here, as contains forms */ . "</div>";
        echo "</div>";
    }

    /**
     * Echo an accordion section using card-based layout
     * @param string $sectionId The section identifier
     * @param string $postboxId The ID for the postbox
     * @param string $title The title of the section
     * @param string $content The content to display
     * @param bool $initiallyVisible Whether the card starts expanded (default: false)
     * @param string $icon The SVG icon for the card header (optional)
     * @param string $badge Optional info badge text
     * @return void
     */
    function echoOptionsSection($sectionId, $postboxId, $title, $content, $initiallyVisible = false, $icon = '', $badge = '') {
        $expandedClass = $initiallyVisible ? ' expanded' : '';

        echo "<div class=\"abj404-card" . esc_attr($expandedClass) . "\" data-card=\"" . esc_attr($sectionId) . "\" id=\"" . esc_attr($postboxId) . "\">";
        echo "<div class=\"abj404-card-header\" onclick=\"abj404ToggleCard(this)\" role=\"button\" aria-expanded=\"" . ($initiallyVisible ? 'true' : 'false') . "\" tabindex=\"0\">";
        echo "<h2 class=\"abj404-card-title\">";
        if ($icon) {
            echo $icon; // Icon is pre-sanitized SVG
        }
        echo esc_html($title);
        if ($badge) {
            echo "<span class=\"abj404-info-badge\">" . esc_html($badge) . "</span>";
        }
        echo "</h2>";
        echo "<svg class=\"abj404-collapse-icon\" width=\"20\" height=\"20\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">";
        echo "<path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M19 9l-7 7-7-7\"></path>";
        echo "</svg>";
        echo "</div>";
        echo "<div class=\"abj404-card-content\">";
        echo $content; /* Can't escape here, as contains forms */
        echo "</div>";
        echo "</div>";
    }

    /**
     * Get SVG icon for a section
     * @param string $iconName The icon name
     * @return string The SVG icon markup
     */
    function getCardIcon($iconName) {
        $icons = array(
            'lightning' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>',
            'gear' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>',
            'filter' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>',
            'document' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>',
            'sliders' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>',
            'lightbulb' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>',
            // Stats page icons
            'chart' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>',
            'warning' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
            'clock' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            // Tools page icons
            'download' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>',
            'upload' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>',
            'trash' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>',
            'database' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>',
            'cog' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>'
        );
        return isset($icons[$iconName]) ? $icons[$iconName] : '';
    }


    /**
     * Echo the sticky save bar
     * @return void
     */
    function echoStickySaveBar() {
        $version = ABJ404_VERSION;
        echo '<div class="abj404-sticky-save-bar">';
        echo '<div class="abj404-save-bar-status">';
        /* translators: %s: plugin version number */
        echo esc_html(sprintf(__('Plugin v%s', '404-solution'), $version));
        echo '</div>';
        echo '<div class="abj404-save-bar-actions">';
        echo '<input type="submit" form="admin-options-page" name="abj404-optionssub" id="abj404-optionssub" value="' . esc_attr__('Save Settings', '404-solution') . '" class="button button-primary abj404-btn abj404-btn-primary">';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Echo the success toast notification container
     * @return void
     */
    function echoToastNotification() {
        echo '<div id="abj404-toast" class="abj404-toast">';
        echo '<span class="abj404-toast-icon">✓</span> ';
        echo '<span class="abj404-toast-message">' . esc_html__('Settings saved successfully!', '404-solution') . '</span>';
        echo '</div>';
    }


    /**
     * Echo the expand/collapse all button and save button
     * @param bool $showSuggestions Not used (kept for compatibility)
     * @return void
     */
    function echoExpandCollapseButton($showSuggestions = true) {
        ?>
        <div class="abj404-accordion-controls">
            <button type="button" id="abj404-expand-collapse-all" class="button">
                <?php echo esc_html__('Expand All', '404-solution'); ?>
            </button>
            <input type="submit" name="abj404-optionssub" id="abj404-optionssub" value="<?php echo esc_attr__('Save Settings', '404-solution'); ?>" class="button-primary">
        </div>
        <?php
    }

    /**
     * Echo the Simple/Advanced mode toggle control.
     * @param string $currentMode 'simple' or 'advanced'
     * @return void
     */
    function echoSettingsModeToggle($currentMode) {
        $simpleActive = ($currentMode === 'simple') ? 'active' : '';
        $advancedActive = ($currentMode === 'advanced') ? 'active' : '';
        $simplePressedState = ($currentMode === 'simple') ? 'true' : 'false';
        $advancedPressedState = ($currentMode === 'advanced') ? 'true' : 'false';

        if ($currentMode === 'simple') {
            $modeDescription = __('Simple Mode shows essential options only. Switch to Advanced Mode for full configuration.', '404-solution');
        } else {
            $modeDescription = __('Advanced Mode shows all options. Switch to Simple Mode for a streamlined view.', '404-solution');
        }

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsModeToggle.html");
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

    /**
     * Echo an inline (compact) mode toggle for the header row.
     * This is a smaller version without the description text.
     * @return void
     */
    function echoInlineModeToggle() {
        $currentMode = $this->logic->getSettingsMode();
        $simpleActive = ($currentMode === 'simple') ? 'active' : '';
        $advancedActive = ($currentMode === 'advanced') ? 'active' : '';
        $simplePressedState = ($currentMode === 'simple') ? 'true' : 'false';
        $advancedPressedState = ($currentMode === 'advanced') ? 'true' : 'false';

        echo '<div class="abj404-mode-toggle abj404-mode-toggle-inline" data-nonce="' . esc_attr(wp_create_nonce('abj404_mode_toggle')) . '">';
        echo '<div class="abj404-mode-toggle-buttons">';
        echo '<button type="button" class="abj404-mode-btn ' . esc_attr($simpleActive) . '" data-mode="simple" aria-pressed="' . esc_attr($simplePressedState) . '">';
        echo '<span class="abj404-mode-btn-text">' . esc_html__('Simple', '404-solution') . '</span>';
        echo '</button>';
        echo '<button type="button" class="abj404-mode-btn ' . esc_attr($advancedActive) . '" data-mode="advanced" aria-pressed="' . esc_attr($advancedPressedState) . '">';
        echo '<span class="abj404-mode-btn-text">' . esc_html__('Advanced', '404-solution') . '</span>';
        echo '</button>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Echo the Simple Mode options page.
     * @param array<string, mixed> $options The plugin options
     * @return void
     */
    function echoSimpleModeOptions($options) {
        // Build behavior tiles HTML (replaces old dropdown)
        $behaviorTilesHtml = $this->getBehaviorTilesHTML($options);

        // Build selected states for checkboxes and dropdowns
        $selectedAutoRedirects = $this->getCheckedAttr($options, 'auto_redirects');
        $selectedCapture404 = $this->getCheckedAttr($options, 'capture_404');
        $selectedDefaultRedirect301 = ($options['default_redirect'] == '301') ? 'selected' : '';
        $selectedDefaultRedirect302 = ($options['default_redirect'] == '302') ? 'selected' : '';
        $selectedDefaultRedirect307 = ($options['default_redirect'] == '307') ? 'selected' : '';
        $selectedDefaultRedirect308 = ($options['default_redirect'] == '308') ? 'selected' : '';

        // Theme selections
        $selectedThemeDefault = ($options['admin_theme'] == 'default') ? 'selected' : '';
        $selectedThemeCalm = ($options['admin_theme'] == 'calm') ? 'selected' : '';
        $selectedThemeMono = ($options['admin_theme'] == 'mono') ? 'selected' : '';
        $selectedThemeNeon = ($options['admin_theme'] == 'neon') ? 'selected' : '';
        $selectedThemeObsidian = ($options['admin_theme'] == 'obsidian') ? 'selected' : '';

        // Notification frequency selections
        $notifyFrequency = isset($options['admin_notification_frequency']) ? (string)(is_scalar($options['admin_notification_frequency']) ? $options['admin_notification_frequency'] : 'instant') : 'instant';
        $selectedNotifyInstant = ($notifyFrequency === 'instant') ? 'selected' : '';
        $selectedNotifyDaily   = ($notifyFrequency === 'daily')   ? 'selected' : '';
        $selectedNotifyWeekly  = ($notifyFrequency === 'weekly')  ? 'selected' : '';

        // Read and build the simple options template
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminOptionsSimple.html");

        // Replace dest404page tiles
        $html = $this->f->str_replace('{behaviorTiles}', $behaviorTilesHtml, $html);

        // Replace checkbox states
        $html = $this->f->str_replace('{selectedAutoRedirects}', $selectedAutoRedirects, $html);
        $html = $this->f->str_replace('{selectedCapture404}', $selectedCapture404, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect301}', $selectedDefaultRedirect301, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect302}', $selectedDefaultRedirect302, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect307}', $selectedDefaultRedirect307, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect308}', $selectedDefaultRedirect308, $html);

        // Replace values
        $html = $this->f->str_replace('{capture_deletion}', esc_attr($this->optStr($options, 'capture_deletion')), $html);
        $html = $this->f->str_replace('{admin_notification}', esc_attr($this->optStr($options, 'admin_notification')), $html);
        $html = $this->f->str_replace('{maximum_log_disk_usage}', esc_attr($this->optStr($options, 'maximum_log_disk_usage')), $html);

        // Theme selections
        $html = $this->f->str_replace('{selectedThemeDefault}', $selectedThemeDefault, $html);
        $html = $this->f->str_replace('{selectedThemeCalm}', $selectedThemeCalm, $html);
        $html = $this->f->str_replace('{selectedThemeMono}', $selectedThemeMono, $html);
        $html = $this->f->str_replace('{selectedThemeNeon}', $selectedThemeNeon, $html);
        $html = $this->f->str_replace('{selectedThemeObsidian}', $selectedThemeObsidian, $html);
        $html = $this->f->str_replace('{theme_default}', __('Default (Follows WordPress)', '404-solution'), $html);

        // Translate labels
        $html = $this->f->str_replace('{Core Settings}', __('Core Settings', '404-solution'), $html);
        $html = $this->f->str_replace('{404 Capture}', __('404 Capture', '404-solution'), $html);
        $html = $this->f->str_replace('{Maintenance}', __('Maintenance', '404-solution'), $html);
        $html = $this->f->str_replace('{Default 404 destination}', __('Default 404 destination', '404-solution'), $html);
        $html = $this->f->str_replace('{Where to send visitors when a 404 error occurs}', __('Where to send visitors when a 404 error occurs', '404-solution'), $html);
        $html = $this->f->str_replace('{Create automatic redirects}', __('Create automatic redirects', '404-solution'), $html);
        $html = $this->f->str_replace('{Automatically redirect 404s to similar pages when a good match is found}', __('Automatically redirect 404s to similar pages when a good match is found', '404-solution'), $html);
        $html = $this->f->str_replace('{Redirect type}', __('Redirect type', '404-solution'), $html);
        $html = $this->f->str_replace('{Permanent 301}', __('Permanent 301', '404-solution'), $html);
        $html = $this->f->str_replace('{Temporary 302}', __('Temporary 302', '404-solution'), $html);
        $html = $this->f->str_replace('{301 for SEO, 302 for temporary changes}', __('301 for SEO, 302 for temporary changes', '404-solution'), $html);
        $html = $this->f->str_replace('{Collect incoming 404 URLs}', __('Collect incoming 404 URLs', '404-solution'), $html);
        $html = $this->f->str_replace('{Log 404 errors so you can review and fix them}', __('Log 404 errors so you can review and fix them', '404-solution'), $html);
        $html = $this->f->str_replace('{Delete captured URLs after}', __('Delete captured URLs after', '404-solution'), $html);
        $html = $this->f->str_replace('{days}', __('days', '404-solution'), $html);
        $html = $this->f->str_replace('{Auto-remove old 404 records (0 to keep forever)}', __('Auto-remove old 404 records (0 to keep forever)', '404-solution'), $html);
        $html = $this->f->str_replace('{Notify me when captured URLs exceed}', __('Notify me when captured URLs exceed', '404-solution'), $html);
        $html = $this->f->str_replace('{URLs}', __('URLs', '404-solution'), $html);
        $html = $this->f->str_replace('{Show admin notice when 404 count gets high (0 to disable)}', __('Show admin notice when 404 count gets high (0 to disable)', '404-solution'), $html);
        $html = $this->f->str_replace('{Maximum log storage}', __('Maximum log storage', '404-solution'), $html);
        $html = $this->f->str_replace('{Oldest logs are deleted when this limit is reached}', __('Oldest logs are deleted when this limit is reached', '404-solution'), $html);
        $html = $this->f->str_replace('{Admin theme}', __('Admin theme', '404-solution'), $html);
        $html = $this->f->str_replace('{Calm Ops (Light)}', __('Calm Ops (Light)', '404-solution'), $html);
        $html = $this->f->str_replace('{Monochrome Minimal (Light)}', __('Monochrome Minimal (Light)', '404-solution'), $html);
        $html = $this->f->str_replace('{Neon Slate (Dark)}', __('Neon Slate (Dark)', '404-solution'), $html);
        $html = $this->f->str_replace('{Obsidian Blue (Dark)}', __('Obsidian Blue (Dark)', '404-solution'), $html);
        $html = $this->f->str_replace('{Save Settings}', __('Save Settings', '404-solution'), $html);
        $html = $this->f->str_replace('{Need more control?}', __('Need more control?', '404-solution'), $html);
        $html = $this->f->str_replace('{Switch to Advanced Mode}', __('Switch to Advanced Mode', '404-solution'), $html);
        $html = $this->f->str_replace('{for 30+ additional options.}', __('for 30+ additional options.', '404-solution'), $html);

        // Frequency dropdown selections and labels
        $html = $this->f->str_replace('{selectedNotifyInstant}', $selectedNotifyInstant, $html);
        $html = $this->f->str_replace('{selectedNotifyDaily}', $selectedNotifyDaily, $html);
        $html = $this->f->str_replace('{selectedNotifyWeekly}', $selectedNotifyWeekly, $html);
        $html = $this->f->str_replace('{Email notification frequency}', __('Email notification frequency', '404-solution'), $html);
        $html = $this->f->str_replace('{Instant (when threshold exceeded)}', __('Instant (when threshold exceeded)', '404-solution'), $html);
        $html = $this->f->str_replace('{Daily digest}', __('Daily digest', '404-solution'), $html);
        $html = $this->f->str_replace('{Weekly digest}', __('Weekly digest', '404-solution'), $html);
        $html = $this->f->str_replace('{Choose how often to receive email notifications about captured 404s.}', __('Choose how often to receive email notifications about captured 404s.', '404-solution'), $html);
        // Also handle the Temporary 307/308 options (present in simple mode too)
        $html = $this->f->str_replace('{Temporary 307 (preserve method)}', __('Temporary 307 (preserve method)', '404-solution'), $html);
        $html = $this->f->str_replace('{Permanent 308 (preserve method)}', __('Permanent 308 (preserve method)', '404-solution'), $html);

        echo $html;
    }


}
