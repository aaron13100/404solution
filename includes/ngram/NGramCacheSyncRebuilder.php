<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Synchronous (blocking) full rebuild of the N-gram cache from
 * permalink_cache. Used by manual rebuilds and by the all-content
 * orchestrator's first phase.
 *
 * WARNING: this path is synchronous and can take minutes on large
 * sites. The cron-driven async rebuild
 * (NGramCacheRebuildScheduler::runAsyncBatch) is the standard path;
 * this collaborator is for manual tools and tests.
 *
 * Lock ownership: the orchestrator (DatabaseUpgradeNGram) acquires
 * the shared 'ngram_rebuild' SyncUtils lock before calling. This
 * collaborator assumes the lock is held and is therefore safe to
 * TRUNCATE the cache table.
 */
class ABJ_404_Solution_NGramCacheSyncRebuilder {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var mixed */
    private $rebuilder;

    /** @var mixed */
    private $coveragePolicy;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param mixed $rebuilder Object exposing rebuildCache().
     * @param mixed $coveragePolicy Object exposing invalidateCoverageCaches().
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($dbCore, $rebuilder, $coveragePolicy, $logger) {
        $this->dbCore = $dbCore;
        $this->rebuilder = $rebuilder;
        $this->coveragePolicy = $coveragePolicy;
        $this->logger = $logger;
    }

    /**
     * TRUNCATE the cache table and rebuild it from permalink_cache in
     * batches of $batchSize. Skips when the cache is already populated
     * unless $forceRebuild is true.
     *
     * @param int $batchSize
     * @param bool $forceRebuild
     * @return array<string, mixed> ['total_pages' => int, 'processed' => int, 'success' => int, 'failed' => int]
     */
    public function rebuild(int $batchSize = 100, bool $forceRebuild = false): array {
        $ngramTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        $permalinkCacheTable = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_permalink_cache');

        $alreadyPopulated = $this->checkAlreadyPopulated($ngramTable, $forceRebuild);
        if ($alreadyPopulated !== null) {
            return $alreadyPopulated;
        }

        $this->logger->debugMessage("Starting N-gram cache rebuild...");

        $truncateError = $this->truncateAndInvalidate($ngramTable);
        if ($truncateError !== null) {
            return $truncateError;
        }

        $totalPagesOrError = $this->countTotalPages($permalinkCacheTable);
        if (is_array($totalPagesOrError)) {
            return $totalPagesOrError;
        }
        $totalPages = $totalPagesOrError;

        if ($totalPages == 0) {
            $this->logger->debugMessage("No pages in permalink cache. N-gram cache rebuild skipped (will rebuild when pages are added).");
            return ['total_pages' => 0, 'processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $this->logger->infoMessage("Rebuilding N-gram cache for {$totalPages} pages in batches of {$batchSize}...");

        $totalStats = $this->runBatchedRebuild($batchSize, $totalPages);
        $totalStats['total_pages'] = $totalPages;

        $successRate = $totalStats['processed'] > 0 ?
            round(($totalStats['success'] / $totalStats['processed']) * 100, 1) : 0;

        $this->logger->infoMessage(sprintf(
            "N-gram cache rebuild complete: %d pages processed, %d success, %d failed (%.1f%% success rate)",
            $totalStats['processed'],
            $totalStats['success'],
            $totalStats['failed'],
            $successRate
        ));

        return $totalStats;
    }

    /**
     * @return array<string, mixed>|null populated-skip payload, or null when rebuild should proceed
     */
    private function checkAlreadyPopulated(string $ngramTable, bool $forceRebuild): ?array {
        if ($forceRebuild) {
            return null;
        }
        $existingCount = $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$ngramTable}");
        if ($existingCount <= 0) {
            return null;
        }
        $this->logger->debugMessage("N-gram cache already contains {$existingCount} entries. Skipping rebuild (use forceRebuild=true to override).");
        return [
            'total_pages' => $existingCount,
            'processed' => 0,
            'success' => $existingCount,
            'failed' => 0,
            'skipped' => true,
        ];
    }

    /**
     * TRUNCATE the cache table and invalidate coverage caches.
     *
     * @return array<string, mixed>|null error-result payload when truncate fails, or null on success
     */
    private function truncateAndInvalidate(string $ngramTable): ?array {
        // skip_repair: TRUNCATE itself is the recovery path during
        // rebuild; we must not recurse into the missing-table
        // repairer here.
        $truncateResult = $this->dbCore->queryAndGetResults(
            "TRUNCATE TABLE {$ngramTable}",
            ['skip_repair' => true]
        );
        $truncateError = isset($truncateResult['last_error']) && is_string($truncateResult['last_error']) ? $truncateResult['last_error'] : '';
        if ($truncateError !== '') {
            if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($truncateError)) {
                $this->logger->errorMessage("Failed to truncate N-gram cache table: " . $truncateError);
            }
            return ['total_pages' => 0, 'processed' => 0, 'success' => 0, 'failed' => 1, 'error' => $truncateError];
        }

        // Invalidate coverage ratio caches immediately after truncate
        // so SpellChecker does not see stale transient data while the
        // cache is empty.
        $this->invalidateCoverageCaches();
        return null;
    }

