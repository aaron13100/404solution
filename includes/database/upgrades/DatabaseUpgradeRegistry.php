<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registry and dispatcher for DatabaseUpgradesEtc delegate components.
 *
 * The legacy DatabaseUpgradesEtc facade remains the public entry point.
 * This registry owns the internal map from legacy method names to concrete
 * upgrade components, keeps component dependencies fresh, and performs the
 * actual delegate invocation.
 */
final class ABJ_404_Solution_DatabaseUpgradeRegistry {

    /** @var ABJ_404_Solution_DatabaseUpgradeCoordinator */
    private $owner;

    /** @var array<string, mixed> */
    private $componentDeps;

    /** @var array<string, ABJ_404_Solution_DatabaseUpgradeComponent> */
    private $components = [];

    /** @var array<string, class-string<ABJ_404_Solution_DatabaseUpgradeComponent>> */
    private const COMPONENT_CLASSES = [
        'nGramUpgrade' => ABJ_404_Solution_DatabaseUpgradeNGram::class,
        'engineNormalizationUpgrade' => ABJ_404_Solution_DatabaseUpgradeEngineNormalization::class,
        'collationDriftUpgrade' => ABJ_404_Solution_DatabaseUpgradeCollationDrift::class,
        'selfHealUpgrade' => ABJ_404_Solution_DatabaseUpgradeSelfHeal::class,
        'canonicalUrlBackfillUpgrade' => ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill::class,
        'dailyMaintenanceUpgrade' => ABJ_404_Solution_DatabaseUpgradeDailyMaintenance::class,
        'pluginUpdateUpgrade' => ABJ_404_Solution_DatabaseUpgradePluginUpdate::class,
        'tableRepairUpgrade' => ABJ_404_Solution_DatabaseUpgradeTableRepair::class,
        'indexesUpgrade' => ABJ_404_Solution_DatabaseUpgradeIndexes::class,
        'orphanAdoptionUpgrade' => ABJ_404_Solution_DatabaseUpgradeOrphanAdoption::class,
        'multiSiteUpgrade' => ABJ_404_Solution_DatabaseUpgradeMultiSite::class,
        'schemaDiffUpgrade' => ABJ_404_Solution_DatabaseUpgradeSchemaDiff::class,
        'bootstrapUpgrade' => ABJ_404_Solution_DatabaseUpgradeBootstrap::class,
    ];

    /** @var array<string, string> */
    private const METHOD_COMPONENTS = [
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
        'dropTranslatedViewLabelColumns' => 'tableRepairUpgrade',
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

    /**
     * @param array<string, mixed> $componentDeps
     */
    public function __construct(ABJ_404_Solution_DatabaseUpgradeCoordinator $owner, array $componentDeps) {
        $this->owner = $owner;
        $this->replaceDependencies($componentDeps);
    }

    /** @return bool */
    public function canInvoke(string $method): bool {
        return isset(self::METHOD_COMPONENTS[$method]);
    }

    /**
     * @param array<string, mixed> $componentDeps
     * @return void
     */
    public function replaceDependencies(array $componentDeps): void {
        $this->componentDeps = $componentDeps;
        $this->refreshComponents();
    }

    /**
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    public function invoke(string $method, array $args = []) {
        if (!$this->canInvoke($method)) {
            throw new BadMethodCallException("Database upgrade component method not found: {$method}");
        }

        $componentKey = self::METHOD_COMPONENTS[$method];
        $this->refreshComponents();
        return $this->components[$componentKey]->invokeDatabaseUpgradeMethod($method, $args);
    }

    /** @return void */
    private function refreshComponents(): void {
        foreach (self::COMPONENT_CLASSES as $componentKey => $className) {
            if (!isset($this->components[$componentKey]) || !$this->components[$componentKey] instanceof $className) {
                $this->components[$componentKey] = new $className($this->owner, $this->componentDeps);
            }
            $this->components[$componentKey]->replaceDatabaseUpgradeDependencies($this->componentDeps);
        }
    }
}
