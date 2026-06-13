<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared dependency carrier for DatabaseUpgradesEtc delegates.
 */
abstract class ABJ_404_Solution_DatabaseUpgradeComponent {

    /** @var ABJ_404_Solution_DatabaseUpgradeCoordinator */
    private $owner;

    /** @var ABJ_404_Solution_DataAccess */
    protected $dao;

    /** @var ABJ_404_Solution_DatabaseCore */
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

    /** @var mixed */
    protected $ngramExtractor;

    /** @var mixed */
    protected $ngramCacheRepository;

    /** @var mixed */
    protected $ngramCoveragePolicy;

    /** @var mixed */
    protected $ngramRebuilder;

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

    protected function upgrades(): ABJ_404_Solution_DatabaseUpgradeCoordinator {
        return $this->owner;
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

    protected function getRedirectsCanonicalUrlBackfillCompleteOption(): string {
        return $this->owner->getRedirectsCanonicalUrlBackfillCompleteOption();
    }

    /** @return array<int, string> */
    protected function getPluginTableSuffixes(): array {
        return $this->owner->getPluginTableSuffixes();
    }
}
