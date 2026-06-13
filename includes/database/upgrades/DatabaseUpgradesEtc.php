<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseUpgradeCoordinator.php';
require_once __DIR__ . '/DatabaseUpgradeComponent.php';
require_once __DIR__ . '/DatabaseUpgradeRuntimeState.php';
require_once __DIR__ . '/DatabaseUpgradesDependencies.php';
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

/* Functions in this class should all reference one of the following variables or support functions that do.
 *      $wpdb, $_GET, $_POST, $_SERVER, $_.*
 * everything $wpdb related.
 * everything $_GET, $_POST, (etc) related.
 * Read the database, Store to the database,
 */

class ABJ_404_Solution_DatabaseUpgradesEtc implements ABJ_404_Solution_DatabaseUpgradeCoordinator {

	/** @var self|null */
	private static $instance = null;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao;

	/** @var ABJ_404_Solution_DatabaseCore */
	private $dbCore;

	/** @var ABJ_404_Solution_ContentRepositoryInterface */
	private $contentRepo;

	/** @var ABJ_404_Solution_ViewBuildOrchestratorInterface */
	private $viewBuild;

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

	/** @var ABJ_404_Solution_DatabaseUpgradeNGram */
	private $nGramUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeEngineNormalization */
	private $engineNormalizationUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeCollationDrift */
	private $collationDriftUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeSelfHeal */
	private $selfHealUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill */
	private $canonicalUrlBackfillUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeDailyMaintenance */
	private $dailyMaintenanceUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradePluginUpdate */
	private $pluginUpdateUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeTableRepair */
	private $tableRepairUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeIndexes */
	private $indexesUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeOrphanAdoption */
	private $orphanAdoptionUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeMultiSite */
	private $multiSiteUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeSchemaDiff */
	private $schemaDiffUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeBootstrap */
	private $bootstrapUpgrade;

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

		$this->dbCore = $this->dao->getDbCore();
		$this->contentRepo = $this->dao->getContentRepo();
		$this->viewBuild = $this->dao->getViewBuildOrchestrator();
		$this->viewRead = $this->dao->getViewReadService();
		$this->logsRepo = $this->dao->getLogsRepo();

		$this->initializeUpgradeComponents();
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
	 * @return array<string, mixed>
	 */
	private function buildComponentDependencyMap(): array {
		return [
			'dao' => $this->dao,
			'dbCore' => $this->dbCore,
			'contentRepo' => $this->contentRepo,
			'viewBuild' => $this->viewBuild,
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
		];
	}

	/** @return void */
	private function initializeUpgradeComponents(): void {
		$deps = $this->buildComponentDependencyMap();
		$this->nGramUpgrade = new ABJ_404_Solution_DatabaseUpgradeNGram($this, $deps);
		$this->engineNormalizationUpgrade = new ABJ_404_Solution_DatabaseUpgradeEngineNormalization($this, $deps);
		$this->collationDriftUpgrade = new ABJ_404_Solution_DatabaseUpgradeCollationDrift($this, $deps);
		$this->selfHealUpgrade = new ABJ_404_Solution_DatabaseUpgradeSelfHeal($this, $deps);
		$this->canonicalUrlBackfillUpgrade = new ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill($this, $deps);
		$this->dailyMaintenanceUpgrade = new ABJ_404_Solution_DatabaseUpgradeDailyMaintenance($this, $deps);
		$this->pluginUpdateUpgrade = new ABJ_404_Solution_DatabaseUpgradePluginUpdate($this, $deps);
		$this->tableRepairUpgrade = new ABJ_404_Solution_DatabaseUpgradeTableRepair($this, $deps);
		$this->indexesUpgrade = new ABJ_404_Solution_DatabaseUpgradeIndexes($this, $deps);
		$this->orphanAdoptionUpgrade = new ABJ_404_Solution_DatabaseUpgradeOrphanAdoption($this, $deps);
		$this->multiSiteUpgrade = new ABJ_404_Solution_DatabaseUpgradeMultiSite($this, $deps);
		$this->schemaDiffUpgrade = new ABJ_404_Solution_DatabaseUpgradeSchemaDiff($this, $deps);
		$this->bootstrapUpgrade = new ABJ_404_Solution_DatabaseUpgradeBootstrap($this, $deps);
	}

