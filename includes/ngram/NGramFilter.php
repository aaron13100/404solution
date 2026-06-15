<?php


if (!defined('ABSPATH')) {
    exit;
}

// Backward-compat: legacy tests/code do `require_once NGramFilter.php`
// and expect every collaborator class to be available.
require_once __DIR__ . '/NGramExtractor.php';
require_once __DIR__ . '/NGramSimilarity.php';
require_once __DIR__ . '/NGramCacheRepository.php';
require_once __DIR__ . '/NGramCoveragePolicy.php';
require_once __DIR__ . '/NGramRebuilder.php';
require_once __DIR__ . '/NGramUsageTelemetry.php';

/**
 * Candidate selection orchestrator: maps a 404 URL to a ranked subset of
 * cached pages worth Levenshtein-comparing.
 *
 * After i804 this class composes single-responsibility collaborators rather
 * than holding their logic inline. The orchestration shape here is:
 *
 *  1. Extractor: char N-grams from the 404 URL.
 *  2. CoveragePolicy: gates whether the cache is trustworthy this request.
 *  3. CacheRepository: range-filtered candidate load (or full load for
 *     small caches).
 *  4. Similarity: Dice coefficient per candidate.
 *  5. Sort + cap.
 *  6. UsageTelemetry: rolling counters for admin diagnostics.
 *
 * Service name remains `ngram_filter` for backward compatibility; production
 * callers that only need a specific collaborator (e.g. ContentRepository
 * invalidating coverage caches) wire to the new services directly.
 */
class ABJ_404_Solution_NGramFilter {

    // Constants forwarded from the new collaborators for backward compat
    // with callers (and tests) that reference NGramFilter::CONST_NAME.
    const COVERAGE_RATIO_CACHE_TTL = ABJ_404_Solution_NGramCoveragePolicy::COVERAGE_RATIO_CACHE_TTL;
    const COVERAGE_VERSION_TTL = ABJ_404_Solution_NGramCoveragePolicy::COVERAGE_VERSION_TTL;
    const COVERAGE_VERSION_KEY = ABJ_404_Solution_NGramCoveragePolicy::COVERAGE_VERSION_KEY;
    const COVERAGE_RATIO_KEY = ABJ_404_Solution_NGramCoveragePolicy::COVERAGE_RATIO_KEY;
    const CACHE_LOAD_LIMIT = ABJ_404_Solution_NGramCacheRepository::CACHE_LOAD_LIMIT;

    /** @var self|null */
    private static $instance = null;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_NGramExtractor */
    private $extractor;

    /** @var ABJ_404_Solution_NGramSimilarity */
    private $similarity;

    /** @var ABJ_404_Solution_NGramCacheRepository */
    private $repo;

    /** @var ABJ_404_Solution_NGramCoveragePolicy */
    private $coveragePolicy;

    /** @var ABJ_404_Solution_NGramUsageTelemetry */
    private $telemetry;

    /**
     * Constructor with dependency injection.
     *
     * Legacy three-arg form (dbCore, logging, functions) is preserved so
     * pre-i804 ServiceContainer wiring and test fixtures don't break; in
     * that mode the collaborators are resolved from abj_service(). Tests
     * needing isolation pass all six explicitly.
     *
     * @param ABJ_404_Solution_DatabaseCore|null $dbCore Legacy; unused if all collaborators are passed.
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_NGramExtractor|null $extractor
     * @param ABJ_404_Solution_NGramSimilarity|null $similarity
     * @param ABJ_404_Solution_NGramCacheRepository|null $repo
     * @param ABJ_404_Solution_NGramCoveragePolicy|null $coveragePolicy
     * @param ABJ_404_Solution_NGramUsageTelemetry|null $telemetry
     */
    public function __construct(
        $dbCore = null,
        $logging = null,
        $functions = null,
        $extractor = null,
        $similarity = null,
        $repo = null,
        $coveragePolicy = null,
        $telemetry = null
    ) {
        $loggingResolved = $logging !== null ? $logging : abj_service('logging');
        $functionsResolved = $functions !== null ? $functions : abj_service('functions');
        if (!$loggingResolved instanceof ABJ_404_Solution_Logging) {
            throw new RuntimeException('NGramFilter requires a Logging instance.');
        }
        if (!$functionsResolved instanceof ABJ_404_Solution_Functions) {
            throw new RuntimeException('NGramFilter requires a Functions instance.');
        }
        $this->logger = $loggingResolved;
        $this->f = $functionsResolved;

        // Collaborators: prefer the explicit arg, then the container-registered
        // instance, then a direct instantiation for unit-test contexts that
        // load NGramFilter standalone without bootstrap. The new collaborators
        // are pure composition over $dbCore/$logger/$f already, so direct
        // construction is equivalent.
        $this->extractor = self::resolveExtractor($extractor, $this->f, $this->logger);
        $this->similarity = self::resolveSimilarity($similarity);
        $this->repo = self::resolveRepo($repo, $dbCore, $this->logger, $this->similarity);
        $this->coveragePolicy = self::resolveCoveragePolicy($coveragePolicy, $dbCore);
        $this->telemetry = self::resolveTelemetry($telemetry);
    }

