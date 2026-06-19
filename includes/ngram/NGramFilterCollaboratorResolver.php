<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves NGramFilter's collaborators using a three-tier policy: prefer an
 * explicitly-passed instance, then a container-registered service, then a
 * direct construction for unit-test contexts that load NGramFilter standalone
 * without bootstrap.
 *
 * Extracted from NGramFilter so the orchestration (candidate selection) and the
 * construction-time wiring policy are independently readable. The new
 * collaborators are pure composition over dbCore/logger/functions, so direct
 * construction is equivalent to the container instance.
 */
class ABJ_404_Solution_NGramFilterCollaboratorResolver {

    /**
     * @param mixed $explicit
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @return ABJ_404_Solution_NGramExtractor
     */
    public static function resolveExtractor($explicit, $f, $logger) {
        if ($explicit instanceof ABJ_404_Solution_NGramExtractor) {
            return $explicit;
        }
        $resolved = self::resolveFromContainer('ngram_extractor');
        if ($resolved instanceof ABJ_404_Solution_NGramExtractor) {
            return $resolved;
        }
        return new ABJ_404_Solution_NGramExtractor($f, $logger);
    }

    /**
     * @param mixed $explicit
     * @return ABJ_404_Solution_NGramSimilarity
     */
    public static function resolveSimilarity($explicit) {
        if ($explicit instanceof ABJ_404_Solution_NGramSimilarity) {
            return $explicit;
        }
        $resolved = self::resolveFromContainer('ngram_similarity');
        if ($resolved instanceof ABJ_404_Solution_NGramSimilarity) {
            return $resolved;
        }
        return new ABJ_404_Solution_NGramSimilarity();
    }

    /**
     * @param mixed $explicit
     * @param mixed $dbCore
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_NGramSimilarity $similarity
     * @return ABJ_404_Solution_NGramCacheRepository
     */
    public static function resolveRepo($explicit, $dbCore, $logger, $similarity) {
        if ($explicit instanceof ABJ_404_Solution_NGramCacheRepository) {
            return $explicit;
        }
        $resolved = self::resolveFromContainer('ngram_cache_repository');
        if ($resolved instanceof ABJ_404_Solution_NGramCacheRepository) {
            return $resolved;
        }
        $dbCoreTyped = $dbCore instanceof ABJ_404_Solution_DatabaseCore ? $dbCore : null;
        return new ABJ_404_Solution_NGramCacheRepository($dbCoreTyped, $logger, $similarity, null);
    }

    /**
     * @param mixed $explicit
     * @param mixed $dbCore
     * @return ABJ_404_Solution_NGramCoveragePolicy
     */
    public static function resolveCoveragePolicy($explicit, $dbCore) {
        if ($explicit instanceof ABJ_404_Solution_NGramCoveragePolicy) {
            return $explicit;
        }
        $resolved = self::resolveFromContainer('ngram_coverage_policy');
        if ($resolved instanceof ABJ_404_Solution_NGramCoveragePolicy) {
            return $resolved;
        }
        $dbCoreTyped = $dbCore instanceof ABJ_404_Solution_DatabaseCore ? $dbCore : null;
        return new ABJ_404_Solution_NGramCoveragePolicy($dbCoreTyped);
    }

    /**
     * @param mixed $explicit
     * @return ABJ_404_Solution_NGramUsageTelemetry
     */
    public static function resolveTelemetry($explicit) {
        if ($explicit instanceof ABJ_404_Solution_NGramUsageTelemetry) {
            return $explicit;
        }
        $resolved = self::resolveFromContainer('ngram_usage_telemetry');
        if ($resolved instanceof ABJ_404_Solution_NGramUsageTelemetry) {
            return $resolved;
        }
        return new ABJ_404_Solution_NGramUsageTelemetry();
    }

    /**
     * Silent container lookup: skips abj_service()'s error_log fallback.
     *
     * @param string $serviceName
     * @return mixed
     */
    private static function resolveFromContainer($serviceName) {
        if (!class_exists('ABJ_404_Solution_ServiceContainer')) {
            return null;
        }
        $container = ABJ_404_Solution_ServiceContainer::getInstance();
        if (!$container->has($serviceName)) {
            return null;
        }
        return $container->get($serviceName);
    }
}
