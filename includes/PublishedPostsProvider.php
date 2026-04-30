<?php


if (!defined('ABSPATH')) {
    exit;
}

/** Return some of the published pages when requested. */
class ABJ_404_Solution_PublishedPostsProvider {
    
	/** Track which rows to get from the database using limit.
	 * @var integer 	 */
	private $currentLowRowNumber = 0;

	/** When not null then use this data instead of querying the database.
	 * @var array<int, mixed>|null
	 */
	private $dataToUse = null;

	/** Tracks whether we're using user supplied data or not.
	 * @var bool 	 */
	private $useDataMode = false;

	/** When set, only return posts with these IDs (whitelist mode).
	 * @var array<int, int>|null */
	private $restrictedIds = null;
	
	/** @var self|null */
	private static $instance = null;

	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_PublishedPostsProvider();
		}
		
		return self::$instance;
	}
	
	/** Use this data instead of querying the database.
	 * @param array<int, mixed> $data
	 * @return void
	 */
	function useThisData(array $data): void {
		$this->dataToUse = $data;
		$this->useDataMode = true;
	}

	/**
	 * Restrict results to only posts with the given IDs (whitelist mode).
	 * This enables N-gram prefiltering to limit the dataset before expensive processing.
	 *
	 * @param array<int, int|string> $ids Array of post IDs to include
	 * @return void
	 */
	function restrictToIds(array $ids): void {
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
	 * @param int|null $maxAcceptableDistance
	 * @return array<int, mixed>
	 */
    function getNextBatch(int $permalinkLength, int $batchSize = 1000, $maxAcceptableDistance = null): array {
    	if ($this->useDataMode) {
    		return $this->getNextBatchFromLocalData($permalinkLength, $batchSize, $maxAcceptableDistance);
    	}
    	
    	return $this->getNextBatchFromTheDatabase($permalinkLength, $batchSize, $maxAcceptableDistance);
    }
    
    /**
     * @param int $permalinkLength
     * @param int $batchSize
     * @param int|null $maxAcceptableDistance
     * @return array<int, mixed>
     */
    private function getNextBatchFromLocalData(int $permalinkLength, int $batchSize, $maxAcceptableDistance): array {
    	$data = $this->dataToUse ?? array();
    	// get the rows to return.
    	$rows = array_slice($data, 0, $batchSize);

    	// remove the rows we'll return.
    	$this->dataToUse = array_slice($data, $batchSize);
    	
    	return $rows;
    }
    
    /**
     * @param int $permalinkLength
     * @param int $batchSize
     * @param int|null $maxAcceptableDistance
     * @return array<int, mixed>
     */
    private function getNextBatchFromTheDatabase(int $permalinkLength, int $batchSize, $maxAcceptableDistance): array {
    	$abj404dao = abj_service('data_access');

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
    
    /** Start over at 0 when getting the next batch.
     * @return void
     */
    function resetBatch(): void {
    	$this->currentLowRowNumber = 0;
    	$this->dataToUse = null;
    	$this->useDataMode = false;
    	$this->restrictedIds = null;
    }

}
