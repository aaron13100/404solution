<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple dependency injection container for managing service instances.
 *
 * This container provides a lightweight alternative to the singleton pattern,
 * making dependencies explicit and enabling easier testing.
 *
 * Usage:
 *   $container = ABJ_404_Solution_ServiceContainer::getInstance();
 *   $service = $container->get('service_name');
 *
 * Or use the helper function:
 *   $service = abj_service('service_name');
 */
class ABJ_404_Solution_ServiceContainer {

    /**
     * Singleton instance of the container itself.
     * Note: The container is a singleton, but the services it manages can have any lifecycle.
     */
    private static $instance = null;

    /**
     * Registered services and their factory functions.
     * @var array
     */
    private $services = array();

    /**
     * Instantiated service instances (for singleton services).
     * @var array
     */
    private $instances = array();

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct() {
        // Private constructor
    }

    /**
     * Get the singleton instance of the container.
     *
     * @return ABJ_404_Solution_ServiceContainer
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a service with a factory function.
     *
     * The factory function receives the container as its first parameter,
     * allowing it to resolve dependencies.
     *
     * @param string $name Service identifier
     * @param callable $factory Factory function that creates the service
     * @return void
     */
    public function set($name, $factory) {
        if (!is_callable($factory)) {
            throw new InvalidArgumentException("Factory for service '$name' must be callable");
        }
        $this->services[$name] = $factory;
        // Clear any existing instance when re-registering
        unset($this->instances[$name]);
    }

    /**
     * Get a service instance.
     *
     * Services are lazy-loaded - the factory function is only called
     * the first time the service is requested.
     *
     * @param string $name Service identifier
     * @return mixed The service instance
     * @throws Exception if service is not registered
     */
    public function get($name) {
        // Return existing instance if already created
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        // Check if service is registered
        if (!isset($this->services[$name])) {
            throw new Exception("Service '$name' is not registered in the container");
        }

        // Create the instance using the factory
        $factory = $this->services[$name];
        $instance = $factory($this);

        // Store the instance for future requests (singleton behavior)
        $this->instances[$name] = $instance;

        return $instance;
    }

    /**
     * Check if a service is registered.
     *
     * @param string $name Service identifier
     * @return bool
     */
    public function has($name) {
        return isset($this->services[$name]);
    }

    /**
     * Clear all services and instances.
     * Useful for testing.
     *
     * @return void
     */
    public function clear() {
        $this->services = array();
        $this->instances = array();
    }

    /**
     * Reset the container singleton instance.
     * Useful for testing.
     *
     * @return void
     */
    public static function reset() {
        self::$instance = null;
    }
}

/**
 * Helper function to access services from the container.
 *
 * This provides a shorter, more convenient syntax than calling
 * ABJ_404_Solution_ServiceContainer::getInstance()->get().
 *
 * @param string $name Service identifier
 * @return mixed The service instance
 */
function abj_service($name) {
    return ABJ_404_Solution_ServiceContainer::getInstance()->get($name);
}
