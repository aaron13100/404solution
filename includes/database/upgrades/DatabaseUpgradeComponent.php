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
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::getRuntimeId();
    }

    protected function isLogsv2CanonicalBackfillScheduled(): bool {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::isLogsv2CanonicalBackfillScheduled();
    }

    protected function setLogsv2CanonicalBackfillScheduled(bool $scheduled): void {
        ABJ_404_Solution_DatabaseUpgradeRuntimeState::setLogsv2CanonicalBackfillScheduled($scheduled);
    }

    protected function getCanonicalUrlBackfillChunkSize(): int {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::CANONICAL_URL_BACKFILL_CHUNK_SIZE;
    }

    protected function getCanonicalUrlBackfillTimeBudgetSec(): float {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC;
    }

    protected function getLogsv2CanonicalUrlBackfillTimeBudgetSec(): float {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::LOGSV2_CANONICAL_URL_BACKFILL_TIME_BUDGET_SEC;
    }

    protected function getLogsv2CanonicalUrlBackfillCompleteOption(): string {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::LOGSV2_CANONICAL_URL_BACKFILL_COMPLETE_OPTION;
    }

    protected function getRedirectsCanonicalUrlBackfillCompleteOption(): string {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_CANONICAL_URL_BACKFILL_COMPLETE_OPTION;
    }

    protected function getRedirectsDenormBackfillChunkSize(): int {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_CHUNK_SIZE;
    }

    protected function getRedirectsDenormBackfillTimeBudgetSec(): float {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_TIME_BUDGET_SEC;
    }

    protected function getRedirectsDenormBackfillCompleteOption(): string {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_BACKFILL_COMPLETE_OPTION;
    }

    protected function getRedirectsDenormReconcileChunkSize(): int {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_RECONCILE_CHUNK_SIZE;
    }

    protected function getRedirectsDenormReconcileTimeBudgetSec(): float {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_RECONCILE_TIME_BUDGET_SEC;
    }

    protected function getRedirectsDenormReconcileCursorOption(): string {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::REDIRECTS_DENORM_RECONCILE_CURSOR_OPTION;
    }

    /** @return array<int, string> */
    protected function getPluginTableSuffixes(): array {
        return ABJ_404_Solution_DatabaseUpgradeRuntimeState::getPluginTableSuffixes();
    }
}
