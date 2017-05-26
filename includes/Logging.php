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
        $options = $abj404logic->getOptions();

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
    
    /** 
     * @return type
     */
    function getDebugFilePath() {
        return ABJ404_PATH . 'abj404_debug.txt';
    }
    
    /** 
     * @return type true if the file was deleted.
     */
    function deleteDebugFile() {
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

