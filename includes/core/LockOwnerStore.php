<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where a synchronizer lock's owner record lives, and how it is read, written,
 * and deleted.
 *
 * This is the data-access half of the lock machinery: it knows about the
 * WordPress options table, the uploads directory, and multisite network
 * options, and nothing about acquiring, waiting, breaking, or releasing a lock.
 * ABJ_404_Solution_SynchronizationUtils owns that protocol and talks to this
 * class for storage.
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
     * @param string $key
     * @return string
     */
    function readOwner($key) {
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
    		// MULTISITE: Use network-aware option for N-gram locks
    		$ownerRaw = $this->getNetworkAwareOption($key);
    		$owner = is_string($ownerRaw) ? $ownerRaw : '';
    	}

    	return $owner;
    }

    /**
     * @param string $key
     * @param string $owner
     * @return void
     */
    function writeOwner($key, $owner) {
    	if ($this->isFileMode()) {
    		$fileSync = ABJ_404_Solution_FileSync::getInstance();
    		$fileSync->writeOwnerToFile($key, $owner);
    	} else {
    		// MULTISITE: Use network-aware option for N-gram locks
    		$this->updateNetworkAwareOption($key, $owner);
    	}
    }

    /**
     * @param string $owner
     * @param string $key
     * @return void
     */
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
}
