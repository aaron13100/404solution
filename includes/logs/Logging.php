<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Static functions that can be used from anywhere.  */

class ABJ_404_Solution_Logging {

    /** If an error happens then we will also output these.
     * @var array<int, string>
     */
    private static $storedDebugMessages = array();

    /** Used to store the last line sent from the debug file. */
    const LAST_SENT_LINE = 'last_sent_line';
    
    /** Used to store the the debug filename. */
    const DEBUG_FILE_KEY = 'debug_file_key';
    
    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it. Mirrors the setInstance()
     * contract on DataAccess / PluginLogic (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /**
     * Return the current singleton instance without consulting the container
     * or building a new one. Used by `abj_service()` to honor a test-installed
     * singleton override (or any other code that has populated `$instance`
     * directly) without forcing the container to cache a stale binding.
     * Mirrors the `peekInstance()` pattern on PluginLogic.
     *
     * @return self|null
     */
    public static function peekInstance() {
        return self::$instance;
    }

    /**
     * Factory for the DI container.
     *
     * This avoids recursion when the container's 'logging' service is defined in terms of getInstance().
     *
     * @return ABJ_404_Solution_Logging
     */
    public static function createForContainer() {
        // Honor a pre-existing singleton override only when it satisfies the
        // canonical Logging contract. Sibling factories that bind
        // `$c->get('logging')` are strictly typed against
        // ABJ_404_Solution_Logging; returning an anonymous double from here
        // would violate that contract and fatal at the call site. Anonymous
        // doubles still take effect via the abj_service() override gate for
        // callers that route through abj_service('logging') directly.
        if (self::$instance instanceof self) {
            // Drain any pending-errors buffer through the existing logger
            // before returning it, so the textdomain-too-early closure
            // contract holds even when a caller pre-populated the singleton.
            $existing = self::$instance;
            self::flushPendingErrorsTo($existing);
            return $existing;
        }

        // Create a fresh instance without consulting the container.
        $logger = new ABJ_404_Solution_Logging();

        // Set the singleton before flushing so a recursive resolution
        // through this same factory does not build a second instance and
        // re-enter the flush loop.
        self::$instance = $logger;

        self::flushPendingErrorsTo($logger);

        return $logger;
    }

    /**
     * Drain $GLOBALS['abj404_pending_errors'] through $logger->errorMessage()
     * and clear the buffer. Safe to call when the buffer is empty.
     *
     * @param self $logger
     * @return void
     */
    private static function flushPendingErrorsTo(self $logger): void {
        if (!isset($GLOBALS['abj404_pending_errors']) || !is_array($GLOBALS['abj404_pending_errors'])) {
            return;
        }
        $pending = $GLOBALS['abj404_pending_errors'];
        unset($GLOBALS['abj404_pending_errors']);
        foreach ($pending as $message) {
            if (is_string($message)) {
                $logger->errorMessage($message);
            }
        }
    }

    /** @return self */
    public static function getInstance() {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // If the DI container is initialized, prefer it.
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $service = ABJ_404_Solution_ServiceContainer::safeGet('logging');
            if ($service instanceof ABJ_404_Solution_Logging) {
                self::$instance = $service;
                return self::$instance;
            }
        }

        $fresh = new ABJ_404_Solution_Logging();
        self::$instance = $fresh;

        // log any errors that were stored before the logger existed.
        self::flushPendingErrorsTo($fresh);

        return $fresh;
    }
    
    private function __construct() {
    }

    /** @var ABJ_404_Solution_DebugLogFileStore|null */
    private $debugLogFileStore = null;
    /** @var ABJ_404_Solution_DebugLogReader|null */
    private $debugLogReader = null;
    /** @var ABJ_404_Solution_DebugLogArchiveBuilder|null */
    private $debugLogArchiveBuilder = null;
    /** @var ABJ_404_Solution_DeveloperLogMailer|null */
    private $developerLogMailer = null;
    /** @var ABJ_404_Solution_LoggingMessageWriter|null */
    private $messageWriter = null;
    /** @var ABJ_404_Solution_LoggingCapabilityDiagnostics|null */
    private $capabilityDiagnostics = null;
    /** @var ABJ_404_Solution_LoggingFeedbackDispatcher|null */
    private $feedbackDispatcher = null;
    /** @var ABJ_404_Solution_LogTimestampFormatter|null */
    private $timestampFormatter = null;
    /** @var ABJ_404_Solution_LogDebugModeResolver|null */
    private $debugModeResolver = null;

