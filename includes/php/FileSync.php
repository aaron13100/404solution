<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_FileSync {

	/** How long an empty owner file must have sat before claimOwnerFile()
	 * treats it as abandoned rather than as a claim in progress. Only a fatal
	 * between the exclusive create and the write can produce one, so the grace
	 * period only has to outlast those few microseconds; it is seconds rather
	 * than milliseconds purely so a filesystem with coarse mtime granularity
	 * cannot make a live claim look abandoned.
	 * @var int */
	const EMPTY_OWNER_FILE_GRACE_SECONDS = 5;

	/** Upper bound for contention on the per-key mutation guard. */
	const OWNER_MUTATION_GUARD_WAIT_MICROSECONDS = 250000;

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
	 * Take ownership of $key, but only if nobody owns it yet.
	 *
	 * This is the whole mutual-exclusion primitive for file storage mode, and
	 * it is atomic by construction: fopen() with mode 'x' is O_CREAT|O_EXCL,
	 * which the kernel resolves for exactly one caller no matter how many
	 * arrive at once. Nothing here reads the current owner and then decides,
	 * because a read-then-write protocol has a window between the two steps
	 * where a second request can read the same "unowned" answer and both then
	 * write. That window is what handed two requests the same
	 * 'update_db_version' lock in error report 270.
	 *
	 * A file that exists but is EMPTY is a lock nobody can break: the owner
	 * reads as '', so the stale-lock check returns early and never deletes it,
	 * while this method keeps failing because the file is there. Only two
	 * things can produce one -- a write that failed after the create (disk
	 * full, quota) and a fatal in the microseconds between the create and the
	 * write -- and both are handled: the first by unlinking before returning,
	 * the second by reclaiming a stale empty file here rather than leaving the
	 * key wedged until someone clears the uploads directory by hand.
	 *
	 * @param array{key: string, owner: string} $claim
	 * @return bool true only if this call created the owner record.
	 */
	function claimOwnerFile(array $claim): bool {
		$key = $claim['key'];
		$uniqueID = $claim['owner'];
		$filePath = $this->getSyncFilePath($key);
		return $this->withOwnerMutationGuard($filePath, function () use ($filePath, $uniqueID): bool {
			if ($this->createOwnerFileExclusively(array(
				'filePath' => $filePath,
				'owner' => $uniqueID,
			))) {
				return true;
			}
			if (!$this->reclaimAbandonedEmptyOwnerFile($filePath)) {
				return false;
			}
			return $this->createOwnerFileExclusively(array(
				'filePath' => $filePath,
				'owner' => $uniqueID,
			));
		});
	}

	/**
	 * One O_CREAT|O_EXCL attempt. Leaves no file behind on any failure path, so
	 * a caller that gets false can trust that it created nothing.
	 *
	 * @param array{filePath: string, owner: string} $claim
	 * @return bool
	 */
	private function createOwnerFileExclusively(array $claim): bool {
		$filePath = $claim['filePath'];
		$uniqueID = $claim['owner'];
		$handle = @fopen($filePath, 'xb');
		if ($handle === false) {
			return false;
		}

		$written = @fwrite($handle, $uniqueID);
		$flushed = @fflush($handle);
		@fclose($handle);

		if ($written === false || $written !== strlen($uniqueID) || $flushed === false) {
			// The record would read back as unowned while still blocking every
			// later claim. Remove it and report the claim as lost, which is the
			// safe direction: the caller skips its critical section.
			ABJ_404_Solution_FileSystemService::safeUnlink($filePath);
			return false;
		}

		return true;
	}

	/** Delete an owner file that is empty and old enough that no live request
	 * could still be between its create and its write.
	 *
	 * @param string $filePath
	 * @return bool true if a file was removed and a retry is worth attempting.
	 */
	private function reclaimAbandonedEmptyOwnerFile(string $filePath): bool {
		clearstatcache(true, $filePath);
		if (!is_file($filePath) || filesize($filePath) !== 0) {
			return false;
		}

		$modifiedAt = @filemtime($filePath);
		if ($modifiedAt === false || (abj_clock()->now() - $modifiedAt) < self::EMPTY_OWNER_FILE_GRACE_SECONDS) {
			return false;
		}

		ABJ_404_Solution_FileSystemService::safeUnlink($filePath);
		clearstatcache(true, $filePath);
		return !is_file($filePath);
	}
	
	/**
	 * @param array{key: string, owner: string} $release
	 * @return bool true only when the named owner was removed
	 */
	function releaseLock(array $release): bool {
		$key = $release['key'];
		$uniqueID = $release['owner'];
		$filePath = $this->getSyncFilePath($key);
		return $this->withOwnerMutationGuard($filePath, function () use ($filePath, $uniqueID): bool {
			try {
				$currentOwner = ABJ_404_Solution_FileSystemService::readFileContents($filePath, false);
			} catch (Exception $e) {
				if (!is_file($filePath)) {
					return false;
				}
				throw $e;
			}
			if ($currentOwner !== $uniqueID) {
				return false;
			}
			ABJ_404_Solution_FileSystemService::safeUnlink($filePath);
			clearstatcache(true, $filePath);
			return !is_file($filePath);
		});
	}

	/**
	 * Serialize owner-file replacement and conditional release for one key.
	 *
	 * @template T
	 * @param string $filePath
	 * @param callable(): T $operation
	 * @return T
	 */
	private function withOwnerMutationGuard(string $filePath, callable $operation) {
		$guardPath = $filePath . '.guard';
		ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages(dirname($guardPath));
		$guard = @fopen($guardPath, 'c');
		if ($guard === false) {
			throw new RuntimeException('Could not open lock-owner mutation guard: ' . $guardPath);
		}
		try {
			$attemptsRemaining = max(1, intdiv(self::OWNER_MUTATION_GUARD_WAIT_MICROSECONDS, 10000));
			while (!@flock($guard, LOCK_EX | LOCK_NB)) {
				$attemptsRemaining--;
				if ($attemptsRemaining <= 0) {
					throw new RuntimeException('Timed out acquiring lock-owner mutation guard: ' . $guardPath);
				}
				usleep(10000);
			}
			return $operation();
		} finally {
			@flock($guard, LOCK_UN);
			@fclose($guard);
		}
	}
	
}
