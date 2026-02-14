<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_SynchronizationUtils {
	
	/** A prefix for keys used for synchronization methods.
	 * @var string */
	const SYNC_KEY_PREFIX = 'SYNC_';
	
	static $usingFileMode = null;
	
	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_SynchronizationUtils();
		}
		
		return self::$instance;
	}
	
	private function getFileModePath() {
		return abj404_getUploadsDir() . 'sync_mode_file.txt';
	}
	
	private function getOptionsModePath() {
		return abj404_getUploadsDir() . 'sync_mode_options.txt';
	}
	
	private function isFileMode() {
		if (self::$usingFileMode == null) {
			$fileModePath = $this->getFileModePath();
			$optionsModePath = $this->getOptionsModePath();
			if (file_exists($fileModePath) && file_exists($optionsModePath)) {
				$fileUtils = ABJ_404_Solution_Functions::getInstance();
				$fileUtils->safeUnlink($fileModePath);
				$fileUtils->safeUnlink($optionsModePath);
			}
			
			if (file_exists($fileModePath)) {
				$usingFileMode = true;
				
			} else if (file_exists($optionsModePath)) {
				$usingFileMode = false;
				
			} else {
				// initialize
				$pass = true;
				$keyForTesting = ABJ404_PP . "_" . self::SYNC_KEY_PREFIX . 'testing';
				$uniqueID = $this->createUniqueID('testing');
				
				// test saving.
				update_option($keyForTesting, $uniqueID);
				$result = get_option($keyForTesting);
				if ($result != $uniqueID) {
					$pass = false;
				}
				
				// test deleting.
				delete_option($keyForTesting);
				$result = get_option($keyForTesting);
				if ($result != null && $result != '') {
					$pass = false;
				}
				
				$f = ABJ_404_Solution_Functions::getInstance();
				$f->createDirectoryWithErrorMessages(dirname($optionsModePath));
				if ($pass) {
					$usingFileMode = false;
					touch($optionsModePath);
				} else {
					$usingFileMode = true;
					touch($fileModePath);
				}
			}
			self::$usingFileMode = $usingFileMode;
		}
		
		return self::$usingFileMode;
	}
	
	function switchToFileSyncMode() {
		$f = ABJ_404_Solution_Functions::getInstance();
		$fileUtils = ABJ_404_Solution_Functions::getInstance();
		
		self::$usingFileMode = true;
		$optionsModePath = $this->getOptionsModePath();
		$fileUtils->safeUnlink($optionsModePath);
			
		$fileModePath = $this->getFileModePath();
		$f->createDirectoryWithErrorMessages(dirname($fileModePath));
		touch($fileModePath);
	}
    
    private function createInternalKey($keyFromUser) {
        return ABJ404_PP . "_" . self::SYNC_KEY_PREFIX . $keyFromUser;
    }

    private function createUniqueID($keyFromUser) {
        return microtime(true) . "_" . $keyFromUser . '_' . $this->uniqidReal() . uniqid('', true);
    }

    /** Returns an empty string if the lock is not acquired.
     * @param string $synchronizedKeyFromUser
     * @return string the unique ID that was used. This is needed to release the lock. Or an empty string if
     * the lock wasn't acquired.
     */
    function synchronizerAcquireLockTry($synchronizedKeyFromUser) {
        $uniqueID = $this->createUniqueID($synchronizedKeyFromUser);
        $internalSynchronizedKey = $this->createInternalKey($synchronizedKeyFromUser);

        // don't let anyone hold the lock for too long.
        $this->fixAnUnforeseenIssue($synchronizedKeyFromUser);
        
        // acquire the lock.
       	$currentOwner = $this->readOwner($internalSynchronizedKey);
        // only write the value if it's empty.
        if (empty($currentOwner)) {
        	$this->writeOwner($internalSynchronizedKey, $uniqueID);
        }
        // give a different thread that ran at the same time a chance to overwrite our value.
        time_nanosleep(0, 10000000 * 30); // 10000000 is 1/100 of a second.
        // check and see if we're the owner yet.
        $currentOwner = $this->readOwner($internalSynchronizedKey);
	
        if ($currentOwner == $uniqueID) {
        	return $uniqueID;
        }
	        
        return '';
    }
    
    /** Remove the lock if it's been in place for too long.
     * @param string $synchronizedKeyFromUser
     */
    function fixAnUnforeseenIssue($synchronizedKeyFromUser) {
        $internalSynchronizedKey = $this->createInternalKey($synchronizedKeyFromUser);

        $uniqueID = $this->readOwner($internalSynchronizedKey);
        
        if (empty($uniqueID)) {
            return;
        }
        
        $uniqueIDInfo = explode("_", $uniqueID);
        
        $createTime = $uniqueIDInfo[0];
        
        $timePassed = microtime(true) - (float)$createTime;
        
        $maxExecutionTime = ini_get('max_execution_time');
        if (empty($maxExecutionTime) || $maxExecutionTime < 1) {
            $maxExecutionTime = 60;
        } else {
            $maxExecutionTime *= 2;
        }
        
        // it should have been released by now.
        if ($timePassed > $maxExecutionTime) {
        	$this->deleteOwner($uniqueID, $internalSynchronizedKey);
            $valueAfterDelete = $this->readOwner($internalSynchronizedKey);
            
            // if options mode failed for some reason then switch to file sync mode.
            if ($valueAfterDelete != null && $valueAfterDelete != '' && 
            		!$this->isFileMode()) {
            	$this->switchToFileSyncMode();
            	return;
            }
            
            $uniqueIDForDebugging = $this->createUniqueID('DEBUG_KEY');
            $logger = ABJ_404_Solution_Logging::getInstance();
            $logger->errorMessage("Forcibly removed synchronization after " . 
            		$timePassed . " seconds for the " . "key " . $internalSynchronizedKey . 
            		" with value: " . $uniqueID . ', value after delete: ' . $valueAfterDelete . 
            		", microtime: " . microtime(true) . ", unique ID for debugging: " . 
                    $uniqueIDForDebugging . ", File sync mode: " . json_encode($this->isFileMode()));
        }
    }
    
    /** Waits until the lock can be acquired and then returns the unique ID.
     * @param string $synchronizedKeyFromUser
     * @return string the unique ID that was used. This is needed to release the lock.
     */
    function synchronizerAcquireLockWithWait($synchronizedKeyFromUser) {
        $uniqueID = $this->createUniqueID($synchronizedKeyFromUser);
        $internalSynchronizedKey = $this->createInternalKey($synchronizedKeyFromUser);
        
        $this->fixAnUnforeseenIssue($synchronizedKeyFromUser);
        $iterations = 0;
        
        // acquire the lock.
        $currentOwner = $this->readOwner($internalSynchronizedKey);
        while ($currentOwner != $uniqueID) {
            // only write the value if it's empty.
            if (empty($currentOwner)) {
            	$this->writeOwner($internalSynchronizedKey, $uniqueID);
            }
            // give a different thread that ran at the same time a chance to overwrite our value.
            time_nanosleep(0, 500000000); // 10000000 is 1/100 of a second. 500000000 is 1/2 of a second.
            // check and see if we're the owner yet.
            $currentOwner = $this->readOwner($internalSynchronizedKey);
            
            $iterations++;
            if ($iterations % 500 == 0) {
                $this->fixAnUnforeseenIssue($synchronizedKeyFromUser);
            }
        }
        
        return $uniqueID;
    }
    
    /** Release the lock for a synchronized block. Should be done in a finally block.
     * @param string $uniqueID
     * @param string $synchronizedKeyFromUser
     * @throws Exception
     */
    function synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser) {
        $internalSynchronizedKey = $this->createInternalKey($synchronizedKeyFromUser);
        
        $currentLockHolder = $this->readOwner($internalSynchronizedKey);
        
		if ($uniqueID == $currentLockHolder) {
			$this->deleteOwner($uniqueID, $internalSynchronizedKey);

		} else {
			// Fail silently instead of throwing fatal exception.			
			$logger = ABJ_404_Solution_Logging::getInstance();
			$logger->debugMessage("Synchronization lock release mismatch. " .
				"Synchronized key: $synchronizedKeyFromUser, current holder: $currentLockHolder, " .
				"attempted release by: $uniqueID");
		}
    }
    
    function readOwner($key) {
    	$owner = '';
    	if ($this->isFileMode()) {
    		$fileSync = ABJ_404_Solution_FileSync::getInstance();
    		$owner = $fileSync->getOwnerFromFile($key);

    	} else {
    		// MULTISITE: Use network-aware option for N-gram locks
    		$owner = $this->getNetworkAwareOption($key);
    	}

    	return $owner;
    }
    function writeOwner($key, $owner) {
    	if ($this->isFileMode()) {
    		$fileSync = ABJ_404_Solution_FileSync::getInstance();
    		$fileSync->writeOwnerToFile($key, $owner);
    	} else {
    		// MULTISITE: Use network-aware option for N-gram locks
    		$this->updateNetworkAwareOption($key, $owner);
    	}
    }
    function deleteOwner($owner, $key) {
    	if ($this->isFileMode()) {
    		$fileSync = ABJ_404_Solution_FileSync::getInstance();
    		$fileSync->releaseLock($owner, $key);
    	} else {
    		// MULTISITE: Use network-aware option for N-gram locks
    		$this->deleteNetworkAwareOption($key);
    	}
    }

    /**
     * Check if the plugin is network-activated in a multisite environment.
     *
     * @return bool True if network-activated, false otherwise
     */
    private function isNetworkActivated() {
        if (!is_multisite()) {
            return false;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename(ABJ404_FILE));
    }

    /**
     * Determine if this lock key should use network-wide storage.
     *
     * N-gram rebuild locks (ngram_rebuild, ngram_schedule) must be network-wide
     * to coordinate across all sites. Other locks remain site-specific.
     *
     * @param string $key The lock key
     * @return bool True if should use network-wide storage
     */
    private function shouldUseNetworkStorage($key) {
        // Extract the user-provided key from the internal key format
        $userKey = str_replace(ABJ404_PP . "_" . self::SYNC_KEY_PREFIX, '', $key);

        // N-gram locks must be network-wide when network-activated
        $networkWideLocks = ['ngram_rebuild', 'ngram_schedule'];

        return $this->isNetworkActivated() && in_array($userKey, $networkWideLocks);
    }

    /**
     * Get an option value, using network-wide storage for N-gram locks.
     *
     * @param string $key The option key
     * @param mixed $default Default value if option doesn't exist
     * @return mixed The option value
     */
    private function getNetworkAwareOption($key, $default = false) {
        if ($this->shouldUseNetworkStorage($key)) {
            return get_site_option($key, $default);
        }
        return get_option($key, $default);
    }

    /**
     * Update an option value, using network-wide storage for N-gram locks.
     *
     * @param string $key The option key
     * @param mixed $value The value to store
     * @return bool True if updated successfully
     */
    private function updateNetworkAwareOption($key, $value) {
        if ($this->shouldUseNetworkStorage($key)) {
            return update_site_option($key, $value);
        }
        return update_option($key, $value);
    }

    /**
     * Delete an option, using network-wide storage for N-gram locks.
     *
     * @param string $key The option key
     * @return bool True if deleted successfully
     */
    private function deleteNetworkAwareOption($key) {
        if ($this->shouldUseNetworkStorage($key)) {
            return delete_site_option($key);
        }
        return delete_option($key);
    }

    /** 
     * @return string a random string of characters.
     * @throws Exception
     */
    function uniqidReal() {
        $bytes = null;
    	if (function_exists("random_bytes")) {
    	    try {
    		  $bytes = random_bytes((int)ceil(13 / 2));
    	    } catch (Exception $e) {
    	        $bytes = null; // don't care.
    	    }
    	}
    	
    	if ($bytes == null && function_exists("openssl_random_pseudo_bytes")) {
    	    try {
    		  $bytes = openssl_random_pseudo_bytes((int)ceil(13 / 2));
    	    } catch (Exception $e) {
    	      $bytes = null;
    	    }
    	    if ($bytes === false) {
    	        $bytes = null;
    	    }
    	}
    	
    	if ($bytes != null) {
    	    return bin2hex($bytes);
    	}
    	return uniqid("", true);
    }

}
