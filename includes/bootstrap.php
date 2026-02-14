<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap file for initializing the service container and registering services.
 *
 * This file sets up dependency injection for the plugin's core services,
 * making dependencies explicit and testable.
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

    // =========================================================================
    // Core Utilities (no dependencies)
    // =========================================================================

    /**
     * Functions service - provides string manipulation and utility methods.
     * Auto-selects between mbstring and preg implementations.
     */
    $container->set('functions', function($c) {
        if (extension_loaded('mbstring')) {
            return new ABJ_404_Solution_FunctionsMBString();
        }
        return new ABJ_404_Solution_FunctionsPreg();
    });

    /**
     * Logging service - handles debug logging and error reporting.
     */
    $container->set('logging', function($c) {
        return ABJ_404_Solution_Logging::createForContainer();
    });

    /**
     * Error handler service - manages error handling and reporting.
     */
    $container->set('error_handler', function($c) {
        // Error handler is static; return class name for callers that want a handle.
        return 'ABJ_404_Solution_ErrorHandler';
    });

    // =========================================================================
    // Data Layer
    // =========================================================================

    /**
     * Data access service - handles all database operations.
     * Dependencies: functions, logging
     */
    $container->set('data_access', function($c) {
        return new ABJ_404_Solution_DataAccess(
            $c->get('functions'),
            $c->get('logging')
        );
    });

    /**
     * Database upgrades - handles schema migrations and upgrades.
     * Dependencies: data_access, logging, functions, permalink_cache, sync_utils, plugin_logic, ngram_filter
     */
    $container->set('database_upgrades', function($c) {
        return new ABJ_404_Solution_DatabaseUpgradesEtc(
            $c->get('data_access'),
            $c->get('logging'),
            $c->get('functions'),
            $c->get('permalink_cache'),
            $c->get('sync_utils'),
            $c->get('plugin_logic'),
            $c->get('ngram_filter')
        );
    });

    /**
     * Permalink cache - caches permalink lookups for performance.
     * Dependencies: data_access, logging, plugin_logic
     */
    $container->set('permalink_cache', function($c) {
        return new ABJ_404_Solution_PermalinkCache(
            $c->get('data_access'),
            $c->get('logging'),
            $c->get('plugin_logic')
        );
    });

    /**
     * N-gram filter - provides N-gram based spell checker optimization.
     * Dependencies: data_access, logging, functions
     */
    $container->set('ngram_filter', function($c) {
        return new ABJ_404_Solution_NGramFilter(
            $c->get('data_access'),
            $c->get('logging'),
            $c->get('functions')
        );
    });

    // =========================================================================
    // Business Logic Layer
    // =========================================================================

    /**
     * Plugin logic service - core business logic and coordination.
     * Dependencies: functions, data_access, logging
     */
    $container->set('plugin_logic', function($c) {
        return new ABJ_404_Solution_PluginLogic(
            $c->get('functions'),
            $c->get('data_access'),
            $c->get('logging')
        );
    });

    /**
     * Spell checker service - handles URL matching and suggestions.
     * Dependencies: functions, plugin_logic, data_access, logging, permalink_cache, ngram_filter
     */
    $container->set('spell_checker', function($c) {
        return new ABJ_404_Solution_SpellChecker(
            $c->get('functions'),
            $c->get('plugin_logic'),
            $c->get('data_access'),
            $c->get('logging'),
            $c->get('permalink_cache'),
            $c->get('ngram_filter')
        );
    });

    /**
     * WordPress connector - interfaces with WordPress core APIs.
     * Dependencies: plugin_logic, data_access, logging, functions, spell_checker
     */
    $container->set('wordpress_connector', function($c) {
        return new ABJ_404_Solution_WordPress_Connector(
            $c->get('plugin_logic'),
            $c->get('data_access'),
            $c->get('logging'),
            $c->get('functions'),
            $c->get('spell_checker')
        );
    });

    /**
     * Slug change handler - detects and handles post slug changes.
     */
    $container->set('slug_change_handler', function($c) {
        return ABJ_404_Solution_SlugChangeHandler::getInstance();
    });

    /**
     * Published posts provider - manages published post lookups.
     */
    $container->set('published_posts_provider', function($c) {
        return ABJ_404_Solution_PublishedPostsProvider::getInstance();
    });

    /**
     * Synchronization utilities - handles data synchronization.
     */
    $container->set('sync_utils', function($c) {
        return ABJ_404_Solution_SynchronizationUtils::getInstance();
    });

    // =========================================================================
    // Presentation Layer
    // =========================================================================

    /**
     * View service - renders admin pages and UI components.
     * Dependencies: functions, plugin_logic, data_access, logging
     */
    $container->set('view', function($c) {
        return new ABJ_404_Solution_View(
            $c->get('functions'),
            $c->get('plugin_logic'),
            $c->get('data_access'),
            $c->get('logging')
        );
    });

    /**
     * View suggestions - renders suggestion UI components.
     * Dependencies: functions
     */
    $container->set('view_suggestions', function($c) {
        return new ABJ_404_Solution_View_Suggestions(
            $c->get('functions')
        );
    });

    /**
     * Shortcode handler - processes WordPress shortcodes.
     */
    $container->set('shortcode', function($c) {
        return ABJ_404_Solution_ShortCode::getInstance();
    });
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
    $container = ABJ_404_Solution_ServiceContainer::getInstance();

    // Map class names to service names
    $serviceMap = array(
        'ABJ_404_Solution_Functions' => 'functions',
        'ABJ_404_Solution_Logging' => 'logging',
        'ABJ_404_Solution_DataAccess' => 'data_access',
        'ABJ_404_Solution_PluginLogic' => 'plugin_logic',
        'ABJ_404_Solution_View' => 'view',
        'ABJ_404_Solution_SpellChecker' => 'spell_checker',
        'ABJ_404_Solution_WordPress_Connector' => 'wordpress_connector',
        // Error handler is static; no instance.
        'ABJ_404_Solution_DatabaseUpgradesEtc' => 'database_upgrades',
        'ABJ_404_Solution_PermalinkCache' => 'permalink_cache',
        'ABJ_404_Solution_NGramFilter' => 'ngram_filter',
        'ABJ_404_Solution_SlugChangeHandler' => 'slug_change_handler',
        'ABJ_404_Solution_PublishedPostsProvider' => 'published_posts_provider',
        'ABJ_404_Solution_SynchronizationUtils' => 'sync_utils',
        'ABJ_404_Solution_View_Suggestions' => 'view_suggestions',
        'ABJ_404_Solution_ShortCode' => 'shortcode',
    );

    if (isset($serviceMap[$className])) {
        return $container->get($serviceMap[$className]);
    }

    // Fallback to calling the class's getInstance() method
    if (method_exists($className, 'getInstance')) {
        return call_user_func(array($className, 'getInstance'));
    }

    throw new Exception("Cannot get instance of class: $className");
}
