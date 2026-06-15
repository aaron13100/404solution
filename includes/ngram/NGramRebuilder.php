<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Batch + incremental N-gram cache regeneration.
 *
 * Two entry points: rebuildCache() walks the permalink cache in batches
 * for full reseeding, and updateNGramsForPages() handles per-post events
 * (slug change, post insert) from SpellPostListeners. Both compose the
 * extractor and cache repository; coverage invalidation happens once per
 * batch (rebuild) or per-write (update) via the repository's own write
 * hooks.
 */
class ABJ_404_Solution_NGramRebuilder {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_NGramExtractor */
    private $extractor;

    /** @var ABJ_404_Solution_NGramCacheRepository */
    private $repo;

    /** @var ABJ_404_Solution_NGramCoveragePolicy */
    private $coveragePolicy;

    /**
     * @param ABJ_404_Solution_NGramRebuilderDependencies|null $deps
     */
    public function __construct(?ABJ_404_Solution_NGramRebuilderDependencies $deps = null) {
        $deps = $deps ?? new ABJ_404_Solution_NGramRebuilderDependencies();
        $this->dbCore = $deps->dbCore !== null ? $deps->dbCore : abj_service('db_core');
        $loggingResolved = $deps->logging !== null ? $deps->logging : abj_service('logging');
        $functionsResolved = $deps->functions !== null ? $deps->functions : abj_service('functions');
        $extractorResolved = $deps->extractor !== null ? $deps->extractor : abj_service('ngram_extractor');
        $repoResolved = $deps->repo !== null ? $deps->repo : abj_service('ngram_cache_repository');
        $coverageResolved = $deps->coveragePolicy !== null ? $deps->coveragePolicy : abj_service('ngram_coverage_policy');
        if (!$loggingResolved instanceof ABJ_404_Solution_Logging
            || !$functionsResolved instanceof ABJ_404_Solution_Functions
            || !$extractorResolved instanceof ABJ_404_Solution_NGramExtractor
            || !$repoResolved instanceof ABJ_404_Solution_NGramCacheRepository
            || !$coverageResolved instanceof ABJ_404_Solution_NGramCoveragePolicy) {
            throw new RuntimeException('NGramRebuilder requires fully-wired collaborators.');
        }
        $this->logger = $loggingResolved;
        $this->f = $functionsResolved;
        $this->extractor = $extractorResolved;
        $this->repo = $repoResolved;
        $this->coveragePolicy = $coverageResolved;
    }

    /**
     * Update N-grams for specific pages (incremental update).
     *
     * @param array<int, int> $pageIds
     * @return array{processed: int, success: int, failed: int}
     */
    public function updateNGramsForPages($pageIds) {
        if (empty($pageIds) || !is_array($pageIds)) {
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');

        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));
        $pageResult = $this->dbCore->queryAndGetResults(
            "SELECT id, url FROM {$permalinkCacheTable} WHERE id IN ({$placeholders})",
            ['query_params' => array_values($pageIds)]
        );
        $pages = isset($pageResult['rows']) && is_array($pageResult['rows']) ? $pageResult['rows'] : [];

        if (empty($pages)) {
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $stats = ['processed' => 0, 'success' => 0, 'failed' => 0];

        foreach ($pages as $page) {
            if (is_object($page)) {
                $page = (array) $page;
            }
            if (!is_array($page) || !isset($page['id'], $page['url'])) {
                continue;
            }
            $pageId = is_scalar($page['id']) ? (int)$page['id'] : 0;
            $url = is_scalar($page['url']) ? (string)$page['url'] : '';

            $urlNormalized = $this->f->strtolower(trim($url));
            $ngrams = $this->extractor->extractNGrams($urlNormalized);
            $success = $this->repo->storeNGrams($pageId, $url, $urlNormalized, $ngrams);

            $stats['processed']++;
            if ($success) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
        }

        $this->logger->debugMessage(sprintf(
            "Incremental N-gram update: %d pages, %d success, %d failed",
            $stats['processed'],
            $stats['success'],
            $stats['failed']
        ));

        return $stats;
    }

    /**
     * Rebuild the N-gram cache for all pages (background batch).
     *
     * @param int $batchSize
     * @param int $offset
     * @return array{processed: int, success: int, failed: int}
     */
    public function rebuildCache($batchSize = 100, $offset = 0) {
        $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');

        $batchResult = $this->dbCore->queryAndGetResults(
            "SELECT id, url FROM {$permalinkCacheTable} LIMIT %d OFFSET %d",
            ['query_params' => [$batchSize, $offset]]
        );
        $pages = isset($batchResult['rows']) && is_array($batchResult['rows']) ? $batchResult['rows'] : [];

        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0
        ];

        foreach ($pages as $page) {
            if (is_object($page)) {
                $page = (array) $page;
            }
            if (!is_array($page) || !isset($page['id'], $page['url'])) {
                continue;
            }
            $pageId = is_scalar($page['id']) ? (int)$page['id'] : 0;
            $url = is_scalar($page['url']) ? (string)$page['url'] : '';

            $urlNormalized = $this->f->strtolower(trim($url));
            $ngrams = $this->extractor->extractNGrams($urlNormalized);

            // Skip per-item invalidation for bulk efficiency; invalidate at batch end.
            $success = $this->repo->storeNGrams($pageId, $url, $urlNormalized, $ngrams, 'post', true);

            $stats['processed']++;
            if ($success) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
        }

        if ($stats['success'] > 0) {
            $this->coveragePolicy->invalidateCoverageCaches();
            $this->repo->resetMemo();
        }

        // Log every 1000 pages to reduce verbosity
        if ($offset % 1000 == 0) {
            $this->logger->debugMessage(sprintf(
                "N-gram cache rebuild batch (offset %d): %d processed, %d success, %d failed",
                $offset,
                $stats['processed'],
                $stats['success'],
                $stats['failed']
            ));
        }

        return $stats;
    }
}