    /**
     * @param mixed $explicit
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @return ABJ_404_Solution_NGramExtractor
     */
    private static function resolveExtractor($explicit, $f, $logger) {
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
    private static function resolveSimilarity($explicit) {
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
    private static function resolveRepo($explicit, $dbCore, $logger, $similarity) {
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
    private static function resolveCoveragePolicy($explicit, $dbCore) {
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
    private static function resolveTelemetry($explicit) {
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

    /** @return self */
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_NGramFilter();
        }
        return self::$instance;
    }

    /**
     * Find pages similar to a 404 URL using N-gram filtering.
     *
     * Main entry point called by SpellLevenshteinEngine. Returns associative
     * array of [pageId => similarity_score] sorted descending.
     *
     * @param string $url404
     * @param float $minSimilarity Minimum Dice coefficient (default 0.4)
     * @param int $maxCandidates Maximum candidates to return (default 100)
     * @return array<int, float>
     */
    public function findSimilarPages($url404, $minSimilarity = 0.4, $maxCandidates = 100) {
        $startTime = abj_clock()->nowFloat();

        $url404Normalized = $this->f->strtolower(trim($url404));
        $queryNGrams = $this->extractor->extractNGrams($url404Normalized);
        $queryCombinedCount = count($queryNGrams['bi']) + count($queryNGrams['tri']);

        if ($queryCombinedCount == 0) {
            $this->logger->debugMessage("Search term too short for N-gram filtering: '{$url404}'");
            return [];
        }

        $totalCount = $this->repo->getCacheCount();

        if ($totalCount == 0) {
            $this->logger->debugMessage("N-gram cache is empty.");

            // Schedule background rebuild if not already initialized/scheduled.
            // Uses multisite-aware init check so network-activated installs
            // read get_site_option correctly on frontend 404 dispatch.
            if (!$this->coveragePolicy->isCacheInitialized()) {
                try {
                    $dbUpgrades = abj_service('database_upgrades');
                    $dbUpgrades->scheduleNGramCacheRebuild();
                    $this->logger->infoMessage("Empty N-gram cache detected during 404 request. Scheduled background rebuild.");
                } catch (Exception $e) {
                    $this->logger->errorMessage("Failed to schedule N-gram cache rebuild: " . $e->getMessage());
                }
            } else {
                $this->logger->debugMessage("N-gram cache rebuild already initialized or scheduled.");
            }

            return [];
        }

        // 40% tolerance window around query's ngram count
        $minCount = max(1, (int)($queryCombinedCount * 0.4));
        $maxCount = (int)($queryCombinedCount * 2.5);

        if ($totalCount > ABJ_404_Solution_NGramCacheRepository::CACHE_LOAD_LIMIT) {
            $this->logger->debugMessage("Using database-side filtering for {$totalCount} entries");
            $cachedPages = $this->repo->getCachedNGramsFiltered($minCount, $maxCount, ABJ_404_Solution_NGramCacheRepository::CACHE_LOAD_LIMIT, $queryCombinedCount);
        } else {
            $cachedPages = $this->repo->getAllCachedNGrams();
        }

        if (empty($cachedPages)) {
            $this->logger->debugMessage("No matching candidates after filtering.");
            return [];
        }

        $similarities = [];
        foreach ($cachedPages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $pageId = isset($page['id']) ? $page['id'] : null;
            $pageNGrams = isset($page['ngrams']) ? $page['ngrams'] : null;

            // Quick optimization: skip if N-gram counts differ too much.
            // Redundant for filtered queries; preserved for unfiltered path.
            $pageNgramCountRaw = isset($page['ngram_count']) ? $page['ngram_count'] : 0;
            $pageCombinedCount = is_scalar($pageNgramCountRaw) ? (int)$pageNgramCountRaw : 0;
            $commonUpperBound = min($queryCombinedCount, $pageCombinedCount);
            $diceUpperBound = (2 * $commonUpperBound) / max(1, $queryCombinedCount + $pageCombinedCount);
            if ($diceUpperBound < $minSimilarity) {
                continue;
            }

            /** @var array{bi?: array<int, string>, tri?: array<int, string>} $pageNGramsTyped */
            $pageNGramsTyped = is_array($pageNGrams) ? $pageNGrams : array();
            $sim = $this->similarity->diceCoefficient($queryNGrams, $pageNGramsTyped);

            if ($sim >= $minSimilarity) {
                $similarities[$pageId] = $sim;
            }
        }

        arsort($similarities);

        if (count($similarities) > $maxCandidates) {
            $similarities = array_slice($similarities, 0, $maxCandidates, true);
        }

        $duration = (abj_clock()->nowFloat() - $startTime) * 1000;

        $this->logger->debugMessage(sprintf(
            "N-gram filtering: %d total, %d examined -> %d candidates (>=%.2f similarity) in %.2fms",
            $totalCount,
            count($cachedPages),
            count($similarities),
            $minSimilarity,
            $duration
        ));

        $this->telemetry->trackNGramUsage($totalCount, count($cachedPages), count($similarities), $duration);

        return $similarities;
    }

    /**
     * Convenience predicate for SpellLevenshteinEngine.
     *
     * @return bool
     */
    public function isCachePopulated() {
        return $this->repo->getCacheCount() > 0;
    }

    // Legacy compatibility methods for external/test callers that still
    // subclass or hold the historical filter service.

    /**
     * @param string $url
     * @param array<int, int> $ngramSizes
     * @return array{bi: array<int, string>, tri: array<int, string>}
     */
    public function extractNGrams($url, $ngramSizes = [2, 3]) {
        return $this->extractor->extractNGrams($url, $ngramSizes);
    }

    /**
     * @param array{bi?: array<int, string>, tri?: array<int, string>} $ngrams1
     * @param array{bi?: array<int, string>, tri?: array<int, string>} $ngrams2
     * @return float
     */
    public function diceCoefficient($ngrams1, $ngrams2) {
        return $this->similarity->diceCoefficient($ngrams1, $ngrams2);
    }

    /**
     * @param int $pageId
     * @param string $url
     * @param string $urlNormalized
     * @param array<string, mixed> $ngrams
     * @param string $type
     * @param bool $skipInvalidation
     * @return bool
     */
    public function storeNGrams($pageId, $url, $urlNormalized, $ngrams, $type = 'post', $skipInvalidation = false) {
        return $this->repo->storeNGrams($pageId, $url, $urlNormalized, $ngrams, $type, $skipInvalidation);
    }

    /**
     * @param int $pageId
     * @param string $type
     * @return array{bi: array<int, string>, tri: array<int, string>}|null
     */
    public function getNGramsForPage($pageId, $type = 'post') {
        return $this->repo->getNGramsForPage($pageId, $type);
    }

    /** @return array<int, array<string, mixed>> */
    public function getAllCachedNGrams() {
        return $this->repo->getAllCachedNGrams();
    }

    /**
     * @param int $minNgramCount
     * @param int $maxNgramCount
     * @param int $limit
     * @param int|null $targetNgramCount
     * @return array<int, array<string, mixed>>
     */
    public function getCachedNGramsFiltered($minNgramCount, $maxNgramCount, $limit = 1000, $targetNgramCount = null) {
        return $this->repo->getCachedNGramsFiltered($minNgramCount, $maxNgramCount, $limit, $targetNgramCount);
    }

    /**
     * @param int $pageId
     * @param string $type
     * @return bool
     */
    public function invalidatePage($pageId, $type = 'post') {
        return $this->repo->invalidatePage($pageId, $type);
    }

    /** @return int */
    public function getCacheCount() {
        return $this->repo->getCacheCount();
    }

    /** @return array<string, mixed> */
    public function getCacheStats() {
        return $this->repo->getCacheStats();
    }

    /** @return void */
    public function invalidateCoverageCaches() {
        $this->coveragePolicy->invalidateCoverageCaches();
        $this->repo->resetMemo();
    }

    /** @return bool */
    public function isCacheInitialized() {
        return $this->coveragePolicy->isCacheInitialized();
    }

    /** @return float */
    public function getCacheCoverageRatio() {
        return $this->coveragePolicy->getCacheCoverageRatio();
    }

    /**
     * @param int $batchSize
     * @param int $offset
     * @return array{processed: int, success: int, failed: int}
     */
    public function rebuildCache($batchSize = 100, $offset = 0) {
        return $this->rebuilder()->rebuildCache($batchSize, $offset);
    }

    /**
     * @param array<int, int> $pageIds
     * @return array{processed: int, success: int, failed: int}
     */
    public function updateNGramsForPages($pageIds) {
        return $this->rebuilder()->updateNGramsForPages($pageIds);
    }

    /** @return array<string, mixed> */
    public function getUsageStats() {
        return $this->telemetry->getUsageStats();
    }

    /**
     * Lazy rebuilder accessor. Rebuilder isn't part of the constructor
     * collaborator list (it's an orchestration-time helper rather than a
     * findSimilarPages dependency); resolved through the container when a
     * facade caller needs it.
     *
     * @return ABJ_404_Solution_NGramRebuilder
     */
    private function rebuilder() {
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $container = ABJ_404_Solution_ServiceContainer::getInstance();
            if ($container->has('ngram_rebuilder')) {
                $service = $container->get('ngram_rebuilder');
                if ($service instanceof ABJ_404_Solution_NGramRebuilder) {
                    return $service;
                }
            }
        }
        // Container miss (unit tests that haven't registered the service):
        // build a transient rebuilder from already-resolved collaborators so
        // the facade still works without container plumbing.
        return new ABJ_404_Solution_NGramRebuilder(
            new ABJ_404_Solution_NGramRebuilderDependencies(
                null,
                $this->logger,
                $this->f,
                $this->extractor,
                $this->repo,
                $this->coveragePolicy
            )
        );
    }
}
