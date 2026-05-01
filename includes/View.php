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
require_once __DIR__ . '/ViewTrait_Logs.php';

class ABJ_404_Solution_View {

	use ViewTrait_Shared,
	    ViewTrait_UI,
	    ViewTrait_Stats,
	    ViewTrait_Settings,
	    ViewTrait_Redirects,
	    ViewTrait_RedirectsTable,
	    ViewTrait_Logs;

	/** @var self|null */
	private static $instance = null;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var array<string,string> Latest table data signatures by subpage. */
	private $tableDataSignatures = array();

	/**
	 * Constructor with dependency injection.
	 * Dependencies are now explicit and visible.
	 *
	 * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
	 * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 */
	public function __construct($functions = null, $pluginLogic = null, $dataAccess = null, $logging = null) {
		// Use injected dependencies or fall back to getInstance() for backward compatibility
		$this->f = $functions !== null ? $functions : abj_service('functions');
		$this->logic = $pluginLogic !== null ? $pluginLogic : abj_service('plugin_logic');
		$this->dao = $dataAccess !== null ? $dataAccess : abj_service('data_access');
		$this->logger = $logging !== null ? $logging : abj_service('logging');
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
