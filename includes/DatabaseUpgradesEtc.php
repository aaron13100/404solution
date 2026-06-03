<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseUpgradeCoordinator.php';
require_once __DIR__ . '/DatabaseUpgradeComponent.php';
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

/**
 * Sub-component methods reached via __call -> invokeDatabaseUpgradeMethod().
 * Listed here so static analysis and IDEs recognize them on this facade.
 *
 * @method mixed createIndexes()
 * @method mixed verifyIndexes($tableName, $createTableStatementGoal)
 * @method mixed indexExists($tableName, $indexName)
 * @method mixed parseIndexDDLToSpec($indexDDL)
 * @method mixed parseIndexSpecsFromCreateTableSql($createTableSql)
 * @method mixed buildAddIndexStatementFromParts($tableName, $indexName, $columnsSql, $unique)
 * @method mixed ensureLogsCompositeIndex($logsTable, $createSqlOverride = null)
 * @method mixed ensureLogsv2CanonicalUrlColumn(string $logsTable)
 * @method mixed ensureRedirectsCanonicalUrlColumn(string $redirectsTable)
 * @method mixed updateTableEngineToInnoDB()
 * @method mixed getTableCollation($tableName)
 * @method mixed getTableCollationFromShowCreate($tableName)
 * @method mixed getTableCollationFromInformationSchema($tableName)
 * @method mixed getDefaultCollationForCharset($charset)
 * @method mixed sanitizeCollationIdentifier($collation)
 * @method mixed resolveTargetUtf8mb4Collation($tableNames, $tableCollations = [])
 * @method mixed correctCollations()
 * @method mixed tableHasMismatchedCharacterColumnCollation($tableName, $targetCharset, $targetCollation)
 * @method mixed runDailyInsuranceCheck()
 * @method mixed runSelfHealPrologue()
 * @method mixed verifyAndRepairCurrentSite()
 * @method mixed cleanupExpiredRateLimitTransients()
 * @method mixed runDatabaseMaintenanceTasks()
 * @method mixed refreshViewDoneSnapshotInline()
 * @method mixed backfillRedirectsCanonicalUrl()
 * @method mixed backfillLogsv2CanonicalUrl()
 * @method mixed scheduleLogsv2CanonicalUrlBackfill()
 * @method mixed shouldScheduleLogsv2CanonicalBackfillViaCron()
 * @method mixed columnExists(string $tableName, string $columnName)
 * @method mixed scheduleBackgroundMultisiteBatch(string $optionPrefix, string $hookName, string $label, int $alreadyProcessedBlogId)
 * @method mixed processMultisiteBatch(string $optionPrefix, string $hookName, string $label, callable $perSiteAction)
 * @method mixed scheduleBackgroundMultisiteActivation(int $alreadyProcessedBlogId)
 * @method mixed processMultisiteActivationBatch()
 * @method mixed scheduleBackgroundMultisiteUpgrade(int $alreadyProcessedBlogId)
 * @method mixed processMultisiteUpgradeBatch()
 * @method mixed createTablesForAllSites()
 * @method mixed scheduleNGramCacheRebuild()
 * @method mixed rebuildNGramCacheAsync($offset = 0)
 * @method mixed rebuildNGramCache($batchSize = 100, $forceRebuild = false)
 * @method mixed syncMissingNGrams($batchSize = 50)
 * @method mixed cleanupOrphanedNGrams()
 * @method mixed buildNGramsForCategories($batchSize = 50)
 * @method mixed buildNGramsForTags($batchSize = 50)
 * @method mixed buildNGramsForAllContent($batchSize = 100)
 * @method mixed rebuildNGramCacheAsyncMultisite(int $batchSize, int $maxBatchesPerRun)
 * @method mixed rebuildNGramCacheAsyncSingleSite(int $batchSize, int $maxBatchesPerRun)
 * @method mixed isNetworkActivated()
 * @method mixed getNetworkAwareOption($option_name, $default = false)
 * @method mixed updateNetworkAwareOption($option_name, $value)
 * @method mixed countTotalPagesForNGramRebuild()
 * @method mixed adoptOrphanedTables()
 * @method mixed countOldPrefixRows(string $oldPrefix, array<int, string> $knownTables)
 * @method mixed verifyOwnershipViaLogs(string $oldPrefix)
 * @method mixed verifyOwnershipViaRedirects(string $oldPrefix)
 * @method mixed adoptDataFromPrefix(string $oldPrefix, string $currentPrefix, array<int, string> $knownTables)
 * @method mixed getCommonColumns(string $tableA, string $tableB)
 * @method mixed getTableColumns(string $tableName)
 * @method mixed migrateURLsToRelativePaths()
 * @method mixed updatePluginCheck()
 * @method mixed doUpdatePlugin($pluginInfo)
 * @method mixed shouldUpdate($pluginInfo)
 * @method mixed verifyColumns($tableName, $createTableStatementGoal)
 * @method mixed getTableDifferences($tableName, $createTableStatementGoal)
 * @method mixed updateATableBasedOnDifferences($tableName, $tableDifferences)
 * @method mixed removeCommentsFromColumns($createTableDDL)
 * @method mixed normalizeColumnDDL($ddl)
 * @method mixed deleteIndexes($tableName)
 * @method mixed correctIssuesBefore()
 * @method mixed correctIssuesAfter()
 * @method mixed dropDeprecatedMutationWatermarkTable()
 * @method mixed repairStrippedViewCacheTable()
 * @method mixed ddlDeclaresIdColumn(string $ddl)
 * @method mixed recoverMissingLogsHitsTable()
 * @method mixed correctMatchData()
 * @method void createDatabaseTables($updatingToNewVersion = false, bool $force = false)
 * @method void renameAbj404TablesToLowerCase()
 * @method void handleSpecificCases($tableName, $colName)
 * @method array<int, array{placeholder: string, bareTableName: string, ddlContent: string}> discoverPermanentDDLFiles()
 * @method void runInitialCreateTables()
 * @method string applyPluginTableCharsetCollate($createTableSql)
 */
