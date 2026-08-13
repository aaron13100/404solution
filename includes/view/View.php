<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Turns data into an html display and vice versa.
 * Houses all displayed pages. Logs, options page, captured 404s, stats, etc. */

require_once __DIR__ . '/ViewComponent.php';
require_once __DIR__ . '/View_Shared.php';
require_once __DIR__ . '/View_UI.php';
require_once __DIR__ . '/View_AdminChrome.php';
require_once __DIR__ . '/View_OptionsPresenter.php';
require_once __DIR__ . '/../diagnostics/ToolsDiagnostics.php';
require_once __DIR__ . '/View_Stats.php';
require_once __DIR__ . '/View_Tools.php';
require_once __DIR__ . '/View_Settings.php';
require_once __DIR__ . '/View_SettingsSections.php';
require_once __DIR__ . '/View_SimpleSettings.php';
require_once __DIR__ . '/View_Redirects.php';
require_once __DIR__ . '/View_RedirectsTable.php';
require_once __DIR__ . '/View_CapturedURLsTable.php';
require_once __DIR__ . '/View_RedirectForms.php';
require_once __DIR__ . '/View_ListTableChrome.php';
require_once __DIR__ . '/View_RedirectTypeUI.php';
require_once __DIR__ . '/View_RedirectConditions.php';
require_once __DIR__ . '/View_Logs.php';