    /** @return ABJ_404_Solution_DebugLogFileStore */
    private function getDebugLogFileStore(): ABJ_404_Solution_DebugLogFileStore {
        if ($this->debugLogFileStore === null) {
            $this->debugLogFileStore = new ABJ_404_Solution_DebugLogFileStore(
                array($this, 'sanitizeLogLine'),
                ABJ_404_Solution_LoggingStateStore::resolve());
        }
        return $this->debugLogFileStore;
    }

    /** @return ABJ_404_Solution_DebugLogReader */
    private function getDebugLogReader(): ABJ_404_Solution_DebugLogReader {
        if ($this->debugLogReader === null) {
            $this->debugLogReader = new ABJ_404_Solution_DebugLogReader(
                array($this, 'errorMessage'));
        }
        return $this->debugLogReader;
    }

    /** @return ABJ_404_Solution_DebugLogArchiveBuilder */
    private function getDebugLogArchiveBuilder(): ABJ_404_Solution_DebugLogArchiveBuilder {
        if ($this->debugLogArchiveBuilder === null) {
            $this->debugLogArchiveBuilder = new ABJ_404_Solution_DebugLogArchiveBuilder();
        }
        return $this->debugLogArchiveBuilder;
    }

    /** @return ABJ_404_Solution_DeveloperLogMailer */
    private function getDeveloperLogMailer(): ABJ_404_Solution_DeveloperLogMailer {
        if ($this->developerLogMailer === null) {
            $this->developerLogMailer = new ABJ_404_Solution_DeveloperLogMailer(
                $this->getBodyFormatter(),
                $this->getDebugLogArchiveBuilder(),
                array($this, 'debugMessage'),
                array($this, 'errorMessage')
            );
        }
        return $this->developerLogMailer;
    }

    /** @return ABJ_404_Solution_LoggingMessageWriter */
    private function getMessageWriter(): ABJ_404_Solution_LoggingMessageWriter {
        if ($this->messageWriter === null) {
            $this->messageWriter = new ABJ_404_Solution_LoggingMessageWriter(
                array($this, 'getTimestamp'),
                array($this, 'isDebug'),
                array($this, 'writeLineToDebugFile'),
                self::$storedDebugMessages
            );
        }
        return $this->messageWriter;
    }

    /** @return ABJ_404_Solution_LoggingCapabilityDiagnostics */
    private function getCapabilityDiagnostics(): ABJ_404_Solution_LoggingCapabilityDiagnostics {
        if ($this->capabilityDiagnostics === null) {
            $this->capabilityDiagnostics = new ABJ_404_Solution_LoggingCapabilityDiagnostics();
        }
        return $this->capabilityDiagnostics;
    }

    /** @return ABJ_404_Solution_LoggingFeedbackDispatcher */
    private function getFeedbackDispatcher(): ABJ_404_Solution_LoggingFeedbackDispatcher {
        if ($this->feedbackDispatcher === null) {
            $this->feedbackDispatcher = new ABJ_404_Solution_LoggingFeedbackDispatcher($this);
        }
        return $this->feedbackDispatcher;
    }

    /** @return ABJ_404_Solution_LogTimestampFormatter */
    private function getTimestampFormatter(): ABJ_404_Solution_LogTimestampFormatter {
        if ($this->timestampFormatter === null) {
            $this->timestampFormatter = new ABJ_404_Solution_LogTimestampFormatter();
        }
        return $this->timestampFormatter;
    }

    /** @return ABJ_404_Solution_LogDebugModeResolver */
    private function getDebugModeResolver(): ABJ_404_Solution_LogDebugModeResolver {
        if ($this->debugModeResolver === null) {
            $this->debugModeResolver = new ABJ_404_Solution_LogDebugModeResolver();
        }
        return $this->debugModeResolver;
    }

