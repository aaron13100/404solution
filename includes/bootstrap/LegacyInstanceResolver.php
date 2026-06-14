<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves legacy class-name lookups against the service container.
 */
class ABJ_404_Solution_LegacyInstanceResolver {

    /**
     * @param string $className The class name to get an instance of
     * @return mixed The service instance
     * @throws Exception when no service or singleton fallback can resolve the class
     */
    public static function resolve($className) {
        $container = ABJ_404_Solution_ServiceContainer::getInstance();

        // Map class names to service names.
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
            // PublishedPostsProvider is intentionally not a shared service; its
            // getInstance() builds a fresh instance, so legacy resolution falls
            // through to that below rather than a container lookup.
            'ABJ_404_Solution_SynchronizationUtils' => 'sync_utils',
            'ABJ_404_Solution_View_Suggestions' => 'view_suggestions',
            'ABJ_404_Solution_ShortCode' => 'shortcode',
            'ABJ_404_Solution_RequestContext' => 'request_context',
            'ABJ_404_Solution_NotFoundResponseService' => 'not_found_response',
            'ABJ_404_Solution_PreviousRequestCookieTracker' => 'previous_request_cookie_tracker',
            'ABJ_404_Solution_RequestIgnoreNormalizer' => 'request_ignore_normalizer',
        );

        if (isset($serviceMap[$className])) {
            return $container->get($serviceMap[$className]);
        }

        // Fallback to calling the class's getInstance() method.
        if (method_exists($className, 'getInstance')) {
            /** @var callable(): mixed $callback */
            $callback = array($className, 'getInstance');
            return call_user_func($callback);
        }

        throw new Exception("Cannot get instance of class: $className"); // allow-raw-error: pre-existing programmer assertion
    }
}
