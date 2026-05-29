<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseUpgradeCoordinator.php';
require_once __DIR__ . '/DatabaseUpgradeComponent.php';
require_once __DIR__ . '/DatabaseUpgradeNGram.php';
require_once __DIR__ . '/DatabaseUpgradeMaintenance.php';
require_once __DIR__ . '/DatabaseUpgradePluginUpdate.php';
require_once __DIR__ . '/DatabaseUpgradeTableRepair.php';
require_once __DIR__ . '/DatabaseUpgradeIndexes.php';
require_once __DIR__ . '/DatabaseUpgradeOrphanAdoption.php';
require_once __DIR__ . '/DatabaseUpgradeMultiSite.php';
require_once __DIR__ . '/DatabaseUpgradeSchemaDiff.php';

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
 */
class ABJ_404_Solution_DatabaseUpgradesEtc implements ABJ_404_Solution_DatabaseUpgradeCoordinator {

	/** @var self|null */
	private static $instance = null;

	/** @var string|null */
	private static $uniqID = null;

	/**
	 * Per-request dedup flag for scheduleLogsv2CanonicalUrlBackfill().
	 * Mirrors DataAccess::$hitsTableRebuildScheduled — ensures the shutdown
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

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_NGramFilter */
	private $ngramFilter;

	/** @var ABJ_404_Solution_DatabaseUpgradeNGram */
	private $nGramUpgrade;

	/** @var ABJ_404_Solution_DatabaseUpgradeMaintenance */
	private $maintenanceUpgrade;

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

