<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers frontend runtime, admin presentation, AJAX, and shortcode services.
 */
class ABJ_404_Solution_RuntimeServiceRegistration implements ABJ_404_Solution_Abj404ServiceRegistrationContract {

    /** @return string[] */
    public static function serviceNames(): array {
        return ABJ_404_Solution_Abj404ServiceRegistrationContract::SERVICE_NAMES;
    }

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
                new ABJ_404_Solution_NotFoundResponseDependencies(
                    $c->get('functions'),
                    $c->get('redirects_repository'),
                    $c->get('logs_repository'),
                    $c->get('logging'),
                    $c->get('options_repository'),
                    $c->get('previous_request_cookie_tracker')
                )
            );
        });

        $container->set('wordpress_connector', function($c) {
            return new ABJ_404_Solution_WordPress_Connector(
                new ABJ_404_Solution_WordPressConnectorDependencies($c->get('plugin_logic'),
                    $c->get('redirects_repository'), $c->get('logging'), $c->get('functions'),
                    $c->get('spell_checker'), $c->get('logs_repository'), $c->get('stats_repository')));
        });

        $container->set('slug_change_handler', function($c) {
            return new ABJ_404_Solution_SlugChangeHandler($c->get('content_repository'),
                $c->get('redirects_repository'), $c->get('logging'));
        });

        // 'published_posts_provider' is intentionally NOT registered as a shared
        // service. Its only consumer (SpellPostListeners) builds a fresh provider
        // from the content repository it was injected with, because the provider
        // is stateful (batch cursor, restricted ids) and must scan the caller's
        // repository, not a globally-shared one. See
        // ABJ_404_Solution_SpellPostListeners::initializePublishedPostsProvider.

        $container->set('sync_utils', function($c) {
            return new ABJ_404_Solution_SynchronizationUtils();
        });

        $container->set('request_context', function($c) {
            // Reuse the per-process singleton so callers that reach the
            // context via the class accessor and callers that reach it via
            // this factory share the same intra-request message bus.
            // Producing a fresh instance here forks request-scoped state
            // between the two paths.
            return ABJ_404_Solution_RequestContext::resolveForContainer();
        });

        $container->set('ajax_security_gate', function($c) {
            // Build with no captured dependencies: the gate resolves
            // admin_access_policy and logging from the container lazily on each
            // authorization. The container caches this gate instance, so
            // capturing those services here would pin whatever was registered
            // at first resolution and ignore any later re-registration.
            return self::buildAjaxSecurityGate();
        });

        $container->set('ajax_failure_logger', function($c) {
            return self::buildAjaxFailureLogger(abj_service('logging'));
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

    /**
     * Build the AJAX security gate. With no arguments the gate resolves
     * admin_access_policy and logging lazily from the container on each
     * authorization (the robust default). Explicit dependencies may still be
     * passed by callers that want a fixed wiring.
     *
     * @param object|null|string $adminAccessPolicy Service exposing isPluginAdmin().
     * @param object|null|string $logging Service exposing infoMessage().
     * @return ABJ_404_Solution_AjaxSecurityGate
     */
    private static function buildAjaxSecurityGate(
        $adminAccessPolicy = ABJ_404_Solution_AjaxSecurityGate::RESOLVE_FROM_CONTAINER,
        $logging = ABJ_404_Solution_AjaxSecurityGate::RESOLVE_FROM_CONTAINER
    ) {
        return new ABJ_404_Solution_AjaxSecurityGate($adminAccessPolicy, $logging);
    }

    /**
     * @param object|null $logging Service exposing writeLineToDebugFile().
     * @return ABJ_404_Solution_AjaxFailureLogger
     */
    private static function buildAjaxFailureLogger($logging) {
        return new ABJ_404_Solution_AjaxFailureLogger($logging);
    }
}
