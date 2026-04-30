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
     * Clock service - injectable wall-clock so cooldown/rate-limit/cron-window
     * code can be tested with a frozen virtual time. Production binds
     * `SystemClock` (delegates to `time()` etc.); tests bind `FrozenClock`.
     * See `docs/clock-injection-audit.md`.
     */
    $container->set('clock', function($c) {
        return new ABJ_404_Solution_SystemClock();
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

    // =========================================================================
    // Matching Engines
    // =========================================================================

    /**
     * Slug matching engine - exact slug lookup via SpellChecker.
     * Dependencies: spell_checker
     */
    $container->set('engine_slug', function($c) {
        return new ABJ_404_Solution_SlugMatchingEngine($c->get('spell_checker'));
    });

    /**
     * URL fix engine - strips file extensions and trailing punctuation, then
     * checks if the cleaned slug resolves to a real page.
     * Dependencies: spell_checker, functions, logging
     */
    $container->set('engine_url_fix', function($c) {
        return new ABJ_404_Solution_UrlFixEngine(
            $c->get('spell_checker'),
            $c->get('functions'),
            $c->get('logging')
        );
    });

    /**
     * Title matching engine - keyword overlap between URL slug and post titles.
     * Dependencies: data_access, functions, logging
     */
    $container->set('engine_title', function($c) {
        return new ABJ_404_Solution_TitleMatchingEngine(
            $c->get('data_access'),
            $c->get('functions'),
            $c->get('logging')
        );
    });

    /**
     * Category/tag matching engine - hierarchical path resolution and taxonomy keyword matching.
     * Dependencies: data_access, functions, logging
     */
    $container->set('engine_category_tag', function($c) {
        return new ABJ_404_Solution_CategoryTagMatchingEngine(
            $c->get('data_access'),
            $c->get('functions'),
            $c->get('logging')
        );
    });

    /**
     * Content matching engine - keyword overlap between URL slug and post content.
     * Dependencies: data_access, functions, logging
     */
    $container->set('engine_content', function($c) {
        return new ABJ_404_Solution_ContentMatchingEngine(
            $c->get('data_access'),
            $c->get('functions'),
            $c->get('logging')
        );
    });

    /**
     * Spelling matching engine - Levenshtein/N-gram matching via SpellChecker.
     * Dependencies: spell_checker
     */
    $container->set('engine_spelling', function($c) {
        return new ABJ_404_Solution_SpellingMatchingEngine($c->get('spell_checker'));
    });

    /**
     * Archive fallback engine - redirects to post type archive pages.
     * Dependencies: functions, logging
     */
    $container->set('engine_archive_fallback', function($c) {
        return new ABJ_404_Solution_ArchiveFallbackEngine(
            $c->get('functions'),
            $c->get('logging')
        );
    });

    /**
     * Ordered list of matching engines for the frontend pipeline.
     * Filterable via 'abj404_matching_engines' to add/remove/reorder engines.
     */
    $container->set('matching_engines', function($c) {
        $engines = [$c->get('engine_slug'), $c->get('engine_url_fix'), $c->get('engine_title'), $c->get('engine_category_tag'), $c->get('engine_content'), $c->get('engine_spelling'), $c->get('engine_archive_fallback')];
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_matching_engines', $engines);
            $engines = is_array($filtered) ? $filtered : [];
        }
        return $engines;
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
        return new ABJ_404_Solution_SlugChangeHandler();
    });

    /**
     * Published posts provider - manages published post lookups.
     */
    $container->set('published_posts_provider', function($c) {
        return new ABJ_404_Solution_PublishedPostsProvider();
    });

    /**
     * Synchronization utilities - handles data synchronization.
     */
    $container->set('sync_utils', function($c) {
        return new ABJ_404_Solution_SynchronizationUtils();
    });

    /**
     * Request context - request-scoped state holder (debug breadcrumbs,
     * permalink cache, ignore flags). Replaces $_REQUEST[ABJ404_PP] as an
     * intra-request message bus. Container scope guarantees one instance
     * per PHP request, matching legacy `getInstance()` semantics.
     */
    $container->set('request_context', function($c) {
        return new ABJ_404_Solution_RequestContext();
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
        return new ABJ_404_Solution_ShortCode();
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
        'ABJ_404_Solution_RequestContext' => 'request_context',
    );

    if (isset($serviceMap[$className])) {
        return $container->get($serviceMap[$className]);
    }

    // Fallback to calling the class's getInstance() method
    if (method_exists($className, 'getInstance')) {
        /** @var callable(): mixed $callback */
        $callback = array($className, 'getInstance');
        return call_user_func($callback);
    }

    throw new Exception("Cannot get instance of class: $className");
}
