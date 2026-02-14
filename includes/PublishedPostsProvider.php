<?php


if (!defined('ABSPATH')) {
    exit;
}

/** Return some of the published pages when requested. */
class ABJ_404_Solution_PublishedPostsProvider {
    
	/** Track which rows to get from the database using limit.
	 * @var integer 	 */
	private $currentLowRowNumber = 0;

	/** WHen not null then use this data instead of querying the database.
	 * @var array
	 */
	private $dataToUse = null;

	/** Tracks whether we're using user supplied data or not.
	 * @var bool 	 */
	private $useDataMode = false;

	/** When set, only return posts with these IDs (whitelist mode).
	 * @var array|null */
	private $restrictedIds = null;
	
	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_PublishedPostsProvider();
		}
		
		return self::$instance;
	}
	
	/** Use this data instead of querying the database.
	 * @param array $data
	 */
	function useThisData($data) {
		$this->dataToUse = $data;
		$this->useDataMode = true;
	}

	/**
	 * Restrict results to only posts with the given IDs (whitelist mode).
	 * This enables N-gram prefiltering to limit the dataset before expensive processing.
	 *
	 * @param array $ids Array of post IDs to include
	 */
	function restrictToIds($ids) {
		if (!empty($ids) && is_array($ids)) {
			$this->restrictedIds = array_map('intval', $ids);
		}
	}

	/**
	 * Check if we're in restricted ID mode.
	 * @return bool
	 */
	function hasRestrictedIds() {
		return !empty($this->restrictedIds);
	}

	/** 
	 * @param int $permalinkLength Order by prioritizing things with a permalink 
	 * close to this length.
	 * @param int $batchSize The number of results to return. e.g. 100. 
	 * @param int $maxAcceptableDistance
	 * @return 
	 */
    function getNextBatch($permalinkLength, $batchSize = 1000, $maxAcceptableDistance = null) {
    	if ($this->useDataMode) {
    		return $this->getNextBatchFromLocalData($permalinkLength, $batchSize, $maxAcceptableDistance);
    	}
    	
    	return $this->getNextBatchFromTheDatabase($permalinkLength, $batchSize, $maxAcceptableDistance);
    }
    
    private function getNextBatchFromLocalData($permalinkLength, $batchSize, $maxAcceptableDistance) {
    	// get the rows to return.
    	$rows = array_slice($this->dataToUse, 0, $batchSize);
    	
    	// remove the rows we'll return.
    	$this->dataToUse = array_slice($this->dataToUse, $batchSize);
    	
    	return $rows;
    }
    
    private function getNextBatchFromTheDatabase($permalinkLength, $batchSize, $maxAcceptableDistance) {
    	$abj404dao = ABJ_404_Solution_DataAccess::getInstance();

    	$orderBy = "abs(plc.url_length - " . $permalinkLength . "), wp_posts.id";
    	$limit = $this->currentLowRowNumber . ", " . $batchSize;
    	$extraWhereClause = '';

    	if ($maxAcceptableDistance != null) {
    		$extraWhereClause = "and abs(plc.url_length - " . $permalinkLength .
    			") <= " . $maxAcceptableDistance;
    	}

    	// N-gram prefiltering: restrict to specific IDs if whitelist is set
    	if (!empty($this->restrictedIds)) {
    		$idList = implode(',', $this->restrictedIds);
    		$extraWhereClause .= " and wp_posts.id IN (" . $idList . ")";
    	}

    	$rows = $abj404dao->getPublishedPagesAndPostsIDs('', '', $limit, $orderBy, $extraWhereClause);

    	$this->currentLowRowNumber += $batchSize;

    	return $rows;
    }
    
    /** Start over at 0 when getting the next batch. */
    function resetBatch() {
    	$this->currentLowRowNumber = 0;
    	$this->dataToUse = null;
    	$this->useDataMode = false;
    	$this->restrictedIds = null;
    }

}
