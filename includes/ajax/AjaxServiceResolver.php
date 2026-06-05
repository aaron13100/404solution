<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves optional and required services for AJAX endpoint adapters.
 */
class ABJ_404_Solution_Ajax_ServiceResolver {

    /**
     * @param string $serviceName
     * @return mixed
     */
    public static function optional($serviceName) {
        if (!class_exists('ABJ_404_Solution_ServiceContainer')) {
            return null;
        }
        return ABJ_404_Solution_ServiceContainer::safeGet($serviceName);
    }

    /**
     * @param string $serviceName
     * @return mixed
     */
    public static function required($serviceName) {
        $service = self::optional($serviceName);
        return ($service !== null) ? $service : abj_service($serviceName);
    }
}
