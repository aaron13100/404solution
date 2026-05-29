<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Narrow contract published by the Core layer so the Data layer (DatabaseUpgrade*,
 * PermalinkCache) can call back into business logic without statically depending
 * on the concrete ABJ_404_Solution_PluginLogic class. Lives in the Shared layer
 * for deptrac purposes so Data -> Shared remains the only direction crossed.
 */
interface ABJ_404_Solution_PluginLogicInterface {

    /**
     * Resolve the plugin's option array; also drives the deferred database
     * upgrade flow on the first read.
     *
     * @param bool $skip_db_check When true, bypass the DB version trigger to
     *                            avoid infinite-loop reentry during an upgrade.
     * @return array<string, mixed>|null
     */
    public function getOptions(bool $skip_db_check = false);

    /**
     * Re-register all WP cron hooks owned by the plugin. Used by upgrade flows
     * that complete schema work and need to ensure schedulers are wired.
     *
     * @return void
     */
    public function registerCrons(): void;
}
