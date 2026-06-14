<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the set of database-upgrade components.
 *
 * Instantiates each upgrade component with the owning coordinator and the
 * shared dependency map, exposes every component through a typed accessor, and
 * re-pushes a refreshed dependency map to all of them when the coordinator's
 * collaborators change (e.g. after a test swaps in a mock DataAccess).
 *
 * This isolates the 13-component instantiation/refresh lifecycle from
 * {@see ABJ_404_Solution_DatabaseUpgradesEtc}, whose remaining job is to be the
 * public upgrade facade. Constructed by the coordinator only.
 */
class ABJ_404_Solution_DatabaseUpgradeComponentRegistry {

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
	 * @param ABJ_404_Solution_DatabaseUpgradeCoordinator $owner
	 * @param array<string, mixed> $deps Shared component dependency map.
	 */
	public function __construct(ABJ_404_Solution_DatabaseUpgradeCoordinator $owner, array $deps) {
		$this->nGramUpgrade = new ABJ_404_Solution_DatabaseUpgradeNGram($owner, $deps);
		$this->engineNormalizationUpgrade = new ABJ_404_Solution_DatabaseUpgradeEngineNormalization($owner, $deps);
		$this->collationDriftUpgrade = new ABJ_404_Solution_DatabaseUpgradeCollationDrift($owner, $deps);
		$this->selfHealUpgrade = new ABJ_404_Solution_DatabaseUpgradeSelfHeal($owner, $deps);
		$this->canonicalUrlBackfillUpgrade = new ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill($owner, $deps);
		$this->dailyMaintenanceUpgrade = new ABJ_404_Solution_DatabaseUpgradeDailyMaintenance($owner, $deps);
		$this->pluginUpdateUpgrade = new ABJ_404_Solution_DatabaseUpgradePluginUpdate($owner, $deps);
		$this->tableRepairUpgrade = new ABJ_404_Solution_DatabaseUpgradeTableRepair($owner, $deps);
		$this->indexesUpgrade = new ABJ_404_Solution_DatabaseUpgradeIndexes($owner, $deps);
		$this->orphanAdoptionUpgrade = new ABJ_404_Solution_DatabaseUpgradeOrphanAdoption($owner, $deps);
		$this->multiSiteUpgrade = new ABJ_404_Solution_DatabaseUpgradeMultiSite($owner, $deps);
		$this->schemaDiffUpgrade = new ABJ_404_Solution_DatabaseUpgradeSchemaDiff($owner, $deps);
		$this->bootstrapUpgrade = new ABJ_404_Solution_DatabaseUpgradeBootstrap($owner, $deps);
	}

	/**
	 * Re-push a refreshed dependency map to every component.
	 *
	 * @param array<string, mixed> $deps
	 * @return void
	 */
	public function refreshDependencies(array $deps): void {
		foreach ($this->all() as $component) {
			$component->replaceDatabaseUpgradeDependencies($deps);
		}
	}

	/** @return ABJ_404_Solution_DatabaseUpgradeComponent[] */
	private function all(): array {
		return [
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
		];
	}

	public function nGramUpgrade(): ABJ_404_Solution_DatabaseUpgradeNGram { return $this->nGramUpgrade; }

	public function engineNormalizationUpgrade(): ABJ_404_Solution_DatabaseUpgradeEngineNormalization { return $this->engineNormalizationUpgrade; }

	public function collationDriftUpgrade(): ABJ_404_Solution_DatabaseUpgradeCollationDrift { return $this->collationDriftUpgrade; }

	public function selfHealUpgrade(): ABJ_404_Solution_DatabaseUpgradeSelfHeal { return $this->selfHealUpgrade; }

	public function canonicalUrlBackfillUpgrade(): ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill { return $this->canonicalUrlBackfillUpgrade; }

	public function dailyMaintenanceUpgrade(): ABJ_404_Solution_DatabaseUpgradeDailyMaintenance { return $this->dailyMaintenanceUpgrade; }

	public function pluginUpdateUpgrade(): ABJ_404_Solution_DatabaseUpgradePluginUpdate { return $this->pluginUpdateUpgrade; }

	public function tableRepairUpgrade(): ABJ_404_Solution_DatabaseUpgradeTableRepair { return $this->tableRepairUpgrade; }

	public function indexesUpgrade(): ABJ_404_Solution_DatabaseUpgradeIndexes { return $this->indexesUpgrade; }

	public function orphanAdoptionUpgrade(): ABJ_404_Solution_DatabaseUpgradeOrphanAdoption { return $this->orphanAdoptionUpgrade; }

	public function multiSiteUpgrade(): ABJ_404_Solution_DatabaseUpgradeMultiSite { return $this->multiSiteUpgrade; }

	public function schemaDiffUpgrade(): ABJ_404_Solution_DatabaseUpgradeSchemaDiff { return $this->schemaDiffUpgrade; }

	public function bootstrapUpgrade(): ABJ_404_Solution_DatabaseUpgradeBootstrap { return $this->bootstrapUpgrade; }
}
