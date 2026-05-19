<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for the staged view-build pipeline and mutation watermark system.
 *
 * Extracted from DataAccess in Phase 7 of the DataAccess refactor.
 * Absorbs 13 traits: ViewQueriesStaged, ViewBuildHelpers,
 * ViewBuildStageCallbacks, ViewBuildLockAndCron, ViewBuildPhpEnvProbe,
 * ViewBuildHostFailurePolicy, ViewBuildSessionEnvProbe,
 * ViewBuildStageRunner, AdminMutationGate, ViewBuildStartedWatermark,
 * ViewBuildAdaptive, ViewBuildForceRestart, MutationWatermarkSeam.
 *
 * @see docs/dataaccess-refactor-plan.md Phase 7.
 */
interface ABJ_404_Solution_ViewBuildOrchestratorInterface {

    // --- ViewQueriesStaged (orchestrator entry point, staged build loop) ---

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

    /** @return string */
    public function reconcileStagedTablesAtRunnerStartup(): string;

    // --- ViewBuildHelpers (progress options, SQL staging, state probes) ---

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

    /** @return array<string, mixed> */
    public function probeSqlModeForBuild(): array;

    /** @return array<string, mixed> */
    public function detectAndAdjustSqlMode(): array;

    /**
     * @param string $url
     * @param int $maxLength
     * @return string
     */
    public function sanitizeUrlBeforeInsert(string $url, int $maxLength = 0): string;

    // --- ViewBuildLockAndCron (cross-request lock, cron scheduling) ---

    /** @return bool */
    public function verifyBuildLockSerializesWriter(): bool;

    /**
     * @param int $delaySeconds
     * @return void
     */
    public function scheduleViewDoneRebuild(int $delaySeconds = 1): void;

    // --- ViewBuildPhpEnvProbe (PHP runtime constraints) ---

    /** @return array<string, mixed> */
    public function probePhpEnvironmentForBuild(): array;

    /** @return bool */
    public function probeSetTimeLimitAvailability(): bool;

    /** @return int */
    public function probeMemoryLimitForS9(): int;

    /** @return array<string, mixed> */
    public function probeFilesystemEnvironmentForBuild(): array;

    // --- ViewBuildHostFailurePolicy (permanent error handling) ---

    /** @return void */
    public function clearStagedBuildDegradedState(): void;

    /** @return bool */
    public function reconcilePostStageElevenState(): bool;

    // --- ViewBuildSessionEnvProbe (MySQL session health) ---

    /** @return array<string, mixed> */
    public function probeSessionVariablesAtS1Entry(): array;

    // --- AdminMutationGate (watermark-based visibility gate) ---

    /** @return void */
    public function markViewDoneInvalidatedByAdminMutation(): void;

    // --- ViewBuildForceRestart ---

    /**
     * @param int $lockTimeoutSeconds
     * @return bool
     */
    public function forceRestartViewBuild(int $lockTimeoutSeconds = 10): bool;

    // --- MutationWatermarkSeam ---

    /** @return int */
    public function bumpMutationWatermark(): int;

    // --- Bridge methods for ViewReadService ---

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
