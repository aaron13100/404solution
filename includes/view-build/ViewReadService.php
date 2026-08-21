<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/AdminViewReadCoordinator.php';

/**
 * Compatibility facade for admin view-read collaborators.
 *
 * Preserves the public view-read interface while delegating each operation to
 * focused collaborators:
 *
 *   - ABJ_404_Solution_AdminViewReadCoordinator -- row/count reads + snapshots
 *   - ABJ_404_Solution_StatusCountsRepository -- cached aggregate status tallies
 *   - ABJ_404_Solution_RedirectHitCountHistogramRepository -- telemetry buckets
 *   - ABJ_404_Solution_RedirectRowCountRepository -- uncached row totals
 *   - ABJ_404_Solution_RedirectsBulkReader     -- non-paginated redirect reads
 *   - ABJ_404_Solution_LogsMetricsReader       -- logs row count + disk usage
 *   - ABJ_404_Solution_DatabaseMetadataReader  -- engine + post-type metadata
 *   - ABJ_404_Solution_ViewQueryBuilder        -- single-table SQL construction
 *   - ABJ_404_Solution_ViewCacheInvalidator    -- invalidation primitives
 *   - ABJ_404_Solution_ViewDiagnostics         -- failure diagnostics
 *
 * @see docs/dataaccess-refactor-plan.md Phase 6.
 */
class ABJ_404_Solution_ViewReadService implements ABJ_404_Solution_ViewReadServiceInterface {

    const CACHE_KEY_REDIRECT_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS;
    const CACHE_KEY_CAPTURED_STATUS = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS;
    const CACHE_KEY_HIGH_IMPACT_CAPTURED = ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED;
    const STATUS_CACHE_TTL = ABJ_404_Solution_ViewReadRuntimeState::STATUS_CACHE_TTL;
    const LOGS_COUNT_CACHE_TTL_SECONDS = ABJ_404_Solution_LogsMetricsReader::LOGS_COUNT_CACHE_TTL_SECONDS;

    /** @var bool Per-request "bulk mutation in progress" flag. */
    public static $bulkMutationInProgress = false;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    // --- Collaborators ---

    /** @var ABJ_404_Solution_ViewQueryBuilder */
    private $queryBuilder;

    /** @var ABJ_404_Solution_ViewDiagnostics */
    private $diagnostics;

    /** @var ABJ_404_Solution_ViewCacheInvalidator */
    private $cacheInvalidator;

    /** @var ABJ_404_Solution_RedirectHitCountHistogramRepository */
    private $redirectHitCountHistogram;

    /** @var ABJ_404_Solution_RedirectRowCountRepository */
    private $redirectRowCounts;

    /** @var ABJ_404_Solution_StatusCountsRefreshCoordinator */
    private $statusCountsRefreshCoordinator;

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

    /** @var ABJ_404_Solution_RedirectsViewLiveResolver */
    private $liveResolver;

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
        $functions = $f !== null ? $f : abj_service('functions');
        $resolvedLogger = $logger !== null ? $logger : abj_service('logging');

        $this->diagnostics = new ABJ_404_Solution_ViewDiagnostics($dbCore);
        $this->cacheInvalidator = new ABJ_404_Solution_ViewCacheInvalidator(
            $dbCore, $redirectsRepo, $this->viewDoneFreshnessOptionName()
        );
        $this->queryBuilder = new ABJ_404_Solution_ViewQueryBuilder($dbCore);
        $this->liveResolver = new ABJ_404_Solution_RedirectsViewLiveResolver($dbCore, $functions);