	/**
	 * Constructor with dependency injection.
	 *
	 * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer (legacy, only for getLatestPluginVersion)
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 * @param ABJ_404_Solution_Functions|null $functions String utilities
	 * @param ABJ_404_Solution_PermalinkCache|null $permalinkCache Permalink cache service
	 * @param ABJ_404_Solution_SynchronizationUtils|null $syncUtils Sync utilities
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
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

		$componentDeps = [
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
		$this->nGramUpgrade = new ABJ_404_Solution_DatabaseUpgradeNGram($this, $componentDeps);
		$this->maintenanceUpgrade = new ABJ_404_Solution_DatabaseUpgradeMaintenance($this, $componentDeps);
		$this->pluginUpdateUpgrade = new ABJ_404_Solution_DatabaseUpgradePluginUpdate($this, $componentDeps);
		$this->tableRepairUpgrade = new ABJ_404_Solution_DatabaseUpgradeTableRepair($this, $componentDeps);
		$this->indexesUpgrade = new ABJ_404_Solution_DatabaseUpgradeIndexes($this, $componentDeps);
		$this->orphanAdoptionUpgrade = new ABJ_404_Solution_DatabaseUpgradeOrphanAdoption($this, $componentDeps);
		$this->multiSiteUpgrade = new ABJ_404_Solution_DatabaseUpgradeMultiSite($this, $componentDeps);
		$this->schemaDiffUpgrade = new ABJ_404_Solution_DatabaseUpgradeSchemaDiff($this, $componentDeps);
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
			'updateTableEngineToInnoDB' => 'maintenanceUpgrade',
			'getTableCollation' => 'maintenanceUpgrade',
			'getTableCollationFromShowCreate' => 'maintenanceUpgrade',
			'getTableCollationFromInformationSchema' => 'maintenanceUpgrade',
			'getDefaultCollationForCharset' => 'maintenanceUpgrade',
			'sanitizeCollationIdentifier' => 'maintenanceUpgrade',
			'resolveTargetUtf8mb4Collation' => 'maintenanceUpgrade',
			'correctCollations' => 'maintenanceUpgrade',
			'tableHasMismatchedCharacterColumnCollation' => 'maintenanceUpgrade',
			'runDailyInsuranceCheck' => 'maintenanceUpgrade',
			'runSelfHealPrologue' => 'maintenanceUpgrade',
			'verifyAndRepairCurrentSite' => 'maintenanceUpgrade',
			'cleanupExpiredRateLimitTransients' => 'maintenanceUpgrade',
			'runDatabaseMaintenanceTasks' => 'maintenanceUpgrade',
			'refreshViewDoneSnapshotInline' => 'maintenanceUpgrade',
			'backfillRedirectsCanonicalUrl' => 'maintenanceUpgrade',
			'backfillLogsv2CanonicalUrl' => 'maintenanceUpgrade',
			'scheduleLogsv2CanonicalUrlBackfill' => 'maintenanceUpgrade',
			'shouldScheduleLogsv2CanonicalBackfillViaCron' => 'maintenanceUpgrade',
			'columnExists' => 'maintenanceUpgrade',
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

	/** @return void */
	private function refreshDatabaseUpgradeComponents() {
		$componentDeps = [
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

		if (!$this->nGramUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeNGram) {
			$this->nGramUpgrade = new ABJ_404_Solution_DatabaseUpgradeNGram($this, $componentDeps);
		}
		if (!$this->maintenanceUpgrade instanceof ABJ_404_Solution_DatabaseUpgradeMaintenance) {
			$this->maintenanceUpgrade = new ABJ_404_Solution_DatabaseUpgradeMaintenance($this, $componentDeps);
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

		foreach ([
			$this->nGramUpgrade,
			$this->maintenanceUpgrade,
			$this->pluginUpdateUpgrade,
			$this->tableRepairUpgrade,
			$this->indexesUpgrade,
			$this->orphanAdoptionUpgrade,
			$this->multiSiteUpgrade,
			$this->schemaDiffUpgrade,
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

	
	/** Create the tables when the plugin is first activated.
     * @param bool $updatingToNewVersion
     * @return void
     */
    function createDatabaseTables($updatingToNewVersion = false, bool $force = false) {

    	$synchronizedKeyFromUser = "create_db_tables";
    	$uniqueID = null;

    	if (!$force) {
    		$uniqueID = $this->syncUtils->synchronizerAcquireLockTry($synchronizedKeyFromUser);

    		if ($uniqueID == '' || $uniqueID == null) {
    			$this->logger->debugMessage("Avoiding multiple calls for creating database tables.");
    			return;
    		}
    	}

    	// Fixed: Use finally block to ensure lock is ALWAYS released, even on fatal errors
    	try {
    		$this->reallyCreateDatabaseTables($updatingToNewVersion);

    	} catch (\Exception $e) {
    		$this->logger->errorMessage("Error creating database tables. ", $e);
    		throw $e;  // Re-throw to propagate the error
    	} finally {
    		// Release the lock only if one was acquired (non-forced path).
    		if ($uniqueID !== null && $uniqueID !== '') {
    			$this->syncUtils->synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser);
    		}
    	}
    }
    
    /**
     * @param bool $updatingToNewVersion
     * @return void
     */
    private function reallyCreateDatabaseTables($updatingToNewVersion = false) {
    	if ($updatingToNewVersion) {
    		$this->correctIssuesBefore();
    	}

    	// MULTISITE: Process current site immediately, schedule background task for remaining sites
    	if ($this->isNetworkActivated() && !$updatingToNewVersion) {
    		// Activation path: create tables for current site + schedule background for others.
    		$currentBlogId = get_current_blog_id();
    		$this->runInitialCreateTables();
    		$this->correctCollations();
    		$this->updateTableEngineToInnoDB();
    		$this->createIndexes();

    		// First chunk of the canonical_url backfill runs in-band so newly
    		// upgraded small sites finish in one shot. Larger sites converge
    		// over subsequent daily-maintenance cron ticks (same method).
    		$this->backfillRedirectsCanonicalUrl();

    		$this->logger->infoMessage(sprintf(
    			"Network activation: Created tables for current site (ID %d). Scheduling background task for remaining sites.",
    			$currentBlogId
    		));

    		$this->scheduleBackgroundMultisiteActivation($currentBlogId);

    	} else if ($this->isNetworkActivated() && $updatingToNewVersion) {
    		// Upgrade path on a network install: update tables for current site + schedule
    		// background upgrade for other sites (so sub-site tables are also updated).
    		$currentBlogId = get_current_blog_id();
    		$this->runInitialCreateTables();
    		$this->correctCollations();
    		$this->updateTableEngineToInnoDB();
    		$this->createIndexes();

    		// First chunk of the canonical_url backfill runs in-band so newly
    		// upgraded small sites finish in one shot. Larger sites converge
    		// over subsequent daily-maintenance cron ticks (same method).
    		$this->backfillRedirectsCanonicalUrl();

    		$this->logger->infoMessage(sprintf(
    			"Network upgrade: Updated tables for current site (ID %d). Scheduling background upgrade for remaining sites.",
    			$currentBlogId
    		));

    		$this->scheduleBackgroundMultisiteUpgrade($currentBlogId);

    	} else {
    		// Single site (or non-network-activated): create/update tables for current site only.
    		$this->runInitialCreateTables();
    		$this->correctCollations();
    		$this->updateTableEngineToInnoDB();
    		$this->createIndexes();

    		// First chunk of the canonical_url backfill runs in-band so newly
    		// upgraded small sites finish in one shot. Larger sites converge
    		// over subsequent daily-maintenance cron ticks (same method).
    		$this->backfillRedirectsCanonicalUrl();
    	}

    	// Adopt orphaned tables AFTER target tables exist (rename handles prefix mismatches).
    	$this->renameAbj404TablesToLowerCase();

    	// we could do this only when a table is created or when the "meta" column is created
    	// but it doesn't take long anyway so we do it every night.
    	$this->permalinkCache->updatePermalinkCache(1);

    	// One-time N-gram cache initialization (async via WP-Cron to prevent blocking)
    	// MULTISITE: Use network-aware option getter to check initialization status
    	if ($this->getNetworkAwareOption('abj404_ngram_cache_initialized') !== '1') {
    		$this->logger->debugMessage("N-gram cache not initialized. Scheduling background build...");

    		// Schedule async rebuild via WP-Cron instead of blocking activation
    		$this->scheduleNGramCacheRebuild();

    		// Show admin notice that build is scheduled
    		if ($updatingToNewVersion && function_exists('add_settings_error')) {
    			$context = is_multisite() && $this->isNetworkActivated() ? ' across all sites in the network' : '';
    			$message = sprintf(
    				__('404 Solution: N-gram spell check cache is being built in the background%s to optimize performance. This may take a few minutes on large sites.', '404-solution'),
    				$context
    			);
    			add_settings_error('abj404_settings', 'ngram_cache_scheduled', $message, 'updated');
    		}

    		$this->logger->infoMessage("N-gram cache rebuild scheduled via WP-Cron.");
    	} else {
    		$this->logger->debugMessage("N-gram cache already initialized. Skipping rebuild.");
    	}

    	// Run one-time migration to relative paths (Issue #24)
    	if (get_option('abj404_migrated_to_relative_paths') !== '1') {
    		$migrationResults = $this->migrateURLsToRelativePaths();

    		// Show admin notice if migration occurred
            if ($updatingToNewVersion && is_array($migrationResults) && !empty($migrationResults['redirects_updated'])) {
    			$rawRedirectsUpdated = $migrationResults['redirects_updated'];
    			$redirectsUpdated = is_scalar($rawRedirectsUpdated) ? (int)$rawRedirectsUpdated : 0;
    			$message = sprintf(
    				_n(
    					'404 Solution: Migrated %d redirect to subdirectory-independent format.',
    					'404 Solution: Migrated %d redirects to subdirectory-independent format.',
    					$redirectsUpdated,
    					'404-solution'
    				),
    				$redirectsUpdated
    			);
    			if (function_exists('add_settings_error')) {
    				add_settings_error('abj404_settings', 'migration_success', $message, 'updated');
    			}
    		}
    	}

    	if ($updatingToNewVersion) {
    		$this->correctIssuesAfter();
    	}
    }

    /**
     * Makes all plugin table names lowercase, in case someone thought it was funny to use
	 * the lower_case_table_names=0 setting. Also detects and adopts orphaned plugin tables
	 * under old prefixes (from site migrations or the rename bug in v2.35.16–v3.x).
     * @return void
     */
	function renameAbj404TablesToLowerCase() {
		global $wpdb;

		// On case-insensitive MySQL (lower_case_table_names >= 1), table names
		// are already treated as lowercase internally. Renaming is pointless and
		// can cause issues on some hosting setups.
		// DAO-bypass-approved: Schema-bootstrap inside renameAbj404TablesToLowerCase() — runs before plugin DAO is fully wired during DB upgrades
		$lctnResult = $wpdb->get_row("SHOW VARIABLES LIKE 'lower_case_table_names'", ARRAY_A);
		if (is_array($lctnResult)) {
			$lctnValue = null;
			foreach ($lctnResult as $key => $value) {
				if (strtolower((string)$key) === 'value') {
					$lctnValue = $value;
					break;
				}
			}
			if ($lctnValue !== null && (int)$lctnValue >= 1) {
				// MySQL already handles table names case-insensitively.
				// Still run adoption check in case of prefix mismatch.
				$this->adoptOrphanedTables();
				return;
			}
		}

		// Fetch all tables containing "abj404", case-insensitive
		$dbNameRaw = $wpdb->dbname ?? '';
		if ($dbNameRaw === '') {
			$this->logger->warn("Could not determine database name for lowercase rename.");
			return;
		}
		$dbNameEscaped = esc_sql($dbNameRaw);
		$dbName = is_array($dbNameEscaped) ? '' : $dbNameEscaped;
		$query = "SELECT table_name
			FROM information_schema.tables
			WHERE table_schema = '{$dbName}'
			AND LOWER(table_name) LIKE '%abj404%'";
		$results = $this->dbCore->queryAndGetResults($query);

		if (!is_array($results['rows'])) {
			$this->logger->warn("Could not query information_schema tables for lowercase rename.");
			return;
		}

		foreach ($results['rows'] as $row) {
			// Case-insensitive key lookup: MySQL drivers return information_schema
			// column names in varying cases (table_name, TABLE_NAME, Table_Name).
			$tableName = null;
			foreach ($row as $key => $value) {
					if (strtolower((string)$key) === 'table_name' && is_scalar($value)) {
						$tableName = (string)$value;
					break;
				}
			}

			if (!empty($tableName)) {
				$lowercaseName = strtolower($tableName);

				// Check if the table name is already lowercase, skip if it is
				if ($tableName !== $lowercaseName) {
					// Rename the table to lowercase
					$renameQuery = "RENAME TABLE `{$tableName}` TO `{$lowercaseName}`";
					$this->dbCore->queryAndGetResults($renameQuery,
						['ignore_errors' => ["already exists"]]);
					$this->logger->infoMessage("Renamed table {$tableName} to {$lowercaseName}\n");
				}
			} else {
				$this->logger->warn("I didn't find a table name in the results of this row: " .
					print_r($row, true));
			}
		}

		// After renaming, check for orphaned tables under old prefixes.
		$this->adoptOrphanedTables();
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
	 * so convergence math (~25K-75K rows per invocation) is consistent.
	 */
	const LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC = 15;

	/**
	 * wp_options key that flips to '1' once backfillLogsv2CanonicalUrl()
	 * confirms zero NULL rows remain on logsv2.canonical_url. Once set, the
	 * read-side query can drop the COALESCE fallback and use the no-COALESCE
	 * form ("logsv2.canonical_url = redirects.canonical_url"); the planner
	 * picks the smaller side as driver and skips the Filter step (~17,000x
	 * cost reduction vs the COALESCE form per the redirects-temp-table-perf
	 * writeup).
	 *
	 * Stored as autoload=false so the option doesn't bloat the autoloaded
	 * options blob on every request — read on the captured-404s render path
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

	/** When certain columns are created we have to populate data.
     * @param string $tableName
     * @param string $colName
     * @return void
     */
	    function handleSpecificCases($tableName, $colName) {
	    	if (empty($tableName) || !is_string($tableName)) {
	    		return;
	    	}

	    	if (strpos($tableName, 'abj404_logsv2') !== false && $colName == 'min_log_id') {
	    		global $wpdb;
	    		$query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/logsSetMinLogID.sql");
	    		$this->dbCore->queryAndGetResults($query);
            // Ensure composite index exists after backfilling min_log_id.
            $this->ensureLogsCompositeIndex($tableName);
    	}
    	if (strpos($tableName, 'abj404_permalink_cache') !== false && $colName == 'url_length') {
    		// clear the permalink cache so that the url length column will be populated.
    		// this could be more efficient but I'll assume that's not necessary.
    		$this->contentRepo->truncatePermalinkCacheTable();
    	}
    }
    
	    /**
	     * Discover all permanent (non-Temp) DDL files and extract table metadata.
	     *
	     * @return array<int, array{placeholder: string, bareTableName: string, ddlContent: string}>
	     */
	    function discoverPermanentDDLFiles(): array {
	    	$sqlDir = __DIR__ . '/sql';
	    	$files = glob($sqlDir . '/create*Table.sql');
	    	if (!is_array($files)) {
	    		$files = [];
	    	}
	    	sort($files);

	    	$result = [];
	    	foreach ($files as $file) {
	    		if (stripos(basename($file), 'Temp') !== false) {
	    			continue;
	    		}
	    		$ddlContent = ABJ_404_Solution_Functions::readFileContents($file);
	    		if (!is_string($ddlContent) || trim($ddlContent) === '') {
	    			continue;
	    		}
	    		if (!preg_match('/\{(wp_(abj404_\w+))\}/', $ddlContent, $m)) {
	    			continue;
	    		}
	    		// Transient staged-build tables (view_build, view_done, view_deleteme)
	    		// are owned by ABJ_404_Solution_DataAccess_ViewQueriesStagedTrait.
	    		// stageCreateBuildTable() creates view_build on demand, stageRenameSwap()
	    		// renames it to view_done, and view_deleteme is the ephemeral previous-
	    		// generation served table that gets dropped right after the swap. None
	    		// of them should participate in the permanent-DDL bootstrap, repair, or
	    		// missing-table check loops. Their absence between builds is normal,
	    		// not a corruption signal.
	    		if (in_array($m[2], array('abj404_view_build', 'abj404_view_done', 'abj404_view_deleteme'), true)) {
	    			continue;
	    		}
	    		$result[] = [
	    			'placeholder' => '{' . $m[1] . '}',
	    			'bareTableName' => $m[2],
	    			'ddlContent' => $ddlContent,
	    		];
	    	}
	    	return $result;
	    }

	    /** @return void */
	    function runInitialCreateTables() {
	    	// Re-add a stripped `id` PRIMARY KEY (via ALTER) BEFORE any CREATE TABLE
	    	// IF NOT EXISTS runs.  Without this step, an existing-but-broken table
	    	// (missing the file's `id` PRIMARY KEY) would survive the IF NOT EXISTS
	    	// check and verifyColumns would only ALTER ADD the missing non-PK
	    	// columns, leaving the table without its primary key.  Lives here (not
	    	// just in correctIssuesBefore) so cron callers of createDatabaseTables()
	    	// — which don't pass the $updatingToNewVersion flag — also repair
	    	// stripped tables instead of propagating the broken state.
	    	$this->repairStrippedViewCacheTable();

	    	foreach ($this->discoverPermanentDDLFiles() as $ddlEntry) {
	    		$query = $this->applyPluginTableCharsetCollate($ddlEntry['ddlContent']);
	    		$this->dbCore->queryAndGetResults($query);

	    		$tableName = $this->dbCore->doTableNameReplacements($ddlEntry['placeholder']);

	    		// Per-table post-CREATE verification: confirm the table actually
	    		// exists on disk. queryAndGetResults logs SQL errors generically,
	    		// but a silently-failing CREATE (concurrent DROP, swallowed parse
	    		// error, prefix drift, or insufficient privileges) is invisible
	    		// without an explicit existence check. Log per-table so the debug
	    		// log identifies which DDL didn't materialize and why downstream
	    		// auto-repair attempts will keep failing.
	    		if (!$this->verifyTableMaterialized($tableName, $ddlEntry['placeholder'])) {
	    			// Don't abort the loop — other tables can still get created.
	    			continue;
	    		}

	    		// Targeted online-DDL column add(s) before the generic verifyColumns()
	    		// flow runs a bare ALTER. On large logsv2 tables (multi-GB on
	    		// busy sites) bare ADD COLUMN can block the table for tens of
	    		// seconds; the targeted helper uses ALGORITHM=INPLACE, LOCK=NONE
	    		// so InnoDB ≥ 5.6 picks the lockless online-DDL path. If the
	    		// engine doesn't support it the helper falls back silently and
	    		// verifyColumns() picks up the column add as a safety net.
	    		if ($ddlEntry['bareTableName'] === 'abj404_logsv2') {
	    			$this->ensureLogsv2CanonicalUrlColumn($tableName);
	    		}
	    		// Same logic for the redirects side. canonical_url is required by
	    		// setupRedirect() and was added in 4.1.11; on a small fraction of
	    		// sites dbDelta silently fails to add it, so every captured 404
	    		// emits "Unknown column 'canonical_url' in 'field list'" until
	    		// verifyColumns eventually retries. Eagerly running the targeted
	    		// add closes that window.
	    		if ($ddlEntry['bareTableName'] === 'abj404_redirects') {
	    			$this->ensureRedirectsCanonicalUrlColumn($tableName);
	    		}

	    		$this->verifyColumns($tableName, $query);
	    	}

	    	// Table-specific post-creation steps.
	    	$logsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
	    	$this->ensureLogsCompositeIndex($logsTable);

	    	// Mark view cache table as ensured so ensureViewSnapshotTableExists() skips redundant DDL.
	    	ABJ_404_Solution_ViewReadService::setViewSnapshotTableEnsured(true);
	    }

	    /**
	     * Verify that a CREATE TABLE actually materialized the named table on disk.
	     * Returns true if the table exists, false (and logs a per-table error) if not.
	     *
	     * Distinguishes silently-failing CREATEs from generic SQL errors so the
	     * debug log identifies which specific DDL didn't materialize. Common causes:
	     * concurrent DROP from a parallel cron, SQL parse error swallowed by
	     * queryAndGetResults, prefix drift between request and table_prefix in
	     * wp-config, or missing CREATE TABLE privileges on the DB user.
	     *
	     * @param string $tableName  Fully-qualified table name (with prefix).
	     * @param string $placeholder Original placeholder (e.g. "{wp_abj404_redirects}") for diagnostic context.
	     * @return bool True if table exists post-CREATE, false otherwise.
	     */
	    private function verifyTableMaterialized(string $tableName, string $placeholder): bool {
	    	global $wpdb;
	    	if (!isset($wpdb)) {
	    		return false;
	    	}
	    	// @utf8-audit: opt-out — $tableName is fully-qualified plugin table
	    	// name from doTableNameReplacements / $wpdb->prefix; never user input.
	    	// DAO-bypass-approved: Schema-bootstrap inside verifyTableMaterialized() — verifies CREATE TABLE actually materialized; DAO timeout wrapper is irrelevant for DDL existence probe
	    	$found = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tableName) . "'");
	    	if ($found === $tableName) {
	    		return true;
	    	}
	    	$this->logger->errorMessage(
	    		"CREATE TABLE did not materialize '" . $tableName . "' "
	    		. "(placeholder " . $placeholder . "). "
	    		. "Table is still missing on disk after CREATE TABLE IF NOT EXISTS ran. "
	    		. "Likely causes: concurrent DROP from a parallel request, "
	    		. "SQL parse error suppressed by queryAndGetResults, "
	    		. "prefix mismatch between request and wp-config table_prefix, "
	    		. "or insufficient CREATE TABLE privileges on the DB user."
	    	);
	    	return false;
	    }

	    /**
	     * @param string $createTableSql
	     * @return string
	     */
	    function applyPluginTableCharsetCollate($createTableSql) {
	    	global $wpdb;
	    	if (!is_string($createTableSql) || $createTableSql === '') {
	    		return $createTableSql;
	    	}

	    	// Always prefer utf8mb4 for plugin tables, regardless of site defaults.
	    	$collate = 'utf8mb4_unicode_ci';
	    	if (!empty($wpdb->collate) && stripos($wpdb->collate, 'utf8mb4') !== false) {
	    		$collate = $wpdb->collate;
	    	}

	    	$createTableSql = str_replace('{COLLATION}', $collate, $createTableSql);
	    	// If the statement already specifies charset/collation, don't override.
	    	if (preg_match('/\b(?:default\s+)?(?:character\s+set|charset|collate)\b/i', $createTableSql)) {
	    		return $createTableSql;
	    	}
	    	
	    	return rtrim($createTableSql) . " DEFAULT CHARACTER SET utf8mb4 COLLATE {$collate}";
	    }

}
