<?php

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_WordPress_Connector {

	private static $instance = null;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_SpellChecker */
	private $spellChecker;

	/**
	 * Constructor with dependency injection.
	 *
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
	 * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 * @param ABJ_404_Solution_Functions|null $functions String utilities
	 * @param ABJ_404_Solution_SpellChecker|null $spellChecker Spell checker service
	 */
	public function __construct($pluginLogic = null, $dataAccess = null, $logging = null, $functions = null, $spellChecker = null) {
		// Use injected dependencies or fall back to getInstance() for backward compatibility
		$this->logic = $pluginLogic !== null ? $pluginLogic : ABJ_404_Solution_PluginLogic::getInstance();
		$this->dao = $dataAccess !== null ? $dataAccess : ABJ_404_Solution_DataAccess::getInstance();
		$this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
		$this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
		$this->spellChecker = $spellChecker !== null ? $spellChecker : ABJ_404_Solution_SpellChecker::getInstance();
	}

	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_WordPress_Connector();
		}

		return self::$instance;
	}
	
	/** Setup. */
    static function init() {
    	if (is_admin()) {
            register_deactivation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::runOnPluginDeactivation');
            register_activation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::runOnPluginActivation');

            // Multisite support: handle new blog creation
            if (is_multisite()) {
                // WordPress < 5.1 compatibility
                add_action('wpmu_new_blog', 'ABJ_404_Solution_PluginLogic::activateNewSite', 10, 6);
                // WordPress >= 5.1 compatibility
                add_action('wp_initialize_site', 'ABJ_404_Solution_PluginLogic::activateNewSiteModern', 10, 2);
                // Handle blog deletion
                add_action('delete_blog', 'ABJ_404_Solution_PluginLogic::deleteBlogData', 10, 2);
            }

            // include only if necessary
            add_filter("plugin_action_links_" . ABJ404_NAME,
            	'ABJ_404_Solution_WordPress_Connector::addSettingsLinkToPluginPage');
            add_action('admin_notices',
            	'ABJ_404_Solution_WordPress_Connector::echoDashboardNotification');
            add_action('admin_menu',
            	'ABJ_404_Solution_WordPress_Connector::addMainSettingsPageLink');
            // a priority of 11 makes sure our style sheet is more important than jquery's. otherwise the indent
            // doesn't work for the ajax dropdown list.
            add_action('admin_enqueue_scripts',
            	'ABJ_404_Solution_WordPress_Connector::add_scripts', 11);
            // Output critical theme CSS early (priority 1) to prevent FOUC
            add_action('admin_head',
            	'ABJ_404_Solution_WordPress_Connector::outputCriticalThemeCSS', 1);
            add_action('admin_head',
            	'ABJ_404_Solution_WordPress_Connector::add_theme_script');
            // wp_ajax_nopriv_ is for normal users

            ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_echoViewLogsFor', 'ABJ_404_Solution_Ajax_Php::echoViewLogsFor');
            ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_trashLink', 'ABJ_404_Solution_Ajax_TrashLink::trashAction');
            ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_echoRedirectToPages', 'ABJ_404_Solution_Ajax_Php::echoRedirectToPages');
            ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_updateOptions', 'ABJ_404_Solution_Ajax_Php::updateOptions');
        }

        ABJ_404_Solution_PluginLogic::doRegisterCrons();
    }

    /** Include things necessary for ajax. */
    static function add_scripts($hook) {
        // only load this stuff for this plugin. 
        // thanks to https://pippinsplugins.com/loading-scripts-correctly-in-the-wordpress-admin/
    	if (!array_key_exists('abj404_settingsPageName', $GLOBALS) || 
    		$hook != $GLOBALS['abj404_settingsPageName']) {
            return;
        }

        // remove the "thank you for creating with wordpress" message
        add_filter('admin_footer_text',
            'ABJ_404_Solution_WordPress_Connector::remove_admin_footer_text');
        // remove the version number message
        add_filter('update_footer',
            'ABJ_404_Solution_WordPress_Connector::remove_admin_footer_text', 11);
        
        // jquery is used for the searchable dropdown list of pages for adding a redirect and other things.
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery');
		ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery-ui-autocomplete');
		ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery-effects-core');
		ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery-effects-highlight');
        
        wp_register_script('abj404-redirect_to_ajax', plugin_dir_url(__FILE__) . 'ajax/redirect_to_ajax.js', 
                array('jquery', 'jquery-ui-autocomplete'));
        wp_register_script('abj404-exclude_pages_ajax', plugin_dir_url(__FILE__) . 'ajax/exclude_pages_ajax.js',
        	array('jquery', 'jquery-ui-autocomplete', 'abj404-redirect_to_ajax'));
        // Localize the script with new data
        $translation_array = array(
            'type_a_page_name' => __('(Type a page name or an external URL)', '404-solution'),
            'a_page_has_been_selected' => __('(A page has been selected.)', '404-solution'),
            'an_external_url_will_be_used' => __('(An external URL will be used.)', '404-solution')
        );
        wp_localize_script('abj404-redirect_to_ajax', 'abj404localization', $translation_array );        
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-redirect_to_ajax');
        wp_localize_script('abj404-exclude_pages_ajax', 'abj404localization', $translation_array );
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-exclude_pages_ajax');
        
        // make sure the "apply" button is only enabled if at least one checkbox is selected
        wp_register_script('abj404-enable_disable_apply_button_js', 
                ABJ404_URL . 'includes/js/enableDisableApplyButton.js');
        $translation_array = array('{altText}' => __('Choose at least one URL', '404-solution'));
        wp_localize_script('abj404-enable_disable_apply_button_js', 'abj404localization', $translation_array);
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-enable_disable_apply_button_js');
        
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-view-updater', plugin_dir_url(__FILE__) . 'ajax/view_updater.js', 
                array('jquery', 'jquery-ui-autocomplete'));
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-search_logs_ajax', plugin_dir_url(__FILE__) . 'ajax/search_logs_ajax.js', 
                array('jquery', 'jquery-ui-autocomplete'));
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-trash_link_ajax', plugin_dir_url(__FILE__) . 'ajax/trash_link_ajax.js', 
                array('jquery'));
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-general-js', plugin_dir_url(__FILE__) . 'js/general.js',
        	array('jquery'));
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-theme-preview', plugin_dir_url(__FILE__) . 'js/themePreview.js',
        	array('jquery'));
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-options-chips', plugin_dir_url(__FILE__) . 'js/optionsChips.js',
        	array('jquery'));

        ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-styles', ABJ404_URL . 'includes/html/404solutionStyles.css',
                null);
        ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-themes', ABJ404_URL . 'includes/html/adminThemes.css',
                null);
    }

    /** Add inline script to apply the selected theme to the body element */
    static function add_theme_script() {
        // Only run on our plugin pages
        if (!array_key_exists('abj404_settingsPageName', $GLOBALS) ||
            !array_key_exists('page', $_GET) ||
            $_GET['page'] != ABJ404_PP) {
            return;
        }

        $logic = ABJ_404_Solution_PluginLogic::getInstance();
        $options = $logic->getOptions();
        $theme = isset($options['admin_theme']) ? $options['admin_theme'] : 'default';

        // Sanitize theme value - only allow specific values
        $allowed_themes = array('default', 'calm', 'mono', 'neon', 'obsidian');
        if (!in_array($theme, $allowed_themes)) {
            $theme = 'default';
        }

        // Don't set data-theme attribute for 'default' theme
        if ($theme === 'default') {
            return;
        }

        echo '<script type="text/javascript">';
        echo '(function() {';
        echo '  document.addEventListener("DOMContentLoaded", function() {';
        echo '    document.body.setAttribute("data-theme", "' . esc_js($theme) . '");';
        echo '  });';
        echo '  if (document.body) {';
        echo '    document.body.setAttribute("data-theme", "' . esc_js($theme) . '");';
        echo '  }';
        echo '})();';
        echo '</script>';
    }

    /** Output critical theme CSS inline to prevent FOUC (Flash of Unstyled Content).
     * This outputs the CSS variables for the selected theme directly in the <head>
     * before any external CSS files load, eliminating the flash when a custom theme is selected.
     */
    static function outputCriticalThemeCSS() {
        // Only run on our plugin pages
        if (!array_key_exists('abj404_settingsPageName', $GLOBALS) ||
            !array_key_exists('page', $_GET) ||
            $_GET['page'] != ABJ404_PP) {
            return;
        }

        $logic = ABJ_404_Solution_PluginLogic::getInstance();
        $options = $logic->getOptions();
        $theme = isset($options['admin_theme']) ? $options['admin_theme'] : 'default';

        // Sanitize theme value - only allow specific values
        $allowed_themes = array('default', 'calm', 'mono', 'neon', 'obsidian');
        if (!in_array($theme, $allowed_themes)) {
            $theme = 'default';
        }

        // Don't output inline CSS for 'default' theme - let WordPress defaults apply
        if ($theme === 'default') {
            return;
        }

        // Define CSS variables for each theme
        $themeVariables = array(
            'mono' => array(
                '--abj404-bg' => '#F8FAFC',
                '--abj404-bg-muted' => '#F5F7FA',
                '--abj404-surface' => '#ffffff',
                '--abj404-surface-muted' => '#F1F5F9',
                '--abj404-text' => '#111827',
                '--abj404-text-muted' => '#6B7280',
                '--abj404-border' => '#E5E7EB',
                '--abj404-primary' => '#374151',
                '--abj404-accent' => '#2563EB',
                '--abj404-info' => '#3B82F6',
                '--abj404-success' => '#10B981',
                '--abj404-warning' => '#F59E0B',
                '--abj404-danger' => '#EF4444',
                '--abj404-focus' => '#93C5FD',
                '--abj404-table-header' => '#F1F5F9',
                '--abj404-row-hover' => '#F5F7FA',
                '--abj404-row-selected' => '#DBEAFE',
                '--abj404-badge-bg' => '#EFF1F5',
                '--abj404-badge-text' => '#374151',
            ),
            'calm' => array(
                '--abj404-bg' => '#F7FAFD',
                '--abj404-bg-muted' => '#F1F6FE',
                '--abj404-surface' => '#ffffff',
                '--abj404-surface-muted' => '#E9F0FB',
                '--abj404-text' => '#17223B',
                '--abj404-text-muted' => '#5A6B86',
                '--abj404-border' => '#E1E8F5',
                '--abj404-primary' => '#1E6BD6',
                '--abj404-accent' => '#00A27A',
                '--abj404-info' => '#2B8AE2',
                '--abj404-success' => '#20B67A',
                '--abj404-warning' => '#F6A700',
                '--abj404-danger' => '#D53F3F',
                '--abj404-focus' => '#5AA2FF',
                '--abj404-table-header' => '#E9F0FB',
                '--abj404-row-hover' => '#F1F6FE',
                '--abj404-row-selected' => '#D7E8FF',
                '--abj404-badge-bg' => '#EEF2F8',
                '--abj404-badge-text' => '#3E546E',
            ),
            'neon' => array(
                '--abj404-bg' => '#0C0F13',
                '--abj404-bg-muted' => '#11151A',
                '--abj404-surface' => '#151A21',
                '--abj404-surface-muted' => '#1B222B',
                '--abj404-text' => '#E5EAF2',
                '--abj404-text-muted' => '#A6B0C3',
                '--abj404-border' => '#273141',
                '--abj404-primary' => '#7C3AED',
                '--abj404-accent' => '#22D3EE',
                '--abj404-info' => '#60A5FA',
                '--abj404-success' => '#34D399',
                '--abj404-warning' => '#F59E0B',
                '--abj404-danger' => '#F87171',
                '--abj404-focus' => '#38BDF8',
                '--abj404-table-header' => '#1F2732',
                '--abj404-row-hover' => '#192028',
                '--abj404-row-selected' => '#0E2936',
                '--abj404-badge-bg' => '#202734',
                '--abj404-badge-text' => '#CFD8E6',
            ),
            'obsidian' => array(
                '--abj404-bg' => '#0A0F1A',
                '--abj404-bg-muted' => '#0E1522',
                '--abj404-surface' => '#121826',
                '--abj404-surface-muted' => '#172032',
                '--abj404-text' => '#E6ECF7',
                '--abj404-text-muted' => '#A9B7CC',
                '--abj404-border' => '#223149',
                '--abj404-primary' => '#1D4ED8',
                '--abj404-accent' => '#A78BFA',
                '--abj404-info' => '#60A5FA',
                '--abj404-success' => '#22C55E',
                '--abj404-warning' => '#F59E0B',
                '--abj404-danger' => '#EF4444',
                '--abj404-focus' => '#93C5FD',
                '--abj404-table-header' => '#1B253A',
                '--abj404-row-hover' => '#141C2C',
                '--abj404-row-selected' => '#1A2A46',
                '--abj404-badge-bg' => '#1A2438',
                '--abj404-badge-text' => '#DCE6F7',
            ),
        );

        // Output inline critical CSS if theme is selected
        if (isset($themeVariables[$theme])) {
            echo '<style id="abj404-critical-theme-css">:root{';
            foreach ($themeVariables[$theme] as $var => $value) {
                echo esc_html($var) . ':' . esc_html($value) . ';';
            }
            echo '}</style>';
        }
    }

    static function remove_admin_footer_text($content) {
        return '';
    }

    /** Add the "Settings" link to the WordPress plugins page (next to activate/deactivate and edit).
     * @param array $links
     * @return array
     */
    static function addSettingsLinkToPluginPage($links) {
        $instance = self::getInstance();

        if (!is_array($links)) {
        	$instance->logger->infoMessage("The settings links variable was not an array. " .
        		"Please verify the validity of other plugins. " . print_r($links, true));
            $links = array();
        }

        if (!is_admin() || !$instance->logic->userIsPluginAdmin()) {
            $instance->logger->logUserCapabilities("addSettingsLinkToPluginPage");

            return $links;
        }

        $settings_link = '<a href="options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options">' .
                __('Settings') . '</a>';
        array_unshift($links, $settings_link);

        $debugExplanation = __('Debug Log', '404-solution');
        $debugLogLink = $instance->logic->getDebugLogFileLink();
        $debugExplanation = '<a href="options-general.php' . $debugLogLink . '" target="_blank" >'
        	. $debugExplanation . '</a>';
        array_push($links, $debugExplanation);

        return $links;
    }

    /** This is called directly by php code inserted into the page by the user.
     * Code: <?php if (!empty($abj404connector)) {$abj404connector->suggestions(); } ?>
     * @global type $abj404shortCode
     */
    function suggestions() {
        $abj404shortCode = ABJ_404_Solution_ShortCode::getInstance();

        if (is_404()) {
            $content = $abj404shortCode->shortcodePageSuggestions(array());

            echo $content;
        }
    }

    function processRedirectAllRequests() {
    	$options = $this->logic->getOptions();
    	
    	$userRequest = ABJ_404_Solution_UserRequest::getInstance();
    	// setup ignore variables on $_REQUEST['abj404solution']
    	$pathOnly = $userRequest->getPath();
    	
    	// remove the home directory from the URL parts because it should not be considered for spell checking.
    	$urlSlugOnly = $userRequest->getOnlyTheSlug();
    	
    	$this->logic->initializeIgnoreValues($pathOnly, $urlSlugOnly);
    	
    	// create a UserRequest object to store various information about the request for later use.
    	$requestedURL = $userRequest->getPathWithSortedQueryString();
    	
    	$this->tryRegexRedirect($options, $requestedURL);
    	
    	// if we're supposed to redirect all requests then a regex redirect should be in place.
    	if (is_admin() || !is_404()) {
    		$this->logger->warn("If REDIRECT_ALL_REQUESTS is turned on then a " .
    			"regex redirect must be in place.");
    	}
    }
    /**
     * Process the 404s
     */
    function process404() {
        if (!is_404() || is_admin()) {
            return;
        }
        
        $abj404connector = ABJ_404_Solution_WordPress_Connector::getInstance();

        
        $_REQUEST[ABJ404_PP]['process_start_time'] = microtime(true);

        // create a UserRequest object to store various information about the request for later use.
        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        
        $pathOnly = $userRequest->getPath();

        // remove the home directory from the URL parts because it should not be considered for spell checking.
        $urlSlugOnly = $userRequest->getOnlyTheSlug();

        // setup ignore variables on $_REQUEST['abj404solution']
        $this->logic->initializeIgnoreValues($pathOnly, $urlSlugOnly);
        
        if ($_REQUEST[ABJ404_PP]['ignore_donotprocess']) {
            $this->dao->logRedirectHit($pathOnly, '404', 'ignore_donotprocess');
            return;
        }
        
        $requestedURL = $userRequest->getPathWithSortedQueryString();
        $requestedURLWithoutComments = $userRequest->getRequestURIWithoutCommentsPage();
        
        // Get URL data if it's already in our database
        $redirect = $this->dao->getActiveRedirectForURL($requestedURL);

        $options = $this->logic->getOptions();

        $this->logAReallyLongDebugMessage($options, $requestedURL, $redirect);

        if ($requestedURL != "") {
            // if we already know where to go then go there.
            if ($redirect['id'] != '0' && $redirect['final_dest'] != '0') {
                // A redirect record exists.
                $abj404connector->processRedirect($requestedURL, $redirect, 'existing');

                // we only reach this line if an error happens because the user should already be redirected.
                exit;
            }
            
            if ($requestedURLWithoutComments != $requestedURL) {
            	$redirect = $this->dao->getActiveRedirectForURL($requestedURLWithoutComments);
            	if ($redirect['id'] != '0' && $redirect['final_dest'] != '0') {
            		// A redirect record exists.
            		$abj404connector->processRedirect($requestedURL, $redirect, 'existing');
            		
            		// we only reach this line if an error happens because the user should already be redirected.
            		exit;
            	}
            }

            $autoRedirectsAreOn = !array_key_exists('auto_redirects', $options) ||
            	$options['auto_redirects'] == '1';
            	
            // --------------------------------------------------------------
            // try a permalink change.
            if ($autoRedirectsAreOn) {
	       		$slugPermalink = $this->spellChecker->getPermalinkUsingSlug($urlSlugOnly);
	            if (!empty($slugPermalink)) {
	                $redirectType = $slugPermalink['type'];
	                $this->dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $slugPermalink['id'], $options['default_redirect'], 0);
	
	                $this->dao->logRedirectHit($requestedURL, $slugPermalink['link'], 'exact slug');
	                $this->logic->forceRedirect(esc_url($slugPermalink['link']), esc_html($options['default_redirect']));
	                exit;
	            }
            }

            // --------------------------------------------------------------
            // try the regex URLs.
            $sentTo404Page = $this->tryRegexRedirect($options, $requestedURL);
            if ($sentTo404Page) {
            	return;
            }

            if (!$autoRedirectsAreOn) {
            	$this->logic->sendTo404Page($requestedURL,
            		'Do not create redirects per the options.');
            	return;
            }
            
            // --------------------------------------------------------------
            // try spell checking.
            $permalink = $this->spellChecker->getPermalinkUsingSpelling($urlSlugOnly);
            if (!empty($permalink)) {
                $redirectType = $permalink['type'];
                $this->dao->setupRedirect($requestedURL, ABJ404_STATUS_AUTO, $redirectType, $permalink['id'], $options['default_redirect'], 0);

                $this->dao->logRedirectHit($requestedURL, $permalink['link'], 'spell check');
                $this->logic->forceRedirect(esc_url($permalink['link']), esc_html($options['default_redirect']));
                exit;
            }

        } else {

            // this is for a permalink structure that has changed?
            if (is_single() || is_page()) {
                if (!is_feed() && !is_trackback() && !is_preview()) {
                    $theID = get_the_ID();
                    $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray(
                    	$theID . "|" . ABJ404_TYPE_POST, 0, null, $options);

                    $urlParts = parse_url($permalink['link']);
                    $perma_link = $urlParts['path'];

                    $paged = get_query_var('page') ? esc_html(get_query_var('page')) : FALSE;

                    if (!$paged === FALSE) {
                        if ($urlParts['query'] == "") {
                            if ($this->f->substr($perma_link, -1) == "/") {
                                $perma_link .= $paged . "/";
                            } else {
                                $perma_link .= "/" . $paged;
                            }
                        } else {
                            $urlParts['query'] .= "&page=" . $paged;
                        }
                    }

                    $perma_link .= $this->f->sortQueryString($urlParts);

                    // Check for forced permalinks.
                    if (@$options['auto_redirects'] == '1') {
                        if ($requestedURL != $perma_link) {
                            if ($redirect['id'] != '0') {
                                $abj404connector->processRedirect($requestedURL, $redirect, 'single page 3');
                            } else {
                                $this->dao->setupRedirect(esc_url($requestedURL), ABJ404_STATUS_AUTO, ABJ404_TYPE_POST, $permalink['id'], $options['default_redirect'], 0);
                                $this->dao->logRedirectHit($requestedURL, $permalink['link'], 'single page');
                                $this->logic->forceRedirect(esc_url($permalink['link']), 
                                        esc_html($options['default_redirect']));
                                exit;
                            }
                        }
                    }

                    if ($requestedURL == $perma_link) {
                        // Not a 404 Link. Check for matches.
                        if ($options['remove_matches'] == '1') {
                            if ($redirect['id'] != '0') {
                                $this->dao->deleteRedirect($redirect['id']);
                            }
                        }
                    }
                }
            }
        }

        // this is for requests like website.com/?p=123            
        $this->logic->tryNormalPostQuery($options);
        
        $this->dao->logRedirectHit($requestedURL, '404', 'gave up.');
        $this->logic->sendTo404Page($requestedURL, '');
    }
    
    /** 
     * 
     * @param $options
     * @param $requestedURL
     * @return boolean true if the user is sent to the default 404 page.
     */
    function tryRegexRedirect($options, $requestedURL) {
    	
    	$regexPermalink = $this->spellChecker->getPermalinkUsingRegEx($requestedURL);
    	if (!empty($regexPermalink)) {
    		$this->dao->logRedirectHit($regexPermalink['matching_regex'], $regexPermalink['link'], 'regex match',
    			$requestedURL);
    		$sentTo404Page = $this->logic->forceRedirect($regexPermalink['link'], 
    			esc_html($options['default_redirect']), $regexPermalink['type'],
    			$requestedURL);
    		if ($sentTo404Page) {
    			return true;
    		}
    		exit;
    	}
    	return false;
    }
    
	/**
	 * @param options
	 */
    function logAReallyLongDebugMessage($options, $requestedURL, $redirect) {
	 	
        $debugOptionsMsg = esc_html('auto_redirects: ' . $options['auto_redirects'] . ', auto_score: ' . 
                $options['auto_score'] . ', template_redirect_priority: ' . $options['template_redirect_priority'] .
                ', auto_cats: ' . $options['auto_cats'] . ', auto_tags: ' .
                $options['auto_tags'] . ', dest404page: ' . $options['dest404page']);

        $remoteAddress = esc_sql($_SERVER['REMOTE_ADDR']);
        if (!array_key_exists('log_raw_ips', $options) || $options['log_raw_ips'] != '1') {
        	$remoteAddress = $this->f->md5lastOctet($remoteAddress);
        }
        
        $httpUserAgent = "";
        if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
        	$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
        }

        $debugServerMsg = esc_html('HTTP_USER_AGENT: ' . $httpUserAgent . ', REMOTE_ADDR: ' . 
                $remoteAddress . ', REQUEST_URI: ' . urldecode($_SERVER['REQUEST_URI']));
        $this->logger->debugMessage("Processing 404 for URL: " . $requestedURL . " | Redirect: " .
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
     * @return boolean true if the user is sent to the default 404 page.
     */
    function processRedirect($requestedURL, $redirect, $matchReason) {

        if (( $redirect['status'] != ABJ404_STATUS_MANUAL && $redirect['status'] != ABJ404_STATUS_AUTO ) || $redirect['disabled'] != 0) {
            // It's a redirect that has been deleted, ignored, or captured.
            $this->logger->errorMessage("processRedirect() was called with bad redirect data. Data: " .
                    wp_kses_post(print_r($redirect, true)));
        }

        if ($redirect['type'] == ABJ404_TYPE_EXTERNAL) {
        	$this->dao->logRedirectHit($redirect['url'], $redirect['final_dest'], 'external');
            $this->logic->forceRedirect($redirect['final_dest'], esc_html($redirect['code']));
            exit;
        }

        $key = $redirect['final_dest'] . "|" . $redirect['type'];
        $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($key, 0);

        // log only the path part of the URL
        $redirectedTo = esc_url($permalink['link']);
        $urlParts = parse_url($redirectedTo);
        if (array_key_exists('path', $urlParts)) {
        	$redirectedTo = $urlParts['path'];
        }
            
        $this->dao->logRedirectHit($redirect['url'], $redirectedTo, $matchReason);
        $sendTo404Page = $this->logic->forceRedirect($permalink['link'], esc_html($redirect['code']));
        
        if ($sendTo404Page) {
        	return;
        }
        exit;
    }

    /** Display an admin dashboard notification.
     * e.g. There are 29 captured 404 URLs to be processed.
     * @global type $pagenow
     * @global type $abj404dao
     * @global type $abj404logic
     * @global type $abj404view
     */
    static function echoDashboardNotification() {
        $instance = self::getInstance();

        if (!is_admin() || !$instance->logic->userIsPluginAdmin()) {
            $instance->logger->logUserCapabilities("echoDashboardNotification");
            return;
        }

        global $pagenow;
        global $abj404view;

        if ($instance->logic->userIsPluginAdmin()) {
            if ( (array_key_exists('page', $_GET) && $_GET['page'] == ABJ404_PP) ||
                 ($pagenow == 'index.php' && !isset($_GET['page'])) ) {
                $captured404Count = $instance->dao->getCapturedCountForNotification();
                if ($instance->logic->shouldNotifyAboutCaptured404s($captured404Count)) {
                    $msg = $abj404view->getDashboardNotificationCaptured($captured404Count);
                    echo $msg;
                }
            }
        }
    }

    /** Adds a link under the "Settings" link to the plugin page.
     * @global string $menu
     * @global type $abj404dao
     * @global type $abj404logic
     * @global type $abj404logging
     */
    static function addMainSettingsPageLink() {
        global $menu;
        $instance = self::getInstance();

        if (!is_admin() || !$instance->logic->userIsPluginAdmin()) {
            $instance->logger->logUserCapabilities("addMainSettingsPageLink");
            return;
        }

        $options = $instance->logic->getOptions();
        $pageName = "404 Solution";

        // Admin notice
        if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
            $captured = $instance->dao->getCapturedCountForNotification();
            if (isset($options['admin_notification']) && $captured >= $options['admin_notification']) {
                $pageName .= " <span class='update-plugins count-1'><span class='update-count'>" . esc_html($captured) . "</span></span>";
                $pos = $instance->f->strpos($menu[80][0], 'update-plugins');
                if ($pos === false) {
                    $menu[80][0] = $menu[80][0] . " <span class='update-plugins count-1'><span class='update-count'>1</span></span>";
                }
            }
        }

        if (isset($options['menuLocation']) &&
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
