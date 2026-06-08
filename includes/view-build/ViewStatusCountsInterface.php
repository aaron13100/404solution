<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Status-counts (captured / redirect / high-impact) reads and the
 * invalidation hooks that keep them and their caches coherent with admin
 * writes.
 */
interface ABJ_404_Solution_ViewStatusCountsInterface {

    /**
     * @param bool $bypassCache
     * @return array<string, int>
     */
    public function getRedirectStatusCounts($bypassCache = false): array;

    /**
     * @param bool $bypassCache
     * @return array<string, int>
     */
    public function getCapturedStatusCounts($bypassCache = false): array;

    /** @return int */
    public function getHighImpactCapturedCount(): int;

    /** @return string */
    public function buildHighImpactCapturedCountQuery(): string;

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runWithDeferredInvalidation(callable $work);

    /** @return void */
    public function invalidateStatusCountsCache(): void;

    /** @return void */
    public function invalidateViewSnapshotCache(): void;

    /** @return void */
    public function clearRegexRedirectsCache(): void;
}
