<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseUpgradeCoordinator.php';
require_once __DIR__ . '/DatabaseUpgradeComponent.php';
require_once __DIR__ . '/DatabaseUpgradeRuntimeState.php';
require_once __DIR__ . '/DatabaseUpgradesDependencies.php';
require_once __DIR__ . '/DatabaseUpgradeComponentRegistry.php';
require_once __DIR__ . '/DatabaseUpgradeNGram.php';
require_once __DIR__ . '/DatabaseUpgradeEngineNormalization.php';
require_once __DIR__ . '/DatabaseUpgradeCollationDrift.php';
require_once __DIR__ . '/DatabaseUpgradeSelfHeal.php';
require_once __DIR__ . '/DatabaseUpgradeCanonicalUrlBackfill.php';
require_once __DIR__ . '/DatabaseUpgradeDailyMaintenance.php';
require_once __DIR__ . '/DatabaseUpgradePluginUpdate.php';
require_once __DIR__ . '/DatabaseUpgradeTableRepair.php';
require_once __DIR__ . '/DatabaseUpgradeIndexes.php';
require_once __DIR__ . '/DatabaseUpgradeOrphanAdoption.php';
require_once __DIR__ . '/DatabaseUpgradeMultiSite.php';
require_once __DIR__ . '/DatabaseUpgradeSchemaDiff.php';
require_once __DIR__ . '/DatabaseUpgradeBootstrap.php';

/**
 * Public entry point for the database-upgrade subsystem.
 *
 * This is a thin facade: it owns the component registry and the shared
 * dependency map, and hands callers the registry through {@see self::components()}.
 * All upgrade operations live on the 13 component classes (reached via the
 * registry's typed accessors); all runtime-state values live on
 * {@see ABJ_404_Solution_DatabaseUpgradeRuntimeState}. Callers run an operation
 * with, for example:
 *
 *     ABJ_404_Solution_DatabaseUpgradesEtc::getInstance()
 *         ->components()->bootstrapUpgrade()->createDatabaseTables();
 *
 * `components()` refreshes the dependency map before returning the registry, so
 * every component access sees the current collaborators (this preserves the old
 * per-operation "refresh then delegate" behavior in a single place).
 */
class ABJ_404_Solution_DatabaseUpgradesEtc {

	/** @var self|null */
	private static $instance = null;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao;

	/** @var ABJ_404_Solution_DatabaseCore */
	private $dbCore;

	/** @var ABJ_404_Solution_ContentRepositoryInterface */
	private $contentRepo;

	/** @var ABJ_404_Solution_ViewReadServiceInterface */
	private $viewRead;

	/** @var ABJ_404_Solution_LogsRepositoryInterface */
	private $logsRepo;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PermalinkCache */
	private $permalinkCache;

	/** @var ABJ_404_Solution_SynchronizationUtils */
	private $syncUtils;

	/** @var ABJ_404_Solution_PluginLogicInterface */
	private $logic;

	/** @var ABJ_404_Solution_NGramFilter */
	private $ngramFilter;

	/** @var mixed */
	private $ngramExtractor;

	/** @var mixed */
	private $ngramCacheRepository;

	/** @var mixed */
	private $ngramCoveragePolicy;

	/** @var mixed */
	private $ngramRebuilder;

	/** @var ABJ_404_Solution_CronScheduler|null */
	private $cronScheduler;

	/** @var ABJ_404_Solution_DatabaseUpgradeComponentRegistry */
	private $components;

	/**
	 * Constructor with dependency injection.
	 *
	 * @param ABJ_404_Solution_DatabaseUpgradesDependencies|null $dependencies Upgrade facade collaborators.
	 */
	public function __construct(?ABJ_404_Solution_DatabaseUpgradesDependencies $dependencies = null) {
		$dependencies = $dependencies !== null ? $dependencies : new ABJ_404_Solution_DatabaseUpgradesDependencies();

		$this->dao = $dependencies->getDataAccess();
		$this->logger = $dependencies->getLogging();
		$this->f = $dependencies->getFunctions();
		$this->permalinkCache = $dependencies->getPermalinkCache();
		$this->syncUtils = $dependencies->getSyncUtils();
		$this->logic = $dependencies->getPluginLogic();
		$this->ngramFilter = $dependencies->getNGramFilter();
		$this->ngramExtractor = $dependencies->getNGramExtractor();
		$this->ngramCacheRepository = $dependencies->getNGramCacheRepository();
		$this->ngramCoveragePolicy = $dependencies->getNGramCoveragePolicy();
		$this->ngramRebuilder = $dependencies->getNGramRebuilder();
		$this->cronScheduler = $dependencies->getCronScheduler();

		$this->dbCore = $this->dao->getDbCore();
		$this->contentRepo = $this->dao->getContentRepo();
		$this->viewRead = $this->dao->getViewReadService();
		$this->logsRepo = $this->dao->getLogsRepo();

		$this->components = new ABJ_404_Solution_DatabaseUpgradeComponentRegistry($this->buildComponentDependencyMap());
	}

