<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist) && is_admin()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Static functions that can be used from anywhere.  */

class ABJ_404_Solution_Logging {

    /** @return boolean true if debug mode is on. false otherwise. */
    function isDebug() {
        global $abj404logic;
        $options = $abj404logic->getOptions(1);

        return (@$options['debug_mode'] == true);
    }
    
    /** Send a message to the error_log if debug mode is on. 
     * This goes to a file and is used by every other class so it goes here.
     * @param type $message  */
    function debugMessage($message) {
        if ($this->isDebug()) {
            $prefix = "ABJ-404-SOLUTION (DEBUG): ";
            $timestamp = date('Y-m-d H:i:s') . ' (DEBUG): ';
            error_log($prefix . $message);
            $this->writeLineToDebugFile($timestamp . $message);
        }
    }

    /** Send a message to the log. 
     * This goes to a file and is used by every other class so it goes here.
     * @param type $message  */
    function infoMessage($message) {
        $timestamp = date('Y-m-d H:i:s') . ' (INFO): ';
        $this->writeLineToDebugFile($timestamp . $message);
    }

    /** Always send a message to the error_log.
     * This goes to a file and is used by every other class so it goes here.
     * @param type $message
     * @param Exception $e
     */
    function errorMessage($message, $e = null) {
        if ($e == null) {
            $e = new Exception;
        }
        $stacktrace = $e->getTraceAsString();
        
        $prefix = "ABJ-404-SOLUTION (ERROR): ";
        $timestamp = date('Y-m-d H:i:s') . ' (ERROR): ';
        error_log($prefix . $message);
        $this->writeLineToDebugFile($timestamp . $message . ", PHP version: " . PHP_VERSION . 
                ", WP ver: " . get_bloginfo('version') . ", Plugin ver: " . ABJ404_VERSION . 
                ", Referrer: " . esc_html($_SERVER['HTTP_REFERER']) . ", \nTrace: " . $stacktrace);
        
        // display a 404 page if the user is NOT an admin and is not on an admin page.
        if (!is_admin() && !current_user_can('administrator')) {
            // send the user to a 404 page. otherwise the user might just get a blank page.
            status_header(404);
            nocache_headers();
            include(get_query_template('404'));
            exit();
        }
    }
    
    /** Log the user capabilities.
     * @param type $msg 
     */
    function logUserCapabilities($msg) {
        $user = wp_get_current_user();
        $usercaps = str_replace(',"', ', "', wp_kses_post(json_encode($user->get_role_caps())));
        
        $this->debugMessage("User caps msg: " . esc_html($msg == '' ? '(none)' : $msg) . ", is_admin(): " . is_admin() . 
                ", current_user_can('administrator'): " . current_user_can('administrator') . 
                ", user caps: " . wp_kses_post(json_encode($user->caps)) . ", get_role_caps: " . 
                $usercaps . ", WP ver: " . get_bloginfo('version'));
    }

    /** Write the line to the debug file. 
     * @param type $line
     */
    function writeLineToDebugFile($line) {
        file_put_contents($this->getDebugFilePath(), $line . "\n", FILE_APPEND);
    }
    
    /** Email the log file to the plugin developer. */
    function emailErrorLogIfNecessary() {
        if (!file_exists($this->getDebugFilePath())) {
            $this->debugMessage("No log file found so no errors were found.");
            return false;
        }

        // get the number of the last line with an error message.
        $latestErrorLineFound = $this->getLatestErrorLine();
        
        // if no error was found then we're done.
        if ($latestErrorLineFound == -1) {
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
        if ($latestErrorLineFound <= $sentLine) {
            $this->debugMessage("The latest error line from the log file was already emailed. " . $latestErrorLineFound . 
                    ' <= ' . $sentLine);
            return false;
        }
        
        $this->emailLogFileToDeveloper();

        // update the latest error line emailed to the developer.
        file_put_contents($sentDateFile, $latestErrorLineFound);
        
        return true;
    }
    
    function emailLogFileToDeveloper() {
        // email the log file.
        $this->debugMessage("Creating zip file of error log file.");
        $logFileZip = ABJ404_PATH . "abj404_debug.zip";
        if (file_exists($logFileZip)) {
            unlink($logFileZip);
        }
        $zip = new ZipArchive;
        if ($zip->open($logFileZip, ZipArchive::CREATE) === TRUE) {
            $zip->addFile($this->getDebugFilePath(), basename($this->getDebugFilePath()));
            $zip->close();
        }
        
        $attachments = array();
        $attachments[] = $logFileZip;
        $to = ABJ404_AUTHOR_EMAIL;
        $subject = ABJ404_PP . ' error log file. Plugin ver: ' . ABJ404_VERSION;
        $body = $subject . "\nSent " . date('Y/m/d h:i:s') . "\n\n" . "PHP version: " . PHP_VERSION . 
                ", \nWP ver: " . get_bloginfo('version') . ", \nPlugin ver: " . ABJ404_VERSION;
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $headers[] = 'From: ' . ABJ404_PP . ' User <' . ABJ404_PP . '@wealth-psychology.com>';
        
        // send the email
        $this->debugMessage("Sending error log zip file as attachment.");
        wp_mail( $to, $subject, $body, $headers, $attachments );
        
        // delete the zip file.
        $this->debugMessage("Mail sent. Deleting error log zip file.");
        unlink($logFileZip);
    }
    
    /** 
     * @return int
     */
    function getLatestErrorLine() {
        $latestErrorLineFound = -1;
        $linesRead = 0;
        $handle = null;
        try {
            if ($handle = fopen($this->getDebugFilePath(), "r")) {
                // read the file one line at a time.
                while (($line = fgets($handle)) !== false) {
                    $linesRead++;
                    // if the line has an error then save the line number.
                    if (stripos($line, '(ERROR)') !== false) {
                        $latestErrorLineFound = $linesRead;
                    }
                }
            } else {
                $this->errorMessage("Error reading log file (1).", $e);
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
     * @return type
     */
    function getDebugFilePath() {
        return ABJ404_PATH . 'abj404_debug.txt';
    }
    
    function getDebugFilePathSentFile() {
        return ABJ404_PATH . "abj404_debug_sent_line.txt";
    }
    
    /** 
     * @return type true if the file was deleted.
     */
    function deleteDebugFile() {
        // since the debug file is being deleted we reset the last error line that was sent.
        if (file_exists($this->getDebugFilePathSentFile())) {
            unlink($this->getDebugFilePathSentFile());
        }
        
        if (!file_exists($this->getDebugFilePath())) {
            return true;
        }
        return unlink($this->getDebugFilePath());
    }
    
    /** 
     * @return int file size in bytes
     */
    function getDebugFileSize() {
        if (!file_exists($this->getDebugFilePath())) {
            return 0;
        }
        return filesize($this->getDebugFilePath());
    }
    
}

