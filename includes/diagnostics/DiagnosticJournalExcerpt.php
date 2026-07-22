<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A bounded, readable tail of a set of JSONL diagnostic files.
 *
 * The plugin keeps more than one durable diagnostic journal for a single AJAX
 * request, on purpose: the stage trace (ABJ_404_Solution_AjaxTraceJournal) and
 * the independent checkpoint log (ABJ_404_Solution_AjaxCheckpointLogger), which
 * exists precisely so a defect in the trace cannot erase the evidence about it.
 * Both have to reach the support payload, and both need the same read: newest
 * file last, whole lines only, everything inside one byte budget.
 *
 * That read lives here rather than in either journal, so the checkpoint logger
 * never has to depend on the trace journal it is deliberately independent of.
 * This class only reads bytes; it decides nothing about WHICH files matter or
 * what a record means. Callers supply the paths, the budget, and the header.
 */
final class ABJ_404_Solution_DiagnosticJournalExcerpt {

    /** Newest files a single excerpt will draw from. Older ones are dropped whole. */
    const MAX_FILES = 8;

    /**
     * Compose one labeled excerpt block from the newest of the given paths.
     *
     * Paths that do not exist are skipped silently: a journal that was never
     * written is a normal state, not a failure. The per-file share of the
     * budget is equal, so one busy file cannot starve the others out of the
     * excerpt.
     *
     * @param array<int, string> $paths Candidate files, any order; missing ones are ignored.
     * @param int $budgetBytes Hard ceiling for the returned string, header included.
     * @param string $header Section label, e.g. "Recent AJAX stage traces (JSONL):\n".
     * @return string Empty string when nothing readable was found.
     */
    public static function compose(array $paths, int $budgetBytes, string $header): string {
        try {
            $files = self::newestFirstExisting($paths);
            if ($files === array()) {
                return '';
            }
            $contentBudget = $budgetBytes - strlen($header) - count($files);
            if ($contentBudget <= 0) {
                return '';
            }
            $perFileLimit = max(1, intdiv($contentBudget, count($files)));
            $parts = array();
            foreach ($files as $path) {
                $tail = self::readFileTail($path, $perFileLimit);
                if ($tail !== '') {
                    $parts[] = $tail;
                }
            }
            return $parts === array() ? '' : $header . implode("\n", $parts);
        } catch (Throwable $e) {
            self::reportFailure('Diagnostic journal excerpt failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * The existing paths, oldest modification time first so the newest file is
     * the last thing a reader sees. Ties break on path so the order is stable
     * across calls (several journals routinely share one mtime second).
     *
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    private static function newestFirstExisting(array $paths): array {
        $files = array();
        foreach ($paths as $path) {
            $modified = @filemtime($path);
            if (is_int($modified)) {
                $files[] = array('path' => $path, 'modified' => $modified);
            }
        }
        usort($files, static function (array $left, array $right): int {
            if ($left['modified'] === $right['modified']) {
                return strcmp($left['path'], $right['path']);
            }
            return $left['modified'] <=> $right['modified'];
        });
        $files = array_slice($files, -self::MAX_FILES);
        $ordered = array();
        foreach ($files as $file) {
            $ordered[] = $file['path'];
        }
        return $ordered;
    }

    /**
     * Last $limit bytes of a file, trimmed forward to a line boundary so a
     * reader never gets half a JSON record.
     */
    private static function readFileTail(string $path, int $limit): string {
        if ($limit <= 0 || !@is_file($path)) {
            return '';
        }
        $size = @filesize($path);
        if (!is_int($size)) {
            self::reportFailure('Diagnostic journal size could not be read: ' . $path);
            return '';
        }
        $offset = max(0, $size - $limit);
        $contents = @file_get_contents($path, false, null, $offset, $limit);
        if (!is_string($contents)) {
            self::reportFailure('Diagnostic journal could not be read: ' . $path);
            return '';
        }
        if ($offset > 0) {
            $newline = strpos($contents, "\n");
            $contents = $newline === false ? '' : substr($contents, $newline + 1);
        }
        return trim($contents);
    }

    private static function reportFailure(string $message): void {
        // Unconditional; see AjaxCheckpointLogger::reportFailure().
        abj404_logPhpFallback('ajax-trace', $message);
    }
}
