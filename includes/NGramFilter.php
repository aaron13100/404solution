<?php

/**
 * N-Gram based filtering for spell checker optimization.
 *
 * This class provides N-gram extraction, similarity computation, and caching
 * to reduce Levenshtein distance calculations from 100-300 calls to <50 calls
 * on large sites by pre-filtering candidates based on character overlap.
 *
 * Architecture: Database-backed N-gram cache for scalability.
 * - Pre-computes N-grams for all existing pages (background process)
 * - Computes N-grams for 404 URL only in real-time (~0.1ms)
 * - Uses Dice coefficient similarity to filter candidates (50-100ms)
 * - Reduces Levenshtein calls by 5-10x on large sites
 */
class ABJ_404_Solution_NGramFilter {

    private static $instance = null;

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * Constructor with dependency injection.
     *
     * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
     * @param ABJ_404_Solution_Logging|null $logging Logging service
     * @param ABJ_404_Solution_Functions|null $functions String utilities
     */
    public function __construct($dataAccess = null, $logging = null, $functions = null) {
        // Use injected dependencies or fall back to getInstance() for backward compatibility
        $this->dao = $dataAccess !== null ? $dataAccess : ABJ_404_Solution_DataAccess::getInstance();
        $this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
        $this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_NGramFilter();
        }

