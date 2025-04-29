<?php

/* Static functions that can be used from anywhere.  */

class ABJ_404_Solution_Logging {

    /** If an error happens then we will also output these. */
    private static $storedDebugMessages = array();

    /** Used to store the last line sent from the debug file. */
    const LAST_SENT_LINE = 'last_sent_line';
    
    /** Used to store the the debug filename. */
    const DEBUG_FILE_KEY = 'debug_file_key';
    
    private static $instance = null;
    
    public static function getInstance() {
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
     * @param string $line
     */
    function writeLineToDebugFile($line) {
        file_put_contents($this->getDebugFilePath(), $line . "\n", FILE_APPEND);
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
        $bodyLines[] = "Total error count: " . $totalErrorCount;
        $bodyLines[] = "Debug file name: " . $this->getDebugFilename();
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

