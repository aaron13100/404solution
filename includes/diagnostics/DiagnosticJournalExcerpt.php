<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A bounded, readable excerpt of a set of JSONL diagnostic files.
 *
 * The plugin keeps more than one durable diagnostic journal for a single AJAX
 * request, on purpose: the stage trace (ABJ_404_Solution_AjaxTraceJournal) and
 * the independent checkpoint log (ABJ_404_Solution_AjaxCheckpointLogger), which
 * exists precisely so a defect in the trace cannot erase the evidence about it.
 * Both have to reach the support payload, and both need the same read: whole
 * lines only, oldest file first, everything inside one byte budget.
 *
 * That read lives here rather than in either journal, so the checkpoint logger
 * never has to depend on the trace journal it is deliberately independent of.
 *
 * This class reads bytes and formats the block. It decides nothing about WHICH
 * files matter (callers supply the paths, the budget and the header) and
 * nothing about which RECORDS matter -- that ranking is
 * ABJ_404_Solution_DiagnosticEvidencePriority's, and it is the reason this
 * class no longer splits the budget evenly per file and no longer takes a
 * blind byte tail. Measured on a real session, that older read shipped 4 of 72
 * request IDs and none of the failing ones: the files are one ordered stream of
 * records now, ranked by what actually failed.
 */
final class ABJ_404_Solution_DiagnosticJournalExcerpt {

    /** Newest files a single excerpt will draw from. Older ones are dropped whole. */
    const MAX_FILES = 8;

    /**
     * Ceiling on the bytes read from disk before ranking, across all files.
     *
     * The allowance is spent NEWEST first, so whatever it cannot cover is the
     * OLDEST bytes -- which in a failing session are the first failures, the
     * most diagnostic records there are. That makes an undersized read bound a
     * SECOND, independent place a session can be lost, on top of rotation, and
     * the two compound in the worst possible way: immediately after a
     * rotation, the head of the rotated file is exactly the oldest evidence
     * and the current file's bytes are spent before it.
     *
     * So this is sized to hold a whole retained journal pair rather than to a
     * session length: 2x ABJ_404_Solution_CheckpointJournalWriter's 4 MB
     * rotation bound. Whatever survived rotation is then always fully read,
     * and retention has one owner (the rotation bound) instead of two.
     * Written as a literal rather than derived from that constant so this
     * class stays usable by any journal, not only the checkpoint one.
     *
     * The ceiling still exists for its original reason: a file that grew past
     * its own bound (a rotation that could not rename) must not turn a support
     * click into an out-of-memory admin request. Decoded records are dropped
     * per line by the ranking pass, so the live cost is the raw lines, not a
     * parsed copy of them.
     */
    const MAX_TOTAL_READ_BYTES = 8388608;

    /** Bytes held back from the content budget for the accounting line and its newline. */
    const SUMMARY_RESERVE_BYTES = 512;

