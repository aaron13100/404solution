<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers redirect matching engines and the filterable engine list.
 */
class ABJ_404_Solution_MatchingEngineServiceRegistration {

    /**
     * @param ABJ_404_Solution_ServiceContainer $container
     * @return void
     */
    public static function register($container): void {
        $container->set('old_permalink_structure_store', function($c) {
            return new ABJ_404_Solution_OldPermalinkStructureStore();
        });

        $container->set('old_permalink_structure_resolver', function($c) {
            return new ABJ_404_Solution_OldPermalinkStructureResolver(
                $c->get('old_permalink_structure_store'),
                $c->get('content_repository'),
                $c->get('logging')
            );
        });

        $container->set('engine_old_permalink_structure', function($c) {
            return new ABJ_404_Solution_OldPermalinkStructureEngine(
                $c->get('old_permalink_structure_resolver')
            );
        });

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
                $c->get('logging'),
                $c->get('term_candidate_source')
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
            $engines = [$c->get('engine_old_permalink_structure'), $c->get('engine_slug'), $c->get('engine_url_fix'), $c->get('engine_title'), $c->get('engine_category_tag'), $c->get('engine_content'), $c->get('engine_spelling'), $c->get('engine_archive_fallback')];
            if (function_exists('apply_filters')) {
                $filtered = apply_filters('abj404_matching_engines', $engines);
                $engines = is_array($filtered) ? $filtered : [];
            }
            return $engines;
        });
    }
}
