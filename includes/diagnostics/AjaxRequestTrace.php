<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable write-ahead journal for one admin AJAX request.
 *
 * Each stage is appended and flushed to a request-local pending file before
 * work starts. Fast successful requests delete that spool; slow, failed, and
 * shutdown requests are promoted into one bounded, rotated JSONL journal.
 * A hard worker kill can skip PHP shutdown, so stale pending files are recovered
 * into the journal by a later request instead of losing the last started stage.
 */
final class ABJ_404_Solution_AjaxRequestTrace {

    const JOURNAL_FILE = 'abj404_ajax_stage_trace.jsonl';
    const ROTATED_FILE = 'abj404_ajax_stage_trace.old.jsonl';
    const LOCK_FILE = 'abj404_ajax_stage_trace.lock';
    const RETAIN_AFTER_SECONDS = 10.0;
    const RECOVER_PENDING_AFTER_SECONDS = 300;
    const MAX_JOURNAL_BYTES = 524288;
    const MAX_PENDING_BYTES = 32768;
    const MAX_SUPPORT_EXCERPT_BYTES = 32768;
    const SCHEMA_VERSION = 1;

    /** @var array<string, scalar> */
    private $context;
    /** @var ABJ_404_Solution_Clock */
    private $clock;
    /** @var string */
    private $directory;
    /** @var string */
    private $pendingPath;
    /** @var float */
    private $requestStartedAt;
    /** @var float|null */
    private $stageStartedAt = null;
    /** @var string */
    private $currentStage = '';
    /** @var array<string, scalar> */
    private $stageMetadata = array();
    /** @var bool */
    private $active = true;
    /** @var bool */
    private $failureReported = false;

