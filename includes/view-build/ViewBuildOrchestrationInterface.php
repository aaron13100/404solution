<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public surface of the staged view_done rebuild pipeline. Callers schedule
 * rebuilds, advance the pipeline, ask whether the view is serveable, and
 * read progress; they do not touch lock or probe internals here.
 */
interface ABJ_404_Solution_ViewBuildOrchestrationInterface {

    /** @return void */
    public function claimForegroundViewBuildLease(): void;

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function runRedirectsForViewStaged(string $sub, array $tableOptions): array;

    /** @return bool */
    public function viewDoneIsServeable(): bool;

    /** @return int Unix timestamp, or 0. */
    public function getViewDoneBuiltAtTimestamp(): int;

    /** @return void */
    public function markViewDoneBuildCompleted(): void;

    /** @return array<string, mixed> */
    public function getViewBuildProgress(): array;

    /**
     * @param bool $forceRebuild
     * @return array<string, mixed>
     */
    public function advanceViewBuildOnce(bool $forceRebuild = false): array;

    /** @return array{ran:bool, reason:string, progress:array<string,mixed>} */
    public function runPageLoadFallbackAdvance(): array;

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return int
     */
    public function runRedirectsForViewCountStaged(string $sub, array $tableOptions): int;

    /** @return void */
    public function rebuildViewDoneInBackground(): void;

    /**
     * Write-through cache reconciliation. Closes the visibility gap
     * between source-table mutation and S11 RENAME swap.
     *
     * @return void
     */
    public function syncViewDoneWithSource(): void;

    /** @return string */
    public function reconcileStagedTablesAtRunnerStartup(): string;

    /**
     * Admin mutation entry point: invalidate the cached view snapshot
     * and schedule a rebuild. Replaces the previous watermark/gate system.
     *
     * @return void
     */
    public function invalidateViewDoneAndScheduleRebuild(): void;

    /**
     * @param int $lockTimeoutSeconds
     * @return bool
     */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool;
}
