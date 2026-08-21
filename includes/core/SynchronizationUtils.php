<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * The synchronizer lock protocol: mint an owner id, acquire it, break a lock
 * whose holder is gone, and release it -- including when the holder dies
 * without unwinding.
 *
 * Storage of the owner records themselves belongs to
 * ABJ_404_Solution_LockOwnerStore; nothing in this class touches the options
 * table or the filesystem directly.
 */
class ABJ_404_Solution_SynchronizationUtils {

	/** Absolute ceiling, in seconds, on how long any lock may look legitimately
	 * held before a later acquirer breaks it.
	 *
	 * The stale-lock threshold is derived from max_execution_time (a request
	 * cannot legitimately outlive it), but that value is host-controlled and
	 * unbounded. westcoat.kinsta.cloud reported max_execution_time=43200, which
	 * the old "* 2" heuristic turned into a 24-hour window: a lock leaked by a
	 * fatal on 2026-07-11 04:36 was not broken until 2026-07-12 04:40, after
	 * 86615 seconds, and the site served a 4.2.0 schema to 4.3.1 code the whole
	 * time. No critical section in this plugin legitimately runs for minutes, so
	 * the derived value is capped here regardless of what the host allows.
	 * @var int */
	const LOCK_STALE_CEILING_SECONDS = 300;

	/** Stale-lock threshold used when max_execution_time reports no limit
	 * (0 / empty, as under CLI, WP-CLI and many cron contexts).
	 * @var int */
	const LOCK_STALE_FALLBACK_SECONDS = 60;

	/** Locks acquired by THIS instance during THIS request that have not been
	 * released yet, as internal key => unique ID.
	 *
	 * A synchronizer lock is a plain owner record in an option row or a file;
	 * nothing in the storage layer knows the holder died. Callers all release in
	 * a finally block, which covers exceptions but NOT the failure modes that
	 * actually leak: E_ERROR, OOM, and request timeouts unwind straight past
	 * finally. Tracking held locks here lets releaseLocksLeakedByThisRequest()
	 * clean up from a shutdown function, which PHP does still run after a fatal.
	 * @var array<string, string> */
	private $locksHeldThisRequest = array();

	/** Whether the shutdown hook that releases leaked locks is registered.
	 * register_shutdown_function() is additive and cannot be undone, so it is
	 * wired at most once per instance and made idempotent instead.
	 * @var bool */
	private $shutdownReleaseRegistered = false;

	/** @var ABJ_404_Solution_LockOwnerStore */
	private $ownerStore;

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

	/**
	 * Test seam: clear all cached static state (the singleton instance and the
	 * owner store's file-vs-options lock-mode latch) without private-field
	 * reflection.
	 *
	 * @return void
	 */
	public static function resetForTests() {
	    self::$instance = null;
	    ABJ_404_Solution_LockOwnerStore::resetForTests();
	}

