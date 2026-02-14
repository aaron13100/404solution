<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Static functions that can be used from anywhere.  */

class ABJ_404_Solution_Logging {

    /** If an error happens then we will also output these. */
    private static $storedDebugMessages = array();

    /** Used to store the last line sent from the debug file. */
    const LAST_SENT_LINE = 'last_sent_line';
    
    /** Used to store the the debug filename. */
    const DEBUG_FILE_KEY = 'debug_file_key';
    
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

    public static function getInstance() {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // If the DI container is initialized, prefer it.
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('logging')) {
                    self::$instance = $c->get('logging');
                    return self::$instance;
                }
            } catch (Throwable $e) {
                // fall back to legacy singleton below
            }
        }

        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_Logging();

            // log any errors that were stored before the logger existed.
            if (isset($GLOBALS['abj404_pending_errors']) && is_array($GLOBALS['abj404_pending_errors'])) {
                foreach ($GLOBALS['abj404_pending_errors'] as $message) {
                    self::$instance->errorMessage($message);
                }
                unset($GLOBALS['abj404_pending_errors']); // Clear after flushing
            }
        }

        return self::$instance;
    }
    
    private function __construct() {
    }
    
    /** @return boolean true if debug mode is on. false otherwise. */
    function isDebug() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $options = $abj404logic->getOptions(true);

        return (array_key_exists('debug_mode', $options) && $options['debug_mode'] == true);
    }
    
    /** for the current timezone. 
     * @return string */
    function getTimestamp() {
        $date = null;
        $timezoneString = get_option('timezone_string');
        
        if (!empty($timezoneString)) {
            $date = new DateTime("now", new DateTimeZone($timezoneString));
        } else {
            $timezoneOffset = (int)get_option('gmt_offset');
            $timezoneOffsetString = '+';
            if ($timezoneOffset < 0) {
                $timezoneOffsetString = '-';
            }

            try {
                // PHP versions before 5.5.18 don't accept "+0" in the constructor.
                // This try/catch fixes https://wordpress.org/support/topic/fatal-error-3172/
                if (version_compare(phpversion(), "5.5.18", ">=")) {
                    $date = new DateTime("now", new DateTimeZone($timezoneOffsetString . $timezoneOffset));
                } else {
                    $date = new DateTime();
                }
            } catch (Exception $e) {
                $date = new DateTime();
            }
        }
        
        return $date->format('Y-m-d H:i:s T');
    }
    
    /** Send a message to the log file if debug mode is on. 
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message  
     * @param \Exception $e If present then a stack trace is included. */
    function debugMessage($message, $e = null) {
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
     * @param string $message  */
    function infoMessage($message) {
    	$timestamp = $this->getTimestamp() . ' (INFO): ';
    	$this->writeLineToDebugFile($timestamp . $message);
    }
    
    /** Send a message to the log. 
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message  */
    function warn($message) {
        $timestamp = $this->getTimestamp() . ' (WARN): ';
        $this->writeLineToDebugFile($timestamp . $message);
    }

/** Always send a message to the error_log.
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message
     * @param Exception $e
     */
    function errorMessage($message, $e = null) {
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
     */
    function logUserCapabilities($msg) {
    	$f = ABJ_404_Solution_Functions::getInstance();
    	$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
    	$user = wp_get_current_user();
        $usercaps = $f->str_replace(',"', ', "', wp_kses_post(json_encode($user->get_role_caps())));
        
        $userIsPluginAdminStr = "false";
        if ($abj404logic->userIsPluginAdmin()) {
        	$userIsPluginAdminStr = "true";
        }
        
        $this->debugMessage("User caps msg: " . esc_html($msg == '' ? '(none)' : $msg) . ", is_admin(): " . is_admin() . 
        		", current_user_can('administrator'): " . current_user_can('administrator') . 
        		", userIsPluginAdmin(): " . $userIsPluginAdminStr . 
                ", user caps: " . wp_kses_post(json_encode($user->caps)) . ", get_role_caps: " . 
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
        // Sanitize PII at write-time (GDPR compliance)
        // This protects all 372 logging calls across the codebase
        $sanitizedLine = $this->sanitizeLogLine($line);

        // Suppress errors to prevent fatal error when disk is full
        $result = @file_put_contents($this->getDebugFilePath(), $sanitizedLine . "\n", FILE_APPEND);

        if ($result === false) {
            // Disk full or permissions issue - log to error_log instead to avoid infinite loop
            // Don't use errorMessage() here as it would call this function again
            error_log('404 Solution: Unable to write to debug log (possibly disk full): ' .
                $this->getDebugFilePath());
            return false;
        }

        return true;
    }
    
    /** Email the log file to the plugin developer. */
    function emailErrorLogIfNecessary() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $options = $abj404logic->getOptions(true);
        
        if (!file_exists($this->getDebugFilePath())) {
            $this->debugMessage("No log file found so no errors were found.");
            return false;
        }

        // get the number of the last line with an error message.
        $latestErrorLineFound = $this->getLatestErrorLine();
        
        // if no error was found then we're done.
        if ($latestErrorLineFound['num'] == -1) {
            $this->debugMessage("No errors found in the log file.");
            return false;
        }
        
        // -------------------
        // get/check the last line that was emailed to the admin.
        $sentDateFile = $this->getDebugFilePathSentFile();
        
        $sentLine = -1;
        if (file_exists($sentDateFile)) {
            $sentLine = absint(
            	ABJ_404_Solution_Functions::readFileContents($sentDateFile, false));
            $this->debugMessage("Last sent line from file: " . $sentLine);
        }
        if ($sentLine < 1 && array_key_exists(self::LAST_SENT_LINE, $options)) {
        	$sentLine = $options[self::LAST_SENT_LINE];
       		$this->debugMessage("Last sent line from options: " . $sentLine);
        }
        
        // if we already sent the error line then don't send the log file again.
        if ($latestErrorLineFound['num'] <= $sentLine) {
            $this->debugMessage("The latest error line from the log file was already emailed. " . $latestErrorLineFound['num'] . 
                    ' <= ' . $sentLine);
            return false;
        }
        
        // only email the error file if the latest version of the plugin is installed.
        if (!$abj404dao->shouldEmailErrorFile()) {
            return false;
        }
        
        // update the latest error line emailed to the developer.
        $options[self::LAST_SENT_LINE] = $latestErrorLineFound['num'];
        $abj404logic->updateOptions($options);
        file_put_contents($sentDateFile, $latestErrorLineFound['num']);
        $fileContents = file_get_contents($sentDateFile);
        if ($fileContents != $latestErrorLineFound['num']) {
        	$this->errorMessage("There was an issue writing to the file " . $sentDateFile);
        	return false;
        	
        } else {
        	$this->emailLogFileToDeveloper($latestErrorLineFound['line'], 
        		$latestErrorLineFound['total_error_count'], $sentLine);
        	return true;
        }
        
        return false;
    }
    
    function emailLogFileToDeveloper($errorLineMessage, $totalErrorCount, $previouslySentLine) {
        global $wpdb;
        
        // email the log file.
        $this->debugMessage("Creating zip file of error log file. " . 
        	"Previously sent error line: " . $previouslySentLine);
        $logFileZip = $this->getZipFilePath();
        if (file_exists($logFileZip)) {
            ABJ_404_Solution_Functions::safeUnlink($logFileZip);
        }
        $zip = new ZipArchive;
        if ($zip->open($logFileZip, ZipArchive::CREATE) === TRUE) {
            $zip->addFile($this->getDebugFilePath(), basename($this->getDebugFilePath()));
            if (file_exists($this->getDebugFilePathOld())) {
            	$zip->addFile($this->getDebugFilePathOld(), basename($this->getDebugFilePathOld()));
            }
            $zip->close();
        }
        
        // Get WordPress content counts
        $count_posts = wp_count_posts();
        $published_posts = $count_posts->publish;
        $count_pages = wp_count_posts('page');
        $published_pages = $count_pages->publish;

        // Get category and tag counts
        $category_count = wp_count_terms('category');
        $tag_count = wp_count_terms('post_tag');
        // Handle WP_Error for categories/tags
        if (is_wp_error($category_count)) {
            $category_count = 0;
        }
        if (is_wp_error($tag_count)) {
            $tag_count = 0;
        }

        // Get plugin-specific counts
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        $redirectCounts = $abj404dao->getRedirectStatusCounts(true);
        $capturedCounts = $abj404dao->getCapturedStatusCounts(true);
        $totalLogsInDB = $abj404dao->getLogsCount(0);

        // Get storage sizes
        $logTableSizeBytes = $abj404dao->getLogDiskUsage();
        $logTableSizeMB = round($logTableSizeBytes / (1024 * 1024), 2);
        $debugFileSize = file_exists($this->getDebugFilePath()) ? filesize($this->getDebugFilePath()) : 0;
        $debugFileSizeMB = round($debugFileSize / (1024 * 1024), 2);

        $attachments = array();
        $attachments[] = $logFileZip;
        $to = ABJ404_AUTHOR_EMAIL;
        $subject = ABJ404_PP . ' error log file. Plugin version: ' . ABJ404_VERSION;
        $bodyLines = array();
        $bodyLines[] = $subject . ". Sent " . date('Y/m/d h:i:s T');
        $bodyLines[] = " ";
        $bodyLines[] = "Error: " . $errorLineMessage;
        $bodyLines[] = " ";
        $bodyLines[] = "PHP version: " . PHP_VERSION;
        $bodyLines[] = "WordPress version: " . get_bloginfo('version');
        $bodyLines[] = "Plugin version: " . ABJ404_VERSION;
        $bodyLines[] = "MySQL version: " . $wpdb->db_version();
        $bodyLines[] = "Site URL: " . get_site_url();
        $bodyLines[] = "Multisite: " . (is_multisite() ? 'yes' : 'no');
        if (is_multisite() && function_exists('is_plugin_active_for_network')) {
            $bodyLines[] = "Network activated: " . (is_plugin_active_for_network(plugin_basename(ABJ404_FILE)) ? 'yes' : 'no');
        }
        $bodyLines[] = "WP_MEMORY_LIMIT: " . WP_MEMORY_LIMIT;
        $bodyLines[] = "Extensions: " . implode(", ", get_loaded_extensions());
        $bodyLines[] = " ";
        $bodyLines[] = "--- WordPress Content Counts ---";
        $bodyLines[] = "Published posts: " . $published_posts;
        $bodyLines[] = "Published pages: " . $published_pages;
        $bodyLines[] = "Categories: " . $category_count;
        $bodyLines[] = "Tags: " . $tag_count;
        $bodyLines[] = " ";
        $bodyLines[] = "--- 404 Solution Counts ---";
        $bodyLines[] = "Total redirects (active): " . $redirectCounts['all'];
        $bodyLines[] = "  - Manual redirects: " . $redirectCounts['manual'];
        $bodyLines[] = "  - Automatic redirects: " . $redirectCounts['auto'];
        $bodyLines[] = "  - Regex redirects: " . $redirectCounts['regex'];
        $bodyLines[] = "  - Trashed redirects: " . $redirectCounts['trash'];
        $bodyLines[] = "Captured 404s (active): " . $capturedCounts['all'];
        $bodyLines[] = "  - Captured (new): " . $capturedCounts['captured'];
        $bodyLines[] = "  - Ignored: " . $capturedCounts['ignored'];
        $bodyLines[] = "  - Later: " . $capturedCounts['later'];
        $bodyLines[] = "  - Trashed: " . $capturedCounts['trash'];
        $bodyLines[] = "Log entries in database: " . $totalLogsInDB;
        $bodyLines[] = "Log table size: " . $logTableSizeMB . " MB";
        $bodyLines[] = " ";
        $bodyLines[] = "Total error count in log file: " . $totalErrorCount;
        $bodyLines[] = "Debug file name: " . $this->getDebugFilename();
        $bodyLines[] = "Debug file size: " . $debugFileSizeMB . " MB";
        $bodyLines[] = "Active plugins: <pre>" .
          json_encode(get_option('active_plugins'), JSON_PRETTY_PRINT) . "</pre>";
          
        $body = implode("<BR/>\n", $bodyLines);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $headers[] = 'From: ' . get_option('admin_email');
        
        // send the email
        $this->debugMessage("Sending error log zip file as attachment.");
        wp_mail($to, $subject, $body, $headers, $attachments);
        
        // delete the zip file.
        ABJ_404_Solution_Functions::safeUnlink($logFileZip);
        $this->debugMessage("Mail sent. Log zip file deleted.");
    }
    
    /** 
     * @return array
     */
    function getLatestErrorLine() {
        $f = ABJ_404_Solution_Functions::getInstance();
        $latestErrorLineFound = array();
        $latestErrorLineFound['num'] = -1;
        $latestErrorLineFound['line'] = null;
        $latestErrorLineFound['total_error_count'] = 0;
        $linesRead = 0;
        $handle = null;
        $collectingErrorLines = false;
        try {
            if ($handle = fopen($this->getDebugFilePath(), "r")) {
                // read the file one line at a time.
                while (($line = fgets($handle)) !== false) {
                    $linesRead++;
                    // if the line has an error then save the line number.
                    $hasError = stripos($line, '(ERROR)');
                    $isDeleteError = stripos($line, 'SQL query error: DELETE command denied to user');
                    if ($hasError !== false && $isDeleteError === false) {
                    	$latestErrorLineFound['num'] = $linesRead;
                        $latestErrorLineFound['line'] = $line;
                        $latestErrorLineFound['total_error_count'] += 1;
                        $collectingErrorLines = true;
                        
                    } else if ($collectingErrorLines && 
                    	!$f->regexMatch("^\d{4}[-]\d{2}[-]\d{2} .*\(\w+\):\s.*$", $line)) {
                        // if we're collecting error lines and we haven't found the 
                        // beginning of a new debug message then continue collecting lines.
                        $latestErrorLineFound['line'] .= "<BR/>\n" . $line;
                        
                    } else {
                    	// this must be the beginning of a new debug message so we'll stop
                    	// collecting error lines.
                    	$collectingErrorLines = false;
                   	}
                }
            } else {
                $this->errorMessage("Error reading log file (1).");
            }
            
        } catch (Exception $e) {
            $this->errorMessage("Error reading log file. (2)", $e);
        }
            
        if ($handle != null) {
            fclose($handle);
        }
        
        return $latestErrorLineFound;
    }
    
    /**
     * Get sanitized log excerpt for support emails
     * Collects last 15 ERROR/WARN entries (already sanitized at write-time)
     * If no errors/warnings found, includes last 20 lines of log for context
     *
     * @return string Sanitized log excerpt or message if no errors found
     */
    function getSanitizedLogExcerptForSupport() {
        $f = ABJ_404_Solution_Functions::getInstance();
        $errorEntries = array();
        $recentLines = array();
        $maxEntries = 15;
        $maxRecentLines = 20;
        $totalLines = 0;
        $handle = null;

        try {
            $debugFilePath = $this->getDebugFilePath();

            if (!file_exists($debugFilePath)) {
                return "No log file available";
            }

            if ($handle = fopen($debugFilePath, "r")) {
                $currentEntry = array();
                $collectingEntry = false;

                // Read file line by line
                while (($line = fgets($handle)) !== false) {
                    $totalLines++;

                    // Keep a sliding window of recent lines (for fallback if no errors)
                    $recentLines[] = $line;
                    if (count($recentLines) > $maxRecentLines) {
                        array_shift($recentLines);
                    }

                    // Check if this is an ERROR or WARN line
                    $hasError = stripos($line, '(ERROR)') !== false;
                    $hasWarn = stripos($line, '(WARN)') !== false;
                    $isDeleteError = stripos($line, 'SQL query error: DELETE command denied to user') !== false;

                    // Start collecting if we find ERROR or WARN (but skip known benign errors)
                    if (($hasError || $hasWarn) && !$isDeleteError) {
                        // If we were collecting a previous entry, save it
                        if ($collectingEntry && !empty($currentEntry)) {
                            $errorEntries[] = $currentEntry;
                            // Keep only last N entries (sliding window)
                            if (count($errorEntries) > $maxEntries) {
                                array_shift($errorEntries);
                            }
                        }

                        // Start new entry (no sanitization needed - already done at write-time)
                        $currentEntry = array($line);
                        $collectingEntry = true;

                    } else if ($collectingEntry &&
                               !$f->regexMatch("^\d{4}[-]\d{2}[-]\d{2} .*\(\w+\):\s.*$", $line)) {
                        // Continue collecting multiline error (no sanitization needed - already done at write-time)
                        $currentEntry[] = $line;

                    } else {
                        // New log entry started, save previous if exists
                        if ($collectingEntry && !empty($currentEntry)) {
                            $errorEntries[] = $currentEntry;
                            if (count($errorEntries) > $maxEntries) {
                                array_shift($errorEntries);
                            }
                        }
                        $collectingEntry = false;
                        $currentEntry = array();
                    }
                }

                // Save last entry if we were still collecting
                if ($collectingEntry && !empty($currentEntry)) {
                    $errorEntries[] = $currentEntry;
                    if (count($errorEntries) > $maxEntries) {
                        array_shift($errorEntries);
                    }
                }

                fclose($handle);

            } else {
                return "Log file not readable";
            }

        } catch (Exception $e) {
            return "Error reading log file";
        }

        // Format output
        if (empty($errorEntries)) {
            // No errors/warnings found - include last N lines for context
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

        return trim($output);
    }

    /**
     * Mask email address with adaptive length-based masking
     * Shows 1-3 chars of username and ≤30% of domain based on length
     *
     * Examples:
     * - joe@mail.com → j***@m***-a1b2
     * - john@gmail.com → j***@gm***-c3d4
     * - jennifer@example.com → jen***@exa***-e5f6
     *
     * @param string $email Email address to mask
     * @return string Masked email with consistent hash
     */
    private function maskEmailAdaptive($email) {
        if (empty($email) || strpos($email, '@') === false) {
            return $email;
        }

        // Split email into parts
        $parts = explode('@', $email);
        if (count($parts) != 2) {
            // Invalid email (multiple @), mask entire string as text
            return $this->maskTextAdaptive($email);
        }

        list($username, $fullDomain) = $parts;

        // Strip TLD from domain (remove .com, .org, .co.uk, etc.)
        $domainParts = explode('.', $fullDomain);
        if (count($domainParts) > 1) {
            // Remove last part (.com), or last 2 parts if it's .co.uk style
            if (in_array(end($domainParts), array('uk', 'au', 'nz', 'za'))) {
                // .co.uk style - remove last 2 parts
                array_pop($domainParts);
                array_pop($domainParts);
            } else {
                // .com style - remove last part
                array_pop($domainParts);
            }
        }
        $domain = implode('.', $domainParts);

        // Calculate visible characters for username (1-3 based on length)
        $usernameLen = strlen($username);
        if ($usernameLen <= 4) {
            $usernameVisible = 1;
        } elseif ($usernameLen <= 9) {
            $usernameVisible = 2;
        } else {
            $usernameVisible = 3;
        }

        // Calculate visible characters for domain (≤30%)
        $domainLen = strlen($domain);
        $domainVisible = max(1, (int) ceil($domainLen * 0.3));

        // Create masked parts
        $maskedUsername = substr($username, 0, $usernameVisible) . '***';
        $maskedDomain = empty($domain) ? '' : substr($domain, 0, $domainVisible) . '***';

        // Generate consistent hash with WordPress salt for security
        if (defined('AUTH_SALT')) {
            $hash = substr(md5(AUTH_SALT . $email), 0, 4);
        } else {
            $hash = substr(md5($email), 0, 4);
        }

        // Format: username***@domain***-hash
        if (!empty($maskedDomain)) {
            return $maskedUsername . '@' . $maskedDomain . '-' . $hash;
        } else {
            return $maskedUsername . '@-' . $hash;
        }
    }

    /**
     * Mask text (names, usernames) with adaptive length-based masking
     * Shows 1-3 chars based on length + consistent hash
     *
     * Examples:
     * - Joe → J***-a1b2
     * - John → J***-c3d4
     * - Jennifer → Jen***-e5f6
     *
     * @param string $text Text to mask
     * @return string Masked text with consistent hash
     */
    private function maskTextAdaptive($text) {
        if (empty($text)) {
            return $text;
        }

        $text = trim($text);
        $textLen = strlen($text);

        // Calculate visible characters (1-3 based on length)
        if ($textLen <= 4) {
            $visible = 1;
        } elseif ($textLen <= 9) {
            $visible = 2;
        } else {
            $visible = 3;
        }

        $masked = substr($text, 0, $visible) . '***';

        // Generate consistent hash with WordPress salt
        if (defined('AUTH_SALT')) {
            $hash = substr(md5(AUTH_SALT . $text), 0, 4);
        } else {
            $hash = substr(md5($text), 0, 4);
        }

        return $masked . '-' . $hash;
    }

    /**
     * Sanitize a single log line for privacy (GDPR compliance)
     * Uses adaptive masking with consistent hashing for debugging
     *
     * @param string $line Log line to sanitize
     * @return string Sanitized line with PII masked adaptively
     */
    public function sanitizeLogLine($line) {
        $f = ABJ_404_Solution_Functions::getInstance();

        // Strip query strings from URLs (everything after ? in http/https URLs)
        // This removes tokens, emails, session IDs, search terms, etc. from URLs
        $line = preg_replace('/(https?:\/\/[^\s?]+)\?[^\s]*/', '$1', $line);

        // Mask email addresses with adaptive length-based masking
        // Example: john@example.com -> j***@exa***-a1b2
        $line = preg_replace_callback(
            '/\S+@\S+/',
            function($matches) {
                return $this->maskEmailAdaptive($matches[0]);
            },
            $line
        );

        // Redact IP addresses using existing md5lastOctet function
        // Keeps first octets, hashes last (e.g., 192.168.1.100 -> 192.168.1.md5hash)
        $line = preg_replace_callback(
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            function($matches) use ($f) {
                return $f->md5lastOctet($matches[0]);
            },
            $line
        );

        // Redact IPv6 addresses (including compressed forms) using existing md5lastOctet function
        // Negative lookbehind prevents matching mid-hex-string; handles ::1, 2001:db8::1, etc.
        $line = preg_replace_callback(
            '/(?<![0-9A-Fa-f:])(?:(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|(?:[0-9a-fA-F]{1,4}:){1,7}:|(?:[0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|(?:[0-9a-fA-F]{1,4}:){1,5}(?::[0-9a-fA-F]{1,4}){1,2}|(?:[0-9a-fA-F]{1,4}:){1,4}(?::[0-9a-fA-F]{1,4}){1,3}|(?:[0-9a-fA-F]{1,4}:){1,3}(?::[0-9a-fA-F]{1,4}){1,4}|(?:[0-9a-fA-F]{1,4}:){1,2}(?::[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:(?::[0-9a-fA-F]{1,4}){1,6}|:(?:(?::[0-9a-fA-F]{1,4}){1,7}|:))(?![0-9A-Fa-f:])/',
            function($matches) use ($f) {
                return $f->md5lastOctet($matches[0]);
            },
            $line
        );

        // Mask usernames with adaptive length-based masking
        // Example: "Current user: john" -> "Current user: j***-a1b2"
        $line = preg_replace_callback(
            '/\b(current\s+)?user(name)?:\s*(\S+)/i',
            function($matches) {
                $prefix = $matches[1] . 'user' . $matches[2] . ': ';
                $username = $matches[3];
                return $prefix . $this->maskTextAdaptive($username);
            },
            $line
        );

        // Mask display names with adaptive length-based masking
        // Example: "Display name: John Doe" -> "Display name: J***-a1b2"
        $line = preg_replace_callback(
            '/\bdisplay\s+name:\s*([^\n,]+)/i',
            function($matches) {
                $name = trim($matches[1]);
                return 'display name: ' . $this->maskTextAdaptive($name);
            },
            $line
        );

        // Redact absolute file paths to prevent info disclosure
        // Matches /home/user/..., /var/www/..., etc.
        // Captures leading space/start to preserve formatting
        $line = preg_replace(
            '/(^|\s)(\/[^\s]+\/wp-content\/)/i',
            '$1/...redacted.../wp-content/',
            $line
        );
        $line = preg_replace(
            '/\b[a-z]:\\\\[^\s]+\\\\wp-content\\\\/i',
            'C:\\...redacted...\\wp-content\\',
            $line
        );

        // Hash long tokens consistently (40+ chars)
        // Example: "abc123def456..." -> "token-a1b2c3d4"
        $line = preg_replace_callback(
            '/\b([A-Za-z0-9_-]{40,})\b/',
            function($matches) {
                $hash = substr(md5($matches[1]), 0, 8);
                return 'token-' . $hash;
            },
            $line
        );

        // Hash WordPress nonces consistently
        // Example: "_wpnonce=abc123" -> "_wpnonce=nonce-a1b2c3d4"
        $line = preg_replace_callback(
            '/_wpnonce=([A-Za-z0-9]+)/',
            function($matches) {
                $hash = substr(md5($matches[1]), 0, 8);
                return '_wpnonce=nonce-' . $hash;
            },
            $line
        );

        return $line;
    }

    /** Return the path to the debug file.
     * @return string
     */
    function getDebugFilePath() {
        $debugFileName = $this->getDebugFilename();
        return $this->getFilePathAndMoveOldFile(abj404_getUploadsDir(), $debugFileName);
    }
    
    function getDebugFilename() {
        // get the UUID here.
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $options = $abj404logic->getOptions(true);
        $debugFileKey = null;
        if (array_key_exists(self::DEBUG_FILE_KEY, $options)) {
            $debugFileKey = $options[self::DEBUG_FILE_KEY];
        }
        // if the key doesn't exist then create it.
        if ($debugFileKey == null || trim($debugFileKey) == '') {
            // delete any lingering debug files.
            $this->deleteDebugFile();

            // create a probably unique UUID and store it to the database.
            $syncUtils = ABJ_404_Solution_SynchronizationUtils::getInstance();
            $debugFileKey = $syncUtils->uniqidReal();
            $options[self::DEBUG_FILE_KEY] = $debugFileKey;
            $abj404logic->updateOptions($options);
        }
        
        $debugFileName = 'abj404_debug_' . $debugFileKey . '.txt';
        
        return $debugFileName;
    }
    
    function getDebugFilePathOld() {
        return $this->getDebugFilePath() . "_old.txt";
    }
    
    /** Return the path to the file that stores the latest error line in the log file.
     * @return string
     */
    function getDebugFilePathSentFile() {
    	return $this->getFilePathAndMoveOldFile(abj404_getUploadsDir(), 'abj404_debug_sent_line.txt');
    }
    
    /** Return the path to the zip file for sending the debug file. 
     * @return string
     */
    function getZipFilePath() {
    	return $this->getFilePathAndMoveOldFile(abj404_getUploadsDir(), 'abj404_debug.zip');
    }
    
    /** This is for legacy support. On new installations it creates a directory and returns
     * a file path. On old installations it moved the old file to the new location. 
     * If the directory can't be created then it falls back to the old location.
     * @param string $directory
     * @param string $filename
     * @return string
     */
    function getFilePathAndMoveOldFile($directory, $filename) {
    	$f = ABJ_404_Solution_Functions::getInstance();
        // create the directory and move the file
        if (!$f->createDirectoryWithErrorMessages($directory)) {
            return ABJ404_PATH . $filename;
        }
        
        if (file_exists(ABJ404_PATH . $filename)) {
            // move the file to the new location
            rename(ABJ404_PATH . $filename, $directory . $filename);
        }
        
        return $directory . $filename;
    }
    
    function limitDebugFileSize() {
        // delete the sent_line file since it's now incorrect.
        if (file_exists($this->getDebugFilePathSentFile())) {
            ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePathSentFile());
        }

        // update the last sent error line since the debug file will be deleted.
        $this->removeLastSentErrorLineFromDatabase();
        
        // delete _old log file
        ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePathOld());
        // rename current log file to _old
        rename($this->getDebugFilePath(), $this->getDebugFilePathOld());
    }
    
    function removeLastSentErrorLineFromDatabase() {
    	// update the last sent error line since the debug file will be deleted.
    	$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
    	$options = $abj404logic->getOptions(true);
    	$options[self::LAST_SENT_LINE] = 0;
    	$abj404logic->updateOptions($options);
    }
    
    /** Deletes all files named abj404_debug_*.txt
     * @return boolean true if the file was deleted.
     */
    function deleteDebugFile() {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $allIsWell = true;
        
        // since the debug file is being deleted we reset the last error line that was sent.
        if (file_exists($this->getDebugFilePathSentFile())) {
            ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePathSentFile());
        }
        // update the last sent error line since the debug file will be deleted.
        $this->removeLastSentErrorLineFromDatabase();
        
        // delete the debug file(s).
        // list any files in the directory and delete any files named debug_*.txt
        $uploadDir = abj404_getUploadsDir();
        // Check if the directory exists
        if (is_dir($uploadDir)) {
            // Get all files matching the pattern abj404_debug_*.txt
            $files = glob($uploadDir . '/abj404_debug_*.txt');
            foreach ($files as $file) { // Loop through the files and delete them
                if (is_file($file)) {
                    // Delete the file
                    if (!ABJ_404_Solution_Functions::safeUnlink($file)) {
                        $allIsWell = false;
                    }
                }
            }
        }
        
        // reset the UUID since we deleted the log file.
        $options = $abj404logic->getOptions(true);
        $options[self::DEBUG_FILE_KEY] = null;
        $abj404logic->updateOptions($options);
        
        return $allIsWell;
    }
    
    /** 
     * @return int file size in bytes
     */
    function getDebugFileSize() {
        $file1Size = 0;
        $file2Size = 0;
        if (file_exists($this->getDebugFilePath())) {
            $file1Size = filesize($this->getDebugFilePath());
        }
        if (file_exists($this->getDebugFilePathOld())) {
            $file2Size = filesize($this->getDebugFilePathOld());
        }
        
        return $file1Size + $file2Size;
    }
    
}
