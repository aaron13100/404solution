<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Select diagnostic journal files without dropping known failure evidence.
 *
 * Candidate files are ordered as one oldest-first stream. Request lifecycle
 * state is folded across EVERY candidate before any file is capped, so a
 * request whose start and terminal records landed in different files is still
 * classified once. Files that contain a known or server-detected failure are
 * pinned. Files that cannot be classified safely are pinned too and carry a
 * bounded reason in the primary support POST. Only proven ordinary context is
 * subject to the recent-file cap.
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
    const MAX_MANIFEST_CLASSIFICATION_ISSUES = 32;

    /**
     * Choose files only after identifying which ones hold failure evidence.
     *
     * All known-failure, server-failure, and unclassifiable files are pinned,
     * even when there are more than MAX_RECENT_FILES of them. The remaining
     * allowance is filled with the newest proven-ordinary files.
     *
     * @param array<int, string> $paths Candidate files in the caller's oldest-first order.
     * @param array<string, bool> $knownFailingIds Cross-journal failed request IDs.
     * @return array{paths: array<int, string>, manifest: array<string, mixed>}
     */
    public static function select(array $paths, array $knownFailingIds = array()): array {
        $files = self::classifyFiles($paths, $knownFailingIds);
        $knownFailureFiles = 0;
        $serverFailureFiles = 0;
        $classificationIssueFiles = 0;
        $pinnedFiles = 0;
        foreach ($files as $file) {
            if ($file['knownFailure']) {
                $knownFailureFiles++;
            }
            if ($file['serverFailure']) {
                $serverFailureFiles++;
            }
            if ($file['classificationIssues'] !== array()) {
                $classificationIssueFiles++;
            }
            if ($file['pinned']) {
                $pinnedFiles++;
            }
        }
        $selectedIndexes = self::selectedIndexes($files, $pinnedFiles);
        return self::selectionResult(
            $files,
            $selectedIndexes,
            $knownFailureFiles,
            $serverFailureFiles,
            $classificationIssueFiles,
            $pinnedFiles
        );
    }

    /**
     * @param array<int, string> $paths
     * @param array<string, bool> $knownFailingIds
     * @return array<int, array{path: string, modified: int, order: int,
     *   requestIds: array<int, string>, knownFailure: bool, serverFailure: bool,
     *   classificationIssues: array<int, string>, pinned: bool}>
     */
    private static function classifyFiles(array $paths, array $knownFailingIds): array {
        $files = self::oldestFirstExistingFiles($paths);
        $requestGroups = array();
        foreach ($files as $index => $file) {
            $facts = self::requestFactsInFile(
                $file['path'],
                $knownFailingIds,
                $requestGroups
            );
            $files[$index]['requestIds'] = $facts['requestIds'];
            $files[$index]['knownFailure'] = $facts['knownFailure'];
            $files[$index]['classificationIssues'] = $facts['classificationIssues'];
        }

        $serverFailingIds = array();
        foreach ($requestGroups as $requestId => $group) {
            if ($group->isFailing()) {
                $serverFailingIds[(string)$requestId] = true;
            }
        }
        foreach ($files as $index => $file) {
            $serverFailure = false;
            foreach ($file['requestIds'] as $requestId) {
                if (isset($serverFailingIds[$requestId])) {
                    $serverFailure = true;
                    break;
                }
            }
            $files[$index]['serverFailure'] = $serverFailure;
            $files[$index]['pinned'] = $file['knownFailure']
                || $serverFailure
                || $file['classificationIssues'] !== array();
        }
        return $files;
    }

    /**
     * @param array<int, array{pinned: bool}> $files
     * @return array<int, bool>
     */
    private static function selectedIndexes(array $files, int $pinnedFiles): array {
        $ordinarySlots = max(0, self::MAX_RECENT_FILES - $pinnedFiles);
        $ordinaryIndexes = array();
        foreach ($files as $index => $file) {
            if (!$file['pinned']) {
                $ordinaryIndexes[] = $index;
            }
        }
        $keptOrdinary = $ordinarySlots === 0
            ? array() : array_slice($ordinaryIndexes, -$ordinarySlots);
        $selectedIndexes = array_fill_keys($keptOrdinary, true);
        foreach ($files as $index => $file) {
            if ($file['pinned']) {
                $selectedIndexes[$index] = true;
            }
        }
        return $selectedIndexes;
    }

    /**
     * @param array<int, array{path: string, requestIds: array<int, string>,
     *   classificationIssues: array<int, string>}> $files
     * @param array<int, bool> $selectedIndexes
     * @return array{paths: array<int, string>, manifest: array<string, mixed>}
     */
    private static function selectionResult(
        array $files,
        array $selectedIndexes,
        int $knownFailureFiles,
        int $serverFailureFiles,
        int $classificationIssueFiles,
        int $pinnedFiles
    ): array {
        $selectedPaths = array();
        $droppedNames = array();
        $droppedRequestIds = array();
        $seenDroppedRequestIds = array();
        $classificationIssues = array();
        foreach ($files as $index => $file) {
            if ($file['classificationIssues'] !== array()) {
                $classificationIssues[] = array(
                    'name' => basename($file['path']),
                    'reasons' => $file['classificationIssues'],
                );
            }
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
                'policy' => 'failure_and_uncertain_evidence_then_newest',
                'max_recent_files' => self::MAX_RECENT_FILES,
                'existing_files' => count($files),
                'selected_files' => count($selectedPaths),
                'known_failure_files' => $knownFailureFiles,
                'server_failure_files' => $serverFailureFiles,
                'classification_issue_files' => $classificationIssueFiles,
                'pinned_files' => $pinnedFiles,
                'classification_issues' => array_slice(
                    $classificationIssues, 0, self::MAX_MANIFEST_CLASSIFICATION_ISSUES),
                'classification_issues_omitted' => max(
                    0, count($classificationIssues) - self::MAX_MANIFEST_CLASSIFICATION_ISSUES),
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
     * Fold one file into the shared request lifecycle index.
     *
     * @param array<string, bool> $knownFailingIds
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $requestGroups
     * @return array{requestIds: array<int, string>, knownFailure: bool,
     *   classificationIssues: array<int, string>}
     */
    private static function requestFactsInFile(
        string $path,
        array $knownFailingIds,
        array &$requestGroups
    ): array {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            self::reportFailure('Diagnostic journal could not be scanned: ' . $path);
            return array(
                'requestIds' => array(),
                'knownFailure' => false,
                'classificationIssues' => array('unreadable'),
            );
        }
        $requestIds = array();
        $knownFailure = false;
        $classificationIssues = array();
        $nonEmptyLines = 0;
        try {
            while (($line = @fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $nonEmptyLines++;
                $record = json_decode($line, true);
                if (!is_array($record)) {
                    $classificationIssues['invalid_json'] = true;
                    continue;
                }
                $requestId = is_scalar($record['request_id'] ?? null)
                    ? (string)$record['request_id']
                    : '';
                if ($requestId === '') {
                    $classificationIssues['missing_request_id'] = true;
                    continue;
                }
                $requestIds[$requestId] = true;
                if (isset($knownFailingIds[$requestId])) {
                    $knownFailure = true;
                }
                if (!isset($requestGroups[$requestId])) {
                    $requestGroups[$requestId] =
                        new ABJ_404_Solution_DiagnosticRequestGroup($requestId, true);
                }
                $requestGroups[$requestId]->applyRecord($record);
            }
        } finally {
            @fclose($handle);
        }
        if ($nonEmptyLines === 0) {
            $classificationIssues['no_records'] = true;
        }
        return array(
            'requestIds' => array_keys($requestIds),
            'knownFailure' => $knownFailure,
            'classificationIssues' => array_keys($classificationIssues),
        );
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('ajax-trace', $message);
    }
}