class ABJ_404_Solution_DatabaseUpgradesEtc implements ABJ_404_Solution_DatabaseUpgradeCoordinator {

	/** @var self|null */
	private static $instance = null;

	/** @var string|null */
	private static $uniqID = null;

	/**
	 * Per-request dedup flag for scheduleLogsv2CanonicalUrlBackfill().
	 * Mirrors DataAccess::$hitsTableRebuildScheduled. Ensures the shutdown
	 * hook is registered at most once per request even if the schedule
	 * function is called from multiple paths (Captured-404s tab render +
	 * Stats panel + EmailDigest, etc.). Reset to false naturally when the
	 * PHP process ends; persistent SAPIs (PHP-FPM, mod_php) reset it
	 * implicitly between requests because static is process-local.
	 *
	 * @var bool
	 */
	private static $logsv2CanonicalBackfillScheduled = false;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao;

	/** @var ABJ_404_Solution_DatabaseCoreInterface */
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
	 * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer (carries the repo composition the upgrade components resolve from)
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 * @param ABJ_404_Solution_Functions|null $functions String utilities
	 * @param ABJ_404_Solution_PermalinkCache|null $permalinkCache Permalink cache service
	 * @param ABJ_404_Solution_SynchronizationUtils|null $syncUtils Sync utilities
	 * @param ABJ_404_Solution_PluginLogicInterface|null $pluginLogic Business logic service
	 * @param ABJ_404_Solution_NGramFilter|null $ngramFilter N-gram filter service
	 */
	public function __construct($dataAccess = null, $logging = null, $functions = null, $permalinkCache = null, $syncUtils = null, $pluginLogic = null, $ngramFilter = null) {
		// Use injected dependencies or fall back to getInstance() for backward compatibility
		$this->dao = $dataAccess !== null ? $dataAccess : abj_service('data_access');
		$this->logger = $logging !== null ? $logging : abj_service('logging');
		$this->f = $functions !== null ? $functions : abj_service('functions');
		$this->permalinkCache = $permalinkCache !== null ? $permalinkCache : abj_service('permalink_cache');
		$this->syncUtils = $syncUtils !== null ? $syncUtils : abj_service('sync_utils');
		$this->logic = $pluginLogic !== null ? $pluginLogic : abj_service('plugin_logic');
		$this->ngramFilter = $ngramFilter !== null ? $ngramFilter : abj_service('ngram_filter');

		$daoClass = is_object($this->dao) ? get_class($this->dao) : '';
		$this->dbCore = ($dataAccess !== null && $daoClass !== 'ABJ_404_Solution_DataAccess'
			&& method_exists($this->dao, 'queryAndGetResults') && method_exists($this->dao, 'doTableNameReplacements'))
			? $this->dao
			: $this->dao->getDbCore();
		$this->contentRepo = $this->dao->getContentRepo();
		$this->viewBuild = $this->dao->getViewBuildOrchestrator();
		$this->viewRead = $this->dao->getViewReadService();
		$this->logsRepo = $this->dao->getLogsRepo();

		$componentDeps = $this->buildComponentDependencyMap();
		$this->nGramUpgrade = new ABJ_404_Solution_DatabaseUpgradeNGram($this, $componentDeps);
		$this->engineNormalizationUpgrade = new ABJ_404_Solution_DatabaseUpgradeEngineNormalization($this, $componentDeps);
		$this->collationDriftUpgrade = new ABJ_404_Solution_DatabaseUpgradeCollationDrift($this, $componentDeps);
		$this->selfHealUpgrade = new ABJ_404_Solution_DatabaseUpgradeSelfHeal($this, $componentDeps);
		$this->canonicalUrlBackfillUpgrade = new ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill($this, $componentDeps);
		$this->dailyMaintenanceUpgrade = new ABJ_404_Solution_DatabaseUpgradeDailyMaintenance($this, $componentDeps);
		$this->pluginUpdateUpgrade = new ABJ_404_Solution_DatabaseUpgradePluginUpdate($this, $componentDeps);
		$this->tableRepairUpgrade = new ABJ_404_Solution_DatabaseUpgradeTableRepair($this, $componentDeps);
		$this->indexesUpgrade = new ABJ_404_Solution_DatabaseUpgradeIndexes($this, $componentDeps);
		$this->orphanAdoptionUpgrade = new ABJ_404_Solution_DatabaseUpgradeOrphanAdoption($this, $componentDeps);
		$this->multiSiteUpgrade = new ABJ_404_Solution_DatabaseUpgradeMultiSite($this, $componentDeps);
		$this->schemaDiffUpgrade = new ABJ_404_Solution_DatabaseUpgradeSchemaDiff($this, $componentDeps);
		$this->bootstrapUpgrade = new ABJ_404_Solution_DatabaseUpgradeBootstrap($this, $componentDeps);
	}

	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_DatabaseUpgradesEtc();
			self::$uniqID = uniqid("", true);
		}

		return self::$instance;
	}

	/**
	 * Invoke a DatabaseUpgradesEtc method or delegate method by name.
	 *
	 * @param string $method
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	public function invokeDatabaseUpgradeMethod(string $method, array $args = []) {
		$delegateMap = [
			'createIndexes' => 'indexesUpgrade',
			'verifyIndexes' => 'indexesUpgrade',
			'indexExists' => 'indexesUpgrade',
			'parseIndexDDLToSpec' => 'indexesUpgrade',
			'parseIndexSpecsFromCreateTableSql' => 'indexesUpgrade',
			'buildAddIndexStatementFromParts' => 'indexesUpgrade',
			'ensureLogsCompositeIndex' => 'indexesUpgrade',
			'ensureLogsv2CanonicalUrlColumn' => 'indexesUpgrade',
			'ensureRedirectsCanonicalUrlColumn' => 'indexesUpgrade',
			'updateTableEngineToInnoDB' => 'engineNormalizationUpgrade',
			'getTableCollation' => 'collationDriftUpgrade',
			'getTableCollationFromShowCreate' => 'collationDriftUpgrade',
			'getTableCollationFromInformationSchema' => 'collationDriftUpgrade',
			'getDefaultCollationForCharset' => 'collationDriftUpgrade',
			'sanitizeCollationIdentifier' => 'collationDriftUpgrade',
			'resolveTargetUtf8mb4Collation' => 'collationDriftUpgrade',
			'correctCollations' => 'collationDriftUpgrade',
			'tableHasMismatchedCharacterColumnCollation' => 'collationDriftUpgrade',
			'runDailyInsuranceCheck' => 'selfHealUpgrade',
			'runSelfHealPrologue' => 'selfHealUpgrade',
			'verifyAndRepairCurrentSite' => 'selfHealUpgrade',
			'cleanupExpiredRateLimitTransients' => 'dailyMaintenanceUpgrade',
			'runDatabaseMaintenanceTasks' => 'dailyMaintenanceUpgrade',
			'refreshViewDoneSnapshotInline' => 'dailyMaintenanceUpgrade',
			'backfillRedirectsCanonicalUrl' => 'canonicalUrlBackfillUpgrade',
			'backfillLogsv2CanonicalUrl' => 'canonicalUrlBackfillUpgrade',
			'scheduleLogsv2CanonicalUrlBackfill' => 'canonicalUrlBackfillUpgrade',
			'shouldScheduleLogsv2CanonicalBackfillViaCron' => 'canonicalUrlBackfillUpgrade',
			'columnExists' => 'canonicalUrlBackfillUpgrade',
			'scheduleBackgroundMultisiteBatch' => 'multiSiteUpgrade',
			'processMultisiteBatch' => 'multiSiteUpgrade',
			'scheduleBackgroundMultisiteActivation' => 'multiSiteUpgrade',
			'processMultisiteActivationBatch' => 'multiSiteUpgrade',
			'scheduleBackgroundMultisiteUpgrade' => 'multiSiteUpgrade',
			'processMultisiteUpgradeBatch' => 'multiSiteUpgrade',
			'createTablesForAllSites' => 'multiSiteUpgrade',
			'scheduleNGramCacheRebuild' => 'nGramUpgrade',
			'rebuildNGramCacheAsync' => 'nGramUpgrade',
			'rebuildNGramCache' => 'nGramUpgrade',
			'syncMissingNGrams' => 'nGramUpgrade',
			'cleanupOrphanedNGrams' => 'nGramUpgrade',
			'buildNGramsForCategories' => 'nGramUpgrade',
			'buildNGramsForTags' => 'nGramUpgrade',
			'buildNGramsForAllContent' => 'nGramUpgrade',
			'rebuildNGramCacheAsyncMultisite' => 'nGramUpgrade',
			'rebuildNGramCacheAsyncSingleSite' => 'nGramUpgrade',
			'isNetworkActivated' => 'nGramUpgrade',
			'getNetworkAwareOption' => 'nGramUpgrade',
			'updateNetworkAwareOption' => 'nGramUpgrade',
			'countTotalPagesForNGramRebuild' => 'nGramUpgrade',
			'adoptOrphanedTables' => 'orphanAdoptionUpgrade',
			'countOldPrefixRows' => 'orphanAdoptionUpgrade',
			'verifyOwnershipViaLogs' => 'orphanAdoptionUpgrade',
			'verifyOwnershipViaRedirects' => 'orphanAdoptionUpgrade',
			'adoptDataFromPrefix' => 'orphanAdoptionUpgrade',
			'getCommonColumns' => 'orphanAdoptionUpgrade',
			'getTableColumns' => 'orphanAdoptionUpgrade',
			'migrateURLsToRelativePaths' => 'pluginUpdateUpgrade',
			'updatePluginCheck' => 'pluginUpdateUpgrade',
			'doUpdatePlugin' => 'pluginUpdateUpgrade',
			'shouldUpdate' => 'pluginUpdateUpgrade',
			'verifyColumns' => 'schemaDiffUpgrade',
			'getTableDifferences' => 'schemaDiffUpgrade',
			'updateATableBasedOnDifferences' => 'schemaDiffUpgrade',
			'removeCommentsFromColumns' => 'schemaDiffUpgrade',
			'normalizeColumnDDL' => 'schemaDiffUpgrade',
			'deleteIndexes' => 'schemaDiffUpgrade',
			'correctIssuesBefore' => 'tableRepairUpgrade',
			'correctIssuesAfter' => 'tableRepairUpgrade',
			'dropDeprecatedMutationWatermarkTable' => 'tableRepairUpgrade',
			'repairStrippedViewCacheTable' => 'tableRepairUpgrade',
			'ddlDeclaresIdColumn' => 'tableRepairUpgrade',
			'recoverMissingLogsHitsTable' => 'tableRepairUpgrade',
			'correctMatchData' => 'tableRepairUpgrade',
			'createDatabaseTables' => 'bootstrapUpgrade',
			'renameAbj404TablesToLowerCase' => 'bootstrapUpgrade',
			'handleSpecificCases' => 'bootstrapUpgrade',
			'discoverPermanentDDLFiles' => 'bootstrapUpgrade',
			'runInitialCreateTables' => 'bootstrapUpgrade',
			'applyPluginTableCharsetCollate' => 'bootstrapUpgrade',
		];

		if (isset($delegateMap[$method])) {
			$delegate = $delegateMap[$method];
			$this->refreshDatabaseUpgradeComponents();
			return $this->$delegate->invokeDatabaseUpgradeMethod($method, $args);
		}

		if (method_exists($this, $method)) {
			return $this->$method(...$args);
		}

		throw new BadMethodCallException("Database upgrade method not found: {$method}");
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
		];
	}

	/** @return void */
	private function refreshDatabaseUpgradeComponents() {
		$componentDeps = $this->buildComponentDependencyMap();

		if (!$this->nGramUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeNGram) {
			$this->nGramUpgrade = new ABJ_404_Solution_DatabaseUpgradeNGram($this, $componentDeps);
		}
		if (!$this->engineNormalizationUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeEngineNormalization) {
			$this->engineNormalizationUpgrade = new ABJ_404_Solution_DatabaseUpgradeEngineNormalization($this, $componentDeps);
		}
		if (!$this->collationDriftUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeCollationDrift) {
			$this->collationDriftUpgrade = new ABJ_404_Solution_DatabaseUpgradeCollationDrift($this, $componentDeps);
		}
		if (!$this->selfHealUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeSelfHeal) {
			$this->selfHealUpgrade = new ABJ_404_Solution_DatabaseUpgradeSelfHeal($this, $componentDeps);
		}
		if (!$this->canonicalUrlBackfillUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill) {
			$this->canonicalUrlBackfillUpgrade = new ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill($this, $componentDeps);
		}
		if (!$this->dailyMaintenanceUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeDailyMaintenance) {
			$this->dailyMaintenanceUpgrade = new ABJ_404_Solution_DatabaseUpgradeDailyMaintenance($this, $componentDeps);
		}
		if (!$this->pluginUpdateUpgrade instanceof ABJ_404_Solution_DatabaseUpgradePluginUpdate) {
			$this->pluginUpdateUpgrade = new ABJ_404_Solution_DatabaseUpgradePluginUpdate($this, $componentDeps);
		}
		if (!$this->tableRepairUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeTableRepair) {
			$this->tableRepairUpgrade = new ABJ_404_Solution_DatabaseUpgradeTableRepair($this, $componentDeps);
		}
		if (!$this->indexesUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeIndexes) {
			$this->indexesUpgrade = new ABJ_404_Solution_DatabaseUpgradeIndexes($this, $componentDeps);
		}
		if (!$this->orphanAdoptionUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeOrphanAdoption) {
			$this->orphanAdoptionUpgrade = new ABJ_404_Solution_DatabaseUpgradeOrphanAdoption($this, $componentDeps);
		}
		if (!$this->multiSiteUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeMultiSite) {
			$this->multiSiteUpgrade = new ABJ_404_Solution_DatabaseUpgradeMultiSite($this, $componentDeps);
		}
		if (!$this->schemaDiffUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeSchemaDiff) {
			$this->schemaDiffUpgrade = new ABJ_404_Solution_DatabaseUpgradeSchemaDiff($this, $componentDeps);
		}
		if (!$this->bootstrapUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeBootstrap) {
			$this->bootstrapUpgrade = new ABJ_404_Solution_DatabaseUpgradeBootstrap($this, $componentDeps);
		}

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
			$component->replaceDatabaseUpgradeDependencies($componentDeps);
		}
	}

	/** @return string|null */
	public function getUpgradeRuntimeId() {
		return self::$uniqID;
	}

	public function isLogsv2CanonicalBackfillScheduled(): bool {
		return self::$logsv2CanonicalBackfillScheduled;
	}

	public function setLogsv2CanonicalBackfillScheduled(bool $scheduled): void {
		self::$logsv2CanonicalBackfillScheduled = $scheduled;
	}

	public function getCanonicalUrlBackfillChunkSize(): int {
		return self::CANONICAL_URL_BACKFILL_CHUNK_SIZE;
	}

	public function getCanonicalUrlBackfillTimeBudgetSec(): float {
		return self::CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC;
	}

	public function getLogsv2CanonicalUrlBackfillTimeBudgetSec(): float {
		return self::LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC;
	}

	public function getLogsv2CanonicalUrlBackfillCompleteOption(): string {
		return self::LOGSV2_CANONICAL_URL_BACKFILL_COMPLETE_OPTION;
	}

	/** @return array<int, string> */
	public function getPluginTableSuffixes(): array {
		return self::PLUGIN_TABLE_SUFFIXES;
	}

	public static function resetLogsv2CanonicalBackfillScheduledFlagForTests(): void {
		self::$logsv2CanonicalBackfillScheduled = false;
	}

	public static function getLogsv2CanonicalBackfillScheduledFlagForTests(): bool {
		return self::$logsv2CanonicalBackfillScheduled;
	}

	/**
	 * Pass-through magic delegation to sub-component upgrade classes.
	 *
	 * The 9 ABJ_404_Solution_DatabaseUpgrade* sub-components own the actual
	 * upgrade logic; this coordinator owns lifecycle + dependency wiring only.
	 * Every external caller that used to invoke a typed wrapper here (e.g.
	 * createIndexes(), backfillRedirectsCanonicalUrl()) now lands in __call
	 * and routes through invokeDatabaseUpgradeMethod()'s delegate map.
	 *
	 * Internal callers in this file (e.g. $this->isNetworkActivated()) also
	 * resolve through __call because no concrete method declarations exist.
	 *
	 * @param string $name
	 * @param array<int, mixed> $arguments
	 * @return mixed
	 */
	public function __call(string $name, array $arguments) {
		return $this->invokeDatabaseUpgradeMethod($name, $arguments);
	}

	/**
	 * Number of rows updated per chunk by backfillRedirectsCanonicalUrl().
	 * Sized so a single chunk completes well under the standard 60s query
	 * timeout even on slow disks; the chunk loop will keep going until the
	 * per-invocation budget is exhausted.
	 *
	 * Defined here (not on the trait) because trait constants require PHP 8.2+
	 * and the plugin supports PHP 7.4. The trait references this via self::
	 * which resolves to the using class at compile time.
	 */
	const CANONICAL_URL_BACKFILL_CHUNK_SIZE = 5000;

	/**
	 * Per-invocation wall-clock budget (seconds) for backfillRedirectsCanonicalUrl().
	 * Bounds how long the daily cron / activation handler will spend on this
	 * task in one call so a 350K-row site finishes over a few cron ticks
	 * instead of all in one request that risks PHP max_execution_time.
	 */
	const CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC = 25;

	/**
	 * Per-invocation wall-clock budget (seconds) for backfillLogsv2CanonicalUrl().
	 * Tighter than the redirects-side budget because logsv2 backfill can also
	 * be triggered from the Captured-404s admin-tab shutdown hook, which
	 * holds a PHP-FPM worker for the duration. 15s caps worker-hold to a
	 * window short enough that concurrent visitors are unlikely to notice
	 * worker-pool pressure on shared hosts. Daily cron uses the same budget
	 * so convergence math (about 25K to 75K rows per invocation) is consistent.
	 */
	const LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC = 15;

	/**
	 * wp_options key that flips to '1' once backfillLogsv2CanonicalUrl()
	 * confirms zero NULL rows remain on logsv2.canonical_url. Once set, the
	 * read-side query can drop the COALESCE fallback and use the no-COALESCE
	 * form ("logsv2.canonical_url = redirects.canonical_url"); the planner
	 * picks the smaller side as driver and skips the Filter step (about
	 * 17,000x cost reduction vs the COALESCE form per the
	 * redirects-temp-table-perf writeup).
	 *
	 * Stored as autoload=false so the option doesn't bloat the autoloaded
	 * options blob on every request. Read on the captured-404s render path
	 * only, which already triggers wp_cache lookups for related options.
	 */
	const LOGSV2_CANONICAL_URL_BACKFILL_COMPLETE_OPTION = 'abj404_logsv2_canonical_url_backfill_complete';

	/**
	 * Known plugin table suffixes for adoption.
	 * @var array<int, string>
	 */
	public const PLUGIN_TABLE_SUFFIXES = [
		'abj404_redirects',
		'abj404_logsv2',
		'abj404_spelling_cache',
		'abj404_permalink_cache',
		'abj404_lookup',
		'abj404_ngram_cache',
		'abj404_logs_hits',
		'abj404_redirect_conditions',
		'abj404_engine_profiles',
		'abj404_view_cache',
	];
}
