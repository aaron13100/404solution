<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_PermalinkCache {

    /** The name of the hook to use in WordPress. */
    const UPDATE_PERMALINK_CACHE_HOOK = 'abj404_updatePermalinkCacheAction';

    /** The maximum number of times in a row to run the hook. */
    const MAX_EXECUTIONS = 15;

    /** @var self|null */
    private static $instance = null;

    /** @var ABJ_404_Solution_ContentRepository */
    private $contentRepository;

    /** @var mixed */
    private $statsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_NGramFilter|null */
    private $ngramFilter;

    /** @var ABJ_404_Solution_OldPermalinkStructureStore */
    private $oldPermalinkStructureStore;

    /**
     * Constructor with dependency injection.
     *
     * @param ABJ_404_Solution_ContentRepository|null $contentRepository Content repository
     * @param ABJ_404_Solution_Logging|null $logging Logging service
     * @param ABJ_404_Solution_StatsRepository|null $statsRepository Stats repository
     * @param ABJ_404_Solution_NGramFilter|null $ngramFilter NGram filter (null = resolved lazily via abj_service)
     * @param ABJ_404_Solution_OldPermalinkStructureStore|null $oldPermalinkStructureStore Previous structure store
     */
    public function __construct(
        $contentRepository = null,
        $logging = null,
        $statsRepository = null,
        $ngramFilter = null,
        $oldPermalinkStructureStore = null
    ) {
        // Use injected dependencies or fall back to getInstance() for backward compatibility
        $this->contentRepository = $contentRepository !== null ? $contentRepository : abj_service('content_repository');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->statsRepository = $statsRepository !== null ? $statsRepository :
            (is_object($contentRepository) && method_exists($contentRepository, 'getPostsNeedingContentKeywords')
                ? $contentRepository
                : call_user_func(array('ABJ_404_Solution_StatsRepositoryResolver', 'resolve'), __CLASS__));
        $this->ngramFilter = $ngramFilter;
        $this->oldPermalinkStructureStore = $oldPermalinkStructureStore !== null
            ? $oldPermalinkStructureStore
            : new ABJ_404_Solution_OldPermalinkStructureStore();
    }

    /** @return self */
    public static function getInstance(): self {
    	if (self::$instance == null) {
    		self::$instance = new ABJ_404_Solution_PermalinkCache();
    	}

    	return self::$instance;
    }

    /**
     * Install a singleton instance directly. The canonical seam for tests
     * that need to swap in a test double, and for callers that have
     * already constructed a fully configured instance. Pass `null` to
     * clear the cached singleton.
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }
    
    /** @return void */
    static function init(): void {
        $me = abj_service('permalink_cache');
        
        add_action('updated_option', array($me, 'permalinkStructureChanged'), 10, 3);
    }

    /** If the permalink structure changes then truncate the cache table and update some values.
     * @global type $abj404logging
     * @param string $var1
     * @param mixed $oldStructure
     * @param mixed|null $newStructure
     */
    /**
     * @param string $var1
     * @param mixed $oldStructure
     * @param mixed|null $newStructure
     * @return void
     */
    function permalinkStructureChanged($var1, $oldStructure, $newStructure = null): void {
        if ($var1 != 'permalink_structure') {
            return;
        }

        $previousStructure = $newStructure === null ? '' : (is_scalar($oldStructure) ? (string)$oldStructure : '');
        $currentStructure = $newStructure === null
            ? (is_scalar($oldStructure) ? (string)$oldStructure : '')
            : (is_scalar($newStructure) ? (string)$newStructure : '');
        if ($previousStructure !== '' && $previousStructure !== $currentStructure) {
            $this->oldPermalinkStructureStore->recordPreviousStructure($previousStructure);
        }
        
        // we need to truncate the permlink cache since the structure changed
        
        $this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ . 
                ": Truncating and updating permalink cache because the permalink structure changed to " . 
                $currentStructure);
        
        $this->contentRepository->truncatePermalinkCacheTable();

        // let's take this opportunity to update some of the values in the cache table.
        $this->updatePermalinkCache(1);
    }
    
    /** 
     * @param int $maxExecutionTime
     * @param int $executionCount
     * @return int
     * @throws Exception
     */
    function updatePermalinkCache($maxExecutionTime, $executionCount = 1) {
    	// check to see if we need to upgrade the database.
        // we must pass "true" here to avoid an infinite loop when updating the database.
        abj_service('options_repository')->getOptions(true);

        // insert the new rows.
        $results = $this->contentRepository->updatePermalinkCache();
        $rowsInserted = (is_array($results) && isset($results['rows_affected']) && is_int($results['rows_affected'])) ? $results['rows_affected'] : 0;

        // Invalidate coverage ratio if rows were inserted (new permalinks may lack N-grams)
        if ($rowsInserted > 0) {
            $ngramFilter = $this->ngramFilter !== null ? $this->ngramFilter : abj_service('ngram_filter');
            $ngramFilter->invalidateCoverageCaches();
        }

        // now we have to update the the pages that have parents to include the parent
        // part of the URL.
        // wherever the post_parent != 0, prepend the parent ID URL onto the current URL
        // and update the post_parent to be the parent ID of the parent.
        $this->contentRepository->updatePermalinkCacheParentPages();

        $this->populateContentKeywords();

        $this->checkPermalinkCacheStaleness();

        return $rowsInserted;
    }

    /** @return void */
    private function checkPermalinkCacheStaleness(): void {
        $cacheCount = $this->contentRepository->getPermalinkCacheCount();
        if ($cacheCount > 0) {
            return;
        }
        $postCount = function_exists('wp_count_posts') ? (int) (wp_count_posts('post')->publish ?? 0) : 0;
        $pageCount = function_exists('wp_count_posts') ? (int) (wp_count_posts('page')->publish ?? 0) : 0;
        if ($postCount + $pageCount === 0) {
            return;
        }
        if (function_exists('set_transient')) {
            // allow-cache-empty: notice payload is constructed locally and intentionally persisted as-is.
            set_transient('abj404_plugin_db_notice', array(
                'type'      => 'stale_permalink_cache',
                'message'   => function_exists('__') ? __('Permalink cache appears empty after rebuild - suggestions may be degraded. Try rebuilding again or check available disk space.', '404-solution') : 'Permalink cache appears empty after rebuild - suggestions may be degraded. Try rebuilding again or check available disk space.',
                'guidance'  => function_exists('__') ? __('The permalink cache appears to be empty. Try rebuilding it from the Tools tab, or check that your site has enough disk space.', '404-solution') : 'The permalink cache appears to be empty. Try rebuilding it from the Tools tab, or check that your site has enough disk space.',
                'timestamp' => abj_clock()->now(),
            ), 86400);
        }
    }
    
    /**
     * @param int $executionCount
     * @return void
     */
    function scheduleToRunAgain(int $executionCount): void {
        $maxExecutionTime = (int)ini_get('max_execution_time') - 5;
        $maxExecutionTime = max($maxExecutionTime, 25);

        abj_cron_scheduler()->scheduleSingleAt(
            ABJ_404_Solution_PermalinkCache::UPDATE_PERMALINK_CACHE_HOOK,
            1,
            array($maxExecutionTime, $executionCount)
        );
    }

    /** Maximum unique keywords to store per post. */
    const MAX_CONTENT_KEYWORDS = 30;

    /** Minimum word length to keep during keyword extraction. */
    const MIN_KEYWORD_LENGTH = 3;

    /**
     * Populate content_keywords for permalink cache rows that have NULL.
     *
     * Reads post_content, strips HTML/shortcodes, filters stop words,
     * keeps top keywords by frequency. Runs in batches to stay within
     * PHP time limits.
     *
     * @param int $batchSize Maximum posts to process per call.
     * @return int Number of rows updated.
     */
    function populateContentKeywords(int $batchSize = 500): int {
        $rows = $this->getPostsNeedingContentKeywords($batchSize);

        if (empty($rows)) {
            return 0;
        }

        $idToKeywords = array();
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $id = isset($row->id) ? (int)$row->id : 0;
            if ($id <= 0) {
                continue;
            }
            $content = isset($row->post_content) && is_string($row->post_content) ? $row->post_content : '';
            $idToKeywords[$id] = self::extractContentKeywords($content);
        }

        if (empty($idToKeywords)) {
            return 0;
        }

        $this->bulkUpdateContentKeywords($idToKeywords);

        return count($idToKeywords);
    }

    /**
     * @param int $batchSize
     * @return array<int, mixed>
     */
    private function getPostsNeedingContentKeywords(int $batchSize): array {
        if (!is_object($this->statsRepository) || !method_exists($this->statsRepository, 'getPostsNeedingContentKeywords')) {
            return array();
        }
        $rows = call_user_func(array($this->statsRepository, 'getPostsNeedingContentKeywords'), $batchSize);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<int, string> $idToKeywords
     * @return void
     */
    private function bulkUpdateContentKeywords(array $idToKeywords): void {
        if (!is_object($this->statsRepository) || !method_exists($this->statsRepository, 'bulkUpdateContentKeywords')) {
            return;
        }
        call_user_func(array($this->statsRepository, 'bulkUpdateContentKeywords'), $idToKeywords);
    }

    /**
     * Extract significant keywords from HTML post content.
     *
     * 1. Strip shortcodes ([shortcode attr=val]...[/shortcode] and [self-closing])
     * 2. Strip HTML tags
     * 3. Decode HTML entities
     * 4. Split on whitespace, lowercase, strip non-alpha
     * 5. Filter: length < MIN_KEYWORD_LENGTH, stop words
     * 6. Count frequency, take top MAX_CONTENT_KEYWORDS unique words
     * 7. Return space-joined string
     *
     * @param string $htmlContent Raw post_content (may contain HTML and shortcodes).
     * @return string Space-separated lowercase keywords.
     */
    public static function extractContentKeywords(string $htmlContent): string {
        if (trim($htmlContent) === '') {
            return '';
        }

        // Strip shortcodes: [tag attr="val"]content[/tag] and [self-closing /]
        $text = preg_replace('/\[\/?\w+[^\]]*\]/', '', $htmlContent);
        if (!is_string($text)) {
            $text = $htmlContent;
        }

        // Strip HTML tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Lowercase
        $text = strtolower($text);

        // Replace non-alpha characters with spaces (keeps Unicode letters via \p{L})
        $text = preg_replace('/[^\p{L}]+/u', ' ', $text);
        if (!is_string($text)) {
            return '';
        }

        // Split on whitespace
        $words = preg_split('/\s+/', trim($text));
        if (!is_array($words)) {
            return '';
        }

        $stopLookup = array_flip(ABJ_404_Solution_StopWords::$common);
        $freq = [];

        foreach ($words as $word) {
            if (!is_string($word) || strlen($word) < self::MIN_KEYWORD_LENGTH) {
                continue;
            }
            if (isset($stopLookup[$word])) {
                continue;
            }
            if (!isset($freq[$word])) {
                $freq[$word] = 0;
            }
            $freq[$word]++;
        }

        if (empty($freq)) {
            return '';
        }

        // Sort by frequency descending
        arsort($freq);

        // Take top N unique words
        $top = array_slice(array_keys($freq), 0, self::MAX_CONTENT_KEYWORDS);

        return implode(' ', $top);
    }

}
