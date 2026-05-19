<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for the admin list view read path, snapshot caching, and status counts.
 *
 * Extracted from DataAccess in Phase 6 of the DataAccess refactor.
 * Absorbs 5 traits: ViewQueries, ViewSnapshotCache, ViewMetadata,
 * ViewQueriesHitsLifecycle, ViewQueriesStagedRead.
 *
 * @see docs/dataaccess-refactor-plan.md Phase 6.
 */
interface ABJ_404_Solution_ViewReadServiceInterface {

    // --- ViewQueries (status counts, list queries, cache invalidation) ---

    /**
     * @param bool $bypassCache
     * @return array<string, int>
     */
    public function getRedirectStatusCounts($bypassCache = false): array;

    /**
     * @param bool $bypassCache
     * @return array<string, int>
     */
    public function getCapturedStatusCounts($bypassCache = false): array;

    /** @return int */
    public function getHighImpactCapturedCount(): int;

    /** @return string */
    public function buildHighImpactCapturedCountQuery(): string;

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runWithDeferredInvalidation(callable $work);

    /** @return void */
    public function invalidateStatusCountsCache(): void;

    /** @return void */
    public function invalidateViewSnapshotCache(): void;

    /** @return void */
    public function clearRegexRedirectsCache(): void;

    /**
     * @param int $logID
     * @return int
     */
    public function getLogsCount($logID);

    /** @return array<int, array<string, mixed>> */
    public function getRedirectsAll();

    /** @param string $tempFile @return void */
    public function doRedirectsExport(string $tempFile): void;

    /** @return array<int, array<string, mixed>> */
    public function getRedirectsWithLogs();

    /** @return array<int, array<string, mixed>> */
    public function getRedirectsWithRegEx();

    /** @return array<int, array<string, mixed>> */
    public function getManualRedirectsWithRegexMetachars();

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int|string, mixed>
     */
    public function getRedirectsForView($sub, $tableOptions);

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    public function viewRowsSnapshotAvailable($sub, array $tableOptions): bool;

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    public function viewTableSnapshotAvailable($sub, array $tableOptions): bool;

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function getRedirectsForViewCount(string $sub, array $tableOptions): int;

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $queryAllRowsAtOnce
     * @param int $limitStart
     * @param int $limitEnd
     * @param bool $selectCountOnly
     * @return string
     */
    public function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce,
        $limitStart, $limitEnd, $selectCountOnly);

    /**
     * @param array<int, string> $postIDs
     * @return array<int, mixed>
     */
    public function getExtraDataToPermalinkSuggestions(array $postIDs): array;

    /**
     * @param string $query
     * @param array<string, mixed> $data
     * @return string
     */
    public function prepare_query_wp($query, $data);

    /**
     * @param string $query
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function prepare_query($query, $data);

    // --- ViewSnapshotCache (warmup) ---

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    public function warmViewTableSnapshotStage(string $sub, array $tableOptions): array;

    // --- ViewMetadata (table diagnostics, inserts, counts) ---

    /** @return array<string, mixed> */
    public function getTableEngines();

    /** @return bool */
    public function isMyISAMSupported(): bool;

    /**
     * @param string $tableName
     * @param array<string, mixed> $dataToInsert
     * @return array<string, mixed>
     */
    public function insertAndGetResults($tableName, $dataToInsert);

    /** @return int */
    public function getCapturedCount();

    /** @return array<int, string> */
    public function getAllPostTypes();

    /** @return int */
    public function getLogDiskUsage();

    /**
     * @param array<int, int> $types
     * @param int $trashed
     * @return int
     */
    public function getRecordCount($types = array(), $trashed = 0);

    /**
     * @param string $sub
     * @param string $failedQuery
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $queryResult
     * @return array<string, mixed>
     */
    public function captureViewQueryFailureDiagnostics(string $sub, string $failedQuery, array $tableOptions, array $queryResult): array;

    // --- ViewQueriesHitsLifecycle ---

    /** @return void */
    public function maybeUpdateRedirectsForViewHitsTable(): void;
}
