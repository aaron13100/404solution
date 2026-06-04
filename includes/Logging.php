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
     * Factory for the DI container.
     *
     * This avoids recursion when the container's 'logging' service is defined in terms of getInstance().
     *
     * @return ABJ_404_Solution_Logging
     */
    public static function createForContainer() {
        // Create a fresh instance without consulting the container.
        $logger = new ABJ_404_Solution_Logging();

        // Flush any pending errors captured before the logger existed.
        if (isset($GLOBALS['abj404_pending_errors']) && is_array($GLOBALS['abj404_pending_errors'])) {
            foreach ($GLOBALS['abj404_pending_errors'] as $message) {
                $logger->errorMessage($message);
            }
            unset($GLOBALS['abj404_pending_errors']); // Clear after flushing
        }

        // Also sync singleton for legacy callers.
        self::$instance = $logger;

        return $logger;
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

        self::$instance = new ABJ_404_Solution_Logging();

        // log any errors that were stored before the logger existed.
        if (isset($GLOBALS['abj404_pending_errors']) && is_array($GLOBALS['abj404_pending_errors'])) {
            foreach ($GLOBALS['abj404_pending_errors'] as $message) {
                self::$instance->errorMessage($message);
            }
            unset($GLOBALS['abj404_pending_errors']); // Clear after flushing
        }

        return self::$instance;
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

    /** @return ABJ_404_Solution_DebugLogFileStore */
    private function getDebugLogFileStore(): ABJ_404_Solution_DebugLogFileStore {
        if ($this->debugLogFileStore === null) {
            $this->debugLogFileStore = new ABJ_404_Solution_DebugLogFileStore(
                array($this, 'sanitizeLogLine'),
                self::DEBUG_FILE_KEY,
                self::LAST_SENT_LINE);
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
    
    /** @return boolean true if debug mode is on. false otherwise. */
    function isDebug() {
        $options = abj_service('options_repository')->getOptions(true);

        return (array_key_exists('debug_mode', $options) && $options['debug_mode'] == true);
    }
    
    /** for the current timezone. 
     * @return string */
    function getTimestamp() {
        $date = null;
        $timezoneStringRaw = get_option('timezone_string');
        $timezoneString = is_string($timezoneStringRaw) ? $timezoneStringRaw : '';

        if (!empty($timezoneString)) {
            $date = new DateTime("now", new DateTimeZone($timezoneString));
        } else {
            $gmtOffsetRaw = get_option('gmt_offset');
            // WordPress's gmt_offset is hours and may be fractional
            // (e.g. 5.5 India, 5.75 Nepal, -3.5 Newfoundland).
            $gmtOffsetHours = is_scalar($gmtOffsetRaw) ? (float)$gmtOffsetRaw : 0.0;
            $totalMinutes = (int) round($gmtOffsetHours * 60);
            $sign = $totalMinutes < 0 ? '-' : '+';
            $absMinutes = abs($totalMinutes);
            $tzString = sprintf('%s%02d:%02d', $sign, intdiv($absMinutes, 60), $absMinutes % 60);

            try {
                $date = new DateTime("now", new DateTimeZone($tzString));
            } catch (Exception $e) {
                // Use error_log (not $this->warn) because this method is part
                // of the logging path; calling warn here would risk recursion
                // if the timezone failure also breaks warn's own DateTime use.
                @error_log('404 Solution: timezone constructor failed (' . $e->getMessage() . '); using server default');
                $date = new DateTime();
            }
        }
        
        return $date->format('Y-m-d H:i:s T');
    }
    
    /** Send a message to the log file if debug mode is on.
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message
     * @param \Throwable|null $e If present then a stack trace is included.
     * @return void
     */
    function debugMessage(string $message, $e = null): void {
    	$stacktrace = "";
    	if ($e != null) {
    		$stacktrace = ", Stacktrace: " . $e->getTraceAsString();
    	}
    	
        $timestamp = $this->getTimestamp() . ' (DEBUG): ';
        if ($this->isDebug()) {
        	$this->writeLineToDebugFile($timestamp . $message . $stacktrace);
            
        } else {
        	array_push(self::$storedDebugMessages, $timestamp . $message . $stacktrace);
        }
    }

    /** Send a message to the log.
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message
     * @return void
     */
    function infoMessage(string $message): void {
    	$timestamp = $this->getTimestamp() . ' (INFO): ';
    	$this->writeLineToDebugFile($timestamp . $message);
    }
    
    /** Send a message to the log.
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message
     * @return void
     */
    function warn(string $message): void {
        $timestamp = $this->getTimestamp() . ' (WARN): ';
        $this->writeLineToDebugFile($timestamp . $message);
    }

    /** Always send a message to the error_log.
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message
     * @param \Exception|null $e
     * @return void
     */
    function errorMessage(string $message, $e = null): void {
        if ($e == null) {
            $e = new Exception;
        }
        $stacktrace = $e->getTraceAsString();
        
        $savedDebugMessages = implode("\n", self::$storedDebugMessages);
        self::$storedDebugMessages = array();
        
        $timestamp = $this->getTimestamp() . ' (ERROR): ';
        $referrer = '';
        if (array_key_exists('HTTP_REFERER', $_SERVER) && !empty($_SERVER['HTTP_REFERER'])) {
            $referrer = $_SERVER['HTTP_REFERER'];
        }
        $requestedURL = '';
        if (array_key_exists('REQUEST_URI', $_SERVER) && !empty($_SERVER['REQUEST_URI'])) {
            $requestedURL = $_SERVER['REQUEST_URI'];
        }
        $this->writeLineToDebugFile($timestamp . $message . ", PHP version: " . PHP_VERSION . 
                ", WP ver: " . get_bloginfo('version') . ", Plugin ver: " . ABJ404_VERSION . 
                ", Referrer: " . $referrer . ", Requested URL: " . $requestedURL . 
                ", \nStored debug messages: \n" . $savedDebugMessages . ", \nTrace: " . $stacktrace);
    }
    
    /** Log the user capabilities.
     * @param string $msg
     * @return void
     */
    function logUserCapabilities(string $msg): void {
    	$f = abj_service('functions');
    	$abj404logic = abj_service('plugin_logic');
    	$user = wp_get_current_user();
        $usercaps = $f->str_replace(',"', ', "', wp_kses_post((string)json_encode($user->get_role_caps())));
        
        $userIsPluginAdminStr = "false";
        if (abj_service('admin_access_policy')->isPluginAdmin()) {
        	$userIsPluginAdminStr = "true";
        }
        
        $this->debugMessage("User caps msg: " . esc_html($msg == '' ? '(none)' : $msg) . ", is_admin(): " . is_admin() .
        		", current_user_can('manage_options'): " . current_user_can('manage_options') .
        		", current_user_can('administrator'): " . current_user_can('administrator') .
        		", userIsPluginAdmin(): " . $userIsPluginAdminStr .
        		", user_login: " . esc_html($user->user_login ?? '(none)') .
                ", user caps: " . wp_kses_post((string)json_encode($user->caps)) . ", get_role_caps: " .
                $usercaps . ", WP ver: " . get_bloginfo('version') . ", mbstring: " .
                (extension_loaded('mbstring') ? 'true' : 'false'));
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
        $debugFilePath = $this->getDebugFilePath();
        if (!file_exists($debugFilePath)) {
            $this->debugMessage("No log file found so no errors were found.");
            return false;
        }

        $latestErrorLineFound = $this->getLatestErrorLine();
        if ($latestErrorLineFound['num'] == -1) {
            $this->debugMessage("No errors found in the log file.");
            return false;
        }

        $optionsRepo = abj_service('options_repository');
        $options = $optionsRepo->getOptions(true);
        $dedupe = $this->getDedupeState();
        $sentinelFilePath = $this->getDebugFilePathSentFile();
        $sentLine = $dedupe->readSentLine($options, $sentinelFilePath);
        $this->debugMessage("Dedupe pointer: sentLine=" . $sentLine);

        if ($dedupe->isAlreadySent($sentLine, $latestErrorLineFound, $debugFilePath)) {
            $this->debugMessage("The latest error line from the log file was already emailed. " .
                $latestErrorLineFound['num'] . ' <= ' . $sentLine);
            return false;
        }

        // only email the error file if the latest version of the plugin is installed.
        $pluginUpdateRepo = abj_service('plugin_update_metadata_repository');
        if (!$pluginUpdateRepo->shouldEmailErrorFileFor($pluginUpdateRepo->getLatestPluginVersion())) {
            return false;
        }

        if (!$dedupe->recordSent($options, $sentinelFilePath, $debugFilePath, $latestErrorLineFound)) {
            $this->errorMessage("There was an issue writing to the file " . $sentinelFilePath);
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
     * Lazily-constructed dedupe-state collaborator. Built off
     * abj_service('options_repository') to match the production wiring of the
     * old inline code path. Memoized so a single request reusing the logger
     * doesn't churn through repeated container lookups.
     *
     * @return ABJ_404_Solution_ErrorEmailDedupeState
     */
    private function getDedupeState(): ABJ_404_Solution_ErrorEmailDedupeState {
        if ($this->dedupeState === null) {
            $this->dedupeState = new ABJ_404_Solution_ErrorEmailDedupeState(
                abj_service('options_repository'));
        }
        return $this->dedupeState;
    }

    /** @var ABJ_404_Solution_ErrorEmailDedupeState|null */
    private $dedupeState = null;

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
     * Roll a 1-in-N dice and send a full debug zip as a heartbeat if it hits.
     * Called during daily maintenance for opted-in sites when no error email was sent.
     *
     * Dispatches via FeedbackTransport::sendNow() (HTTP POST + email fallback)
     * with type='heartbeat' so the same payload shape is shared with the error
     * path.
     *
     * @param int $oneInN Probability denominator (default 200 = ~once per 6 months).
     * @return bool True if a heartbeat was sent.
     */
    function sendHeartbeatIfDueRandom(int $oneInN = 200): bool {
        if (!file_exists($this->getDebugFilePath())) {
            return false;
        }
        if (mt_rand(1, $oneInN) !== 1) {
            return false;
        }
        $this->debugMessage("Heartbeat dice roll hit (1-in-{$oneInN}). Sending heartbeat log.");
        $errorInfo = $this->getLatestErrorLine();

        $payload = ABJ_404_Solution_FeedbackTransport::buildPayload('heartbeat', array(
            'error_signature' => 'Heartbeat: no errors to report.',
            'previously_sent_line' => 0,
            'error_count_in_log' => (int)$errorInfo['total_error_count'],
        ));
        ABJ_404_Solution_FeedbackTransport::sendNow($payload, 'heartbeat');
        return true;
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
        try {
            /** @var ABJ_404_Solution_PiiRedactor $redactor */
            $redactor = abj_service('pii_redactor');
        } catch (\Exception $e) {
            // allow-silent-catch: early boot before container is initialized; fall through to raw line
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
