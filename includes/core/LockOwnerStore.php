<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where a synchronizer lock's owner record lives, and how it is claimed, read
 * and released.
 *
 * This is the data-access half of the lock machinery: it knows about the
 * WordPress options table, the uploads directory, and multisite network
 * options, and nothing about waiting, breaking, or bookkeeping a lock.
 * ABJ_404_Solution_SynchronizationUtils owns that protocol and talks to this
 * class for storage.
 *
 * Every operation here is atomic and answers from the SHARED store, never from
 * a per-request cache. claimOwner() is a compare-and-set (an exclusive file
 * create, or an INSERT arbitrated by UNIQUE(option_name)); deleteOwner() only
 * removes a record whose value the caller named; and the read in options mode
 * is direct SQL rather than get_option(). That last point is not a performance
 * choice: WordPress serves get_option() from the object cache and primes it on
 * write, so without a persistent object cache a request reads back its own
 * write. A protocol built on such a read cannot detect the loser of a race, and
 * error report 270 is what that looks like in production -- two requests
 * running the 4.3.2 to 4.3.3 database upgrade in the same second, each holding
 * what it believed was the exclusive 'update_db_version' lock.
 *
 * The split matters because the storage decision is genuinely independent
 * behavior with its own persistent state and its own failure modes: a host
 * whose options table will not round-trip a value latches this site onto
 * file-based records for good, and that latch has to be re-derived per blog on
 * multisite. SynchronizationUtilsBlogScopeTest exercises exactly that, without
 * taking a single lock.
 */
class ABJ_404_Solution_LockOwnerStore {

	/** Whether owner records are stored as files rather than options.
	 * @var bool|null */
	static $usingFileMode = null;

	/**
	 * Blog id self::$usingFileMode was decided for. isFileMode() derives the
	 * decision from per-blog state (abj404_getUploadsDir() -> wp_upload_dir(),
	 * and a get_option()/update_option()/delete_option() round-trip against
	 * the per-blog options table), but the decision itself is cached in a
	 * bare static for the lifetime of the process. Multisite background
	 * batches (e.g. ABJ_404_Solution_DatabaseUpgradeMultiSite's per-site
	 * work) switch_to_blog()/restore_current_blog() around per-site work in
	 * the SAME request/singleton lifetime; without this blog-id check, a
	 * decision minted for one blog (e.g. "file mode" because that blog's
	 * options table round-trip failed) would silently be reused for a
	 * different, healthy blog's lock operations.
	 *
	 * @var int|null
	 */
	static $usingFileModeBlogId = null;

	/** A prefix for keys used for synchronization methods.
	 * @var string */
	const SYNC_KEY_PREFIX = 'SYNC_';
	const NETWORK_ATOMIC_READY_OPTION = 'abj404_network_lock_atomic_ready_at';
	const NETWORK_ATOMIC_MIGRATION_DELAY = 86400;

	/**
	 * Test seam: clear the cached file-vs-options latch so the next call
	 * re-derives it.
	 *
	 * @return void
	 */
	public static function resetForTests() {
	    self::$usingFileMode = null;
	    self::$usingFileModeBlogId = null;
	}

	/** Build the storage key an owner record is filed under.
	 *
	 * @param string $keyFromUser
	 * @return string
	 */
	function createInternalKey($keyFromUser) {
	    return ABJ404_PP . "_" . self::SYNC_KEY_PREFIX . $keyFromUser;
	}

	/** @return string */
	private function getFileModePath() {
		return abj404_getUploadsDir() . 'sync_mode_file.txt';
	}

	/** @return string */
	private function getOptionsModePath() {
		return abj404_getUploadsDir() . 'sync_mode_options.txt';
	}

