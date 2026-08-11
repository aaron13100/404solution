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
     * So this is sized to hold the checkpoint channel's whole retained source:
     * two 4 MB ordinary generations, two 4 MB fixed-intent generations, and
     * the 1 MB append-only active-operation journal. Whatever survived rotation is then
     * always fully read, and retention has one owner (the writer bounds)
     * instead of a smaller second retention window in the reader. Written as
     * a literal rather than derived from those constants so this class stays
     * usable by any journal, not only the checkpoint one.
     *
     * The ceiling still exists for its original reason: a file that grew past
     * its own bound (a rotation that could not rename) must not turn a support
     * click into an out-of-memory admin request. Decoded records are dropped
     * per line by the ranking pass, so the live cost is the raw lines, not a
     * parsed copy of them.
     */
    const MAX_TOTAL_READ_BYTES = 17825792;

    /** Bytes held back from the content budget for the accounting line and its newline. */
    const SUMMARY_RESERVE_BYTES = 512;

    /**
     * Compose one labeled excerpt block from the newest of the given paths.
     *
     * Paths that do not exist are skipped: a journal that was never written is
     * a normal state, not a failure. That silence is only READABLE because the
     * support collector states, separately and unconditionally, which
     * directories and files it checked -- see
     * ABJ_404_Solution_DiagnosticCollectionManifest. Without that manifest an
     * empty return here is indistinguishable from a wrong directory, a wrong
     * node, or a regression in this reader, which is precisely how beta.1's
     * "the journal came back empty" ended up undiagnosable. Do NOT make this
     * method the place that explains itself: a reader is the last component
     * that can be trusted to describe its own failure, which is why the
     * manifest stats the files independently instead.
     *
     * @param array<int, string> $paths Candidate files, any order; missing ones are ignored.
     * @param int $budgetBytes Hard ceiling for the returned string, header included.
     * @param string $header Section label, e.g. "Recent AJAX stage traces (JSONL):\n".
     * @param array<string, bool> $knownFailingIds Requests condemned in ANOTHER journal,
     *   from failureIndex(). Without them this excerpt ranks only on what its own
     *   files say, and the browser says it in one journal and not the other.
     * @param callable(array<int, string>):array<int, string>|null $lineTransform
     *   Channel-specific normalization applied before evidence ranking.
     * @param array{paths: array<int, string>, manifest: array<string, mixed>}|null $fileSelection
     *   A selection produced by DiagnosticJournalFileSelector. Passing it
     *   keeps the excerpt and collection manifest on one decision.
     * @return string Empty string when nothing readable was found.
     */
    public static function compose(array $paths, int $budgetBytes, string $header,
            array $knownFailingIds = array(), ?callable $lineTransform = null,
            ?array $fileSelection = null): string {
        try {
            $selection = $fileSelection
                ?? ABJ_404_Solution_DiagnosticJournalFileSelector::select($paths, $knownFailingIds);
            $files = isset($selection['paths']) && is_array($selection['paths'])
                ? $selection['paths'] : array();
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
            $lines = $lineTransform === null ? $read['lines'] : $lineTransform($read['lines']);
            if (!is_array($lines) || $lines === array()) {
                return '';
            }
            $selected = ABJ_404_Solution_DiagnosticEvidencePriority::select(
                $lines, $contentBudget, $knownFailingIds);
            if ($selected['lines'] === array()) {
                return '';
            }
            $summary = array_merge($selected['summary'], array(
                'files_read' => $read['filesRead'],
                'files_skipped' => $read['filesSkipped'],
                'bytes_unread' => $read['bytesUnread'],
                'files_dropped_by_cap' => self::selectionCount($selection, 'dropped_files'),
                'known_failure_files' => self::selectionCount($selection, 'known_failure_files'),
                'server_failure_files' => self::selectionCount($selection, 'server_failure_files'),
                'classification_issue_files' =>
                    self::selectionCount($selection, 'classification_issue_files'),
                'pinned_files' => self::selectionCount($selection, 'pinned_files'),
            ));
            return $header . self::summaryLine($summary) . "\n" . implode("\n", $selected['lines']);
        } catch (Throwable $e) {
            self::reportFailure('Diagnostic journal excerpt failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Every request id the browser condemned in one channel's journals.
     *
     * Read as its own pass, BEFORE any excerpt is composed, because the answer
     * has to be available to a journal that does not contain it: the browser's
     * verdicts live only in the checkpoint journal, and the stage trace has to
     * rank the same requests as failing or it spends a browser-lost request's
     * stage timings on budget as ordinary context. Callers build the index per
     * channel and pass the union into every compose() call -- one index, both
     * journals, so the two can never disagree about which requests failed.
     *
     * This narrow pass scans every candidate before file selection and retains
     * only verdict lines. Applying the excerpt's file cap first would erase the
     * IDs needed to pin the older evidence file.
     *
     * @param array<int, string> $paths One channel's candidate files, as compose() takes them.
     * @return array<string, bool> Condemned request ids, keyed by id; empty when unreadable.
     */
    public static function failureIndex(array $paths): array {
        try {
            return ABJ_404_Solution_DiagnosticClientVerdict::requestIdsIn(
                self::failureVerdictLines($paths));
        } catch (Throwable $e) {
            self::reportFailure('Diagnostic failure index failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * One channel's journals as a single oldest-first stream of whole lines.
     *
     * Whole-journal consumers share the same ordinary-file selection and byte
     * allowance as an excerpt with no externally known failures.
     *
     * @param array<int, string> $paths One channel's candidate files, as compose() takes them.
     * @return array<int, string> Empty when nothing readable was found.
     */
    public static function readAllLines(array $paths): array {
        try {
            $selection = ABJ_404_Solution_DiagnosticJournalFileSelector::select($paths);
            $files = $selection['paths'];
            if ($files === array()) {
                return array();
            }
            return self::readLines($files)['lines'];
        } catch (Throwable $e) {
            self::reportFailure('Diagnostic journal read failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Browser-verdict lines from every existing candidate, before file capping.
     *
     * This pass deliberately has no file-count or byte-budget decision: it
     * stores only the two event shapes that can condemn another request, so a
     * later file selection cannot erase the IDs needed to pin their evidence.
     *
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    private static function failureVerdictLines(array $paths): array {
        $lines = array();
        foreach ($paths as $path) {
            if (!@is_file($path)) {
                continue;
            }
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                self::reportFailure('Diagnostic failure index could not scan: ' . $path);
                continue;
            }
            try {
                while (($line = @fgets($handle)) !== false) {
                    if (strpos($line, ABJ_404_Solution_DiagnosticClientVerdict::PRIOR_ATTEMPT_EVENT) === false
                            && strpos($line,
                                ABJ_404_Solution_DiagnosticClientVerdict::BEACON_BRANCH_EVENT) === false) {
                        continue;
                    }
                    $line = trim($line);
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            } finally {
                @fclose($handle);
            }
        }
        return $lines;
    }

    /**
     * @param array{manifest?: array<string, mixed>} $selection
     */
    private static function selectionCount(array $selection, string $field): int {
        $manifest = isset($selection['manifest']) && is_array($selection['manifest'])
            ? $selection['manifest'] : array();
        return is_numeric($manifest[$field] ?? null) ? (int)$manifest[$field] : 0;
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
