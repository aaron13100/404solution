<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/NGramTermCacheReconciler.php';

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
 * Owns the POST side of both operations directly (permalink_cache as the
 * source, the bulk rebuilder as the writer, and the drift-vs-backlog
 * measurement that decides whether an incremental pass is even the right
 * tool). The taxonomy-term side reconciles a different source through a
 * different writer and lives in
 * {@see ABJ_404_Solution_NGramTermCacheReconciler}, which this class composes
 * so both public operations stay one call for the orchestrator.
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
    private $coveragePolicy;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_NGramTermCacheReconciler */
    private $termReconciler;

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
        $this->coveragePolicy = $coveragePolicy;
        $this->contentRepo = $contentRepo;
        $this->logger = $logger;
        $this->termReconciler = new ABJ_404_Solution_NGramTermCacheReconciler(
            $dbCore, $extractor, $repo, $coveragePolicy, $f, $logger);
    }

    /**
     * Find post and category ids that exist in the source but are
     * missing from the n-gram cache, and add entries for them.
     *
     * Sized for DRIFT: the handful of rows a day that slipped past the
     * real-time post-save hooks. A gap this call cannot finish by its next
     * run is a BACKLOG that belongs to the bulk rebuild path instead, and is
     * reported as such through the 'posts_backlogged' key so the caller can
     * hand it off (see ABJ_404_Solution_DatabaseUpgradeNGram::syncMissingNGrams).
     *
     * @param int $batchSize maximum number of missing posts to add
     *                       per call (categories are processed in full
     *                       because the published category set is
     *                       small).
     * @return array<string, mixed> ['posts_added' => int, 'posts_failed' => int, 'posts_missing_total' => int|null, 'posts_remaining' => int|null, 'posts_backlogged' => bool, 'categories_added' => int, 'categories_failed' => int, 'tags_added' => int, 'tags_failed' => int]
     */
    public function syncMissing($batchSize = 50) {
        $ngramTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');

        $stats = ['posts_added' => 0, 'posts_failed' => 0, 'posts_missing_total' => 0, 'posts_remaining' => 0,
            'posts_backlogged' => false, 'categories_added' => 0, 'categories_failed' => 0, 'tags_added' => 0, 'tags_failed' => 0];

        $postsResult = $this->syncMissingPosts($ngramTable, $permalinkCacheTable, $batchSize);
        if (isset($postsResult['error'])) {
            return array_merge($stats, ['error' => $postsResult['error']]);
        }
        $stats['posts_added'] = $postsResult['added'];
        $stats['posts_failed'] = $postsResult['failed'];
        $stats['posts_missing_total'] = $postsResult['missing_total'];
        $stats['posts_remaining'] = $postsResult['remaining'];
        $stats['posts_backlogged'] = $postsResult['backlogged'];

        $categoriesResult = $this->termReconciler->syncMissingTerms($ngramTable, 'category', $this->contentRepo->getPublishedCategories());
        $stats['categories_added'] = $categoriesResult['added'];
        $stats['categories_failed'] = $categoriesResult['failed'];

        $tagsResult = $this->termReconciler->syncMissingTerms($ngramTable, 'tag', $this->contentRepo->getPublishedTags());
        $stats['tags_added'] = $tagsResult['added'];
        $stats['tags_failed'] = $tagsResult['failed'];

        $remainingText = $stats['posts_remaining'] === null ? 'unknown' : (string)$stats['posts_remaining'];
        $this->logger->infoMessage("Ngram sync complete: {$stats['posts_added']} posts added, {$stats['posts_failed']} posts failed, "
            . "{$remainingText} posts still missing after this run, "
            . "{$stats['categories_added']} categories added, {$stats['categories_failed']} categories failed, "
            . "{$stats['tags_added']} tags added, {$stats['tags_failed']} tags failed.");

        return $stats;
    }

    /**
     * Delete n-gram rows whose source no longer exists.
     *
     * @return array{posts_deleted:int, categories_deleted:int, tags_deleted:int, errors:int}|array<string, mixed>
     */
    public function cleanupOrphaned() {
        $ngramTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');

        $this->logger->debugMessage("Checking for orphaned ngram entries...");

        $stats = ['posts_deleted' => 0, 'categories_deleted' => 0, 'tags_deleted' => 0, 'errors' => 0];

        $postsResult = $this->cleanupOrphanedPosts($ngramTable, $permalinkCacheTable);
        if (isset($postsResult['error'])) {
            return array_merge($stats, ['error' => $postsResult['error']]);
        }
        $stats['posts_deleted'] = $postsResult['deleted'];
        $stats['errors'] += $postsResult['errors'];

        $categoriesResult = $this->termReconciler->cleanupOrphanedTerms($ngramTable, 'category', $this->contentRepo->getPublishedCategories());
        $stats['categories_deleted'] = $categoriesResult['deleted'];
        $stats['errors'] += $categoriesResult['errors'];

        $tagsResult = $this->termReconciler->cleanupOrphanedTerms($ngramTable, 'tag', $this->contentRepo->getPublishedTags());
        $stats['tags_deleted'] = $tagsResult['deleted'];
        $stats['errors'] += $tagsResult['errors'];

        $this->logger->infoMessage("Orphaned ngram cleanup complete: {$stats['posts_deleted']} posts deleted, {$stats['categories_deleted']} categories deleted, {$stats['tags_deleted']} tags deleted, {$stats['errors']} errors.");

        return $stats;
    }

    /**
     * @return array{added:int, failed:int, missing_total:int|null, remaining:int|null, backlogged:bool}|array{error:string, added:int, failed:int, missing_total:null, remaining:null, backlogged:bool}
     */
    private function syncMissingPosts(string $ngramTable, string $permalinkCacheTable, int $batchSize): array {
        // Measure the WHOLE gap before taking a batch out of it. Counting the
        // rows that came back from a LIMITed SELECT can only ever report the
        // batch size, so a 12,000-row backlog and a 50-row drift logged the
        // same line ("Found 50 posts missing ngram entries") and neither the
        // user nor we could tell them apart -- which is how a months-long
        // drain read as a stuck 50-row loop.
        $missingTotal = $this->countMissingPosts($ngramTable, $permalinkCacheTable);

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
            return ['error' => $missingError, 'added' => 0, 'failed' => 0,
                'missing_total' => null, 'remaining' => null, 'backlogged' => false];
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
            return ['added' => 0, 'failed' => 0, 'missing_total' => 0, 'remaining' => 0, 'backlogged' => false];
        }

        $batchCount = count($missingIds);
        $this->logger->infoMessage(sprintf(
            "Found %s posts missing ngram entries. Adding %d of them in this batch...",
            $missingTotal === null ? 'an unknown number of' : (string)$missingTotal,
            $batchCount
        ));

        $result = $this->updateNGramsForPages($missingIds);

        $remaining = $missingTotal === null ? null : max(0, $missingTotal - $result['success']);
        $backlogged = $this->isBacklog($remaining, $batchCount, $batchSize);

        $this->logger->infoMessage(sprintf(
            "Ngram post sync: closed %d of %s missing entries this run; %s still missing.",
            $result['success'],
            $missingTotal === null ? 'an unknown number of' : (string)$missingTotal,
            $remaining === null ? 'an unknown number' : (string)$remaining
        ));

        return ['added' => $result['success'], 'failed' => $result['failed'],
            'missing_total' => $missingTotal, 'remaining' => $remaining, 'backlogged' => $backlogged];
    }

    /**
     * Count every permalink-cache row with no post-type n-gram entry.
     *
     * Deliberately NOT routed through queryScalarInt(): that helper returns 0
     * both for "nothing is missing" and for "the query failed", and those two
     * answers drive opposite recovery decisions here. Null means "unknown".
     *
     * @return int|null
     */
    private function countMissingPosts(string $ngramTable, string $permalinkCacheTable): ?int {
        $countResult = $this->dbCore->queryAndGetResults(
            "SELECT COUNT(*) AS c
             FROM {$permalinkCacheTable} pc
             LEFT JOIN {$ngramTable} ng ON pc.id = ng.id AND ng.type = 'post'
             WHERE ng.id IS NULL"
        );

        $countError = isset($countResult['last_error']) && is_string($countResult['last_error']) ? $countResult['last_error'] : '';
        if ($countError !== '') {
            if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($countError)) {
                $this->logger->warn("Could not measure the missing post ngram backlog: " . $countError);
            }
            return null;
        }

        $rows = isset($countResult['rows']) && is_array($countResult['rows']) ? $countResult['rows'] : [];
        if (empty($rows) || !is_array($rows[0])) {
            return null;
        }
        $first = reset($rows[0]);
        return is_scalar($first) ? (int)$first : null;
    }

    /**
     * DRIFT or BACKLOG: is what is left over more than this incremental path
     * can finish on its next run?
     *
     * The discriminator is the exact remaining row count, not the N-gram
     * coverage ratio. The ratio is ngram_cache rows over permalink_cache rows,
     * but ngram_cache also holds category and tag rows while permalink_cache
     * holds only posts and pages -- so a site with enough terms reports a
     * healthy ratio (11,000 posts + 1,100 tags over 12,028 permalinks = 1.006)
     * while a thousand posts are missing. The ratio is the harm signal that
     * gates the spell prefilter; it is structurally incapable of measuring
     * this backlog.
     *
     * A leftover of at most one batch converges on the next daily run, so it
     * stays here. Anything larger would take days-to-months at this batch size
     * and belongs to the bulk rebuild (1,000 rows per cron run, self
     * rescheduling). When the backlog could not be measured, a saturated batch
     * is the fallback signal: it proves this run could not see the end of the
     * gap.
     *
     * @param int|null $remaining Rows still missing after this run, null when unmeasurable.
     * @param int $batchCount Rows this run took out of the gap.
     * @param int $batchSize The per-run cap.
     * @return bool
     */
    private function isBacklog(?int $remaining, int $batchCount, int $batchSize): bool {
        if ($remaining === null) {
            return $batchCount >= $batchSize;
        }
        return $remaining > $batchSize;
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

    /** @return void */
    private function invalidateCoverageCaches(): void {
        $coveragePolicy = $this->coveragePolicy;
        if (!is_object($coveragePolicy) || !method_exists($coveragePolicy, 'invalidateCoverageCaches')) {
            throw new RuntimeException('NGramCacheReconciler requires a coverage policy with invalidateCoverageCaches().');
        }
        $coveragePolicy->invalidateCoverageCaches();
    }

}