    /** @return boolean true if debug mode is on. false otherwise. */
    function isDebug() {
        return $this->getDebugModeResolver()->isDebug();
    }

    /** for the current timezone.
     * @return string */
    function getTimestamp() {
        return $this->getTimestampFormatter()->format();
    }

    /** Send a message to the log file if debug mode is on.
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message
     * @param \Throwable|null $e If present then a stack trace is included.
     * @return void
     */
    function debugMessage(string $message, $e = null): void {
        $this->getMessageWriter()->debugMessage($message, $e);
    }

    /** Send a message to the log.
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message
     * @return void
     */
    function infoMessage(string $message): void {
        $this->getMessageWriter()->infoMessage($message);
    }
    
    /** Send a message to the log.
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message
     * @return void
     */
    function warn(string $message): void {
        $this->getMessageWriter()->warn($message);
    }

    /** Always send a message to the error_log.
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message
     * @param \Exception|null $e
     * @return void
     */
    function errorMessage(string $message, $e = null): void {
        $this->getMessageWriter()->errorMessage($message, $e);
    }
    
    /** Log the user capabilities.
     * @param string $msg
     * @return void
     */
    function logUserCapabilities(string $msg): void {
        $this->debugMessage($this->getCapabilityDiagnostics()->format($msg));
    }

    /** Write the line to the debug file.
     *
     * Sanitizes PII at write-time for GDPR compliance (defense in depth).
     * Fix for disk space error (reported by 1 user - 2% of errors)
     * Handles file write failures gracefully to prevent error loops when disk is full.
     * Uses error suppression and returns status instead of throwing exceptions.
     *
     * @param string $line
     * @return bool True on success, false on failure
     */
    function writeLineToDebugFile($line) {
        return $this->getDebugLogFileStore()->writeLine((string)$line, $this->getDebugFilePath());
    }
    
    /** Email the log file to the plugin developer.
     *
     * Cron-context entry: builds a FeedbackTransport payload from the freshly-
     * scanned latest-error line plus dedup state, and dispatches via
     * FeedbackTransport::sendNow() (sync HTTP POST + email fallback). Returns
     * true iff any transport (HTTP or email) succeeded; the dedup pointer is
     * advanced before sending so a transport failure does not cause repeated
     * sends of the same error line on the next cron tick.
     *
     * @return bool
     */
    function emailErrorLogIfNecessary(): bool {
        return $this->getFeedbackDispatcher()->emailErrorLogIfNecessary();
    }

    /**
     * Drain a pending crash beacon (a fatal/OOM that could not phone home at the
     * time) and report it as a post-mortem `error` report. Called during daily
     * maintenance for opted-in sites.
     *
     * @return bool True if a crash beacon was reported.
     */
    function drainCrashBeaconIfNecessary(): bool {
        return $this->getFeedbackDispatcher()->drainCrashBeaconIfNecessary();
    }

    /**
     * Lazily-constructed body-formatter collaborator. Pure presentation, no
     * dependencies, kept as a field only so it isn't reallocated every send.
     *
     * @return ABJ_404_Solution_ErrorEmailBodyFormatter
     */
    private function getBodyFormatter(): ABJ_404_Solution_ErrorEmailBodyFormatter {
        if ($this->bodyFormatter === null) {
            $this->bodyFormatter = new ABJ_404_Solution_ErrorEmailBodyFormatter();
        }
        return $this->bodyFormatter;
    }

    /** @var ABJ_404_Solution_ErrorEmailBodyFormatter|null */
    private $bodyFormatter = null;

    /**
     * Send the weekly status heartbeat when its deterministic cadence is due.
     * Called during daily maintenance for opted-in sites when no error email
     * was sent.
     *
     * @return bool True if a heartbeat was sent successfully.
     */
    function sendHeartbeatIfDueWeekly(): bool {
        return $this->getFeedbackDispatcher()->sendHeartbeatIfDueWeekly();
    }