        $readiness = new ABJ_404_Solution_TableReadinessGate(
            $dbCore,
            $dbCore->tableNameResolver()
        );
        $statusCounts = new ABJ_404_Solution_StatusCountsRepository(
            $dbCore,
            $logsRepo,
            $this->queryBuilder,
            $readiness
        );
        $this->redirectHitCountHistogram = new ABJ_404_Solution_RedirectHitCountHistogramRepository(
            $dbCore,
            $readiness
        );
        $this->redirectRowCounts = new ABJ_404_Solution_RedirectRowCountRepository(
            $dbCore,
            $readiness
        );
        $this->statusCountsRefreshCoordinator = new ABJ_404_Solution_StatusCountsRefreshCoordinator(
            $statusCounts,
            new ABJ_404_Solution_StatsRefreshLock($dbCore),
            static function(string $message) use ($resolvedLogger): void {
                $resolvedLogger->warn($message);
            }
        );
        $this->redirectsBulkReader = new ABJ_404_Solution_RedirectsBulkReader($dbCore, $this->queryBuilder, $functions);
        $this->logsMetricsReader = new ABJ_404_Solution_LogsMetricsReader($dbCore, $logsRepo, $functions, $resolvedLogger);
        $this->dbMetadataReader = new ABJ_404_Solution_DatabaseMetadataReader($dbCore);
        $this->hitsTableRebuildPolicy = new ABJ_404_Solution_HitsTableRebuildPolicy($dbCore, $logsRepo, $resolvedLogger);
        $this->adminViewReadCoordinator = new ABJ_404_Solution_AdminViewReadCoordinator(
            $dbCore,
            $this->queryBuilder,
            $this->diagnostics,
            $this->cacheInvalidator,
            $this->liveResolver,
            $resolvedLogger
        );
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
     * Map a UI orderby alias to the narrow sort-key column that backs it, or ''
     * for a sort that is not sort-key-backed (it orders by a real always-present
     * column and is therefore always available).
     *
     * @var array<string, string>
     */
    const ORDERBY_TO_SORT_KEY = array(
        'url'        => 'url_sort_key',
        'dest'       => 'dest_sort_key',
        'final_dest' => 'dest_sort_key',
    );

    /** @var int|null Per-request memo of MAX(id) for the progress denominator. */
    private $sortKeyMaxIdMemo = null;

    /**
     * Whether ordering the admin list by $orderby can be served index-ordered
     * right now. For a narrow-sort-key-backed column (URL, Destination) that
     * means the column exists AND its one-time legacy-row drain has converged
     * (the latch is set) -- the SAME condition the read path uses before ordering
     * by the key (see ViewQueryBuilder::wideColumnSortPendingBackfill /
     * AdminViewReadCoordinator). Sorts on real always-populated columns
     * (logshits, last_used, score, timestamp, ...) are always ready.
     *
     * The admin header uses this to disable the URL / Destination sort links on
     * the captured tab during the post-upgrade window, where ordering by those
     * columns would otherwise filesort the captured majority over the wide
     * varchar(2048) source column and risk the host's max_statement_time.
     *
     * @param string $orderby UI orderby alias (url, final_dest, logshits, ...).
     * @return bool
     */
    public function isSortReadyForOrderby(string $orderby): bool {
        return $this->sortReadinessStatusForOrderby($orderby)
            === ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_READY;
    }

    /**
     * @param string $orderby UI orderby alias (url, final_dest, logshits, ...).
     * @return string One of ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_*.
     */
    public function sortReadinessStatusForOrderby(string $orderby): string {
        $column = self::ORDERBY_TO_SORT_KEY[strtolower($orderby)] ?? '';
        if ($column === '') {
            return ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_READY;
        }

        $readiness = $this->liveResolver->schemaReadiness();
        if (!$readiness->sortKeySchemaAvailableForColumn($column)) {
            return ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_SCHEMA_UNAVAILABLE;
        }
        if ($readiness->sortKeyReadyForColumn($column)) {
            return ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_READY;
        }
        return ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_BACKFILL_PENDING;
    }

    /**
     * Backfill progress for $orderby's narrow sort key as a 0..100 integer, for
     * the admin "building the index" tooltip. 100 once ready (or when the sort is
     * not sort-key-backed). Otherwise derived from the drain cursor (highest
     * redirect id drained) over MAX(id): a wp_options read plus an O(1)
     * primary-key probe, NEVER a COUNT over the captured rows. This is a
     * high-water estimate over the id space, not an exact row-count fraction, so
     * sparse ids are acceptable. Capped at 99 until the latch flips so the
     * tooltip never claims 100% before the sort is actually available. A wrapped
     * cursor of 0 is shown as 0% until the latch flips or the next drain advances
     * it again.
     *
     * @param string $orderby UI orderby alias.
     * @return int
     */
    public function sortBackfillPercentForOrderby(string $orderby): int {
        if ($this->isSortReadyForOrderby($orderby)) {
            return 100;
        }
        $column = self::ORDERBY_TO_SORT_KEY[strtolower($orderby)] ?? '';
        if ($column === '' || !function_exists('get_option')) {
            return 0;
        }
        $cursorOption = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillCursorOption($column);
        $cursorRaw = get_option($cursorOption);
        $cursor = is_numeric($cursorRaw) ? (int) $cursorRaw : 0;
        $maxId = $this->sortKeyMaxId();
        if ($maxId <= 0 || $cursor <= 0) {
            return 0;
        }
        return max(1, min(99, (int) floor(100 * $cursor / $maxId)));
    }

