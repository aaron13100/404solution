<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php and tests/LogExcerptAdminActionsTest.php through public Logging entry points.

/**
 * Reader/parser for debug-log files.
 *
 * Owns multiline ERROR/WARN parsing for cron error reports and support
 * excerpts. It does not know where the log file lives; callers provide a
 * concrete path from the debug-log store.
 *
 * @phpstan-type DebugLogSnapshot array{status: string, num: int, line: string|null,
 *     total_error_count: int, file_size: int, tail: string, latest_error_offset: int,
 *     error_context_start: int, error_context: string,
 *     error_entries: array<int, array<int, string>>, recent_lines: array<int, string>}
 */
class ABJ_404_Solution_DebugLogReader {

    /** Existing wire budget shared by the recent tail and anchored evidence. */
    const REPORT_EVIDENCE_MAX_BYTES = 262144;

    /** Maximum bytes reserved for an error that sits outside the recent tail. */
    const ERROR_EXCERPT_MAX_BYTES = 65536;

    /** Context retained immediately before the latest reportable error. */
    const ERROR_CONTEXT_PREFIX_BYTES = 8192;

    /** @var callable */
    private $errorLogger;

    /** @phpstan-var array<string, DebugLogSnapshot> request-scoped file snapshots */
    private $snapshots = array();

    /** @param callable $errorLogger Receives message and optional exception. */
    public function __construct(callable $errorLogger) {
        $this->errorLogger = $errorLogger;
    }

    /**
     * @return array{status: string, num: int, line: string|null, total_error_count: int,
     *     file_size: int, tail: string, latest_error_offset: int, error_context_start: int,
     *     error_context: string, error_entries: array<int, array<int, string>>,
     *     recent_lines: array<int, string>} Immutable request-scoped file snapshot.
     */
    public function getSnapshot(string $debugPath): array {
        return $this->snapshot($debugPath);
    }

    /** @return array{num: int, line: string|null, total_error_count: int} */
    public function getLatestErrorLine(string $debugPath): array {
        $evidence = $this->snapshot($debugPath);
        return array(
            'num' => $evidence['num'],
            'line' => $evidence['line'],
            'total_error_count' => $evidence['total_error_count'],
        );
    }

    /**
     * The one physical read used by error reports, support requests, previews,
     * and dedupe checks during this request.
     *
     * @return array{status: string, num: int, line: string|null, total_error_count: int,
     *     file_size: int, tail: string, latest_error_offset: int, error_context_start: int,
     *     error_context: string, error_entries: array<int, array<int, string>>,
     *     recent_lines: array<int, string>}
     */
    private function snapshot(string $debugFilePath): array {
        if (array_key_exists($debugFilePath, $this->snapshots)) {
            return $this->snapshots[$debugFilePath];
        }
        $snapshot = $this->emptySnapshot('missing');
        if ($debugFilePath === '' || !file_exists($debugFilePath)) {
            return $this->snapshots[$debugFilePath] = $snapshot;
        }

        $observedSize = @filesize($debugFilePath);
        if (is_int($observedSize)) {
            $snapshot['file_size'] = $observedSize;
        }

        $handle = @fopen($debugFilePath, 'rb');
        if (!is_resource($handle)) {
            call_user_func($this->errorLogger, 'Error reading log file (open failed).');
            $snapshot['status'] = 'unreadable';
            return $this->snapshots[$debugFilePath] = $snapshot;
        }

        $errorEntries = array();
        $recentLines = array();
        $currentEntry = array();
        $collectingEntry = false;
        $collectingErrorLines = false;
        $linesRead = 0;
        $bytesRead = 0;
        $tailChunks = array();
        $tailHead = 0;
        $tailBytes = 0;
        $previousChunks = array();
        $previousHead = 0;
        $previousByteCount = 0;
        $contextActive = false;
        try {
            while (($line = fgets($handle)) !== false) {
                $line = (string)$line;
                $lineOffset = $bytesRead;
                $lineBytes = strlen($line);
                $bytesRead += $lineBytes;
                $linesRead++;
                $this->appendBoundedChunk(
                    $tailChunks,
                    $tailHead,
                    $tailBytes,
                    $line,
                    self::REPORT_EVIDENCE_MAX_BYTES
                );
                $this->collectSupportExcerptLine(
                    $line,
                    $errorEntries,
                    $recentLines,
                    $currentEntry,
                    $collectingEntry
                );

                $errorMarker = stripos($line, '(ERROR)');
                $isError = $errorMarker !== false
                    && stripos($line, 'SQL query error: DELETE command denied to user') === false;
                if ($isError) {
                    $snapshot['num'] = $linesRead;
                    $snapshot['line'] = $line;
                    $snapshot['total_error_count']++;
                    $markerOffset = (int)$errorMarker;
                    $snapshot['latest_error_offset'] = $lineOffset + $markerOffset;
                    $lineContextStart = max(0, $markerOffset - self::ERROR_CONTEXT_PREFIX_BYTES);
                    $prefix = $lineContextStart === 0
                        ? implode('', $previousChunks) : '';
                    $snapshot['error_context_start'] = max(
                        0,
                        $lineOffset + $lineContextStart - strlen($prefix)
                    );
                    $snapshot['error_context'] = substr(
                        $prefix . substr($line, $lineContextStart),
                        0,
                        self::ERROR_EXCERPT_MAX_BYTES
                    );
                    $contextActive = strlen($snapshot['error_context']) < self::ERROR_EXCERPT_MAX_BYTES;
                    $collectingErrorLines = true;
                } else {
                    if ($collectingErrorLines && $this->isContinuationLine($line)) {
                        $snapshot['line'] .= "<BR/>\n" . $line;
                    } else {
                        $collectingErrorLines = false;
                    }
                    if ($contextActive) {
                        $remaining = self::ERROR_EXCERPT_MAX_BYTES - strlen($snapshot['error_context']);
                        $snapshot['error_context'] .= substr($line, 0, $remaining);
                        $contextActive = strlen($snapshot['error_context']) < self::ERROR_EXCERPT_MAX_BYTES;
                    }
                }
                $this->appendBoundedChunk(
                    $previousChunks,
                    $previousHead,
                    $previousByteCount,
                    $line,
                    self::ERROR_CONTEXT_PREFIX_BYTES
                );
            }

            $this->storeCurrentSupportEntry($errorEntries, $currentEntry);
            $snapshot['status'] = 'ok';
            $snapshot['file_size'] = $bytesRead;
            $snapshot['tail'] = implode('', $tailChunks);
            $snapshot['error_entries'] = $errorEntries;
            $snapshot['recent_lines'] = $recentLines;
        } catch (\Throwable $e) {
            call_user_func(
                $this->errorLogger,
                'Error reading log file snapshot: ' . get_class($e) . ': ' . $e->getMessage(),
                $e instanceof \Exception ? $e : null
            );
            $snapshot = $this->emptySnapshot('unreadable');
        } finally {
            fclose($handle);
        }
        return $this->snapshots[$debugFilePath] = $snapshot;
    }

