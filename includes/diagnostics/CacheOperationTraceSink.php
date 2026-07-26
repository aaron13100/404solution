<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checked contract for diagnostic scopes that decorate WordPress object cache.
 */
interface ABJ_404_Solution_CacheOperationTraceSink {
    /**
     * @template T
     * @param mixed $key
     * @param mixed $group
     * @param callable():T $work
     * @return T
     */
    public function traceCache(string $operation, $key, $group, callable $work);
}