    /** @return int MAX(id) on the redirects table (O(1) PK probe), memoized per request. */
    private function sortKeyMaxId(): int {
        if ($this->sortKeyMaxIdMemo !== null) {
            return $this->sortKeyMaxIdMemo;
        }
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $result = $this->dbCore->queryAndGetResults('SELECT MAX(id) AS max_id FROM ' . $table);
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $firstRow = is_array($rows[0] ?? null) ? $rows[0] : array();
        $raw = $firstRow['max_id'] ?? 0;
        $this->sortKeyMaxIdMemo = is_numeric($raw) ? max(0, (int) $raw) : 0;
        return $this->sortKeyMaxIdMemo;
    }

    /**
     * Whether the most recent getRedirectsForView() result is NOT a trustworthy
     * "genuinely empty" listing (pending build, errored read, or an empty
     * snapshot contradicting the live source count). Drives the renderer's
     * "still preparing" state and the AJAX view-build poller re-engagement.
     *
     * @return bool
     */
    function lastRedirectsViewReadWasIncomplete(): bool {
        return $this->adminViewReadCoordinator->lastRedirectsViewReadWasIncomplete();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int Negative when the count query was incomplete or unavailable.
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
    // Delegated: status and row-count repositories
    // =========================================================================

    /**
     * @param bool $bypassCache Retained for compatibility; foreground reads are always cache-only.
     * @param array<string, mixed> $tableOptions Retained for compatibility; never enables a foreground query.
     * @return array<string, int>
     */
    function getRedirectStatusCounts($bypassCache = false, array $tableOptions = array()): array {
        unset($bypassCache, $tableOptions);
        return $this->statusCountsRefreshCoordinator->refreshingRedirectStatusCounts();
    }

    /** @inheritDoc Enqueues a recomputation when the cache is stale. */
    function getRedirectStatusCountsResult(): array {
        return $this->statusCountsRefreshCoordinator->refreshingRedirectStatusCountsResult();
    }

    /** @return array<string, int> */
    function getRedirectHitCountHistogram(): array {
        return $this->redirectHitCountHistogram->getRedirectHitCountHistogram();
    }

    /**
     * @param bool $bypassCache Retained for compatibility; foreground reads are always cache-only.
     * @param array<string, mixed> $tableOptions Retained for compatibility; never enables a foreground query.
     * @return array<string, int>
     */
    function getCapturedStatusCounts($bypassCache = false, array $tableOptions = array()): array {
        unset($bypassCache, $tableOptions);
        return $this->statusCountsRefreshCoordinator->refreshingCapturedStatusCounts();
    }

    /** @inheritDoc Enqueues a recomputation when the cache is stale. */
    function getCapturedStatusCountsResult(): array {
        return $this->statusCountsRefreshCoordinator->refreshingCapturedStatusCountsResult();
    }

    /**
     * Cron-only status-count recomputation. Foreground callers can only enqueue
     * the work, which prevents a cache miss from entering an aggregate query.
     */
    public function refreshStatusCounts(string $scope): void {
        $this->statusCountsRefreshCoordinator->refresh($scope);
    }

    /** @return int|null Enqueues a recomputation when the cache is stale. */
    function getHighImpactCapturedCount(): ?int {
        return $this->statusCountsRefreshCoordinator->refreshingHighImpactCapturedCount();
    }

    /** @return int */
    function getCapturedCount() {
        return $this->redirectRowCounts->getCapturedCount();
    }

    /**
     * @param array<int, int> $types
     * @param int $trashed
     * @return int
     */
    function getRecordCount($types = array(), $trashed = 0) {
        return $this->redirectRowCounts->getRecordCount(is_array($types) ? $types : array(), $trashed);
    }

    // =========================================================================
    // Delegated: RedirectsBulkReader
    // =========================================================================

    /** @param string $tempFile @return void */
    function doRedirectsExport(string $tempFile): void {
        $this->redirectsBulkReader->doRedirectsExport($tempFile);
    }

    /** @return iterable<int, array<string, mixed>> */
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
     * Single-table redirects read for one page (Denorm Step 3b): fetch the
     * ordered/filtered page off wp_abj404_redirects, then resolve the visible
     * rows' derived/display values live and write the four denorm columns back.
     * This is the live read path that replaces the staged view_done read.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readRedirectsSingleTable(string $sub, array $tableOptions): array {
        return $this->adminViewReadCoordinator->readRedirectsSingleTable($sub, $tableOptions);
    }

    /**
     * Single-table filtered count against wp_abj404_redirects (Denorm Step 3b).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function countRedirectsSingleTable(string $sub, array $tableOptions): int {
        return $this->adminViewReadCoordinator->countRedirectsSingleTable($sub, $tableOptions);
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
}
