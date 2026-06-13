<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../core/PhpErrorLogFallback.php';

/**
 * Global service-locator helper functions.
 *
 * Required from includes/bootstrap.php at production boot and from
 * tests/bootstrap.php during the test run, so abj_service() is defined
 * before any caller invokes it.
 *
 * The container itself lives in ABJ_404_Solution_ServiceContainer.
 */

/**
 * Helper function to access services from the container.
 *
 * This provides a shorter, more convenient syntax than calling
 * ABJ_404_Solution_ServiceContainer::getInstance()->get().
 *
 * The production bootstrap registers every service via
 * `abj_404_solution_init_services()`. Tests that clear the container must
 * register the collaborators they need explicitly.
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
    $container = ABJ_404_Solution_ServiceContainer::getInstance();
    try {
        $service = $container->get($name);
        ABJ_404_Solution_ServiceContainer::clearLastSuppressedError();
        return $service;
    } catch (\Throwable $e) {
        ABJ_404_Solution_ServiceContainer::recordSuppressedErrorPublic(
            'abj_service(' . $name . ')',
            $e
        );
        return null;
    }
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
