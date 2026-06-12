<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers low-level infrastructure services used by all later bootstrap layers.
 */
class ABJ_404_Solution_CoreServiceRegistration {

    /**
     * @param ABJ_404_Solution_ServiceContainer $container
     * @return void
     */
    public static function register($container): void {
        $container->set('mb_string_adapter', function($c) {
            if (extension_loaded('mbstring')) {
                return ABJ_404_Solution_MbStringAdapterMb::getInstance();
            }
            return ABJ_404_Solution_MbStringAdapterPreg::getInstance();
        });

        $container->set('regex_helper', function($c) {
            if (extension_loaded('mbstring')) {
                return ABJ_404_Solution_RegexHelperMb::getInstance();
            }
            return ABJ_404_Solution_RegexHelperPreg::getInstance();
        });

        $container->set('functions', function($c) {
            return new ABJ_404_Solution_Functions(
                $c->get('logging'),
                $c->get('request_context'),
                $c->get('mb_string_adapter'),
                $c->get('regex_helper')
            );
        });

        $container->set('pii_redactor', function($c) {
            return new ABJ_404_Solution_PiiRedactor($c->get('functions'));
        });

        $container->set('url_encoder', function($c) {
            return new ABJ_404_Solution_UrlEncoder($c->get('mb_string_adapter'), $c->get('regex_helper'));
        });

        $container->set('sanitizer', function($c) {
            return new ABJ_404_Solution_Sanitizer($c->get('mb_string_adapter'));
        });

        $container->set('query_string_helper', function($c) {
            return new ABJ_404_Solution_QueryStringHelper($c->get('sanitizer'), $c->get('logging'));
        });

        $container->set('logging', function($c) {
            return ABJ_404_Solution_Logging::createForContainer();
        });

        $container->set('clock', function($c) {
            return new ABJ_404_Solution_SystemClock();
        });

        $container->set('cron_scheduler', function($c) {
            return new ABJ_404_Solution_CronScheduler(
                $c->get('clock'),
                $c->get('logging')
            );
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
}
