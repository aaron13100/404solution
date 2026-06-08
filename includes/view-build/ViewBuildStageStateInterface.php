<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Low-level state operations used by the staged-build internals (and by tests
 * that simulate stage boundaries): prefix capture, option-write coherence,
 * URL sanitization, lock verification, cron scheduling, and post-stage-11
 * reconciliation.
 */
interface ABJ_404_Solution_ViewBuildStageStateInterface {

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
}
