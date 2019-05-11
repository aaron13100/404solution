<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], array($GLOBALS['abj404_whitelist']))) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Static functions that can be used from anywhere.  */

class ABJ_404_Solution_Logging {

    private static $instance = null;
    
    /** If an error happens then we will also output these. */
    private static $storedDebugMessages = array();
    
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_Logging();
        }
        
        return self::$instance;
    }
    
    private function __construct() {
    }
    
    /** @return boolean true if debug mode is on. false otherwise. */
    function isDebug() {
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $options = $abj404logic->getOptions(true);

        return (@$options['debug_mode'] == true);
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
     * @param string $message  */
    function debugMessage($message) {
        $timestamp = $this->getTimestamp() . ' (DEBUG): ';
        if ($this->isDebug()) {
            $this->writeLineToDebugFile($timestamp . $message);
            
        } else {
            array_push(self::$storedDebugMessages, $timestamp . $message);
        }
    }

    /** Send a message to the log. 
     * This goes to a file and is used by every other class so it goes here.
     * @param string $message  */
    function infoMessage($message) {
        $timestamp = $this->getTimestamp() . ' (INFO): ';
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
        
        // display a 404 page if the user is NOT an admin and is not on an admin page.
        if (!is_admin()) {
            // try to send the user to a 404 page. otherwise the user might just get a blank page.
            status_header(404);
            nocache_headers();
            $queryTemplate = get_query_template('404');
            if ($queryTemplate != null && $queryTemplate != '') {
                include(get_query_template('404'));
            }
        }
    }
    
    /** Log the user capabilities.
     * @param string $msg 
     */
    function logUserCapabilities($msg) {
        $user = wp_get_current_user();
        $usercaps = str_replace(',"', ', "', wp_kses_post(json_encode($user->get_role_caps())));
        
        $this->debugMessage("User caps msg: " . esc_html($msg == '' ? '(none)' : $msg) . ", is_admin(): " . is_admin() . 
                ", current_user_can('administrator'): " . current_user_can('administrator') . 
                ", user caps: " . wp_kses_post(json_encode($user->caps)) . ", get_role_caps: " . 
                $usercaps . ", WP ver: " . get_bloginfo('version') . ", mbstring: " . 
                (extension_loaded('mbstring') ? 'true' : 'false'));
    }

    /** Write the line to the debug file. 
     * @param string $line
     */
    function writeLineToDebugFile($line) {
        file_put_contents($this->getDebugFilePath(), $line . "\n", FILE_APPEND);
    }
    
    /** Email the log file to the plugin developer. */
    function emailErrorLogIfNecessary() {
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        
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
        
        if (file_exists($sentDateFile)) {
            $sentLine = absint(ABJ_404_Solution_Functions::readFileContents($sentDateFile));
        } else {
            $sentLine = -1;
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
        
        $this->emailLogFileToDeveloper($latestErrorLineFound['line'], $latestErrorLineFound['total_error_count']);

        // update the latest error line emailed to the developer.
        file_put_contents($sentDateFile, $latestErrorLineFound['num']);
        
        return true;
    }
    
    function emailLogFileToDeveloper($errorLineMessage, $totalErrorCount) {
        global $wpdb;
        
        // email the log file.
        $this->debugMessage("Creating zip file of error log file.");
        $logFileZip = $this->getZipFilePath();
        if (file_exists($logFileZip)) {
            ABJ_404_Solution_Functions::safeUnlink($logFileZip);
        }
        $zip = new ZipArchive;
        if ($zip->open($logFileZip, ZipArchive::CREATE) === TRUE) {
            $zip->addFile($this->getDebugFilePath(), basename($this->getDebugFilePath()));
            $zip->addFile($this->getDebugFilePathOld(), basename($this->getDebugFilePathOld()));
            $zip->close();
        }
        
        $count_posts = wp_count_posts();
        $published_posts = $count_posts->publish;
        $count_pages = wp_count_posts('page');
        $published_pages = $count_pages->publish;
        
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
        $bodyLines[] = "WP_MEMORY_LIMIT: " . WP_MEMORY_LIMIT;
        $bodyLines[] = "Extensions: " . implode(", ", get_loaded_extensions());
        $bodyLines[] = "Published posts: " . $published_posts . ", published pages: " . $published_pages;
        $bodyLines[] = "Active plugins: <pre>" . 
        	json_encode(get_option('active_plugins'), JSON_PRETTY_PRINT) . "</pre>";
        
        $bodyLines[] = "Total error count: " . $totalErrorCount;
        
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
     * @return int
     */
    function getLatestErrorLine() {
        $f = ABJ_404_Solution_Functions::getInstance();
        $latestErrorLineFound = array();
        $latestErrorLineFound['num'] = -1;
        $latestErrorLineFound['line'] = null;
        $latestErrorLineFound['total_error_count'] = 0;
        $linesRead = 0;
        $handle = null;
        try {
            if ($handle = fopen($this->getDebugFilePath(), "r")) {
                // read the file one line at a time.
                while (($line = fgets($handle)) !== false) {
                    $linesRead++;
                    // if the line has an error then save the line number.
                    if (stripos($line, '(ERROR)') !== false) {
                        $latestErrorLineFound['num'] = $linesRead;
                        $latestErrorLineFound['line'] = $line;
                        $latestErrorLineFound['total_error_count'] += 1;
                        
                    } else if ($f->regexMatch("^#\d+ .+$", $line)) {
                        // include the entire stack trace.
                        $latestErrorLineFound['line'] .= "<BR/>\n" . $line;
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
    
    /** Return the path to the debug file.
     * @return string
     */
    function getDebugFilePath() {
        return $this->getFilePathAndMoveOldFile(ABJ404_PATH . 'temp/', 'abj404_debug.txt');
    }
    
    function getDebugFilePathOld() {
        return $this->getDebugFilePath() . "_old.txt";
    }
    
    /** Return the path to the file that stores the latest error line in the log file.
     * @return string
     */
    function getDebugFilePathSentFile() {
        return $this->getFilePathAndMoveOldFile(ABJ404_PATH . 'temp/', 'abj404_debug_sent_line.txt');
    }
    
    /** Return the path to the zip file for sending the debug file. 
     * @return string
     */
    function getZipFilePath() {
        return $this->getFilePathAndMoveOldFile(ABJ404_PATH . 'temp/', 'abj404_debug.zip');
    }
    
    function getFilePathAndMoveOldFile($directory, $filename) {
        // create the directory and move the file
        if (!$this->createDirectoryWithErrorMessages($directory)) {
            return ABJ404_PATH . $filename;
        }
        
        if (file_exists(ABJ404_PATH . $filename)) {
            // move the file to the new location
            rename(ABJ404_PATH . $filename, $directory . $filename);
        }
        
        return $directory . $filename;
    }
    
    /** 
     * @param string $directory
     * @return boolean
     */
    function createDirectoryWithErrorMessages($directory) {
        if (!is_dir($directory)) {
        	if (file_exists($directory) || file_exists(rtrim($directory, '/'))) {
        		unlink($directory);
        		
        		if (file_exists($directory) || file_exists(rtrim($directory, '/'))) {
        			error_log("ABJ-404-SOLUTION (ERROR) " . date('Y-m-d H:i:s T') . ": Error creating the directory " .
        					$directory . ". A file with that name alraedy exists.");
        			return false;
        		}
        		
            } else if (!mkdir($directory)) {
                error_log("ABJ-404-SOLUTION (ERROR) " . date('Y-m-d H:i:s T') . ": Error creating the directory " .
                        $directory . ". Unknown issue.");
                return false;
            }
        }
        return true;
    }
    
    function limitDebugFileSize() {
        // delete the sent_line file since it's now incorrect.
        if (file_exists($this->getDebugFilePathSentFile())) {
            ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePathSentFile());
        }
        
        // delete _old log file
        ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePathOld());
        // rename current log file to _old
        rename($this->getDebugFilePath(), $this->getDebugFilePathOld());
    }
    
    /** 
     * @return boolean true if the file was deleted.
     */
    function deleteDebugFile() {
        // since the debug file is being deleted we reset the last error line that was sent.
        if (file_exists($this->getDebugFilePathSentFile())) {
            ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePathSentFile());
        }
        
        ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePathOld());
        return ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePath());
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

