<?php

class ABJ_404_Solution_FileSync {
	
	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_FileSync();
		}
		
		return self::$instance;
	}
	
	function getSyncFilePath($key) {
		$filePath = abj404_getUploadsDir() . 'SYNC_FILE_' . $key . '.txt';
		return $filePath;
	}
    
	function getOwnerFromFile($key) {
		$filePath = $this->getSyncFilePath($key);
		$fileUtils = ABJ_404_Solution_Functions::getInstance();
		
		if (!file_exists($filePath)) {
			return "";
		}
		
		$contents = $fileUtils->readFileContents($filePath, false);
		
		return $contents;
	}
	
	function writeOwnerToFile($key, $uniqueID) {
		$filePath = $this->getSyncFilePath($key);
		file_put_contents($filePath, $uniqueID, LOCK_EX);
	}
	
	function releaseLock($uniqueID, $key) {
		$filePath = $this->getSyncFilePath($key);
		$fileUtils = ABJ_404_Solution_Functions::getInstance();
		$fileUtils->safeUnlink($filePath);
	}
	
}
