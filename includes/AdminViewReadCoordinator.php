<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates admin redirects/captured table row and count reads.
 *
 * Owns snapshot-cache hit/miss decisions, staged view_done reads, per-request
 * count memoization, and failure diagnostics for the public
 * getRedirectsForView() / getRedirectsForViewCount() facade methods.
 */
class ABJ_404_Solution_AdminViewReadCoordinator {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewQueryBuilder */
    private $queryBuilder;

    /** @var ABJ_404_Solution_ViewDiagnostics */
    private $diagnostics;

    /** @var ABJ_404_Solution_ViewCacheInvalidator */
    private $cacheInvalidator;

    /** @var ABJ_404_Solution_ViewSnapshotCache */
    private $snapshotCache;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ViewBuildOrchestratorInterface|null */
    private $viewBuildOrchestrator;

    /** @var array<string, int> */
    private $redirectsForViewCountRequestCache = array();

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_ViewQueryBuilder $queryBuilder
     * @param ABJ_404_Solution_ViewDiagnostics $diagnostics
     * @param ABJ_404_Solution_ViewCacheInvalidator $cacheInvalidator
     * @param ABJ_404_Solution_ViewSnapshotCache $snapshotCache
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_ViewQueryBuilder $queryBuilder,
        ABJ_404_Solution_ViewDiagnostics $diagnostics,
        ABJ_404_Solution_ViewCacheInvalidator $cacheInvalidator,
        ABJ_404_Solution_ViewSnapshotCache $snapshotCache,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->queryBuilder = $queryBuilder;
        $this->diagnostics = $diagnostics;
        $this->cacheInvalidator = $cacheInvalidator;
        $this->snapshotCache = $snapshotCache;
        $this->logger = $logger;
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->viewBuildOrchestrator = $viewBuildOrchestrator;
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestratorInterface */
    private function requireViewBuildOrchestrator(): ABJ_404_Solution_ViewBuildOrchestratorInterface {
        if ($this->viewBuildOrchestrator === null) {
            throw new \RuntimeException('AdminViewReadCoordinator requires ViewBuildOrchestrator (call setViewBuildOrchestrator first)'); // allow-raw-error: assertion, should never reach user
        }
        return $this->viewBuildOrchestrator;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int|string, mixed>
     */
    public function getRedirectsForView($sub, $tableOptions) {
        $tableOptionsArray = is_array($tableOptions) ? $tableOptions : array();
        $canUseSnapshotCache = $this->snapshotCache->canUseViewTableSnapshotCache($tableOptionsArray);
        $queryTimeout = isset($tableOptionsArray['_abj404_query_timeout']) && is_numeric($tableOptionsArray['_abj404_query_timeout'])
            ? max(1, intval($tableOptionsArray['_abj404_query_timeout'])) : 0;
        $throwOnQueryError = !empty($tableOptionsArray['_abj404_throw_on_view_query_error']);
        $snapshotCacheKey = '';
        if ($canUseSnapshotCache && $queryTimeout <= 0) {
            $snapshotCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptionsArray);
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
            $rows = $this->requireViewBuildOrchestrator()->runRedirectsForViewStaged((string)$sub, $tableOptionsArray);
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
                    $tableOptionsArray,
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
            $snapshotCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_rows', $sub, $tableOptionsArray);
        }
        if ($canUseSnapshotCache && $snapshotCacheKey !== '') {
            $this->snapshotCache->setViewRowsSnapshotToTable($snapshotCacheKey, $sub, $rows, ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            if (function_exists('set_transient')) {
                // allow-cache-empty: empty rows are legitimate on a fresh install; pending/error paths return before this cache write.
                set_transient($snapshotCacheKey, $rows, ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
            }
        }

        return $rows;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    public function viewRowsSnapshotAvailable($sub, array $tableOptions): bool {
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
    public function viewTableSnapshotAvailable($sub, array $tableOptions): bool {
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
    public function getRedirectsForViewCount(string $sub, array $tableOptions): int {
        $queryTimeout = isset($tableOptions['_abj404_query_timeout']) && is_numeric($tableOptions['_abj404_query_timeout'])
            ? max(1, intval($tableOptions['_abj404_query_timeout'])) : 0;
        $throwOnQueryError = !empty($tableOptions['_abj404_throw_on_view_query_error']);
        $canUseSnapshotCache = function_exists('get_transient')
            && $this->snapshotCache->canUseViewTableSnapshotCache($tableOptions);
        $requestCountCacheKey = (string)$sub . '|' . md5(serialize($tableOptions));
        $countCacheKey = '';

        $cachedCount = $this->getCachedViewCount($sub, $tableOptions, $canUseSnapshotCache, $queryTimeout, $countCacheKey);
        if ($cachedCount !== null) {
            return $cachedCount;
        }
        if (array_key_exists($requestCountCacheKey, $this->redirectsForViewCountRequestCache)) {
            return intval($this->redirectsForViewCountRequestCache[$requestCountCacheKey]);
        }

        $rawFilterText = is_string($tableOptions['filterText'] ?? null) ? $tableOptions['filterText'] : '';
        if ($rawFilterText !== '') {
            return $this->getFilteredViewCount(
                $sub, $tableOptions, $throwOnQueryError, $canUseSnapshotCache,
                $requestCountCacheKey, $countCacheKey
            );
        }

        return $this->getUnfilteredViewCount(
            $sub, $tableOptions, $queryTimeout, $throwOnQueryError,
            $canUseSnapshotCache, $requestCountCacheKey, $countCacheKey
        );
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $canUseSnapshotCache
     * @param int $queryTimeout
     * @param string $countCacheKey Populated when a cache lookup is possible.
     * @return int|null
     */
    private function getCachedViewCount(
        string $sub,
        array $tableOptions,
        bool $canUseSnapshotCache,
        int $queryTimeout,
        string &$countCacheKey
    ): ?int {
        if (!$canUseSnapshotCache || $queryTimeout > 0) {
            return null;
        }

        $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
        $cachedCount = get_transient($countCacheKey);
        if ($cachedCount === false) {
            return null;
        }
        return intval(is_scalar($cachedCount) ? $cachedCount : 0);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $throwOnQueryError
     * @param bool $canUseSnapshotCache
     * @param string $requestCountCacheKey
     * @param string $countCacheKey
     * @return int
     */
    private function getFilteredViewCount(
        string $sub,
        array $tableOptions,
        bool $throwOnQueryError,
        bool $canUseSnapshotCache,
        string $requestCountCacheKey,
        string $countCacheKey
    ): int {
        try {
            $countValue = $this->requireViewBuildOrchestrator()->runRedirectsForViewCountStaged((string)$sub, $tableOptions);
            $this->cacheResolvedViewCount($sub, $tableOptions, $countValue, $canUseSnapshotCache, $requestCountCacheKey, $countCacheKey);
            return $countValue;
        } catch (ABJ_404_Solution_ViewBuildPendingException $pending) {
            if ($throwOnQueryError) {
                throw $pending;
            }
            $this->logger->debugMessage('[staged] getRedirectsForViewCount pending: ' . $pending->getMessage());
            $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
            return -1;
        } catch (Throwable $e) {
            return $this->handleFilteredCountFailure($sub, $tableOptions, $throwOnQueryError, $requestCountCacheKey, $e);
        }
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $throwOnQueryError
     * @param string $requestCountCacheKey
     * @param Throwable $e
     * @return int
     */
    private function handleFilteredCountFailure(
        string $sub,
        array $tableOptions,
        bool $throwOnQueryError,
        string $requestCountCacheKey,
        Throwable $e
    ): int {
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

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param int $queryTimeout
     * @param bool $throwOnQueryError
     * @param bool $canUseSnapshotCache
     * @param string $requestCountCacheKey
     * @param string $countCacheKey
     * @return int
     */
    private function getUnfilteredViewCount(
        string $sub,
        array $tableOptions,
        int $queryTimeout,
        bool $throwOnQueryError,
        bool $canUseSnapshotCache,
        string $requestCountCacheKey,
        string $countCacheKey
    ): int {
        $query = $this->queryBuilder->getOptimizedRedirectsForViewCountQuery($sub, $tableOptions);
        $this->cacheInvalidator->setSqlBigSelects();
        $queryOptions = $queryTimeout > 0 ? array('timeout' => $queryTimeout) : array();
        $results = $this->dbCore->queryAndGetResults($query, $queryOptions);
        $lastErrorRaw = $results['last_error'] ?? '';
        $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';

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
        $this->cacheResolvedViewCount($sub, $tableOptions, $countValue, $canUseSnapshotCache, $requestCountCacheKey, $countCacheKey);
        return $countValue;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param int $countValue
     * @param bool $canUseSnapshotCache
     * @param string $requestCountCacheKey
     * @param string $countCacheKey
     * @return void
     */
    private function cacheResolvedViewCount(
        string $sub,
        array $tableOptions,
        int $countValue,
        bool $canUseSnapshotCache,
        string $requestCountCacheKey,
        string $countCacheKey
    ): void {
        $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
        if ($canUseSnapshotCache && $countCacheKey === '') {
            $countCacheKey = $this->snapshotCache->getViewSnapshotCacheKey('abj404_view_count', $sub, $tableOptions);
        }
        if ($canUseSnapshotCache && $countCacheKey !== '') {
            set_transient($countCacheKey, $countValue, ABJ_404_Solution_ViewReadRuntimeState::VIEW_SNAPSHOT_CACHE_TTL_SECONDS);
        }
    }
}