	/** @return void */
	public function refreshUpgradeComponentDependencies(): void {
		$deps = $this->buildComponentDependencyMap();
		foreach ([
			$this->nGramUpgrade,
			$this->engineNormalizationUpgrade,
			$this->collationDriftUpgrade,
			$this->selfHealUpgrade,
			$this->canonicalUrlBackfillUpgrade,
			$this->dailyMaintenanceUpgrade,
			$this->pluginUpdateUpgrade,
			$this->tableRepairUpgrade,
			$this->indexesUpgrade,
			$this->orphanAdoptionUpgrade,
			$this->multiSiteUpgrade,
			$this->schemaDiffUpgrade,
			$this->bootstrapUpgrade,
		] as $component) {
			$component->replaceDatabaseUpgradeDependencies($deps);
		}
	}

	public function nGramUpgrade(): ABJ_404_Solution_DatabaseUpgradeNGram {
		return $this->nGramUpgrade;
	}

	public function engineNormalizationUpgrade(): ABJ_404_Solution_DatabaseUpgradeEngineNormalization {
		return $this->engineNormalizationUpgrade;
	}

	public function collationDriftUpgrade(): ABJ_404_Solution_DatabaseUpgradeCollationDrift {
		return $this->collationDriftUpgrade;
	}

	public function selfHealUpgrade(): ABJ_404_Solution_DatabaseUpgradeSelfHeal {
		return $this->selfHealUpgrade;
	}

	public function canonicalUrlBackfillUpgrade(): ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill {
		return $this->canonicalUrlBackfillUpgrade;
	}

	public function dailyMaintenanceUpgrade(): ABJ_404_Solution_DatabaseUpgradeDailyMaintenance {
		return $this->dailyMaintenanceUpgrade;
	}

	public function pluginUpdateUpgrade(): ABJ_404_Solution_DatabaseUpgradePluginUpdate {
		return $this->pluginUpdateUpgrade;
	}

	public function tableRepairUpgrade(): ABJ_404_Solution_DatabaseUpgradeTableRepair {
		return $this->tableRepairUpgrade;
	}

	public function indexesUpgrade(): ABJ_404_Solution_DatabaseUpgradeIndexes {
		return $this->indexesUpgrade;
	}

	public function orphanAdoptionUpgrade(): ABJ_404_Solution_DatabaseUpgradeOrphanAdoption {
		return $this->orphanAdoptionUpgrade;
	}

	public function multiSiteUpgrade(): ABJ_404_Solution_DatabaseUpgradeMultiSite {
		return $this->multiSiteUpgrade;
	}

	public function schemaDiffUpgrade(): ABJ_404_Solution_DatabaseUpgradeSchemaDiff {
		return $this->schemaDiffUpgrade;
	}

	public function bootstrapUpgrade(): ABJ_404_Solution_DatabaseUpgradeBootstrap {
		return $this->bootstrapUpgrade;
	}

	/**
	 * @param bool $updatingToNewVersion
	 * @return void
	 */
	public function createDatabaseTables($updatingToNewVersion = false, bool $force = false) {
		$this->bootstrapUpgrade->createDatabaseTables($updatingToNewVersion, $force);
	}

	/** @return void */
	public function runSelfHealPrologue() {
		$this->selfHealUpgrade->runSelfHealPrologue();
	}

	/** @return void */
	public function runDatabaseMaintenanceTasks() {
		$this->dailyMaintenanceUpgrade->runDatabaseMaintenanceTasks();
	}

	/** @return void */
	public function runDailyInsuranceCheck() {
		$this->selfHealUpgrade->runDailyInsuranceCheck();
	}

	/** @return void */
	public function verifyAndRepairCurrentSite() {
		$this->refreshUpgradeComponentDependencies();
		$this->selfHealUpgrade->verifyAndRepairCurrentSite();
	}

	/** @return array<string, mixed> */
	public function cleanupExpiredRateLimitTransients() {
		return $this->dailyMaintenanceUpgrade->cleanupExpiredRateLimitTransients();
	}

	/** @return bool */
	public function processMultisiteActivationBatch() {
		return $this->multiSiteUpgrade->processMultisiteActivationBatch();
	}

	/**
	 * @param int $alreadyProcessedBlogId
	 * @return void
	 */
	public function scheduleBackgroundMultisiteActivation(int $alreadyProcessedBlogId): void {
		$this->multiSiteUpgrade->scheduleBackgroundMultisiteActivation($alreadyProcessedBlogId);
	}

	/** @return bool */
	public function processMultisiteUpgradeBatch() {
		return $this->multiSiteUpgrade->processMultisiteUpgradeBatch();
	}