    /**
     * Email-fallback for FeedbackTransport when the HTTP POST of an error or
     * heartbeat report fails. Builds an HTML email body purely from the
     * FeedbackTransport payload (single source of truth shared with the HTTP
     * path) and attaches a zip of the current debug log file(s).
     *
     * Public because FeedbackTransport::sendNow() invokes it via the service
     * container for type='error' and type='heartbeat'.
     *
     * @param array<string, mixed> $payload FeedbackTransport-built payload.
     * @return bool True if wp_mail() reported success, false otherwise.
     */
    function emailLogFileToDeveloper(array $payload): bool {
        return $this->getDeveloperLogMailer()->send(
            $payload,
            $this->getDebugFilePath(),
            $this->getDebugFilePathOld(),
            $this->getZipFilePath(),
            $this->getDebugFilename()
        );
    }
    
    /**
     * @return array{num: int, line: string|null, total_error_count: int}
     */
    function getLatestErrorLine(): array {
        return $this->getDebugLogReader()->getLatestErrorLine($this->getDebugFilePath());
    }
    
    /**
     * Get sanitized log excerpt for support emails.
     * Collects last 15 ERROR/WARN entries (already sanitized at write-time)
     * plus the last 20 lines for recent context (admin actions, AJAX calls).
     * If no errors/warnings found, includes only the last 20 lines.
     *
     * @return string Sanitized log excerpt or message if no errors found
     */
    function getSanitizedLogExcerptForSupport() {
        return $this->getDebugLogReader()->getSanitizedLogExcerptForSupport($this->getDebugFilePath());
    }

    /**
     * Sanitize a single log line for privacy (GDPR compliance).
     * Delegates to PiiRedactor for all pattern matching and masking.
     *
     * @param string $line Log line to sanitize
     * @return string Sanitized line with PII masked adaptively
     */
    public function sanitizeLogLine($line) {
        /** @var ABJ_404_Solution_PiiRedactor|null $redactor */
        $redactor = function_exists('abj_service_optional') ? abj_service_optional('pii_redactor') : null;
        if (!$redactor instanceof ABJ_404_Solution_PiiRedactor) {
            return $line;
        }
        return $redactor->redact($line);
    }

    /** Return the path to the debug file.
     * @return string
     */
    function getDebugFilePath() {
        return $this->getDebugLogFileStore()->getDebugFilePath();
    }
    
    /** @return string */
    function getDebugFilename(): string {
        return $this->getDebugLogFileStore()->getDebugFilename();
    }
    
    /** @return string */
    function getDebugFilePathOld(): string {
        return $this->getDebugFilePath() . "_old.txt";
    }
    
    /** Return the path to the file that stores the latest error line in the log file.
     * @return string
     */
    function getDebugFilePathSentFile() {
        return $this->getDebugLogFileStore()->getDebugFilePathSentFile();
    }
    
    /** Return the path to the zip file for sending the debug file. 
     * @return string
     */
    function getZipFilePath() {
        return $this->getDebugLogFileStore()->getZipFilePath();
    }
    
    /** This is for legacy support. On new installations it creates a directory and returns
     * a file path. On old installations it moved the old file to the new location. 
     * If the directory can't be created then it falls back to the old location.
     * @param string $directory
     * @param string $filename
     * @return string
     */
    function getFilePathAndMoveOldFile($directory, $filename) {
        return $this->getDebugLogFileStore()->getFilePathAndMoveOldFile($directory, $filename);
    }
    
    /** @return void */
    function limitDebugFileSize(): void {
        $this->getDebugLogFileStore()->limitDebugFileSize(
            $this->getDebugFilePathSentFile(),
            $this->getDebugFilePathOld(),
            $this->getDebugFilePath()
        );
    }
    
    /** @return void */
    function removeLastSentErrorLineFromDatabase(): void {
        $this->getDebugLogFileStore()->removeLastSentErrorLineFromDatabase();
    }
    
    /** Deletes all files named abj404_debug_*.txt
     * @return boolean true if the file was deleted.
     */
    function deleteDebugFile() {
        return $this->getDebugLogFileStore()->deleteDebugFile();
    }
    
    /** 
     * @return int file size in bytes
     */
    function getDebugFileSize() {
        return $this->getDebugLogFileStore()->getDebugFileSize(
            $this->getDebugFilePath(),
            $this->getDebugFilePathOld()
        );
    }
    
}
