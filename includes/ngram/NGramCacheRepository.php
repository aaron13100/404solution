<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for the abj404_ngram_cache table.
 *
 * Owns reads (per-page lookup, full-table load with guard, two-range filtered
 * load, count, stats) and writes (REPLACE on store, DELETE on invalidate)
 * for stored N-gram entries. After every write, calls the coverage policy
 * (lazily resolved to avoid construction-time circular dependency, since
 * the policy can also call back into repository-shaped helpers) so the
 * coverage transient is invalidated in one place.
 *
 * The two-range query strategy in getCachedNGramsFiltered() avoids filesort
 * from ORDER BY ABS() by splitting into ASC and DESC halves and merging via
 * the proximity-merge primitive.
 */
class ABJ_404_Solution_NGramCacheRepository {

    /** Maximum entries to load from N-gram cache to prevent memory exhaustion.
     * JSON decode of N-gram data is memory-intensive; 1000 entries is safe for 128MB limit. */
    const CACHE_LOAD_LIMIT = 1000;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_NGramSimilarity */
    private $similarity;

    /** @var callable|null Lazily resolves the NGramCoveragePolicy for write-path invalidation. */
    private $coveragePolicyResolver;

    /** @var int|null Per-request memoized cache count */
    private $cacheCountMemo = null;

    /**
     * @param ABJ_404_Solution_DatabaseCore|null $dbCore
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_NGramSimilarity|null $similarity
     * @param callable|null $coveragePolicyResolver Called on write to fetch the coverage policy. Lazy to break ctor cycle.
     */
    public function __construct($dbCore = null, $logging = null, $similarity = null, $coveragePolicyResolver = null) {
        $this->dbCore = $dbCore !== null ? $dbCore : abj_service('db_core');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->similarity = $similarity instanceof ABJ_404_Solution_NGramSimilarity
            ? $similarity
            : new ABJ_404_Solution_NGramSimilarity();
        $this->coveragePolicyResolver = $coveragePolicyResolver;
    }

    /**
     * Store N-grams for a page.
     *
     * @param int $pageId
     * @param string $url
     * @param string $urlNormalized
     * @param array<string, mixed> $ngrams
     * @param string $type Entity type: 'post', 'page', 'category', 'tag'
     * @param bool $skipInvalidation Skip coverage invalidation (for bulk operations)
     * @return bool
     */
    public function storeNGrams($pageId, $url, $urlNormalized, $ngrams, $type = 'post', $skipInvalidation = false) {
        if (!is_numeric($pageId) || $pageId <= 0) {
            $this->logger->errorMessage("Invalid page ID for N-gram storage: " . var_export($pageId, true));
            return false;
        }

        if (!is_array($ngrams) || !isset($ngrams['bi']) || !isset($ngrams['tri'])) {
            $this->logger->errorMessage("Invalid N-gram structure for page ID {$pageId}");
            return false;
        }

        if (!is_array($ngrams['bi']) || !is_array($ngrams['tri'])) {
            $this->logger->errorMessage("Invalid N-gram array types for page ID {$pageId}");
            return false;
        }

        $ngramJson = json_encode($ngrams);
        if ($ngramJson === false) {
            $this->logger->errorMessage("Failed to JSON encode N-grams for page ID {$pageId}");
            return false;
        }

        $ngramCount = count($ngrams['bi']) + count($ngrams['tri']);
        $table = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');

        // REPLACE = DELETE + INSERT. Routed through DAO for timeout/retry/recovery.
        $queryResult = $this->dbCore->queryAndGetResults(
            "REPLACE INTO {$table} (id, type, url, url_normalized, ngrams, ngram_count, last_updated)
             VALUES (%d, %s, %s, %s, %s, %d, %d)",
            ['query_params' => [
                (int)$pageId,
                $type,
                $url,
                $urlNormalized,
                $ngramJson,
                $ngramCount,
                abj_clock()->wpNow(),
            ]]
        );

        $lastError = isset($queryResult['last_error']) && is_string($queryResult['last_error']) ? $queryResult['last_error'] : '';
        if ($lastError !== '') {
            global $wpdb;
            $dbName = isset($wpdb->dbname) && is_string($wpdb->dbname) ? $wpdb->dbname : '';
            $errorContext = sprintf(
                "Failed to store N-grams for page ID %d: %s, Table: %s, Prefix: %s, DB: %s",
                $pageId,
                $lastError,
                $table,
                $this->dbCore->tableNameResolver()->getLowercasePrefix(),
                $dbName
            );

            if (is_multisite()) {
                $errorContext .= sprintf(", Blog ID: %d", get_current_blog_id());
            }

            if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($lastError)) {
                $this->logger->errorMessage($errorContext);
            }
            return false;
        }

