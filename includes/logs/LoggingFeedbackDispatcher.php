<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php public Logging feedback entry-point tests.

/**
 * Orchestrates debug-log feedback reports from the Logging facade.
 *
 * Owns the cron-context decisions for developer error reports and heartbeat
 * reports: debug-file presence checks, latest-error dedupe state, update
 * eligibility, FeedbackTransport payload construction, and heartbeat dice
 * guarding. File reading/writing and transport delivery remain delegated to
 * their existing collaborators.
 */
class ABJ_404_Solution_LoggingFeedbackDispatcher {

    /** @var ABJ_404_Solution_Logging */
    private $logging;
    /** @var ABJ_404_Solution_ErrorEmailDedupeState|null */
    private $dedupeState = null;

    /**
     * @param ABJ_404_Solution_Logging $logging Stable facade used for public-path behavior.
     */
    public function __construct(ABJ_404_Solution_Logging $logging) {
        $this->logging = $logging;
    }

    /**
     * Send a developer error report when the debug log contains a new error.
     *
     * @return bool
     */
    public function emailErrorLogIfNecessary(): bool {
        $debugFilePath = $this->logging->getDebugFilePath();
        if (!file_exists($debugFilePath)) {
            $this->logging->debugMessage("No log file found so no errors were found.");
            return false;
        }

        $latestErrorLineFound = $this->logging->getLatestErrorLine();
        if ($latestErrorLineFound['num'] == -1) {
            $this->logging->debugMessage("No errors found in the log file.");
            return false;
        }

        $optionsRepo = abj_service('options_repository');
        $options = $optionsRepo->getOptions(true);
        $dedupe = $this->getDedupeState();
        $sentinelFilePath = $this->logging->getDebugFilePathSentFile();
        $sentLine = $dedupe->readSentLine($options, $sentinelFilePath);
        $this->logging->debugMessage("Dedupe pointer: sentLine=" . $sentLine);

        if ($dedupe->isAlreadySent($sentLine, $latestErrorLineFound, $debugFilePath)) {
            $this->logging->debugMessage("The latest error line from the log file was already emailed. " .
                $latestErrorLineFound['num'] . ' <= ' . $sentLine);
            return false;
        }

        $pluginUpdateRepo = abj_service('plugin_update_metadata_repository');
        if (!$pluginUpdateRepo->shouldEmailErrorFileFor($pluginUpdateRepo->getLatestPluginVersion())) {
            return false;
        }

        if (!$dedupe->recordSent($options, $sentinelFilePath, $debugFilePath, $latestErrorLineFound)) {
            $this->logging->errorMessage("There was an issue writing to the file " . $sentinelFilePath);
            return false;
        }

        $payload = ABJ_404_Solution_FeedbackTransport::buildPayload('error', array(
            'error_signature' => (string)($latestErrorLineFound['line'] ?? ''),
            'previously_sent_line' => (int)$sentLine,
            'error_count_in_log' => (int)$latestErrorLineFound['total_error_count'],
        ));
        return ABJ_404_Solution_FeedbackTransport::sendNow($payload, 'error');
    }

    /**
     * Roll a 1-in-N dice and send a heartbeat debug log if it hits.
     *
     * @param int $oneInN Probability denominator.
     * @return bool True if a heartbeat was sent.
     */
    public function sendHeartbeatIfDueRandom(int $oneInN = 200): bool {
        if ($oneInN < 1) {
            $this->logging->debugMessage("Invalid heartbeat denominator: " . $oneInN . ". Skipping heartbeat log.");
            return false;
        }
        if (!file_exists($this->logging->getDebugFilePath())) {
            return false;
        }
        if (mt_rand(1, $oneInN) !== 1) {
            return false;
        }
        $this->logging->debugMessage("Heartbeat dice roll hit (1-in-{$oneInN}). Sending heartbeat log.");
        $errorInfo = $this->logging->getLatestErrorLine();

        $payload = ABJ_404_Solution_FeedbackTransport::buildPayload('heartbeat', array(
            'error_signature' => 'Heartbeat: no errors to report.',
            'previously_sent_line' => 0,
            'error_count_in_log' => (int)$errorInfo['total_error_count'],
        ));
        ABJ_404_Solution_FeedbackTransport::sendNow($payload, 'heartbeat');
        return true;
    }

    /**
     * @return ABJ_404_Solution_ErrorEmailDedupeState
     */
    private function getDedupeState(): ABJ_404_Solution_ErrorEmailDedupeState {
        if ($this->dedupeState === null) {
            $this->dedupeState = new ABJ_404_Solution_ErrorEmailDedupeState(
                abj_service('options_repository'));
        }
        return $this->dedupeState;
    }
}
