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
    $rebuildHealth = $container->get('rebuild_health');
    $svc = new ABJ_404_Solution_ViewBuildOrchestrator(
        $dbCore,
        $functions,
        $logging,
        $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState ? $rebuildHealth : null
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

    abj_404_solution_register_core_utilities($container);
    abj_404_solution_register_data_layer($container);
    abj_404_solution_register_business_logic($container);
    abj_404_solution_register_matching_engines($container);
    abj_404_solution_register_frontend_services($container);
    abj_404_solution_register_presentation_layer($container);
}

/**
 * @param ABJ_404_Solution_ServiceContainer $container
 * @return void
 */
function abj_404_solution_register_core_utilities($container) {
    $container->set('functions', function($c) {
        if (extension_loaded('mbstring')) {
            return new ABJ_404_Solution_FunctionsMBString();
        }
        return new ABJ_404_Solution_FunctionsPreg();
    });

    $container->set('pii_redactor', function($c) {
        return new ABJ_404_Solution_PiiRedactor($c->get('functions'));
    });

    $container->set('logging', function($c) {
        return ABJ_404_Solution_Logging::createForContainer();
    });

    $container->set('clock', function($c) {
        return new ABJ_404_Solution_SystemClock();
    });

    $container->set('rebuild_health', function($c) {
        return new ABJ_404_Solution_RebuildHealthState(
            $c->get('clock'),
            $c->get('logging')
        );
    });

    $container->set('error_handler', function($c) {
        return 'ABJ_404_Solution_ErrorHandler';
    });
}

/**
 * @param ABJ_404_Solution_ServiceContainer $container
 * @return void
 */
function abj_404_solution_register_data_layer($container) {
    $container->set('db_core', function($c) {
        return new ABJ_404_Solution_DatabaseCore(
            $c->get('functions'),
            $c->get('logging')
        );
    });

    $daoModuleDeps = function($c) { return [$c->get('db_core'), $c->get('functions'), $c->get('logging')]; };
    $container->set('content_repository', function($c) use ($daoModuleDeps) {
        return new ABJ_404_Solution_ContentRepository(...$daoModuleDeps($c));
    });
    $container->set('redirects_repository', function($c) use ($daoModuleDeps) {
        return new ABJ_404_Solution_RedirectsRepository(...$daoModuleDeps($c));
    });
    $container->set('logs_repository', function($c) {
        return new ABJ_404_Solution_LogsRepository(
            $c->get('db_core'),
            $c->get('functions'),
            $c->get('logging'),
            $c->get('rebuild_health')
        );
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

    $container->set('permalink_cache', function($c) {
        return new ABJ_404_Solution_PermalinkCache($c->get('content_repository'),
            $c->get('logging'), $c->get('plugin_logic'), $c->get('stats_repository'));
    });

    $container->set('ngram_filter', function($c) {
        return new ABJ_404_Solution_NGramFilter($c->get('db_core'), $c->get('logging'), $c->get('functions'));
    });
}

/**
 * @param ABJ_404_Solution_ServiceContainer $container
 * @return void
 */
function abj_404_solution_register_business_logic($container) {
    $container->set('plugin_logic', function($c) {
        return new ABJ_404_Solution_PluginLogic(
            $c->get('functions'),
            $c->get('data_access'),
            $c->get('logging')
        );
    });

    $container->set('spell_checker', function($c) {
        return new ABJ_404_Solution_SpellChecker($c->get('functions'), $c->get('plugin_logic'),
            $c->get('content_repository'), $c->get('logging'), $c->get('permalink_cache'),
            $c->get('ngram_filter'), $c->get('view_read_service'));
    });
}

/**
 * @param ABJ_404_Solution_ServiceContainer $container
 * @return void
 */
function abj_404_solution_register_matching_engines($container) {
    $container->set('engine_slug', function($c) {
        return new ABJ_404_Solution_SlugMatchingEngine($c->get('spell_checker'));
    });

    $container->set('engine_url_fix', function($c) {
        return new ABJ_404_Solution_UrlFixEngine(
            $c->get('spell_checker'),
            $c->get('functions'),
            $c->get('logging')
        );
    });

    $container->set('engine_title', function($c) {
        return new ABJ_404_Solution_TitleMatchingEngine(
            $c->get('content_repository'),
            $c->get('functions'),
            $c->get('logging')
        );
    });

    $container->set('engine_category_tag', function($c) {
        return new ABJ_404_Solution_CategoryTagMatchingEngine(
            $c->get('content_repository'),
            $c->get('functions'),
            $c->get('logging')
        );
    });

    $container->set('engine_content', function($c) {
        return new ABJ_404_Solution_ContentMatchingEngine(
            $c->get('content_repository'),
            $c->get('functions'),
            $c->get('logging')
        );
    });

    $container->set('engine_spelling', function($c) {
        return new ABJ_404_Solution_SpellingMatchingEngine($c->get('spell_checker'));
    });

    $container->set('engine_archive_fallback', function($c) {
        return new ABJ_404_Solution_ArchiveFallbackEngine(
            $c->get('functions'),
            $c->get('logging')
        );
    });

    $container->set('matching_engines', function($c) {
        $engines = [$c->get('engine_slug'), $c->get('engine_url_fix'), $c->get('engine_title'), $c->get('engine_category_tag'), $c->get('engine_content'), $c->get('engine_spelling'), $c->get('engine_archive_fallback')];
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_matching_engines', $engines);
            $engines = is_array($filtered) ? $filtered : [];
        }
        return $engines;
    });
}

/**
 * @param ABJ_404_Solution_ServiceContainer $container
 * @return void
 */
function abj_404_solution_register_frontend_services($container) {
    $container->set('wordpress_connector', function($c) {
        return new ABJ_404_Solution_WordPress_Connector($c->get('plugin_logic'),
            $c->get('redirects_repository'), $c->get('logging'), $c->get('functions'),
            $c->get('spell_checker'), $c->get('logs_repository'), $c->get('stats_repository'));
    });

    $container->set('slug_change_handler', function($c) {
        return new ABJ_404_Solution_SlugChangeHandler($c->get('content_repository'),
            $c->get('redirects_repository'), $c->get('logging'), $c->get('plugin_logic'));
    });

    $container->set('published_posts_provider', function($c) {
        return new ABJ_404_Solution_PublishedPostsProvider($c->get('content_repository'));
    });

    $container->set('sync_utils', function($c) {
        return new ABJ_404_Solution_SynchronizationUtils();
    });

    $container->set('request_context', function($c) {
        return ABJ_404_Solution_RequestContext::getInstance();
    });
}

/**
 * @param ABJ_404_Solution_ServiceContainer $container
 * @return void
 */
function abj_404_solution_register_presentation_layer($container) {
    $container->set('view', function($c) {
        return new ABJ_404_Solution_View(
            $c->get('functions'),
            $c->get('plugin_logic'),
            $c->get('view_read_service'),
            $c->get('logging')
        );
    });

    $container->set('view_suggestions', function($c) {
        return new ABJ_404_Solution_View_Suggestions(
            $c->get('functions')
        );
    });

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

    throw new Exception("Cannot get instance of class: $className"); // allow-raw-error: pre-existing programmer assertion
}
