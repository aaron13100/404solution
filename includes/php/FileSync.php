<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_FileSync {
	
	/** @var self|null */
	private static $instance = null;
	/**
	 * Test seam: install or clear the cached singleton instance without
	 * private-field reflection. Pass null to reset between tests; pass a
	 * configured instance (or double) to install it. Mirrors the setInstance()
	 * contract on DataAccess / PluginLogic (M105 singleton-reset seam).
	 *
	 * @param self|null $instance
	 * @return void
	 */
	public static function setInstance($instance) {
	    self::$instance = $instance;
	}


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

		// Fixed: TOCTOU race condition - catch exception instead of check-then-read
		try {
			$contents = ABJ_404_Solution_FileSystemService::readFileContents($filePath, false);
			return $contents;
		} catch (Exception $e) { // allow-silent-catch: TOCTOU-safe file read; missing or unreadable file returns empty, caller treats as "no lock owner"
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
		ABJ_404_Solution_FileSystemService::safeUnlink($filePath);
	}
	
}