/**
 * Magic-method dispatch surface for the View facade. Each entry below points
 * at a method that lives on one of the ABJ_404_Solution_View_* components
 * (Shared, UI, AdminChrome, OptionsPresenter, Stats, Tools, Settings, SettingsSections,
 * SimpleSettings, Redirects, RedirectsTable,
 * RedirectTypeUI, RedirectConditions, Logs). __call routes the call to whichever component
 * owns the method. PHPStan needs these annotations because it cannot trace
 * __call dispatch through the component array.
 *
 * @method mixed buildRedirectRowHTML(array<mixed> $row, string $sub, array<mixed> $tableOptions, array<mixed> $deadDestIds, int $y)
 * @method mixed buildRedirectToDropdownHtml(string $pageTitle, string $pageIDAndType)
 * @method mixed buildRedirectsColumnDefs(array<mixed> $tableOptions)
 * @method mixed buildScoreCell($rawScore, string $rowEngine)
 * @method mixed buildTableActionLinks($row, $sub, $tableOptions, $isCapturedPage = false)
 * @method mixed echoAddManualRedirect($tableOptions)
 * @method mixed echoAddRedirectModal($tableOptions)
 * @method mixed echoAdminCapturedURLsPage()
 * @method mixed echoAdminDebugFile()
 * @method mixed echoAdminEditRedirectPage()
 * @method mixed echoAdminFooter()
 * @method mixed echoAdminLogsPage()
 * @method mixed echoAdminOptionsPage()
 * @method mixed echoAdminRedirectsPage()
 * @method mixed echoAdminToolsPage()
 * @method mixed echoBrokenInternalLinksSection()
 * @method mixed echoChosenAdminTab($action, $sub, $message)
 * @method mixed echoConditionRow($index, array<mixed> $cond)
 * @method mixed echoConditionsJavaScript()
 * @method mixed echoConfidenceDistributionSection()
 * @method string buildSubsubsubFilters(string $sub, array<int, array{0: int|string, 1: string}> $items, array<string, mixed> $tableOptions)
 * @method mixed echoEditRedirect(ABJ_404_Solution_EditRedirectFormContext $ctx)
 * @method mixed echoExpandCollapseButton($showSuggestions = true)
 * @method mixed echoFileContents($fileName)
 * @method mixed echoInlineModeToggle()
 * @method mixed echoOptionsSection(ABJ_404_Solution_OptionsSectionView $section)
 * @method mixed echoPostBox($id, $title, $content)
 * @method mixed echoRedirectConditionsSection()
 * @method mixed echoRedirectDestinationOptionsCatsTags($dest)
 * @method mixed echoRedirectDestinationOptionsDefaults($currentlySelected)
 * @method mixed echoRedirectDestinationOptionsOthers($dest, $rows)
 * @method mixed echoRedirectTypeButtonGrid(string $selectedCode)
 * @method mixed echoRestoreDefaultsModal()
 * @method mixed echoSettingsModeToggle($currentMode)
 * @method mixed echoSimpleModeOptions($options)
 * @method mixed echoStickySaveBar()
 * @method mixed echoToastNotification()
 * @method mixed echoTrendsSection()
 * @method mixed fillRedirectRowTemplate(array<mixed> $replacements)
 * @method string getAdminLogsPageTable($sub, array<string, mixed> $tableOptionOverrides = array())
 * @method mixed getAdminOptionsPageAdvancedContent($options)
 * @method mixed getAdminOptionsPageAdvancedLogging($options)
 * @method mixed getAdminOptionsPageAdvancedSystem($options)
 * @method mixed getAdminOptionsPageAutoRedirects($options)
 * @method mixed getAdminOptionsPageGeneralSettings($options)
 * @method string getAdminRedirectsPageTable($sub, array<string, mixed> $tableOptionOverrides = array())
 * @method mixed getBehaviorTilesHTML($options)
 * @method mixed getBulkOperationsFormURL($sub, $tableOptions)
 * @method string getCapturedURLSPageTable($sub, array<string, mixed> $tableOptionOverrides = array())
 * @method string getCardIcon(string $iconName)
 * @method mixed getCheckedAttr($options, $key)
 * @method string computeTableDataSignature($sub, array<string, mixed> $tableOptionOverrides = array())
 * @method string getCurrentTableDataSignature($sub)
 * @method mixed getDashboardNotificationCaptured($captured)
 * @method mixed getFallbackOptionDefaults()
 * @method mixed getHeaderSortState($tableOptions, $orderby, $preferDescOnFirstClick = false)
 * @method mixed getMigrateFromPluginMarkup()
 * @method mixed getOptionsWithDefaults()
 * @method string getPaginationLinks($sub, array<string, mixed> $tableOptionOverrides = array())
 * @method mixed getSignatureFieldsForSubpage($sub, $row)
 * @method mixed getSubSubSub($sub)
 * @method mixed getSuggestedDestination(string $url, array<mixed> $options)
 * @method mixed getTabFilters($sub, $tableOptions)
 * @method mixed getTableColumns($sub, $columns)
 * @method mixed getToolsDiagnosticsMarkup()
 * @method mixed getToolsDiagnosticsRows()
 * @method mixed humanizeEngineName(string $rawName)
 * @method mixed normalizeOptionsForView($options)
 * @method mixed normalizeSignatureValue($value)
 * @method mixed optStr($options, $key, $default = '')
 * @method mixed outputAdminHeaderTabs($sub = 'list', $message = '')
 * @method mixed outputAdminStatsPage()
 * @method mixed rememberTableDataSignature($sub, $rows)
 * @method mixed renderBulkRedirectFormFields(array<mixed> $recnums_multiple)
 * @method mixed renderRegexAutoPromoteNotice(array<mixed> $notice)
 * @method mixed renderSuggestionBlock(array<mixed> $suggestion)
 * @method mixed resolveDestinationWarnings(ABJ_404_Solution_RedirectDestinationWarningContext $ctx)
 * @method mixed resolveRedirectDestLink($rowType, string $rowFinalDest)
 * @method mixed resolveRedirectDestinationInfo(array<mixed> $redirect, array<mixed> $options)
 * @method mixed traceOutcomeClass(string $outcome)
 * @method mixed translateTraceLabel(string $text)
 * @method mixed viewGetPostOrGetSanitize($name, $defaultValue = null)
 */
class ABJ_404_Solution_View {

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


	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_ViewReadServiceInterface */
	private $viewReadService;

	/** @var ABJ_404_Solution_LogsRepositoryInterface */
	private $logsRepository;

	/** @var ABJ_404_Solution_RedirectsRepositoryInterface */
	private $redirectsRepository;

	/** @var ABJ_404_Solution_ContentRepositoryInterface */
	private $contentRepository;

	/** @var ABJ_404_Solution_StatsRepositoryInterface */
	private $statsRepository;

	/** @var array<int, ABJ_404_Solution_ViewComponent> */
	private $components = array();

