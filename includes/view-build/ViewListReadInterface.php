<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirect-list reads consumed by the admin tables: pagination, export,
 * regex-only and manual-with-metachars subsets, and the per-sub list-view
 * read used by the captured / redirected / trashed tabs.
 */
interface ABJ_404_Solution_ViewListReadInterface {

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
     * @return int
     */
    public function getRedirectsForViewCount(string $sub, array $tableOptions): int;

    /**
     * @param array<int, string> $postIDs
     * @return array<int, mixed>
     */
    public function getExtraDataToPermalinkSuggestions(array $postIDs): array;
}
