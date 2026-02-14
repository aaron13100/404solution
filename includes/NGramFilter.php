<?php


if (!defined('ABSPATH')) {
    exit;
}

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

    /** Maximum entries to load from N-gram cache to prevent memory exhaustion.
     * JSON decode of N-gram data is memory-intensive; 1000 entries is safe for 128MB limit. */
    const CACHE_LOAD_LIMIT = 1000;

    /** Cache TTL for coverage ratio transient (seconds).
     * Prevents per-request COUNT(*) queries on large sites under 404 bursts. */
    const COVERAGE_RATIO_CACHE_TTL = 300; // 5 minutes

    /** TTL for coverage version transient (seconds).
     * Version persists across ratio TTL cycles; 1 day is sufficient. */
    const COVERAGE_VERSION_TTL = 86400; // 1 day

    /** Transient key for coverage ratio cache version.
     * Version is a timestamp; cached ratios with older timestamps are stale. */
    const COVERAGE_VERSION_KEY = 'abj404_ngram_coverage_version';

    /** Transient key for coverage ratio cache data. */
    const COVERAGE_RATIO_KEY = 'abj404_ngram_coverage_ratio';

    private static $instance = null;

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var int|null Per-request memoized N-gram cache count */
    private $ngramCountMemo = null;

    /** @var array|null Per-request memoized coverage ratio data ['ratio' => float, 'ngram_count' => int, 'permalink_count' => int] */
    private $coverageRatioMemo = null;

    /**
     * Invalidate coverage ratio caches (transient and per-request memos).
     * Call this whenever N-gram or permalink counts change, including after
     * TRUNCATE operations during cache rebuilds.
     *
     * Uses timestamp-based versioning: sets version to current time().
     * Cached ratios with older timestamps are stale. This approach is:
     * - Overflow-safe: no accumulating counter
     * - Race-safe: concurrent invalidations both write current time
     */
    public function invalidateCoverageCaches() {
        // Set version to current timestamp (race-safe: concurrent writes both invalidate)
        set_transient(self::COVERAGE_VERSION_KEY, time(), self::COVERAGE_VERSION_TTL);

        // Also delete the ratio transient to force immediate recompute
        delete_transient(self::COVERAGE_RATIO_KEY);

        // Clear per-request memos
        $this->ngramCountMemo = null;
        $this->coverageRatioMemo = null;
    }

    /**
     * Check if the N-gram cache is initialized (multisite-aware).
     *
     * On multisite, checks both get_site_option() (network activation) and
     * get_option() (per-site activation) since we can't reliably determine
     * activation mode on frontend requests where is_plugin_active_for_network()
     * isn't available.
     *
     * @return bool True if cache is initialized
     */
    public function isCacheInitialized() {
        $optionName = 'abj404_ngram_cache_initialized';

        if (is_multisite()) {
            // Check site option first (network activation stores here)
            // Then fall back to per-site option (per-site activation stores here)
            // This handles both activation modes without requiring admin functions
            $siteValue = get_site_option($optionName);
            if ($siteValue === '1') {
                return true;
            }
            // Fall through to check per-site option
        }

        return get_option($optionName) === '1';
    }

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

        // Limit URL length to prevent excessive N-gram generation
        // Most real URLs are < 200 chars. Limiting to 500 prevents:
        // - Memory exhaustion (4000+ N-grams for 2083 char URLs)
        // - Slow JSON encoding/decoding
        // - Database bloat
        $maxLength = 500;
        $originalLength = $this->f->strlen($url);
        if ($originalLength > $maxLength) {
            $this->logger->infoMessage("WARNING: URL too long for N-gram extraction: {$originalLength} chars, truncating to {$maxLength}. URL: " . $this->f->substr($url, 0, 100) . "...");
            $url = $this->f->substr($url, 0, $maxLength);
        }

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
            // Convert keys to strings to prevent PHP from converting numeric strings to integers
            $result[$key] = array_map('strval', array_keys($ngrams));
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
     * @param string $type Entity type: 'post', 'page', 'category', 'tag' (default: 'post')
     * @param bool $skipInvalidation Skip cache invalidation (for bulk operations)
     * @return bool Success status
     */
    public function storeNGrams($pageId, $url, $urlNormalized, $ngrams, $type = 'post', $skipInvalidation = false) {
        // Input validation
        if (!is_numeric($pageId) || $pageId <= 0) {
            $this->logger->errorMessage("Invalid page ID for N-gram storage: " . var_export($pageId, true));
            return false;
        }

        if (!is_string($url) || !is_string($urlNormalized)) {
            $this->logger->errorMessage("Invalid URL type for N-gram storage (page ID {$pageId})");
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

        global $wpdb;

        $ngramJson = json_encode($ngrams);
        if ($ngramJson === false) {
            $this->logger->errorMessage("Failed to JSON encode N-grams for page ID {$pageId}");
            return false;
        }

        $ngramCount = count($ngrams['bi']) + count($ngrams['tri']);

        $table = $this->dao->getPrefixedTableName('abj404_ngram_cache');

        // Use REPLACE to handle updates (REPLACE = DELETE + INSERT)
        $result = $wpdb->replace(
            $table,
            [
                'id' => (int)$pageId,
                'type' => $type,
                'url' => $url,
                'url_normalized' => $urlNormalized,
                'ngrams' => $ngramJson,
                'ngram_count' => $ngramCount,
                'last_updated' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        if ($result === false) {
            // Enhanced error message with multisite context and table details
            $errorContext = sprintf(
                "Failed to store N-grams for page ID %d: %s, Table: %s, Prefix: %s, DB: %s",
                $pageId,
                $wpdb->last_error,
                $table,
                $this->dao->getLowercasePrefix(),
                $wpdb->dbname
            );

            // Add multisite context if applicable
            if (is_multisite()) {
                $errorContext .= sprintf(", Blog ID: %d", get_current_blog_id());
            }

            $this->logger->errorMessage($errorContext);
            return false;
        }

        // Invalidate coverage ratio caches since N-gram count changed
        // (skip during bulk operations for efficiency)
        if (!$skipInvalidation) {
            $this->invalidateCoverageCaches();
        }

        return true;
    }

    /**
     * Get N-grams for a specific page.
     *
     * @param int $pageId The page/post ID
     * @param string $type Entity type: 'post', 'page', 'category', 'tag' (default: 'post')
     * @return array|null N-gram data or null if not found
     */
    public function getNGramsForPage($pageId, $type = 'post') {
        global $wpdb;

        $table = $this->dao->getPrefixedTableName('abj404_ngram_cache');
        $query = $wpdb->prepare(
            "SELECT ngrams FROM {$table} WHERE id = %d AND type = %s",
            $pageId,
            $type
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
     * DEPRECATED: This method loads all entries into memory and should not be used
     * on large sites. Use findSimilarPagesEfficient() instead for sites with > 1000 pages.
     *
     * @deprecated Use database-side filtering for large sites
     * @return array Array of cached entries with id, url, url_normalized, and ngrams
     */
    public function getAllCachedNGrams() {
        global $wpdb;

        $table = $this->dao->getPrefixedTableName('abj404_ngram_cache');

        // Check cache size first - abort if too large
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 10000) {
            $this->logger->errorMessage("CRITICAL: N-gram cache has {$count} entries. Cannot load into memory. Feature disabled for this request.");
            return [];
        }

        if ($count > 5000) {
            $this->logger->infoMessage("WARNING: N-gram cache has {$count} entries. This may cause memory issues.");
        }

        $query = "SELECT id, url, url_normalized, ngrams, ngram_count FROM {$table}";

        $results = $wpdb->get_results($query, ARRAY_A);

        if (!is_array($results)) {
            return [];
        }

        // Decode JSON for each entry and ensure array format
        foreach ($results as &$row) {
            // Handle both object and array results (defensive coding for test environments)
            if (is_object($row)) {
                $row = (array) $row;
            }
            $row['ngrams'] = json_decode($row['ngrams'], true);
        }

        return $results;
    }

    /**
     * Get cached N-grams efficiently with database-side filtering.
     *
     * Uses two-range query strategy to avoid filesort from ORDER BY ABS().
     * Splits query into below-target (DESC) and above-target (ASC), then merges
     * results by proximity to target in PHP.
     *
     * @param int $minNgramCount Minimum N-gram count (for filtering dissimilar pages)
     * @param int $maxNgramCount Maximum N-gram count
     * @param int $limit Maximum number of results to return
     * @param int $targetNgramCount The query's actual N-gram count for proximity ordering
     * @return array Array of cached entries
     */
    public function getCachedNGramsFiltered($minNgramCount, $maxNgramCount, $limit = 1000, $targetNgramCount = null) {
        global $wpdb;

        $table = $this->dao->getPrefixedTableName('abj404_ngram_cache');

        // Clamp target to valid range; fall back to midpoint if not provided
        $orderTarget = ($targetNgramCount !== null)
            ? max($minNgramCount, min($maxNgramCount, (int)$targetNgramCount))
            : (int)(($minNgramCount + $maxNgramCount) / 2);

        $halfLimit = (int)ceil($limit / 2);

        // Query 1: ngram_count <= target, ORDER BY ngram_count DESC
        // Uses idx_ngram_count for both range scan and sort (no filesort)
        $queryBelow = $wpdb->prepare(
            "SELECT id, url, url_normalized, ngrams, ngram_count
             FROM {$table}
             WHERE ngram_count >= %d AND ngram_count <= %d
             ORDER BY ngram_count DESC
             LIMIT %d",
            $minNgramCount,
            $orderTarget,
            $halfLimit
        );
        $resultsBelow = $wpdb->get_results($queryBelow, ARRAY_A) ?: [];

        // Query 2: above target - adjust limit based on below results to handle skewed distributions
        // If below side returned fewer than halfLimit, give the remainder to above side
        $belowCount = count($resultsBelow);
        $aboveLimit = $limit - $belowCount;

        $queryAbove = $wpdb->prepare(
            "SELECT id, url, url_normalized, ngrams, ngram_count
             FROM {$table}
             WHERE ngram_count > %d AND ngram_count <= %d
             ORDER BY ngram_count ASC
             LIMIT %d",
            $orderTarget,
            $maxNgramCount,
            $aboveLimit
        );
        $resultsAbove = $wpdb->get_results($queryAbove, ARRAY_A) ?: [];

        // If we didn't get enough results, fetch additional from whichever side hit its limit
        $aboveCount = count($resultsAbove);
        $totalFetched = $belowCount + $aboveCount;

        if ($totalFetched < $limit && $belowCount === $halfLimit) {
            // Below hit its limit, might have more rows - fetch additional
            $additionalNeeded = $limit - $totalFetched;
            $queryBelowExtra = $wpdb->prepare(
                "SELECT id, url, url_normalized, ngrams, ngram_count
                 FROM {$table}
                 WHERE ngram_count >= %d AND ngram_count <= %d
                 ORDER BY ngram_count DESC
                 LIMIT %d OFFSET %d",
                $minNgramCount,
                $orderTarget,
                $additionalNeeded,
                $belowCount
            );
            $extraBelow = $wpdb->get_results($queryBelowExtra, ARRAY_A) ?: [];
            $resultsBelow = array_merge($resultsBelow, $extraBelow);
            $totalFetched = count($resultsBelow) + $aboveCount;
        }

        if ($totalFetched < $limit && $aboveCount === $aboveLimit) {
            // Above hit its limit, might have more rows - fetch additional
            $additionalNeeded = $limit - $totalFetched;
            $queryAboveExtra = $wpdb->prepare(
                "SELECT id, url, url_normalized, ngrams, ngram_count
                 FROM {$table}
                 WHERE ngram_count > %d AND ngram_count <= %d
                 ORDER BY ngram_count ASC
                 LIMIT %d OFFSET %d",
                $orderTarget,
                $maxNgramCount,
                $additionalNeeded,
                $aboveCount
            );
            $extraAbove = $wpdb->get_results($queryAboveExtra, ARRAY_A) ?: [];
            $resultsAbove = array_merge($resultsAbove, $extraAbove);
        }

        // Merge results by proximity to target
        $merged = $this->mergeByProximity($resultsBelow, $resultsAbove, $orderTarget, $limit);

        // Decode JSON for each entry, filtering out corrupt entries
        $validResults = [];
        foreach ($merged as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            $decoded = json_decode($row['ngrams'], true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->errorMessage(sprintf(
                    "Corrupt N-gram JSON for page ID %d: %s",
                    $row['id'] ?? 0,
                    json_last_error_msg()
                ));
                continue; // Skip corrupt entry
            }
            $row['ngrams'] = $decoded;
            $validResults[] = $row;
        }

        return $validResults;
    }

    /**
     * Merge two arrays sorted by proximity to target, interleaving results.
     *
     * Both input arrays must be pre-sorted by proximity to the target:
     * - $below: ngram_count <= target, ordered DESC by ngram_count (closest first)
     * - $above: ngram_count > target, ordered ASC by ngram_count (closest first)
     *
     * @param array $below Results with ngram_count <= target
     * @param array $above Results with ngram_count > target
     * @param int $targetNgramCount The target N-gram count
     * @param int $limit Maximum results to return
     * @return array Merged results ordered by proximity to target
     */
    private function mergeByProximity($below, $above, $targetNgramCount, $limit) {
        $result = [];
        $i = 0;
        $j = 0;
        $belowCount = count($below);
        $aboveCount = count($above);

        while (count($result) < $limit && ($i < $belowCount || $j < $aboveCount)) {
            // Calculate distances (use PHP_INT_MAX as sentinel for exhausted arrays)
            $distBelow = ($i < $belowCount)
                ? abs($below[$i]['ngram_count'] - $targetNgramCount)
                : PHP_INT_MAX;
            $distAbove = ($j < $aboveCount)
                ? abs($above[$j]['ngram_count'] - $targetNgramCount)
                : PHP_INT_MAX;

            // Pick the entry closer to target; prefer below on tie (includes exact matches)
            if ($distBelow <= $distAbove) {
                $result[] = $below[$i];
                $i++;
            } else {
                $result[] = $above[$j];
                $j++;
            }
        }

        return $result;
    }

    /**
     * Invalidate (delete) N-grams for a specific page.
     * Call this when a page is updated or deleted.
     *
     * @param int $pageId The page/post ID
     * @param string $type Entity type: 'post', 'page', 'category', 'tag' (default: 'post')
     * @return bool Success status
     */
    public function invalidatePage($pageId, $type = 'post') {
        global $wpdb;

        $table = $this->dao->getPrefixedTableName('abj404_ngram_cache');
        $result = $wpdb->delete($table, ['id' => $pageId, 'type' => $type], ['%d', '%s']);

        if ($result !== false) {
            // Invalidate coverage ratio caches since N-gram count changed
            $this->invalidateCoverageCaches();
        }

        return $result !== false;
    }

    /**
     * Update N-grams for specific pages (incremental update).
     *
     * This method updates N-grams for specific page IDs, useful when
     * individual pages are added or updated in the permalink cache.
     *
     * @param array $pageIds Array of page IDs to update
     * @return array Statistics: ['processed' => int, 'success' => int, 'failed' => int]
     */
    public function updateNGramsForPages($pageIds) {
        if (empty($pageIds) || !is_array($pageIds)) {
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        global $wpdb;
        $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');

        // Prepare IN clause for page IDs
        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));
        $query = $wpdb->prepare(
            "SELECT id, url FROM {$permalinkCacheTable} WHERE id IN ({$placeholders})",
            ...$pageIds
        );

        $pages = $wpdb->get_results($query, ARRAY_A);

        if (!is_array($pages)) {
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $stats = ['processed' => 0, 'success' => 0, 'failed' => 0];

        foreach ($pages as $page) {
            if (is_object($page)) {
                $page = (array) $page;
            }

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
            "Incremental N-gram update: %d pages, %d success, %d failed",
            $stats['processed'],
            $stats['success'],
            $stats['failed']
        ));

        return $stats;
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

        $permalinkCacheTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');

        // Get a batch of pages from permalink cache
        $query = $wpdb->prepare(
            "SELECT id, url FROM {$permalinkCacheTable} LIMIT %d OFFSET %d",
            $batchSize,
            $offset
        );

        $pages = $wpdb->get_results($query, ARRAY_A);

        if (!is_array($pages)) {
            $pages = [];
        }

        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0
        ];

        foreach ($pages as $page) {
            // Handle both object and array results (defensive coding for test environments)
            if (is_object($page)) {
                $page = (array) $page;
            }

            $pageId = $page['id'];
            $url = $page['url'];

            // Normalize URL for matching (lowercase, trim)
            $urlNormalized = $this->f->strtolower(trim($url));

            // Extract N-grams
            $ngrams = $this->extractNGrams($urlNormalized);

            // Store in database (skip per-item invalidation for bulk efficiency)
            $success = $this->storeNGrams($pageId, $url, $urlNormalized, $ngrams, 'post', true);

            $stats['processed']++;
            if ($success) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
        }

        // Invalidate coverage caches once at end of batch (not per-item)
        if ($stats['success'] > 0) {
            $this->invalidateCoverageCaches();
        }

        // Log only every 1000 pages to reduce log verbosity
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

    /**
     * Find pages similar to a 404 URL using N-gram filtering.
     *
     * This is the main method called by SpellChecker to reduce candidates.
     *
     * Process:
     * 1. Extract N-grams for the 404 URL (~0.1ms)
     * 2. Load filtered cached N-grams from DB (database-side filtering)
     * 3. Compute Dice similarity for each (~0.05ms each)
     * 4. Filter by minimum similarity threshold (removes 80-90%)
     * 5. Sort by similarity (best matches first)
     * 6. Return top N candidates
     *
     * @param string $url404 The 404 URL to find matches for
     * @param float $minSimilarity Minimum Dice coefficient (default: 0.4)
     * @param int $maxCandidates Maximum candidates to return (default: 100)
     * @return array Associative array [id => similarity_score] sorted by score (descending)
     */
    public function findSimilarPages($url404, $minSimilarity = 0.4, $maxCandidates = 100) {
        global $wpdb;

        // Start timing for performance tracking
        $startTime = microtime(true);

        // Step 1: Extract N-grams for the 404 URL
        $url404Normalized = $this->f->strtolower(trim($url404));
        $queryNGrams = $this->extractNGrams($url404Normalized);
        $queryCombinedCount = count($queryNGrams['bi']) + count($queryNGrams['tri']);

        // Early return if search term is too short for N-gram filtering
        if ($queryCombinedCount == 0) {
            $this->logger->debugMessage("Search term too short for N-gram filtering: '{$url404}'");
            return [];
        }

        // Check cache size to determine strategy (use memoized count)
        $totalCount = $this->getCacheCount();

        if ($totalCount == 0) {
            $this->logger->debugMessage("N-gram cache is empty.");

            // Schedule background rebuild if not already initialized/scheduled
            // This ensures automatic recovery from empty cache state
            if (get_option('abj404_ngram_cache_initialized') !== '1') {
                try {
                    $dbUpgrades = ABJ_404_Solution_DatabaseUpgradesEtc::getInstance();
                    $dbUpgrades->scheduleNGramCacheRebuild();
                    $this->logger->infoMessage("Empty N-gram cache detected during 404 request. Scheduled background rebuild.");
                } catch (Exception $e) {
                    $this->logger->errorMessage("Failed to schedule N-gram cache rebuild: " . $e->getMessage());
                }
            } else {
                $this->logger->debugMessage("N-gram cache rebuild already initialized or scheduled.");
            }

            return [];
        }

        // Step 2: Load cached N-grams with smart filtering
        // Calculate N-gram count range for filtering (40% tolerance)
        $minCount = max(1, (int)($queryCombinedCount * 0.4));
        $maxCount = (int)($queryCombinedCount * 2.5);

        // Use efficient database-side filtering for large caches
        if ($totalCount > self::CACHE_LOAD_LIMIT) {
            $this->logger->debugMessage("Using database-side filtering for {$totalCount} entries");
            $cachedPages = $this->getCachedNGramsFiltered($minCount, $maxCount, self::CACHE_LOAD_LIMIT, $queryCombinedCount);
        } else {
            // For small caches, load all (legacy behavior)
            $cachedPages = $this->getAllCachedNGrams();
        }

        if (empty($cachedPages)) {
            $this->logger->debugMessage("No matching candidates after filtering.");
            return [];
        }

        // Step 3: Compute similarity for each page
        $similarities = [];
        foreach ($cachedPages as $page) {
            $pageId = $page['id'];
            $pageNGrams = $page['ngrams'];

            // Quick optimization: Skip if N-gram counts are too different
            // (This is redundant for filtered queries but kept for unfiltered path)
            $pageCombinedCount = $page['ngram_count'];
            $denominator = max($queryCombinedCount, $pageCombinedCount);
            if ($denominator == 0) {
                continue; // Skip comparison when both have no n-grams
            }
            $countRatio = min($queryCombinedCount, $pageCombinedCount) / $denominator;
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
            "N-gram filtering: %d total, %d examined → %d candidates (≥%.2f similarity) in %.2fms",
            $totalCount,
            count($cachedPages),
            count($similarities),
            $minSimilarity,
            $duration
        ));

        // Track usage stats
        $this->trackNGramUsage($totalCount, count($cachedPages), count($similarities), $duration);

        return $similarities;
    }

    /**
     * Check if the N-gram cache is populated.
     *
     * @return bool True if cache has entries, false otherwise
     */
    public function isCachePopulated() {
        return $this->getCacheCount() > 0;
    }

    /**
     * Get cache entry count (memoized per-request).
     *
     * Use this instead of getCacheStats() when only the count is needed,
     * especially in hot code paths like 404 request handling.
     *
     * @return int Number of entries in the N-gram cache
     */
    public function getCacheCount() {
        // Return memoized value if available
        if ($this->ngramCountMemo !== null) {
            return $this->ngramCountMemo;
        }

        // Check if coverage ratio memo has the count
        if ($this->coverageRatioMemo !== null && isset($this->coverageRatioMemo['ngram_count'])) {
            $this->ngramCountMemo = $this->coverageRatioMemo['ngram_count'];
            return $this->ngramCountMemo;
        }

        global $wpdb;
        $table = $this->dao->getPrefixedTableName('abj404_ngram_cache');
        if (!isset($wpdb) || !is_object($wpdb) || !is_callable([$wpdb, 'get_var'])) {
            // In test environments or very early bootstrap, wpdb may not exist.
            // Treat as "no cache" rather than fatal.
            $this->ngramCountMemo = 0;
            return $this->ngramCountMemo;
        }

        $this->ngramCountMemo = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        return $this->ngramCountMemo;
    }

    /**
     * Get cache coverage ratio (ngram entries / permalink entries).
     *
     * Used to detect stale or incomplete caches. A ratio < 1.0 indicates
     * some permalink entries are not in the N-gram cache.
     *
     * Results are memoized per-request and cached in a transient for 5 minutes.
     * Uses version-based validation to avoid expensive COUNT(*) queries on every
     * request - versions are bumped by invalidateCoverageCaches() when data changes.
     *
     * @return float Coverage ratio (0.0 to 1.0+), or 1.0 if permalink cache is empty
     */
    public function getCacheCoverageRatio() {
        // Fast path: return memoized value if available (already validated this request)
        if ($this->coverageRatioMemo !== null) {
            return (float)$this->coverageRatioMemo['ratio'];
        }

        // Get current version (cheap scalar read, no COUNT queries)
        $currentVersion = (int)get_transient(self::COVERAGE_VERSION_KEY);

        // Check transient with version-based validation
        $cached = get_transient(self::COVERAGE_RATIO_KEY);
        if ($cached !== false && is_array($cached)
            && isset($cached['ratio'], $cached['version'])
            && (int)$cached['version'] === $currentVersion) {
            // Valid: version matches, trust the cached ratio without COUNT queries
            $this->coverageRatioMemo = $cached;
            if (isset($cached['ngram_count'])) {
                $this->ngramCountMemo = (int)$cached['ngram_count'];
            }
            return (float)$cached['ratio'];
        }

        // Transient miss or version mismatch - compute fresh ratio
        global $wpdb;
        $ngramTable = $this->dao->getPrefixedTableName('abj404_ngram_cache');
        $permalinkTable = $this->dao->getPrefixedTableName('abj404_permalink_cache');

        // Get both counts (required for ratio computation)
        $ngramCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$ngramTable}");
        $permalinkCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$permalinkTable}");

        // Memoize ngram count to avoid redundant queries elsewhere
        $this->ngramCountMemo = $ngramCount;

        if ($permalinkCount === 0) {
            // Empty permalink cache with existing N-grams = stale state (during rebuild)
            // Return 0.0 to skip prefiltering until both caches are populated
            $ratio = ($ngramCount === 0) ? 1.0 : 0.0;
        } else {
            $ratio = $ngramCount / $permalinkCount;
        }

        // Memoize for this request (include version for cache storage)
        $this->coverageRatioMemo = [
            'ratio' => $ratio,
            'ngram_count' => $ngramCount,
            'permalink_count' => $permalinkCount,
            'version' => $currentVersion
        ];

        // Cache in transient for subsequent requests
        set_transient(self::COVERAGE_RATIO_KEY, $this->coverageRatioMemo, self::COVERAGE_RATIO_CACHE_TTL);

        return $ratio;
    }

    /**
     * Get cache statistics for admin display.
     *
     * @return array Statistics: ['total_entries' => int, 'last_updated' => string]
     */
    public function getCacheStats() {
        global $wpdb;

        $table = $this->dao->getPrefixedTableName('abj404_ngram_cache');

        $stats = [
            'total_entries' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'posts_entries' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE type = 'post'"),
            'category_entries' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE type = 'category'"),
            'tag_entries' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE type = 'tag'"),
            'last_updated' => $wpdb->get_var("SELECT MAX(last_updated) FROM {$table}")
        ];

        return $stats;
    }

    /**
     * Track N-gram usage statistics.
     *
     * @param int $totalInCache Total entries in cache
     * @param int $examined Number of entries examined
     * @param int $candidates Number of candidates returned
     * @param float $duration Time taken in milliseconds
     */
    private function trackNGramUsage($totalInCache, $examined, $candidates, $duration) {
        // Get current stats
        $stats = get_option('abj404_ngram_usage_stats', [
            'total_queries' => 0,
            'total_entries_examined' => 0,
            'total_candidates_returned' => 0,
            'total_duration_ms' => 0,
            'avg_reduction_percent' => 0,
            'last_reset' => time()
        ]);

        // Update stats
        $stats['total_queries']++;
        $stats['total_entries_examined'] += $examined;
        $stats['total_candidates_returned'] += $candidates;
        $stats['total_duration_ms'] += $duration;

        // Calculate average reduction (how much ngrams reduced the search space)
        if ($totalInCache > 0) {
            $reductionPercent = (($totalInCache - $examined) / $totalInCache) * 100;
            $stats['avg_reduction_percent'] = (($stats['avg_reduction_percent'] * ($stats['total_queries'] - 1)) + $reductionPercent) / $stats['total_queries'];
        }

        // Reset stats monthly to avoid unbounded growth
        $monthAgo = time() - (30 * 24 * 60 * 60);
        if ($stats['last_reset'] < $monthAgo) {
            $stats = [
                'total_queries' => 1,
                'total_entries_examined' => $examined,
                'total_candidates_returned' => $candidates,
                'total_duration_ms' => $duration,
                'avg_reduction_percent' => ($totalInCache > 0) ? (($totalInCache - $examined) / $totalInCache) * 100 : 0,
                'last_reset' => time()
            ];
        }

        update_option('abj404_ngram_usage_stats', $stats);
    }

    /**
     * Get N-gram usage statistics.
     *
     * @return array Usage statistics
     */
    public function getUsageStats() {
        $stats = get_option('abj404_ngram_usage_stats', [
            'total_queries' => 0,
            'total_entries_examined' => 0,
            'total_candidates_returned' => 0,
            'total_duration_ms' => 0,
            'avg_reduction_percent' => 0,
            'last_reset' => time()
        ]);

        // Calculate averages
        if ($stats['total_queries'] > 0) {
            $stats['avg_examined_per_query'] = round($stats['total_entries_examined'] / $stats['total_queries'], 1);
            $stats['avg_candidates_per_query'] = round($stats['total_candidates_returned'] / $stats['total_queries'], 1);
            $stats['avg_duration_ms'] = round($stats['total_duration_ms'] / $stats['total_queries'], 2);
        } else {
            $stats['avg_examined_per_query'] = 0;
            $stats['avg_candidates_per_query'] = 0;
            $stats['avg_duration_ms'] = 0;
        }

        return $stats;
    }
}
