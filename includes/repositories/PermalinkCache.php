<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PermalinkCacheScheduleLock.php';

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
     * Rebuild the permalink cache, bounded by a wall-clock budget.
     *
     * `$maxExecutionTime` is a real budget, not a hint. Callers on user-facing
     * paths (post save/delete listeners, the settings-save handler, the
     * permalink-structure change hook) pass a small one precisely because they
     * are running inside somebody's request; the cron listener passes
     * `max_execution_time - 5`. Before this was honoured, every one of those
     * callers ran an unbounded full-corpus pass -- on a site with thousands of
     * posts, that is seconds to minutes of PHP-side keyword extraction inside a
     * foreground request.
     *
     * What the budget can and cannot bound:
     *   - The two set-based statements (the cache INSERT..SELECT and the
     *     parent-page pass) are single SQL statements. They cannot be split
     *     mid-flight, so they always run once; their cost is the database's,
     *     not PHP's.
     *   - The content-keyword population is a PHP loop over post bodies. That is
     *     the interruptible part, so it is what the deadline gates: batches run
     *     only while budget remains, and never at all once it is already spent.
     *
     * When the budget runs out with keyword work still pending, the run
     * re-arms itself via scheduleToRunAgain() so a large corpus converges
     * across successive cron ticks instead of being silently truncated at one
     * batch forever. `$executionCount` caps that chain at MAX_EXECUTIONS.
     *
     * @param int $maxExecutionTime Wall-clock budget in seconds (minimum 1).
     * @param int $executionCount 1-based position in a rescheduled chain.
     * @return int Rows inserted into the permalink cache by this pass.
     * @throws Exception
     */
    function updatePermalinkCache($maxExecutionTime, $executionCount = 1) {
        $budgetSeconds = is_numeric($maxExecutionTime) ? max(1, (int)$maxExecutionTime) : 1;
        $executionCount = is_numeric($executionCount) ? max(1, (int)$executionCount) : 1;
        $deadline = abj_clock()->nowFloat() + $budgetSeconds;

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

        $keywordWorkRemains = $this->populateContentKeywordsWithinBudget($deadline);

        $this->checkPermalinkCacheStaleness();

        if ($keywordWorkRemains && $executionCount < self::MAX_EXECUTIONS) {
            $this->scheduleToRunAgain($executionCount + 1);
        }

        return $rowsInserted;
    }

    /**
     * Maximum content-keyword batches a single run will process, independent of
     * the wall-clock budget. Guarantees termination even when the budget clock
     * does not advance (a frozen/injected clock) or when the keyword write is
     * silently not sticking, so the same rows keep coming back forever. At
     * populateContentKeywords()'s default batch size of 500 posts this is
     * 10,000 posts per run, well above what any realistic budget affords.
     */
    const MAX_KEYWORD_BATCHES_PER_RUN = 20;

    /**
     * Populate content keywords in batches while wall-clock budget remains.
     *
     * Starts a batch only while budget remains. The set-based cache work runs
     * before this method and can consume the entire caller budget by itself;
     * starting a 500-post PHP batch after that point would make the deadline a
     * decorative hint instead of a real request bound.
     *
     * Both early exits (budget spent, batch cap reached) mean "we stopped
     * before finding out", which is NOT the same question as "is there more to
     * do" -- so they ask, rather than assume. Assuming is production report 296
     * (urbanseed.info): a frontend 404 calls this with a one-second budget, the
     * set-based work above spends it before batch zero, and a site whose 73
     * published posts all had keywords already re-armed the cron chain on every
     * single 404. Fifty duplicate-event refusals a day for a pass with nothing
     * in it.
     *
     * @param float $deadline Epoch seconds (microsecond precision) after which
     *   no further batch may start.
     * @return bool True when keyword work is genuinely still pending, so the
     *   caller should reschedule. False when the corpus has converged.
     */
    private function populateContentKeywordsWithinBudget(float $deadline): bool {
        $clock = abj_clock();
        for ($batch = 0; $batch < self::MAX_KEYWORD_BATCHES_PER_RUN; $batch++) {
            if ($clock->nowFloat() >= $deadline) {
                return $this->keywordWorkRemains();
            }
            if ($this->populateContentKeywords() === 0) {
                // Nothing left needing keywords: the corpus is converged, so
                // there is nothing to reschedule.
                return false;
            }
        }
        return $this->keywordWorkRemains();
    }

    /**
     * Whether any published post still lacks content keywords.
     *
     * Deliberately a one-row existence probe rather than a batch: it runs
     * precisely when the caller's budget is already spent (or its batch
     * allowance used up), so fetching 500 post bodies to answer a yes/no
     * question would turn the deadline back into the decorative hint that
     * bounding this pass exists to prevent.
     *
     * A repository that cannot answer -- the content_keywords column is still
     * missing mid-migration, or the query failed -- returns no rows and is read
     * as "nothing to do". That is the same answer the batch path already gives
     * for the same condition (populateContentKeywords() returns 0), and it is
     * the safe one: the alternative queues a cron pass that runs the identical
     * failing query and re-arms itself forever.
     */
    private function keywordWorkRemains(): bool {
        return $this->getPostsNeedingContentKeywords(1) !== array();
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
     * Arm the next link of the deferred permalink-cache chain, unless the chain
     * is already armed.
     *
     * The guard is the whole point of the method now. WordPress identifies a
     * single event by hook AND arguments (md5(serialize($args))), and this
     * chain's links carry `[$maxExecutionTime, $executionCount]` -- both of
     * which move. So a link the previous cron tick queued as `[25, 7]` is NOT a
     * duplicate of the `[55, 2]` a frontend 404 asks for, and without the probe
     * WordPress dutifully queued a second pass beside the first. That is
     * production report 295 (tv503.com, 7,568 posts + 81 pages): three `cron`
     * option writes in two seconds, one per scanner 404, every one asking for
     * `[55, 2]` because a foreground call always passes execution count 1. The
     * chain was restarted from the front end rather than advanced, so
     * MAX_EXECUTIONS never bounded anything, and the next cron spawn re-ran
     * batches that were already queued to run.
     *
     * Asking the store rather than a known args tuple is deliberate: the budget
     * is recomputed from ini_get() in whatever request arms the link, and a
     * WP-Cron request routinely reports a different max_execution_time than a
     * front-end one, so there is no tuple to probe for.
     *
     * Safe against wedging the chain, in both directions. WordPress removes a
     * single event from the store BEFORE invoking its callback, so a pass
     * re-arming its own successor sees nothing queued and advances normally;
     * and if two links are somehow already queued, the first to run declines to
     * add a third while the second is still there, which collapses the
     * duplicates a pre-fix site accumulated instead of preserving them.
     * The hook probe and WordPress's cron-option write are not atomic by
     * themselves, so one expiring options-row claim encloses both. Otherwise
     * two requests can inspect the same empty store and both add links whose
     * different arguments bypass WordPress's exact-event de-duplication.
     *
     * @param int $executionCount
     * @return void
     */
    function scheduleToRunAgain(int $executionCount): void {
        $scheduleLock = new ABJ_404_Solution_PermalinkCacheScheduleLock();
        $lockValue = $scheduleLock->acquire();
        if ($lockValue === null) {
            $this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                ": another request is deciding whether to queue a permalink cache pass.");
            return;
        }

        $scheduler = abj_cron_scheduler();
        try {
            $scheduler->refreshStoredEventReads();
            if ($scheduler->hasAnyScheduledEvent(self::UPDATE_PERMALINK_CACHE_HOOK)) {
                $this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                    ": a permalink cache pass is already queued; not queueing another.");
                return;
            }

            $maxExecutionTime = (int)ini_get('max_execution_time') - 5;
            $maxExecutionTime = max($maxExecutionTime, 25);

            $scheduler->scheduleSingleAt(
                ABJ_404_Solution_PermalinkCache::UPDATE_PERMALINK_CACHE_HOOK,
                1,
                array($maxExecutionTime, $executionCount)
            );
        } finally {
            $scheduleLock->release($lockValue);
        }
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
