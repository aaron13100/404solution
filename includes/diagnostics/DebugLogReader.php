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
 */
class ABJ_404_Solution_DebugLogReader {

    /** @var callable */
    private $errorLogger;

    /** @param callable $errorLogger Receives message and optional exception. */
    public function __construct(callable $errorLogger) {
        $this->errorLogger = $errorLogger;
    }

    /**
     * Return the latest reportable ERROR line and total reportable error count.
     *
     * @param string $debugPath
     * @return array{num: int, line: string|null, total_error_count: int}
     */
    public function getLatestErrorLine(string $debugPath): array {
        $f = abj_service('functions');
        $latestErrorLineFound = array(
            'num' => -1,
            'line' => null,
            'total_error_count' => 0,
        );
        $linesRead = 0;
        $handle = null;
        $collectingErrorLines = false;
        try {
            if ($debugPath === '' || !file_exists($debugPath)) {
                return $latestErrorLineFound;
            }
            if ($handle = fopen($debugPath, "r")) {
                while (($line = fgets($handle)) !== false) {
                    $linesRead++;
                    $hasError = stripos($line, '(ERROR)');
                    $isDeleteError = stripos($line, 'SQL query error: DELETE command denied to user');
                    if ($hasError !== false && $isDeleteError === false) {
                        $latestErrorLineFound['num'] = $linesRead;
                        $latestErrorLineFound['line'] = $line;
                        $latestErrorLineFound['total_error_count'] += 1;
                        $collectingErrorLines = true;
                    } else if ($collectingErrorLines &&
                        !$f->regexMatch("^\d{4}[-]\d{2}[-]\d{2} .*\(\w+\):\s.*$", $line)) {
                        $latestErrorLineFound['line'] .= "<BR/>\n" . $line;
                    } else {
                        $collectingErrorLines = false;
                    }
                }
            } else {
                call_user_func($this->errorLogger, "Error reading log file (1).");
            }
        } catch (Exception $e) {
            call_user_func($this->errorLogger, "Error reading log file. (2)", $e);
        } finally {
            // finally (not "after the try/catch") so the handle still
            // closes if a Throwable that isn't an Exception (e.g. a
            // TypeError/Error from $f->regexMatch()) escapes the loop --
            // the previous unconditional-looking cleanup below the
            // try/catch was skipped in that case. Same resource-lifecycle
            // shape as includes/import/ImportService.php::doImportFile().
            if ($handle != null) {
                fclose($handle);
            }
        }

        return $latestErrorLineFound;
    }

    /**
     * Build a sanitized support excerpt from recent ERROR/WARN entries and
     * recent context lines.
     *
     * @param string $debugFilePath
     * @return string
     */
    public function getSanitizedLogExcerptForSupport(string $debugFilePath): string {
        try {
            if (!file_exists($debugFilePath)) {
                return "No log file available";
            }
            $excerptParts = $this->readSupportExcerptParts($debugFilePath);
            if (!$excerptParts['readable']) {
                return "Log file not readable";
            }
            return $this->formatSupportExcerpt($excerptParts['error_entries'], $excerptParts['recent_lines']);
        } catch (Exception $e) {
            abj404_logPhpFallback('logger-internal', 'support log excerpt read failed: ' . $e->getMessage());
            return "Error reading log file: " . $e->getMessage();
        }
    }

    /**
     * @param string $debugFilePath
     * @return array{readable: bool, error_entries: array<int, array<int, string>>, recent_lines: array<int, string>}
     */
    private function readSupportExcerptParts(string $debugFilePath): array {
        $errorEntries = array();
        $recentLines = array();
        $currentEntry = array();
        $collectingEntry = false;
        $handle = fopen($debugFilePath, "r");

        if (!$handle) {
            return array('readable' => false, 'error_entries' => array(), 'recent_lines' => array());
        }

        // try/finally (not a bare fclose() after the loop) so the handle
        // still closes if collectSupportExcerptLine()/storeCurrentSupportEntry()
        // throw. The caller (getSanitizedLogExcerptForSupport()) only catches
        // Exception, not Throwable, so without this the handle would leak on
        // an Error subtype. Same resource-lifecycle shape as
        // includes/import/ImportService.php::doImportFile().
        try {
            while (($line = fgets($handle)) !== false) {
                $this->collectSupportExcerptLine(
                    (string)$line,
                    $errorEntries,
                    $recentLines,
                    $currentEntry,
                    $collectingEntry
                );
            }

            $this->storeCurrentSupportEntry($errorEntries, $currentEntry);

            return array(
                'readable' => true,
                'error_entries' => $errorEntries,
                'recent_lines' => $recentLines,
            );
        } finally {
            fclose($handle);
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

    /**
     * @param array<int, array<int, string>> $errorEntries
     * @param array<int, string> $recentLines
     * @return string
     */
    private function formatSupportExcerpt(array $errorEntries, array $recentLines): string {
        if (empty($errorEntries)) {
            if (empty($recentLines)) {
                return "Log file is empty";
            }
            $output = "No ERROR/WARN entries found. Last " . count($recentLines) . " log lines:\n\n";
            $output .= implode("", $recentLines);
            return trim($output);
        }

        $output = "Last " . count($errorEntries) . " ERROR/WARN entries:\n\n";
        foreach ($errorEntries as $entry) {
            $output .= implode("\n", $entry) . "\n\n";
        }

        if (!empty($recentLines)) {
            $output .= "Recent context (last " . count($recentLines) . " lines):\n\n";
            $output .= implode("", $recentLines);
        }

        return trim($output);
    }
}
