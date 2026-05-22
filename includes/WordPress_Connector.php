<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_WordPress_Connector {

	/** @var self|null */
	private static $instance = null;

    /** @var array<int, string> */
    private static $adminRuntimeErrors = array();

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

		/** @var ABJ_404_Solution_RedirectsRepository */ private $redirectsRepository;

		/** @var mixed */ private $logsRepository;

		/** @var mixed */ private $statsRepository;

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
	 * @param ABJ_404_Solution_RedirectsRepository|null $redirectsRepository Redirects repository
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 * @param ABJ_404_Solution_Functions|null $functions String utilities
	 * @param ABJ_404_Solution_SpellChecker|null $spellChecker Spell checker service
	 * @param mixed|null $logsRepository Log writer
	 * @param mixed|null $statsRepository Stats reader
	 */
	public function __construct($pluginLogic = null, $redirectsRepository = null, $logging = null, $functions = null, $spellChecker = null, $logsRepository = null, $statsRepository = null) {
		$this->logic = $pluginLogic !== null ? $pluginLogic : abj_service('plugin_logic');
		$this->redirectsRepository = $redirectsRepository !== null ? $redirectsRepository : abj_service('redirects_repository');
		$this->logger = $logging !== null ? $logging : abj_service('logging');
		$this->f = $functions !== null ? $functions : abj_service('functions');
		$this->spellChecker = $spellChecker !== null ? $spellChecker : abj_service('spell_checker');
		$this->logsRepository = $logsRepository !== null ? $logsRepository :
			(is_object($redirectsRepository) && method_exists($redirectsRepository, 'logRedirectHit') ? $redirectsRepository : abj_service('logs_repository'));
		$this->statsRepository = $statsRepository !== null ? $statsRepository :
			(is_object($redirectsRepository) && method_exists($redirectsRepository, 'getCapturedCountForNotification') ? $redirectsRepository : abj_service('stats_repository'));
	}

	/** @return ABJ_404_Solution_FrontendRequestPipeline */
	private function getFrontendPipeline() {
		if ($this->frontendPipeline !== null) {
			return $this->frontendPipeline;
		}

		if (!class_exists('ABJ_404_Solution_FrontendRequestPipeline')) {
			require_once dirname(__FILE__) . '/FrontendRequestPipeline.php';
		}

		$matchingEngines = [];
		if (class_exists('ABJ_404_Solution_ServiceContainer')) {
			$engines = ABJ_404_Solution_ServiceContainer::safeGet('matching_engines');
			if (is_array($engines)) {
				$matchingEngines = $engines;
			}
		}

		$this->frontendPipeline = new ABJ_404_Solution_FrontendRequestPipeline(
			$this->logic,
			$this->redirectsRepository,
			$this->logger,
			$this->f,
			$this->spellChecker,
			$matchingEngines,
			$this->logsRepository
		);
		return $this->frontendPipeline;
	}

	public function getCapturedCountForNotification(): int {
		if (!is_object($this->statsRepository) || !method_exists($this->statsRepository, 'getCapturedCountForNotification')) { return 0; } try { return (int)call_user_func(array($this->statsRepository, 'getCapturedCountForNotification')); } catch (Throwable $e) {
			if (is_object($this->logger) && method_exists($this->logger, 'errorMessage')) { $this->logger->errorMessage('Captured-count notification lookup failed: ' . $e->getMessage(), $e instanceof Exception ? $e : null); } else { error_log('404 Solution: Captured-count notification lookup failed: ' . $e->getMessage()); } return 0; }
	}

	/** @return ABJ_404_Solution_PluginLogic */
	public function getPluginLogic() {
		return $this->logic;
	}

	/** @return ABJ_404_Solution_Logging */
	public function getLogger() {
		return $this->logger;
	}

	/** @return self */
	public static function getInstance() {
		if (self::$instance !== null) {
			return self::$instance;
		}

		// If the DI container is initialized, prefer it.
		if (class_exists('ABJ_404_Solution_ServiceContainer')) {
			$svc = ABJ_404_Solution_ServiceContainer::safeGet('wordpress_connector');
			if ($svc instanceof self) {
				self::$instance = $svc;
				return self::$instance;
			}
		}

		self::$instance = new ABJ_404_Solution_WordPress_Connector();

		return self::$instance;
	}

    /**
     * Persist and queue an admin runtime error so users see a notice instead of a blank page.
     *
     * @param string $hookName
     * @param Throwable $e
     * @return void
     */
    public static function reportAdminRuntimeError(string $hookName, Throwable $e): void {
        $summary = sprintf('[%s] %s', $hookName, $e->getMessage());
        self::$adminRuntimeErrors[] = $summary;

        try {
            $logger = abj_service('logging');
            $logger->errorMessage('Admin runtime exception in ' . $hookName . ': ' . $e->getMessage());
        } catch (Throwable $ignored) {
            // Last-resort logging fallback.
            @error_log('404 Solution admin runtime exception in ' . $hookName . ': ' . $e->getMessage());
        }

        if (function_exists('set_transient')) {
            // allow-cache-empty: runtime-error notice summary is generated locally and intentionally persisted as-is.
            set_transient('abj404_admin_runtime_error', $summary, 300);
        }
    }

    /**
     * Echo one-time admin runtime errors captured from earlier hooks in this request (or previous request).
     *
     * @return void
     */
    public static function echoAdminRuntimeErrorNotice(): void {
        $errors = self::$adminRuntimeErrors;
        self::$adminRuntimeErrors = array();

        if (function_exists('get_transient')) {
            $saved = get_transient('abj404_admin_runtime_error');
            if (is_string($saved) && $saved !== '') {
                $errors[] = $saved;
                delete_transient('abj404_admin_runtime_error');
            }
        }

        if (empty($errors)) {
            return;
        }

        $message = implode("\n", array_unique(array_filter($errors)));
        echo '<div class="notice notice-error"><p><strong>404 Solution:</strong> ';
        echo esc_html__('An internal error occurred while loading this admin page.', '404-solution');
        echo '</p><details><summary>' . esc_html__('Show details', '404-solution') . '</summary><pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">';
        echo esc_html($message);
        echo '</pre></details></div>';
    }
	
	/** Setup.
	 * @return void
	 */
    static function init() {
        self::registerLifecycleHooks();
        self::registerAdminHooks();
        self::registerAsyncSuggestionHooks();
        ABJ_404_Solution_PluginLogic::doRegisterCrons();
    }

    /** @return void */
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

    /** @return void */
    private static function registerAdminHooks() {
        if (!is_admin()) {
            return;
        }

        add_filter("plugin_action_links_" . ABJ404_NAME,
            'ABJ_404_Solution_WordPress_Connector::addSettingsLinkToPluginPage');
        add_filter('plugin_row_meta',
            'ABJ_404_Solution_WordPress_Connector::addPluginRowMeta', 10, 2);
        add_action('admin_notices',
            'ABJ_404_Solution_ReviewFeedback::echoDashboardNotification');
        add_action('admin_init',
            'ABJ_404_Solution_ReviewFeedback::handleResponseRedirects');
        add_action('admin_menu',
            'ABJ_404_Solution_WordPress_Connector::addMainSettingsPageLink');
        add_action('admin_enqueue_scripts',
            'ABJ_404_Solution_WordPress_Connector::add_scripts', 11);
        add_action('admin_enqueue_scripts',
            'ABJ_404_Solution_WordPress_Connector::enqueueSupportRequestAssetsOnPluginsPage', 11);
        add_action('admin_head',
            'ABJ_404_Solution_AdminThemeManager::outputCriticalThemeCSS', 1);

        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_echoViewLogsFor', 'ABJ_404_Solution_Ajax_Php::echoViewLogsFor');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_trashLink', 'ABJ_404_Solution_Ajax_TrashLink::trashAction');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_echoRedirectToPages', 'ABJ_404_Solution_Ajax_Php::echoRedirectToPages');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_updateOptions', 'ABJ_404_Solution_Ajax_Php::updateOptions');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_load_gsc_section', 'ABJ_404_Solution_Ajax_Php::loadGscSection');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404getTrendData', 'ABJ_404_Solution_Ajax_TrendData::echoTrendData');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_crossPluginPreview', 'ABJ_404_Solution_Ajax_CrossPluginImporter::handlePreview');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_gsc_oauth_callback', 'ABJ_404_Solution_GscOAuthHandler::handleCallback');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_gsc_revoke', 'ABJ_404_Solution_GscOAuthHandler::handleRevoke');

        ABJ_404_Solution_Ajax_EngineProfiles::registerActions();
        ABJ_404_Solution_Ajax_SettingsModeToggle::init();
        ABJ_404_Solution_Ajax_RestoreDefaults::init();
        ABJ_404_Solution_Ajax_SupportRequest::init();
        ABJ_404_Solution_Ajax_SupportRequestPreview::init();
        ABJ_404_Solution_UninstallModal::init();
        ABJ_404_Solution_SetupWizard::init();
        if (class_exists('ABJ_404_Solution_Privacy')) {
            ABJ_404_Solution_Privacy::init();
        }
    }

    /** @return void */
    private static function registerAsyncSuggestionHooks() {
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_compute_suggestions', 'ABJ_404_Solution_Ajax_SuggestionCompute::computeSuggestions');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_nopriv_abj404_compute_suggestions', 'ABJ_404_Solution_Ajax_SuggestionCompute::computeSuggestions');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_abj404_poll_suggestions', 'ABJ_404_Solution_Ajax_SuggestionPolling::pollSuggestions');
        ABJ_404_Solution_WPUtils::safeAddAction('wp_ajax_nopriv_abj404_poll_suggestions', 'ABJ_404_Solution_Ajax_SuggestionPolling::pollSuggestions');
    }

    /** Include things necessary for ajax.
     * @param string $hook
     * @return void
     */
    static function add_scripts($hook) {
        // only load this stuff for this plugin. 
        // thanks to https://pippinsplugins.com/loading-scripts-correctly-in-the-wordpress-admin/
        if (!array_key_exists('abj404_settingsPageName', $GLOBALS) ||
                $hook != $GLOBALS['abj404_settingsPageName']) {
            return;
        }

        try {
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
            $isToolsPage = ($subpage === 'abj404_tools');
            $isCardAccordionPage = in_array($subpage, array('abj404_options', 'abj404_tools', 'abj404_stats'), true);
            $isLogsPage = ($subpage === 'abj404_logs');
            $isListPage = in_array($subpage, array('abj404_redirects', 'abj404_captured', 'abj404_logs'), true);
            $isEditPage = ($subpage === 'abj404_edit');
            $needsDestinationAutocomplete = in_array($subpage, array('abj404_redirects', 'abj404_captured', 'abj404_options', 'abj404_edit'), true);

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
        
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-admin-ajax',
            ABJ404_URL . 'includes/js/abj404-admin-ajax.js', array('jquery'));

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
        }
        // tableInteractions.js provides abj404ToggleRegexInfo() used on both list pages
        // and the Edit Redirect page (subpage=abj404_edit).
        if ($isListPage || $isEditPage) {
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
            self::enqueueViewUpdaterModules(plugin_dir_url(__FILE__) . 'ajax/');
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

            // Restore defaults (sticky save bar)
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-restore-defaults', plugin_dir_url(__FILE__) . 'ajax/RestoreDefaults.js',
                array('jquery'));

            // Behavior tiles (404 destination selector)
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-behavior-tiles', ABJ404_URL . 'includes/js/behaviorTiles.js',
                array());
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-settings-deferred', ABJ404_URL . 'includes/js/settingsDeferred.js',
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

        if ($isOptionsPage) {
            ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-engine-profiles',
                plugin_dir_url(__FILE__) . 'ajax/ajax-engine-profiles.js',
                array('jquery'));
            wp_localize_script('abj404-engine-profiles', 'abj404EngineProfiles', array(
                'nonce'   => wp_create_nonce('abj404_engine_profiles_nonce'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'i18n'    => array(
                    'edit'            => __('Edit', '404-solution'),
                    'delete'          => __('Delete', '404-solution'),
                    'addProfile'      => __('Add Engine Profile', '404-solution'),
                    'editProfile'     => __('Edit Engine Profile', '404-solution'),
                    'nameRequired'    => __('Profile name is required.', '404-solution'),
                    'patternRequired' => __('URL pattern is required.', '404-solution'),
                    'saved'           => __('Profile saved.', '404-solution'),
                    'saveFailed'      => __('Failed to save profile.', '404-solution'),
                    'confirmDelete'   => __('Delete this engine profile?', '404-solution'),
                ),
            ));
        }

        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt(
            'abj404-review-feedback',
            plugin_dir_url(__FILE__) . 'js/reviewFeedback.js',
            array()
        );

        self::registerSupportRequestAssets();

        ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-styles', ABJ404_URL . 'includes/html/404solutionStyles.css',
                array());
        ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-themes', ABJ404_URL . 'includes/html/adminThemes.css',
                array());

            // Load RTL styles for Arabic, Hebrew, and other right-to-left languages
            if (is_rtl()) {
                ABJ_404_Solution_WPUtils::my_wp_enq_style('abj404solution-rtl', ABJ404_URL . 'includes/html/404solutionStyles-rtl.css',
                        array('abj404solution-styles'));
            }
        } catch (Throwable $e) {
            self::reportAdminRuntimeError('admin_enqueue_scripts', $e);
        }
    }

    /**
     * Enqueue the reusable support-request button assets. Loaded on
     * every plugin admin page so any screen can drop a
     * SupportRequestButton::render() mount-point without a per-screen
     * enqueue checklist that drifts as new mount points are added.
     *
     * The inline bootstrap exposes window.ABJ404.ajaxurl plus
     * window.ABJ404.nonces.{support_request, support_request_preview}
     * so the JS client and the modal component can both reach the
     * nonces without wp_localize_script's per-handle binding.
     *
     * @return void
     */
    /**
     * Enqueue the support-request button assets specifically for the
     * wp-admin/plugins.php screen so the row-meta link added by
     * `addPluginRowMeta()` can open its consent modal in-place. The
     * plugin's main `add_scripts()` enqueue is gated to the plugin's
     * settings page and would skip plugins.php otherwise.
     *
     * Scope: this hook runs on every admin page but no-ops unless the
     * current screen is plugins.php, keeping the asset footprint tight.
     *
     * @param string $hook the admin page slug WP passes to admin_enqueue_scripts
     * @return void
     */
    static function enqueueSupportRequestAssetsOnPluginsPage($hook) {
        if ($hook !== 'plugins.php') {
            return;
        }
        try {
            self::registerSupportRequestAssets();
        } catch (Throwable $e) {
            self::reportAdminRuntimeError('admin_enqueue_scripts:plugins.php', $e);
        }
    }

    /**
     * Enqueue the view-updater module bundle (the AJAX-driven admin table
     * orchestration). Split out of add_scripts() to keep that function under
     * the ModularityTest body-line cap. Enqueue order matters: every file
     * below uses globals defined by the modules listed before it; the
     * bootstrap (view_updater.js) declares the jQuery.ready entry point and
     * must load LAST so the helpers are defined when ready fires.
     * WordPress's $deps array enforces this ordering on the emitted
     * <script> tags. The B20 nonce-refresh helper exposes
     * abj404AjaxWithNonceRetry which every sibling uses via a soft typeof
     * reference, so its enqueue must precede them.
     *
     * @param string $vuBase URL prefix for the ajax/ assets directory.
     * @return void
     */
    private static function enqueueViewUpdaterModules(string $vuBase): void {
        $enq = array('ABJ_404_Solution_WPUtils', 'my_wp_enq_scrpt');
        $enq('abj404-view-updater-nonce-refresh',
            $vuBase . 'view_updater_nonce_refresh.js', array('jquery'));
        $enq('abj404-view-updater-stage-diagnostics',
            $vuBase . 'view_updater_stage_diagnostics.js', array('jquery'));
        $enq('abj404-view-updater-compare',
            $vuBase . 'view_updater_compare.js', array('jquery'));
        $enq('abj404-view-updater-toast',
            $vuBase . 'view_updater_toast.js', array('jquery'));
        $enq('abj404-view-updater-stats', $vuBase . 'view_updater_stats.js',
            array('jquery', 'abj404-view-updater-toast', 'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-build-advance', $vuBase . 'view_updater_build_advance.js',
            array('jquery', 'abj404-view-updater-stage-diagnostics', 'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-table-init', $vuBase . 'view_updater_table_init.js',
            array('jquery', 'abj404-view-updater-toast', 'abj404-view-updater-stats',
                'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-table-warmup', $vuBase . 'view_updater_table_warmup.js',
            array('jquery', 'abj404-view-updater-stage-diagnostics',
                'abj404-view-updater-build-advance', 'abj404-view-updater-table-init',
                'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater-pagination', $vuBase . 'view_updater_pagination.js',
            array('jquery', 'abj404-view-updater-compare', 'abj404-view-updater-stage-diagnostics',
                'abj404-view-updater-build-advance', 'abj404-view-updater-table-init',
                'abj404-view-updater-table-warmup', 'abj404-view-updater-toast',
                'abj404-view-updater-nonce-refresh'));
        $enq('abj404-view-updater', $vuBase . 'view_updater.js',
            array('jquery', 'jquery-ui-autocomplete',
                'abj404-view-updater-stage-diagnostics', 'abj404-view-updater-compare',
                'abj404-view-updater-toast', 'abj404-view-updater-stats',
                'abj404-view-updater-build-advance', 'abj404-view-updater-table-init',
                'abj404-view-updater-table-warmup', 'abj404-view-updater-pagination',
                'abj404-view-updater-nonce-refresh'));
    }

    private static function registerSupportRequestAssets(): void {
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-support-request-client',
            plugin_dir_url(__FILE__) . 'ajax/SupportRequest.js', array());
        ABJ_404_Solution_WPUtils::my_wp_enq_scrpt('abj404-support-request-button',
            ABJ404_URL . 'includes/js/support-request-button.js',
            array('abj404-support-request-client'));
        if (!function_exists('wp_add_inline_script')) {
            return;
        }
        $supportNonce = wp_create_nonce(ABJ_404_Solution_Ajax_SupportRequest::NONCE_ACTION);
        $previewNonce = wp_create_nonce(ABJ_404_Solution_Ajax_SupportRequestPreview::NONCE_ACTION);
        $ajaxUrl = function_exists('admin_url') ? admin_url('admin-ajax.php') : '/wp-admin/admin-ajax.php';
        $payload = wp_json_encode(array(
            'ajaxurl' => $ajaxUrl,
            'nonces' => array(
                'support_request' => $supportNonce,
                'support_request_preview' => $previewNonce,
            ),
        ));
        $bootstrap = 'window.ABJ404=window.ABJ404||{};Object.assign(window.ABJ404,'
            . (is_string($payload) ? $payload : '{}') . ');';
        wp_add_inline_script('abj404-support-request-client', $bootstrap, 'before');
    }

    /** @deprecated Use ABJ_404_Solution_AdminThemeManager::isDarkModeDetected() */
    static function isDarkModeDetected(): bool {
        return ABJ_404_Solution_AdminThemeManager::isDarkModeDetected();
    }

    /** @deprecated Use ABJ_404_Solution_AdminThemeManager::getAutoSelectedTheme() */
    static function getAutoSelectedTheme(): string {
        return ABJ_404_Solution_AdminThemeManager::getAutoSelectedTheme();
    }

    /**
     * @param string $content
     * @return string
     */
    static function remove_admin_footer_text($content) {
        return '';
    }

    /** Add the "Settings" link to the WordPress plugins page (next to activate/deactivate and edit).
     * @param array<int|string, string> $links
     * @return array<int|string, string>
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

    /**
     * Adds a "Send debug log to developer" link to the plugin row on
     * the Plugins page. The link opens the support-request consent
     * modal in-place on wp-admin/plugins.php (handled by
     * support-request-button.js, which attaches to elements matching
     * .abj404-support-request-link). Opening in-place is deliberate:
     * the Plugins listing is the screen an admin reaches when the
     * plugin's own Settings page is broken, so the modal must not
     * depend on Settings rendering correctly.
     *
     * The href falls back to the same-page anchor `#abj404-support-request`
     * so the link is still well-formed if support-request-button.js
     * fails to load. The modal itself is the only path that transmits
     * the support-request payload; clicking the link never POSTs.
     *
     * @param array<int|string, string> $links
     * @param string $file
     * @return array<int|string, string>
     */
    static function addPluginRowMeta($links, $file) {
        if ($file !== ABJ404_NAME) {
            return $links;
        }
        $links[] = '<a href="#abj404-support-request"'
            . ' class="abj404-support-request-link"'
            . ' data-triggered-from="plugins_row_action">'
            . esc_html__('Send debug log to developer', '404-solution') . '</a>';
        return $links;
    }

    /** This is called directly by php code inserted into the page by the user.
     * Code: <?php if (!empty($abj404connector)) {$abj404connector->suggestions(); } ?>
     * @global type $abj404shortCode
     */
    /** @return void */
    function suggestions() {
        $abj404shortCode = abj_service('shortcode');

        if (is_404()) {
            $content = $abj404shortCode->shortcodePageSuggestions(array());

            echo $content;
        }
    }

    /** @return void */
    function processRedirectAllRequests() {
        $this->getFrontendPipeline()->processRedirectAllRequests();
    }
    /**
     * Process the 404s
     */
    /** @return void */
    function process404() {
        $this->getFrontendPipeline()->process404();
    }

    /**
     * @param array<string, mixed> $options
     * @param string $requestedURL
     * @return bool true if the user is sent to the default 404 page.
     */
    function tryRegexRedirect($options, $requestedURL) {
        return $this->getFrontendPipeline()->tryRegexRedirect($options, $requestedURL);
    }
    
	/**
	 * @param array<string, mixed> $options
	 * @param string $requestedURL
	 * @param array<string, mixed> $redirect
	 * @return void
	 */
    function logAReallyLongDebugMessage($options, $requestedURL, $redirect) {
        $this->getFrontendPipeline()->logAReallyLongDebugMessage($options, $requestedURL, $redirect);
	}
    
    /** Redirect to the page specified.
     * @param string $requestedURL
     * @param array<string, mixed> $redirect
     * @param string $matchReason
     * @return bool true if the user is sent to the default 404 page.
     */
    function processRedirect($requestedURL, $redirect, $matchReason) {
        return $this->getFrontendPipeline()->processRedirect($requestedURL, $redirect, $matchReason);
    }

    /** @deprecated Use ABJ_404_Solution_ReviewFeedback::echoDashboardNotification() */
    static function echoDashboardNotification(): void {
        ABJ_404_Solution_ReviewFeedback::echoDashboardNotification();
    }

    /** @deprecated Use ABJ_404_Solution_ReviewFeedback::handleResponseRedirects() */
    static function handleReviewResponseRedirects(): void {
        ABJ_404_Solution_ReviewFeedback::handleResponseRedirects();
    }

    /**
     * Safely unslash request data when wp_unslash exists and is callable.
     * Some test environments report wp_unslash as existing but throw when called.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function safeWpUnslash($value) {
        if (!function_exists('wp_unslash')) {
            return $value;
        }

        try {
            return wp_unslash($value);
        } catch (Throwable $e) { // allow-silent-catch: wp_unslash() failure; pass-through preserves the original value which is always usable
            return $value;
        }
    }

    /**
     * Normalize request input to a scalar string to avoid warnings when arrays/objects are passed.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalizeRequestScalar($value) {
        $value = self::safeWpUnslash($value);
        if (!is_scalar($value)) {
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
    public static function sanitizeFeedbackIssues($issuesRaw) {
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
    /** @return void */
    static function addMainSettingsPageLink() {
        global $menu;

        // The menu must ALWAYS be registered so the admin page is accessible.
        // Wrap all pre-registration logic in try/catch — if anything fails
        // (missing tables, broken service container, etc.), fall through to
        // register the menu with safe defaults.
        $pageName = "404 Solution";
        $menuLocation = '';

        try {
            $instance = self::getInstance();

            if (!is_admin() || !$instance->logic->userIsPluginAdmin()) {
                $instance->logger->logUserCapabilities("addMainSettingsPageLink");
                return;
            }

            // Use skip_db_check=true so menu registration never triggers
            // updateToNewVersion() — that can hang on slow database upgrades
            // and block the entire admin page from rendering.
            $options = $instance->logic->getOptions(true);
            $menuLocation = isset($options['menuLocation']) ? $options['menuLocation'] : '';

            // Admin notice badge
            if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
                $captured = $instance->getCapturedCountForNotification();
                if ($captured >= $options['admin_notification']) {
                    $pageName .= " <span class='update-plugins count-1'><span class='update-count'>" . esc_html((string)$captured) . "</span></span>";
                    if (isset($menu[80][0])) {
                        $pos = $instance->f->strpos($menu[80][0], 'update-plugins');
                        if ($pos === false) {
                            $menu[80][0] = $menu[80][0] . " <span class='update-plugins count-1'><span class='update-count'>1</span></span>";
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Something failed before menu registration. Continue with defaults
            // so the admin page is still accessible for debugging. Surface the
            // failure to PHP's error log so it isn't completely invisible.
            error_log('404 Solution: addMainSettingsPageLink pre-registration failed: ' . $e->getMessage());
        }

        if ($menuLocation === 'settingsLevel') {
            // this adds the settings link at the same level as the "Tools" and "Settings" menu items.
			$GLOBALS['abj404_settingsPageName'] = add_menu_page(PLUGIN_NAME, PLUGIN_NAME, 'manage_options', 'abj404_solution',
                    'abj404_admin_page_callback');

        } else {
            // this adds the settings link at Settings->404 Solution.
        	$GLOBALS['abj404_settingsPageName'] = add_submenu_page('options-general.php', PLUGIN_NAME, $pageName, 'manage_options', ABJ404_PP,
                    'abj404_admin_page_callback');
        }
    }

    /** @deprecated Use ABJ_404_Solution_GscOAuthHandler::handleCallback() */
    public static function handleGscOauthCallback(): void {
        ABJ_404_Solution_GscOAuthHandler::handleCallback();
    }

    /** @deprecated Use ABJ_404_Solution_GscOAuthHandler::handleRevoke() */
    public static function handleGscRevoke(): void {
        ABJ_404_Solution_GscOAuthHandler::handleRevoke();
    }

}
