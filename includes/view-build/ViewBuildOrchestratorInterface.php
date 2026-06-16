<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public surface of the view_done rebuild orchestrator.
 *
 * This was previously expressed as four segregated sub-interfaces
 * (orchestration, environment-probe, stage-state, read-bridge) aggregated by
 * this composite. The segregation was never realized: every typed caller
 * (DataAccess delegate, View, the AJAX/REST handlers, the extraction tests)
 * depended on this composite, and no code ever depended on a narrow
 * sub-interface. The sub-interfaces were therefore unrealized scaffolding and
 * have been collapsed into this single interface. The method set is unchanged,
 * so existing typed callers continue to compile.
 *
 * Methods are grouped by their former sub-interface for readability:
 *   - rebuild lifecycle / read serving
 *   - runtime environment probes (diagnostic seams)
 *   - low-level stage-state operations
 *   - read-side bridge consumed by ViewReadService
 */
interface ABJ_404_Solution_ViewBuildOrchestratorInterface {

    /* ---- rebuild lifecycle / read serving ---- */

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

    /* ---- runtime environment probes (diagnostic seams) ---- */

    /** @return array<string, mixed> */
    public function probeSqlModeForBuild(): array;

    /** @return array<string, mixed> */
    public function detectAndAdjustSqlMode(): array;

    /** @return array<string, mixed> */
    public function probePhpEnvironmentForBuild(): array;

    /** @return bool */
    public function probeSetTimeLimitAvailability(): bool;

    /** @return int */
    public function probeMemoryLimitForS9(): int;

    /** @return array<string, mixed> */
    public function probeFilesystemEnvironmentForBuild(): array;

    /** @return array<string, mixed> */
    public function probeSessionVariablesAtS1Entry(): array;

    /* ---- low-level stage-state operations ---- */

    /**
     * @param string $optionName
     * @param mixed $expected
     * @return bool
     */
    public function verifyOptionWriteCoherent(string $optionName, $expected): bool;

    /** @return void */
    public function capturePrefixAtBuildStart(): void;

    /** @return bool */
    public function verifyPrefixUnchangedSinceStageOne(): bool;

    /** @return void */
    public function clearPrefixAtStageOne(): void;

    /**
     * @param string $url
     * @param int $maxLength
     * @return string
     */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string;

    /** @return bool */
    public function verifyBuildLockSerializesWriter(): bool;

    /**
     * @param int $delaySeconds
     * @return void
     */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void;

    /** @return void */
    public function clearStagedBuildDegradedState(): void;

    /** @return bool */
    public function reconcilePostStageElevenState(): bool;

    /* ---- read-side bridge consumed by ViewReadService ---- */

    /** @return void */
    public function invalidateViewDoneServeableCacheBridge(): void;

    /** @return array<string, mixed> */
    public function getStagedQueryOptionsForRead(): array;

    /**
     * @param string $shortName
     * @param int $default
     * @return int
     */
    public function readBuildProgressOption(string $shortName, int $default = 0): int;
}
