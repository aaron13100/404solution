<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron / runtime hooks around the hits-table lifecycle: existence probe,
 * rebuild scheduling, min/max log IDs, and the decision metadata cron uses
 * to schedule the next pass.
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
    public function getLogsHitsTableLastCheckedAt();

    /** @return int|null */
    public function getLogsHitsTableLastScheduledAt();

    /** @return string */
    public function getLogsHitsTableLastDecision(): string;
}
