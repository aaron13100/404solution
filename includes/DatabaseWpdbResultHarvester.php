<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Copies wpdb execution state into the plugin's standard query result array.
 *
 * The query pipeline and recovery paths all need the same fields after each
 * initial query or retry. Centralizing the adapter keeps the result contract
 * consistent across SELECT, mutation, timeout fallback, collation recovery,
 * and table-repair retry paths.
 */
class ABJ_404_Solution_DatabaseWpdbResultHarvester {

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    public function harvestWpdbResult(array &$result): void {
        global $wpdb;
        $result['last_error'] = (string)($wpdb->last_error ?? '');
        $result['last_result'] = $wpdb->last_result ?? array();
        $result['rows_affected'] = $wpdb->rows_affected ?? 0;
        $result['insert_id'] = $wpdb->insert_id ?? 0;
    }
}
