<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewSnapshotCache.php';
require_once __DIR__ . '/AdminViewReadCoordinator.php';

/**
 * Compatibility facade for admin view-read collaborators.
 *
 * The class started as a kitchen-sink "ViewReadService" carrying status
 * counts, bulk redirect reads, logs metrics, DB metadata, and the staged
 * view-read pipeline itself. Its current responsibility is preserving the
 * public interface while delegating the actual work to focused collaborators:
 *
 *   - ABJ_404_Solution_AdminViewReadCoordinator -- row/count reads + snapshots
 *   - ABJ_404_Solution_StatusCountsRepository  -- aggregate status tallies
 *   - ABJ_404_Solution_RedirectsBulkReader     -- non-paginated redirect reads
 *   - ABJ_404_Solution_LogsMetricsReader       -- logs row count + disk usage
 *   - ABJ_404_Solution_DatabaseMetadataReader  -- engine + post-type metadata
 *   - ABJ_404_Solution_ViewQueryBuilder        -- staged SQL construction
 *   - ABJ_404_Solution_ViewSnapshotCache       -- snapshot CRUD + warmup
 *   - ABJ_404_Solution_ViewCacheInvalidator    -- invalidation primitives
 *   - ABJ_404_Solution_ViewDiagnostics         -- failure diagnostics
 *
 * The slim-facade pattern matches NGramFilter (i804): the interface stays
 * intact so existing callers and ~80 test stubs keep working, while the
 * actual work happens in single-responsibility collaborators that the rest
 * of the codebase can also wire directly.
 *
 * @see docs/dataaccess-refactor-plan.md Phase 6.
 */
class ABJ_404_Solution_ViewReadService implements ABJ_404_Solution_ViewReadServiceInterface, ABJ_404_Solution_ViewSnapshotCacheHostInterface {
    /** @var bool Legacy reflection bridge for tests and old diagnostics. */
    private static $viewSnapshotTableEnsured = false;

    const CACHE_KEY_REDIRECT_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS;
    const CACHE_KEY_CAPTURED_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS;
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED;
    const STATUS_CACHE_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TTL;
    const STATUS_CACHE_TIMEOUT_SELFHEAL_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TIMEOUT_SELFHEAL_TTL;
    const VIEW_SNAPSHOT_CACHE_TTL_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_CACHE_TTL_SECONDS;
    const VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_REFRESH_COOLDOWN_SECONDS;
    const VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_STAGE_TIMEOUT_SECONDS;
    const VIEW_SNAPSHOT_WARMUP_STALE_SECONDS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_STALE_SECONDS;
    const VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_WARMUP_MAX_ATTEMPTS;
    const VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES = ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_MAX_PAYLOAD_BYTES;
    const HITS_TABLE_LAST_CHECKED_FLAG = ABJ_404_Solution_ViewReadRuntimeState::HITS_TABLE_LAST_CHECKED_FLAG;
    const HITS_TABLE_LAST_DECISION_FLAG = ABJ_404_Solution_ViewReadRuntimeState::HITS_TABLE_LAST_DECISION_FLAG;
    const LOGS_COUNT_CACHE_TTL_SECONDS = ABJ_404_Solution_LogsMetricsReader::LOGS_COUNT_CACHE_TTL_SECONDS;

    /** @var bool Per-request "bulk mutation in progress" flag. */
    public static $bulkMutationInProgress = false;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    // --- Collaborators ---

    /** @var ABJ_404_Solution_ViewQueryBuilder */
    private $queryBuilder;

    /** @var ABJ_404_Solution_ViewDiagnostics */
    private $diagnostics;

    /** @var ABJ_404_Solution_ViewCacheInvalidator */
    private $cacheInvalidator;

    /** @var ABJ_404_Solution_ViewSnapshotCache */
    private $snapshotCache;

    /** @var ABJ_404_Solution_StatusCountsRepository */
    private $statusCounts;

    /** @var ABJ_404_Solution_RedirectsBulkReader */
    private $redirectsBulkReader;

    /** @var ABJ_404_Solution_LogsMetricsReader */
    private $logsMetricsReader;

    /** @var ABJ_404_Solution_DatabaseMetadataReader */
    private $dbMetadataReader;

    /** @var ABJ_404_Solution_HitsTableRebuildPolicy */
    private $hitsTableRebuildPolicy;

    /** @var ABJ_404_Solution_AdminViewReadCoordinator */
    private $adminViewReadCoordinator;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepo
     * @param ABJ_404_Solution_Functions|null $f Falls back to abj_service('functions')
     * @param ABJ_404_Solution_Logging|null $logger Falls back to abj_service('logging')
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_LogsRepository $logsRepo,
        ABJ_404_Solution_RedirectsRepository $redirectsRepo,
        $f = null,
        $logger = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f !== null ? $f : abj_service('functions');
        $this->logger = $logger !== null ? $logger : abj_service('logging');