    /**
     * Compose one labeled excerpt block from the newest of the given paths.
     *
     * Paths that do not exist are skipped silently: a journal that was never
     * written is a normal state, not a failure.
     *
     * @param array<int, string> $paths Candidate files, any order; missing ones are ignored.
     * @param int $budgetBytes Hard ceiling for the returned string, header included.
     * @param string $header Section label, e.g. "Recent AJAX stage traces (JSONL):\n".
     * @return string Empty string when nothing readable was found.
     */
    public static function compose(array $paths, int $budgetBytes, string $header): string {
        try {
            $files = self::oldestFirstExisting($paths);
            if ($files === array()) {
                return '';
            }
            $contentBudget = $budgetBytes - strlen($header) - self::SUMMARY_RESERVE_BYTES;
            if ($contentBudget <= 0) {
                return '';
            }
            $read = self::readLines($files);
            if ($read['lines'] === array()) {
                return '';
            }
            $selected = ABJ_404_Solution_DiagnosticEvidencePriority::select($read['lines'], $contentBudget);
            if ($selected['lines'] === array()) {
                return '';
            }
            $summary = array_merge($selected['summary'], array(
                'files_read' => $read['filesRead'],
                'files_skipped' => $read['filesSkipped'],
                'bytes_unread' => $read['bytesUnread'],
            ));
            return $header . self::summaryLine($summary) . "\n" . implode("\n", $selected['lines']);
        } catch (Throwable $e) {
            self::reportFailure('Diagnostic journal excerpt failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * The existing paths, oldest modification time first so a reader walks the
     * session forwards.
     *
     * Ties break on the CALLER'S order, never on the path. Callers list their
     * files oldest-first (rotated, then current, then any live spool), and
     * filemtime() has one-second granularity, so a journal that rotates and is
     * appended to inside the same second reports both files as equally old.
     * Sorting those by name put `abj404_ajax_checkpoints.jsonl` ahead of
     * `abj404_ajax_checkpoints.old.jsonl` -- 'j' before 'o' -- which reversed
     * the session and made the ranking treat the newest requests as the
     * oldest. Ordering is load-bearing now that it decides which requests are
     * "recent context", so it may not rest on a filename coincidence.
     *
     * @param array<int, string> $paths Oldest first, as the caller understands them.
     * @return array<int, string>
     */
    private static function oldestFirstExisting(array $paths): array {
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
        $files = array_slice($files, -self::MAX_FILES);
        $ordered = array();
        foreach ($files as $file) {
            $ordered[] = $file['path'];
        }
        return $ordered;
    }

    /**
     * Every whole line from the given files as one oldest-first stream.
     *
     * The read allowance is consumed newest file first, so if it runs out the
     * bytes lost are the OLDEST -- but ranking then happens over everything
     * that was read, which is what stops a busy file from displacing the
     * failing requests in another one.
     *
     * @param array<int, string> $files Oldest first.
     * @return array{lines: array<int, string>, filesRead: int, filesSkipped: int, bytesUnread: int}
     */
    private static function readLines(array $files): array {
        $allowance = self::MAX_TOTAL_READ_BYTES;
        $perFile = array();
        $filesRead = 0;
        $filesSkipped = 0;
        $bytesUnread = 0;
        foreach (array_reverse($files) as $path) {
            $size = @filesize($path);
            if (!is_int($size)) {
                self::reportFailure('Diagnostic journal size could not be read: ' . $path);
                $filesSkipped++;
                continue;
            }
            if ($allowance <= 0) {
                $filesSkipped++;
                $bytesUnread += $size;
                continue;
            }
            $contents = self::readFileTail($path, $allowance);
            if ($contents === '') {
                $filesSkipped++;
                $bytesUnread += $size;
                continue;
            }
            $filesRead++;
            $bytesUnread += max(0, $size - strlen($contents));
            $allowance -= strlen($contents);
            $perFile[] = $contents;
        }

        $lines = array();
        foreach (array_reverse($perFile) as $contents) {
            foreach (explode("\n", $contents) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }
        return array(
            'lines' => $lines,
            'filesRead' => $filesRead,
            'filesSkipped' => $filesSkipped,
            'bytesUnread' => $bytesUnread,
        );
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

    /**
     * The accounting line. JSON, so the whole block below the human header
     * stays parseable as JSONL, and bounded, so it can never eat into the
     * evidence it is describing.
     *
     * @param array<string, int> $summary
     */
    private static function summaryLine(array $summary): string {
        $line = json_encode(array('abj404_excerpt_summary' => $summary), JSON_UNESCAPED_SLASHES);
        // +1 for the newline that follows it, which the reserve also covers.
        if (!is_string($line) || strlen($line) + 1 > self::SUMMARY_RESERVE_BYTES) {
            return '{"abj404_excerpt_summary":{"encoding_failed":1}}';
        }
        return $line;
    }

    private static function reportFailure(string $message): void {
        // Unconditional; see AjaxCheckpointLogger::reportFailure().
        abj404_logPhpFallback('ajax-trace', $message);
    }
}