	/**
	 * Return the current singleton instance without consulting the container
	 * or building a new one. Mirrors the peekInstance pattern on
	 * PluginLogic / Logging / DataAccess so abj_service() can honor a
	 * caller-installed singleton override.
	 *
	 * @return self|null
	 */
	public static function peekInstance() {
		return self::$instance;
	}

	/**
	 * Install a singleton instance directly. Symmetric with `peekInstance()`;
	 * the canonical seam for tests that need to swap in a test double, and
	 * for callers that have already constructed a fully configured instance.
	 * Pass `null` to clear the cached singleton.
	 *
	 * @param self|null $instance
	 * @return void
	 */
	public static function setInstance($instance) {
		self::$instance = $instance;
	}

	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_DatabaseUpgradesEtc();
			ABJ_404_Solution_DatabaseUpgradeRuntimeState::initializeRuntimeId();
		}

		return self::$instance;
	}

	/**
	 * Refresh component dependencies, then return the component registry.
	 *
	 * This is the single public seam external callers use to reach an upgrade
	 * component. Refreshing here means every component access sees the current
	 * collaborators (e.g. after a test swaps in a mock DataAccess), which
	 * preserves the behavior of the former per-operation refresh-then-delegate
	 * methods in one place.
	 *
	 * @return ABJ_404_Solution_DatabaseUpgradeComponentRegistry
	 */
	public function components(): ABJ_404_Solution_DatabaseUpgradeComponentRegistry {
		$this->refreshUpgradeComponentDependencies();
		return $this->components;
	}

	// -------------------------------------------------------------------------
	// Lifecycle entry points.
	//
	// These are the facade's concrete public API: the operations the rest of the
	// plugin (boot, cron, activation, retention) invokes. Each delegates to the
	// component that owns the work. They are intentionally explicit methods (not
	// a __call bridge or string registry) and their presence is enforced by
	// DatabaseUpgradeCompositionGateTest::testProductionLifecycleMethodsAreConcreteFacadeMethods.
	// Non-lifecycle component operations are reached through components().
	// -------------------------------------------------------------------------

	/**
	 * @param bool $updatingToNewVersion
	 * @return void
	 */
	public function createDatabaseTables($updatingToNewVersion = false) {
		$this->components()->bootstrapUpgrade()->createDatabaseTables($updatingToNewVersion);
	}

	/** @return void */
	public function correctCollations() {
		$this->components()->collationDriftUpgrade()->correctCollations();
	}

	/** @return void */
	public function runSelfHealPrologue() {
		$this->components()->selfHealUpgrade()->runSelfHealPrologue();
	}

	/**
	 * Bounded, never-throwing missing-table repair for a user-facing request.
	 *
	 * @return void
	 */
	public function repairMissingTablesForRequest() {
		$this->components()->selfHealUpgrade()->repairMissingTablesForRequest();
	}

	/** @return void */
	public function runDatabaseMaintenanceTasks() {
		$this->components()->dailyMaintenanceUpgrade()->runDatabaseMaintenanceTasks();
	}

	/** @return void */
	public function updatePluginCheck() {
		$this->components()->pluginUpdateUpgrade()->updatePluginCheck();
	}

	/** @return mixed */
	public function scheduleNGramCacheRebuild() {
		return $this->components()->nGramUpgrade()->scheduleNGramCacheRebuild();
	}

	/**
	 * @param int $offset
	 * @return void
	 */
	public function rebuildNGramCacheAsync($offset = 0) {
		$this->components()->nGramUpgrade()->rebuildNGramCacheAsync($offset);
	}

	/** @return bool */
	public function processMultisiteActivationBatch(): bool {
		return $this->components()->multiSiteUpgrade()->processMultisiteActivationBatch();
	}

	/** @return bool */
	public function processMultisiteUpgradeBatch(): bool {
		return $this->components()->multiSiteUpgrade()->processMultisiteUpgradeBatch();
	}

	/** @return int */
	public function backfillLogsv2CanonicalUrl(): int {
		return $this->components()->canonicalUrlBackfillUpgrade()->backfillLogsv2CanonicalUrl();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildComponentDependencyMap(): array {
		return [
			'dao' => $this->dao,
			'dbCore' => $this->dbCore,
			'contentRepo' => $this->contentRepo,
			'viewRead' => $this->viewRead,
			'logsRepo' => $this->logsRepo,
			'logger' => $this->logger,
			'f' => $this->f,
			'permalinkCache' => $this->permalinkCache,
			'syncUtils' => $this->syncUtils,
			'logic' => $this->logic,
			'ngramFilter' => $this->ngramFilter,
			'ngramExtractor' => $this->ngramExtractor,
			'ngramCacheRepository' => $this->ngramCacheRepository,
			'ngramCoveragePolicy' => $this->ngramCoveragePolicy,
			'ngramRebuilder' => $this->ngramRebuilder,
			'cronScheduler' => $this->cronScheduler,
		];
	}

	/**
	 * Rebuild the dependency map from the facade's current collaborators and
	 * push it to every component. Called by {@see self::components()} on each
	 * access; also a public seam tests use to re-sync components after swapping
	 * the DAO on the facade.
	 *
	 * @return void
	 */
	public function refreshUpgradeComponentDependencies(): void {
		$this->components->refreshDependencies($this->buildComponentDependencyMap());
	}
}
