<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron / runtime hooks around the hits-table lifecycle: existence probe,
 * rebuild scheduling, and min/max log IDs used by drift checks.
 */
interface ABJ_404_Solution_LogHitsLifecycleInterface {

    /** @return bool */
    public function logsHitsTableExists();

    /** @return void */
    public function scheduleHitsTableRebuild(): void;

    /** @return int */
    public function getMaxLogId();

    /** @return int */
    public function getMinLogId();

    /** @return int */
    public function getStoredMaxLogId();

    /** @return int|null */
    public function getLogsHitsTableLastScheduledAt();
}
