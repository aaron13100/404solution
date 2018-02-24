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
        $options = $abj404logic->getOptions(true);

        return (@$options['debug_mode'] == true);
    }
    
    /** Send a message to the error_log if debug mode is on. 
     * This goes to a file and is used by every other class so it goes here.
     * @param type $message  */
    function debugMessage($message) {
        if ($this->isDebug()) {
            $prefix = "ABJ-404-SOLUTION (DEBUG): ";
            $timestamp = date('Y-m-d H:i:s T') . ' (DEBUG): ';
            error_log($prefix . $message);
            $this->writeLineToDebugFile($timestamp . $message);
        }
    }

    /** Send a message to the log. 
     * This goes to a file and is used by every other class so it goes here.
     * @param type $message  */
    function infoMessage($message) {
        $timestamp = date('Y-m-d H:i:s T') . ' (INFO): ';
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
        $timestamp = date('Y-m-d H:i:s T') . ' (ERROR): ';
        $referrer = '';
        if (array_key_exists('HTTP_REFERER', $_SERVER) && !empty($_SERVER['HTTP_REFERER'])) {
            $referrer = $_SERVER['HTTP_REFERER'];
        }
        error_log($prefix . $message);
        $this->writeLineToDebugFile($timestamp . $message . ", PHP version: " . PHP_VERSION . 
                ", WP ver: " . get_bloginfo('version') . ", Plugin ver: " . ABJ404_VERSION . 
                ", Referrer: " . esc_html($referrer) . ", \nTrace: " . $stacktrace);
        
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
        if (!$this->latestVersionIsInstalled()) {
            return false;
        }
        
        $this->emailLogFileToDeveloper($latestErrorLineFound['line']);

        // update the latest error line emailed to the developer.
        file_put_contents($sentDateFile, $latestErrorLineFound['num']);
        
        return true;
    }
    
    /** Check wordpress.org for the latest version of this plugin. Return true if the latest version is installed, 
     * false otherwise.
     * @return boolean
     */
    function latestVersionIsInstalled() {
        if (!function_exists('plugins_api')) {
              require_once(ABSPATH . 'wp-admin/includes/plugin-install.php');
        }        
        if (!function_exists('plugins_api')) {
            $this->debugMessage("I couldn't find the plugins_api function to check for the latest version, "
                    . "so I won't be emailing the error file.");
            return false;
        }
        
        $pluginSlug = dirname(ABJ404_NAME);
        
        // set the arguments to get latest info from repository via API ##
        $args = array(
            'slug' => $pluginSlug,
            'fields' => array(
                'version' => true,
            )
        );

        /** Prepare our query */
        $call_api = plugins_api('plugin_information', $args);

        /** Check for Errors & Display the results */
        if (is_wp_error($call_api)) {
            $api_error = $call_api->get_error_message();
            $this->debugMessage("There was an API issue checking the latest plugin version, "
                    . "so I won't be emailing the error file. (" . esc_html($api_error) . ")");
            return false;
        }
        
        $version_latest = $call_api->version;

        if (ABJ404_VERSION == $version_latest) {
            return true;
        }
        
        return false;
    }
    
    function emailLogFileToDeveloper($errorLineMessage) {
        // email the log file.
        $this->debugMessage("Creating zip file of error log file.");
        $logFileZip = $this->getZipFilePath();
        if (file_exists($logFileZip)) {
            ABJ_404_Solution_Functions::safeUnlink($logFileZip);
        }
        $zip = new ZipArchive;
        if ($zip->open($logFileZip, ZipArchive::CREATE) === TRUE) {
            $zip->addFile($this->getDebugFilePath(), basename($this->getDebugFilePath()));
            $zip->close();
        }
        
        $attachments = array();
        $attachments[] = $logFileZip;
        $to = ABJ404_AUTHOR_EMAIL;
        $subject = ABJ404_PP . ' error log file. Plugin version: ' . ABJ404_VERSION;
        $body = $subject . "\nSent " . date('Y/m/d h:i:s T') . "<BR/><BR/>\n\n" . "PHP version: " . PHP_VERSION . 
                ", <BR/>\nWordPress version: " . get_bloginfo('version') . ", <BR/>\nPlugin version: " . 
                ABJ404_VERSION . "<BR/>\nError: " . $errorLineMessage;
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $headers[] = 'From: ' . get_option('admin_email');
        
        // send the email
        $this->debugMessage("Sending error log zip file as attachment.");
        wp_mail($to, $subject, $body, $headers, $attachments);
        
        // delete the zip file.
        $this->debugMessage("Mail sent. Deleting error log zip file.");
        ABJ_404_Solution_Functions::safeUnlink($logFileZip);
    }
    
    /** 
     * @return int
     */
    function getLatestErrorLine() {
        $latestErrorLineFound = array();
        $latestErrorLineFound['num'] = -1;
        $latestErrorLineFound['line'] = null;
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
    
    /** Return the path to the debug file.
     * @return type
     */
    function getDebugFilePath() {
        return $this->getFilePathAndMoveOldFile(ABJ404_PATH . 'temp/', 'abj404_debug.txt');
    }
    
    /** Return the path to the file that stores the latest error line in the log file.
     * @return type
     */
    function getDebugFilePathSentFile() {
        return $this->getFilePathAndMoveOldFile(ABJ404_PATH . 'temp/', 'abj404_debug_sent_line.txt');
    }
    
    /** Return the path to the zip file for sending the debug file. 
     * @return type
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
     * @param type $directory
     * @return boolean
     */
    function createDirectoryWithErrorMessages($directory) {
        if (!is_dir($directory)) {
            if (file_exists(rtrim($directory, '/'))) {
                error_log("ABJ-404-SOLUTION (ERROR) " . date('Y-m-d H:i:s T') . ": Error creating the directory " . 
                        $directory . ". A file with that name alraedy exists.");
                return false;
                
            } else if (!mkdir($directory)) {
                error_log("ABJ-404-SOLUTION (ERROR) " . date('Y-m-d H:i:s T') . ": Error creating the directory " .
                        $directory . ". Unknown issue.");
                return false;
            }
        }
        return true;
    }
    
    /** 
     * @return type true if the file was deleted.
     */
    function deleteDebugFile() {
        // since the debug file is being deleted we reset the last error line that was sent.
        if (file_exists($this->getDebugFilePathSentFile())) {
            ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePathSentFile());
        }
        
        if (!file_exists($this->getDebugFilePath())) {
            return true;
        }
        return ABJ_404_Solution_Functions::safeUnlink($this->getDebugFilePath());
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

