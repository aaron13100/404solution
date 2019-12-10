<?php

// turn on debug for localhost etc
if ($GLOBALS['abj404_display_errors']) {
	error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

class ABJ_404_Solution_FileSync {
	
	function getSyncFilePath($key) {
		$filePath = ABJ404_PATH . 'temp/' . 'SYNC_FILE_' . $key . '.txt';
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
