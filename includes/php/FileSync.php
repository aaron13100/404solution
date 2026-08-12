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

		// Read and catch rather than check-then-read, so there is no TOCTOU
		// window between "does it exist" and "read it".
		try {
			$contents = ABJ_404_Solution_FileSystemService::readFileContents($filePath, false);
			return $contents;
		} catch (Exception $e) {
			// Empty means "no lock owner" to every caller, and for a missing
			// file that is exactly right. For a file that EXISTS and could not
			// be read it is dangerous: a permissions problem or a full disk
			// then presents as an unlocked resource, two workers proceed at
			// once, and nothing downstream can tell the difference.
			//
			// So report rather than decide. This class does file I/O; whether
			// an unreadable lock file should stop the caller is lock policy,
			// and lock policy lives in LockOwnerStore. Deciding here also meant
			// writing a log line from inside a getter, which is its own problem
			// (lint-hidden-write-getters) and a fair one: a get* that emits
			// telemetry surprises every caller.
			if (is_file($filePath)) {
				throw $e;
			}
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
