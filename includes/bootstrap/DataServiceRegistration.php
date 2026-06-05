<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers persistence, view-data, upgrade, cache, and ngram data services.
 */
class ABJ_404_Solution_DataServiceRegistration {

    /**
     * @param ABJ_404_Solution_ServiceContainer $container
     * @return void
     */
    public static function register($container): void {
        $container->set('db_core', function($c) {
            return new ABJ_404_Solution_DatabaseCore(
                $c->get('functions'),
                $c->get('logging')
            );
        });

        $daoModuleDeps = function($c) { return [$c->get('db_core'), $c->get('functions'), $c->get('logging')]; };
        $container->set('content_repository', function($c) use ($daoModuleDeps) {
            return new ABJ_404_Solution_ContentRepository(...$daoModuleDeps($c));
        });
        $container->set('redirects_repository', function($c) use ($daoModuleDeps) {
            return new ABJ_404_Solution_RedirectsRepository(...$daoModuleDeps($c));
        });
        $container->set('redirects_retention_service', function($c) {
            return new ABJ_404_Solution_RedirectsRetentionService(
                $c->get('db_core'),
                $c->get('redirects_repository'),
                $c->get('functions'),
                $c->get('logging')
            );
        });
        $container->set('logs_repository', function($c) {
            $rollup = new ABJ_404_Solution_LogsHitsRollupService(
                $c->get('db_core'),
                $c->get('logging'),
                $c->get('rebuild_health')
            );
            return new ABJ_404_Solution_LogsRepository(
                $c->get('db_core'),
                $c->get('functions'),
                $c->get('logging'),
                $c->get('rebuild_health'),
                $rollup
            );
        });
        $container->set('stats_repository', function($c) {
            return new ABJ_404_Solution_StatsRepository(
                $c->get('db_core'), $c->get('logs_repository'),
                $c->get('functions'), $c->get('logging')
            );
        });
        $container->set('plugin_update_metadata_repository', function($c) {
            return new ABJ_404_Solution_PluginUpdateMetadataRepository(
                $c->get('db_core'), $c->get('functions'), $c->get('logging')
            );
        });
        $container->set('view_read_service', function($c) use ($daoModuleDeps) {
            return self::createViewReadService($c, $daoModuleDeps);
        });
        $container->set('view_build_orchestrator', function($c) {
            return self::createViewBuildOrchestrator($c);
        });
        $container->set('data_access', function($c) {
            return new ABJ_404_Solution_DataAccess($c->get('functions'), $c->get('logging'), $c->get('db_core'),
                $c->get('content_repository'), $c->get('redirects_repository'),
                $c->get('logs_repository'), $c->get('stats_repository'), $c->get('view_read_service'), $c->get('view_build_orchestrator'));
        });

        $container->set('database_upgrades', function($c) {
            return new ABJ_404_Solution_DatabaseUpgradesEtc(
                $c->get('data_access'),
                $c->get('logging'),
                $c->get('functions'),
                $c->get('permalink_cache'),
                $c->get('sync_utils'),
                $c->get('plugin_logic'),
                $c->get('ngram_filter'),
                $c->get('ngram_extractor'),
                $c->get('ngram_cache_repository'),
                $c->get('ngram_coverage_policy'),
                $c->get('ngram_rebuilder')
            );
        });

        $container->set('permalink_cache', function($c) {
            return new ABJ_404_Solution_PermalinkCache($c->get('content_repository'),
                $c->get('logging'), $c->get('stats_repository'));
        });

        $container->set('ngram_extractor', function($c) {
            return new ABJ_404_Solution_NGramExtractor($c->get('functions'), $c->get('logging'));
        });

        $container->set('ngram_similarity', function($c) {
            return new ABJ_404_Solution_NGramSimilarity();
        });

        $container->set('ngram_coverage_policy', function($c) {
            return new ABJ_404_Solution_NGramCoveragePolicy($c->get('db_core'));
        });

        $container->set('ngram_cache_repository', function($c) {
            return new ABJ_404_Solution_NGramCacheRepository(
                $c->get('db_core'),
                $c->get('logging'),
                $c->get('ngram_similarity'),
                // Lazy resolver: the policy invalidation hook fires on write but the
                // policy itself doesn't exist when the repo is constructed if both
                // are wired in the same pass. Resolve on demand.
                function() use ($c) { return $c->get('ngram_coverage_policy'); }
            );
        });

        $container->set('ngram_usage_telemetry', function($c) {
            return new ABJ_404_Solution_NGramUsageTelemetry();
        });

        $container->set('ngram_rebuilder', function($c) {
            return new ABJ_404_Solution_NGramRebuilder(
                $c->get('db_core'),
                $c->get('logging'),
                $c->get('functions'),
                $c->get('ngram_extractor'),
                $c->get('ngram_cache_repository'),
                $c->get('ngram_coverage_policy')
            );
        });

        $container->set('ngram_filter', function($c) {
            return new ABJ_404_Solution_NGramFilter(
                $c->get('db_core'),
                $c->get('logging'),
                $c->get('functions'),
                $c->get('ngram_extractor'),
                $c->get('ngram_similarity'),
                $c->get('ngram_cache_repository'),
                $c->get('ngram_coverage_policy'),
                $c->get('ngram_usage_telemetry')
            );
        });
    }

    /**
     * @param ABJ_404_Solution_ServiceContainer $container
     * @param callable $daoModuleDeps
     * @return ABJ_404_Solution_ViewReadService
     */
    private static function createViewReadService($container, $daoModuleDeps) {
        /** @var array{0: ABJ_404_Solution_DatabaseCore, 1: ABJ_404_Solution_Functions, 2: ABJ_404_Solution_Logging} $d */
        $d = $daoModuleDeps($container);
        /** @var ABJ_404_Solution_LogsRepository $logsRepository */
        $logsRepository = $container->get('logs_repository');
        /** @var ABJ_404_Solution_RedirectsRepository $redirectsRepository */
        $redirectsRepository = $container->get('redirects_repository');
        return new ABJ_404_Solution_ViewReadService(
            $d[0], $logsRepository, $redirectsRepository, $d[1], $d[2]
        );
    }

    /**
     * @param ABJ_404_Solution_ServiceContainer $container
     * @return ABJ_404_Solution_ViewBuildOrchestrator
     */
    private static function createViewBuildOrchestrator($container) {
        /** @var ABJ_404_Solution_DatabaseCore $dbCore */
        $dbCore = $container->get('db_core');
        /** @var ABJ_404_Solution_Functions $functions */
        $functions = $container->get('functions');
        /** @var ABJ_404_Solution_Logging $logging */
        $logging = $container->get('logging');
        /** @var ABJ_404_Solution_ViewReadService $viewReadService */
        $viewReadService = $container->get('view_read_service');
        /** @var ABJ_404_Solution_LogsRepository $logsRepository */
        $logsRepository = $container->get('logs_repository');
        $rebuildHealth = $container->get('rebuild_health');
        $svc = new ABJ_404_Solution_ViewBuildOrchestrator(
            $dbCore,
            $functions,
            $logging,
            $rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState ? $rebuildHealth : null
        );
        $svc->setViewReadService($viewReadService);
        $svc->setLogsRepository($logsRepository);
        $viewReadService->setViewBuildOrchestrator($svc);
        return $svc;
    }
}
