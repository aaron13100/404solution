<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_WordPress_Connector {

    /** Setup. */
    static function init() {
        // always load
        add_action('abj404_cleanupCronAction', 'abj404_dailyMaintenanceCronJobListener');
        
        if (is_admin()) {
            register_deactivation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::doUnregisterCrons');
            register_activation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::runOnPluginActivation');
            
            // include only if necessary
            add_filter("plugin_action_links_" . ABJ404_NAME, 'ABJ_404_Solution_WordPress_Connector::addSettingsLinkToPluginPage');
            add_action('admin_notices', 'ABJ_404_Solution_WordPress_Connector::echoDashboardNotification');
            add_action('admin_menu', 'ABJ_404_Solution_WordPress_Connector::addMainSettingsPageLink');
            // a priority of 11 makes sure our style sheet is more important than jquery's. otherwise the indent
            // doesn't work for the ajax dropdown list.
            add_action('admin_enqueue_scripts', 'ABJ_404_Solution_WordPress_Connector::add_scripts', 11);
            // wp_ajax_nopriv_ is for normal users
            add_action('wp_ajax_echoViewLogsFor', 'ABJ_404_Solution_Ajax_Php::echoViewLogsFor');
            add_action('wp_ajax_trashLink', 'ABJ_404_Solution_Ajax_TrashLink::trashAction');
            add_action('wp_ajax_echoRedirectToPages', 'ABJ_404_Solution_Ajax_Php::echoRedirectToPages');
            
            ABJ_404_Solution_PluginLogic::doRegisterCrons();
        }
    }
    
    /** Include things necessary for ajax. */
    static function add_scripts($hook) {
        // only load this stuff for this plugin. 
        // thanks to https://pippinsplugins.com/loading-scripts-correctly-in-the-wordpress-admin/
    	if ($hook != $GLOBALS['abj404_settingsPageName']) {
            return;
        }

        // jquery is used for the searchable dropdown list of pages for adding a redirect and other things.
        wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-autocomplete');
		wp_enqueue_script('jquery-effects-core');
		wp_enqueue_script('jquery-effects-highlight');
        
        wp_register_script('abj404-redirect_to_ajax', plugin_dir_url(__FILE__) . 'ajax/redirect_to_ajax.js', 
                array('jquery', 'jquery-ui-autocomplete'), ABJ404_VERSION);
        // Localize the script with new data
        $translation_array = array(
            'type_a_page_name' => __('(Type a page name or an external URL)', '404-solution'),
            'a_page_has_been_selected' => __('(A page has been selected.)', '404-solution'),
            'an_external_url_will_be_used' => __('(An external URL will be used.)', '404-solution')
        );
        wp_localize_script('abj404-redirect_to_ajax', 'abj404localization', $translation_array );        
        wp_enqueue_script('abj404-redirect_to_ajax');
        
        // make sure the "apply" button is only enabled if at least one checkbox is selected
        wp_register_script('abj404-enable_disable_apply_button_js', 
                ABJ404_URL . 'includes/js/enableDisableApplyButton.js', null, ABJ404_VERSION);
        $translation_array = array('{altText}' => __('Choose at least one URL', '404-solution'));
        wp_localize_script('abj404-enable_disable_apply_button_js', 'abj404localization', $translation_array);
        wp_enqueue_script('abj404-enable_disable_apply_button_js');
        
        wp_enqueue_script('abj404-view-updater', plugin_dir_url(__FILE__) . 'ajax/view_updater.js', 
                array('jquery', 'jquery-ui-autocomplete'), ABJ404_VERSION);
        wp_enqueue_script('abj404-search_logs_ajax', plugin_dir_url(__FILE__) . 'ajax/search_logs_ajax.js', 
                array('jquery', 'jquery-ui-autocomplete'), ABJ404_VERSION);
        wp_enqueue_script('abj404-trash_link_ajax', plugin_dir_url(__FILE__) . 'ajax/trash_link_ajax.js', 
                array('jquery'), ABJ404_VERSION);
        
        wp_enqueue_style('abj404solution-styles', ABJ404_URL . 'includes/html/404solutionStyles.css',
                null, ABJ404_VERSION);
    }

    /** Add the "Settings" link to the WordPress plugins page (next to activate/deactivate and edit).
     * @param array $links
     * @return array
     */
    static function addSettingsLinkToPluginPage($links) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        
        if (!is_array($links)) {
            $links = array();
        }
        
        if (!is_admin() || !current_user_can('administrator')) {
            $abj404logging->logUserCapabilities("addSettingsLinkToPluginPage");

            return $links;
        }

        $settings_link = '<a href="options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options">' . 
                __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        
        $debugExplanation = __('Debug Log', '404-solution');
        $debugLogLink = $abj404logic->getDebugLogFileLink();
        $debugExplanation = '<a href="options-general.php' . $debugLogLink . '" target="_blank" >' . $debugExplanation .
                '</a>';
        array_push($links, $debugExplanation);
        
        return $links;
    }

    /** This is called directly by php code inserted into the page by the user.
     * Code: <?php if (!empty($abj404connector)) {$abj404connector->suggestions(); } ?>
     * @global type $abj404shortCode
     */
    function suggestions() {
        $abj404shortCode = new ABJ_404_Solution_ShortCode();

        if (is_404()) {
            $content = $abj404shortCode->shortcodePageSuggestions(array());

            echo $content;
        }
    }

    /**
     * Process the 404s
     */
    function process404() {
        if (!is_404() || is_admin()) {
            return;
        }
        
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404connector = new ABJ_404_Solution_WordPress_Connector();
        $abj404spellChecker = new ABJ_404_Solution_SpellChecker();
        $f = ABJ_404_Solution_Functions::getInstance();

        
        $_REQUEST[ABJ404_PP]['process_start_time'] = microtime(true);

        // create a UserRequest object to store various information about the request for later use.
        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        
        $pathOnly = $userRequest->getPath();

        // remove the home directory from the URL parts because it should not be considered for spell checking.
        $urlSlugOnly = $userRequest->getOnlyTheSlug();

        // setup ignore variables on $_REQUEST['abj404solution']
        $abj404logic->initializeIgnoreValues($pathOnly, $urlSlugOnly);
        
        if ($_REQUEST[ABJ404_PP]['ignore_donotprocess']) {
            $abj404dao->logRedirectHit($pathOnly, '404', 'ignore_donotprocess');
            return;
        }
        
        $requestedURL = $userRequest->getPathWithSortedQueryString();
        
        // Get URL data if it's already in our database
        $redirect = $abj404dao->getActiveRedirectForURL($requestedURL);

        $options = $abj404logic->getOptions();

        $this->logAReallyLongDebugMessage($options, $requestedURL, $redirect);

        if ($requestedURL != "") {
            // if we already know where to go then go there.
            if ($redirect['id'] != '0' && $redirect['final_dest'] != '0') {
                // A redirect record exists.
                $abj404connector->processRedirect($requestedURL, $redirect, 'existing');

                // we only reach this line if an error happens because the user should already be redirected.
                exit;
            }

            // --------------------------------------------------------------
            // try a permalink change.
            $slugPermalink = $abj404spellChecker->getPermalinkUsingSlug($urlSlugOnly);
            if (!empty($slugPermalink)) {
                $redirectType = $slugPermalink['type'];
                $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $slugPermalink['id'], $options['default_redirect'], 0);

                $abj404dao->logRedirectHit($requestedURL, $slugPermalink['link'], 'exact slug');
                $abj404logic->forceRedirect(esc_url($slugPermalink['link']), esc_html($options['default_redirect']));
                exit;
            }

            // --------------------------------------------------------------
            // try the regex URLs.
            $regexPermalink = $abj404spellChecker->getPermalinkUsingRegEx($requestedURL);
            if (!empty($regexPermalink)) {
                $redirectType = $regexPermalink['type'];

                $abj404dao->logRedirectHit($regexPermalink['matching_regex'], $regexPermalink['link'], 'regex match', 
                        $requestedURL);
                $abj404logic->forceRedirect($regexPermalink['link'], esc_html($options['default_redirect']));
                exit;
            }
            
            // --------------------------------------------------------------
            // try spell checking.
            $permalink = $abj404spellChecker->getPermalinkUsingSpelling($urlSlugOnly);
            if (!empty($permalink)) {
                $redirectType = $permalink['type'];
                $abj404dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $permalink['id'], $options['default_redirect'], 0);

                $abj404dao->logRedirectHit($requestedURL, $permalink['link'], 'spell check');
                $abj404logic->forceRedirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                exit;
            }

        } else {

            // this is for a permalink structure that has changed?
            if (is_single() || is_page()) {
                if (!is_feed() && !is_trackback() && !is_preview()) {
                    $theID = get_the_ID();
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($theID . "|" . ABJ404_TYPE_POST, 0);

                    $urlParts = parse_url($permalink['link']);
                    $perma_link = $urlParts['path'];

                    $paged = get_query_var('page') ? esc_html(get_query_var('page')) : FALSE;

                    if (!$paged === FALSE) {
                        if ($urlParts[query] == "") {
                            if ($f->substr($perma_link, -1) == "/") {
                                $perma_link .= $paged . "/";
                            } else {
                                $perma_link .= "/" . $paged;
                            }
                        } else {
                            $urlParts['query'] .= "&page=" . $paged;
                        }
                    }

                    $perma_link .= $f->sortQueryString($urlParts);

                    // Check for forced permalinks.
                    if (@$options['auto_redirects'] == '1') {
                        if ($requestedURL != $perma_link) {
                            if ($redirect['id'] != '0') {
                                $abj404connector->processRedirect($requestedURL, $redirect, 'single page 3');
                            } else {
                                $abj404dao->setupRedirect(esc_url($requestedURL), ABJ404_STATUS_AUTO, ABJ404_TYPE_POST, $permalink['id'], $options['default_redirect'], 0);
                                $abj404dao->logRedirectHit($requestedURL, $permalink['link'], 'single page');
                                $abj404logic->forceRedirect(esc_url($permalink['link']), 
                                        esc_html($options['default_redirect']));
                                exit;
                            }
                        }
                    }

                    if ($requestedURL == $perma_link) {
                        // Not a 404 Link. Check for matches.
                        if ($options['remove_matches'] == '1') {
                            if ($redirect['id'] != '0') {
                                $abj404dao->deleteRedirect($redirect['id']);
                            }
                        }
                    }
                }
            }
        }

        // this is for requests like website.com/?p=123            
        $abj404logic->tryNormalPostQuery($options);
        
        $abj404logic->sendTo404Page($requestedURL, '');
    }
    
	/**
	 * @param options
	 */
    function logAReallyLongDebugMessage($options, $requestedURL, $redirect) {
	 	$abj404logging = ABJ_404_Solution_Logging::getInstance();
	 	
        $debugOptionsMsg = esc_html('auto_redirects: ' . $options['auto_redirects'] . ', auto_score: ' . 
                $options['auto_score'] . ', auto_cats: ' . $options['auto_cats'] . ', auto_tags: ' .
                $options['auto_tags'] . ', dest404page: ' . $options['dest404page']);

        $remoteAddress = esc_sql($_SERVER['REMOTE_ADDR']);
        if (!array_key_exists('log_raw_ips', $options) || $options['log_raw_ips'] != '1') {
            $remoteAddress = md5($remoteAddress);
        }

        $debugServerMsg = esc_html('HTTP_USER_AGENT: ' . $_SERVER['HTTP_USER_AGENT'] . ', REMOTE_ADDR: ' . 
                $remoteAddress . ', REQUEST_URI: ' . urldecode($_SERVER['REQUEST_URI']));
        $abj404logging->debugMessage("Processing 404 for URL: " . $requestedURL . " | Redirect: " .
                wp_kses_post(json_encode($redirect)) . " | is_single(): " . is_single() . " | " . "is_page(): " . is_page() .
                " | is_feed(): " . is_feed() . " | is_trackback(): " . is_trackback() . " | is_preview(): " .
                is_preview() . " | options: " . $debugOptionsMsg . ', ' . $debugServerMsg);
	}
    
    /** Redirect to the page specified. 
     * @global type $abj404dao
     * @global type $abj404logging
     * @global type $abj404logic
     * #param type $requestedURL
     * @param array $redirect
     * #param type $matchReason
     */
    function processRedirect($requestedURL, $redirect, $matchReason) {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $abj404logic = new ABJ_404_Solution_PluginLogic();

        if (( $redirect['status'] != ABJ404_STATUS_MANUAL && $redirect['status'] != ABJ404_STATUS_AUTO ) || $redirect['disabled'] != 0) {
            // It's a redirect that has been deleted, ignored, or captured.
            $abj404logging->errorMessage("processRedirect() was called with bad redirect data. Data: " .
                    wp_kses_post(json_encode($redirect)));
            return;
        }

        if ($redirect['type'] == ABJ404_TYPE_EXTERNAL) {
            $abj404dao->logRedirectHit($requestedURL, $redirect['final_dest'], 'external');
            $abj404logic->forceRedirect(esc_url($redirect['final_dest']), esc_html($redirect['code']));
            exit;
        }

        $key = "";
        if ($redirect['type'] == ABJ404_TYPE_POST) {
            $key = $redirect['final_dest'] . "|" . ABJ404_TYPE_POST;
        } else if ($redirect['type'] == ABJ404_TYPE_CAT) {
            $key = $redirect['final_dest'] . "|" . ABJ404_TYPE_CAT;
        } else if ($redirect['type'] == ABJ404_TYPE_TAG) {
            $key = $redirect['final_dest'] . "|" . ABJ404_TYPE_TAG;
        } else if ($redirect['type'] == ABJ404_TYPE_HOME) {
            $key = $redirect['final_dest'] . "|" . ABJ404_TYPE_HOME;
        } else {
            $abj404logging->errorMessage("Unrecognized redirect type in Connector: " .
                    wp_kses_post(json_encode($redirect)));
        }

        if ($key != "") {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($key, 0);

            // log only the path part of the URL
            $urlParts = parse_url(esc_url($permalink['link']));
            $redirectedTo = $urlParts['path'];
            
            $abj404dao->logRedirectHit($requestedURL, $redirectedTo, $matchReason);
            $abj404logic->forceRedirect($permalink['link'], esc_html($redirect['code']));
            exit;
        }
    }

    /** Display an admin dashboard notification.
     * e.g. There are 29 captured 404 URLs to be processed.
     * @global type $pagenow
     * @global type $abj404dao
     * @global type $abj404logic
     * @global type $abj404view
     */
    static function echoDashboardNotification() {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        
        if (!is_admin() || !current_user_can('administrator')) {
            $abj404logging->logUserCapabilities("echoDashboardNotification");
            return;
        }

        global $pagenow;
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        global $abj404view;

        if (current_user_can('manage_options')) {
            if ( (array_key_exists('page', $_GET) && $_GET['page'] == ABJ404_PP) ||
                 ($pagenow == 'index.php' && !isset($_GET['page'])) ) {
                $captured404Count = $abj404dao->getCapturedCountForNotification();
                if ($abj404logic->shouldNotifyAboutCaptured404s($captured404Count)) {
                    $msg = $abj404view->getDashboardNotificationCaptured($captured404Count);
                    echo $msg;
                }
            }
        }
        
        ABJ_404_Solution_WPNotices::echoAdminNotices();
    }

    /** Adds a link under the "Settings" link to the plugin page.
     * @global string $menu
     * @global type $abj404dao
     * @global type $abj404logic
     * @global type $abj404logging
     */
    static function addMainSettingsPageLink() {
        global $menu;
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        if (!is_admin() || !current_user_can('administrator')) {
            $abj404logging->logUserCapabilities("addMainSettingsPageLink");
            return;
        }

        $options = $abj404logic->getOptions();
        $pageName = "404 Solution";

        // Admin notice
        if (array_key_exists('admin_notification', $options) && isset($options['admin_notification']) && $options['admin_notification'] != '0') {
            $captured = $abj404dao->getCapturedCountForNotification();
            if (isset($options['admin_notification']) && $captured >= $options['admin_notification']) {
                $pageName .= " <span class='update-plugins count-1'><span class='update-count'>" . esc_html($captured) . "</span></span>";
                $pos = $f->strpos($menu[80][0], 'update-plugins');
                if ($pos === false) {
                    $menu[80][0] = $menu[80][0] . " <span class='update-plugins count-1'><span class='update-count'>1</span></span>";
                }
            }
        }

        if (array_key_exists('menuLocation', $options) && isset($options['menuLocation']) && 
                $options['menuLocation'] == 'settingsLevel') {
            // this adds the settings link at the same level as the "Tools" and "Settings" menu items.
			$GLOBALS['abj404_settingsPageName'] = add_menu_page(PLUGIN_NAME, PLUGIN_NAME, 'manage_options', 'abj404_solution',
                    'ABJ_404_Solution_View::handleMainAdminPageActionAndDisplay');
                
        } else {
            // this adds the settings link at Settings->404 Solution.
        	$GLOBALS['abj404_settingsPageName'] = add_submenu_page('options-general.php', PLUGIN_NAME, $pageName, 'manage_options', ABJ404_PP, 
                    'ABJ_404_Solution_View::handleMainAdminPageActionAndDisplay');
        }
    }

}

ABJ_404_Solution_WordPress_Connector::init();
