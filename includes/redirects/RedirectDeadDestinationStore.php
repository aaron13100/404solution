<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores redirect IDs whose destinations recently appeared as 404s.
 */
class ABJ_404_Solution_RedirectDeadDestinationStore {

    public const CACHE_KEY = 'abj404_dead_dest_ids';

    /** @return array<int, string> */
    public function getIds(): array {
        if (!function_exists('get_transient')) {
            return array();
        }

        $raw = get_transient(self::CACHE_KEY);
        if (!is_array($raw)) {
            return array();
        }

        $ids = array();
        foreach ($raw as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                $ids[] = (string)$value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, string> $ids
     * @return void
     */
    public function storeIds(array $ids): void {
        if (!function_exists('set_transient')) {
            return;
        }

        $normalized = array();
        foreach ($ids as $id) {
            if (is_scalar($id) && (string)$id !== '') {
                $normalized[] = (string)$id;
            }
        }

        $ttl = defined('HOUR_IN_SECONDS') ? 25 * (int) HOUR_IN_SECONDS : 90000;
        // allow-cache-empty: an empty array is the explicit "no dead destinations" cache result.
        set_transient(self::CACHE_KEY, array_values(array_unique($normalized)), $ttl);
    }
}
