<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates admin redirects/captured table row and count reads.
 *
 * Serves the live single-table read off wp_abj404_redirects (Denorm Step 3b):
 * the ordered/filtered page is fetched via ViewQueryBuilder and the four derived
 * columns are resolved live per visible row by RedirectsViewLiveResolver. Owns
 * per-request count memoization, read-outcome classification, and failure
 * diagnostics for the public getRedirectsForView() / getRedirectsForViewCount()
 * facade methods. No staged view_done materialization, no snapshot result cache.
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

    /** @var ABJ_404_Solution_RedirectsViewLiveResolver */
    private $liveResolver;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var array<string, int> */
    private $redirectsForViewCountRequestCache = array();

    /** @var ABJ_404_Solution_ViewReadOutcome Classifier for the last row-read outcome (i455). */
    private $lastReadOutcome;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_ViewQueryBuilder $queryBuilder
     * @param ABJ_404_Solution_ViewDiagnostics $diagnostics
     * @param ABJ_404_Solution_ViewCacheInvalidator $cacheInvalidator
     * @param ABJ_404_Solution_RedirectsViewLiveResolver $liveResolver
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_ViewQueryBuilder $queryBuilder,
        ABJ_404_Solution_ViewDiagnostics $diagnostics,
        ABJ_404_Solution_ViewCacheInvalidator $cacheInvalidator,
        ABJ_404_Solution_RedirectsViewLiveResolver $liveResolver,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->queryBuilder = $queryBuilder;
        $this->diagnostics = $diagnostics;
        $this->cacheInvalidator = $cacheInvalidator;
        $this->liveResolver = $liveResolver;
        $this->logger = $logger;
        $this->lastReadOutcome = new ABJ_404_Solution_ViewReadOutcome();
    }

    /**
     * Single-table redirects read for one page (Denorm Step 3b): fetch the
     * ordered/filtered page off wp_abj404_redirects, then resolve the visible
     * rows' derived/display values live and write the four denorm columns back.
     * This is the live read path that replaced the staged view_done read; it is
     * the single source of truth for the live single-table read (ViewReadService
     * delegates its readRedirectsSingleTable() here).
     *
     * The derived columns are still READ (and live-resolved for display) when
     * present, but the live write-back can be suppressed per-request via the
     * `_abj404_suppress_denorm_writeback` tableOptions flag: a change-detection
     * probe (the detect-only signature poll) resolves values to compute its
     * signature but must not mutate rows. The full-render path leaves the flag
     * unset, so it still persists fresh values (the freshness mechanism).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readRedirectsSingleTable(string $sub, array $tableOptions): array {
        $derivedPresent = $this->liveResolver->derivedColumnsPresent();
        // Tell the query builder whether a narrow sort key is SAFE to ORDER BY:
        // the column must exist AND its one-time legacy-row backfill must have
        // converged (the drain set the latch). Right after the column-add ALTER
        // but before the drain runs, every legacy row carries a NULL sort key, so
        // ordering by it would bucket every row as equal and fall to the id
        // tie-break -- i.e. order the table by id, not by URL/destination. Until
        // the latch is set the query builder falls back to the wide source column
        // (raw url / CASE-on-dest_for_view): correct order, filesort, and on the
        // Page Redirects tab the filesort is bounded to the status-filtered
        // minority so the default load stays fast. Same _abj404_* option
        // convention as the timeout / suppress-writeback flags.
        $tableOptions['_abj404_dest_sort_key_present'] = $this->sortKeyReady('dest_sort_key',
            $this->liveResolver->destSortKeyColumnPresent());
        $tableOptions['_abj404_url_sort_key_present'] = $this->sortKeyReady('url_sort_key',
            $this->liveResolver->urlSortKeyColumnPresent());
        $rows = $this->queryBuilder->readRedirectsSingleTable($sub, $tableOptions, $derivedPresent);
        $persist = $derivedPresent && empty($tableOptions['_abj404_suppress_denorm_writeback']);
        return $this->liveResolver->resolveAndPersistVisibleRows($rows, $persist);
    }

    /**
     * Whether a narrow sort-key column is safe to ORDER BY: present on the table
     * AND its backfill latch set (the legacy-row drain has converged so no row
     * still carries a NULL key). A column with no latch contract is never ready.
     *
     * @param string $targetColumn dest_sort_key or url_sort_key.
     * @param bool   $columnPresent Whether the column exists on the table.
     * @return bool
     */
    private function sortKeyReady(string $targetColumn, bool $columnPresent): bool {
        if (!$columnPresent) {
            return false;
        }
        $latchOption = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillLatchOption($targetColumn);
        return $latchOption !== '' && get_option($latchOption) === '1';
    }

    /**
     * Single-table filtered count against wp_abj404_redirects (Denorm Step 3b).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function countRedirectsSingleTable(string $sub, array $tableOptions): int {
        return $this->queryBuilder->countRedirectsSingleTable($sub, $tableOptions,
            $this->liveResolver->derivedColumnsPresent());
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int|string, mixed>
     */
    public function getRedirectsForView($sub, $tableOptions) {
        $tableOptionsArray = is_array($tableOptions) ? $tableOptions : array();
        $throwOnQueryError = !empty($tableOptionsArray['_abj404_throw_on_view_query_error']);

        try {
            $rows = $this->readRedirectsSingleTable((string)$sub, $tableOptionsArray);
        } catch (Throwable $e) {
            $this->lastReadOutcome->markErrored();
            if ($throwOnQueryError) {
                $failureMarker = '/* single-table: ' . $e->getMessage() . ' */';
                $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics(
                    (string)$sub,
                    $failureMarker,
                    $tableOptionsArray,
                    array('last_error' => $e->getMessage(), 'timed_out' => false)
                );
                $diagnostics['failed_query_label'] = 'getRedirectsForView';
                $diagnostics['staged_error'] = $e->getMessage();
                $message = 'getRedirectsForView failed; last_error=' . $e->getMessage()
                    . '; timed_out=false; sql_source=' . $failureMarker;
                throw new ABJ_404_Solution_ViewQueryFailureException($message, $diagnostics);
            }
            $this->logger->errorMessage('[single-table] getRedirectsForView failed: ' . $e->getMessage(),
                $e instanceof \Exception ? $e : null);
            return array();
        }

        $this->logger->debugMessage(sprintf(
            '[single-table] getRedirectsForView returned %d rows for page %s',
            count($rows),
            (string)$sub
        ));
        $this->finalizeReadStatusForRows($rows, (string)$sub, $tableOptionsArray);

        return $rows;
    }

    /**
     * Record how to read the last getRedirectsForView() result (i455). The
     * live source count is probed lazily, only for empty rows.
     *
     * @param array<int|string, mixed> $rows
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    private function finalizeReadStatusForRows(array $rows, string $sub, array $tableOptions): void {
        $this->lastReadOutcome->classifyRows($rows, $tableOptions, function () use ($sub, $tableOptions): int {
            try {
                return $this->getRedirectsForViewCount($sub, $tableOptions);
            } catch (Throwable $e) {
                $this->logger->debugMessage('[single-table] live-count probe for empty read failed: ' . $e->getMessage());
                return -1;
            }
        });
    }

    /**
     * Whether the last getRedirectsForView() result is NOT a trustworthy empty
     * listing (errored/stale-empty). See ABJ_404_Solution_ViewReadOutcome.
     *
     * @return bool
     */
    public function lastRedirectsViewReadWasIncomplete(): bool {
        return $this->lastReadOutcome->wasIncomplete();
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
        $requestCountCacheKey = (string)$sub . '|' . md5(serialize($tableOptions));

        if (array_key_exists($requestCountCacheKey, $this->redirectsForViewCountRequestCache)) {
            return intval($this->redirectsForViewCountRequestCache[$requestCountCacheKey]);
        }

        $rawFilterText = is_string($tableOptions['filterText'] ?? null) ? $tableOptions['filterText'] : '';
        if ($rawFilterText !== '') {
            return $this->getFilteredViewCount($sub, $tableOptions, $throwOnQueryError, $requestCountCacheKey);
        }

        return $this->getUnfilteredViewCount($sub, $tableOptions, $queryTimeout, $throwOnQueryError, $requestCountCacheKey);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $throwOnQueryError
     * @param string $requestCountCacheKey
     * @return int
     */
    private function getFilteredViewCount(
        string $sub,
        array $tableOptions,
        bool $throwOnQueryError,
        string $requestCountCacheKey
    ): int {
        try {
            $countValue = $this->countRedirectsSingleTable((string)$sub, $tableOptions);
            $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
            return $countValue;
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
            $failureMarker = '/* single-table-count: ' . $e->getMessage() . ' */';
            $diagnostics = $this->diagnostics->captureViewQueryFailureDiagnostics(
                (string)$sub,
                $failureMarker,
                $tableOptions,
                array('last_error' => $e->getMessage(), 'timed_out' => false)
            );
            $diagnostics['failed_query_label'] = 'getRedirectsForViewCount';
            $diagnostics['staged_error'] = $e->getMessage();
            throw new ABJ_404_Solution_ViewQueryFailureException($e->getMessage(), $diagnostics);
        }
        $this->logger->errorMessage('[single-table] getRedirectsForViewCount failed: ' . $e->getMessage(),
            $e instanceof \Exception ? $e : null);
        $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = -1;
        return -1;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param int $queryTimeout
     * @param bool $throwOnQueryError
     * @param string $requestCountCacheKey
     * @return int
     */
    private function getUnfilteredViewCount(
        string $sub,
        array $tableOptions,
        int $queryTimeout,
        bool $throwOnQueryError,
        string $requestCountCacheKey
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
        $this->redirectsForViewCountRequestCache[$requestCountCacheKey] = $countValue;
        return $countValue;
    }
}