	/** @return bool */
	function isFileMode() {
		$currentBlogId = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;

		if (self::$usingFileMode == null || self::$usingFileModeBlogId !== $currentBlogId) {
			$fileModePath = $this->getFileModePath();
			$optionsModePath = $this->getOptionsModePath();
			if (file_exists($fileModePath) && file_exists($optionsModePath)) {
				ABJ_404_Solution_FileSystemService::safeUnlink($fileModePath);
				ABJ_404_Solution_FileSystemService::safeUnlink($optionsModePath);
			}

			if (file_exists($fileModePath)) {
				$usingFileMode = true;

			} else if (file_exists($optionsModePath)) {
				$usingFileMode = false;

			} else {
				// initialize
				$pass = true;
				$keyForTesting = $this->createInternalKey('testing');
				$uniqueID = 'testing_' . uniqid('', true);

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

				ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages(dirname($optionsModePath));
				if ($pass) {
					$usingFileMode = false;
					touch($optionsModePath);
				} else {
					$usingFileMode = true;
					touch($fileModePath);
				}
			}
			self::$usingFileMode = $usingFileMode;
			self::$usingFileModeBlogId = $currentBlogId;
		}

		return self::$usingFileMode;
	}

	/** Latch this site onto file-based owner records.
	 *
	 * Any lock a request already holds was written to the options table and
	 * becomes unreachable once the mode flips, so neither the normal release
	 * nor the crash-safe release can delete it. That is not a wedge: the mode
	 * files persist, so later requests read owner records from disk and never
	 * consult the orphaned option row again. If the site is ever flipped back
	 * to options mode, the orphan carries an old acquisition timestamp and is
	 * broken by the stale-lock check.
	 *
	 * @return void */
	function switchToFileSyncMode() {
		self::$usingFileMode = true;
		self::$usingFileModeBlogId = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
		$optionsModePath = $this->getOptionsModePath();
		ABJ_404_Solution_FileSystemService::safeUnlink($optionsModePath);

		$fileModePath = $this->getFileModePath();
		ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages(dirname($fileModePath));
		touch($fileModePath);
	}

    /**
     * Take ownership of $key, but only if nobody owns it yet.
     *
     * This is the mutual-exclusion primitive itself, and it is atomic in both
     * storage modes: an O_CREAT|O_EXCL file create, or an INSERT that the
     * options table's UNIQUE(option_name) index can satisfy exactly once. The
     * caller never reads first and then decides, because the gap between a read
     * and the write that follows it is a window in which a second request reads
     * the same "unowned" answer -- which is how error report 270 ended up with
     * two requests holding 'update_db_version' at the same moment.
     *
     * @param array{key: string, owner: string} $claim
     * @return bool true only if this call created the owner record.
     */
    function claimOwner(array $claim) {
		$key = $claim['key'];
		$uniqueID = $claim['owner'];
		if (!$this->networkAtomicStorageReady($key, true)) {
			return false;
		}
    	if ($this->isFileMode()) {
    		$fileSync = ABJ_404_Solution_FileSync::getInstance();
    		try {
				return $fileSync->claimOwnerFile($claim);
    		} catch (Throwable $e) {
    			// An unwritable uploads directory, a full disk, a revoked
    			// permission. Report the claim as lost, which stops the caller
    			// entering its critical section, and record why: a lock that can
    			// never be taken silently disables every synchronized section in
    			// the plugin, and that has to be diagnosable.
    			$this->logStorageFailure('claim the lock owner record for key "' . $key . '"', $e);
    			return false;
    		}
    	}

		return $this->optionRowFor($key)->claim(array('optionName' => $key, 'value' => $uniqueID));
    }

    /**
     * @param string $key
     * @return string
     */
    function readOwner($key) {
		if (!$this->networkAtomicStorageReady($key, false)) {
			return '';
		}
    	$owner = '';
    	if ($this->isFileMode()) {
    		$fileSync = ABJ_404_Solution_FileSync::getInstance();
    		try {
    			$owner = $fileSync->getOwnerFromFile($key);
    		} catch (Throwable $e) {
    			// The lock file is present but unreadable. Treating that as
    			// "unlocked" is what the caller will do with '', and it is the
    			// only answer available -- but it is a guess, and an I/O failure
    			// read as an unlocked resource lets two workers into the same
    			// critical section. Record it so the corruption that may follow
    			// has something pointing back here.
    			$logger = abj_service('logging');
    			if (is_object($logger) && method_exists($logger, 'debugMessage')) {
    				$logger->debugMessage(
    					'Lock owner file for key "' . $key . '" exists but could not be read; '
    					. 'proceeding as if unowned. ' . get_class($e) . ' (code '
    					. (string)$e->getCode() . '): ' . $e->getMessage(),
    					$e
    				);
    			}
    			$owner = '';
    		}

    	} else {
    		$owner = $this->readOwnerRow($key);
    	}

    	return $owner;
    }

