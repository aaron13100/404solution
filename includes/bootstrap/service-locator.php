<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../core/PhpErrorLogFallback.php';
require_once __DIR__ . '/../core/ServiceContainer.php';

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
 * `abj_404_solution_init_services()`. A required lookup rehydrates the
 * production graph after tests or early boot clear the container.
 *
 * @param string $name Service identifier
 * @return mixed The service instance
 * @throws ABJ_404_Solution_ServiceNotRegisteredException when the service has
 *         no registered factory.
 *
 * @phpstan-return (
 *     $name is 'functions' ? ABJ_404_Solution_Functions : (
 *     $name is 'request_input_normalizer' ? ABJ_404_Solution_RequestInputNormalizer : (
 *     $name is 'mb_string_adapter' ? ABJ_404_Solution_MbStringAdapter : (
 *     $name is 'regex_helper' ? ABJ_404_Solution_RegexHelper : (
 *     $name is 'url_encoder' ? ABJ_404_Solution_UrlEncoder : (
 *     $name is 'sanitizer' ? ABJ_404_Solution_Sanitizer : (
 *     $name is 'query_string_helper' ? ABJ_404_Solution_QueryStringHelper : (
 *     $name is 'logging' ? ABJ_404_Solution_Logging : (
 *     $name is 'logging_state_store' ? ABJ_404_Solution_LoggingStateStore : (
 *     $name is 'clock' ? ABJ_404_Solution_Clock : (
 *     $name is 'error_handler' ? class-string : (
 *     $name is 'db_core' ? ABJ_404_Solution_DatabaseCore : (
 *     $name is 'content_repository' ? ABJ_404_Solution_ContentRepository : (
 *     $name is 'redirects_repository' ? ABJ_404_Solution_RedirectsRepository : (
 *     $name is 'redirects_retention_service' ? ABJ_404_Solution_RedirectsRetentionService : (
 *     $name is 'redirect_dead_destination_checker' ? ABJ_404_Solution_RedirectDeadDestinationChecker : (
 *     $name is 'logs_repository' ? ABJ_404_Solution_LogsRepository : (
 *     $name is 'stats_repository' ? ABJ_404_Solution_StatsRepository : (
 *     $name is 'internal_source_evidence_repository' ? ABJ_404_Solution_InternalSourceEvidenceRepository : (
 *     $name is 'plugin_update_metadata_repository' ? ABJ_404_Solution_PluginUpdateMetadataRepository : (
 *     $name is 'view_read_service' ? ABJ_404_Solution_ViewReadService : (
 *     $name is 'data_access' ? ABJ_404_Solution_DataAccess : (
 *     $name is 'database_upgrades' ? ABJ_404_Solution_DatabaseUpgradesEtc : (
 *     $name is 'permalink_cache' ? ABJ_404_Solution_PermalinkCache : (
 *     $name is 'ngram_extractor' ? ABJ_404_Solution_NGramExtractor : (
 *     $name is 'ngram_coverage_policy' ? ABJ_404_Solution_NGramCoveragePolicy : (
 *     $name is 'ngram_cache_repository' ? ABJ_404_Solution_NGramCacheRepository : (
 *     $name is 'ngram_filter' ? ABJ_404_Solution_NGramFilter : (
 *     $name is 'plugin_logic' ? ABJ_404_Solution_PluginLogic : (
 *     $name is 'request_ignore_normalizer' ? ABJ_404_Solution_RequestIgnoreNormalizer : (
 *     $name is 'spell_checker' ? ABJ_404_Solution_SpellChecker : (
 *     $name is 'old_permalink_structure_store' ? ABJ_404_Solution_OldPermalinkStructureStore : (
 *     $name is 'old_permalink_structure_resolver' ? ABJ_404_Solution_OldPermalinkStructureResolver : (
 *     $name is 'engine_old_permalink_structure' ? ABJ_404_Solution_OldPermalinkStructureEngine : (
 *     $name is 'engine_slug' ? ABJ_404_Solution_SlugMatchingEngine : (
 *     $name is 'engine_url_fix' ? ABJ_404_Solution_UrlFixEngine : (
 *     $name is 'engine_title' ? ABJ_404_Solution_TitleMatchingEngine : (
 *     $name is 'engine_category_tag' ? ABJ_404_Solution_CategoryTagMatchingEngine : (
 *     $name is 'engine_content' ? ABJ_404_Solution_ContentMatchingEngine : (
 *     $name is 'engine_spelling' ? ABJ_404_Solution_SpellingMatchingEngine : (
 *     $name is 'engine_archive_fallback' ? ABJ_404_Solution_ArchiveFallbackEngine : (
 *     $name is 'matching_engines' ? array<int, object> : (
 *     $name is 'near_miss_recorder' ? ABJ_404_Solution_NearMissRecorder : (
 *     $name is 'wordpress_connector' ? ABJ_404_Solution_WordPress_Connector : (
 *     $name is 'slug_change_handler' ? ABJ_404_Solution_SlugChangeHandler : (
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
 * ))))))))))))))))))))))))))))))))))))))))))))))))))))))))))
 */
