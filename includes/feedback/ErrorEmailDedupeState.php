<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistence layer for the "last sent error line" dedupe pointer used by
 * ABJ_404_Solution_Logging::emailErrorLogIfNecessary().
 *
 * Owns the round-trip across three storage locations:
 *
 *   1. The on-disk sentinel file (abj404_debug_sent_line.txt) -- authoritative
 *      across requests and survives cron restarts.
 *   2. The plugin options row (LAST_SENT_LINE key) -- fallback when the
 *      sentinel file is absent or unreadable, and the only durable copy on
 *      hosts with ephemeral filesystems.
 *   3. Request-local statics -- prevent a single PHP request that triggers
 *      multiple emailErrorLogIfNecessary() calls (e.g. via shutdown handlers)
 *      from emitting duplicate sends before the on-disk pointer has caught up.
 *
 * Pure persistence: no policy, no presentation, no email dispatch. Returns
 * the current pointer, decides whether a candidate error-line is already
 * recorded, and writes the pointer forward.
 */
class ABJ_404_Solution_ErrorEmailDedupeState {

    /**
     * Options-array key under which the most-recently-sent error line number
     * is persisted. Shared with ABJ_404_Solution_Logging::LAST_SENT_LINE so
     * older deployments that still write to that key continue to be read here.
     */
    const LAST_SENT_LINE_KEY = 'last_sent_line';

    /** @var int Latest error-log line emailed during this PHP request. */
    private static $lastSentErrorLineThisRequest = 0;
    /** @var string Latest error signature emailed during this PHP request. */
    private static $lastSentErrorSignatureThisRequest = '';
    /** @var string Debug file path associated with the request-local dedupe state. */
    private static $lastSentDebugFilePathThisRequest = '';

    /** @var object Options repository providing getOptions(bool)/updateOptions(array). */
    private $optionsRepo;

    /** @param object $optionsRepo */
    public function __construct($optionsRepo) {
        $this->optionsRepo = $optionsRepo;
    }

    /**
     * Read the latest known "last sent error line" pointer from disk, falling
     * back to the options copy. Request-local statics are merged in
     * isAlreadySent() so a fresh read from this method always reflects only
     * the durable state.
     *
     * @param array<string, mixed> $options Already-loaded options array (the
     *        caller has a copy in hand for other purposes; avoiding a second
     *        getOptions() round-trip).
     * @param string $sentinelFilePath
     * @return int Last-sent line number, or -1 when no record exists.
     */
    public function readSentLine(array $options, string $sentinelFilePath): int {
        $sentLine = -1;
        if (file_exists($sentinelFilePath)) {
            $sentLine = absint(
                ABJ_404_Solution_FileSystemService::readFileContents($sentinelFilePath, false));
        }
        if ($sentLine < 1 && array_key_exists(self::LAST_SENT_LINE_KEY, $options)) {
            $sentLine = is_scalar($options[self::LAST_SENT_LINE_KEY])
                ? (int)$options[self::LAST_SENT_LINE_KEY] : -1;
        }
        return $sentLine;
    }

    /**
     * Decide whether the latest-found error line has already been emailed.
     *
     * Combines the durable pointer (sentLine) with the request-local high-
     * water marks. Two requests for the same debug file path within a single
     * PHP process are deduped on either:
     *
     *   - the line number having already advanced past the latest, OR
     *   - the error signature exactly matching the last one we sent (which
     *     catches cases where the log file has been rotated and line numbers
     *     reset but the same recurring error is still on top).
     *
     * @param int $sentLine Durable pointer (from readSentLine()).
     * @param array{num: int, line: string|null, total_error_count?: int} $latestErrorLineFound
     * @param string $debugFilePath
     * @return bool true if the latest-found line has already been sent.
     */
    public function isAlreadySent(int $sentLine, array $latestErrorLineFound, string $debugFilePath): bool {
        $latestNum = (int)($latestErrorLineFound['num'] ?? -1);
        $latestSignature = (string)($latestErrorLineFound['line'] ?? '');

        $effectiveSentLine = $sentLine;
        if (self::$lastSentDebugFilePathThisRequest === $debugFilePath) {
            $effectiveSentLine = max($effectiveSentLine, self::$lastSentErrorLineThisRequest);
        }
        if ($latestNum <= $effectiveSentLine) {
            return true;
        }
        if (self::$lastSentDebugFilePathThisRequest === $debugFilePath
            && $latestSignature !== ''
            && $latestSignature === self::$lastSentErrorSignatureThisRequest) {
            return true;
        }
        return false;
    }

    /**
     * Record the just-sent error line forward across all three layers:
     * options row, sentinel file, request-local statics.
     *
     * @param array<string, mixed> $options Options snapshot to be updated and
     *        persisted via $this->optionsRepo->updateOptions().
     * @param string $sentinelFilePath
     * @param string $debugFilePath
     * @param array{num: int, line: string|null, total_error_count?: int} $latestErrorLineFound
     * @return bool false if the sentinel file write failed verification
     *              (caller should bail before dispatching mail to avoid a
     *              dedupe-pointer regression on the next cron tick); true
     *              otherwise.
     */
    public function recordSent(array $options, string $sentinelFilePath, string $debugFilePath, array $latestErrorLineFound): bool {
        $latestNum = (int)($latestErrorLineFound['num'] ?? -1);
        $latestSignature = (string)($latestErrorLineFound['line'] ?? '');

        $options[self::LAST_SENT_LINE_KEY] = $latestNum;
        self::$lastSentErrorLineThisRequest = $latestNum;
        self::$lastSentErrorSignatureThisRequest = $latestSignature;
        self::$lastSentDebugFilePathThisRequest = $debugFilePath;

        if (is_object($this->optionsRepo) && method_exists($this->optionsRepo, 'updateOptions')) {
            $this->optionsRepo->updateOptions($options);
        }

        @file_put_contents($sentinelFilePath, (string)$latestNum);
        $fileContents = @file_get_contents($sentinelFilePath);
        return ($fileContents === (string)$latestNum);
    }

    /**
     * Test seam: reset the request-local statics. Called from test setUp() to
     * keep ParaTest workers' Logging::emailErrorLogIfNecessary() runs from
     * leaking dedupe state across tests in the same worker. Not used in
     * production.
     */
    public static function resetRequestLocalsForTesting(): void {
        self::$lastSentErrorLineThisRequest = 0;
        self::$lastSentErrorSignatureThisRequest = '';
        self::$lastSentDebugFilePathThisRequest = '';
    }
}
