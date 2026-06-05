<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseUpgradeCoordinator.php';
require_once __DIR__ . '/DatabaseUpgradeComponent.php';
require_once __DIR__ . '/DatabaseUpgradeRuntimeState.php';
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
require_once __DIR__ . '/DatabaseUpgradeRegistry.php';

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

	/** @var mixed */
	private $ngramExtractor;

	/** @var mixed */
	private $ngramCacheRepository;

	/** @var mixed */
	private $ngramCoveragePolicy;

	/** @var mixed */
	private $ngramRebuilder;

	/** @var ABJ_404_Solution_DatabaseUpgradeRegistry */
	private $upgradeRegistry;

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
	 * @param ABJ_404_Solution_NGramExtractor|null $ngramExtractor N-gram extraction service
	 * @param ABJ_404_Solution_NGramCacheRepository|null $ngramCacheRepository N-gram cache repository
	 * @param ABJ_404_Solution_NGramCoveragePolicy|null $ngramCoveragePolicy N-gram coverage policy
	 * @param ABJ_404_Solution_NGramRebuilder|null $ngramRebuilder N-gram rebuild service
	 */
	public function __construct($dataAccess = null, $logging = null, $functions = null, $permalinkCache = null, $syncUtils = null, $pluginLogic = null, $ngramFilter = null, $ngramExtractor = null, $ngramCacheRepository = null, $ngramCoveragePolicy = null, $ngramRebuilder = null) {
		// Use injected dependencies or fall back to getInstance() for backward compatibility
		$this->dao = $dataAccess !== null ? $dataAccess : abj_service('data_access');
		$this->logger = $logging !== null ? $logging : abj_service('logging');
		$this->f = $functions !== null ? $functions : abj_service('functions');
		$this->permalinkCache = $permalinkCache !== null ? $permalinkCache : abj_service('permalink_cache');
		$this->syncUtils = $syncUtils !== null ? $syncUtils : abj_service('sync_utils');
		$this->logic = $pluginLogic !== null ? $pluginLogic : abj_service('plugin_logic');
		$this->ngramFilter = $ngramFilter !== null ? $ngramFilter : abj_service('ngram_filter');
		$this->ngramExtractor = $ngramExtractor;
		$this->ngramCacheRepository = $ngramCacheRepository;
		$this->ngramCoveragePolicy = $ngramCoveragePolicy;
		$this->ngramRebuilder = $ngramRebuilder;

		$daoClass = is_object($this->dao) ? get_class($this->dao) : '';
		$this->dbCore = ($dataAccess !== null && $daoClass !== 'ABJ_404_Solution_DataAccess'
			&& method_exists($this->dao, 'queryAndGetResults') && method_exists($this->dao, 'doTableNameReplacements'))
			? $this->dao
			: $this->dao->getDbCore();
		$this->contentRepo = $this->dao->getContentRepo();
		$this->viewBuild = $this->dao->getViewBuildOrchestrator();
		$this->viewRead = $this->dao->getViewReadService();
		$this->logsRepo = $this->dao->getLogsRepo();

		$this->upgradeRegistry = new ABJ_404_Solution_DatabaseUpgradeRegistry(
			$this,
			$this->buildComponentDependencyMap()
		);
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
	 * Invoke a DatabaseUpgradesEtc method or delegate method by name.
	 *
	 * @param string $method
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	public function invokeDatabaseUpgradeMethod(string $method, array $args = []) {
		if ($this->upgradeRegistry->canInvoke($method)) {
			$this->upgradeRegistry->replaceDependencies($this->buildComponentDependencyMap());
			return $this->upgradeRegistry->invoke($method, $args);
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
			'ngramExtractor' => $this->ngramExtractor,
			'ngramCacheRepository' => $this->ngramCacheRepository,
			'ngramCoveragePolicy' => $this->ngramCoveragePolicy,
			'ngramRebuilder' => $this->ngramRebuilder,
		];
	}

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
}
