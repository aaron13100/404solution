<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hits-table rebuild policy and create surface: records the staleness
 * signal, answers "needs rebuild?" and "last updated when?", and creates
 * the hits rollup table on demand.
 */
interface ABJ_404_Solution_LogHitsRebuildInterface {

    /** @return void */
    public function recordLogsHitsRollupStalenessSignal(): void;

    /** @return bool */
    public function hitsTableNeedsRebuild();

    /**
     * @return int|null Unix timestamp of last update, or null if table doesn't exist
     */
    public function getLogsHitsTableLastUpdated();

    /** @return bool */
    public function createRedirectsForViewHitsTable(): bool;
}
