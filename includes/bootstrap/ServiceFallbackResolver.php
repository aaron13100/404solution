<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Test-time fallback resolution for the service container.
 *
 * When the container has not been initialized for the current request
 * (typical of unit tests that called clear()/reset() and only registered
 * their specific mocks), this resolver bridges to:
 *
 *   1. Hand-coded factories for services with non-trivial constructors.
 *   2. DataAccess subsystem getters (db_core, repositories, view services).
 *   3. Legacy Class::getInstance() singleton fallback via a name->class map.
 *
 * Production code paths are unaffected: services are always registered at
 * boot via abj_404_solution_init_services(), so the container's has()
 * check succeeds before reaching this resolver. The lint at
 * scripts/lint/lint-getinstance-callers.sh enforces that production
 * callers must use the abj_service() helper rather than calling
 * getInstance() directly.
 *
 * Companion file: includes/bootstrap/service-locator.php (defines the
 * global abj_service() helper that delegates here when the container
 * misses).
 */
class ABJ_404_Solution_ServiceFallbackResolver {

    /**
     * Resolve a service name when the container has no registration for it.
     *
     * Tries (in order): hand-coded factories, DataAccess subsystem getters,
     * legacy Class::getInstance() map. Returns null on full failure (with
     * the underlying Throwable preserved via
     * ABJ_404_Solution_ServiceContainer::recordSuppressedErrorPublic()).
     *
     * @param string                            $name      Service identifier
     * @param ABJ_404_Solution_ServiceContainer $container Container we already
     *                                                     verified has no
     *                                                     registration for $name
     * @return mixed|null
     */
    public static function resolve($name, ABJ_404_Solution_ServiceContainer $container) {
        $built = self::buildKnownFactory($name);
        if ($built !== null) {
            return $built;
        }

        $dataAccessService = self::resolveDataAccessService($name);
        if ($dataAccessService !== null) {
            return $dataAccessService;
        }

        $legacy = self::resolveLegacySingleton($name);
        if ($legacy !== null) {
            return $legacy;
        }

        // Last resort: the container raises its standard "not registered"
        // exception. Catch and return null so callers can rely on a uniform
        // non-throwing contract; the swallow is logged via error_log() so the
        // failure is still visible in production logs.
        try {
            return $container->get($name);
        } catch (\Throwable $e) {
            ABJ_404_Solution_ServiceContainer::recordSuppressedErrorPublic(
                'abj_service(' . $name . ') unresolved',
                $e
            );
            return null;
        }
    }

    /**
     * Honor a singleton override (installed via reflection on a
     * peekInstance()-exposing class) so test-installed mock instances win
     * over the container cache. Returns null when no override is in effect
     * or when the installed instance is the canonical class itself (signal
     * that production getInstance() populated it, not a test).
     *
     * @param string $name Service identifier
     * @return mixed|null
     */
    public static function singletonOverride($name) {
        static $map = array(
            'plugin_logic'      => 'ABJ_404_Solution_PluginLogic',
            'logging'           => 'ABJ_404_Solution_Logging',
            'data_access'       => 'ABJ_404_Solution_DataAccess',
            'database_upgrades' => 'ABJ_404_Solution_DatabaseUpgradesEtc',
        );
        if (!isset($map[$name])) {
            return null;
        }
        $class = $map[$name];
        if (!class_exists($class, false) || !method_exists($class, 'peekInstance')) {
            return null;
        }
        $peeked = $class::peekInstance();
        if ($peeked === null) {
            return null;
        }
        // If the installed instance IS exactly the canonical class (not a
        // subclass or unrelated double), it was almost certainly populated by
        // the production getInstance() path during normal setup, not by a
        // caller asserting a behavioral override. Returning it here would also
        // short-circuit any container.set() that a caller has installed for
        // the same service. Subclass doubles (test stubs that extend the real
        // class) and non-class doubles (anonymous classes that mimic the
        // duck-type) are honored, since those are the cases where a caller
        // wants the override.
        if (is_object($peeked) && get_class($peeked) === $class) {
            return null;
        }
        return $peeked;
    }

    /**
     * Hand-coded factories for services with non-trivial constructors that
     * the registration classes also wire. Used when the container is
     * empty for $name. Returns null when no factory matches or the
     * required class is not loaded.
     *
     * Implemented as a registry of name -> {required_class, factory closure}
     * so adding a fallback is a one-entry edit and the cyclomatic complexity
     * stays flat regardless of how many services need a hand-coded path.
     *
     * @param string $name
     * @return mixed|null
     */
    private static function buildKnownFactory($name) {
        $factories = self::knownFactories();
        if (!isset($factories[$name])) {
            return null;
        }
        list($requiredClass, $factory) = $factories[$name];
        if ($requiredClass !== null && !class_exists($requiredClass)) {
            return null;
        }
        return $factory();
    }