	/**
	 * @param int $alreadyProcessedBlogId
	 * @return void
	 */
	public function scheduleBackgroundMultisiteUpgrade(int $alreadyProcessedBlogId): void {
		$this->multiSiteUpgrade->scheduleBackgroundMultisiteUpgrade($alreadyProcessedBlogId);
	}

	/** @return void */
	public function createTablesForAllSites(): void {
		$this->multiSiteUpgrade->createTablesForAllSites();
	}

	/** @return bool */
	public function scheduleNGramCacheRebuild() {
		$this->refreshUpgradeComponentDependencies();
		return $this->nGramUpgrade->scheduleNGramCacheRebuild();
	}

	/**
	 * @param int $offset
	 * @return void
	 */
	public function rebuildNGramCacheAsync($offset = 0) {
		$this->refreshUpgradeComponentDependencies();
		$this->nGramUpgrade->rebuildNGramCacheAsync($offset);
	}

	/** @return void */
	public function scheduleLogsv2CanonicalUrlBackfill() {
		$this->canonicalUrlBackfillUpgrade->scheduleLogsv2CanonicalUrlBackfill();
	}

	/** @return int */
	public function backfillLogsv2CanonicalUrl() {
		return $this->canonicalUrlBackfillUpgrade->backfillLogsv2CanonicalUrl();
	}

	/** @return void */
	public function correctCollations() {
		$this->collationDriftUpgrade->correctCollations();
	}

	/** @return void */
	public function updatePluginCheck() {
		$this->pluginUpdateUpgrade->updatePluginCheck();
	}

	/** @return int */
	public function backfillRedirectsCanonicalUrl() { return $this->canonicalUrlBackfillUpgrade->backfillRedirectsCanonicalUrl(); }

	/**
	 * @param int $batchSize
	 * @param bool $forceRebuild
	 * @return array<string, mixed>
	 */
	public function rebuildNGramCache($batchSize = 100, $forceRebuild = false) {
		$this->refreshUpgradeComponentDependencies();
		return $this->nGramUpgrade->rebuildNGramCache($batchSize, $forceRebuild);
	}

	/**
	 * @param int $batchSize
	 * @return array<string, mixed>
	 */
	public function syncMissingNGrams($batchSize = 50) {
		$this->refreshUpgradeComponentDependencies();
		return $this->nGramUpgrade->syncMissingNGrams($batchSize);
	}

	/**
	 * @param int $batchSize
	 * @return array<string, mixed>
	 */
	public function buildNGramsForAllContent($batchSize = 100) {
		$this->refreshUpgradeComponentDependencies();
		return $this->nGramUpgrade->buildNGramsForAllContent($batchSize);
	}

	/**
	 * @param string $option_name
	 * @param mixed $default
	 * @return mixed
	 */
	public function getNetworkAwareOption($option_name, $default = false) { return $this->nGramUpgrade->getNetworkAwareOption($option_name, $default); }

	/** @return int */
	public function countTotalPagesForNGramRebuild() {
		$this->refreshUpgradeComponentDependencies();
		return $this->nGramUpgrade->countTotalPagesForNGramRebuild();
	}

	/** @return void */
	public function updateTableEngineToInnoDB() { $this->engineNormalizationUpgrade->updateTableEngineToInnoDB(); }

	/** @return void */
	public function createIndexes() { $this->indexesUpgrade->createIndexes(); }

	/**
	 * @param string $tableName
	 * @param string $createTableStatementGoal
	 * @return void
	 */
	public function verifyIndexes($tableName, $createTableStatementGoal) {
		$this->indexesUpgrade->verifyIndexes($tableName, $createTableStatementGoal);
	}

	/** @return void */
	public function runInitialCreateTables() { $this->bootstrapUpgrade->runInitialCreateTables(); }

	/** @return void */
	public function renameAbj404TablesToLowerCase() { $this->bootstrapUpgrade->renameAbj404TablesToLowerCase(); }

	/** @return array<int, array{placeholder: string, bareTableName: string, ddlContent: string}> */
	public function discoverPermanentDDLFiles(): array { return $this->bootstrapUpgrade->discoverPermanentDDLFiles(); }

	/**
	 * @param mixed $createTableSql
	 * @return mixed
	 */
	public function applyPluginTableCharsetCollate($createTableSql) {
		if (!is_string($createTableSql)) {
			return $createTableSql;
		}
		return $this->bootstrapUpgrade->applyPluginTableCharsetCollate($createTableSql);
	}

	/** @return void */
	public function correctIssuesBefore() { $this->tableRepairUpgrade->correctIssuesBefore(); }

