<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable write-ahead storage and retention for AJAX trace records.
 *
 * Records are appended and flushed to a request-local pending spool as they
 * happen, then promoted whole into one bounded, rotated JSONL journal. The
 * spool is what survives a hard worker kill: a request that never reaches
 * PHP shutdown leaves its file behind, and a later request recovers it into
 * the journal rather than losing the last thing that request was doing.
 *
 * This class holds the retention policy and nothing else. It makes no
 * decision about WHAT is worth recording -- callers hand it fully formed
 * records -- which is deliberate: the beta.1 flight recorder failed because
 * a retention rule (delete fast-completing requests) silently erased the
 * evidence of the very requests under investigation. Retention now has one
 * home, one test surface, and exactly one bound: rotation.
 */
final class ABJ_404_Solution_AjaxTraceJournal {

    const JOURNAL_FILE = 'abj404_ajax_stage_trace.jsonl';
    const ROTATED_FILE = 'abj404_ajax_stage_trace.old.jsonl';
    const LOCK_FILE = 'abj404_ajax_stage_trace.lock';
    const PENDING_GLOB = 'abj404_ajax_trace_*.pending.jsonl';
    const RECOVER_PENDING_AFTER_SECONDS = 300;
    const MAX_JOURNAL_BYTES = 524288;
    const MAX_PENDING_BYTES = 32768;
    const PROMOTION_LOCK_WAIT_TIMEOUT_US = 50000;

    /**
     * Share of the support payload's excerpt field this journal may claim.
     * Smaller than the checkpoint journal's because a request costs ~11 stage
     * records here against ~27 checkpoints. The per-section budgets are proven
     * to sum inside the report contract by SupportExcerptBudgetContractTest.
     */
    const MAX_SUPPORT_EXCERPT_BYTES = 49152;

    /** @var string Trace directory, with a trailing separator. */
    private $directory;
    /** @var string */
    private $pendingPath;
    /** @var ABJ_404_Solution_Clock */
    private $clock;
    /** @var bool One failure report per request; a broken directory must not flood the debug log. */
    private $failureReported = false;

    public function __construct(string $directory, string $pendingPath, ABJ_404_Solution_Clock $clock) {
        $this->directory = $directory;
        $this->pendingPath = $pendingPath;
        $this->clock = $clock;
    }

    public function pendingPath(): string {
        return $this->pendingPath;
    }

    /**
     * Append one fully formed record to the pending spool and flush it, so
     * the record survives a kill that never reaches PHP shutdown.
     *
     * @param array<string, mixed> $record
     */
    public function append(array $record): void {
        if (@is_file($this->pendingPath)) {
            $size = @filesize($this->pendingPath);
            if (is_int($size) && $size >= self::MAX_PENDING_BYTES) {
                $this->reportFailure('AJAX pending trace reached its size limit: ' . $this->pendingPath);
                return;
            }
        }
        $this->appendJsonLine($this->pendingPath, $record);
    }

