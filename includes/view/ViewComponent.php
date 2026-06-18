<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract base for the concrete View_* components that replaced
 * the legacy ViewTrait_* family. Each component holds:
 *   - typed back-reference to the owning View facade (for external code paths)
 *   - typed sibling references for every other component (for cross-component
 *     calls). Sibling references are set after View constructs all components
 *     via setSiblingComponents(); access via $this->shared, $this->ui, etc.
 *   - the shared dependency graph (functions, plugin logic, logging, the four
 *     repository interfaces, plus the view-build orchestrator)
 *
 * Cross-component access goes through typed sibling properties so PHPStan
 * can fully resolve calls such as $this->optionsPresenter->getCheckedAttr(...). The View facade
 * keeps its __call dispatcher for the (smaller) set of EXTERNAL callers
 * that hold a View instance rather than a component.
 */
abstract class ABJ_404_Solution_ViewComponent {

    /** @var ABJ_404_Solution_View */
    protected $view;

    /** @var ABJ_404_Solution_Functions */
    protected $f;

    /** @var ABJ_404_Solution_PluginLogic */
    protected $logic;

    /** @var ABJ_404_Solution_Logging */
    protected $logger;

    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    protected $viewReadService;

    /** @var ABJ_404_Solution_LogsRepositoryInterface */
    protected $logsRepository;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    protected $redirectsRepository;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    protected $contentRepository;

    /** @var ABJ_404_Solution_StatsRepositoryInterface */
    protected $statsRepository;

    /** @var ABJ_404_Solution_View_Shared */
    protected $shared;

    /** @var ABJ_404_Solution_View_UI */
    protected $ui;

    /** @var ABJ_404_Solution_View_AdminChrome */
    protected $adminChrome;

    /** @var ABJ_404_Solution_View_OptionsPresenter */
    protected $optionsPresenter;

    /** @var ABJ_404_Solution_View_Stats */
    protected $stats;

    /** @var ABJ_404_Solution_View_Tools */
    protected $tools;

    /** @var ABJ_404_Solution_View_Settings */
    protected $settings;

    /** @var ABJ_404_Solution_View_SettingsSections */
    protected $settingsSections;

    /** @var ABJ_404_Solution_View_SimpleSettings */
    protected $simpleSettings;

    /** @var ABJ_404_Solution_View_Redirects */
    protected $redirects;

    /** @var ABJ_404_Solution_View_RedirectsTable */
    protected $redirectsTable;

    /** @var ABJ_404_Solution_View_CapturedURLsTable */
    protected $capturedURLsTable;

    /** @var ABJ_404_Solution_View_RedirectForms */
    protected $redirectForms;

    /** @var ABJ_404_Solution_View_ListTableChrome */
    protected $listTableChrome;

    /** @var ABJ_404_Solution_View_RedirectTypeUI */
    protected $redirectTypeUI;

    /** @var ABJ_404_Solution_View_RedirectConditions */
    protected $redirectConditions;

    /** @var ABJ_404_Solution_View_Logs */
    protected $logs;

    /**
     * @param ABJ_404_Solution_View $view
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     * @param ABJ_404_Solution_Logging $logging
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewReadService
     * @param ABJ_404_Solution_LogsRepositoryInterface $logsRepository
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepository
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepository
     * @param ABJ_404_Solution_StatsRepositoryInterface $statsRepository
     */
    public function __construct(
        ABJ_404_Solution_View $view,
        $functions,
        $pluginLogic,
        $logging,
        $viewReadService,
        $logsRepository,
        $redirectsRepository,
        $contentRepository,
        $statsRepository
    ) {
        $this->view = $view;
        $this->f = $functions;
        $this->logic = $pluginLogic;
        $this->logger = $logging;
        $this->viewReadService = $viewReadService;
        $this->logsRepository = $logsRepository;
        $this->redirectsRepository = $redirectsRepository;
        $this->contentRepository = $contentRepository;
        $this->statsRepository = $statsRepository;
    }

    /**
     * Wire up sibling component references after all components are constructed.
     * Called once by ABJ_404_Solution_View::buildComponents().
     */
    public function setSiblingComponents(
        ABJ_404_Solution_View_Shared $shared,
        ABJ_404_Solution_View_UI $ui,
        ABJ_404_Solution_View_AdminChrome $adminChrome,
        ABJ_404_Solution_View_OptionsPresenter $optionsPresenter,
        ABJ_404_Solution_View_Stats $stats,
        ABJ_404_Solution_View_Tools $tools,
        ABJ_404_Solution_View_Settings $settings,
        ABJ_404_Solution_View_SettingsSections $settingsSections,
        ABJ_404_Solution_View_SimpleSettings $simpleSettings,
        ABJ_404_Solution_View_Redirects $redirects,
        ABJ_404_Solution_View_RedirectsTable $redirectsTable,
        ABJ_404_Solution_View_CapturedURLsTable $capturedURLsTable,
        ABJ_404_Solution_View_RedirectForms $redirectForms,
        ABJ_404_Solution_View_ListTableChrome $listTableChrome,
        ABJ_404_Solution_View_RedirectTypeUI $redirectTypeUI,
        ABJ_404_Solution_View_RedirectConditions $redirectConditions,
        ABJ_404_Solution_View_Logs $logs
    ): void {
        $this->shared = $shared;
        $this->ui = $ui;
        $this->adminChrome = $adminChrome;
        $this->optionsPresenter = $optionsPresenter;
        $this->stats = $stats;
        $this->tools = $tools;
        $this->settings = $settings;
        $this->settingsSections = $settingsSections;
        $this->simpleSettings = $simpleSettings;
        $this->redirects = $redirects;
        $this->redirectsTable = $redirectsTable;
        $this->capturedURLsTable = $capturedURLsTable;
        $this->redirectForms = $redirectForms;
        $this->listTableChrome = $listTableChrome;
        $this->redirectTypeUI = $redirectTypeUI;
        $this->redirectConditions = $redirectConditions;
        $this->logs = $logs;
    }
}