	/** @return void */
	public function correctIssuesAfter() { $this->tableRepairUpgrade->correctIssuesAfter(); }

	/** @return void */
	public function repairStrippedViewCacheTable() {
		$this->refreshUpgradeComponentDependencies();
		$this->tableRepairUpgrade->repairStrippedViewCacheTable();
	}

	/** @return array<string, mixed> */
	public function migrateURLsToRelativePaths() { return $this->pluginUpdateUpgrade->migrateURLsToRelativePaths(); }

	/**
	 * @param array<string, mixed> $pluginInfo
	 * @return bool
	 */
	public function shouldUpdate($pluginInfo) { return $this->pluginUpdateUpgrade->shouldUpdate($pluginInfo); }

	/**
	 * @param string $tableName
	 * @param string $createTableStatementGoal
	 * @return void
	 */
	public function verifyColumns($tableName, $createTableStatementGoal) {
		$this->refreshUpgradeComponentDependencies();
		$this->schemaDiffUpgrade->verifyColumns($tableName, $createTableStatementGoal);
	}

	/**
	 * @param string $tableName
	 * @param string $createTableStatementGoal
	 * @return array<string, mixed>
	 */
	public function getTableDifferences($tableName, $createTableStatementGoal) {
		$this->refreshUpgradeComponentDependencies();
		return $this->schemaDiffUpgrade->getTableDifferences($tableName, $createTableStatementGoal);
	}

	/**
	 * @param string $tableName
	 * @param array<string, mixed> $tableDifferences
	 * @return void
	 */
	public function updateATableBasedOnDifferences($tableName, $tableDifferences) {
		$this->refreshUpgradeComponentDependencies();
		$this->schemaDiffUpgrade->updateATableBasedOnDifferences($tableName, $tableDifferences);
	}

	/**
	 * @param string|null $createTableDDL
	 * @return string
	 */
	public function removeCommentsFromColumns($createTableDDL) { return $this->schemaDiffUpgrade->removeCommentsFromColumns($createTableDDL); }

	/**
	 * @param mixed $ddl
	 * @return string
	 */
	public function normalizeColumnDDL($ddl) { return $this->schemaDiffUpgrade->normalizeColumnDDL($ddl); }

	/** @return string|null */
	public function getUpgradeRuntimeId() {
		return ABJ_404_Solution_DatabaseUpgradeRuntimeState::getRuntimeId();
	}

	public function isLogsv2CanonicalBackfillScheduled(): bool {
		return ABJ_404_Solution_DatabaseUpgradeRuntimeState::isLogsv2CanonicalBackfillScheduled();
	}

	public function setLogsv2CanonicalBackfillScheduled(bool $scheduled): void {
		ABJ_404_Solution_DatabaseUpgradeRuntimeState::setLogsv2CanonicalBackfillScheduled($scheduled);
	}

	public function getCanonicalUrlBackfillChunkSize(): int {
		return ABJ_404_Solution_DatabaseUpgradeRuntimeState::CANONICAL_URL_BACKFILL_CHUNK_SIZE;
	}

	public function getCanonicalUrlBackfillTimeBudgetSec(): float {
		return ABJ_404_Solution_DatabaseUpgradeRuntimeState::CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC;
	}

	public function getLogsv2CanonicalUrlBackfillTimeBudgetSec(): float {
		return ABJ_404_Solution_DatabaseUpgradeRuntimeState::LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC;
	}

	public function getLogsv2CanonicalUrlBackfillCompleteOption(): string {
		return ABJ_404_Solution_DatabaseUpgradeRuntimeState::LOGSV2_CANONICAL_URL_BACKFILL_COMPLETE_OPTION;
	}

	public function getRedirectsCanonicalUrlBackfillCompleteOption(): string {
		return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_CANONICAL_URL_BACKFILL_COMPLETE_OPTION;
	}

	/** @return array<int, string> */
	public function getPluginTableSuffixes(): array {
		return ABJ_404_Solution_DatabaseUpgradeRuntimeState::getPluginTableSuffixes();
	}

	public static function resetLogsv2CanonicalBackfillScheduledFlagForTests(): void {
		ABJ_404_Solution_DatabaseUpgradeRuntimeState::resetLogsv2CanonicalBackfillScheduledFlagForTests();
	}

	public static function getLogsv2CanonicalBackfillScheduledFlagForTests(): bool {
		return ABJ_404_Solution_DatabaseUpgradeRuntimeState::isLogsv2CanonicalBackfillScheduled();
	}

}
