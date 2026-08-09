<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the taxonomy-term half of the n-gram cache in sync with the published
 * categories and tags.
 *
 * Split from {@see ABJ_404_Solution_NGramCacheReconciler} because the term
 * half reconciles against a different source through a different writer: the
 * published-term set comes from the content repository (WP taxonomy) rather
 * than the permalink cache, and rows are written by extracting n-grams and
 * storing them through the cache repository rather than by handing page ids to
 * the bulk rebuilder. It is also bounded differently -- the published term set
 * is small, so it is processed in full rather than in batches, and there is no
 * backlog to classify.
 *
 * Sibling of the term-side collaborators already in this layer
 * (ABJ_404_Solution_TermCandidateSource,
 * ABJ_404_Solution_TermNGramCoveragePolicy).
 *
 * Lock ownership stays with the orchestrator (DatabaseUpgradeNGram): the sync
 * path runs under the shared 'ngram_rebuild' lock, the cleanup path without it
 * (it only deletes by primary key on rows the source no longer references).
 */
class ABJ_404_Solution_NGramTermCacheReconciler {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var mixed */
    private $extractor;

    /** @var mixed */
    private $repo;

    /** @var mixed */
    private $coveragePolicy;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param mixed $extractor Object exposing extractNGrams().
     * @param mixed $repo Object exposing storeNGrams().
     * @param mixed $coveragePolicy Object exposing invalidateCoverageCaches().
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($dbCore, $extractor, $repo, $coveragePolicy, $f, $logger) {
        $this->dbCore = $dbCore;
        $this->extractor = $extractor;
        $this->repo = $repo;
        $this->coveragePolicy = $coveragePolicy;
        $this->f = $f;
        $this->logger = $logger;
    }