    /**
     * @return int|array<string, mixed> int on success, error-result payload on failure
     */
    private function countTotalPages(string $permalinkCacheTable) {
        $totalPagesResult = $this->dbCore->queryAndGetResults("SELECT COUNT(*) AS c FROM {$permalinkCacheTable}");
        $totalPagesRows = isset($totalPagesResult['rows']) && is_array($totalPagesResult['rows']) ? $totalPagesResult['rows'] : [];
        $totalPagesRow = $totalPagesRows[0] ?? null;

        if (!is_array($totalPagesRow) || !isset($totalPagesRow['c'])) {
            $countError = isset($totalPagesResult['last_error']) && is_string($totalPagesResult['last_error']) ? $totalPagesResult['last_error'] : '';
            if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($countError)) {
                $this->logger->errorMessage("Failed to query permalink cache table: " . $countError);
            }
            return ['total_pages' => 0, 'processed' => 0, 'success' => 0, 'failed' => 1, 'error' => $countError];
        }
        return is_scalar($totalPagesRow['c']) ? (int)$totalPagesRow['c'] : 0;
    }

    /**
     * @return array{processed:int, success:int, failed:int}
     */
    private function runBatchedRebuild(int $batchSize, int $totalPages): array {
        $offset = 0;
        $totalStats = ['processed' => 0, 'success' => 0, 'failed' => 0];

        while ($offset < $totalPages) {
            try {
                $stats = $this->runRebuildBatch($batchSize, $offset);

                $totalStats['processed'] += $stats['processed'];
                $totalStats['success'] += $stats['success'];
                $totalStats['failed'] += $stats['failed'];

                $offset += $batchSize;

                if ($stats['processed'] < $batchSize) {
                    break;
                }
            } catch (Exception $e) {
                $this->logger->errorMessage("Error during N-gram cache rebuild at offset {$offset}: " . $e->getMessage());
                $totalStats['failed'] += $batchSize;
                $offset += $batchSize;
            }
        }

        return $totalStats;
    }

    /** @return void */
    private function invalidateCoverageCaches(): void {
        $coveragePolicy = $this->coveragePolicy;
        if (!is_object($coveragePolicy) || !method_exists($coveragePolicy, 'invalidateCoverageCaches')) {
            throw new RuntimeException('NGramCacheSyncRebuilder requires a coverage policy with invalidateCoverageCaches().');
        }
        $coveragePolicy->invalidateCoverageCaches();
    }

    /**
     * @param int $batchSize
     * @param int $offset
     * @return array{processed: int, success: int, failed: int}
     */
    private function runRebuildBatch(int $batchSize, int $offset): array {
        $rebuilder = $this->rebuilder;
        if (!is_object($rebuilder) || !method_exists($rebuilder, 'rebuildCache')) {
            throw new RuntimeException('NGramCacheSyncRebuilder requires a rebuilder with rebuildCache().');
        }
        $stats = $rebuilder->rebuildCache($batchSize, $offset);
        return [
            'processed' => is_array($stats) && isset($stats['processed']) && is_numeric($stats['processed']) ? (int)$stats['processed'] : 0,
            'success' => is_array($stats) && isset($stats['success']) && is_numeric($stats['success']) ? (int)$stats['success'] : 0,
            'failed' => is_array($stats) && isset($stats['failed']) && is_numeric($stats['failed']) ? (int)$stats['failed'] : 0,
        ];
    }
}
