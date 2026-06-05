<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers frontend runtime, admin presentation, AJAX, and shortcode services.
 */
class ABJ_404_Solution_RuntimeServiceRegistration {

    /**
     * @param ABJ_404_Solution_ServiceContainer $container
     * @return void
     */
    public static function register($container): void {
        $container->set('previous_request_cookie_tracker', function($c) {
            return new ABJ_404_Solution_PreviousRequestCookieTracker(
                $c->get('logging')
            );
        });

        $container->set('not_found_response', function($c) {
            return new ABJ_404_Solution_NotFoundResponseService(
                $c->get('functions'),
                $c->get('redirects_repository'),
                $c->get('logs_repository'),
                $c->get('logging'),
                $c->get('options_repository'),
                $c->get('previous_request_cookie_tracker')
            );
        });

        $container->set('wordpress_connector', function($c) {
            return new ABJ_404_Solution_WordPress_Connector($c->get('plugin_logic'),
                $c->get('redirects_repository'), $c->get('logging'), $c->get('functions'),
                $c->get('spell_checker'), $c->get('logs_repository'), $c->get('stats_repository'));
        });

        $container->set('slug_change_handler', function($c) {
            return new ABJ_404_Solution_SlugChangeHandler($c->get('content_repository'),
                $c->get('redirects_repository'), $c->get('logging'));
        });

        $container->set('published_posts_provider', function($c) {
            return new ABJ_404_Solution_PublishedPostsProvider($c->get('content_repository'));
        });

        $container->set('sync_utils', function($c) {
            return new ABJ_404_Solution_SynchronizationUtils();
        });

        $container->set('request_context', function($c) {
            return new ABJ_404_Solution_RequestContext();
        });

        $container->set('ajax_security_gate', function($c) {
            return new ABJ_404_Solution_AjaxSecurityGate($c->get('admin_access_policy'), $c->get('logging'));
        });

        $container->set('ajax_failure_logger', function($c) {
            return new ABJ_404_Solution_AjaxFailureLogger($c->get('logging'));
        });

        $container->set('view', function($c) {
            return new ABJ_404_Solution_View(
                $c->get('functions'),
                $c->get('plugin_logic'),
                $c->get('view_read_service'),
                $c->get('logging'),
                $c->get('stats_repository')
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
}
