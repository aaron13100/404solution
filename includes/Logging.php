<?php declare(strict_types=1); 

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

    /** Always send a message to the error_log.
     * This goes to a file and is used by every other class so it goes here.
     * @param type $message  */
    function errorMessage($message) {
        $prefix = "ABJ-404-SOLUTION (ERROR): ";
        $timestamp = date('Y-m-d H:i:s') . ' (ERROR): ';
        error_log($prefix . $message);
        $this->writeLineToDebugFile($timestamp . $message);
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

