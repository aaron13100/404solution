<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sources candidate taxonomy-term rows (categories or tags) for a 404 match.
 *
 * Single responsibility: decide HOW to obtain the candidate term rows for a
 * given type and 404 query, then return them in the uniform term-row shape
 * (objects with term_id, name, slug, taxonomy, url).
 *
 *  - When the term n-gram cache for the type is trustworthy (coverage policy
 *    ready), route through the bounded n-gram prefilter and load only the
 *    matching term ids via PublishedTermsProvider. This keeps steady-state work
 *    bounded to a small candidate set instead of scanning every published term.
 *  - On a cold or low-coverage cache, fall back to the full
 *    getPublishedCategories()/getPublishedTags() scan (historical behavior).
 *
 * Extracted so both CategoryTagMatchingEngine and SpellSuggestionScorer share
 * one implementation of this bounded-vs-fallback policy rather than duplicating
 * it. The collaborators are optional; when any is absent (legacy/unit
 * construction) the source always uses the full-taxonomy scan.
 */
class ABJ_404_Solution_TermCandidateSource {

    /** N-gram cache type for category archives. */
    const TYPE_CATEGORY = 'category';

    /** N-gram cache type for tag archives. */
    const TYPE_TAG = 'tag';

    /** Dice threshold for the term n-gram prefilter. Matches the post path's
     * SpellNGramPrefilter::NGRAM_PREFILTER_THRESHOLD: terms and posts share the
     * same URL-shaped n-gram cache, so the same 0.3 recall floor applies. */
    const NGRAM_PREFILTER_THRESHOLD = 0.3;

    /** Max bounded term candidates to pull from the prefilter. Matches the post
     * path's NGRAM_PREFILTER_MAX_CANDIDATES; downstream scoring narrows further. */
    const NGRAM_PREFILTER_MAX_CANDIDATES = 500;

    /** @var mixed Object exposing getPublishedCategories()/getPublishedTags() (the full-scan fallback). */
    private $contentRepo;

    /** @var ABJ_404_Solution_NGramFilter|null */
    private $ngramFilter;

    /** @var ABJ_404_Solution_PublishedTermsProvider|null */
    private $publishedTermsProvider;

    /** @var ABJ_404_Solution_TermNGramCoveragePolicy|null */
    private $termCoveragePolicy;

    /**
     * @param mixed $contentRepo Object exposing getPublishedCategories()/getPublishedTags().
     * @param ABJ_404_Solution_NGramFilter|null $ngramFilter
     * @param ABJ_404_Solution_PublishedTermsProvider|null $publishedTermsProvider
     * @param ABJ_404_Solution_TermNGramCoveragePolicy|null $termCoveragePolicy
     */
    public function __construct(
        $contentRepo,
        $ngramFilter = null,
        $publishedTermsProvider = null,
        $termCoveragePolicy = null
    ) {
        $this->contentRepo = $contentRepo;
        $this->ngramFilter = $ngramFilter;
        $this->publishedTermsProvider = $publishedTermsProvider;
        $this->termCoveragePolicy = $termCoveragePolicy;
    }

    /**
     * Resolve candidate term rows for a taxonomy type.
     *
     * @param string $type 'category' or 'tag' (n-gram cache type).
     * @param string $query The URL slug / cleaned URL to prefilter against.
     * @return array<int, object> Uniform term-row shape; same either way.
     */
    public function getCandidateTermRows(string $type, string $query): array {
        if ($query !== ''
            && $this->ngramFilter !== null
            && $this->publishedTermsProvider !== null
            && $this->termCoveragePolicy !== null
            && $this->termCoveragePolicy->isReady($type)) {
            $candidates = $this->ngramFilter->findSimilarTermIds(
                $query,
                $type,
                self::NGRAM_PREFILTER_THRESHOLD,
                self::NGRAM_PREFILTER_MAX_CANDIDATES
            );
            return $this->publishedTermsProvider->getTermsByIds(array_keys($candidates), $type);
        }

        return $this->fullScan($type);
    }

    /**
     * @param string $type
     * @return array<int, object>
     */
    private function fullScan(string $type): array {
        $repo = $this->contentRepo;
        if (!is_object($repo)) {
            return array();
        }
        if ($type === self::TYPE_CATEGORY && method_exists($repo, 'getPublishedCategories')) {
            return $this->objectRows($repo->getPublishedCategories());
        }
        if ($type === self::TYPE_TAG && method_exists($repo, 'getPublishedTags')) {
            return $this->objectRows($repo->getPublishedTags());
        }
        return array();
    }

    /**
     * @param mixed $rows
     * @return array<int, object>
     */
    private function objectRows($rows): array {
        if (!is_array($rows)) {
            return array();
        }
        $objects = array();
        foreach ($rows as $row) {
            if (is_object($row)) {
                $objects[] = $row;
            }
        }
        return $objects;
    }
}