	/**
	 * Constructor with dependency injection.
	 *
	 * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
	 * @param mixed $dataAccessOrViewReadService Data access (legacy) or ViewReadService
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 * @param ABJ_404_Solution_StatsRepositoryInterface|null $statsRepository Stats repository
	 */
	public function __construct($functions = null, $pluginLogic = null, $dataAccessOrViewReadService = null, $logging = null, $statsRepository = null) {
		$this->f = $functions !== null ? $functions : abj_service('functions');
		$this->logic = $pluginLogic !== null ? $pluginLogic : abj_service('plugin_logic');
		$this->logger = $logging !== null ? $logging : abj_service('logging');

		// When a DataAccess facade is injected (legacy callers / tests),
		// use it directly for all module interfaces -- it delegates to the
		// real modules internally.
		$dao = $dataAccessOrViewReadService;
		$isLegacyDao = ($dao !== null && is_object($dao)
			&& !($dao instanceof ABJ_404_Solution_ViewReadServiceInterface)
			&& (method_exists($dao, 'getRedirectsForView') || $dao instanceof ABJ_404_Solution_DataAccess));

		if ($isLegacyDao) {
			/** @var ABJ_404_Solution_ViewReadServiceInterface&ABJ_404_Solution_RedirectsRepositoryInterface&ABJ_404_Solution_StatsRepositoryInterface $dao */
			$this->viewReadService = $dao;
			// Resolve typed repository surfaces off the DataAccess facade so the
			// View talks to the real LogsRepository / ContentRepository objects.
			// Pass-throughs for these repos have been removed from DataAccess,
			// so resolve the typed repositories from the facade when available.
			$this->logsRepository = (is_object($dao) && method_exists($dao, 'getLogsRepo'))
				? $dao->getLogsRepo()
				: $dao;
			$this->redirectsRepository = $dao;
			$this->contentRepository = (is_object($dao) && method_exists($dao, 'getContentRepo'))
				? $dao->getContentRepo()
				: $dao;
			$this->statsRepository = $this->resolveStatsRepository($statsRepository, $dao);
		} else {
			/** @var ABJ_404_Solution_ViewReadServiceInterface $vrs */
			$vrs = abj_service('view_read_service');
			$this->viewReadService = $vrs;
			/** @var ABJ_404_Solution_LogsRepositoryInterface $lr */
			$lr = abj_service('logs_repository');
			$this->logsRepository = $lr;
			/** @var ABJ_404_Solution_RedirectsRepositoryInterface $rr */
			$rr = abj_service('redirects_repository');
			$this->redirectsRepository = $rr;
			/** @var ABJ_404_Solution_ContentRepositoryInterface $cr */
			$cr = abj_service('content_repository');
			$this->contentRepository = $cr;
			/** @var ABJ_404_Solution_StatsRepositoryInterface $sr */
			$sr = $statsRepository !== null ? $statsRepository : $this->resolveStatsRepository(null, null);
			$this->statsRepository = $sr;
		}

		$this->components = $this->buildComponents();
	}

	/** @return self */
	public static function getInstance() {
		if (self::$instance !== null) {
			return self::$instance;
		}

		// If the DI container is initialized, prefer it.
		if (class_exists('ABJ_404_Solution_ServiceContainer')) {
			$resolved = ABJ_404_Solution_ServiceContainer::safeGet('view');
			if ($resolved instanceof self) {
				self::$instance = $resolved;
				return self::$instance;
			}
		}

		self::$instance = new ABJ_404_Solution_View();

		return self::$instance;
	}

	/**
	 * @param ABJ_404_Solution_StatsRepositoryInterface|null $provided
	 * @param mixed $legacyDao
	 * @return ABJ_404_Solution_StatsRepositoryInterface
	 */
	private function resolveStatsRepository($provided, $legacyDao): ABJ_404_Solution_StatsRepositoryInterface {
		if ($provided instanceof ABJ_404_Solution_StatsRepositoryInterface) {
			return $provided;
		}

		$service = class_exists('ABJ_404_Solution_ServiceContainer')
			? ABJ_404_Solution_ServiceContainer::safeGet('stats_repository')
			: null;
		if ($service instanceof ABJ_404_Solution_StatsRepositoryInterface) {
			return $service;
		}

		return ABJ_404_Solution_StatsRepositoryResolver::resolve(__CLASS__);
	}