    /**
     * Registry of hand-coded factory closures for services that need
     * non-trivial construction during the test-time fallback path.
     *
     * Each entry: [required_class_or_null, factory_closure].
     * A null required_class means the closure handles its own
     * availability checks (mb_string_adapter and regex_helper, which
     * pick between an mbstring and a preg implementation).
     *
     * @return array<string, array{0: ?string, 1: \Closure}>
     */
    private static function knownFactories() {
        static $factories = null;
        if ($factories !== null) {
            return $factories;
        }
        $factories = array(
            'clock' => array(
                'ABJ_404_Solution_SystemClock',
                static function () {
                    return new ABJ_404_Solution_SystemClock();
                },
            ),
            'cron_scheduler' => array(
                'ABJ_404_Solution_CronScheduler',
                static function () {
                    return new ABJ_404_Solution_CronScheduler(
                        abj_service('clock'),
                        abj_service('logging')
                    );
                },
            ),
            'pii_redactor' => array(
                'ABJ_404_Solution_PiiRedactor',
                static function () {
                    return new ABJ_404_Solution_PiiRedactor(abj_service('functions'));
                },
            ),
            'url_encoder' => array(
                'ABJ_404_Solution_UrlEncoder',
                static function () {
                    $regexHelper = abj_service('regex_helper');
                    return new ABJ_404_Solution_UrlEncoder(
                        abj_service('mb_string_adapter'),
                        $regexHelper instanceof ABJ_404_Solution_RegexHelper ? $regexHelper : null
                    );
                },
            ),
            'sanitizer' => array(
                'ABJ_404_Solution_Sanitizer',
                static function () {
                    return new ABJ_404_Solution_Sanitizer(abj_service('mb_string_adapter'));
                },
            ),
            'query_string_helper' => array(
                'ABJ_404_Solution_QueryStringHelper',
                static function () {
                    $logging = abj_service('logging');
                    return new ABJ_404_Solution_QueryStringHelper(
                        abj_service('sanitizer'),
                        $logging instanceof ABJ_404_Solution_Logging ? $logging : null
                    );
                },
            ),
            'mb_string_adapter' => array(
                null,
                static function () {
                    if (extension_loaded('mbstring') && class_exists('ABJ_404_Solution_MbStringAdapterMb')) {
                        return ABJ_404_Solution_MbStringAdapterMb::getInstance();
                    }
                    if (class_exists('ABJ_404_Solution_MbStringAdapterPreg')) {
                        return ABJ_404_Solution_MbStringAdapterPreg::getInstance();
                    }
                    return null;
                },
            ),
            'regex_helper' => array(
                null,
                static function () {
                    if (extension_loaded('mbstring') && class_exists('ABJ_404_Solution_RegexHelperMb')) {
                        return ABJ_404_Solution_RegexHelperMb::getInstance();
                    }
                    if (class_exists('ABJ_404_Solution_RegexHelperPreg')) {
                        return ABJ_404_Solution_RegexHelperPreg::getInstance();
                    }
                    return null;
                },
            ),
            'ajax_security_gate' => array(
                'ABJ_404_Solution_RuntimeServiceRegistration',
                static function () {
                    return ABJ_404_Solution_RuntimeServiceRegistration::buildAjaxSecurityGateFallback();
                },
            ),
            'ajax_failure_logger' => array(
                'ABJ_404_Solution_RuntimeServiceRegistration',
                static function () {
                    return ABJ_404_Solution_RuntimeServiceRegistration::buildAjaxFailureLoggerFallback();
                },
            ),
            'not_found_response' => array(
                'ABJ_404_Solution_NotFoundResponseService',
                static function () {
                    return new ABJ_404_Solution_NotFoundResponseService(
                        abj_service('functions'),
                        abj_service('redirects_repository'),
                        abj_service('logs_repository'),
                        abj_service('logging'),
                        abj_service('options_repository'),
                        abj_service('previous_request_cookie_tracker')
                    );
                },
            ),
            'previous_request_cookie_tracker' => array(
                'ABJ_404_Solution_PreviousRequestCookieTracker',
                static function () {
                    return new ABJ_404_Solution_PreviousRequestCookieTracker(
                        abj_service('logging')
                    );
                },
            ),
            'request_ignore_normalizer' => array(
                'ABJ_404_Solution_RequestIgnoreNormalizer',
                static function () {
                    return new ABJ_404_Solution_RequestIgnoreNormalizer(
                        abj_service('options_repository'),
                        abj_service('functions'),
                        abj_service('logging'),
                        abj_service('redirects_repository'),
                        abj_service('logs_repository'),
                        abj_service('not_found_response')
                    );
                },
            ),
            'stats_repository' => array(
                'ABJ_404_Solution_StatsRepository',
                static function () {
                    return new ABJ_404_Solution_StatsRepository(
                        abj_service('db_core'),
                        abj_service('logs_repository'),
                        abj_service('functions'),
                        abj_service('logging')
                    );
                },
            ),
        );
        return $factories;
    }

