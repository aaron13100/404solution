<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Probes for the runtime environment the staged view-build pipeline depends
 * on: PHP runtime limits, filesystem properties, and MySQL session state.
 * Used at S1 entry and by ad-hoc diagnostic surfaces.
 */
interface ABJ_404_Solution_ViewBuildEnvironmentProbeInterface {

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
}