	/**
	 * @return array<int, ABJ_404_Solution_ViewComponent>
	 */
	private function buildComponents() {
		$args = array(
			$this,
			$this->f,
			$this->logic,
			$this->logger,
			$this->viewReadService,
			$this->logsRepository,
			$this->redirectsRepository,
			$this->contentRepository,
			$this->statsRepository,
		);

		$shared = new ABJ_404_Solution_View_Shared(...$args);
		$ui = new ABJ_404_Solution_View_UI(...$args);
		$adminChrome = new ABJ_404_Solution_View_AdminChrome(...$args);
		$optionsPresenter = new ABJ_404_Solution_View_OptionsPresenter(...$args);
		$stats = new ABJ_404_Solution_View_Stats(...$args);
		$tools = new ABJ_404_Solution_View_Tools(...$args);
		$settings = new ABJ_404_Solution_View_Settings(...$args);
		$settingsSections = new ABJ_404_Solution_View_SettingsSections(...$args);
		$simpleSettings = new ABJ_404_Solution_View_SimpleSettings(...$args);
		$redirects = new ABJ_404_Solution_View_Redirects(...$args);
		$redirectsTable = new ABJ_404_Solution_View_RedirectsTable(...$args);
		$capturedURLsTable = new ABJ_404_Solution_View_CapturedURLsTable(...$args);
		$redirectForms = new ABJ_404_Solution_View_RedirectForms(...$args);
		$listTableChrome = new ABJ_404_Solution_View_ListTableChrome(...$args);
		$redirectTypeUI = new ABJ_404_Solution_View_RedirectTypeUI(...$args);
		$redirectConditions = new ABJ_404_Solution_View_RedirectConditions(...$args);
		$logs = new ABJ_404_Solution_View_Logs(...$args);

		$components = array(
			$shared, $ui, $adminChrome, $optionsPresenter, $stats, $tools,
			$settings, $settingsSections, $simpleSettings, $redirects, $redirectsTable,
			$capturedURLsTable, $redirectForms, $listTableChrome,
			$redirectTypeUI, $redirectConditions, $logs,
		);
		foreach ($components as $component) {
			$component->setSiblingComponents(
				$shared, $ui, $adminChrome, $optionsPresenter, $stats, $tools,
				$settings, $settingsSections, $simpleSettings, $redirects,
				$redirectsTable, $capturedURLsTable, $redirectForms, $listTableChrome,
				$redirectTypeUI, $redirectConditions, $logs
			);
		}

		return $components;
	}

	/**
	 * Magic method dispatch. Routes the call to whichever component owns the
	 * named method. Replaces the trait-based composition with explicit
	 * delegation while preserving the existing call surface ($view->methodX()).
	 *
	 * @param string $name
	 * @param array<int,mixed> $arguments
	 * @return mixed
	 */
	public function __call($name, $arguments) {
		foreach ($this->components as $component) {
			if (method_exists($component, $name)) {
				return $component->{$name}(...$arguments);
			}
		}

		throw new BadMethodCallException(
			'ABJ_404_Solution_View has no method ' . $name
		);
	}

	/**
	 * Static forwarder for the renderer of error-notice + support-button HTML.
	 * Used by callers that don't have the View instance handy.
	 *
	 * @param string $messageHtml
	 * @param string $triggeredFrom
	 * @param string|null $contextSummary
	 * @return string
	 */
	public static function renderErrorNoticeWithSupportButton(string $messageHtml,
			string $triggeredFrom, ?string $contextSummary = null): string {
		return ABJ_404_Solution_View_UI::renderErrorNoticeWithSupportButton(
			$messageHtml,
			$triggeredFrom,
			$contextSummary
		);
	}

	/**
	 * Maintain the logs_hits rollup when a plugin admin page renders. The policy
	 * records staleness and schedules a rebuild only when the rollup is missing
	 * or behind the source log table.
	 *
	 * @return void
	 */
	private function maintainLogsHitsRollupOnAdminPageLoad(): void {
		try {
			$this->viewReadService->maybeUpdateRedirectsForViewHitsTable();
		} catch (\Throwable $e) {
			$this->logger->warn(
				'logs_hits rollup maintenance failed during admin page render: '
				. get_class($e) . ' code=' . (string)$e->getCode() . ' message=' . $e->getMessage()
			);
		}
	}

