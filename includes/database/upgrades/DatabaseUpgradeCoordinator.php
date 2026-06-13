<?php

if (!defined('ABSPATH')) {
    exit;
}

interface ABJ_404_Solution_DatabaseUpgradeCoordinator {

    public function nGramUpgrade(): ABJ_404_Solution_DatabaseUpgradeNGram;

    public function engineNormalizationUpgrade(): ABJ_404_Solution_DatabaseUpgradeEngineNormalization;

    public function collationDriftUpgrade(): ABJ_404_Solution_DatabaseUpgradeCollationDrift;

    public function selfHealUpgrade(): ABJ_404_Solution_DatabaseUpgradeSelfHeal;

    public function canonicalUrlBackfillUpgrade(): ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill;

    public function dailyMaintenanceUpgrade(): ABJ_404_Solution_DatabaseUpgradeDailyMaintenance;

    public function pluginUpdateUpgrade(): ABJ_404_Solution_DatabaseUpgradePluginUpdate;

    public function tableRepairUpgrade(): ABJ_404_Solution_DatabaseUpgradeTableRepair;

    public function indexesUpgrade(): ABJ_404_Solution_DatabaseUpgradeIndexes;

    public function orphanAdoptionUpgrade(): ABJ_404_Solution_DatabaseUpgradeOrphanAdoption;

    public function multiSiteUpgrade(): ABJ_404_Solution_DatabaseUpgradeMultiSite;

    public function schemaDiffUpgrade(): ABJ_404_Solution_DatabaseUpgradeSchemaDiff;

    public function bootstrapUpgrade(): ABJ_404_Solution_DatabaseUpgradeBootstrap;

    /**
     * @param bool $updatingToNewVersion
     * @return void
     */
    public function createDatabaseTables($updatingToNewVersion = false, bool $force = false);

    /** @return void */
    public function runSelfHealPrologue();

    /** @return string|null */
    public function getUpgradeRuntimeId();

    public function isLogsv2CanonicalBackfillScheduled(): bool;

    public function setLogsv2CanonicalBackfillScheduled(bool $scheduled): void;

    public function getCanonicalUrlBackfillChunkSize(): int;

    public function getCanonicalUrlBackfillTimeBudgetSec(): float;

    public function getLogsv2CanonicalUrlBackfillTimeBudgetSec(): float;

    public function getLogsv2CanonicalUrlBackfillCompleteOption(): string;

    public function getRedirectsCanonicalUrlBackfillCompleteOption(): string;

    /** @return array<int, string> */
    public function getPluginTableSuffixes(): array;
}
