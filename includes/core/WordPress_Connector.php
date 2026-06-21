<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_WordPress_Connector {

	/** @var self|null */
	private static $instance = null;
	/**
	 * Test seam: install or clear the cached singleton instance without
	 * private-field reflection. Pass null to reset between tests; pass a
	 * configured instance (or double) to install it. Mirrors the setInstance()
	 * contract on DataAccess / PluginLogic (M105 singleton-reset seam).
	 *
	 * @param self|null $instance
	 * @return void
	 */
	public static function setInstance($instance) {
	    self::$instance = $instance;
	}


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
	 * @param ABJ_404_Solution_WordPressConnectorDependencies|null $deps
	 */
	public function __construct(?ABJ_404_Solution_WordPressConnectorDependencies $deps = null) {
		$deps = $deps ?? new ABJ_404_Solution_WordPressConnectorDependencies();
		$redirectsRepository = $deps->redirectsRepository;
		$this->logic = $deps->pluginLogic !== null ? $deps->pluginLogic : abj_service('plugin_logic');
		$this->redirectsRepository = $redirectsRepository !== null ? $redirectsRepository : abj_service('redirects_repository');
		$this->logger = $deps->logging !== null ? $deps->logging : abj_service('logging');
		$this->f = $deps->functions !== null ? $deps->functions : abj_service('functions');
		$this->spellChecker = $deps->spellChecker !== null ? $deps->spellChecker : abj_service('spell_checker');
		$this->logsRepository = $deps->logsRepository !== null ? $deps->logsRepository :
			(is_object($redirectsRepository) && method_exists($redirectsRepository, 'logRedirectHit') ? $redirectsRepository : abj_service('logs_repository'));
		$this->statsRepository = $deps->statsRepository !== null ? $deps->statsRepository :
			(is_object($redirectsRepository) && method_exists($redirectsRepository, 'getCapturedCountForNotification')
				? $redirectsRepository
				: ABJ_404_Solution_StatsRepositoryResolver::resolve(__CLASS__));
	}

	/** @return ABJ_404_Solution_FrontendRequestPipeline */
	private function getFrontendPipeline() {
		if ($this->frontendPipeline !== null) {
			return $this->frontendPipeline;
		}

		if (!class_exists('ABJ_404_Solution_FrontendRequestPipeline')) {
			require_once __DIR__ . '/../frontend/FrontendRequestPipeline.php';
		}

		$matchingEngines = [];
		if (class_exists('ABJ_404_Solution_ServiceContainer')) {
			$engines = ABJ_404_Solution_ServiceContainer::safeGet('matching_engines');
			if (is_array($engines)) {
				$matchingEngines = $engines;
			}
		}

		$dependencies = new ABJ_404_Solution_FrontendPipelineDependencies(
			$this->logic,
			$this->redirectsRepository,
			$this->logger,
			$this->f,
			$this->spellChecker,
			$matchingEngines,
			$this->logsRepository,
			abj_service('not_found_response'),
			abj_service('request_ignore_normalizer'),
			abj_service('previous_request_cookie_tracker')
		);
		$this->frontendPipeline = new ABJ_404_Solution_FrontendRequestPipeline($dependencies);
		return $this->frontendPipeline;
	}

	public function getCapturedCountForNotification(): int {
		if (!is_object($this->statsRepository) || !method_exists($this->statsRepository, 'getCapturedCountForNotification')) { return 0; } try { return (int)call_user_func(array($this->statsRepository, 'getCapturedCountForNotification')); } catch (Throwable $e) {
			if (is_object($this->logger) && method_exists($this->logger, 'errorMessage')) { $this->logger->errorMessage('Captured-count notification lookup failed: ' . $e->getMessage(), $e instanceof Exception ? $e : null); } else { $this->logWarning('Captured-count notification lookup failed: ' . $e->getMessage()); } return 0; }
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

    /** Setup.
	 * @return void
	 */
    static function init() {
        ABJ_404_Solution_WordPressHookRegistrar::registerAll(self::wordpressHookCallbacks());
    }

    /**
     * @return array<string, callable-string>
     */
    private static function wordpressHookCallbacks(): array {
        return array(
            'settings_link' => __CLASS__ . '::addSettingsLinkToPluginPage',
            'plugin_row_meta' => __CLASS__ . '::addPluginRowMeta',
            'review_notice' => 'ABJ_404_Solution_ReviewFeedback::echoDashboardNotification',
            'review_redirects' => 'ABJ_404_Solution_ReviewFeedback::handleResponseRedirects',
            'settings_page' => __CLASS__ . '::addMainSettingsPageLink',
            'admin_assets' => __CLASS__ . '::add_scripts',
            'plugins_page_assets' => __CLASS__ . '::enqueueSupportRequestAssetsOnPluginsPage',
            'admin_theme_css' => 'ABJ_404_Solution_AdminThemeManager::outputCriticalThemeCSS',
            'ajax_view_logs' => 'ABJ_404_Solution_Ajax_ViewLogs::echoViewLogsFor',
            'ajax_trash_link' => 'ABJ_404_Solution_Ajax_TrashLink::trashAction',
            'ajax_redirect_to_pages' => 'ABJ_404_Solution_Ajax_RedirectDestinationAutocomplete::echoRedirectToPages',
            'ajax_update_options' => 'ABJ_404_Solution_Ajax_UpdateOptions::updateOptions',
            'ajax_load_gsc_section' => 'ABJ_404_Solution_Ajax_LoadGscSection::loadGscSection',
            'ajax_trend_data' => 'ABJ_404_Solution_Ajax_TrendData::echoTrendData',
            'ajax_cross_plugin_preview' => 'ABJ_404_Solution_Ajax_CrossPluginImporter::handlePreview',
            'ajax_gsc_oauth_callback' => 'ABJ_404_Solution_GscOAuthHandler::handleCallback',
            'ajax_gsc_revoke' => 'ABJ_404_Solution_GscOAuthHandler::handleRevoke',
            'ajax_compute_suggestions' => 'ABJ_404_Solution_Ajax_SuggestionCompute::computeSuggestions',
            'ajax_poll_suggestions' => 'ABJ_404_Solution_Ajax_SuggestionPolling::pollSuggestions',
        );
    }

    /** Include things necessary for ajax.
     * @param string $hook
     * @return void
     */
    static function add_scripts($hook) {
        ABJ_404_Solution_AdminAssetEnqueuer::addScripts($hook, array('ABJ_404_Solution_AdminRuntimeErrorNotice', 'reportAdminRuntimeError'));
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
        ABJ_404_Solution_AdminAssetEnqueuer::enqueueSupportRequestAssetsOnPluginsPage(
            $hook,
            array('ABJ_404_Solution_AdminRuntimeErrorNotice', 'reportAdminRuntimeError')
        );
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

        $isPluginAdmin = abj_service('admin_access_policy')->isPluginAdmin();
        if (!is_admin() || !$isPluginAdmin) {
            $instance->logger->logUserCapabilities("addSettingsLinkToPluginPage");

            return $links;
        }

        $settings_link = self::renderPluginRowLink(
            esc_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options'),
            esc_html__('Settings', '404-solution')
        );
        array_unshift($links, $settings_link);

        $debugExplanation = __('Debug Log', '404-solution');
        $debugLogLink = '?page=' . ABJ404_PP . '&subpage=abj404_debugfile';
        $debugExplanation = self::renderPluginRowLink(
            esc_url('options-general.php' . $debugLogLink),
            esc_html($debugExplanation),
            ' target="_blank"'
        );
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
        $links[] = self::renderTemplate('supportRequestInlineLink.html', array(
            '{triggered_from}'       => esc_attr('plugins_row_action'),
            '{context_summary_attr}' => '',
            '{label}'                => esc_html__('Send debug log to developer', '404-solution'),
        ));
        return $links;
    }

    /**
     * @param string $href
     * @param string $label
     * @param string $targetAttr
     * @return string
     */
    private static function renderPluginRowLink(string $href, string $label, string $targetAttr = ''): string {
        return self::renderTemplate('pluginRowLink.html', array(
            '{href}'        => $href,
            '{target_attr}' => $targetAttr,
            '{label}'       => $label,
        ));
    }

    /**
     * @param string $templateName
     * @param array<string, string> $replacements
     * @return string
     */
    private static function renderTemplate(string $templateName, array $replacements): string {
        $template = ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/' . $templateName,
            false
        );
        return str_replace(array_keys($replacements), array_values($replacements), $template);
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
     * Safely unslash request data when wp_unslash exists and is callable.
     * Some test environments report wp_unslash as existing but throw when called.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function safeWpUnslash($value) {
        return ABJ_404_Solution_RequestInputNormalizer::safeWpUnslash($value);
    }

    /**
     * Normalize request input to a scalar string to avoid warnings when arrays/objects are passed.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalizeRequestScalar($value) {
        return ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($value);
    }

    /**
     * Normalize and sanitize feedback issue selections from request data.
     *
     * @param mixed $issuesRaw
     * @return array<int, string>
     */
    public static function sanitizeFeedbackIssues($issuesRaw) {
        return ABJ_404_Solution_RequestInputNormalizer::sanitizeFeedbackIssues($issuesRaw);
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

            if (!is_admin() || !abj_service('admin_access_policy')->isPluginAdmin()) {
                $instance->logger->logUserCapabilities("addMainSettingsPageLink");
                return;
            }

            // Use skip_db_check=true so menu registration never triggers
            // updateToNewVersion() — that can hang on slow database upgrades
            // and block the entire admin page from rendering.
            $options = abj_service('options_repository')->getOptions(true);
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
            // failure through the plugin log so it isn't completely invisible.
            self::logWarningUsingLogger(
                isset($instance) && $instance instanceof self ? $instance->logger : null,
                'addMainSettingsPageLink pre-registration failed: ' . $e->getMessage()
            );
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

    private function logWarning(string $message): void {
        self::logWarningUsingLogger($this->logger, $message);
    }

    private static function logWarningUsingLogger(?object $logger, string $message): void {
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }

        $resolvedLogger = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (is_object($resolvedLogger) && method_exists($resolvedLogger, 'warn')) {
            $resolvedLogger->warn($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
