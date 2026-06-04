<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the stats repository for legacy construction paths.
 */
class ABJ_404_Solution_StatsRepositoryResolver {

    /**
     * @return ABJ_404_Solution_StatsRepositoryInterface
     */
    public static function resolve(string $context = ''): ABJ_404_Solution_StatsRepositoryInterface {
        $service = class_exists('ABJ_404_Solution_ServiceContainer')
            ? ABJ_404_Solution_ServiceContainer::safeGet('stats_repository')
            : null;
        if ($service instanceof ABJ_404_Solution_StatsRepositoryInterface) {
            return $service;
        }

        return new ABJ_404_Solution_UnavailableStatsRepository($context);
    }
}
