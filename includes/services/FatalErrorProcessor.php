<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orchestrates shutdown-time fatal-error handling.
 */
class ABJ_404_Solution_FatalErrorProcessor {

    /** @var ABJ_404_Solution_ErrorTypeClassifier */
    private $classifier;

    /** @var ABJ_404_Solution_ErrorDiagnosticsReporter */
    private $diagnostics;

    /** @var ABJ_404_Solution_AdminFatalErrorResponder */
    private $adminResponder;

    /** @var ABJ_404_Solution_AjaxFatalErrorResponder */
    private $ajaxResponder;

    /**
     * @param ABJ_404_Solution_ErrorTypeClassifier|null $classifier
     * @param ABJ_404_Solution_ErrorDiagnosticsReporter|null $diagnostics
     * @param ABJ_404_Solution_AdminFatalErrorResponder|null $adminResponder
     * @param ABJ_404_Solution_AjaxFatalErrorResponder|null $ajaxResponder
     */
    public function __construct($classifier = null, $diagnostics = null, $adminResponder = null, $ajaxResponder = null) {
        $this->classifier = $classifier !== null ? $classifier : new ABJ_404_Solution_ErrorTypeClassifier();
        $this->diagnostics = $diagnostics !== null ? $diagnostics : new ABJ_404_Solution_ErrorDiagnosticsReporter();
        $this->adminResponder = $adminResponder !== null ? $adminResponder : new ABJ_404_Solution_AdminFatalErrorResponder();
        $this->ajaxResponder = $ajaxResponder !== null ? $ajaxResponder : new ABJ_404_Solution_AjaxFatalErrorResponder($this->diagnostics);
    }

    /**
     * @param array<string,mixed>|null $lasterror
     * @return bool
     */
    public function process($lasterror): bool {
        if (!$this->isProcessableFatal($lasterror) || !is_array($lasterror)) {
            return false;
        }

        $lasterror = $this->truncateLargeMessage($lasterror);
        $ctx = $this->currentAjaxContext();

        $isPluginAdminPage = $this->adminResponder->isPluginAdminPageRequest();
        if ($isPluginAdminPage) {
            ABJ_404_Solution_ErrorHandler::releaseReservedMemory();
            $this->adminResponder->stashAdminFatal($lasterror);
        }

        if ($this->ajaxResponder->isAjaxContext($ctx)) {
            return $this->ajaxResponder->process($lasterror, is_array($ctx) ? $ctx : array());
        }

        $this->logDefaultFatal($lasterror, $isPluginAdminPage);

        if ($isPluginAdminPage) {
            $this->adminResponder->renderAdminFatalFallback($lasterror);
        }

        return false;
    }

    /**
     * @param mixed $lasterror
     * @return bool
     */
    private function isProcessableFatal($lasterror): bool {
        if ($lasterror == null || !is_array($lasterror) || !array_key_exists('type', $lasterror) ||
            !array_key_exists('file', $lasterror)) {
            return false;
        }

        $errorType = $lasterror['type'];
        return $this->classifier->isFatalType(is_int($errorType) ? $errorType : (is_scalar($errorType) ? (int)$errorType : 0));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function currentAjaxContext() {
        if (!isset($GLOBALS['abj404_ajax_context']) || !is_array($GLOBALS['abj404_ajax_context'])) {
            return null;
        }

        $context = array();
        foreach ($GLOBALS['abj404_ajax_context'] as $key => $value) {
            if (is_string($key)) {
                $context[$key] = $value;
            }
        }
        return $context;
    }

    /**
     * @param array<string,mixed> $lasterror
     * @return void
     */
    private function logDefaultFatal(array $lasterror, bool $isPluginAdminPage): void {
        try {
            $errno = $lasterror['type'];
            $errfile = is_string($lasterror['file']) ? $lasterror['file'] : '';
            $pluginFolder = $this->pluginFolder();
            $isPluginScopeFatal = (strpos($errfile, $pluginFolder) !== false);

            if (!$isPluginScopeFatal && !$isPluginAdminPage) {
                return;
            }

            $extraInfo = "(none)";
            $ctxDebugInfo = abj_service('request_context')->debug_info;
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

            $abj404logging = abj_service('logging');
            if ($abj404logging != null) {
                switch ($errno) {
                    case E_NOTICE:
                        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
                        $whitelist = isset($GLOBALS['abj404_whitelist']) && is_array($GLOBALS['abj404_whitelist'])
                            ? $GLOBALS['abj404_whitelist'] : array();
                        if (in_array($serverName, $whitelist, true)) {
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
            abj404_logPhpFallback(
                'fatal-handler-fallback',
                'error handler itself failed (code ' . $ex->getCode() . '): ' . $ex->getMessage()
            );
        }
    }

    /**
     * @param array<string,mixed> $lasterror
     * @return array<string,mixed>
     */
    private function truncateLargeMessage(array $lasterror): array {
        if (isset($lasterror['message']) && is_string($lasterror['message'])
            && strlen($lasterror['message']) > 8192) {
            $lasterror['message'] = substr($lasterror['message'], 0, 8192)
                . '... (truncated; original length ' . strlen($lasterror['message']) . ' bytes)';
        }
        return $lasterror;
    }

    /** @return string */
    private function pluginFolder(): string {
        $slashPos = strpos(ABJ404_NAME, '/');
        $pluginFolder = substr(ABJ404_NAME, 0, ($slashPos !== false ? $slashPos : strlen(ABJ404_NAME)));
        return is_string($pluginFolder) ? $pluginFolder : (string)ABJ404_NAME;
    }
}