        return self::$instance;
    }

    /**
     * Extract N-grams from a URL string.
     *
     * Generates both bigrams (n=2) and trigrams (n=3) for optimal accuracy.
     * Research shows using both provides better typo detection than either alone.
     *
     * @param string $url The URL to extract N-grams from
     * @param array $ngramSizes Array of N-gram sizes to extract (default: [2, 3])
     * @return array Associative array with keys 'bi' and 'tri' containing arrays of N-grams
     *
     * Example:
     *   Input: "product"
     *   Output: [
     *     'bi' => ['pr', 'ro', 'od', 'du', 'uc', 'ct'],
     *     'tri' => ['pro', 'rod', 'odu', 'duc', 'uct']
     *   ]
     */
    public function extractNGrams($url, $ngramSizes = [2, 3]) {
        if (empty($url)) {
            return ['bi' => [], 'tri' => []];
        }

        // Normalize: lowercase and use mbstring for UTF-8 support
        $url = $this->f->strtolower($url);

        $result = [];
        $length = $this->f->strlen($url);

        foreach ($ngramSizes as $n) {
            $ngrams = [];

            // Extract all N-grams of size n
            for ($i = 0; $i <= $length - $n; $i++) {
                $ngram = $this->f->substr($url, $i, $n);
                // Use array keys for automatic deduplication
                $ngrams[$ngram] = true;
            }

            // Store under 'bi' for n=2, 'tri' for n=3
            $key = ($n == 2) ? 'bi' : 'tri';
            $result[$key] = array_keys($ngrams);
        }

        return $result;
    }

    /**
     * Compute Dice coefficient similarity between two N-gram sets.
     *
     * Dice coefficient: 2 * |intersection| / (|set1| + |set2|)
     * Range: 0.0 (no overlap) to 1.0 (identical)
     *
     * Threshold correlation:
     * - 0.4 = ~30% edit distance (recommended)
     * - 0.5 = ~20% edit distance
     * - 0.6 = ~10% edit distance
     *
     * @param array $ngrams1 First N-gram set (format: ['bi' => [...], 'tri' => [...]])
     * @param array $ngrams2 Second N-gram set
     * @return float Similarity score between 0.0 and 1.0
     */
    public function diceCoefficient($ngrams1, $ngrams2) {
        // Combine bigrams and trigrams for similarity computation
        $set1 = array_merge(
            isset($ngrams1['bi']) ? $ngrams1['bi'] : [],
            isset($ngrams1['tri']) ? $ngrams1['tri'] : []
        );
        $set2 = array_merge(
            isset($ngrams2['bi']) ? $ngrams2['bi'] : [],
            isset($ngrams2['tri']) ? $ngrams2['tri'] : []
        );

        // Handle empty sets
        if (empty($set1) || empty($set2)) {
            return 0.0;
        }

        // Convert to associative arrays for fast lookup
        $set1 = array_flip($set1);
        $set2 = array_flip($set2);

        // Count intersection
        $intersection = count(array_intersect_key($set1, $set2));

        // Dice coefficient: 2 * |intersection| / (|set1| + |set2|)
        $dice = (2.0 * $intersection) / (count($set1) + count($set2));

        return $dice;
    }

    /**
     * Store N-grams for a page in the database.
     *
     * @param int $pageId The page/post ID
     * @param string $url Original URL
     * @param string $urlNormalized Normalized URL for matching
     * @param array $ngrams N-gram data (format: ['bi' => [...], 'tri' => [...]])
     * @return bool Success status
     */
    public function storeNGrams($pageId, $url, $urlNormalized, $ngrams) {
        global $wpdb;

        $ngramJson = json_encode($ngrams);
        $ngramCount = count($ngrams['bi']) + count($ngrams['tri']);

        $table = $wpdb->prefix . 'abj404_ngram_cache';

        // Use REPLACE to handle updates (REPLACE = DELETE + INSERT)
        $result = $wpdb->replace(
            $table,
            [
                'page_id' => $pageId,
                'url' => $url,
                'url_normalized' => $urlNormalized,
                'ngrams' => $ngramJson,
                'ngram_count' => $ngramCount,
                'last_updated' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );

        if ($result === false) {
            $this->logger->errorMessage("Failed to store N-grams for page ID {$pageId}: " . $wpdb->last_error);
            return false;
        }

        return true;
    }

    /**
     * Get N-grams for a specific page.
     *
     * @param int $pageId The page/post ID
     * @return array|null N-gram data or null if not found
     */
    public function getNGramsForPage($pageId) {
        global $wpdb;

        $table = $wpdb->prefix . 'abj404_ngram_cache';
        $query = $wpdb->prepare(
            "SELECT ngrams FROM {$table} WHERE page_id = %d",
            $pageId
        );

        $result = $wpdb->get_var($query);

        if ($result === null) {
            return null;
        }

        return json_decode($result, true);
    }

    /**
     * Get all cached N-grams for similarity queries.
     *
     * @return array Array of cached entries with page_id, url, url_normalized, and ngrams
     */
    public function getAllCachedNGrams() {
        global $wpdb;

        $table = $wpdb->prefix . 'abj404_ngram_cache';
        $query = "SELECT page_id, url, url_normalized, ngrams, ngram_count FROM {$table}";

        $results = $wpdb->get_results($query, ARRAY_A);

        // Decode JSON for each entry
        foreach ($results as &$row) {
            $row['ngrams'] = json_decode($row['ngrams'], true);
        }

        return $results;
    }

    /**
     * Invalidate (delete) N-grams for a specific page.
     * Call this when a page is updated or deleted.
     *
     * @param int $pageId The page/post ID
     * @return bool Success status
     */
    public function invalidatePage($pageId) {
        global $wpdb;

        $table = $wpdb->prefix . 'abj404_ngram_cache';
        $result = $wpdb->delete($table, ['page_id' => $pageId], ['%d']);

        return $result !== false;
    }

    /**
     * Rebuild the N-gram cache for all pages (background process).
     *
     * This method processes pages in batches to avoid memory issues and timeouts.
     * Should be called during permalink cache updates or as a scheduled task.
     *
     * @param int $batchSize Number of pages to process per batch (default: 100)
     * @param int $offset Starting offset for pagination (default: 0)
     * @return array Statistics: ['processed' => int, 'success' => int, 'failed' => int]
     */
    public function rebuildCache($batchSize = 100, $offset = 0) {
        global $wpdb;

        $permalinkCacheTable = $wpdb->prefix . 'abj404_permalink_cache';

        // Get a batch of pages from permalink cache
        $query = $wpdb->prepare(
            "SELECT id, url FROM {$permalinkCacheTable} LIMIT %d OFFSET %d",
            $batchSize,
            $offset
        );

        $pages = $wpdb->get_results($query, ARRAY_A);

        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0
        ];

        foreach ($pages as $page) {
            $pageId = $page['id'];
            $url = $page['url'];

            // Normalize URL for matching (lowercase, trim)
            $urlNormalized = $this->f->strtolower(trim($url));

            // Extract N-grams
            $ngrams = $this->extractNGrams($urlNormalized);

            // Store in database
            $success = $this->storeNGrams($pageId, $url, $urlNormalized, $ngrams);

            $stats['processed']++;
            if ($success) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
        }

        $this->logger->debugMessage(sprintf(
            "N-gram cache rebuild batch (offset %d): %d processed, %d success, %d failed",
            $offset,
            $stats['processed'],
            $stats['success'],
            $stats['failed']
        ));

        return $stats;
    }

    /**
     * Find pages similar to a 404 URL using N-gram filtering.
     *
     * This is the main method called by SpellChecker to reduce candidates.
     *
     * Process:
     * 1. Extract N-grams for the 404 URL (~0.1ms)
     * 2. Load all cached N-grams from DB (10-50ms, one query)
     * 3. Compute Dice similarity for each (~0.05ms each)
     * 4. Filter by minimum similarity threshold (removes 80-90%)
     * 5. Sort by similarity (best matches first)
     * 6. Return top N candidates
     *
     * @param string $url404 The 404 URL to find matches for
     * @param float $minSimilarity Minimum Dice coefficient (default: 0.4)
     * @param int $maxCandidates Maximum candidates to return (default: 100)
     * @return array Associative array [page_id => similarity_score] sorted by score (descending)
     */
    public function findSimilarPages($url404, $minSimilarity = 0.4, $maxCandidates = 100) {
        // Start timing for performance tracking
        $startTime = microtime(true);

        // Step 1: Extract N-grams for the 404 URL
        $url404Normalized = $this->f->strtolower(trim($url404));
        $queryNGrams = $this->extractNGrams($url404Normalized);

        // Step 2: Load all cached N-grams
        $cachedPages = $this->getAllCachedNGrams();

        if (empty($cachedPages)) {
            $this->logger->debugMessage("N-gram cache is empty. Run rebuildCache() first.");
            return [];
        }

        // Step 3: Compute similarity for each page
        $similarities = [];
        foreach ($cachedPages as $page) {
            $pageId = $page['page_id'];
            $pageNGrams = $page['ngrams'];

            // Quick optimization: Skip if N-gram counts are too different
            $queryCombinedCount = count($queryNGrams['bi']) + count($queryNGrams['tri']);
            $pageCombinedCount = $page['ngram_count'];

            // If one set is less than 40% the size of the other, they can't be similar enough
            $countRatio = min($queryCombinedCount, $pageCombinedCount) / max($queryCombinedCount, $pageCombinedCount);
            if ($countRatio < 0.4) {
                continue;
            }

            // Compute Dice coefficient
            $similarity = $this->diceCoefficient($queryNGrams, $pageNGrams);

            // Step 4: Filter by minimum similarity
            if ($similarity >= $minSimilarity) {
                $similarities[$pageId] = $similarity;
            }
        }

        // Step 5: Sort by similarity (descending)
        arsort($similarities);

        // Step 6: Limit to top N candidates
        if (count($similarities) > $maxCandidates) {
            $similarities = array_slice($similarities, 0, $maxCandidates, true);
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $this->logger->debugMessage(sprintf(
            "N-gram filtering: %d cached pages → %d candidates (≥%.2f similarity) in %.2fms",
            count($cachedPages),
            count($similarities),
            $minSimilarity,
            $duration
        ));

        return $similarities;
    }

    /**
     * Check if the N-gram cache is populated.
     *
     * @return bool True if cache has entries, false otherwise
     */
    public function isCachePopulated() {
        global $wpdb;

        $table = $wpdb->prefix . 'abj404_ngram_cache';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return $count > 0;
    }

    /**
     * Get cache statistics for admin display.
     *
     * @return array Statistics: ['total_entries' => int, 'last_updated' => string]
     */
    public function getCacheStats() {
        global $wpdb;

        $table = $wpdb->prefix . 'abj404_ngram_cache';

        $stats = [
            'total_entries' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'last_updated' => $wpdb->get_var("SELECT MAX(last_updated) FROM {$table}")
        ];

        return $stats;
    }
}