function abj_service($name) {
    if ($name === 'logging' && class_exists('ABJ_404_Solution_Logging', false)) {
        $logger = ABJ_404_Solution_Logging::peekInstance();
        if ($logger !== null) {
            ABJ_404_Solution_ServiceContainer::clearLastSuppressedError();
            return $logger;
        }
    }
    if ($name === 'database_upgrades' && class_exists('ABJ_404_Solution_DatabaseUpgradesEtc', false)) {
        $upgrades = ABJ_404_Solution_DatabaseUpgradesEtc::peekInstance();
        if ($upgrades !== null) {
            ABJ_404_Solution_ServiceContainer::clearLastSuppressedError();
            return $upgrades;
        }
    }

    $container = ABJ_404_Solution_ServiceContainer::getInstance();
    if (!$container->has($name) && function_exists('abj_404_solution_init_services')) {
        $container->beginPreservingExistingRegistrations();
        try {
            abj_404_solution_init_services();
        } finally {
            $container->endPreservingExistingRegistrations();
        }
        $container = ABJ_404_Solution_ServiceContainer::getInstance();
    }
    if (!$container->has($name)) {
        ABJ_404_Solution_ServiceContainer::clearLastSuppressedError();
        throw new ABJ_404_Solution_ServiceNotRegisteredException(
            $name,
            'ABJ_404_Solution_RuntimeServiceRegistration'
        );
    }

    $service = $container->get($name);
    ABJ_404_Solution_ServiceContainer::clearLastSuppressedError();
    return $service;
}

/**
 * Optional service lookup for bootstrap probes and degraded-mode fallbacks.
 *
 * Required dependencies must use abj_service() so missing registrations fail
 * with a named service. This helper is only for call sites where null is an
 * explicit, handled outcome.
 *
 * @param string $name Service identifier
 * @return mixed The service instance, or null when unavailable
 *
 * @phpstan-return (
 *     $name is 'logging' ? ABJ_404_Solution_Logging|null : (
 *     $name is 'options_repository' ? ABJ_404_Solution_PluginLogicOptionsResolver|null : (
 *     $name is 'ajax_failure_logger' ? ABJ_404_Solution_AjaxFailureLogger|null : (
 *     $name is 'ajax_security_gate' ? ABJ_404_Solution_AjaxSecurityGate|null : (
 *     $name is 'view' ? ABJ_404_Solution_View|null : (
 *     $name is 'database_upgrades' ? ABJ_404_Solution_DatabaseUpgradesEtc|null : (
 *     $name is 'cron_scheduler' ? ABJ_404_Solution_CronScheduler|null : (
 *     $name is 'cron_recurrence_migration' ? ABJ_404_Solution_CronRecurrenceMigration|null : (
 *     $name is 'clock' ? ABJ_404_Solution_Clock|null : (
 *     $name is 'not_found_response' ? ABJ_404_Solution_NotFoundResponseService|null : (
 *     $name is 'request_ignore_normalizer' ? ABJ_404_Solution_RequestIgnoreNormalizer|null : (
 *     $name is 'previous_request_cookie_tracker' ? ABJ_404_Solution_PreviousRequestCookieTracker|null : (
 *     $name is 'admin_access_policy' ? ABJ_404_Solution_PluginAdminAccessPolicy|null : (
 *     $name is 'pii_redactor' ? ABJ_404_Solution_PiiRedactor|null : (
 *     $name is 'view_read_service' ? ABJ_404_Solution_ViewReadService|null : (
 *     $name is 'rebuild_health' ? ABJ_404_Solution_RebuildHealthState|null :
 *     mixed|null
 * ))))))))))))))))
 */
function abj_service_optional($name) {
    $container = ABJ_404_Solution_ServiceContainer::getInstance();
    if (!$container->has($name)) {
        ABJ_404_Solution_ServiceContainer::clearLastSuppressedError();
        return null;
    }

    try {
        $service = $container->get($name);
        ABJ_404_Solution_ServiceContainer::clearLastSuppressedError();
        return $service;
    } catch (\Throwable $e) {
        ABJ_404_Solution_ServiceContainer::recordSuppressedErrorPublic(
            'abj_service_optional(' . $name . ')',
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
    $scheduler = abj_service_optional('cron_scheduler');
    if ($scheduler instanceof ABJ_404_Solution_CronScheduler) {
        return $scheduler;
    }
    $clock = abj_service_optional('clock');
    $logger = abj_service_optional('logging');
    return new ABJ_404_Solution_CronScheduler(
        $clock instanceof ABJ_404_Solution_Clock ? $clock : new ABJ_404_Solution_SystemClock(),
        $logger instanceof ABJ_404_Solution_Logging ? $logger : null
    );
}

/**
 * Typed accessor for the daily-recurrence cadence policy.
 *
 * @return ABJ_404_Solution_CronRecurrenceMigration
 */
function abj_cron_recurrence_migration(): ABJ_404_Solution_CronRecurrenceMigration {
    $migration = abj_service_optional('cron_recurrence_migration');
    if ($migration instanceof ABJ_404_Solution_CronRecurrenceMigration) {
        return $migration;
    }
    $logger = abj_service_optional('logging');
    return new ABJ_404_Solution_CronRecurrenceMigration(
        abj_cron_scheduler(),
        new ABJ_404_Solution_ScheduledEventInspector(),
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
    $clock = abj_service_optional('clock');
    if ($clock instanceof ABJ_404_Solution_Clock) {
        return $clock;
    }
    return new ABJ_404_Solution_SystemClock();
}
