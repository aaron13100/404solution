<?php


if (!defined('ABSPATH')) {
    exit;
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ErrorHandler {

	/**
	 * Prevent duplicate shutdown fallback output when multiple handlers run.
	 *
	 * @var bool
	 */
	private static $adminFatalPageRendered = false;
	
	/** Keep a reference to the original error handler so we can use it later.
	 * @var callable|null
	 */
	static $originalErrorHandler = null;

	/**
	 * Reserved memory released during fatal shutdown handling so OOM errors can still render fallback output.
	 *
	 * @var string|null
	 */
	private static $reservedMemory = null;

    /** Setup.
     * @return void
     */
    static function init(): void {
    	// store the original error handler.
    	self::$originalErrorHandler = set_error_handler(function(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool { return false; });
    	restore_error_handler();
    	
        // set to the user defined error handler
        set_error_handler("ABJ_404_Solution_ErrorHandler::NormalErrorHandler");
        if (self::$reservedMemory === null) {
            // Keep a small memory reserve so shutdown handling can render a fallback page on memory exhaustion.
            self::$reservedMemory = str_repeat('R', 262144);
        }
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
        	$slashPos = $f->strpos(ABJ404_NAME, '/');
        	$pluginFolder = $f->substr(ABJ404_NAME, 0, ($slashPos !== false ? $slashPos : null));
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
            $ctxDebugInfo = ABJ_404_Solution_RequestContext::getInstance()->debug_info;
            if ($ctxDebugInfo !== '') {
                $extraInfo = stripcslashes(wp_kses_post((string)json_encode($ctxDebugInfo)));
            }
            $errmsg = "ABJ404-SOLUTION Normal error handler error: errno: " .
                        wp_kses_post((string)json_encode($errno)) . ", errstr: " . wp_kses_post((string)json_encode($errstr)) .
                        ", \nerrfile: " . stripcslashes(wp_kses_post((string)json_encode($errfile))) .
                        ", \nerrline: " . wp_kses_post((string)json_encode($errline)) .
                        ', \nAdditional info: ' . $extraInfo . ", mbstring: " . 
                    (extension_loaded('mbstring') ? 'true' : 'false');
            
            if ($abj404logging != null) {
                if ($errno === E_NOTICE) {
                    $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
                    if (in_array($serverName, $GLOBALS['abj404_whitelist'])) {
                        $e = new Exception;
                        $abj404logging->debugMessage($errmsg . ', Trace:' . $e->getTraceAsString());
                    }
                } elseif ($onlyAWarning) {
                    $abj404logging->debugMessage($errmsg);
                } else {
                    $abj404logging->errorMessage($errmsg);
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

    /** @return bool */
    static function FatalErrorHandler(): bool {
        $lasterror = error_get_last();
        return self::processFatalError($lasterror);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function safeJsonEncode($value): string {
        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false) {
            return '(json_encode failed) ' . print_r($value, true);
        }
        return $encoded;
    }

    /**
     * @param string $line
     * @return bool
     */
    private static function safeWriteLine(string $line): bool {
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

    /**
     * @param int $type
     * @return bool
     */
    private static function isFatalType(int $type): bool {
        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        return in_array($type, $fatalTypes, true);
    }

	/**
	 * Best-effort scalar read from request arrays without depending on WP helpers.
	 *
	 * @param string $key
	 * @return string
	 */
	private static function getRequestValue(string $key): string {
		$raw = null;
		if (array_key_exists($key, $_GET)) {
			$raw = $_GET[$key];
		} elseif (array_key_exists($key, $_POST)) {
			$raw = $_POST[$key];
		} elseif (array_key_exists($key, $_REQUEST)) {
			$raw = $_REQUEST[$key];
		}

		if (!is_scalar($raw)) {
			return '';
		}

		return trim((string)$raw);
	}

	/**
	 * Detect whether the current request is the plugin admin page.
	 *
	 * @return bool
	 */
	private static function isPluginAdminPageRequest(): bool {
		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			return false;
		}

		$page = self::getRequestValue('page');
		if ($page === '') {
			return false;
		}

		$pluginPage = defined('ABJ404_PP') ? (string)ABJ404_PP : 'abj404_solution';
		return $page === $pluginPage;
	}

	/**
	 * Persist the last admin fatal so we can show a notice on the next request.
	 *
	 * @param array<string,mixed> $lasterror
	 * @return void
	 */
	private static function stashAdminFatal(array $lasterror): void {
		$payload = array(
			'message' => array_key_exists('message', $lasterror) ? (is_string($lasterror['message']) ? $lasterror['message'] : '') : '',
			'file' => array_key_exists('file', $lasterror) ? (is_string($lasterror['file']) ? $lasterror['file'] : '') : '',
			'line' => array_key_exists('line', $lasterror) ? (is_int($lasterror['line']) ? $lasterror['line'] : 0) : 0,
			'type' => array_key_exists('type', $lasterror) ? (is_int($lasterror['type']) ? $lasterror['type'] : 0) : 0,
			'time' => time(),
			'page' => self::getRequestValue('page'),
			'subpage' => self::getRequestValue('subpage'),
		);

		$ttl = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
		if (function_exists('set_transient')) {
			set_transient('abj404_admin_fatal', $payload, $ttl);
			return;
		}

		if (function_exists('update_option')) {
			update_option('abj404_admin_fatal_fallback', $payload);
		}
	}

	/**
	 * Render a small HTML fallback so fatal admin errors do not become a blank page.
	 *
	 * @param array<string,mixed> $lasterror
	 * @return void
	 */
	private static function renderAdminFatalFallback(array $lasterror): void {
		if (self::$adminFatalPageRendered) {
			return;
		}
		self::$adminFatalPageRendered = true;

		$canShowDetails = false;
		try {
			if (function_exists('current_user_can') && current_user_can('manage_options')) {
				$canShowDetails = true;
			} elseif (function_exists('is_super_admin') && is_super_admin()) {
				$canShowDetails = true;
			}
		} catch (Throwable $e) {
			$canShowDetails = false;
		}

		if (!(defined('ABJ404_TEST_DISABLE_OB') && ABJ404_TEST_DISABLE_OB)) {
			while (ob_get_level() > 0) {
				@ob_end_clean();
			}
		}

		if (!headers_sent()) {
			if (function_exists('status_header')) {
				status_header(500);
			} elseif (function_exists('http_response_code')) {
				http_response_code(500);
			}
			header('Content-Type: text/html; charset=UTF-8');
		}

		$settingsUrl = '?page=' . (defined('ABJ404_PP') ? ABJ404_PP : 'abj404_solution') . '&subpage=abj404_options';
		if (function_exists('admin_url')) {
			$settingsUrl = admin_url('options-general.php' . $settingsUrl);
		}

		$message = array_key_exists('message', $lasterror) ? (is_string($lasterror['message']) ? $lasterror['message'] : 'Fatal error') : 'Fatal error';
		$file = array_key_exists('file', $lasterror) ? (is_string($lasterror['file']) ? $lasterror['file'] : '(unknown file)') : '(unknown file)';
		$line = array_key_exists('line', $lasterror) ? (is_int($lasterror['line']) ? $lasterror['line'] : 0) : 0;

		echo '<!doctype html><html><head><meta charset="utf-8"><title>404 Solution Error</title></head><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;padding:24px;">';
		echo '<h1 style="margin:0 0 12px 0;">404 Solution</h1>';
		echo '<p><strong>A fatal error occurred while rendering this admin page.</strong></p>';
		echo '<p>Open the Options tab to continue: <a href="' . htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8') . '">Options</a></p>';

		if ($canShowDetails) {
			echo '<details open><summary>Error details</summary>';
			echo '<pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;">' .
				htmlspecialchars($message . "\n" . $file . ':' . (string)$line, ENT_QUOTES, 'UTF-8') .
				'</pre>';
			echo '</details>';
		}

		echo '</body></html>';
	}

	    /**
	     * @param array<string, mixed> $payload
	     * @param int $httpStatus
	     * @return bool
	     */
	    private static function emitJsonAndExit(array $payload, int $httpStatus): bool {
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
    /**
     * @param array<string, mixed>|null $lasterror
     * @return bool
     */
    public static function processFatalError($lasterror): bool {
        $f = ABJ_404_Solution_Functions::getInstance();

        if ($lasterror == null || !is_array($lasterror) || !array_key_exists('type', $lasterror) ||
            !array_key_exists('file', $lasterror)) {
            return false;
        }
        $errorType = $lasterror['type'];
        if (!self::isFatalType(is_int($errorType) ? $errorType : (is_scalar($errorType) ? (int)$errorType : 0))) {
            return false;
        }

        $ctx = isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])
            ? $GLOBALS['abj404_ajax_context'] : null;

		$isPluginAdminPage = self::isPluginAdminPageRequest();
		if ($isPluginAdminPage) {
			// Free reserved memory first so fallback rendering can succeed after OOM fatals.
			self::$reservedMemory = null;
			self::stashAdminFatal($lasterror);
		}

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
        // Default behavior: only log plugin-scope fatals (avoid noise from other plugins/themes),
        // except plugin admin page requests where we deliberately capture foreign fatals too.
        try {
            $errno = $lasterror['type'];
            $errfile = is_string($lasterror['file']) ? $lasterror['file'] : '';
            $slashPos2 = $f->strpos(ABJ404_NAME, '/');
            $pluginFolder = $f->substr(ABJ404_NAME, 0, ($slashPos2 !== false ? $slashPos2 : null));

            $isPluginScopeFatal = ($f->strpos($errfile, $pluginFolder) !== false);

            // If the error file does not contain our plugin name, ignore it unless
            // we are rendering the plugin admin page where blank-page prevention is critical.
            if (!$isPluginScopeFatal && !$isPluginAdminPage) {
                return false;
            }

            $extraInfo = "(none)";
            $ctxDebugInfo = ABJ_404_Solution_RequestContext::getInstance()->debug_info;
            if ($ctxDebugInfo !== '') {
                $extraInfo = stripcslashes(wp_kses_post((string)json_encode($ctxDebugInfo)));
            }
            $contextPrefix = $isPluginScopeFatal
                ? 'ABJ404-SOLUTION Fatal error handler: '
                : 'ABJ404-SOLUTION Fatal error handler (plugin admin page, foreign scope): ';

            $errmsg = $contextPrefix .
                stripcslashes(wp_kses_post((string)json_encode($lasterror))) .
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
        } catch (Throwable $ex) {
            // ignored
        }

		if ($isPluginAdminPage) {
			self::renderAdminFatalFallback($lasterror);
		}

        return false;
    }
}
