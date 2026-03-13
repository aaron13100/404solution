<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_FileSync {
	
	/** @var self|null */
	private static $instance = null;

	/** @return self */
	public static function getInstance(): self {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_FileSync();
		}
		
		return self::$instance;
	}
	
	/**
	 * @param string $key
	 * @return string
	 */
	function getSyncFilePath(string $key): string {
		$filePath = abj404_getUploadsDir() . 'SYNC_FILE_' . $key . '.txt';
		return $filePath;
	}
    
	/**
	 * @param string $key
	 * @return string
	 */
	function getOwnerFromFile(string $key): string {
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
	
	/**
	 * @param string $key
	 * @param string $uniqueID
	 * @return void
	 */
	function writeOwnerToFile(string $key, string $uniqueID): void {
		$filePath = $this->getSyncFilePath($key);

		// Fixed: Check return value to handle write failures (disk full, permissions, etc.)
		$result = @file_put_contents($filePath, $uniqueID, LOCK_EX);

		if ($result === false) {
			throw new Exception("Failed to write lock file: " . $filePath);
		}
	}
	
	/**
	 * @param string $uniqueID
	 * @param string $key
	 * @return void
	 */
	function releaseLock(string $uniqueID, string $key): void {
		$filePath = $this->getSyncFilePath($key);
		$fileUtils = ABJ_404_Solution_Functions::getInstance();
		$fileUtils->safeUnlink($filePath);
	}
	
}
