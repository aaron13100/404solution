<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ErrorHandler {

    /** Setup. */
    static function init() {
        // set to the user defined error handler
        set_error_handler("ABJ_404_Solution_ErrorHandler::NormalErrorHandler");
        register_shutdown_function('ABJ_404_Solution_ErrorHandler::FatalErrorHandler');
    }

    /** Try to capture PHP errors.
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @return boolean
     */
    static function NormalErrorHandler($errno, $errstr, $errfile, $errline) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        try {
            // if the error file does not contain the name of our plugin then we ignore it.
            $pluginFolder = $f->substr(ABJ404_NAME, 0, $f->strpos(ABJ404_NAME, '/'));
            if ($f->strpos($errfile, $pluginFolder) === false) {
                return false;
            }

            $extraInfo = "(none)";
            if (array_key_exists(ABJ404_PP, $_REQUEST) && array_key_exists('debug_info', $_REQUEST[ABJ404_PP])) {
                $extraInfo = stripcslashes(wp_kses_post(json_encode($_REQUEST[ABJ404_PP]['debug_info'])));
            }
            $errmsg = "ABJ404-SOLUTION Normal error handler error: errno: " .
                        wp_kses_post(json_encode($errno)) . ", errstr: " . wp_kses_post(json_encode($errstr)) .
                        ", errfile: " . stripcslashes(wp_kses_post(json_encode($errfile))) .
                        ", errline: " . wp_kses_post(json_encode($errline)) .
                        ', Additional info: ' . $extraInfo . ", mbstring: " . 
                    (extension_loaded('mbstring') ? 'true' : 'false');
            
            if ($abj404logging != null) {
                switch ($errno) {
                    case E_NOTICE:
                        if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
                            $e = new Exception;
                            $abj404logging->debugMessage($errmsg . ', Trace:' . $e->getTraceAsString());
                        }
                        break;
                    
                    default:
                        $abj404logging->errorMessage($errmsg);
                        break;
                }
            } else {
                echo $errmsg;
            }
        } catch (Exception $ex) { 
            // ignored
        }
        /* Execute the PHP internal error handler anyway. */
        return false;
    }

    static function FatalErrorHandler() {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $lasterror = error_get_last();

        try {
            $errno = $lasterror['type'];
            $errfile = $lasterror['file'];
            $pluginFolder = $f->substr(ABJ404_NAME, 0, $f->strpos(ABJ404_NAME, '/'));

            // if the error file does not contain the name of our plugin then we ignore it.
            if ($f->strpos($errfile, $pluginFolder) === false) {
                return false;
            }
            
            $extraInfo = "(none)";
            if (array_key_exists(ABJ404_PP, $_REQUEST) && array_key_exists('debug_info', $_REQUEST[ABJ404_PP])) {
                $extraInfo = stripcslashes(wp_kses_post(json_encode($_REQUEST[ABJ404_PP]['debug_info'])));
            }
            $errmsg = "ABJ404-SOLUTION Fatal error handler: " . 
                stripcslashes(wp_kses_post(json_encode($lasterror))) .
                ', Additional info: ' . $extraInfo . ", mbstring: " . 
                    (extension_loaded('mbstring') ? 'true' : 'false');

            if ($abj404logging != null) {
                switch ($errno) {
                    case E_NOTICE:
                        if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
                            $abj404logging->debugMessage($errmsg);
                        }
                        break;

                    default:
                        $abj404logging->errorMessage($errmsg);
                        break;
                }
            } else {
                echo $errmsg;
            }
        } catch (Exception $ex) {
            // ignored
        }
    }
}

ABJ_404_Solution_ErrorHandler::init();