        $this->cacheCountMemo = null;
        if (!$skipInvalidation) {
            $this->invalidateCoverageCaches();
        }

        return true;
    }

    /**
     * Get N-grams for a specific page.
     *
     * @param int $pageId
     * @param string $type
     * @return array{bi: array<int, string>, tri: array<int, string>}|null
     */
    public function getNGramsForPage($pageId, $type = 'post') {
        $table = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');

        $queryResult = $this->dbCore->queryAndGetResults(
            "SELECT ngrams FROM {$table} WHERE id = %d AND type = %s",
            ['query_params' => [$pageId, $type]]
        );

        $rows = isset($queryResult['rows']) && is_array($queryResult['rows']) ? $queryResult['rows'] : [];
        $first = $rows[0] ?? null;
        if (!is_array($first) || !isset($first['ngrams']) || !is_string($first['ngrams'])) {
            return null;
        }

        $decoded = json_decode($first['ngrams'], true);
        if (!is_array($decoded) || !isset($decoded['bi'], $decoded['tri'])) {
            return null;
        }
        /** @var array{bi: array<int, string>, tri: array<int, string>} $decoded */
        return $decoded;
    }

    /**
     * Get all cached N-grams for similarity queries.
     *
     * Loads entire table into memory; only safe for small caches. Above
     * CACHE_LOAD_LIMIT (1000) callers should use getCachedNGramsFiltered().
     *
     * @param string|null $type When non-null, restrict the load to a single
     *        entity type ('post', 'category', 'tag', ...). Null preserves the
     *        historical all-types scan (the posts path is unaffected).
     * @return array<int, array<string, mixed>>
     */
    public function getAllCachedNGrams($type = null) {
        $table = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');

        $count = ($type !== null)
            ? $this->getCacheCountForType((string)$type)
            : $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$table}");
        if ($count > 10000) {
            $this->logger->errorMessage("CRITICAL: N-gram cache has {$count} entries. Cannot load into memory. Feature disabled for this request.");
            return [];
        }

        if ($count > 5000) {
            $this->logger->infoMessage("WARNING: N-gram cache has {$count} entries. This may cause memory issues.");
        }

        if ($type !== null) {
            $listResult = $this->dbCore->queryAndGetResults(
                "SELECT id, url, url_normalized, ngrams, ngram_count FROM {$table} WHERE type = %s",
                ['query_params' => [(string)$type]]
            );
        } else {
            $listResult = $this->dbCore->queryAndGetResults(
                "SELECT id, url, url_normalized, ngrams, ngram_count FROM {$table}"
            );
        }
        $results = isset($listResult['rows']) && is_array($listResult['rows']) ? $listResult['rows'] : [];

        if (empty($results)) {
            return [];
        }

        $output = [];
        foreach ($results as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            $ngramsRaw = isset($row['ngrams']) && is_string($row['ngrams']) ? $row['ngrams'] : '';
            $row['ngrams'] = json_decode($ngramsRaw, true);
            $output[] = $row;
        }

        return $output;
    }

    /**
     * Get cached N-grams with database-side range filtering.
     *
     * Two-range strategy avoids filesort from ORDER BY ABS(): splits the
     * query into below-target (DESC) and above-target (ASC), then merges
     * by proximity to target in PHP.
     *
     * @param int $minNgramCount
     * @param int $maxNgramCount
     * @param int $limit
     * @param int|null $targetNgramCount
     * @param string|null $type When non-null, restrict to a single entity type
     *        ('post', 'category', 'tag', ...). Null preserves the all-types scan.
     * @return array<int, array<string, mixed>>
     */
    public function getCachedNGramsFiltered($minNgramCount, $maxNgramCount, $limit = 1000, $targetNgramCount = null, $type = null) {
        $table = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');

        $orderTarget = ($targetNgramCount !== null)
            ? max($minNgramCount, min($maxNgramCount, (int)$targetNgramCount))
            : (int)(($minNgramCount + $maxNgramCount) / 2);

        $halfLimit = (int)ceil($limit / 2);

        $resultsBelow = $this->fetchBelowTarget($table, $minNgramCount, $orderTarget, $halfLimit, 0, $type);
        $belowCount = count($resultsBelow);
        $aboveLimit = $limit - $belowCount;
        $resultsAbove = $this->fetchAboveTarget($table, $orderTarget, $maxNgramCount, $aboveLimit, 0, $type);
        $aboveCount = count($resultsAbove);

        $totalFetched = $belowCount + $aboveCount;
        // Balance for skewed distributions: if one side hit its cap and the
        // other has headroom, pull more from the saturated side.
        if ($totalFetched < $limit && $belowCount === $halfLimit) {
            $extra = $this->fetchBelowTarget($table, $minNgramCount, $orderTarget, $limit - $totalFetched, $belowCount, $type);
            $resultsBelow = array_merge($resultsBelow, $extra);
            $totalFetched = count($resultsBelow) + $aboveCount;
        }
        if ($totalFetched < $limit && $aboveCount === $aboveLimit) {
            $extra = $this->fetchAboveTarget($table, $orderTarget, $maxNgramCount, $limit - $totalFetched, $aboveCount, $type);
            $resultsAbove = array_merge($resultsAbove, $extra);
        }

        $merged = $this->similarity->mergeByProximity($resultsBelow, $resultsAbove, $orderTarget, $limit);
        return $this->decodeNGramRows($merged);
    }

    /**
     * @param string $table
     * @param int $minNgramCount
     * @param int $orderTarget
     * @param int $limit
     * @param int $offset
     * @param string|null $type Optional single-type restriction.
     * @return array<int, mixed>
     */
    private function fetchBelowTarget($table, $minNgramCount, $orderTarget, $limit, $offset = 0, $type = null) {
        $typeClause = ($type !== null) ? " AND type = %s" : '';
        $params = ($type !== null)
            ? [$minNgramCount, $orderTarget, (string)$type, $limit, $offset]
            : [$minNgramCount, $orderTarget, $limit, $offset];
        $result = $this->dbCore->queryAndGetResults(
            "SELECT id, url, url_normalized, ngrams, ngram_count
             FROM {$table}
             WHERE ngram_count >= %d AND ngram_count <= %d{$typeClause}
             ORDER BY ngram_count DESC
             LIMIT %d OFFSET %d",
            ['query_params' => $params]
        );
        return isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
    }

    /**
     * @param string $table
     * @param int $orderTarget
     * @param int $maxNgramCount
     * @param int $limit
     * @param int $offset
     * @param string|null $type Optional single-type restriction.
     * @return array<int, mixed>
     */
    private function fetchAboveTarget($table, $orderTarget, $maxNgramCount, $limit, $offset = 0, $type = null) {
        $typeClause = ($type !== null) ? " AND type = %s" : '';
        $params = ($type !== null)
            ? [$orderTarget, $maxNgramCount, (string)$type, $limit, $offset]
            : [$orderTarget, $maxNgramCount, $limit, $offset];
        $result = $this->dbCore->queryAndGetResults(
            "SELECT id, url, url_normalized, ngrams, ngram_count
             FROM {$table}
             WHERE ngram_count > %d AND ngram_count <= %d{$typeClause}
             ORDER BY ngram_count ASC
             LIMIT %d OFFSET %d",
            ['query_params' => $params]
        );
        return isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function decodeNGramRows(array $rows) {
        $validResults = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ngramsJson = isset($row['ngrams']) && is_string($row['ngrams']) ? $row['ngrams'] : '';
            $decoded = json_decode($ngramsJson, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $rowId = isset($row['id']) ? $row['id'] : 0;
                $this->logger->errorMessage(sprintf(
                    "Corrupt N-gram JSON for page ID %s: %s",
                    (is_scalar($rowId) ? (string)$rowId : '0'),
                    json_last_error_msg()
                ));
                continue;
            }
            $row['ngrams'] = $decoded;
            $validResults[] = $row;
        }
        return $validResults;
    }

    /**
     * Invalidate (delete) N-grams for a specific page.
     *
     * @param int $pageId
     * @param string $type
     * @return bool
     */
    public function invalidatePage($pageId, $type = 'post') {
        $table = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        $queryResult = $this->dbCore->queryAndGetResults(
            "DELETE FROM {$table} WHERE id = %d AND type = %s",
            ['query_params' => [(int)$pageId, $type]]
        );

        $lastError = isset($queryResult['last_error']) && is_string($queryResult['last_error']) ? $queryResult['last_error'] : '';
        $success = $lastError === '';
        if ($success) {
            $this->cacheCountMemo = null;
            $this->invalidateCoverageCaches();
        }

        return $success;
    }

    /**
     * Get cache entry count (memoized per-request).
     *
     * Use this instead of getCacheStats() when only the count is needed.
     *
     * @return int
     */
    public function getCacheCount() {
        if ($this->cacheCountMemo !== null) {
            return $this->cacheCountMemo;
        }

        global $wpdb;
        $table = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        if (!isset($wpdb) || !is_object($wpdb) || !is_callable([$wpdb, 'get_var'])) {
            // Test environments / very early bootstrap: treat as no cache.
            $this->cacheCountMemo = 0;
            return $this->cacheCountMemo;
        }

        $this->cacheCountMemo = $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$table}");
        return $this->cacheCountMemo;
    }

    /**
     * Count cache entries of a single type ('post', 'category', 'tag', ...).
     *
     * Used by the type-scoped term prefilter to make its load-limit decision
     * and to feed the term coverage policy's readiness gate. Not memoized:
     * the term path calls this at most twice per request (count + ratio),
     * both against the indexed `type` column.
     *
     * @param string $type
     * @return int
     */
    public function getCacheCountForType(string $type): int {
        $table = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');
        return $this->dbCore->queryScalarInt(
            "SELECT COUNT(*) AS c FROM {$table} WHERE type = %s",
            ['query_params' => [$type]]
        );
    }

    /**
     * Reset per-request memoization.
     *
     * Used after bulk operations that bypass storeNGrams() (e.g. TRUNCATE
     * during rebuild) so the next getCacheCount() reads fresh state.
     *
     * @return void
     */
    public function resetMemo() {
        $this->cacheCountMemo = null;
    }

    /**
     * Get cache statistics for admin display.
     *
     * @return array<string, mixed>
     */
    public function getCacheStats() {
        $table = $this->dbCore->tableNameResolver()->getPrefixedTableName('abj404_ngram_cache');

        $totalEntries = $this->dbCore->queryScalarInt("SELECT COUNT(*) AS c FROM {$table}");
        $postsEntries = $this->getCacheCountForType('post');
        $categoryEntries = $this->getCacheCountForType('category');
        $tagEntries = $this->getCacheCountForType('tag');
        $lastUpdatedResult = $this->dbCore->queryAndGetResults(
            "SELECT MAX(last_updated) AS m FROM {$table}"
        );
        $lastUpdatedRows = isset($lastUpdatedResult['rows']) && is_array($lastUpdatedResult['rows']) ? $lastUpdatedResult['rows'] : [];
        $lastUpdatedFirst = $lastUpdatedRows[0] ?? null;
        $lastUpdated = is_array($lastUpdatedFirst) && isset($lastUpdatedFirst['m']) ? $lastUpdatedFirst['m'] : null;

        return [
            'total_entries' => $totalEntries,
            'posts_entries' => $postsEntries,
            'category_entries' => $categoryEntries,
            'tag_entries' => $tagEntries,
            'last_updated' => $lastUpdated,
        ];
    }

    /**
     * Invalidate coverage caches by resolving the policy lazily.
     *
     * Lazy resolution avoids constructor-time coupling between the
     * repository and coverage policy services.
     *
     * @return void
     */
    private function invalidateCoverageCaches() {
        $resolver = $this->coveragePolicyResolver;
        if (!is_callable($resolver)) {
            return;
        }
        $policy = $resolver();
        if ($policy instanceof ABJ_404_Solution_NGramCoveragePolicy) {
            $policy->invalidateCoverageCaches();
        }
    }
}
