<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], array($GLOBALS['abj404_whitelist']))) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Static functions that can be used from anywhere.  */
class ABJ_404_Solution_SynchronizationUtils {
    
    /** A prefix for keys used for synchronization methods. 
     * @var string */
    const SYNC_KEY_PREFIX = 'SYNC_';
    
    private function createInternalKey($keyFromUser) {
        return ABJ404_PP . "_" . ABJ_404_Solution_SynchronizationUtils::SYNC_KEY_PREFIX . $keyFromUser;
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
        $currentOwner = get_option($internalSynchronizedKey);
        // only write the value if it's empty.
        if (empty($currentOwner)) {
            update_option($internalSynchronizedKey, $uniqueID);
        }
        // give a different thread that ran at the same time a chance to overwrite our value.
        time_nanosleep(0, 10000000); // 10000000 is 1/100 of a second.
        // check and see if we're the owner yet.
        $currentOwner = get_option($internalSynchronizedKey);

        if ($currentOwner == $uniqueID) {
            return $uniqueID;
        }
        
        return '';
    }
    
    /** Remove the lock if it's been in place for too long.
     * @param type $synchronizedKeyFromUser
     * @return type
     */
    function fixAnUnforeseenIssue($synchronizedKeyFromUser) {
        $internalSynchronizedKey = $this->createInternalKey($synchronizedKeyFromUser);

        $uniqueID = get_option($internalSynchronizedKey);
        
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
            delete_option($internalSynchronizedKey);
            $logger = new ABJ_404_Solution_Logging();
            $logger->errorMessage("Forcibly removed synchronization after " . $timePassed . " seconds for the "
                    . "key " . $internalSynchronizedKey . " with value: " . $uniqueID);
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
        $currentOwner = get_option($internalSynchronizedKey);
        while ($currentOwner != $uniqueID) {
            // only write the value if it's empty.
            if (empty($currentOwner)) {
                update_option($internalSynchronizedKey, $uniqueID);
            }
            // give a different thread that ran at the same time a chance to overwrite our value.
            time_nanosleep(0, 500000000); // 10000000 is 1/100 of a second. 500000000 is 1/2 of a second.
            // check and see if we're the owner yet.
            $currentOwner = get_option($internalSynchronizedKey);
            
            $iterations++;
            if ($iterations % 500 == 0) {
                $this->fixAnUnforeseenIssue($synchronizedKeyFromUser);
            }
        }
        
        return $uniqueID;
    }
    
    /** Release the lock for a synchronized block. Should be done in a finally block.
     * @param string $uniqueID
     * @param string $internalSynchronizedKey
     * @throws Exception
     */
    function synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser) {
        $internalSynchronizedKey = $this->createInternalKey($synchronizedKeyFromUser);
        
        $currentLockHolder = get_option($internalSynchronizedKey);
        
        if ($uniqueID == $currentLockHolder) {
            delete_option($internalSynchronizedKey);
            
        } else {
            throw new Exception("Tried to release lock when you're not the owner. synchronized key from user: " . $synchronizedKeyFromUser .
                    ', current lock holder: ' . $currentLockHolder . ', requested release for lock holder: ' . 
                    $uniqueID);
        }
    }

    /** 
     * @param int $lenght
     * @return string a random string of characters.
     * @throws Exception
     */
    function uniqidReal($lenght = 13) {
        if (function_exists("random_bytes")) {
            $bytes = random_bytes((int)ceil($lenght / 2));
        } else if (function_exists("openssl_random_pseudo_bytes")) {
            $bytes = openssl_random_pseudo_bytes((int)ceil($lenght / 2));
        } else {
            throw new Exception("A random_bytes method wasn't found. I don't know what to do.");
        }
        
        return substr(bin2hex($bytes), 0, $lenght);
    }

}
