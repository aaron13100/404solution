<?php


if (!defined('ABSPATH')) {
    exit;
}

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
        $lasterror = error_get_last();
        return self::processFatalError($lasterror);
    }

    private static function safeJsonEncode($value) {
        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false) {
            return '(json_encode failed) ' . print_r($value, true);
        }
        return $encoded;
    }

    private static function safeWriteLine($line) {
        try {
            $logger = ABJ_404_Solution_Logging::getInstance();
            if (is_object($logger) && method_exists($logger, 'writeLineToDebugFile')) {
                $logger->writeLineToDebugFile($line);
                return true;
            }
        } catch (Throwable $e) {
            // fall back below
        }
        try {
            $logger = ABJ_404_Solution_Logging::getInstance();
            if (is_object($logger) && method_exists($logger, 'sanitizeLogLine')) {
                $line = $logger->sanitizeLogLine($line);
            }
        } catch (Throwable $e) {
            // ignore; still write the best-effort line below
        }
        @file_put_contents(ABJ404_PATH . 'abj404_debug_fallback.txt', $line . "\n", FILE_APPEND);
        return false;
    }

    private static function isFatalType($type) {
        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        return in_array($type, $fatalTypes, true);
    }

	    private static function emitJsonAndExit($payload, $httpStatus) {
	        if (!headers_sent()) {
	            // Marker headers help support quickly identify that this response came from our AJAX endpoint.
	            // These are safe to expose (no sensitive values).
	            if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
	                $ctx = $GLOBALS['abj404_ajax_context'];
	                if (array_key_exists('action', $ctx) && is_string($ctx['action'])) {
	                    header('X-ABJ404-Ajax: ' . preg_replace('/[\r\n]+/', '', $ctx['action']));
	                }
	                if (array_key_exists('subpage', $ctx) && is_string($ctx['subpage']) && $ctx['subpage'] !== '') {
	                    header('X-ABJ404-Subpage: ' . preg_replace('/[\r\n]+/', '', $ctx['subpage']));
	                }
	            }
	            header('Content-type: application/json; charset=UTF-8');
	            if (function_exists('status_header')) {
	                status_header($httpStatus);
	            } else if (function_exists('http_response_code')) {
	                http_response_code($httpStatus);
            }
        }
        echo json_encode($payload);
        if (defined('ABJ404_TEST_NO_EXIT') && ABJ404_TEST_NO_EXIT) {
            return true;
        }
        exit;
    }

    /**
     * Process a fatal error (shutdown handler).
     * Public for unit tests (allows injecting a fake last error).
     */
    public static function processFatalError($lasterror) {
        $f = ABJ_404_Solution_Functions::getInstance();

        if ($lasterror == null || !is_array($lasterror) || !array_key_exists('type', $lasterror) ||
            !array_key_exists('file', $lasterror)) {
            return false;
        }
        if (!self::isFatalType($lasterror['type'])) {
            return false;
        }

        $ctx = isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])
            ? $GLOBALS['abj404_ajax_context'] : null;

        $isAjaxContext = is_array($ctx) &&
            !empty($ctx['ajax_expected_json']) &&
            empty($ctx['response_sent']) &&
            array_key_exists('action', $ctx) &&
            $ctx['action'] === 'ajaxUpdatePaginationLinks';

        // -------------------------
        // AJAX context: always log (even if fatal is from another plugin/theme/core),
        // and emit JSON for admins so WordPress's generic "critical error" page doesn't hide details.
        if ($isAjaxContext) {
            // Only handle fatals for this endpoint when the context was created by our handler.
            // This avoids logging unrelated admin-ajax fatals while still capturing "foreign" plugin/theme fatals
            // that break our AJAX response.
            $contextSourceOk = array_key_exists('abj404_context_source', $ctx) &&
                $ctx['abj404_context_source'] === 'ViewUpdater::getPaginationLinks';
            if (!$contextSourceOk) {
                return false;
            }

            $bufferedOutput = '';
            if (!(defined('ABJ404_TEST_DISABLE_OB') && ABJ404_TEST_DISABLE_OB)) {
                if (ob_get_level() > 0) {
                    $bufferedOutput = (string)ob_get_contents();
                }
                $minLevel = array_key_exists('ob_level_before', $ctx) ? intval($ctx['ob_level_before']) : 0;
                while (ob_get_level() > $minLevel) {
                    @ob_end_clean();
                }
            }

            $details = array(
                'fatal_error' => $lasterror,
                'context' => $ctx,
            );
            if ($bufferedOutput !== '') {
                $details['buffered_output'] = substr($bufferedOutput, 0, 8000);
            }

            $line = date('c') . ' (ERROR): AJAX fatal error in ajaxUpdatePaginationLinks. Details: ' . self::safeJsonEncode($details);
            self::safeWriteLine($line);

            $isPluginAdmin = array_key_exists('is_plugin_admin', $ctx) ? (bool)$ctx['is_plugin_admin'] : null;
            // Only try to compute admin status if it wasn't already determined earlier in the request.
            if ($isPluginAdmin === null) {
                try {
                    $logic = ABJ_404_Solution_PluginLogic::getInstance();
                    if (is_object($logic) && method_exists($logic, 'userIsPluginAdmin')) {
                        $isPluginAdmin = $logic->userIsPluginAdmin();
                    }
                } catch (Throwable $e) {
                    $isPluginAdmin = null;
                }
            }
            if ($isPluginAdmin === null) {
                // Best-effort fallback: show details to real WordPress admins if PluginLogic is broken.
                try {
                    if (function_exists('wp_get_current_user')) {
                        $user = wp_get_current_user();
                        if (is_object($user) && property_exists($user, 'roles') && is_array($user->roles)) {
                            $isPluginAdmin = in_array('administrator', $user->roles, true);
                        }
                    }
                    if ($isPluginAdmin !== true && function_exists('is_super_admin') && is_super_admin()) {
                        $isPluginAdmin = true;
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }
            if ($isPluginAdmin === null) {
                $isPluginAdmin = false;
            }

            $payload = array(
                'success' => false,
                'data' => array(
                    'message' => 'Server error while updating the table.',
                ),
            );
            if ($isPluginAdmin) {
                $payload['data']['details'] = $details;
            }

            $GLOBALS['abj404_ajax_context']['response_sent'] = true;
            return self::emitJsonAndExit($payload, 500);
        }

        // -------------------------
        // Default behavior: only log plugin-scope fatals (avoid noise from other plugins/themes).
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

            $abj404logging = ABJ_404_Solution_Logging::getInstance();
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

        return false;
    }
}