	public function __construct(?ABJ_404_Solution_LockOwnerStore $ownerStore = null) {
		$this->ownerStore = $ownerStore !== null ? $ownerStore : new ABJ_404_Solution_LockOwnerStore();
	}

	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_SynchronizationUtils();
		}

		return self::$instance;
	}

	/** The owner-record storage this lock protocol reads and writes through.
	 *
	 * Exposed so callers that need the storage decision itself (the
	 * file-vs-options latch, most often in tests pinning a deterministic mode)
	 * can reach it without this class re-publishing the store's surface.
	 *
	 * @return ABJ_404_Solution_LockOwnerStore
	 */
	function ownerStore() {
		return $this->ownerStore;
	}

    /**
     * @param string $keyFromUser
     * @return string
     */
    private function createInternalKey($keyFromUser) {
        return $this->ownerStore->createInternalKey($keyFromUser);
    }

    /**
     * @param string $keyFromUser
     * @return string
     */
    private function createUniqueID($keyFromUser) {
        return abj_clock()->nowFloat() . "_" . $keyFromUser . '_' . $this->uniqidReal() . uniqid('', true);
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

        if (!$this->ownerStore->claimOwner(array(
            'key' => $internalSynchronizedKey,
            'owner' => $uniqueID,
        ))) {
        	// Somebody else owns it. This request wrote nothing, so it has
        	// nothing to clean up.
        	return '';
        }

        // Arm the crash-safe release as the very next thing after the record
        // exists. The two cannot be made one operation in PHP, so a fatal in
        // between still leaks the record -- but that gap is now a single
        // statement rather than the 300ms settle sleep the old read-write-
        // sleep-read protocol had to hold it open for.
        $this->rememberHeldLock($internalSynchronizedKey, $uniqueID);

        return $uniqueID;
    }

    /** Remove the lock if it's been in place for too long.
     * @param string $synchronizedKeyFromUser
     * @return void
     */
    function fixAnUnforeseenIssue($synchronizedKeyFromUser) {
        $internalSynchronizedKey = $this->createInternalKey($synchronizedKeyFromUser);

        $uniqueID = $this->ownerStore->readOwner($internalSynchronizedKey);

        if (empty($uniqueID)) {
            return;
        }

        $uniqueIDInfo = explode("_", $uniqueID);

        $createTime = $uniqueIDInfo[0];

        $timePassed = abj_clock()->nowFloat() - (float)$createTime;

        $maxExecutionTime = $this->staleLockThresholdSeconds();

        // it should have been released by now.
        if ($timePassed > $maxExecutionTime) {
			$this->ownerStore->deleteOwner(array(
				'key' => $internalSynchronizedKey,
				'owner' => $uniqueID,
			));
            $valueAfterDelete = $this->ownerStore->readOwner($internalSynchronizedKey);

            // Options storage is only proven broken when the record that is
            // still sitting there is the SAME one we just deleted. A different
            // value means another request legitimately claimed the key in the
            // meantime, which is the protocol working rather than the storage
            // failing, and latching the whole site onto file-based records over
            // it would be a false alarm. (deleteOwner() only removes a record
            // whose value the caller named, so losing that race leaves the new
            // owner's record untouched, which is exactly what should happen.)
            if ($valueAfterDelete === $uniqueID &&
            		!$this->ownerStore->isFileMode()) {
            	$this->ownerStore->switchToFileSyncMode();
            	return;
            }

            $uniqueIDForDebugging = $this->createUniqueID('DEBUG_KEY');
            $logger = abj_service('logging');
            $logger->errorMessage("Forcibly removed synchronization after " .
            		$timePassed . " seconds for the " . "key " . $internalSynchronizedKey .
            		" with value: " . $uniqueID . ', value after delete: ' . $valueAfterDelete .
                    ", microtime: " . abj_clock()->nowFloat() . ", unique ID for debugging: " .
                    $uniqueIDForDebugging . ", File sync mode: " . json_encode($this->ownerStore->isFileMode()));
        }
    }

    // There is deliberately no blocking acquire here. synchronizerAcquireLockWithWait()
    // used to sit at this spot: a `while (!claimOwner(...))` that slept half a second
    // between attempts, with no ceiling, no deadline and no give-up. Whether it ever
    // returned was entirely the storage layer's decision, and every reason a claim can
    // fail permanently -- a read-only replica refusing the write, a full disk, an
    // unwritable uploads directory, a leaked owner record younger than the stale-lock
    // threshold -- turned it into a request that ran until max_execution_time killed it.
    // It had no callers in the plugin's whole recorded history.
    //
    // Waiting is therefore the CALLER's decision, not this class's: take the lock with
    // synchronizerAcquireLockTry(), and on '' either skip the work (what every caller
    // here does, because a concurrent request is already doing it) or retry under a
    // deadline the caller owns and can report on. A bounded waiter may be added back if
    // something genuinely needs one, but it has to carry a wall-clock deadline read from
    // abj_clock() -- time_nanosleep() is not a clock under load -- and a return contract
    // that can express "the deadline passed" with the reason the claims were failing.
    // LockAcquireApiSurfaceTest holds that line for every acquire method on this class.

    /** Release the lock for a synchronized block. Should be done in a finally block.
     * @param string $uniqueID
     * @param string $synchronizedKeyFromUser
     * @return void
     * @throws Exception
     */
    function synchronizerReleaseLock($uniqueID, $synchronizedKeyFromUser) {
        $internalSynchronizedKey = $this->createInternalKey($synchronizedKeyFromUser);

        $currentLockHolder = $this->ownerStore->readOwner($internalSynchronizedKey);

        // Whatever the outcome below, this request is done with the lock, so
        // the shutdown release must no longer consider it outstanding.
        $this->forgetHeldLock($internalSynchronizedKey, $uniqueID);

		if ($uniqueID == $currentLockHolder) {
			$this->ownerStore->deleteOwner(array(
				'key' => $internalSynchronizedKey,
				'owner' => $uniqueID,
			));

		} else {
			// Fail silently instead of throwing fatal exception.
			$logger = abj_service('logging');
			$logger->debugMessage("Synchronization lock release mismatch. " .
				"Synchronized key: $synchronizedKeyFromUser, current holder: $currentLockHolder, " .
				"attempted release by: $uniqueID");
		}
    }

    /** How long, in seconds, an owner record may sit before a later acquirer
     * treats it as leaked and breaks it.
     *
     * Derived from max_execution_time because a live request cannot outlive it,
     * but capped at LOCK_STALE_CEILING_SECONDS because that ini value is
     * host-controlled and unbounded. See the constant for the incident this
     * ceiling exists to prevent.
     *
     * @return int
     */
    private function staleLockThresholdSeconds() {
        $maxExecutionTime = ini_get('max_execution_time');

        if (empty($maxExecutionTime) || !is_numeric($maxExecutionTime) || (int)$maxExecutionTime < 1) {
            return self::LOCK_STALE_FALLBACK_SECONDS;
        }

        return (int) min((int)$maxExecutionTime * 2, self::LOCK_STALE_CEILING_SECONDS);
    }

    /** Record that this request now owns $internalSynchronizedKey, and make
     * sure the shutdown release hook is wired.
     *
     * @param string $internalSynchronizedKey
     * @param string $uniqueID
     * @return void
     */
    private function rememberHeldLock($internalSynchronizedKey, $uniqueID) {
        $this->locksHeldThisRequest[$internalSynchronizedKey] = $uniqueID;

        if ($this->shutdownReleaseRegistered) {
            return;
        }
        $this->shutdownReleaseRegistered = true;

        // Two hooks, same idempotent handler, because they run at different
        // points and only one of them is always available.
        //
        // WordPress registers shutdown_action_hook() (which fires the
        // 'shutdown' action and THEN calls wp_cache_close()) from
        // wp-settings.php, long before plugins load -- so it always runs
        // before anything this plugin can register. Releasing from the
        // 'shutdown' action therefore happens while the object cache is still
        // open, which matters in options mode: a delete_option() whose cache
        // invalidation silently failed would leave other requests reading the
        // released owner record straight out of a persistent object cache,
        // recreating the very wedge this release exists to prevent.
        //
        // The raw shutdown function is the backstop for the cases the action
        // cannot cover: a fatal before WordPress's action system is usable, or
        // a site where something unhooked shutdown_action_hook().
        if (function_exists('add_action')) {
            add_action('shutdown', array($this, 'releaseLocksLeakedByThisRequest'));
        }
        register_shutdown_function(array($this, 'releaseLocksLeakedByThisRequest'));
    }

    /** Drop $internalSynchronizedKey from the outstanding set, but only when
     * the caller is releasing the same acquisition we recorded. A double
     * release of an old unique ID must not cancel the crash-safe release of a
     * newer acquisition of the same key in the same request.
     *
     * @param string $internalSynchronizedKey
     * @param string $uniqueID
     * @return void
     */
    private function forgetHeldLock($internalSynchronizedKey, $uniqueID) {
        if (array_key_exists($internalSynchronizedKey, $this->locksHeldThisRequest)
                && $this->locksHeldThisRequest[$internalSynchronizedKey] === $uniqueID) {
            unset($this->locksHeldThisRequest[$internalSynchronizedKey]);
        }
    }

    /** Shutdown hook: release any lock this request acquired but never released.
     *
     * Callers all release in a finally block, which covers thrown exceptions.
     * It does NOT cover the failure modes that actually leak a lock: a fatal
     * error, memory exhaustion, or a request timeout terminates the request
     * without unwinding, so `finally` never runs. PHP does still run shutdown
     * functions in those cases, which makes this the only place a leaked lock
     * can be reclaimed by the process that leaked it.
     *
     * Public because register_shutdown_function() has to be able to call it;
     * it is idempotent and only ever deletes an owner record whose value still
     * matches a unique ID this request minted, so a lock that has since been
     * broken or taken over by another request is left alone.
     *
     * It is also RESUMABLE, which is a stronger property than idempotent and
     * the reason each key is dropped from the outstanding map individually,
     * after its own owner record is gone, rather than clearing the map up
     * front. This method can be re-entered from the top while a pass is still
     * suspended mid-loop: PHP's LiteSpeed SAPI handles SIGTERM by calling
     * php_request_shutdown() from inside the signal handler
     * (lsapi_main.c:714-728), which fires the 'shutdown' action again, and
     * this method is deliberately hooked both there and on
     * register_shutdown_function(). Under LSAPI the handler then calls
     * exit(1), so the suspended pass never resumes and the re-entrant pass is
     * the last one that runs. Emptying the map before the deletes would leave
     * that final pass with nothing to do and leak every lock the interrupted
     * pass had not reached yet, deferring the next database or version upgrade
     * until the stale-lock breaker fires (up to LOCK_STALE_CEILING_SECONDS).
     *
     * @return void
     */
    function releaseLocksLeakedByThisRequest() {
        if (empty($this->locksHeldThisRequest)) {
            return;
        }

        // Snapshot the keys only, so a re-entrant pass that releases and forgets
        // some of them cannot make this foreach skip a key or trip over a
        // mutation mid-iteration. The map itself stays authoritative: each key
        // is re-read from it below and left in place until its record is gone.
        foreach (array_keys($this->locksHeldThisRequest) as $internalSynchronizedKey) {
            if (!array_key_exists($internalSynchronizedKey, $this->locksHeldThisRequest)) {
                // A re-entrant pass already released this one.
                continue;
            }
            $uniqueID = $this->locksHeldThisRequest[$internalSynchronizedKey];

            try {
                if ($this->ownerStore->readOwner($internalSynchronizedKey) !== $uniqueID) {
                    // Already broken by the stale-lock heuristic, taken over by
                    // another request, or released by a re-entrant pass. Not
                    // ours to delete, and nothing left to retry.
                    $this->forgetHeldLock($internalSynchronizedKey, $uniqueID);
                    continue;
                }

                $this->ownerStore->deleteOwner(array(
                    'key' => $internalSynchronizedKey,
                    'owner' => $uniqueID,
                ));

                // The record is gone, so this key's work is durably done. Drop
                // it before anything else can throw: a key still in the map is
                // a key a later pass will retry, and retrying a delete could
                // remove a record another request has since acquired.
                $this->forgetHeldLock($internalSynchronizedKey, $uniqueID);

                $logger = abj_service('logging');
                $logger->warn("Released a synchronization lock that this request " .
                    "acquired but never released (the request ended without reaching the " .
                    "release call, e.g. a fatal error, memory exhaustion, or a timeout " .
                    "inside the critical section). Key: " . $internalSynchronizedKey .
                    ", value: " . $uniqueID);

            } catch (Throwable $e) {
                // Shutdown context: the logging service (or whatever fataled)
                // may no longer be usable, so fall back to the centralized raw
                // PHP error-log sink rather than losing the failure. The key
                // stays in the outstanding map on this path on purpose -- a
                // delete that threw is unfinished work, and the second shutdown
                // hook (or a re-entrant pass) has to be able to retry it.
                if (function_exists('abj404_logPhpFallback')) {
                    abj404_logPhpFallback('fatal-handler-fallback',
                        'Failed to release leaked synchronization lock ' .
                        $internalSynchronizedKey . ': ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * @return string a random string of characters.
     * @throws Exception
     */
    function uniqidReal() {
        $bytes = null;
    	if (function_exists("random_bytes")) {
    	    try {
    		  $bytes = random_bytes(max(1, (int)ceil(13 / 2)));
    	    } catch (Exception $e) { // allow-silent-catch: random_bytes unavailable; fall through to openssl then uniqid
    	        $bytes = null;
    	    }
    	}

    	if ($bytes == null && function_exists("openssl_random_pseudo_bytes")) {
    	    try {
    		  $bytes = openssl_random_pseudo_bytes((int)ceil(13 / 2));
    	    } catch (Exception $e) { // allow-silent-catch: openssl fallback unavailable; fall through to uniqid
    	      $bytes = null;
    	    }
    	}

    	if ($bytes != null) {
    	    return bin2hex($bytes);
    	}
    	return uniqid("", true);
    }

}
