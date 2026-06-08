<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-side adapter to the view_table snapshot cache: availability checks
 * plus the public warmup entry point routed into the snapshot orchestrator.
 */
interface ABJ_404_Solution_ViewSnapshotReadInterface {

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
     * @return array<string, mixed>
     */
    public function warmViewTableSnapshotStage(string $sub, array $tableOptions): array;
}