	/**
	 * Static entry point for WP admin page rendering. Routes admin POST actions
	 * (trash / delete / ignore / edit / import) to PluginLogic and then renders
	 * the selected subpage. Needs to live on the View facade itself because it
	 * touches the facade's private $logic / $logger / $components fields, which
	 * View_UI (the rest of the UI surface lives there) cannot reach from
	 * outside the class scope.
	 *
	 * @return void
	 */
	public static function handleMainAdminPageActionAndDisplay() {
		global $abj404view;
		$instance = self::getInstance();

		try {
			$action = (string)$instance->viewGetPostOrGetSanitize('action');

			if (!is_admin() || !abj_service('admin_access_policy')->isPluginAdmin()) {
				$instance->logger->logUserCapabilities("handleMainAdminPageActionAndDisplay (" .
						esc_html($action == '' ? '(none)' : $action) . ")");

				$permMessageTpl = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/adminPagePermissionDeniedMessage.html');
				$permMessage = str_replace(
					['{permission_denied_label}', '{access_explanation}', '{verify_role_prefix}', '{capability_word}', '{security_plugin_note}'],
					[
						esc_html__('Permission denied.', '404-solution'),
						esc_html__('Your user account does not have permission to access this page.', '404-solution'),
						esc_html__('Please verify that your WordPress role has the', '404-solution'),
						esc_html__('capability.', '404-solution'),
						esc_html__('If you have a security plugin installed, it may be restricting access to this page.', '404-solution'),
					],
					$permMessageTpl
				);
				$subpageForContext = (string)$instance->viewGetPostOrGetSanitize('subpage');
				$triggerForPerm = ($subpageForContext === 'abj404_captured')
					? 'captured_404s_page' : 'redirects_page';
				$permNotice = self::renderErrorNoticeWithSupportButton(
					$permMessage,
					$triggerForPerm,
					'Permission denied on plugin admin page (action=' .
						($action == '' ? '(none)' : $action) . ')'
				);
				$permWrap = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/adminPagePermissionDeniedWrap.html');
				echo str_replace(
					['{plugin_name}', '{notice}'],
					[esc_html(PLUGIN_NAME), $permNotice],
					$permWrap
				);
				return;
			}

			// Close any missing plugin table before drawing anything. The bounded
			// counterpart to the boot prologue, and it contains its own failures;
			// see DatabaseUpgradeSelfHeal::repairMissingTablesForRequest() for why
			// a render has to ask for this rather than cause it by accident.
			// After the authorization gate on purpose: an unauthorized visitor
			// must not be able to make the site issue DDL.
			abj_service('database_upgrades')->repairMissingTablesForRequest();

			$instance->maintainLogsHitsRollupOnAdminPageLoad();

			$sub = "";

			// Handle post actions.
			$instance->logger->debugMessage("Processing request for action: " .
					esc_html($action == '' ? '(none)' : $action));

			$message = "";
			$message .= $instance->logic->adminActions()->handlePluginAction($action, $sub);
			$message .= $instance->logic->adminActions()->hanldeTrashAction();
			$message .= $instance->logic->adminActions()->handleDeleteAction();
			$message .= $instance->logic->adminActions()->handleIgnoreAction();
			$message .= $instance->logic->adminActions()->handleLaterAction();
			$message .= $instance->logic->adminActions()->handleActionEdit($sub, $action);
			$message .= $instance->logic->adminActions()->handleActionImportRedirects();
			$instance->logic->adminActions()->handleActionChangeItemsPerRow();
			$message .= $instance->logic->adminActions()->handleActionImportFile();

			if ($action !== '' && $message !== '') {
				$instance->logger->debugMessage("Admin action completed: " .
					esc_html($action) . " => " . esc_html(substr($message, 0, 200)));
			}

			// Output the correct subpage.
			$abj404view->echoChosenAdminTab($action, $sub, $message);

		} catch (\Throwable $e) {
			$encodedEx = json_encode($e);
			$encodedContext = is_string($encodedEx) ? stripcslashes(wp_kses_post($encodedEx)) : '';
			$instance->logger->errorMessage(
				"Caught exception (" . get_class($e) . "): " . $e->getMessage()
				. ($encodedContext !== '' ? " | context=" . $encodedContext : '')
			);
			$subpageForContext = (string)$instance->viewGetPostOrGetSanitize('subpage');
			$triggerForRenderError = ($subpageForContext === 'abj404_captured')
				? 'captured_404s_page' : 'redirects_page';
			$renderErrorMessageTpl = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/adminPageRenderErrorMessage.html');
			$renderErrorMessage = str_replace(
				'{escaped_details}',
				esc_html($e->getMessage() . "\n" . $e->getTraceAsString()),
				$renderErrorMessageTpl
			);
			$renderErrorNotice = self::renderErrorNoticeWithSupportButton(
				$renderErrorMessage,
				$triggerForRenderError,
				'Render error: ' . substr($e->getMessage(), 0, 200)
			);
			$renderErrorWrap = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/adminPageRenderErrorWrap.html');
			echo str_replace('{notice}', $renderErrorNotice, $renderErrorWrap);
		}
	}

}
