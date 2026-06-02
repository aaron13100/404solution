<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared dependency carrier and dispatcher for DatabaseUpgradesEtc delegates.
 *
 * @method array<int, array{placeholder: string, bareTableName: string, ddlContent: string}> discoverPermanentDDLFiles()
 * @method bool columnExists(string $tableName, string $columnName)
 * @method mixed deleteIndexes(string $tableName)
 * @method mixed runInitialCreateTables()
 * @method mixed correctCollations()
 * @method mixed updateTableEngineToInnoDB()
 * @method mixed createIndexes()
 * @method mixed backfillRedirectsCanonicalUrl()
 * @method mixed renameAbj404TablesToLowerCase()
 * @method mixed runSelfHealPrologue()
 * @method mixed correctIssuesBefore()
 * @method mixed correctIssuesAfter()
 * @method mixed adoptOrphanedTables()
 * @method mixed syncMissingNGrams()
 * @method mixed cleanupOrphanedNGrams()
 * @method mixed handleSpecificCases(string $tableName, string $colName)
 * @method string applyPluginTableCharsetCollate(string $createTableSql)
 * @method bool isNetworkActivated()
 * @method mixed scheduleBackgroundMultisiteActivation(int $alreadyProcessedBlogId)
 * @method mixed scheduleBackgroundMultisiteUpgrade(int $alreadyProcessedBlogId)
 * @method mixed getNetworkAwareOption(string $option_name, mixed $default = false)
 * @method mixed scheduleNGramCacheRebuild()
 * @method mixed migrateURLsToRelativePaths()
 * @method mixed ensureLogsCompositeIndex(string $logsTable, ?string $createSqlOverride = null)
 * @method mixed repairStrippedViewCacheTable()
 * @method mixed ensureLogsv2CanonicalUrlColumn(string $logsTable)
 * @method mixed ensureRedirectsCanonicalUrlColumn(string $redirectsTable)
 * @method mixed verifyColumns(string $tableName, string $createTableStatementGoal)
 */
abstract class ABJ_404_Solution_DatabaseUpgradeComponent {

    /** @var ABJ_404_Solution_DatabaseUpgradeCoordinator */
    private $owner;

    /** @var ABJ_404_Solution_DataAccess */
    protected $dao;

    /** @var ABJ_404_Solution_DatabaseCoreInterface */
    protected $dbCore;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    protected $contentRepo;

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface */
    protected $viewBuild;

    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    protected $viewRead;

    /** @var ABJ_404_Solution_LogsRepositoryInterface */
    protected $logsRepo;

    /** @var ABJ_404_Solution_PluginUpdateMetadataRepository */
    protected $pluginUpdateRepo;

    /** @var ABJ_404_Solution_Logging */
    protected $logger;

    /** @var ABJ_404_Solution_Functions */
    protected $f;

    /** @var ABJ_404_Solution_PermalinkCache */
    protected $permalinkCache;

    /** @var ABJ_404_Solution_SynchronizationUtils */
    protected $syncUtils;

    /** @var ABJ_404_Solution_PluginLogicInterface */
    protected $logic;

    /** @var ABJ_404_Solution_NGramFilter */
    protected $ngramFilter;

    /**
     * @param array<string, mixed> $deps
     */
    public function __construct(ABJ_404_Solution_DatabaseUpgradeCoordinator $owner, array $deps) {
        $this->owner = $owner;
        $this->replaceDatabaseUpgradeDependencies($deps);
    }

    /**
     * @param array<string, mixed> $deps
     * @return void
     */
    public function replaceDatabaseUpgradeDependencies(array $deps) {
        foreach ($deps as $name => $value) {
            $this->$name = $value;
        }
    }

    /**
     * Invoke a method declared on this component, including private helpers.
     *
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    public function invokeDatabaseUpgradeMethod(string $method, array $args = []) {
        if (!method_exists($this, $method)) {
            throw new BadMethodCallException("Database upgrade component method not found: {$method}");
        }

        $invoker = \Closure::bind(function($targetMethod, $targetArgs) {
            return $this->$targetMethod(...$targetArgs);
        }, $this, get_class($this));

        return $invoker($method, $args);
    }

    /**
     * Preserve DatabaseUpgradesEtc subclass override behavior after trait
     * extraction. Before conversion, a trait method's `$this->foo()` call
     * resolved to an overriding method on the host subclass. Delegate classes
     * need to check for that override explicitly before falling back to the
     * component implementation.
     *
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    protected function invokeOwnerOverrideOrSelf(string $method, array $args = []) {
        if (method_exists($this->owner, $method)) {
            $ownerMethod = new ReflectionMethod($this->owner, $method);
            if ($ownerMethod->getDeclaringClass()->getName() !== 'ABJ_404_Solution_DatabaseUpgradesEtc'
                    || !method_exists($this, $method)) {
                return $ownerMethod->invokeArgs($this->owner, $args);
            }
        }

        // Method is not a real method on owner or self. If owner can route it
        // through its delegate map (another sub-component owns it), prefer that
        // over a self-only lookup. Falls back to self for cases where neither
        // routes the call.
        if (!method_exists($this, $method)) {
            return $this->owner->invokeDatabaseUpgradeMethod($method, $args);
        }

        return $this->invokeDatabaseUpgradeMethod($method, $args);
    }

    /**
     * Delegate cross-component calls back through the DatabaseUpgradesEtc owner.
     *
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    public function __call($method, $args) {
        return $this->owner->invokeDatabaseUpgradeMethod($method, $args);
    }

    /** @return string|null */
    protected function getUpgradeRuntimeId() {
        return $this->owner->getUpgradeRuntimeId();
    }

    protected function isLogsv2CanonicalBackfillScheduled(): bool {
        return $this->owner->isLogsv2CanonicalBackfillScheduled();
    }

    protected function setLogsv2CanonicalBackfillScheduled(bool $scheduled): void {
        $this->owner->setLogsv2CanonicalBackfillScheduled($scheduled);
    }

    protected function getCanonicalUrlBackfillChunkSize(): int {
        return $this->owner->getCanonicalUrlBackfillChunkSize();
    }

    protected function getCanonicalUrlBackfillTimeBudgetSec(): float {
        return $this->owner->getCanonicalUrlBackfillTimeBudgetSec();
    }

    protected function getLogsv2CanonicalUrlBackfillTimeBudgetSec(): float {
        return $this->owner->getLogsv2CanonicalUrlBackfillTimeBudgetSec();
    }

    protected function getLogsv2CanonicalUrlBackfillCompleteOption(): string {
        return $this->owner->getLogsv2CanonicalUrlBackfillCompleteOption();
    }

    /** @return array<int, string> */
    protected function getPluginTableSuffixes(): array {
        return $this->owner->getPluginTableSuffixes();
    }
}
