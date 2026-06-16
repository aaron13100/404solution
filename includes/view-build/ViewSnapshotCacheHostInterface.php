<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract a snapshot-cache host exposes to the warmup machinery.
 *
 * The host owns the actual view-query execution; the snapshot-cache subsystem
 * (store, warmup orchestrator, warmup context) calls back through this contract
 * to materialize rows and counts on demand without depending on the concrete
 * ViewReadService. Implemented by {@see ABJ_404_Solution_ViewReadService};
 * consumed by {@see ABJ_404_Solution_ViewSnapshotCache},
 * {@see ABJ_404_Solution_ViewSnapshotWarmupOrchestrator}, and
 * {@see ABJ_404_Solution_ViewSnapshotWarmupContext}.
 */
interface ABJ_404_Solution_ViewSnapshotCacheHostInterface {
    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    public function viewTableSnapshotAvailable($sub, array $tableOptions): bool;

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return bool
     */
    public function viewRowsSnapshotAvailable($sub, array $tableOptions): bool;

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
}
