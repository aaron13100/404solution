<?php

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_PermalinkCache {

    /** The name of the hook to use in WordPress. */
    const UPDATE_PERMALINK_CACHE_HOOK = 'abj404_updatePermalinkCacheAction';

    /** The maximum number of times in a row to run the hook. */
    const MAX_EXECUTIONS = 15;

    private static $instance = null;

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;

    /**
     * Constructor with dependency injection.
     *
     * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
     * @param ABJ_404_Solution_Logging|null $logging Logging service
     * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
     */
    public function __construct($dataAccess = null, $logging = null, $pluginLogic = null) {
        // Use injected dependencies or fall back to getInstance() for backward compatibility
        $this->dao = $dataAccess !== null ? $dataAccess : ABJ_404_Solution_DataAccess::getInstance();
        $this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
        $this->logic = $pluginLogic !== null ? $pluginLogic : ABJ_404_Solution_PluginLogic::getInstance();
    }

    public static function getInstance() {
    	if (self::$instance == null) {
    		self::$instance = new ABJ_404_Solution_PermalinkCache();
    	}

    	return self::$instance;
    }
    
    static function init() {
        $me = ABJ_404_Solution_PermalinkCache::getInstance();
        
        add_action('updated_option', array($me, 'permalinkStructureChanged'), 10, 2);
    }

    /** If the permalink structure changes then truncate the cache table and update some values.
     * @global type $abj404logging
     * @param string $var1
     * @param string $newStructure
     */
    function permalinkStructureChanged($var1, $newStructure) {
        if ($var1 != 'permalink_structure') {
            return;
        }
        
        // we need to truncate the permlink cache since the structure changed
        
        $this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ . 
                ": Truncating and updating permalink cache because the permalink structure changed to " . 
                $newStructure);
        
        $this->dao->truncatePermalinkCacheTable();

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
        $this->logic->getOptions(true);

        // insert the new rows.
        $results = $this->dao->updatePermalinkCache();
        $rowsInserted = $results['rows_affected'];

        // Invalidate coverage ratio if rows were inserted (new permalinks may lack N-grams)
        if ($rowsInserted > 0) {
            ABJ_404_Solution_NGramFilter::getInstance()->invalidateCoverageCaches();
        }

        // now we have to update the the pages that have parents to include the parent
        // part of the URL.
        // wherever the post_parent != 0, prepend the parent ID URL onto the current URL
        // and update the post_parent to be the parent ID of the parent.
        $this->dao->updatePermalinkCacheParentPages();

        return $rowsInserted;
    }
    
    function scheduleToRunAgain($executionCount) {
        $maxExecutionTime = (int)ini_get('max_execution_time') - 5;
        $maxExecutionTime = max($maxExecutionTime, 25);
        
        wp_schedule_single_event(1, ABJ_404_Solution_PermalinkCache::UPDATE_PERMALINK_CACHE_HOOK,
                array($maxExecutionTime, $executionCount));
    }
    
}