    /**
     * Release ownership of $key, but only if $owner still holds it.
     *
     * The condition is not a nicety. Every caller decides to delete on the
     * strength of a PRIOR read, and between that read and this call the record
     * can have been broken as stale or taken over by another request. Making
     * the delete itself carry the expected owner means a request can only ever
     * remove its own record, which is what stops a request that lost a race
     * from wiping the winner's lock.
     *
     * @param array{key: string, owner: string} $release
     * @return bool true if the record named by $owner was removed.
     */
    function deleteOwner(array $release) {
		$key = $release['key'];
		$owner = $release['owner'];
		if (!$this->networkAtomicStorageReady($key, false)) {
			return false;
		}
		if ($this->isFileMode()) {
			$fileSync = ABJ_404_Solution_FileSync::getInstance();
			return $fileSync->releaseLock($release);
    	}

		return $this->optionRowFor($key)->releaseIfValueIs(array('optionName' => $key, 'value' => $owner));
    }

    /** The owner value recorded in the options table.
     *
     * @param string $key
     * @return string '' when no record exists, or when the storage cannot answer.
     */
    private function readOwnerRow($key) {
    	return $this->optionRowFor($key)->valueOf($key);
    }

    /** The exclusive options row that holds $key's owner record.
     *
     * Network-wide locks contend on the network's main site. Acquisitions pause
     * for a drain window before this store is used, and remain paused while a
     * legacy sitemeta owner exists, so deployment cannot split one lock across
     * the old and new stores.
     *
     * @param string $key
     * @return ABJ_404_Solution_ExclusiveOptionRow
     */
    private function optionRowFor($key) {
    	return new ABJ_404_Solution_ExclusiveOptionRow($this->shouldUseNetworkStorage($key)
    		? ABJ_404_Solution_ExclusiveOptionRow::SCOPE_NETWORK_MAIN_SITE
    		: ABJ_404_Solution_ExclusiveOptionRow::SCOPE_CURRENT_BLOG);
    }

    /** Pause network locks while old sitemeta-based requests drain. */
    private function networkAtomicStorageReady(string $key, bool $initialize): bool {
		if (!$this->shouldUseNetworkStorage($key)) {
			return true;
		}
		$readyAt = get_site_option(self::NETWORK_ATOMIC_READY_OPTION, false);
		if ($readyAt === false || !is_numeric($readyAt)) {
			if ($initialize) {
				$stored = update_site_option(self::NETWORK_ATOMIC_READY_OPTION,
					(string)(abj_clock()->now() + self::NETWORK_ATOMIC_MIGRATION_DELAY));
				if (!$stored && function_exists('abj_service')) {
					$logger = abj_service('logging');
					if (is_object($logger) && method_exists($logger, 'warn')) {
						$logger->warn('Could not persist the network lock migration deadline; network lock work remains paused.');
					}
				}
			}
			return false;
		}
		if (abj_clock()->now() < (int)$readyAt) {
			return false;
		}
		// An old request still owns the legacy site option. Stay paused until
		// that owner releases it instead of opening a cross-store overlap.
		return get_site_option($key, false) === false;
    }

    /** Record a storage failure that cost the caller a lock.
     *
     * @param string $attempted what the store was trying to do
     * @param Throwable $e
     * @return void
     */
    private function logStorageFailure($attempted, Throwable $e) {
    	if (!function_exists('abj_service')) {
    		return;
    	}
    	$logger = abj_service('logging');
    	if (is_object($logger) && method_exists($logger, 'warn')) {
    		$logger->warn('Could not ' . $attempted . '; treating the lock as unavailable. '
    			. get_class($e) . ' (code ' . (string)$e->getCode() . '): ' . $e->getMessage());
    	}
    }

    /**
     * Check if the plugin is network-activated in a multisite environment.
     *
     * @return bool True if network-activated, false otherwise
     */
    private function isNetworkActivated() {
        // Synchronizer shutdown recovery can run from a deliberately minimal
        // bootstrap (and some hosts invoke shutdown handlers after WordPress
        // has only partially loaded). A missing multisite API means this
        // cannot be a network-wide lock; do not turn recovery into a fatal.
        if (!function_exists('is_multisite') || !is_multisite()) {
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
}
