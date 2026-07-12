<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php public Logging entry-point tests for debug writes and log rotation.

/**
 * File-store for the plugin debug log.
 *
 * Owns debug-log path derivation, sanitized line writes, rotation/deletion,
 * size accounting, and the dedupe-pointer reset that must happen whenever
 * line numbers become invalid. It intentionally does not decide when to log
 * or send feedback reports; ABJ_404_Solution_Logging remains that facade.
 */
class ABJ_404_Solution_DebugLogFileStore {

    /** @var callable */
    private $sanitizeLogLine;

    /** @var ABJ_404_Solution_LoggingStateStore The single recursion-safe chokepoint for logging-owned scalars. */
    private $stateStore;

    /**
     * Per-blog memo of the resolved debug filename. Logging writes one file
     * for the lifetime of a single blog context, so the key is read/generated
     * once per blog and reused; this also keeps the filename stable when the
     * underlying option write does not round-trip within the request (e.g. a
     * deferred object cache), which would otherwise make getDebugFilename()
     * regenerate the key and have deleteDebugFile() wipe a file just written.
     * Cleared by deleteDebugFile(). Scoped to the blog id active at cache time
     * (not just the request) because the underlying debug_file_key lives in
     * abj404_settings, a per-blog option: a multisite background batch that
     * switch_to_blog()s mid-request would otherwise permanently pin the
     * memoized filename to whichever blog happened to trigger the first
     * resolution, silently splitting the debug log across two files once the
     * blog context restores.
     *
     * @var string|null
     */
    private $cachedDebugFilename = null;

    /** @var int|null Blog id $cachedDebugFilename was resolved for. */
    private $cachedDebugFilenameBlogId = null;

    /**
     * @param callable $sanitizeLogLine Receives a raw line and returns the sanitized line to write.
     * @param ABJ_404_Solution_LoggingStateStore $stateStore Recursion-safe accessor for the
     *        debug-file suffix and last-sent-line scalars (raw read/write only).
     */
    public function __construct(callable $sanitizeLogLine, ABJ_404_Solution_LoggingStateStore $stateStore) {
        $this->sanitizeLogLine = $sanitizeLogLine;
        $this->stateStore = $stateStore;
    }

    /**
     * Write one sanitized line to the active debug file.
     *
     * @param string $line
     * @param string $debugFilePath
     * @return bool True on success, false on disk/permission failure.
     */
    public function writeLine(string $line, string $debugFilePath): bool {
        $sanitizedLine = (string)call_user_func($this->sanitizeLogLine, $line);
        $result = @file_put_contents($debugFilePath, $sanitizedLine . "\n", FILE_APPEND);

        if ($result === false) {
            abj404_logPhpFallback(
                'logger-internal',
                'Unable to write to debug log (possibly disk full): ' . $debugFilePath
            );
            return false;
        }

        return true;
    }

    /** @return string */
    public function getDebugFilePath(): string {
        return $this->getFilePathAndMoveOldFile(abj404_getUploadsDir(), $this->getDebugFilename());
    }

    /** @return string */
    public function getDebugFilename(): string {
        $currentBlogId = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
        if ($this->cachedDebugFilename !== null && $this->cachedDebugFilenameBlogId === $currentBlogId) {
            return $this->cachedDebugFilename;
        }
        // A blog switch mid-request (multisite background batch) re-derives the
        // filename for the newly-active blog without wiping files: the glob
        // delete below is orphan cleanup for a genuinely fresh boot, not a
        // routine step of every blog switch. On a shared-uploads-dir multisite
        // config (the UPLOADS constant), the glob is not blog-scoped the way
        // abj404_getUploadsDir() usually is, so running it on every keyless
        // blog visited mid-request could delete another blog's in-use file.
        $isFirstResolutionThisRequest = ($this->cachedDebugFilename === null);
        try {
            // Logging MUST read its metadata via the raw, side-effect-free
            // state store, never getOptions(). getOptions() runs the normalize
            // pipeline, which logs a warning on any schema-validation failure;
            // that warning re-enters this method and recurses without bound
            // until memory is exhausted (the 4.3.0 "broken sites after the
            // latest update" OOM at PluginLogicOptionsResolver line ~250). The
            // store reaches storage with the raw accessor only.
            $debugFileKey = $this->stateStore->getDebugFileKey();

            if ($debugFileKey === null || trim($debugFileKey) === '') {
                if ($isFirstResolutionThisRequest) {
                    $this->deleteDebugFile();
                }

                $syncUtils = abj_service('sync_utils');
                if (!is_object($syncUtils) || !method_exists($syncUtils, 'uniqidReal')) {
                    return 'abj404_debug.txt';
                }
                $debugFileKey = $syncUtils->uniqidReal();
                $this->stateStore->setDebugFileKey($debugFileKey);
            }

            $this->cachedDebugFilename = 'abj404_debug_' . $debugFileKey . '.txt';
            $this->cachedDebugFilenameBlogId = $currentBlogId;
            return $this->cachedDebugFilename;
        } catch (\Throwable $e) { // allow-silent-catch: debug filename derivation; fallback name keeps logging available during degraded boot
            return 'abj404_debug.txt';
        }
    }

