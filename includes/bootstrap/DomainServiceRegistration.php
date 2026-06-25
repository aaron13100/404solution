<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers plugin-domain services, option access, and admin access policy.
 */
class ABJ_404_Solution_DomainServiceRegistration {

    /**
     * @param ABJ_404_Solution_ServiceContainer $container
     * @return void
     */
    public static function register($container): void {
        $container->set('plugin_logic', function($c) {
            if (class_exists('ABJ_404_Solution_PluginLogic', false)) {
                $peeked = ABJ_404_Solution_PluginLogic::peekInstance();
                if ($peeked !== null) {
                    return $peeked;
                }
            }
            // Use abj_service() for data_access and logging so a caller that
            // installed a singleton override via reflection wins over the
            // container's freshly-built default. The other deps remain
            // container-resolved.
            return new ABJ_404_Solution_PluginLogic(
                $c->get('functions'),
                abj_service('data_access'),
                abj_service('logging'),
                $c->get('stats_repository')
            );
        });

        $container->set('request_ignore_normalizer', function($c) {
            return new ABJ_404_Solution_RequestIgnoreNormalizer(
                new ABJ_404_Solution_RequestIgnoreNormalizerDependencies(
                    $c->get('options_repository'),
                    $c->get('functions'),
                    $c->get('logging'),
                    $c->get('redirects_repository'),
                    $c->get('logs_repository'),
                    $c->get('not_found_response')
                )
            );
        });

        $container->set('version_upgrade', function($c) {
            return new ABJ_404_Solution_PluginLogicVersionUpgrader(
                $c->get('functions'),
                $c->get('logging'),
                $c->get('db_core')
            );
        });

        $container->set('spell_checker', function($c) {
            // Honor a test-installed singleton override (via reflection /
            // setInstance) the same way the plugin_logic factory above does.
            // Without this peek, abj_service('spell_checker') always builds a
            // fresh real SpellChecker and silently ignores a swapped-in double,
            // so handlers that resolve through the container never hit the test's
            // findMatchingPosts seam.
            if (class_exists('ABJ_404_Solution_SpellChecker', false)) {
                $peeked = ABJ_404_Solution_SpellChecker::peekInstance();
                if ($peeked !== null) {
                    return $peeked;
                }
            }
            return new ABJ_404_Solution_SpellChecker(new ABJ_404_Solution_SpellCheckerDependencies(
                $c->get('functions'), $c->get('plugin_logic'),
                $c->get('content_repository'), $c->get('logging'), $c->get('permalink_cache'),
                $c->get('ngram_filter'), $c->get('view_read_service')));
        });

        $container->set('options_repository', function($c) {
            return new ABJ_404_Solution_PluginLogicOptionsResolver();
        });

        $container->set('logging_state_store', function($c) {
            return new ABJ_404_Solution_LoggingStateStore($c->get('options_repository'));
        });

        $container->set('admin_access_policy', function($c) {
            return new ABJ_404_Solution_PluginAdminAccessPolicy(
                $c->get('options_repository'),
                $c->get('functions'),
                $c->get('logging')
            );
        });

        $container->set('settings_mode_preference', function($c) {
            return new ABJ_404_Solution_SettingsModePreference();
        });
    }
}