    /**
     * Start tracing for an authorized AJAX request. Failure is non-fatal.
     *
     * @param array<string, mixed> $context
     * @return self|null
     */
    public static function start(array $context): ?self {
        try {
            $directory = function_exists('abj404_getUploadsDir') ? abj404_getUploadsDir() : '';
            if (function_exists('apply_filters')) {
                $directory = (string)apply_filters('abj404_ajax_trace_directory', $directory, $context);
            }
            if ($directory === '') {
                self::reportStaticFailure('AJAX trace uploads directory is unavailable.');
                return null;
            }
            if (!ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory)) {
                self::reportStaticFailure('AJAX trace directory could not be created: ' . $directory);
                return null;
            }
            $trace = new self($context, rtrim($directory, '/\\') . DIRECTORY_SEPARATOR, abj_clock());
            $trace->recoverAbandonedPendingFiles();
            register_shutdown_function(array($trace, 'recordShutdown'));
            return $trace;
        } catch (Throwable $e) {
            self::reportStaticFailure('AJAX trace initialization failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Return bounded recent journal lines for the existing support-request
     * payload. Trace records contain only the normalized PII-safe schema.
     */
    public static function readRecentJournalForSupport(): string {
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
            $pendingPaths = glob($directory . 'abj404_ajax_trace_*.pending.jsonl');
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

    /**
     * @param array<string, mixed> $context
     */
    private function __construct(array $context, string $directory, ABJ_404_Solution_Clock $clock) {
        $this->clock = $clock;
        $this->directory = $directory;
        $this->requestStartedAt = $clock->nowFloat();
        $this->context = $this->normalizeContext($context);
        $stamp = str_replace('.', '', sprintf('%.6f', $this->requestStartedAt));
        $this->pendingPath = $directory . 'abj404_ajax_trace_'
            . $this->context['request_id'] . '_' . $this->context['part'] . '_'
            . $this->context['retry_count'] . '_' . getmypid() . '_' . $stamp . '.pending.jsonl';
    }

    /** Begin and flush a stage before its work runs. */
    public function beginStage(string $stage): void {
        if (!$this->active) {
            return;
        }
        if ($this->currentStage !== '') {
            $this->endStage('superseded');
        }
        $this->currentStage = substr($stage, 0, 128);
        $this->stageStartedAt = $this->clock->nowFloat();
        $this->stageMetadata = array();
        $this->appendPending(array('event' => 'stage_start', 'stage' => $this->currentStage));
    }

    /** @param array<string, scalar> $metadata */
    public function addStageMetadata(array $metadata): void {
        if (!$this->active || $this->currentStage === '') {
            return;
        }
        $changed = array();
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if ($key === 'db_timeout_mode') {
                $value = $this->strongestTimeoutMode((string)($this->stageMetadata[$key] ?? ''), (string)$value);
            }
            $value = is_string($value) ? substr($value, 0, 256) : $value;
            if (array_key_exists($key, $this->stageMetadata) && $this->stageMetadata[$key] === $value) {
                continue;
            }
            $this->stageMetadata[$key] = $value;
            $changed[$key] = $value;
        }
        if ($changed !== array()) {
            $startedAt = $this->stageStartedAt ?? $this->clock->nowFloat();
            $this->appendPending(array_merge(array(
                'event' => 'stage_metadata',
                'stage' => $this->currentStage,
                'elapsed_ms' => max(0, (int)round(($this->clock->nowFloat() - $startedAt) * 1000)),
            ), $changed));
        }
    }

    public function endStage(string $status = 'complete'): void {
        if (!$this->active || $this->currentStage === '') {
            return;
        }
        $startedAt = $this->stageStartedAt ?? $this->clock->nowFloat();
        $record = array_merge(array(
            'event' => 'stage_end',
            'stage' => $this->currentStage,
            'status' => substr($status, 0, 32),
            'elapsed_ms' => max(0, (int)round(($this->clock->nowFloat() - $startedAt) * 1000)),
        ), $this->stageMetadata);
        $this->appendPending($record);
        $this->currentStage = '';
        $this->stageStartedAt = null;
        $this->stageMetadata = array();
    }

    /** Complete the request and retain only slow or failed traces. */
    public function finish(string $status): void {
        if (!$this->active) {
            return;
        }
        if ($this->currentStage !== '') {
            $this->endStage($status === 'complete' ? 'complete' : 'error');
        }
        $elapsedMs = max(0, (int)round(($this->clock->nowFloat() - $this->requestStartedAt) * 1000));
        $this->appendPending(array(
            'event' => 'request_end',
            'status' => substr($status, 0, 32),
            'elapsed_ms' => $elapsedMs,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'connection_aborted' => function_exists('connection_aborted') ? connection_aborted() : 0,
        ));
        $this->active = false;
        if ($status === 'complete' && $elapsedMs < (int)(self::RETAIN_AFTER_SECONDS * 1000)) {
            $this->removePending();
            return;
        }
        $this->promotePending();
    }

    /** PHP shutdown entry point; no-op after finish(). */
    public function recordShutdown(): void {
        if (!$this->active) {
            return;
        }
        $lastError = error_get_last();
        $record = array(
            'event' => 'shutdown',
            'elapsed_ms' => max(0, (int)round(($this->clock->nowFloat() - $this->requestStartedAt) * 1000)),
            'current_stage' => $this->currentStage,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'connection_aborted' => function_exists('connection_aborted') ? connection_aborted() : 0,
            'php_error_type' => is_array($lastError) ? (int)$lastError['type'] : 0,
        );
        $this->appendPending($record);
        $this->active = false;
        $this->promotePending();
    }

    /** @param array<string, mixed> $record */
    private function appendPending(array $record): void {
        if (@is_file($this->pendingPath)) {
            $size = @filesize($this->pendingPath);
            if (is_int($size) && $size >= self::MAX_PENDING_BYTES) {
                $this->reportFailure('AJAX pending trace reached its size limit: ' . $this->pendingPath);
                return;
            }
        }
        $this->appendJsonLine($this->pendingPath, array_merge($this->baseRecord(), $record));
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

    private function promotePending(): void {
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

    private function recoverAbandonedPendingFiles(): void {
        $matches = glob($this->directory . 'abj404_ajax_trace_*.pending.jsonl');
        $cutoff = $this->clock->now() - self::RECOVER_PENDING_AFTER_SECONDS;
        foreach (is_array($matches) ? $matches : array() as $path) {
            $modified = @filemtime($path);
            if ($modified === false || $modified > $cutoff) {
                continue;
            }
            $original = $this->pendingPath;
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
            $this->promotePending();
            $this->pendingPath = $original;
        }
    }

    private function removePending(): void {
        if (@is_file($this->pendingPath) && !@unlink($this->pendingPath)) {
            $this->reportFailure('AJAX pending trace could not be removed: ' . $this->pendingPath);
        }
    }

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

    /** @return array<string, scalar> */
    private function baseRecord(): array {
        return array_merge(array(
            'schema_version' => self::SCHEMA_VERSION,
            'ts' => $this->clock->nowFloat(),
        ), $this->context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{request_id: string, plugin_version: string, action: string, subpage: string, part: string, retry_count: int}
     */
    private function normalizeContext(array $context): array {
        $requestIdRaw = $context['request_id'] ?? '';
        $requestId = is_scalar($requestIdRaw) ? (string)$requestIdRaw : '';
        $actionRaw = $context['action'] ?? '';
        $action = is_scalar($actionRaw) ? (string)$actionRaw : '';
        $subpageRaw = $context['subpage'] ?? '';
        $subpage = is_scalar($subpageRaw) ? (string)$subpageRaw : '';
        $partRaw = $context['part'] ?? 'all';
        $part = is_scalar($partRaw) ? (string)$partRaw : 'all';
        $retryCountRaw = $context['retry_count'] ?? 0;
        $retryCount = is_numeric($retryCountRaw) ? (int)$retryCountRaw : 0;
        return array(
            'request_id' => preg_match('/^[A-Za-z0-9]{8,64}$/', $requestId) ? $requestId : 'unknown00',
            'plugin_version' => defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : 'unknown',
            'action' => substr($action, 0, 64),
            'subpage' => substr($subpage, 0, 64),
            'part' => substr($part, 0, 32),
            'retry_count' => max(0, min(2, $retryCount)),
        );
    }

    private function strongestTimeoutMode(string $current, string $incoming): string {
        $rank = array('' => 0, 'none' => 1, 'wrapped' => 2, 'unwrapped' => 3);
        return ($rank[$incoming] ?? 0) >= ($rank[$current] ?? 0) ? $incoming : $current;
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
