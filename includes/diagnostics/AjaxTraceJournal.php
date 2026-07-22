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
    const MAX_SUPPORT_EXCERPT_BYTES = 32768;

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
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            $this->reportFailure('AJAX trace journal lock failed. Pending evidence remains at ' . $this->pendingPath);
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
     */
    public static function readRecentForSupport(): string {
        try {
            $directory = function_exists('abj404_getUploadsDir') ? abj404_getUploadsDir() : '';
            if (function_exists('apply_filters')) {
                $directory = (string)apply_filters('abj404_ajax_trace_directory', $directory, array());
            }
            if ($directory === '') {
                return '';
            }
            $directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
            $paths = array(
                $directory . self::ROTATED_FILE,
                $directory . self::JOURNAL_FILE,
            );
            $pendingPaths = glob($directory . self::PENDING_GLOB);
            if (is_array($pendingPaths)) {
                $paths = array_merge($paths, $pendingPaths);
            }
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
            $files = array_slice($files, -8);
            if ($files === array()) {
                return '';
            }
            $header = "Recent AJAX stage traces (JSONL):\n";
            $contentBudget = self::MAX_SUPPORT_EXCERPT_BYTES - strlen($header) - count($files);
            $perFileLimit = max(1, intdiv($contentBudget, count($files)));
            $parts = array();
            foreach ($files as $file) {
                $tail = self::readFileTail($file['path'], $perFileLimit);
                if ($tail !== '') {
                    $parts[] = $tail;
                }
            }
            return $parts === array() ? '' : $header . implode("\n", $parts);
        } catch (Throwable $e) {
            self::reportStaticFailure('AJAX trace support excerpt failed: ' . $e->getMessage());
            return '';
        }
    }

    /** @param array<string, mixed> $record */
    private function appendJsonLine(string $path, array $record): bool {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $this->reportFailure('AJAX trace JSON encoding failed.');
            return false;
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            $this->reportFailure('AJAX trace file could not be opened: ' . $path);
            return false;
        }
        $ok = false;
        try {
            if (!@flock($handle, LOCK_EX)) {
                $this->reportFailure('AJAX trace file lock failed: ' . $path);
                return false;
            }
            $written = @fwrite($handle, $json . "\n");
            $flushed = @fflush($handle);
            $ok = $written !== false && $flushed;
            if (!$ok) {
                $this->reportFailure('AJAX trace append/flush failed: ' . $path);
            }
            @flock($handle, LOCK_UN);
        } finally {
            @fclose($handle);
        }
        return $ok;
    }

    private function removePending(): void {
        if (@is_file($this->pendingPath) && !@unlink($this->pendingPath)) {
            $this->reportFailure('AJAX pending trace could not be removed: ' . $this->pendingPath);
        }
    }

    /** Last $limit bytes of a file, trimmed to whole lines. */
    private static function readFileTail(string $path, int $limit): string {
        if ($limit <= 0 || !@is_file($path)) {
            return '';
        }
        $size = @filesize($path);
        if (!is_int($size)) {
            self::reportStaticFailure('AJAX trace journal size could not be read: ' . $path);
            return '';
        }
        $offset = max(0, $size - $limit);
        $contents = @file_get_contents($path, false, null, $offset, $limit);
        if (!is_string($contents)) {
            self::reportStaticFailure('AJAX trace journal could not be read: ' . $path);
            return '';
        }
        if ($offset > 0) {
            $newline = strpos($contents, "\n");
            $contents = $newline === false ? '' : substr($contents, $newline + 1);
        }
        return trim($contents);
    }

    private function reportFailure(string $message): void {
        if ($this->failureReported) {
            return;
        }
        $this->failureReported = true;
        self::reportStaticFailure($message);
    }

    private static function reportStaticFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('ajax-trace', $message);
            return;
        }
        error_log('404 Solution AJAX trace: ' . $message);
    }
}
