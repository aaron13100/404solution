<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php zip-failure mail fallback entry-point test.

/**
 * Builds the developer-feedback debug-log zip attachment.
 *
 * The builder only creates the archive from paths supplied by the caller. It
 * does not send mail and does not decide whether a missing/failed archive
 * should abort the report.
 */
class ABJ_404_Solution_DebugLogArchiveBuilder {

    /**
     * Create a zip containing the current and rotated debug logs.
     *
     * @param string $zipPath
     * @param string $debugFilePath
     * @param string $oldDebugFilePath
     * @return string The requested zip path, or an empty string when ZipArchive is unavailable.
     */
    public function build(string $zipPath, string $debugFilePath, string $oldDebugFilePath): string {
        if (file_exists($zipPath)) {
            ABJ_404_Solution_FileSystemService::safeUnlink($zipPath);
        }
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $zipDirectory = dirname($zipPath);
        if (!is_dir($zipDirectory)) {
            abj404_logPhpFallback('logger-internal', 'debug log zip directory does not exist: ' . $zipDirectory);
            return $zipPath;
        }
        $zip = new ZipArchive;
        $openResult = $zip->open($zipPath, ZipArchive::CREATE);
        if ($openResult === true) {
            if (file_exists($debugFilePath)) {
                $zip->addFile($debugFilePath, basename($debugFilePath));
            }
            if (file_exists($oldDebugFilePath)) {
                $zip->addFile($oldDebugFilePath, basename($oldDebugFilePath));
            }
            if (!$zip->close()) {
                abj404_logPhpFallback('logger-internal', 'debug log zip close failed for ' . $zipPath);
            }
        } else {
            abj404_logPhpFallback(
                'logger-internal',
                'debug log zip open failed for ' . $zipPath . ' (status ' . (string)$openResult . ')'
            );
        }
        return $zipPath;
    }
}
