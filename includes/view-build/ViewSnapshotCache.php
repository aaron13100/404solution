<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewSnapshotCacheHostInterface.php';
require_once __DIR__ . '/ViewSnapshotStore.php';
require_once __DIR__ . '/ViewWarmupStatePolicy.php';
require_once __DIR__ . '/ViewWarmupDiagnostics.php';
require_once __DIR__ . '/ViewSnapshotWarmupOrchestrator.php';

class ABJ_404_Solution_ViewSnapshotCache {

    /** @var ABJ_404_Solution_ViewSnapshotStore */
    private $snapshotStore;

    /** @var ABJ_404_Solution_ViewWarmupStatePolicy */
    private $warmupStatePolicy;

    /** @var ABJ_404_Solution_ViewWarmupDiagnostics */
    private $warmupDiagnostics;

    /** @var ABJ_404_Solution_ViewSnapshotWarmupOrchestrator */
    private $warmupOrchestrator;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $logger
    ) {
        $this->snapshotStore = new ABJ_404_Solution_ViewSnapshotStore($dbCore);
        $this->warmupStatePolicy = new ABJ_404_Solution_ViewWarmupStatePolicy($dbCore);
        $this->warmupDiagnostics = new ABJ_404_Solution_ViewWarmupDiagnostics($logger, $this->warmupStatePolicy);
        $this->warmupOrchestrator = new ABJ_404_Solution_ViewSnapshotWarmupOrchestrator(
            $logger,
            $this->snapshotStore,
            $this->warmupStatePolicy,
            $this->warmupDiagnostics
        );
    }

    /**
     * @param ABJ_404_Solution_ViewSnapshotCacheHostInterface $host
     * @return void
     */
    public function setHost(ABJ_404_Solution_ViewSnapshotCacheHostInterface $host): void {
        $this->warmupOrchestrator->setHost($host);
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->warmupStatePolicy->setViewBuildOrchestrator($viewBuildOrchestrator);
    }

    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        ABJ_404_Solution_ViewSnapshotStore::setViewSnapshotTableEnsured($value);
    }

    /**
     * @param string $prefix
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function getViewSnapshotCacheKey($prefix, $sub, $tableOptions) {
        return $this->snapshotStore->getViewSnapshotCacheKey($prefix, $sub, $tableOptions);
    }

    /** @return bool */
    public function acquireViewSnapshotWarmupGlobalLock(): bool {
        return $this->snapshotStore->acquireViewSnapshotWarmupGlobalLock();
    }

    /** @return void */
    public function releaseViewSnapshotWarmupGlobalLock(): void {
        $this->snapshotStore->releaseViewSnapshotWarmupGlobalLock();
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    public function canUseViewTableSnapshotCache(array $tableOptions): bool {
        if (!empty($tableOptions['_abj404_force_view_rebuild'])) {
            return false;
        }
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $isLogsMaintenanceSort = ($orderBy === 'logshits' || $orderBy === 'last_used');
        $rawPerpage = $tableOptions['perpage'] ?? 0;
        return absint(is_scalar($rawPerpage) ? $rawPerpage : 0) <= 200 && !$isLogsMaintenanceSort;
    }

    /** @return array<string, int> */
    public function getViewBuildProgressFingerprint(): array {
        return $this->warmupStatePolicy->getViewBuildProgressFingerprint();
    }

    /**
     * Drive the next warmup stage for the given table shape. Early-returns
     * for shapes that are not snapshot-cacheable (large perpage, maintenance
     * sort orders, force-rebuild flag); otherwise delegates to the warmup
     * orchestrator that owns the multi-attempt, multi-stage, lock-protected
     * state machine.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    public function warmViewTableSnapshotStage(string $sub, array $tableOptions): array {
        if (!$this->canUseViewTableSnapshotCache($tableOptions)) {
            return array(
                'status' => 'ready',
                'ready' => true,
                'uncached' => true,
                'stage' => 'rows',
                'stageNumber' => 1,
                'queryLabel' => 'getRedirectsForView',
                'message' => 'This table shape is not snapshot-cacheable.',
            );
        }
        return $this->warmupOrchestrator->warmViewTableSnapshotStage($sub, $tableOptions);
    }

    /**
     * @param string $cacheKey
     * @param bool $allowExpired
     * @param bool $respectCooldown
     * @return array<int|string, mixed>|null
     */
    public function getViewRowsSnapshotFromTable(string $cacheKey, bool $allowExpired = false, bool $respectCooldown = false) {
        return $this->snapshotStore->getViewRowsSnapshotFromTable($cacheKey, $allowExpired, $respectCooldown);
    }

    /**
     * @param string $cacheKey
     * @param string $sub
     * @param mixed $rows
     * @param int $ttlSeconds
     * @return void
     */
    public function setViewRowsSnapshotToTable(string $cacheKey, string $sub, $rows, int $ttlSeconds): void {
        $this->snapshotStore->setViewRowsSnapshotToTable($cacheKey, $sub, $rows, $ttlSeconds);
    }

    /**
     * @param string $cacheKey
     * @param int $timeoutMs
     * @return array<int|string, mixed>|null
     */
    public function waitForViewRowsSnapshotFromTable(string $cacheKey, int $timeoutMs = 4000) {
        return $this->snapshotStore->waitForViewRowsSnapshotFromTable($cacheKey, $timeoutMs);
    }

}
