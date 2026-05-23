<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Turns data into an html display and vice versa.
 * Houses all displayed pages. Logs, options page, captured 404s, stats, etc. */

require_once __DIR__ . '/ViewTrait_Shared.php';
require_once __DIR__ . '/ViewTrait_UI.php';
require_once __DIR__ . '/ViewTrait_Stats.php';
require_once __DIR__ . '/ViewTrait_Settings.php';
require_once __DIR__ . '/ViewTrait_Redirects.php';
require_once __DIR__ . '/ViewTrait_RedirectsTable.php';
require_once __DIR__ . '/ViewTrait_RedirectTypeUI.php';
require_once __DIR__ . '/ViewTrait_RedirectConditions.php';
require_once __DIR__ . '/ViewTrait_Logs.php';

class ABJ_404_Solution_View {

	use ViewTrait_Shared,
	    ViewTrait_UI,
	    ViewTrait_Stats,
	    ViewTrait_Settings,
	    ViewTrait_Redirects,
	    ViewTrait_RedirectsTable,
	    ViewTrait_RedirectTypeUI,
	    ViewTrait_RedirectConditions,
	    ViewTrait_Logs;

	/** @var self|null */
	private static $instance = null;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_ViewReadServiceInterface */
	private $viewReadService;

	/** @var ABJ_404_Solution_ViewBuildOrchestratorInterface */
	private $viewBuildOrchestrator;

	/** @var ABJ_404_Solution_LogsRepositoryInterface */
	private $logsRepository;

	/** @var ABJ_404_Solution_RedirectsRepositoryInterface */
	private $redirectsRepository;

	/** @var ABJ_404_Solution_ContentRepositoryInterface */
	private $contentRepository;

	/** @var ABJ_404_Solution_StatsRepositoryInterface */
	private $statsRepository;

	/** @var array<string,string> Latest table data signatures by subpage. */
	private $tableDataSignatures = array();

	/**
	 * Constructor with dependency injection.
	 * Dependencies are now explicit and visible.
	 *
	 * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
	 * @param mixed $dataAccessOrViewReadService Data access (legacy) or ViewReadService
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 */
	public function __construct($functions = null, $pluginLogic = null, $dataAccessOrViewReadService = null, $logging = null) {
		// Use injected dependencies or fall back to service container
		$this->f = $functions !== null ? $functions : abj_service('functions');
		$this->logic = $pluginLogic !== null ? $pluginLogic : abj_service('plugin_logic');
		$this->logger = $logging !== null ? $logging : abj_service('logging');

		// When a DataAccess facade is injected (legacy callers / tests),
		// use it directly for all module interfaces -- it delegates to the
		// real modules internally. This keeps existing tests working while
		// production code migrates to direct module calls.
		$dao = $dataAccessOrViewReadService;
		// A ViewReadService also exposes getRedirectsForView() but is NOT a
		// legacy DataAccess facade: it implements only ViewReadServiceInterface,
		// not the other five repository interfaces. The modern bootstrap.php
		// wiring passes a ViewReadService here and must take the else branch
		// so each repository field gets the correct dedicated service.
		// (Regression caught by ViewServiceBindingTest after the General
		// Settings admin tab crashed with "undefined method
		// ABJ_404_Solution_ViewReadService::getEarliestLogTimestamp()" on
		// 2026-05-23.)
		$isLegacyDao = ($dao !== null && is_object($dao)
			&& !($dao instanceof ABJ_404_Solution_ViewReadServiceInterface)
			&& (method_exists($dao, 'getRedirectsForView') || $dao instanceof ABJ_404_Solution_DataAccess));

		if ($isLegacyDao) {
			// DataAccess facade implements all module interfaces via delegation.
			// Tests may also pass a Mockery mock that handles all methods.
			/** @var ABJ_404_Solution_ViewReadServiceInterface&ABJ_404_Solution_ViewBuildOrchestratorInterface&ABJ_404_Solution_LogsRepositoryInterface&ABJ_404_Solution_RedirectsRepositoryInterface&ABJ_404_Solution_ContentRepositoryInterface&ABJ_404_Solution_StatsRepositoryInterface $dao */
			$this->viewReadService = $dao;
			$this->viewBuildOrchestrator = $dao;
			$this->logsRepository = $dao;
			$this->redirectsRepository = $dao;
			$this->contentRepository = $dao;
			$this->statsRepository = $dao;
		} else {
			// Resolve module dependencies from container. Container returns
			// DataAccess::getInstance() as a facade for all module services.
			/** @var ABJ_404_Solution_ViewReadServiceInterface $vrs */
			$vrs = abj_service('view_read_service');
			$this->viewReadService = $vrs;
			/** @var ABJ_404_Solution_ViewBuildOrchestratorInterface $vbo */
			$vbo = abj_service('view_build_orchestrator');
			$this->viewBuildOrchestrator = $vbo;
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
			$sr = abj_service('stats_repository');
			$this->statsRepository = $sr;
		}
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

}
