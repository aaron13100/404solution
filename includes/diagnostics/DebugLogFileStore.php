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

    /** @var string */
    private $debugFileKeyOptionName;

    /** @var string */
    private $lastSentLineOptionName;

    /**
     * Per-request memo of the resolved debug filename. Logging writes one file
     * for the lifetime of the request, so the key is read/generated once and
     * reused; this also keeps the filename stable when the underlying option
     * write does not round-trip within the request (e.g. a deferred object
     * cache), which would otherwise make getDebugFilename() regenerate the key
     * and have deleteDebugFile() wipe a file just written. Cleared by
     * deleteDebugFile().
     *
     * @var string|null
     */
    private $cachedDebugFilename = null;

    /**
     * @param callable $sanitizeLogLine Receives a raw line and returns the sanitized line to write.
     * @param string $debugFileKeyOptionName Option key that stores the debug-file suffix.
     * @param string $lastSentLineOptionName Option key that stores the last sent debug-log line.
     */
    public function __construct(callable $sanitizeLogLine, string $debugFileKeyOptionName, string $lastSentLineOptionName) {
        $this->sanitizeLogLine = $sanitizeLogLine;
        $this->debugFileKeyOptionName = $debugFileKeyOptionName;
        $this->lastSentLineOptionName = $lastSentLineOptionName;
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
        if ($this->cachedDebugFilename !== null) {
            return $this->cachedDebugFilename;
        }
        try {
            $optionsRepo = abj_service('options_repository');
            // Logging MUST read its metadata via the raw, side-effect-free
            // accessor, never getOptions(). getOptions() runs the normalize
            // pipeline, which logs a warning on any schema-validation failure;
            // that warning re-enters this method and recurses without bound
            // until memory is exhausted (the 4.3.0 "broken sites after the
            // latest update" OOM at PluginLogicOptionsResolver line ~250).
            if (!is_object($optionsRepo) || !method_exists($optionsRepo, 'getRawSettingValue')) {
                return 'abj404_debug.txt';
            }
            $rawKey = $optionsRepo->getRawSettingValue($this->debugFileKeyOptionName);
            $debugFileKey = is_string($rawKey) ? $rawKey : null;

            if ($debugFileKey === null || trim($debugFileKey) === '') {
                $this->deleteDebugFile();

                $syncUtils = abj_service('sync_utils');
                if (!is_object($syncUtils) || !method_exists($syncUtils, 'uniqidReal')) {
                    return 'abj404_debug.txt';
                }
                $debugFileKey = $syncUtils->uniqidReal();
                if (method_exists($optionsRepo, 'setRawSettingValue')) {
                    $optionsRepo->setRawSettingValue($this->debugFileKeyOptionName, $debugFileKey);
                }
            }

            $this->cachedDebugFilename = 'abj404_debug_' . $debugFileKey . '.txt';
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
        $optionsRepo = abj_service('options_repository');
        // Raw write only -- logging metadata must not pass through the
        // getOptions()/updateOptions() normalize-and-log pipeline (recursion).
        if (is_object($optionsRepo) && method_exists($optionsRepo, 'setRawSettingValue')) {
            $optionsRepo->setRawSettingValue($this->lastSentLineOptionName, 0);
        }
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

        // Raw write only -- clearing the debug-file key must not pass through
        // getOptions()/updateOptions(), whose normalize step logs on validation
        // failure and would re-enter logging from this delete-during-logging
        // path (the 4.3.0 recursion). The in-request filename memo is dropped
        // too so the next getDebugFilename() re-derives the key.
        $optionsRepo = abj_service('options_repository');
        if (is_object($optionsRepo) && method_exists($optionsRepo, 'setRawSettingValue')) {
            $optionsRepo->setRawSettingValue($this->debugFileKeyOptionName, null);
        }
        $this->cachedDebugFilename = null;

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
