<?php


if (!defined('ABSPATH')) {
    exit;
}

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

		// Fixed: TOCTOU race condition - catch exception instead of check-then-read
		try {
			$contents = $fileUtils->readFileContents($filePath, false);
			return $contents;
		} catch (Exception $e) {
			// File doesn't exist or can't be read - return empty string
			return "";
		}
	}
	
	function writeOwnerToFile($key, $uniqueID) {
		$filePath = $this->getSyncFilePath($key);

		// Fixed: Check return value to handle write failures (disk full, permissions, etc.)
		$result = @file_put_contents($filePath, $uniqueID, LOCK_EX);

		if ($result === false) {
			throw new Exception("Failed to write lock file: " . $filePath);
		}
	}
	
	function releaseLock($uniqueID, $key) {
		$filePath = $this->getSyncFilePath($key);
		$fileUtils = ABJ_404_Solution_Functions::getInstance();
		$fileUtils->safeUnlink($filePath);
	}
	
}
