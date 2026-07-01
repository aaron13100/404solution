<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public surface of the admin view-read service.
 *
 * This was previously expressed as five segregated sub-interfaces
 * (status-counts, list-read, snapshot-read, metadata, hits-lifecycle)
 * aggregated by this composite. The segregation was never realized: every
 * typed caller (DataAccess delegate, View, admin tables, REST/AJAX handlers,
 * the extraction tests) depended on this composite, and no code ever depended
 * on a narrow sub-interface. The sub-interfaces were therefore unrealized
 * scaffolding and have been collapsed into this single interface. The method
 * set is unchanged, so existing typed callers continue to compile.
 *
 * Methods are grouped by their former sub-interface for readability:
 *   - status counts + invalidation hooks
 *   - redirect-list reads (admin tables / export)
 *   - schema / capacity introspection + failure diagnostics
 *   - hits-table lifecycle hook
 */
interface ABJ_404_Solution_ViewReadServiceInterface {
    const SORT_READINESS_READY = 'ready';
    const SORT_READINESS_BACKFILL_PENDING = 'backfill-pending';
    const SORT_READINESS_SCHEMA_UNAVAILABLE = 'schema-unavailable';

    /* ---- status counts + invalidation hooks ---- */

    /**
     * @param bool $bypassCache
     * @return array<string, int>
     */
    public function getRedirectStatusCounts($bypassCache = false): array;

    /**
     * @return array<string, int>
     */
    public function getRedirectHitCountHistogram(): array;

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

    /* ---- redirect-list reads (admin tables / export) ---- */

    /**
     * @param int $logID
     * @return int
     */
    public function getLogsCount($logID);

    /** @param string $tempFile @return void */
    public function doRedirectsExport(string $tempFile): void;

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
     * Whether the most recent getRedirectsForView() result is NOT a trustworthy
     * "genuinely empty" listing (pending build, errored read, or an empty
     * snapshot contradicting the live source count).
     *
     * @return bool
     */
    public function lastRedirectsViewReadWasIncomplete(): bool;

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function getRedirectsForViewCount(string $sub, array $tableOptions): int;

    /**
     * Whether ordering the admin list by $orderby can be served index-ordered now
     * (a narrow sort-key-backed sort whose column exists and whose backfill latch
     * is set; non-key-backed sorts are always ready). The admin header uses this
     * to disable the URL / Destination sort links on the captured tab during the
     * post-upgrade backfill window.
     *
     * @param string $orderby UI orderby alias (url, final_dest, logshits, ...).
     * @return bool
     */
    public function isSortReadyForOrderby(string $orderby): bool;

    /**
     * Readiness status for ordering the admin list by $orderby. Sort-key-backed
     * URL / Destination sorts can be ready, temporarily pending a backfill, or
     * structurally unavailable because the backing column/index schema is absent.
     * Non-key-backed sorts are always ready.
     *
     * @param string $orderby UI orderby alias (url, final_dest, logshits, ...).
     * @return string One of the SORT_READINESS_* constants.
     */
    public function sortReadinessStatusForOrderby(string $orderby): string;

    /**
     * Backfill progress (0..100) for $orderby's narrow sort key, for the admin
     * "building the index" tooltip. Cheap: cursor wp_options read over an O(1)
     * MAX(id) probe, never a COUNT over the captured rows.
     *
     * @param string $orderby UI orderby alias.
     * @return int
     */
    public function sortBackfillPercentForOrderby(string $orderby): int;

    /**
     * @param array<int, string> $postIDs
     * @return array<int, mixed>
     */
    public function getExtraDataToPermalinkSuggestions(array $postIDs): array;

    /* ---- schema / capacity introspection + failure diagnostics ---- */

    /** @return array<string, mixed> */
    public function getTableEngines();

    /** @return bool */
    public function isMyISAMSupported(): bool;

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

    /* ---- hits-table lifecycle hook ---- */

    /** @return void */
    public function maybeUpdateRedirectsForViewHitsTable(): void;
}
