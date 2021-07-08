<?php

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
		return ABJ404_PATH . 'temp/' . 'sync_mode_file.txt';
	}
	
	private function getOptionsModePath() {
		return ABJ404_PATH . 'temp/' . 'sync_mode_options.txt';
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
        return microtime(true) . "_" . $keyFromUser . '_' . $this->uniqidReal();
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
        time_nanosleep(0, 10000000); // 10000000 is 1/100 of a second.
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
            throw new Exception("Tried to release lock when you're not the owner. " . 
            		"Synchronized key from user: " . $synchronizedKeyFromUser .
                    ', current lock holder: ' . $currentLockHolder . 
            		', requested release for lock holder: ' . $uniqueID);
        }
    }
    
    function readOwner($key) {
    	$owner = '';
    	if ($this->isFileMode()) {
    		$fileSync = ABJ_404_Solution_FileSync::getInstance();
    		$owner = $fileSync->getOwnerFromFile($key);
    		
    	} else {
    		$owner = get_option($key);
    	}
    	
    	return $owner;
    }
    function writeOwner($key, $owner) {
    	if ($this->isFileMode()) {
    		$fileSync = ABJ_404_Solution_FileSync::getInstance();
    		$fileSync->writeOwnerToFile($key, $owner);
    	} else {
    		update_option($key, $owner);
    	}
    }
    function deleteOwner($owner, $key) {
    	if ($this->isFileMode()) {
    		$fileSync = ABJ_404_Solution_FileSync::getInstance();
    		$fileSync->releaseLock($owner, $key);
    	} else {
    		delete_option($key);
    	}
    }

    /** 
     * @return string a random string of characters.
     * @throws Exception
     */
    function uniqidReal() {
    	return uniqid("", true);
    }

}
