<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_WordPress_Connector {

	private static $instance = null;
    private const REVIEW_INITIAL_DELAY_DAYS = 30;
    private const REVIEW_ASK_LATER_DELAY_DAYS = 7;
    private const REVIEW_CLOSE_X_SNOOZE_DAYS = 14;

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

	/** @var ABJ_404_Solution_FrontendRequestPipeline|null */
	private $frontendPipeline = null;

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

	/** @return ABJ_404_Solution_FrontendRequestPipeline */
	private function getFrontendPipeline() {
		if ($this->frontendPipeline !== null) {
			return $this->frontendPipeline;
		}

		if (!class_exists('ABJ_404_Solution_FrontendRequestPipeline')) {
			require_once dirname(__FILE__) . '/FrontendRequestPipeline.php';
		}

		$this->frontendPipeline = new ABJ_404_Solution_FrontendRequestPipeline(
			$this->logic,
			$this->dao,
			$this->logger,
			$this->f,
			$this->spellChecker
		);
		return $this->frontendPipeline;
	}

	public static function getInstance() {
		if (self::$instance !== null) {
			return self::$instance;
		}

		// If the DI container is initialized, prefer it.
		if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
			try {
				$c = ABJ_404_Solution_ServiceContainer::getInstance();
				if (is_object($c) && method_exists($c, 'has') && $c->has('wordpress_connector')) {
					self::$instance = $c->get('wordpress_connector');
					return self::$instance;
				}
			} catch (Throwable $e) {
				// fall back
			}
		}

		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_WordPress_Connector();
		}

		return self::$instance;
	}
	
	/** Setup. */
    static function init() {
        self::registerLifecycleHooks();
        self::registerAdminHooks();
        self::registerAsyncSuggestionHooks();
        ABJ_404_Solution_PluginLogic::doRegisterCrons();
    }

    private static function registerLifecycleHooks() {
        if (!is_admin()) {
            return;
        }

        register_deactivation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::runOnPluginDeactivation');
        register_activation_hook(ABJ404_NAME, 'ABJ_404_Solution_PluginLogic::runOnPluginActivation');

        if (is_multisite()) {
            add_action('wpmu_new_blog', 'ABJ_404_Solution_PluginLogic::activateNewSite', 10, 6);
            add_action('wp_initialize_site', 'ABJ_404_Solution_PluginLogic::activateNewSiteModern', 10, 2);
            add_action('delete_blog', 'ABJ_404_Solution_PluginLogic::deleteBlogData', 10, 2);
        }
    }

    private static function registerAdminHooks() {
        if (!is_admin()) {
            return;
        }

        add_filter("plugin_action_links_" . ABJ404_NAME,
            'ABJ_404_Solution_WordPress_Connector::addSettingsLinkToPluginPage');
        add_action('admin_notices',
            'ABJ_404_Solution_WordPress_Connector::echoDashboardNotification');
        add_action('admin_menu',
            'ABJ_404_Solution_WordPress_Connector::addMainSettingsPageLink');
        add_action('admin_enqueue_scripts',
            'ABJ_404_Solution_WordPress_Connector::add_scripts', 11);
        add_action('admin_head',
            'ABJ_404_Solution_WordPress_Connector::outputCriticalThemeCSS', 1);

        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_echoViewLogsFor', 'ABJ_404_Solution_Ajax_Php::echoViewLogsFor');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_trashLink', 'ABJ_404_Solution_Ajax_TrashLink::trashAction');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_echoRedirectToPages', 'ABJ_404_Solution_Ajax_Php::echoRedirectToPages');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_updateOptions', 'ABJ_404_Solution_Ajax_Php::updateOptions');

        ABJ_404_Solution_Ajax_SettingsModeToggle::init();
        ABJ_404_Solution_UninstallModal::init();
        ABJ_404_Solution_SetupWizard::init();
        if (class_exists('ABJ_404_Solution_Privacy')) {
            ABJ_404_Solution_Privacy::init();
        }
    }

    private static function registerAsyncSuggestionHooks() {
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_compute_suggestions', 'ABJ_404_Solution_Ajax_SuggestionCompute::computeSuggestions');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_nopriv_abj404_compute_suggestions', 'ABJ_404_Solution_Ajax_SuggestionCompute::computeSuggestions');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_poll_suggestions', 'ABJ_404_Solution_Ajax_SuggestionPolling::pollSuggestions');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_nopriv_abj404_poll_suggestions', 'ABJ_404_Solution_Ajax_SuggestionPolling::pollSuggestions');
    }

    /** Include things necessary for ajax. */
    static function add_scripts($hook) {
        // only load this stuff for this plugin. 
        // thanks to https://pippinsplugins.com/loading-scripts-correctly-in-the-wordpress-admin/
        if (!array_key_exists('abj404_settingsPageName', $GLOBALS) ||
                $hook != $GLOBALS['abj404_settingsPageName']) {
            return;
        }

        $subpage = '';
        if (array_key_exists('subpage', $_GET)) {
            $subpage = sanitize_text_field(self::normalizeRequestScalar($_GET['subpage']));
        }
        // Default plugin landing is redirects when subpage is not specified.
        if ($subpage === '') {
            $subpage = 'abj404_redirects';
        }

        $isOptionsPage = ($subpage === 'abj404_options');
        $isStatsPage = ($subpage === 'abj404_stats');
        $isCardAccordionPage = in_array($subpage, array('abj404_options', 'abj404_tools', 'abj404_stats'), true);
        $isLogsPage = ($subpage === 'abj404_logs');
        $isListPage = in_array($subpage, array('abj404_redirects', 'abj404_captured', 'abj404_logs'), true);
        $needsDestinationAutocomplete = in_array($subpage, array('abj404_redirects', 'abj404_captured', 'abj404_options'), true);

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
		ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('jquery-color');
        
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
        if ($needsDestinationAutocomplete) {
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-redirect_to_ajax');
            wp_localize_script('abj404-exclude_pages_ajax', 'abj404localization', $translation_array );
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-exclude_pages_ajax');
        }
        
        // make sure the "apply" button is only enabled if at least one checkbox is selected
        wp_register_script('abj404-enable_disable_apply_button_js', 
                ABJ404_URL . 'includes/js/enableDisableApplyButton.js');
        $translation_array = array('{altText}' => __('Choose at least one URL', '404-solution'));
        wp_localize_script('abj404-enable_disable_apply_button_js', 'abj404localization', $translation_array);
        if ($isListPage) {
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-enable_disable_apply_button_js');
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-trash_link_ajax', plugin_dir_url(__FILE__) . 'ajax/trash_link_ajax.js',
                    array('jquery'));
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-table-interactions', plugin_dir_url(__FILE__) . 'js/tableInteractions.js',
                    array('jquery'));

            // Localized strings for time-ago display
            wp_localize_script('abj404-table-interactions', 'abj404_time_ago', array(
                'second'  => __('second', '404-solution'),
                'seconds' => __('seconds', '404-solution'),
                'minute'  => __('minute', '404-solution'),
                'minutes' => __('minutes', '404-solution'),
                'hour'    => __('hour', '404-solution'),
                'hours'   => __('hours', '404-solution'),
                'day'     => __('day', '404-solution'),
                'days'    => __('days', '404-solution'),
                'ago'     => __('ago', '404-solution'),
            ));
        }

        if ($isListPage || $isStatsPage) {
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-view-updater', plugin_dir_url(__FILE__) . 'ajax/view_updater.js',
                    array('jquery', 'jquery-ui-autocomplete'));
        }

        if ($isLogsPage) {
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-search_logs_ajax', plugin_dir_url(__FILE__) . 'ajax/search_logs_ajax.js',
                array('jquery', 'jquery-ui-autocomplete'));
        }

        if ($isOptionsPage) {
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-general-js', plugin_dir_url(__FILE__) . 'js/general.js',
                array('jquery'));

            // Localize general.js strings for translation
            wp_localize_script('abj404-general-js', 'abj404General', array(
                'savingSettings' => __('Saving settings...', '404-solution'),
            ));

            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-theme-preview', plugin_dir_url(__FILE__) . 'js/themePreview.js',
                array('jquery'));

            // Settings mode toggle (Simple/Advanced)
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-settings-mode-toggle', plugin_dir_url(__FILE__) . 'ajax/SettingsModeToggle.js',
                array('jquery'));
        }

        if ($isCardAccordionPage) {
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-options-accordion', plugin_dir_url(__FILE__) . 'js/optionsAccordion.js',
                array('jquery'));

            // Localize accordion strings for translation
            wp_localize_script('abj404-options-accordion', 'abj404Accordion', array(
                'expandAll' => __('Expand All', '404-solution'),
                'collapseAll' => __('Collapse All', '404-solution'),
            ));
        }

        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt(
            'abj404-review-feedback',
            plugin_dir_url(__FILE__) . 'js/reviewFeedback.js',
            array()
        );

        ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-styles', ABJ404_URL . 'includes/html/404solutionStyles.css',
                null);
        ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-themes', ABJ404_URL . 'includes/html/adminThemes.css',
                null);

        // Load RTL styles for Arabic, Hebrew, and other right-to-left languages
        if (is_rtl()) {
            ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-rtl', ABJ404_URL . 'includes/html/404solutionStyles-rtl.css',
                    array('abj404solution-styles'));
        }
    }

    /** Detect if dark mode is enabled from various sources.
     * Checks WordPress admin color scheme, dark mode plugins, and browser preference.
     *
     * @return bool True if dark mode is detected, false otherwise
     */
    static function isDarkModeDetected() {
        // Check WordPress admin color scheme
        $current_user_id = get_current_user_id();
        if ($current_user_id) {
            $admin_color = get_user_meta($current_user_id, 'admin_color', true);
            // WordPress dark color schemes: midnight, ectoplasm, coffee
            $dark_schemes = array('midnight', 'ectoplasm', 'coffee');
            if (in_array($admin_color, $dark_schemes)) {
                return true;
            }
        }

        // Check for popular dark mode plugins
        // WP Dark Mode plugin
        if (get_option('wp_dark_mode_enabled')) {
            return true;
        }

        // Dark Mode for WP Dashboard plugin
        if (get_option('dark_mode_for_wp_dashboard_enabled')) {
            return true;
        }

        // Check if any dark mode plugin class exists
        if (class_exists('WP_Dark_Mode') || class_exists('Dark_Mode_For_WP_Dashboard')) {
            return true;
        }

        // Browser/OS preference will be checked via JavaScript
        return false;
    }

    /** Get the auto-selected theme based on dark mode detection.
     *
     * @return string The theme to use ('obsidian' for dark mode, 'default' otherwise)
     */
    static function getAutoSelectedTheme() {
        if (self::isDarkModeDetected()) {
            // Default to obsidian for dark mode (can be changed to 'neon' if preferred)
            return 'obsidian';
        }
        return 'default';
    }

    /** Output critical theme CSS inline to prevent FOUC (Flash of Unstyled Content).
     * This outputs the CSS variables for the selected theme directly in the <head>
     * before any external CSS files load, eliminating the flash when a custom theme is selected.
     *
     * Additionally, this sets the data-theme attribute on both HTML and body elements
     * via a synchronous script, ensuring the attribute exists before CSS is parsed.
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

        // Check if auto dark mode detection is enabled (default: enabled)
        $auto_dark_mode = !isset($options['disable_auto_dark_mode']) || $options['disable_auto_dark_mode'] != '1';

        // If theme is 'default' and auto dark mode is enabled, check for dark mode
        if ($theme === 'default' && $auto_dark_mode) {
            $theme = self::getAutoSelectedTheme();
        }

        // Sanitize theme value - only allow specific values
        $allowed_themes = array('default', 'calm', 'mono', 'neon', 'obsidian');
        if (!in_array($theme, $allowed_themes)) {
            $theme = 'default';
        }

        // For 'default' theme, don't set data-theme attribute
        // This respects WordPress admin color scheme (default/Fresh is light)
        // and avoids overriding it with browser dark mode preference
        if ($theme === 'default') {
            // No theme CSS needed for default - use WordPress default styling
            // Ensure no data-theme attribute is set
            $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/themeRemoverScript.html");
            echo $html;
            return;
        }

        // Output synchronous script to set data-theme attributes immediately
        // This MUST run before CSS is parsed to prevent flash
        // Setting on html immediately, and body as soon as it's available
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/themeSetterScript.html");
        $f = ABJ_404_Solution_Functions::getInstance();
        $html = $f->str_replace('{theme}', esc_js($theme), $html);
        echo $html;

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
            // Build CSS variables string
            $cssVars = '';
            foreach ($themeVariables[$theme] as $var => $value) {
                $cssVars .= esc_html($var) . ':' . esc_html($value) . ';';
            }

            // Load template and replace placeholder
            $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/criticalThemeCSS.html");
            $f = ABJ_404_Solution_Functions::getInstance();
            $html = $f->str_replace('{css_variables}', $cssVars, $html);
            echo $html;
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
                __('Settings', '404-solution') . '</a>';
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
        $this->getFrontendPipeline()->processRedirectAllRequests();
    }
    /**
     * Process the 404s
     */
    function process404() {
        return $this->getFrontendPipeline()->process404();
    }

    /** 
     * 
     * @param $options
     * @param $requestedURL
     * @return boolean true if the user is sent to the default 404 page.
     */
    function tryRegexRedirect($options, $requestedURL) {
        return $this->getFrontendPipeline()->tryRegexRedirect($options, $requestedURL);
    }
    
	/**
	 * @param options
	 */
    function logAReallyLongDebugMessage($options, $requestedURL, $redirect) {
        $this->getFrontendPipeline()->logAReallyLongDebugMessage($options, $requestedURL, $redirect);
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
        return $this->getFrontendPipeline()->processRedirect($requestedURL, $redirect, $matchReason);
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

                // Show review request after 7 days of use
                self::maybeShowReviewRequest();
            }
        }
    }

    /** Display a review request notification after a sustained period of plugin use.
     * Uses a qualification question to ensure only satisfied users are directed to leave reviews.
     * Unhappy users are directed to provide feedback instead.
     *
     * Guarantees:
     * - Never shows again after user clicks "Never ask again"
     * - Never shows again after user clicks review link button
     * - Never shows again after user submits feedback
     * - Shows again in 7 days after "Ask again later"
     * - Shows again in 14 days after close "X"
     */
    static function maybeShowReviewRequest() {
        // Only show on 404 Solution plugin pages
        if (!isset($_GET['page']) || $_GET['page'] !== ABJ404_PP) {
            return;
        }

        // Check if user permanently dismissed this
        $dismissed = get_user_meta(get_current_user_id(), 'abj404_review_dismissed', true);
        if ($dismissed === 'permanent') {
            return;
        }

        // Check if user asked to be reminded later
        $remind_later = get_user_meta(get_current_user_id(), 'abj404_review_remind_later', true);
        if ($remind_later && time() < $remind_later) {
            // Not time yet to remind
            return;
        }

        // Get plugin installation/activation time
        $installed_time = get_option('abj404_installed_time');
        if (!$installed_time) {
            // First time - record installation time
            $installed_time = time();
            update_option('abj404_installed_time', $installed_time);
            return;
        }

        // Show review request after enough real usage time has passed.
        $days_installed = (time() - $installed_time) / 86400;
        if ($days_installed < self::REVIEW_INITIAL_DELAY_DAYS) {
            return;
        }

        // Handle user responses to qualification question
        if (isset($_GET['abj404_review_response'])) {
            $rawResponseNonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
            $responseNonce = sanitize_text_field(self::normalizeRequestScalar($rawResponseNonce));
            if ($responseNonce === '' || !wp_verify_nonce($responseNonce, 'abj404_review_response')) {
                return;
            }

            $response = sanitize_text_field(self::normalizeRequestScalar($_GET['abj404_review_response']));
            $allowedResponses = array('yes', 'not_yet', 'ask_later', 'close_x', 'never');
            if (!in_array($response, $allowedResponses, true)) {
                return;
            }

            if ($response === 'yes') {
                // User thinks it deserves 5 stars - show review link
                update_user_meta(get_current_user_id(), 'abj404_review_step', 'show_review_link');
                delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');
            } elseif ($response === 'not_yet') {
                // User doesn't think it deserves 5 stars - show feedback form
                update_user_meta(get_current_user_id(), 'abj404_review_step', 'show_feedback');
                delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');
            } elseif ($response === 'ask_later') {
                // User wants to be reminded in 7 days
                update_user_meta(get_current_user_id(), 'abj404_review_remind_later', time() + (self::REVIEW_ASK_LATER_DELAY_DAYS * 86400));
                delete_user_meta(get_current_user_id(), 'abj404_review_step');
            } elseif ($response === 'close_x') {
                // Close button snoozes this prompt for at least two weeks.
                update_user_meta(get_current_user_id(), 'abj404_review_remind_later', time() + (self::REVIEW_CLOSE_X_SNOOZE_DAYS * 86400));
                delete_user_meta(get_current_user_id(), 'abj404_review_step');
            } elseif ($response === 'never') {
                // User never wants to see this - PERMANENT dismissal
                update_user_meta(get_current_user_id(), 'abj404_review_dismissed', 'permanent');
                delete_user_meta(get_current_user_id(), 'abj404_review_step');
                delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');
            }

            // Redirect to remove query parameter and show the appropriate notice
            wp_safe_redirect(remove_query_arg(array('abj404_review_response', '_wpnonce')));
            exit;
        }

        // Handle "Going to review now" button click - PERMANENT dismissal
        if (isset($_GET['abj404_leaving_review'])) {
            $rawLeavingReviewNonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
            $leavingReviewNonce = sanitize_text_field(self::normalizeRequestScalar($rawLeavingReviewNonce));
            if ($leavingReviewNonce !== '' && wp_verify_nonce($leavingReviewNonce, 'abj404_leaving_review')) {
                update_user_meta(get_current_user_id(), 'abj404_review_dismissed', 'permanent');
                delete_user_meta(get_current_user_id(), 'abj404_review_step');
                delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');

                // Open review page in new tab and redirect current page to clean URL
                $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/reviewRedirectScript.html");
                $f = ABJ_404_Solution_Functions::getInstance();
                $html = $f->str_replace('{review_url}', esc_js('https://wordpress.org/support/plugin/404-solution/reviews/#new-post'), $html);
                echo $html;
                wp_safe_redirect(remove_query_arg(array('abj404_leaving_review', '_wpnonce')));
                exit;
            }
        }

        // Handle feedback submission - PERMANENT dismissal
        $rawFeedbackNonce = isset($_POST['abj404_feedback_nonce']) ? $_POST['abj404_feedback_nonce'] : '';
        $feedbackNonce = sanitize_text_field(self::normalizeRequestScalar($rawFeedbackNonce));
        if (isset($_POST['abj404_submit_feedback']) &&
            $feedbackNonce !== '' &&
            wp_verify_nonce($feedbackNonce, 'abj404_submit_feedback')) {

            // Get selected issues (checkboxes) and normalize malformed inputs safely.
            $issuesRaw = isset($_POST['feedback_issues']) ? $_POST['feedback_issues'] : array();
            $issues = self::sanitizeFeedbackIssues($issuesRaw);

            $feedbackDetailsRaw = isset($_POST['feedback_details']) ? $_POST['feedback_details'] : '';
            $feedback_details = sanitize_textarea_field(self::normalizeRequestScalar($feedbackDetailsRaw));

            // Prepare feedback data
            $feedback_data = array(
                'timestamp' => current_time('mysql'),
                'user_id' => get_current_user_id(),
                'site_url' => get_site_url(),
                'issues' => $issues,
                'details' => $feedback_details,
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => ABJ404_VERSION,
                'php_version' => PHP_VERSION
            );

            // Store feedback in database
            $all_feedback = get_option('abj404_user_feedback', array());
            $all_feedback[] = $feedback_data;
            update_option('abj404_user_feedback', $all_feedback);

            // Email feedback to plugin author
            self::emailFeedback($feedback_data);

            // PERMANENT dismissal - never show again
            update_user_meta(get_current_user_id(), 'abj404_review_dismissed', 'permanent');
            delete_user_meta(get_current_user_id(), 'abj404_review_step');
            delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');

            // Show thank you message
            $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/feedbackSuccessNotice.html");
            echo $html;
            return;
        }

        // Check what step we're on
        $review_step = get_user_meta(get_current_user_id(), 'abj404_review_step', true);

        if ($review_step === 'show_review_link') {
            // Step 2a: User said YES - show review link
            self::showReviewLinkNotice();
        } elseif ($review_step === 'show_feedback') {
            // Step 2b: User said NOT YET - show feedback form
            self::showFeedbackFormNotice();
        } else {
            // Step 1: Initial qualification question
            self::showQualificationQuestion();
        }
    }

    /** Email feedback to plugin author */
    private static function emailFeedback($feedback_data) {
        $to = '404solution@ajexperience.com';
        $subject = '404 Solution Feedback from ' . get_bloginfo('name');

        $message = "New feedback received from 404 Solution plugin\n\n";
        $message .= "Site: " . $feedback_data['site_url'] . "\n";
        $message .= "Date: " . $feedback_data['timestamp'] . "\n";
        $message .= "WordPress Version: " . $feedback_data['wp_version'] . "\n";
        $message .= "Plugin Version: " . $feedback_data['plugin_version'] . "\n";
        $message .= "PHP Version: " . $feedback_data['php_version'] . "\n\n";

        $message .= "Issues Selected:\n";
        if (!empty($feedback_data['issues'])) {
            foreach ($feedback_data['issues'] as $issue) {
                $message .= "  - " . ucfirst(str_replace('_', ' ', $issue)) . "\n";
            }
        } else {
            $message .= "  None selected\n";
        }

        $message .= "\nAdditional Details:\n";
        $message .= $feedback_data['details'] ? $feedback_data['details'] : "(No additional details provided)\n";

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        wp_mail($to, $subject, $message, $headers);
    }

    /** Step 1: Show the initial qualification question */
    private static function showQualificationQuestion() {
        $yes_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'yes'),
            'abj404_review_response'
        );
        $not_yet_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'not_yet'),
            'abj404_review_response'
        );
        $ask_later_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'ask_later'),
            'abj404_review_response'
        );
        $never_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'never'),
            'abj404_review_response'
        );
        $close_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'close_x'),
            'abj404_review_response'
        );

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/reviewQualificationQuestion.html");
        $f = ABJ_404_Solution_Functions::getInstance();
        $html = $f->str_replace('{yes_url}', esc_attr($yes_url), $html);
        $html = $f->str_replace('{not_yet_url}', esc_attr($not_yet_url), $html);
        $html = $f->str_replace('{ask_later_url}', esc_attr($ask_later_url), $html);
        $html = $f->str_replace('{never_url}', esc_attr($never_url), $html);
        $html = $f->str_replace('{close_url}', esc_attr($close_url), $html);
        echo $html;
    }

    /** Step 2a: User said YES - show review link and thank you */
    private static function showReviewLinkNotice() {
        // URL that marks as done when they click to go leave review
        $review_link_url = wp_nonce_url(
            add_query_arg('abj404_leaving_review', '1'),
            'abj404_leaving_review'
        );

        $never_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'never'),
            'abj404_review_response'
        );
        $close_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'close_x'),
            'abj404_review_response'
        );

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/reviewLinkNotice.html");
        $f = ABJ_404_Solution_Functions::getInstance();
        $html = $f->str_replace('{review_link_url}', esc_attr($review_link_url), $html);
        $html = $f->str_replace('{never_url}', esc_attr($never_url), $html);
        $html = $f->str_replace('{close_url}', esc_attr($close_url), $html);
        echo $html;
    }

    /** Step 2b: User said NOT YET - show feedback form */
    private static function showFeedbackFormNotice() {
        $never_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'never'),
            'abj404_review_response'
        );
        $close_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'close_x'),
            'abj404_review_response'
        );

        // Get nonce field HTML
        ob_start();
        wp_nonce_field('abj404_submit_feedback', 'abj404_feedback_nonce');
        $nonce_field = ob_get_clean();

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/feedbackFormNotice.html");
        $f = ABJ_404_Solution_Functions::getInstance();
        $html = $f->str_replace('{nonce_field}', $nonce_field, $html);
        $html = $f->str_replace('{never_url}', esc_attr($never_url), $html);
        $html = $f->str_replace('{close_url}', esc_attr($close_url), $html);
        echo $html;
    }

    /**
     * Safely unslash request data when wp_unslash exists and is callable.
     * Some test environments report wp_unslash as existing but throw when called.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function safeWpUnslash($value) {
        if (!function_exists('wp_unslash')) {
            return $value;
        }

        try {
            return wp_unslash($value);
        } catch (Throwable $e) {
            return $value;
        }
    }

    /**
     * Normalize request input to a scalar string to avoid warnings when arrays/objects are passed.
     *
     * @param mixed $value
     * @return string
     */
    private static function normalizeRequestScalar($value) {
        $value = self::safeWpUnslash($value);
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return (string)$value;
    }

    /**
     * Normalize and sanitize feedback issue selections from request data.
     *
     * @param mixed $issuesRaw
     * @return array<int, string>
     */
    private static function sanitizeFeedbackIssues($issuesRaw) {
        $issuesRaw = self::safeWpUnslash($issuesRaw);
        if (!is_array($issuesRaw)) {
            $issuesRaw = array($issuesRaw);
        }

        $issues = array();
        foreach ($issuesRaw as $issue) {
            if (is_array($issue) || is_object($issue)) {
                continue;
            }
            $clean = sanitize_text_field((string)$issue);
            if ($clean !== '') {
                $issues[] = $clean;
            }
        }
        return $issues;
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
