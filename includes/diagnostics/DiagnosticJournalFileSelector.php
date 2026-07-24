<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Select diagnostic journal files without dropping known failure evidence.
 *
 * Candidate files are ordered as one oldest-first stream. Every file that
 * contains a request ID condemned in another journal is pinned; only the
 * remaining ordinary context is subject to the recent-file cap. The returned
 * manifest is carried in the primary support POST so every cap exclusion is
 * stated rather than silent.
 *
 * This class scans files but never reads an excerpt or ranks records. Those
 * remain ABJ_404_Solution_DiagnosticJournalExcerpt's responsibility.
 *
 * // allow-no-test-found: covered through the real support AJAX and primary POST in SupportRequestAjaxTest
 */
final class ABJ_404_Solution_DiagnosticJournalFileSelector {

    /** Ordinary recent files retained after known-failure files are pinned. */
    const MAX_RECENT_FILES = 8;

    /** Detail bounds for the primary-POST file-selection manifest. */
    const MAX_MANIFEST_DROPPED_FILE_NAMES = 32;
    const MAX_MANIFEST_DROPPED_REQUEST_IDS = 128;

    /**
     * Choose files only after identifying which ones hold known failures.
     *
     * All known-failure files are pinned, even when there are more than
     * MAX_RECENT_FILES of them. The remaining allowance is filled with the
     * newest ordinary files.
     *
     * @param array<int, string> $paths Candidate files in the caller's oldest-first order.
     * @param array<string, bool> $knownFailingIds Cross-journal failed request IDs.
     * @return array{paths: array<int, string>, manifest: array<string, mixed>}
     */
    public static function select(array $paths, array $knownFailingIds = array()): array {
        $files = self::classifyFiles($paths, $knownFailingIds);
        $knownFailureFiles = 0;
        foreach ($files as $file) {
            if ($file['knownFailure']) {
                $knownFailureFiles++;
            }
        }
        $selectedIndexes = self::selectedIndexes($files, $knownFailureFiles);
        return self::selectionResult($files, $selectedIndexes, $knownFailureFiles);
    }

    /**
     * @param array<int, string> $paths
     * @param array<string, bool> $knownFailingIds
     * @return array<int, array{path: string, modified: int, order: int, requestIds: array<int, string>, knownFailure: bool}>
     */
    private static function classifyFiles(array $paths, array $knownFailingIds): array {
        $files = self::oldestFirstExistingFiles($paths);
        foreach ($files as $index => $file) {
            $facts = self::requestFactsInFile($file['path'], $knownFailingIds);
            $files[$index]['requestIds'] = $facts['requestIds'];
            $files[$index]['knownFailure'] = $facts['knownFailure'];
        }
        return $files;
    }

    /**
     * @param array<int, array{knownFailure: bool}> $files
     * @return array<int, bool>
     */
    private static function selectedIndexes(array $files, int $knownFailureFiles): array {
        $ordinarySlots = max(0, self::MAX_RECENT_FILES - $knownFailureFiles);
        $ordinaryIndexes = array();
        foreach ($files as $index => $file) {
            if (!$file['knownFailure']) {
                $ordinaryIndexes[] = $index;
            }
        }
        $keptOrdinary = $ordinarySlots === 0
            ? array() : array_slice($ordinaryIndexes, -$ordinarySlots);
        $selectedIndexes = array_fill_keys($keptOrdinary, true);
        foreach ($files as $index => $file) {
            if ($file['knownFailure']) {
                $selectedIndexes[$index] = true;
            }
        }
        return $selectedIndexes;
    }

    /**
     * @param array<int, array{path: string, requestIds: array<int, string>}> $files
     * @param array<int, bool> $selectedIndexes
     * @return array{paths: array<int, string>, manifest: array<string, mixed>}
     */
    private static function selectionResult(
        array $files,
        array $selectedIndexes,
        int $knownFailureFiles
    ): array {
        $selectedPaths = array();
        $droppedNames = array();
        $droppedRequestIds = array();
        $seenDroppedRequestIds = array();
        foreach ($files as $index => $file) {
            if (isset($selectedIndexes[$index])) {
                $selectedPaths[] = $file['path'];
                continue;
            }
            $droppedNames[] = basename($file['path']);
            foreach ($file['requestIds'] as $requestId) {
                if (!isset($seenDroppedRequestIds[$requestId])) {
                    $seenDroppedRequestIds[$requestId] = true;
                    $droppedRequestIds[] = $requestId;
                }
            }
        }

        return array(
            'paths' => $selectedPaths,
            'manifest' => array(
                'policy' => 'known_failures_then_newest',
                'max_recent_files' => self::MAX_RECENT_FILES,
                'existing_files' => count($files),
                'selected_files' => count($selectedPaths),
                'known_failure_files' => $knownFailureFiles,
                'dropped_files' => count($droppedNames),
                'dropped_file_names' => array_slice(
                    $droppedNames, 0, self::MAX_MANIFEST_DROPPED_FILE_NAMES),
                'dropped_file_names_omitted' => max(
                    0, count($droppedNames) - self::MAX_MANIFEST_DROPPED_FILE_NAMES),
                'dropped_request_ids' => array_slice(
                    $droppedRequestIds, 0, self::MAX_MANIFEST_DROPPED_REQUEST_IDS),
                'dropped_request_ids_omitted' => max(
                    0, count($droppedRequestIds) - self::MAX_MANIFEST_DROPPED_REQUEST_IDS),
            ),
        );
    }

    /**
     * Existing paths ordered by mtime, with caller order breaking ties.
     *
     * @param array<int, string> $paths
     * @return array<int, array{path: string, modified: int, order: int}>
     */
    private static function oldestFirstExistingFiles(array $paths): array {
        $files = array();
        foreach (array_values($paths) as $order => $path) {
            $modified = @filemtime($path);
            if (is_int($modified)) {
                $files[] = array('path' => $path, 'modified' => $modified, 'order' => $order);
            }
        }
        usort($files, static function (array $left, array $right): int {
            if ($left['modified'] === $right['modified']) {
                return $left['order'] <=> $right['order'];
            }
            return $left['modified'] <=> $right['modified'];
        });
        return $files;
    }

    /**
     * Request IDs in one file plus whether any is known to have failed.
     *
     * @param array<string, bool> $knownFailingIds
     * @return array{requestIds: array<int, string>, knownFailure: bool}
     */
    private static function requestFactsInFile(string $path, array $knownFailingIds): array {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            self::reportFailure('Diagnostic journal could not be scanned: ' . $path);
            return array('requestIds' => array(), 'knownFailure' => false);
        }
        $requestIds = array();
        $knownFailure = false;
        try {
            while (($line = @fgets($handle)) !== false) {
                if (strpos($line, '"request_id"') === false) {
                    continue;
                }
                $record = json_decode($line, true);
                $requestId = is_array($record) && is_scalar($record['request_id'] ?? null)
                    ? (string)$record['request_id'] : '';
                if ($requestId === '') {
                    continue;
                }
                $requestIds[$requestId] = true;
                if (isset($knownFailingIds[$requestId])) {
                    $knownFailure = true;
                }
            }
        } finally {
            @fclose($handle);
        }
        return array('requestIds' => array_keys($requestIds), 'knownFailure' => $knownFailure);
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('ajax-trace', $message);
    }
}
