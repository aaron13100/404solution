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
 *
 * The global abj_service() helper is defined in
 * includes/bootstrap/service-locator.php.
 */
class ABJ_404_Solution_ServiceNotRegisteredException extends RuntimeException {

    /** @var string */
    private $serviceName;

    /** @var string */
    private $registrationClass;

    /** @var string */
    private $hint;

    /**
     * @param string          $serviceName
     * @param string          $registrationClass
     * @param \Throwable|null $previous
     */
    public function __construct($serviceName, $registrationClass, ?\Throwable $previous = null) {
        $this->serviceName = (string)$serviceName;
        $this->registrationClass = (string)$registrationClass;
        $this->hint = 'Register service "' . $this->serviceName . '" in ' . $this->registrationClass
            . ' or use abj_service_optional() when absence is an intentional bootstrap probe.';

        parent::__construct($this->hint, 0, $previous);
    }

    /** @return string */
    public function getServiceName() {
        return $this->serviceName;
    }

    /** @return string */
    public function getRegistrationClass() {
        return $this->registrationClass;
    }

    /** @return string */
    public function getHint() {
        return $this->hint;
    }
}

/**
 * Thrown when a service factory transitively re-requests the service it is
 * mid-construction of. Carries the resolution chain so the offending cycle is
 * diagnosable from the message alone.
 */
class ABJ_404_Solution_ServiceResolutionCycleException extends RuntimeException {

    /** @var string */
    private $serviceName;

    /** @var string */
    private $chain;

    /**
     * @param string $serviceName The service whose factory re-entered.
     * @param string $chain Human-readable resolution chain (a -> b -> a).
     */
    public function __construct($serviceName, $chain) {
        $this->serviceName = (string)$serviceName;
        $this->chain = (string)$chain;
        parent::__construct(
            'Service resolution cycle detected while building "' . $this->serviceName
            . '": ' . $this->chain
            . '. A factory must not request the service it is constructing.'
        );
    }

    /** @return string */
    public function getServiceName() {
        return $this->serviceName;
    }

    /** @return string */
    public function getChain() {
        return $this->chain;
    }
}

class ABJ_404_Solution_ServiceContainer {

    /**
     * Singleton instance of the container itself.
     * Note: The container is a singleton, but the services it manages can have any lifecycle.
     */
    /** @var self|null */
    private static $instance = null;

    /**
     * Most recent Throwable suppressed by safeGet() / abj_service_optional(), or
     * null when the last resolution succeeded. Recovered by diagnostic
     * code via getLastSuppressedError().
     *
     * @var \Throwable|null
     */
    private static $lastSuppressedError = null;

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
     * Names whose factory is currently executing, in resolution order. Used to
     * detect a service-resolution cycle (a factory that transitively re-requests
     * itself) before it recurses into a stack overflow. Empty except mid-get().
     * @var array<string, true>
     */
    private $resolving = array();

    /**
     * When true, registration fills gaps without replacing factories that a
     * caller already installed before lazy bootstrap. Direct registration
     * calls outside this guarded mode still replace services.
     *
     * @var bool
     */
    private $preserveExistingRegistrations = false;

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
        if ($this->preserveExistingRegistrations && isset($this->services[$name])) {
            return;
        }
        $this->services[$name] = $factory;
        // Clear any existing instance when re-registering
        unset($this->instances[$name]);
    }

    /** @return void */
    public function beginPreservingExistingRegistrations(): void {
        $this->preserveExistingRegistrations = true;
    }

    /** @return void */
    public function endPreservingExistingRegistrations(): void {
        $this->preserveExistingRegistrations = false;
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
            throw new Exception("Service '$name' is not registered in the container"); // allow-raw-error: programmer assertion, callers either expect the throw or use safeGet()/abj_service_optional() which catch it
        }

        // Detect a resolution cycle before it recurses into a stack overflow.
        // The instance is cached only AFTER its factory returns, so a factory
        // that transitively re-requests the same service would otherwise re-run
        // forever (a structural sibling of the 4.3.0 logging<->options OOM).
        if (isset($this->resolving[$name])) {
            $chain = implode(' -> ', array_keys($this->resolving)) . ' -> ' . $name;
            throw new ABJ_404_Solution_ServiceResolutionCycleException($name, $chain);
        }

        // Create the instance using the factory
        $factory = $this->services[$name];
        $this->resolving[$name] = true;
        try {
            $instance = $factory($this);
        } finally {
            unset($this->resolving[$name]);
        }

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
     * catch { fall back } ` pattern at call sites - the swallow lives
     * here, in one place.
     *
     * On failure the underlying Throwable is preserved two ways:
     *   1. Full context (class, code, file:line, message) is written to
     *      the PHP error log so production sysadmins see it.
     *   2. The Throwable instance is captured in self::$lastSuppressedError
     *      so diagnostic surfaces (admin notices, integration tests, the
     *      design-audit M401 fix in c367/368/369) can recover the full
     *      exception chain by calling self::getLastSuppressedError().
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
            $result = $c->get($name);
            self::$lastSuppressedError = null;
            return $result;
        } catch (\Throwable $e) {
            self::recordSuppressedError('ServiceContainer::safeGet(' . $name . ')', $e);
            return null;
        }
    }

    /**
     * Returns the most recent Throwable that was suppressed by safeGet() or
     * by the abj_service_optional() helper, or null if the last resolution succeeded.
     *
     * Diagnostic code should call this immediately after a null-return from
     * safeGet()/abj_service_optional() to recover the full exception chain. The wrappers
     * intentionally return null instead of throwing, but the underlying error
     * is preserved here for inspection.
     *
     * @return \Throwable|null
     */
    public static function getLastSuppressedError() {
        return self::$lastSuppressedError;
    }

    /**
     * Reset the suppressed-error capture. Useful between tests and after a
     * caller has handled a prior failure.
     *
     * @return void
     */
    public static function clearLastSuppressedError() {
        self::$lastSuppressedError = null;
    }

    /**
     * Internal: capture a suppressed Throwable for getLastSuppressedError()
     * and emit a fully-contextualised PHP error-log line.
     *
     * Centralising the swallow-and-log behaviour here ensures every catch in
     * this file records the exception class, file:line, code, and message,
     * not just the message, so the exception chain is recoverable from
     * production logs alone.
     *
     * @param string     $context Short identifier for the call site
     *                            (e.g. 'ServiceContainer::safeGet(foo)').
     * @param \Throwable $e       The suppressed exception.
     * @return void
     */
    private static function recordSuppressedError($context, \Throwable $e) {
        self::$lastSuppressedError = $e;
        abj404_logPhpFallback('service-resolution-fallback', sprintf(
            '%s suppressed %s (code %s) at %s:%d: %s',
            $context,
            get_class($e),
            (string) $e->getCode(),
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        ));
    }

    /**
     * Public seam for the abj_service_optional() helper.
     * Code outside the class cannot reach private statics, so this delegates
     * to recordSuppressedError() and is otherwise identical. Not intended
     * for call sites elsewhere in the codebase: use safeGet() instead.
     *
     * @param string     $context
     * @param \Throwable $e
     * @return void
     */
    public static function recordSuppressedErrorPublic($context, \Throwable $e) {
        self::recordSuppressedError($context, $e);
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

// Preserve the historical contract that loading core/ServiceContainer.php
// makes the global abj_service() helper available. Many tests require the
// container file directly to get that helper without also booting the
// production bootstrap.php. Production callers reach the same require via
// bootstrap.php; require_once makes either path safe.
require_once __DIR__ . '/../bootstrap/service-locator.php';
