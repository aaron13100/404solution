<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Incremental maintenance of the n-gram cache: keep it in sync with
 * the canonical content sources (permalink_cache for posts/pages,
 * published categories for category archives) without rebuilding
 * everything.
 *
 * Two paired operations:
 *
 *  - syncMissing(): find ids that exist in the source but lack
 *    n-gram entries, and add them.
 *  - cleanupOrphaned(): find n-gram rows whose source row no longer
 *    exists, and delete them.
 *
 * Lock ownership: the orchestrator (DatabaseUpgradeNGram) acquires
 * the shared 'ngram_rebuild' SyncUtils lock before calling
 * syncMissing() so its INSERTs do not race with a TRUNCATE from the
 * sync rebuilder. cleanupOrphaned() runs without the rebuild lock
 * because it only deletes by primary key on rows the source no
 * longer references.
 */
class ABJ_404_Solution_NGramCacheReconciler {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var mixed */
    private $rebuilder;

    /** @var mixed */
    private $extractor;

    /** @var mixed */
    private $repo;

    /** @var mixed */
    private $coveragePolicy;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param mixed $rebuilder Object exposing updateNGramsForPages().
     * @param mixed $extractor Object exposing extractNGrams().
     * @param mixed $repo Object exposing storeNGrams().
     * @param mixed $coveragePolicy Object exposing invalidateCoverageCaches().
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($dbCore, $rebuilder, $extractor, $repo, $coveragePolicy, $contentRepo, $f, $logger) {
        $this->dbCore = $dbCore;
        $this->rebuilder = $rebuilder;
        $this->extractor = $extractor;
        $this->repo = $repo;
        $this->coveragePolicy = $coveragePolicy;
        $this->contentRepo = $contentRepo;
        $this->f = $f;
        $this->logger = $logger;
    }

    /**
     * Find post and category ids that exist in the source but are
     * missing from the n-gram cache, and add entries for them.
     *
     * @param int $batchSize maximum number of missing posts to add
     *                       per call (categories are processed in full
     *                       because the published category set is
     *                       small).
     * @return array<string, mixed> ['posts_added' => int, 'posts_failed' => int, 'categories_added' => int, 'categories_failed' => int]
     */
    public function syncMissing($batchSize = 50) {
        $ngramTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');

        $stats = ['posts_added' => 0, 'posts_failed' => 0, 'categories_added' => 0, 'categories_failed' => 0];

        $postsResult = $this->syncMissingPosts($ngramTable, $permalinkCacheTable, $batchSize);
        if (isset($postsResult['error'])) {
            return array_merge($stats, ['error' => $postsResult['error']]);
        }
        $stats['posts_added'] = $postsResult['added'];
        $stats['posts_failed'] = $postsResult['failed'];

        $categoriesResult = $this->syncMissingCategories($ngramTable);
        $stats['categories_added'] = $categoriesResult['added'];
        $stats['categories_failed'] = $categoriesResult['failed'];

        $this->logger->infoMessage("Ngram sync complete: {$stats['posts_added']} posts added, {$stats['posts_failed']} posts failed, {$stats['categories_added']} categories added, {$stats['categories_failed']} categories failed.");

        return $stats;
    }

    /**
     * Delete n-gram rows whose source no longer exists.
     *
     * @return array{posts_deleted:int, categories_deleted:int, errors:int}|array<string, mixed>
     */
    public function cleanupOrphaned() {
        $ngramTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');

        $this->logger->debugMessage("Checking for orphaned ngram entries...");

        $stats = ['posts_deleted' => 0, 'categories_deleted' => 0, 'errors' => 0];

        $postsResult = $this->cleanupOrphanedPosts($ngramTable, $permalinkCacheTable);
        if (isset($postsResult['error'])) {
            return array_merge($stats, ['error' => $postsResult['error']]);
        }
        $stats['posts_deleted'] = $postsResult['deleted'];
        $stats['errors'] += $postsResult['errors'];

        $categoriesResult = $this->cleanupOrphanedCategories($ngramTable);
        $stats['categories_deleted'] = $categoriesResult['deleted'];
        $stats['errors'] += $categoriesResult['errors'];

        $this->logger->infoMessage("Orphaned ngram cleanup complete: {$stats['posts_deleted']} posts deleted, {$stats['categories_deleted']} categories deleted, {$stats['errors']} errors.");

        return $stats;
    }

