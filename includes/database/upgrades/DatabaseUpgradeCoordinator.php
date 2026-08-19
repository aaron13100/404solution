<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provides typed access to the 18 database-upgrade components.
 *
 * Implemented by {@see ABJ_404_Solution_DatabaseUpgradeComponentRegistry}, which
 * owns the component instances. Each upgrade component receives the coordinator
 * so it can reach its sibling components (e.g. the bootstrap upgrade asking the
 * schema-diff upgrade to run). This interface is intentionally limited to the
 * component accessors; runtime-state values live on
 * {@see ABJ_404_Solution_DatabaseUpgradeRuntimeState} and the public operation
 * surface lives on the components themselves, reached via
 * {@see ABJ_404_Solution_DatabaseUpgradesEtc::components()}.
 */
interface ABJ_404_Solution_DatabaseUpgradeCoordinator {

    public function nGramUpgrade(): ABJ_404_Solution_DatabaseUpgradeNGram;

    public function engineNormalizationUpgrade(): ABJ_404_Solution_DatabaseUpgradeEngineNormalization;

    public function collationDriftUpgrade(): ABJ_404_Solution_DatabaseUpgradeCollationDrift;

    public function selfHealUpgrade(): ABJ_404_Solution_DatabaseUpgradeSelfHeal;

    public function canonicalUrlBackfillUpgrade(): ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill;

    public function redirectsDenormBackfillUpgrade(): ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill;

    public function addedColumnBackfillUpgrade(): ABJ_404_Solution_DatabaseUpgradeAddedColumnBackfill;

    public function redirectsSortKeyBackfillUpgrade(): ABJ_404_Solution_DatabaseUpgradeRedirectsSortKeyBackfill;

    public function redirectsDenormReconcileUpgrade(): ABJ_404_Solution_DatabaseUpgradeRedirectsDenormReconcile;

    public function dailyMaintenanceUpgrade(): ABJ_404_Solution_DatabaseUpgradeDailyMaintenance;

    public function pluginUpdateUpgrade(): ABJ_404_Solution_DatabaseUpgradePluginUpdate;

    public function tableRepairUpgrade(): ABJ_404_Solution_DatabaseUpgradeTableRepair;

    public function dropStagedViewTablesUpgrade(): ABJ_404_Solution_DatabaseUpgradeDropStagedViewTables;

    public function indexesUpgrade(): ABJ_404_Solution_DatabaseUpgradeIndexes;

    public function orphanAdoptionUpgrade(): ABJ_404_Solution_DatabaseUpgradeOrphanAdoption;

    public function multiSiteUpgrade(): ABJ_404_Solution_DatabaseUpgradeMultiSite;

    public function schemaDiffUpgrade(): ABJ_404_Solution_DatabaseUpgradeSchemaDiff;

    public function bootstrapUpgrade(): ABJ_404_Solution_DatabaseUpgradeBootstrap;
}
