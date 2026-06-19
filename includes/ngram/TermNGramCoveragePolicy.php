<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether the per-type (category/tag) n-gram prefilter is trustworthy
 * enough to use on a given 404 request, or whether the caller should fall back
 * to the full getPublishedCategories()/getPublishedTags() scan.
 *
 * Mirrors the post-side gates in SpellNGramPrefilter: a minimum absolute number
 * of cache entries for the type AND a minimum coverage ratio (cached term
 * n-grams / published terms of that type). Both must hold. This is intentionally
 * a separate policy from NGramCoveragePolicy, which measures post coverage
 * against the permalink cache; the term denominator is the published-taxonomy
 * count, a different source.
 *
 * Unit-testable in isolation: construct with the ngram filter (count source)
 * and a published-content repository exposing getPublishedCategoryCount() /
 * getPublishedTagCount(), then call isReady($type).
 */
class ABJ_404_Solution_TermNGramCoveragePolicy {

    /** Minimum cached term n-grams of a type before the prefilter is trusted. */
    const TERM_MIN_CACHE_ENTRIES = 50;

    /** Minimum (cached / published) coverage ratio for a type. */
    const TERM_MIN_COVERAGE_RATIO = 0.8;

    /** N-gram cache type for category archives. */
    const TYPE_CATEGORY = 'category';

    /** N-gram cache type for tag archives. */
    const TYPE_TAG = 'tag';

    /** @var ABJ_404_Solution_NGramFilter */
    private $ngramFilter;

    /** @var mixed Object exposing getPublishedCategoryCount() and getPublishedTagCount(). */
    private $contentRepo;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_NGramFilter $ngramFilter Source of getCacheCountForType().
     * @param mixed $contentRepo Object exposing getPublishedCategoryCount() / getPublishedTagCount().
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($ngramFilter, $contentRepo, $logger) {
        $this->ngramFilter = $ngramFilter;
        $this->contentRepo = $contentRepo;
        $this->logger = $logger;
    }

    /**
     * Is the prefilter trustworthy for this term type right now?
     *
     * @param string $type 'category' or 'tag'.
     * @return bool True when the cache has enough entries AND covers enough of
     *              the published terms; false on cold/insufficient coverage.
     */
    public function isReady(string $type): bool {
        if ($type !== self::TYPE_CATEGORY && $type !== self::TYPE_TAG) {
            return false;
        }

        $cacheCount = $this->ngramFilter->getCacheCountForType($type);
        if ($cacheCount < self::TERM_MIN_CACHE_ENTRIES) {
            $this->logger->debugMessage(sprintf(
                "Term n-gram prefilter not ready (min entries): type=%s count=%d (need %d)",
                $type,
                $cacheCount,
                self::TERM_MIN_CACHE_ENTRIES
            ));
            return false;
        }

        $publishedCount = $this->publishedCountFor($type);
        if ($publishedCount <= 0) {
            // No published terms of this type: nothing to prefilter against.
            return false;
        }

        $ratio = $cacheCount / $publishedCount;
        if ($ratio < self::TERM_MIN_COVERAGE_RATIO) {
            $this->logger->debugMessage(sprintf(
                "Term n-gram prefilter not ready (low coverage): type=%s ratio=%.2f (need %.2f)",
                $type,
                $ratio,
                self::TERM_MIN_COVERAGE_RATIO
            ));
            return false;
        }

        return true;
    }

    /**
     * @param string $type
     * @return int Published term count for the type, or 0 on unknown type.
     */
    private function publishedCountFor(string $type): int {
        $repo = $this->contentRepo;
        if (!is_object($repo)) {
            return 0;
        }
        if ($type === self::TYPE_CATEGORY && method_exists($repo, 'getPublishedCategoryCount')) {
            return (int)$repo->getPublishedCategoryCount();
        }
        if ($type === self::TYPE_TAG && method_exists($repo, 'getPublishedTagCount')) {
            return (int)$repo->getPublishedTagCount();
        }
        return 0;
    }
}