    /**
     * @return array{status: string, num: int, line: string|null, total_error_count: int,
     *     file_size: int, tail: string, latest_error_offset: int, error_context_start: int,
     *     error_context: string, error_entries: array<int, array<int, string>>,
     *     recent_lines: array<int, string>}
     */
    private function emptySnapshot(string $status): array {
        return array(
            'status' => $status,
            'num' => -1,
            'line' => null,
            'total_error_count' => 0,
            'file_size' => 0,
            'tail' => '',
            'latest_error_offset' => -1,
            'error_context_start' => 0,
            'error_context' => '',
            'error_entries' => array(),
            'recent_lines' => array(),
        );
    }

    /**
     * Append without repeatedly copying the whole retained window. Numeric
     * keys may be sparse after eviction; implode intentionally ignores keys.
     *
     * @param array<int, string> $chunks
     */
    private function appendBoundedChunk(
        array &$chunks,
        int &$head,
        int &$byteCount,
        string $addition,
        int $maxBytes
    ): void {
        $chunks[] = $addition;
        $byteCount += strlen($addition);
        while ($byteCount > $maxBytes && isset($chunks[$head])) {
            $overflow = $byteCount - $maxBytes;
            $headBytes = strlen($chunks[$head]);
            if ($headBytes <= $overflow) {
                unset($chunks[$head]);
                $head++;
                $byteCount -= $headBytes;
                continue;
            }
            $trimmed = substr($chunks[$head], $overflow);
            if ($trimmed === false) {
                throw new \RuntimeException('Unable to trim bounded debug-log evidence chunk.');
            }
            $chunks[$head] = $trimmed;
            $byteCount -= $overflow;
        }
    }

    /**
     * @param array<int, array<int, string>> $errorEntries
     * @param array<int, string> $recentLines
     * @param array<int, string> $currentEntry
     * @param bool $collectingEntry
     * @return void
     */
    private function collectSupportExcerptLine(
        string $line,
        array &$errorEntries,
        array &$recentLines,
        array &$currentEntry,
        bool &$collectingEntry
    ): void {
        $this->rememberRecentLine($recentLines, $line);

        if ($this->isReportableWarningOrError($line)) {
            if ($collectingEntry) {
                $this->storeCurrentSupportEntry($errorEntries, $currentEntry);
            }
            $currentEntry = array($line);
            $collectingEntry = true;
            return;
        }

        if ($collectingEntry && $this->isContinuationLine($line)) {
            $currentEntry[] = $line;
            return;
        }

        if ($collectingEntry) {
            $this->storeCurrentSupportEntry($errorEntries, $currentEntry);
        }
        $collectingEntry = false;
        $currentEntry = array();
    }

    /**
     * @param array<int, string> $recentLines
     * @return void
     */
    private function rememberRecentLine(array &$recentLines, string $line): void {
        $recentLines[] = $line;
        if (count($recentLines) > 20) {
            array_shift($recentLines);
        }
    }

    private function isReportableWarningOrError(string $line): bool {
        $hasError = stripos($line, '(ERROR)') !== false;
        $hasWarn = stripos($line, '(WARN)') !== false;
        $isDeleteError = stripos($line, 'SQL query error: DELETE command denied to user') !== false;
        return ($hasError || $hasWarn) && !$isDeleteError;
    }

    private function isContinuationLine(string $line): bool {
        $f = abj_service('functions');
        return !$f->regexMatch("^\d{4}[-]\d{2}[-]\d{2} .*\(\w+\):\s.*$", $line);
    }

    /**
     * @param array<int, array<int, string>> $errorEntries
     * @param array<int, string> $currentEntry
     * @return void
     */
    private function storeCurrentSupportEntry(array &$errorEntries, array &$currentEntry): void {
        if (empty($currentEntry)) {
            return;
        }
        $errorEntries[] = $currentEntry;
        if (count($errorEntries) > 15) {
            array_shift($errorEntries);
        }
        $currentEntry = array();
    }

}