    /**
     * Move the whole pending spool into the durable journal, rotating first
     * when the append would cross the size bound. Every request is promoted:
     * there is no outcome-based retention.
     */
    public function promote(): void {
        if (!@is_file($this->pendingPath)) {
            return;
        }
        $lock = @fopen($this->directory . self::LOCK_FILE, 'cb');
        if ($lock === false) {
            $this->reportFailure('AJAX trace journal lock could not be opened. Pending evidence remains at '
                . $this->pendingPath);
            return;
        }
        if (!$this->acquirePromotionLock($lock)) {
            @fclose($lock);
            $this->reportFailure('AJAX trace journal lock wait exceeded. Pending evidence remains at '
                . $this->pendingPath);
            return;
        }
        try {
            $contents = @file_get_contents($this->pendingPath);
            if (!is_string($contents)) {
                $this->reportFailure('AJAX pending trace could not be read: ' . $this->pendingPath);
                return;
            }
            $journal = $this->directory . self::JOURNAL_FILE;
            $size = @filesize($journal);
            if (is_int($size) && ($size + strlen($contents)) > self::MAX_JOURNAL_BYTES) {
                $old = $this->directory . self::ROTATED_FILE;
                if (@is_file($old) && !@unlink($old)) {
                    $this->reportFailure('AJAX rotated trace could not be removed: ' . $old);
                    return;
                }
                if (@is_file($journal) && !@rename($journal, $old)) {
                    $this->reportFailure('AJAX trace journal rotation failed: ' . $journal);
                    return;
                }
            }
            $written = @file_put_contents($journal, $contents, FILE_APPEND | LOCK_EX);
            if ($written === false) {
                $this->reportFailure('AJAX trace journal append failed: ' . $journal);
                return;
            }
            $this->removePending();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * Promote spools left behind by workers that died without running PHP
     * shutdown. Each one is annotated with how it ended before promotion, so
     * a reader can tell "the request was killed here" apart from "the
     * request finished here".
     */
    public function recoverAbandoned(): void {
        $matches = glob($this->directory . self::PENDING_GLOB);
        $cutoff = $this->clock->now() - self::RECOVER_PENDING_AFTER_SECONDS;
        $ownPending = $this->pendingPath;
        foreach (is_array($matches) ? $matches : array() as $path) {
            $modified = @filemtime($path);
            if ($modified === false || $modified > $cutoff) {
                continue;
            }
            $this->pendingPath = $path;
            $handle = @fopen($path, 'rb');
            $firstLine = is_resource($handle) ? @fgets($handle) : false;
            if (is_resource($handle)) {
                @fclose($handle);
            }
            $originalContext = is_string($firstLine) ? json_decode($firstLine, true) : null;
            if (is_array($originalContext)) {
                unset($originalContext['event'], $originalContext['stage'], $originalContext['elapsed_ms']);
                $this->appendJsonLine($path, array_merge($originalContext, array(
                    'ts' => $this->clock->nowFloat(),
                    'event' => 'abandoned_recovered',
                    'status' => 'worker-ended-without-shutdown',
                )));
            } else {
                $this->reportFailure('Abandoned AJAX trace context could not be parsed: ' . $path);
            }
            $this->promote();
            $this->pendingPath = $ownPending;
        }
    }

    /**
     * Bounded recent journal lines for the existing support-request payload,
     * newest files last. Pending spools are included on purpose: a request
     * that is hung RIGHT NOW has written nothing to the journal yet, and it
     * is the most interesting request in the file.
     *
     * This journal holds no client verdicts of its own -- they are written to
     * the checkpoint journal -- so a caller that omits $knownFailingIds gets an
     * excerpt that cannot tell a browser-lost request from a healthy one. See
     * ABJ_404_Solution_DiagnosticJournalExcerpt::failureIndex().
     *
     * @param array<string, bool> $knownFailingIds
     * @param array{paths: array<int, string>, manifest: array<string, mixed>}|null $fileSelection
     *   Shared selection plan used by the primary support manifest.
     */
    public static function readRecentForSupport(
        array $knownFailingIds = array(),
        ?array $fileSelection = null
    ): string {
        try {
            $directory = self::resolveSupportDirectory();
            if ($directory === '') {
                return '';
            }
            return ABJ_404_Solution_DiagnosticJournalExcerpt::compose(
                self::supportExcerptPaths($directory),
                self::MAX_SUPPORT_EXCERPT_BYTES,
                "Recent AJAX stage traces (JSONL):\n",
                $knownFailingIds,
                null,
                $fileSelection
            );
        } catch (Throwable $e) {
            self::reportStaticFailure('AJAX trace support excerpt failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * What readRecentForSupport() will look at, whether or not any of it
     * exists, so ABJ_404_Solution_DiagnosticCollectionManifest can state what
     * was checked even when the answer is "nothing was there".
     *
     * The candidate list comes from the same private helper the reader itself
     * uses: a manifest that described a DIFFERENT set of files than the read
     * would be worse than no manifest at all.
     *
     * @return array{channel: string, directory: string, usable: bool, paths: array<int, string>}
     */
    public static function supportCollectionSource(): array {
        try {
            $directory = self::resolveSupportDirectory();
            return array(
                'channel' => 'ajax_stage_trace',
                'directory' => $directory,
                'usable' => $directory !== '',
                'paths' => $directory === '' ? array() : self::supportExcerptPaths($directory),
            );
        } catch (Throwable $e) {
            self::reportStaticFailure('AJAX trace support source resolution failed: ' . $e->getMessage());
            return array('channel' => 'ajax_stage_trace', 'directory' => '', 'usable' => false, 'paths' => array());
        }
    }

    /**
     * Rotated file, current journal, then any live pending spools -- oldest
     * first, which is the order the excerpt reader breaks mtime ties on.
     *
     * @param string $directory With a trailing separator.
     * @return array<int, string>
     */
    private static function supportExcerptPaths(string $directory): array {
        $paths = array(
            $directory . self::ROTATED_FILE,
            $directory . self::JOURNAL_FILE,
        );
        $pendingPaths = glob($directory . self::PENDING_GLOB);
        return is_array($pendingPaths) ? array_merge($paths, $pendingPaths) : $paths;
    }

    /**
     * Existing journal and pending-spool files, for a channel that carries
     * them WHOLE. See CheckpointJournalReader::supportArchivePaths().
     *
     * @return array<int, string>
     */
    public static function supportArchivePaths(): array {
        try {
            $directory = self::resolveSupportDirectory();
            if ($directory === '') {
                return array();
            }
            $paths = array();
            foreach (array(self::JOURNAL_FILE, self::ROTATED_FILE) as $name) {
                if (@is_file($directory . $name)) {
                    $paths[] = $directory . $name;
                }
            }
            $pendingPaths = glob($directory . self::PENDING_GLOB);
            return is_array($pendingPaths) ? array_merge($paths, $pendingPaths) : $paths;
        } catch (Throwable $e) {
            self::reportStaticFailure('AJAX trace archive path resolution failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * The trace directory as the read-side callers see it, with a trailing
     * separator, or '' when unavailable. Resolved through the same filter the
     * writer uses so a site that relocates the directory relocates every
     * reader with it.
     */
    private static function resolveSupportDirectory(): string {
        return ABJ_404_Solution_DiagnosticDirectoryResolver::resolve();
    }

    /** @param array<string, mixed> $record */
    private function appendJsonLine(string $path, array $record): bool {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $this->reportFailure('AJAX trace JSON encoding failed.');
            return false;
        }
        // The descriptor is held for the request rather than re-opened per
        // record, and the lock is taken on that same held descriptor. See
        // ABJ_404_Solution_DiagnosticAppendStream: this sink is lower volume
        // than the checkpoint journal, but it is the same shape and it shares
        // the fix rather than keeping a second copy of the write path.
        $acquired = ABJ_404_Solution_DiagnosticAppendStream::acquireExclusive(
            $path,
            self::PROMOTION_LOCK_WAIT_TIMEOUT_US
        );
        if ($acquired['status'] === 'failed') {
            $this->reportFailure('AJAX trace file could not be opened: ' . $path);
            return false;
        }
        if ($acquired['status'] === 'lock_timeout') {
            $this->reportFailure('AJAX trace file lock failed: ' . $path);
            return false;
        }
        try {
            $written = ABJ_404_Solution_DiagnosticAppendStream::append($path, $json . "\n");
            $ok = $written['status'] === 'complete';
            if (!$ok) {
                $this->reportFailure('AJAX trace append/flush failed: ' . $path);
            }
        } finally {
            ABJ_404_Solution_DiagnosticAppendStream::release($path);
        }
        return $ok;
    }

    private function removePending(): void {
        // Drop the held descriptor BEFORE unlinking: a descriptor to a deleted
        // inode accepts writes that no reader can ever find.
        ABJ_404_Solution_DiagnosticAppendStream::invalidate($this->pendingPath);
        if (@is_file($this->pendingPath) && !@unlink($this->pendingPath)) {
            $this->reportFailure('AJAX pending trace could not be removed: ' . $this->pendingPath);
        }
    }

    /** @param resource $lock */
    private function acquirePromotionLock($lock): bool {
        $started = $this->monotonicNanoseconds();
        do {
            if (@flock($lock, LOCK_EX | LOCK_NB)) {
                return true;
            }
            if ($this->elapsedMicroseconds($started) >= self::PROMOTION_LOCK_WAIT_TIMEOUT_US) {
                return false;
            }
            usleep(1000);
        } while (true);
    }

    private function monotonicNanoseconds(): int {
        return function_exists('hrtime')
            ? (int)hrtime(true)
            : (int)round($this->clock->nowFloat() * 1000000000);
    }

    private function elapsedMicroseconds(int $started): int {
        return max(0, (int)round(($this->monotonicNanoseconds() - $started) / 1000));
    }

    private function reportFailure(string $message): void {
        if ($this->failureReported) {
            return;
        }
        $this->failureReported = true;
        self::reportStaticFailure($message);
    }

    private static function reportStaticFailure(string $message): void {
        // Unconditional; see AjaxCheckpointLogger::reportFailure().
        abj404_logPhpFallback('ajax-trace', $message);
    }
}
