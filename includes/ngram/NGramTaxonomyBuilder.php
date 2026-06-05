<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds n-gram cache entries for taxonomy terms (categories and
 * tags). The cache stores type-tagged rows so SpellChecker can match
 * URLs against term archives the same way it matches post/page URLs.
 *
 * Post/page n-grams are handled by NGramCacheSyncRebuilder; this
 * collaborator owns the term-archive side because terms have a
 * different identity (term_id) and URL source than posts.
 */
class ABJ_404_Solution_NGramTaxonomyBuilder {

    /** @var mixed */
    private $extractor;

    /** @var mixed */
    private $repo;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param mixed $extractor Object exposing extractNGrams().
     * @param mixed $repo Object exposing storeNGrams().
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($extractor, $repo, $contentRepo, $f, $logger) {
        $this->extractor = $extractor;
        $this->repo = $repo;
        $this->contentRepo = $contentRepo;
        $this->f = $f;
        $this->logger = $logger;
    }

    /**
     * Build n-grams for all published categories.
     *
     * @param int $batchSize reserved for future batching; current
     *                       implementation processes the published
     *                       set in one pass.
     * @return array{processed:int, success:int, failed:int}
     */
    public function buildForCategories($batchSize = 50) {
        $this->logger->debugMessage("Building N-grams for categories...");

        $categories = $this->contentRepo->getPublishedCategories();

        if (empty($categories)) {
            $this->logger->debugMessage("No published categories found.");
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $stats = $this->buildForTerms($categories, 'category');

        $this->logger->infoMessage("Category N-grams built: {$stats['processed']} processed, {$stats['success']} success, {$stats['failed']} failed.");

        return $stats;
    }

    /**
     * Build n-grams for all published tags.
     *
     * @param int $batchSize reserved for future batching.
     * @return array{processed:int, success:int, failed:int}
     */
    public function buildForTags($batchSize = 50) {
        $this->logger->debugMessage("Building N-grams for tags...");

        $tags = $this->contentRepo->getPublishedTags();

        if (empty($tags)) {
            $this->logger->debugMessage("No published tags found.");
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $stats = $this->buildForTerms($tags, 'tag');

        $this->logger->infoMessage("Tag N-grams built: {$stats['processed']} processed, {$stats['success']} success, {$stats['failed']} failed.");

        return $stats;
    }

    /**
     * Shared per-term builder: normalize each term's URL, extract
     * n-grams, store with the supplied type discriminator.
     *
     * @param iterable<object> $terms each entry is expected to expose
     *                                term_id (int) and url (string).
     * @param string $type 'category' or 'tag'
     * @return array{processed:int, success:int, failed:int}
     */
    private function buildForTerms($terms, $type) {
        $stats = ['processed' => 0, 'success' => 0, 'failed' => 0];
        $termId = 0;

        foreach ($terms as $term) {
            try {
                /** @var object{term_id: int, url: string} $term */
                $termId = (int)$term->term_id;
                $url = (string)$term->url;

                if (empty($url) || $url === 'in code') {
                    $this->logger->debugMessage("Skipping {$type} {$termId} - no valid URL");
                    continue;
                }

                $urlNormalized = $this->f->strtolower(trim($url));
                $ngrams = $this->extractNGrams($urlNormalized);
                $success = $this->storeNGrams($termId, $url, $urlNormalized, $ngrams, $type);

                $stats['processed']++;
                if ($success) {
                    $stats['success']++;
                } else {
                    $stats['failed']++;
                }
            } catch (Exception $e) {
                $this->logger->errorMessage("Failed to build ngram for {$type} {$termId}: " . $e->getMessage());
                $stats['processed']++;
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * @param string $url
     * @return array{bi: array<int, string>, tri: array<int, string>}
     */
    private function extractNGrams(string $url): array {
        $extractor = $this->extractor;
        if (!is_object($extractor) || !method_exists($extractor, 'extractNGrams')) {
            throw new RuntimeException('NGramTaxonomyBuilder requires an extractor with extractNGrams().');
        }
        $ngrams = $extractor->extractNGrams($url);
        $bi = [];
        $tri = [];
        if (is_array($ngrams)) {
            $biRaw = isset($ngrams['bi']) && is_array($ngrams['bi']) ? $ngrams['bi'] : [];
            foreach ($biRaw as $ngram) {
                if (is_string($ngram)) {
                    $bi[] = $ngram;
                }
            }
            $triRaw = isset($ngrams['tri']) && is_array($ngrams['tri']) ? $ngrams['tri'] : [];
            foreach ($triRaw as $ngram) {
                if (is_string($ngram)) {
                    $tri[] = $ngram;
                }
            }
        }
        return ['bi' => $bi, 'tri' => $tri];
    }

    /**
     * @param int $termId
     * @param string $url
     * @param string $urlNormalized
     * @param array<string, mixed> $ngrams
     * @param string $type
     * @return bool
     */
    private function storeNGrams(int $termId, string $url, string $urlNormalized, array $ngrams, string $type): bool {
        $repo = $this->repo;
        if (!is_object($repo) || !method_exists($repo, 'storeNGrams')) {
            throw new RuntimeException('NGramTaxonomyBuilder requires a repository with storeNGrams().');
        }
        return (bool)$repo->storeNGrams($termId, $url, $urlNormalized, $ngrams, $type);
    }
}