    /** @return string */
    public function getDebugFilePathOld(): string {
        return $this->getDebugFilePath() . "_old.txt";
    }

    /** @return string */
    public function getDebugFilePathSentFile(): string {
        return $this->getFilePathAndMoveOldFile(abj404_getUploadsDir(), 'abj404_debug_sent_line.txt');
    }

    /** @return string */
    public function getZipFilePath(): string {
        return $this->getFilePathAndMoveOldFile(abj404_getUploadsDir(), 'abj404_debug.zip');
    }

    /**
     * Create the storage directory and migrate a legacy root-level file if present.
     *
     * @param string $directory
     * @param string $filename
     * @return string
     */
    public function getFilePathAndMoveOldFile($directory, $filename): string {
        if (!ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
            return ABJ404_PATH . $filename;
        }

        if (file_exists(ABJ404_PATH . $filename)) {
            rename(ABJ404_PATH . $filename, $directory . $filename);
        }

        return $directory . $filename;
    }

    /** @return void */
    public function limitDebugFileSize(string $sentFilePath, string $oldDebugFilePath, string $debugFilePath): void {
        if (file_exists($sentFilePath)) {
            ABJ_404_Solution_FileSystemService::safeUnlink($sentFilePath);
        }

        $this->removeLastSentErrorLineFromDatabase();
        ABJ_404_Solution_FileSystemService::safeUnlink($oldDebugFilePath);
        rename($debugFilePath, $oldDebugFilePath);
    }

    /** @return void */
    public function removeLastSentErrorLineFromDatabase(): void {
        // Raw write only via the recursion-safe state store -- logging metadata
        // must not pass through the getOptions()/updateOptions() normalize-and-
        // log pipeline (recursion).
        $this->stateStore->setLastSentLine(0);
    }

    /** @return bool true if every matching debug file was deleted. */
    public function deleteDebugFile(): bool {
        $allIsWell = true;

        if (file_exists($this->getDebugFilePathSentFile())) {
            ABJ_404_Solution_FileSystemService::safeUnlink($this->getDebugFilePathSentFile());
        }
        $this->removeLastSentErrorLineFromDatabase();

        $uploadDir = abj404_getUploadsDir();
        if (is_dir($uploadDir)) {
            $files = glob($uploadDir . '/abj404_debug_*.txt');
            if (!is_array($files)) {
                $files = array();
            }
            foreach ($files as $file) {
                if (is_file($file) && !ABJ_404_Solution_FileSystemService::safeUnlink($file)) {
                    $allIsWell = false;
                }
            }
        }

        // Raw write only via the recursion-safe state store -- clearing the
        // debug-file key must not pass through getOptions()/updateOptions(),
        // whose normalize step logs on validation failure and would re-enter
        // logging from this delete-during-logging path (the 4.3.0 recursion).
        // The in-request filename memo is dropped too so the next
        // getDebugFilename() re-derives the key.
        $this->stateStore->setDebugFileKey(null);
        $this->cachedDebugFilename = null;
        $this->cachedDebugFilenameBlogId = null;

        return $allIsWell;
    }

    /** @return int file size in bytes */
    public function getDebugFileSize(string $debugFilePath, string $oldDebugFilePath): int {
        $file1Size = 0;
        $file2Size = 0;
        if (file_exists($debugFilePath)) {
            $file1Size = (int)filesize($debugFilePath);
        }
        if (file_exists($oldDebugFilePath)) {
            $file2Size = (int)filesize($oldDebugFilePath);
        }

        return $file1Size + $file2Size;
    }
}