    /**
     * @return array{added:int, failed:int}|array{error:string, added:int, failed:int}
     */
    private function syncMissingPosts(string $ngramTable, string $permalinkCacheTable, int $batchSize): array {
        $missingResult = $this->dbCore->queryAndGetResults(
            "SELECT pc.id
             FROM {$permalinkCacheTable} pc
             LEFT JOIN {$ngramTable} ng ON pc.id = ng.id AND ng.type = 'post'
             WHERE ng.id IS NULL
             LIMIT %d",
            ['query_params' => [$batchSize]]
        );

        $missingError = isset($missingResult['last_error']) && is_string($missingResult['last_error']) ? $missingResult['last_error'] : '';
        if ($missingError !== '') {
            if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($missingError)) {
                $this->logger->errorMessage("Failed to query for missing post ngram entries: " . $missingError);
            }
            return ['error' => $missingError, 'added' => 0, 'failed' => 0];
        }

        $missingRows = isset($missingResult['rows']) && is_array($missingResult['rows']) ? $missingResult['rows'] : [];
        $missingIds = [];
        foreach ($missingRows as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $missingIds[] = (int)$row['id'];
            }
        }

        if (empty($missingIds)) {
            $this->logger->debugMessage("No missing post ngram entries found. All posts are synced.");
            return ['added' => 0, 'failed' => 0];
        }

        $this->logger->infoMessage("Found " . count($missingIds) . " posts missing ngram entries. Adding...");

        $result = $this->updateNGramsForPages($missingIds);

        return ['added' => $result['success'], 'failed' => $result['failed']];
    }

    /**
     * @return array{added:int, failed:int}
     */
    private function syncMissingCategories(string $ngramTable): array {
        $stats = ['added' => 0, 'failed' => 0];

        $categories = $this->contentRepo->getPublishedCategories();
        if (empty($categories)) {
            return $stats;
        }

        $missingCategories = [];
        foreach ($categories as $category) {
            /** @var object{term_id: int, url: string} $category */
            $termId = (int)$category->term_id;
            $exists = $this->dbCore->queryScalarInt(
                "SELECT COUNT(*) AS c FROM {$ngramTable} WHERE id = %d AND type = 'category'",
                ['query_params' => [$termId]]
            );
            if ($exists == 0) {
                $missingCategories[] = $category;
            }
        }

        if (empty($missingCategories)) {
            $this->logger->debugMessage("No missing category ngram entries found. All categories are synced.");
            return $stats;
        }

        $this->logger->infoMessage("Found " . count($missingCategories) . " categories missing ngram entries. Adding...");

        foreach ($missingCategories as $category) {
            try {
                /** @var object{term_id: int, url: string} $category */
                $termId = (int)$category->term_id;
                $url = (string)$category->url;

                if (empty($url) || $url === 'in code') {
                    $this->logger->debugMessage("Skipping category {$termId} - no valid URL");
                    continue;
                }

                $urlNormalized = $this->f->strtolower(trim($url));
                $ngrams = $this->extractNGrams($urlNormalized);
                $success = $this->storeNGrams($termId, $url, $urlNormalized, $ngrams, 'category');

                if ($success) {
                    $stats['added']++;
                } else {
                    $stats['failed']++;
                }
            } catch (Exception $e) {
                $this->logger->errorMessage("Failed to add ngram for category {$termId}: " . $e->getMessage());
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * @return array{deleted:int, errors:int}|array{error:string, deleted:int, errors:int}
     */
    private function cleanupOrphanedPosts(string $ngramTable, string $permalinkCacheTable): array {
        $orphanedResult = $this->dbCore->queryAndGetResults(
            "SELECT ng.id, ng.type
                  FROM {$ngramTable} ng
                  LEFT JOIN {$permalinkCacheTable} pc ON ng.id = pc.id AND ng.type = 'post'
                  WHERE ng.type = 'post' AND pc.id IS NULL",
            ['result_type' => OBJECT]
        );

        $orphanedError = isset($orphanedResult['last_error']) && is_string($orphanedResult['last_error']) ? $orphanedResult['last_error'] : '';
        if ($orphanedError !== '') {
            if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($orphanedError)) {
                $this->logger->errorMessage("Failed to query for orphaned post ngram entries: " . $orphanedError);
            }
            return ['error' => $orphanedError, 'deleted' => 0, 'errors' => 0];
        }

        $orphanedPosts = isset($orphanedResult['rows']) && is_array($orphanedResult['rows']) ? $orphanedResult['rows'] : [];

        if (empty($orphanedPosts)) {
            $this->logger->debugMessage("No orphaned post ngram entries found.");
            return ['deleted' => 0, 'errors' => 0];
        }

        $this->logger->infoMessage("Found " . count($orphanedPosts) . " orphaned post ngram entries. Deleting...");

        $deleted = 0;
        $errors = 0;
        foreach ($orphanedPosts as $entry) {
            if (!is_object($entry)) {
                continue;
            }
            /** @var object{id: int, type: string} $entry */
            $entryId = (int)$entry->id;
            $entryType = (string)$entry->type;
            $deleteResult = $this->dbCore->queryAndGetResults(
                "DELETE FROM {$ngramTable} WHERE id = %d AND type = %s",
                ['query_params' => [$entryId, $entryType]]
            );

            $deleteError = isset($deleteResult['last_error']) && is_string($deleteResult['last_error']) ? $deleteResult['last_error'] : '';
            if ($deleteError !== '') {
                if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($deleteError)) {
                    $this->logger->errorMessage("Failed to delete orphaned post ngram entry ID {$entryId}: " . $deleteError);
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
     * @return array{deleted:int, errors:int}
     */
    private function cleanupOrphanedCategories(string $ngramTable): array {
        $publishedCategories = $this->contentRepo->getPublishedCategories();
        $publishedCategoryIds = [];

        if (!empty($publishedCategories)) {
            foreach ($publishedCategories as $category) {
                /** @var object{term_id: int, url: string} $category */
                $publishedCategoryIds[] = (int)$category->term_id;
            }
        }

        $catEntriesResult = $this->dbCore->queryAndGetResults(
            "SELECT DISTINCT id FROM {$ngramTable} WHERE type = 'category'",
            ['result_type' => OBJECT]
        );
        $categoryNGramEntries = isset($catEntriesResult['rows']) && is_array($catEntriesResult['rows']) ? $catEntriesResult['rows'] : [];

        if (empty($categoryNGramEntries)) {
            return ['deleted' => 0, 'errors' => 0];
        }

        $orphanedCategories = [];
        foreach ($categoryNGramEntries as $entry) {
            if (!is_object($entry)) {
                continue;
            }
            /** @var object{id: int} $entry */
            $entId = (int)$entry->id;
            if (!in_array($entId, $publishedCategoryIds)) {
                $orphanedCategories[] = $entId;
            }
        }

        if (empty($orphanedCategories)) {
            $this->logger->debugMessage("No orphaned category ngram entries found.");
            return ['deleted' => 0, 'errors' => 0];
        }

        $this->logger->infoMessage("Found " . count($orphanedCategories) . " orphaned category ngram entries. Deleting...");

        $deleted = 0;
        $errors = 0;
        foreach ($orphanedCategories as $categoryId) {
            $catDeleteResult = $this->dbCore->queryAndGetResults(
                "DELETE FROM {$ngramTable} WHERE id = %d AND type = %s",
                ['query_params' => [$categoryId, 'category']]
            );

            $catDeleteError = isset($catDeleteResult['last_error']) && is_string($catDeleteResult['last_error']) ? $catDeleteResult['last_error'] : '';
            if ($catDeleteError !== '') {
                if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($catDeleteError)) {
                    $this->logger->errorMessage("Failed to delete orphaned category ngram entry ID {$categoryId}: " . $catDeleteError);
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
     * @param array<int, int> $pageIds
     * @return array{processed: int, success: int, failed: int}
     */
    private function updateNGramsForPages(array $pageIds): array {
        $rebuilder = $this->rebuilder;
        if (!is_object($rebuilder) || !method_exists($rebuilder, 'updateNGramsForPages')) {
            throw new RuntimeException('NGramCacheReconciler requires a rebuilder with updateNGramsForPages().');
        }
        $stats = $rebuilder->updateNGramsForPages($pageIds);
        return [
            'processed' => is_array($stats) && isset($stats['processed']) && is_numeric($stats['processed']) ? (int)$stats['processed'] : 0,
            'success' => is_array($stats) && isset($stats['success']) && is_numeric($stats['success']) ? (int)$stats['success'] : 0,
            'failed' => is_array($stats) && isset($stats['failed']) && is_numeric($stats['failed']) ? (int)$stats['failed'] : 0,
        ];
    }

    /**
     * @param string $url
     * @return array{bi: array<int, string>, tri: array<int, string>}
     */
    private function extractNGrams(string $url): array {
        $extractor = $this->extractor;
        if (!is_object($extractor) || !method_exists($extractor, 'extractNGrams')) {
            throw new RuntimeException('NGramCacheReconciler requires an extractor with extractNGrams().');
        }
        $ngrams = $extractor->extractNGrams($url);
        return $this->normalizeNGramPayload($ngrams);
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
            throw new RuntimeException('NGramCacheReconciler requires a repository with storeNGrams().');
        }
        return (bool)$repo->storeNGrams($pageId, $url, $urlNormalized, $ngrams, $type);
    }

    /** @return void */
    private function invalidateCoverageCaches(): void {
        $coveragePolicy = $this->coveragePolicy;
        if (!is_object($coveragePolicy) || !method_exists($coveragePolicy, 'invalidateCoverageCaches')) {
            throw new RuntimeException('NGramCacheReconciler requires a coverage policy with invalidateCoverageCaches().');
        }
        $coveragePolicy->invalidateCoverageCaches();
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
}
