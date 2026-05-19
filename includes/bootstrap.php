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
 * Build the view-read service from the common DAO module dependencies.
 *
 * @param ABJ_404_Solution_ServiceContainer $container
 * @param callable $daoModuleDeps
 * @return ABJ_404_Solution_ViewReadService
 */
function abj_404_solution_create_view_read_service($container, $daoModuleDeps) {
    /** @var array{0: ABJ_404_Solution_DatabaseCore, 1: ABJ_404_Solution_Functions, 2: ABJ_404_Solution_Logging} $d */
    $d = $daoModuleDeps($container);
    /** @var ABJ_404_Solution_LogsRepository $logsRepository */
    $logsRepository = $container->get('logs_repository');
    /** @var ABJ_404_Solution_RedirectsRepository $redirectsRepository */
    $redirectsRepository = $container->get('redirects_repository');
    return new ABJ_404_Solution_ViewReadService(
        $d[0], $logsRepository, $redirectsRepository, $d[1], $d[2]
    );
}

/**
 * Build and wire the view-build orchestrator service.
 *
 * @param ABJ_404_Solution_ServiceContainer $container
 * @return ABJ_404_Solution_ViewBuildOrchestrator
 */
function abj_404_solution_create_view_build_orchestrator($container) {
    /** @var ABJ_404_Solution_DatabaseCore $dbCore */
    $dbCore = $container->get('db_core');
    /** @var ABJ_404_Solution_Functions $functions */
    $functions = $container->get('functions');
    /** @var ABJ_404_Solution_Logging $logging */
    $logging = $container->get('logging');
    /** @var ABJ_404_Solution_ViewReadService $viewReadService */
    $viewReadService = $container->get('view_read_service');
    /** @var ABJ_404_Solution_LogsRepository $logsRepository */
    $logsRepository = $container->get('logs_repository');
    $svc = new ABJ_404_Solution_ViewBuildOrchestrator(
        $dbCore, $functions, $logging
    );
    $svc->setViewReadService($viewReadService);
    $svc->setLogsRepository($logsRepository);
    $viewReadService->setViewBuildOrchestrator($svc);
    return $svc;
}

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
     * PII redactor - centralized redaction layer for all outgoing logs/reports.
     * Dependencies: functions
     */
    $container->set('pii_redactor', function($c) {
        return new ABJ_404_Solution_PiiRedactor($c->get('functions'));
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
     * Database core infrastructure: query execution, error recovery, timeouts.
     * Dependencies: functions, logging
     */
    $container->set('db_core', function($c) {
        return new ABJ_404_Solution_DatabaseCore(
            $c->get('functions'),
            $c->get('logging')
        );
    });

    // Extracted DAO modules (Phase 1+). Each receives db_core, functions, logging.
    $daoModuleDeps = function($c) { return [$c->get('db_core'), $c->get('functions'), $c->get('logging')]; };
    $container->set('content_repository', function($c) use ($daoModuleDeps) {
        return new ABJ_404_Solution_ContentRepository(...$daoModuleDeps($c));
    });
    $container->set('redirects_repository', function($c) use ($daoModuleDeps) {
        return new ABJ_404_Solution_RedirectsRepository(...$daoModuleDeps($c));
    });
    $container->set('logs_repository', function($c) use ($daoModuleDeps) {
        return new ABJ_404_Solution_LogsRepository(...$daoModuleDeps($c));
    });
    $container->set('stats_repository', function($c) {
        return new ABJ_404_Solution_StatsRepository(
            $c->get('db_core'), $c->get('logs_repository'),
            $c->get('functions'), $c->get('logging')
        );
    });
    $container->set('view_read_service', function($c) use ($daoModuleDeps) { return abj_404_solution_create_view_read_service($c, $daoModuleDeps); });
    $container->set('view_build_orchestrator', function($c) { return abj_404_solution_create_view_build_orchestrator($c); });
    $container->set('data_access', function($c) {
        return new ABJ_404_Solution_DataAccess($c->get('functions'), $c->get('logging'), $c->get('db_core'),
            $c->get('content_repository'), $c->get('redirects_repository'),
            $c->get('logs_repository'), $c->get('stats_repository'), $c->get('view_read_service'), $c->get('view_build_orchestrator'));
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
     * Dependencies: content_repository, logging, plugin_logic, stats_repository
     */
    $container->set('permalink_cache', function($c) {
        return new ABJ_404_Solution_PermalinkCache($c->get('content_repository'),
            $c->get('logging'), $c->get('plugin_logic'), $c->get('stats_repository'));
    });

    /**
     * N-gram filter - provides N-gram based spell checker optimization.
     * Dependencies: db_core, logging, functions
     */
    $container->set('ngram_filter', function($c) {
        return new ABJ_404_Solution_NGramFilter($c->get('db_core'), $c->get('logging'), $c->get('functions'));
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
     * Dependencies: functions, plugin_logic, content_repository, logging, permalink_cache, ngram_filter, view_read_service
     */
    $container->set('spell_checker', function($c) {
        return new ABJ_404_Solution_SpellChecker($c->get('functions'), $c->get('plugin_logic'),
            $c->get('content_repository'), $c->get('logging'), $c->get('permalink_cache'),
            $c->get('ngram_filter'), $c->get('view_read_service'));
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
     * Dependencies: plugin_logic, redirects_repository, logging, functions, spell_checker, logs_repository
     */
    $container->set('wordpress_connector', function($c) {
        return new ABJ_404_Solution_WordPress_Connector($c->get('plugin_logic'),
            $c->get('redirects_repository'), $c->get('logging'), $c->get('functions'),
            $c->get('spell_checker'), $c->get('logs_repository'), $c->get('stats_repository'));
    });

    /**
     * Slug change handler - detects and handles post slug changes.
     */
    $container->set('slug_change_handler', function($c) {
        return new ABJ_404_Solution_SlugChangeHandler($c->get('content_repository'),
            $c->get('redirects_repository'), $c->get('logging'), $c->get('plugin_logic'));
    });

    /**
     * Published posts provider - manages published post lookups.
     */
    $container->set('published_posts_provider', function($c) {
        return new ABJ_404_Solution_PublishedPostsProvider($c->get('content_repository'));
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
        return ABJ_404_Solution_RequestContext::getInstance();
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
