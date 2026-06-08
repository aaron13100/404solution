<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Narrow surface consumed by ViewReadService to coordinate with the build
 * pipeline: cache invalidation on the read side, the option set used when
 * reading staged output, and progress option reads.
 */
interface ABJ_404_Solution_ViewBuildReadBridgeInterface {

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