    /**
     * Resolve DataAccess-owned services (db_core, repositories, view
     * services) via the DataAccess singleton's getters. Returns null when
     * $name isn't owned by DataAccess or the class isn't loaded.
     *
     * @param string $name
     * @return mixed|null
     */
    private static function resolveDataAccessService($name) {
        $dataAccessClass = 'ABJ_404_Solution_DataAccess';
        if (!class_exists($dataAccessClass)) {
            return null;
        }
        $getters = array(
            'db_core' => 'getDbCore',
            'content_repository' => 'getContentRepo',
            'redirects_repository' => 'getRedirectsRepo',
            'redirects_retention_service' => 'getRetentionService',
            'logs_repository' => 'getLogsRepo',
            'view_read_service' => 'getViewReadService',
            'view_build_orchestrator' => 'getViewBuildOrchestrator',
        );
        if (!isset($getters[$name])) {
            return null;
        }
        $dataAccess = $dataAccessClass::getInstance();
        $getter = $getters[$name];
        if (method_exists($dataAccess, $getter)) {
            return $dataAccess->$getter();
        }
        return null;
    }

    /**
     * Legacy singleton fallback: consult a static service-name -> class-name
     * map and call Class::getInstance() when available. Inverse of the
     * registration map in bootstrap.php; lets a caller resolve a service
     * even when the container hasn't been populated for this request
     * (typically: a unit test that called reset()/clear() and only
     * registered the specific mocks it needed).
     *
     * @param string $name
     * @return mixed|null
     */
    private static function resolveLegacySingleton($name) {
        static $serviceClassMap = null;
        if ($serviceClassMap === null) {
            $serviceClassMap = array(
                'functions' => 'ABJ_404_Solution_Functions',
                'mb_string_adapter' => extension_loaded('mbstring')
                    ? 'ABJ_404_Solution_MbStringAdapterMb'
                    : 'ABJ_404_Solution_MbStringAdapterPreg',
                'regex_helper' => extension_loaded('mbstring')
                    ? 'ABJ_404_Solution_RegexHelperMb'
                    : 'ABJ_404_Solution_RegexHelperPreg',
                'logging' => 'ABJ_404_Solution_Logging',
                'data_access' => 'ABJ_404_Solution_DataAccess',
                'plugin_logic' => 'ABJ_404_Solution_PluginLogic',
                'request_ignore_normalizer' => 'ABJ_404_Solution_RequestIgnoreNormalizer',
                'view' => 'ABJ_404_Solution_View',
                'view_suggestions' => 'ABJ_404_Solution_View_Suggestions',
                'spell_checker' => 'ABJ_404_Solution_SpellChecker',
                'wordpress_connector' => 'ABJ_404_Solution_WordPress_Connector',
                'database_upgrades' => 'ABJ_404_Solution_DatabaseUpgradesEtc',
                'permalink_cache' => 'ABJ_404_Solution_PermalinkCache',
                'ngram_filter' => 'ABJ_404_Solution_NGramFilter',
                'ngram_extractor' => 'ABJ_404_Solution_NGramExtractor',
                'ngram_similarity' => 'ABJ_404_Solution_NGramSimilarity',
                'ngram_cache_repository' => 'ABJ_404_Solution_NGramCacheRepository',
                'ngram_coverage_policy' => 'ABJ_404_Solution_NGramCoveragePolicy',
                'ngram_rebuilder' => 'ABJ_404_Solution_NGramRebuilder',
                'ngram_usage_telemetry' => 'ABJ_404_Solution_NGramUsageTelemetry',
                'slug_change_handler' => 'ABJ_404_Solution_SlugChangeHandler',
                'published_posts_provider' => 'ABJ_404_Solution_PublishedPostsProvider',
                'sync_utils' => 'ABJ_404_Solution_SynchronizationUtils',
                'shortcode' => 'ABJ_404_Solution_ShortCode',
                'request_context' => 'ABJ_404_Solution_RequestContext',
                'previous_request_cookie_tracker' => 'ABJ_404_Solution_PreviousRequestCookieTracker',
                'version_upgrade' => 'ABJ_404_Solution_PluginLogicVersionUpgrader',
                'options_repository' => 'ABJ_404_Solution_PluginLogicOptionsResolver',
                'admin_access_policy' => 'ABJ_404_Solution_PluginAdminAccessPolicy',
                'settings_mode_preference' => 'ABJ_404_Solution_SettingsModePreference',
                'not_found_response' => 'ABJ_404_Solution_NotFoundResponseService',
            );
        }
        if (!isset($serviceClassMap[$name])) {
            return null;
        }
        $class = $serviceClassMap[$name];
        if (!class_exists($class) || !method_exists($class, 'getInstance')) {
            return null;
        }
        /** @var callable(): mixed $callback */
        $callback = array($class, 'getInstance');
        try {
            return call_user_func($callback);
        } catch (\Throwable $e) {
            ABJ_404_Solution_ServiceContainer::recordSuppressedErrorPublic(
                'abj_service(' . $name . ') legacy fallback',
                $e
            );
            return null;
        }
    }
}
