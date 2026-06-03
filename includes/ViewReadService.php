<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ViewSnapshotCache.php';

/**
 * Admin redirect-list staged view-read pipeline + hits-table-rebuild lifecycle.
 *
 * The class started as a kitchen-sink "ViewReadService" carrying status
 * counts, bulk redirect reads, logs metrics, DB metadata, and the staged
 * view-read pipeline itself. As of the i805 decomposition the focused
 * responsibility kept here is the staged admin-list read pipeline plus the
 * hits-table-rebuild policy that gates its joined queries. The remaining
 * interface methods are one-line delegations to focused collaborators:
 *
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

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

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

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface|null */
    private $viewBuildOrchestrator;

    /** @var array<string, int> */
    private $redirectsForViewCountRequestCache = array();

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
        $this->logsRepo = $logsRepo;
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
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->viewBuildOrchestrator = $viewBuildOrchestrator;
        $this->cacheInvalidator->setViewBuildOrchestrator($viewBuildOrchestrator);
        $this->queryBuilder->setViewBuildOrchestrator($viewBuildOrchestrator);
        $this->snapshotCache->setViewBuildOrchestrator($viewBuildOrchestrator);
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestratorInterface */
    private function requireViewBuildOrchestrator(): ABJ_404_Solution_ViewBuildOrchestratorInterface {
        if ($this->viewBuildOrchestrator === null) {
            throw new \RuntimeException('ViewReadService requires ViewBuildOrchestrator (call setViewBuildOrchestrator first)'); // allow-raw-error: assertion, should never reach user
        }
        return $this->viewBuildOrchestrator;
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
        return $this->dbCore->getLowercasePrefix() . 'abj404_view_done_built_at';
    }

    // =========================================================================
    // Staged view-read pipeline (the residual single responsibility)
    // =========================================================================

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int|string, mixed>
     */
    function getRedirectsForView($sub, $tableOptions) {
        $canUseSnapshotCache = $this->snapshotCache->canUseViewTableSnapshotCache($tableOptions);
        $queryTimeout = isset($tableOptions['_abj404_query_timeout']) && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(1, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $throwOnQueryError = !empty($tableOptions['_abj404_throw_on_view_query_error']);
        $snapshotCacheKey = '';
        if ($canUseSnapshotCache && $queryTimeout <= 0) {
            $snapshotCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
            $cachedRowsFromTable = $this->snapshotCache->getViewRowsSnapshotFromTable($snapshotCacheKey, false, false);
            if (is_array($cachedRowsFromTable)) {
                return $cachedRowsFromTable;
            }
            if (function_exists('get_transient')) {
                $cachedRows = get_transient($snapshotCacheKey);
                if (is_array($cachedRows)) {
                    return $cachedRows;
                }
            }
        }

        try {
            $rows = $this->requireViewBuildOrchestrator()->runRedirectsForViewStaged((string)$sub, is_array($tableOptions) ? $tableOptions : array());
        } catch (ABJ_404_Solution_ViewBuildPendingException $pending) {
            if ($throwOnQueryError) {
                throw $pending;
            }
            $this->logger->debugMessage('[staged] getRedirectsForView pending: ' . $pending->getMessage());
            return array();
        } catch (Throwable $e) {
            if ($throwOnQueryError) {
                $stagedFailureMarker = '/* staged: ' . $e->getMessage() . ' */';
                $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics(
                    (string)$sub,
                    $stagedFailureMarker,
                    is_array($tableOptions) ? $tableOptions : array(),
                    array('last_error' => $e->getMessage(), 'timed_out' => false)
                );
                $diagnostics['failed_query_label'] = 'getRedirectsForView';
                $diagnostics['staged_error'] = $e->getMessage();
                $message = 'getRedirectsForView failed; last_error=' . $e->getMessage()
                    . '; timed_out=false; sql_source=' . $stagedFailureMarker;
                throw new ABJ_404_Solution_ViewQueryFailureException($message, $diagnostics);
            }
            $this->logger->errorMessage('[staged] getRedirectsForView failed: ' . $e->getMessage(),
                $e instanceof \Exception ? $e : null);
            return array();
        }

        $this->logger->debugMessage(sprintf(
            '[staged] getRedirectsForView returned %d rows for page %s',
            count($rows),
            (string)$sub
        ));

        if ($canUseSnapshotCache && $snapshotCacheKey === '') {
            $snapshotCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
        }
        if ($canUseSnapshotCache && $snapshotCacheKey !== '') {
            $this->snapshotCache->setViewRowsSnapshotToTable($snapshotCacheKey, $sub, $rows, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            if (function_exists('set_transient')) {
                // allow-cache-empty: empty $rows is a legitimate result on a fresh install (no redirects yet); error paths early-return above without reaching this line
                set_transient($snapshotCacheKey, $rows, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            }
        }

        return $rows;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    function viewRowsSnapshotAvailable($sub, array $tableOptions): bool {
        $canUseSnapshotCache = $this->snapshotCache->canUseViewTableSnapshotCache($tableOptions);
        if (!$canUseSnapshotCache) {
            return false;
        }

        $snapshotCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptions);
        $freshRows = $this->snapshotCache->getViewRowsSnapshotFromTable($snapshotCacheKey, false, false);
        if (is_array($freshRows)) {
            return true;
        }
        $recentRows = $this->snapshotCache->getViewRowsSnapshotFromTable($snapshotCacheKey, true, true);
        if (is_array($recentRows)) {
            return true;
        }
        if (function_exists('get_transient')) {
            $transientRows = get_transient($snapshotCacheKey);
            if (is_array($transientRows)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    function viewTableSnapshotAvailable($sub, array $tableOptions): bool {
        if (!$this->viewRowsSnapshotAvailable($sub, $tableOptions)) {
            return false;
        }

        $canUseSnapshotCache = function_exists('get_transient')
            && $this->snapshotCache->canUseViewTableSnapshotCache($tableOptions);
        if (!$canUseSnapshotCache) {
            return false;
        }

        $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
        return get_transient($countCacheKey) !== false;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    function getRedirectsForViewCount(string $sub, array $tableOptions): int {
        $queryTimeout = isset($tableOptions['_abj404_query_timeout']) && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(1, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $throwOnQueryError = !empty($tableOptions['_abj404_throw_on_view_query_error']);
        $canUseSnapshotCache = function_exists('get_transient')
            && $this->snapshotCache->canUseViewTableSnapshotCache($tableOptions);
        $requestCountCacheKey = (string)$sub . '|' . md5(serialize($tableOptions));
        $countCacheKey = '';
        if ($canUseSnapshotCache && $queryTimeout <= 0) {
            $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
            $cachedCount = get_transient($countCacheKey);
            if ($cachedCount !== false) {
                return intval(is_scalar($cachedCount) ? $cachedCount : 0);
            }
        }
        if (array_key_exists($requestCountCacheKey, $this->redirectsForViewCountRequestCache)) {
            return intval($this->redirectsForViewCountRequestCache[$requestCountCacheKey]);
        }

        $rawFilterText = is_string($tableOptions['filterText'] ?? null) ? $tableOptions['filterText'] : '';
        if ($rawFilterText === '') {
            $query = $this->queryBuilder->getOptimizedRedirectsForViewCountQuery($sub, $tableOptions);
            $this->cacheInvalidator->setSqlBigSelects();
            $queryOptions = $queryTimeout > 0 ? array('timeout' => $queryTimeout) : array();
            $results = $this->dbCore->queryAndGetResults($query, $queryOptions);
            $lastErrorRaw = $results['last_error'] ?? '';
            $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        } else {
            try {
                $countValue = $this->requireViewBuildOrchestrator()->runRedirectsForViewCountStaged((string)$sub, $tableOptions);
                $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
                if ($canUseSnapshotCache && $countCacheKey === '') {
                    $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
                }
                if ($canUseSnapshotCache && $countCacheKey !== '') {
                    // allow-cache-empty: $countValue=0 is a legitimate result when no rows match the search filter; the staged pending/error paths throw above without reaching this line
                    set_transient($countCacheKey, $countValue, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
                }
                return $countValue;
            } catch (ABJ_404_Solution_ViewBuildPendingException $pending) {
                if ($throwOnQueryError) {
                    throw $pending;
                }
                $this->logger->debugMessage('[staged] getRedirectsForViewCount pending: ' . $pending->getMessage());
                $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
                return -1;
            } catch (Throwable $e) {
                if ($throwOnQueryError) {
                    $stagedFailureMarker = '/* staged-count: ' . $e->getMessage() . ' */';
                    $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics(
                        (string)$sub,
                        $stagedFailureMarker,
                        $tableOptions,
                        array('last_error' => $e->getMessage(), 'timed_out' => false)
                    );
                    $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
                    $diagnostics['staged_error'] = $e->getMessage();
                    throw new ABJ_404_Solution_ViewQueryFailureException($e->getMessage(), $diagnostics);
                }
                $this->logger->errorMessage('[staged] getRedirectsForViewCount failed: ' . $e->getMessage(),
                    $e instanceof \Exception ? $e : null);
                $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
                return -1;
            }
        }

        if ($throwOnQueryError && (!empty($results['timed_out']) || $lastError !== '')) {
            $message = $this->diagnostics->formatViewQueryFailureMessage('getRedirectsForViewCount', $query, $results);
            $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics($sub, $query, $tableOptions, $results);
            $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
            throw new ABJ_404_Solution_ViewQueryFailureException($message, $diagnostics);
        }

        if ($lastError != '' && trim($lastError) != '') {
            $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics($sub, $query, $tableOptions, $results);
            $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
            throw new ABJ_404_Solution_ViewQueryFailureException(
                "Error getting redirect count: " . esc_html($lastError),
                $diagnostics
            );
        }
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($rows)) {
            $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
        	return -1;
        }
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        $rawCount = $row['count'] ?? $row['COUNT(*)'] ?? reset($row);
        $countValue = intval(is_scalar($rawCount) ? $rawCount : 0);
        $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
        if ($canUseSnapshotCache && $countCacheKey === '') {
            $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
        }
        if ($canUseSnapshotCache && $countCacheKey !== '') {
            set_transient($countCacheKey, $countValue, self::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
        }
        return $countValue;
    }

    // =========================================================================
    // Hits-table lifecycle (tightly coupled to staged view-read)
    // =========================================================================

    /** @return void */
    function maybeUpdateRedirectsForViewHitsTable(): void {
        $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_CHECKED_FLAG, time(), 86400);

        if (function_exists('abj_service')) {
            $upgradesEtc = abj_service('database_upgrades');
            if (is_object($upgradesEtc) && method_exists($upgradesEtc, 'scheduleLogsv2CanonicalUrlBackfill')) {
                $upgradesEtc->scheduleLogsv2CanonicalUrlBackfill();
            }
        }

        if ($this->dbCore->shouldSkipNonEssentialDbWrites()) {
            $this->logger->debugMessage(__FUNCTION__ . " skipped due to temporary DB write cooldown.");
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'paused', 86400);
            return;
        }

        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logger->debugMessage(__FUNCTION__ . " table doesn't exist, deferring creation to shutdown hook.");
            $this->logsRepo->scheduleHitsTableRebuild();
            return;
        }

        $this->logsRepo->recordLogsHitsRollupStalenessSignal();

        if (!$this->logsRepo->hitsTableNeedsRebuild()) {
            $this->dbCore->setRuntimeFlag(self::HITS_TABLE_LAST_DECISION_FLAG, 'not_needed', 86400);
            return;
        }

        $this->logsRepo->scheduleHitsTableRebuild();
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
    // Generic helpers (interface-mandated; thin wrappers around $wpdb)
    // =========================================================================

    /**
     * @param string $tableName
     * @param array<string, mixed> $dataToInsert
     * @return array<string, mixed>
     */
    function insertAndGetResults($tableName, $dataToInsert) {
        $tableName = $this->dbCore->doTableNameReplacements($tableName);

        $columns = array();
        $placeholders = array();
        $values = array();

        foreach ($dataToInsert as $column => $value) {
            $columns[] = '`' . $column . '`';

            if ($value === null) {
                $placeholders[] = 'NULL';
            } else {
                $currentDataType = gettype($value);
                if ($currentDataType == 'integer' || $currentDataType == 'double') {
                    $placeholders[] = '%d';
                    $values[] = $value;
                } elseif ($currentDataType == 'boolean') {
                    $placeholders[] = '%d';
                    $values[] = $value ? 1 : 0;
                } else {
                    $placeholders[] = '%s';
                    $values[] = is_scalar($value) ? (string)$value : '';
                }
            }
        }

        $sql = 'INSERT INTO `' . $tableName . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        return $this->dbCore->queryAndGetResults($sql, ['query_params' => $values]);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $data
     * @return string
     */
    function prepare_query_wp($query, $data) {
        global $wpdb;
        list($prepared_query, $ordered_values) = $this->prepare_query($query, $data);
        // DAO-bypass-approved: $wpdb->prepare is read-only string formatting; callers execute the result through queryAndGetResults
        return $wpdb->prepare($prepared_query, $ordered_values);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<int, mixed>}
     */
    function prepare_query($query, $data) {
        $ordered_values = [];
        $prepared_query = preg_replace_callback('/\{(\w+)\}/', function($matches) use ($data, &$ordered_values) {
            $key = $matches[1];
            if (!isset($data[$key])) {
                return $matches[0];
            }
            $value = $data[$key];

            $ordered_values[] = $value;

            $placeholder_type = is_int($value) ? '%d' : '%s';

            return $placeholder_type;
        }, $query);

        return [$prepared_query !== null ? $prepared_query : $query, $ordered_values];
    }

    // =========================================================================
    // Delegated: ViewQueryBuilder
    // =========================================================================

    /** @return string */
    function buildHighImpactCapturedCountQuery(): string {
        return $this->queryBuilder->buildHighImpactCapturedCountQuery();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $queryAllRowsAtOnce
     * @param int $limitStart
     * @param int $limitEnd
     * @param bool $selectCountOnly
     * @return string
     */
    function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
    	$limitStart, $limitEnd, $selectCountOnly) {
        return $this->queryBuilder->getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
            $limitStart, $limitEnd, $selectCountOnly);
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