        $this->diagnostics = new ABJ_404_Solution_ViewDiagnostics($dbCore);
        $this->cacheInvalidator = new ABJ_404_Solution_ViewCacheInvalidator(
            $dbCore, $redirectsRepo, $this->viewDoneFreshnessOptionName()
        );
        $this->queryBuilder = new ABJ_404_Solution_ViewQueryBuilder(
            $dbCore, $this->f, $logsRepo, $this->logger
        );
        $this->queryBuilder->setHost($this);
        $this->snapshotCache = new ABJ_404_Solution_ViewSnapshotCache($dbCore, $this->logger);
        $this->snapshotCache->setHost($this);

        $this->statusCounts = new ABJ_404_Solution_StatusCountsRepository($dbCore, $logsRepo, $this->queryBuilder);
        $this->redirectsBulkReader = new ABJ_404_Solution_RedirectsBulkReader($dbCore, $this->queryBuilder, $this->f);
        $this->logsMetricsReader = new ABJ_404_Solution_LogsMetricsReader($dbCore, $logsRepo, $this->f, $this->logger);
        $this->dbMetadataReader = new ABJ_404_Solution_DatabaseMetadataReader($dbCore);
        $this->hitsTableRebuildPolicy = new ABJ_404_Solution_HitsTableRebuildPolicy($dbCore, $logsRepo, $this->logger);
        $this->adminViewReadCoordinator = new ABJ_404_Solution_AdminViewReadCoordinator(
            $dbCore,
            $this->queryBuilder,
            $this->diagnostics,
            $this->cacheInvalidator,
            $this->snapshotCache,
            $this->logger
        );
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->cacheInvalidator->setViewBuildOrchestrator($viewBuildOrchestrator);
        $this->queryBuilder->setViewBuildOrchestrator($viewBuildOrchestrator);
        $this->snapshotCache->setViewBuildOrchestrator($viewBuildOrchestrator);
        $this->adminViewReadCoordinator->setViewBuildOrchestrator($viewBuildOrchestrator);
    }

    /** @param bool $value @return void */
    public static function setViewSnapshotTableEnsured(bool $value): void {
        self::$viewSnapshotTableEnsured = $value;
        ABJ_404_Solution_ViewSnapshotCache::setViewSnapshotTableEnsured($value);
    }

    /** @return bool */
    public static function isViewSnapshotTableEnsured(): bool {
        return self::$viewSnapshotTableEnsured;
    }

    /** @return string */
    private function viewDoneFreshnessOptionName(): string {
        return $this->dbCore->tableNameResolver()->getLowercasePrefix() . 'abj404_view_done_built_at';
    }

    // =========================================================================
    // Delegated: AdminViewReadCoordinator
    // =========================================================================

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int|string, mixed>
     */
    function getRedirectsForView($sub, $tableOptions) {
        return $this->adminViewReadCoordinator->getRedirectsForView($sub, $tableOptions);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    function viewRowsSnapshotAvailable($sub, array $tableOptions): bool {
        return $this->adminViewReadCoordinator->viewRowsSnapshotAvailable($sub, $tableOptions);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    function viewTableSnapshotAvailable($sub, array $tableOptions): bool {
        return $this->adminViewReadCoordinator->viewTableSnapshotAvailable($sub, $tableOptions);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    function getRedirectsForViewCount(string $sub, array $tableOptions): int {
        return $this->adminViewReadCoordinator->getRedirectsForViewCount($sub, $tableOptions);
    }

    // =========================================================================
    // Delegated: HitsTableRebuildPolicy
    // =========================================================================

    /** @return void */
    function maybeUpdateRedirectsForViewHitsTable(): void {
        $this->hitsTableRebuildPolicy->maybeUpdateRedirectsForViewHitsTable();
    }

    // =========================================================================
    // Delegated: StatusCountsRepository
    // =========================================================================

    /** @param bool $bypassCache @return array<string, int> */
    function getRedirectStatusCounts($bypassCache = false): array {
        return $this->statusCounts->getRedirectStatusCounts((bool)$bypassCache);
    }

    /** @param bool $bypassCache @return array<string, int> */
    function getCapturedStatusCounts($bypassCache = false): array {
        return $this->statusCounts->getCapturedStatusCounts((bool)$bypassCache);
    }

    /** @return int */
    function getHighImpactCapturedCount(): int {
        return $this->statusCounts->getHighImpactCapturedCount();
    }

    /** @return int */
    function getCapturedCount() {
        return $this->statusCounts->getCapturedCount();
    }

    /**
     * @param array<int, int> $types
     * @param int $trashed
     * @return int
     */
    function getRecordCount($types = array(), $trashed = 0) {
        return $this->statusCounts->getRecordCount(is_array($types) ? $types : array(), $trashed);
    }

    // =========================================================================
    // Delegated: RedirectsBulkReader
    // =========================================================================

    /** @return array<int, array<string, mixed>> */
    function getRedirectsAll() {
        return $this->redirectsBulkReader->getRedirectsAll();
    }

    /** @param string $tempFile @return void */
    function doRedirectsExport(string $tempFile): void {
        $this->redirectsBulkReader->doRedirectsExport($tempFile);
    }

    /** @return array<int, array<string, mixed>> */
    function getRedirectsWithLogs() {
        return $this->redirectsBulkReader->getRedirectsWithLogs();
    }

    /** @return array<int, array<string, mixed>> */
    function getRedirectsWithRegEx() {
        return $this->redirectsBulkReader->getRedirectsWithRegEx();
    }

    /** @return array<int, array<string, mixed>> */
    function getManualRedirectsWithRegexMetachars() {
        return $this->redirectsBulkReader->getManualRedirectsWithRegexMetachars();
    }

    /** @param array<int, string> $postIDs @return array<int, mixed> */
    function getExtraDataToPermalinkSuggestions(array $postIDs): array {
        return $this->redirectsBulkReader->getExtraDataToPermalinkSuggestions($postIDs);
    }

    // =========================================================================
    // Delegated: LogsMetricsReader
    // =========================================================================

    /** @param int $logID @return int */
    function getLogsCount($logID) {
        return $this->logsMetricsReader->getLogsCount($logID);
    }

    /** @return int */
    function getLogDiskUsage() {
        return $this->logsMetricsReader->getLogDiskUsage();
    }

    // =========================================================================
    // Delegated: DatabaseMetadataReader
    // =========================================================================

    /** @return array<string, mixed> */
    function getTableEngines() {
        return $this->dbMetadataReader->getTableEngines();
    }

    /** @return bool */
    function isMyISAMSupported(): bool {
        return $this->dbMetadataReader->isMyISAMSupported();
    }

    /** @return array<int, string> */
    function getAllPostTypes() {
        return $this->dbMetadataReader->getAllPostTypes();
    }

    // =========================================================================
    // Delegated: ViewQueryBuilder
    // =========================================================================

    /** @return string */
    function buildHighImpactCapturedCountQuery(): string {
        return $this->queryBuilder->buildHighImpactCapturedCountQuery();
    }

    /**
     * @param ABJ_404_Solution_ViewListQueryRequest $request
     * @return string
     */
    function getRedirectsForViewQuery(ABJ_404_Solution_ViewListQueryRequest $request) {
        return $this->queryBuilder->getRedirectsForViewQuery($request);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array {
        return $this->queryBuilder->readFromViewDone($sub, $tableOptions);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        return $this->queryBuilder->buildViewDoneCountQuery($sub, $tableOptions);
    }

    /** @return array<string, string> */
    public function viewBuildOnlyTranslations(): array {
        return $this->queryBuilder->viewBuildOnlyTranslations();
    }

    // =========================================================================
    // Delegated: ViewCacheInvalidator
    // =========================================================================

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runWithDeferredInvalidation(callable $work) {
        return $this->cacheInvalidator->runWithDeferredInvalidation($work);
    }

    /** @return void */
    function invalidateStatusCountsCache(): void {
        $this->cacheInvalidator->invalidateStatusCountsCache();
    }

    /** @return void */
    function invalidateViewSnapshotCache(): void {
        $this->cacheInvalidator->invalidateViewSnapshotCache();
    }

    /** @return void */
    function clearRegexRedirectsCache(): void {
        $this->cacheInvalidator->clearRegexRedirectsCache();
    }

    // =========================================================================
    // Delegated: ViewDiagnostics
    // =========================================================================

    /**
     * @param string $sub
     * @param string $failedQuery
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $queryResult
     * @return array<string, mixed>
     */
    public function captureViewQueryFailureDiagnostics(string $sub, string $failedQuery, array $tableOptions, array $queryResult): array {
        return $this->diagnostics->captureViewQueryFailureDiagnostics($sub, $failedQuery, $tableOptions, $queryResult);
    }

    // =========================================================================
    // Delegated: ViewSnapshotCache
    // =========================================================================

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    function warmViewTableSnapshotStage(string $sub, array $tableOptions): array {
        return $this->snapshotCache->warmViewTableSnapshotStage($sub, $tableOptions);
    }

    /** @return array<string, int> */
    public function getViewBuildProgressFingerprint(): array {
        return $this->snapshotCache->getViewBuildProgressFingerprint();
    }
}
