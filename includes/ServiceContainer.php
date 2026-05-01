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
    /** @var self|null */
    private static $instance = null;

    /**
     * Registered services and their factory functions.
     * @var array<string, callable>
     */
    private $services = array();

    /**
     * Instantiated service instances (for singleton services).
     * @var array<string, mixed>
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

    /**
     * Non-throwing existence check. Returns true iff the container has a
     * registered factory for the named service. Bootstraps the container
     * singleton on demand so callers don't have to.
     *
     * @param string $name Service identifier
     * @return bool
     */
    public static function safeHas($name) {
        $c = self::getInstance();
        return $c->has($name);
    }

    /**
     * Non-throwing service resolution. Returns the resolved instance, or
     * null if the service isn't registered or the factory raises any
     * Throwable. Replaces the legacy `try { ServiceContainer::get(...) }
     * catch { fall back } ` pattern at call sites — the swallow lives
     * here, in one place, and is logged via error_log() so it isn't
     * completely invisible.
     *
     * @param string $name Service identifier
     * @return mixed The service instance, or null on any failure
     */
    public static function safeGet($name) {
        $c = self::getInstance();
        if (!$c->has($name)) {
            return null;
        }
        try {
            return $c->get($name);
        } catch (\Throwable $e) {
            error_log('404 Solution: ServiceContainer::safeGet(' . $name . ') failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Returns true iff the container singleton has been instantiated AND
     * at least one service factory has been registered. False during
     * very early boot (autoload-only) or after `reset()` in tests.
     *
     * @return bool
     */
    public static function isInitialized() {
        return self::$instance !== null && self::$instance->services !== array();
    }
}

/**
 * Helper function to access services from the container.
 *
 * This provides a shorter, more convenient syntax than calling
 * ABJ_404_Solution_ServiceContainer::getInstance()->get().
 *
 * Fallback semantics: if the named service is not currently registered
 * (e.g. a test cleared the container without re-running
 * `abj_404_solution_init_services()`), this looks up the service name in a
 * static name->class map and falls back to the legacy
 * `ClassName::getInstance()` singleton. This preserves test patterns that
 * predate the c260 codemod (clear container, register only the mocks the
 * test cares about) without forcing every test to re-init the entire
 * service graph. Production code paths are unaffected because services are
 * always registered at boot via `abj_404_solution_init_services()`; the
 * lint at `scripts/lint/lint-getinstance-callers.sh` enforces that
 * production callers must use this helper rather than `getInstance()`
 * directly.
 *
 * @param string $name Service identifier
 * @return mixed The service instance
 *
 * @phpstan-return (
 *     $name is 'functions' ? ABJ_404_Solution_Functions : (
 *     $name is 'logging' ? ABJ_404_Solution_Logging : (
 *     $name is 'clock' ? ABJ_404_Solution_Clock : (
 *     $name is 'error_handler' ? class-string : (
 *     $name is 'data_access' ? ABJ_404_Solution_DataAccess : (
 *     $name is 'database_upgrades' ? ABJ_404_Solution_DatabaseUpgradesEtc : (
 *     $name is 'permalink_cache' ? ABJ_404_Solution_PermalinkCache : (
 *     $name is 'ngram_filter' ? ABJ_404_Solution_NGramFilter : (
 *     $name is 'plugin_logic' ? ABJ_404_Solution_PluginLogic : (
 *     $name is 'spell_checker' ? ABJ_404_Solution_SpellChecker : (
 *     $name is 'engine_slug' ? ABJ_404_Solution_SlugMatchingEngine : (
 *     $name is 'engine_url_fix' ? ABJ_404_Solution_UrlFixEngine : (
 *     $name is 'engine_title' ? ABJ_404_Solution_TitleMatchingEngine : (
 *     $name is 'engine_category_tag' ? ABJ_404_Solution_CategoryTagMatchingEngine : (
 *     $name is 'engine_content' ? ABJ_404_Solution_ContentMatchingEngine : (
 *     $name is 'engine_spelling' ? ABJ_404_Solution_SpellingMatchingEngine : (
 *     $name is 'engine_archive_fallback' ? ABJ_404_Solution_ArchiveFallbackEngine : (
 *     $name is 'matching_engines' ? array<int, object> : (
 *     $name is 'wordpress_connector' ? ABJ_404_Solution_WordPress_Connector : (
 *     $name is 'slug_change_handler' ? ABJ_404_Solution_SlugChangeHandler : (
 *     $name is 'published_posts_provider' ? ABJ_404_Solution_PublishedPostsProvider : (
 *     $name is 'sync_utils' ? ABJ_404_Solution_SynchronizationUtils : (
 *     $name is 'request_context' ? ABJ_404_Solution_RequestContext : (
 *     $name is 'view' ? ABJ_404_Solution_View : (
 *     $name is 'view_suggestions' ? ABJ_404_Solution_View_Suggestions : (
 *     $name is 'shortcode' ? ABJ_404_Solution_ShortCode :
 *     mixed
 * ))))))))))))))))))))))))))
 */
function abj_service($name) {
    $container = ABJ_404_Solution_ServiceContainer::getInstance();
    if ($container->has($name)) {
        return $container->get($name);
    }

    // Inverse of the registration map in bootstrap.php. Lets a caller resolve
    // a service even when the container hasn't been populated for this
    // request (typically: a unit test that called
    // ABJ_404_Solution_ServiceContainer::reset() / ->clear() and only
    // registered the specific mocks it needed). Long-tail unregistered
    // singletons that have a stable getInstance() are also reachable here so
    // call sites don't have to know whether a class is registered yet.
    static $serviceClassMap = array(
        'functions' => 'ABJ_404_Solution_Functions',
        'logging' => 'ABJ_404_Solution_Logging',
        'data_access' => 'ABJ_404_Solution_DataAccess',
        'plugin_logic' => 'ABJ_404_Solution_PluginLogic',
        'view' => 'ABJ_404_Solution_View',
        'view_suggestions' => 'ABJ_404_Solution_View_Suggestions',
        'spell_checker' => 'ABJ_404_Solution_SpellChecker',
        'wordpress_connector' => 'ABJ_404_Solution_WordPress_Connector',
        'database_upgrades' => 'ABJ_404_Solution_DatabaseUpgradesEtc',
        'permalink_cache' => 'ABJ_404_Solution_PermalinkCache',
        'ngram_filter' => 'ABJ_404_Solution_NGramFilter',
        'slug_change_handler' => 'ABJ_404_Solution_SlugChangeHandler',
        'published_posts_provider' => 'ABJ_404_Solution_PublishedPostsProvider',
        'sync_utils' => 'ABJ_404_Solution_SynchronizationUtils',
        'shortcode' => 'ABJ_404_Solution_ShortCode',
        'request_context' => 'ABJ_404_Solution_RequestContext',
    );
    if (isset($serviceClassMap[$name])) {
        $class = $serviceClassMap[$name];
        if (class_exists($class) && method_exists($class, 'getInstance')) {
            /** @var callable(): mixed $callback */
            $callback = array($class, 'getInstance');
            try {
                return call_user_func($callback);
            } catch (\Throwable $e) {
                error_log('404 Solution: abj_service(' . $name . ') legacy fallback failed: ' . $e->getMessage());
                return null;
            }
        }
    }

    // Last resort — the container raises its standard "not registered"
    // exception. Catch and return null so callers can rely on a uniform
    // non-throwing contract; the swallow is logged via error_log() so the
    // failure is still visible in production logs.
    try {
        return $container->get($name);
    } catch (\Throwable $e) {
        error_log('404 Solution: abj_service(' . $name . ') unresolved: ' . $e->getMessage());
        return null;
    }
}
