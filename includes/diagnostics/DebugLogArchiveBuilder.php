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
     * Name of the entry that states what the archive was ASKED to carry.
     *
     * A file that does not exist is skipped rather than failing the archive,
     * which leaves the receiver with the same ambiguity the support payload's
     * empty excerpt used to have: nothing separates "this site wrote no
     * journal" from "the builder was handed paths on the wrong node". The
     * manifest is how a zip that is missing a journal says so out loud. Same
     * property as ABJ_404_Solution_DiagnosticCollectionManifest, on the
     * out-of-band channel.
     */
    const MANIFEST_ENTRY = 'abj404-archive-manifest.txt';

    /**
     * Create a zip containing the current and rotated debug logs, plus any
     * additional diagnostic files the caller wants carried WHOLE.
     *
     * The additional-paths channel exists because the support payload's
     * diagnostic journals ride a byte budget and a ranking: whatever that
     * budget decides to drop is gone, and the failing session is one we only
     * get once. The archive has no such bound, so it is the out-of-band copy.
     *
     * @param string $zipPath
     * @param string $debugFilePath
     * @param string $oldDebugFilePath
     * @param array<int, string> $additionalPaths Absolute paths; missing ones are skipped but stated.
     * @return string The requested zip path, or an empty string when ZipArchive is unavailable.
     */
    public function build(string $zipPath, string $debugFilePath, string $oldDebugFilePath,
            array $additionalPaths = array()): string {
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
            foreach ($additionalPaths as $additionalPath) {
                if (is_string($additionalPath) && $additionalPath !== '' && file_exists($additionalPath)) {
                    $zip->addFile($additionalPath, basename($additionalPath));
                }
            }
            $zip->addFromString(self::MANIFEST_ENTRY,
                $this->manifest(array_merge(array($debugFilePath, $oldDebugFilePath), $additionalPaths)));
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

    /**
     * One line per requested path: whether it was there, how big it was, and
     * when it last changed. Requested paths, not added ones, because the
     * question this answers is "what did the collector look for", and the
     * absent ones are the interesting half of the answer.
     *
     * @param array<int, string> $requestedPaths
     */
    private function manifest(array $requestedPaths): string {
        $lines = array(
            'Files this archive was asked to carry, and whether they were found.',
            'Collected by ' . (gethostname() !== false ? (string)gethostname() : 'unknown-host')
                . ' pid ' . (string)getmypid() . ' (' . PHP_SAPI . ').',
            '',
        );
        foreach ($requestedPaths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            if (!file_exists($path)) {
                $lines[] = 'absent  ' . $path;
                continue;
            }
            $size = @filesize($path);
            $modified = @filemtime($path);
            $lines[] = 'present ' . $path
                . ' (' . (is_int($size) ? $size . ' bytes' : 'size unreadable')
                . ', modified ' . (is_int($modified) ? gmdate('Y-m-d H:i:s', $modified) . ' UTC' : 'unknown')
                . ')';
        }
        return implode("\n", $lines) . "\n";
    }
}
