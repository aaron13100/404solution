<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public contract for log insertion, querying, hits rebuild, GDPR, and lookup operations.
 *
 * Extracted from DataAccess in Phase 3 of the DataAccess refactor. Callers that
 * need log insertion, log querying, GDPR anonymization, hits-table lifecycle,
 * or lookup-table operations program against this interface.
 */
interface ABJ_404_Solution_LogsRepositoryInterface {

    // =========================================================================
    // Log data population and querying (from DataAccessTrait_Logs)
    // =========================================================================

    /**
     * Populate logshits / logsid / last_used on each row from the pre-aggregated
     * wp_abj404_logs_hits rollup.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function populateLogsData($rows);

    /**
     * @return array<int, string>
     */
    public function getDistinctLoggedUrls(): array;

    /**
     * @param string $specificURL
     * @return array<int, array<string, mixed>>
     */
    public function getLogsIDandURL($specificURL = '');

    /**
     * @param string $specificURL
     * @param string|int $limitResults
     * @return array<int, array<string, mixed>>
     */
    public function getLogsIDandURLLike($specificURL, $limitResults);

    /**
     * @param array<string, mixed> $tableOptions orderby, paged, perpage, etc.
     * @return array<int, array<string, mixed>>
     */
    public function getLogRecords($tableOptions);

    /**
     * @param int $days Number of days (default 30, clamped to 1-90)
     * @return array<int, array<string, mixed>>
     */
    public function getDailyActivityTrend(int $days = 30): array;

    // =========================================================================
    // GDPR / privacy (from DataAccessTrait_Logs)
    // =========================================================================

    /**
     * @param string $lkupValue
     * @param int $page
     * @param int $perPage
     * @return int[]
     */
    public function getLogsv2IdsForLookupValue($lkupValue, $page = 1, $perPage = 100);

    /**
     * @param string $lkupValue
     * @param int $page
     * @param int $perPage
     * @return array<int, array<string, mixed>>
     */
    public function getLogsv2RowsForLookupValue($lkupValue, $page = 1, $perPage = 50);

    /**
     * @param int[] $ids
     * @return bool
     */
    public function anonymizeLogsv2RowsByIds($ids);

    // =========================================================================
    // Log insertion and queue (from DataAccessTrait_Logs)
    // =========================================================================

    /**
     * @param string $requested_url
     * @param string $action
     * @param string $matchReason
     * @param string|null $requestedURLDetail
     * @param list<array{step: string, outcome: string, detail: string}>|null $pipelineTrace
     * @return void
     */
    public function logRedirectHit(string $requested_url, string $action, string $matchReason, ?string $requestedURLDetail = null, ?array $pipelineTrace = null): void;

    /**
     * @param array<string, mixed> $entry
     * @return void
     */
    public function queueLogEntry(array $entry): void;

    /** @return void */
    public function flushLogQueue(): void;

    // =========================================================================
    // Lookup table (from DataAccessTrait_Logs)
    // =========================================================================

    /**
     * @param string $valueToInsert
     * @return int
     */
    public function insertLookupValueAndGetID($valueToInsert);

    /**
     * @param string $userName
     * @return int
     */
    public function getLookupIDForUser($userName);

    /** @return void */
    public function correctDuplicateLookupValues(): void;

    // =========================================================================
    // Pipeline trace (from DataAccessTrait_Logs, static)
    // =========================================================================

    /**
     * @param string|null $raw
     * @return array<int, array{step: string, outcome: string, detail: string}>|null
     */
    public static function decompressPipelineTrace(?string $raw): ?array;

    // =========================================================================
    // Hits table rebuild (from DataAccessTrait_LogsHitsRebuild)
    // =========================================================================

    /** @return void */
    public function recordLogsHitsRollupStalenessSignal(): void;

    /** @return bool */
    public function hitsTableNeedsRebuild();

    /**
     * @return int|null Unix timestamp of last update, or null if table doesn't exist
     */
    public function getLogsHitsTableLastUpdated();

    /** @return string */
    public function getLogsHitsTableLastUpdatedHuman();

    /** @return bool */
    public function createRedirectsForViewHitsTable(): bool;

    // =========================================================================
    // Hits table lifecycle (from DataAccessTrait_ViewQueriesHitsLifecycle)
    // =========================================================================

    /** @return bool */
    public function logsHitsTableExists();

    /** @return void */
    public function scheduleHitsTableRebuild(): void;

    /** @return int */
    public function getMaxLogId();

    /** @return int */
    public function getMinLogId();

    /** @return int */
    public function getStoredMaxLogId();

    /** @return int|null */
    public function getLogsHitsTableLastCheckedAt();

    /** @return int|null */
    public function getLogsHitsTableLastScheduledAt();

    /** @return string */
    public function getLogsHitsTableLastDecision(): string;
}
