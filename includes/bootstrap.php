<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/bootstrap/CoreServiceRegistration.php';
require_once __DIR__ . '/bootstrap/DataServiceRegistration.php';
require_once __DIR__ . '/bootstrap/DomainServiceRegistration.php';
require_once __DIR__ . '/bootstrap/MatchingEngineServiceRegistration.php';
require_once __DIR__ . '/bootstrap/Abj404ServiceRegistrationContract.php';
require_once __DIR__ . '/bootstrap/RuntimeServiceRegistration.php';
require_once __DIR__ . '/bootstrap/LegacyInstanceResolver.php';
require_once __DIR__ . '/bootstrap/service-locator.php';

/**
 * Bootstrap file for initializing the service container and registering services.
 *
 * This file keeps the entry points stable while layer-specific registration
 * modules own the actual factory wiring.
 */

/**
 * Initialize all services in the dependency injection container.
 *
 * Services are registered with factory functions that define their dependencies.
 * The container handles lazy instantiation and ensures each service is a singleton.
 *
 * @return void
 */
function abj_404_solution_init_services() {
    $container = ABJ_404_Solution_ServiceContainer::getInstance();

    ABJ_404_Solution_CoreServiceRegistration::register($container);
    ABJ_404_Solution_DataServiceRegistration::register($container);
    ABJ_404_Solution_DomainServiceRegistration::register($container);
    ABJ_404_Solution_MatchingEngineServiceRegistration::register($container);
    ABJ_404_Solution_RuntimeServiceRegistration::register($container);
}

/**
 * Backward compatibility function for accessing services.
 *
 * This allows existing code to continue working while we migrate
 * to the container pattern. Eventually this can be removed.
 *
 * @param string $className The class name to get an instance of
 * @return mixed The service instance
 */
function abj_get_instance($className) {
    return ABJ_404_Solution_LegacyInstanceResolver::resolve($className);
}
