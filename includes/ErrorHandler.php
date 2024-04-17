<?php

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ErrorHandler {
	
	/** Keep a reference to the original error handler so we can use it later. */
	static $originalErrorHandler = null;

    /** Setup. */
    static function init() {
    	// store the original error handler.
    	self::$originalErrorHandler = set_error_handler(function(){});
    	restore_error_handler();
    	
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
        $onlyAWarning = false;
        
        try {
        	// if the error file does not contain the name of our plugin then we ignore it.
        	$pluginFolder = $f->substr(ABJ404_NAME, 0, $f->strpos(ABJ404_NAME, '/'));
        	if ($f->strpos($errfile, $pluginFolder) === false) {
        		// let the normal error handler handle it.
        		
        		// this would display the error for other plugins but show @author user
        		// stacktrace from this plugin.
//         		// try calling the original error handler.
//         		if (is_callable(self::$originalErrorHandler)) {
//         			return call_user_func_array(self::$originalErrorHandler,
//         				array($errno, $errstr, $errfile, $errline));
//         		}
        		return false;
        		
        	} else {
        		// for our own plugin errors make sure we see them.
        		if ($GLOBALS['abj404_display_errors']) {
        			error_reporting(E_ALL);
        			ini_set('display_errors', '1');
        		}
        	}
        	
            if ($errno == 2 && 
            	$f->strpos($errstr, 
            			"Cannot modify header information - headers already sent by") !== false) {
            	
       			$onlyAWarning = true;
            }
            
            $extraInfo = "(none)";
            if (array_key_exists(ABJ404_PP, $_REQUEST) && array_key_exists('debug_info', $_REQUEST[ABJ404_PP])) {
                $extraInfo = stripcslashes(wp_kses_post(json_encode($_REQUEST[ABJ404_PP]['debug_info'])));
            }
            $errmsg = "ABJ404-SOLUTION Normal error handler error: errno: " .
                        wp_kses_post(json_encode($errno)) . ", errstr: " . wp_kses_post(json_encode($errstr)) .
                        ", \nerrfile: " . stripcslashes(wp_kses_post(json_encode($errfile))) .
                        ", \nerrline: " . wp_kses_post(json_encode($errline)) .
                        ', \nAdditional info: ' . $extraInfo . ", mbstring: " . 
                    (extension_loaded('mbstring') ? 'true' : 'false');
            
            if ($abj404logging != null) {
                switch ($errno) {
                    case E_NOTICE:
                        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
                        if (in_array($serverName, $GLOBALS['abj404_whitelist'])) {
                            $e = new Exception;
                            $abj404logging->debugMessage($errmsg . ', Trace:' . $e->getTraceAsString());
                        }
                        break;
                        
                    case $onlyAWarning:
                    	$abj404logging->debugMessage($errmsg);
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
        
        // show all warnings and errors.
        if ($GLOBALS['abj404_display_errors']) {
	        error_reporting(E_ALL);
	        ini_set('display_errors', '1');
        }
        // let the original error handler handle it.
        return false;
    }

    static function FatalErrorHandler() {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        $lasterror = error_get_last();
        
        if ($lasterror == null || !is_array($lasterror) || !array_key_exists('type', $lasterror) || 
        	!array_key_exists('file', $lasterror)) {
        	
        	return false;
        }

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
                ", \nAdditional info: " . $extraInfo . ", mbstring: " . 
                    (extension_loaded('mbstring') ? 'true' : 'false');

            if ($abj404logging != null) {
                switch ($errno) {
                    case E_NOTICE:
                        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
                        if (in_array($serverName, $GLOBALS['abj404_whitelist'])) {
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
