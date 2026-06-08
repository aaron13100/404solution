<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema and capacity introspection that the view tables expose to the admin
 * UI: table engines (MyISAM vs InnoDB), supported-feature flags, total
 * captured / log counts, post-type vocabulary, disk usage, and the failure
 * diagnostic payload built when a view query errors out.
 */
interface ABJ_404_Solution_ViewMetadataInterface {

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
}
