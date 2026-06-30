<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authoritative service-name contract for container registration.
 *
 * Production bootstrap and test bootstrap both implement this contract so
 * required `abj_service()` lookups stay visible as one list instead of
 * drifting between runtime factories and PHPUnit defaults.
 */
interface ABJ_404_Solution_Abj404ServiceRegistrationContract {

    public const CORE_SERVICE_NAMES = array(
        'mb_string_adapter',
        'regex_helper',
        'functions',
        'pii_redactor',
        'url_encoder',
        'sanitizer',
        'query_string_helper',
        'logging',
        'clock',
        'cron_scheduler',
        'rebuild_health',
        'error_handler',
    );

    public const DATA_SERVICE_NAMES = array(
        'db_core',
        'content_repository',
        'redirects_repository',
        'redirects_retention_service',
        'redirect_dead_destination_checker',
        'logs_repository',
        'stats_repository',
        'plugin_update_metadata_repository',
        'view_read_service',
        'data_access',
        'database_upgrades',
        'permalink_cache',
        'ngram_extractor',
        'ngram_similarity',
        'ngram_coverage_policy',
        'ngram_cache_repository',
        'ngram_usage_telemetry',
        'ngram_rebuilder',
        'ngram_filter',
    );

    public const DOMAIN_SERVICE_NAMES = array(
        'plugin_logic',
        'request_ignore_normalizer',
        'version_upgrade',
        'spell_checker',
        'options_repository',
        'logging_state_store',
        'admin_access_policy',
        'settings_mode_preference',
    );

    public const MATCHING_ENGINE_SERVICE_NAMES = array(
        'old_permalink_structure_store',
        'old_permalink_structure_resolver',
        'engine_old_permalink_structure',
        'engine_slug',
        'engine_url_fix',
        'engine_title',
        'engine_category_tag',
        'engine_content',
        'engine_spelling',
        'engine_archive_fallback',
        'matching_engines',
    );

    public const RUNTIME_SERVICE_NAMES = array(
        'previous_request_cookie_tracker',
        'not_found_response',
        'wordpress_connector',
        'slug_change_handler',
        'sync_utils',
        'request_context',
        'ajax_security_gate',
        'ajax_failure_logger',
        'view',
        'view_suggestions',
        'shortcode',
    );

    public const SERVICE_NAMES = array(
        'mb_string_adapter',
        'regex_helper',
        'functions',
        'pii_redactor',
        'url_encoder',
        'sanitizer',
        'query_string_helper',
        'logging',
        'clock',
        'cron_scheduler',
        'rebuild_health',
        'error_handler',
        'db_core',
        'content_repository',
        'redirects_repository',
        'redirects_retention_service',
        'redirect_dead_destination_checker',
        'logs_repository',
        'stats_repository',
        'plugin_update_metadata_repository',
        'view_read_service',
        'data_access',
        'database_upgrades',
        'permalink_cache',
        'ngram_extractor',
        'ngram_similarity',
        'ngram_coverage_policy',
        'ngram_cache_repository',
        'ngram_usage_telemetry',
        'ngram_rebuilder',
        'ngram_filter',
        'plugin_logic',
        'request_ignore_normalizer',
        'version_upgrade',
        'spell_checker',
        'options_repository',
        'logging_state_store',
        'admin_access_policy',
        'settings_mode_preference',
        'old_permalink_structure_store',
        'old_permalink_structure_resolver',
        'engine_old_permalink_structure',
        'engine_slug',
        'engine_url_fix',
        'engine_title',
        'engine_category_tag',
        'engine_content',
        'engine_spelling',
        'engine_archive_fallback',
        'matching_engines',
        'previous_request_cookie_tracker',
        'not_found_response',
        'wordpress_connector',
        'slug_change_handler',
        'sync_utils',
        'request_context',
        'ajax_security_gate',
        'ajax_failure_logger',
        'view',
        'view_suggestions',
        'shortcode',
    );

    /**
     * @return string[]
     */
    public static function serviceNames(): array;
}
