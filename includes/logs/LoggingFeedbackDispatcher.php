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
 * eligibility, FeedbackTransport payload construction, and weekly heartbeat
 * guarding. File reading/writing and transport delivery remain delegated to
 * their existing collaborators.
 */
class ABJ_404_Solution_LoggingFeedbackDispatcher {

    /** @var ABJ_404_Solution_Logging */
    private $logging;
    /** @var ABJ_404_Solution_ErrorEmailDedupeState|null */
    private $dedupeState = null;
    /** @var ABJ_404_Solution_LoggingStateStore|null */
    private $loggingStateStore = null;

    private const HEARTBEAT_MIN_INSTALL_AGE_SECONDS = 5 * 86400;
    private const HEARTBEAT_INTERVAL_SECONDS = 7 * 86400;

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

        $dedupe = $this->getDedupeState();
        $sentinelFilePath = $this->logging->getDebugFilePathSentFile();
        $sentLine = $dedupe->readSentLine($sentinelFilePath);
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

        if (!$dedupe->recordSent($sentinelFilePath, $debugFilePath, $latestErrorLineFound)) {
            $this->logging->errorMessage("There was an issue writing to the file " . $sentinelFilePath);
            return false;
        }

        try {
            $payload = ABJ_404_Solution_FeedbackTransport::buildPayload('error', array(
                'error_signature' => (string)($latestErrorLineFound['line'] ?? ''),
                'previously_sent_line' => (int)$sentLine,
                'error_count_in_log' => (int)$latestErrorLineFound['total_error_count'],
            ));
            return ABJ_404_Solution_FeedbackTransport::sendNow($payload, 'error');
        } catch (\Throwable $e) {
            // buildPayload() throws if the payload fails its schema
            // contract; a build/transport failure must never escape this
            // cron-context call and crash the rest of the maintenance run.
            // errorMessage()'s second param only accepts Exception (not the
            // wider Throwable an \Error also matches), so the message/class
            // are folded into the log line itself rather than dropped.
            $this->logDispatchFailure('emailErrorLogIfNecessary: report build/send failed', $e);
            return false;
        }
    }

    /**
     * Send a heartbeat at most once per week after the plugin has been active
     * for five days. The timestamp is persisted only after a successful
     * transport send so failed reports retry on the next maintenance run.
     *
     * @return bool True if a heartbeat was sent successfully.
     */
    public function sendHeartbeatIfDueWeekly(): bool {
        $now = abj_clock()->now();
        $installedAt = $this->installedAt();
        if ($installedAt <= 0) {
            $this->logging->debugMessage("Weekly heartbeat skipped: installed time unavailable.");
            return false;
        }

        $installAgeSeconds = $now - $installedAt;
        if ($installAgeSeconds < self::HEARTBEAT_MIN_INSTALL_AGE_SECONDS) {
            $this->logging->debugMessage("Weekly heartbeat skipped: plugin active for {$installAgeSeconds}s (< " .
                self::HEARTBEAT_MIN_INSTALL_AGE_SECONDS . "s).");
            return false;
        }

        $state = $this->getLoggingStateStore();
        $lastSentAt = $state->getLastHeartbeatSentAt();
        if ($lastSentAt > 0 && ($now - $lastSentAt) < self::HEARTBEAT_INTERVAL_SECONDS) {
            $this->logging->debugMessage("Weekly heartbeat skipped: last sent at {$lastSentAt}.");
            return false;
        }

        if (!file_exists($this->logging->getDebugFilePath())) {
            return false;
        }

        $this->logging->debugMessage("Weekly heartbeat due. Sending heartbeat log.");
        $errorInfo = $this->logging->getLatestErrorLine();

        try {
            $payload = ABJ_404_Solution_FeedbackTransport::buildPayload('heartbeat', array(
                'error_signature' => 'Heartbeat: no errors to report.',
                'previously_sent_line' => 0,
                'error_count_in_log' => (int)$errorInfo['total_error_count'],
            ));
            $sent = ABJ_404_Solution_FeedbackTransport::sendNow($payload, 'heartbeat');
            if ($sent) {
                $state->setLastHeartbeatSentAt($now);
            }
            return $sent;
        } catch (\Throwable $e) {
            $this->logDispatchFailure('sendHeartbeatIfDueWeekly: report build/send failed', $e);
            return false;
        }
    }

    /**
     * Record a report build/send failure at the severity its cause deserves.
     *
     * These run in the async maintenance (cron) context, which is also where a
     * plugin self-update lands on a live request: WordPress swaps the plugin
     * directory underneath the running process, and a class the incoming
     * release added cannot be resolved (production report 266). That is a
     * hosting event which fixes itself on the next run, so it degrades to a
     * warning; a real schema-contract or transport failure still reports as an
     * error.
     *
     * @param string $context Already-composed prefix naming the failed step.
     * @param \Throwable $e
     * @return void
     */
    private function logDispatchFailure($context, \Throwable $e) {
        $message = $context . ': ' . get_class($e) . ': ' . $e->getMessage();
        if (function_exists('abj404_logCallbackFailure')) {
            abj404_logCallbackFailure($this->logging, $message, $e);
            return;
        }
        $this->logging->errorMessage($message, $e instanceof \Exception ? $e : null);
    }

    /**
     * Drain any pending crash beacon and report it as a post-mortem `error`
     * report. Runs in the same async maintenance context as the error-log and
     * heartbeat dispatch (and under the same send_error_logs opt-in), so a fatal
     * or OOM that could not phone home at the time gets reported on the next
     * healthy maintenance run (after a plugin update, for an every-request OOM).
     *
     * Delegates to FeedbackTransport (the Core telemetry facade this dispatcher
     * already routes error/heartbeat sends through) so the crash-beacon reporter
     * lives beside the other transports in the Core layer.
     *
     * @return bool True if a crash beacon was reported.
     */
    public function drainCrashBeaconIfNecessary(): bool {
        return ABJ_404_Solution_FeedbackTransport::drainCrashBeacon();
    }

    /**
     * @return ABJ_404_Solution_ErrorEmailDedupeState
     */
    private function getDedupeState(): ABJ_404_Solution_ErrorEmailDedupeState {
        if ($this->dedupeState === null) {
            $this->dedupeState = new ABJ_404_Solution_ErrorEmailDedupeState(
                $this->getLoggingStateStore());
        }
        return $this->dedupeState;
    }

    private function getLoggingStateStore(): ABJ_404_Solution_LoggingStateStore {
        if ($this->loggingStateStore === null) {
            $this->loggingStateStore = ABJ_404_Solution_LoggingStateStore::resolve();
        }
        return $this->loggingStateStore;
    }

    private function installedAt(): int {
        if (!function_exists('get_option')) {
            return 0;
        }
        $installedAt = get_option('abj404_installed_time', null);
        return is_scalar($installedAt) && is_numeric($installedAt) ? (int)$installedAt : 0;
    }
}
