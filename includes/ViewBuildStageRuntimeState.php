<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Process-local runtime state for one staged view-build tick.
 *
 * This state is intentionally not persisted. Durable checkpoint writes live in
 * ViewBuildStageMarkers; this object only carries the currently open stage and
 * the latest batch detail between the runner, log presenter, and shutdown
 * diagnostics during a single PHP request.
 */
class ABJ_404_Solution_ViewBuildStageRuntimeState {

    /** @var bool */
    private $stageOpenForShutdown = false;
    /** @var int */
    private $shutdownStageNumber = 0;
    /** @var string */
    private $shutdownStageKey = '';
    /** @var string */
    private $lastBatchProgressDetail = '';

    /** @return void */
    public function resetBatchProgressDetail(): void {
        $this->lastBatchProgressDetail = '';
    }

    /**
     * @param string $detail
     * @return void
     */
    public function captureBatchProgressDetail(string $detail): void {
        $this->lastBatchProgressDetail = $detail;
    }

    /** @return string */
    public function lastBatchProgressDetail(): string {
        return $this->lastBatchProgressDetail;
    }

    /**
     * @param int $stageNumber
     * @param string $stageKey
     * @return void
     */
    public function openStageForShutdown(int $stageNumber, string $stageKey): void {
        $this->stageOpenForShutdown = true;
        $this->shutdownStageNumber = $stageNumber;
        $this->shutdownStageKey = $stageKey;
    }

    /** @return void */
    public function clearOpenStageForShutdown(): void {
        $this->stageOpenForShutdown = false;
        $this->shutdownStageNumber = 0;
        $this->shutdownStageKey = '';
    }

    /** @return bool */
    public function stageOpenForShutdown(): bool {
        return $this->stageOpenForShutdown;
    }

    /** @return int */
    public function shutdownStageNumber(): int {
        return $this->shutdownStageNumber;
    }

    /** @return string */
    public function shutdownStageKey(): string {
        return $this->shutdownStageKey;
    }
}
