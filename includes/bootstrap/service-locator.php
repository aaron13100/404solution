<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Global service-locator helper functions.
 *
 * Required from includes/bootstrap.php at production boot and from
 * tests/bootstrap.php during the test run, so abj_service() is defined
 * before any caller invokes it.
 *
 * Heavy fallback resolution lives in ABJ_404_Solution_ServiceFallbackResolver;
 * the container itself lives in ABJ_404_Solution_ServiceContainer.
 */

/**
 * Helper function to access services from the container.
 *
 * This provides a shorter, more convenient syntax than calling
 * ABJ_404_Solution_ServiceContainer::getInstance()->get().
 *
 * Fallback semantics: if the named service is not currently registered
 * (e.g. a test cleared the container without re-running
 * `abj_404_solution_init_services()`), delegates to
 * ABJ_404_Solution_ServiceFallbackResolver, which consults a static
 * name->class map and per-service hand-coded factories before falling
 * back to the legacy `ClassName::getInstance()` singleton. This
 * preserves test patterns that predate the c260 codemod (clear
 * container, register only the mocks the test cares about) without
 * forcing every test to re-init the entire service graph. Production
 * code paths are unaffected because services are always registered at
 * boot via `abj_404_solution_init_services()`; the lint at
 * `scripts/lint/lint-getinstance-callers.sh` enforces that production
 * callers must use this helper rather than `getInstance()` directly.
 *
 * @param string $name Service identifier
 * @return mixed The service instance
 *
 * @phpstan-return (
 *     $name is 'functions' ? ABJ_404_Solution_Functions : (
 *     $name is 'mb_string_adapter' ? ABJ_404_Solution_MbStringAdapter : (
 *     $name is 'url_encoder' ? ABJ_404_Solution_UrlEncoder : (
 *     $name is 'sanitizer' ? ABJ_404_Solution_Sanitizer : (
 *     $name is 'query_string_helper' ? ABJ_404_Solution_QueryStringHelper : (
 *     $name is 'logging' ? ABJ_404_Solution_Logging : (
 *     $name is 'clock' ? ABJ_404_Solution_Clock : (
 *     $name is 'error_handler' ? class-string : (
 *     $name is 'db_core' ? ABJ_404_Solution_DatabaseCore : (
 *     $name is 'content_repository' ? ABJ_404_Solution_ContentRepository : (
 *     $name is 'redirects_repository' ? ABJ_404_Solution_RedirectsRepository : (
 *     $name is 'redirects_retention_service' ? ABJ_404_Solution_RedirectsRetentionService : (
 *     $name is 'logs_repository' ? ABJ_404_Solution_LogsRepository : (
 *     $name is 'stats_repository' ? ABJ_404_Solution_StatsRepository : (
 *     $name is 'plugin_update_metadata_repository' ? ABJ_404_Solution_PluginUpdateMetadataRepository : (
 *     $name is 'view_read_service' ? ABJ_404_Solution_ViewReadService : (
 *     $name is 'view_build_orchestrator' ? ABJ_404_Solution_ViewBuildOrchestrator : (
 *     $name is 'data_access' ? ABJ_404_Solution_DataAccess : (
 *     $name is 'database_upgrades' ? ABJ_404_Solution_DatabaseUpgradesEtc : (
 *     $name is 'permalink_cache' ? ABJ_404_Solution_PermalinkCache : (
 *     $name is 'ngram_filter' ? ABJ_404_Solution_NGramFilter : (
 *     $name is 'plugin_logic' ? ABJ_404_Solution_PluginLogic : (
 *     $name is 'request_ignore_normalizer' ? ABJ_404_Solution_RequestIgnoreNormalizer : (
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
 *     $name is 'previous_request_cookie_tracker' ? ABJ_404_Solution_PreviousRequestCookieTracker : (
 *     $name is 'view' ? ABJ_404_Solution_View : (
 *     $name is 'view_suggestions' ? ABJ_404_Solution_View_Suggestions : (
 *     $name is 'shortcode' ? ABJ_404_Solution_ShortCode : (
 *     $name is 'ajax_security_gate' ? ABJ_404_Solution_AjaxSecurityGate : (
 *     $name is 'ajax_failure_logger' ? ABJ_404_Solution_AjaxFailureLogger : (
 *     $name is 'version_upgrade' ? ABJ_404_Solution_PluginLogicVersionUpgrader : (
 *     $name is 'options_repository' ? ABJ_404_Solution_PluginLogicOptionsResolver : (
 *     $name is 'admin_access_policy' ? ABJ_404_Solution_PluginAdminAccessPolicy : (
 *     $name is 'settings_mode_preference' ? ABJ_404_Solution_SettingsModePreference : (
 *     $name is 'not_found_response' ? ABJ_404_Solution_NotFoundResponseService :
 *     mixed
 * ))))))))))))))))))))))))))))))))))))))))))))))))
 */
function abj_service($name) {
    // Honor a singleton override on each peekInstance-exposing singleton
    // class before consulting the container. Without this, the container
    // caches its freshly-built default and any subsequent override (set via
    // reflection or direct assignment) is silently ignored, even though
    // each class's own getInstance() respects $instance.
    $override = ABJ_404_Solution_ServiceFallbackResolver::singletonOverride($name);
    if ($override !== null) {
        return $override;
    }
    $container = ABJ_404_Solution_ServiceContainer::getInstance();
    if ($container->has($name)) {
        return $container->get($name);
    }
    return ABJ_404_Solution_ServiceFallbackResolver::resolve($name, $container);
}

/**
 * Typed accessor for the WP-Cron scheduler service.
 *
 * @return ABJ_404_Solution_CronScheduler
 */
function abj_cron_scheduler(): ABJ_404_Solution_CronScheduler {
    $scheduler = abj_service('cron_scheduler');
    if ($scheduler instanceof ABJ_404_Solution_CronScheduler) {
        return $scheduler;
    }
    $clock = abj_service('clock');
    $logger = abj_service('logging');
    return new ABJ_404_Solution_CronScheduler(
        $clock instanceof ABJ_404_Solution_Clock ? $clock : new ABJ_404_Solution_SystemClock(),
        $logger instanceof ABJ_404_Solution_Logging ? $logger : null
    );
}

/**
 * Typed accessor for the project clock service.
 *
 * Production resolves the container's SystemClock; tests can bind a
 * FrozenClock to the same service and drive time-sensitive behavior without
 * direct wall-clock calls.
 *
 * @return ABJ_404_Solution_Clock
 */
function abj_clock(): ABJ_404_Solution_Clock {
    $clock = abj_service('clock');
    if ($clock instanceof ABJ_404_Solution_Clock) {
        return $clock;
    }
    return new ABJ_404_Solution_SystemClock();
}

/**
 * Return the singleton-installed instance for a given service name when
 * one is set, else null. Exists so abj_service() can route around the
 * container cache for services that expose a `peekInstance()` reflection
 * seam. Thin wrapper over the resolver class; preserved as a global
 * function for backward compatibility with existing call sites.
 *
 * @param string $name
 * @return mixed
 */
function abj_service_singleton_override($name) {
    return ABJ_404_Solution_ServiceFallbackResolver::singletonOverride($name);
}
