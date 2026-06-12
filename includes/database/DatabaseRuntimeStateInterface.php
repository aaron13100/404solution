<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cross-request runtime state the database layer maintains: transient-backed
 * flags, plugin admin notices about DB issues, and the write-block status
 * that gates non-essential writes during disk-full / read-only cooldowns.
 */
interface ABJ_404_Solution_DatabaseRuntimeStateInterface {

    /**
     * Set/get runtime flags (transients with option fallback).
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttlSeconds
     * @return void
     */
    public function setRuntimeFlag(string $key, $value, int $ttlSeconds): void;

    /**
     * @param string $key
     * @return mixed
     */
    public function getRuntimeFlag(string $key);

    /**
     * Surface a plugin-specific admin notice about a DB issue.
     *
     * @param string $type
     * @param string $message
     * @param string $guidance
     * @param string $errorString
     * @return void
     */
    public function setPluginDbNotice(string $type, string $message, string $guidance, string $errorString = ''): void;

    /**
     * Clear the plugin DB notice only when its current type matches.
     *
     * @param string $type
     * @return void
     */
    public function clearPluginDbNoticeIfType(string $type): void;

    /**
     * @return bool True when a write-block cooldown is active (disk full or read-only).
     */
    public function isWriteBlockActive(): bool;

    /**
     * @return bool True when non-essential DB writes should be skipped.
     */
    public function shouldSkipNonEssentialDbWrites(): bool;
}