    /**
     * Add n-gram entries for published terms of one taxonomy type
     * ('category' or 'tag') that are missing from the cache. Category and tag
     * sync are identical apart from the type label and source rows, so they
     * share this one implementation.
     *
     * @param string $ngramTable
     * @param string $type 'category' or 'tag'.
     * @param array<int, object> $terms Published terms of this type (term_id, url).
     * @return array{added:int, failed:int}
     */
    public function syncMissingTerms(string $ngramTable, string $type, array $terms): array {
        $stats = ['added' => 0, 'failed' => 0];

        if (empty($terms)) {
            return $stats;
        }

        $missing = [];
        foreach ($terms as $term) {
            /** @var object{term_id: int, url: string} $term */
            $termId = (int)$term->term_id;
            $exists = $this->dbCore->queryScalarInt(
                "SELECT COUNT(*) AS c FROM {$ngramTable} WHERE id = %d AND type = %s",
                ['query_params' => [$termId, $type]]
            );
            if ($exists == 0) {
                $missing[] = $term;
            }
        }

        if (empty($missing)) {
            $this->logger->debugMessage("No missing {$type} ngram entries found. All {$type}s are synced.");
            return $stats;
        }

        $this->logger->infoMessage("Found " . count($missing) . " {$type}s missing ngram entries. Adding...");

        foreach ($missing as $term) {
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

                if ($success) {
                    $stats['added']++;
                } else {
                    $stats['failed']++;
                }
            } catch (Exception $e) {
                $this->logger->errorMessage("Failed to add ngram for {$type} {$termId}: " . $e->getMessage());
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Delete n-gram rows of one taxonomy type ('category' or 'tag') whose
     * source term is no longer published. Category and tag cleanup are
     * identical apart from the type label and source rows, so they share this
     * one implementation.
     *
     * @param string $ngramTable
     * @param string $type 'category' or 'tag'.
     * @param array<int, object> $publishedTerms Currently-published terms of this type.
     * @return array{deleted:int, errors:int}
     */
    public function cleanupOrphanedTerms(string $ngramTable, string $type, array $publishedTerms): array {
        // Positive evidence is required before deleting anything. The published
        // list arrives from ContentRepository::getPublishedCategories() /
        // getPublishedTags(), which return an EMPTY ARRAY when their query
        // fails (PublishedContentRepository logs the error and falls through to
        // objectRows($result['rows'] ?? array())). An empty list is therefore
        // indistinguishable from a failed read, and treating it as "nothing is
        // published" made a single transient database error -- a Galera
        // failover, a dropped connection -- delete every cached n-gram row of
        // this type. Refusing to act costs at most some stale rows, which only
        // affect suggestion ranking and are cleaned up on the next run that has
        // real data; acting on it costs the whole cache.
        if (empty($publishedTerms)) {
            $this->logger->debugMessage("Skipping orphaned {$type} ngram cleanup: no published {$type} "
                . "terms were supplied, which is indistinguishable from a failed lookup. "
                . "Nothing is deleted without positive evidence of what is published.");
            return ['deleted' => 0, 'errors' => 0];
        }

        $publishedIds = [];
        foreach ($publishedTerms as $term) {
            /** @var object{term_id: int, url: string} $term */
            $publishedIds[] = (int)$term->term_id;
        }

        $entriesResult = $this->dbCore->queryAndGetResults(
            "SELECT DISTINCT id FROM {$ngramTable} WHERE type = %s",
            ['query_params' => [$type], 'result_type' => OBJECT]
        );
        $ngramEntries = isset($entriesResult['rows']) && is_array($entriesResult['rows']) ? $entriesResult['rows'] : [];

        if (empty($ngramEntries)) {
            return ['deleted' => 0, 'errors' => 0];
        }

        $orphaned = [];
        foreach ($ngramEntries as $entry) {
            if (!is_object($entry)) {
                continue;
            }
            /** @var object{id: int} $entry */
            $entId = (int)$entry->id;
            if (!in_array($entId, $publishedIds)) {
                $orphaned[] = $entId;
            }
        }

        if (empty($orphaned)) {
            $this->logger->debugMessage("No orphaned {$type} ngram entries found.");
            return ['deleted' => 0, 'errors' => 0];
        }

        $this->logger->infoMessage("Found " . count($orphaned) . " orphaned {$type} ngram entries. Deleting...");

        $deleted = 0;
        $errors = 0;
        foreach ($orphaned as $termId) {
            $deleteResult = $this->dbCore->queryAndGetResults(
                "DELETE FROM {$ngramTable} WHERE id = %d AND type = %s",
                ['query_params' => [$termId, $type]]
            );

            $deleteError = isset($deleteResult['last_error']) && is_string($deleteResult['last_error']) ? $deleteResult['last_error'] : '';
            if ($deleteError !== '') {
                if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($deleteError)) {
                    $this->logger->errorMessage("Failed to delete orphaned {$type} ngram entry ID {$termId}: " . $deleteError);
                }
                $errors++;
            } else {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->invalidateCoverageCaches();
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * @param string $url
     * @return array{bi: array<int, string>, tri: array<int, string>}
     */
    private function extractNGrams(string $url): array {
        $extractor = $this->extractor;
        if (!is_object($extractor) || !method_exists($extractor, 'extractNGrams')) {
            throw new RuntimeException('NGramTermCacheReconciler requires an extractor with extractNGrams().');
        }
        $ngrams = $extractor->extractNGrams($url);
        return $this->normalizeNGramPayload($ngrams);
    }


    /**
     * @param mixed $ngrams
     * @return array{bi: array<int, string>, tri: array<int, string>}
     */
    private function normalizeNGramPayload($ngrams): array {
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
     * @param int $pageId
     * @param string $url
     * @param string $urlNormalized
     * @param array<string, mixed> $ngrams
     * @param string $type
     * @return bool
     */
    private function storeNGrams(int $pageId, string $url, string $urlNormalized, array $ngrams, string $type): bool {
        $repo = $this->repo;
        if (!is_object($repo) || !method_exists($repo, 'storeNGrams')) {
            throw new RuntimeException('NGramTermCacheReconciler requires a repository with storeNGrams().');
        }
        return (bool)$repo->storeNGrams($pageId, $url, $urlNormalized, $ngrams, $type);
    }

    /** @return void */
    private function invalidateCoverageCaches(): void {
        $coveragePolicy = $this->coveragePolicy;
        if (!is_object($coveragePolicy) || !method_exists($coveragePolicy, 'invalidateCoverageCaches')) {
            throw new RuntimeException('NGramTermCacheReconciler requires a coverage policy with invalidateCoverageCaches().');
        }
        $coveragePolicy->invalidateCoverageCaches();
    }
}
