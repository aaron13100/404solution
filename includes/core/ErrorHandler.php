<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../policies/ErrorTypeClassifier.php';
require_once __DIR__ . '/../services/ErrorDiagnosticsReporter.php';
require_once __DIR__ . '/../services/AdminFatalErrorResponder.php';
require_once __DIR__ . '/../services/AjaxFatalErrorResponder.php';
require_once __DIR__ . '/../services/FatalErrorProcessor.php';

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ErrorHandler {

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
        // Respect PHP's `@` error-suppression operator. Up to PHP 7.x the @
        // operator zeroed error_reporting() for the duration of the call;
        // PHP 8.0+ instead leaves error_reporting() non-zero but masks out
        // the specific level being silenced. A custom handler that ignores
        // this state still escalates intentionally-suppressed warnings to
        // error-level logging, defeating the suppression.
        // Concrete case: @opcache_invalidate() at PluginLogic.php:1036 on
        // hosts with opcache.restrict_api set produces an E_WARNING. The
        // legacy `error_reporting() === 0` check matched on PHP 7.x but
        // not on PHP 8.0+ (where the @ leaves the mask non-zero), so 24
        // such ERROR entries reached the email reporter in the May 10
        // debug zip, including 2 from current 4.1.17 sites on PHP 8.3.
        // The bitwise form covers both eras: on PHP 7.x error_reporting()
        // is 0 and `& $errno` is 0; on PHP 8.0+ the specific level bit is
        // cleared and `& $errno` is also 0. Returning false here lets
        // PHP's default handler honour the suppression.
        if ((error_reporting() & $errno) === 0) {
            return false;
        }

        $abj404logging = abj_service('logging');
        $f = abj_service('functions');
        $onlyAWarning = false;

        try {
            // if the error file does not contain the name of our plugin then we ignore it.
            $slashPos = $f->strpos(ABJ404_NAME, '/');
            $pluginFolder = $f->substr(ABJ404_NAME, 0, ($slashPos !== false ? $slashPos : null));
            if ($f->strpos($errfile, $pluginFolder) === false) {
                // let the normal error handler handle it.

                // this would display the error for other plugins but show @author user
                // stacktrace from this plugin.
//              // try calling the original error handler.
//              if (is_callable(self::$originalErrorHandler)) {
//                  return call_user_func_array(self::$originalErrorHandler,
//                      array($errno, $errstr, $errfile, $errline));
//              }
                return false;

            } else {
                // for our own plugin errors make sure we see them.
                if ($GLOBALS['abj404_display_errors']) {
                    error_reporting(E_ALL);
                    ini_set('display_errors', '1');
                }
            }

            // PHP emits this warning in two forms: with a "by (output started
            // at /file:line)" suffix when it can attribute the earlier output,
            // and without that suffix (bare "...headers already sent") when it
            // cannot. Match the stable prefix so both forms downgrade to a
            // warning. The " by" variant alone missed the bare form, which then
            // reached error level and triggered a feedback report (production
            // report 104, chasalford.com, PHP 8.4).
            if ($errno == 2 &&
                $f->strpos($errstr,
                        "Cannot modify header information - headers already sent") !== false) {

                $onlyAWarning = true;
            }

            $extraInfo = "(none)";
            $ctxDebugInfo = abj_service('request_context')->debug_info;
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
                    $whitelist = isset($GLOBALS['abj404_whitelist']) && is_array($GLOBALS['abj404_whitelist'])
                        ? $GLOBALS['abj404_whitelist'] : array();
                    if (in_array($serverName, $whitelist, true)) {
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
        } catch (Throwable $ex) {
            // Last-resort breadcrumb: the inner logging path itself failed,
            // so we can't go through $abj404logging. Match the pattern used
            // by AdminRuntimeErrorNotice::reportAdminRuntimeError() and
            // Ajax_SuggestionCompute::handleShutdown(). Widening from
            // Exception to Throwable is intentional because Error types are
            // exactly the case the outer handler exists for.
            abj404_logPhpFallback(
                'fatal-handler-fallback',
                'error handler itself failed (code ' . $ex->getCode() . '): ' . $ex->getMessage()
            );
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
     * Release the OOM reserve before fatal fallback rendering.
     *
     * @return void
     */
    public static function releaseReservedMemory(): void {
        self::$reservedMemory = null;
    }

    /**
     * Process a fatal error (shutdown handler).
     * Public for unit tests (allows injecting a fake last error).
     *
     * @param array<string, mixed>|null $lasterror
     * @return bool
     */
    public static function processFatalError($lasterror): bool {
        $processor = new ABJ_404_Solution_FatalErrorProcessor();
        return $processor->process($lasterror);
    }
}
